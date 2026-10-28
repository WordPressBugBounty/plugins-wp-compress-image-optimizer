<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_report_stamp_door_key')) {
    /**
     * The secret a direct-entry door (api/v2) and the render share without a plugin boot. Where wp-config.php
     * carries at least two unique salts (32 characters or more, not WordPress's placeholder) it is derived from
     * them, so nothing is stored and no two first renders can disagree about it (WordPress's add_option
     * overwrites on a second insert); constants exist under SHORTINIT. Where it does not, it is wpc_szr_secret,
     * 64 random hex characters kept in the options table, made once by the render (with $create, which also
     * replaces a stored value too short to be one) and never by a door. Either way it is not the site key and
     * not made from it, so the public stamp it signs is no test of a guess at the key. '' when there is none.
     */
    function wpc_report_stamp_door_key($create = false)
    {
        $parts = [];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $name) {
            $value = defined($name) ? (string) constant($name) : '';
            if (strlen($value) >= 32 && stripos($value, 'put your unique phrase here') === false) {
                $parts[] = $value;
            }
        }
        if (count($parts) >= 2) {
            return hash_hmac('sha256', 'wpc-sized-stamp|v1', implode('|', $parts));
        }
        $secret = get_option('wpc_szr_secret', '');
        if (is_string($secret) && strlen($secret) >= 32) {
            return $secret;
        }
        if (!$create || !function_exists('add_option')) {
            return '';
        }
        if ($secret !== '' && function_exists('delete_option')) {
            delete_option('wpc_szr_secret');
        }
        try {
            add_option('wpc_szr_secret', bin2hex(random_bytes(32)), '', 'no');
        } catch (\Throwable $e) {
            return '';
        }
        $secret = get_option('wpc_szr_secret', '');
        return is_string($secret) && strlen($secret) >= 32 ? $secret : '';
    }
}

if (!function_exists('wpc_report_stamp_mac')) {
    /**
     * The one keyed hash every visitor-report stamp is made with, under the label the caller names.
     * Keyed on the salt (or the api key) and ABSPATH; with $door true it is keyed on the site's own
     * stamp secret (wpc_report_stamp_door_key) and answers '' when there is none, because a
     * direct-entry door runs under SHORTINIT, where wp_salt() does not exist, and has to compute the
     * value the render computed.
     */
    function wpc_report_stamp_mac($label, $door = false)
    {
        if ($door) {
            $key = wpc_report_stamp_door_key();
            return $key === '' ? '' : substr(hash_hmac('sha256', (string) $label, $key), 0, 20);
        }
        $secret = function_exists('wp_salt') ? (string) wp_salt('nonce') : '';
        if ($secret === '' && function_exists('get_option') && defined('WPS_IC_OPTIONS')) {
            $opts = get_option(WPS_IC_OPTIONS);
            $secret = is_array($opts) && !empty($opts['api_key']) ? (string) $opts['api_key'] : '';
        }
        return substr(hash_hmac('sha256', (string) $label, $secret . '|' . ABSPATH), 0, 20);
    }
}
