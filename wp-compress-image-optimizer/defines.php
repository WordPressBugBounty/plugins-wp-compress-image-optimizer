<?php
define('WPS_IC_TEXTDOMAIN', 'wp-compress-image-optimizer');

if (!function_exists('wpc_crit_meta_write')) {
    






    function wpc_crit_meta_write($path, $value)
    {
        try {
            $wpc_mt = $path . '.tmp.' . getmypid() . '.' . substr(md5(uniqid('', true)), 0, 6);
            if (@file_put_contents($wpc_mt, (string) $value) === false) {
                
                
                $wpc_md = dirname((string) $path);
                if (!is_dir($wpc_md)) {
                    @mkdir($wpc_md, 0777, true);
                }
                if (@file_put_contents($wpc_mt, (string) $value) === false) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('meta-write-fail', basename($wpc_md), '', ['f' => basename((string) $path)]);
                    }
                    return false;
                }
            }
            if (!@rename($wpc_mt, $path)) {
                @unlink($wpc_mt);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('meta-write-fail', basename(dirname((string) $path)), '', ['f' => basename((string) $path), 'op' => 'rename']);
                }
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_crit_meta_files')) {
    





    function wpc_crit_meta_files()
    {
        return ['url.txt', 'uuid.txt', 'land_uuid.txt', 'dispatch_ts.txt', 'land_ts.txt',
            'stale.txt', 'tpl.txt', 'used_tpl.txt', 'lcp_url.txt', 'lcp_src.txt',
            'delay_url.txt', 'fonts_url.txt', 'used_css_url.txt', 'used_css_mobile_url.txt',
            'used_css_desktop_url.txt', 'used_css_sheets_url.txt', 'crit_combined_url.txt',
            'crit_combined_src.txt', 'font-preload.txt'];
    }
}

define('WPS_IC_MAXWIDTH', 3000);
define('WPS_IC_QUEUE_EXECUTION_TIME', 360);
define('WPS_IC_LOCAL_V', 4);
if (empty($_GET['min_debug'])) {
  define('WPS_IC_MIN', '.min'); 
} else {
  define('WPS_IC_MIN', ''); 
}


$dev = false;
if ($dev) {
    define('WPC_IC_LOCAL_BULK_START', 'https://local-dev.zapwp.net/bulk-start');
    define('WPC_IC_LOCAL_BULK_RUN', 'https://local-dev.zapwp.net/process');
    define('WPC_IC_LOCAL_BULK_STOP', 'https://local-dev.zapwp.net/stop');
    define('WPC_IC_LOCAL_BULK_RESTORE_START', 'https://local-dev.zapwp.net/bulk-restore-start');
    define('WPC_IC_LOCAL_BULK_RESTORE_RUN', 'https://local-dev.zapwp.net/bulk-restore-process');
    define('WPC_IC_LOCAL_OPTIMIZE', 'https://local-dev.zapwp.net/optimize');
    define('WPC_IC_LOCAL_RESTORE', 'https://local-dev.zapwp.net/restore');
    define('WPC_IC_LOCAL_DOWNLOAD', 'https://local-dev.zapwp.net/download');
} else {
    define('WPC_IC_LOCAL_BULK_START', 'https://local-mc.zapwp.net/bulk-start');
    define('WPC_IC_LOCAL_BULK_RUN', 'https://local-mc.zapwp.net/process');
    define('WPC_IC_LOCAL_BULK_STOP', 'https://local-mc.zapwp.net/stop');
    define('WPC_IC_LOCAL_BULK_RESTORE_START', 'https://local-mc.zapwp.net/bulk-restore-start');
    define('WPC_IC_LOCAL_BULK_RESTORE_RUN', 'https://local-mc.zapwp.net/bulk-restore-process');
    define('WPC_IC_LOCAL_OPTIMIZE', 'https://local-mc.zapwp.net/optimize');
    define('WPC_IC_LOCAL_RESTORE', 'https://local-mc.zapwp.net/restore');
    define('WPC_IC_LOCAL_DOWNLOAD', 'https://local-mc.zapwp.net/download');
}


define('WPS_IC_CF', 'wps-ic-cf');
define('WPS_IC_CF_CNAME', 'wps-ic-cf-cname');
define('WPS_IC_GB', 1000000000);
define('WPC_IC_CACHE_EXPIRE', 86400); 
define('WPS_IC_ACCOUNT_STATUS_MEMORY', 60*60); 


define('WPS_IC_FONTS_SCAN', 'https://google-fonts.zapwp.net/scan');
define('WPS_IC_FONTS_DIR', WP_CONTENT_DIR . '/cache/wp-cio-fonts/');
define('WPS_IC_FONTS_URL', WP_CONTENT_URL . '/cache/wp-cio-fonts/');
define('WPS_IC_FONTS_MAP', 'wps_ic_fonts_map');


define('WPS_IC_API_USERAGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36');

define('WPS_IC_APIURL', 'https://legacy-eu.wpcompress.com/');
define('WPS_IC_KEYSURL', 'https://keys.wpmediacompress.com/');


define('WPS_IC_CRITICAL_API_URL', 'https://crit-push.zapwp.net/generate');
define('WPS_IC_CRITICAL_API_URL_HOME', 'https://crit-push.zapwp.net/generate');
define('WPS_IC_PAGESPEED_API_URL_HOME', 'http://pagespeed.zapwp.net/run-pagespeed');
define('WPS_IC_PAGESPEED_RESULTS_HOME', 'http://pagespeed.zapwp.net/get-results/');


if (!defined('WPS_IC_OPTIMIZE_API_URL')) {


    define('WPS_IC_OPTIMIZE_API_URL', 'https://pagespeed.zapwp.net/optimize');
}
if (!defined('WPS_IC_OPTIMIZE_STATUS_API_URL')) {
    define('WPS_IC_OPTIMIZE_STATUS_API_URL', 'https://pagespeed.zapwp.net/optimize-status');
}


define('WPS_IC_PAGESPEED_API_URL', 'http://pagespeed.zapwp.net/run-pagespeed');
define('WPS_IC_PAGESPEED_RESULTS', 'http://pagespeed.zapwp.net/get-results/');
define('WPS_IC_JOB_TRANSIENT', 'wps_ic_job_transient');

define('WPS_IC_CRITICAL_API_ASSETS_URL', 'https://loadbalancer-critical.zapwp.net/assets.php');
define('WPS_IC_PRELOADER_API_URL', 'https://preloader.wpcompress.com/v2/index.php');

define('WPS_IC_IN_BULK', 'wps_ic_in_bulk');
define('WPS_IC_MU_SETTINGS', 'wps_ic_mu_settings');



define('WPS_IC_TEST_FAILURES', 80);


define('WPS_IC_TESTS', 'wpc-tests');
define('WPS_IC_LITE_GPS_HISTORY', 'wps_ic_initial_gps_history');
define('WPS_IC_LITE_GPS', 'wps_ic_initial_gps');
define('WPS_IC_GUI', 'wps_ic_gui');
define('WPS_IC_SETTINGS', 'wps_ic_settings');
if (!defined('WPS_IC_CACHE')) {
	define('WPS_IC_CACHE', WP_CONTENT_DIR . '/cache/wp-cio/');
}

define('WPS_IC_CSS', WP_CONTENT_DIR . '/cache/wp-cio/css');
define('WPS_IC_CSS_URL', WP_CONTENT_URL . '/cache/wp-cio/css');


define('WPS_IC_CACHE_URL', WP_CONTENT_URL . '/cache/wp-cio/');

define('WPS_IC_PRESET', 'wps_ic_preset_setting');
define('WPS_IC_OPTIONS', 'wps_ic');
define('WPS_IC_OPTIONS_V2', 'wps_ic_options');

define('WPS_IC_BULK', 'wps_ic_bulk');

$plugin_dir = str_replace(site_url('/', 'https'), '', WP_PLUGIN_URL);
$plugin_dir = str_replace(site_url('/', 'http'), '', $plugin_dir);

define('WPS_IC_URI', plugin_dir_url(__FILE__));
define('WPS_IC_DIR', realpath(plugin_dir_path(__FILE__)) . '/');
define('WPS_IC_ASSETS', WPS_IC_URI . 'assets');


define('WPC_API_WHITELIST', WPS_IC_DIR . 'whitelist-ip.txt');

define('WPS_IC_IMAGES', $plugin_dir . '/wp-compress-image-optimizer/assets/images');
define('WPS_IC_TEMPLATES', plugin_dir_path(__FILE__) . 'templates/');

define('WPS_IC_UPLOADS_DIR', WP_CONTENT_DIR . '/uploads');

define('WPS_IC_CRITICAL', WP_CONTENT_DIR . '/cache/critical/');
define('WPS_IC_CRITICAL_URL', WP_CONTENT_URL . '/cache/critical/');

define('WPS_IC_COMBINE', WP_CONTENT_DIR . '/cache/combine/');
define('WPS_IC_COMBINE_URL', WP_CONTENT_URL . '/cache/combine/');

define('WPS_IC_LOG', WP_CONTENT_DIR . '/cache/logs/');
define('WPS_IC_LOG_URL', WP_CONTENT_URL . '/cache/logs/');
define('WPC_WARMUP_LOG_SETTING', 'wps_ic_warmup_log');

if (!file_exists(WP_CONTENT_DIR . '/cache')) {
  mkdir(WP_CONTENT_DIR . '/cache');
}

if (!file_exists(rtrim(WPS_IC_CACHE, '/'))) {
  mkdir(rtrim(WPS_IC_CACHE, '/'));
}

if (!file_exists(rtrim(WPS_IC_CRITICAL, '/'))) {
  mkdir(rtrim(WPS_IC_CRITICAL, '/'));
}

if (!file_exists(rtrim(WPS_IC_LOG, '/'))) {
  mkdir(rtrim(WPS_IC_LOG, '/'));
}


define('WPS_IC_STATS_BULK_FILES', 'wps_ic_stats_bulk_files');
define('WPS_IC_STATS_BULK_TOTAL_FILES', 'wps_ic_stats_bulk_total_files');
define('WPS_IC_STATS_BULK_SAVINGS', 'wps_ic_stats_bulk_savings');
define('WPS_IC_STATS_BULK_AVG', 'wps_ic_stats_bulk_avg');
define('WPS_IC_STATS_FILES', 'wps_ic_files_processed');
define('WPS_IC_STATS_BYTES', 'wps_ic_bytes_saved');
define('WPS_IC_STATS_AVG_REDUCTION', 'wps_ic_avg_reduction');


















if (!function_exists('wpc_opcache_refresh')) {
    function wpc_opcache_refresh($ctx = '') {
        if (function_exists('apply_filters') && apply_filters('wpc_opcache_full_reset', false, $ctx)) {
            $wpc_or = function_exists('opcache_reset') ? (int) (bool) @opcache_reset() : 0;
            if (function_exists('wpc_purge_record')) {
                wpc_purge_record('opcache', 'reset_full', 'pool', 1, (bool) $wpc_or, (string) $ctx);
            }
            return $wpc_or;
        }
        if (!function_exists('opcache_invalidate') || !defined('WPS_IC_DIR')) {
            return 0;
        }
        $wpc_n514 = 0;
        $wpc_cap514 = function_exists('apply_filters')
            ? (int) apply_filters('wpc_opcache_invalidate_cap', 1200) : 1200;
        try {
            $wpc_it514 = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(WPS_IC_DIR, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($wpc_it514 as $wpc_f514) {
                if ($wpc_n514 >= $wpc_cap514) {
                    break;
                }
                $wpc_p514 = (string) $wpc_f514;
                if (substr($wpc_p514, -4) !== '.php') {
                    continue;
                }
                @opcache_invalidate($wpc_p514, true);
                $wpc_n514++;
            }
        } catch (\Throwable $e) {
        }
        
        
        if (defined('WP_CONTENT_DIR') && @is_file(WP_CONTENT_DIR . '/advanced-cache.php')) {
            @opcache_invalidate(WP_CONTENT_DIR . '/advanced-cache.php', true);
            $wpc_n514++;
        }
        return $wpc_n514;
    }
}






if (!function_exists('wpc_diag_sleep')) {
    function wpc_diag_sleep($seconds, $ctx = '') {
        $seconds = (float) $seconds;
        if ($seconds <= 0) {
            return 0.0;
        }
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            return 0.0;
        }
        if (function_exists('wpc_safe_mode') && wpc_safe_mode()) {
            return 0.0;
        }
        $wpc_cap514 = function_exists('apply_filters')
            ? (float) apply_filters('wpc_diag_sleep_cap_s', 3.0, $ctx) : 3.0;
        $wpc_bud514 = function_exists('apply_filters')
            ? (float) apply_filters('wpc_diag_sleep_budget_s', 4.0, $ctx) : 4.0;
        if ($wpc_cap514 <= 0 || $wpc_bud514 <= 0) {
            return 0.0;
        }
        $wpc_used514 = isset($GLOBALS['wpc_diag_slept514']) ? (float) $GLOBALS['wpc_diag_slept514'] : 0.0;
        $wpc_left514 = $wpc_bud514 - $wpc_used514;
        if ($wpc_left514 <= 0) {
            return 0.0;
        }
        $wpc_s514 = min($seconds, $wpc_cap514, $wpc_left514);
        if ($wpc_s514 <= 0) {
            return 0.0;
        }
        $GLOBALS['wpc_diag_slept514'] = $wpc_used514 + $wpc_s514;
        usleep((int) round($wpc_s514 * 1000000));
        return $wpc_s514;
    }
}











if (!function_exists('wpc_object_cache_flush')) {
    function wpc_object_cache_flush($ctx = '') {
        if (!function_exists('wp_cache_flush')) { return false; }
        if (function_exists('apply_filters') && !apply_filters('wpc_object_cache_flush_on', true, $ctx)) {
            return false;
        }
        $wpc_cl510 = function_exists('get_transient') ? get_transient('doing_cron') : false;
        $wpc_ok510 = (bool) @wp_cache_flush();
        if ($wpc_cl510 !== false && function_exists('set_transient')) {
            set_transient('doing_cron', $wpc_cl510);
        }
        if (function_exists('wpc_purge_record')) {
            wpc_purge_record('object-cache', 'flush', 'site', 1, $wpc_ok510, (string) $ctx);
        }
        return $wpc_ok510;
    }
}
if (!function_exists('wpc_lock_name')) {
    function wpc_lock_name($logical) {
        global $wpdb;
        $prefix = (isset($wpdb) && is_object($wpdb)) ? $wpdb->prefix : '';
        $db     = defined('DB_NAME') ? DB_NAME : '';
        return 'wl' . substr(md5($db . '|' . $prefix . '|' . (string) $logical), 0, 20);
    }
}
if (!function_exists('wpc_worker_lock')) {
    function wpc_worker_lock($logical, $budget_ms = null) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return false; }
        if ($budget_ms === null) {
            $budget_ms = function_exists('apply_filters') ? (int) apply_filters('wpc_worker_lock_budget_ms', 500) : 500;
        }
        $name = wpc_lock_name($logical);
        $t0 = microtime(true);
        $deadline = $t0 + max(0, (int) $budget_ms) / 1000;
        for (;;) {
            $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $name));
            if ($got === '1' || $got === 1) {
                if (function_exists('wpc_prof_mark')) { wpc_prof_mark('lock:' . $logical, $t0); }
                return true;
            }
            if (microtime(true) >= $deadline) {
                
                if (function_exists('wpc_prof_mark')) { wpc_prof_mark('LOCKFAIL:' . $logical, $t0); }
                return false;
            }
            usleep(30000); 
        }
    }
}
if (!function_exists('wpc_worker_unlock')) {
    function wpc_worker_unlock($logical) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return; }
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", wpc_lock_name($logical)));
    }
}

if (!function_exists('wpc_css_is_icon_font')) {
    





    function wpc_css_is_icon_font($family)
    {
        $f = strtolower(trim((string) $family, " \t\"'"));
        if ($f === '') { return false; }
        return (bool) preg_match(
            '/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple\.?line|etmodules|et-?modules|divi/i',
            $f
        );
    }
}

if (!function_exists('wpc_ua_is_mobile')) {
    
    
    
    
    
    
    function wpc_ua_is_mobile()
    {
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }
        $agent = strtolower((string) $_SERVER['HTTP_USER_AGENT']);
        if (strpos($agent, 'ipad') !== false || strpos($agent, 'tablet') !== false
            || strpos($agent, 'windows phone') !== false || strpos($agent, 'mobile') !== false) {
            return true;
        }
        if (preg_match('#^.*(2.0\ MMP|240x320|400X240|mobile|AvantGo|BlackBerry|Blazer|Cellphone|Danger|DoCoMo|Elaine/3.0|EudoraWeb|Googlebot-Mobile|hiptop|IEMobile|KYOCERA/WX310K|LG/U990|MIDP-2.|MMEF20|MOT-V|NetFront|Newt|Nintendo\ Wii|Nitro|Nokia|Opera\ Mini|Palm|PlayStation\ Portable|portalmmm|Proxinet|ProxiNet|SHARP-TQ-GX10|SHG-i900|Small|SonyEricsson|Symbian\ OS|SymbianOS|TS21i-10|UP.Browser|UP.Link|webOS|Windows\ CE|WinWAP|YahooSeeker/M1A1-R2D2|iPhone|iPod|Android|BlackBerry9530|LG-TU915\ Obigo|LGE\ VX|webOS|Nokia5800).*#i', $agent)
            || preg_match('#^(w3c\ |w3c-|acs-|alav|alca|amoi|audi|avan|benq|bird|blac|blaz|brew|cell|cldc|cmd-|dang|doco|eric|hipt|htc_|inno|ipaq|ipod|jigs|kddi|keji|leno|lg-c|lg-d|lg-g|lge-|lg/u|maui|maxo|midp|mits|mmef|mobi|mot-|moto|mwbp|nec-|newt|noki|palm|pana|pant|phil|play|port|prox|qwap|sage|sams|sany|sch-|sec-|send|seri|sgh-|shar|sie-|siem|smal|smar|sony|sph-|symb|t-mo|teli|tim-|tosh|tsm-|upg1|upsi|vk-v|voda|wap-|wapa|wapi|wapp|wapr|webc|winw|winw|xda\ |xda-).*#i', substr($agent, 0, 4))) {
            return true;
        }
        return false;
    }
}









function wpc_kick_dead_mark($key)
{
    $key = (string) $key;
    if ($key === '' || !function_exists('get_option')) {
        return;
    }
    $dead = get_option('wpc_kick_dead');
    $dead = is_array($dead) ? $dead : [];
    $dead[md5($key)] = time();
    if (count($dead) > 64) {
        asort($dead);
        $dead = array_slice($dead, -64, null, true);
    }
    update_option('wpc_kick_dead', $dead, true);
}


function wpc_kick_is_dead($key)
{
    $key = (string) $key;
    if ($key === '' || !function_exists('get_option')) {
        return false;
    }
    $dead = get_option('wpc_kick_dead');
    if (!is_array($dead) || empty($dead[md5($key)])) {
        return false;
    }
    
    return (time() - (int) $dead[md5($key)]) < 21600;
}


function wpc_kick_budget_ok()
{
    if (!function_exists('get_option')) {
        return true;
    }
    $max = (int) apply_filters('wpc_kick_per_minute', 6);
    if ($max <= 0) {
        return false;
    }
    $win = (array) get_option('wpc_kick_win', []);
    $now = time();
    if (empty($win['t']) || ($now - (int) $win['t']) >= 60) {
        $win = ['t' => $now, 'n' => 0];
    }
    if ((int) $win['n'] >= $max) {
        return false;
    }
    $win['n'] = (int) $win['n'] + 1;
    update_option('wpc_kick_win', $win, true);
    return true;
}












function wpc_render_slot_acquire()
{
    if (!function_exists('wpc_worker_lock')) {
        return true; 
    }
    $n = (int) (function_exists('apply_filters') ? apply_filters('wpc_render_slots', 4) : 4);
    if ($n <= 0) {
        return true;
    }
    for ($i = 1; $i <= $n; $i++) {
        if (wpc_worker_lock('rslot' . $i, 0)) {
            $GLOBALS['wpc_rslot530'] = $i;
            
            if (function_exists('register_shutdown_function')) {
                register_shutdown_function(function () use ($i) {
                    if (function_exists('wpc_worker_unlock')) {
                        wpc_worker_unlock('rslot' . $i);
                    }
                });
            }
            return true;
        }
    }
    return false;
}










function wpc_is_low_value_page()
{
    if (function_exists('is_attachment') && is_attachment()) {
        return true;
    }
    if (function_exists('is_search') && is_search()) {
        return true;
    }
    if (function_exists('is_feed') && is_feed()) {
        return true;
    }
    if (function_exists('is_trackback') && is_trackback()) {
        return true;
    }
    if (function_exists('is_robots') && is_robots()) {
        return true;
    }
    if (function_exists('is_favicon') && is_favicon()) {
        return true;
    }
    
    
    
    
    
    
    
    
    if (function_exists('is_404') && function_exists('did_action')
        && did_action('template_redirect') && is_404()
        && (!function_exists('apply_filters') || apply_filters('wpc_low_value_404', true))) {
        return true;
    }
    return (bool) (function_exists('apply_filters') ? apply_filters('wpc_low_value_page', false) : false);
}









function wpc_prof_mark($label, $start)
{
    if (!isset($GLOBALS['wpc_prof530'])) {
        $GLOBALS['wpc_prof530'] = [];
    }
    $ms = (microtime(true) - (float) $start) * 1000;
    if (!isset($GLOBALS['wpc_prof530'][$label])) {
        $GLOBALS['wpc_prof530'][$label] = ['ms' => 0.0, 'n' => 0];
    }
    $GLOBALS['wpc_prof530'][$label]['ms'] += $ms;
    $GLOBALS['wpc_prof530'][$label]['n']++;
    return $ms;
}


function wpc_prof_dump($min_ms = 25)
{
    
    
    if (isset($GLOBALS['wpc_cp533'], $GLOBALS['wpc_cplabel533'])) {
        wpc_prof_mark($GLOBALS['wpc_cplabel533'], $GLOBALS['wpc_cp533']);
        unset($GLOBALS['wpc_cp533'], $GLOBALS['wpc_cplabel533']);
    }
    if (empty($GLOBALS['wpc_prof530']) || !is_array($GLOBALS['wpc_prof530'])) {
        return '';
    }
    $rows = $GLOBALS['wpc_prof530'];
    uasort($rows, function ($a, $b) {
        return ($b['ms'] == $a['ms']) ? 0 : (($b['ms'] < $a['ms']) ? -1 : 1);
    });
    $out = [];
    foreach ($rows as $k => $v) {
        if ($v['ms'] < $min_ms) {
            continue;
        }
        $out[] = $k . ':' . (int) $v['ms'] . '/' . (int) $v['n'];
        if (count($out) >= 12) {
            break;
        }
    }
    return implode(' ', $out);
}








class Wpc_Prof_Span530
{
    private $label;
    private $t0;
    public function __construct($label)
    {
        $this->label = $label;
        $this->t0    = microtime(true);
    }
    public function __destruct()
    {
        if (function_exists('wpc_prof_mark')) {
            wpc_prof_mark($this->label, $this->t0);
        }
    }
}










function wpc_suppress_self_cron()
{
    if (empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
        return false; 
    }
    if (!function_exists('remove_action') || !function_exists('apply_filters')) {
        return false;
    }
    if (!apply_filters('wpc_suppress_self_cron_enabled', true)) {
        return false;
    }
    remove_action('init', 'wp_cron');
    
    add_filter('pre_transient_doing_cron', function ($v) {
        return ($v === false || $v === null) ? microtime(true) : $v;
    }, 9999);
    return true;
}


if (function_exists('add_action')) {
    add_action('plugins_loaded', 'wpc_suppress_self_cron', 0);
}


function wpc_prof_cp($label)
{
    $now = microtime(true);
    if (isset($GLOBALS['wpc_cp533'])) {
        wpc_prof_mark($GLOBALS['wpc_cplabel533'], $GLOBALS['wpc_cp533']);
    }
    $GLOBALS['wpc_cp533']      = $now;
    $GLOBALS['wpc_cplabel533'] = 'rx:' . $label;
}










function wpc_bound_socket_timeout()
{
    if (!function_exists('ini_get') || !function_exists('ini_set')) {
        return;
    }
    $cur = (int) @ini_get('default_socket_timeout');
    $max = (int) apply_filters('wpc_socket_timeout_ceiling', 5);
    if ($cur <= $max || $max <= 0) {
        return;
    }
    @ini_set('default_socket_timeout', (string) $max);
    
    
    
    
    if (function_exists('stream_context_set_default')) {
        @stream_context_set_default([
            'http'  => ['timeout' => $max, 'follow_location' => 0, 'max_redirects' => 0,
                        'ignore_errors' => true],
            'https' => ['timeout' => $max, 'follow_location' => 0, 'max_redirects' => 0,
                        'ignore_errors' => true],
        ]);
    }
    if (function_exists('register_shutdown_function')) {
        register_shutdown_function(function () use ($cur) {
            @ini_set('default_socket_timeout', (string) $cur);
        });
    }
}






wpc_bound_socket_timeout();
if (function_exists('add_action')) {
    add_action('plugins_loaded', 'wpc_bound_socket_timeout', 0);
}

if (!function_exists('wpc_atf_glyphs_read')) {
    















    function wpc_atf_glyphs_read($critDir)
    {
        $dir = rtrim((string) $critDir, '/') . '/';
        foreach (['delay.json', 'lcp.json'] as $wpc_fn) {
            if (!@is_readable($dir . $wpc_fn)) {
                continue;
            }
            $j = json_decode((string) @file_get_contents($dir . $wpc_fn), true);
            if (!is_array($j)) {
                continue;
            }
            if (isset($j['atf_glyphs']) && is_array($j['atf_glyphs']) && !empty($j['atf_glyphs'])) {
                return $j['atf_glyphs'];
            }
            foreach ($j as $wpc_v) {
                if (is_array($wpc_v) && isset($wpc_v['atf_glyphs']) && is_array($wpc_v['atf_glyphs'])
                    && !empty($wpc_v['atf_glyphs'])) {
                    return $wpc_v['atf_glyphs'];
                }
            }
        }
        return [];
    }
}













if (!function_exists('wpc_purge_ledger_file')) {
    function wpc_purge_ledger_file()
    {
        $wpc_dir = defined('WPS_IC_LOG') ? WPS_IC_LOG : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/logs/' : '');
        if ($wpc_dir === '') {
            return '';
        }
        if (!is_dir($wpc_dir)) {
            @mkdir($wpc_dir, 0755, true);
        }
        return is_dir($wpc_dir) && is_writable($wpc_dir) ? rtrim($wpc_dir, '/') . '/purge-ledger.jsonl' : '';
    }
}




if (!function_exists('wpc_purge_ledger_why')) {
    function wpc_purge_ledger_why()
    {
        $wpc_w = ['at' => '', 'by' => '', 'stack' => '', 'hook' => '', 'src' => 'web', 'ref' => ''];
        try {
            if (function_exists('wp_doing_cron') && wp_doing_cron()) {
                $wpc_w['src'] = 'cron';
            } elseif (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
                $wpc_w['src'] = 'ajax';
                $wpc_w['ref'] = isset($_REQUEST['action']) ? substr((string) $_REQUEST['action'], 0, 40) : '';
            } elseif (defined('WP_CLI') && WP_CLI) {
                $wpc_w['src'] = 'cli';
            } elseif (defined('REST_REQUEST') && REST_REQUEST) {
                $wpc_w['src'] = 'rest';
            }
            
            
            if (!empty($GLOBALS['wp_current_filter']) && is_array($GLOBALS['wp_current_filter'])) {
                $wpc_hooks = array_slice($GLOBALS['wp_current_filter'], -3);
                $wpc_w['hook'] = substr(implode('>', $wpc_hooks), 0, 90);
            }
            if ($wpc_w['ref'] === '' && !empty($_SERVER['REQUEST_URI'])) {
                $wpc_w['ref'] = substr((string) $_SERVER['REQUEST_URI'], 0, 60);
            }
            if (!function_exists('debug_backtrace')) {
                return $wpc_w;
            }
            $wpc_bt    = @debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
            $wpc_names = [];
            foreach ((array) $wpc_bt as $wpc_f) {
                $wpc_file = isset($wpc_f['file']) ? (string) $wpc_f['file'] : '';
                $wpc_fn   = isset($wpc_f['function']) ? (string) $wpc_f['function'] : '';
                
                
                
                
                
                
                
                if (stripos($wpc_fn, 'ledger') !== false
                    || $wpc_fn === 'wpc_purge_record'
                    || $wpc_fn === 'wpc_purge_ledger_add') {
                    continue;
                }
                if (!empty($wpc_f['class']) && stripos($wpc_f['class'], 'CloudflareAPI') !== false) {
                    continue;
                }
                if ($wpc_file !== '' && basename($wpc_file) === 'defines.php') {
                    continue;
                }
                $wpc_names[] = (!empty($wpc_f['class']) ? $wpc_f['class'] . '::' : '') . $wpc_fn;
                if ($wpc_w['at'] === '' && $wpc_file !== '') {
                    
                    
                    $wpc_w['at'] = basename($wpc_file) . ':' . (isset($wpc_f['line']) ? (int) $wpc_f['line'] : 0);
                    $wpc_w['by'] = end($wpc_names);
                }
            }
            $wpc_w['stack'] = substr(implode('<', array_slice($wpc_names, 0, 5)), 0, 140);
        } catch (\Throwable $e) {
        }
        return $wpc_w;
    }
}














if (!function_exists('wpc_settings_ledger_file')) {
    function wpc_settings_ledger_file()
    {
        $wpc_dir = defined('WPS_IC_LOG') ? WPS_IC_LOG : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache/logs/' : '');
        if ($wpc_dir === '') {
            return '';
        }
        if (!is_dir($wpc_dir)) {
            @mkdir($wpc_dir, 0755, true);
        }
        return is_dir($wpc_dir) && is_writable($wpc_dir) ? rtrim($wpc_dir, '/') . '/settings-ledger.jsonl' : '';
    }
}

if (!function_exists('wpc_settings_diff594')) {
    
    function wpc_settings_diff594($old, $new)
    {
        $wpc_d = ['changed' => [], 'added' => [], 'gone' => []];
        if (!is_array($old) || !is_array($new)) {
            return $wpc_d;
        }
        $wpc_flat = function ($wpc_v) {
            if (is_scalar($wpc_v) || $wpc_v === null) {
                return (string) $wpc_v;
            }
            return @json_encode($wpc_v);
        };
        foreach ($new as $wpc_k => $wpc_v) {
            if (!array_key_exists($wpc_k, $old)) {
                $wpc_d['added'][] = (string) $wpc_k;
            } elseif ($wpc_flat($old[$wpc_k]) !== $wpc_flat($wpc_v)) {
                $wpc_d['changed'][] = (string) $wpc_k;
            }
        }
        foreach ($old as $wpc_k => $wpc_v) {
            if (!array_key_exists($wpc_k, $new)) {
                $wpc_d['gone'][] = (string) $wpc_k;
            }
        }
        return $wpc_d;
    }
}

if (!function_exists('wpc_settings_ledger_record')) {
    function wpc_settings_ledger_record($new, $old, $option = '')
    {
        try {
            if (!apply_filters('wpc_settings_ledger', true)) {
                return $new;
            }
            $wpc_d = wpc_settings_diff594($old, $new);
            
            if (!$wpc_d['changed'] && !$wpc_d['added'] && !$wpc_d['gone']) {
                return $new;
            }
            $wpc_why = function_exists('wpc_purge_ledger_why')
                ? wpc_purge_ledger_why()
                : ['at' => '', 'by' => '', 'stack' => '', 'hook' => '', 'src' => '', 'ref' => ''];
            $wpc_row = [
                't' => time(),
                'opt' => (string) $option,
                'at' => $wpc_why['at'],
                'by' => $wpc_why['by'],
                'hook' => $wpc_why['hook'],
                'src' => $wpc_why['src'],
                'ref' => $wpc_why['ref'],
                'stack' => $wpc_why['stack'],
                'n_old' => is_array($old) ? count($old) : -1,
                'n_new' => is_array($new) ? count($new) : -1,
                
                'gone' => array_slice($wpc_d['gone'], 0, 40),
                'changed' => array_slice($wpc_d['changed'], 0, 25),
                'added' => array_slice($wpc_d['added'], 0, 25),
                'verb' => $wpc_d['gone'] ? 'REPLACE' : 'set',
            ];
            
            $wpc_watch = (array) apply_filters('wpc_settings_ledger_watch',
                ['emoji-remove', 'force-delay-jquery', 'delay_js', 'critical', 'cache', 'preset', 'mode']);
            foreach ($wpc_watch as $wpc_wk) {
                if (in_array($wpc_wk, $wpc_d['changed'], true) || in_array($wpc_wk, $wpc_d['gone'], true)) {
                    $wpc_ov = (is_array($old) && array_key_exists($wpc_wk, $old)) ? $old[$wpc_wk] : null;
                    $wpc_nv = (is_array($new) && array_key_exists($wpc_wk, $new)) ? $new[$wpc_wk] : null;
                    $wpc_row['w'][$wpc_wk] = substr((string) (is_scalar($wpc_ov) ? $wpc_ov : @json_encode($wpc_ov)), 0, 24)
                        . '=>' . substr((string) (is_scalar($wpc_nv) ? $wpc_nv : @json_encode($wpc_nv)), 0, 24);
                }
            }
            $wpc_f = wpc_settings_ledger_file();
            if ($wpc_f !== '') {
                
                
                if (@filesize($wpc_f) > (int) apply_filters('wpc_settings_ledger_max', 262144)) {
                    @file_put_contents($wpc_f, '');
                }
                @file_put_contents($wpc_f, @json_encode($wpc_row) . "\n", FILE_APPEND | LOCK_EX);
            }
            if ($wpc_d['gone'] && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('settings-wholesale-replace', (string) $wpc_row['at'], '', [
                    'gone' => count($wpc_d['gone']),
                    'by' => $wpc_row['by'],
                    'hook' => $wpc_row['hook'],
                    'src' => $wpc_row['src'],
                ]);
            }
        } catch (\Throwable $e) {
        }
        return $new;
    }
}










if (function_exists('add_filter') && defined('WPS_IC_SETTINGS')) {
    add_filter('pre_update_option_' . WPS_IC_SETTINGS, 'wpc_settings_ledger_record', 10, 3);
    if (function_exists('add_action')) {
        add_action('add_option_' . WPS_IC_SETTINGS, function ($wpc_opt596, $wpc_val596) {
            wpc_settings_ledger_record($wpc_val596, [], (string) $wpc_opt596 . ':add');
        }, 10, 2);
        add_action('delete_option', function ($wpc_opt596) {
            if ((string) $wpc_opt596 !== WPS_IC_SETTINGS) {
                return;
            }
            $wpc_cur596 = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
            wpc_settings_ledger_record([], is_array($wpc_cur596) ? $wpc_cur596 : [],
                (string) $wpc_opt596 . ':delete');
        }, 10, 1);
    }
}






if (!function_exists('wpc_purge_tag_remember')) {
    function wpc_purge_tag_remember($tag, $url)
    {
        if (!isset($GLOBALS['wpc_purge_tagmap'])) {
            $GLOBALS['wpc_purge_tagmap'] = [];
        }
        if (count($GLOBALS['wpc_purge_tagmap']) > 200) {
            $GLOBALS['wpc_purge_tagmap'] = array_slice($GLOBALS['wpc_purge_tagmap'], -100, null, true);
        }
        $GLOBALS['wpc_purge_tagmap'][(string) $tag] = substr((string) $url, 0, 120);
    }
}

if (!function_exists('wpc_purge_tag_resolve')) {
    function wpc_purge_tag_resolve($sample)
    {
        $wpc_s = (string) $sample;
        if ($wpc_s === '' || empty($GLOBALS['wpc_purge_tagmap'])) {
            return $wpc_s;
        }
        $wpc_out = [];
        foreach (explode(' ', $wpc_s) as $wpc_one) {
            $wpc_out[] = isset($GLOBALS['wpc_purge_tagmap'][$wpc_one])
                ? $GLOBALS['wpc_purge_tagmap'][$wpc_one] . ' (' . $wpc_one . ')'
                : $wpc_one;
        }
        return implode(' ', $wpc_out);
    }
}



if (!function_exists('wpc_purge_record')) {
    function wpc_purge_record($surface, $method, $scope = '', $count = 1, $ok = true, $sample = '')
    {
        try {
            $wpc_w   = wpc_purge_ledger_why();
            $wpc_row = [
                't'   => time(),
                'sf'  => (string) $surface,
                'm'   => (string) $method,
                'sc'  => (string) $scope,
                'n'   => (int) $count,
                'ok'  => $ok ? 1 : 0,
                'src' => $wpc_w['src'],
                'at'  => $wpc_w['at'],
                'by'  => substr($wpc_w['by'], 0, 48),
                'hk'  => $wpc_w['hook'],
                'st'  => $wpc_w['stack'],
                'rf'  => $wpc_w['ref'],
                'u'   => substr(wpc_purge_tag_resolve($sample), 0, 200),
                'pid' => (isset($_REQUEST['post']) && is_scalar($_REQUEST['post'])) ? (int) $_REQUEST['post'] : 0,
            ];
            $wpc_f = wpc_purge_ledger_file();
            if ($wpc_f !== '') {
                
                if (@filesize($wpc_f) > 2097152) {
                    @rename($wpc_f, $wpc_f . '.1');
                }
                @file_put_contents($wpc_f, wp_json_encode($wpc_row) . "\n", FILE_APPEND | LOCK_EX);
                return;
            }
            if (!function_exists('get_option')) {
                return;
            }
            $wpc_led = get_option('wpc_purge_ledger', []);
            if (!is_array($wpc_led)) {
                $wpc_led = [];
            }
            $wpc_led[] = $wpc_row;
            update_option('wpc_purge_ledger', array_slice($wpc_led, -300), false);
        } catch (\Throwable $e) {
        }
    }
}


if (!function_exists('wpc_purge_ledger_add')) {
    function wpc_purge_ledger_add($method, $scope, $count, $ok, $sample = '')
    {
        wpc_purge_record('cf', $method, $scope, $count, $ok, $sample);
    }
}

if (!function_exists('wpc_purge_ledger_rows')) {
    function wpc_purge_ledger_rows($limit = 2000)
    {
        $wpc_f = wpc_purge_ledger_file();
        if ($wpc_f === '' || !@is_readable($wpc_f)) {
            $wpc_o = get_option('wpc_purge_ledger', []);
            return is_array($wpc_o) ? $wpc_o : [];
        }
        $wpc_rows = [];
        $wpc_h    = @fopen($wpc_f, 'r');
        if (!$wpc_h) {
            return [];
        }
        while (($wpc_l = fgets($wpc_h)) !== false) {
            $wpc_d = json_decode(trim($wpc_l), true);
            if (is_array($wpc_d)) {
                $wpc_rows[] = $wpc_d;
            }
        }
        fclose($wpc_h);
        return array_slice($wpc_rows, -$limit);
    }
}



if (!function_exists('wpc_purge_ledger_report')) {
    function wpc_purge_ledger_report()
    {
        $wpc_led = wpc_purge_ledger_rows();
        if (empty($wpc_led)) {
            return ['rows' => 0];
        }
        $wpc_now = time();
        $wpc_1h  = 0;
        $wpc_24h = 0;
        $wpc_fail = 0;
        $wpc_meth = $wpc_by = $wpc_src = $wpc_sf = $wpc_hk = $wpc_at = [];
        $wpc_gaps = [];
        $wpc_prev = 0;
        $wpc_secs = [];
        foreach ($wpc_led as $wpc_r) {
            $wpc_t = isset($wpc_r['t']) ? (int) $wpc_r['t'] : 0;
            if ($wpc_now - $wpc_t <= 3600)  { $wpc_1h++; }
            if ($wpc_now - $wpc_t <= 86400) { $wpc_24h++; }
            if (empty($wpc_r['ok'])) { $wpc_fail++; }
            $wpc_bump = function (&$arr, $key) {
                $key = ($key === '' || $key === null) ? '(none)' : $key;
                $arr[$key] = (isset($arr[$key]) ? $arr[$key] : 0) + 1;
            };
            $wpc_bump($wpc_sf,   isset($wpc_r['sf']) ? $wpc_r['sf'] : '?');
            $wpc_bump($wpc_meth, (isset($wpc_r['sf']) ? $wpc_r['sf'] : '?') . ':' . (isset($wpc_r['m']) ? $wpc_r['m'] : '?'));
            $wpc_bump($wpc_by,   isset($wpc_r['by']) ? $wpc_r['by'] : '');
            $wpc_bump($wpc_at,   isset($wpc_r['at']) ? $wpc_r['at'] : '');
            $wpc_bump($wpc_src,  isset($wpc_r['src']) ? $wpc_r['src'] : '?');
            $wpc_bump($wpc_hk,   isset($wpc_r['hk']) ? $wpc_r['hk'] : '');
            $wpc_secs[$wpc_t] = (isset($wpc_secs[$wpc_t]) ? $wpc_secs[$wpc_t] : 0) + 1;
            if ($wpc_prev && $wpc_t > $wpc_prev) {
                $wpc_gaps[] = $wpc_t - $wpc_prev;
            }
            $wpc_prev = $wpc_t;
        }
        $wpc_dupe = 0;
        foreach ($wpc_secs as $wpc_c) {
            if ($wpc_c > 1) { $wpc_dupe += $wpc_c - 1; }
        }
        arsort($wpc_meth); arsort($wpc_by); arsort($wpc_at); arsort($wpc_hk); arsort($wpc_sf);
        sort($wpc_gaps);
        return [
            'rows'         => count($wpc_led),
            'last_1h'      => $wpc_1h,
            'last_24h'     => $wpc_24h,
            'failed'       => $wpc_fail,
            'same_second'  => $wpc_dupe,
            'median_gap_s' => $wpc_gaps ? $wpc_gaps[intdiv(count($wpc_gaps), 2)] : 0,
            'by_surface'   => $wpc_sf,
            'by_method'    => array_slice($wpc_meth, 0, 10, true),
            'by_line'      => array_slice($wpc_at, 0, 10, true),
            'by_caller'    => array_slice($wpc_by, 0, 10, true),
            'by_hook'      => array_slice($wpc_hk, 0, 10, true),
            'by_trigger'   => $wpc_src,
            'oldest'       => isset($wpc_led[0]['t']) ? (int) $wpc_led[0]['t'] : 0,
            'file'         => wpc_purge_ledger_file(),
        ];
    }
}



















if (!function_exists('wpc_cdn_host600')) {
    function wpc_cdn_host600()
    {
        if (!function_exists('get_option')) {
            return '';
        }
        try {
            $wpc_cf600 = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
            $wpc_cfc600 = (string) get_option(defined('WPS_IC_CF_CNAME') ? WPS_IC_CF_CNAME : 'wps-ic-cf-cname');
            $wpc_ok600 = (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok());
            if (is_array($wpc_cf600) && !empty($wpc_cf600['settings']['cdn'])
                && $wpc_cfc600 !== '' && $wpc_ok600) {
                return trim($wpc_cfc600);
            }
            $wpc_cn600 = trim((string) get_option('ic_custom_cname'));
            if ($wpc_cn600 !== '') {
                return $wpc_cn600;
            }
            return trim((string) get_option('ic_cdn_zone_name'));
        } catch (\Throwable $e) {
            return '';
        }
    }
}







if (!function_exists('wpc_url_is_low_value')) {
    function wpc_url_is_low_value($wpc_url)
    {
        $wpc_url = (string) $wpc_url;
        if ($wpc_url === '') {
            return false;
        }
        static $wpc_seen = [];
        if (isset($wpc_seen[$wpc_url])) {
            return $wpc_seen[$wpc_url];
        }
        $wpc_low = false;
        $wpc_path = (string) parse_url($wpc_url, PHP_URL_PATH);
        $wpc_qs   = (string) parse_url($wpc_url, PHP_URL_QUERY);
        if ($wpc_path !== '' && preg_match('#/(?:feed|embed|trackback)/?$#i', $wpc_path)) {
            $wpc_low = true;
        }
        
        
        
        
        
        
        
        
        
        
        
        if (!$wpc_low && $wpc_path !== ''
            && preg_match('/\.(?:pdf|zip|rar|7z|tar|gz|tgz'
                . '|jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?'
                . '|mp4|webm|mov|avi|mkv|mp3|wav|ogg|m4a'
                . '|css|js|mjs|map|woff2?|ttf|otf|eot'
                . '|docx?|xlsx?|pptx?|csv|rtf|psd|ai|eps)$/i', $wpc_path)) {
            $wpc_low = true;
        }
        
        
        
        
        if (!$wpc_low && $wpc_path !== '' && preg_match('#(?:^|/)graphql/?$#i', $wpc_path)) {
            $wpc_low = true;
        }
        if (!$wpc_low && $wpc_path !== ''
            && preg_match('#(?:^|/)wp-(?:admin|json|content|includes)/'
                . '|(?:^|/)(?:wp-login|wp-cron|wp-signup|wp-activate|wp-trackback|wp-comments-post|xmlrpc)\.php$'
                . '|\.(?:xml|txt|json)$#i', $wpc_path)) {
            $wpc_low = true;
        }
        if (!$wpc_low && $wpc_qs !== '') {
            parse_str($wpc_qs, $wpc_q);
            if (isset($wpc_q['s']) || isset($wpc_q['attachment_id']) || !empty($wpc_q['feed'])) {
                $wpc_low = true;
            }
        }
        if (!$wpc_low && function_exists('url_to_postid') && function_exists('get_post_type')) {
            $wpc_pid = (int) url_to_postid($wpc_url);
            if ($wpc_pid > 0 && get_post_type($wpc_pid) === 'attachment') {
                $wpc_low = true;
            }
        }
        $wpc_low = (bool) (function_exists('apply_filters') ? apply_filters('wpc_url_low_value', $wpc_low, $wpc_url) : $wpc_low);
        if (count($wpc_seen) > 500) {
            $wpc_seen = [];
        }
        return $wpc_seen[$wpc_url] = $wpc_low;
    }
}






if (!function_exists('wpc_fonts_htaccess_body')) {
    function wpc_fonts_htaccess_body()
    {
        return "<IfModule mod_headers.c>\n<FilesMatch \"\\.(css|woff2?|ttf)$\">\nHeader set Cache-Control \"public, max-age=31536000, immutable\"\n</FilesMatch>\n</IfModule>\n";
    }

    function wpc_fonts_htaccess_ensure($wpc_dir)
    {
        $wpc_dir = rtrim((string) $wpc_dir, '/');
        if ($wpc_dir === '' || !@is_dir($wpc_dir) || !@is_writable($wpc_dir)) {
            return false;
        }
        $wpc_body = wpc_fonts_htaccess_body();
        
        
        $wpc_key = 'wpc_fonts_ht_' . md5($wpc_dir . '|' . $wpc_body);
        if (function_exists('get_transient') && get_transient($wpc_key)) {
            return false;
        }
        if (function_exists('set_transient')) {
            set_transient($wpc_key, 1, defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400);
        }
        $wpc_file = $wpc_dir . '/.htaccess';
        if (@file_get_contents($wpc_file) === $wpc_body) {
            return false;
        }
        return (bool) @file_put_contents($wpc_file, $wpc_body);
    }
}
















if (!function_exists('wpc_font_carrier_file602')) {
    function wpc_font_carrier_file602()
    {
        
        
        
        
        
        try {
            if (function_exists('wp_upload_dir')) {
                $wpc_ud618 = wp_upload_dir(null, false);
                if (empty($wpc_ud618['error']) && !empty($wpc_ud618['basedir'])) {
                    $wpc_dir618 = rtrim($wpc_ud618['basedir'], '/') . '/wpc-assets/';
                    $wpc_new618 = $wpc_dir618 . 'font-carrier.css';
                    if (!@is_file($wpc_new618) && defined('WPS_IC_CACHE')
                        && @is_file(WPS_IC_CACHE . 'font-carrier.css')) {
                        if (!is_dir($wpc_dir618)) {
                            @mkdir($wpc_dir618, 0755, true);
                        }
                        @copy(WPS_IC_CACHE . 'font-carrier.css', $wpc_new618);
                    }
                    if (is_dir($wpc_dir618)) {
                        return $wpc_new618;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return defined('WPS_IC_CACHE') ? WPS_IC_CACHE . 'font-carrier.css' : '';
    }
}

if (!function_exists('wpc_font_carrier_key602')) {
    function wpc_font_carrier_key602($block)
    {
        $fam = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $block, $m)
            ? strtolower(trim($m[1], " \t\"'")) : '';
        if ($fam === '') { return ''; }
        $w = preg_match('/font-weight\s*:\s*([^;}]+)/i', $block, $m2) ? strtolower(trim($m2[1])) : '400';
        $s = preg_match('/font-style\s*:\s*(italic|oblique)/i', $block) ? 'i' : 'n';
        $r = preg_match('/unicode-range\s*:\s*([^;}]+)/i', $block, $m3) ? strtolower(trim($m3[1])) : '';
        return $fam . '|' . $w . '|' . $s . '|' . substr(md5($r), 0, 8);
    }
}

if (!function_exists('wpc_font_carrier_record602')) {
    function wpc_font_carrier_record602($css)
    {
        try {
            if (!is_string($css) || $css === '' || stripos($css, '@font-face') === false) { return false; }
            $file = wpc_font_carrier_file602();
            if ($file === '') { return false; }
            if (!apply_filters('wpc_font_carrier_record', true)) { return false; }
            if (!preg_match_all('/@font-face\s*\{[^{}]*\}/is', $css, $m)) { return false; }
            $have = @is_readable($file) ? (string) @file_get_contents($file) : '';
            $seen = [];
            if ($have !== '' && preg_match_all('/@font-face\s*\{[^{}]*\}/is', $have, $hm)) {
                foreach ($hm[0] as $hb) {
                    $k = wpc_font_carrier_key602($hb);
                    if ($k !== '') { $seen[$k] = $hb; }
                }
            }
            $added = 0;
            $cap = (int) apply_filters('wpc_font_carrier_cap', 98304);
            foreach ($m[0] as $blk) {
                $k = wpc_font_carrier_key602($blk);
                if ($k === '' || isset($seen[$k])) { continue; }
                $seen[$k] = $blk;
                $added++;
            }
            if ($added === 0) { return false; }
            $out = '';
            foreach ($seen as $blk) {
                if (strlen($out) + strlen($blk) > $cap) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('font-carrier-capped', '', '', ['cap' => $cap]);
                    }
                    break;
                }
                $out .= $blk;
            }
            if ($out === '') { return false; }
            $tmp = $file . '.tmp' . getmypid();
            if (@file_put_contents($tmp, $out, LOCK_EX) === false) { return false; }
            if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('font-carrier-recorded', '', '', ['added' => $added, 'bytes' => strlen($out)]);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_font_carrier_needed602')) {
    function wpc_font_carrier_needed602()
    {
        if (is_admin()
            || (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('is_feed') && is_feed())) {
            return false;
        }
        
        
        
        
        
        
        return (bool) apply_filters('wpc_font_carrier_force', true);
    }
}

if (!function_exists('wpc_font_preload_postpaint_tag')) {
    











    function wpc_font_preload_postpaint_tag($entries, $marker = '')
    {
        if (!apply_filters('wpc_font_preload_postpaint', true)) {
            return '';
        }
        $list = [];
        foreach ((array) $entries as $e) {
            $u = is_array($e) ? (string) ($e[0] ?? '') : (string) $e;
            $t = is_array($e) && !empty($e[1]) ? (string) $e[1] : 'font/woff2';
            if ($u === '' || stripos($u, 'http') !== 0) { continue; }
            $list[$u] = [$u, $t];
        }
        if (!$list) { return ''; }
        $json = wp_json_encode(array_values(array_slice($list, 0, 6)), JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') { return ''; }
        $json = str_replace('</', '<\/', $json);
        
        
        
        
        
        $mk = $marker !== '' ? ' data-wpc-fpm="' . esc_attr($marker) . '"' : '';
        
        
        
        
        
        
        
        $wpc_late716 = false;
        try {
            if (class_exists('wps_ic_js_delay_v3') && function_exists('get_option') && defined('WPS_IC_SETTINGS')
                && is_callable(['wps_ic_js_delay_v3', 'wpc_delay_master_on'])) {
                $wpc_late716 = (bool) wps_ic_js_delay_v3::wpc_delay_master_on(get_option(WPS_IC_SETTINGS));
            }
        } catch (\Throwable $e) {
            $wpc_late716 = false;
        }
        $wpc_g716 = 'var g=function(){window.__wpcFPB=window.__wpcFPB||{};'
            . 'for(var i=0;i<u.length;i++){if(window.__wpcFPB[u[i][0]])continue;window.__wpcFPB[u[i][0]]=1;'
            . 'var l=document.createElement("link");l.rel="preload";l.as="font";l.type=u[i][1];'
            . 'l.crossOrigin="anonymous";l.href=u[i][0];document.head.appendChild(l)}};';
        if ($wpc_late716) {
            $wpc_d716 = max(0, (int) apply_filters('wpc_delay_latecss_timer', 2500));
            return '<script data-nodefer="1" data-wpc-fpb="1"' . $mk . '>(function(){var u=' . $json . ';'
                . $wpc_g716
                . 'var s=function(){setTimeout(g,' . $wpc_d716 . ')};'
                . 'if(document.readyState==="complete"){s()}else{window.addEventListener("load",s,{once:true})}})();</script>';
        }
        
        
        return '<script data-nodefer="1" data-wpc-fpb="1"' . $mk . '>(function(){var u=' . $json . ';'
            . $wpc_g716
            . 'requestAnimationFrame(function(){setTimeout(function(){g()},0)})})();</script>';
    }
}

if (!function_exists('wpc_font_twin_locals802')) {
    











    function wpc_font_twin_locals802($css)
    {
        if (!is_string($css) || $css === '' || stripos($css, 'Fallback') === false) {
            return is_string($css) ? $css : '';
        }
        if (!preg_match_all('/@font-face\s*\{[^{}]*\}/is', $css, $wpc_bl802)) {
            return $css;
        }
        $wpc_seen802 = [];
        foreach ($wpc_bl802[0] as $wpc_b802) {
            if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+?)\s*["\']?\s*;/i', $wpc_b802, $wpc_fm802)) {
                continue;
            }
            $wpc_fam802 = trim($wpc_fm802[1]);
            if (substr(strtolower($wpc_fam802), -9) !== ' fallback') {
                continue;
            }
            if (!preg_match('/src\s*:\s*local\(\s*["\']?([^"\')]+?)["\']?\s*\)/i', $wpc_b802, $wpc_lm802)) {
                continue;
            }
            $wpc_l802 = trim($wpc_lm802[1]);
            if ($wpc_l802 === '' || strcasecmp($wpc_l802, 'Arial') === 0 || strcasecmp($wpc_l802, 'Helvetica') === 0) {
                continue;
            }
            $wpc_k802 = strtolower($wpc_fam802);
            if (!isset($wpc_seen802[$wpc_k802])) {
                $wpc_seen802[$wpc_k802] = [];
            }
            if (!isset($wpc_seen802[$wpc_k802][$wpc_l802])) {
                $wpc_seen802[$wpc_k802][$wpc_l802] = 0;
            }
            $wpc_seen802[$wpc_k802][$wpc_l802]++;
        }
        if (!$wpc_seen802) {
            return $css;
        }
        $wpc_win802 = [];
        foreach ($wpc_seen802 as $wpc_k802 => $wpc_counts802) {
            arsort($wpc_counts802);
            $wpc_names802 = array_keys($wpc_counts802);
            $wpc_win802[$wpc_k802] = $wpc_names802[0];
        }
        
        
        
        
        
        
        $wpc_out802 = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($wpc_m802) use ($wpc_win802) {
            $wpc_blk802 = $wpc_m802[0];
            if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+?)\s*["\']?\s*;/i', $wpc_blk802, $wpc_f802)) {
                return $wpc_blk802;
            }
            $wpc_k802 = strtolower(trim($wpc_f802[1]));
            if (!isset($wpc_win802[$wpc_k802])) {
                return $wpc_blk802;
            }
            if (!preg_match('/src\s*:\s*local\(\s*["\']?([^"\')]+?)["\']?\s*\)/i', $wpc_blk802, $wpc_l802)) {
                return $wpc_blk802;
            }
            return (strcasecmp(trim($wpc_l802[1]), $wpc_win802[$wpc_k802]) === 0) ? $wpc_blk802 : '';
        }, $css);
        return is_string($wpc_out802) ? $wpc_out802 : $css;
    }
}

if (!function_exists('wpc_font_carrier_emit602')) {
    function wpc_font_carrier_emit602()
    {
        static $done = false;
        try {
            if ($done || !wpc_font_carrier_needed602()) { return; }
            $done = true;
            if (function_exists('wpc_font_carrier_seed619')) {
                wpc_font_carrier_seed619();
            }
            $file = wpc_font_carrier_file602();
            if ($file === '' || !@is_readable($file)) { return; }
            $css = (string) @file_get_contents($file);
            if ($css === '' || stripos($css, '@font-face') === false) { return; }
            
            
            
            if (strlen($css) > 16384) {
                $wpc_url619 = preg_replace('/@font-face\s*\{[^{}]*data:[^{}]*\}/is', '', $css);
                if (is_string($wpc_url619) && stripos($wpc_url619, '@font-face') !== false) {
                    $css = $wpc_url619;
                }
            }
            
            
            $css = (string) preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($m) {
                if (stripos($m[0], 'font-display') !== false) { return $m[0]; }
                $icon = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $m[0], $f)
                    && function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($f[1]);
                return preg_replace('/\{/', '{font-display:' . ($icon ? 'block' : 'swap') . ';', $m[0], 1);
            }, $css);
            if ($css === '' || $css === null) { return; }
            if (function_exists('wpc_font_twin_locals802')) {
                $css = wpc_font_twin_locals802($css);
            }
            
            
            
            if (preg_match_all('/url\(\s*["\']?(https?:\/\/[^)"\'\s]+\.woff2[^)"\'\s]*)/i', $css, $wpc_pf620)) {
                $wpc_fpb689 = wpc_font_preload_postpaint_tag(array_slice(array_unique($wpc_pf620[1]), 0, 4));
                if ($wpc_fpb689 !== '') {
                    echo "\n" . $wpc_fpb689;
                }
            }
            if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_face_display_sweep21')) {
                $css = wps_rewriteLogic::wpc_face_display_sweep21($css);
            }
            echo "\n" . '<style id="wpc-font-carrier">' . $css . '</style>' . "\n";
            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_fc602_log')) {
                set_transient('wpc_fc602_log', 1, 3600);
                wpc_cache_first_log('font-carrier-emitted', '', '', ['bytes' => strlen($css)]);
            }
        } catch (\Throwable $e) {
        }
    }
    if (function_exists('add_action')) {
        add_action('wp_head', 'wpc_font_carrier_emit602', 2);
    }
}




















if (!function_exists('wpc_purge_request_allowed')) {
    function wpc_purge_request_allowed($what = '')
    {
        try {
            
            
            $wpc_act604 = isset($_REQUEST['action']) ? strtolower((string) $_REQUEST['action']) : '';
            if ($wpc_act604 === 'heartbeat') {
                return (bool) apply_filters('wpc_purge_allow_heartbeat', false, $what);
            }
            
            
            if ($wpc_act604 !== ''
                && strpos($wpc_act604, 'wpc') !== 0
                && strpos($wpc_act604, 'wps_ic') !== 0
                && strpos($wpc_act604, 'ic_') !== 0) {
                return (bool) apply_filters('wpc_purge_allow_foreign_ajax', false, $what);
            }
            
            
            if (function_exists('did_action') && did_action('template_redirect')) {
                if ((function_exists('is_404') && is_404())
                    || (function_exists('wpc_is_low_value_page') && wpc_is_low_value_page())) {
                    return (bool) apply_filters('wpc_purge_allow_low_value', false, $what);
                }
            }
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('wpc_purge_gate_log604')) {
    function wpc_purge_gate_log604($what)
    {
        if (!function_exists('wpc_cache_first_log')) {
            return;
        }
        $wpc_act = isset($_REQUEST['action']) ? substr((string) $_REQUEST['action'], 0, 40) : '';
        if ($wpc_act === '' && !empty($_SERVER['REQUEST_URI'])) {
            $wpc_act = substr((string) $_SERVER['REQUEST_URI'], 0, 60);
        }
        wpc_cache_first_log('purge-gated', '', '', ['what' => (string) $what, 'rf' => $wpc_act]);
    }
}






if (!function_exists('wpc_prune_pagespeed_logs604')) {
    function wpc_prune_pagespeed_logs604()
    {
        try {
            if (!defined('WPS_IC_LOG')) {
                return 0;
            }
            $wpc_keep = (int) apply_filters('wpc_pagespeed_log_keep_days', 14);
            if ($wpc_keep < 1) {
                return 0;
            }
            $wpc_cut = time() - ($wpc_keep * DAY_IN_SECONDS);
            $wpc_n   = 0;
            $wpc_cap = (int) apply_filters('wpc_pagespeed_log_prune_cap', 200);
            foreach ((array) @glob(rtrim(WPS_IC_LOG, '/') . '/pagespeed-log-*.txt') as $wpc_f) {
                if ($wpc_n >= $wpc_cap) {
                    break;
                }
                $wpc_mt = (int) @filemtime($wpc_f);
                if ($wpc_mt > 0 && $wpc_mt < $wpc_cut && @unlink($wpc_f)) {
                    $wpc_n++;
                }
            }
            if ($wpc_n > 0 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('pagespeed-log-pruned', '', '', ['n' => $wpc_n, 'keep' => $wpc_keep]);
            }
            return $wpc_n;
        } catch (\Throwable $e) {
            return 0;
        }
    }
    if (function_exists('add_action')) {
        add_action('wpc_autopurge_sweep', 'wpc_prune_pagespeed_logs604', 30);
    }
}





















if (!function_exists('wpc_used_css_provenance_ok')) {
    function wpc_used_css_provenance_ok($wpc_dir605, $wpc_cur605)
    {
        try {
            if (!apply_filters('wpc_used_css_provenance_gate', true)) {
                return true;
            }
            $wpc_dir605 = (string) $wpc_dir605;
            $wpc_cur605 = trim((string) $wpc_cur605);
            if ($wpc_dir605 === '' || $wpc_cur605 === '') {
                return true;
            }
            $wpc_f605 = rtrim($wpc_dir605, '/') . '/used_tpl.txt';
            if (!@is_readable($wpc_f605)) {
                return true;
            }
            $wpc_was605 = trim((string) @file_get_contents($wpc_f605));
            if ($wpc_was605 === '' || $wpc_was605 === $wpc_cur605) {
                return true;
            }
            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_ucssprov_log')) {
                set_transient('wpc_ucssprov_log', 1, 600);
                wpc_cache_first_log('used-css-provenance-mismatch', '', '', [
                    'used_tpl' => substr($wpc_was605, 0, 24),
                    'cur_tpl'  => substr($wpc_cur605, 0, 24),
                ]);
            }
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

















if (!function_exists('wpc_body_scope_guard606')) {
    function wpc_body_scope_guard606($wpc_crit606 = '', $wpc_ucss606 = true)
    {
        try {
            if (!apply_filters('wpc_body_scope_guard', true)) {
                return '';
            }
            
            
            
            
            
            
            if (!$wpc_ucss606) {
                return '';
            }
            $wpc_css606 = '';
            $wpc_pos606 = '';
            if (is_string($wpc_crit606) && $wpc_crit606 !== ''
                && preg_match_all('/(?<![\w.#\[-])body\s*\{([^{}]{0,600})\}/i', $wpc_crit606, $wpc_pm606)) {
                foreach ($wpc_pm606[1] as $wpc_pb606) {
                    if (preg_match('/(?<![-\w])position\s*:\s*(static|relative|absolute|fixed|sticky)/i', $wpc_pb606, $wpc_pv606)) {
                        $wpc_pos606 = strtolower($wpc_pv606[1]);
                    }
                }
            }
            
            if (apply_filters('wpc_body_position_guard', true)) {
                $wpc_css606 .= 'body{position:' . ($wpc_pos606 !== '' ? $wpc_pos606 : 'static') . '!important}';
            }
            
            
            $wpc_bg606 = '';
            if (is_string($wpc_crit606) && $wpc_crit606 !== ''
                && preg_match_all('/(?<![\w.#\[-])body\s*\{([^{}]{0,600})\}/i', $wpc_crit606, $wpc_bm606)) {
                foreach ($wpc_bm606[1] as $wpc_blk606) {
                    if (preg_match('/background(?:-color)?\s*:\s*([^;!}]+)/i', $wpc_blk606, $wpc_v606)) {
                        $wpc_cand606 = trim($wpc_v606[1]);
                        if ($wpc_cand606 !== '' && stripos($wpc_cand606, 'url(') === false) {
                            $wpc_bg606 = $wpc_cand606;
                        }
                    }
                }
            }
            $wpc_bg606 = (string) apply_filters('wpc_body_bg_guard', $wpc_bg606, $wpc_crit606);
            if ($wpc_bg606 !== '' && !preg_match('/[<>{}"\']/', $wpc_bg606)) {
                $wpc_css606 .= 'body{background-color:' . $wpc_bg606 . '!important}';
            }
            if ($wpc_css606 === '') {
                return '';
            }
            return "\r\n" . '<style id="wpc-body-guard">' . $wpc_css606 . '</style>';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
















if (!function_exists('wpc_canon_same_host609')) {
    function wpc_canon_same_host609($wpc_a609, $wpc_b609)
    {
        $wpc_ha609 = strtolower((string) parse_url((string) $wpc_a609, PHP_URL_HOST));
        $wpc_hb609 = strtolower((string) parse_url((string) $wpc_b609, PHP_URL_HOST));
        if ($wpc_ha609 === '' || $wpc_hb609 === '') { return false; }
        $wpc_st609 = function ($wpc_h) { return strpos($wpc_h, 'www.') === 0 ? substr($wpc_h, 4) : $wpc_h; };
        return $wpc_st609($wpc_ha609) === $wpc_st609($wpc_hb609);
    }
}

if (!function_exists('wpc_canon_url609')) {
    function wpc_canon_url609($wpc_u609)
    {
        try {
            $wpc_u609 = trim((string) $wpc_u609);
            if ($wpc_u609 === '' || !function_exists('get_option')) { return $wpc_u609; }
            if (!apply_filters('wpc_canonical_loopback', true)) { return $wpc_u609; }
            $wpc_map609 = get_option('wpc_canon_map', []);
            if (is_array($wpc_map609)) {
                $wpc_k609 = md5($wpc_u609);
                if (!empty($wpc_map609[$wpc_k609]['u'])
                    && wpc_canon_same_host609($wpc_u609, $wpc_map609[$wpc_k609]['u'])) {
                    return (string) $wpc_map609[$wpc_k609]['u'];
                }
            }
            $wpc_p609 = (string) parse_url($wpc_u609, PHP_URL_PATH);
            $wpc_q609 = (string) parse_url($wpc_u609, PHP_URL_QUERY);
            
            
            if ($wpc_p609 === '' || $wpc_q609 !== '' || preg_match('/\.[A-Za-z0-9]{1,8}$/', $wpc_p609)) {
                return $wpc_u609;
            }
            if (!function_exists('user_trailingslashit')) { return $wpc_u609; }
            $wpc_n609 = user_trailingslashit($wpc_p609);
            if (!is_string($wpc_n609) || $wpc_n609 === '' || $wpc_n609 === $wpc_p609) { return $wpc_u609; }
            return str_replace($wpc_p609, $wpc_n609, $wpc_u609);
        } catch (\Throwable $e) {
            return (string) $wpc_u609;
        }
    }
}

if (!function_exists('wpc_canon_learn609')) {
    function wpc_canon_learn609($wpc_from609, $wpc_to609)
    {
        try {
            $wpc_from609 = trim((string) $wpc_from609);
            $wpc_to609   = trim((string) $wpc_to609);
            if ($wpc_from609 === '' || $wpc_to609 === '' || $wpc_from609 === $wpc_to609) { return false; }
            if (!function_exists('get_option') || !wpc_canon_same_host609($wpc_from609, $wpc_to609)) { return false; }
            $wpc_map609 = get_option('wpc_canon_map', []);
            if (!is_array($wpc_map609)) { $wpc_map609 = []; }
            $wpc_k609 = md5($wpc_from609);
            if (isset($wpc_map609[$wpc_k609]['u']) && $wpc_map609[$wpc_k609]['u'] === $wpc_to609) { return false; }
            $wpc_now609 = time();
            foreach ($wpc_map609 as $wpc_mk609 => $wpc_mv609) {
                if (!is_array($wpc_mv609) || ($wpc_now609 - (int) ($wpc_mv609['t'] ?? 0)) > 7 * DAY_IN_SECONDS) {
                    unset($wpc_map609[$wpc_mk609]);
                }
            }
            $wpc_cap609 = (int) apply_filters('wpc_canon_map_cap', 200);
            if (count($wpc_map609) >= $wpc_cap609) { $wpc_map609 = array_slice($wpc_map609, -($wpc_cap609 - 1), null, true); }
            $wpc_map609[$wpc_k609] = ['u' => $wpc_to609, 't' => $wpc_now609];
            update_option('wpc_canon_map', $wpc_map609, false);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('canon-url-learned', '', $wpc_from609, ['to' => substr($wpc_to609, 0, 90)]);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_canon_final609')) {
    
    function wpc_canon_final609($wpc_r609)
    {
        try {
            if (!is_array($wpc_r609) || empty($wpc_r609['http_response'])
                || !is_object($wpc_r609['http_response'])
                || !method_exists($wpc_r609['http_response'], 'get_response_object')) {
                return '';
            }
            $wpc_o609 = $wpc_r609['http_response']->get_response_object();
            return (is_object($wpc_o609) && !empty($wpc_o609->url)) ? (string) $wpc_o609->url : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}





























if (!function_exists('wpc_device_blind_decide')) {
    function wpc_device_blind_decide($signals, $breeze, $rocket)
    {
        $signals = is_array($signals) ? $signals : [];
        
        if (!empty($signals['breeze'])) {
            $desk = is_array($breeze) && !empty($breeze['breeze-desktop-cache']) && $breeze['breeze-desktop-cache'] == '1';
            $mob  = is_array($breeze) && !empty($breeze['breeze-mobile-cache']) && $breeze['breeze-mobile-cache'] == '1';
            if ($desk && !$mob) { return true; }
        }
        
        if (!empty($signals['wp-rocket'])) {
            $aware = is_array($rocket) && !empty($rocket['cache_mobile']) && !empty($rocket['do_caching_mobile_files']);
            if (!$aware) { return true; }
        }
        
        
        foreach (['litespeed', 'w3tc', 'wp-super-cache', 'wp-fastest-cache', 'cache-enabler', 'comet-cache'] as $wpc_k) {
            if (!empty($signals[$wpc_k])) { return true; }
        }
        return false;
    }
}
if (!function_exists('wpc_foreign_device_blind_cache')) {
    function wpc_foreign_device_blind_cache()
    {
        if (!function_exists('get_option')) { return false; }
        $signals = [
            'breeze'           => defined('BREEZE_VERSION'),
            'wp-rocket'        => defined('WP_ROCKET_VERSION'),
            'litespeed'        => defined('LSCWP_V'),
            'w3tc'             => defined('W3TC'),
            'wp-super-cache'   => defined('WPCACHEHOME'),
            'wp-fastest-cache' => defined('WPFC_MAIN_PATH'),
            'cache-enabler'    => defined('CACHE_ENABLER_VERSION'),
            'comet-cache'      => defined('COMET_CACHE_PLUGIN_FILE'),
        ];
        $breeze = !empty($signals['breeze']) ? get_option('breeze_basic_settings') : [];
        $rocket = !empty($signals['wp-rocket']) ? get_option('wp_rocket_settings') : [];
        $blind  = wpc_device_blind_decide($signals, is_array($breeze) ? $breeze : [], is_array($rocket) ? $rocket : []);
        return (bool) apply_filters('wpc_foreign_device_blind_cache', $blind);
    }
}
if (!function_exists('wpc_cf_fronted_html')) {
    









    function wpc_cf_fronted_html()
    {
        $wpc_seen801 = (!empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']));
        if ($wpc_seen801) {
            if (function_exists('get_option') && function_exists('update_option')
                && !get_option('wpc_cf_fronted_seen', 0)) {
                update_option('wpc_cf_fronted_seen', 1, false);
            }
            return (bool) apply_filters('wpc_cf_fronted_html', true);
        }
        $wpc_sticky801 = function_exists('get_option') ? !empty(get_option('wpc_cf_fronted_seen', 0)) : false;
        return (bool) apply_filters('wpc_cf_fronted_html', $wpc_sticky801);
    }
}
if (!function_exists('wpc_devblind_edge')) {
    








    function wpc_devblind_edge()
    {
        if (!function_exists('wpc_cf_fronted_html') || !wpc_cf_fronted_html()) {
            return false;
        }
        $wpc_dk801 = function_exists('get_option') ? get_option('wpc_cf_devkey_verified') : false;
        return !is_array($wpc_dk801) || empty($wpc_dk801['devkey'])
            || (isset($wpc_dk801['src']) ? (string) $wpc_dk801['src'] : '') !== 'readback';
    }
}

if (!function_exists('wpc_foreign_purge610')) {
    function wpc_foreign_purge610($wpc_arg610 = false, $wpc_ctx610 = '')
    {
        try {
            if (!function_exists('do_action')) { return false; }
            if (!apply_filters('wpc_foreign_purge_enabled', true, $wpc_ctx610)) { return false; }
            
            
            $wpc_scope610 = ($wpc_arg610 === false || $wpc_arg610 === null || $wpc_arg610 === '')
                ? 'site' : 'url:' . md5((string) $wpc_arg610);
            $wpc_win610 = (int) apply_filters('wpc_foreign_purge_window_s', 120, $wpc_scope610, $wpc_ctx610);
            if ($wpc_win610 > 0 && function_exists('get_transient')) {
                $wpc_k610 = 'wpc_fp610_' . md5($wpc_scope610);
                if (get_transient($wpc_k610)) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('foreign-purge-throttled', '', '', [
                            'scope' => $wpc_scope610, 'ctx' => (string) $wpc_ctx610, 'win' => $wpc_win610,
                        ]);
                    }
                    return false;
                }
                set_transient($wpc_k610, 1, $wpc_win610);
            }
            if (function_exists('wpc_purge_record')) {
                wpc_purge_record('foreign', 'purge_all', $wpc_scope610, 1, true, (string) $wpc_ctx610);
            }
            do_action('wps_ic_purge_all_cache', $wpc_arg610);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}














if (!function_exists('wpc_dispatch_day611')) {
    function wpc_dispatch_day611() { return 'wpc_disp611_' . gmdate('Ymd'); }
}

if (!function_exists('wpc_dispatch_allowed611')) {
    function wpc_dispatch_allowed611($wpc_url611, $wpc_burst611 = false)
    {
        try {
            if (!function_exists('get_option')) { return true; }
            if (!apply_filters('wpc_dispatch_budget', true, $wpc_url611)) { return true; }
            $wpc_url611 = (string) $wpc_url611;
            
            
            $wpc_dead611 = get_option('wpc_disp_dead611', []);
            if (is_array($wpc_dead611) && !empty($wpc_dead611[md5($wpc_url611)])) {
                wpc_dispatch_log611('dispatch-skip-dead', $wpc_url611, []);
                return false;
            }
            $wpc_cur611 = (int) get_transient('wpc_disp_conc611');
            $wpc_max611 = (int) apply_filters('wpc_dispatch_max_concurrent', 2);
            if ($wpc_max611 > 0 && $wpc_cur611 >= $wpc_max611) {
                wpc_dispatch_log611('dispatch-skip-concurrent', $wpc_url611, ['n' => $wpc_cur611]);
                return false;
            }
            $wpc_cap611 = (int) apply_filters('wpc_dispatch_daily_cap', $wpc_burst611 ? 100 : 20, $wpc_burst611);
            $wpc_used611 = (int) get_option(wpc_dispatch_day611(), 0);
            if ($wpc_cap611 > 0 && $wpc_used611 >= $wpc_cap611) {
                wpc_dispatch_log611('dispatch-skip-budget', $wpc_url611, ['used' => $wpc_used611, 'cap' => $wpc_cap611]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('wpc_dispatch_log611')) {
    function wpc_dispatch_log611($wpc_ev611, $wpc_url611, $wpc_extra611 = [])
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log((string) $wpc_ev611, '', (string) $wpc_url611, (array) $wpc_extra611);
        }
    }
}

if (!function_exists('wpc_dispatch_mark611')) {
    
    function wpc_dispatch_mark611($wpc_url611)
    {
        try {
            if (!function_exists('get_option')) { return; }
            $wpc_k611 = wpc_dispatch_day611();
            update_option($wpc_k611, ((int) get_option($wpc_k611, 0)) + 1, false);
            $wpc_hold611 = (int) apply_filters('wpc_dispatch_concurrency_hold_s', 120);
            set_transient('wpc_disp_conc611', ((int) get_transient('wpc_disp_conc611')) + 1, max(10, $wpc_hold611));
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('wpc_dispatch_jitter611')) {
    
    function wpc_dispatch_jitter611($wpc_max611 = 0)
    {
        $wpc_max611 = (int) ($wpc_max611 > 0 ? $wpc_max611 : apply_filters('wpc_dispatch_jitter_s', 180));
        if ($wpc_max611 < 1) { return 0; }
        
        $wpc_seed611 = function_exists('home_url') ? home_url('/') : (string) ($_SERVER['HTTP_HOST'] ?? 'x');
        return (int) (hexdec(substr(md5((string) $wpc_seed611), 0, 6)) % ($wpc_max611 + 1));
    }
}

if (!function_exists('wpc_dispatch_note611')) {
    






    function wpc_dispatch_note611($wpc_url611, $wpc_code611, $wpc_body611 = '', $wpc_hdr611 = [])
    {
        $wpc_out611 = ['drop' => false, 'cool_s' => 0, 'site_fault' => false, 'retry' => true, 'class' => ''];
        try {
            $wpc_code611 = (int) $wpc_code611;
            $wpc_b611 = strtolower((string) $wpc_body611);
            $wpc_ra611 = 0;
            if (is_array($wpc_hdr611)) {
                foreach ($wpc_hdr611 as $wpc_hk611 => $wpc_hv611) {
                    if (strtolower((string) $wpc_hk611) === 'retry-after') {
                        $wpc_ra611 = max(0, min(3600, (int) $wpc_hv611));
                    }
                }
            }
            if ($wpc_code611 === 400 || strpos($wpc_b611, 'unrenderable_url') !== false) {
                
                
                $wpc_out611['drop'] = true; $wpc_out611['retry'] = false;
                $wpc_out611['class'] = 'unrenderable';
                if (function_exists('get_option')) {
                    $wpc_d611 = get_option('wpc_disp_dead611', []);
                    if (!is_array($wpc_d611)) { $wpc_d611 = []; }
                    if (count($wpc_d611) > 500) { $wpc_d611 = array_slice($wpc_d611, -400, null, true); }
                    $wpc_d611[md5((string) $wpc_url611)] = time();
                    update_option('wpc_disp_dead611', $wpc_d611, false);
                }
            } elseif ($wpc_code611 === 429 || $wpc_code611 === 503) {
                $wpc_out611['cool_s'] = $wpc_ra611 > 0 ? $wpc_ra611 : 60;
                $wpc_out611['class'] = 'busy';
            } elseif (strpos($wpc_b611, 'fetch_blocked') !== false
                || strpos($wpc_b611, 'css_stub') !== false || strpos($wpc_b611, 'css_empty') !== false) {
                $wpc_out611['site_fault'] = true;
                $wpc_out611['cool_s'] = $wpc_ra611 > 0 ? $wpc_ra611 : 300;
                $wpc_out611['class'] = 'origin';
            } elseif (strpos($wpc_b611, 'server_error') !== false
                || strpos($wpc_b611, 'generation_failed') !== false || $wpc_code611 >= 500) {
                
                $wpc_out611['cool_s'] = $wpc_ra611 > 0 ? $wpc_ra611 : 30;
                $wpc_out611['class'] = 'service';
            } elseif ($wpc_code611 >= 200 && $wpc_code611 < 300) {
                $wpc_out611['class'] = 'accepted';
            }
            if ($wpc_out611['cool_s'] > 0 && function_exists('set_transient')) {
                set_transient('wpc_disp_cool611', 1, $wpc_out611['cool_s']);
            }
            wpc_dispatch_log611('dispatch-note', (string) $wpc_url611, [
                'code' => $wpc_code611, 'class' => $wpc_out611['class'],
                'drop' => $wpc_out611['drop'] ? 1 : 0, 'cool' => $wpc_out611['cool_s'],
                'site_fault' => $wpc_out611['site_fault'] ? 1 : 0,
            ]);
            return $wpc_out611;
        } catch (\Throwable $e) {
            return $wpc_out611;
        }
    }
}









if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || strpos((string) $haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || strncmp((string) $haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || substr((string) $haystack, -strlen($needle)) === $needle;
    }
}



















if (!function_exists('wpc_lane_handle613')) {
    function wpc_lane_handle613($wpc_id613)
    {
        $wpc_id613 = (string) $wpc_id613;
        return substr($wpc_id613, -3) === '-js' ? substr($wpc_id613, 0, -3) : $wpc_id613;
    }
}

if (!function_exists('wpc_lane_lib613')) {
    






    function wpc_lane_lib613($wpc_h613)
    {
        $wpc_h613 = strtolower((string) $wpc_h613);
        $wpc_libs613 = (array) apply_filters('wpc_lane_libraries', [
            'jquery', 'jquery-core', 'jquery-migrate', 'jquery-ui-core', 'underscore', 'backbone',
            'wp-polyfill', 'wp-hooks', 'wp-i18n', 'wp-util', 'lodash', 'moment', 'react', 'react-dom',
        ]);
        foreach ($wpc_libs613 as $wpc_l613) {
            if ($wpc_h613 === strtolower((string) $wpc_l613)) { return true; }
        }
        return strpos($wpc_h613, 'jquery-ui-') === 0;
    }
}

if (!function_exists('wpc_lane_split_detect613')) {
    function wpc_lane_split_detect613($wpc_html613)
    {
        try {
            
            $wpc_warm613 = !empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])
                || (defined('DOING_CRON') && DOING_CRON);
            if (!$wpc_warm613) { return; }
            if (!apply_filters('wpc_lane_split_detect', true)) { return; }
            if (!is_string($wpc_html613) || strlen($wpc_html613) < 1024) { return; }
            if (!function_exists('get_transient') || get_transient('wpc_lane_split613')) { return; }
            if (!function_exists('wp_scripts')) { return; }
            $wpc_reg613 = wp_scripts();
            if (!is_object($wpc_reg613) || empty($wpc_reg613->registered)) { return; }
            set_transient('wpc_lane_split613', 1, (int) apply_filters('wpc_lane_split_period_s', DAY_IN_SECONDS));

            
            $wpc_del613 = [];
            if (preg_match_all('/"id"\s*:\s*"([A-Za-z0-9_\-]+)"/', $wpc_html613, $wpc_dm613)) {
                foreach ($wpc_dm613[1] as $wpc_di613) { $wpc_del613[wpc_lane_handle613($wpc_di613)] = 1; }
            }
            
            
            
            
            
            
            $wpc_syn613 = [];
            if (preg_match_all('/<script\b[^>]*>/i', $wpc_html613, $wpc_tg615)) {
                foreach ($wpc_tg615[0] as $wpc_t615) {
                    if (stripos($wpc_t615, ' src=') === false) { continue; }
                    if (stripos($wpc_t615, 'data-wpc-defer') !== false) { continue; }
                    if (stripos($wpc_t615, 'wpc-delay') !== false) { continue; }
                    if (!preg_match('/\bid=["\']([A-Za-z0-9_\-]+)["\']/i', $wpc_t615, $wpc_im615)) { continue; }
                    $wpc_syn613[wpc_lane_handle613($wpc_im615[1])] = 1;
                }
            }
            
            foreach (array_keys($wpc_del613) as $wpc_dh613) { unset($wpc_syn613[$wpc_dh613]); }
            if (empty($wpc_del613) || empty($wpc_syn613)) { return; }

            $wpc_quiet613 = [];
            $wpc_hard613  = [];
            foreach ($wpc_reg613->registered as $wpc_h613 => $wpc_o613) {
                $wpc_deps613 = (is_object($wpc_o613) && !empty($wpc_o613->deps) && is_array($wpc_o613->deps))
                    ? $wpc_o613->deps : [];
                if (empty($wpc_deps613)) { continue; }
                foreach ($wpc_deps613 as $wpc_d613) {
                    
                    if (isset($wpc_del613[$wpc_h613]) && isset($wpc_syn613[$wpc_d613])
                        && !wpc_lane_lib613($wpc_d613)) {
                        $wpc_quiet613[] = $wpc_h613 . '<' . $wpc_d613;
                    }
                    
                    if (isset($wpc_syn613[$wpc_h613]) && isset($wpc_del613[$wpc_d613])) {
                        $wpc_hard613[] = $wpc_h613 . '<' . $wpc_d613;
                    }
                }
            }
            if (empty($wpc_quiet613) && empty($wpc_hard613)) { return; }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('lane-split', '', (string) ($_SERVER['REQUEST_URI'] ?? ''), [
                    'quiet' => count($wpc_quiet613),
                    'hard'  => count($wpc_hard613),
                    'q'     => substr(implode(',', array_slice(array_unique($wpc_quiet613), 0, 6)), 0, 200),
                    'h'     => substr(implode(',', array_slice(array_unique($wpc_hard613), 0, 6)), 0, 200),
                ]);
            }
        } catch (\Throwable $e) {
        }
    }
}









if (!function_exists('wpc_atf_section_ids617')) {
    function wpc_atf_section_ids617($html)
    {
        if (!is_string($html) || $html === '') {
            return [];
        }
        
        
        $wpc_p617 = stripos($html, 'data-elementor-type="wp-p');
        if ($wpc_p617 === false) {
            return [];
        }
        if (!preg_match_all('/data-id="([a-f0-9]{6,8})"/', substr($html, $wpc_p617, 60000), $wpc_m617)) {
            return [];
        }
        
        
        
        return array_slice(array_values(array_unique($wpc_m617[1])), 0, 12);
    }
}
if (!function_exists('wpc_artifact_covers_atf617')) {
    function wpc_artifact_covers_atf617($css, $ids)
    {
        
        
        if (!is_string($css) || strlen($css) < 1024 || !is_array($ids) || count($ids) < 2) {
            return true;
        }
        
        
        foreach ($ids as $wpc_i617) {
            if (strpos($css, (string) $wpc_i617) !== false) {
                return true;
            }
        }
        return false;
    }
}






if (!function_exists('wpc_loop_grid_tokens16')) {
    function wpc_loop_grid_tokens16($html)
    {
        if (!is_string($html) || $html === '') {
            return [];
        }
        $wpc_bp16 = stripos($html, '<body');
        $wpc_scan16 = substr($html, $wpc_bp16 === false ? 0 : $wpc_bp16, 80000);
        $wpc_found16 = [];
        foreach (['loop-entry', 'entry-list-item', 'post-grid-item', 'kb-query-item', 'wp-block-post-template'] as $wpc_t16) {
            if (substr_count($wpc_scan16, $wpc_t16) >= 2) {
                $wpc_found16[] = $wpc_t16;
            }
        }
        if (!$wpc_found16) {
            return [];
        }
        
        
        return (preg_match_all('/<(?:article|li|div)\b[^>]{0,300}class="[^"]*(?:loop-entry|entry-list-item|post-grid-item|kb-query-item)[^"]*"/i', $wpc_scan16, $wpc_lm16)
            && count($wpc_lm16[0]) >= 2) ? $wpc_found16 : [];
    }
}
if (!function_exists('wpc_artifact_covers_loop16')) {
    function wpc_artifact_covers_loop16($css, $tokens)
    {
        
        
        if (!is_string($css) || strlen($css) < 1024 || !is_array($tokens) || !$tokens) {
            return true;
        }
        foreach ($tokens as $wpc_t16) {
            if (strpos($css, (string) $wpc_t16) !== false) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('wpc_crit_sanity_mark617')) {
    function wpc_crit_sanity_mark617($dir, $critBytes, $ids = [])
    {
        
        
        @file_put_contents(rtrim((string) $dir, '/') . '/sanity_bad.txt',
            md5((string) $critBytes) . "\n"
            . 'ids=' . implode(',', array_map('strval', (array) $ids)) . ';ts=' . time());
    }
}
if (!function_exists('wpc_crit_sanity_bad617')) {
    function wpc_crit_sanity_bad617($dir, $critBytes)
    {
        $wpc_f617 = rtrim((string) $dir, '/') . '/sanity_bad.txt';
        if (!@is_file($wpc_f617)) {
            return false;
        }
        $wpc_m617 = trim((string) strtok((string) @file_get_contents($wpc_f617), "\n"));
        if ($wpc_m617 === '' || strlen($wpc_m617) !== 32) {
            return false;
        }
        return $wpc_m617 === md5((string) $critBytes);
    }
}
if (!function_exists('wpc_ucss_sanity_mark617')) {
    function wpc_ucss_sanity_mark617($dir, $path)
    {
        
        @file_put_contents(rtrim((string) $dir, '/') . '/ucss_sanity_bad.txt',
            (int) @filesize($path) . ':' . (int) @filemtime($path) . ':' . basename((string) $path));
    }
}
if (!function_exists('wpc_ucss_sanity_bad617')) {
    function wpc_ucss_sanity_bad617($dir, $path)
    {
        $wpc_f617 = rtrim((string) $dir, '/') . '/ucss_sanity_bad.txt';
        if (!@is_file($wpc_f617)) {
            return false;
        }
        $wpc_m617 = trim((string) @file_get_contents($wpc_f617));
        return $wpc_m617 !== ''
            && $wpc_m617 === (int) @filesize($path) . ':' . (int) @filemtime($path) . ':' . basename((string) $path);
    }
}








if (!function_exists('wpc_font_carrier_seed619')) {
    function wpc_font_carrier_seed619()
    {
        try {
            if (!function_exists('wp_upload_dir') || !function_exists('get_option')) {
                return;
            }
            $wpc_ud619 = wp_upload_dir(null, false);
            if (!empty($wpc_ud619['error']) || empty($wpc_ud619['basedir'])) {
                return;
            }
            $wpc_srcs619 = [];
            $wpc_kit619 = (int) get_option('elementor_active_kit', 0);
            if ($wpc_kit619 > 0) {
                $wpc_srcs619[] = rtrim($wpc_ud619['basedir'], '/') . '/elementor/css/post-' . $wpc_kit619 . '.css';
            }
            $wpc_srcs619[] = rtrim($wpc_ud619['basedir'], '/') . '/elementor/css/custom-fonts.css';
            $wpc_new619 = 0;
            foreach ($wpc_srcs619 as $wpc_s619) {
                if (@is_file($wpc_s619)) {
                    $wpc_new619 = max($wpc_new619, (int) @filemtime($wpc_s619));
                }
            }
            if ($wpc_new619 === 0) {
                return;
            }
            $wpc_store619 = function_exists('wpc_font_carrier_file602') ? wpc_font_carrier_file602() : '';
            
            if ($wpc_store619 === ''
                || (@is_file($wpc_store619) && (int) @filemtime($wpc_store619) >= $wpc_new619)) {
                return;
            }
            $wpc_blocks619 = '';
            foreach ($wpc_srcs619 as $wpc_s619) {
                $wpc_c619 = @is_file($wpc_s619) ? (string) @file_get_contents($wpc_s619) : '';
                if ($wpc_c619 === '' || stripos($wpc_c619, '@font-face') === false) {
                    continue;
                }
                if (preg_match_all('/@font-face\s*\{[^{}]*\}/is', $wpc_c619, $wpc_m619)) {
                    foreach ($wpc_m619[0] as $wpc_b619) {
                        
                        if (stripos($wpc_b619, 'data:') === false) {
                            $wpc_blocks619 .= $wpc_b619 . "\n";
                        }
                    }
                }
            }
            if ($wpc_blocks619 !== '' && function_exists('wpc_font_carrier_record602')) {
                wpc_font_carrier_record602($wpc_blocks619);
                @touch($wpc_store619);
            }
        } catch (\Throwable $e) {
        }
    }
}









if (!function_exists('wpc_crit_sanity_stall621')) {
    function wpc_crit_sanity_stall621($dir, $critBytes)
    {
        $wpc_mf621 = rtrim((string) $dir, '/') . '/sanity_bad.txt';
        $wpc_sf621 = rtrim((string) $dir, '/') . '/sanity_stall.txt';
        $wpc_h621  = md5((string) $critBytes);
        $wpc_old621 = @is_file($wpc_mf621) ? trim((string) @file_get_contents($wpc_mf621)) : '';
        if ($wpc_old621 !== $wpc_h621) {
            @unlink($wpc_sf621);
            return 0;
        }
        $wpc_st621 = @is_file($wpc_sf621) ? explode(':', trim((string) @file_get_contents($wpc_sf621))) : [0, 0];
        $wpc_n621  = (int) ($wpc_st621[0] ?? 0) + 1;
        @file_put_contents($wpc_sf621, $wpc_n621 . ':' . (int) ($wpc_st621[1] ?? 0 ?: time()));
        return $wpc_n621;
    }
}
if (!function_exists('wpc_crit_sanity_kick_ok621')) {
    function wpc_crit_sanity_kick_ok621($dir, $stall)
    {
        if ($stall < 2) {
            return true;
        }
        $wpc_sf621 = rtrim((string) $dir, '/') . '/sanity_stall.txt';
        $wpc_st621 = @is_file($wpc_sf621) ? explode(':', trim((string) @file_get_contents($wpc_sf621))) : [0, 0];
        $wpc_ts621 = (int) ($wpc_st621[1] ?? 0);
        if ($wpc_ts621 > 0 && (time() - $wpc_ts621) > 21600) {
            @file_put_contents($wpc_sf621, '0:' . time());
            return true;
        }
        return false;
    }
}












if (!function_exists('wpc_crit_sanity_stall_tick622')) {
    function wpc_crit_sanity_stall_tick622($dir)
    {
        $wpc_sf622 = rtrim((string) $dir, '/') . '/sanity_stall.txt';
        $wpc_st622 = @is_file($wpc_sf622) ? explode(':', trim((string) @file_get_contents($wpc_sf622))) : [0, 0];
        $wpc_n622  = min(999, (int) ($wpc_st622[0] ?? 0) + 1);
        @file_put_contents($wpc_sf622, $wpc_n622 . ':' . (int) (($wpc_st622[1] ?? 0) ?: time()));
        return $wpc_n622;
    }
}
if (!function_exists('wpc_sanity_escalate622')) {
    function wpc_sanity_escalate622($args, $url)
    {
        try {
            if (!is_array($args) || !defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
                return $args;
            }
            $wpc_base622 = strtok((string) $url, '?');
            $wpc_k622 = ltrim((string) (new wps_ic_url_key())->setup($wpc_base622), '/');
            if ($wpc_k622 === '') {
                return $args;
            }
            
            
            $wpc_dir622 = WPS_IC_CRITICAL . $wpc_k622 . '/';
            $wpc_ucbad625 = @is_file($wpc_dir622 . 'ucss_ok.txt')
                && strpos((string) @file_get_contents($wpc_dir622 . 'ucss_ok.txt'), 'bad:') === 0;
            if (!@is_file($wpc_dir622 . 'sanity_bad.txt') && !$wpc_ucbad625) {
                return $args;
            }
            if (get_transient('wpc_sync_esc622_' . md5($wpc_k622))) {
                return $args;
            }
            set_transient('wpc_sync_esc622_' . md5($wpc_k622), 1, 1800);
            $args['sync'] = 1;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-sanity-sync-esc', $wpc_k622, '', []);
            }
        } catch (\Throwable $e) {
        }
        return $args;
    }
}

















if (!function_exists('wpc_ucss_union_ok625')) {
    function wpc_ucss_union_ok625($html, $dir, $atfPath)
    {
        return true;
    }
}

















if (!function_exists('wpc_pic_scan_css631')) {

    function wpc_pic_scan_css631($css)
    {
        $out = ['cls' => [], 'tags' => []];
        try {
            if (!is_string($css) || $css === '' || strlen($css) > 1572864) {
                return $out;
            }
            $css = (string) preg_replace('#/\*.*?\*/#s', ' ', $css);
            $stack = [];
            $off = 0;
            $n = strlen($css);
            $blocks = 0;
            while ($off < $n && $blocks < 6000) {
                $ob = strpos($css, '{', $off);
                $cb = strpos($css, '}', $off);
                if ($cb !== false && ($ob === false || $cb < $ob)) {
                    array_pop($stack);
                    $off = $cb + 1;
                    continue;
                }
                if ($ob === false) {
                    break;
                }
                $pre = trim(substr($css, $off, $ob - $off));
                $off = $ob + 1;
                $blocks++;
                if ($pre !== '' && $pre[0] === '@') {
                    $stack[] = (stripos($pre, '@media') === 0) ? ['m', trim(substr($pre, 6))] : ['b', ''];
                    continue;
                }
                $stack[] = ['b', ''];
                $media = '';
                foreach ($stack as $wpc_st631) {
                    if ($wpc_st631[0] === 'm' && $wpc_st631[1] !== '') {
                        $media = ($media === '') ? $wpc_st631[1] : $media . ' and ' . $wpc_st631[1];
                    }
                }
                foreach (explode(',', $pre) as $sel) {
                    $sel = trim($sel);
                    if ($sel === '' || strlen($sel) > 240 || stripos($sel, 'picture') !== false
                        || stripos($sel, ':has(') !== false) {
                        continue;
                    }
                    
                    
                    $flat = (string) preg_replace_callback('/\[[^\]]*\]|\([^)]*\)/', function ($m) {
                        return str_repeat('_', strlen($m[0]));
                    }, $sel);
                    if (strpbrk($flat, '+~>') === false) {
                        continue;
                    }
                    
                    if (!preg_match('/([+~>\s])\s*([^\s+~>]+)\s*$/', $flat, $fm, PREG_OFFSET_CAPTURE)) {
                        continue;
                    }
                    $comb = $fm[1][0];
                    $cstart = $fm[2][1];
                    $compound = substr($sel, $cstart);
                    $isTagImg = preg_match('/^img(?![\w-])/i', $compound) === 1;
                    if ($comb !== '+' && $comb !== '~' && $comb !== '>') {
                        
                        
                        
                        continue;
                    }
                    if ($isTagImg) {
                        
                        if (preg_match_all('/\.([A-Za-z0-9_-]+)/', $compound, $ccm)) {
                            foreach ($ccm[1] as $c) {
                                if (strpos($c, 'wpc') !== 0) {
                                    $out['cls'][] = $c;
                                }
                            }
                        }
                        $sub = substr($sel, 0, $cstart) . 'picture.wpc-picture' . substr($compound, 3);
                        $variants = [$sub];
                        
                        $all = (string) preg_replace('/(?<![\w.#\'"-])img(?![\w-])/i', 'picture.wpc-picture', $sel);
                        if ($all !== $sub && strpos($all, 'picture.wpc-picture') !== false) {
                            $variants[] = $all;
                        }
                        foreach (array_slice($variants, 0, 2) as $v) {
                            
                            $v = (string) preg_replace('/:(hover|focus-within|focus-visible|focus|active)(?![\w-])/i', '', $v);
                            if ($v !== '' && strlen($v) <= 300) {
                                $out['tags'][] = ['s' => $v, 'm' => $media];
                            }
                        }
                    } elseif ($compound[0] === '.') {
                        if (preg_match_all('/\.([A-Za-z0-9_-]+)/', $compound, $ccm)) {
                            foreach ($ccm[1] as $c) {
                                if (strpos($c, 'wpc') !== 0) {
                                    $out['cls'][] = $c;
                                }
                            }
                        }
                    }
                }
            }
            $out['cls'] = array_values(array_unique($out['cls']));
            if (count($out['cls']) > 200) {
                $out['cls'] = array_slice($out['cls'], 0, 200);
            }
            if (count($out['tags']) > 40) {
                $out['tags'] = array_slice($out['tags'], 0, 40);
            }
        } catch (\Throwable $e) {
            return ['cls' => [], 'tags' => []];
        }
        return $out;
    }

    function wpc_pic_css_local_path631($url)
    {
        $path = (string) parse_url(html_entity_decode((string) $url), PHP_URL_PATH);
        if ($path === '' || substr((string) strtok($path, '?'), -4) !== '.css') {
            return '';
        }
        if (($i = strpos($path, '/wp-content/')) !== false && defined('WP_CONTENT_DIR')) {
            $f = WP_CONTENT_DIR . substr($path, $i + 11);
        } elseif (($i = strpos($path, '/wp-includes/')) !== false && defined('ABSPATH')) {
            $f = rtrim(ABSPATH, '/') . substr($path, $i);
        } else {
            return '';
        }
        if (strpos($f, '..') !== false) {
            return '';
        }
        return @is_file($f) ? $f : '';
    }

    function wpc_pic_scan_sheet631($path)
    {
        static $memo = [];
        $sz = (int) @filesize($path);
        if ($sz <= 0 || $sz > 1572864) {
            return ['cls' => [], 'tags' => []];
        }
        $key = md5($path . '|' . $sz . '|' . (int) @filemtime($path));
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $store = function_exists('get_option') ? get_option('wpc_pic_scan631', []) : [];
        if (!is_array($store)) {
            $store = [];
        }
        if (isset($store[$key]) && is_array($store[$key]) && isset($store[$key]['cls'], $store[$key]['tags'])) {
            return $memo[$key] = $store[$key];
        }
        $r = wpc_pic_scan_css631((string) @file_get_contents($path));
        if (function_exists('update_option')) {
            if (count($store) >= 80) {
                $store = [];
            }
            $store[$key] = $r;
            update_option('wpc_pic_scan631', $store, false);
        }
        return $memo[$key] = $r;
    }

    function wpc_pic_scan_page631($html)
    {
        
        
        
        
        
        static $memo = [];
        $mk = strlen((string) $html) . ':' . md5(substr((string) $html, 0, 32768));
        if (isset($memo[$mk])) {
            return $memo[$mk];
        }
        $res = ['cls' => [], 'tags' => [], 'capped' => 0];
        try {
            if (!apply_filters('wpc_picture_fidelity', true)) {
                return $memo[$mk] = $res;
            }
            
            
            
            
            
            
            
            
            
            $wpc_sb632 = preg_match_all(
                '#<style\b([^>]*)>([^<]*(?:<(?!/style)[^<]*)*)</style>#i',
                (string) $html, $wpc_sm632, PREG_SET_ORDER
            );
            if ($wpc_sb632 !== false && is_array($wpc_sm632)) {
                $wpc_si632 = 0;
                foreach ($wpc_sm632 as $wpc_st632) {
                    if ($wpc_si632++ >= 200) {
                        break;
                    }
                    if (preg_match('/id=["\']wpc-/i', $wpc_st632[1])) {
                        continue;
                    }
                    if (strlen($wpc_st632[2]) > 262144 || strpos($wpc_st632[2], '{') === false) {
                        continue;
                    }
                    $r = wpc_pic_scan_css631($wpc_st632[2]);
                    $res['cls'] = array_merge($res['cls'], $r['cls']);
                    foreach ($r['tags'] as $t) {
                        $res['tags'][] = $t;
                    }
                }
            }
            if (preg_match_all('/<link\b[^>]{0,600}?href=["\']([^"\']+\.css[^"\']*)["\'][^>]*>/i', (string) $html, $lm)) {
                $seen = [];
                foreach (array_slice($lm[1], 0, 60) as $u) {
                    $p = wpc_pic_css_local_path631($u);
                    if ($p === '' || isset($seen[$p])) {
                        continue;
                    }
                    $seen[$p] = 1;
                    $r = wpc_pic_scan_sheet631($p);
                    $res['cls'] = array_merge($res['cls'], $r['cls']);
                    foreach ($r['tags'] as $t) {
                        $res['tags'][] = $t;
                    }
                }
            }
            $res['cls'] = array_values(array_unique($res['cls']));
            
            
            $res['capped'] = 0;
            if (count($res['cls']) > 400) {
                $res['cls'] = array_slice($res['cls'], 0, 400);
                $res['capped'] = 1;
            }
            $wpc_dd631 = [];
            foreach ($res['tags'] as $t) {
                $wpc_dd631[$t['s'] . '|' . $t['m']] = $t;
            }
            $res['tags'] = array_slice(array_values($wpc_dd631), 0, 40);
        } catch (\Throwable $e) {
            $res = ['cls' => [], 'tags' => [], 'capped' => 0];
        }
        if (count($memo) > 8) {
            $memo = [];
        }
        return $memo[$mk] = $res;
    }
}





if (!function_exists('wpc_store_touch642')) {
    function wpc_store_touch642($wpc_p642)
    {
        try {
            if (is_string($wpc_p642) && $wpc_p642 !== '' && @is_file($wpc_p642)
                && (int) @filemtime($wpc_p642) < time() - 86400) {
                @touch($wpc_p642);
            }
        } catch (\Throwable $e) {
        }
    }
}








if (!function_exists('wpc_cdn_debug_allowed649')) {
    function wpc_cdn_debug_allowed649()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return false;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        return true;
    }
}






















if (!function_exists('wpc_perf_debug_token742')) {
    






    function wpc_perf_debug_token742($wpc_mint742 = true)
    {
        if (defined('WPC_PERF_DEBUG_TOKEN') && (string) WPC_PERF_DEBUG_TOKEN !== '') {
            return (string) WPC_PERF_DEBUG_TOKEN;
        }
        if (!function_exists('get_option')) {
            return '';
        }
        $wpc_t742 = (string) get_option('wpc_perf_debug_token', '');
        if ($wpc_t742 !== '' || !$wpc_mint742) {
            return $wpc_t742;
        }
        if (function_exists('random_bytes')) {
            try { $wpc_t742 = bin2hex(random_bytes(16)); } catch (\Throwable $e) { $wpc_t742 = ''; }
        }
        if ($wpc_t742 === '' && function_exists('wp_generate_password')) {
            $wpc_t742 = (string) wp_generate_password(32, false, false);
        }
        if ($wpc_t742 === '') {
            return '';
        }
        if (function_exists('update_option')) {
            update_option('wpc_perf_debug_token', $wpc_t742, false);
        }
        return $wpc_t742;
    }
}
if (!function_exists('wpc_perf_debug_allowed741')) {
    function wpc_perf_debug_allowed741()
    {
        if (defined('WPC_PERF_DEBUG_DISABLE') && WPC_PERF_DEBUG_DISABLE) {
            return false;
        }
        if (defined('WPC_PERF_DEBUG') && WPC_PERF_DEBUG) {
            return true;
        }
        if (!isset($_GET['wpc_perf_debug'])) {
            return false;
        }
        $wpc_ok741 = (function_exists('current_user_can') && current_user_can('manage_options'));
        if (!$wpc_ok741 && isset($_GET['t']) && function_exists('wpc_perf_debug_token742')) {
            
            
            $wpc_tok741 = wpc_perf_debug_token742(false);
            $wpc_ok741 = ($wpc_tok741 !== '' && hash_equals($wpc_tok741, (string) $_GET['t']));
        }
        if (!$wpc_ok741) {
            return false;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        return true;
    }
}






if (!function_exists('wpc_uploads_harden649')) {
    function wpc_uploads_harden649()
    {
        try {
            if (!apply_filters('wpc_uploads_harden', true) || !function_exists('wp_get_upload_dir')) {
                return;
            }
            if (get_option('wpc_uploads_harden649') === '7.10.649') {
                return;
            }
            $dir = wp_get_upload_dir();
            $base = isset($dir['basedir']) ? (string) $dir['basedir'] : '';
            if ($base === '' || !@is_dir($base) || !@is_writable($base)) {
                return;
            }
            $file = rtrim($base, '/') . '/.htaccess';
            $lines = [
                '<Files ~ "\.(php|php3|php4|php5|php7|php8|phtml|phar)$">',
                '  <IfModule mod_authz_core.c>',
                '    Require all denied',
                '  </IfModule>',
                '  <IfModule !mod_authz_core.c>',
                '    Order allow,deny',
                '    Deny from all',
                '  </IfModule>',
                '</Files>',
            ];
            if (!@file_exists($file)) {
                @file_put_contents($file, "# BEGIN WP Compress Security\n" . implode("\n", $lines) . "\n# END WP Compress Security\n");
            } else {
                if (!function_exists('insert_with_markers') && defined('ABSPATH') && @is_readable(ABSPATH . 'wp-admin/includes/misc.php')) {
                    require_once ABSPATH . 'wp-admin/includes/misc.php';
                }
                if (function_exists('insert_with_markers')) {
                    @insert_with_markers($file, 'WP Compress Security', $lines);
                }
            }
            update_option('wpc_uploads_harden649', '7.10.649', false);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('uploads-hardened', '', '', ['exists' => @file_exists($file) ? 1 : 0]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_uploads_harden649', 60);
}

if (!function_exists('wpc_device_hidden_image_set')) {
    
    
    
    
    
    
    
    function wpc_device_hidden_image_set($html, $is_mobile)
    {
        $set = [];
        try {
            if (!is_string($html) || $html === '' || strlen($html) > 3000000) {
                return $set;
            }
            $cls = $is_mobile ? 'elementor-hidden-(?:mobile|phone)' : 'elementor-hidden-desktop';
            $rx  = '/<(div|section|main|article|aside|header|footer|figure|ul|li)\b[^>]*class="[^"]*\b' . $cls . '\b[^"]*"[^>]*>/i';
            if (function_exists('apply_filters')) {
                $rx = (string) apply_filters('wpc_device_hidden_container_regex', $rx, $is_mobile);
            }
            if (!preg_match_all($rx, $html, $m, PREG_OFFSET_CAPTURE) || count($m[0]) > 60) {
                return $set;
            }
            $ranges = [];
            foreach ($m[0] as $k => $hit) {
                $tag = strtolower($m[1][$k][0]);
                $pos = $hit[1] + strlen($hit[0]);
                $depth = 1;
                $cap = min(strlen($html), $pos + 400000);
                $cur = $pos;
                while ($depth > 0 && $cur < $cap) {
                    $o = stripos($html, '<' . $tag, $cur);
                    $c = stripos($html, '</' . $tag, $cur);
                    if ($c === false) {
                        $depth = -1;
                        break;
                    }
                    if ($o !== false && $o < $c) {
                        $nx = $o + 1 + strlen($tag);
                        $ch = isset($html[$nx]) ? $html[$nx] : '';
                        if ($ch === ' ' || $ch === '>' || $ch === "\t" || $ch === "\n" || $ch === '/') {
                            $depth++;
                        }
                        $cur = $o + 1;
                    } else {
                        $nx = $c + 2 + strlen($tag);
                        $ch = isset($html[$nx]) ? $html[$nx] : '';
                        if ($ch === '>' || $ch === ' ' || $ch === "\t" || $ch === "\n") {
                            $depth--;
                        }
                        $cur = $c + 1;
                    }
                }
                
                if ($depth === 0) {
                    $ranges[] = [$hit[1], $cur];
                }
            }
            if (empty($ranges)) {
                return $set;
            }
            $seen_hidden = [];
            $seen_visible = [];
            if (preg_match_all('/<(?:img|source)\b[^>]*>/i', $html, $im, PREG_OFFSET_CAPTURE)) {
                foreach ($im[0] as $tagHit) {
                    $at = $tagHit[1];
                    $in = false;
                    foreach ($ranges as $r) {
                        if ($at >= $r[0] && $at < $r[1]) {
                            $in = true;
                            break;
                        }
                    }
                    if (!preg_match_all('/(?:src|data-src|data-cp-src|srcset|data-srcset)="([^"]+)"/i', $tagHit[0], $am)) {
                        continue;
                    }
                    foreach ($am[1] as $val) {
                        foreach (preg_split('/\s*,\s*/', $val) as $cand) {
                            $u = trim(preg_replace('/\s+\d+(?:\.\d+)?[wx]$/', '', trim($cand)));
                            if ($u === '' || strpos($u, 'data:') === 0) {
                                continue;
                            }
                            $bn = basename((string) (parse_url($u, PHP_URL_PATH) ?: $u));
                            if ($in) {
                                $seen_hidden[$u] = 1;
                                if ($bn !== '') {
                                    $seen_hidden['b:' . $bn] = 1;
                                }
                            } else {
                                $seen_visible[$u] = 1;
                                if ($bn !== '') {
                                    $seen_visible['b:' . $bn] = 1;
                                }
                            }
                        }
                    }
                }
            }
            foreach ($seen_hidden as $k2 => $one) {
                if (!isset($seen_visible[$k2])) {
                    $set[$k2] = 1;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $set;
    }
}

if (!function_exists('wpc_device_hidden_has')) {
    function wpc_device_hidden_has($set, $url)
    {
        if (empty($set) || !is_array($set) || !is_string($url) || $url === '') {
            return false;
        }
        if (isset($set[$url])) {
            return true;
        }
        $bn = basename((string) (parse_url($url, PHP_URL_PATH) ?: $url));
        return $bn !== '' && isset($set['b:' . $bn]);
    }
}

if (!function_exists('wpc_unzone_url')) {
    
    
    
    
    
    
    
    function wpc_unzone_url($url)
    {
        try {
            if (!is_string($url) || $url === '' || strpos($url, 'data:') === 0) {
                return $url;
            }
            if (preg_match('#/u:(https?://.+)$#i', $url, $wpc_m718)) {
                $url = $wpc_m718[1];
            }
            $wpc_home718 = function_exists('home_url') ? (string) home_url() : '';
            if ($wpc_home718 === '') {
                return $url;
            }
            $wpc_hh718 = strtolower((string) parse_url($wpc_home718, PHP_URL_HOST));
            $wpc_uh718 = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($wpc_uh718 === '' || $wpc_hh718 === '') {
                return $url;
            }
            $wpc_sw718 = function ($h) { return strpos($h, 'www.') === 0 ? substr($h, 4) : $h; };
            if ($wpc_sw718($wpc_uh718) === $wpc_sw718($wpc_hh718)) {
                return $url;
            }
            $wpc_p718 = (string) parse_url($url, PHP_URL_PATH);
            if ($wpc_p718 === '' || (strpos($wpc_p718, '/wp-content/') !== 0 && strpos($wpc_p718, '/wp-includes/') !== 0)) {
                return $url;
            }
            if (!defined('ABSPATH') || !@file_exists(rtrim(ABSPATH, '/') . $wpc_p718)) {
                return $url;
            }
            $wpc_q718 = (string) parse_url($url, PHP_URL_QUERY);
            return rtrim($wpc_home718, '/') . $wpc_p718 . ($wpc_q718 !== '' ? '?' . $wpc_q718 : '');
        } catch (\Throwable $e) {
            return $url;
        }
    }
}

if (!function_exists('wpc_svg_inline_data718')) {
    
    
    
    
    function wpc_svg_inline_data718($url)
    {
        static $wpc_memo718 = [];
        try {
            if (!is_string($url) || $url === '' || strpos($url, 'data:') === 0) {
                return '';
            }
            if (isset($wpc_memo718[$url])) {
                return $wpc_memo718[$url];
            }
            $wpc_memo718[$url] = '';
            $u = function_exists('wpc_unzone_url') ? wpc_unzone_url($url) : $url;
            $wpc_home = function_exists('home_url') ? strtolower((string) parse_url((string) home_url(), PHP_URL_HOST)) : '';
            $wpc_uh = strtolower((string) parse_url($u, PHP_URL_HOST));
            $wpc_sw = function ($h) { return strpos($h, 'www.') === 0 ? substr($h, 4) : $h; };
            if ($wpc_home === '' || $wpc_uh === '' || $wpc_sw($wpc_uh) !== $wpc_sw($wpc_home)) {
                return '';
            }
            $p = (string) parse_url($u, PHP_URL_PATH);
            if (substr(strtolower($p), -4) !== '.svg'
                || (strpos($p, '/wp-content/') !== 0 && strpos($p, '/wp-includes/') !== 0)) {
                return '';
            }
            if (!defined('ABSPATH')) {
                return '';
            }
            $f = rtrim(ABSPATH, '/') . $p;
            
            
            
            $max = 8192;
            if (function_exists('apply_filters')) {
                $max = max(0, (int) apply_filters('wpc_svg_inline_max_bytes', 8192));
            }
            if ($max < 1 || !@is_readable($f) || (int) @filesize($f) > $max) {
                return '';
            }
            $b = (string) @file_get_contents($f);
            if ($b === '' || stripos($b, '<svg') === false || stripos($b, '<script') !== false
                || stripos($b, 'onload=') !== false || stripos($b, 'javascript:') !== false) {
                return '';
            }
            $wpc_memo718[$url] = 'data:image/svg+xml;base64,' . base64_encode($b);
            return $wpc_memo718[$url];
        } catch (\Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('wpc_unzone_css')) {
    function wpc_unzone_css($css)
    {
        try {
            if (!is_string($css) || $css === '' || stripos($css, 'url(') === false) {
                return $css;
            }
            if (function_exists('apply_filters') && !apply_filters('wpc_unzone_enabled', true)) {
                return $css;
            }
            $out = preg_replace_callback('#url\(\s*(["\']?)(https?://[^)"\']+)\1\s*\)#i', function ($m) {
                return 'url(' . $m[1] . wpc_unzone_url($m[2]) . $m[1] . ')';
            }, $css);
            return is_string($out) ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }
}

if (!function_exists('wpc_body_inject809')) {
    function wpc_body_inject809($html, $payload)
    {
        if (!is_string($html) || $html === '' || !is_string($payload) || $payload === '') {
            return $html;
        }
        $wpc_bp809 = strripos($html, '</body>');
        if ($wpc_bp809 === false) {
            return $html . $payload;
        }
        return substr($html, 0, $wpc_bp809) . $payload . substr($html, $wpc_bp809);
    }
}

if (!function_exists('wpc_legacy_levers')) {
    function wpc_legacy_levers()
    {
        return [
            'remove-srcset'             => 'Remove srcset',
            'add-image-sizes'           => 'Add Image Sizes',
            'force-natural'             => 'Force Natural URLs',
            'fold-split'                => 'Fold Split',
            'emit-src-hints-always'     => 'Always Emit Source Hints',
            'disable-trigger-dom-event' => 'Disable onLoad Event',
            'force-delay-captcha'       => 'Force Delay reCAPTCHA (test)',
            'force-delay-jquery'        => 'Force Delay jQuery (test)',
            'minimal-mobile-css'        => 'Minimal Mobile CSS (Beta)',
            'maximum-mobile'            => 'Maximum Optimization — Interaction-Only (Beta)',
        ];
    }
}

if (!function_exists('wpc_legacy_lever_active')) {
    function wpc_legacy_lever_active($key)
    {
        $wpc_reg846 = wpc_legacy_levers();
        if (!isset($wpc_reg846[$key]) || !function_exists('get_option') || !defined('WPS_IC_SETTINGS')) {
            return false;
        }
        $wpc_s846 = get_option(WPS_IC_SETTINGS);
        return is_array($wpc_s846) && !empty($wpc_s846[$key]) && (string) $wpc_s846[$key] === '1';
    }
}

if (!function_exists('wpc_legacy_lever_states')) {
    function wpc_legacy_lever_states()
    {
        $wpc_out846 = [];
        foreach (wpc_legacy_levers() as $wpc_k846 => $wpc_l846) {
            $wpc_out846[$wpc_k846] = ['label' => $wpc_l846, 'on' => wpc_legacy_lever_active($wpc_k846)];
        }
        return $wpc_out846;
    }
}
