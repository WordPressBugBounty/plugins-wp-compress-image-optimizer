<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/speculation_rules.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */






























class wps_ic_speculation_rules
{
    






    public static function register_hooks($settings)
    {
        if (!empty($settings['speculation-rules']) && $settings['speculation-rules'] == '1') {
            add_filter('wp_speculation_rules_configuration', '__return_null');
        }
    }

    public static function isActive($settings, $page_excludes)
    {
        if (isset($_GET['disableSpeculation'])) return false; 
        if (is_user_logged_in()) return false;
        
        if (isset($page_excludes['speculation_rules'])) {
            if ($page_excludes['speculation_rules'] == '0') return false;
            if ($page_excludes['speculation_rules'] == '1') return true;
        }
        if (empty($settings['speculation-rules']) || $settings['speculation-rules'] != '1') return false;
        return true;
    }

    public function process_html($html)
    {
        if (stripos($html, 'type="speculationrules"') !== false) return $html; 
        if (stripos($html, "type='speculationrules'") !== false) return $html; 

        
        
        
        $base = '';
        if (function_exists('home_url')) {
            $p = parse_url(home_url('/'), PHP_URL_PATH);
            if (is_string($p) && $p !== '' && $p !== '/') $base = rtrim($p, '/');
        }

        $exclude = array(
            array('href_matches' => $base . '/wp-admin/*'),
            array('href_matches' => $base . '/wp-login.php*'),
            
            array('href_matches' => '/*\\?(.+)'),
            array('href_matches' => $base . '/cart/*'), array('href_matches' => $base . '/checkout/*'),
            array('href_matches' => $base . '/my-account/*'), array('href_matches' => $base . '/account/*'),
            array('selector_matches' => '.no-prerender, .no-prerender a, [rel~=nofollow]'),
        );

        
        
        if (function_exists('wc_get_page_id') && function_exists('get_permalink')) {
            foreach (array('cart', 'checkout', 'myaccount') as $wcPage) {
                $pid = wc_get_page_id($wcPage);
                if ($pid > 0) {
                    $path = parse_url(get_permalink($pid), PHP_URL_PATH);
                    if (is_string($path) && $path !== '' && $path !== '/') {
                        $exclude[] = array('href_matches' => rtrim($path, '/') . '/*');
                    }
                }
            }
        }

        $where = array('and' => array(
            array('href_matches' => ($base !== '' ? $base : '') . '/*'),
            array('not' => array('or' => $exclude)),
        ));
        $rules = array(
            'prerender' => array(array('where' => $where, 'eagerness' => 'moderate')),
            'prefetch'  => array(array('where' => $where, 'eagerness' => 'moderate')),
        );
        $json = wp_json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        if (!$json) return $html; 
        $tag = '<script type="speculationrules" id="wpc-speculation-rules">' . $json . '</script>';

        
        $pos = stripos($html, '</head>');
        if ($pos === false) return $html;
        return substr_replace($html, $tag, $pos, 0);
    }
}
