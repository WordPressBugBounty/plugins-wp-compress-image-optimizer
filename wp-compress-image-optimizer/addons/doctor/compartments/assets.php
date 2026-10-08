<?php
/*
 * The assets compartment: the page's script dependencies and the natural size of its upload images.
 *
 * Two inputs only the site knows: the dependency graph of the scripts WordPress printed on the
 * page (every handle with the dependencies it registered, aliases included) and the natural
 * width and height of the page's own upload images. collect() reads what is known without a
 * request: whether the script registry is loaded in this request and how many handles it holds,
 * and the uploads folder the images are read from. The opt-in probe fetches the page per device
 * with the plugin off and the graph switched on (classes/script_graph.class.php), reads the
 * comment back, and reads each upload image the page names from its file on disk (getimagesize:
 * a read, never a write). The page itself never enters the report. The artifacts, as rows:
 *
 *   probe.artifacts.script_graph  device => ['v' => 1, 'handles' => [['handle' => h, 'deps' => [...]], ...]]
 *   probe.artifacts.image_sizes   [['path' => 'wp-content/uploads/...', 'w' => n, 'h' => n], ...]
 */
if (!class_exists('wps_ic_doctor_assets')) {
    class wps_ic_doctor_assets extends wps_ic_doctor_compartment
    {
        const NAME = 'assets';
        const TITLE = 'Scripts and images';
        const SYMPTOMS = 'a script runs before one it needs, or an image has no size';
        const EVENTS = [];

        /** The schema of the graph comment this reads. */
        const GRAPH_VERSION = 1;
        /** The most handles one device's graph carries. */
        const MAX_HANDLES = 2000;
        /** The most upload image files a probe reads the size of. */
        const MAX_IMAGES = 200;
        /** The most seconds a probe spends reading image files. */
        const IMAGE_SECONDS = 4;
        /** The file types whose size is read. */
        const IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

        public function collect(wps_ic_doctor_context $ctx)
        {
            $facts = [];
            $scripts = isset($GLOBALS['wp_scripts']) && is_object($GLOBALS['wp_scripts']) ? $GLOBALS['wp_scripts'] : null;
            $registered = $scripts !== null && isset($scripts->registered) && is_array($scripts->registered) ? count($scripts->registered) : null;
            $facts[] = self::fact('script registry in this request', $registered === null ? self::unavailable('wp_scripts') : $registered . ' handles registered', 'global:wp_scripts');

            $uploads = self::uploads();
            $facts[] = self::fact('uploads', $uploads === null ? self::unavailable('wp_upload_dir') : ['url' => $uploads['url'], 'dir' => self::relative($uploads['dir']), 'readable' => is_dir($uploads['dir'])], 'fn:wp_upload_dir');
            $reader = function_exists('getimagesize');
            $facts[] = self::fact('image size reader', $reader ? 'getimagesize' : self::unavailable('getimagesize'), 'fn:getimagesize');
            $facts[] = self::fact('the probe reads', 'this page with disableWPC=true&wpc_script_graph=1 per device, then up to ' . self::MAX_IMAGES . ' upload image files', 'probe');

            if ($uploads === null || !$reader || !is_dir($uploads['dir'])) {
                $verdict = self::verdict('warn', 'cannot-read-images', 'the uploads folder or getimagesize is not available here: the image sizes cannot be read');
            } else {
                $verdict = self::verdict('ok', 'ready', 'the script graph and the upload image sizes are read by the probe: run with probes');
            }
            return ['verdict' => $verdict, 'facts' => $facts, 'artifacts' => []];
        }

        /**
         * One cookie-less GET of <url>?disableWPC=true&wpc_script_graph=1 per device: the script
         * graph read from the comment the site prints, and the size of each upload image the page
         * names, read from the file. The page body never leaves this method.
         */
        public function probe(wps_ic_doctor_context $ctx)
        {
            $started = time();
            $out = ['note' => 'a logged-out render of this site with the plugin off; nothing is stored', 'started' => gmdate('Y-m-d H:i:s\Z', $started)];
            if (!function_exists('wp_remote_get')) {
                $out['error'] = 'unavailable: wp_remote_get not loaded';
                return $out;
            }
            $page = explode('#', $ctx->url, 2)[0];
            $url = $page . (strpos($page, '?') === false ? '?' : '&') . 'disableWPC=true&wpc_script_graph=1';
            $home = function_exists('home_url') ? (string) home_url('/') : $ctx->url;
            $uploads = self::uploads();
            $graphs = [];
            $paths = [];
            foreach ($ctx->devices as $device) {
                $row = wps_ic_doctor_cache::fetch_once($url, $device);
                $html = isset($row['body']) ? (string) $row['body'] : '';
                unset($row['body']);
                $graph = self::parse_graph($html);
                if ($graph === null) {
                    $row['graph'] = 'none: the page carries no wpc-script-graph comment (an error page, a redirect, or a theme that never calls wp_footer)';
                } else {
                    $row['graph'] = ['handles' => count($graph['handles'])];
                    $graphs[$device] = $graph;
                }
                if ($uploads !== null) {
                    foreach (self::upload_paths($html, $home, $uploads['url']) as $path) {
                        $paths[$path] = true;
                    }
                }
                $out[$device] = $row;
            }
            $rows = [];
            if ($uploads === null || !function_exists('getimagesize')) {
                $out['images'] = 'unavailable: ' . ($uploads === null ? 'wp_upload_dir' : 'getimagesize') . ' not loaded';
            } else {
                $seconds = isset($ctx->opts['assets_seconds']) ? (float) $ctx->opts['assets_seconds'] : self::IMAGE_SECONDS;
                $read = self::read_sizes(array_keys($paths), $uploads, rtrim((string) parse_url($home, PHP_URL_PATH), '/'), $seconds);
                $rows = $read['rows'];
                $out['images'] = $read['summary'];
            }
            $out['artifacts'] = ['script_graph' => $graphs, 'image_sizes' => $rows];
            $out['ended'] = gmdate('Y-m-d H:i:s\Z');
            return $out;
        }

        /**
         * The graph in a page's <!-- wpc-script-graph: {json} --> comment (the last one) as
         * ['v' => 1, 'handles' => [['handle' => h, 'deps' => [...]], ...]], or null when the page
         * carries none or it does not read as this schema.
         */
        public static function parse_graph($html)
        {
            $open = '<!-- wpc-script-graph: ';
            $start = strrpos((string) $html, $open);
            if ($start === false) {
                return null;
            }
            $from = $start + strlen($open);
            $end = strpos($html, '-->', $from);
            if ($end === false) {
                return null;
            }
            $decoded = json_decode(trim(substr($html, $from, $end - $from)), true);
            if (!is_array($decoded) || ($decoded['v'] ?? null) !== self::GRAPH_VERSION || !isset($decoded['handles']) || !is_array($decoded['handles'])) {
                return null;
            }
            $rows = [];
            foreach ($decoded['handles'] as $handle => $deps) {
                if (!is_array($deps) || count($rows) >= self::MAX_HANDLES) {
                    continue;
                }
                $names = [];
                foreach ($deps as $dep) {
                    if (is_string($dep)) {
                        $names[] = $dep;
                    }
                }
                $rows[] = ['handle' => (string) $handle, 'deps' => $names];
            }
            return ['v' => self::GRAPH_VERSION, 'handles' => $rows];
        }

        /**
         * The distinct paths of the upload images a page names in src, srcset and data-src of its
         * img and source tags, in the order found: the host of $homeUrl or of $uploadsUrl, under $uploadsUrl's
         * path, as written (percent-encoding kept, no query).
         */
        public static function upload_paths($html, $homeUrl, $uploadsUrl)
        {
            $host = array_values(array_unique(array_filter([strtolower((string) parse_url($homeUrl, PHP_URL_HOST)), strtolower((string) parse_url($uploadsUrl, PHP_URL_HOST))])));
            $base = rtrim((string) parse_url($uploadsUrl, PHP_URL_PATH), '/');
            $found = [];
            if ($host === [] || $base === '' || !preg_match_all('/<(?:img|source)\b[^>]*>/i', (string) $html, $tags)) {
                return [];
            }
            foreach ($tags[0] as $tag) {
                if (!preg_match_all('/(?<![\w-])(src|srcset|data-src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $attrs, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($attrs as $attr) {
                    $value = html_entity_decode(isset($attr[3]) && $attr[3] !== '' ? $attr[3] : $attr[2], ENT_QUOTES);
                    $candidates = strtolower($attr[1]) === 'srcset' ? preg_split('/(?<=\d[wx]),|\s*,\s+/', trim($value)) : [$value];
                    foreach ($candidates as $candidate) {
                        $parts = preg_split('/\s+/', trim((string) $candidate));
                        $path = self::upload_path((string) $parts[0], $host, $base);
                        if ($path !== null) {
                            $found[$path] = true;
                        }
                    }
                }
            }
            return array_keys($found);
        }

        /** The path of one URL when it is this host's and under the uploads path; null otherwise. */
        private static function upload_path($url, $host, $base)
        {
            $url = rtrim($url, ',');
            if ($url === '' || stripos($url, 'data:') === 0) {
                return null;
            }
            $parts = parse_url($url);
            if (!is_array($parts) || empty($parts['path']) || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true))) {
                return null;
            }
            if (isset($parts['host']) ? !in_array(strtolower($parts['host']), $host, true) : $url[0] !== '/') {
                return null;
            }
            return strpos($parts['path'], $base . '/') === 0 ? $parts['path'] : null;
        }

        /** The uploads folder and URL as WordPress answers them, without creating a folder; null when it cannot. */
        private static function uploads()
        {
            if (!function_exists('wp_upload_dir')) {
                return null;
            }
            $dir = wp_upload_dir(null, false);
            if (!is_array($dir) || empty($dir['basedir']) || empty($dir['baseurl'])) {
                return null;
            }
            return ['dir' => rtrim(str_replace('\\', '/', (string) $dir['basedir']), '/'), 'url' => (string) $dir['baseurl']];
        }

        /**
         * The size of each upload file in $paths (percent-encoded URL paths under the uploads
         * path), read with getimagesize: ['rows' => [['path' => relative to the site root, 'w', 'h']],
         * 'summary' => counts]. A path that leaves the uploads folder or is not an image type is
         * skipped; at most MAX_IMAGES files are read, and none after $seconds have passed.
         */
        private static function read_sizes(array $paths, array $uploads, $homePath, $seconds)
        {
            $basePath = rtrim((string) parse_url($uploads['url'], PHP_URL_PATH), '/');
            $root = realpath($uploads['dir']);
            $root = $root === false ? '' : rtrim(str_replace('\\', '/', $root), '/');
            $deadline = microtime(true) + $seconds;
            $rows = [];
            $summary = ['found' => count($paths), 'sized' => 0, 'unreadable' => 0, 'skipped' => 0, 'capped' => false, 'out_of_time' => false];
            $read = 0;
            foreach ($paths as $path) {
                $relative = rawurldecode(substr($path, strlen($basePath)));
                if (strpos($relative, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $relative) || !in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::IMAGE_TYPES, true)) {
                    $summary['skipped']++;
                    continue;
                }
                if ($read >= self::MAX_IMAGES) {
                    $summary['capped'] = true;
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $summary['out_of_time'] = true;
                    break;
                }
                $read++;
                $file = @realpath($uploads['dir'] . $relative);
                $file = $file === false ? '' : str_replace('\\', '/', $file);
                $size = $root !== '' && strpos($file, $root . '/') === 0 && is_file($file) ? @getimagesize($file) : false;
                if (!is_array($size) || (int) $size[0] < 1 || (int) $size[1] < 1) {
                    $summary['unreadable']++;
                    continue;
                }
                $key = ltrim($homePath !== '' && strpos($path, $homePath . '/') === 0 ? substr($path, strlen($homePath)) : $path, '/');
                $rows[] = ['path' => $key, 'w' => (int) $size[0], 'h' => (int) $size[1]];
                $summary['sized']++;
            }
            return ['rows' => $rows, 'summary' => $summary];
        }
    }
}
