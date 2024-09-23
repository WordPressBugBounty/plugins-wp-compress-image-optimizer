<?php

class wps_ic_preload
{

    public function __construct()
    {

        if (!is_admin()) {
            $this->apikey = get_option(WPS_IC_OPTIONS)['api_key'];
            if (!isset($_GET['apikey']) || $_GET['apikey'] != $this->apikey) {
                die ('Failed authentication.');
            }
        }

    }

    /**
     * Finds all CSS and JS files in plugins and theme folder, saves to options, returns json response (true/false)
     */
    public function find_files()
    {

        $js_size = 0;
        $css_size = 0;
        $scripts = array();
        $styles = array();


        //scan plugins folder
        $plugins = new RecursiveDirectoryIterator(WP_CONTENT_DIR . '/plugins');
        foreach (new RecursiveIteratorIterator($plugins) as $filename => $file) {
            if ($file->isDir()) {
                continue;
            }

            $ext = $file->getExtension();

            switch ($ext) {
                case 'js':
                    array_push($scripts, $this->path_to_url($filename));
                    $js_size = $js_size + $file->getSize();
                    break;

                case 'css':
                    array_push($styles, $this->path_to_url($filename));
                    $css_size = $css_size + $file->getSize();
                    break;
            }

        }

        //scan theme folder
        $plugins = new RecursiveDirectoryIterator(get_template_directory());
        foreach (new RecursiveIteratorIterator($plugins) as $filename => $file) {
            if ($file->isDir()) {
                continue;
            }

            $ext = $file->getExtension();

            switch ($ext) {
                case 'js':
                    array_push($scripts, $this->path_to_url($filename));
                    $js_size = $js_size + $file->getSize();
                    break;

                case 'css':
                    array_push($styles, $this->path_to_url($filename));
                    $css_size = $css_size + $file->getSize();
                    break;
            }

        }

        //ALL scripts
        update_option('wps_ic_found_scripts', array('files' => $scripts, 'size' => $js_size, 'count' => count($scripts)));
        update_option('wps_ic_found_styles', array('files' => $styles, 'size' => $css_size, 'count' => count($styles)));

        wp_remote_get(get_home_url() . "/pricing?action=get_enqueued&apikey=$this->apikey");
        wp_remote_get(get_home_url() . "/pricing?action=get_registered&apikey=$this->apikey");

    }

    /**
     * Get all registered scripts for the homepage (all pages probably)
     */
    public function get_registered()
    {

        add_action('wp_footer',
            function () {
                global $wp_scripts;
                $scripts = array();
                foreach ($wp_scripts->registered as $script) {
                    //check if is url
                    $check = filter_var($script->src, FILTER_SANITIZE_URL);
                    if ($check) {
                        $url = $script->src;
                        $path = '';
                    } else {
                        //else is local
                        $url = get_home_url() . '/' . $script->src;
                        $path = $script->src;
                    }

                    $scripts += [$script->handle => ['src' => $url, 'path' => $path]];
                }
                update_option('wps_ic_registered_scripts', array('files' => $scripts, 'count' => count($scripts)));
            }
        , PHP_INT_MAX);

        add_action('wp_footer',
            function () {
                global $wp_styles;
                $styles = array();
                foreach ($wp_styles->registered as $style) {

                    //check if is url
                    $check = filter_var($style->src, FILTER_SANITIZE_URL);
                    if ($check) {
                        $url = $style->src;
                        $path = '';
                    } else {
                        //else is local
                        $url = get_home_url() . '/' . $style->src;
                        $path = $style->src;
                    }

                    $styles += [$style->handle => ['src' => $url, 'path' => $path]];
                }
                update_option('wps_ic_registered_styles', array('files' => $styles, 'count' => count($styles)));
            }
        , PHP_INT_MAX);

    }

    /**
     * Get enqueued scripts for the homepage head
     */
    public function get_enqueued()
    {

        add_action('wp_head',
            function () {
                global $wp_scripts;
                $scripts = array();
                foreach ($wp_scripts->groups as $handle => $group) {

                    if ( $group > 0 || $wp_scripts->registered[$handle]->src === false){
                        continue;
                    }

                    //check if is url
                    $check = wp_http_validate_url($wp_scripts->registered[$handle]->src);
                    if ($check || strpos($wp_scripts->registered[$handle]->src, '//') === 0) {
                        $url = $wp_scripts->registered[$handle]->src;
                        $path = '';
                    } else {
                        $url = get_home_url() . $wp_scripts->registered[$handle]->src;
                        $path = $wp_scripts->registered[$handle]->src;
                    }

                    $before = $wp_scripts->registered[$handle]->extra['before'][1];
                    $data = $wp_scripts->registered[$handle]->extra['data'];
                    $after = $wp_scripts->registered[$handle]->extra['after'][1];
                    $deps = $wp_scripts->registered[$handle]->deps;

                    $scripts += [$handle => ['src' => $url, 'path' => $path, 'before' => $before, 'after' => $after, 'data' => $data, 'deps' => $deps]];
                }

                update_option('wps_ic_enqueued_scripts_head', $scripts);
            }
        , PHP_INT_MAX);

        add_action('wp_footer',
            function () {
                global $wp_scripts;
                $scripts = array();
                foreach ($wp_scripts->groups as $handle => $group) {

                    if ( $group == 0 || $wp_scripts->registered[$handle]->src === false){
                        continue;
                    }

                    //check if is url
                    $check = wp_http_validate_url($wp_scripts->registered[$handle]->src);
                    if ($check || strpos($wp_scripts->registered[$handle]->src, '//') === 0) {
                        $url = $wp_scripts->registered[$handle]->src;
                        $path = '';
                    } else {
                        $url = get_home_url() . $wp_scripts->registered[$handle]->src;
                        $path = $wp_scripts->registered[$handle]->src;
                    }

                    $before = $wp_scripts->registered[$handle]->extra['before'][1];
                    $data = $wp_scripts->registered[$handle]->extra['data'];
                    $after = $wp_scripts->registered[$handle]->extra['after'][1];
                    $deps = $wp_scripts->registered[$handle]->deps;

                    $scripts += [$handle => ['src' => $url, 'path' => $path, 'before' => $before, 'after' => $after, 'data' => $data, 'deps' => $deps]];
                }
                update_option('wps_ic_global_scripts', $wp_scripts);
                update_option('wps_ic_enqueued_scripts_footer', $scripts);
            }
            , PHP_INT_MAX);

        add_action('wp_footer',
            function () {
                global $wp_styles;
                $styles = array();
                foreach ($wp_styles->queue as $handle) {

                    if ($wp_styles->registered[$handle]->src === false){
                        continue;
                    }

                    //check if is url
                    $check = wp_http_validate_url($wp_styles->registered[$handle]->src);
                    if ($check || strpos($wp_styles->registered[$handle]->src, '//') === 0) {
                        $url = $wp_styles->registered[$handle]->src;
                        $path = '';
                    } else {
                        //else is local
                        $url = get_home_url()  . $wp_styles->registered[$handle]->src;
                        $path = $wp_styles->registered[$handle]->src;
                    }

                    $before = $wp_styles->registered[$handle]->extra['before'];
                    $data = $wp_styles->registered[$handle]->extra['data'];
                    $after = $wp_styles->registered[$handle]->extra['after'];
                    $deps = $wp_styles->registered[$handle]->deps;

                    $styles += [$handle => ['src' => $url, 'path' => $path, 'before' => $before, 'after' => $after, 'data' => $data, 'deps' => $deps]];
                }
                update_option('wps_ic_global_styles', $wp_styles);
                update_option('wps_ic_enqueued_styles', $styles );
            }
        , PHP_INT_MAX);

    }

    public function get_js()
    {

        $js = get_option('wps_ic_found_scripts');

        if ($js === false) {
            wp_send_json_error('Option not fund.');
        }
        wp_send_json_success($js);

    }

    public function get_css()
    {
        $css = get_option('wps_ic_enqueued_styles');

        if ($css === false) {
            wp_send_json_error('Option not fund.');
        }
        wp_send_json_success($css);
    }

    public function get_enqueued_js()
    {

        $head = get_option('wps_ic_enqueued_scripts_head');
        $footer = get_option('wps_ic_enqueued_scripts_footer');

        if ($head === false || $footer === false) {
            wp_send_json_error('Option not fund.');
        }

        echo '<h1>HEAD</h1><br>';
        foreach ($head as $script=>$data){
            echo $script.'<br>';
            var_dump($data);
            echo '<br>';
        }

         echo '<h1>FOOTER</h1><br>';
        foreach ($footer as $script=>$data){
            echo $script.'<br>';
            var_dump($data);
            echo '<br>';
        }

        die();

    }

    public function get_enqueued_css()
    {
        $css = get_option('wps_ic_enqueued_styles');

        if ($css === false) {
            wp_send_json_error('Option not fund.');
        }

        foreach ($css as $script=>$data){
            echo $script.'<br>';
            var_dump($data);
            echo '<br>';
        }
        die();

    }

    public function get_registered_js()
    {

        $js = get_option('wps_ic_registered_scripts');

        if ($js === false) {
            wp_send_json_error('Option not fund.');
        }
        wp_send_json_success($js);

    }

    public function get_registered_css()
    {
        $css = get_option('wps_ic_registered_styles');

        if ($css === false) {
            wp_send_json_error('Option not fund.');
        }
        wp_send_json_success($css);
    }

    public function get_global_css(){
        $css = get_option('wps_ic_global_styles');

        if ($css === false) {
            wp_send_json_error('Option not fund.');
        }
        wp_send_json_success($css);
    }

    public function get_global_js(){
        $js = get_option('wps_ic_global_scripts');

        if ($js === false) {
            wp_send_json_error('Option not fund.');
        }
        wp_send_json_success($js);
    }

    public function path_to_url($path)
    {
        return get_site_url() . '/' . str_replace(ABSPATH, '', $path);
    }


}