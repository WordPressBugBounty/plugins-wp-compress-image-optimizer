<?php
if (!defined('ABSPATH')) {
    exit; 
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
        
        if (defined('ELEMENTOR_VERSION') && !$critSave) {
            
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
	    
	    $skipSections = get_option('wps_ic_elementor_skip_sections');
	    $defaultSkip = 5;

	    if (empty($skipSections)) {
		    $skip = $defaultSkip;
	    } else {
		    
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

        
        
        
        
        
        
        $html = str_replace('</head>', '<style>.wpc-delay-elementor{content-visibility:auto;contain-intrinsic-size:auto 900px;}</style></head>', $html);

        $html = preg_replace(
            '/(<footer[^>]*class="[^"]*)"/i',
            '$1 wpc-delay-elementor"',
            $html
        );

        
        $html = preg_replace(
            '/(<footer)(?![^>]*class="[^"]*")/i',
            '$1 class="wpc-delay-elementor"',
            $html
        );

        return $html;
    }

    public function delayBackgrounds($html)
    {
        
        
        
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

			
			$mobileKeywords = [
				'android', 'iphone', 'ipad', 'windows phone', 'blackberry', 'tablet', 'mobile'
			];

			
			foreach ($mobileKeywords as $keyword) {
				if (strpos($userAgent, $keyword) !== false) {
					return true; 
				}
			}
		}

		return false;
	}

    


    public function intercept_css_404() {
        
        if ( ! is_404() ) {
            return;
        }

        
        if ( ! did_action( 'elementor/loaded' ) ) {
            return;
        }

        
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        
        if ( ! $this->is_elementor_css_request( $request_uri ) ) {
            return;
        }

        
        $css_details = $this->parse_css_filename( $request_uri );

        if ( ! $css_details ) {
            return;
        }

        
        $this->regenerate_and_serve_css( $css_details );
    }

    





    private function is_elementor_css_request( $uri ) {
        
        if ( strpos( $uri, '/elementor/css/' ) === false ) {
            return false;
        }

        
        $filename = basename( strtok( $uri, '?' ) );

        
        return preg_match( '/^(post|loop)-(\d+)\.css$/', $filename );
    }

    





    private function parse_css_filename( $uri ) {
        
        $filename = basename( strtok( $uri, '?' ) );

        
        if ( preg_match( '/^(post|loop)-(\d+)\.css$/', $filename, $matches ) ) {
            return array(
                'type' => $matches[1],
                'id'   => (int) $matches[2],
            );
        }

        return false;
    }

    




    private function regenerate_and_serve_css( $css_details ) {
        $type = $css_details['type'];
        $id   = $css_details['id'];

        
        $lock_key = "elementor_css_regen_{$type}_{$id}";

        if ( get_transient( $lock_key ) ) {
            
            
            $this->serve_css_file( $type, $id );
            return;
        }

        
        set_transient( $lock_key, true, 30 );

        
        $post = get_post( $id );
        if ( ! $post ) {
            delete_transient( $lock_key );
            return;
        }

        
        if ( ! $this->is_built_with_elementor( $id ) ) {
            delete_transient( $lock_key );
            return;
        }

        
        $success = $this->regenerate_css_file( $type, $id );

        
        delete_transient( $lock_key );

        if ( $success ) {
            
            $this->serve_css_file( $type, $id );
        } else {
        }
    }

    





    private function is_built_with_elementor( $post_id ) {
        $document = \Elementor\Plugin::$instance->documents->get( $post_id );
        return $document && $document->is_built_with_elementor();
    }


    private function regenerate_css_file( $type, $id ) {
        try {
            
            $document = \Elementor\Plugin::$instance->documents->get_doc_for_frontend( $id );

            if ( ! $document ) {
                return false;
            }

            
            
            $css_file = \Elementor\Core\Files\CSS\Post::create( $id );

            if ( ! $css_file ) {
                return false;
            }

            
            $css_file->update();

            return true;

        } catch ( Exception $e ) {
            
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

    





    private function serve_css_file( $type, $id ) {
        
        $upload_dir = wp_upload_dir();
        $css_file_path = sprintf(
            '%s/elementor/css/%s-%d.css',
            $upload_dir['basedir'],
            $type,
            $id
        );

        
        if ( ! file_exists( $css_file_path ) ) {
            return;
        }

        
        status_header( 200 );

        
        header( 'Content-Type: text/css; charset=UTF-8' );
        header( 'Cache-Control: public, max-age=31536000' );
        header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 31536000 ) . ' GMT' );
        header( 'Content-Length: ' . filesize( $css_file_path ) );

        
        readfile( $css_file_path );

        
        exit;
    }

}