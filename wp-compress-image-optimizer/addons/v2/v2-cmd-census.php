<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_cmd_door')) {
    /**
     * The name of the remote door this request calls, lowercase: comms:<action>, ajax:<action>, rest:<route>,
     * get:<the service's query flag>, or path:<script>. The signed-command message carries the same string.
     */
    function wpc_cmd_door()
    {
        return strtolower(wpc_cmd_door_raw());
    }
}

if (!function_exists('wpc_cmd_door_raw')) {
    function wpc_cmd_door_raw()
    {
        $clean = function ($v) {
            return is_string($v) ? substr(preg_replace('/[^A-Za-z0-9_\-\/]/', '', $v), 0, 48) : '';
        };
        if (isset($_REQUEST['comms_action']) && $clean($_REQUEST['comms_action']) !== '') {
            return 'comms:' . $clean($_REQUEST['comms_action']);
        }
        if (((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX)) && isset($_REQUEST['action']) && $clean($_REQUEST['action']) !== '') {
            return 'ajax:' . $clean($_REQUEST['action']);
        }
        if (isset($_GET['rest_route']) && $clean($_GET['rest_route']) !== '') {
            return 'rest:' . ltrim($clean($_GET['rest_route']), '/');
        }
        $path = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        $rest = strpos($path, '/wp-json/');
        if ($rest !== false) {
            return 'rest:' . $clean(substr($path, $rest + 9));
        }
        foreach (['criticalDone', 'pagespeedDone', 'wpc_optimization_done', 'wpc_start_test', 'fetchTest', 'apiGenerateCritical', 'apiPreload', 'runPurge', 'purge_other_cache', 'wpc_action', 'wps_ic_action', 'run_restore', 'run_compress'] as $flag) {
            if (isset($_GET[$flag])) {
                return 'get:' . $flag;
            }
        }
        foreach (array_keys($_GET) as $name) {
            if (is_string($name) && !in_array(strtolower($name), ['apikey', 'key', 'url', 't', 'ts', 'sig', 'hash'], true) && $clean($name) !== '') {
                return 'get:' . $clean($name);
            }
        }
        return 'path:' . $clean(basename($path));
    }
}

if (!function_exists('wpc_cmd_key_form')) {
    /** How the key came: key-url when it is in the query string, else key-body. */
    function wpc_cmd_key_form($given)
    {
        if (!is_string($given) || $given === '') {
            return 'key-body';
        }
        if (in_array($given, array_filter((array) $_GET, 'is_string'), true)) {
            return 'key-url';
        }
        $query = isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        return ($query !== '' && strpos(rawurldecode($query), $given) !== false) ? 'key-url' : 'key-body';
    }
}

if (!defined('WPC_CMD_PUBKEYS')) {
    define('WPC_CMD_PUBKEYS', []);
}

if (!function_exists('wpc_cmd_signature_state')) {
    /**
     * The signed-command check, log-only: none (no X-WPC-Cmd header), malformed, nokey (no public key
     * for its kid), nosodium (no Ed25519 verifier), stale (t more than 300 s away), bad, or ok. The header is
     * v=1,kid=<kid>,t=<unix>,sig=<base64 Ed25519> over
     * "wpc-cmd|v1|<home host>|<t>|<METHOD>|<door, lowercase>|<sha256 hex of query . "\n" . raw body>".
     * A multipart/form-data body is not readable as raw bytes, so a signed command sends its body urlencoded or as JSON.
     */
    function wpc_cmd_signature_state($door, $now = null, $keys = null)
    {
        $header = isset($_SERVER['HTTP_X_WPC_CMD']) && is_string($_SERVER['HTTP_X_WPC_CMD']) ? trim($_SERVER['HTTP_X_WPC_CMD']) : '';
        if ($header === '') {
            return 'none';
        }
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            $eq = strpos($kv, '=');
            if ($eq !== false) {
                $parts[trim(substr($kv, 0, $eq))] = trim(substr($kv, $eq + 1));
            }
        }
        if (($parts['v'] ?? '') !== '1' || !isset($parts['kid'], $parts['t'], $parts['sig']) || !ctype_digit($parts['t'])) {
            return 'malformed';
        }
        $keys = is_array($keys) ? $keys : WPC_CMD_PUBKEYS;
        $pub = isset($keys[$parts['kid']]) ? base64_decode((string) $keys[$parts['kid']], true) : false;
        if (!is_string($pub) || strlen($pub) !== 32) {
            return 'nokey';
        }
        $now = $now === null ? time() : (int) $now;
        if (abs($now - (int) $parts['t']) > 300) {
            return 'stale';
        }
        $sig = base64_decode($parts['sig'], true);
        if (!is_string($sig) || strlen($sig) !== 64) {
            return 'bad';
        }
        if (!function_exists('sodium_crypto_sign_verify_detached') && defined('ABSPATH') && defined('WPINC') && @is_file(ABSPATH . WPINC . '/sodium_compat/autoload.php')) {
            require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return 'nosodium';
        }
        $home = function_exists('home_url') ? home_url() : (function_exists('get_option') ? (string) get_option('home') : '');
        $host = strtolower((string) parse_url($home, PHP_URL_HOST));
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $query = isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        $digest = hash_init('sha256');
        hash_update($digest, $query . "\n");
        $input = @fopen('php://input', 'rb');
        if ($input) {
            hash_update_stream($digest, $input);
            fclose($input);
        }
        $message = 'wpc-cmd|v1|' . $host . '|' . $parts['t'] . '|' . $method . '|' . strtolower((string) $door) . '|' . hash_final($digest);
        try {
            return sodium_crypto_sign_verify_detached($sig, $message, $pub) ? 'ok' : 'bad';
        } catch (\Throwable $e) {
            return 'bad';
        }
    }
}

if (!function_exists('wpc_cmd_census')) {
    /**
     * Records that a key-authorised door was called: the door, how the caller authenticated
     * (key-url, key-body, key-hmac, cb-secret, key-token, self-hmac) and the signed-command state, each with the last
     * time seen. Option wpc_cmd_census, at most 100 doors, rewritten only when something is new or an
     * hour has passed. Returns the signature state.
     */
    function wpc_cmd_census($door, $auth)
    {
        $door = substr(strtolower(preg_replace('/[^A-Za-z0-9_:.\-\/]/', '', (string) $door)), 0, 64);
        $auth = substr(preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $auth)), 0, 16);
        $state = wpc_cmd_signature_state($door);
        if ($door === '' || !function_exists('get_option')) {
            return $state;
        }
        try {
            $census = get_option('wpc_cmd_census');
            $census = is_array($census) ? $census : [];
            $now = time();
            $row = isset($census[$door]) && is_array($census[$door]) ? $census[$door] : ['f' => $now, 'l' => 0, 'a' => [], 's' => []];
            $new = !isset($row['a'][$auth]) || !isset($row['s'][$state]);
            if (!$new && $now - (int) $row['l'] < 3600) {
                return $state;
            }
            if (!isset($census[$door]) && count($census) >= 100) {
                return $state;
            }
            $row['a'][$auth] = $now;
            $row['s'][$state] = $now;
            $row['l'] = $now;
            $census[$door] = $row;
            update_option('wpc_cmd_census', $census, false);
        } catch (\Throwable $e) {
        }
        return $state;
    }
}

if (!function_exists('wpc_cmd_census_echo')) {
    /** The census as the config sync carries it: doors seen in the last 30 days, each auth form and signature state with the last time it was seen. */
    function wpc_cmd_census_echo()
    {
        $census = function_exists('get_option') ? get_option('wpc_cmd_census') : [];
        $out = [];
        $since = time() - 30 * 86400;
        foreach (is_array($census) ? $census : [] as $door => $row) {
            if (!is_array($row) || (int) ($row['l'] ?? 0) < $since) {
                continue;
            }
            $out[] = ['door' => (string) $door, 'auth' => array_map('intval', (array) ($row['a'] ?? [])), 'signed' => array_map('intval', (array) ($row['s'] ?? [])), 'first' => (int) ($row['f'] ?? 0), 'last' => (int) $row['l']];
        }
        return $out;
    }
}
