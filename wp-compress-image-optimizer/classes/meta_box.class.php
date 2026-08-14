<?php

class wps_ic_meta_box
{

    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'wpc_add_meta_box']);
        add_action('save_post', [$this, 'save_meta_box_data'], 16, 2);
    }

    public function wpc_add_meta_box()
    {
        add_meta_box(
            'wpc_page_settings_meta_box',
            'WP Compress',
            [$this, 'meta_box_html'],
            ['page', 'post', 'product'],
            'side',
            'high'
        );
    }

    public function meta_box_html($post)
    {
        
        wp_nonce_field('wpc_meta_box', 'wpc_meta_box_settings');

        $preload_warmup = new wps_ic_preload_warmup();
        $page = $preload_warmup->getOptimizationsStatus('', '', '', '', '', $post->ID);

        $locked = [];
        $locked['cdn'] = true;
        $locked['advanced_cache'] = true;
        $locked['adaptive'] = true;
        $locked['critical_css'] = true;
        $locked['delay_js'] = true;

        $settings = get_option(WPS_IC_SETTINGS);
        $globalSettings = [
            'cdn' => $settings['live-cdn'],
            'adaptive' => $settings['generate_adaptive'],
            'advanced_cache' => $settings['cache']['advanced'],
            'critical_css' => $settings['critical']['css'],
            'delay_js' => $settings['delay-js']
        ];

        
        echo '<div style="padding: 20px;">';
        foreach ($globalSettings as $settingName => $globalSetting) {
            if (is_array($globalSetting)) {
                foreach ($globalSetting as $subSettingName => $subSettingValue) {
                    echo $this->createDropdown($page[0], $subSettingName, $subSettingValue, $locked[$subSettingName]);
                }
            } else {
                echo $this->createDropdown($page[0], $settingName, $globalSetting, $locked[$settingName]);
            }
        }
        echo '</div>';
    }

    public function isFeatureEnabled($featureName)
    {
        
        if (function_exists('wpc_caps_enabled')) {
            return wpc_caps_enabled($featureName);
        }
        $feature = get_transient($featureName . 'Enabled');
        return !(!$feature || $feature == '0');
    }

    private function createDropdown($page, $settingName, $globalSetting, $locked)
    {
        $disabled = $locked ? 'disabled' : '';

        
        $html = "<div style='margin-bottom: 10px;'>";
        $html .= "<label for='{$settingName}'>{$settingName}: </label>";
        $html .= "<select id='{$settingName}' name='{$settingName}' {$disabled} style='";
        if ($locked) {
            $html .= "background-color: #e9ecef;";  
        }
        $html .= "'>";
        $html .= "<option value='force_on'" . ((isset($page[$settingName]) && $page[$settingName] === '1') ? " selected" :
                "") . ">Force On</option>";
        $html .= "<option value='force_off'" . ((isset($page[$settingName]) && $page[$settingName] === '0') ? " selected" :
                "") . ">Force Off</option>";
        $html .= "<option value='global'" . (!isset($page[$settingName]) ? " selected" : "") . ">Global</option>";
        $html .= "</select>";
        $html .= "</div>";

        return $html;
    }


    public function save_meta_box_data($post_id, $post)
    {
        
        if (!isset($_POST['wpc_meta_box_settings']) || !wp_verify_nonce($_POST['wpc_meta_box_settings'], 'wpc_meta_box')) {
            return;
        }

        
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) {
            return;
        }

        
        $settings = ['cdn', 'adaptive', 'advanced_cache', 'critical_css', 'delay_js']; 

        
        $wpc_excludes = get_option('wpc-excludes', []);
        if (!isset($wpc_excludes['page_excludes'])) {
            $wpc_excludes['page_excludes'] = [];
        }


        
        if (!isset($wpc_excludes['page_excludes'][$post_id])) {
            $wpc_excludes['page_excludes'][$post_id] = [];
        }

        $changed = false;

        foreach ($settings as $setting_name) {
            if (isset($_POST[$setting_name])) {
                $setting_action = $_POST[$setting_name];
                $current_value = $wpc_excludes['page_excludes'][$post_id][$setting_name] ?? null;

                if (($setting_action === 'force_on' && $current_value !== '1') ||
                    ($setting_action === 'force_off' && $current_value !== '0') ||
                    ($setting_action === 'global' && $current_value !== null)) {
                    if ($setting_action === 'global') {
                        unset($wpc_excludes['page_excludes'][$post_id][$setting_name]);
                    } else {
                        $wpc_excludes['page_excludes'][$post_id][$setting_name] = $setting_action === 'force_on' ? '1' : '0';
                    }
                    $changed = true;
                }
            }
        }

        if ($changed) {
            update_option('wpc-excludes', $wpc_excludes);

            
            $keys = new wps_ic_url_key();
            $url_key = ($post_id == 'home') ? $keys->setup(home_url()) : $keys->setup(get_permalink($post_id));
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll($url_key);

            
            if (in_array($setting_name, ['combine_js', 'css_combine', 'delay_js', 'critical_css'])) {
                $cache::purgeCombinedFiles($url_key);
                if ($setting_name == 'critical_css') {
                    $cache::purgeCriticalFiles($url_key);
                }
            }
        }
    }


}