<?php


class wps_ic_fonts
{


    public $stylesheet;
    public $stylesheetMap;
    private $url;
    /** @var string $filename */
    private $filename;
    /** @var string $path */
    private $path;
    private $response;
    private $foundFonts;
    private $mimeMap = ['font/woff2' => 'woff2', 'application/font-woff2' => 'woff2', 'font/woff' => 'woff', 'application/font-woff' => 'woff', 'font/ttf' => 'ttf', 'application/x-font-ttf' => 'ttf', 'font/sfnt' => 'ttf', // Can be WOFF2 or TTF, but we pick TTF.
        'application/font-sfnt' => 'ttf', 'font/otf' => 'otf', 'application/x-font-opentype' => 'otf', 'application/vnd.ms-fontobject' => 'eot',];


    public function __construct()
    {
        $this->path = WPS_IC_FONTS_DIR;
        $this->stylesheetMap = get_option(WPS_IC_FONTS_MAP);
    }


    public function listFoundFonts()
    {
        return $this->stylesheetMap;
    }


    public function replaceFrontend($html)
    {
        if (!empty($this->stylesheetMap)) {
            foreach ($this->stylesheetMap as $style => $replaceData) {
                if (empty($replaceData) || empty($replaceData['filename'])) {
                    continue;
                }

                if (!file_exists(WPS_IC_FONTS_DIR . $replaceData['dir'] . '/' . $replaceData['filename'])) {
                    continue;
                }

                $replaceUrl = WPS_IC_FONTS_URL . $replaceData['dir'] . '/' . $replaceData['filename'];
                $html = str_replace($style, $replaceUrl, $html);
            }
        }

        return $html;
    }


    public function callAPI($urlToScan)
    {
        $this->url = $urlToScan;

        $response = wp_remote_get(add_query_arg(array('url' => esc_url_raw($urlToScan),), WPS_IC_FONTS_SCAN), array('method' => 'POST', 'timeout' => 15, 'headers' => array('Content-Type' => 'application/json',),));

        // Handle errors
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        // Get response body
        $body = wp_remote_retrieve_body($response);

        // Decode JSON if API returns JSON
        $data = json_decode($body, true);
        $this->response = $data;

        return $data;
    }


    public function scanForFonts($response)
    {

        // If response is JSON string, decode it
        if (is_string($response)) {
            $response = json_decode($response, true);
        }

        // Validate response structure
        if (!is_array($response) || !isset($response['found']) || !is_array($response['found'])) {
            return array();
        }

        $found = array();

        // Store google fonts stylesheets if present
        if (!empty($response['found']['googleFontsStylesheets'])) {
            $found['googleFontsStylesheets'] = array_values($response['found']['googleFontsStylesheets']);
        } else {
            $found['googleFontsStylesheets'] = array();
        }

        // Store gstatic URLs if present
        if (!empty($response['found']['gstaticUrls'])) {
            $found['gstaticUrls'] = array_values($response['found']['gstaticUrls']);
        } else {
            $found['gstaticUrls'] = array();
        }

        $this->foundFonts = $found;
        return $found;
    }

    public function readGoogleStylesheet($array)
    {
        if (!empty($array['googleFontsStylesheets'])) {
            foreach ($array['googleFontsStylesheets'] as $font) {
                echo 'downloading: ' . $font . " -- ";

                // Read CSS Stylesheet
                $download = $this->read($font);

                // For found fonts - download them
                // save them into directory fot specific stylesheet
                $stylesheetDir = md5($font);

                $stylesheet = $this->saveStylesheet($font, $this->stylesheet, $stylesheetDir);

                if (!empty($download['all'])) {
                    foreach ($download['all'] as $font) {
                        $downloadFont = $this->download($font, $stylesheetDir);
                        $this->replaceInStylesheet($stylesheet['stylesheetPath'], $downloadFont['url'], WPS_IC_FONTS_URL . $stylesheetDir . '/' . $downloadFont['filename']);
                    }
                }

            }

            return 'downloaded';
        }

        return 'error';
    }

    public function read($stylesheet)
    {
        // Normalize HTML-encoded ampersands (your scan example had &#038;)
        $css_url = str_replace('&#038;', '&', $stylesheet);

        // Support protocol-relative
        if (str_starts_with($css_url, '//')) {
            $css_url = 'https:' . $css_url;
        }

        $response = wp_remote_get($css_url, ['timeout' => 30, 'redirection' => 5, 'headers' => [// Browser-ish headers: helps avoid 403 with Google Fonts sometimes
            'User-Agent' => WPS_IC_API_USERAGENT, 'Accept' => 'text/css,*/*;q=0.1',],]);

        if (is_wp_error($response)) {
            return ['all' => [], 'woff2' => [], 'woff' => [], 'ttf' => [], 'otf' => [], 'eot' => [], 'svg' => [], 'unknown' => [], 'error' => $response->get_error_message(),];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['all' => [], 'woff2' => [], 'woff' => [], 'ttf' => [], 'otf' => [], 'eot' => [], 'svg' => [], 'unknown' => [], 'error' => 'HTTP ' . $code,];
        }

        $css = wp_remote_retrieve_body($response);
        if (!is_string($css) || $css === '') {
            return ['all' => [], 'woff2' => [], 'woff' => [], 'ttf' => [], 'otf' => [], 'eot' => [], 'svg' => [], 'unknown' => [], 'error' => 'Empty CSS body',];
        }

        $this->stylesheet = $css;

        // Find all url(...) occurrences in CSS, tolerate quotes, spaces.
        // Captures: url( ... )
        preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $matches);

        $urls = [];
        if (!empty($matches[2])) {
            foreach ($matches[2] as $u) {
                $u = trim($u);

                // Skip data URIs
                if (str_starts_with($u, 'data:')) {
                    continue;
                }

                // Protocol-relative inside CSS
                if (str_starts_with($u, '//')) {
                    $u = 'https:' . $u;
                }

                // Basic sanitize
                $u = esc_url_raw($u);

                if ($u) {
                    $urls[] = $u;
                }
            }
        }

        // Unique while preserving order
        $urls = array_values(array_unique($urls));

        // Group by extension (ignoring querystrings)
        $out = ['all' => $urls, 'woff2' => [], 'woff' => [], 'ttf' => [], 'otf' => [], 'eot' => [], 'svg' => [], 'unknown' => [],];

        foreach ($urls as $u) {
            $path = wp_parse_url($u, PHP_URL_PATH);
            $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

            switch ($ext) {
                case 'woff2':
                    $out['woff2'][] = $u;
                    break;
                case 'woff':
                    $out['woff'][] = $u;
                    break;
                case 'ttf':
                    $out['ttf'][] = $u;
                    break;
                case 'otf':
                    $out['otf'][] = $u;
                    break;
                case 'eot':
                    $out['eot'][] = $u;
                    break;
                case 'svg':
                    $out['svg'][] = $u;
                    break;
                default:
                    $out['unknown'][] = $u;
                    break;
            }
        }

        return $out;
    }

    public function saveStylesheet($styleSheetURL, $stylesheetCSS, $dir)
    {
        $stylesheetPath = WPS_IC_FONTS_DIR . $dir . '/';

        // Encode the filename because of special characters
        $stylesheetFilename = md5(basename($styleSheetURL)) . '.css';

        // Map the stylesheet URL to Filename
        $this->mapStylesheets($styleSheetURL, $dir, $stylesheetFilename);

        // Create directory
        wp_mkdir_p($stylesheetPath);

        // If file exists, remove it
        if (file_exists($stylesheetPath . $stylesheetFilename)) {
            unlink($stylesheetPath . $stylesheetFilename);
        }

        // Write CSS content to File
        file_put_contents($stylesheetPath . $stylesheetFilename, $stylesheetCSS);

        return ['stylesheetPath' => $stylesheetPath . $stylesheetFilename];
    }

    public function mapStylesheets($url, $dir, $filename)
    {
        $this->stylesheetMap = get_option(WPS_IC_FONTS_MAP);
        $this->stylesheetMap[$url] = ['dir' => $dir, 'filename' => $filename];
        update_option(WPS_IC_FONTS_MAP, $this->stylesheetMap);
    }

    public function download($url, $dir = '')
    {
        $this->filename = basename($url);

        if (empty($dir)) {
            $dir = $this->path;
            $uri = $this->uriPath;
        } else {
            $dir = WPS_IC_FONTS_DIR . $dir . '/';
            $uri = WPS_IC_FONTS_URL . $dir . '/';
        }

        wp_mkdir_p($dir);

        // Use the $url passed in (your original code used $this->url)
        $request_url = $url;

        // Handle protocol-relative URLs
        if (str_starts_with($request_url, '//')) {
            $request_url = 'https:' . $request_url;
        }

        // IMPORTANT: if your source sometimes contains '&#038;' from HTML, normalize it
        $request_url = str_replace('&#038;', '&', $request_url);

        $temp_filename = $dir . $this->filename . '.tmp';

        $args = ['timeout' => 300, 'redirection' => 5, 'stream' => true, 'filename' => $temp_filename, 'headers' => [// Browser-like headers often prevent Google Fonts 403
            'User-Agent' => 'Mozilla/5.0 (compatible; WordPress; +https://wordpress.org/)', 'Accept' => 'text/css,*/*;q=0.1',],];

        $response = wp_remote_get($request_url, $args); // wp_safe_remote_get is fine too, but wp_remote_get is enough here

        if (is_wp_error($response)) {
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }
            return ['error' => true, 'msg' => 'WP Error: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }

            // Helpful debug info for 403s
            $server_msg = wp_remote_retrieve_header($response, 'status') ?: '';
            return ['error' => true, 'msg' => 'Response code: ' . $code . ' ' . $server_msg];
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!$content_type) {
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }
            return ['error' => true, 'msg' => 'Missing content-type header'];
        }

        $content_type = strtolower(trim(explode(';', $content_type)[0]));
        $extension = $this->mimeMap[$content_type] ?? '';

        if (!$extension) {
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }
            return ['error' => true, 'msg' => 'Extension not accepted for content-type: ' . $content_type];
        }

        $final_path = $dir . '/' . $this->filename;

        if (!rename($temp_filename, $final_path)) {
            if (file_exists($temp_filename)) {
                unlink($temp_filename);
            }
            return ['error' => true, 'msg' => 'Unable to rename file'];
        }


        return ['url' => $request_url, 'path' => $final_path, 'uriPath' => $uri, 'filename' => $this->filename];
    }

    public function replaceInStylesheet($stylesheetPath, $findUrl, $replaceUrl)
    {
        $contents = file_get_contents($stylesheetPath);
        $contents = str_replace($findUrl, $replaceUrl, $contents);
        file_put_contents($stylesheetPath, $contents);
    }

    public function downloadFound($array)
    {
        if (!empty($array['googleFontsStylesheets'])) {
            foreach ($array['googleFontsStylesheets'] as $font) {
                echo 'downloading: ' . $font . " -- ";
                $download = $this->download($font);
                if (!empty($download) && empty($download['error'])) {
                    echo 'Download successful!' . "\r\n";
                } else {
                    echo 'Download failed! ' . $download['msg'] . "\r\n";
                }
            }
        }
    }


}