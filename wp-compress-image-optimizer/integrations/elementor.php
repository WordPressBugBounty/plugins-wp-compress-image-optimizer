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
                wps_ic_cache_integrations::purgeCriticalFiles();
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

        $html = $this->hideSections($html);

        $html = $this->delayBackgrounds($html);

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
            // Another request is already handling this, wait a moment and try to serve the file
            sleep( 1 );
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

    /**
     * Regenerate the CSS file using Elementor's API
     *
     * @param string $type CSS file type (post or loop)
     * @param int $id Post/Template ID
     * @return bool Success status
     */
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