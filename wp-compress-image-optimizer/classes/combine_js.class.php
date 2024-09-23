<?php

include_once WPS_IC_DIR . 'traits/url_key.php';

class wps_ic_combine_js {

	public static $excludes;
	public static $rewrite;

	/**
	 * @var void
	 */

	public function __construct() {
		$this->url_key_class     = new wps_ic_url_key();
		$this->urlKey            = $this->url_key_class->setup();
		$this->combined_dir      = WPS_IC_COMBINE . $this->urlKey . '/js/';
		$this->combined_url_base = WPS_IC_COMBINE_URL . $this->urlKey . '/js/';

		self::$excludes               = new wps_ic_excludes();
		self::$rewrite                = new wps_cdn_rewrite();
		$this->settings               = get_option( WPS_IC_SETTINGS );
		$this->filesize_cap           = '500000'; //in bytes
		$this->combine_inline_scripts = true;
		$this->combine_external       = false;

		$this->all_excludes = self::$excludes->combineJSExcludes();

		if ( ! empty( $this->settings['delay-js'] ) && $this->settings['delay-js'] == '1' ) {
			//If it shouldn't be delayed, it shouldn't be combined
			$this->all_excludes = array_merge( $this->all_excludes, self::$excludes->delayJSExcludes() );
		}

		$custom_cname    = get_option( 'ic_custom_cname' );
		if ( empty( $custom_cname ) || ! $custom_cname ) {
			$this->zone_name = get_option( 'ic_cdn_zone_name' );
		} else {
			$this->zone_name = $custom_cname;
		}

		//Check if Hide my WP is active and get replaces
		$this->hmwpReplace = false;
		if (class_exists('HMWP_Classes_ObjController')) {
			$this->hmwpReplace = true;
			$plugin_path = WP_PLUGIN_DIR . '/hide-my-wp/';
			include_once($plugin_path.'classes/ObjController.php');
			$hmwp_controller = new HMWP_Classes_ObjController;
			$this->hmwp_rewrite = $hmwp_controller::getClass('HMWP_Models_Rewrite');
		}
	}

	public function combine_exists() {
		$exists = is_dir( $this->combined_dir );
		if ( $exists ) {
			$exists = ( new \FilesystemIterator( $this->combined_dir ) )->valid();
		}

		return $exists;
	}

	public function write_file_and_next() {
		if ( $this->current_file != '' ) {
			file_put_contents( $this->combined_dir . 'wps_' . $this->current_section . '_' .
			                   $this->file_count . '.js', $this->current_file );
		}
		$this->file_count ++;
		$this->current_file = '';
	}

	public function maybe_do_combine( $html ) {
		if ( $this->combine_exists() && empty($_GET['forceRecombine'])) {

			$this->no_content_excludes = get_option('wps_no_content_excludes_js');

			$html = $this->replace( $html );
			return $html;
		}

		$this->no_content_excludes = [];

		$this->current_file = '';
		$this->file_count   = 1;

		$this->setup_dirs();

		$this->current_section = 'header';
		$html                  = preg_replace_callback( '/<head(.*?)<\/head>/si', [ $this, 'combine' ], $html );

		$this->write_file_and_next();
		$this->current_section = 'footer';
		$this->file_count      = 1;
		$html                  = preg_replace_callback( '/<\/head>(.*?)<\/body>/si', [ $this, 'combine' ], $html );

		$this->write_file_and_next();

		update_option('wps_no_content_excludes_js', $this->no_content_excludes);
		$html = $this->insert_combined_scripts( $html );

		return $html;
	}

	public function combine( $html ) {
		$html = $html[0];
		$html = preg_replace_callback( '/<script\b[^>]*>(.*?)<\/script>/si', array( $this, 'script_combine_and_replace' ), $html );
		return $html;
	}

	public function script_combine_and_replace( $tag ) {
		$tag = $tag[0];
		$src = '';

		if ( self::$excludes->strInArray( $tag, $this->all_excludes ) || current_user_can( 'manage_options' ) ) {
			return $tag;
      #return print_r(array($tag),true);
		}

		//get only the <script ...> and check for src
		preg_match( '/<script(.*?)>/si', $tag, $tag_start );
		$tag_start  = $tag_start[0];
		$is_src_set = preg_match( '/src=["|\'](.*?)["|\']/si', $tag_start, $src );

    #return print_r(array($src),true);

		if ( $is_src_set == 1 ) {

			$src = str_replace( 'src=', '', $src );
			$src = str_replace( [ "'", '"' ], "", $src );
			$src = $src[0];

      #return print_r(array($src,$this->url_key_class->is_external($src)),true);


			if ( ! $this->combine_external && $this->url_key_class->is_external( $src ) ) {
				return $tag;
			} else if ( $this->combine_external && $this->url_key_class->is_external( $src ) ) {
				$content = $this->getRemoteContent( $src );
			} else {
				$content = $this->getLocalContent( $src );
			}


			if(!$content){
				$this->no_content_excludes[] = $src;
				return $tag;
			}

		} else if ( $this->combine_inline_scripts ) {

      // TODO: Testing
      //return $tag;

			$src = 'Inline Script';
			$content = $tag;
			$content = preg_replace( '/<script(.*?)>/', '', $content );
			$content = preg_replace( '/<\/script>/', '', $content );
			$content = trim($content);
			if (strpos($content, '<') === 0 || strpos($content, '{') === 0){
				$this->no_content_excludes[] = $tag;
				return $tag;
			}
		} else {
			return $tag;
		}

		//sometimes php injects a zero width space char at the start of a new script, this clears it
		$content = preg_replace( '/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $content );

		$this->current_file .= "/* SCRIPT : $src */" . PHP_EOL;
		$this->current_file .= $content . PHP_EOL;

		if ( mb_strlen( $this->current_file, '8bit' ) >= $this->filesize_cap ) {
			$this->write_file_and_next();
		}

		return '';
	}

	public function replace( $html ) {

		$html = preg_replace_callback( '/<script\b[^>]*>(.*?)<\/script>/si', array( $this, 'remove_scripts' ), $html );
		$html = $this->insert_combined_scripts( $html );

		return $html;
	}

	public function remove_scripts( $tag ) {
		$tag = $tag[0];
		$src = '';

		if ( self::$excludes->strInArray( $tag, $this->all_excludes ) || current_user_can( 'manage_options' ) ) {
			#return $tag;
			#return print_r(array($tag),true);
		}

		if ( self::$excludes->strInArray( $tag, $this->no_content_excludes ) || current_user_can( 'manage_options' ) ) {
			//These are tags that we couldn't get content for
			return $tag;
		}

		//get only the <script ...> and check for src
		preg_match( '/<script(.*?)>/si', $tag, $tag_start );
		$tag_start  = $tag_start[0];
		$is_src_set = preg_match( '/src=["|\'](.*?)["|\']/si', $tag_start, $src );

		#return print_r(array($src),true);

		if ( $is_src_set == 1 ) {

			$src = str_replace( 'src=', '', $src );
			$src = str_replace( [ "'", '"' ], "", $src );
			$src = $src[0];

			#return print_r(array($src,$this->url_key_class->is_external($src)),true);


			if ( ! $this->combine_external && $this->url_key_class->is_external( $src ) ) {
				return $tag;
			}

		} else if ( $this->combine_inline_scripts ) {

			// TODO: Testing
			//return $tag;

			$src = 'Inline Script';
			$content = $tag;
			$content = preg_replace( '/<script(.*?)>/', '', $content );
			$content = preg_replace( '/<\/script>/', '', $content );
		} else {
			return $tag;
		}


		return '';
	}

	public function insert_combined_scripts( $html ) {

		$combined_files = new \FilesystemIterator( $this->combined_dir );
		$header_links   = '';
		$footer_links   = '';

		foreach ( $combined_files as $file ) {
			$url = $this->combined_url_base . basename( $file );

			if ( strpos( $file, 'wps_header' ) !== false ) {
				$header_links .= '<script type="text/javascript" src="' . self::$rewrite->adjust_src_url( $url ) . '"></script>' . PHP_EOL;
			} else {
				$footer_links .= '<script type="text/javascript" src="' . self::$rewrite->adjust_src_url( $url ) . '"></script>' . PHP_EOL;
			}

		}

		if ($this->hmwpReplace){
			//apply their replacements to our combined files because they are doing them before our insert
			foreach ($this->hmwp_rewrite->_replace['from'] as $key => $value) {
				$replace = $this->hmwp_rewrite->_replace['to'][$key];
				$header_links = str_replace($value, $replace, $header_links);
				$footer_links = str_replace($value, $replace, $footer_links);
			}
		}

		//header
    //$html = preg_replace( '/<head>/', '<head>'.$footer_links, $html );
		$html = preg_replace( '/<\/head>/', $header_links . '</head>', $html );
		//$html = preg_replace( '/<head>/', '<head>' . $header_links, $html );
		//footer
		$html = preg_replace( '/<\/body>/', $footer_links . '</body>', $html );

		return $html;
	}

	function getRemoteContent($url)
	{
		if (strpos($url, '//') === 0) {
			$url = 'https:' . $url;
		}

		$data = wp_remote_get($url);

		//todo Check if file is really js

		if (is_wp_error($data)) {
			return false;
		}

		return wp_remote_retrieve_body($data);
	}

	function getLocalContent($url)
	{

		if ($this->hmwpReplace){
			//go trougn their replacements and reverse them to get true path to files
			foreach ($this->hmwp_rewrite->_replace['to'] as $key => $value) {
				$replace = $this->hmwp_rewrite->_replace['from'][$key];
				$url = str_replace($value, $replace, $url);
			}
		}

		if (strpos($url, $this->zone_name) !== false){
			preg_match('/a:(.*?)(\?|$)/', $url, $match);
			$url = $match[1];
		}


    // denis start
		//$url = preg_replace('/\?.*/', '', $url);

		//$path = wp_make_link_relative($url);
		//$path = ltrim($path, '/');
    // denis end

    if (strpos($url, '?') !== false) {
      $url = explode('?', $url);
      $url = $url[0];
    }

    if (strpos($url,'http:') !== false || strpos($url, 'https:') !== false) {
      $path = wp_make_link_relative($url);
      $path = ltrim($path, '/');
    } else {
      $path = ltrim($url, '/');
    }

		//check if is folder install and if folder is in url remove it (it is already in ABSPATH)
		$last_abspath = basename(ABSPATH);
		$first_path = explode('/', $path)[0];
		if ($last_abspath == $first_path) {
			$path = substr($path, strlen($first_path));
			$path = ltrim($path, '/');
		}

		$content = file_get_contents(ABSPATH . $path);

		if(!$content){
			return false;
		}

		return $content;
	}

	public function setup_dirs() {
		mkdir( WPS_IC_COMBINE . $this->urlKey . '/js', 0777, true );
	}

}