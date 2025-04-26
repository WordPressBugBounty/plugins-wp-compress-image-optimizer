<?php


/**
 * Class - Options
 */
class wps_ic_options
{

    public static $options;
    public static $recommendedSettings;
    public static $aggressiveSettings;
    public static $liteSettings;
    public static $safeSettings;
    public static $preloadSettings;
    public $purgeList;

		public static $purgeRules;

    public function __construct()
    {

        //Format of this list is the same as settings list, just instead of setting value, put ['critical' , 'combine'] to set what files will be purged. Cache is always purged
        $this->purgeList = [
            'live-cdn' => ['critical'],
            'serve' => [
                'jpg' => ['critical'],
                'png' => ['critical'],
                'gif' => ['critical'],
                'svg' => ['critical'],
            ],
            'fonts' => ['critical'],
            'critical' => ['css' => ['critical']],
            'background-sizing' => ['critical'],
            'css_minify' => ['combine'],
            'css_combine' => ['combine'],
            'js_combine' => ['combine'],
            'js_minify' => ['combine'],
            'delay-js' => ['combine'],
            'font-subsetting' => ['cdn','critical'],
            'imagesPreset' => ['cdn','critical'],
            'cdnAll' => ['cdn','critical'],
        ];

        $this::$recommendedSettings = [
            'imagesPreset' => '1',
            'cdnAll' => '1',
            'live-cdn' => '1',
            'serve' => [
                'jpg' => '1',
                'png' => '1',
                'gif' => '1',
                'svg' => '1',
            ],
            'css' => 1,
            'js' => 0,
            'fonts' => 1,
            'generate_adaptive' => 1,
            'generate_webp' => 1,
            'retina' => 1,
            'retina-in-srcset' => 1,
            'lazy' => 1,
            'nativeLazy' => 1,
            'remove-srcset' => 0,
            'background-sizing' => 0,
            'qualityLevel' => 2,
            'optimization' => 'intelligent',
            'on-upload' => 0,
            'emoji-remove' => 1,
            'disable-oembeds' => 0,
            'disable-dashicons' => 0,
            'disable-gutenberg' => 0,
            'external-url' => 0,
            'disable-cart-fragments' => 0,
            'iframe-lazy' => 1,
            'gtag-lazy' => 1,
            'fontawesome-lazy' => 1,
            'critical' => ['css' => 1],
            'css_minify' => 0,
            'css_combine' => 0,
            'inline-css' => 0,
            'js_combine' => 0,
            'js_minify' => 0,
            'js_defer' => 0,
            'delay-js' => 1,
            'font-subsetting' => 0,
            'scripts-to-footer' => 0,
            'inline-js' => 0,
            'cache' => [ 'advanced'              => 0,
                         'mobile'                => 0,
                         'minify'                => 0,
                         'expire'                => 0,
                         'ignore-server-control' => 0,
                         'cache-logged-in'       => 0,
                         'headers'               => 0,
                         'purge-hooks'           => 1
            ],
            'local' => ['media-library' => 0],
            'status' => [
                'hide_in_admin_bar' => '0',
                'hide_cache_status' => '0',
                'hide_critical_css_status' => '0',
                'hide_preload_status' => '0'
            ],
            'lazySkipCount' => '4',
            'disable-trigger-dom-event' => '0',
            'hide_compress' => '0',
            'preload-scripts' => '1',
            'fetchpriority-high' => '1',
            'preload-crit-fonts' => '0',
            'htaccess-webp-replace' => '0',
            'disable-logged-in-opt' => '0'
        ];

        $this::$safeSettings = [
            'imagesPreset' => '0',
            'cdnAll' => '0',
            'live-cdn' => '0',
            'serve' => [
                'jpg' => '0',
                'png' => '0',
                'gif' => '0',
                'svg' => '0',
            ],
            'css' => '0',
            'js' => '0',
            'fonts' => '0',
            'generate_adaptive' => '0',
            'generate_webp' => '0',
            'retina' => '0',
            'retina-in-srcset' => '0',
            'lazy' => '0',
            'remove-srcset' => '0',
            'background-sizing' => '0',
            'qualityLevel' => '1',
            'optimization' => 'lossless',
            'on-upload' => '0',
            'emoji-remove' => '0',
            'disable-oembeds' => '0',
            'disable-dashicons' => '0',
            'disable-gutenberg' => '0',
            'external-url' => '0',
            'disable-cart-fragments' => '0',
            'gtag-lazy' => 0,
            'fontawesome-lazy' => 0,
            'iframe-lazy' => '0',
            'critical' => ['css' => '0'],
            'css_minify' => '0',
            'css_combine' => '0',
            'inline-css' => '0',
            'js_combine' => '0',
            'js_minify' => '0',
            'js_defer' => '0',
            'delay-js' => '0',
            'font-subsetting' => '0',
            'scripts-to-footer' => '0',
            'inline-js' => '0',
            'lazySkipCount' => '4',
            'disable-trigger-dom-event' => '0',
            'cache' => [ 'advanced'              => 0,
                         'mobile'                => 0,
                         'minify'                => 0,
                         'expire'                => 0,
                         'ignore-server-control' => 0,
                         'cache-logged-in'       => 0,
                         'headers'               => 0,
                         'purge-hooks'           => 1
            ],
            'local' => ['media-library' => '0'],
            'status' => [
                'hide_in_admin_bar' => '0',
                'hide_cache_status' => '0',
                'hide_critical_css_status' => '0',
                'hide_preload_status' => '0'
            ],
            'hide_compress' => '0',
            'preload-crit-fonts' => '0',
            'htaccess-webp-replace' => '0',
            'disable-logged-in-opt' => '0'
        ];

        $this::$liteSettings = [
            'imagesPreset' => '1',
            'cdnAll' => '0',
            'live-cdn' => '0',
            'serve' => [
                'jpg' => '0',
                'png' => '0',
                'gif' => '0',
                'svg' => '0',
            ],
            'css' => '0',
            'js' => '0',
            'fonts' => '0',
            'generate_adaptive' => '1',
            'generate_webp' => '1',
            'retina' => '1',
            'retina-in-srcset' => '0',
            'nativeLazy' => '1',
            'lazy' => '0',
            'remove-srcset' => '0',
            'background-sizing' => '0',
            'qualityLevel' => '1',
            'optimization' => 'lossless',
            'on-upload' => 0,
            'emoji-remove' => 1,
            'disable-oembeds' => 0,
            'disable-dashicons' => 1,
            'disable-gutenberg' => 0,
            'external-url' => 0,
            'disable-cart-fragments' => 1,
            'iframe-lazy' => 1,
            'gtag-lazy' => 1,
            'fontawesome-lazy' => 1,
            'critical' => ['css' => '1'],
            'css_minify' => '0',
            'css_combine' => '0',
            'inline-css' => '0',
            'js_combine' => '0',
            'js_minify' => '0',
            'js_defer' => '0',
            'delay-js' => '1',
            'font-subsetting' => '0',
            'scripts-to-footer' => '0',
            'inline-js' => '0',
            'cache' => [ 'advanced'              => '1',
                         'mobile'                => '1',
                         'minify'                => '0',
                         'expire'                => 24,
                         'ignore-server-control' => '1',
                         'cache-logged-in'       => '1',
                         'headers'               => 0,
                         'purge-hooks'           => 1
            ],
            'local' => ['media-library' => '0'],
            'status' => [
                'hide_in_admin_bar' => '0',
                'hide_cache_status' => '0',
                'hide_critical_css_status' => '0',
                'hide_preload_status' => '0'
            ],
            'lazySkipCount' => '4',
            'disable-trigger-dom-event' => '0',
            'hide_compress' => '0',
            'preload-scripts' => '1',
            'fetchpriority-high' => '1',
            'preload-crit-fonts' => '0',
            'htaccess-webp-replace' => '0',
            'disable-logged-in-opt' => '0'
        ];

        $this::$aggressiveSettings = [
            'imagesPreset' => '1',
            'cdnAll' => '1',
            'live-cdn' => '1',
            'serve' => [
                'jpg' => '1',
                'png' => '1',
                'gif' => '1',
                'svg' => '1',
                'css' => '1',
                'js' => '1',
                'fonts' => '1'
            ],
            'css' => 1,
            'js' => 1,
            'fonts' => 1,
            'generate_adaptive' => 1,
            'generate_webp' => 1,
            'retina' => 1,
            'retina-in-srcset' => 1,
            'lazy' => 1,
            'nativeLazy' => 1,
            'remove-srcset' => 0,
            'background-sizing' => 1,
            'qualityLevel' => 2,
            'optimization' => 'intelligent',
            'on-upload' => 0,
            'emoji-remove' => 1,
            'disable-oembeds' => 0,
            'disable-dashicons' => 1,
            'disable-gutenberg' => 0,
            'external-url' => 0,
            'disable-cart-fragments' => 1,
            'iframe-lazy' => 1,
            'gtag-lazy' => 1,
            'fontawesome-lazy' => 1,
            'critical' => ['css' => 1],
            'css_minify' => 0,
            'css_combine' => 0,
            'inline-css' => 0,
            'js_combine' => 0,
            'js_minify' => 0,
            'js_defer' => 0,
            'delay-js' => 1,
            'font-subsetting' => 0,
            'scripts-to-footer' => 0,
            'inline-js' => 0,
            'lazySkipCount' => '4',
            'disable-trigger-dom-event' => '0',
            'cache' => [ 'advanced'              => 1,
                         'mobile'                => 1,
                         'minify'                => 0,
                         'expire'                => 24,
                         'ignore-server-control' => 1,
                         'cache-logged-in'       => 1,
                         'purge-hooks'           => 1
            ],
            'local' => ['media-library' => 0],
            'status' => [
                'hide_in_admin_bar' => '0',
                'hide_cache_status' => '0',
                'hide_critical_css_status' => '0',
                'hide_preload_status' => '0'
            ],
            'hide_compress' => '0',
            'preload-scripts' => '1',
            'fetchpriority-high' => '1',
            'preload-crit-fonts' => '0',
            'htaccess-webp-replace' => '0',
            'disable-logged-in-opt' => '0'
        ];

				$this::$purgeRules = ['post-publish' => ['all-pages' => 0,
				                                         'home-page' => 1,
				                                         'recent-posts-widget' => 1,
				                                         'archive-pages' => 1],
				                      'hooks' => ['switch_theme',
				                                  'add_link',
				                                  'edit_link',
				                                  'delete_link',
				                                  'update_option_sidebars_widgets',
				                                  'update_option_category_base',
				                                  'update_option_tag_base',
				                                  'wp_update_nav_menu',
				                                  'permalink_structure_changed',
				                                  'customize_save',
				                                  'update_option_theme_mods_' . get_option( 'stylesheet', '')]
				];

        return $this;
    }


    public function get_preset($preset)
    {
        $settings = '';

        switch ($preset) {
            case 'lite':
                $settings = self::$liteSettings;
                break;
            case 'recommended':
                $settings = self::$recommendedSettings;
                break;
            case 'safe':
                $settings = self::$safeSettings;
                break;
            case 'aggressive':
                $settings = self::$aggressiveSettings;
                break;
            case 'preload':
                $settings = $this->getPreloadSettings();
                break;
		        case 'purge_rules':
								$settings = self::$purgeRules;
								break;
        }

        return $settings;
    }

    public function getPreloadSettings()
    {
        $settings = get_option('wps_ic_settings');
        $connectivityStatus = get_option('wpc-connectivity-status');

        $preloadSettings = $settings;
        $preloadSettings['critical']['css'] = 1;
        $preloadSettings['css_combine'] = 0;
        $preloadSettings['inline-css'] = 0;
        $preloadSettings['delay-js'] = 0;
        $preloadSettings['inline-js'] = 0;

        if (!empty($connectivityStatus) && $connectivityStatus == 'failed') {
            $preloadSettings['critical']['css'] = 0;
        }

        return $preloadSettings;
    }

    public function setMissingSettings($settings)
    {
        foreach ($this::$recommendedSettings as $option_key => $option_value) {
            if (is_array($option_value)) {
                foreach ($option_value as $sub_key => $sub_value) {
                    if (!isset($settings[$option_key][$sub_key])) {
                        $settings[$option_key][$sub_key] = '0';
                    }
                }
            } else {
                if (!isset($settings[$option_key]) && $option_key != 'disable-elementor-triggers') {
                    $settings[$option_key] = '0';
                }
            }
        }

        return $settings;
    }


    public function getPurgeList($settings)
    {
        $currentSettings = get_option(WPS_IC_SETTINGS);
        $whatToPurge = [];
        foreach ($settings as $option_key => $option_value) {
            if (is_array($option_value)) {
                foreach ($option_value as $sub_key => $sub_value) {
                    // Check if the current setting exists and has changed
                    if (isset($currentSettings[$option_key][$sub_key]) && $currentSettings[$option_key][$sub_key] != $sub_value) {
                        // Check if the change is relevant for purging
                        if (isset($this->purgeList[$option_key][$sub_key])) {
                            $whatToPurge = array_merge($whatToPurge, $this->purgeList[$option_key][$sub_key]);
                        }
                    }
                }
            } else {
                // For non-array options, check if the setting has changed and is relevant for purging
                if (isset($currentSettings[$option_key]) && $currentSettings[$option_key] != $option_value && isset($this->purgeList[$option_key])) {
                    $whatToPurge = array_merge($whatToPurge, $this->purgeList[$option_key]);
                }
            }
        }
        return $whatToPurge;
    }


    /**
     * Save settings
     */
    public function save_settings()
    {
        if (!empty($_POST)) {
            $options = get_option(WPS_IC_SETTINGS);
            $_POST['wp-ic-setting']['unlocks'] = $options['unlocks'];

            if (empty($_POST['wp-ic-setting']['optimization']) || $_POST['wp-ic-setting']['optimization'] == '0') {
                $_POST['wp-ic-setting']['optimization'] = 'maximum';
            }

            if (empty($_POST['wp-ic-setting']['optimize_upload'])) {
                $_POST['wp-ic-setting']['optimize_upload'] = '0';
            }

            if (empty($_POST['wp-ic-setting']['ignore_larger_images'])) {
                $_POST['wp-ic-setting']['ignore_larger_images'] = '0';
            }

            if (empty($_POST['wp-ic-setting']['resize_larger_images'])) {
                $_POST['wp-ic-setting']['resize_larger_images'] = '0';
            }

            if (empty($_POST['wp-ic-setting']['resize_larger_images_width'])) {
                $_POST['wp-ic-setting']['resize_larger_images_width'] = '2048';
            }

            if (empty($_POST['wp-ic-setting']['ignore_larger_images_width'])) {
                $_POST['wp-ic-setting']['ignore_larger_images_width'] = '2048';
            }

            if (empty($_POST['wps_no']['time'])) {
                $_POST['wp-ic-setting']['wps_no']['time'] = '';
            }

            if (empty($_POST['wp-ic-setting']['backup'])) {
                $_POST['wp-ic-setting']['backup'] = '0';
            }

            if (empty($_POST['wp-ic-setting']['hide_compress'])) {
                $_POST['wp-ic-setting']['hide_compress'] = '0';
            }

            if (empty($_POST['wp-ic-setting']['thumbnails_locally'])) {
                $_POST['wp-ic-setting']['thumbnails_locally'] = '0';
            }

            if (empty($_POST['wp-ic-setting']['debug'])) {
                $_POST['wp-ic-setting']['debug'] = '0';
            }

            if (empty($_POST['wp-ic-setting']['preserve_exif'])) {
                $_POST['wp-ic-setting']['preserve_exif'] = '0';
            }

            if (empty($_POST['wp-ic-setting']['night_owl'])) {
                $_POST['wp-ic-setting']['night_owl'] = '0';
            }

            if (empty($_POST['wp-ic-setting']['otto'])) {
                $_POST['wp-ic-setting']['otto'] = 'off';
            }

            if (empty($_POST['wp-ic-setting']['night_owl_upload'])) {
                $_POST['wp-ic-setting']['night_owl_upload'] = '0';
            }

            if (!empty($_POST['wp-ic-setting']['thumbnails'])) {
                foreach ($_POST['wp-ic-setting']['thumbnails'] as $key => $value) {
                    $_POST['wp-ic-setting']['thumbnails'][$key] = 1;
                }
            }

            // Sanitize
            foreach ($_POST['wp-ic-setting'] as $key => $value) {
                $_POST['wp-ic-setting'][$key] = $value;
            }

            update_option(WPS_IC_SETTINGS, $_POST['wp-ic-setting']);
        }
    }


    /**
     * Get compress stats (total images, total saved)
     * @return mixed|void
     */
    public function get_stats()
    {
        global $wpdb;

        $query = $wpdb->prepare("SELECT COUNT(ID) as images, SUM(saved) as saved FROM " . $wpdb->prefix . "ic_compressed ORDER by ID");
        $query = $wpdb->get_results($query);

        return ['images' => $query[0]->images, 'saved' => $query[0]->saved];
    }


    /**
     * Update stats
     */
    public function update_stats($attachment_ID = 1, $saved = '', $action = 'add')
    {
        global $wpdb;

        $attachment_ID = (int)$attachment_ID;
        $saved = sanitize_text_field($saved);

        if ($action == 'add') {
            $query = $wpdb->prepare("INSERT INTO " . $wpdb->prefix . "ic_compressed (created, attachment_ID, saved, count) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE created=%s, count=count+1, restored=0", current_time('mysql'), $attachment_ID, $saved, current_time('mysql'), '1');
            $wpdb->query($query);
        } else {
            //
        }
    }


    /**
     * Get various settings for WP Compress
     * @return mixed|void
     */
    public function get_settings()
    {
        $settings = get_option(WPS_IC_SETTINGS);

        if (!$settings) {
            $this->set_recommended_options();
            $settings = get_option(WPS_IC_SETTINGS);
        }

        return $settings;
    }


    /**
     * Set recommended options
     */
    public function set_recommended_options()
    {
        update_option(WPS_IC_SETTINGS, self::$recommendedSettings);
    }


    /**
     * Fetch specific option or all options if key is empty
     *
     * @param null $key
     *
     * @return bool|mixed|void
     */
    public function get_option($key = null)
    {
        $options = get_option(WPS_IC_OPTIONS);

        if ($key == null) {
            if (empty($options)) {
                return false;
            }

            return $options;
        } else {
            if (empty($options[$key])) {
                return false;
            }

            return $options[$key];
        }
    }


    /**
     * Set option with key and value
     *
     * @param $key
     * @param $value
     */
    public function set_option($key, $value)
    {
        $options = get_option(WPS_IC_OPTIONS);
        $options[$key] = $value;
        update_option(WPS_IC_OPTIONS, $options);
    }

    /**
     * Setup default settings
     */
    public function set_defaults()
    {
        $this->set_recommended_options();
    }

    public function getDefault()
    {
        return self::$recommendedSettings;
    }


    public function getSafe()
    {
        return self::$safeSettings;
    }

}