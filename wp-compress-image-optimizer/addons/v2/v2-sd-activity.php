<?php

if (!defined('WPC_SD_FEED_OPTION')) {
    define('WPC_SD_FEED_OPTION', 'wpc_sd_feed');
    define('WPC_SD_FEED_CAP', 60);
    define('WPC_SD_FEED_SHOWN', 24);
    define('WPC_SD_FEED_VISIBLE', 12);
    define('WPC_SD_TOTALS_TTL', 60);
    define('WPC_SD_ON_AT_OPTION', 'wpc_sd_on_at');
    define('WPC_SD_PAUSED_AT_OPTION', 'wpc_sd_paused_at');
    define('WPC_RESTORE_ALL_AT_OPTION', 'wpc_restore_all_at');
    define('WPC_RESTORE_ALL_WINDOW', 86400);
}

if (!function_exists('wpc_sd_feed')) {
    function wpc_sd_feed()
    {
        $feed = get_option(WPC_SD_FEED_OPTION, []);
        $feed = is_array($feed) ? $feed : [];
        return [
            'n'    => isset($feed['n']) ? (int) $feed['n'] : 0,
            'ps'   => isset($feed['ps']) ? (float) $feed['ps'] : 0.0,
            'pn'   => isset($feed['pn']) ? (int) $feed['pn'] : 0,
            'rows' => isset($feed['rows']) && is_array($feed['rows']) ? array_values($feed['rows']) : [],
        ];
    }
}

if (!function_exists('wpc_sd_feed_format')) {
    function wpc_sd_feed_format($key, $url)
    {
        if (preg_match('/-(avif|webp|jpe?g|png)$/i', (string) $key, $m)) {
            $f = strtolower($m[1]);
        } else {
            $ext = strtolower((string) pathinfo((string) parse_url((string) $url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $f = in_array($ext, ['avif', 'webp', 'jpg', 'jpeg', 'png'], true) ? $ext : 'jpeg';
        }
        return $f === 'jpg' ? 'jpeg' : $f;
    }
}

if (!function_exists('wpc_sd_feed_width')) {
    function wpc_sd_feed_width($id, $key, $url)
    {
        if (preg_match('/^(\d+)w(?:-[a-z0-9]+)?$/i', (string) $key, $m)) {
            return (int) $m[1];
        }
        if (class_exists('wps_ic_image_variants')) {
            return (int) wps_ic_image_variants::width($id, $key, basename((string) parse_url((string) $url, PHP_URL_PATH)));
        }
        return 0;
    }
}

if (!function_exists('wpc_sd_feed_baseline')) {
    function wpc_sd_feed_baseline($id, $width)
    {
        $none = [0, '', 0];
        $id = (int) $id;
        $width = (int) $width;
        if ($id <= 0 || $width <= 0 || !function_exists('wp_get_attachment_metadata')) {
            return $none;
        }
        $meta = wp_get_attachment_metadata($id);
        $attached = function_exists('get_attached_file') ? (string) get_attached_file($id) : '';
        $url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($id) : '';
        if (!is_array($meta) || $attached === '' || $url === '') {
            return $none;
        }
        $fw = (int) ($meta['width'] ?? 0);
        $fh = (int) ($meta['height'] ?? 0);
        $candidates = [];
        $sizes = isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : [];
        foreach ($sizes as $s) {
            if (!is_array($s) || empty($s['file']) || (int) ($s['width'] ?? 0) < $width) {
                continue;
            }
            $sw = (int) $s['width'];
            $sh = (int) ($s['height'] ?? 0);
            if ($fw > 0 && $fh > 0) {
                $ratio = function_exists('wp_image_matches_ratio') ? wp_image_matches_ratio($sw, $sh, $fw, $fh) : ($sh > 0 && abs($sw / $sh - $fw / $fh) < 0.02);
                if (!$ratio) {
                    continue;
                }
            }
            $candidates[] = [$sw, dirname($attached) . '/' . $s['file'], trailingslashit(dirname($url)) . $s['file'], !empty($s['filesize']) ? (int) $s['filesize'] : 0];
        }
        usort($candidates, function ($x, $y) {
            return $x[0] - $y[0];
        });
        $candidates[] = [$fw > 0 ? $fw : $width, $attached, $url, !empty($meta['filesize']) ? (int) $meta['filesize'] : 0];
        foreach ($candidates as $c) {
            $bytes = $c[3] > 0 ? $c[3] : (int) @filesize($c[1]);
            if ($bytes > 0) {
                return [$bytes, $c[2], (int) $c[0]];
            }
        }
        return $none;
    }
}

if (!function_exists('wpc_sd_fill_baselines')) {
    function wpc_sd_fill_baselines(array $rows)
    {
        foreach ($rows as $k => $row) {
            if ((int) ($row['b'] ?? 0) > 0 || (int) ($row['i'] ?? 0) <= 0 || (int) ($row['w'] ?? 0) <= 0) {
                continue;
            }
            list($b, $ou, $bw) = wpc_sd_feed_baseline((int) $row['i'], (int) $row['w']);
            if ($b > 0) {
                $rows[$k]['b'] = (int) $b;
                $rows[$k]['ou'] = (string) $ou;
                $rows[$k]['bw'] = (int) $bw;
            }
        }
        return $rows;
    }
}

if (!function_exists('wpc_sd_feed_note')) {
    function wpc_sd_feed_note($id, array $landed)
    {
        $id = (int) $id;
        if ($id <= 0 || !$landed || !function_exists('wpc_get_optimization_mode') || wpc_get_optimization_mode() !== 'lazy_cdn') {
            return;
        }
        $built = [];
        $name = '';
        $thumb = '';
        foreach ($landed as $key => $entry) {
            $after = (int) ($entry['size'] ?? 0);
            if ($after <= 0 || !empty($entry['skipped'])) {
                continue;
            }
            $url = (string) ($entry['url'] ?? '');
            $width = wpc_sd_feed_width($id, $key, $url);
            list($before, $before_url, $before_w) = wpc_sd_feed_baseline($id, $width);
            if ($name === '') {
                $file = function_exists('get_attached_file') ? (string) get_attached_file($id) : '';
                $name = $file !== '' ? basename($file) : '';
                $thumb = function_exists('wp_get_attachment_image_url') ? (string) wp_get_attachment_image_url($id, 'thumbnail') : '';
            }
            $built[] = [
                'i'  => $id,
                'w'  => $width,
                'f'  => wpc_sd_feed_format($key, $url),
                'a'  => $after,
                'b'  => $before,
                'u'  => $url,
                'ou' => $before_url,
                'bw' => $before_w,
                'nm' => $name,
                'th' => $thumb,
            ];
        }
        if (!$built) {
            return;
        }
        $locked = function_exists('wpc_worker_lock') ? wpc_worker_lock('wpc_sd_feed', 200) : false;
        try {
            $feed = wpc_sd_feed();
            $now = time();
            foreach ($built as $row) {
                $feed['n']++;
                $row['n'] = $feed['n'];
                $row['t'] = $now;
                if ($row['b'] > 0) {
                    $feed['ps'] += $row['a'] < $row['b'] ? round((1 - $row['a'] / $row['b']) * 100, 1) : 0;
                    $feed['pn']++;
                }
                array_unshift($feed['rows'], $row);
            }
            $feed['rows'] = array_slice($feed['rows'], 0, WPC_SD_FEED_CAP);
            update_option(WPC_SD_FEED_OPTION, $feed, false);
        } finally {
            if ($locked && function_exists('wpc_worker_unlock')) {
                wpc_worker_unlock('wpc_sd_feed');
            }
        }
    }
}

if (!function_exists('wpc_sd_feed_reset')) {
    function wpc_sd_feed_reset()
    {
        delete_option(WPC_SD_FEED_OPTION);
        delete_transient('wpc_sd_totals');
        delete_option('wpc_sd_totals_last');
    }
}

if (!function_exists('wpc_sd_set_time')) {
    function wpc_sd_set_time(array $set)
    {
        $t = 0;
        foreach ($set as $entry) {
            if (!is_array($entry) || !empty($entry['skipped']) || (int) ($entry['size'] ?? 0) <= 0) {
                continue;
            }
            $t = max($t, (int) ($entry['bg_upgraded'] ?? 0), (int) ($entry['time'] ?? 0));
        }
        return $t;
    }
}

if (!function_exists('wpc_sd_backfill_rows')) {
    function wpc_sd_backfill_rows($limit, array $skip = [])
    {
        global $wpdb;
        $limit = (int) $limit;
        if ($limit <= 0 || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_col') || !class_exists('wps_ic_image_variants')) {
            return [];
        }
        $like = '%' . $wpdb->esc_like('s:4:"size";i:') . '%';
        $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY meta_id DESC LIMIT %d", 'ic_local_variants', $like, 150));
        $ids = array_values(array_diff(array_map('intval', (array) $ids), array_map('intval', $skip)));
        if (!$ids) {
            return [];
        }
        if (function_exists('update_meta_cache')) {
            update_meta_cache('post', $ids);
        }
        $order = [];
        foreach ($ids as $id) {
            $order[$id] = wpc_sd_set_time(wps_ic_image_variants::get($id));
        }
        uksort($order, function ($a, $b) use ($order) {
            return $order[$b] <=> $order[$a] ?: $b <=> $a;
        });
        $rows = [];
        foreach (array_keys($order) as $id) {
            foreach (wpc_sd_backfill_variants((int) $id, $order[$id]) as $row) {
                $rows[] = $row;
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }
        return $rows;
    }
}

if (!function_exists('wpc_sd_backfill_variants')) {
    function wpc_sd_backfill_variants($id, $t)
    {
        $pick = wpc_sd_backfill_row($id, $t);
        if ($pick === null) {
            return [];
        }
        $rows = [];
        $found = false;
        foreach (wpc_sd_image_widths($id) as $copy) {
            $w = (int) $copy[0];
            if ($w === (int) $pick['w']) {
                $rows[] = $pick;
                $found = true;
                continue;
            }
            $a = (int) $copy[1];
            list($b, $ou, $bw) = wpc_sd_feed_baseline($id, $w);
            $rows[] = array_merge($pick, [
                'w'  => $w,
                'f'  => (string) $copy[2],
                'a'  => $a,
                'b'  => (int) $b,
                'u'  => isset($copy[3]) ? (string) $copy[3] : '',
                'ou' => (string) $ou,
                'bw' => (int) $bw,
            ]);
        }
        if (!$found) {
            array_unshift($rows, $pick);
        }
        return $rows;
    }
}

if (!function_exists('wpc_sd_backfill_row')) {
    function wpc_sd_backfill_row($id, $t)
    {
        $set = wps_ic_image_variants::get($id);
        $orig = (int) get_post_meta($id, 'ic_savings_baseline', true);
        $saved = (int) get_post_meta($id, 'ic_savings_bytes', true);
        $after = $orig > 0 && $saved > 0 && $saved < $orig ? $orig - $saved : 0;
        $pick = null;
        $pick_key = '';
        foreach ($set as $key => $entry) {
            if (!is_array($entry) || !empty($entry['skipped']) || (int) ($entry['size'] ?? 0) <= 0) {
                continue;
            }
            $size = (int) $entry['size'];
            if ($after > 0 ? $size === $after : ($pick === null || $size > (int) $pick['size'])) {
                $pick = $entry;
                $pick_key = (string) $key;
                if ($after > 0) {
                    break;
                }
            }
        }
        if ($pick === null) {
            return null;
        }
        $url = (string) ($pick['url'] ?? '');
        $width = wpc_sd_feed_width($id, $pick_key, $url);
        if ($after <= 0) {
            $after = (int) $pick['size'];
            $orig = (int) ($pick['originalSize'] ?? 0);
            if ($orig <= $after) {
                $orig = 0;
            }
        }
        list($base_bytes, $base_url, $base_w) = wpc_sd_feed_baseline($id, $width);
        if ($orig <= 0 && $base_bytes > 0) {
            $orig = (int) $base_bytes;
        } elseif ($orig > 0 && $base_url === '' && function_exists('wp_get_attachment_url')) {
            $base_url = (string) wp_get_attachment_url($id);
        }
        $file = function_exists('get_attached_file') ? (string) get_attached_file($id) : '';
        return [
            'i'  => $id,
            'w'  => $width,
            'f'  => wpc_sd_feed_format($pick_key, $url),
            'a'  => $after,
            'b'  => $orig,
            'u'  => $url,
            'ou' => $orig > 0 ? $base_url : '',
            'bw' => $orig > 0 ? (int) $base_w : 0,
            'nm' => $file !== '' ? basename($file) : '',
            'th' => function_exists('wp_get_attachment_image_url') ? (string) wp_get_attachment_image_url($id, 'thumbnail') : '',
            'n'  => 0,
            't'  => (int) $t,
        ];
    }
}

if (!function_exists('wpc_sd_totals')) {
    function wpc_sd_totals()
    {
        $held = get_transient('wpc_sd_totals');
        if (is_array($held)) {
            return $held;
        }
        $last = get_option('wpc_sd_totals_last', null);
        $last = is_array($last) ? $last : ['images' => 0, 'sizes' => 0, 'smaller' => null, 'ready_s' => null];
        if (get_transient('wpc_sd_totals_lock')) {
            $last['stale'] = true;
            return $last;
        }
        set_transient('wpc_sd_totals_lock', 1, 20);
        global $wpdb;
        $images = 0;
        $sizes = 0;
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
            $tok = 's:4:"size";i:';
            $skip = 's:7:"skipped";b:1;';
            $like = '%' . $wpdb->esc_like($tok) . '%';
            $images = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", 'ic_local_variants', $like));
            $made = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT SUM((LENGTH(meta_value) - LENGTH(REPLACE(meta_value, %s, ''))) / %d) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
                $tok, strlen($tok), 'ic_local_variants', $like
            ));
            $skipped = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT SUM((LENGTH(meta_value) - LENGTH(REPLACE(meta_value, %s, ''))) / %d) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
                $skip, strlen($skip), 'ic_local_variants', $like
            ));
            $sizes = max(0, (int) round($made - $skipped));
        }
        $feed = wpc_sd_feed();
        $ps = (float) $feed['ps'];
        $pn = (int) $feed['pn'];
        foreach ($feed['rows'] as $row) {
            if ((int) ($row['b'] ?? 0) > 0) {
                continue;
            }
            $row = wpc_sd_fill_baselines([$row])[0];
            $a = (int) ($row['a'] ?? 0);
            $b = (int) ($row['b'] ?? 0);
            if ($a > 0 && $b > 0) {
                $ps += $a < $b ? round((1 - $a / $b) * 100, 1) : 0;
                $pn++;
            }
        }
        if (count($feed['rows']) < WPC_SD_FEED_SHOWN) {
            $seen = [];
            foreach ($feed['rows'] as $row) {
                $seen[] = (int) ($row['i'] ?? 0);
            }
            foreach (wpc_sd_backfill_rows(WPC_SD_FEED_SHOWN - count($feed['rows']), $seen) as $row) {
                $a = (int) ($row['a'] ?? 0);
                $b = (int) ($row['b'] ?? 0);
                if ($a > 0 && $b > 0) {
                    $ps += $a < $b ? round((1 - $a / $b) * 100, 1) : 0;
                    $pn++;
                }
            }
        }
        $ready = null;
        if (function_exists('wpc_sized_slo_block')) {
            $slo = wpc_sized_slo_block();
            $v = isset($slo['view_to_land_s']) && is_array($slo['view_to_land_s']) ? $slo['view_to_land_s'] : [];
            if (!empty($v['n']) && isset($v['p50']) && $v['p50'] !== null) {
                $ready = max(1, (int) round((float) $v['p50']));
            }
        }
        $out = [
            'images'  => $images,
            'sizes'   => $sizes,
            'smaller' => $pn > 0 ? (int) round($ps / $pn) : null,
            'ready_s' => $ready,
        ];
        set_transient('wpc_sd_totals', $out, WPC_SD_TOTALS_TTL);
        update_option('wpc_sd_totals_last', $out, false);
        delete_transient('wpc_sd_totals_lock');
        return $out;
    }
}

if (!function_exists('wpc_sd_format_name')) {
    function wpc_sd_format_name($f)
    {
        $map = ['avif' => 'AVIF', 'webp' => 'WebP', 'jpeg' => 'JPEG', 'png' => 'PNG'];
        return isset($map[$f]) ? $map[$f] : strtoupper((string) $f);
    }
}

if (!function_exists('wpc_sd_bytes')) {
    function wpc_sd_bytes($b)
    {
        $b = (int) $b;
        if ($b >= 1048576) {
            return number_format($b / 1048576, 1) . ' MB';
        }
        return max(1, (int) round($b / 1024)) . ' KB';
    }
}

if (!function_exists('wpc_sd_ago')) {
    function wpc_sd_ago($t, $now = null)
    {
        if ((int) $t <= 0) {
            return '';
        }
        $d = max(0, ($now === null ? time() : (int) $now) - (int) $t);
        if ($d < 45) {
            return 'just now';
        }
        if ($d < 3600) {
            return max(1, (int) round($d / 60)) . ' min ago';
        }
        if ($d < 86400) {
            return (int) round($d / 3600) . ' h ago';
        }
        return (int) round($d / 86400) . ' d ago';
    }
}

if (!function_exists('wpc_sd_row_pct')) {
    function wpc_sd_row_pct(array $row)
    {
        $a = (int) ($row['a'] ?? 0);
        $b = (int) ($row['b'] ?? 0);
        return ($a > 0 && $b > $a) ? (int) round((1 - $a / $b) * 100) : 0;
    }
}

if (!function_exists('wpc_sd_image_pair')) {
    function wpc_sd_image_pair($id, array $copies, array $feed, $format)
    {
        foreach ($copies as $copy) {
            $w = (int) $copy[0];
            $a = (int) $copy[1];
            $b = 0;
            $ou = '';
            $f = isset($copy[2]) && $copy[2] !== '' ? (string) $copy[2] : (string) $format;
            foreach ($feed as $row) {
                if ((int) ($row['w'] ?? 0) === $w && (int) ($row['b'] ?? 0) > (int) ($row['a'] ?? 0)) {
                    $a = (int) $row['a'];
                    $b = (int) $row['b'];
                    $ou = (string) ($row['ou'] ?? '');
                    $f = (string) ($row['f'] ?? $format);
                    break;
                }
            }
            if ($b <= 0) {
                list($b, $ou) = wpc_sd_feed_baseline($id, $w);
            }
            if ($b > $a) {
                return ['w' => $w, 'a' => $a, 'b' => (int) $b, 'f' => $f, 'ou' => (string) $ou];
            }
        }
        return null;
    }
}

if (!function_exists('wpc_sd_day')) {
    function wpc_sd_day($t, $now = null)
    {
        $t = (int) $t;
        if ($t <= 0) {
            return ['', __('Earlier', WPS_IC_TEXTDOMAIN)];
        }
        $now = $now === null ? time() : (int) $now;
        $key = wp_date('Y-m-d', $t);
        if ($key === wp_date('Y-m-d', $now)) {
            return [$key, __('Today', WPS_IC_TEXTDOMAIN)];
        }
        if ($key === wp_date('Y-m-d', $now - 86400)) {
            return [$key, __('Yesterday', WPS_IC_TEXTDOMAIN)];
        }
        return [$key, wp_date('F j, Y', $t)];
    }
}

if (!function_exists('wpc_sd_sort_rows')) {
    function wpc_sd_sort_rows(array $rows)
    {
        $rows = array_values($rows);
        $at = array_keys($rows);
        usort($at, function ($x, $y) use ($rows) {
            $tx = (int) ($rows[$x]['t'] ?? 0);
            $ty = (int) ($rows[$y]['t'] ?? 0);
            if (($tx > 0) !== ($ty > 0)) {
                return $tx > 0 ? -1 : 1;
            }
            return $ty <=> $tx ?: $x <=> $y;
        });
        $out = [];
        foreach ($at as $i) {
            $out[] = $rows[$i];
        }
        return $out;
    }
}

if (!function_exists('wpc_sd_row_title')) {
    function wpc_sd_row_title(array $row)
    {
        $id = (int) ($row['i'] ?? 0);
        $title = function_exists('get_the_title') ? trim((string) get_the_title($id)) : '';
        return $title !== '' ? $title : (string) ($row['nm'] ?? '');
    }
}

if (!function_exists('wpc_sd_variant_row_html')) {
    function wpc_sd_variant_row_html(array $row, $now = null, $more = false)
    {
        $now = $now === null ? time() : (int) $now;
        $id = (int) ($row['i'] ?? 0);
        $t = (int) ($row['t'] ?? 0);
        $w = (int) ($row['w'] ?? 0);
        $a = (int) ($row['a'] ?? 0);
        $b = (int) ($row['b'] ?? 0);
        $f = (string) ($row['f'] ?? '');
        $day = wpc_sd_day($t, $now);
        $today = $day[0] !== '' && $day[0] === wp_date('Y-m-d', $now);
        $ago = $t <= 0 ? __('earlier', WPS_IC_TEXTDOMAIN) : ($today ? wpc_sd_ago($t, $now) : wp_date('g:i a', $t));
        $thumb = !empty($row['th']) ? esc_url($row['th']) : '';

        $h = '<div class="wpc-bulk-completion-row wpc-sd-row' . ($b <= 0 ? ' is-nb' : '') . ($more ? ' is-more' : '') . '" data-i="' . $id . '" data-n="' . (int) ($row['n'] ?? 0) . '" data-t="' . $t . '" data-day="' . esc_attr($day[0]) . '" data-dl="' . esc_attr($day[1]) . '" data-ti="' . esc_attr(wpc_sd_row_title($row)) . '" data-th="' . $thumb . '">';
        $h .= '<div class="wpc-bulk-cell-image">';
        $h .= '<span class="wpc-bulk-thumb wpc-sd-thumb">' . ($thumb !== '' ? '<img src="' . $thumb . '" alt="" width="40" height="40" loading="lazy">' : '') . '</span>';
        $h .= '<span class="wpc-sd-names"><span class="wpc-bulk-name">' . esc_html(wpc_sd_row_title($row)) . '</span>';
        $h .= '<span class="wpc-sd-sub">';
        if ($w > 0) {
            $h .= '<span class="wpc-sd-w"' . ($b <= 0 ? ' title="' . esc_attr__("Made for a visitor's screen \u{2014} WordPress has no file at this size", WPS_IC_TEXTDOMAIN) . '"' : '') . '>' . esc_html($w . ' px') . '</span>';
        }
        $h .= '<span class="wpc-sd-time"' . ($today ? ' data-wpc-ago' : '') . '>' . esc_html($ago) . '</span>';
        $links = '';
        if (!empty($row['ou'])) {
            $links .= '<a href="' . esc_url($row['ou']) . '" target="_blank" rel="noopener">' . esc_html__('Original', WPS_IC_TEXTDOMAIN) . '</a>';
        }
        if (!empty($row['u'])) {
            $links .= '<a href="' . esc_url($row['u']) . '" target="_blank" rel="noopener">' . esc_html__('Optimized', WPS_IC_TEXTDOMAIN) . '</a>';
        }
        if ($links !== '') {
            $h .= '<span class="wpc-sd-links">' . $links . '</span>';
        }
        $h .= '</span></span></div>';
        $h .= '<div class="wpc-sd-fmt"><span class="wpc-format-badge wpc-fmt-' . esc_attr($f) . '">' . esc_html(wpc_sd_format_name($f)) . '</span></div>';
        $bw = (int) ($row['bw'] ?? 0);
        $h .= '<div class="wpc-bulk-cell-orig"' . ($b > 0 && $bw > 0 ? ' title="' . esc_attr(sprintf(__('WordPress file %s %d px', WPS_IC_TEXTDOMAIN), "\u{00B7}", $bw)) . '"' : '') . '>' . ($b > 0 ? esc_html(wpc_sd_bytes($b)) : '') . '</div>';
        $h .= '<div class="wpc-bulk-cell-opt">' . esc_html(wpc_sd_bytes($a)) . '</div>';
        $h .= '<div class="wpc-bulk-cell-savings">';
        if ($b > 0) {
            $pct = wpc_sd_row_pct($row);
            $h .= '<span class="wpc-savings-pct">' . esc_html($pct . '%') . '</span><div class="wpc-bar-track"><div class="wpc-bar-fill" style="width:' . ($pct > 0 ? max(2, $pct) : 0) . '%"></div></div>';
        }
        $h .= '</div></div>';
        return $h;
    }
}

if (!function_exists('wpc_sd_rows_html')) {
    function wpc_sd_rows_html(array $rows, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $h = '';
        $prev_day = null;
        $today = wp_date('Y-m-d', $now);
        $at = 0;
        foreach ($rows as $row) {
            $day = wpc_sd_day((int) ($row['t'] ?? 0), $now);
            $more = $at >= WPC_SD_FEED_VISIBLE;
            if ($day[0] !== $prev_day) {
                if ($prev_day !== null || $day[0] !== $today) {
                    $h .= '<div class="wpc-sd-day' . ($more ? ' is-more' : '') . '" data-day="' . esc_attr($day[0]) . '">' . esc_html($day[1]) . '</div>';
                }
                $prev_day = $day[0];
            }
            $h .= wpc_sd_variant_row_html($row, $now, $more);
            $at++;
        }
        return $h;
    }
}

if (!function_exists('wpc_sd_row_copies')) {
    function wpc_sd_row_copies($id, array $mine)
    {
        $copies = wpc_sd_image_widths($id);
        if ($copies) {
            return $copies;
        }
        $by = [];
        foreach ($mine as $row) {
            $w = (int) ($row['w'] ?? 0);
            $a = (int) ($row['a'] ?? 0);
            if ($w > 0 && $a > 0 && (!isset($by[$w]) || $a < $by[$w][0])) {
                $by[$w] = [$a, (string) ($row['f'] ?? '')];
            }
        }
        krsort($by);
        foreach ($by as $w => $pick) {
            $copies[] = [(int) $w, (int) $pick[0], $pick[1]];
        }
        return $copies;
    }
}

if (!function_exists('wpc_sd_hero')) {
    function wpc_sd_hero(array $rows)
    {
        if (!$rows) {
            return null;
        }
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) ($row['i'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $mine = [];
            foreach ($rows as $r) {
                if ((int) ($r['i'] ?? 0) === $id) {
                    $mine[] = $r;
                }
            }
            $pair = wpc_sd_image_pair($id, wpc_sd_row_copies($id, $mine), $mine, (string) $row['f']);
            if ($pair !== null) {
                return ['row' => $row, 'pair' => $pair, 'rows' => $mine];
            }
        }
        return ['row' => $rows[0], 'pair' => null, 'rows' => [$rows[0]]];
    }
}

if (!function_exists('wpc_sd_hero_html')) {
    function wpc_sd_hero_html(array $hero)
    {
        $row = $hero['row'];
        $pair = $hero['pair'];
        $lead = $hero['rows'][0];
        $f = $pair !== null && (string) ($pair['f'] ?? '') !== '' ? (string) $pair['f'] : (string) ($lead['f'] ?? '');
        $src = '';
        foreach ([$row['th'] ?? '', $row['u'] ?? '', $row['ou'] ?? ''] as $cand) {
            if ($cand !== '') {
                $src = (string) $cand;
                break;
            }
        }
        $h = '<div class="wpc-sd-hero" data-n="' . (int) ($row['n'] ?? 0) . '">';
        $h .= '<div class="wpc-sd-hero-thumb">' . ($src !== '' ? '<img src="' . esc_url($src) . '" alt="" width="232" height="176" loading="lazy">' : '') . '</div>';
        $h .= '<div class="wpc-sd-hero-copy">';
        $arrow = '<svg class="wpc-sd-svg wpc-sd-bytes-arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
        $chips = '';
        if ($f !== '') {
            $chips .= '<span class="wpc-sd-chip wpc-sd-chip-fmt wpc-fmt-' . esc_attr($f) . '">' . esc_html(wpc_sd_format_name($f)) . '</span>';
        }
        $title = wpc_sd_row_title($row);
        if ($pair !== null) {
            $h .= '<div class="wpc-sd-big"><strong>' . esc_html(sprintf(__('%d%% smaller', WPS_IC_TEXTDOMAIN), (int) wpc_sd_row_pct($pair))) . '</strong><span>' . esc_html(sprintf(__('than the WordPress file %1$s %2$s', WPS_IC_TEXTDOMAIN), "\u{00B7}", $title)) . '</span></div>';
            $chips .= '<span class="wpc-sd-chip">' . esc_html((int) $pair['w'] . ' px') . '</span><span class="wpc-sd-chip wpc-sd-chip-flow"><s>' . esc_html(wpc_sd_bytes($pair['b'])) . '</s>' . $arrow . '<b>' . esc_html(wpc_sd_bytes($pair['a'])) . '</b></span>';
        } else {
            $h .= '<div class="wpc-sd-big"><strong class="wpc-sd-big-word">' . esc_html__('Optimized', WPS_IC_TEXTDOMAIN) . '</strong><span>' . esc_html($title) . '</span></div>';
            $chips .= '<span class="wpc-sd-chip wpc-sd-chip-flow"><b>' . esc_html(wpc_sd_bytes((int) ($lead['a'] ?? 0))) . '</b></span>';
        }
        $h .= '<div class="wpc-sd-chips">' . $chips . '</div></div>';
        return $h . '</div>';
    }
}

if (!function_exists('wpc_sd_skeleton_row_html')) {
    function wpc_sd_skeleton_row_html()
    {
        $bar = function ($cls) {
            return '<span class="wpc-sd-skel-bar ' . $cls . '"></span>';
        };
        return '<div class="wpc-bulk-completion-row wpc-sd-skel-row" data-skel="1" aria-hidden="true">'
            . '<div class="wpc-bulk-cell-image"><span class="wpc-bulk-thumb wpc-sd-thumb"></span><span class="wpc-sd-names">' . $bar('wpc-sd-skel-title') . $bar('wpc-sd-skel-meta') . '</span></div>'
            . '<div class="wpc-sd-fmt">' . $bar('wpc-sd-skel-badge') . '</div>'
            . '<div class="wpc-bulk-cell-orig">' . $bar('wpc-sd-skel-num') . '</div>'
            . '<div class="wpc-bulk-cell-opt">' . $bar('wpc-sd-skel-num') . '</div>'
            . '<div class="wpc-bulk-cell-savings">' . $bar('wpc-sd-skel-pct') . '<div class="wpc-bar-track"></div></div>'
            . '</div>';
    }
}

if (!function_exists('wpc_sd_hero_skeleton_html')) {
    function wpc_sd_hero_skeleton_html()
    {
        $bar = function ($cls) {
            return '<span class="wpc-sd-skel-bar ' . $cls . '"></span>';
        };
        return '<div class="wpc-sd-hero wpc-sd-hero--skel" aria-hidden="true"><div class="wpc-sd-hero-thumb"><span class="wpc-sd-skel-fill"></span></div>'
            . '<div class="wpc-sd-hero-copy"><div class="wpc-sd-big">' . $bar('wpc-sd-skel-headline') . $bar('wpc-sd-skel-sub') . '</div>'
            . '<div class="wpc-sd-chips">' . $bar('wpc-sd-skel-chip') . $bar('wpc-sd-skel-chip') . $bar('wpc-sd-skel-chip wpc-sd-skel-chip--wide') . '</div></div></div>';
    }
}

if (!function_exists('wpc_sd_image_widths')) {
    function wpc_sd_image_widths($id)
    {
        $set = class_exists('wps_ic_image_variants') ? wps_ic_image_variants::get((int) $id) : [];
        $by = [];
        foreach ((array) $set as $key => $entry) {
            if (!is_array($entry) || !empty($entry['skipped']) || (int) ($entry['size'] ?? 0) <= 0) {
                continue;
            }
            $w = wpc_sd_feed_width($id, $key, (string) ($entry['url'] ?? ''));
            if ($w <= 0) {
                continue;
            }
            $b = (int) $entry['size'];
            if (!isset($by[$w]) || $b < $by[$w][0]) {
                $by[$w] = [$b, wpc_sd_feed_format($key, (string) ($entry['url'] ?? '')), (string) ($entry['url'] ?? '')];
            }
        }
        krsort($by);
        $out = [];
        foreach ($by as $w => $pick) {
            $out[] = [(int) $w, (int) $pick[0], $pick[1], $pick[2]];
        }
        return $out;
    }
}

if (!function_exists('wpc_sd_state')) {
    function wpc_sd_state()
    {
        $mode = function_exists('wpc_get_optimization_mode') ? wpc_get_optimization_mode() : 'legacy';
        if ($mode === 'lazy_cdn') {
            return 'smart';
        }
        if ($mode === 'manual' && (int) get_option(WPC_SD_PAUSED_AT_OPTION, 0) > 0) {
            return 'paused';
        }
        return 'other';
    }
}

if (!function_exists('wpc_sd_page_data')) {
    function wpc_sd_page_data()
    {
        $feed = wpc_sd_feed();
        $rows = array_slice($feed['rows'], 0, WPC_SD_FEED_SHOWN);
        $newest = $feed['rows'] ? (int) $feed['rows'][0]['n'] : (int) $feed['n'];
        if (count($rows) < WPC_SD_FEED_SHOWN) {
            $seen = [];
            foreach ($feed['rows'] as $row) {
                $seen[] = (int) ($row['i'] ?? 0);
            }
            $rows = array_merge($rows, wpc_sd_backfill_rows(WPC_SD_FEED_SHOWN - count($rows), $seen));
        }
        $rows = wpc_sd_sort_rows(wpc_sd_fill_baselines($rows));
        return [
            'totals' => wpc_sd_totals(),
            'rows'   => $rows,
            'newest' => $newest,
            'since'  => (int) get_option(WPC_SD_ON_AT_OPTION, 0),
            'hero'   => wpc_sd_hero($rows),
        ];
    }
}

if (!function_exists('wpc_sd_heartbeat_answer')) {
    function wpc_sd_heartbeat_answer($response, $data)
    {
        if (!is_array($data) || !isset($data['wpc_sd_since']) || !function_exists('current_user_can') || !current_user_can('manage_wpc_settings')) {
            return $response;
        }
        if (wpc_sd_state() !== 'smart') {
            return $response;
        }
        $since = (int) $data['wpc_sd_since'];
        $feed = wpc_sd_feed();
        $now = time();
        $shown = wpc_sd_fill_baselines(array_slice($feed['rows'], 0, WPC_SD_FEED_SHOWN));
        $html = [];
        foreach ($shown as $row) {
            if ((int) $row['n'] > $since) {
                $html[] = wpc_sd_variant_row_html($row, $now);
            }
        }
        $hero = $shown && ($html || empty($data['wpc_sd_hero'])) ? wpc_sd_hero($shown) : null;
        $totals = wpc_sd_totals();
        $response['wpc_sd'] = [
            'newest' => $feed['rows'] ? (int) $feed['rows'][0]['n'] : $since,
            'rows'   => array_reverse($html),
            'hero'   => $hero === null ? '' : wpc_sd_hero_html($hero),
            'totals' => [
                'images'  => (int) $totals['images'],
                'sizes'   => (int) $totals['sizes'],
                'smaller' => $totals['smaller'] === null ? null : (int) $totals['smaller'],
                'ready_s' => $totals['ready_s'] === null ? null : (int) $totals['ready_s'],
                'stale'   => !empty($totals['stale']),
            ],
        ];
        return $response;
    }
}

if (function_exists('add_filter')) {
    add_filter('heartbeat_received', 'wpc_sd_heartbeat_answer', 11, 2);
}

if (!function_exists('wpc_sd_mode_of')) {
    function wpc_sd_mode_of($settings)
    {
        return is_array($settings) && !empty($settings['wpc_optimization_mode']) ? (string) $settings['wpc_optimization_mode'] : '';
    }
}

if (!function_exists('wpc_sd_mode_changed')) {
    function wpc_sd_mode_changed($old, $new)
    {
        $was = wpc_sd_mode_of($old);
        $now = wpc_sd_mode_of($new);
        if ($was === $now) {
            return;
        }
        $resuming = (int) get_option(WPC_SD_PAUSED_AT_OPTION, 0) > 0;
        if ($now === 'lazy_cdn' && (!$resuming || (int) get_option(WPC_SD_ON_AT_OPTION, 0) <= 0)) {
            update_option(WPC_SD_ON_AT_OPTION, time(), false);
        }
        if (empty($GLOBALS['wpc_sd_pausing'])) {
            delete_option(WPC_SD_PAUSED_AT_OPTION);
        }
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 5, 'wpc_v2_master_protected_release', [0]);
        }
    }
}

if (!function_exists('wpc_v2_master_protected_release')) {
    function wpc_v2_master_protected_release($after = 0, $limit = 200)
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !class_exists('wps_ic_image_variants')) {
            return 0;
        }
        $limit = max(1, (int) $limit);
        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d AND meta_value LIKE %s ORDER BY post_id ASC LIMIT %d",
            'ic_local_variants',
            (int) $after,
            '%' . $wpdb->esc_like('"master_protected"') . '%',
            $limit
        ));
        $released = 0;
        $last = (int) $after;
        foreach ($ids as $id) {
            $id = (int) $id;
            $last = max($last, $id);
            $keys = [];
            foreach ((array) wps_ic_image_variants::get($id) as $key => $entry) {
                if (is_array($entry) && !empty($entry['bg_no_improvement']) && (string) ($entry['no_improvement_reason'] ?? '') === 'master_protected') {
                    $keys[] = $key;
                }
            }
            if ($keys) {
                $released += wps_ic_image_variants::forget($id, $keys, 'master-protected-release');
            }
        }
        if (count($ids) >= $limit) {
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + 5, 'wpc_v2_master_protected_release', [$last]);
            }
        }
        return $released;
    }
}

if (function_exists('add_action')) {
    add_action('wpc_v2_master_protected_release', 'wpc_v2_master_protected_release', 10, 1);
}

if (function_exists('add_action') && defined('WPS_IC_SETTINGS')) {
    add_action('update_option_' . WPS_IC_SETTINGS, 'wpc_sd_mode_changed', 5, 2);
    add_action('add_option_' . WPS_IC_SETTINGS, function ($option, $value) {
        wpc_sd_mode_changed([], $value);
    }, 5, 2);
}

if (!function_exists('wpc_sd_set_mode')) {
    function wpc_sd_set_mode($mode)
    {
        $settings = get_option(WPS_IC_SETTINGS, []);
        $settings = is_array($settings) ? $settings : [];
        $settings['wpc_optimization_mode'] = (string) $mode;
        update_option(WPS_IC_SETTINGS, $settings);
    }
}

if (!function_exists('wpc_sd_pause')) {
    function wpc_sd_pause()
    {
        $GLOBALS['wpc_sd_pausing'] = true;
        try {
            wpc_sd_set_mode('manual');
        } finally {
            unset($GLOBALS['wpc_sd_pausing']);
        }
        update_option(WPC_SD_PAUSED_AT_OPTION, time(), false);
    }
}

if (!function_exists('wpc_sd_resume')) {
    function wpc_sd_resume()
    {
        wpc_sd_set_mode('lazy_cdn');
        delete_option(WPC_SD_PAUSED_AT_OPTION);
    }
}

if (!function_exists('wpc_sd_admin_post_resume')) {
    function wpc_sd_admin_post_resume()
    {
        if (!current_user_can('manage_wpc_settings')) {
            wp_die('Forbidden.', '', ['response' => 403]);
        }
        check_admin_referer('wpc_sd_resume');
        if (wpc_sd_state() === 'paused') {
            wpc_sd_resume();
        }
        $slug = class_exists('wps_ic') && isset(wps_ic::$slug) ? wps_ic::$slug : 'wpcompress';
        wp_safe_redirect(admin_url('options-general.php?page=' . $slug . '&view=bulk'));
        exit;
    }
}

if (function_exists('add_action')) {
    add_action('admin_post_wpc_sd_resume', 'wpc_sd_admin_post_resume');
}

if (!function_exists('wpc_restore_all_remaining')) {
    function wpc_restore_all_remaining()
    {
        if (defined('WPC_RESTORE_ALL_THROTTLE_OFF') && WPC_RESTORE_ALL_THROTTLE_OFF) {
            return 0;
        }
        if (!apply_filters('wpc_restore_all_throttle', true)) {
            return 0;
        }
        $at = (int) get_option(WPC_RESTORE_ALL_AT_OPTION, 0);
        if ($at <= 0) {
            return 0;
        }
        return max(0, $at + WPC_RESTORE_ALL_WINDOW - time());
    }
}

if (!function_exists('wpc_restore_all_hours')) {
    function wpc_restore_all_hours($seconds)
    {
        $h = max(1, (int) ceil((int) $seconds / 3600));
        return $h . ($h === 1 ? ' hour' : ' hours');
    }
}
