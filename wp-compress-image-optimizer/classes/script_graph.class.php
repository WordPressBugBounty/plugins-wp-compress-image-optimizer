<?php

/**
 * The script dependency graph of a page, answered to the doctor's assets compartment
 * (addons/doctor/compartments/assets.php).
 *
 * A request that carries both disableWPC=true (the plugin does not load: the page is what
 * WordPress built) and wpc_script_graph=1 gets one HTML comment at the end of the footer:
 *
 *   <!-- wpc-script-graph: {"v":1,"handles":{"jquery":["jquery-core","jquery-migrate"],...}} -->
 *
 * The handles are every script WordPress printed on the page plus every handle they depend on,
 * aliases included, each with the dependencies it registered. Names only: no source, no inline
 * data. Any "--" in the JSON is written as two JSON-escaped hyphens, so the comment cannot close early.
 * wp-compress.php requires this file on a request that names wpc_script_graph and arms it there.
 *
 *   arm()               hook the comment onto the footer when the request asks for it
 *   print_comment()     print the comment
 *   comment($scripts)   the comment for a WP_Scripts instance
 *   graph($scripts)     handle => dependencies
 */
if (!class_exists('wps_ic_script_graph')) {
    class wps_ic_script_graph
    {
        /** The schema of the JSON a reader checks. */
        const VERSION = 1;

        /** Hooks the footer comment when the request carries both parameters; whether it did. */
        public static function arm()
        {
            if (!isset($_GET['wpc_script_graph']) || !is_string($_GET['wpc_script_graph']) || $_GET['wpc_script_graph'] !== '1'
                || empty($_GET['disableWPC']) || !function_exists('add_action')) {
                return false;
            }
            add_action('wp_footer', [__CLASS__, 'print_comment'], PHP_INT_MAX);
            return true;
        }

        /** Prints the comment for this request's scripts. */
        public static function print_comment()
        {
            echo self::comment(isset($GLOBALS['wp_scripts']) ? $GLOBALS['wp_scripts'] : null);
        }

        /** The comment for $scripts; '' when the graph cannot be encoded. */
        public static function comment($scripts)
        {
            $json = json_encode(['v' => self::VERSION, 'handles' => (object) self::graph($scripts)], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($json)) {
                return '';
            }
            return '<!-- wpc-script-graph: ' . str_replace('--', '\u002d\u002d', $json) . ' -->';
        }

        /**
         * Every handle in $scripts->done, in the order WordPress printed them, then every handle
         * any of them depends on, each as handle => the dependencies it registered. A handle with
         * no registration has none.
         */
        public static function graph($scripts)
        {
            $registered = is_object($scripts) && isset($scripts->registered) && is_array($scripts->registered) ? $scripts->registered : [];
            $queue = is_object($scripts) && isset($scripts->done) && is_array($scripts->done) ? array_map('strval', array_values($scripts->done)) : [];
            $graph = [];
            for ($at = 0; $at < count($queue); $at++) {
                $handle = $queue[$at];
                if (isset($graph[$handle])) {
                    continue;
                }
                $deps = [];
                if (isset($registered[$handle]) && is_object($registered[$handle]) && isset($registered[$handle]->deps) && is_array($registered[$handle]->deps)) {
                    foreach ($registered[$handle]->deps as $dep) {
                        $deps[] = (string) $dep;
                    }
                }
                $graph[$handle] = $deps;
                foreach ($deps as $dep) {
                    $queue[] = $dep;
                }
            }
            return $graph;
        }
    }
}
