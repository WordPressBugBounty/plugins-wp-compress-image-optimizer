<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/combine_js.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */


include_once WPS_IC_DIR . 'traits/url_key.php';

class wps_ic_combine_js
{

    public static $excludes;
    public static $rewrite;
    public $url_key_class;
    public $urlKey;
    public $combined_dir;
    public $combined_url_base;
    public $settings;
    public $filesize_cap;
    public $combine_inline_scripts;
    public $combine_external;
    public $all_excludes;
    public $zone_name;
    public $hmwpReplace;
    public $hmwp_rewrite;
    public $current_file;
    public $no_content_excludes;
    public $file_count;
    public $current_section;

    



    public function __construct()
    {
        $this->url_key_class = new wps_ic_url_key();
        $this->urlKey = $this->url_key_class->setup();
        $this->combined_dir = WPS_IC_COMBINE . $this->urlKey . '/js/';
        $this->combined_url_base = WPS_IC_COMBINE_URL . $this->urlKey . '/js/';

        self::$excludes = new wps_ic_excludes();
        self::$rewrite = new wps_cdn_rewrite();
        $this->settings = get_option(WPS_IC_SETTINGS);
        $this->filesize_cap = '500000';
        $this->combine_inline_scripts = true;
        $this->combine_external = false;

        $this->all_excludes = self::$excludes->combineJSExcludes();

        if (!empty($this->settings['delay-js']) && $this->settings['delay-js'] == '1') {
            
            $this->all_excludes = array_merge($this->all_excludes, self::$excludes->delayJSExcludes());
        }


	    $cf = get_option(WPS_IC_CF);
	    $cfCname = get_option(WPS_IC_CF_CNAME);
	    $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok())) ? $cfCname : get_option('ic_custom_cname');
        if (empty($custom_cname) || !$custom_cname) {
            $this->zone_name = get_option('ic_cdn_zone_name');
        } else {
            $this->zone_name = $custom_cname;
        }

        
        $this->hmwpReplace = false;
        if (class_exists('HMWP_Classes_ObjController')) {
            $this->hmwpReplace = true;
            $plugin_path = WP_PLUGIN_DIR . '/hide-my-wp/';
            include_once($plugin_path . 'classes/ObjController.php');
            $hmwp_controller = new HMWP_Classes_ObjController();
            $this->hmwp_rewrite = $hmwp_controller::getClass('HMWP_Models_Rewrite');
        }
    }

    public function combine_exists()
    {
        $exists = is_dir($this->combined_dir);
        if ($exists) {
            $exists = (new \FilesystemIterator($this->combined_dir))->valid();
        }

        return $exists;
    }

    public function write_file_and_next()
    {
        if ($this->current_file != '') {
            $wpc_name644 = 'wps_' . $this->current_section . '_' . $this->file_count . '.js';
            file_put_contents($this->combined_dir . $wpc_name644, $this->current_file);
            $this->wpc_written644[] = $wpc_name644;
        }
        $this->file_count++;
        $this->current_file = '';
    }

    
    public $wpc_written644 = [];
    public function wpc_sweep_unwritten644()
    {
        try {
            foreach ((array) @glob($this->combined_dir . 'wps_*.js') as $wpc_f644) {
                if (!in_array(basename($wpc_f644), $this->wpc_written644, true)) {
                    @unlink($wpc_f644);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    
    
    
    
    private function wpc_combine_stale644()
    {
        try {
            $wpc_key644 = rtrim(WPS_IC_COMBINE . $this->urlKey, '/');
            if (@is_file($wpc_key644 . '/.wpc-stale')) {
                return true;
            }
            $wpc_ep644 = (int) get_option('wpc_combine_stale_epoch', 0);
            if ($wpc_ep644 > 0) {
                $wpc_new644 = 0;
                foreach ((array) @glob($this->combined_dir . '*.js') as $wpc_f644) {
                    $wpc_new644 = max($wpc_new644, (int) @filemtime($wpc_f644));
                }
                return $wpc_new644 > 0 && $wpc_new644 < $wpc_ep644;
            }
        } catch (\Throwable $e) {
        }
        return false;
    }

    public function maybe_do_combine($html)
    {
        if ($this->combine_exists() && empty($_GET['forceRecombine']) && !$this->wpc_combine_stale644()) {

            $this->no_content_excludes = get_option('wps_no_content_excludes_js');

            $html = $this->replace($html);
            return $html;
        }
        @unlink(rtrim(WPS_IC_COMBINE . $this->urlKey, '/') . '/.wpc-stale');

        $this->no_content_excludes = [];

        $this->current_file = '';
        $this->file_count = 1;

        $this->setup_dirs();

        $this->current_section = 'header';
        $html = preg_replace_callback('/<head(.*?)<\/head>/si', [$this, 'combine'], $html);

        $this->write_file_and_next();
        $this->current_section = 'footer';
        $this->file_count = 1;
        $html = preg_replace_callback('/<\/head>(.*?)<\/body>/si', [$this, 'combine'], $html);

        $this->write_file_and_next();
        $this->wpc_sweep_unwritten644();

        update_option('wps_no_content_excludes_js', $this->no_content_excludes);
        $html = $this->insert_combined_scripts($html);

        return $html;
    }

    public function combine($html)
    {
        $html = $html[0];
        $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$this, 'script_combine_and_replace'], $html);
        return $html;
    }

    public function script_combine_and_replace($tag)
    {
        $tag = $tag[0];
        $src = '';

        if (self::$excludes->strInArray($tag, $this->all_excludes) || current_user_can('manage_wpc_settings')) {
            return $tag;
            
        }


        preg_match('/<script(.*?)>/si', $tag, $tag_start);
        $tag_start = $tag_start[0];
        $is_src_set = preg_match('/src=["|\'](.*?)["|\']/si', $tag_start, $src);

        

        if ($is_src_set == 1) {

            $src = str_replace('src=', '', $src);
            $src = str_replace(["'", '"'], "", $src);
            $src = $src[0];

            


            if (!$this->combine_external && $this->url_key_class->is_external($src)) {
                return $tag;
            } else if ($this->combine_external && $this->url_key_class->is_external($src)) {
                $content = $this->getRemoteContent($src);
            } else {
                $content = $this->getLocalContent($src);
            }


            if (!$content) {
                $this->no_content_excludes[] = $src;
                return $tag;
            }

        } else if ($this->combine_inline_scripts) {

            
            

            $src = 'Inline Script';
            $content = $tag;
            $content = preg_replace('/<script(.*?)>/', '', $content);
            $content = preg_replace('/<\/script>/', '', $content);
            $content = trim($content);
            if (strpos($content, '<') === 0 || strpos($content, '{') === 0) {
                $this->no_content_excludes[] = $tag;
                return $tag;
            }
        } else {
            return $tag;
        }


        $content = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $content);

        $this->current_file .= "/* SCRIPT : $src */" . PHP_EOL;
        $this->current_file .= $content . PHP_EOL;

        if (mb_strlen($this->current_file, '8bit') >= $this->filesize_cap) {
            $this->write_file_and_next();
        }

        return '';
    }

    public function replace($html)
    {

        $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$this, 'remove_scripts'], $html);
        $html = $this->insert_combined_scripts($html);

        return $html;
    }

    public function remove_scripts($tag)
    {
        $tag = $tag[0];
        $src = '';

        if (self::$excludes->strInArray($tag, $this->all_excludes) || current_user_can('manage_wpc_settings')) {
            
            
        }

        if (self::$excludes->strInArray($tag, $this->no_content_excludes) || current_user_can('manage_wpc_settings')) {

            return $tag;
        }


        preg_match('/<script(.*?)>/si', $tag, $tag_start);
        $tag_start = $tag_start[0];
        $is_src_set = preg_match('/src=["|\'](.*?)["|\']/si', $tag_start, $src);

        

        if ($is_src_set == 1) {

            $src = str_replace('src=', '', $src);
            $src = str_replace(["'", '"'], "", $src);
            $src = $src[0];

            


            if (!$this->combine_external && $this->url_key_class->is_external($src)) {
                return $tag;
            }

        } else if ($this->combine_inline_scripts) {

            
            

            $src = 'Inline Script';
            $content = $tag;
            $content = preg_replace('/<script(.*?)>/', '', $content);
            $content = preg_replace('/<\/script>/', '', $content);
        } else {
            return $tag;
        }


        return '';
    }

    public function insert_combined_scripts($html)
    {

        $combined_files = new \FilesystemIterator($this->combined_dir);
        $header_links = '';
        $footer_links = '';

        foreach ($combined_files as $file) {
            $url = $this->combined_url_base . basename($file);

            if (strpos($file, 'wps_header') !== false) {
                $header_links .= '<script type="text/javascript" src="' . self::$rewrite->adjust_src_url($url) . '"></script>' . PHP_EOL;
            } else {
                $footer_links .= '<script type="text/javascript" src="' . self::$rewrite->adjust_src_url($url) . '"></script>' . PHP_EOL;
            }

        }

        if ($this->hmwpReplace) {

            foreach ($this->hmwp_rewrite->_replace['from'] as $key => $value) {
                $replace = $this->hmwp_rewrite->_replace['to'][$key];
                $header_links = str_replace($value, $replace, $header_links);
                $footer_links = str_replace($value, $replace, $footer_links);
            }
        }


        $html = preg_replace('/<\/head>/', $header_links . '</head>', $html);
        
        
        $html = preg_replace('/<\/body>/', $footer_links . '</body>', $html);

        return $html;
    }

    
    
    
    public function wpc_looks_like_html_doc659($s)
    {
        if (!is_string($s) || $s === '') {
            return false;
        }
        
        
        
        
        $head = strtolower(ltrim($s));
        return strncmp($head, '<!doctype html', 14) === 0
            || strncmp($head, '<html', 5) === 0
            || strncmp($head, '<head>', 6) === 0
            || strncmp($head, '<head ', 6) === 0
            || strncmp($head, '<body', 5) === 0;
    }

    public function getRemoteContent($url)
    {
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        $data = wp_remote_get($url, array('timeout' => (int) apply_filters('wpc_combine_fetch_timeout', 3)));


        if (is_wp_error($data)) {
            return false;
        }

        
        
        
        
        
        
        if ((int) wp_remote_retrieve_response_code($data) !== 200) {
            return false;
        }
        $wpc_body659 = wp_remote_retrieve_body($data);
        $wpc_ct659 = strtolower((string) wp_remote_retrieve_header($data, 'content-type'));
        if (strpos($wpc_ct659, 'text/html') !== false || $this->wpc_looks_like_html_doc659($wpc_body659)) {
            return false;
        }

        return $wpc_body659;
    }

    public function getLocalContent($url)
    {

        if ($this->hmwpReplace) {

            foreach ($this->hmwp_rewrite->_replace['to'] as $key => $value) {
                $replace = $this->hmwp_rewrite->_replace['from'][$key];
                $url = str_replace($value, $replace, $url);
            }
        }

        if (strpos($url, $this->zone_name) !== false) {
            preg_match('/a:(.*?)(\?|$)/', $url, $match);
            $url = $match[1];
        }


        

        
        


        if (strpos($url, '?') !== false) {
            $url = explode('?', $url);
            $url = $url[0];
        }

        if (strpos($url, 'http:') !== false || strpos($url, 'https:') !== false) {
            $path = wp_make_link_relative($url);
            $path = ltrim($path, '/');
        } else {
            $path = ltrim($url, '/');
        }


        $last_abspath = basename(ABSPATH);
        $first_path = explode('/', $path)[0];
        if ($last_abspath == $first_path) {
            $path = substr($path, strlen($first_path));
            $path = ltrim($path, '/');
        }

        $path = urldecode($path);
        $fullPath = ABSPATH . $path;

        if (!file_exists($fullPath)) {
            return false;
        }

        $content = @file_get_contents($fullPath);

        if (!$content) {
            return false;
        }

        return $content;
    }

    public function setup_dirs()
    {
        mkdir(WPS_IC_COMBINE . $this->urlKey . '/js', 0777, true);
    }

}