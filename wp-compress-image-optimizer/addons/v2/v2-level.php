<?php


if (!defined('ABSPATH')) {
    exit;
}

// The image-compression quality level: one settings reader for every consumer. The dispatch
// envelope (/optimize-v2 `level`) and the /v2/config sync (`local_quality`) both ask here, so the
// level the admin chose is the level every request carries.

if (!function_exists('wpc_v2_level_for_setting')) {
    /**
     * The orchestrator level a stored `optimization` setting names, or null when it names none.
     * The UI stores the name; older saves and the settings grid also store 1/2/3, and older
     * presets `maximum`.
     */
    function wpc_v2_level_for_setting($raw)
    {
        switch (strtolower(trim((string) $raw))) {
            case 'lossless':
            case 'l':
            case '1':
                return 'lossless';
            case 'ultra':
            case 'u':
            case 'maximum':
            case '3':
                return 'ultra';
            case 'intelligent':
            case 'i':
            case '2':
                return 'intelligent';
        }
        return null;
    }
}

if (!function_exists('wpc_v2_normalize_quality')) {
    /** The level for a setting value, `intelligent` when it names none (the /v2/config sync). */
    function wpc_v2_normalize_quality($raw)
    {
        $level = wpc_v2_level_for_setting($raw);
        return $level === null ? 'intelligent' : $level;
    }
}

if (!function_exists('wpc_v2_level')) {
    /**
     * The level every /optimize-v2 request carries: the site's `optimization` setting, or
     * `intelligent` when none is set. A stored value that names no level falls back to
     * `intelligent` with a v2-level-fallback {value} receipt, so the mismatch is visible.
     */
    function wpc_v2_level()
    {
        $settings = get_option(WPS_IC_SETTINGS);
        $raw = (is_array($settings) && isset($settings['optimization']) && is_scalar($settings['optimization']))
            ? (string) $settings['optimization'] : '';
        if ($raw === '') {
            return 'intelligent';
        }
        $level = wpc_v2_level_for_setting($raw);
        if ($level === null) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('v2-level-fallback', 'site', '', ['value' => substr($raw, 0, 32)]);
            }
            return 'intelligent';
        }
        return $level;
    }
}
