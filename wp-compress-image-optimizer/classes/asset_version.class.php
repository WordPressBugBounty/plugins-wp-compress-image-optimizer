<?php
/**
 * Per-file cache-buster for the css/js URLs the plugin rewrites.
 *
 * The ?icv= / ?js_icv= value used to be one site-wide hash re-minted on every purge, so a
 * purge changed the URL of every stylesheet and script whether or not the file changed, and
 * the CDN treated them all as new objects (15–18% of CDN misses on the fixed 50-zone sample).
 * The value is now derived from the file itself: substr(md5(filemtime . filesize), 0, 8),
 * cached in a transient keyed by path under a salt that a purge bumps. A remote or missing
 * file gets the plugin version string, never a random value, so an unchanged file keeps its
 * URL across purges and only an edited file changes.
 */
class wps_ic_asset_version
{
    const SALT_OPTION = 'wpc_asset_ver_salt';
    const TTL = 43200;

    protected static $memo = [];

    /** Version token for the local file behind a first-party css/js URL. */
    public static function for_url($url)
    {
        $path = self::local_path($url);
        return $path !== '' ? self::for_path($path) : self::fallback();
    }

    /** Version token for a local file path. */
    public static function for_path($path)
    {
        $path = (string) $path;
        if ($path === '' || !@is_file($path)) {
            return self::fallback();
        }
        if (isset(self::$memo[$path])) {
            return self::$memo[$path];
        }
        $key = 'wpc_av_' . md5(self::salt() . '|' . $path);
        $hit = function_exists('get_transient') ? get_transient($key) : false;
        if (is_string($hit) && preg_match('/^[a-f0-9]{8}$/', $hit)) {
            return self::$memo[$path] = $hit;
        }
        $mt = (int) @filemtime($path);
        $sz = (int) @filesize($path);
        if ($mt <= 0 || $sz <= 0) {
            return self::fallback();
        }
        $v = substr(md5($mt . $sz), 0, 8);
        if (function_exists('set_transient')) {
            set_transient($key, $v, self::TTL);
        }
        return self::$memo[$path] = $v;
    }

    /**
     * Local file behind a first-party URL: /wp-content/ paths map to WP_CONTENT_DIR,
     * /wp-includes/ and /wp-admin/ to ABSPATH. Anything else is remote and gets ''.
     */
    public static function local_path($url)
    {
        $path = (string) parse_url(html_entity_decode((string) $url, ENT_QUOTES), PHP_URL_PATH);
        if ($path === '' || strpos($path, '..') !== false) {
            return '';
        }
        if (($i = strpos($path, '/wp-content/')) !== false && defined('WP_CONTENT_DIR')) {
            $f = rtrim(WP_CONTENT_DIR, '/') . substr($path, $i + 11);
        } elseif ((($i = strpos($path, '/wp-includes/')) !== false || ($i = strpos($path, '/wp-admin/')) !== false) && defined('ABSPATH')) {
            $f = rtrim(ABSPATH, '/') . substr($path, $i);
        } else {
            return '';
        }
        return @is_file($f) ? $f : '';
    }

    /** Invalidates every cached token: the next request re-reads mtime and size. */
    public static function reset()
    {
        self::$memo = [];
        if (function_exists('update_option')) {
            update_option(self::SALT_OPTION, (string) time() . '.' . mt_rand(1000, 9999), false);
        }
    }

    protected static function salt()
    {
        return function_exists('get_option') ? (string) get_option(self::SALT_OPTION, '0') : '0';
    }

    protected static function fallback()
    {
        return defined('WPC_PLUGIN_VERSION') ? preg_replace('/[^0-9.]/', '', (string) WPC_PLUGIN_VERSION) : '0';
    }
}
