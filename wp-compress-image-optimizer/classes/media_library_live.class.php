<?php

/**
 * Class - Media Library
 */
class wps_ic_media_library_live extends wps_ic
{

    public static $slug;
    public static $logo_compressed;
    public static $logo_uncompressed;
    public static $logo_excluded;
    public static $load_spinner;
    public static $allowed_types;

    public static $allow_local;
    public static $exclude_list;
    public static $settings;
    public static $options;
    public static $parent;
    public static $accountStatus;
    public static $parsedImages;


    public function __construct()
    {

        self::$slug = parent::$slug;
        self::$settings = parent::$settings;
        self::$options = parent::$options;
        self::$exclude_list = get_option('wps_ic_exclude_list');
        self::$allow_local = $this->get_local_status();

        if (!empty($_GET['regen'])) {
            if (!function_exists('download_url')) {
                require_once(ABSPATH . "wp-admin" . '/includes/image.php');
                require_once(ABSPATH . "wp-admin" . '/includes/file.php');
                require_once(ABSPATH . "wp-admin" . '/includes/media.php');
            }

            if (!function_exists('update_option')) {
                require_once(ABSPATH . "wp-includes" . '/option.php');
            }

            $path_to_image = get_attached_file($_GET['regen']);
            if (!empty($path_to_image)) {
                $imageID = $_GET['regen'];
                $meta = wp_generate_attachment_metadata($_GET['regen'], $path_to_image);
                wp_update_attachment_metadata($_GET['regen'], $meta);

                // Remove meta tags
                delete_post_meta($imageID, 'ic_stats');
                delete_post_meta($imageID, 'ic_status');
                delete_post_meta($imageID, 'ic_bulk_running');
                //
                delete_post_meta($imageID, 'ic_compressed_images');
                delete_post_meta($imageID, 'ic_compressed_thumbs');
                delete_post_meta($imageID, 'ic_backup_images');

            }
        }

        if (empty(self::$exclude_list)) {
            self::$exclude_list = [];
        }

        self::$parsedImages = get_option('wps_ic_parsed_images');
        self::$load_spinner = WPS_IC_URI . 'assets/images/legacy/spinner.svg';
        self::$logo_compressed = WPS_IC_URI . 'assets/images/legacy/logo-compressed.svg';
        self::$logo_uncompressed = WPS_IC_URI . 'assets/images/legacy/logo-not-compressed.svg';
        self::$logo_excluded = WPS_IC_URI . 'assets/images/legacy/logo-excluded.svg';
        self::$allowed_types = ['jpg' => 'jpg', 'jpeg' => 'jpeg', 'gif' => 'gif', 'png' => 'png'];


        // Decouple the Optimization column + per-image actions from the
        // "Show in Media Library" (settings['local']['media-library']) display toggle. Optimizing is
        // a core capability: you should always be able to optimize/restore an image from the media
        // library regardless of a display preference, and enable it right there. So the column +
        // actions register whenever WPC isn't hard-hidden (the hide_compress kill-switch below),
        // not only when the display toggle is on. Filterable escape hatch (wpc_media_library_optimize)
        // for any managed site that genuinely needs to suppress it. The media-library display toggle
        // continues to govern only the other WPC admin UI registered elsewhere.
        if (apply_filters('wpc_media_library_optimize', true)) {
            if (empty(self::$options['hide_compress']) || self::$options['hide_compress'] == '') {

                // Per-image Exclude/Include row action — registered INSIDE the same gate as the column (the
                // wpc_media_library_optimize filter + hide_compress kill-switch) instead of unconditionally in
                // the constructor, so the kill-switches govern it consistently. It stays decoupled from the
                // "Show in Media Library" display toggle by design: excluding an image from optimization is a
                // core capability, not a display preference, so gating it by that toggle would remove
                // deliberate functionality (it's suppressible via the filter/kill-switch above when needed).
                add_filter('media_row_actions', [$this, 'add_exclude_link'], 10, 2);

                // WP Custom Fields
                #add_action('attachment_submitbox_misc_actions', array($this, 'wps_custom_media_fields'), PHP_INT_MAX);

                // WP Media MetaBox
                add_action('add_meta_boxes_attachment', [$this, 'wpc_custom_media_metabox']);


                // Register new columns
                add_filter('manage_media_columns', [$this, 'wps_compress_column']);
                add_action('manage_media_custom_column', [$this, 'wps_compress_column_value'], 10, 2);
                add_action('admin_footer', [$this, 'popups']);
                add_filter('wps_ic_debug_log_link', [$this, 'debug_log_link'], 10, 1);
                add_action('pre_get_posts', [$this, 'do_wps_ic_filters']);
                add_filter('attachment_fields_to_edit', [$this, 'add_grid_view_fields'], 10, 2);
                global $pagenow;
                if ($pagenow !== 'upload.php') {
                    return;
                }
                add_action('restrict_manage_posts', [$this, 'add_wps_ic_filters']);
                wp_enqueue_script('wps-ic-filters', WPS_IC_URI . '/assets/js/admin/media-filters.min.js', ['media-editor', 'media-views']);
                wp_localize_script('wps-ic-filters', 'WpsIcFilters', ['filters' => $this->get_filters(), 'filter_all' => __('Optimization Filters', WPS_IC_TEXTDOMAIN)]);
                add_filter('ajax_query_attachments_args', [$this, 'do_wps_ic_ajax_filters']);

                // Bulk actions disabled — queue processor not yet implemented
                // $this->add_bulk_actions_list();
                add_action('admin_notices', [$this, 'custom_bulk_admin_notices']);
            } else {
                add_action('pre_current_active_plugins', [$this, 'wps_ic_hide_compress_plugin_list']);
            }
        }
    }

    /**
     * Is local enabled?
     * TODO: Maybe remove
     * @return int|mixed
     */
    public function get_local_status()
    {
        if (empty(self::$options['api_key'])) {
            return 0;
        }

        $allow_local = get_transient('ic_allow_local');
        if (!empty($allow_local) || $allow_local == 0) {
            return $allow_local;
        }

        $call = wp_remote_get(WPS_IC_KEYSURL . '?action=get_credits&apikey=' . self::$options['api_key'] . '&v=2&hash=' . md5(mt_rand(999, 9999)), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($call) == 200) {
            $body = wp_remote_retrieve_body($call);
            $body = json_decode($body);
            $body = $body->data;

            if (!empty($body)) {
                update_option('wps_ic_allow_local', $body->agency->allow_local);
                set_transient('ic_allow_local', $body->agency->allow_local, 60 * 30);

                return $body->agency->allow_local;
            } else {
                return 0;
            }

        } else {
            return 0;
        }
    }

    private function get_filters()
    {
        return ['uncompressed' => 'Uncompressed', 'compressed' => 'Compressed',//'in_queue' => 'In Queue'
        ];
    }

	public static function popups()
	{
		$support_url = function_exists('wpc_get_whitelabel_url') ? wpc_get_whitelabel_url() : 'https://www.wpcompress.com/';
		$pricing_url = function_exists('wpc_get_whitelabel_url') ? wpc_get_whitelabel_url('https://www.wpcompress.com/pricing') : 'https://www.wpcompress.com/pricing';

		$warn_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
		$info_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';

		$popup = function($id, $icon, $title, $desc = '', $btn_label = '', $btn_url = '') {
			$html = '<div id="' . esc_attr($id) . '" style="display:none;"><div class="wpc-error-popup"><div class="wpc-error-popup-icon">' . $icon . '</div>';
			$html .= '<h3>' . $title . '</h3>';
			if ($desc) $html .= '<p>' . $desc . '</p>';
			if ($btn_label) $html .= '<a href="' . esc_url($btn_url) . '" target="_blank" class="wpc-error-popup-btn">' . esc_html($btn_label) . '</a>';
			$html .= '</div></div>';
			echo $html;
		};

		$popup('no-credits-popup', $warn_icon,
			esc_html__('No quota remaining', WPS_IC_TEXTDOMAIN),
			esc_html__('Your account has exhausted all credits and has reverted to Local mode.', WPS_IC_TEXTDOMAIN),
			esc_html__('Get Credits', WPS_IC_TEXTDOMAIN), $pricing_url);

		$popup('file-already-compressed', $info_icon,
			esc_html__('Already compressed', WPS_IC_TEXTDOMAIN),
			esc_html__('This image has already been optimized. Restore it first to re-compress.', WPS_IC_TEXTDOMAIN));

		$popup('file-not-supported', $info_icon,
			esc_html__('File type not supported', WPS_IC_TEXTDOMAIN),
			esc_html__('Only JPEG, PNG, and GIF images can be optimized.', WPS_IC_TEXTDOMAIN));

		$popup('file-in-bulk', $info_icon,
			esc_html__('Already queued', WPS_IC_TEXTDOMAIN),
			esc_html__('This image is already in the bulk optimization queue.', WPS_IC_TEXTDOMAIN));

		$popup('unable-to-contact-api', $warn_icon,
			esc_html__('Unable to reach the optimization service', WPS_IC_TEXTDOMAIN),
			esc_html__('The request timed out. Please try again in a moment.', WPS_IC_TEXTDOMAIN),
			esc_html__('Contact Support', WPS_IC_TEXTDOMAIN), $support_url);

		$popup('failed-to-get-backup', $warn_icon,
			esc_html__('Backup not found', WPS_IC_TEXTDOMAIN),
			esc_html__('We couldn\'t retrieve the backup file for this image.', WPS_IC_TEXTDOMAIN),
			esc_html__('Contact Support', WPS_IC_TEXTDOMAIN), $support_url);

		$popup('missing-apikey', $warn_icon,
			esc_html__('API key missing', WPS_IC_TEXTDOMAIN),
			esc_html__('Your API key could not be retrieved. Please reconnect the plugin.', WPS_IC_TEXTDOMAIN),
			esc_html__('Contact Support', WPS_IC_TEXTDOMAIN), $support_url);

		$popup('empty-site-url', $warn_icon,
			esc_html__('Site URL missing', WPS_IC_TEXTDOMAIN),
			esc_html__('The API could not determine your site URL. Please check your WordPress settings.', WPS_IC_TEXTDOMAIN),
			esc_html__('Contact Support', WPS_IC_TEXTDOMAIN), $support_url);

		$popup('apikey-not-matching', $warn_icon,
			esc_html__('API key mismatch', WPS_IC_TEXTDOMAIN),
			esc_html__('Your API key doesn\'t match. Please reconnect the plugin or contact support.', WPS_IC_TEXTDOMAIN),
			esc_html__('Contact Support', WPS_IC_TEXTDOMAIN), $support_url);


		echo '<div id="api-blocked-by-firewall" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>Our API was blocked by some type of firewall!</h3>
          <a href="' . $support_url . '" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
        </div>

      </div>
    </div>';

		echo '<div id="api-unable-to-download" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>Our API was unable to download the image!</h3>
          <a href="' . $support_url . '" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
        </div>

      </div>
    </div>';

		echo '<div id="internal-api-issue" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>Our API Experienced an Internal Issue!</h3>
          <a href="' . $support_url . '" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
        </div>

      </div>
    </div>';

		echo '<div id="failure-to-contact-api" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>Your site was unable to contact our API!</h3>
          <a href="' . $support_url . '" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
        </div>

      </div>
    </div>';
	}

    public function wpc_custom_media_metabox()
    {
        add_meta_box('wpc_media_metabox', 'Compression Details', [$this, 'mediaMetabox'], 'attachment', 'side', 'high');
    }


    public function mediaMetabox()
    {
        $post = get_post();
        $attachment_id = $post->ID;
        $stats = get_post_meta($attachment_id, 'ic_stats', true);

        if (!$stats) {
            $output = '<strong>Not yet compressed.</strong>';
        } else {
            $totalThumbs = count($stats);

            $totalOriginal = 0;
            $totalCompressed = 0;
            foreach ($stats as $size => $data) {
                $totalOriginal += $data['original']['size'];
                $totalCompressed += $data['compressed']['size'];
            }

            $output = '<div class="misc-pub-section misc-pub-dimensions" style="padding:0;">';
            $output .= '<ul>';

            $output .= '<li>Total Thumbnails:';
            $output .= '<strong><span id="media-dims-52"> ' . $totalThumbs . '</span> </strong>';
            $output .= '</li>';

            $output .= '<li>';
            $output .= 'Total Original:';
            $output .= '<strong><span id="media-dims-52"> ' . wps_ic_format_bytes($totalOriginal) . '</span> </strong>';
            $output .= '</li>';

            $output .= '<li>';
            $output .= 'Total Compressed:';
            $output .= '<strong><span id="media-dims-52"> ' . wps_ic_format_bytes($totalCompressed) . '</span> </strong>';
            $output .= '</li>';

            $output .= '<li>';
            $output .= 'Total Saved:';
            $output .= '<strong><span id="media-dims-52"> ' . wps_ic_format_bytes(($totalOriginal - $totalCompressed)) . '</span> </strong>';
            $output .= '</li>';

            $output .= '</ul>';
            $output .= '</div>';
        }

        echo $output;
    }


    public function wps_custom_media_fields()
    {
        $post = get_post();
        $attachment_id = $post->ID;

        $stats = get_post_meta($attachment_id, 'ic_stats', true);

        $totalThumbs = count($stats);

        $plugin_name = function_exists('wpc_get_plugin_name') ? wpc_get_plugin_name() : __('WP Compress', WPS_IC_TEXTDOMAIN);
        $output = '<h4 style="margin:10px 0px 10px 10px;">' . esc_html($plugin_name . ' ' . __('Stats', WPS_IC_TEXTDOMAIN)) . '</h4>';
        $output .= '<div class="misc-pub-section misc-pub-dimensions">Total thumbnails:';
        $output .= '<strong><span id="media-dims-52">' . $totalThumbs . '</span> </strong>';
        $output .= '</div>';

        #echo $output;
    }

    public function add_bulk_actions_list()
    {
        if (isset($_GET['wps-ic-filters'])) {
            $filter = sanitize_title(wp_unslash($_GET['wps-ic-filters']));
        } else {
            $filter = '';
        }

        //Uncompressed view
        if ($filter == 'uncompressed' || $filter == 'all' || !isset($_GET['wps-ic-filters'])) {
            add_filter('bulk_actions-upload', function ($bulk_actions) {
                $bulk_actions['wps_ic_compress_in_background'] = __('Compress Images', 'wp-compress-image-optimizer');
                return $bulk_actions;
            });
            add_filter('handle_bulk_actions-upload', [$this, 'start_bulk_in_background'], 10, 3);
        }

        //Queue view
        if ($filter == 'in_queue' || $filter == 'all' || !isset($_GET['wps-ic-filters'])) {
            add_filter('bulk_actions-upload', function ($bulk_actions) {
                $bulk_actions['wps_ic_remove_from_queue'] = __('Remove from Queue', 'wp-compress-image-optimizer');
                return $bulk_actions;
            });
            add_filter('handle_bulk_actions-upload', [$this, 'remove_from_queue'], 10, 3);
        }

    }

    public function remove_from_queue($redirect_url, $action, $post_ids)
    {
        if ($action == 'wps_ic_remove_from_queue') {

            $removed_images = 0;
            $queue = get_option('wps-ic-background-compress-queue');

            foreach ($post_ids as $imageID) {
                if (isset($queue[$imageID])) {
                    unset($queue[$imageID]);
                    $removed_images++;
                }
                delete_post_meta($imageID, 'ic_status');
            }

            $redirect_url = add_query_arg(['wps-ic-action' => 'removed_from_queue', 'wps-ic-count' => $removed_images], $redirect_url);
            update_option('wps-ic-background-compress-queue', $queue);
        }
        return $redirect_url;
    }

    public function start_bulk_in_background($redirect_url, $action, $post_ids)
    {
        if ($action == 'wps_ic_compress_in_background') {

            $added_images = 0;
            $queue = get_option('wps-ic-background-compress-queue');

            foreach ($post_ids as $imageID) {
                if (!isset($queue[$imageID])) {
                    $queue[$imageID] = 'in_queue';
                    $added_images++;
                }
                update_post_meta($imageID, 'ic_status', 'in_queue');
            }

            $redirect_url = add_query_arg(['wps-ic-action' => 'added_to_queue', 'wps-ic-count' => $added_images], $redirect_url);
            update_option('wps-ic-background-compress-queue', $queue);
        }
        return $redirect_url;
    }

    /**
     * Hook to add our filters in list view
     * @return void
     */
    public function add_wps_ic_filters()
    {
        if (isset($_GET['wps-ic-filters'])) {
            $filter = sanitize_title(wp_unslash($_GET['wps-ic-filters']));
        } else {
            $filter = '';
        }
        ?>
        <select id="wps-ic-filters" name="wps-ic-filters" class="attachment-filters">
            <option value="all"><?php echo esc_html__('Optimization Filters', WPS_IC_TEXTDOMAIN); ?></option>
            <?php foreach ($this->get_filters() as $key => $value) { ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($filter, $key); ?>>
                    <?php echo esc_html($value); ?>
                </option>
            <?php } ?>
        </select>
        <?php
    }

    /**
     * Filter attachments in list view
     * @param \WP_Query $query The wp_query instance.
     */
    public function do_wps_ic_filters($query)
    {
        if (!isset($_GET['wps-ic-filters'])) {
            return $query;
        }

        $filter = $_GET['wps-ic-filters'];
        if (!$filter) {
            return $query;
        }

        $supported_mimes = ['image/jpeg', 'image/png', 'image/gif'];

        switch ($filter) {
            case 'uncompressed':
                $query->set('post_mime_type', $supported_mimes);
                $query->set('meta_query', ['relation' => 'OR', ['key' => 'ic_status', 'value' => 'restored', 'compare' => '='], ['key' => 'ic_status', 'compare' => 'Not Exists'],]);
                break;

            case 'compressed':
                $query->set('meta_key', 'ic_stats');
                $query->set('meta_compare', 'EXISTS');
                break;

            case 'in_queue':
                $query->set('meta_key', 'ic_stats');
                $query->set('meta_compare', 'NOT EXISTS');

                $query->set('meta_key', 'ic_status');
                $query->set('meta_value', 'in_queue');
                $query->set('meta_compare', '=');
                break;
        }

        return $query;
    }

    /**
     * Apply our filters to grid view ajax query
     * @param array $query Query parameters.
     * @return array        New query parameters.
     */
    public function do_wps_ic_ajax_filters($query)
    {
        if (empty($_POST['query']['wps_ic_filters_ajax'])) {
            return $query;
        }

        $filter = sanitize_title(wp_unslash($_POST['query']['wps_ic_filters_ajax']));
        switch ($filter) {
            case 'uncompressed':
                $query['post_mime_type'] = ['image/jpeg', 'image/png', 'image/gif'];
                if (!isset($query['meta_query'])) {
                    $query['meta_query'] = [];
                }
                $query['meta_query'][] = ['key' => 'ic_stats', 'compare' => 'NOT EXISTS',];
                $query['meta_query'][] = ['key' => 'ic_status', 'compare' => 'NOT EXISTS',];
                break;

            case 'compressed':
                if (!isset($query['meta_query'])) {
                    $query['meta_query'] = [];
                }
                $query['meta_query'][] = ['key' => 'ic_stats', 'compare' => 'EXISTS',];
                break;

            case 'in_queue':
                if (!isset($query['meta_query'])) {
                    $query['meta_query'] = [];
                }
                $query['meta_query'][] = ['key' => 'ic_stats', 'compare' => 'NOT EXISTS',];
                $query['meta_query'][] = ['key' => 'ic_status', 'compare' => '=', 'value' => 'in_queue'];
                break;
        }

        return $query;
    }

    public function debug_log_link($args)
    {

        if (!defined('WPS_IC_DEBUG') || (defined('WPS_IC_DEBUG') && WPS_IC_DEBUG == 'false')) {
            return '';
        }

        return '<a href="' . admin_url('/options-general.php?page=' . $this::$slug . '&view=debug_tool&debug_img=' . $args) . '" target="_blank" class="wpc-dropdown-btn wps-ic-debug-log wpc-dropdown-item-hidden">Debug Log</a>';
    }

    /**
     * Remove plugin from list if it's hidden
     * @return void
     */
    public function wps_ic_hide_compress_plugin_list()
    {
        global $wp_list_table;
        $hidearr = ['wp-compress-image-optimizer/wp-compress.php'];
        $myplugins = $wp_list_table->items;
        foreach ($myplugins as $key => $val) {
            if (in_array($key, $hidearr)) {
                unset($wp_list_table->items[$key]);
            }
        }
    }


    /**
     * Hide the plugin
     * @return void
     */
    public function wps_ic_hide_compress()
    {
        echo '<script type="text/javascript">';
        echo 'jQuery(document).ready(function($){';
        echo '$("tr[data-slug=\'wp-compress-image-optimizer\']").hide();';
        echo '$("#wp-compress-image-optimizer-update").hide();';
        echo '});';
        echo '</script>';
    }


    public function wps_compress_column($cols)
    {
        $cols['wps_ic_actions'] = __('Optimization', 'wp-compress-image-optimizer');
        return $cols;
    }

    /**
     * Add compression status field to grid view attachment modal.
     */
    public function add_grid_view_fields($form_fields, $post)
    {
        $type = wp_check_filetype(get_attached_file($post->ID));
        if (empty($type['ext']) || !in_array(strtolower($type['ext']), self::$allowed_types)) {
            return $form_fields;
        }

        $plugin_name = function_exists('wpc_get_plugin_name') ? wpc_get_plugin_name() : __('WP Compress', 'wp-compress-image-optimizer');
        $stats = get_post_meta($post->ID, 'ic_stats', true);
        $status = get_post_meta($post->ID, 'ic_status', true);
        $excluded = !empty(self::$exclude_list) && in_array($post->ID, self::$exclude_list);

        if ($excluded) {
            $html = '<span style="color:#94a3b8;">' . esc_html__('Excluded', 'wp-compress-image-optimizer') . '</span>';
        } elseif ((!empty($status) && $status === 'compressed') || !empty($stats)) {
            $ic_savings  = get_post_meta($post->ID, 'ic_savings', true);
            $ic_base     = get_post_meta($post->ID, 'ic_savings_baseline', true);
            $savings_pct = 0;

            if (!empty($ic_savings) && !empty($ic_base) && $ic_base > 0) {
                $savings_pct = floatval($ic_savings);
            } elseif (!empty($stats['original']['original']['size']) && !empty($stats['original']['compressed']['size'])) {
                $orig = $stats['original']['original']['size'];
                $comp = $stats['original']['compressed']['size'];
                if ($orig > 0 && $comp > 0 && $orig != $comp) {
                    $savings_pct = round((1 - ($comp / $orig)) * 100, 1);
                }
            }

            $html = '<span style="color:#22b73a;font-weight:600;">' . esc_html__('Compressed', 'wp-compress-image-optimizer') . '</span>';
            if ($savings_pct > 0) {
                $html .= ' <span style="color:#64748b;">(' . number_format($savings_pct, 1) . '% ' . esc_html__('savings', 'wp-compress-image-optimizer') . ')</span>';
            }
        } else {
            $html = '<span style="color:#94a3b8;">' . esc_html__('Not Compressed', 'wp-compress-image-optimizer') . '</span>';
        }

        $form_fields['wps_ic_status'] = [
            'label' => esc_html($plugin_name),
            'input' => 'html',
            'html'  => $html,
        ];

        return $form_fields;
    }


    public function wps_compress_column_value($column_name, $id)
    {
        global $wps_ic;

        if ($column_name != 'wps_ic_actions') {
            return true;
        }

        $output = '';
        $file_data = get_attached_file($id);
        $type = wp_check_filetype($file_data);

        // Is file extension allowed
        if (!in_array(strtolower($type['ext']), self::$allowed_types)) {

            /**
             * Extensions is NOT allowed
             */

            if ($column_name == 'wps_ic_all') {


            } else if ($column_name == 'wps_ic_actions') {
                // (v7.03.114) Render the unsupported-format state as a proper card, reusing the EXCLUDED
                // card's exact styling so it's visually consistent with the rest of the column (was a bare
                // legacy "Not supported" string that looked out of place). JPEG/PNG/GIF are the optimizable
                // SOURCE formats; a webp/avif/svg source shows this. (Such images are still delivered
                // next-gen via the CDN where applicable — this only refers to local source optimization.)
                $output .= '<div class="wpc-ml-card wpc-ml-card--excluded is-excluded">';
                $output .= self::icon_stack();
                $output .= '<div class="wpc-ml-body">';
                // (v7.10.04.7) A next-gen SOURCE (webp/avif) can't be re-optimized locally — name it
                // explicitly ("Already WebP/AVIF Image") instead of a generic "Unsupported Format".
                $wpc_src_ext = strtolower((string) $type['ext']);
                if ($wpc_src_ext === '') $wpc_src_ext = strtolower((string) pathinfo((string) $file_data, PATHINFO_EXTENSION));
                if ($wpc_src_ext === 'webp') {
                    $wpc_ml_title = __('Already WebP', 'wp-compress-image-optimizer');
                    $wpc_ml_sub   = __('Unsupported format', 'wp-compress-image-optimizer');
                } elseif ($wpc_src_ext === 'avif') {
                    $wpc_ml_title = __('Already AVIF', 'wp-compress-image-optimizer');
                    $wpc_ml_sub   = __('Unsupported format', 'wp-compress-image-optimizer');
                } else {
                    $wpc_ml_title = __('Unsupported Format', 'wp-compress-image-optimizer');
                    $wpc_ml_sub   = __('JPEG, PNG & GIF only', 'wp-compress-image-optimizer'); // literal & — esc_html encodes once
                }
                $output .= '<div class="wpc-ml-title">' . esc_html($wpc_ml_title) . '</div>';
                $output .= '<div class="wpc-ml-subtitle">' . esc_html($wpc_ml_sub) . '</div>';
                $output .= '</div>';
                $output .= '</div>';
            }

            echo $output;
        } else {
            if (in_array($id, self::$exclude_list)) {
                // Excluded
                $output .= '<div class="wps-ic-media-actions-container wps-ic-media-actions-' . $id . '">';
                $output .= $this->excluded_details($id);
                $output .= '</div>';
            } else {

                #$compressing = get_transient('wps_ic_compress_' . $id);

                $output .= '<div class="wps-ic-media-actions-container wps-ic-media-actions-' . $id . '">';
                $output .= $this->compress_details($id);
                $output .= '</div>';


            }

            $output .= '<div class="wps-ic-image-loading-' . $id . ' wps-ic-image-loading-container" id="wp-ic-image-loading-' . $id . '" style="display:none;"></div>';
            echo $output;

        }
    }


    // ─── Icon stack: idle (with exclude badge), success, engines ────
    private static function icon_stack() {
        $idle = self::icon_idle();
        $success = self::icon_success_check();
        $sparkle_core = '<svg viewBox="0 0 512 512" fill="currentColor"><path d="M278.5 15.6C275 6.2 266 0 256 0s-19 6.2-22.5 15.6L174.2 174.2 15.6 233.5C6.2 237 0 246 0 256s6.2 19 15.6 22.5l158.6 59.4 59.4 158.6C237 505.8 246 512 256 512s19-6.2 22.5-15.6l59.4-158.6 158.6-59.4C505.8 275 512 266 512 256s-6.2-19-15.6-22.5L337.8 174.2 278.5 15.6z"/></svg>';
        $engine = '<div class="wpc-engine wpc-engine-compress"><div class="wpc-comet"><div class="wpc-comet-track"></div><div class="wpc-comet-tail"></div></div><div class="wpc-comet-core">' . $sparkle_core . '</div></div>';
        $engine_restore = '<div class="wpc-engine wpc-engine-restore"><div class="wpc-comet"><div class="wpc-comet-track"></div><div class="wpc-comet-tail"></div></div><div class="wpc-comet-core">' . $sparkle_core . '</div></div>';
        return '<div class="wpc-ml-card-icon">' . $idle . $success . $engine . $engine_restore . '</div>';
    }

    // Idle icon: FA Regular image with exclude badge overlay
    private static function icon_idle() {
        return '<svg class="main-icon icon-idle" viewBox="0 0 448 512" fill="currentColor">'
            . '<path d="M64 80c-8.8 0-16 7.2-16 16l0 320c0 8.8 7.2 16 16 16l320 0c8.8 0 16-7.2 16-16l0-320c0-8.8-7.2-16-16-16L64 80zM0 96C0 60.7 28.7 32 64 32l320 0c35.3 0 64 28.7 64 64l0 320c0 35.3-28.7 64-64 64L64 480c-35.3 0-64-28.7-64-64L0 96zm128 32a32 32 0 1 1 0 64 32 32 0 1 1 0-64zm136 72c8.5 0 16.4 4.5 20.7 11.8l80 136c4.4 7.4 4.4 16.6 .1 24.1S352.6 384 344 384l-240 0c-8.9 0-17.2-5-21.3-12.9s-3.5-17.5 1.6-24.8l56-80c4.5-6.4 11.8-10.2 19.7-10.2s15.2 3.8 19.7 10.2l17.2 24.6 46.5-79c4.3-7.3 12.2-11.8 20.7-11.8z"/>'
            . '<g class="exclude-badge">'
            . '<circle cx="370" cy="430" r="78" fill="#f8fafc" stroke="none"/>'
            . '<circle cx="370" cy="430" r="55" fill="#cbd5e1" stroke="none"/>'
            . '<line x1="348" y1="408" x2="392" y2="452" stroke="#fff" stroke-width="16" stroke-linecap="round"/>'
            . '<line x1="392" y1="408" x2="348" y2="452" stroke="#fff" stroke-width="16" stroke-linecap="round"/>'
            . '</g>'
            . '</svg>';
    }

    // Success icon: FA Duotone Solid sparkles (compressed)
    private static function icon_success_check() {
        return '<svg class="main-icon icon-success" viewBox="0 0 576 512" fill="currentColor"><path opacity=".4" d="M352 448c0 4.8 3 9.1 7.5 10.8L416 480 437.2 536.5c1.7 4.5 6 7.5 10.8 7.5s9.1-3 10.8-7.5L480 480 536.5 458.8c4.5-1.7 7.5-6 7.5-10.8s-3-9.1-7.5-10.8L480 416 458.8 359.5c-1.7-4.5-6-7.5-10.8-7.5s-9.1 3-10.8 7.5L416 416 359.5 437.2c-4.5 1.7-7.5 6-7.5 10.8zM384 64c0 4.8 3 9.1 7.5 10.8L448 96 469.2 152.5c1.7 4.5 6 7.5 10.8 7.5s9.1-3 10.8-7.5L512 96 568.5 74.8c4.5-1.7 7.5-6 7.5-10.8s-3-9.1-7.5-10.8L512 32 490.8-24.5c-1.7-4.5-6-7.5-10.8-7.5s-9.1 3-10.8 7.5L448 32 391.5 53.2c-4.5 1.7-7.5 6-7.5 10.8z"/><path d="M205.1 73.3c-2.6-5.7-8.3-9.3-14.5-9.3s-11.9 3.6-14.5 9.3L123.4 187.4 9.3 240C3.6 242.6 0 248.3 0 254.6s3.6 11.9 9.3 14.5L123.4 321.8 176 435.8c2.6 5.7 8.3 9.3 14.5 9.3s11.9-3.6 14.5-9.3l52.7-114.1 114.1-52.7c5.7-2.6 9.3-8.3 9.3-14.5s-3.6-11.9-9.3-14.5L257.8 187.4 205.1 73.3z"/></svg>';
    }

    // Excluded icon: FA Duotone Solid image-circle-xmark
    private static function icon_excluded_ban() {
        return '<svg class="main-icon icon-excluded" viewBox="0 0 640 512" fill="currentColor"><path opacity=".4" d="M64 96c0-35.3 28.7-64 64-64l320 0c35.3 0 64 28.7 64 64l0 80.7c-5.3-.4-10.6-.7-16-.7-54.8 0-104.3 23-139.3 59.9l-.2-.4c-4.4-7.1-12.1-11.5-20.5-11.5s-16.1 4.4-20.5 11.5L254.1 336 227.7 298.2c-4.5-6.4-11.8-10.2-19.7-10.2s-15.2 3.8-19.7 10.2l-56 80c-5.1 7.3-5.8 16.9-1.6 24.8S143.1 416 152 416l158 0c6 23.3 16.3 45 30 64l-212 0c-35.3 0-64-28.7-64-64L64 96zm80 64a48 48 0 1 0 96 0 48 48 0 1 0 -96 0z"/><path d="M352 368a144 144 0 1 1 288 0 144 144 0 1 1 -288 0zm203.3-59.3c-6.2-6.2-16.4-6.2-22.6 0l-36.7 36.7-36.7-36.7c-6.2-6.2-16.4-6.2-22.6 0s-6.2 16.4 0 22.6l36.7 36.7-36.7 36.7c-6.2 6.2-6.2 16.4 0 22.6s16.4 6.2 22.6 0l36.7-36.7 36.7 36.7c6.2 6.2 16.4 6.2 22.6 0s6.2-16.4 0-22.6l-36.7-36.7 36.7-36.7c6.2-6.2 6.2-16.4 0-22.6z"/></svg>';
    }

    // Inline SVG action icons (12px stroke icons)
    private static function svg_bolt() {
        return '<svg viewBox="0 0 448 512" fill="currentColor"><path d="M341.2-12.1c9.1 6 13 17.3 9.6 27.6L292 192 412.9 192c19.4 0 35.1 15.7 35.1 35.1 0 10-4.2 19.5-11.7 26.1L136 521.9c-8.1 7.3-20.1 8.2-29.2 2.2s-13-17.3-9.6-27.6L156 320 35.1 320C15.7 320 0 304.3 0 284.9 0 275 4.2 265.5 11.7 258.8L312-9.9c8.1-7.3 20.1-8.1 29.2-2.2zM68.9 272l120.4 0c7.7 0 15 3.7 19.5 10s5.7 14.3 3.3 21.6L171.3 425.9 379.1 240 258.7 240c-7.7 0-15-3.7-19.5-10s-5.7-14.3-3.3-21.6L276.7 86.1 68.9 272z"/></svg>';
    }
    private static function svg_x() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
    }
    private static function svg_plus() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
    }
    private static function svg_chart() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>';
    }
    private static function svg_undo() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"></path><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"></path></svg>';
    }

    // ─── FA Sharp Light SVG icons (12px inline) ─────────────────
    private static function icon_brand() {
        if (class_exists('whtlbl_whitelabel_plugin')) {
            return '<svg width="20" height="20" viewBox="0 0 640 512" fill="currentColor"><path d="M528-16l-32 0 0 64-64 0 0 32 64 0 0 64 32 0 0-64 64 0 0-32-64 0 0-64zM288 320c80.6-35.8 128.6-57.2 144-64-15.4-6.8-63.4-28.2-144-64-35.8-80.6-57.2-128.6-64-144-6.8 15.4-28.2 63.4-64 144-80.6 35.8-128.6 57.2-144 64 15.4 6.8 63.4 28.2 144 64 35.8 80.6 57.2 128.6 64 144 6.8-15.4 28.2-63.4 64-144zm-64 65.2l-34.8-78.2-5-11.2-11.2-5-78.2-34.8 78.2-34.8 11.2-5 5-11.2 34.8-78.2 34.8 78.2 5 11.2 11.2 5 78.2 34.8-78.2 34.8-11.2 5-5 11.2-34.8 78.2zM496 384l0-16-32 0 0 64-64 0 0 32 64 0 0 64 32 0 0-64 64 0 0-32-64 0 0-48z"/></svg>';
        }
        return '<svg width="18" height="18" viewBox="0 0 512 512" fill="currentColor"><path d="M322.4 192C358.9 59.4 379.4-15.3 384-32L340.9 3.9 38.4 256 0 288 198.4 288 189.6 320c-36.5 132.6-57 207.3-61.6 224l43.1-35.9 302.5-252.1 38.4-32-198.4 0 8.8-32zm101.2 64L185.9 454.1c34.3-124.6 52.4-190.6 54.5-198.1l-152 0 237.7-198.1C291.8 182.5 273.7 248.5 271.6 256l152 0z"/></svg>';
    }
    // FA Sharp Light Bolt
    private static function icon_compress() {
        return '<svg width="12" height="12" viewBox="0 0 512 512" fill="currentColor"><path d="M322.4 192C358.9 59.4 379.4-15.3 384-32L340.9 3.9 38.4 256 0 288 198.4 288 189.6 320c-36.5 132.6-57 207.3-61.6 224l43.1-35.9 302.5-252.1 38.4-32-198.4 0 8.8-32zm101.2 64L185.9 454.1c34.3-124.6 52.4-190.6 54.5-198.1l-152 0 237.7-198.1C291.8 182.5 273.7 248.5 271.6 256l152 0z"/></svg>';
    }
    // FA Sharp Light Rotate Left
    private static function icon_restore() {
        return '<svg width="12" height="12" viewBox="0 0 512 512" fill="currentColor"><path d="M0-16l0 192 192 0c-17.6-17.6-46.4-46.4-86.2-86.2 87.9-79.6 223.8-77 308.6 7.8 39.9 39.9 61.6 91.2 65.1 143.5l0 0c3.5 51.5-10.7 104.4-44 149-74 99.1-214.4 119.5-313.5 45.5-31.9-23.8-55.6-54.5-70.7-88.4l-29.2 13c17.2 38.8 44.4 73.9 80.8 101 113.3 84.6 273.7 61.3 358.3-51.9 38-50.9 54.3-111.4 50.3-170.2-4-59.7-28.8-118.3-74.4-164-97.3-97.3-253.4-99.9-353.9-7.8L0-16zM32 61.3l82.7 82.7-82.7 0 0-82.7z"/></svg>';
    }
    // FA Sharp Light Ban
    private static function icon_exclude() {
        return '<svg width="12" height="12" viewBox="0 0 512 512" fill="currentColor"><path d="M402.7 425.3l-316-316c-34.1 39.3-54.7 90.6-54.7 146.7 0 123.7 100.3 224 224 224 56.1 0 107.4-20.6 146.7-54.7zm22.6-22.6c34.1-39.3 54.7-90.6 54.7-146.7 0-123.7-100.3-224-224-224-56.1 0-107.4 20.6-146.7 54.7l316 316zM0 256a256 256 0 1 1 512 0 256 256 0 1 1 -512 0z"/></svg>';
    }
    // FA Sharp Light Chart Bar
    private static function icon_stats() {
        return '<svg width="12" height="12" viewBox="0 0 512 512" fill="currentColor"><path d="M32 48l0-16-32 0 0 448 512 0 0-32-480 0 0-400zM368 96l16 0 0-32-256 0 0 32 240 0zM144 192l-16 0 0 32 192 0 0-32-176 0zm0 128l-16 0 0 32 320 0 0-32-304 0z"/></svg>';
    }
    // FA Sharp Light Circle Check
    private static function icon_include() {
        return '<svg width="12" height="12" viewBox="0 0 512 512" fill="currentColor"><path d="M256 512a256 256 0 1 1 0-512 256 256 0 1 1 0 512zm0-480a224 224 0 1 0 0 448 224 224 0 1 0 0-448zM374.3 172.5l-9.4 12.9-128 176-11 15.2-88.6-88.6 22.6-22.6 62.1 62.1 117-160.8 9.4-12.9 25.9 18.8z"/></svg>';
    }

    public function excluded_details($imageID)
    {
        $filedata = get_attached_file($imageID);
        $originalFilepath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : false;
        $scaled_size = ($filedata && file_exists($filedata)) ? filesize($filedata) : 0;
        $orig_size = ($originalFilepath && file_exists($originalFilepath)) ? filesize($originalFilepath) : 0;
        $filesize = wps_ic_format_bytes(max($scaled_size, $orig_size), null, null, false);

        $output = '<div class="wpc-ml-card wpc-ml-card--excluded is-excluded">';
        $output .= self::icon_stack();
        $output .= '<div class="wpc-ml-body">';
        $output .= '<div class="wpc-ml-title">' . esc_html__('Excluded', 'wp-compress-image-optimizer') . '</div>';
        $output .= '<div class="wpc-ml-subtitle">' . $filesize . '<a class="wpc-ml-action wps-ic-exclude-live" data-action="include" data-attachment_id="' . $imageID . '">' . self::svg_plus() . ' ' . esc_html__('Include', 'wp-compress-image-optimizer') . '</a></div>';
        $output .= '</div>';
        $output .= '</div>';
        return $output;
    }


    public function compress_details($imageID)
    {
        $output = '';
        $stats = get_post_meta($imageID, 'ic_stats', true);

        // Removed the earlier visual "Restoring..." gate that locked the card
        // until _wpc_pending_thumb_regen cleared. Side effect: when the card was rendered
        // mid-regen, it shipped to the JS as "Restoring..." HTML and could stay stuck if
        // the next heartbeat tick happened to miss the regen-completion transient.
        //
        // The gate's underlying concern (user clicks Compress before regen done, sends
        // incomplete filenames JSON to the service) is now handled action-side:
        // wps_ic_compress_live calls wait_for_regen_or_clear_stale() before kicking the
        // service request. Visual state is decoupled from action gating.

        $isBulkCompressRunning = false;
        $isBulkRestoreRunning = false;
        $bulkIsrunning = get_option('wps_ic_bulk_process');
        if (!empty($bulkIsrunning)) {
            if (!empty($bulkIsrunning['status'])) {
                if ($bulkIsrunning['status'] == 'compressing') {
                    $isBulkCompressRunning = true;
                } else if ($bulkIsrunning['status'] == 'restoring') {
                    $isBulkRestoreRunning = true;
                }
            }
        }

        $compressing = get_post_meta($imageID, 'ic_compressing', true);
        delete_post_meta($imageID, 'ic_bulk_running');

        // Check if the image ID is already in Bulk Process
        $imageStatus = get_transient('wps_ic_compress_' . $imageID);

        if (!empty($_GET['debug_media_library'])) {
            if (!empty($stats)) {
                foreach ($stats as $size => $data) {
                    $output .= '<strong>' . $size . '</strong> - ' . wps_ic_format_bytes($data['original']['size']) . ' - ' . wps_ic_format_bytes($data['compressed']['size']) . '<br/>';
                }
            }
        }

        // ─── Loading / bulk states ─────────────────────
        // Failsafe: if transient is stale AND image is not in the queue AND no worker is running → clear it
        if ($imageStatus && is_array($imageStatus) && !empty($imageStatus['time'])) {
            $age = time() - intval($imageStatus['time']);
            $inQueue = in_array($imageID, get_option('wpc_compress_queue', []));
            $workerRunning = (bool) get_transient('wpc_compress_lock');
            if ($age > 120 && !$inQueue && !$workerRunning) {
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);
                $imageStatus = false;
            }
        }

        // Post-restore regen-pending detection. After wps_ic_restore_live
        // completes, the disk has the original bytes back but WP's sub-size
        // thumbnails (300x300, medium_large, etc.) still need to be regenerated
        // by wpc_regen_thumbs_hook running async in a separate worker. Until
        // that finishes, clicking Compress would re-encode against stale
        // sub-sizes. The Uncompressed render below uses this flag to render
        // a disabled Compress button + tooltip: eager visual flip without
        // exposing a footgun.
        $ic_status_for_regen = get_post_meta($imageID, 'ic_status', true);
        $regen_still_pending = !empty(get_post_meta($imageID, '_wpc_pending_thumb_regen', true));
        $post_restore_regen_pending = ($ic_status_for_regen === 'restored' && $regen_still_pending);

        // Skip the bulk-running Optimizing flip if this image is
        // already compressed. The original condition flipped every card with
        // empty $stats (legacy v1 meta key) into Optimizing whenever any bulk
        // was running, but v2-compressed images store their data in
        // ic_local_variants, not ic_stats. So already-compressed v2 images
        // had empty $stats and got mass-flipped to "Optimizing 0J 0W 0A"
        // during bulk runs even when their meta clearly said compressed.
        // Trust ic_status here: if it says compressed, this image isn't part
        // of the bulk pipeline (the queue filter excludes ic_status=compressed).
        //
        // Also accept ic_compressing.status='compressed' as proof
        // of compression. eager_flip (in pull-manifest direct-entry) flips
        // ic_compressing.status the instant the first Phase B variant lands,
        // but ic_status only flips when Phase A's promote_to_compressed runs
        // (potentially 10-30s later, or not at all if Phase A fails). For
        // images whose variants land via pull before Phase A returns,
        // ic_status stays empty so the old check said "not compressed" and ML
        // refresh during bulk rendered "Optimizing 0J 0W 0A" forever even
        // with 24 variants on disk. Trust either signal.
        $ic_status_actual = get_post_meta($imageID, 'ic_status', true);
        $ic_compressing_status = is_array($compressing) ? ($compressing['status'] ?? '') : '';
        $is_already_compressed = ($ic_status_actual === 'compressed' || $ic_compressing_status === 'compressed');

        // Exclusion is explicit user intent and must win over a stale
        // "Optimizing" flip. An excluded image caught in a bulk run (isBulkCompressRunning
        // + empty $stats), or one left with a lingering wps_ic_compress_ transient or
        // ic_compressing.status='optimizing' from a dispatch that fired before the user
        // excluded it, used to render "Optimizing 0J 0W 0A" forever: the loading branch
        // just below returns at line ~902, so the Excluded render down in the not-compressed
        // else-block (~985) was never reached. Check exclusion here, ahead of the loading
        // state, and clear the stale loading signals so it can't re-trip. Only when not
        // actually compressed: a compressed image keeps its Compressed card + Restore action.
        if (!$is_already_compressed && get_post_meta($imageID, 'wps_ic_exclude_live', true) == 'true') {
            // Unstick: drop any loading transient / optimizing meta that would otherwise
            // keep flipping this excluded image back to Optimizing on the next refresh.
            if ($imageStatus) {
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);
            }
            if ($ic_compressing_status === 'optimizing' || $ic_compressing_status === 'queueing') {
                delete_post_meta($imageID, 'ic_compressing');
            }

            // (No global clearstatcache here — an excluded image isn't being
            // written during render, so the cached filesize is accurate, and a
            // full realpath-cache flush per card is wasteful on excluded-heavy grids.)
            $ex_filedata = get_attached_file($imageID);
            $ex_origpath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : false;
            $ex_scaled   = ($ex_filedata && file_exists($ex_filedata)) ? filesize($ex_filedata) : 0;
            $ex_orig     = ($ex_origpath && file_exists($ex_origpath)) ? filesize($ex_origpath) : 0;
            $ex_filesize = wps_ic_format_bytes(max($ex_scaled, $ex_orig), null, null, false);

            $output .= '<div class="wpc-ml-card wpc-ml-card--excluded is-excluded">';
            $output .= self::icon_stack();
            $output .= '<div class="wpc-ml-body">';
            $output .= '<div class="wpc-ml-title">' . esc_html__('Excluded', 'wp-compress-image-optimizer') . '</div>';
            $output .= '<div class="wpc-ml-subtitle">' . $ex_filesize . '<a class="wpc-ml-action wps-ic-exclude-live" data-action="include" data-attachment_id="' . $imageID . '">' . self::svg_plus() . ' ' . esc_html__('Include', 'wp-compress-image-optimizer') . '</a></div>';
            $output .= '</div>';
            $output .= '</div>';
            return $output;
        }

        if (!$is_already_compressed && ($imageStatus || ($isBulkCompressRunning && empty($stats)) || ($isBulkRestoreRunning && !empty($stats) && empty(self::$parsedImages[$imageID])))) {
            // Distinguish restoring vs compressing so refresh during either action
            // renders the correct animation state (wps_ic_restore_live sets status=restoring).
            $is_restoring = (is_array($imageStatus) && isset($imageStatus['status']) && $imageStatus['status'] === 'restoring')
                || $isBulkRestoreRunning;
            $css_class = $is_restoring ? 'is-restoring' : 'is-compressing';
            $label     = $is_restoring ? esc_html__('Restoring', 'wp-compress-image-optimizer') : esc_html__('Optimizing', 'wp-compress-image-optimizer');

            // Pre-render the variant-count chip inside the Optimizing
            // card too (initial state 0/0/0/0). SSE updates this chip element
            // directly via DOM as Phase B callbacks land, so the user sees the
            // count climb from 1, 2, 3… during the Optimizing phase, not
            // waiting for the heartbeat-driven card swap to expose the chip.
            // Once status flips to compressed, the heartbeat swaps the whole
            // card to the Compressed template (which has its own identical
            // chip), and the SSE event continues seamlessly.
            $output .= '<div class="wpc-ml-card ' . $css_class . '">';
            $output .= self::icon_stack();
            $output .= '<div class="wpc-ml-body"><div class="fade-in-up"><div class="wpc-ml-title">' . $label . '</div><div class="wpc-skeleton"><div class="wpc-skeleton-bar w-long"></div><div class="wpc-skeleton-bar w-short"></div></div>';
            if (!$is_restoring) {
                $output .=
                    '<div class="wpc-variant-count-chip-row" style="display:none;margin-top:6px;line-height:1;">' . // (v7.03.65) hidden on optimizing too — the "0 · 0J 0W 0A" read as empty; count still tracked under the hood for the heartbeat/?wpc_counter_debug diagnostic
                    '<span class="wpc-variant-count-chip" style="display:inline-flex;align-items:center;gap:4px;' .
                    'padding:2px 7px;border-radius:9px;background:rgba(120,120,140,0.12);' .
                    'font-size:10px;font-weight:600;letter-spacing:.2px;color:#445;">' .
                    '<span style="opacity:.95;">0</span>' .
                    '<span style="opacity:.35;margin:0 1px;">·</span>' .
                    '<span style="color:#888;">0J</span>' .
                    '<span style="color:#0aa56b;">0W</span>' .
                    '<span style="color:#7c4ddc;">0A</span>' .
                    '</span></div>';
            }
            $output .= '</div></div>';
            $output .= '</div>';
            return $output;
        }

        // ─── Compressed state ─────────────────────
        if ((!empty($compressing['status']) && ($compressing['status'] == 'compressed' || $compressing['status'] == 'no-further')) || !empty($stats)) {
            $ic_savings = get_post_meta($imageID, 'ic_savings', true);
            $ic_bytes   = get_post_meta($imageID, 'ic_savings_bytes', true);
            $ic_base    = get_post_meta($imageID, 'ic_savings_baseline', true);

            if (!empty($ic_savings) && !empty($ic_base) && $ic_base > 0) {
                $savings_percent = number_format(floatval($ic_savings), 1);
                $saved_bytes = intval($ic_bytes);
            } else {
                $original = isset($stats['original']['original']['size']) ? $stats['original']['original']['size'] : 0;
                $compressed = isset($stats['original']['compressed']['size']) ? $stats['original']['compressed']['size'] : 0;
                if ($original > 0 && $compressed > 0 && $original != $compressed) {
                    $savings_percent = number_format((1 - ($compressed / $original)) * 100, 1);
                    $saved_bytes = $original - $compressed;
                } else {
                    $savings_percent = '0';
                    $saved_bytes = 0;
                }
            }

            // Live variant-count chip for production testing. Shows total
            // variants in ic_local_variants plus per-format breakdown (J/W/A). Always
            // visible. Renders as its own row BELOW the Details/Restore subtitle so it
            // doesn't crowd the headline savings %. Inline-styled so it ships without a
            // LESS recompile. Easy to hide later via:
            //   .wpc-variant-count-chip-row { display: none !important; }
            $variant_chip_row = '';
            $vc_variants = get_post_meta($imageID, 'ic_local_variants', true);
            if (is_array($vc_variants) && !empty($vc_variants)) {
                $vc_total = 0; $vc_jpeg = 0; $vc_webp = 0; $vc_avif = 0;
                foreach ($vc_variants as $vc_key => $vc_entry) {
                    $vc_total++;
                    if (strpos($vc_key, '-avif') !== false)      $vc_avif++;
                    elseif (strpos($vc_key, '-webp') !== false)  $vc_webp++;
                    elseif (strpos($vc_key, '-png') !== false)   { /* skip from chip */ }
                    else                                          $vc_jpeg++;
                }
                $vc_title = sprintf('%d JPEG · %d WebP · %d AVIF · %d total',
                    $vc_jpeg, $vc_webp, $vc_avif, $vc_total);
                $variant_chip_row =
                    '<div class="wpc-variant-count-chip-row"' .
                    ' style="display:none;margin-top:6px;line-height:1;">' .
                    '<span class="wpc-variant-count-chip" title="' . esc_attr($vc_title) . '"' .
                    ' style="display:inline-flex;align-items:center;gap:4px;' .
                    'padding:2px 7px;border-radius:9px;background:rgba(120,120,140,0.12);' .
                    'font-size:10px;font-weight:600;letter-spacing:.2px;color:#445;">' .
                    '<span style="opacity:.95;">' . (int) $vc_total . '</span>' .
                    '<span style="opacity:.35;margin:0 1px;">·</span>' .
                    '<span style="color:#888;">' . (int) $vc_jpeg . 'J</span>' .
                    '<span style="color:#0aa56b;">' . (int) $vc_webp . 'W</span>' .
                    '<span style="color:#7c4ddc;">' . (int) $vc_avif . 'A</span>' .
                    '</span>' .
                    '</div>';
            }

            $output .= '<div class="wpc-ml-card wpc-ml-card--compressed" style="align-items:center;">'; // (v7.03.60) badge hidden → vertically center the remaining content with the icon (no leftover chip slot)
            $output .= self::icon_stack();
            $output .= '<div class="wpc-ml-body">';
            if ($saved_bytes > 0) {
                $output .= '<div class="wpc-ml-title">' . $savings_percent . '% ' . esc_html__('Saved', 'wp-compress-image-optimizer') . '</div>';
                $output .= '<div class="wpc-ml-subtitle"><a class="wpc-ml-action wpc-ml-action--primary wpc-stats-trigger" data-attachment_id="' . $imageID . '">' . self::svg_chart() . ' ' . esc_html__('Details', 'wp-compress-image-optimizer') . '</a><a class="wpc-ml-action wps-ic-restore-live" data-attachment_id="' . $imageID . '">' . self::svg_undo() . ' ' . esc_html__('Restore', 'wp-compress-image-optimizer') . '</a></div>';
                $output .= $variant_chip_row;
            } else {
                $output .= '<div class="wpc-ml-title">' . esc_html__('Compressed', 'wp-compress-image-optimizer') . '</div>';
                $output .= '<div class="wpc-ml-subtitle"><a class="wpc-ml-action wpc-ml-action--primary wpc-stats-trigger" data-attachment_id="' . $imageID . '">' . self::svg_chart() . ' ' . esc_html__('Details', 'wp-compress-image-optimizer') . '</a><a class="wpc-ml-action wps-ic-restore-live" data-attachment_id="' . $imageID . '">' . self::svg_undo() . ' ' . esc_html__('Restore', 'wp-compress-image-optimizer') . '</a></div>';
                $output .= $variant_chip_row;
            }
            $output .= '</div>';
            $output .= '</div>';

        // ─── Not compressed / excluded state ─────────────────────
        } else {
            clearstatcache(true);
            $filedata = get_attached_file($imageID);
            $originalFilepath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : false;
            $scaled_size = ($filedata && file_exists($filedata)) ? filesize($filedata) : 0;
            $orig_size = ($originalFilepath && file_exists($originalFilepath)) ? filesize($originalFilepath) : 0;
            $filesize = wps_ic_format_bytes(max($scaled_size, $orig_size), null, null, false);

            if (get_post_meta($imageID, 'wps_ic_exclude_live', true) == 'true') {
                $output .= '<div class="wpc-ml-card wpc-ml-card--excluded is-excluded">';
                $output .= self::icon_stack();
                $output .= '<div class="wpc-ml-body">';
                $output .= '<div class="wpc-ml-title">' . esc_html__('Excluded', 'wp-compress-image-optimizer') . '</div>';
                $output .= '<div class="wpc-ml-subtitle">' . $filesize . '<a class="wpc-ml-action wps-ic-exclude-live" data-action="include" data-attachment_id="' . $imageID . '">' . self::svg_plus() . ' ' . esc_html__('Include', 'wp-compress-image-optimizer') . '</a></div>';
                $output .= '</div>';
                $output .= '</div>';
            } else {
                // In lazy_* modes the per-image Compress button is repurposed
                // to "Pre-warm now" so customers can opt to encode a specific image
                // synchronously rather than wait for its first front-end view. Manual
                // mode keeps "Compress" since that's the only way to encode at all.
                // Legacy mode is unchanged.
                $compress_label = esc_html__('Compress', 'wp-compress-image-optimizer');
                $compress_title = '';
                if (function_exists('wpc_lazy_mode_active') && wpc_lazy_mode_active()) {
                    $compress_label = esc_html__('Pre-warm now', 'wp-compress-image-optimizer');
                    $compress_title = ' title="' . esc_attr__('Lazy mode is on — variants encode on first view. Click to force encoding now.', 'wp-compress-image-optimizer') . '"';
                }
                // Post-restore regen lock. Card flips to Uncompressed
                // immediately (eager) but Compress stays unavailable until WP's
                // sub-size thumbnails finish regenerating. Without this, the
                // user could click Compress on an image whose sub-sizes are
                // missing/stale and get a half-encoded result.
                //   - Card carries `.is-regen-pending` class (wpcWatchCard's
                //     class-diff detection picks this up vs the eventual
                //     un-locked Uncompressed render and re-renders the card).
                //   - Compress button rendered as a span (not anchor), so no
                //     click handler binds. Tooltip explains the wait.
                //   - Replaces the regen marker file/post-meta path which is
                //     already polled by wpcWatchCard via the `pending` flag
                //     returned by wps_ic_get_card.
                $card_classes = 'wpc-ml-card wpc-ml-card--uncompressed';
                if ($post_restore_regen_pending) $card_classes .= ' is-regen-pending';
                $output .= '<div class="' . esc_attr($card_classes) . '">';
                $output .= self::icon_stack();
                $output .= '<div class="wpc-ml-body">';
                $output .= '<div class="wpc-ml-title">' . $filesize . '</div>';
                $output .= '<div class="wpc-ml-subtitle">';
                // Hide Compress button when in any lazy_* mode.
                // Per UX intent: lazy mode means lazy, encoding happens on
                // first front-end view, not by admin click. Showing the button
                // suggests the customer can/should click it, which contradicts
                // the lazy strategy. Customers who want manual control should
                // switch the mode to legacy or manual. The Exclude action is
                // still useful so it stays.
                $is_lazy_mode = function_exists('wpc_lazy_mode_active') && wpc_lazy_mode_active();
                if ($post_restore_regen_pending) {
                    $regen_tip = esc_attr__('Finishing restore — regenerating sub-size thumbnails. Compress will be available in a few seconds.', 'wp-compress-image-optimizer');
                    $output .= '<span class="wpc-ml-action wpc-ml-action--primary wpc-ml-action--disabled" title="' . $regen_tip . '" aria-disabled="true">' . self::svg_bolt() . ' ' . esc_html__('Regenerating Thumbnails…', 'wp-compress-image-optimizer') . '</span>';
                } elseif ($is_lazy_mode) {
                    $lazy_tip = esc_attr__('Optimized automatically on first view, we\'ll generate and serve modern, right-sized variants the moment a visitor loads this image. No manual compression needed.', 'wp-compress-image-optimizer');
                    $output .= '<span class="wpc-ml-action wpc-ml-action--lazy" data-wpc-tip="' . $lazy_tip . '" aria-label="' . $lazy_tip . '">' . self::svg_bolt() . ' ' . esc_html__('Smart Delivery', 'wp-compress-image-optimizer') . '</span>';
                } else {
                    $output .= '<a class="wpc-ml-action wpc-ml-action--primary wps-ic-compress-live" data-attachment_id="' . $imageID . '"' . $compress_title . '>' . self::svg_bolt() . ' ' . $compress_label . '</a>';
                }
                // (v7.03.117) Hide Exclude WHILE regenerating thumbnails — the long "Regenerating
                // Thumbnails…" label + Exclude overflow the card. Exclude returns the moment regen ends
                // (the card re-renders to the normal Compress/Smart-Delivery + Exclude row).
                if (!$post_restore_regen_pending) {
                    $output .= '<a class="wpc-ml-action wps-ic-exclude-live" data-action="exclude" data-attachment_id="' . $imageID . '">' . self::svg_x() . ' ' . esc_html__('Exclude', 'wp-compress-image-optimizer') . '</a>';
                }
                $output .= '</div>';
                $output .= '</div>';
                $output .= '</div>';
            }
        }

        return $output;
    }


    public function compress_details_popup($imageID)
    {
        $output = '';
        $savings_list = '';
        $combined_savings = 0;

        $imageFull = wp_get_attachment_image_src($imageID, 'full');
        $stats = get_post_meta($imageID, 'ic_stats', true);
        $filename = basename($imageFull[0]);

        if ($stats && !empty($stats)) {
            foreach ($stats as $size => $image) {
                $imageDetails = wp_get_attachment_image_src($imageID, $size);
                $filenameDetails = basename($imageDetails[0]);

                $original_size = $image['original']['size'];
                $compressed_size = $image['compressed']['size'];
                if ($original_size > $compressed_size) {
                    $savings = $original_size - $compressed_size;
                    $combined_savings += $savings;
                } else {
                    $savings = 0;
                }

                if (empty($image['original']['size']) || !isset($image['original']['size']) || is_null($image['original']['size'])) {
                    $original_size = 'Not Existing';
                } else {
                    $original_size = wps_ic_format_bytes($original_size);
                }

                $savings_list .= '<tr>';
                $savings_list .= '<td>' . $size . '</td>';
                $savings_list .= '<td>' . $original_size . '</td>';
                $savings_list .= '<td>' . wps_ic_format_bytes($compressed_size) . '</td>';
                $savings_list .= '<td>' . wps_ic_format_bytes($savings) . '</td>';
                $savings_list .= '</tr>';
            }
        } else {
            $savings_list .= '<tr>';
            $savings_list .= '<td colspan="4" style="text-align:center;">Sorry, there has been an error!</td>';
            $savings_list .= '</tr>';
        }

        #$output .= '<div class="wps-ic-compress-details-popup-' . $imageID . '" style="display:none;">';
        $output .= '<div class="wps-ic-compress-details-popup-inner">';

        $output .= '<div class="wps-ic-cd-left">';
        $output .= '<h2>' . $filename . '</h2>';
        $output .= '<img src="' . $imageFull[0] . '" />';
        $output .= '<h2>Combined Savings</h2>';
        $output .= wps_ic_format_bytes($combined_savings);
        $output .= '</div>';

        $output .= '<div class="wps-ic-cd-right overflow-scroll">';
        $output .= '<table class="wp-list-table widefat fixed striped wp-compress-details-table">';
        $output .= '<thead>';
        $output .= '<tr>';
        $output .= '<th>Size</th>';
        $output .= '<th>Original</th>';
        $output .= '<th>Compressed</th>';
        $output .= '<th>Savings KB</th>';
        $output .= '</tr>';
        $output .= '</thead>';
        $output .= '<tbody>';

        $output .= $savings_list;

        $output .= '</tbody>';
        $output .= '</table>';
        $output .= '</div>';

        $output .= '</div>';

        #$output .= '</div>';

        return $output;
    }


    /**
     * Finds all images and saves them to queue
     */
    public function prepare_restore()
    {
        $compressed_images_queue = $this->find_compressed_images();
        if ($compressed_images_queue) {
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }


    public function find_compressed_images($queue = false)
    {
        $compressed_images = [];
        $images = get_posts(['post_type' => 'attachment', 'posts_per_page' => -1]);

        if ($images) {
            foreach ($images as $image) {
                $stats = get_post_meta($image->ID, 'ic_stats', true);

                $file_data = get_attached_file($image->ID);
                $type = wp_check_filetype($file_data);

                // Is file extension allowed
                if (!in_array(strtolower($type['ext']), self::$allowed_types)) {
                    continue;
                }

                if ($stats && !empty($stats)) {
                    $compressed_images[] = $image->ID;
                }
            }
        }

        set_transient('wps_ic_restore_queue', ['total_images' => count($compressed_images), 'queue' => $compressed_images], 1800);

        return $compressed_images;

    }


    /**
     * Finds all images and saves them to queue
     */
    public function prepare_compress()
    {
        $uncompressed_images_queue = $this->find_uncompressed_images();
        if ($uncompressed_images_queue) {
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }


    public function find_uncompressed_images($queue = false)
    {
        $uncompressed_images = [];
        $excluded_list = get_option('wps_ic_exclude_list');
        $images = get_posts(['post_type' => 'attachment', 'posts_per_page' => -1]);

        if ($images) {
            foreach ($images as $image) {
                $stats = get_post_meta($image->ID, 'ic_stats', true);
                $file_data = get_attached_file($image->ID);
                $type = wp_check_filetype($file_data);

                if (!empty($excluded_list[$image->ID])) {
                    continue;
                }

                // Is file extension allowed
                if (!in_array(strtolower($type['ext']), self::$allowed_types)) {
                    continue;
                }

                if (empty($stats)) {
                    $uncompressed_images[] = $image->ID;
                }
            }
        }


        set_transient('wps_ic_compress_queue', ['total_images' => count($uncompressed_images), 'queue' => $uncompressed_images], 1800);

        return $uncompressed_images;

    }


    public function add_exclude_link($actions, $att)
    {
        $filedata = get_attached_file($att->ID);
        $basename = sanitize_title(basename($filedata));

        $exclude = 'style="display:none;"';
        $include = 'style="display:none;"';

        if (!in_array($basename, self::$exclude_list)) {
            $exclude = '';
        } else {
            $include = '';
        }

        $actions['exclude'] = '<a href="#" class="wps-ic-exclude-live-link" id="wps-ic-exclude-live-link-' . $att->ID . '" data-action="exclude" data-attachment_id="' . $att->ID . '" title="Exclude" ' . $exclude . '>Exclude</a>';

        $actions['exclude'] .= '<a href="#" class="wps-ic-include-live-link" id="wps-ic-include-live-link-' . $att->ID . '" data-action="include" data-attachment_id="' . $att->ID . '" title="Include" ' . $include . '>Include</a>';

        #$actions['exclude'] .= '<div class="wps-ic-image-loading-mini" id="wp-ic-image-loading-' . $att->ID . '" style="display:none;"><img src="' . WPS_IC_URI . 'assets/images/spinner.svg" /></div>';

        return $actions;
    }

    public function custom_bulk_admin_notices()
    {
        global $post_type, $pagenow;
        if ($pagenow == 'upload.php' && isset($_REQUEST['wps-ic-action']) && $_REQUEST['wps-ic-action'] == 'added_to_queue') {
            $message = sprintf(_n('Image added to queue', '%s images added to queue.', $_REQUEST['wps-ic-count']), number_format_i18n($_REQUEST['wps-ic-count']));
            echo "<div class=\"updated\"><p>{$message}</p></div>";
        } else if ($pagenow == 'upload.php' && isset($_REQUEST['wps-ic-action']) && $_REQUEST['wps-ic-action'] == 'removed_from_queue') {
            $message = sprintf(_n('Image removed from queue', '%s images removed from queue.', $_REQUEST['wps-ic-count']), number_format_i18n($_REQUEST['wps-ic-count']));
            echo "<div class=\"updated\"><p>{$message}</p></div>";
        }
    }

}