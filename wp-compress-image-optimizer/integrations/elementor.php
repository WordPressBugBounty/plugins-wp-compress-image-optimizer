<?php

class wps_ic_elementor
{

    public $delayActive;

    public function __construct($delayActive = '')
    {
        if (!empty($delayActive)) {
            $this->delayActive = $delayActive;
        } else {
            $this->delayActive = false;
        }
    }

    public function is_active()
    {
        return defined('ELEMENTOR_VERSION');
    }

    public function do_checks()
    {

    }

    public function fix_setting($setting)
    {

    }

    public function add_admin_hooks()
    {
        return [
            'elementor/core/files/clear_cache' => [
                'callback' => 'clear_cache',
                'priority' => 10,
                'args' => 1
            ],
            'elementor/maintenance_mode/mode_changed' => [
                'callback' => 'clear_cache',
                'priority' => 10,
                'args' => 1
            ],
            'update_option__elementor_global_css' => [
                'callback' => 'clear_cache',
                'priority' => 10,
                'args' => 1
            ],
            'delete_option__elementor_global_css' => [
                'callback' => 'clear_cache',
                'priority' => 10,
                'args' => 1
            ]
        ];
    }

    public function clear_cache()
    {
        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll();
    }

    public function runIntegration($html)
    {
        if ($this->delayActive) {
            $html = $this->hideSections($html);
        }
        $html = $this->delayBackgrounds($html);

        if (str_contains($html, 'elementor/optimize.js') === false) {
            $html = str_replace('optimize.js', 'elementor/optimize.js', $html);
        }

        return $html;
    }

    public function hideSections($html)
    {
        $count = 0;
        $html = preg_replace_callback(
            '/(<section[^>]*class="[^"]*?)elementor-top-section([^"]*")/i',
            function ($matches) use (&$count) {
                $count++;
                if ($count > 5) {
                    return $matches[1] . 'elementor-top-section wpc-delay-elementor' . $matches[2];
                } else {
                    return $matches[0];
                }
            },
            $html
        );
        $html = str_replace('</head>', '<style>.wpc-delay-elementor{display:none!important;}</style></head>', $html);

        $html = preg_replace(
            '/(<footer[^>]*class="[^"]*)"/i',
            '$1 wpc-delay-elementor"',
            $html
        );

        // Handle <footer> elements without a class attribute
        $html = preg_replace(
            '/(<footer)(?![^>]*class="[^"]*")/i',
            '$1 class="wpc-delay-elementor"',
            $html
        );

        $html = str_replace('</head>', '<style>.wpc-delay-elementor{display:none!important;}</style></head>', $html);


        return $html;
    }

    public function delayBackgrounds($html)
    {
        $html = preg_replace('/class="([^"]*?)elementor-background-overlay([^"]*?)"/i', 'class="wpc-delay-elementor $1elementor-background-overlay$2"', $html);

        return $html;
    }

    public function insertJS($html)
    {
        $js_file_path = WPS_IC_DIR . 'integrations/js/elementor.js';

        if (file_exists($js_file_path)) {
            $js_content = file_get_contents($js_file_path);
            $script_tag = "<script type='text/javascript'>\n" . $js_content . "\n</script>\n</head>";
            $html = str_replace('</head>', $script_tag, $html);
        }
        return $html;
    }
}