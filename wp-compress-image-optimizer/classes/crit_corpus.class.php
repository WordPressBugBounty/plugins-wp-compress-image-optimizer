<?php

include_once WPS_IC_DIR . 'addons/cdn/cdn-rewrite.php';
include_once WPS_IC_DIR . 'traits/url_key.php';

/**
 * The CSS corpus the critical CSS generator reads for a page: every stylesheet the page links and
 * every inline style, in document order, joined into one text with each sheet's url()s made
 * absolute. Nothing else builds it, and it has two consumers:
 *
 * - corpus_from_html(): the dispatch. wps_criticalCss::wpc_push_corpus() sends it to the service
 *   with the page, as `css`. Nothing is written.
 * - push_render(): the render the service fetches as ?criticalCombine=true (or with the
 *   criticalCombine header). It writes the corpus to cache/combine/<url key>/css/ as
 *   wps_combined.css (wps_mobile_combined.css for the handset) and links it in place of the first
 *   sheet it absorbed, as #wpc-critical-combined-css.
 *
 * Both read the same sheets the same way, so the text is byte for byte the same. No visitor
 * setting decides what goes in.
 *
 * Left out: the sheets on the site's combine exclusion list (wpc-excludes css_combine), print
 * sheets, consent-platform CSS, anything wpc-owned, and IE9 sheets.
 */
class wps_ic_crit_corpus
{

    public static $excludes;
    public static $isMobile;
    public static $site_url;
    public $zone_name;
    public $hmwpReplace;
    public $hmwp_rewrite;
    public $patterns;
    public $allExcludes;
    public $settings;
    public $firstFoundStyle;
    public $combined_url_base;
    public $combined_dir;
    public $urlKey;
    public $url_key_class;
    public $log_criticalCombine;
    public $logger;
    public $current_file;
    public $asset_url;
    public $enabledCDN;
    public $collect_only = false;
    public $remote_deadline = null;
    public $corpus_sheets = [];
    public $wpc_written_files = [];

    public function __construct()
    {
        $this->url_key_class = new wps_ic_url_key();
        $this->urlKey = $this->url_key_class->setup();
        $this->combined_dir = WPS_IC_COMBINE . $this->urlKey . '/css/';
        $this->combined_url_base = WPS_IC_COMBINE_URL . $this->urlKey . '/css/';
        $this->enabledCDN = false;

        $this::$isMobile = $this->isMobile();

        $this->firstFoundStyle = false;

        self::$excludes = new wps_ic_excludes();
        self::$site_url = site_url();
        $this->settings = get_option(WPS_IC_SETTINGS);
        // Print sheets never paint above the fold, so the corpus leaves them out.
        $this->allExcludes = array_merge(['media="print"', 'media=\'print\''], self::$excludes->combineCSSExcludes());

        if (!empty($this->settings['serve']['jpg']) || !empty($this->settings['serve']['png']) || !empty($this->settings['serve']['gif']) || !empty($this->settings['serve']['svg'])) {
            $this->enabledCDN = true;
            $cf = get_option(WPS_IC_CF);
            if (!empty($cf['settings']['cdn']) && $cf['settings']['cdn'] == '0') {
                $this->enabledCDN = false;
            }
        }

        $this->patterns = '/(<link[^>]*rel=["\']stylesheet["\'][^>]*>)|((?<!<noscript>)<style\b[^>]*>(.*?)<\/style>)|(<link\b[^>]*?onload=["\']this.rel=["\']stylesheet["\']["\'][^>]*>)/si';

        $cf = get_option(WPS_IC_CF);
        $cfCname = get_option(WPS_IC_CF_CNAME);
        $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok())) ? $cfCname : get_option('ic_custom_cname');
        if (!empty($custom_cname) && function_exists('wpc_cdn_cname_is_reachable') && !wpc_cdn_cname_is_reachable($custom_cname)) { $custom_cname = ''; }
        if (empty($custom_cname) || !$custom_cname) {
            $this->zone_name = get_option('ic_cdn_zone_name');
        } else {
            $this->zone_name = $custom_cname;
        }

        //Check if Hide my WP is active and get replaces
        $this->hmwpReplace = false;
        if (class_exists('HMWP_Classes_ObjController')) {
            $this->hmwpReplace = true;
            $plugin_path = WP_PLUGIN_DIR . '/hide-my-wp/';
            include_once($plugin_path . 'classes/ObjController.php');
            $hmwp_controller = new HMWP_Classes_ObjController();
            $this->hmwp_rewrite = $hmwp_controller::getClass('HMWP_Models_Rewrite');
        }
    }

    /**
     * The CSS corpus the crit generator reads for a page, built from the page's own HTML: the
     * sheets it links and its inline styles, in document order, exactly as the
     * ?criticalCombine=true render combines them into wps_combined.css. A sheet on one of the
     * site's own hosts, the CDN zone included, is read from disk (corpus_own_path); one on
     * another host is fetched within $remoteBudget seconds for all of them together. Nothing is
     * written.
     *
     * Complete or absent: when a linked sheet cannot be read, the corpus is ''. corpus_sheets
     * then says what each linked sheet came to: 'read', 'empty' (a 0-byte file, skipped),
     * 'missing', or 'left-out' (a sheet the combine never takes: the site's exclusions, print,
     * consent platforms, our own).
     */
    public function corpus_from_html($html, $remoteBudget = 4.0)
    {
        $this->corpus_sheets = [];
        if (!is_string($html) || $html === '') {
            return '';
        }
        // Built for its side effect: it loads the settings and excludes that the naturalize passes
        // in naturalize_asset_urls() read from wps_cdn_rewrite's statics. A dispatch runs outside
        // a render (cron, admin-ajax, the kick receiver), where nothing else has set them.
        // push_render() does not build it: inside a render the statics are the render's own.
        new wps_cdn_rewrite();
        $this->collect_only = true;
        $this->remote_deadline = microtime(true) + max(0.0, (float) $remoteBudget);
        $this->current_file = '';
        $this->firstFoundStyle = false;
        try {
            if (preg_match('/<head(.*?)<\/head>/si', $html, $head)) {
                $this->combine($head);
            }
            if (preg_match('/<\/head>(.*?)<\/body>/si', $html, $body)) {
                $this->combine($body);
            }
            $css = $this->naturalize_asset_urls((string) $this->current_file);
        } finally {
            $this->collect_only = false;
            $this->remote_deadline = null;
            $this->current_file = '';
        }
        $css = (string) preg_replace('#/\*wp_block_styles_on_demand_placeholder:[0-9a-f]+\*/#i', '', $css);
        if (trim($css) === '' || $this->wpc_looks_like_html_doc($css) || in_array('missing', $this->corpus_sheets, true)) {
            return '';
        }
        return $css;
    }

    /**
     * The path of a sheet this site serves itself, for the corpus to read from disk: one on the
     * site's own hosts (wpc_crit_own_hosts, the site URL's host, the zone this class rewrites to,
     * and each one's www twin) or wrapped in a zone URL on one (/a: or /u:), or a root-relative
     * href. '' for a sheet on another host.
     */
    public function corpus_own_path($href)
    {
        $href = html_entity_decode(trim((string) $href), ENT_QUOTES);
        if ($href === '' || stripos($href, 'data:') === 0) {
            return '';
        }
        $own = [];
        $ownHosts = function_exists('wpc_crit_own_hosts') ? wpc_crit_own_hosts() : [];
        $ownHosts[] = (string) parse_url((string) self::$site_url, PHP_URL_HOST);
        $ownHosts[] = (string) $this->zone_name;
        foreach ($ownHosts as $ownHost) {
            $ownHost = strtolower(trim((string) $ownHost));
            if ($ownHost !== '') {
                $own[$ownHost] = 1;
                $own[strpos($ownHost, 'www.') === 0 ? substr($ownHost, 4) : 'www.' . $ownHost] = 1;
            }
        }
        for ($hop = 0; $hop < 2; $hop++) {
            $parts = parse_url(strpos($href, '//') === 0 ? 'https:' . $href : $href);
            if (!is_array($parts) || empty($parts['path'])) {
                return '';
            }
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
            if ($host === '' ? strpos((string) $parts['path'], '/') !== 0 : !isset($own[$host])) {
                return '';
            }
            if ($hop === 0 && preg_match('#/(?:a|u):((?:https?:)?//[^?\#\s"\']+)#i', (string) $parts['path'], $wrapped)) {
                $href = $wrapped[1];
                continue;
            }
            break;
        }
        $path = '/' . ltrim(rawurldecode((string) $parts['path']), '/');
        return strpos($path, '..') === false ? $path : '';
    }


    /**
     * The ?criticalCombine=true render: the page with its sheets and inline styles replaced by one
     * link to the corpus, written to wps_combined.css (desktop) or wps_mobile_combined.css
     * (handset). A sheet that cannot be read stays in the page as it was. Receipt:
     * critcombine-receipt (page bytes, whether the link is in it, the combined files' sizes).
     */
    public function push_render($html)
    {
        if (!empty(get_option('wps_log_critCombine'))) {
            $this->log_criticalCombine = true;
            $this->logger = new wps_ic_logger('criticalCombine');
        }

        $this->current_file = '';

        $this->setup_dirs();

        // No single-flight lock: the generator's render must produce the corpus within its own
        // request, so it always builds.
        $html = preg_replace_callback('/<head(.*?)<\/head>/si', [$this, 'combine'], $html);
        $html = preg_replace_callback('/<\/head>(.*?)<\/body>/si', [$this, 'combine'], $html);

        $this->write_file_and_next();
        $this->wpc_sweep_unwritten_combined_files();

        $html = $this->insert_corpus_link($html);

        // v7.21.271 — CRITCOMBINE RECEIPT (joint diagnostic, beucomply homepage class:
        // pod received 194,418 bytes where every direct fetch serves 243K with the
        // combined.css link — an intermittent in-render combine failure on a loaded
        // host leaves the generator page smaller and sourceless, and neither side could
        // see it after the fact). Every generator render now logs what it actually
        // served: page bytes, link presence, and the combined artifacts' sizes — the
        // pod's next attempt self-documents from our side, no access log needed.
        if (function_exists('wpc_cache_first_log')) {
            try {
                $combined_sizes = [];
                if (!empty($this->combined_dir) && is_dir($this->combined_dir)) {
                    foreach (array_slice((array) @glob($this->combined_dir . '*.css'), 0, 8) as $combined_file) {
                        $combined_sizes[basename((string) $combined_file)] = (int) @filesize($combined_file);
                    }
                }
                wpc_cache_first_log('critcombine-receipt', '', '', [
                    'html_bytes' => strlen((string) $html),
                    'link_present' => strpos((string) $html, 'combined.css') !== false ? 1 : 0,
                    'files' => $combined_sizes,
                ]);
            } catch (\Throwable $e) {
            }
        }

        return $html;
    }

    /** The link to this device's corpus file, in place of the first sheet the corpus absorbed. */
    public function insert_corpus_link($html)
    {
        $combined_files = new \FilesystemIterator($this->combined_dir);

        $link = '';
        $wanted_file = self::$isMobile ? 'wps_mobile_combined.css' : 'wps_combined.css';
        foreach ($combined_files as $file) {
            if (strpos($file->getFilename(), '.') === 0) {
                continue;
            }
            // v7.21.276 — link ONLY this device's combined file (the dir now holds both;
            // last-iterated used to win).
            if ($file->getFilename() !== $wanted_file) {
                continue;
            }
            // v7.10.657 (B1-R2) — emit ONLY real stylesheets. The .646 `.md5` sidecar is
            // not a dotfile, so it was iterated here; because $link is overwritten each
            // pass, whenever the filesystem returned wps_combined.css.md5 last the emitted
            // link pointed at the 32-byte md5 hash. The crit generator then fetched 32
            // bytes as "the combined stylesheet" and failed the gen as css_stub. Restrict
            // to *.css and the sidecar can never be linked.
            if (substr($file->getFilename(), -4) !== '.css') {
                continue;
            }
            $url = $this->combined_url_base . basename($file);
            if (strpos($url, 'http://') !== false) {

                $url = str_replace('http://', 'https://', $url);
            }
            // v7.10.642 — content-derived version, never time(): a per-second buster
            // made the 0.4–2.1MB combined sheet uncacheable for every layer and every
            // returning visitor (service receipt: median 1.48MB across 11 domains).
            // mtime:size changes exactly when the bytes on disk change.
            $content_md5 = (string) @file_get_contents($file . '.md5');
            if (strlen($content_md5) !== 32) { $content_md5 = (string) @md5_file((string) $file); }
            $short_hash = substr($content_md5, 0, 10);
            $link = '<link rel="stylesheet" id="wpc-critical-combined-css" href="' . $url . '?hash=' . $short_hash . '" type="text/css" media="all">' . PHP_EOL;
        }

        if ($link !== '') {
            $this->wpc_mark_combined_dir_live();
        }
        return str_replace('<!--WPC_INSERT_COMBINED_CSS-->', $link, $html);
    }

    public function setup_dirs()
    {
        mkdir(WPS_IC_COMBINE . $this->urlKey . '/css', 0777, true);
    }

    /**
     * Writes the corpus so far to this device's file. It runs after every sheet, so the file is
     * rewritten as it grows (wpc_write_if_changed skips identical bytes); corpus_from_html only
     * collects.
     */
    public function write_file_and_next()
    {
        if ($this->collect_only) {
            return;
        }

        if ($this->current_file != '') {
            $this->current_file = $this->naturalize_asset_urls($this->current_file);
        }

        // v7.21.276 — device-keyed: mobile and desktop generations previously thrashed
        // ONE wps_combined.css with device-different bytes (the .646 identical-skip
        // never held across a device flip), so the other device's sheet fetch read a
        // mid-rewrite file. Each device owns its own name.
        $this->wpc_write_if_changed(self::$isMobile ? 'wps_mobile_combined.css' : 'wps_combined.css');
    }

    /** The asset URLs inside combined CSS, moved onto the hosts that serve them. */
    public function naturalize_asset_urls($css)
    {
        if ($css === '' || !class_exists('wps_cdn_rewrite')) {
            return $css;
        }
        if (method_exists('wps_cdn_rewrite', 'wpc_raster_naturalize')) {
            $css = wps_cdn_rewrite::wpc_raster_naturalize($css);
        }
        if (method_exists('wps_cdn_rewrite', 'wpc_svg_naturalize')) {
            $css = wps_cdn_rewrite::wpc_svg_naturalize($css);
        }
        if (method_exists('wps_cdn_rewrite', 'wpc_svg_zoneify')) {
            $css = wps_cdn_rewrite::wpc_svg_zoneify($css);
        }
        return $css;
    }

    public function isMobile()
    {
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }

        $userAgent = '';
        if (!empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'PreloaderAPI') !== false) {
            $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);
        }

        // Desktop Detection
        $desktopKeywords = ['windows nt', 'macintosh', 'linux', 'cros', 'x11'];

        foreach ($desktopKeywords as $keyword) {
            if (strpos($userAgent, $keyword) !== false) {
                return false; // Detected a desktop identifier, so it's not a mobile device
            }
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            // Define an array of mobile device keywords to check against
            $mobileKeywords = ['android', 'iphone', 'ipad', 'ipod', 'windows phone', 'blackberry', 'bb10', 'webos', 'symbian', 'playbook', 'kindle', 'silk', 'opera mini', 'opera mobi', 'palm'];

            // Check if the user agent contains any of the mobile device keywords
            foreach ($mobileKeywords as $keyword) {
                if (strpos($userAgent, $keyword) !== false) {
                    return true; // Found a match, so it's a mobile device
                }
            }
        }

        return false;
    }


    // v7.10.657 (B1-R2) — a real stylesheet is never an HTML DOCUMENT. A soft-404 (HTTP 200
    // carrying an error page) or a server that answers a missing .css with its 404 template
    // otherwise gets concatenated into the combined bundle; the crit generator then reads the
    // bundle, sees markup, and fails the whole gen as css_stub. isHtml() is too loose for this
    // (CSS legitimately contains `<` inside content:/SVG data URIs), so match document markers
    // near the start only — those cannot occur in a valid stylesheet.
    public function wpc_looks_like_html_doc($s)
    {
        if (!is_string($s) || $s === '') {
            return false;
        }
        // v7.10.659 — START-anchored (was: match anywhere in the first 512 bytes). An error page
        // BEGINS with the doctype or a document root tag; valid CSS never does. The old form
        // could misflag a stylesheet whose first rule carried an HTML tag in a content: string
        // — same false-positive class the JS twin hit on document.write. Retightened to match
        // the combine_js detector exactly.
        $head = strtolower(ltrim($s));
        return strncmp($head, '<!doctype html', 14) === 0
            || strncmp($head, '<html', 5) === 0
            || strncmp($head, '<head>', 6) === 0
            || strncmp($head, '<head ', 6) === 0
            || strncmp($head, '<body', 5) === 0;
    }

    // v7.10.642 — liveness SIDECAR, never the artifacts: their mtime feeds the
    // content-derived ?hash and a touch would re-bust caches daily. Written only
    // after a real artifact was emitted, so an empty dir never reads as live (or
    // as existing). The retention sweep reads this marker to spare the key dir.
    private function wpc_mark_combined_dir_live()
    {
        try {
            $live_marker = rtrim($this->combined_dir, '/') . '/.wpc-live';
            if (!@is_file($live_marker) || (int) @filemtime($live_marker) < time() - 86400) {
                wpc_fs_put($live_marker, (string) time());
            }
        } catch (\Throwable $e) {
        }
    }

    // v7.10.646 — the ?hash= input is the CONTENT, nothing else (service canary
    // receipt: byte-identical file, different hash every fetch — .642 keyed on
    // mtime:size and this writer rewrites identical bytes on every uncached render,
    // so the key rotated without the content changing). Byte-identical rewrites are
    // skipped entirely: mtime holds still, IO is saved, and the .md5 sidecar written
    // alongside is the emission's O(1) hash source.
    public function wpc_write_if_changed($file_name)
    {
        // B1-R2 (write-side belt) — never emit a .css that is actually an HTML document. If a
        // soft-404 slipped past the fetch guard (or a source was assembled from cached HTML),
        // writing it hands the crit generator a stub stylesheet and kills the gen. Skipping the
        // write leaves no combined file for this lane, so the page falls back to its original
        // sheets (fail-open) instead of serving markup on a .css URL. Not marked as written, so
        // the sweep does not treat a refused poison-file as a real emission.
        if ($this->wpc_looks_like_html_doc($this->current_file)) {
            if ($this->log_criticalCombine) {
                $this->logger->log('Refused to write HTML-containing combined CSS: ' . $file_name, true);
            }
            return;
        }
        $file_path = $this->combined_dir . $file_name;
        $this->current_file = (string) preg_replace('#/\*wp_block_styles_on_demand_placeholder:[0-9a-f]+\*/#i', '', $this->current_file);
        $content_md5 = md5($this->current_file);
        if (!@is_file($file_path) || (string) @file_get_contents($file_path . '.md5') !== $content_md5) {
            // v7.21.276 — ATOMIC. The in-place rewrite of a ~1MB sheet leaves a long
            // truncated-partial window, and the crit generator races ITSELF: its render
            // rebuilds this file, then the pod fetches it milliseconds later (beucomply
            // homepage: src=82b from a 1,001,890b sheet; reproduced from outside — one
            // hammer request caught 103,339b of a 103,661b transfer). tmp+rename means a
            // concurrent reader always sees a complete file, old or new.
            $tmp_path = $file_path . '.tmp-' . getmypid();
            if (wpc_fs_put($tmp_path, $this->current_file) !== false && @rename($tmp_path, $file_path)) {
                wpc_fs_put($file_path . '.md5', $content_md5);
            } else {
                @unlink($tmp_path);
            }
        }
        $this->wpc_written_files[] = $file_name;
    }

    // v7.10.644 — overwrite-only contract: purges no longer delete the dir, so a build
    // that produces FEWER files than its predecessor must clear its own lane's leftovers
    // (they would be emitted as stale extras forever). Runs only after the fresh set is
    // fully written — the old files stay valid until that moment, no absence window.
    // Each device sweeps only its own names, so a desktop render never deletes the handset's
    // file or the reverse.
    public function wpc_sweep_unwritten_combined_files()
    {
        try {
            foreach ((array) @glob($this->combined_dir . 'wps_*.css') as $file) {
                $base_name = basename($file);
                if (!self::$isMobile && strpos($base_name, 'wps_mobile_') === 0) {
                    continue;
                }
                if (self::$isMobile && strpos($base_name, 'wps_mobile_') !== 0) {
                    // .276: wps_combined.css is DESKTOP-owned now — the old carve-out (which
                    // let legacy mobile sweep its own stale copy) would delete the desktop
                    // artifact on every mobile render.
                    continue;
                }
                if (!in_array($base_name, $this->wpc_written_files, true)) {
                    @unlink($file);
                    @unlink($file . '.md5');
                }
            }
        } catch (\Throwable $e) {
        }
    }

    public function script_combine_and_replace($tag)
    {
        if ($this->log_criticalCombine) {
            $this->logger->log('Starting new script.');
        }

        $tag = trim($tag[0]);
        if (empty($tag)) {
            return $tag;
        }
        $src = '';
        $media_query = null;

        $leftOut = $this->collect_only && strpos($tag, '<link') !== false && preg_match('/href=["\'](.*?)["\']/', $tag, $leftOutHref)
            ? $leftOutHref[1] : '';

        // Consent-platform CSS never rides a combined bundle — the bundle defers.
        if (class_exists('wps_rewriteLogic') && wps_rewriteLogic::wpc_consent_family($tag)) {
            if ($leftOut !== '') { $this->corpus_sheets[$leftOut] = 'left-out'; }
            return $tag;
        }

        // v7.21.289 — NEVER EAT OUR OWN MACHINERY. The combiner matched any
        // rel="stylesheet" link and any <style>: the href-less used-css REST link
        // (rel=stylesheet + data-wpc-rest, armed post-load by the boot) fell through the
        // src gate and was SWALLOWED — staging served the ucss-boot with zero links to
        // arm, the .286 gesture gate correctly read none, and every timer restore ran
        // (cmb + post css + fonts on the lab wire). The <style> arm could equally absorb
        // parked late-faces into a live bundle. Anything wpc-owned returns untouched.
        if (preg_match('/\bid=["\']wpc-|\bdata-wpc-|type=["\']wpc\//i', $tag)) {
            if ($leftOut !== '') { $this->corpus_sheets[$leftOut] = 'left-out'; }
            return $tag;
        }

        // Check if the CSS is Excluded
        if (self::$excludes->strInArray($tag, $this->allExcludes)) {
            if ($this->log_criticalCombine) {
                $this->logger->log('It is excluded.', true);
            }
            if ($leftOut !== '') { $this->corpus_sheets[$leftOut] = 'left-out'; }
            return $tag;
        }

        // If it has ie9 tag exclude by default
        if (strpos($tag, 'ie9') !== false) {
            if ($leftOut !== '') { $this->corpus_sheets[$leftOut] = 'left-out'; }
            return $tag;
        }

        // Extract media query if present
        if (preg_match('/media=["\']([^"\']+)["\']/', $tag, $media_match)) {
            $media_query = $media_match[1];
            if ($this->log_criticalCombine) {
                $this->logger->log('Media query found: ' . $media_query);
            }
        }

        if (strpos($tag, '<link') !== false) {
            $is_src_set = preg_match('/href=["|\'](.*?)["|\']/', $tag, $src);
        } elseif (strpos($tag, '<style') !== false) {
            $is_src_set = preg_match('/<style\b[^>]*\bhref=["\'](.*?)["\'][^>]*>/i', $tag, $src);
        }

        if ($is_src_set == 1) {
            $src = str_replace('href=', '', $src);
            $src = str_replace("'", "", $src);
            $src = str_replace('"', "", $src);
            $src = $src[0];

            if ($this->log_criticalCombine) {
                $this->logger->log('Src: ' . $src);
            }

            if ($this->collect_only) {
                $ownPath = $this->corpus_own_path($src);
                if ($ownPath === '') {
                    $content = $this->getRemoteContent($src);
                } else {
                    $content = strtolower(substr($ownPath, -4)) === '.css' ? $this->getLocalContent($ownPath) : false;
                }
            } elseif ($this->url_key_class->is_external($src)) {
                $content = $this->getRemoteContent($src);
            } else {
                $content = $this->getLocalContent($src);
            }

            if ($this->collect_only) {
                $this->corpus_sheets[$src] = $content === '' ? 'empty' : (!$content ? 'missing' : 'read');
            }
            if (!$content) {
                return $tag;
            }


            $this->asset_url = $src;

            if (!empty($_GET['dbgCombine']) && $_GET['dbgCombine'] == 'oldrewrite') {
                $content = preg_replace_callback("/url(\(((?:[^()])+)\))/i", [$this, 'rewrite_relative_url'], $content);
            } else {
                    $re = '~url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s]+))\s*\)~i';

                    $content = preg_replace_callback($re, function ($m) {
                        $url = '';
                        foreach ([1, 2, 3] as $i) {
                            if (isset($m[$i]) && $m[$i] !== '') {
                                $url = $m[$i];
                                break;
                            }
                        }

                        if ($url === '') return $m[0];

                        $new = $this->rewrite_relative_url($url);
                        if ($new === '' || $new === null) return $m[0];

                        // Unwrap if rewrite_relative_url mistakenly returned url(...)
                        if (is_string($new) && preg_match('~^\s*url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s]+))\s*\)\s*$~i', $new, $mm)) {
                            foreach ([1, 2, 3] as $i) {
                                if (isset($mm[$i]) && $mm[$i] !== '') {
                                    $new = $mm[$i];
                                    break;
                                }
                            }
                        }

                        if (!empty($m[1])) return 'url("' . $new . '")';
                        if (!empty($m[2])) return "url('" . $new . "')";
                        return 'url(' . $new . ')';
                    }, $content);

            }


        } else {
            $src = 'Inline Script';

            if ($this->log_criticalCombine) {
                $this->logger->log('Is inline.');
            }

            $content = $tag;
            $content = preg_replace('/<style(.*?)>/', '', $content, -1, $count);
            $content = preg_replace('/<\/style>/', '', $content);

            if (!$count) {

                return $tag;
            }
        }

        if ($this->log_criticalCombine) {
            $this->logger->log('Fetched.');
        }


        $content = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $content);
        // Prepending alone left ANY font-display the source already declared in place, and CSS
        // last-wins — so on every face that declared its own (Divi's ETmodules: block) our value
        // was silently a no-op and the original policy stood. Strip first, then prepend.
        // v7.21.135 — this writer hardcoded swap, ignoring both the dropdown and the shared
        // resolver (the .02 one-display-policy violated by one more lane). 'off' leaves faces
        // exactly as authored; text faces resolve per family through the same resolver every
        // other emitter uses; icon fonts stay block (never swap — the Divi "3" glyph lesson).
        $settings = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
        $fontDisplayRaw = (is_array($settings) && !empty($settings['font-display']))
            ? strtolower((string) $settings['font-display']) : 'smart';
        if ($fontDisplayRaw !== 'off') {
        $content = preg_replace_callback('/@font-face\s*\{([^}]*)\}/is', function ($m) use ($fontDisplayRaw) {
            $body = (string) preg_replace('/(?:^|;)\s*font-display\s*:\s*[a-zA-Z-]+\s*(?=;|$)/i', '', $m[1]);
            $body = ltrim($body, "; \t\r\n");
            // swap on an ICON font renders the raw codepoint in the fallback face — Divi's menu
            // arrow is content:"3", so swap paints a literal "3" until the icon font lands, then
            // reflows to the glyph (visible character change + a metric shift). Icon fonts must
            // stay block: invisible, then correct. Never swap.
            $disp = 'swap';
            $fontFamily = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $body, $wpc_ff)
                ? trim($wpc_ff[1], " \t\"'") : '';
            if ($fontFamily !== '' && wpc_css_is_icon_font($fontFamily)) {
                $disp = 'block';
            } elseif (function_exists('wpc_font_display_effective')) {
                $disp = wpc_font_display_effective($fontDisplayRaw, $fontFamily);
                if (!in_array($disp, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) { $disp = 'swap'; }
            }
            return '@font-face{font-display: ' . $disp . ';' . ($body !== '' ? $body : '') . '}';
        }, $content);
        }

        if ($this->enabledCDN) {
            $content = preg_replace_callback('/src:\s*url\("([^"]+\.woff2)"\)\s*format\(\s*\'woff2\'\s*\);/is', [$this, 'changeFontToCDN'], $content);
        }

        $this->current_file .= "/* SCRIPT : $src */" . PHP_EOL;
        // Wrap content in media query if it exists
        if ($media_query) {
            $this->current_file .= "@media " . $media_query . " {" . PHP_EOL;
            $this->current_file .= $content . PHP_EOL;
            $this->current_file .= "}" . PHP_EOL;
        } else {
            $this->current_file .= $content . PHP_EOL;
        }

        $this->write_file_and_next();

        if (!$this->firstFoundStyle) {
            $this->firstFoundStyle = true;
            return '<!--WPC_INSERT_COMBINED_CSS-->';
        } else {
            return '';
        }
    }

    public function getRemoteContent($url)
    {
        if ($this->log_criticalCombine) {
            $this->logger->log('Fetching script content.');
        }

        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        // Fetched serially per external sheet on an uncached render — an unbounded
        // timeout multiplies across sheets, so cap each hard.
        $args = array('timeout' => (int) apply_filters('wpc_combine_fetch_timeout', 3), 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36', 'headers' => array('Accept' => 'text/css,*/*;q=0.1', 'Accept-Language' => 'en-US,en;q=0.9',));
        if ($this->remote_deadline !== null) {
            $left = $this->remote_deadline - microtime(true);
            if ($left < 0.2) {
                return false;
            }
            $args['timeout'] = min((float) $args['timeout'], $left);
        }

        $data = wp_remote_get($url, $args);


        if (is_wp_error($data)) {
            if ($this->log_criticalCombine) {
                $this->logger->log('Failed fetching script content: WP_Error.', true);
            }
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($data);

        if ($response_code !== 200) {
            if ($this->log_criticalCombine) {
                $this->logger->log('Failed fetching script content. Response code: ' . $response_code, true);
            }
            return false;
        }

        $body = wp_remote_retrieve_body($data);

        // B1-R2 — a 200 does not mean it is CSS. A server answering a missing sheet with its
        // HTML error template (soft-404) passes the code check; reject it here so it can never
        // reach the bundle. Content-Type is the first signal; the body is the belt for servers
        // that mislabel or omit it. Rejecting returns false, and the caller leaves the sheet
        // un-combined (fail-open) rather than poisoning the combined file.
        $content_type = strtolower((string) wp_remote_retrieve_header($data, 'content-type'));
        if (strpos($content_type, 'text/html') !== false || $this->wpc_looks_like_html_doc($body)) {
            if ($this->log_criticalCombine) {
                $this->logger->log('Rejected non-CSS response (soft-404 / HTML) for: ' . $url, true);
            }
            return false;
        }

        if ($this->log_criticalCombine) {
            $this->logger->log('Script content fetched.');
        }

        return $body;
    }

    public function getLocalContent($url)
    {
        $output = [];

        if ($this->log_criticalCombine) {
            $this->logger->log('Fetching script content.');
        }

        if ($this->hmwpReplace) {

            foreach ($this->hmwp_rewrite->_replace['to'] as $key => $value) {
                $replace = $this->hmwp_rewrite->_replace['from'][$key];
                $url = str_replace($value, $replace, $url);
            }
            if ($this->log_criticalCombine) {
                $this->logger->log('Did hidemywp replacements and got ' . $url);
            }
        }

        if (!empty($this->zone_name) && strpos($url, $this->zone_name) !== false && preg_match('/a:(.*?)(\?|$)/', $url, $match)) {
            $url = trim($match[1]);
        }


        $url = preg_replace('/\?.*/', '', $url);

        $path = wp_make_link_relative($url);
        $path = ltrim($path, '/');

        // Upload Dir Path
        $uploadDir = wp_upload_dir();
        $uploadDir = $uploadDir['basedir'];

        // Includes Path
        $includesPath = ABSPATH . WPINC;

        // Theme Dir Path (Without Active Theme)
        $themePath = get_theme_root();

        // $path relative is example: wp-content/plugins/jeg-elementor-kit/assets/css/elements/main.css
        if (strpos($path, 'wp-content/plugins/') !== false) {
            // Plugins DIR: WP_PLUGIN_DIR
            $pathExploded = explode('wp-content/plugins/', $path);
            $justPath = $pathExploded[1];
            $finalPath = WP_PLUGIN_DIR . '/' . $justPath;
        } else if (strpos($path, 'wp-includes/') !== false) {
            // Uploads DIR: wp_upload_dir()
            $pathExploded = explode('wp-includes/', $path);
            $justPath = $pathExploded[1];
            $finalPath = $includesPath . '/' . $justPath;
        } else if (strpos($path, 'wp-content/uploads/') !== false) {
            // Uploads DIR: wp_upload_dir()
            $pathExploded = explode('wp-content/uploads/', $path);
            $justPath = $pathExploded[1];
            $finalPath = $uploadDir . '/' . $justPath;
        } else if (strpos($path, 'wp-content/themes/') !== false) {
            // Themes Dir: TEMPLATEPATH
            $pathExploded = explode('wp-content/themes/', $path);
            $justPath = $pathExploded[1];
            $finalPath = $themePath . '/' . $justPath;
        } else {
            $finalPath = ABSPATH . $path;
        }


        if ($this->log_criticalCombine) {
            $this->logger->log('Fetching script content.' . $finalPath);
        }

        $content = false;
        if (file_exists($finalPath)) {
            $content = file_get_contents($finalPath);
            if ($content === '' && is_file($finalPath)) {
                return '';
            }
        }

        if (!$content) {

            /** Workaround if file_get_contents failed */ global $wp_filesystem;

            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            WP_Filesystem();

            $content = false;
            if ($wp_filesystem && $wp_filesystem->exists($finalPath)) {
                $content = $wp_filesystem->get_contents($finalPath);
            }

            if (!$content || empty($content)) {

                if ($this->log_criticalCombine) {
                    $this->logger->log('Fetch failed,', true);
                }

                return false;
            }
        }

        if ($this->log_criticalCombine) {
            $this->logger->log('Fetched.');
        }

        return $content;
    }

    public function rewrite_relative_url(string $matched_url): string
    {
        $matched_url = trim($matched_url);
        $matched_url = trim($matched_url, " \t\n\r\0\x0B'\"");

        // Skip already-rewritten / external / data URLs
        if ($matched_url === '') return '';
        if (stripos($matched_url, 'data:') === 0) return $matched_url;


        if (stripos($matched_url, '/cache/wp-cio-fonts/') !== false) return $matched_url;

        if ((!empty($this->zone_name) && strpos($matched_url, (string) $this->zone_name) !== false) || strpos($matched_url, 'zapwp.net') !== false) {
            return $matched_url;
        }
        if (strpos($matched_url, 'google') !== false || strpos($matched_url, 'gstatic') !== false || strpos($matched_url, 'typekit') !== false) {
            return $matched_url;
        }

        $asset_url = $this->asset_url;
        $parsed_asset = parse_url($asset_url);
        $home = parse_url(get_home_url());

        $scheme = $parsed_asset['scheme'] ?? ($home['scheme'] ?? 'https');
        $host = $parsed_asset['host'] ?? ($home['host'] ?? '');

        // If it's already absolute (http/https or protocol-relative), just normalize
        if (preg_match('~^https?://~i', $matched_url)) {
            $absolute = $matched_url;
        } elseif (strpos($matched_url, '//') === 0) {
            $absolute = $scheme . ':' . $matched_url;
        } else {
            // Build base directory of the asset (CSS) file
            $asset_path = $parsed_asset['path'] ?? '/';
            $base_dir = rtrim(str_replace(basename($asset_path), '', $asset_path), '/');

            if (strpos($matched_url, '/') === 0) {
                // Root-relative
                $path = $matched_url;
            } else {
                // Relative to CSS directory
                $path = $base_dir . '/' . $matched_url;
            }

            // Normalize /./ and /../ segments
            $path = $this->normalize_path($path);

            $absolute = $scheme . '://' . $host . $path;
        }

        // Apply your "serve" logic BUT return plain URL (no url("..."))
        $lower = strtolower($matched_url);

        $is_font = (strpos($lower, '.eot') !== false || strpos($lower, '.woff') !== false || strpos($lower, '.woff2') !== false || strpos($lower, '.ttf') !== false);
        $is_img = (strpos($lower, '.jpg') !== false || strpos($lower, '.jpeg') !== false || strpos($lower, '.png') !== false || strpos($lower, '.gif') !== false || strpos($lower, '.svg') !== false || strpos($lower, '.webp') !== false);

        if ($is_font && !empty($this->settings['serve']['fonts'])) {


            $rru_fhost = function_exists('wp_parse_url') ? wp_parse_url($absolute, PHP_URL_HOST) : '';
            $rru_shost = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
            if (!empty($rru_fhost) && !empty($rru_shost) && strcasecmp((string) $rru_fhost, (string) $rru_shost) === 0
                && stripos((string) wp_parse_url($absolute, PHP_URL_PATH), '/wp-content/') === false) {
                return $absolute;
            }
            $font_path = (string) wp_parse_url($absolute, PHP_URL_PATH);
            if (!empty($rru_fhost) && !empty($rru_shost) && strcasecmp((string) $rru_fhost, (string) $rru_shost) === 0
                && stripos($font_path, '/wp-content/') !== false
                && strcasecmp((string) $this->zone_name, (string) $rru_shost) !== 0
                && apply_filters('wpc_combine_css_natural_fonts', true)) {
                return 'https://' . $this->zone_name . $font_path;
            }
            return 'https://' . $this->zone_name . '/m:0/a:' . $absolute;
        }

        if ($is_img) {

            $serve = false;
            if (strpos($lower, '.jpg') !== false && !empty($this->settings['serve']['jpg'])) $serve = true;
            if (strpos($lower, '.jpeg') !== false && !empty($this->settings['serve']['jpg'])) $serve = true;
            if (strpos($lower, '.png') !== false && !empty($this->settings['serve']['png'])) $serve = true;
            if (strpos($lower, '.gif') !== false && !empty($this->settings['serve']['gif'])) $serve = true;
            if (strpos($lower, '.svg') !== false && !empty($this->settings['serve']['svg'])) $serve = true;

            if ($serve) {


                $wpc_ih = function_exists('wp_parse_url') ? (string) wp_parse_url($absolute, PHP_URL_HOST) : '';
                $wpc_sh = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
                $wpc_ip = function_exists('wp_parse_url') ? (string) wp_parse_url($absolute, PHP_URL_PATH) : '';
                if ($wpc_ih !== '' && $wpc_sh !== '' && strcasecmp($wpc_ih, $wpc_sh) === 0
                    && stripos($wpc_ip, '/wp-content/') !== false
                    && strcasecmp((string) $this->zone_name, $wpc_sh) !== 0
                    && apply_filters('wpc_combine_css_natural_rasters', true)) {
                    return 'https://' . $this->zone_name . $wpc_ip;
                }
                return 'https://' . $this->zone_name . '/q:u/r:0/wp:0/w:1/u:' . $absolute;
            }
        }


        return $absolute;
    }

    /**
     * Normalize a URL path by resolving /./ and /../ segments.
     */
    private function normalize_path(string $path): string
    {
        $is_abs = (strpos($path, '/') === 0);
        $parts = explode('/', $path);
        $out = [];

        foreach ($parts as $p) {
            if ($p === '' || $p === '.') continue;
            if ($p === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $p;
        }

        return ($is_abs ? '/' : '') . implode('/', $out);
    }

    public function changeFontToCDN($html)
    {
        // Local-Fonts cache (wp-cio-fonts): NEVER zoneify — keep natural origin so it matches the inline
        // @font-face + preload + deferred .css (one URL, fetched once). $html[1] is the captured woff2 URL.
        if (stripos($html[1], '/cache/wp-cio-fonts/') !== false) {
            return 'src:url("' . $html[1] . '");';
        }


        if (!empty($this->zone_name) && strpos($html[1], $this->zone_name) !== false) {
            return 'src:url("' . $html[1] . '");';
        }


        $cf2_host = function_exists('wp_parse_url') ? wp_parse_url($html[1], PHP_URL_HOST) : '';
        $cf2_site = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
        if (!empty($cf2_host) && !empty($cf2_site) && strcasecmp((string) $cf2_host, (string) $cf2_site) === 0
            && stripos((string) wp_parse_url($html[1], PHP_URL_PATH), '/wp-content/') === false) {
            return 'src:url("' . $html[1] . '");';
        }
        if (!empty($this->settings['font-subsetting']) && $this->settings['font-subsetting'] == '1') {
            if (strpos($html[1], 'icon') === false && strpos($html[1], 'awesome') === false && strpos($html[1], 'lightgallery') === false && strpos($html[1], 'gallery') === false && strpos($html[1], 'side-cart-woocommerce') === false) {
                return 'src:url("https://' . $this->zone_name . '/font:true/a:' . $html[1] . '");';
            }
        }

        $font_path = (string) wp_parse_url($html[1], PHP_URL_PATH);
        if (!empty($cf2_host) && !empty($cf2_site) && strcasecmp((string) $cf2_host, (string) $cf2_site) === 0
            && stripos($font_path, '/wp-content/') !== false
            && strcasecmp((string) $this->zone_name, (string) $cf2_site) !== 0
            && apply_filters('wpc_combine_css_natural_fonts', true)) {
            return 'src:url("https://' . $this->zone_name . $font_path . '");';
        }
        return 'src:url("https://' . $this->zone_name . '/m:0/a:' . $html[1] . '");';
    }

    public function combine($html)
    {
        $html = $html[0];

        // Run for Cookie Compliant CSS
        if (!empty($_GET['testCompliant'])) {
            $html = $this->cookieCompliantCSS($html);
        }

        // STEP 1: Extract and preserve all <script> tags (including their content)
        $script_placeholders = [];
        $script_counter = 0;

        $html = preg_replace_callback('/<script\b[^>]*>.*?<\/script>/si', function ($match) use (&$script_placeholders, &$script_counter) {
            $placeholder = "___SCRIPT_PLACEHOLDER_{$script_counter}___";
            $script_placeholders[$placeholder] = $match[0];
            $script_counter++;
            return $placeholder;
        }, $html);

        // STEP 2: Now process CSS (scripts are temporarily removed)
        $html = preg_replace_callback($this->patterns, [$this, 'script_combine_and_replace'], $html);

        // STEP 3: Restore all <script> tags
        foreach ($script_placeholders as $placeholder => $original_script) {
            $html = str_replace($placeholder, $original_script, $html);
        }

        return $html;
    }

    public function cookieCompliantCSS($html)
    {
        $pattern = '/<script[^>]*id="cmplz-cookiebanner-js-extra"[^>]*>(.*?)<\/script>/si';
        if (preg_match($pattern, $html, $matches)) {
            $script_content = $matches[1];

            // 2. Extract the JSON: var complianz = {...};
            if (preg_match('/var complianz\s*=\s*(\{.*?\});/s', $script_content, $json_match)) {
                $json_string = $json_match[1];

                // 3. Decode JSON to PHP array
                $complianz = json_decode($json_string, true);


                if ($complianz && isset($complianz['css_file'])) {
                    $css_file = $complianz['css_file'];
                    $banner_id = $complianz['user_banner_id'] ?? '1';
                    $type = $complianz['consenttype'] ?? 'optin';

                    // 4. Replace placeholders
                    $css_file_final = str_replace(['{banner_id}', '{type}'], [$banner_id, $type], $css_file);

                    // 5. Insert <link> before </head>

                    if (!empty($_GET['dbgCmplz']) && $_GET['dbgCmplz'] == 'inject-entities') {
                        $link_tag = htmlentities("<link rel='stylesheet' id='wpc-cmplz-banner' href='" . $css_file_final . "' type='text/css' media='all' />");
                    } else {
                        $link_tag = '<link rel="stylesheet" id="wpc-cmplz-banner" href="' . $css_file_final . '" type="text/css" media="all" />';
                    }

                    $pattern = '/<script[^>]*id="cmplz-cookiebanner-js-extra"[^>]*>.*?<\/script>/si';

                    if (preg_match($pattern, $html, $matches)) {
                        $matched_script = $matches[0];

                        // Debug match
                        $html = str_replace($matched_script, $link_tag, $html);
                    } else {
                        return 'REGEX DID NOT MATCH';
                    }

                    return $html;
                }
            }
        }

        return $html;
    }
}
