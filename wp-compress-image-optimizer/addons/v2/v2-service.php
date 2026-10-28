<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_orchestrator_url')) {
    function wpc_v2_orchestrator_url()
    {

        if (defined('WPC_V2_ORCHESTRATOR_URL') && WPC_V2_ORCHESTRATOR_URL !== '') {
            return rtrim((string) WPC_V2_ORCHESTRATOR_URL, '/');
        }

        // 2) Filter override.
        $override = function_exists('apply_filters') ? apply_filters('wpc_v2_orchestrator_url', '') : '';
        if ($override !== '') return rtrim((string) $override, '/');


        $valid_hosts = ['local-mc.zapwp.net'];
        if (function_exists('apply_filters')) {
            $valid_hosts = apply_filters('wpc_v2_orchestrator_valid_hosts', $valid_hosts);
        }
        $geo = function_exists('get_option') ? get_option('wps_ic_geo_locate_v2') : false;
        if (is_array($geo) && !empty($geo['server'])) {
            $server = trim((string) $geo['server'], '/');
            // Strip scheme for the whitelist check; preserve original for return.
            $host_only = preg_replace('#^https?://#i', '', $server);
            if (in_array($host_only, $valid_hosts, true)) {
                if (preg_match('#^https?://#i', $server)) return $server;
                return 'https://' . $server;
            }


        }


        return 'https://local-mc.zapwp.net';
    }
}

if (!function_exists('wpc_v2_service_sign')) {
    /**
     * The signature headers of a request to the image service's signed plugin routes (the manifest
     * GET, ack and purge, and the variants list and delete). $message is what the route verifies:
     * the canonical query for a GET, the raw body for a POST.
     *
     * Rule: X-WPC-Sig = HMAC-SHA256(apikey, "<X-WPC-Timestamp>.<message>"), the timestamp being the
     * exact header string sent, as verifyHmacRawBytes() in the orchestrator's lib/manifestPull.js
     * checks its v2 form. Observed: the v1 form HMAC(apikey, message) bound nothing to the timestamp,
     * so a captured request replayed with a fresh X-WPC-Timestamp still verified; the service
     * accepts v2 since v3.24.143 and drops v1 once its sig_form counter shows v1 near zero (hub ask
     * 047, item 1).
     */
    function wpc_v2_service_sign($apikey, $message, $ts = null)
    {
        $ts = (string) ($ts === null ? time() : (int) $ts);
        return [
            'X-WPC-Sig'       => hash_hmac('sha256', $ts . '.' . (string) $message, (string) $apikey),
            'X-WPC-Timestamp' => $ts,
        ];
    }
}

if (!function_exists('wpc_v2_service_image_id')) {
    /**
     * An image id as the image service keys it: the integer for a WordPress attachment, the string
     * exactly as the service named it for anything else (a lazy-CDN image is `lazycdn-...`). The
     * landed receipt and the manifest ack both send ids through here.
     */
    function wpc_v2_service_image_id($id)
    {
        if (is_int($id)) {
            return $id;
        }
        if (is_numeric($id)) {
            return (int) $id;
        }
        return is_scalar($id) ? (string) $id : '';
    }
}

if (!function_exists('wpc_v2_journal_guard')) {
    /**
     * The deny file (Apache 2.2 and 2.4) and the empty index every directory of the journal carries.
     * One content for all of them; a file that is there is never overwritten.
     */
    function wpc_v2_journal_guard($dir)
    {
        $dir = rtrim((string) $dir, '/\\');
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $put = function ($path, $body) {
            if (function_exists('wpc_fs_put')) {
                wpc_fs_put($path, $body);
            } else {
                @file_put_contents($path, $body);
            }
        };
        if (!is_file($dir . '/.htaccess')) {
            $put($dir . '/.htaccess', "Order Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.html')) {
            $put($dir . '/index.html', '');
        }
    }
}

if (!function_exists('wpc_v2_journal_mkdir')) {
    /**
     * The one way a directory of the journal is made: WordPress's wp_mkdir_p() where it is loaded (a new
     * directory takes its parent's permission bits), mkdir 0755 where it is not loaded or answers false and
     * the directory is still absent.
     */
    function wpc_v2_journal_mkdir($dir)
    {
        if (function_exists('wp_mkdir_p') && wp_mkdir_p($dir)) {
            return true;
        }
        return is_dir($dir) || @mkdir($dir, 0755, true);
    }
}

if (!function_exists('wpc_v2_journal_fire_due')) {
    /**
     * The one bound on the drain kick of both lanes (v2-journal.php, api/v2/_shared.php): true, with the kick
     * stamped, when the last stamp is WPC_V2_JOURNAL_FIRE_THROTTLE_MS (250 ms, never under 50) or more away from
     * now, behind it or ahead of it; false otherwise, with wpc_v2_journal_pending_fire set.
     */
    function wpc_v2_journal_fire_due()
    {
        $throttle_ms = defined('WPC_V2_JOURNAL_FIRE_THROTTLE_MS')
            ? max(50, (int) WPC_V2_JOURNAL_FIRE_THROTTLE_MS)
            : 250;
        $now_ms       = (int) (microtime(true) * 1000);
        $last_fire_ms = (int) get_option('wpc_v2_journal_fire_last_ms', 0);

        $since_ms     = $now_ms - $last_fire_ms;

        if (abs($since_ms) < $throttle_ms) {
            set_transient('wpc_v2_journal_pending_fire', $now_ms, 60);
            return false;
        }
        update_option('wpc_v2_journal_fire_last_ms', $now_ms, false);
        return true;
    }
}

if (!function_exists('wpc_v2_journal_max_files')) {
    function wpc_v2_journal_max_files()
    {
        $max = defined('WPC_V2_JOURNAL_MAX_FILES') ? (int) WPC_V2_JOURNAL_MAX_FILES : 5000;
        return max(100, (int) apply_filters('wpc_v2_journal_max_files', $max));
    }
}

if (!function_exists('wpc_v2_journal_count_files')) {
    function wpc_v2_journal_count_files($limit = 1000)
    {
        $up = wp_get_upload_dir();
        if (empty($up['basedir'])) {
            return 0;
        }
        $dir = rtrim($up['basedir'], '/\\') . '/wpci-journal';
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        $dh = @opendir($dir);
        if (!$dh) {
            return 0;
        }
        while (($f = readdir($dh)) !== false) {
            if (substr($f, -6) === '.jsonl') {
                $n++;
            }
            if ($n > $limit) {
                break;
            }
        }
        closedir($dh);
        return $n;
    }
}

if (!function_exists('wpc_v2_journal_has_room')) {
    function wpc_v2_journal_has_room()
    {
        $cap = wpc_v2_journal_max_files();
        return wpc_v2_journal_count_files($cap) < $cap;
    }
}

if (!function_exists('wpc_v2_journal_root')) {
    /**
     * uploads/wpci-journal, the one place the directory is made. With $create it is made when it
     * is missing and its guard files are written when they are missing (a directory that was made
     * bare by an earlier release is closed here too); without it nothing is written. '' when the
     * uploads directory is unknown or the directory cannot be had.
     */
    function wpc_v2_journal_root($create = true)
    {
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        $base = isset($up['basedir']) ? rtrim((string) $up['basedir'], '/\\') : '';
        if ($base === '') {
            return '';
        }
        $dir = $base . '/wpci-journal';
        if (!is_dir($dir)) {
            if (!$create || (!wpc_v2_journal_mkdir($dir) && !is_dir($dir))) {
                return '';
            }
        }
        if ($create) {
            wpc_v2_journal_guard($dir);
        }
        return $dir;
    }
}

