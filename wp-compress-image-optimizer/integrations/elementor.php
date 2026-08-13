<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_elementor
{

    public $delayActive;

    public function __construct()
    {

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
            ],
            'save_post' => [
              'callback' => 'clear_cache',
              'priority' => 10,
              'args' => 1
            ]
        ];
    }

    public function do_admin_filters()
    {
        return [
            'wps_ic_purge_all_url_key' => [
                'callback' => 'filter_url_key',
                'priority' => 10,
                'args' => 2
            ]
        ];
    }

    public function filter_url_key($url_key, $critSave)
    {
        // When Elementor is active and not in critSave mode, purge all (set url_key to false)
        if (defined('ELEMENTOR_VERSION') && !$critSave) {
            // Also purge critical files
            if (class_exists('wps_ic_cache_integrations')) {


                if (function_exists('wpc_crit_mark_stale_instead') && wpc_crit_mark_stale_instead()) {
                    return false;
                }
                if (!function_exists('wpc_inv2_crit_soft_purge') || !wpc_inv2_crit_soft_purge('elementor-widen')) {
                    wps_ic_cache_integrations::purgeCriticalFiles();
                }
            }
            return false;
        }
        return $url_key;
    }

    public function clear_cache()
    {
        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll(false, false, false, false);
    }

    public function runIntegration($html)
    {

        // Both of these stamp .wpc-delay-elementor, and the ONLY thing that ever removes it is
        // the delay-v3 loader — insertJS() below is dead code, never called from anywhere.
        // checkCache() returns early for every logged-in request, so the rewriter never emits
        // that loader and the stamp becomes permanent: hawkeye.design rendered 2,677px of an
        // 8,337px page, footer included, and only when logged in. Defer only when something
        // is actually going to undo the deferral.
        if (!(function_exists('is_user_logged_in') && is_user_logged_in())) {

            $html = $this->hideSections($html);

            $html = $this->delayBackgrounds($html);

        }

        if (str_contains($html, 'elementor/optimize.js') === false) {
            $html = str_replace('optimize.js', 'elementor/optimize.js', $html);
        }

        return $html;
    }

    public function hideSections($html)
    {
	    // Get skip sections configuration with fallback
	    $skipSections = get_option('wps_ic_elementor_skip_sections');
	    $defaultSkip = 5;

	    if (empty($skipSections)) {
		    $skip = $defaultSkip;
	    } else {
		    // Determine device type and get appropriate skip value
		    $deviceType = $this->isMobile() ? 'mobile' : 'desktop';
		    $skip = isset($skipSections[$deviceType]) ? $skipSections[$deviceType] : $defaultSkip;
	    }

        $count = 0;
        $html = preg_replace_callback(
            '/(<section[^>]*class="[^"]*?)elementor-top-section([^"]*")/i',
            function ($matches) use (&$count, $skip) {
                $count++;
                if ($count > $skip) {
                    return $matches[1] . 'elementor-top-section wpc-delay-elementor' . $matches[2];
                } else {
                    return $matches[0];
                }
            },
            $html
        );


        try {
            $wpc_ex53 = [];
            if (class_exists('wps_ic_url_key') && defined('WPS_IC_CRITICAL')) {
                $wpc_k53 = (new wps_ic_url_key())->setup('');
                $wpc_d53 = $wpc_k53 ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_k53 . '/' : '';
                foreach (['lcp.json', 'delay.json'] as $wpc_f53) {
                    $wpc_j53 = $wpc_d53 ? @json_decode((string) @file_get_contents($wpc_d53 . $wpc_f53), true) : null;
                    if (!is_array($wpc_j53)) { continue; }
                    $wpc_sels = [];
                    if (isset($wpc_j53['lcp_element']) && is_array($wpc_j53['lcp_element'])) {
                        foreach (['mobile', 'desktop'] as $wpc_dv) {
                            if (!empty($wpc_j53['lcp_element'][$wpc_dv]['sel'])) { $wpc_sels[] = (string) $wpc_j53['lcp_element'][$wpc_dv]['sel']; }
                        }
                    }
                    foreach (['atf_bg', 'atf_images'] as $wpc_ak) {
                        $wpc_av = isset($wpc_j53[$wpc_ak]) ? $wpc_j53[$wpc_ak] : null;
                        if (is_array($wpc_av)) {
                            foreach (['mobile', 'desktop'] as $wpc_dv) {
                                foreach ((array) ($wpc_av[$wpc_dv] ?? $wpc_av) as $wpc_e53) {
                                    if (is_array($wpc_e53) && !empty($wpc_e53['sel'])) { $wpc_sels[] = (string) $wpc_e53['sel']; }
                                }
                            }
                        }
                    }
                    foreach ($wpc_sels as $wpc_sl) {
                        if (preg_match_all('/#([A-Za-z][\w-]*)/', $wpc_sl, $wpc_m1)) { $wpc_ex53 = array_merge($wpc_ex53, $wpc_m1[1]); }
                        if (preg_match_all('/elementor-element-([a-z0-9]+)/i', $wpc_sl, $wpc_m2)) { $wpc_ex53 = array_merge($wpc_ex53, $wpc_m2[1]); }
                    }
                }
            }
            $wpc_ex53 = array_slice(array_unique(array_filter(apply_filters('wpc_section_delay_atf_exempt', $wpc_ex53))), 0, 12);
            foreach ($wpc_ex53 as $wpc_id53) {
                $wpc_q53 = preg_quote($wpc_id53, '/');

                $html = preg_replace(
                    '/(<[a-z]+\b[^>]*(?:data-id|id)="' . $wpc_q53 . '"[^>]*class="[^"]*?)\s*wpc-delay-elementor/i',
                    '$1', $html);
                $html = preg_replace(
                    '/(<[a-z]+\b[^>]*class="[^"]*?)\s*wpc-delay-elementor([^"]*"[^>]*(?:data-id|id)="' . $wpc_q53 . '")/i',
                    '$1$2', $html);
            }
        } catch (\Throwable $e) {

        }

        // display:none here needed reveal JS, and the only remover that ever ships is the
        // delay-v3 loader — insertJS() below is dead code, never called. checkCache() skips
        // the rewriter for every logged-in request, so the loader is absent and these sections
        // stayed hidden FOREVER: hawkeye.design rendered 2,677px instead of 8,337px, footer
        // included. Same failure Divi/Avada had in .304; same fix. content-visibility:auto is
        // the same below-fold render deferral with NO reveal JS, and old browsers fail open.
        $html = str_replace('</head>', '<style>.wpc-delay-elementor{content-visibility:auto;contain-intrinsic-size:auto 900px;}</style></head>', $html);

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

        return $html;
    }

    public function delayBackgrounds($html)
    {
        // The first overlay(s) are the ATF hero — display:none'ing the hero overlay makes
        // IT the LCP, painted only at JS reveal (~1.3s). Keep the ATF overlay(s) eager;
        // below-fold overlays still delay. Errs toward NOT delaying (safe for LCP).
        $skip = (int) apply_filters('wpc_delay_overlay_skip', 1);
        $n = 0;
        $out = preg_replace_callback(
            '/class="([^"]*?)elementor-background-overlay([^"]*?)"/i',
            function ($m) use (&$n, $skip) {
                $n++;
                if ($n <= $skip) {
                    return $m[0];
                }
                return 'class="wpc-delay-elementor ' . $m[1] . 'elementor-background-overlay' . $m[2] . '"';
            },
            $html
        );

        return is_string($out) ? $out : $html;
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

	public function isMobile()
	{
		if (!empty($_GET['simulate_mobile'])) {
			return true;
		}

		if (isset($_SERVER['HTTP_USER_AGENT'])) {
			$userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);

			// Define an array of mobile device keywords to check against
			$mobileKeywords = [
				'android', 'iphone', 'ipad', 'windows phone', 'blackberry', 'tablet', 'mobile'
			];

			// Check if the user agent contains any of the mobile device keywords
			foreach ($mobileKeywords as $keyword) {
				if (strpos($userAgent, $keyword) !== false) {
					return true; // Found a match, so it's a mobile device
				}
			}
		}

		return false;
	}

    /**
     * Intercept 404 requests for Elementor CSS files
     */
    public function intercept_css_404() {
        // Only proceed if this is a 404
        if ( ! is_404() ) {
            return;
        }

        // Check if Elementor is active
        if ( ! did_action( 'elementor/loaded' ) ) {
            return;
        }

        // Get the requested URI
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Check if this is an Elementor CSS file request
        if ( ! $this->is_elementor_css_request( $request_uri ) ) {
            return;
        }

        // Parse the CSS file details
        $css_details = $this->parse_css_filename( $request_uri );

        if ( ! $css_details ) {
            return;
        }

        // Try to regenerate and serve the CSS file
        $this->regenerate_and_serve_css( $css_details );
    }

    /**
     * Check if the request is for an Elementor CSS file
     *
     * @param string $uri The request URI
     * @return bool
     */
    private function is_elementor_css_request( $uri ) {
        // Fast string check to eliminate 99.9% of 404s instantly
        if ( strpos( $uri, '/elementor/css/' ) === false ) {
            return false;
        }

        // Extract filename and remove query string
        $filename = basename( strtok( $uri, '?' ) );

        // Minimal regex on just the filename (faster than full path regex)
        return preg_match( '/^(post|loop)-(\d+)\.css$/', $filename );
    }

    /**
     * Parse the CSS filename to extract ID and type
     *
     * @param string $uri The request URI
     * @return array|false Array with 'type' and 'id', or false on failure
     */
    private function parse_css_filename( $uri ) {
        // Extract filename and remove query string
        $filename = basename( strtok( $uri, '?' ) );

        // Match patterns like: post-123.css, loop-456.css
        if ( preg_match( '/^(post|loop)-(\d+)\.css$/', $filename, $matches ) ) {
            return array(
                'type' => $matches[1],
                'id'   => (int) $matches[2],
            );
        }

        return false;
    }

    /**
     * Regenerate and serve the CSS file
     *
     * @param array $css_details Array with 'type' and 'id'
     */
    private function regenerate_and_serve_css( $css_details ) {
        $type = $css_details['type'];
        $id   = $css_details['id'];

        // Prevent race conditions - check if another request is already regenerating this file
        $lock_key = "elementor_css_regen_{$type}_{$id}";

        if ( get_transient( $lock_key ) ) {
            // Another request is already regenerating — serve whatever is on disk now
            // (stale is fine) rather than holding this visitor's worker on a sleep.
            $this->serve_css_file( $type, $id );
            return;
        }

        // Set a lock for 30 seconds
        set_transient( $lock_key, true, 30 );

        // Validate the post exists
        $post = get_post( $id );
        if ( ! $post ) {
            delete_transient( $lock_key );
            return;
        }

        // Check if the post is built with Elementor
        if ( ! $this->is_built_with_elementor( $id ) ) {
            delete_transient( $lock_key );
            return;
        }

        // Regenerate the CSS file
        $success = $this->regenerate_css_file( $type, $id );

        // Release the lock
        delete_transient( $lock_key );

        if ( $success ) {
            // Serve the newly created file
            $this->serve_css_file( $type, $id );
        } else {
        }
    }

    /**
     * Check if a post is built with Elementor
     *
     * @param int $post_id Post ID
     * @return bool
     */
    private function is_built_with_elementor( $post_id ) {
        $document = \Elementor\Plugin::$instance->documents->get( $post_id );
        return $document && $document->is_built_with_elementor();
    }


    private function regenerate_css_file( $type, $id ) {
        try {
            // Get the document
            $document = \Elementor\Plugin::$instance->documents->get_doc_for_frontend( $id );

            if ( ! $document ) {
                return false;
            }

            // Both post and loop files use the Post CSS class
            // Loop templates are just posts with template-type metadata
            $css_file = \Elementor\Core\Files\CSS\Post::create( $id );

            if ( ! $css_file ) {
                return false;
            }

            // Force update the CSS file
            $css_file->update();

            return true;

        } catch ( Exception $e ) {
            // Log error if WP_DEBUG is enabled
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    'Elementor CSS Regenerator: Failed to regenerate %s-%d.css - %s',
                    $type,
                    $id,
                    $e->getMessage()
                ) );
            }
            return false;
        }
    }

    /**
     * Serve the CSS file with proper headers
     *
     * @param string $type CSS file type (post or loop)
     * @param int $id Post/Template ID
     */
    private function serve_css_file( $type, $id ) {
        // Build the file path
        $upload_dir = wp_upload_dir();
        $css_file_path = sprintf(
            '%s/elementor/css/%s-%d.css',
            $upload_dir['basedir'],
            $type,
            $id
        );

        // Check if file exists
        if ( ! file_exists( $css_file_path ) ) {
            return;
        }

        // CRITICAL: Override the 404 status with 200
        status_header( 200 );

        // Set proper headers
        header( 'Content-Type: text/css; charset=UTF-8' );
        header( 'Cache-Control: public, max-age=31536000' );
        header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 31536000 ) . ' GMT' );
        header( 'Content-Length: ' . filesize( $css_file_path ) );

        // Output the file
        readfile( $css_file_path );

        // Exit to prevent WordPress from loading
        exit;
    }

}