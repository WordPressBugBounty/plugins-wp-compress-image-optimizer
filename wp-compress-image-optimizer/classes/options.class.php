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
    public static $cacheCookies;

    public function __construct()
    {

        //Format of this list is the same as settings list, just instead of setting value, put ['critical' , 'combine'] to set what files will be purged. Cache is always purged
        $this->purgeList = [
            'live-cdn' => ['critical', 'html'],
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
            'imagesPreset' => ['cdn','critical', 'html'],
            'cdnAll' => ['cdn','critical', 'html'],
            // Image-delivery settings that change HTML output.
            // When any of these toggle, cached pages contain stale picture
            // wraps / srcset / fetchpriority / lazy attributes, so we must purge
            // HTML cache so the next render rebuilds with the new rules.
            'fetchpriority-high' => ['html'],
            'lazySkipCount'      => ['html'],
            'lazy-load'          => ['html'],
            'native-lazy'        => ['html'],
            'picture_webp'       => ['html'],
            'picture_avif'       => ['html'],
            'retina-in-srcset'   => ['html'],
            'avif-natural-source' => ['html'],
            'single-url-image-format' => ['html'], // Regime B: flipping it rewrites every single-URL image href
            'optimize-lcp'       => ['html'],
            'lazy-auto-sizes'    => ['html'],
            'adaptive'           => ['html'],
            'add-image-sizes'    => ['html'],
            'generate_webp'      => ['html'],
            'generate_adaptive'  => ['html'],
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
            'picture_webp' => 1,
            'picture_avif' => 1,
            'retina' => 1,
            'retina-in-srcset' => 1,
            // Natural-AVIF picture sources (default ON). When on, the avif
            // <source> emits natural -WxH.avif URLs (edge serves avif / self-heals)
            // instead of wp:2 transforms that serve webp. Safe everywhere: Bunny
            // auto-upgrades; Cloudflare is gated on wpc_v2_cf_avif_live (set when the
            // edge's .avif live-transform is live) so it can't pin a webp interim.
            'avif-natural-source' => 1,
            'single-url-image-format' => 'auto', // Regime B default
            'lazy' => 0,
            'nativeLazy' => 1,
            'remove-srcset' => 0,
            'background-sizing' => 0,
            // Pixel-optimal delivery is the default experience in
            // Recommended/Aggressive (user direction, final testing): the LCP
            // sizes ladder feeds demand-width targeting end to end.
            'optimize-lcp' => '1',
            'qualityLevel' => 2,
            'optimization' => 'intelligent',
            'on-upload' => 1,
            'emoji-remove' => 0,
            'remove-duplicated-fontawesome' => 0,
            'disable-oembeds' => 0,
            'disable-dashicons' => 0,
            'disable-gutenberg' => 0,
            'external-url' => 0,
            'disable-cart-fragments' => 0,
            'iframe-lazy' => 1,
            'video-preload-none' => 0,
            'emit-src-hints' => '1', // Source Hints baked ON: edge skips the origin format-probe (no storm). Opt-out via the toggle.
            'emit-src-hints-always' => '0', // (v7.03.61) ?src mode: 0=until-landed (self-healing, default), 1=always (keep hint after on-disk).
            'gtag-lazy' => 1,
            'fontawesome-lazy' => 1,
            'icon-font-display' => 'block',
            'critical' => ['css' => 1],
            'css_minify' => 0,
            'css_combine' => 0,
            'inline-css' => 0,
            'js_combine' => 0,
            'js_minify' => 0,
            'js_defer' => 0,
            'delay-js' => 0,
            'delay-js-v2' => 1,
            'font-subsetting' => 0,
            'scripts-to-footer' => 0,
            'inline-js' => 0,
            'cache' => [ 'advanced'              => 1,
                'mobile'                => 0,
                'minify'                => 0,
                'expire'                => 0,
                'ignore-server-control' => 1,
                'cache-logged-in'       => 0,
                'headers'               => 0,
                'purge-hooks'           => 1
            ],
            'local' => ['media-library' => 0],
            'status' => [
                'hide_in_admin_bar' => '0',
                'show_admin_bar_title' => '0',
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
            'disable-logged-in-opt' => '0',
            'eu-routing' => '0',
            // Local optimization defaults (Smart Optimization preset)
            'picture_avif' => 1,
            'backup' => 'local',
            'maxWidth' => '2560',
            'local_qualityLevel' => '0',
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
            'generate_webp' => '1',
            'picture_webp' => '1',
            'picture_avif' => '1',
            'retina' => '0',
            'retina-in-srcset' => '0',
            // Safe mode is the CONSERVATIVE preset: both HTML-output-altering image
            // toggles ship OFF. 'avif-natural-source' => 0 keeps AVIF <source>s on the never-404
            // wp:2 transform form (not natural -WxH URLs) in Safe mode; 'fetchpriority-high' => 0
            // was ABSENT from this preset, so the toggle kept its prior ON value on a Safe switch.
            // Both were reported staying ON after switching to Safe.
            'avif-natural-source' => '0',
            'single-url-image-format' => 'same-ext', // Safe forces the single-URL floor (consistent with avif-natural-source off)
            'fetchpriority-high' => '0',
            'lazy' => '0',
            'remove-srcset' => '0',
            'background-sizing' => '0',
            'optimize-lcp' => '0', // BETA: device-independent LCP srcset, available since 7.00.08
            'modern_image_delivery' => '0', // BETA: native <picture> + srcset, JS-free, available since 7.01.0
            'modern_delivery_prefer_local' => '0', // when 1, srcset uses origin URL for variants confirmed local on disk; otherwise CDN. Off by default.
            'qualityLevel' => '1',
            'optimization' => 'lossless',
            'on-upload' => '1',
            'emoji-remove' => '0',
            'remove-duplicated-fontawesome' => 0,
            'disable-oembeds' => '0',
            'disable-dashicons' => '0',
            'disable-gutenberg' => '0',
            'external-url' => '0',
            'disable-cart-fragments' => '0',
            'gtag-lazy' => 0,
            'fontawesome-lazy' => 0,
            'icon-font-display' => 'block',
            'iframe-lazy' => '0',
            'video-preload-none' => 0,
            'critical' => ['css' => '0'],
            'css_minify' => '0',
            'css_combine' => '0',
            'inline-css' => '0',
            'js_combine' => '0',
            'js_minify' => '0',
            'js_defer' => '0',
            'delay-js' => '0',
            'delay-js-v2' => '0',
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
                'purge-hooks'           => 1,
                'cookies'               => 0
            ],
            'local' => ['media-library' => '0'],
            'status' => [
                'hide_in_admin_bar' => '0',
                'show_admin_bar_title' => '0',
                'hide_cache_status' => '0',
                'hide_critical_css_status' => '0',
                'hide_preload_status' => '0'
            ],
            'hide_compress' => '0',
            'preload-crit-fonts' => '0',
            'htaccess-webp-replace' => '0',
            'disable-logged-in-opt' => '0',
            'eu-routing' => '0',
            // Local optimization defaults (Smart Optimization preset)
            'backup' => 'local',
            'maxWidth' => '2560',
            'local_qualityLevel' => '0',
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
            'picture_webp' => '1',
            'picture_avif' => '1',
            'retina' => '1',
            'retina-in-srcset' => '0',
            'avif-natural-source' => 1,
            'single-url-image-format' => 'auto', // Regime B default
            'nativeLazy' => '1',
            'lazy' => '0',
            'remove-srcset' => '0',
            'background-sizing' => '0',
            'optimize-lcp' => '0', // BETA: device-independent LCP srcset, available since 7.00.08
            'modern_image_delivery' => '0', // BETA: native <picture> + srcset, JS-free, available since 7.01.0
            'modern_delivery_prefer_local' => '0', // when 1, srcset uses origin URL for variants confirmed local on disk; otherwise CDN. Off by default.
            'qualityLevel' => '1',
            'optimization' => 'lossless',
            'on-upload' => 1,
            'emoji-remove' => 0,
            'remove-duplicated-fontawesome' => 0,
            'disable-oembeds' => 0,
            'disable-dashicons' => 0,
            'disable-gutenberg' => 0,
            'external-url' => 0,
            'disable-cart-fragments' => 1,
            'iframe-lazy' => 1,
            'video-preload-none' => 0,
            'emit-src-hints' => '1', // Source Hints baked ON: edge skips the origin format-probe (no storm). Opt-out via the toggle.
            'emit-src-hints-always' => '0', // (v7.03.61) ?src mode: 0=until-landed (self-healing, default), 1=always (keep hint after on-disk).
            'gtag-lazy' => 1,
            'fontawesome-lazy' => 1,
            'icon-font-display' => 'block',
            'critical' => ['css' => '1'],
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
            'cache' => [ 'advanced'              => '1',
                'mobile'                => '1',
                'minify'                => '0',
                'expire'                => 24,
                'ignore-server-control' => '1',
                'cache-logged-in'       => '1',
                'headers'               => 0,
                'purge-hooks'           => 1,
                'cookies'               => 1
            ],
            'local' => ['media-library' => '0'],
            'status' => [
                'hide_in_admin_bar' => '0',
                'show_admin_bar_title' => '0',
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
            'disable-logged-in-opt' => '0',
            'eu-routing' => '0',
            // Local optimization defaults (Smart Optimization preset)
            'picture_avif' => 1,
            'backup' => 'local',
            'maxWidth' => '2560',
            'local_qualityLevel' => '0',
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
            'picture_webp' => 1,
            'picture_avif' => 1,
            'retina' => 1,
            'retina-in-srcset' => 1,
            'avif-natural-source' => 1,
            'single-url-image-format' => 'auto', // Regime B default
            'lazy' => 0,
            'nativeLazy' => 1,
            'remove-srcset' => 0,
            'background-sizing' => 1,
            'optimize-lcp' => '1', // see recommended preset note
            'qualityLevel' => 2,
            'optimization' => 'intelligent',
            'on-upload' => 1,
            'emoji-remove' => 0,
            'remove-duplicated-fontawesome' => 0,
            'disable-oembeds' => 0,
            'disable-dashicons' => 0,
            'disable-gutenberg' => 0,
            'external-url' => 0,
            'disable-cart-fragments' => 1,
            'iframe-lazy' => 1,
            'video-preload-none' => 0,
            'emit-src-hints' => '1', // Source Hints baked ON: edge skips the origin format-probe (no storm). Opt-out via the toggle.
            'emit-src-hints-always' => '0', // (v7.03.61) ?src mode: 0=until-landed (self-healing, default), 1=always (keep hint after on-disk).
            'gtag-lazy' => 1,
            'fontawesome-lazy' => 1,
            'icon-font-display' => 'block',
            'critical' => ['css' => 1],
            'css_minify' => 0,
            'css_combine' => 0,
            'inline-css' => 0,
            'js_combine' => 0,
            'js_minify' => 0,
            'js_defer' => 0,
            'delay-js' => 0,
            'delay-js-v2' => 1,
            'font-subsetting' => 0,
            'scripts-to-footer' => 0,
            'inline-js' => 0,
            'lazySkipCount' => '4',
            'disable-trigger-dom-event' => '0',
            'cache' => [ 'advanced'              => 1,
                'mobile'                => 0,
                'minify'                => 0,
                'expire'                => 0,
                'ignore-server-control' => 1,
                'cache-logged-in'       => 0,
                'headers'               => 0,
                'purge-hooks'           => 1,
                'cookies'               => 1
            ],
            'local' => ['media-library' => 0],
            'status' => [
                'hide_in_admin_bar' => '0',
                'show_admin_bar_title' => '0',
                'hide_cache_status' => '0',
                'hide_critical_css_status' => '0',
                'hide_preload_status' => '0'
            ],
            'hide_compress' => '0',
            'preload-scripts' => '1',
            'fetchpriority-high' => '1',
            'preload-crit-fonts'    => '0',
            'htaccess-webp-replace' => '0',
            'disable-logged-in-opt' => '0',
            'eu-routing' => '0',
            // Local optimization defaults (Smart Optimization preset)
            'picture_avif' => 1,
            'backup' => 'local',
            'maxWidth' => '2560',
            'local_qualityLevel' => '0',
        ];

        $this::$purgeRules = [
            'post-publish' => [
                'all-pages'           => 0,
                'home-page'           => 1,
                'recent-posts-widget' => 1,
                'archive-pages'       => 1
            ],
            'hooks'        => [
                'switch_theme',
                'add_link',
                'edit_link',
                'delete_link',
                'update_option_sidebars_widgets',
                'update_option_category_base',
                'update_option_tag_base',
                'wp_update_nav_menu',
                'permalink_structure_changed',
                'customize_save',
                'update_option_theme_mods_' . get_option('stylesheet', ''),
                'elementor/core/files/clear_cache',
                'uagb_delete_uag_asset_dir',
				'uagb_delete_page_assets',
                'et_core_static_resources_removed',
                'fl_builder_cache_cleared',
                'bricks/settings/after_save'
            ]
        ];

        $this::$cacheCookies = [
            'cookies' => [
                'cookie_notice_accepted',
                'allowed_cookies',
                'consent_types',
                'catAccCookies',
                'aelia_cs_recalculate_cart_totals',
                'aelia_cs_selected_currency',
                'aelia_customer_country',
                'aelia_customer_state',
                'aelia_tax_exempt',
                'wcml_client_currency',
                'wcml_client_currency_language',
                'wcml_client_country',
                'geot_rocket_',
                'pll_language'
            ],
            'exclude_cookies' => []
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
            case 'cache_cookies':
                $settings = self::$cacheCookies;
                break;
        }

        return $settings;
    }

    public function getPreloadSettings()
    {
        $settings = get_option('wps_ic_settings');

        $preloadSettings = $settings;
        $preloadSettings['critical']['css'] = 1;
        $preloadSettings['css_combine'] = 0;
        $preloadSettings['inline-css'] = 0;
        $preloadSettings['delay-js'] = 0;
        $preloadSettings['delay-js-v2'] = 0;
        $preloadSettings['inline-js'] = 0;

        // (v7.03.31) Removed the wpc-connectivity-status == 'failed' -> critical['css'] = 0 gate. It disabled
        // critical CSS based on the WARMUP box's reachability, but crit now runs on crit-push.zapwp.net (a
        // different host that self-checks). Wrong-vantage + unreliable (the probe had no timeout) -> keep crit on.

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