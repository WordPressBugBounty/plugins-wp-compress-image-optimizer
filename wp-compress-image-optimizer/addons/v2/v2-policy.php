<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-policy.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.24.00
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_policy23_defaults')) {
    function wpc_policy23_defaults()
    {
        $s = function_exists('get_option') ? get_option(WPS_IC_SETTINGS, []) : [];
        $s = is_array($s) ? $s : [];
        $live = !empty($s['live-cdn']) && (string) $s['live-cdn'] === '1';
        $ceiling = 'avif';
        if (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'effective_ceiling')) {
            $c = (string) WPC_Delivery_Resolver::effective_ceiling($s);
            if ($c === 'webp' || $c === 'avif' || $c === 'off') {
                $ceiling = $c;
            }
        }
        return [
            'serve'   => $live ? 'edge' : 'origin',
            'land'    => 'on',
            'scope'   => 'site',
            'formats' => $ceiling,
            'budget'  => 0,
            'version' => 0,
            'source'  => 'derived',
        ];
    }
}

if (!function_exists('wpc_policy23_normalize')) {
    function wpc_policy23_normalize($p)
    {
        if (!is_array($p)) {
            return null;
        }
        $out = [];
        if (isset($p['serve']) && in_array($p['serve'], ['edge', 'origin'], true)) {
            $out['serve'] = $p['serve'];
        }
        if (isset($p['land'])) {
            $l = $p['land'];
            if ($l === 'on' || $l === 'off') {
                $out['land'] = $l;
            } elseif (is_bool($l) || $l === 1 || $l === 0 || $l === '1' || $l === '0') {
                $out['land'] = ((bool) $l) ? 'on' : 'off';
            }
        }
        if (isset($p['scope']) && in_array($p['scope'], ['site', 'homepage'], true)) {
            $out['scope'] = $p['scope'];
        }
        if (isset($p['formats']) && in_array($p['formats'], ['webp', 'avif', 'off'], true)) {
            $out['formats'] = $p['formats'];
        }
        if (isset($p['budget']) && is_numeric($p['budget'])) {
            $out['budget'] = max(0, (int) $p['budget']);
        }
        if (isset($p['version']) && is_numeric($p['version'])) {
            $out['version'] = max(0, (int) $p['version']);
        }
        return $out;
    }
}

if (!function_exists('wpc_policy23_stored')) {
    function wpc_policy23_stored()
    {
        $o = function_exists('get_option') ? get_option('wpc_policy23', null) : null;
        return is_array($o) ? $o : null;
    }
}

if (!function_exists('wpc_policy23_local')) {
    function wpc_policy23_local()
    {
        $o = function_exists('get_option') ? get_option('wpc_policy23_local', []) : [];
        return is_array($o) ? $o : [];
    }
}

if (!function_exists('wpc_policy23')) {
    function wpc_policy23()
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $p = wpc_policy23_defaults();
        $stored = wpc_policy23_stored();
        if (is_array($stored)) {
            foreach (['serve', 'land', 'scope', 'formats', 'budget', 'version'] as $k) {
                if (array_key_exists($k, $stored)) {
                    $p[$k] = $stored[$k];
                }
            }
            $p['source'] = 'orchestrator';
        }
        $local = wpc_policy23_local();
        if (isset($local['serve']) && $local['serve'] === 'origin') {
            $p['serve'] = 'origin';
        }
        if (isset($local['land']) && $local['land'] === 'on') {
            $p['land'] = 'on';
        }
        $f = apply_filters('wpc_policy23', $p);
        if ($f === null || $f === false) {
            $f = wpc_policy23_defaults();
        }
        $memo = is_array($f) ? $f : $p;
        return $memo;
    }
}

if (!function_exists('wpc_policy23_get')) {
    function wpc_policy23_get($key, $default = null)
    {
        $p = wpc_policy23();
        return array_key_exists($key, $p) ? $p[$key] : $default;
    }
}

if (!function_exists('wpc_policy23_serve')) {
    function wpc_policy23_serve()
    {
        return (string) wpc_policy23_get('serve', 'edge');
    }
}

if (!function_exists('wpc_policy23_land')) {
    function wpc_policy23_land()
    {
        return (string) wpc_policy23_get('land', 'on');
    }
}

if (!function_exists('wpc_policy23_formats')) {
    function wpc_policy23_formats()
    {
        return (string) wpc_policy23_get('formats', 'avif');
    }
}

if (!function_exists('wpc_policy23_scope')) {
    function wpc_policy23_scope()
    {
        return (string) wpc_policy23_get('scope', 'site');
    }
}

if (!function_exists('wpc_policy23_version')) {
    function wpc_policy23_version()
    {
        $s = wpc_policy23_stored();
        return is_array($s) && isset($s['version']) ? (int) $s['version'] : 0;
    }
}

if (!function_exists('wpc_policy23_ingest')) {
    function wpc_policy23_ingest($rbody)
    {
        if (!is_array($rbody)) {
            return false;
        }
        $raw = null;
        if (isset($rbody['policy']) && is_array($rbody['policy'])) {
            $raw = $rbody['policy'];
        } elseif (!empty($rbody['zones']) && is_array($rbody['zones'])) {
            foreach ($rbody['zones'] as $rz) {
                if (is_array($rz) && isset($rz['policy']) && is_array($rz['policy'])) {
                    $raw = $rz['policy'];
                    break;
                }
            }
        }
        if ($raw === null) {
            return false;
        }
        $new = wpc_policy23_normalize($raw);
        if (!is_array($new) || empty($new)) {
            return false;
        }
        $old = wpc_policy23_stored();
        if (is_array($old) && isset($old['version']) && isset($new['version']) && (int) $new['version'] < (int) $old['version']) {
            return false;
        }
        $new['received_at'] = time();
        update_option('wpc_policy23', $new, false);
        $changed = [];
        foreach (['serve', 'land', 'scope', 'formats'] as $k) {
            $was = is_array($old) && isset($old[$k]) ? $old[$k] : null;
            if (isset($new[$k]) && $new[$k] !== $was) {
                $changed[$k] = [$was, $new[$k]];
            }
        }
        if (!empty($changed)) {
            wpc_policy23_transition($changed);
        }
        return true;
    }
}

if (!function_exists('wpc_policy23_transition')) {
    function wpc_policy23_transition($changed)
    {
        if (!is_array($changed) || empty($changed)) {
            return;
        }
        if (isset($changed['land']) && $changed['land'][1] === 'on' && $changed['land'][0] === 'off') {
            wpc_policy23_materialize();
        }
        if (isset($changed['serve']) && function_exists('wpc_crit_soft_purge_all')) {
            wpc_crit_soft_purge_all();
        }
        if (isset($changed['formats']) && function_exists('update_option')) {
            $s = get_option(WPS_IC_SETTINGS, []);
            $s = is_array($s) ? $s : [];
            $map = ['avif' => 'auto', 'webp' => 'webp', 'off' => 'off'];
            if (isset($map[$changed['formats'][1]]) && class_exists('WPC_Delivery_Resolver') && defined('WPC_Delivery_Resolver::NEXTGEN_OPTION')) {
                $s[WPC_Delivery_Resolver::NEXTGEN_OPTION] = $map[$changed['formats'][1]];
                update_option(WPS_IC_SETTINGS, $s);
            }
        }
        if (isset($changed['serve']) || isset($changed['scope'])) {
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeAllCache')) {
                try {
                    wps_ic_cache::purgeAllCache();
                } catch (\Throwable $e) {
                }
            }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('policy-changed', '', '', ['changed' => $changed, 'version' => wpc_policy23_version()]);
        }
        do_action('wpc_policy23_changed', $changed);
    }
}

if (!function_exists('wpc_policy23_sign_query')) {
    function wpc_policy23_sign_query($apikey, $query)
    {
        return [
            'x-wpc-sig'       => hash_hmac('sha256', (string) $query, (string) $apikey),
            'x-wpc-timestamp' => (string) time(),
        ];
    }
}

if (!function_exists('wpc_v2_variants_list23')) {
    function wpc_v2_variants_list23($cursor = '', $limit = 200)
    {
        $orch   = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($orch === '' || $apikey === '') {
            return ['ok' => false, 'reason' => 'not_connected'];
        }
        $query = 'apikey=' . rawurlencode($apikey) . '&cursor=' . rawurlencode((string) $cursor) . '&limit=' . (int) $limit;
        $resp = wp_remote_get(rtrim($orch, '/') . '/optimize-v2/variants/list?' . $query, [
            'timeout' => 15,
            'headers' => wpc_policy23_sign_query($apikey, $query),
        ]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'reason' => 'transport', 'detail' => $resp->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['ok'])) {
            return ['ok' => false, 'reason' => 'http_' . $code, 'detail' => is_array($body) && isset($body['error']) ? (string) $body['error'] : ''];
        }
        return [
            'ok'          => true,
            'entries'     => isset($body['entries']) && is_array($body['entries']) ? $body['entries'] : [],
            'next_cursor' => isset($body['next_cursor']) ? (string) $body['next_cursor'] : '',
            'total'       => isset($body['total']) ? (int) $body['total'] : 0,
        ];
    }
}

if (!function_exists('wpc_policy23_store_entry_to_manifest')) {
    function wpc_policy23_store_entry_to_manifest($e)
    {
        if (!is_array($e) || empty($e['path']) || empty($e['url'])) {
            return null;
        }
        $path = '/' . ltrim((string) $e['path'], '/');
        $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== 'avif' && $ext !== 'webp') {
            return null;
        }
        $stem = substr($path, 0, -(strlen($ext) + 1));
        $up   = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        $base_path = !empty($up['baseurl']) ? (string) wp_parse_url($up['baseurl'], PHP_URL_PATH) : '/wp-content/uploads';
        $orig_ext = 'jpg';
        if (!empty($up['basedir']) && strpos($stem, rtrim($base_path, '/') . '/') === 0) {
            $rel = substr($stem, strlen(rtrim($base_path, '/')));
            $src_stem = preg_replace('/-\d{2,4}x\d{2,4}$/', '', $rel);
            foreach (['jpg', 'jpeg', 'png'] as $try) {
                if (@is_file(rtrim($up['basedir'], '/') . $src_stem . '.' . $try)) {
                    $orig_ext = $try;
                    break;
                }
            }
        }
        $home = function_exists('site_url') ? rtrim(site_url(), '/') : '';
        return [
            'origin_url' => $home . $stem . '.' . $orig_ext,
            'filename'   => basename($path),
            'sizeLabel'  => '',
            'format'     => $ext,
            'fetchUrl'   => (string) $e['url'],
            'sha256'     => isset($e['sha256']) ? (string) $e['sha256'] : '',
            'bytes'      => isset($e['bytes']) ? (int) $e['bytes'] : 0,
            'lazy_cdn'   => 1,
        ];
    }
}

if (!function_exists('wpc_policy23_materialize')) {
    function wpc_policy23_materialize($cursor = '')
    {
        if (!apply_filters('wpc_policy23_materialize_on', true)) {
            return 0;
        }
        if (function_exists('update_site_option')) {
            update_site_option('wpc_v2_pull_enabled', 1);
        }
        $landed = 0;
        $list = wpc_v2_variants_list23($cursor, 200);
        if (!empty($list['ok'])) {
            foreach ($list['entries'] as $e) {
                $m = wpc_policy23_store_entry_to_manifest($e);
                if ($m === null) {
                    continue;
                }
                if (function_exists('wpc_v2_lazy_cdn_ingest') && wpc_v2_lazy_cdn_ingest($m)) {
                    $landed++;
                }
            }
            if ($list['next_cursor'] !== '') {
                if (!(function_exists('wpc_pl_sched') && wpc_pl_sched(time() + 5, 'wpc_policy23_materialize', [$list['next_cursor']]))
                    && function_exists('wp_schedule_single_event')) {
                    wp_schedule_single_event(time() + 5, 'wpc_policy23_materialize', [$list['next_cursor']]);
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('policy-materialize', '', '', ['source' => 'store', 'landed' => $landed, 'entries' => count($list['entries']), 'next' => $list['next_cursor'] !== '' ? 1 : 0]);
            }
            return $landed;
        }
        if (function_exists('wpc_v2_pull_set_cursor')) {
            wpc_v2_pull_set_cursor(0);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('policy-materialize', '', '', ['source' => 'manifest', 'reason' => isset($list['reason']) ? $list['reason'] : '']);
        }
        if (function_exists('wpc_pl_sched')) {
            wpc_pl_sched(time() + 5, 'wpc_v2_pull_cron');
        } elseif (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 5, 'wpc_v2_pull_cron');
        }
        return $landed;
    }
    add_action('wpc_policy23_materialize', 'wpc_policy23_materialize', 10, 1);
}
if (!function_exists('wpc_policy23_tighten')) {
    function wpc_policy23_tighten($key, $value)
    {
        $local = wpc_policy23_local();
        if ($key === 'serve') {
            if ($value === 'origin') {
                $local['serve'] = 'origin';
            } else {
                unset($local['serve']);
            }
        } elseif ($key === 'land') {
            if ($value === 'on') {
                $local['land'] = 'on';
            } else {
                unset($local['land']);
            }
        } else {
            return false;
        }
        $before = wpc_policy23();
        update_option('wpc_policy23_local', $local, false);
        $after = $before;
        $after[$key] = $value;
        $stored = wpc_policy23_stored();
        if ($key === 'serve' && $value !== 'origin') {
            $after['serve'] = is_array($stored) && isset($stored['serve']) ? $stored['serve'] : wpc_policy23_defaults()['serve'];
        }
        if ($key === 'land' && $value !== 'on') {
            $after['land'] = is_array($stored) && isset($stored['land']) ? $stored['land'] : 'on';
        }
        if ($after[$key] !== $before[$key]) {
            wpc_policy23_transition([$key => [$before[$key], $after[$key]]]);
        }
        return true;
    }
}

if (!function_exists('wpc_policy23_resync')) {
    function wpc_policy23_resync()
    {
        if (!function_exists('wpc_v2_get_zone_id') || !function_exists('wpc_v2_config_sync_lazy_enabled')) {
            return false;
        }
        $zone = (string) wpc_v2_get_zone_id();
        if ($zone === '') {
            return false;
        }
        $enabled = function_exists('wpc_v2_get_lazy_enabled') ? (bool) wpc_v2_get_lazy_enabled() : false;
        wpc_v2_config_sync_lazy_enabled($zone, $enabled);
        return true;
    }
    add_action('wpc_policy23_resync', 'wpc_policy23_resync');
}

if (!function_exists('wpc_policy23_schedule_resync')) {
    function wpc_policy23_schedule_resync($delay = 5)
    {
        if (function_exists('wp_next_scheduled') && wp_next_scheduled('wpc_policy23_resync')) {
            return false;
        }
        if (function_exists('wpc_pl_sched') && wpc_pl_sched(time() + (int) $delay, 'wpc_policy23_resync')) {
            return true;
        }
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + (int) $delay, 'wpc_policy23_resync');
            return true;
        }
        return false;
    }
}

if (!function_exists('wpc_v2_attachment_paths23')) {
    function wpc_v2_attachment_paths23($imageID)
    {
        $imageID = (int) $imageID;
        $paths = [];
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        $base_path = !empty($up['baseurl']) ? rtrim((string) wp_parse_url($up['baseurl'], PHP_URL_PATH), '/') : '/wp-content/uploads';
        $file = function_exists('get_post_meta') ? (string) get_post_meta($imageID, '_wp_attached_file', true) : '';
        if ($file === '') {
            return $paths;
        }
        $dir = (strpos($file, '/') !== false) ? substr($file, 0, strrpos($file, '/') + 1) : '';
        $paths[] = $base_path . '/' . ltrim($file, '/');
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($imageID) : [];
        if (is_array($meta)) {
            if (!empty($meta['original_image'])) {
                $paths[] = $base_path . '/' . $dir . $meta['original_image'];
            }
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $s) {
                    if (is_array($s) && !empty($s['file'])) {
                        $paths[] = $base_path . '/' . $dir . $s['file'];
                    }
                }
            }
        }
        return array_values(array_unique($paths));
    }
}

if (!function_exists('wpc_v2_attachment_stems22')) {
    function wpc_v2_attachment_stems22($paths)
    {
        $stems = [];
        foreach ((array) $paths as $p) {
            $p = (string) $p;
            if ($p === '' || strpos($p, '.') === false) {
                continue;
            }
            $stem = preg_replace('/(?:-scaled)?(?:-\d+x\d+)?\.[a-z0-9]+$/i', '', $p);
            if (is_string($stem) && $stem !== '' && $stem !== $p) {
                $stems[$stem] = 1;
            }
        }
        return array_keys($stems);
    }
}
if (!function_exists('wpc_v2_variant_forget23')) {
    function wpc_v2_variant_forget23($imageID, $reason = 'delete', $paths = [])
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) {
            return false;
        }
        if (function_exists('get_transient') && get_transient('wpc_forget_done14_' . $imageID) === (string) $reason) {
            return true;
        }
        $paths = is_array($paths) && !empty($paths) ? $paths : wpc_v2_attachment_paths23($imageID);
        $orch   = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $store_ok = null;
        if ($orch !== '' && $apikey !== '' && !empty($paths) && function_exists('wpc_v2_manifest_sign_body')) {
            $body_raw = wp_json_encode(['paths' => array_values($paths), 'stems' => wpc_v2_attachment_stems22($paths), 'image_ids' => [(int) $imageID], 'reason' => (string) $reason]);
            $resp = wp_remote_post(rtrim($orch, '/') . '/optimize-v2/variants/delete?apikey=' . rawurlencode($apikey), [
                'timeout' => 8,
                'headers' => array_merge(['Content-Type' => 'application/json'], wpc_v2_manifest_sign_body($apikey, $body_raw)),
                'body'    => $body_raw,
            ]);
            $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
            $store_ok = ($code >= 200 && $code < 300);
            if ($store_ok && function_exists('set_transient')) {
                set_transient('wpc_forget_done14_' . $imageID, (string) $reason, 10 * MINUTE_IN_SECONDS);
            }
            if (!$store_ok && !get_transient('wpc_forget_retry23_' . $imageID)) {
                set_transient('wpc_forget_retry23_' . $imageID, 1, 15 * MINUTE_IN_SECONDS);
                if (!(function_exists('wpc_pl_sched') && wpc_pl_sched(time() + 300, 'wpc_v2_variant_forget23', [$imageID, (string) $reason, $paths]))
                    && function_exists('wp_schedule_single_event')) {
                    wp_schedule_single_event(time() + 300, 'wpc_v2_variant_forget23', [$imageID, (string) $reason, $paths]);
                }
            }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('variant-forget', (string) $imageID, '', ['reason' => (string) $reason, 'paths' => count($paths), 'store' => $store_ok, 'http' => isset($code) ? (int) $code : null]);
        }
        return (bool) $store_ok;
    }
    add_action('wpc_v2_variant_forget23', 'wpc_v2_variant_forget23', 10, 3);
}
if (!function_exists('wpc_v2_variant_forget23_queue')) {
    function wpc_v2_variant_forget23_queue($imageID, $reason)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) {
            return false;
        }
        if (!apply_filters('wpc_v2_variant_forget23_on', true)) {
            return false;
        }
        if (function_exists('get_transient') && get_transient('wpc_forget_q23_' . $imageID)) {
            return true;
        }
        if (function_exists('set_transient')) {
            set_transient('wpc_forget_q23_' . $imageID, (string) $reason, 60);
        }
        $paths = wpc_v2_attachment_paths23($imageID);
        wpc_v2_variant_forget14_detach($imageID, (string) $reason, $paths);
        if (function_exists('wpc_pl_sched') && wpc_pl_sched(time() + 5, 'wpc_v2_variant_forget23', [$imageID, (string) $reason, $paths])) {
            return true;
        }
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 5, 'wpc_v2_variant_forget23', [$imageID, (string) $reason, $paths]);
            return true;
        }
        return false;
    }
    function wpc_v2_variant_forget14_detach($imageID, $reason, $paths)
    {
        if (!function_exists('add_action') || empty($paths)) {
            return false;
        }
        add_action('shutdown', function () use ($imageID, $reason, $paths) {
            if (function_exists('wpc_finish_request39') && empty($GLOBALS['wpc_released39'])) {
                wpc_finish_request39();
            }
            @ignore_user_abort(true);
            wpc_v2_variant_forget23($imageID, $reason, $paths);
        }, 99);
        return true;
    }
    add_action('delete_attachment', function ($id) { wpc_v2_variant_forget23_queue($id, 'delete'); }, 5);
    add_action('wpc_image_restored', function ($id) { wpc_v2_variant_forget23_queue($id, 'restore'); }, 5);
    function wpc_v2_variant_forget23_meta($meta_id, $object_id, $meta_key, $meta_value)
    {
        if ($meta_key === 'ic_status' && $meta_value === 'restored') {
            wpc_v2_variant_forget23_queue((int) $object_id, 'restore');
        }
    }
    add_action('updated_post_meta', 'wpc_v2_variant_forget23_meta', 10, 4);
    add_action('added_post_meta', 'wpc_v2_variant_forget23_meta', 10, 4);
}

if (!function_exists('wpc_policy23_ui')) {
    function wpc_policy23_ui()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_wpc_settings')) {
            return;
        }
        $p = wpc_policy23();
        $stored = wpc_policy23_stored();
        $orch_serve = is_array($stored) && isset($stored['serve']) ? $stored['serve'] : wpc_policy23_defaults()['serve'];
        $orch_land  = is_array($stored) && isset($stored['land']) ? $stored['land'] : 'on';
        $modes = [
            'edge_land' => ['label' => __('CDN edge + copy on this server', WPS_IC_TEXTDOMAIN), 'chip' => 'Default', 'chipClass' => 'wpc-chip--success', 'tip' => __('Visitors get images from the CDN edge. Every optimized image is also kept on this server.', WPS_IC_TEXTDOMAIN), 'serve' => 'edge', 'land' => 'on', 'allowed' => ($orch_serve === 'edge')],
            'edge_only' => ['label' => __('CDN edge only', WPS_IC_TEXTDOMAIN), 'chip' => 'No disk', 'chipClass' => 'wpc-chip--purple', 'tip' => __('Optimized images live on the CDN only. Nothing new is written to this server.', WPS_IC_TEXTDOMAIN), 'serve' => 'edge', 'land' => 'off', 'allowed' => ($orch_serve === 'edge' && $orch_land === 'off')],
            'origin'    => ['label' => __('This server only', WPS_IC_TEXTDOMAIN), 'chip' => 'No CDN', 'chipClass' => 'wpc-chip--info', 'tip' => __('Visitors never contact the CDN. Optimized images are created by the service and served from this server.', WPS_IC_TEXTDOMAIN), 'serve' => 'origin', 'land' => 'on', 'allowed' => true],
        ];
        $current = $p['serve'] === 'origin' ? 'origin' : ($p['land'] === 'off' ? 'edge_only' : 'edge_land');
        $nonce = wp_create_nonce('wpc_policy23');
        $meta = sprintf(__('Plan setting: %s, %s. Version %d.', WPS_IC_TEXTDOMAIN), $orch_serve === 'edge' ? 'CDN edge' : 'this server', $orch_land === 'on' ? 'copy on disk' : 'no copy on disk', wpc_policy23_version());
        ?>
        <div class="wpc-box-for-checkbox wpc-policy23" data-nonce="<?php echo esc_attr($nonce); ?>">
            <div class="wpc-box-content">
                <div class="wpc-checkbox-title-holder">
                    <div class="circle-check active"></div>
                    <h4><?php esc_html_e('Delivery', WPS_IC_TEXTDOMAIN); ?></h4>
                </div>
                <p><?php echo esc_html(__('Where Smart Delivery serves images from and whether a copy stays on this server. Your plan sets the widest option; you can always choose a stricter one.', WPS_IC_TEXTDOMAIN) . ' ' . $meta); ?></p>
            </div>
            <input type="hidden" id="wpcPolicy23Mode" value="<?php echo esc_attr($current); ?>">
            <div class="wpc-custom-dropdown" data-target="wpcPolicy23Mode">
                <button type="button" class="wpc-custom-dropdown-trigger">
                    <span class="wpc-custom-dropdown-label"><?php echo esc_html($modes[$current]['label']); ?></span>
                    <svg width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div class="wpc-custom-dropdown-menu">
                    <?php foreach ($modes as $val => $m) : ?>
                        <?php if (!$m['allowed'] && $current !== $val) continue; ?>
                        <div class="wpc-custom-dropdown-item<?php echo $current === $val ? ' wpc-active' : ''; ?>" data-value="<?php echo esc_attr($val); ?>" data-serve="<?php echo esc_attr($m['serve']); ?>" data-land="<?php echo esc_attr($m['land']); ?>">
                            <span class="wpc-dropdown-check"><?php echo $current === $val ? '&#10003;' : ''; ?></span>
                            <span class="wpc-dropdown-item-content">
                                <span class="wpc-dropdown-item-label">
                                    <?php echo esc_html($m['label']); ?>
                                    <span class="wpc-dropdown-badge <?php echo esc_attr($m['chipClass']); ?>"><?php echo esc_html($m['chip']); ?></span>
                                </span>
                                <span class="wpc-dropdown-tooltip"><?php echo esc_html($m['tip']); ?></span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var box = document.querySelector('.wpc-policy23'); if (!box) return;
            var hidden = document.getElementById('wpcPolicy23Mode'); if (!hidden) return;
            function send(key, value){
                var fd = new FormData(); fd.append('action','wpc_policy23_tighten'); fd.append('nonce', box.getAttribute('data-nonce')); fd.append('key', key); fd.append('value', value);
                return fetch(ajaxurl, {method:'POST', credentials:'same-origin', body: fd}).then(function(r){ return r.json(); }).then(function(j){ if (!j || !j.success) { alert('Could not save delivery setting'); } });
            }
            hidden.addEventListener('change', function(){
                var item = box.querySelector('.wpc-custom-dropdown-item[data-value="' + hidden.value + '"]'); if (!item) return;
                send('serve', item.getAttribute('data-serve')).then(function(){ return send('land', item.getAttribute('data-land')); });
            });
        })();
        </script>
        <?php
    }
    add_action('wpc_policy23_ui', 'wpc_policy23_ui');
}

if (!function_exists('wpc_policy23_tighten_ajax')) {
    function wpc_policy23_tighten_ajax()
    {
        if (!current_user_can('manage_wpc_settings') || !isset($_POST['nonce']) || !wp_verify_nonce((string) $_POST['nonce'], 'wpc_policy23')) {
            wp_send_json_error(['error' => 'forbidden'], 403);
        }
        $key = isset($_POST['key']) ? sanitize_key((string) $_POST['key']) : '';
        $value = isset($_POST['value']) ? sanitize_key((string) $_POST['value']) : '';
        $stored = wpc_policy23_stored();
        if ($key === 'serve' && $value === 'edge') {
            $orch_serve = is_array($stored) && isset($stored['serve']) ? $stored['serve'] : wpc_policy23_defaults()['serve'];
            if ($orch_serve !== 'edge') {
                wp_send_json_error(['error' => 'plan_does_not_allow'], 403);
            }
        }
        if ($key === 'land' && $value === 'off') {
            $orch_land = is_array($stored) && isset($stored['land']) ? $stored['land'] : 'on';
            if ($orch_land !== 'off') {
                wp_send_json_error(['error' => 'plan_does_not_allow'], 403);
            }
        }
        $ok = wpc_policy23_tighten($key, $value);
        if ($ok) {
            wp_send_json_success(['policy' => wpc_policy23()]);
        }
        wp_send_json_error(['error' => 'bad_key'], 400);
    }
    add_action('wp_ajax_wpc_policy23_tighten', 'wpc_policy23_tighten_ajax');
}

if (!function_exists('wpc_twin_bytes29')) {
    function wpc_twin_bytes29($delta)
    {
        $c = get_option('wpc_twin_bytes29', ['bytes' => 0, 'since' => time()]);
        $c = is_array($c) ? $c : ['bytes' => 0, 'since' => time()];
        $c['bytes'] = max(0, (int) $c['bytes'] + (int) $delta);
        update_option('wpc_twin_bytes29', $c, false);
        return (int) $c['bytes'];
    }
}
if (!function_exists('wpc_v2_health29')) {
    function wpc_v2_health29()
    {
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        $base = !empty($up['basedir']) ? (string) $up['basedir'] : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '/');
        $free = @disk_free_space($base); $total = @disk_total_space($base);
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
        $twin = get_option('wpc_twin_bytes29', ['bytes' => 0, 'since' => 0]);
        return [
            'plugin_version'  => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
            'mode'            => function_exists('wpc_get_optimization_mode') ? wpc_get_optimization_mode() : '',
            'policy'          => ['version' => function_exists('wpc_policy23_version') ? (int) wpc_policy23_version() : 0, 'serve' => function_exists('wpc_policy23_serve') ? wpc_policy23_serve() : '', 'land' => function_exists('wpc_policy23_land') ? wpc_policy23_land() : ''],
            'last_sync'       => get_option('wpc_v2_sync_last20', null),
            'queue'           => ['depth' => function_exists('wpc_rail_depth') ? (int) wpc_rail_depth() : -1, 'parked' => (int) get_option('wpc_rail_parked28', 0), 'purge_spool' => count((array) get_option('wpc_landed_purge_spool88', []))],
            'pressure'        => ['hot' => function_exists('wpc_under_pressure') ? (int) wpc_under_pressure() : -1, 'load1' => is_array($load) ? round((float) $load[0], 2) : -1, 'cores' => function_exists('wpc_box_cores') ? (int) wpc_box_cores() : -1],
            'disk'            => ['free' => $free === false ? null : (int) $free, 'total' => $total === false ? null : (int) $total, 'twin_bytes' => is_array($twin) ? (int) $twin['bytes'] : 0, 'twin_since' => is_array($twin) ? (int) $twin['since'] : 0],
            'safe_mode'       => function_exists('wpc_safe_mode') ? (int) wpc_safe_mode() : -1,
            'time'            => time(),
        ];
    }
    add_action('rest_api_init', function () {
        register_rest_route('wpc/v2', '/health', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function ($request) {
                $sig = $request->get_header('X-WPC-Sig');
                $v = function_exists('wpc_v2_verify_hmac') ? wpc_v2_verify_hmac($sig, '', 300) : ['ok' => false, 'reason' => 'no_verifier'];
                if (empty($v['ok'])) {
                    return new WP_REST_Response(['error' => 'hmac_fail'], 401);
                }
                return new WP_REST_Response(wpc_v2_health29(), 200);
            },
        ]);
    });
}
if (!function_exists('wpc_selfcheck29')) {
    function wpc_selfcheck29()
    {
        if (!apply_filters('wpc_selfcheck', true) || !function_exists('home_url') || !function_exists('wp_remote_get')) {
            return null;
        }
        $t0 = microtime(true);
        $r = wp_remote_get(add_query_arg(['wpcnc' => 1, 'wpc_selfcheck' => 1], home_url('/')), ['timeout' => 20, 'sslverify' => false, 'headers' => ['User-Agent' => 'WPCompress-SelfCheck/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '')]]);
        $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        $body = is_wp_error($r) ? '' : (string) wp_remote_retrieve_body($r);
        $ok = ($code === 200 && stripos($body, '</html>') !== false && stripos($body, 'Fatal error') === false);
        $fails = $ok ? 0 : (int) get_option('wpc_selfcheck_fails29', 0) + 1;
        update_option('wpc_selfcheck_fails29', $fails, false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($ok ? 'selfcheck-ok' : 'selfcheck-fail', '', '', ['http' => $code, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'fails' => $fails, 'v' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '']);
        }
        if ($fails >= 2 && apply_filters('wpc_selfcheck_standdown', true)) {
            update_option('wpc_safe_mode', 1);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('selfcheck-standdown', '', '', ['fails' => $fails]);
            }
        }
        return $ok;
    }
    function wpc_selfcheck29_arm()
    {
        static $armed = false;
        if ($armed || !function_exists('add_action')) {
            return false;
        }
        $armed = true;
        add_action('shutdown', function () {
            if (function_exists('wpc_finish_request39') && empty($GLOBALS['wpc_released39'])) {
                wpc_finish_request39();
            }
            @ignore_user_abort(true);
            wpc_selfcheck29();
        }, 97);
        return true;
    }
}
