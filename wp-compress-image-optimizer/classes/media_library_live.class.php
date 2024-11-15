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

        self::$load_spinner = WPS_IC_URI . 'assets/images/legacy/spinner.svg';
        self::$logo_compressed = WPS_IC_URI . 'assets/images/legacy/logo-compressed.svg';
        self::$logo_uncompressed = WPS_IC_URI . 'assets/images/legacy/logo-not-compressed.svg';
        self::$logo_excluded = WPS_IC_URI . 'assets/images/legacy/logo-excluded.svg';
        self::$allowed_types = ['jpg' => 'jpg', 'jpeg' => 'jpeg', 'gif' => 'gif', 'png' => 'png'];


        add_filter('media_row_actions', [$this, 'add_exclude_link'], 10, 2);
        if ((empty(self::$settings['live-cdn']) || self::$settings['live-cdn'] == '0') || (!empty(self::$settings['local']['media-library']) && self::$settings['local']['media-library'] == '1')) {
            if (empty(self::$options['hide_compress']) || self::$options['hide_compress'] == '') {

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
                global $pagenow;
                if ($pagenow !== 'upload.php') {
                    return;
                }
                add_action('restrict_manage_posts', [$this, 'add_wps_ic_filters']);
                wp_enqueue_script('wps-ic-filters', WPS_IC_URI . '/assets/js/admin/media-filters.min.js', ['media-editor', 'media-views']);
                wp_localize_script('wps-ic-filters', 'WpsIcFilters', ['filters' => $this->get_filters(), 'filter_all' => 'WP Compress Filters']);
                add_filter('ajax_query_attachments_args', [$this, 'do_wps_ic_ajax_filters']);

                //Add compress bulk action to list view
                //$this->add_bulk_actions_list();
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
        echo '<div id="no-credits-popup" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>Ooops, you have no quota left.</h3>
          <p>Seems like your account has exhausted all credits and it automatically reverted to "Local" Mode to prevent CDN Issues.</p>
          <a href="https://www.wpcompress.com/pricing" target="_blank" class="button button-primary">Get Credits</a>
        </div>

      </div>
    </div>';

        echo '<div id="file-already-compressed" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>This image appears to have already been compressed.</h3>
          <p>If you think this is an error, please don\'t hesitate to contact us  for further assistance.</p>
        </div>

      </div>
    </div>';

        echo '<div id="file-not-supported" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>Sorry, we don\'t support this file type!</h3>
        </div>

      </div>
    </div>';

        echo '<div id="file-in-bulk" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>File is already added to bulk!</h3>
        </div>

      </div>
    </div>';

        echo '<div id="unable-to-contact-api" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>We were unable to contact WP Compress API!</h3>
          <a href="https://www.wpcompress.com/" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
        </div>

      </div>
    </div>';

        echo '<div id="failed-to-get-backup" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>We were unable to retrieve your backup file!</h3>
          <a href="https://www.wpcompress.com/" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
        </div>

      </div>
    </div>';

        echo '<div id="missing-apikey" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>Our API was unable to retrieve your API Key!</h3>
          <a href="https://www.wpcompress.com/" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
        </div>

      </div>
    </div>';

        echo '<div id="empty-site-url" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>Our API was unable to retrieve Site URL!</h3>
          <a href="https://www.wpcompress.com/" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
        </div>

      </div>
    </div>';

        echo '<div id="apikey-not-matching" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>Our API was unable to match your API Key!</h3>
          <a href="https://www.wpcompress.com/" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
        </div>

      </div>
    </div>';


        echo '<div id="api-blocked-by-firewall" style="display: none;">
      <div id="cdn-popup-inner" class="ic-compress-all-popup">

        <div class="cdn-popup-top">
          <img class="popup-icon" src="' . WPS_IC_URI . 'assets/v4/images/warning-icon.svg"/>
        </div>

        <div class="cdn-popup-content" style="padding-bottom: 50px;">
          <h3>Our API was blocked by some type of firewall!</h3>
          <a href="https://www.wpcompress.com/" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
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
          <a href="https://www.wpcompress.com/" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
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
          <a href="https://www.wpcompress.com/" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
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
          <a href="https://www.wpcompress.com/" target="_blank" class="button button-primary button-wpc-popup-primary">Contact Support</a>
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

        $output = '<h4 style="margin:10px 0px 10px 10px;">WP Compress Stats</h4>';
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
                $bulk_actions['wps_ic_compress_in_background'] = 'Compress in Background';
                return $bulk_actions;
            });
            add_filter('handle_bulk_actions-upload', [$this, 'start_bulk_in_background'], 10, 3);
        }

        //Queue view
        if ($filter == 'in_queue' || $filter == 'all' || !isset($_GET['wps-ic-filters'])) {
            add_filter('bulk_actions-upload', function ($bulk_actions) {
                $bulk_actions['wps_ic_remove_from_queue'] = 'Remove from Queue';
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
            <option value="all">WP Compress Filters</option>
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

        switch ($filter) {
            case 'uncompressed':
                $query->set('meta_key', 'ic_stats');
                $query->set('meta_compare', 'NOT EXISTS');

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
        if (WPS_IC_DEBUG == 'false') {
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
        $old = $cols;
        $cols = [];
        $cols['cb'] = $old['cb'];
        $cols['title'] = $old['title'];
        #$cols["wps_ic_all"]     = "";
        $cols["wps_ic_actions"] = "";
        $cols['author'] = $old['author'];
        $cols['parent'] = $old['parent'];
        $cols['comments'] = $old['comments'];
        $cols['date'] = $old['date'];

        return $cols;
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
                $output .= '<div class="wps-ic-media-actions-toolbox">';
                $output .= '<ul class="wps-ic-include">';
                $output .= '<li class="no-padding">';

                $output .= '<div class="btn-group">';
                $output .= 'Not supported';
                $output .= '</div>';

                $output .= '</li>';
                $output .= '</ul>';
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

            #$output .= '<div class="wps-ic-image-loading-' . $id . ' wps-ic-image-loading-container" id="wp-ic-image-loading-' . $id . '" style="display:none;"><img src="' . self::$load_spinner . '" /></div>';
            $output .= '<div class="wps-ic-image-loading-' . $id . ' wps-ic-image-loading-container" id="wp-ic-image-loading-' . $id . '" style="display:none;"><div class="wps-ic-bulk-preparing-logo-container-media-lib">
        <div class="wps-ic-bulk-preparing-logo-media-lib">
          <img src="' . WPS_IC_URI . 'assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
          <img src="' . WPS_IC_URI . 'assets/preparing.svg" class="bulk-preparing"/>
        </div>
      </div></div>';
            echo $output;

        }
    }


    public function excluded_details($imageID)
    {
        $output = '<div class="wps-ic-compressed-logo">';
        $output .= '<img src="' . self::$logo_excluded . '" />';
        $output .= '</div>';

        $output .= '<div class="wps-ic-compressed-info">';

        $output .= '<div class="wpc-info-box wpc-excluded-box">';
        $output .= '<h5>Excluded</h5>';

        $output .= '<ul class="wpc-inline-list">';
        $output .= '<li><a class="wps-ic-exclude-live" data-attachment_id="' . $imageID . '">Include</a></li>';
        $output .= '</ul>';

        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }


    public function compress_details($imageID)
    {
        $output = '';
        $stats = get_post_meta($imageID, 'ic_stats', true);
        $thumbs = get_post_meta($imageID, 'ic_compressed_thumbs', true);

        $compressing = get_post_meta($imageID, 'ic_compressing', true);
        delete_post_meta($imageID, 'ic_bulk_running');

        // Check if the image ID is already in Bulk Process
        $isInBulk = get_post_meta($imageID, 'ic_bulk_running', true);
        $imageStatus = get_transient('wps_ic_compress_' . $imageID);

        if (!empty($_GET['debug_media_library'])) {
            if (!empty($stats)) {
                foreach ($stats as $size => $data) {
                    $output .= '<strong>' . $size . '</strong> - ' . wps_ic_format_bytes($data['original']['size']) . ' - ' . wps_ic_format_bytes($data['compressed']['size']) . '<br/>';
                }
            }
        }

        if ($imageStatus) {
            $output .= '<div class="wps-ic-bulk-preparing-logo-container-media-lib">
                <div class="wps-ic-bulk-preparing-logo-media-lib">
                  <img src="' . WPS_IC_URI . 'assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
                  <img src="' . WPS_IC_URI . 'assets/preparing.svg" class="bulk-preparing"/>
                </div>
              </div>';
            return $output;
        }

        if ($isInBulk) {
            $output .= '<div class="wps-ic-bulk-preparing-logo-container-media-lib">
                <div class="wps-ic-bulk-preparing-logo-media-lib">
                  <img src="' . WPS_IC_URI . 'assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
                  <img src="' . WPS_IC_URI . 'assets/preparing.svg" class="bulk-preparing"/>
                </div>
              </div>';
            return $output;
        }

        if ((!empty($compressing['status']) && $compressing['status'] == 'compressed') || !empty($stats)) {
            $original = $stats['original']['original']['size'];
            $compressed = $stats['original']['compressed']['size'];

            if ($original > 0 && $compressed > 0 && $original != $compressed) {
                $savings_percent = number_format((1 - ($compressed / $original)) * 100, 1);
                $savings_kb = round($compressed);
            } else {
                $savings_percent = '0';
                $savings_kb = 0;
            }


            if ($savings_kb <= 0) {
                $filesize = parent::get_wp_filesize($imageID);

                $output .= '<div class="wps-ic-compressed-logo">';
                $output .= '<img src="' . self::$logo_compressed . '" />';
                $output .= '</div>';

                $output .= '<div class="wps-ic-compressed-info">';

                $output .= '<div class="wpc-info-box">';
                $output .= '<h5>No further savings</h5>';
                $output .= '</div>';

                $output .= '<div>';
                $output .= '<ul class="wpc-inline-list">';

                $output .= '<li><div class="wpc-savings-tag">' . $filesize . '</div></li>';
                $output .= '<li><a class="wps-ic-restore-live ic-tooltip" title="Restore" data-attachment_id="' . $imageID . '"></a></li>';
                $output .= '<li>' . apply_filters('wps_ic_debug_log_link', $imageID) . '</li>';

                $output .= '</ul>';
                $output .= '</div>';

                $output .= '</div>';

            } else {

                // Original
                $originalFilesize = round($original);
                $originalFilesize = wps_ic_format_bytes($originalFilesize);

                // Scaled
                $wpScaledFilesize = round($compressed);
                $wpScaledFilesize = wps_ic_format_bytes($wpScaledFilesize);

                // Saved
                $savedFileSize = round($original) - round($compressed);
                $savedFileSize = wps_ic_format_bytes($savedFileSize);

                $output .= '<div class="wps-ic-compressed-logo">';
                $output .= '<img src="' . self::$logo_compressed . '" />';
                $output .= '</div>';

                $output .= '<div class="wps-ic-compressed-info">';

                $output .= '<div class="wpc-info-box">';
                $output .= '<h5>' . $savings_percent . '% Savings</h5>';
                $output .= '</div>';

                $output .= '<div>';

                $output .= '<ul class="wpc-inline-list">';
                #$output .= '<li><div class="wpc-savings-tag">Original: ' . $originalFilesize . '</div></li>';
                #$output .= '<li><div class="wpc-savings-tag">Compressed: ' . $wpScaledFilesize . '</div></li>';
                $output .= '<li><div class="wpc-savings-tag">' . $savedFileSize . ' Saved</div></li>';

                $output .= '<li class="li-dropdown">';
                $output .= '<a class="wpc-dropdown-btn wps-ic-restore-live ic-tooltip" title="Restore" data-attachment_id="' . $imageID . '"></a>';
                $output .= '</li>';

                $output .= '</ul>';
                $output .= '</div>';

                $output .= '<div class="wps-ic-compress-details-popup-' . $imageID . '" style="display:none;">';
                $output .= '</div>';
                $output .= '</div>';

            }

        } else if (!empty($compressing['status']) && $compressing['status'] == 'no-further') {

            $filesize = parent::get_wp_filesize($imageID);

            $output .= '<div class="wps-ic-compressed-logo">';
            $output .= '<img src="' . self::$logo_compressed . '" />';
            $output .= '</div>';

            $output .= '<div class="wps-ic-compressed-info">';

            $output .= '<div class="wpc-info-box">';
            $output .= '<h5>No further savings</h5>';
            $output .= '</div>';

            $output .= '<div>';
            $output .= '<ul class="wpc-inline-list">';

            $output .= '<li><div class="wpc-savings-tag">' . $filesize . '</div></li>';
            $output .= '<li><a class="wps-ic-restore-live ic-tooltip" title="Restore" data-attachment_id="' . $imageID . '"></a></li>';
            $output .= '<li>' . apply_filters('wps_ic_debug_log_link', $imageID) . '</li>';

            $output .= '</ul>';
            $output .= '</div>';

            $output .= '</div>';


        } else {
            $filedata = get_attached_file($imageID);

            // Get scaled file size
            $filesize = filesize($filedata);
            $wpScaledFilesize = wps_ic_format_bytes($filesize, null, null, false);

            // Get original filesize
            $originalFilepath = wp_get_original_image_path($imageID);
            $originalFilesize = filesize($originalFilepath);
            $originalFilesize = wps_ic_format_bytes($originalFilesize, null, null, false);

            $basename = sanitize_title(basename($filedata));

            if (get_post_meta($imageID, 'wps_ic_exclude_live', true) == 'true') {
                $output .= '<div class="wps-ic-compressed-logo">';
                $output .= '<img src="' . self::$logo_excluded . '" />';
                $output .= '</div>';

                $output .= '<div class="wps-ic-compressed-info">';

                $output .= '<div class="wpc-info-box">';
                $output .= '<h5>Excluded</h5>';
                $output .= '</div>';

                $output .= '<div>';
                $output .= '<ul class="wpc-inline-list">';

                $output .= '<li><div class="wpc-savings-tag">' . $originalFilesize . '</div></li>';

                $output .= '<li>';
                $output .= '<a class="wpc-dropdown-btn wps-ic-include-live ic-tooltip" title="Include" data-action="include" data-attachment_id="' . $imageID . '"></a>';
                $output .= '</li>';

                $output .= '</ul>';
                $output .= '</div>';

                $output .= '</div>';
            } else {
                $output .= '<div class="wps-ic-compressed-logo">';
                $output .= '<img src="' . self::$logo_uncompressed . '" />';
                $output .= '</div>';

                $output .= '<div class="wps-ic-compressed-info">';

                $output .= '<div class="wpc-info-box">';
                $output .= '<h5>Not Compressed</h5>';
                $output .= '</div>';

                $output .= '<div>';
                $output .= '<ul class="wpc-inline-list">';

                $output .= '<li><div class="wpc-savings-tag">' . $originalFilesize . '</div></li>';
                #$output .= '<li><div class="wpc-savings-tag">After: ' . $wpScaledFilesize . '</div></li>';

                $output .= '<li>';
                $output .= '<a class="wpc-dropdown-btn wps-ic-compress-live ic-tooltip" title="Compress" data-attachment_id="' . $imageID . '"></a>';
                $output .= '</li>';
                $output .= '<li>';
                $output .= '<a class="wpc-dropdown-btn wps-ic-exclude-live ic-tooltip" title="Exclude" data-action="exclude" data-attachment_id="' . $imageID . '"></a>';
                $output .= '</li>';

                $output .= '</ul>';
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