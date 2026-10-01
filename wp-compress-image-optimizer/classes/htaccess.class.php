<?php

class wps_ic_htaccess extends wps_ic
{

    /** The insert_with_markers name of the next-gen negotiation block. */
    const WEBP_MARKER = 'WPC Serve WebP';
    public $htaccessPath;
    public $configPath;
    public $advancedCachePath;
    public $htaccessContent;
    public $isApache;

    public function __construct()
    {

        if (is_admin()) {
            $this->isApache();
        }
    }


    public function isApache() {
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'];
        if (!empty($serverSoftware)) {
            if (strpos(strtolower($serverSoftware), 'litespeed') !== false || strpos(strtolower($serverSoftware), 'apache') !== false) {
                $this->isApache = true;
            } else if (strpos(strtolower($serverSoftware), 'nginx') !== false) {
                $this->isApache = false;
            }
        } else {
            $this->isApache = false;
        }

        return $this->isApache;
    }


    public function addGzip()
    {
        $error = false;
        $this->htaccessPath = $this->getHtaccessPath();
        if (empty($this->htaccessPath)) {
            return;
        }

        // Is the file writeable?
        if ($this->exists($this->htaccessPath) && !$this->isWriteable($this->htaccessPath)) {
            $error = true;
            $this->notice('not-writeable-htaccess');
        }

        // Is the file readable?
        if ($this->exists($this->htaccessPath) && !$this->isReadble($this->htaccessPath)) {
            $error = true;
            $this->notice('not-readable-htaccess');
        }

        if ($error) return;

        // Get Contents
        $this->htaccessContent = $this->getContents($this->htaccessPath);

        // Did we retrieve the correct htaccess content?
        if (!empty($this->htaccessContent)) {

            // Check if gzip rules already exist
            if (strpos($this->htaccessContent, 'mod_deflate') === false) {

                $rules = $this->modifyModDeflate();

                // Prepare new content (append)
                $newHtaccessContent = rtrim($this->htaccessContent) . "\n\n" . $rules;

                // Only write if content actually changed
                if (!empty($newHtaccessContent) && $newHtaccessContent !== $this->htaccessContent) {
                    wpc_fs_put($this->htaccessPath, $newHtaccessContent);
                }
            }
        }

    }

    public function getHtaccessPath()
    {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $htaccess_file = get_home_path() . '.htaccess';

        if (empty($htaccess_file) || !file_exists($htaccess_file)) {
            return false;
        }

        return $htaccess_file;
    }

    public function exists($path)
    {
        if ($this->fileSystem()->exists($path)) {
            return true;
        }

        return false;
    }

    public function fileSystem()
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        return new WP_Filesystem_Direct(new stdClass());
    }

    public function isWriteable($path)
    {
        if ($this->fileSystem()->is_writable($path)) {
            return true;
        }

        return false;
    }

    public function notice($what)
    {
        if (!function_exists('wpc_set_state_notice')) {
            return;
        }
        $what = (string) $what;
        $map = [
            'not-readable-config'     => 'The wp-config.php file could not be read',
            'not-readable-htaccess'   => 'The .htaccess file could not be read',
            'not-readable-adv-cache'  => 'The advanced-cache.php file could not be read',
            'not-readable'            => 'Some plugin files could not be read',
            'not-writeable-config'    => 'The wp-config.php file could not be written',
            'not-writeable-adv-cache' => 'The advanced-cache.php file could not be written',
            'not-writeable-htaccess'  => 'The .htaccess file could not be written',
            'browser-cache-failed'    => 'The browser-cache rules were rolled back because the server rejected them',
        ];
        $why = isset($map[$what]) ? $map[$what] : 'A file could not be accessed';
        wpc_set_state_notice('fs_' . sanitize_key($what), 'warning', sprintf(__('%s. The related caching feature is paused until file permissions allow it.', 'wp-compress-image-optimizer'), $why));
    }

    public function isReadble($path)
    {
        if ($this->fileSystem()->is_readable($path)) {
            return true;
        }

        return false;
    }

    public function getContents($path)
    {
        return $this->fileSystem()->get_contents($path);
    }

    public function checkHtaccess()
    {
        $error = false;
        $this->htaccessPath = $this->getHtaccessPath();

        if (empty($this->htaccessPath) || !$this->isApache) {
            return;
        }

        // Is the file writeable?
        if ($this->exists($this->htaccessPath) && !$this->isWriteable($this->htaccessPath)) {
            $error = true;
            $this->notice('not-writeable-htaccess');
        }

        // Is the file readable?
        if ($this->exists($this->htaccessPath) && !$this->isReadble($this->htaccessPath)) {
            $error = true;
            $this->notice('not-readable-htaccess');
        }

        if ($error) return;

        // Get Contents
        $this->htaccessContent = $this->getContents($this->htaccessPath);

        // Did we retrieve the correct htaccess content?
        if (!empty($this->htaccessContent)) {
            // Does it already have modifications?

            if (!$this->hasRewriteMods() || !empty($_GET['rebuildHtaccess'])) {
                $this->modifyHtaccess();
            }

            // Remove Mods Fix
            if ($this->hasRewriteMods() && !empty($_GET['removeHtaccess'])) {
                // Remove HtAccess Rules
                $this->removeHtaccessRules();
            }
        }
    }

    public function hasRewriteMods()
    {
        if (strpos($this->htaccessContent, '#StartWPC-Cache') !== false) {
            return true;
        }

        return false;
    }

    public function modifyHtaccess()
    {

        return;

        if (!$this->isApache) {
            return;
        }

        $removeExistingRules = preg_replace('/\s*#StartWPC-Cache.*#EndWPC-Cache\s*?/isU', PHP_EOL . PHP_EOL, $this->htaccessContent);
        $cleanedHtaccessContent = ltrim($removeExistingRules);
        $newHtaccessContent = $this->getHtaccessRules() . PHP_EOL . $cleanedHtaccessContent;
        if (!empty($newHtaccessContent)) {
            if (!defined('FS_CHMOD_FILE')) {
                define('FS_CHMOD_FILE', 0644);
            }

            $this->fileSystem()->put_contents($this->getHtaccessPath(), $newHtaccessContent);
        }
    }


    const WPC_BC_START = '#StartWPC-BrowserCache';
    const WPC_BC_END   = '#EndWPC-BrowserCache';

    public function wpcBrowserCacheEnabled()
    {
        if (defined('WPC_BROWSER_CACHE_DISABLE') && WPC_BROWSER_CACHE_DISABLE) {
            return false;
        }
        $s  = get_option(WPS_IC_SETTINGS);
        $on = is_array($s) && !empty($s['browser-cache-headers']) && $s['browser-cache-headers'] == '1';
        return (bool) apply_filters('wpc_browser_cache_headers', $on);
    }

    public function wpcBrowserCacheRules()
    {
        $s  = self::WPC_BC_START . PHP_EOL;
        $s .= '# WP Compress — compression + browser caching for static assets (HTML is never cached).' . PHP_EOL;
        $s .= '<IfModule mod_deflate.c>' . PHP_EOL;
        $s .= '  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/x-javascript application/json application/xml application/rss+xml application/vnd.ms-fontobject image/svg+xml font/ttf font/otf font/opentype' . PHP_EOL;
        $s .= '</IfModule>' . PHP_EOL;
        $s .= '<IfModule mod_expires.c>' . PHP_EOL;
        $s .= '  ExpiresActive On' . PHP_EOL;
        $s .= '  ExpiresByType text/html "access plus 0 seconds"' . PHP_EOL;
        foreach ([
            'text/css', 'application/javascript', 'application/x-javascript', 'text/javascript',
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'image/x-icon',
            'font/woff2', 'font/woff', 'application/font-woff2', 'application/vnd.ms-fontobject', 'application/x-font-ttf', 'font/otf',
        ] as $mime) {
            $s .= '  ExpiresByType ' . $mime . ' "access plus 1 year"' . PHP_EOL;
        }
        $s .= '</IfModule>' . PHP_EOL;
        $s .= '<IfModule mod_headers.c>' . PHP_EOL;
        $s .= '  <FilesMatch "\.(css|js|jpe?g|png|gif|webp|avif|svgz?|ico|woff2?|ttf|otf|eot)$">' . PHP_EOL;
        $s .= '    Header set Cache-Control "public, max-age=31536000"' . PHP_EOL;
        $s .= '  </FilesMatch>' . PHP_EOL;
        $s .= '</IfModule>' . PHP_EOL;
        $s .= self::WPC_BC_END . PHP_EOL;
        return (string) apply_filters('wpc_browser_cache_rules', $s);
    }

    private function wpcStripBcBlock($content)
    {
        return preg_replace(
            '/\s*' . preg_quote(self::WPC_BC_START, '/') . '.*?' . preg_quote(self::WPC_BC_END, '/') . '\s*/is',
            PHP_EOL,
            (string) $content
        );
    }

    public function wpcApplyBrowserCache($force = false)
    {
        try {
            if (!$force && !$this->wpcBrowserCacheEnabled()) {
                return false;
            }
            if (!$this->isApache()) {
                return false;
            }
            $path = $this->getHtaccessPath();
            if (empty($path) || !$this->isWriteable($path)) {
                $this->notice('not-writeable-htaccess');
                return false;
            }
            $content = (string) $this->getContents($path);
            if ($content === '') {
                return false;
            }
            // Append our block LAST (idempotent: strip any prior copy first). WordPress' managed
            // rewrite block runs first for routing; cache headers are order-independent.
            $desired = rtrim($this->wpcStripBcBlock($content)) . PHP_EOL . PHP_EOL . $this->wpcBrowserCacheRules();
            if (trim($desired) === trim($content)) {
                return true;
            }

            wpc_fs_put($path . '.wpc-bcbak', $content);
            if (wpc_fs_put($path, $desired) === false) {
                return false;
            }


            $wpc_probes = [
                add_query_arg('wpc_bc_probe', (string) time(), home_url('/')),
                site_url('wp-login.php'),
            ];
            $wpc_bad = false; $wpc_any_ok = false; $wpc_last = 0;
            foreach ($wpc_probes as $wpc_pu) {
                $r = wp_remote_get($wpc_pu, [
                    'timeout'     => (int) apply_filters('wpc_browser_cache_probe_timeout', 8),
                    'redirection' => 0,
                    'sslverify'   => false,
                    'headers'     => ['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'],
                ]);
                $wpc_last = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
                if ($wpc_last >= 500) { $wpc_bad = true; break; }
                if ($wpc_last >= 200 && $wpc_last < 500) { $wpc_any_ok = true; } // 200/302/401/403 = Apache parsed .htaccess OK
            }
            if ($wpc_bad || !$wpc_any_ok) {
                wpc_fs_put($path, $content);
                $s = get_option(WPS_IC_SETTINGS);
                if (is_array($s)) { $s['browser-cache-headers'] = '0'; update_option(WPS_IC_SETTINGS, $s); }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('browser-cache-rollback', '', '', ['code' => $wpc_last]);
                }
                $this->notice('browser-cache-failed');
                return false;
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('browser-cache-applied', '', '', ['code' => $wpc_last]);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function wpcRemoveBrowserCache()
    {
        try {
            if (!$this->isApache()) {
                return false;
            }
            $path = $this->getHtaccessPath();
            if (empty($path) || !$this->isWriteable($path)) {
                return false;
            }
            $content = (string) $this->getContents($path);
            if (strpos($content, self::WPC_BC_START) === false) {
                return true;
            }
            wpc_fs_put($path, rtrim($this->wpcStripBcBlock($content)) . PHP_EOL);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getHtaccessRules()
    {
        $output = '#StartWPC-Cache' . PHP_EOL;
        $output .= $this->modifyGetCharset();
        $output .= $this->modifyGetEtag();
        $output .= $this->modifyGetFontsCORS();
        $output .= $this->modifyCacheControl();
        $output .= $this->modifyModExpires();
        $output .= $this->modifyModDeflate();
        $output .= $this->modifyForCaching();
        $output .= '#EndWPC-Cache' . PHP_EOL;
        return $output;
    }

    public function modifyGetCharset()
    {
        $charset = preg_replace('/[^a-zA-Z0-9_\-\.:]+/', '', get_bloginfo('charset', 'display'));

        if (empty($charset)) {
            return '';
        }

        $rules = "# Use defined encoding for anything served text/plain or text/html" . PHP_EOL;
        $rules .= "AddDefaultCharset $charset" . PHP_EOL;
        $rules .= "# Force defined encoding for file formats" . PHP_EOL;
        $rules .= '<IfModule mod_mime.c>' . PHP_EOL;
        $rules .= "AddCharset $charset .atom .css .js .json .rss .vtt .xml" . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
        return $rules;
    }

    public function modifyGetEtag()
    {
        $rules = '# FileETag None is not enough for all servers' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header unset ETag' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
        $rules .= '# Since we are sending far dated expires, we do not required ETags for that static content.' . PHP_EOL;
        $rules .= 'FileETag None' . PHP_EOL . PHP_EOL;
        return $rules;
    }

    public function modifyGetFontsCORS()
    {
        $rules = '# Send CORS headers when browsers request them.' . PHP_EOL;
        $rules .= '<IfModule mod_setenvif.c>' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= '# mod_headers - no match by Content-Type?!' . PHP_EOL;
        $rules .= '<FilesMatch "\.(avifs?|cur|gif|png|jpe?g|svgz?|ico|webp)$">' . PHP_EOL;
        $rules .= 'SetEnvIf Origin ":" IS_CORS' . PHP_EOL;
        $rules .= 'Header set Access-Control-Allow-Origin "*" env=IS_CORS' . PHP_EOL;
        $rules .= '</FilesMatch>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;

        $rules .= '# Allow Access to Web Fonts for CORS.' . PHP_EOL;
        $rules .= '<FilesMatch "\.(eot|otf|tt[cf]|woff2?)$">' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header set Access-Control-Allow-Origin "*"' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</FilesMatch>' . PHP_EOL . PHP_EOL;
        return $rules;
    }

    public function modifyCacheControl()
    {
        $rules = '<IfModule mod_alias.c>' . PHP_EOL;
        $rules .= '<FilesMatch "\.(html|htm|rtf|rtx|txt|xsd|xsl|xml)$">' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header set X-Powered-By "WP Compress Cache"' . PHP_EOL;
        $rules .= 'Header set Expires "3600"' . PHP_EOL;
        $rules .= 'Header set X-Cache "HIT"' . PHP_EOL;
        $rules .= 'Header set X-Cache-Enabled "True"' . PHP_EOL;
        $rules .= 'Header unset Pragma' . PHP_EOL;
        $rules .= 'Header set Cache-Control "max-age=86400, public"' . PHP_EOL;
        $rules .= 'Header unset Last-Modified' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</FilesMatch>' . PHP_EOL . PHP_EOL;
        $rules .= '<FilesMatch "\.(css|js)$">' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header set Cache-Control "public, max-age=31536000"' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</FilesMatch>' . PHP_EOL;
        $rules .= '<FilesMatch "\.(htc|js|asf|asx|wax|wmv|wmx|avi|bmp|class|divx|doc|docx|eot|exe|gif|gz|gzip|ico|jpg|jpeg|jpe|json|mdb|mid|midi|mov|qt|mp3|m4a|mp4|m4v|mpeg|mpg|mpe|mpp|otf|odb|odc|odf|odg|odp|ods|odt|ogg|pdf|png|pot|pps|ppt|pptx|ra|ram|svg|svgz|swf|tar|tif|tiff|ttf|ttc|wav|wma|wri|xla|xls|xlsx|xlt|xlw|zip)$">' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header unset Pragma' . PHP_EOL;
        $rules .= 'Header append Cache-Control "public"' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</FilesMatch>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
        return $rules;
    }

    public function modifyModExpires()
    {
        $rules = <<<HTACCESS
<IfModule mod_mime.c>
	AddType image/avif                                  avif
    AddType image/avif-sequence                         avifs
</IfModule>
# Expires headers (for better cache control)
<IfModule mod_expires.c>
	ExpiresActive on
	ExpiresDefault                              "access plus 1 month"
	# cache.appcache
	ExpiresByType text/cache-manifest           "access plus 0 seconds"
	# Your document html
	ExpiresByType text/html                     "access plus 0 seconds"
	# Data
	ExpiresByType text/xml                      "access plus 0 seconds"
	ExpiresByType application/xml               "access plus 0 seconds"
	ExpiresByType application/json              "access plus 0 seconds"
	# Feed
	ExpiresByType application/rss+xml           "access plus 1 hour"
	ExpiresByType application/atom+xml          "access plus 1 hour"
	# Favicon (cannot be renamed)
	ExpiresByType image/x-icon                  "access plus 1 year"
	# Media: images, video, audio
	ExpiresByType image/gif                     "access plus 4 months"
	ExpiresByType image/png                     "access plus 4 months"
	ExpiresByType image/jpeg                    "access plus 4 months"
	ExpiresByType image/webp                    "access plus 4 months"
	ExpiresByType video/ogg                     "access plus 4 months"
	ExpiresByType audio/ogg                     "access plus 4 months"
	ExpiresByType video/mp4                     "access plus 4 months"
	ExpiresByType video/webm                    "access plus 4 months"
	ExpiresByType image/avif                    "access plus 4 months"
	ExpiresByType image/avif-sequence           "access plus 4 months"
	# HTC files  (css3pie)
	ExpiresByType text/x-component              "access plus 1 month"
	# Webfonts
	ExpiresByType font/ttf                      "access plus 4 months"
	ExpiresByType font/otf                      "access plus 4 months"
	ExpiresByType font/woff                     "access plus 4 months"
	ExpiresByType font/woff2                    "access plus 4 months"
	ExpiresByType image/svg+xml                 "access plus 4 months"
	ExpiresByType application/vnd.ms-fontobject "access plus 1 month"
	# CSS and JavaScript
	ExpiresByType text/css                      "access plus 1 year"
	ExpiresByType application/javascript        "access plus 1 year"
</IfModule>

HTACCESS;
        return $rules;
    }

    public function modifyModDeflate()
    {
        $rules = '# Enable GZIP' . PHP_EOL;
        $rules .= '<IfModule mod_deflate.c>' . PHP_EOL;
        $rules .= '# Activate Compression' . PHP_EOL;
        $rules .= 'SetOutputFilter DEFLATE' . PHP_EOL;
        $rules .= '# Force deflate for mangled headers' . PHP_EOL;
        $rules .= '<IfModule mod_setenvif.c>' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'SetEnvIfNoCase ^(Accept-EncodXng|X-cept-Encoding|X{15}|~{15}|-{15})$ ^((gzip|deflate)\s*,?\s*)+|[X~-]{4,13}$ HAVE_Accept-Encoding' . PHP_EOL;
        $rules .= 'RequestHeader append Accept-Encoding "gzip,deflate" env=HAVE_Accept-Encoding' . PHP_EOL;
        $rules .= '# Do not compress uncompresible content' . PHP_EOL;
        $rules .= 'SetEnvIfNoCase Request_URI \\' . PHP_EOL;
        $rules .= '\\.(?:gif|jpe?g|png|rar|zip|exe|flv|mov|wma|mp3|avi|swf|mp?g|mp4|webm|webp|pdf)$ no-gzip dont-vary' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
        $rules .= '# Compress All output with MIME-types' . PHP_EOL;
        $rules .= '<IfModule mod_filter.c>' . PHP_EOL;
        $rules .= 'AddOutputFilterByType DEFLATE application/atom+xml \
		                          application/javascript \
		                          application/json \
		                          application/rss+xml \
		                          application/vnd.ms-fontobject \
		                          application/x-font-ttf \
		                          application/xhtml+xml \
		                          application/xml \
		                          font/opentype \
		                          image/svg+xml \
		                          image/x-icon \
		                          text/css \
		                          text/html \
		                          text/plain \
		                          text/x-component \
		                          text/xml' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header append Vary: Accept-Encoding' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
        return $rules;
    }

    public function modifyForCaching()
    {
        // Multisite does not require rewrite rules
        if (is_multisite()) {
            return;
        }

        // Korean is having problems, does not require rules
        if ('ko_KR' === get_locale() || (defined('WPLANG') && 'ko_KR' === WPLANG)) {
            return;
        }

        // Get root base.
        $homeRoot = $this->extractUrlComponent(home_url(), PHP_URL_PATH);
        $homeRoot = isset($homeRoot) ? trailingslashit($homeRoot) : '/';

        $siteRoot = $this->extractUrlComponent(site_url(), PHP_URL_PATH);
        $siteRoot = isset($siteRoot) ? trailingslashit($siteRoot) : '';

        if (strpos(WPS_IC_CACHE, ABSPATH) === false && isset($_SERVER['DOCUMENT_ROOT'])) {
            $cacheRoot = '/' . ltrim(str_replace(sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT'])), '', WPS_IC_CACHE), '/');
        } else {
            $cacheRoot = '/' . ltrim($siteRoot . str_replace(ABSPATH, '', WPS_IC_CACHE), '/');
        }


        $wpc_abs_cache = @realpath(WPS_IC_CACHE);
        $wpc_abs_dr    = isset($_SERVER['DOCUMENT_ROOT']) ? @realpath($_SERVER['DOCUMENT_ROOT']) : '';
        if ($wpc_abs_cache && $wpc_abs_dr && strpos($wpc_abs_cache, $wpc_abs_dr) === 0) {
            $cacheRoot = '/' . trim(str_replace('\\', '/', substr($wpc_abs_cache, strlen($wpc_abs_dr))), '/') . '/';
        }


        $http_host = $this->removeUrlProtocol(home_url());

        $rules = '';
        $gzip_rules = '';
        $enc = '';

        $cache_dir_path = ($wpc_abs_cache ? rtrim($wpc_abs_cache, '/') . '/' : '%{DOCUMENT_ROOT}/' . ltrim($cacheRoot, '/')) . $http_host . '%{REQUEST_URI}';
        update_option('wpc_static_serve_cond', $cache_dir_path . ' | target=' . $cacheRoot . $http_host, false);

        if (function_exists('gzencode')) {
            $rules = '<IfModule mod_mime.c>' . PHP_EOL;
            $rules .= 'AddType text/html .html_gzip' . PHP_EOL;
            $rules .= 'AddEncoding gzip .html_gzip' . PHP_EOL;
            $rules .= '</IfModule>' . PHP_EOL;
            $rules .= '<IfModule mod_setenvif.c>' . PHP_EOL;
            $rules .= 'SetEnvIfNoCase Request_URI \.html_gzip no-gzip' . PHP_EOL;
            $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
            $gzip_rules .= 'RewriteCond %{HTTP:Accept-Encoding} gzip' . PHP_EOL;
            $gzip_rules .= 'RewriteRule .* - [E=WPC_ENC:_gzip]' . PHP_EOL;
            $enc = '%{ENV:WPC_ENC}';
        }

        $rules .= '<IfModule mod_rewrite.c>' . PHP_EOL;
        $rules .= 'RewriteEngine On' . PHP_EOL;
        $rules .= 'RewriteBase ' . $homeRoot . PHP_EOL;
        $rules .= $this->sslRewrite();
        $rules .= $this->webpRewrite($cache_dir_path);
        $rules .= $gzip_rules;

        // TODO: Exclude Mobile?
        $mobileCacheEnabled = false;
        #if (!$mobileCacheEnabled) {
        $mobilePatterns = wpc_ua_mobile_patterns();
        $rules .= 'RewriteCond %{HTTP_USER_AGENT} "' . $mobilePatterns['contains'] . '|' . $mobilePatterns['tokens'] . '" [NC,OR]' . PHP_EOL;
        $rules .= 'RewriteCond %{HTTP_USER_AGENT} "^(' . $mobilePatterns['prefix'] . ')" [NC]' . PHP_EOL;
        $rules .= 'RewriteRule .* - [E=WPC_MOBILE:mobile_]' . PHP_EOL;

        $rules .= 'RewriteCond %{REQUEST_METHOD} GET' . PHP_EOL;


        $rules .= 'RewriteCond %{HTTP:X-WPC-Cache-Warm} ^$' . PHP_EOL;
        $rules .= 'RewriteCond %{QUERY_STRING} ^$' . PHP_EOL;

        $cookies = $this->rejectCookies();
        if ($cookies) {
            $rules .= 'RewriteCond %{HTTP:Cookie} !(' . $cookies . ') [NC]' . PHP_EOL;
        }

        $rules .= 'RewriteCond "' . $cache_dir_path . '/%{ENV:WPC_MOBILE}index.html' . $enc . '" -s' . PHP_EOL;
        $rules .= 'RewriteRule .* "' . $cacheRoot . $http_host . '%{REQUEST_URI}/%{ENV:WPC_MOBILE}index.html' . $enc . '" [L]' . PHP_EOL;
        #}

        $rules .= 'RewriteCond %{REQUEST_METHOD} GET' . PHP_EOL;


        $rules .= 'RewriteCond %{HTTP:X-WPC-Cache-Warm} ^$' . PHP_EOL;
        $rules .= 'RewriteCond %{QUERY_STRING} ^$' . PHP_EOL;

        // v7.10.682 — the desktop-file fallback must NEVER serve a mobile UA. Serving the desktop
        // mirror to a mobile visitor bypasses PHP, so the mobile variant is never rendered and the
        // page stays device-blind PERMANENTLY (and a device-bucketed edge in front caches that
        // wrong copy per bucket — the wpcompress mobile-got-desktop-crit vector). A mobile UA with
        // no mobile variant falls through to PHP, which renders AND writes the mobile copy once.
        $rules .= 'RewriteCond %{ENV:WPC_MOBILE} ^$' . PHP_EOL;

        #$cookies = $this->rejectCookies();
        if ($cookies) {
            $rules .= 'RewriteCond %{HTTP:Cookie} !(' . $cookies . ') [NC]' . PHP_EOL;
        }

        // TODO: Excluded URLs from Cache?
        $excludedCacheUrls = false;
        if ($excludedCacheUrls) {
            $rules .= 'RewriteCond %{REQUEST_URI} !^(' . $excludedCacheUrls . ')$ [NC]' . PHP_EOL;
        }

        $rules .= 'RewriteCond "' . $cache_dir_path . '/index.html' . $enc . '" -s' . PHP_EOL;
        $rules .= 'RewriteRule .* "' . $cacheRoot . $http_host . '%{REQUEST_URI}/index.html' . $enc . '" [L]' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        return $rules;
    }


    /** Write the #StartWPC-StaticServe block (modifyForCaching rules), replacing any prior one. */
    private function writeStaticServeBlock()
    {
        $rules = $this->modifyForCaching();
        if (empty($rules)) {
            return false;
        }
        $content = (string) $this->getContents($this->htaccessPath);
        $content = ltrim((string) preg_replace('/\s*#StartWPC-StaticServe.*#EndWPC-StaticServe\s*?/isU', PHP_EOL, $content));
        $block   = '#StartWPC-StaticServe' . PHP_EOL . $rules . '#EndWPC-StaticServe' . PHP_EOL . PHP_EOL;
        return $this->fileSystem()->put_contents($this->htaccessPath, $block . $content);
    }

    /** Enable static serve: write rules → self-test → keep, or auto-rollback if the host doesn't serve it. */
    public function applyStaticServe()
    {
        $was_active  = (int) get_option('wpc_static_serve_active', 0) === 1;
        $this->htaccessPath = $this->getHtaccessPath();
        if (empty($this->htaccessPath)) {
            return ['ok' => false, 'reason' => 'no-htaccess'];
        }
        if (!$this->isApache) {
            return ['ok' => false, 'reason' => 'not-apache'];
        }
        if (!$this->isWriteable($this->htaccessPath)) {
            $this->notice('not-writeable-htaccess');
            return ['ok' => false, 'reason' => 'not-writeable'];
        }


        $wpc_dr = !empty($_SERVER['DOCUMENT_ROOT']) ? @realpath($_SERVER['DOCUMENT_ROOT']) : '';
        $wpc_ap = @realpath(ABSPATH);
        if ($wpc_dr && $wpc_ap && strpos($wpc_ap, $wpc_dr) !== 0 && strpos($wpc_dr, $wpc_ap) !== 0) {
            update_option('wpc_static_serve_failed', 'docroot-mismatch', false);
            return ['ok' => false, 'reason' => 'docroot-mismatch'];
        }
        if ($this->writeStaticServeBlock() === false) {
            return ['ok' => false, 'reason' => 'write-failed'];
        }


        if (class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'ensureStaticMirrorHeaderHtaccess')) {
            wps_cacheHtml::ensureStaticMirrorHeaderHtaccess();
        }
        // The load-bearing safety: prove a REAL zero-PHP static serve works on THIS host before trusting it.
        if (!$this->staticServeSelfTest()) {
            $this->removeStaticServe(); // ROLLBACK → site stays on the PHP serve, nothing broken
            $wpc_probe = get_option('wpc_static_serve_probe');
            return ['ok' => false, 'reason' => $wpc_probe ? 'selftest failed — ' . $wpc_probe : 'selftest-failed-reverted'];
        }
        update_option('wpc_static_serve_active', 1, false);
        delete_option('wpc_static_serve_failed');


        // v7.10.530 — the purge+warm tail is a TRANSITION cost, not a steady-state one. Receipted
        // firing from admin_init on every admin request (uri=admin-ajax?action=wpc_perf_debug), so
        // each one wiped the whole HTML tree and fanned out a warm while visitors were rendering.
        // Only pay it when this call actually flipped the site from off to on.
        if (empty($was_active)) {
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                try {
                    wps_ic_cache::removeHtmlCacheFiles('all');
                } catch (\Throwable $e) {
                }
            }
            if (function_exists('wpc_warm_url_queue')) {
                wpc_warm_url_queue(home_url('/'), 'static-serve-enable');
            }
        }
        return ['ok' => true];
    }

    /** Remove the static-serve block (revert to PHP serve). */
    public function removeStaticServe()
    {
        $this->htaccessPath = $this->getHtaccessPath();
        delete_option('wpc_static_serve_active');
        // Any removal (manual toggle-off, self-test rollback, deactivation) also clears the
        // TTFB auto-arm flag — else the wpc_static_serve filter keeps forcing orphaned mirror
        // writes for rules that no longer exist, and the actuator's "already armed" gate
        // blocks self-heal (v7.10.357).
        delete_option('wpc_ttfb_ss_auto');
        if (empty($this->htaccessPath) || !$this->isWriteable($this->htaccessPath)) {
            return;
        }
        $content = (string) $this->getContents($this->htaccessPath);
        if (strpos($content, '#StartWPC-StaticServe') === false) {
            return;
        }
        $content = ltrim((string) preg_replace('/\s*#StartWPC-StaticServe.*#EndWPC-StaticServe\s*?/isU', PHP_EOL, $content));
        $this->fileSystem()->put_contents($this->htaccessPath, $content);
    }

    /**
     * Self-test: write a MARKER mirror at a nonce URL that has NO WordPress page, then loopback-fetch it.
     * Only the static .htaccess rule can produce that marker (WP would 404) — so a marker in the response
     * proves a zero-PHP static serve is live. Fail-safe: any failure (loopback blocked, no marker, error)
     * returns false → the caller rolls back. Cleans up the test files either way.
     */
    public function staticServeSelfTest()
    {
        if (!$this->isApache || !defined('WPS_IC_CACHE') || !function_exists('home_url')) {
            return false;
        }
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        if ($host === '') {
            return false;
        }
        $nonce  = 'wpc-static-selftest-' . substr(md5(uniqid('', true)), 0, 12);
        $marker = 'WPCSTATICOK' . $nonce;
        $dir    = WPS_IC_CACHE . $host . '/' . $nonce . '/';
        if (!file_exists($dir)) {
            @mkdir(rtrim($dir, '/'), 0777, true);
        }
        if (!is_dir($dir)) {
            return false;
        }
        $html = '<!doctype html><html><body>' . $marker . '</body></html>';
        wpc_fs_put($dir . 'index.html_gzip', gzencode($html, 8));
        wpc_fs_put($dir . 'index.html', $html);

        $resp = wp_remote_get(home_url('/' . $nonce), [
            'timeout'    => 10,
            'sslverify'  => false,
            'headers'    => ['Accept-Encoding' => 'gzip'],
            'user-agent' => 'wpc-static-selftest',
        ]);
        $ok = (!is_wp_error($resp) && strpos((string) wp_remote_retrieve_body($resp), $marker) !== false);

        // A proxy front (CF bot filtering, challenges) can eat the public loopback while
        // the host serves the rules fine — retry against the origin directly before
        // declaring failure. Marker found = the static serve is real; enable it.
        $wpc_via = '';
        if (!$ok) {
            foreach (['https://127.0.0.1/', 'http://127.0.0.1/'] as $wpc_scheme) {
                $wpc_r2 = wp_remote_get($wpc_scheme . $nonce, [
                    'timeout'    => 8,
                    'sslverify'  => false,
                    'headers'    => ['Host' => $host, 'Accept-Encoding' => 'gzip'],
                    'user-agent' => 'wpc-static-selftest',
                ]);
                if (!is_wp_error($wpc_r2) && strpos((string) wp_remote_retrieve_body($wpc_r2), $marker) !== false) {
                    $ok = true;
                    $wpc_via = $wpc_scheme;
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('static-selftest-via-origin', '', $wpc_scheme, []);
                    }
                    break;
                }
            }
        }

        if (!$ok) {
            if (is_wp_error($resp)) {
                $wpc_probe = 'loopback: ' . substr(preg_replace('/[^a-zA-Z0-9 :._-]/', '', $resp->get_error_message()), 0, 60);
            } else {
                $wpc_code  = (int) wp_remote_retrieve_response_code($resp);
                $wpc_by    = (string) wp_remote_retrieve_header($resp, 'x-cache-by');
                $wpc_probe = 'http ' . $wpc_code . ($wpc_by !== '' ? ' served-by ' . $wpc_by : ' no-marker')
                           . ' — rules did not serve the test file (rewrite not matching on this host)';
            }


            if (strpos((string) $this->getContents($this->getHtaccessPath()), '#StartWPC-StaticServe') === false) {
                $wpc_probe .= ' | rules-block-missing-from-htaccess';
            }
            // Host fingerprint: names the server class (LiteSpeed/Apache/…) and whether a
            // proxy fronts it, so support never has to guess which host family failed.
            $server_software = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '?');
            $wpc_probe .= ' | srv:' . substr(preg_replace('/[^a-zA-Z0-9 .\/_-]/', '', $server_software), 0, 40)
                . (isset($_SERVER['HTTP_CF_RAY']) ? ' cf-fronted' : '');
            if (stripos($server_software, 'litespeed') !== false
                && strpos((string) $this->getContents($this->getHtaccessPath()), '#StartWPC-StaticServe') !== false) {
                $wpc_probe .= ' | litespeed-family: OpenLiteSpeed loads rewrite rules only at server'
                    . ' restart — restart (or enable .htaccess auto-reload) and this will pass;'
                    . ' re-tested daily and enabled automatically';
            }
            update_option('wpc_static_serve_probe', $wpc_probe, false);
        } else {
            delete_option('wpc_static_serve_probe');
        }

        @unlink($dir . 'index.html_gzip');
        @unlink($dir . 'index.html');
        @rmdir(rtrim($dir, '/'));
        return $ok;
    }

    public function extractUrlComponent($url, $component)
    {
        return _get_component_from_parsed_url_array(wp_parse_url($url), $component);
    }

    public function removeUrlProtocol($url)
    {
        $url = preg_replace('#^(https?:)?\/\/#im', '', $url);
        return $url;
    }

    public function sslRewrite()
    {
        // Redirect non SSL to SSL
        $rules = '';
        #$rules .= 'RewriteCond %{HTTPS} off' . PHP_EOL;
        #$rules .= 'RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]' . PHP_EOL;
        // TODO: Check if this works
        $rules .= 'RewriteCond %{HTTPS} !=on' . PHP_EOL;
        $rules .= 'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]' . PHP_EOL;
        $rules .= 'RewriteCond %{HTTPS} on [OR]' . PHP_EOL;
        $rules .= 'RewriteCond %{SERVER_PORT} ^443$ [OR]' . PHP_EOL;
        $rules .= 'RewriteCond %{HTTP:X-Forwarded-Proto} https' . PHP_EOL;
        $rules .= 'RewriteRule .* - [E=WPC_SSL:-https]' . PHP_EOL;
        return $rules;
    }

    public function webpRewrite($cache_dir_path)
    {
        $rules = 'RewriteCond %{HTTP_ACCEPT} image/webp' . PHP_EOL;
        $rules .= 'RewriteCond "' . $cache_dir_path . '/.no-webp" !-f' . PHP_EOL;
        $rules .= 'RewriteRule .* - [E=WPC_WEBP:-webp]' . PHP_EOL;
        return $rules;
    }

    public function rejectCookies()
    {
        $logged_in_cookie = explode(COOKIEHASH, LOGGED_IN_COOKIE);
        $logged_in_cookie = array_map('preg_quote', $logged_in_cookie);
        $logged_in_cookie = implode('.+', $logged_in_cookie);

        $cookies = [];
        $cookies[] = $logged_in_cookie;
        $cookies[] = 'wp-postpass_';
        $cookies[] = 'wptouch_switch_toggle';
        $cookies[] = 'comment_author_';
        $cookies[] = 'comment_author_email_';
        return implode('|', $cookies);
    }

    public function removeHtaccessRules()
    {
        return true;

        $this->htaccessPath = $this->getHtaccessPath();
        if (!$this->htaccessPath) return;

        // Get Contents
        $this->htaccessContent = $this->getContents($this->htaccessPath);

        if (!$this->htaccessContent || empty($this->htaccessContent)) return;

        $removeExistingRules = preg_replace('/\s*#StartWPC-Cache.*#EndWPC-Cache\s*?/isU', PHP_EOL . PHP_EOL, $this->htaccessContent);
        $cleanedHtaccessContent = ltrim($removeExistingRules);

        if (!empty($cleanedHtaccessContent)) {

            if (!defined('FS_CHMOD_FILE')) {
                define('FS_CHMOD_FILE', 0644);
            }

            $this->fileSystem()->put_contents($this->htaccessPath, $cleanedHtaccessContent);
        }
    }

    /**
     * Writes (status true) or removes (status false) the WP_CACHE line in the wp-config.php
     * WordPress loads, and returns what happened: 'found' (the file already says true),
     * 'written', 'defined-elsewhere' (WP_CACHE is true at runtime but not in that file, so a
     * second define would only raise a warning), 'not-found', 'not-readable', 'not-writable',
     * or 'disabled' for status false. The verdict is recorded and a failure raises the notice
     * that tells the admin what to add and where: this function used to return silently when
     * ABSPATH held no wp-config.php, and greenvalleytint.com (wp-config one directory above the
     * web root, 2026-09-22/24) ran with the drop-in installed, WP_CACHE unset and "only the home
     * URL caches", with nothing on screen.
     */
    public function setWPCache($status = true)
    {
        $status = (bool) $status;
        $runtimeActive = defined('WP_CACHE') && WP_CACHE;
        $this->configPath = $this->getConfigPath();
        $verdict = $this->writeWPCacheConstant($status, $runtimeActive);
        if (function_exists('wpc_wp_cache_record_verdict')) {
            wpc_wp_cache_record_verdict($verdict, (string) $this->configPath, $status);
        }
        return $verdict;
    }

    private function writeWPCacheConstant($status, $runtimeActive)
    {
        $definePattern = '/define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false)\s*\);/si';

        if (!$this->configPath) {
            if (!$status) {
                return 'disabled';
            }
            return $runtimeActive ? 'defined-elsewhere' : 'not-found';
        }

        if (!$this->isReadble($this->configPath)) {
            if (!$status) {
                return 'disabled';
            }
            return $runtimeActive ? 'defined-elsewhere' : 'not-readable';
        }

        $configContents = (string) $this->getContents($this->configPath);
        $hasDefine = (bool) preg_match($definePattern, $configContents);

        if ($status) {
            if (preg_match('/define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\);/si', $configContents)) {
                return 'found';
            }
            if (!$hasDefine && $runtimeActive) {
                return 'defined-elsewhere';
            }
            if (!$hasDefine) {
                $cacheConstantLine = "define('WP_CACHE', true); // WP Compress Cache";
                $newContents = preg_replace('/(<\?php)/i', "<?php\r\n{$cacheConstantLine}\r\n", $configContents, 1);
            } else {
                $newContents = preg_replace($definePattern, "define('WP_CACHE', true);", $configContents);
            }
        } else {
            if (!$hasDefine) {
                return 'disabled';
            }
            $newContents = preg_replace($definePattern, '', $configContents);
            $newContents = str_replace('// WP Compress Cache', '', $newContents);
        }

        if (!is_string($newContents) || $newContents === $configContents) {
            return $status ? 'found' : 'disabled';
        }

        if (!$this->isWriteable($this->configPath)) {
            return $status ? 'not-writable' : 'disabled';
        }

        $written = wpc_fs_put($this->configPath, $newContents);
        if ($written === false) {
            return $status ? 'not-writable' : 'disabled';
        }
        return $status ? 'written' : 'disabled';
    }


    /**
     * The wp-config.php WordPress loads (wpc_wp_config_path(): ABSPATH, else the directory above
     * it when that is not another install), backed up once to wp-content/.wp-compress-backups.
     * false when there is none.
     */
    public function getConfigPath()
    {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Remove legacy insecure backup
        $legacy_backup = ABSPATH . 'wp-config-backup.php';
        if (file_exists($legacy_backup)) {
            @unlink($legacy_backup);
        }

        $config_file = wpc_wp_config_path();

        if ($config_file === '') {
            return false;
        }

        if (!is_readable($config_file)) {
            return $config_file;
        }

        $backup_dir  = WP_CONTENT_DIR . '/.wp-compress-backups';
        $backup_file = $backup_dir . '/wp-config.php';

        // Ensure backup directory exists
        if (!is_dir($backup_dir)) {
            wp_mkdir_p($backup_dir);
            @chmod($backup_dir, 0700);
        }

        // Ensure .htaccess exists to block web access (Apache)
        $htaccess = $backup_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            wpc_fs_put($htaccess, "Require all denied\n");
            @chmod($htaccess, 0644);
        }

        // Create backup
        if (!file_exists($backup_file)) {
            if (@copy($config_file, $backup_file)) {
                @chmod($backup_file, 0600);
            }
        }

        return $config_file;
    }


    public function setAdvancedCache()
    {
        $error = false;
        $this->advancedCachePath = $this->getAdvancedCachePath();

        if (!$this->advancedCachePath) {
            return;
        }

        // Is the file writeable?
        if ($this->exists($this->advancedCachePath) && !$this->isWriteable($this->advancedCachePath)) {
            $error = true;
            $this->notice('not-writeable-adv-cache');
        }

        // Is the file readable?
        if ($this->exists($this->advancedCachePath) && !$this->isReadble($this->advancedCachePath)) {
            $error = true;
            $this->notice('not-readable-adv-cache');
        }

        if ($error) return;

        // Get Contents
        $advancedCacheSample = $this->getContents(WPS_IC_DIR . 'templates/samples/advancedCacheSample.php');

        // Only write if changed
        $currentAdvancedCache = '';
        if (file_exists($this->advancedCachePath)) {
            $currentAdvancedCache = file_get_contents($this->advancedCachePath);
        }

        if (!empty($advancedCacheSample)) {
            global $wp_filter;
            $settings = get_option(WPS_IC_SETTINGS);

            $cacheLoggedIn = 'false';
            if (!empty($settings['cache']['cache-logged-in']) && $settings['cache']['cache-logged-in'] == '1') {
                $cacheLoggedIn = 'true';
            }

            // Set cache logged in const in advanced-cache
            $pattern = "#WPC_CACHE_LOGGED_IN_START\r?\n(.+?)\r?\n#WPC_CACHE_LOGGED_IN_END";
            $replacement = "#WPC_CACHE_LOGGED_IN_START\n define('WPC_CACHE_LOGGED_IN' , $cacheLoggedIn );\n#WPC_CACHE_LOGGED_IN_END";
            $newContents = preg_replace("/$pattern/s", $replacement, $advancedCacheSample);

            // v7.10.743 — the benchmark arms need a signal the DROP-IN can read, and the drop-in
            // runs before WordPress, so an option is unreadable there. Baked in here instead of
            // asking for a wp-config edit. Armed ONLY when a tier key already exists, so this one
            // constant implies both halves of the old two-constant contract; the KEY itself is
            // never written to a file — it stays an option, read at door time with WP loaded.
            $tier_cache_literal = 'false';
            if (get_option('wpc_tier_cache', '') === '1'
                && class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'tierKey')
                && wps_ic_url_key::tierKey(false) !== '') {
                $tier_cache_literal = 'true';
            }
            $pattern = "#WPC_TIER_CACHE_START\r?\n(.+?)\r?\n#WPC_TIER_CACHE_END";
            $replacement = "#WPC_TIER_CACHE_START\n define('WPC_TIER_CACHE' , $tier_cache_literal );\n#WPC_TIER_CACHE_END";
            $newContents = preg_replace("/$pattern/s", $replacement, $newContents);

            // Set Developer Mode
            if (!empty($settings['developer_mode']) && $settings['developer_mode'] === '1') {
                $pattern = "#WPC_CACHE_DEVELOPER_MODE_START\r?\n(.+?)\r?\n#WPC_CACHE_DEVELOPER_MODE_END";
                $replacement = "#WPC_CACHE_DEVELOPER_MODE_START\n define('DONOTCACHEPAGE', true);\n return;\n#WPC_CACHE_DEVELOPER_MODE_END";
                $newContents = preg_replace("/$pattern/s", $replacement, $newContents);
            } else {
                $pattern = "#WPC_CACHE_DEVELOPER_MODE_START\r?\n(.+?)\r?\n#WPC_CACHE_DEVELOPER_MODE_END";
                $replacement = "#WPC_CACHE_DEVELOPER_MODE_START\n \n#WPC_CACHE_DEVELOPER_MODE_END";
                $newContents = preg_replace("/$pattern/s", $replacement, $newContents);
            }

            // Set cache cookies constant in advanced-cache
            $cookiesConstant = 'false';
            $excludeCookiesConstant = 'false';
            $mandatoryCookiesConstant = 'false';

            $cookies_list = [];
            $exclude_cookies_list = [];

            if (!empty($settings['cache']['cookies']) && $settings['cache']['cookies'] == 1) {
                $cookies_setting = get_option('wps_ic_cache_cookies', []);

                if (!empty($cookies_setting['cookies'])) {
                    $cookies_list = $cookies_setting['cookies'];
                }

                if (!empty($cookies_setting['exclude_cookies'])) {
                    $exclude_cookies_list = $cookies_setting['exclude_cookies'];
                }
            }

            // Allow plugins to add/modify cache cookies and exclude cookies via filters
            $cookies_list = apply_filters('wps_ic_cache_cookies', $cookies_list);
            $exclude_cookies_list = apply_filters('wps_ic_exclude_cookies', $exclude_cookies_list);

            if (!empty($cookies_list)) {
                $cookiesFormatted = array_map(function ($cookie) {
                    return "'" . addslashes($cookie) . "'";
                }, $cookies_list);
                $cookiesConstant = 'array(' . implode(', ', $cookiesFormatted) . ')';
            }

            if (!empty($exclude_cookies_list)) {
                $excludeCookiesFormatted = array_map(function ($cookie) {
                    return "'" . addslashes($cookie) . "'";
                }, $exclude_cookies_list);
                $excludeCookiesConstant = 'array(' . implode(', ', $excludeCookiesFormatted) . ')';
            }

            // Mandatory cookies - cache is bypassed entirely if any of these are not set
            $mandatory_cookies_list = apply_filters('wps_ic_mandatory_cookies', []);
            if (!empty($mandatory_cookies_list)) {
                $mandatoryCookiesFormatted = array_map(function ($cookie) {
                    return "'" . addslashes($cookie) . "'";
                }, $mandatory_cookies_list);
                $mandatoryCookiesConstant = 'array(' . implode(', ', $mandatoryCookiesFormatted) . ')';
            }

            // Replace cache cookies
            $cookiePattern = "#WPC_CACHE_COOKIES_START\r?\n(.+?)\r?\n#WPC_CACHE_COOKIES_END";
            $cookieReplacement = "#WPC_CACHE_COOKIES_START\ndefine('WPC_CACHE_COOKIES', $cookiesConstant);\n#WPC_CACHE_COOKIES_END";
            $newContents = preg_replace("/$cookiePattern/s", $cookieReplacement, $newContents);

            // Replace exclude cookies
            $excludeCookiePattern = "#WPC_EXCLUDE_COOKIES_START\r?\n(.+?)\r?\n#WPC_EXCLUDE_COOKIES_END";
            $excludeCookieReplacement = "#WPC_EXCLUDE_COOKIES_START\ndefine('WPC_EXCLUDE_COOKIES', $excludeCookiesConstant);\n#WPC_EXCLUDE_COOKIES_END";
            $newContents = preg_replace("/$excludeCookiePattern/s", $excludeCookieReplacement, $newContents);

            // Replace mandatory cookies
            $mandatoryCookiePattern = "#WPC_MANDATORY_COOKIES_START\r?\n(.+?)\r?\n#WPC_MANDATORY_COOKIES_END";
            $mandatoryCookieReplacement = "#WPC_MANDATORY_COOKIES_START\ndefine('WPC_MANDATORY_COOKIES', $mandatoryCookiesConstant);\n#WPC_MANDATORY_COOKIES_END";
            $newContents = preg_replace("/$mandatoryCookiePattern/s", $mandatoryCookieReplacement, $newContents);

            // Query parameters that may vary the cache, and the ones the key ignores. Baked for the
            // same reason the cookie lists are: url_key.php reads them from the drop-in's constants,
            // and the drop-in runs before WordPress can answer a get_option(). Defined either way —
            // `false` when the row is off — so a baked drop-in is always the single source of truth.
            $queryParamsConstant = 'false';
            $ignoreQueryParamsConstant = 'false';

            $query_params_list = [];
            $ignore_query_params_list = [];

            if (!empty($settings['cache']['query-params']) && $settings['cache']['query-params'] == 1) {
                $query_params_setting = get_option('wps_ic_cache_query_params', []);

                if (!empty($query_params_setting['params'])) {
                    $query_params_list = $query_params_setting['params'];
                }

                if (!empty($query_params_setting['exclude_params'])) {
                    $ignore_query_params_list = $query_params_setting['exclude_params'];
                }
            }

            // Same filter shape the cookie lists offer, so an integration can register the
            // parameter its own plugin varies on without the site owner typing it in.
            $query_params_list = apply_filters('wps_ic_cache_query_params', $query_params_list);
            $ignore_query_params_list = apply_filters('wps_ic_ignore_query_params', $ignore_query_params_list);

            if (!empty($query_params_list)) {
                $queryParamsFormatted = array_map(function ($param) {
                    return "'" . addslashes($param) . "'";
                }, $query_params_list);
                $queryParamsConstant = 'array(' . implode(', ', $queryParamsFormatted) . ')';
            }

            if (!empty($ignore_query_params_list)) {
                $ignoreQueryParamsFormatted = array_map(function ($param) {
                    return "'" . addslashes($param) . "'";
                }, $ignore_query_params_list);
                $ignoreQueryParamsConstant = 'array(' . implode(', ', $ignoreQueryParamsFormatted) . ')';
            }

            $queryParamsPattern = "#WPC_CACHE_QUERY_PARAMS_START\r?\n(.+?)\r?\n#WPC_CACHE_QUERY_PARAMS_END";
            $queryParamsReplacement = "#WPC_CACHE_QUERY_PARAMS_START\ndefine('WPC_CACHE_QUERY_PARAMS', $queryParamsConstant);\n#WPC_CACHE_QUERY_PARAMS_END";
            $newContents = preg_replace("/$queryParamsPattern/s", $queryParamsReplacement, $newContents);

            $ignoreQueryParamsPattern = "#WPC_IGNORE_QUERY_PARAMS_START\r?\n(.+?)\r?\n#WPC_IGNORE_QUERY_PARAMS_END";
            $ignoreQueryParamsReplacement = "#WPC_IGNORE_QUERY_PARAMS_START\ndefine('WPC_IGNORE_QUERY_PARAMS', $ignoreQueryParamsConstant);\n#WPC_IGNORE_QUERY_PARAMS_END";
            $newContents = preg_replace("/$ignoreQueryParamsPattern/s", $ignoreQueryParamsReplacement, $newContents);


            // ZERO DB queries (was 2 SELECTs per request). Re-baked here on every settings/excludes save.
            $urlExcludesConstant = 'false';
            $cacheExcludesConstant = 'false';

            $wpc_url_excludes_opt = get_option('wpc-url-excludes');
            if (!empty($wpc_url_excludes_opt['exclude-url-from-all']) && is_array($wpc_url_excludes_opt['exclude-url-from-all'])) {
                $urlExFmt = array_map(function ($p) {
                    return "'" . addslashes($p) . "'";
                }, $wpc_url_excludes_opt['exclude-url-from-all']);
                $urlExcludesConstant = 'array(' . implode(', ', $urlExFmt) . ')';
            }

            $wpc_cache_excludes_opt = get_option('wpc-excludes');
            if (!empty($wpc_cache_excludes_opt['cache']) && is_array($wpc_cache_excludes_opt['cache'])) {
                $cacheExFmt = array_map(function ($p) {
                    return "'" . addslashes($p) . "'";
                }, $wpc_cache_excludes_opt['cache']);
                $cacheExcludesConstant = 'array(' . implode(', ', $cacheExFmt) . ')';
            }

            $urlExPattern = "#WPC_URL_EXCLUDES_START\r?\n(.+?)\r?\n#WPC_URL_EXCLUDES_END";
            $urlExReplacement = "#WPC_URL_EXCLUDES_START\ndefine('WPC_URL_EXCLUDES', $urlExcludesConstant);\n#WPC_URL_EXCLUDES_END";
            $newContents = preg_replace("/$urlExPattern/s", $urlExReplacement, $newContents);

            $cacheExPattern = "#WPC_CACHE_EXCLUDES_START\r?\n(.+?)\r?\n#WPC_CACHE_EXCLUDES_END";
            $cacheExReplacement = "#WPC_CACHE_EXCLUDES_START\ndefine('WPC_CACHE_EXCLUDES', $cacheExcludesConstant);\n#WPC_CACHE_EXCLUDES_END";
            $newContents = preg_replace("/$cacheExPattern/s", $cacheExReplacement, $newContents);

            if ($newContents !== $currentAdvancedCache) {
                wpc_fs_put($this->advancedCachePath, $newContents);
            }
        }
    }

    public function getAdvancedCachePath()
    {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $config_file = ABSPATH . 'wp-content/advanced-cache.php';

        if (!file_exists($config_file)) {
            // Initialize the WP_Filesystem
            global $wp_filesystem;
            WP_Filesystem();
            $wp_filesystem->put_contents($config_file, "", 0644);


            if (!file_exists($config_file)) {
                return false;
            }

        }

        return $config_file;
    }

    public function removeAdvancedCache()
    {
        $error = false;
        $this->advancedCachePath = $this->getAdvancedCachePath();

        if (!$this->advancedCachePath) {
            return true;
        }

        // Is the file writeable?
        if ($this->exists($this->advancedCachePath) && !$this->isWriteable($this->advancedCachePath)) {
            $error = true;
            $this->notice('not-writeable-adv-cache');
        }

        // Is the file readable?
        if ($this->exists($this->advancedCachePath) && !$this->isReadble($this->advancedCachePath)) {
            $error = true;
            $this->notice('not-readable-adv-cache');
        }

        if ($error) return true;

        $this->fileSystem()->put_contents($this->advancedCachePath, '');
    }

    /**
     * The one writer of the next-gen negotiation block. Wanted when the site serves its own
     * images (live-cdn off) with WebP on and Advanced Cache on — the conditions the admin-load
     * writer has always used; the settings save and the agency relay call this too, so a save no
     * longer removes the block the next admin page load writes back (it keyed on the retired
     * 'htaccess-webp-replace', '0' in every preset).
     */
    public function syncWebpReplace($settings)
    {
        $s = is_array($settings) ? $settings : [];
        $wanted = !empty($s['cache']['advanced']) && (string) $s['cache']['advanced'] === '1'
            && !(isset($s['live-cdn']) && (string) $s['live-cdn'] === '1')
            && !empty($s['generate_webp']) && (string) $s['generate_webp'] === '1';
        $this->isApache();
        if ($wanted) {
            return $this->addWebpReplace();
        }
        $this->removeWebpReplace();
        return null;
    }

    public function addWebpReplace()
    {
        if (!$this->isApache) {
            return;
        }

        $this->htaccessPath = $this->getHtaccessPath();
        if (!$this->htaccessPath) return;

        $this->htaccessContent = $this->getContents($this->htaccessPath);

        // The block in the file is rewritten whenever it differs from what the owner answers now:
        // a policy change (the opt-out, a Cloudflare Vary for Images witness appearing or going),
        // a ceiling change and an upgrade from a pre-7.21.34 block all land here.
        $policyWhy = '';
        $policy = class_exists('WPC_Delivery_Resolver') ? WPC_Delivery_Resolver::negotiated_cache_policy($policyWhy) : 'private';
        $rules = self::getWebpReplaceRules($policy);
        if (self::markerBlockLines((string) $this->htaccessContent) === self::ruleLines($rules)) {
            return;
        }

        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        if (!file_exists($this->htaccessPath)) {
            if (!@touch($this->htaccessPath)) {
                update_option('wpc_htaccess_error', 'Could not create .htaccess. Add the rules manually or make it writable.');
                return false;
            }
        }
        if (!is_writable($this->htaccessPath)) {
            update_option('wpc_htaccess_error', 'Could not write to .htaccess. Make it writable or add the rules manually.');
            return false;
        }

        $written = insert_with_markers($this->htaccessPath, self::WEBP_MARKER, $rules);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('htaccess-negotiated', '', '', ['policy' => $policy, 'why' => $policyWhy, 'written' => $written ? 1 : 0]);
        }
        return $written;
    }

    /** The non-comment lines between this block's BEGIN and END markers; [] when there is no block.
     *  insert_with_markers adds WordPress's own comment lines after BEGIN, so those are skipped. */
    private static function markerBlockLines($content)
    {
        $q = preg_quote(self::WEBP_MARKER, '/');
        if (!preg_match('/^# BEGIN ' . $q . '\R(.*?)^# END ' . $q . '/ms', $content, $m)) {
            return [];
        }
        return self::ruleLines($m[1]);
    }

    private static function ruleLines($text)
    {
        $out = [];
        foreach (preg_split('/\R/', (string) $text) as $line) {
            $line = rtrim($line);
            if ($line === '' || $line[0] === '#') continue;
            $out[] = $line;
        }
        return $out;
    }

    // v7.21.257 — MISSING-VARIANT FALLBACK. An explicit .avif/.webp request whose file was
    // never generated dies as a server-level static 404 (LiteSpeed answers before PHP exists;
    // bestexteriorsinc: siding.avif?src=jpg = broken background while siding.jpg sits beside
    // it). The src= hint on every minted variant URL names the source extension — a rewrite
    // rung 302s the miss to the original, and the unhinted ladder falls to a .jpg/.png twin
    // when one exists. Uploads-scoped, -f guarded, own marker block, once per plugin version.
    public function applyMissingVariantFallback()
    {
        try {
            $this->htaccessPath = $this->getHtaccessPath();
            if (empty($this->htaccessPath) || !$this->isApache || !@file_exists($this->htaccessPath)
                || !$this->isWriteable($this->htaccessPath)
                || !apply_filters('wpc_missing_variant_fallback', true)) {
                return false;
            }
            $c = (string) $this->getContents($this->htaccessPath);
            if (strpos($c, 'wpc-mvf-302') !== false) {
                return true;
            }
            if (!function_exists('insert_with_markers')) {
                require_once ABSPATH . 'wp-admin/includes/misc.php';
            }
            return (bool) insert_with_markers($this->htaccessPath, 'WPC Missing Variant Fallback', self::getMissingVariantFallbackRules());
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function getMissingVariantFallbackRules()
    {
        $r   = [];
        $r[] = '<IfModule mod_rewrite.c>';
        $r[] = 'RewriteEngine On';
        $r[] = '# wpc-mvf-302: variant file absent, src= names the source';
        $r[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
        $r[] = 'RewriteCond %{QUERY_STRING} (?:^|&)src=(jpe?g|png|gif)(?:&|$)';
        $r[] = 'RewriteRule ^(.*wp-content/uploads/.+)\.(?:avif|webp)$ $1.%1 [R=302,L]';
        $r[] = '# unhinted: fall to a jpg/png twin when one exists';
        $r[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
        $r[] = 'RewriteCond %{REQUEST_URI} ^(.+/wp-content/uploads/.+)\.(?:avif|webp)$ [OR]';
        $r[] = 'RewriteCond %{REQUEST_URI} ^(/wp-content/uploads/.+)\.(?:avif|webp)$';
        $r[] = 'RewriteCond %{DOCUMENT_ROOT}%1.jpg -f';
        $r[] = 'RewriteRule ^ %1.jpg [R=302,L]';
        $r[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
        $r[] = 'RewriteCond %{REQUEST_URI} ^(.+/wp-content/uploads/.+)\.(?:avif|webp)$ [OR]';
        $r[] = 'RewriteCond %{REQUEST_URI} ^(/wp-content/uploads/.+)\.(?:avif|webp)$';
        $r[] = 'RewriteCond %{DOCUMENT_ROOT}%1.png -f';
        $r[] = 'RewriteRule ^ %1.png [R=302,L]';
        $r[] = '</IfModule>';
        return $r;
    }

    /** The negotiation block for a cache policy (WPC_Delivery_Resolver::negotiated_cache_policy). */
    public static function getWebpReplaceRules($policy = 'private')
    {

        $webp_rules = '<IfModule mod_rewrite.c>'.PHP_EOL;
        $webp_rules .= 'RewriteEngine On'.PHP_EOL;


        $wpc_avif_ok = !class_exists('WPC_Delivery_Resolver')
            || WPC_Delivery_Resolver::effective_ceiling(get_option(WPS_IC_SETTINGS)) === 'avif';
        if ($wpc_avif_ok) {
            $webp_rules .= 'RewriteCond %{HTTP_ACCEPT} image/avif'.PHP_EOL;
            $webp_rules .= 'RewriteCond %{REQUEST_URI} ^(.+)\.(jpe?g|png|gif)$'.PHP_EOL;
            $webp_rules .= 'RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.avif -f'.PHP_EOL;
            $webp_rules .= 'RewriteRule ^(.+)$ $1.avif [E=avif,L]'.PHP_EOL;
            $webp_rules .= 'RewriteCond %{HTTP_ACCEPT} image/avif'.PHP_EOL;
            $webp_rules .= 'RewriteCond %{REQUEST_URI} ^(.+)\.(jpe?g|png|gif)$'.PHP_EOL;
            $webp_rules .= 'RewriteCond %{DOCUMENT_ROOT}/%1.avif -f'.PHP_EOL;
            $webp_rules .= 'RewriteRule ^(.+)\.(jpe?g|png|gif)$ $1.avif [E=avif,L]'.PHP_EOL;
        }
        $webp_rules .= 'RewriteCond %{HTTP_ACCEPT} image/webp'.PHP_EOL;
        $webp_rules .= 'RewriteCond %{REQUEST_URI} ^(.+)\.(jpe?g|png|gif)$'.PHP_EOL;
        $webp_rules .= 'RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.webp -f'.PHP_EOL;
        $webp_rules .= 'RewriteRule ^(.+)$ $1.webp [E=webp,L]'.PHP_EOL;
        $webp_rules .= 'RewriteCond %{HTTP_ACCEPT} image/webp'.PHP_EOL;
        $webp_rules .= 'RewriteCond %{REQUEST_URI} ^(.+)\.(jpe?g|png|gif)$'.PHP_EOL;
        $webp_rules .= 'RewriteCond %{DOCUMENT_ROOT}/%1.webp -f'.PHP_EOL;
        $webp_rules .= 'RewriteRule ^(.+)\.(jpe?g|png|gif)$ $1.webp [E=webp,L]'.PHP_EOL;
        $webp_rules .= '</IfModule>'.PHP_EOL;

        $webp_rules .= '<IfModule mod_mime.c>'.PHP_EOL;
        $webp_rules .= 'AddType image/webp .webp'.PHP_EOL;


        $webp_rules .= 'AddType image/avif .avif'.PHP_EOL;
        $webp_rules .= '</IfModule>'.PHP_EOL;
        $webp_rules .= '<IfModule mod_headers.c>'.PHP_EOL;
        $webp_rules .= 'Header append Vary Accept env=REDIRECT_webp'.PHP_EOL;


        if ($wpc_avif_ok) {
            $webp_rules .= 'Header append Vary Accept env=REDIRECT_avif'.PHP_EOL;
        }
        // Rule: a negotiated body is private unless the owner answers 'shared-ok'. Observed
        // failure: Cloudflare (and most CDNs) ignore Vary: Accept, so a webp body cached under
        // the natural .jpg URL reached Safari <= 15 on Catalina as a broken image, per PoP and
        // intermittently (7.21.34). private keeps the browser cache (its own Accept never
        // changes) while barring every shared cache; the cost is a Cloudflare BYPASS on every
        // negotiated body (ticket 12001), so a vary-aware edge gets the public year instead.
        $cacheControl = $policy === 'shared-ok' ? 'public, max-age=31536000' : 'private, max-age=31536000';
        $webp_rules .= 'Header set Cache-Control "' . $cacheControl . '" env=REDIRECT_webp'.PHP_EOL;
        if ($wpc_avif_ok) {
            $webp_rules .= 'Header set Cache-Control "' . $cacheControl . '" env=REDIRECT_avif'.PHP_EOL;
        }
        if ($policy === 'shared-ok') {
            // The un-negotiated original varies too, so a vary-aware edge keys both answers of
            // the same URL; without it a jpg cached first would be served to every Accept.
            $webp_rules .= '<FilesMatch "\.(jpe?g|png|gif)$">'.PHP_EOL;
            $webp_rules .= 'Header append Vary Accept'.PHP_EOL;
            $webp_rules .= '</FilesMatch>'.PHP_EOL;
        }
        $webp_rules .= '</IfModule>'.PHP_EOL;

        return $webp_rules;
    }

    public function removeWebpReplace()
    {
        $this->htaccessPath = $this->getHtaccessPath();
        if (!$this->htaccessPath) return;

        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        if (file_exists($this->htaccessPath) && is_writable($this->htaccessPath)) {
            insert_with_markers($this->htaccessPath, self::WEBP_MARKER, []);
        }
    }



}