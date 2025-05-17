<?php

class wps_ic_htaccess extends wps_ic
{

    public $htaccessPath;
    public $configPath;
    public $advancedCachePath;
    public $htaccessContent;
    public $isApache;
    public $cacheConstant;

    public function __construct()
    {

        if (is_admin()) {
            $this->cacheConstant = "define('WP_CACHE', VALUE); // WP Compress Cache";
            $serverSoftware = $_SERVER['SERVER_SOFTWARE'];
            if (strpos(strtolower($serverSoftware), 'litespeed') !== false || strpos(strtolower($serverSoftware), 'apache') !== false) {
                $this->isApache = true;
            } else if (strpos(strtolower($serverSoftware), 'nginx') !== false) {
                $this->isApache = false;
            }
        }
    }


    public function addGzip()
    {
        $error = false;
        $this->htaccessPath = $this->getHtaccessPath();
        if (empty($this->htaccessPath)) {
            return;
        }

        // Is the file writeable?
        if ($this->exists($this->htaccessPath) && !$this->isWriteable($this->htaccessPath)) {
            $error = true;
            $this->notice('not-writeable-htaccess');
        }

        // Is the file readable?
        if ($this->exists($this->htaccessPath) && !$this->isReadble($this->htaccessPath)) {
            $error = true;
            $this->notice('not-readable-htaccess');
        }

        if ($error) return;

        // Get Contents
        $this->htaccessContent = $this->getContents($this->htaccessPath);


        // Did we retrieve the correct htaccess content?
        if (!empty($this->htaccessContent)) {
            if (strpos($this->htaccessContent, 'IfModule mod_deflate.c') == false) {

                $mimeTypes = array(
                    'text/plain', 'text/css', 'text/javascript', 'application/javascript', 'application/x-javascript',
                    'application/json', 'text/html', 'text/xml', 'application/atom+xml', 'application/rss+xml',
                    'application/xhtml+xml', 'application/xml', 'text/x-component', 'application/vnd.ms-fontobject',
                    'application/x-font-ttf', 'font/eot', 'font/opentype', 'image/bmp', 'image/svg+xml',
                    'image/vnd.microsoft.icon', 'image/x-icon',
                );

                $rules = "<IfModule mod_deflate.c>\n";
                foreach ($mimeTypes as $type) {
                    $rules .= "    AddOutputFilterByType DEFLATE {$type}\n";
                }
                $rules .= "</IfModule>\n";

                // Append GZIP rules
                file_put_contents($this->htaccessPath, "\n\n" . $rules, FILE_APPEND);
            }
        }

    }

    public function getHtaccessPath()
    {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $htaccess_file = get_home_path() . '.htaccess';

        if (empty($htaccess_file) || !file_exists($htaccess_file)) {
            return false;
        }

        return $htaccess_file;
    }

    public function exists($path)
    {
        if ($this->fileSystem()->exists($path)) {
            return true;
        }

        return false;
    }

    public function fileSystem()
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        return new WP_Filesystem_Direct(new stdClass());
    }

    public function isWriteable($path)
    {
        if ($this->fileSystem()->is_writable($path)) {
            return true;
        }

        return false;
    }

    public function notice($what)
    {
        add_action('admin_notices', [$this, 'notice_' . str_replace('-', '_', $what)]);
    }

    public function isReadble($path)
    {
        if ($this->fileSystem()->is_readable($path)) {
            return true;
        }

        return false;
    }

    public function getContents($path)
    {
        return $this->fileSystem()->get_contents($path);
    }

    public function checkHtaccess()
    {
        $error = false;
        $this->htaccessPath = $this->getHtaccessPath();

        if (empty($this->htaccessPath) || !$this->isApache) {
            return;
        }

        // Is the file writeable?
        if ($this->exists($this->htaccessPath) && !$this->isWriteable($this->htaccessPath)) {
            $error = true;
            $this->notice('not-writeable-htaccess');
        }

        // Is the file readable?
        if ($this->exists($this->htaccessPath) && !$this->isReadble($this->htaccessPath)) {
            $error = true;
            $this->notice('not-readable-htaccess');
        }

        if ($error) return;

        // Get Contents
        $this->htaccessContent = $this->getContents($this->htaccessPath);

        // Did we retrieve the correct htaccess content?
        if (!empty($this->htaccessContent)) {
            // Does it already have modifications?

            if (!$this->hasRewriteMods() || !empty($_GET['rebuildHtaccess'])) {
                $this->modifyHtaccess();
            }

            // Remove Mods Fix
            if ($this->hasRewriteMods() && !empty($_GET['removeHtaccess'])) {
                // Remove HtAccess Rules
                $this->removeHtaccessRules();
            }
        }
    }

    public function hasRewriteMods()
    {
        if (strpos($this->htaccessContent, '#StartWPC-Cache') !== false) {
            return true;
        }

        return false;
    }

    public function modifyHtaccess()
    {

        return;

        if (!$this->isApache) {
            return;
        }

        $removeExistingRules = preg_replace('/\s*#StartWPC-Cache.*#EndWPC-Cache\s*?/isU', PHP_EOL . PHP_EOL, $this->htaccessContent);
        $cleanedHtaccessContent = ltrim($removeExistingRules);
        $newHtaccessContent = $this->getHtaccessRules() . PHP_EOL . $cleanedHtaccessContent;
        if (!empty($newHtaccessContent)) {
            if (!defined('FS_CHMOD_FILE')) {
                define('FS_CHMOD_FILE', 0644);
            }

            $this->fileSystem()->put_contents($this->getHtaccessPath(), $newHtaccessContent);
        }
    }

    public function getHtaccessRules()
    {
        $output = '#StartWPC-Cache' . PHP_EOL;
        $output .= $this->modifyGetCharset();
        $output .= $this->modifyGetEtag();
        $output .= $this->modifyGetFontsCORS();
        $output .= $this->modifyCacheControl();
        $output .= $this->modifyModExpires();
        $output .= $this->modifyModDeflate();
        $output .= $this->modifyForCaching();
        $output .= '#EndWPC-Cache' . PHP_EOL;
        return $output;
    }

    public function modifyGetCharset()
    {
        $charset = preg_replace('/[^a-zA-Z0-9_\-\.:]+/', '', get_bloginfo('charset', 'display'));

        if (empty($charset)) {
            return '';
        }

        $rules = "# Use defined encoding for anything served text/plain or text/html" . PHP_EOL;
        $rules .= "AddDefaultCharset $charset" . PHP_EOL;
        $rules .= "# Force defined encoding for file formats" . PHP_EOL;
        $rules .= '<IfModule mod_mime.c>' . PHP_EOL;
        $rules .= "AddCharset $charset .atom .css .js .json .rss .vtt .xml" . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
        return $rules;
    }

    public function modifyGetEtag()
    {
        $rules = '# FileETag None is not enough for all servers' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header unset ETag' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
        $rules .= '# Since we are sending far dated expires, we do not required ETags for that static content.' . PHP_EOL;
        $rules .= 'FileETag None' . PHP_EOL . PHP_EOL;
        return $rules;
    }

    public function modifyGetFontsCORS()
    {
        $rules = '# Send CORS headers when browsers request them.' . PHP_EOL;
        $rules .= '<IfModule mod_setenvif.c>' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= '# mod_headers - no match by Content-Type?!' . PHP_EOL;
        $rules .= '<FilesMatch "\.(avifs?|cur|gif|png|jpe?g|svgz?|ico|webp)$">' . PHP_EOL;
        $rules .= 'SetEnvIf Origin ":" IS_CORS' . PHP_EOL;
        $rules .= 'Header set Access-Control-Allow-Origin "*" env=IS_CORS' . PHP_EOL;
        $rules .= '</FilesMatch>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;

        $rules .= '# Allow Access to Web Fonts for CORS.' . PHP_EOL;
        $rules .= '<FilesMatch "\.(eot|otf|tt[cf]|woff2?)$">' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header set Access-Control-Allow-Origin "*"' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</FilesMatch>' . PHP_EOL . PHP_EOL;
        return $rules;
    }

    public function modifyCacheControl()
    {
        $rules = '<IfModule mod_alias.c>' . PHP_EOL;
        $rules .= '<FilesMatch "\.(html|htm|rtf|rtx|txt|xsd|xsl|xml)$">' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header set X-Powered-By "WP Compress Cache"' . PHP_EOL;
        $rules .= 'Header set Expires "3600"' . PHP_EOL;
        $rules .= 'Header set X-Cache "HIT"' . PHP_EOL;
        $rules .= 'Header set X-Cache-Enabled "True"' . PHP_EOL;
        $rules .= 'Header unset Pragma' . PHP_EOL;
        $rules .= 'Header set Cache-Control "max-age=86400, public"' . PHP_EOL;
        $rules .= 'Header unset Last-Modified' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</FilesMatch>' . PHP_EOL . PHP_EOL;
        $rules .= '<FilesMatch "\.(css|js)$">' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header set Cache-Control "public, max-age=31536000"' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</FilesMatch>' . PHP_EOL;
        $rules .= '<FilesMatch "\.(htc|js|asf|asx|wax|wmv|wmx|avi|bmp|class|divx|doc|docx|eot|exe|gif|gz|gzip|ico|jpg|jpeg|jpe|json|mdb|mid|midi|mov|qt|mp3|m4a|mp4|m4v|mpeg|mpg|mpe|mpp|otf|odb|odc|odf|odg|odp|ods|odt|ogg|pdf|png|pot|pps|ppt|pptx|ra|ram|svg|svgz|swf|tar|tif|tiff|ttf|ttc|wav|wma|wri|xla|xls|xlsx|xlt|xlw|zip)$">' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header unset Pragma' . PHP_EOL;
        $rules .= 'Header append Cache-Control "public"' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</FilesMatch>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
        return $rules;
    }

    public function modifyModExpires()
    {
        $rules = <<<HTACCESS
<IfModule mod_mime.c>
	AddType image/avif                                  avif
    AddType image/avif-sequence                         avifs
</IfModule>
# Expires headers (for better cache control)
<IfModule mod_expires.c>
	ExpiresActive on
	ExpiresDefault                              "access plus 1 month"
	# cache.appcache
	ExpiresByType text/cache-manifest           "access plus 0 seconds"
	# Your document html
	ExpiresByType text/html                     "access plus 0 seconds"
	# Data
	ExpiresByType text/xml                      "access plus 0 seconds"
	ExpiresByType application/xml               "access plus 0 seconds"
	ExpiresByType application/json              "access plus 0 seconds"
	# Feed
	ExpiresByType application/rss+xml           "access plus 1 hour"
	ExpiresByType application/atom+xml          "access plus 1 hour"
	# Favicon (cannot be renamed)
	ExpiresByType image/x-icon                  "access plus 1 year"
	# Media: images, video, audio
	ExpiresByType image/gif                     "access plus 4 months"
	ExpiresByType image/png                     "access plus 4 months"
	ExpiresByType image/jpeg                    "access plus 4 months"
	ExpiresByType image/webp                    "access plus 4 months"
	ExpiresByType video/ogg                     "access plus 4 months"
	ExpiresByType audio/ogg                     "access plus 4 months"
	ExpiresByType video/mp4                     "access plus 4 months"
	ExpiresByType video/webm                    "access plus 4 months"
	ExpiresByType image/avif                    "access plus 4 months"
	ExpiresByType image/avif-sequence           "access plus 4 months"
	# HTC files  (css3pie)
	ExpiresByType text/x-component              "access plus 1 month"
	# Webfonts
	ExpiresByType font/ttf                      "access plus 4 months"
	ExpiresByType font/otf                      "access plus 4 months"
	ExpiresByType font/woff                     "access plus 4 months"
	ExpiresByType font/woff2                    "access plus 4 months"
	ExpiresByType image/svg+xml                 "access plus 4 months"
	ExpiresByType application/vnd.ms-fontobject "access plus 1 month"
	# CSS and JavaScript
	ExpiresByType text/css                      "access plus 1 year"
	ExpiresByType application/javascript        "access plus 1 year"
</IfModule>

HTACCESS;
        return $rules;
    }

    public function modifyModDeflate()
    {
        $rules = '# Enable GZIP' . PHP_EOL;
        $rules .= '<IfModule mod_deflate.c>' . PHP_EOL;
        $rules .= '# Activate Compression' . PHP_EOL;
        $rules .= 'SetOutputFilter DEFLATE' . PHP_EOL;
        $rules .= '# Force deflate for mangled headers' . PHP_EOL;
        $rules .= '<IfModule mod_setenvif.c>' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'SetEnvIfNoCase ^(Accept-EncodXng|X-cept-Encoding|X{15}|~{15}|-{15})$ ^((gzip|deflate)\s*,?\s*)+|[X~-]{4,13}$ HAVE_Accept-Encoding' . PHP_EOL;
        $rules .= 'RequestHeader append Accept-Encoding "gzip,deflate" env=HAVE_Accept-Encoding' . PHP_EOL;
        $rules .= '# Do not compress uncompresible content' . PHP_EOL;
        $rules .= 'SetEnvIfNoCase Request_URI \\' . PHP_EOL;
        $rules .= '\\.(?:gif|jpe?g|png|rar|zip|exe|flv|mov|wma|mp3|avi|swf|mp?g|mp4|webm|webp|pdf)$ no-gzip dont-vary' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
        $rules .= '# Compress All output with MIME-types' . PHP_EOL;
        $rules .= '<IfModule mod_filter.c>' . PHP_EOL;
        $rules .= 'AddOutputFilterByType DEFLATE application/atom+xml \
		                          application/javascript \
		                          application/json \
		                          application/rss+xml \
		                          application/vnd.ms-fontobject \
		                          application/x-font-ttf \
		                          application/xhtml+xml \
		                          application/xml \
		                          font/opentype \
		                          image/svg+xml \
		                          image/x-icon \
		                          text/css \
		                          text/html \
		                          text/plain \
		                          text/x-component \
		                          text/xml' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= 'Header append Vary: Accept-Encoding' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
        return $rules;
    }

    public function modifyForCaching()
    {
        // Multisite does not require rewrite rules
        if (is_multisite()) {
            return;
        }

        // Korean is having problems, does not require rules
        if ('ko_KR' === get_locale() || (defined('WPLANG') && 'ko_KR' === WPLANG)) {
            return;
        }

        // Get root base.
        $homeRoot = $this->extractUrlComponent(home_url(), PHP_URL_PATH);
        $homeRoot = isset($homeRoot) ? trailingslashit($homeRoot) : '/';

        $siteRoot = $this->extractUrlComponent(site_url(), PHP_URL_PATH);
        $siteRoot = isset($siteRoot) ? trailingslashit($siteRoot) : '';

        if (strpos(WPS_IC_CACHE, ABSPATH) === false && isset($_SERVER['DOCUMENT_ROOT'])) {
            $cacheRoot = '/' . ltrim(str_replace(sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT'])), '', WPS_IC_CACHE), '/');
        } else {
            $cacheRoot = '/' . ltrim($siteRoot . str_replace(ABSPATH, '', WPS_IC_CACHE), '/');
        }


        $http_host = $this->removeUrlProtocol(home_url());

        $rules = '';
        $gzip_rules = '';
        $enc = '';

        $cache_dir_path = '%{DOCUMENT_ROOT}/' . ltrim($cacheRoot, '/') . $http_host . '%{REQUEST_URI}';

        if (function_exists('gzencode')) {
            $rules = '<IfModule mod_mime.c>' . PHP_EOL;
            $rules .= 'AddType text/html .html_gzip' . PHP_EOL;
            $rules .= 'AddEncoding gzip .html_gzip' . PHP_EOL;
            $rules .= '</IfModule>' . PHP_EOL;
            $rules .= '<IfModule mod_setenvif.c>' . PHP_EOL;
            $rules .= 'SetEnvIfNoCase Request_URI \.html_gzip no-gzip' . PHP_EOL;
            $rules .= '</IfModule>' . PHP_EOL . PHP_EOL;
            $gzip_rules .= 'RewriteCond %{HTTP:Accept-Encoding} gzip' . PHP_EOL;
            $gzip_rules .= 'RewriteRule .* - [E=WPC_ENC:_gzip]' . PHP_EOL;
            $enc = '%{ENV:WPC_ENC}';
        }

        $rules .= '<IfModule mod_rewrite.c>' . PHP_EOL;
        $rules .= 'RewriteEngine On' . PHP_EOL;
        $rules .= 'RewriteBase ' . $homeRoot . PHP_EOL;
        $rules .= $this->sslRewrite();
        $rules .= $this->webpRewrite($cache_dir_path);
        $rules .= $gzip_rules;

        // TODO: Exclude Mobile?
        $mobileCacheEnabled = false;
        #if (!$mobileCacheEnabled) {
        $rules .= 'RewriteCond %{HTTP_USER_AGENT} "android|blackberry|iphone|ipod|iemobile|opera mobile|palmos|webos|googlebot-mobile" [NC]' . PHP_EOL;
        $rules .= 'RewriteRule .* - [E=WPC_MOBILE:mobile_]' . PHP_EOL;

        $rules .= 'RewriteCond %{REQUEST_METHOD} GET' . PHP_EOL;
        $rules .= 'RewriteCond %{QUERY_STRING} ^$' . PHP_EOL;

        $cookies = $this->rejectCookies();
        if ($cookies) {
            $rules .= 'RewriteCond %{HTTP:Cookie} !(' . $cookies . ') [NC]' . PHP_EOL;
        }

        $rules .= 'RewriteCond "' . $cache_dir_path . '/%{ENV:WPC_MOBILE}index.html' . $enc . '" -f' . PHP_EOL;
        $rules .= 'RewriteRule .* "' . $cacheRoot . $http_host . '%{REQUEST_URI}/%{ENV:WPC_MOBILE}index.html' . $enc . '" [L]' . PHP_EOL;
        #}

        $rules .= 'RewriteCond %{REQUEST_METHOD} GET' . PHP_EOL;
        $rules .= 'RewriteCond %{QUERY_STRING} ^$' . PHP_EOL;

        #$cookies = $this->rejectCookies();
        if ($cookies) {
            $rules .= 'RewriteCond %{HTTP:Cookie} !(' . $cookies . ') [NC]' . PHP_EOL;
        }

        // TODO: Excluded URLs from Cache?
        $excludedCacheUrls = false;
        if ($excludedCacheUrls) {
            $rules .= 'RewriteCond %{REQUEST_URI} !^(' . $excludedCacheUrls . ')$ [NC]' . PHP_EOL;
        }

        // Todo: Exclude User Agents (bots) from cache
        $excludeBots = false;
        if ($excludeBots) {
            $rules .= 'RewriteCond %{HTTP_USER_AGENT} !^(' . $excludeBots . ').* [NC]' . PHP_EOL;
        }

        $rules .= 'RewriteCond "' . $cache_dir_path . '/index.html' . $enc . '" -f' . PHP_EOL;
        $rules .= 'RewriteRule .* "' . $cacheRoot . $http_host . '%{REQUEST_URI}/index.html' . $enc . '" [L]' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        return $rules;
    }

    public function extractUrlComponent($url, $component)
    {
        return _get_component_from_parsed_url_array(wp_parse_url($url), $component);
    }

    public function removeUrlProtocol($url)
    {
        $url = preg_replace('#^(https?:)?\/\/#im', '', $url);
        return $url;
    }

    public function sslRewrite()
    {
        // Redirect non SSL to SSL
        $rules = '';
        #$rules .= 'RewriteCond %{HTTPS} off' . PHP_EOL;
        #$rules .= 'RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]' . PHP_EOL;
        // TODO: Check if this works
        $rules .= 'RewriteCond %{HTTPS} !=on' . PHP_EOL;
        $rules .= 'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]' . PHP_EOL;
        $rules .= 'RewriteCond %{HTTPS} on [OR]' . PHP_EOL;
        $rules .= 'RewriteCond %{SERVER_PORT} ^443$ [OR]' . PHP_EOL;
        $rules .= 'RewriteCond %{HTTP:X-Forwarded-Proto} https' . PHP_EOL;
        $rules .= 'RewriteRule .* - [E=WPC_SSL:-https]' . PHP_EOL;
        return $rules;
    }

    public function webpRewrite($cache_dir_path)
    {
        $rules = 'RewriteCond %{HTTP_ACCEPT} image/webp' . PHP_EOL;
        $rules .= 'RewriteCond "' . $cache_dir_path . '/.no-webp" !-f' . PHP_EOL;
        $rules .= 'RewriteRule .* - [E=WPC_WEBP:-webp]' . PHP_EOL;
        return $rules;
    }

    public function rejectCookies()
    {
        $logged_in_cookie = explode(COOKIEHASH, LOGGED_IN_COOKIE);
        $logged_in_cookie = array_map('preg_quote', $logged_in_cookie);
        $logged_in_cookie = implode('.+', $logged_in_cookie);

        $cookies = [];
        $cookies[] = $logged_in_cookie;
        $cookies[] = 'wp-postpass_';
        $cookies[] = 'wptouch_switch_toggle';
        $cookies[] = 'comment_author_';
        $cookies[] = 'comment_author_email_';
        return implode('|', $cookies);
    }

    public function removeHtaccessRules()
    {
        return true;

        $this->htaccessPath = $this->getHtaccessPath();
        if (!$this->htaccessPath) return;

        // Get Contents
        $this->htaccessContent = $this->getContents($this->htaccessPath);

        if (!$this->htaccessContent || empty($this->htaccessContent)) return;

        $removeExistingRules = preg_replace('/\s*#StartWPC-Cache.*#EndWPC-Cache\s*?/isU', PHP_EOL . PHP_EOL, $this->htaccessContent);
        $cleanedHtaccessContent = ltrim($removeExistingRules);

        if (!empty($cleanedHtaccessContent)) {

            if (!defined('FS_CHMOD_FILE')) {
                define('FS_CHMOD_FILE', 0644);
            }

            $this->fileSystem()->put_contents($this->htaccessPath, $cleanedHtaccessContent);
        }
    }

    public function setWPCache($status = true)
    {
        $error = false;
        $this->configPath = $this->getConfigPath();

        if (!$this->configPath) {
            return;
        }

        // Is the file writeable?
        if ($this->exists($this->configPath) && !$this->isWriteable($this->configPath)) {
            $error = true;
            $this->notice('not-writeable-config');
        }

        // Is the file readable?
        if ($this->exists($this->configPath) && !$this->isReadble($this->configPath)) {
            $error = true;
            $this->notice('not-readable-config');
        }

        if (!empty($error)) return;

        // Get Contents
        $configContents = $this->getContents($this->configPath);


        $cacheStatus = $status ? 'true' : 'false';
        $this->cacheConstant = str_replace('VALUE', $cacheStatus, $this->cacheConstant);


        if (!preg_match('/define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false)\s*\);/si', $configContents, $matches)) {
            #if (!preg_match('/define\(\'WP_CACHE\',\s*(true|false)\);/si', $configContents, $matches)) {
            $fileContents = preg_replace('/(<\?php)/i', "<?php\r\n{$this->cacheConstant}\r\n", $configContents, 1);
        } else {
            if ($cacheStatus === 'true') {
                $fileContents = preg_replace('/define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false)\s*\);/si', 'define(\'WP_CACHE\', true);', $configContents);
            } else {
                $fileContents = preg_replace('/define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false)\s*\);/si', '', $configContents);
                $fileContents = str_replace('// WP Compress Cache', '', $configContents);
            }
        }

        if (!empty($fileContents)) {
            //this was generating a fatal error on activation
            //$this->fileSystem()->put_contents($this->configPath, $fileContents);
            file_put_contents($this->configPath, $fileContents);
        }
    }


    public function getConfigPath()
    {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $config_file = ABSPATH . 'wp-config.php';

        if (empty($config_file) || !file_exists($config_file)) {
            return false;
        }

        // Create Backup
        $backupNamed = ABSPATH . 'wp-config-backup.php';
        if (!file_exists($backupNamed)) {
            copy($config_file, $backupNamed);
        }

        return $config_file;
    }

    public function setAdvancedCache()
    {
        $error = false;
        $this->advancedCachePath = $this->getAdvancedCachePath();

        if (!$this->advancedCachePath) {
            return;
        }

        // Is the file writeable?
        if ($this->exists($this->advancedCachePath) && !$this->isWriteable($this->advancedCachePath)) {
            $error = true;
            $this->notice('not-writeable-adv-cache');
        }

        // Is the file readable?
        if ($this->exists($this->advancedCachePath) && !$this->isReadble($this->advancedCachePath)) {
            $error = true;
            $this->notice('not-readable-adv-cache');
        }

        if ($error) return;

        // Get Contents
        $advancedCacheSample = $this->getContents(WPS_IC_DIR . 'templates/samples/advancedCacheSample.php');

        if (!empty($advancedCacheSample)) {
            //this was generating a fatal error on activation
            //$this->fileSystem()->put_contents($this->advancedCachePath, $advancedCacheSample);
            $settings = get_option(WPS_IC_SETTINGS);

            $cacheLoggedIn = 'false';
            if (!empty($settings['cache']['cache-logged-in']) && $settings['cache']['cache-logged-in'] == '1') {
                $cacheLoggedIn = 'true';
            }

            // Set cache logged in const in advanced-cache
            $pattern = "#WPC_CACHE_LOGGED_IN_START\r?\n(.+?)\r?\n#WPC_CACHE_LOGGED_IN_END";
            $replacement = "#WPC_CACHE_LOGGED_IN_START\n define('WPC_CACHE_LOGGED_IN' , $cacheLoggedIn );\n#WPC_CACHE_LOGGED_IN_END";
            $advancedCacheSample = preg_replace("/$pattern/s", $replacement, $advancedCacheSample);

            file_put_contents($this->advancedCachePath, $advancedCacheSample);
        }
    }

    public function getAdvancedCachePath()
    {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $config_file = ABSPATH . 'wp-content/advanced-cache.php';

        if (!file_exists($config_file)) {
            // Initialize the WP_Filesystem
            global $wp_filesystem;
            WP_Filesystem();
            $wp_filesystem->put_contents($config_file, "", 0644);
            //return false;

            if (!file_exists($config_file)) {
                return false;
            }

        }

        return $config_file;
    }

    public function removeAdvancedCache()
    {
        $error = false;
        $this->advancedCachePath = $this->getAdvancedCachePath();

        if (!$this->advancedCachePath) {
            return true;
        }

        // Is the file writeable?
        if ($this->exists($this->advancedCachePath) && !$this->isWriteable($this->advancedCachePath)) {
            $error = true;
            $this->notice('not-writeable-adv-cache');
        }

        // Is the file readable?
        if ($this->exists($this->advancedCachePath) && !$this->isReadble($this->advancedCachePath)) {
            $error = true;
            $this->notice('not-readable-adv-cache');
        }

        if ($error) return true;

        $this->fileSystem()->put_contents($this->advancedCachePath, '');
    }

    public function addWebpReplace()
    {
        if (!$this->isApache) {
            return;
        }

        $this->htaccessPath = $this->getHtaccessPath();
        if (!$this->htaccessPath) return;

        // Get Contents
        $this->htaccessContent = $this->getContents($this->htaccessPath);

        if (!$this->htaccessContent || empty($this->htaccessContent)) return;

        // Check if WebP rules already exist
        if ($this->hasWebpReplaceRules()) {
            return;
        }

        $webpRules = $this->getWebpReplaceRules();

        // Check if WordPress rules exist
        if (strpos($this->htaccessContent, '# BEGIN WordPress') !== false) {
            // Insert WebP rules before WordPress rules
            $newHtaccessContent = preg_replace('/# BEGIN WordPress/i', $webpRules . PHP_EOL . '# BEGIN WordPress', $this->htaccessContent);
        } else {
            // If no WordPress rules, add to the beginning of the file
            $newHtaccessContent = $webpRules . PHP_EOL . $this->htaccessContent;
        }

        if (!empty($newHtaccessContent)) {
            if (!defined('FS_CHMOD_FILE')) {
                define('FS_CHMOD_FILE', 0644);
            }

            $this->fileSystem()->put_contents($this->htaccessPath, $newHtaccessContent);
        }
    }

    private function hasWebpReplaceRules()
    {
        if (strpos($this->htaccessContent, '#StartWPC-WebP-Replace') !== false) {
            return true;
        }

        return false;
    }

    private function getWebpReplaceRules()
    {
        $rules = '#StartWPC-WebP-Replace' . PHP_EOL;
        $rules .= '<IfModule mod_rewrite.c>' . PHP_EOL;
        $rules .= '    RewriteEngine On' . PHP_EOL;
        $rules .= '    RewriteBase /' . PHP_EOL;
        $rules .= '    # Check if browser supports WebP images.' . PHP_EOL;
        $rules .= '    RewriteCond %{HTTP_ACCEPT} image/webp' . PHP_EOL;
        $rules .= '    # Check if WebP replacement image exists with REPLACED extension' . PHP_EOL;
        $rules .= '    RewriteCond %{DOCUMENT_ROOT}/$1.webp -f' . PHP_EOL;
        $rules .= '    # Serve WebP image instead - replacing extension, not appending' . PHP_EOL;
        $rules .= '    RewriteRule (.+)\.(jpe?g|png|gif)$ $1.webp [T=image/webp,L]' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '<IfModule mod_headers.c>' . PHP_EOL;
        $rules .= '    # Standard Vary header for WebP' . PHP_EOL;
        $rules .= '    Header append Vary Accept env=REQUEST_image' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '#EndWPC-WebP-Replace' . PHP_EOL;

        return $rules;
    }

    public function removeWebpReplace()
    {
        $this->htaccessPath = $this->getHtaccessPath();
        if (!$this->htaccessPath) return;

        // Get Contents
        $this->htaccessContent = $this->getContents($this->htaccessPath);

        if (!$this->htaccessContent || empty($this->htaccessContent)) return;

        // Check if WebP rules don't exist
        if (!$this->hasWebpReplaceRules()) {
            return;
        }

        $cleanedHtaccessContent = preg_replace('/\s*#StartWPC-WebP-Replace.*#EndWPC-WebP-Replace\s*?/isU', PHP_EOL, $this->htaccessContent);

        if (!empty($cleanedHtaccessContent)) {
            if (!defined('FS_CHMOD_FILE')) {
                define('FS_CHMOD_FILE', 0644);
            }

            $this->fileSystem()->put_contents($this->htaccessPath, $cleanedHtaccessContent);
        }
    }

    public function notice_not_readable_config()
    {
        $class = 'notice notice-error';
        $message = '<strong>Error!</strong> Seems like we are unable to read your config files, please contact support.';

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }

    public function notice_not_readable_htaccess()
    {
        $class = 'notice notice-error';
        $message = '<strong>Error!</strong> Seems like we are unable to read your htaccess file, please contact support.';

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }

    public function notice_not_readable_adv_cache()
    {
        $class = 'notice notice-error';
        $message = '<strong>Error!</strong> Seems like we are unable to read your advanced cache files, please contact support.';

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }

    public function notice_not_readable()
    {
        $class = 'notice notice-error';
        $message = '<strong>Error!</strong> Seems like we are unable to read some of your files, please contact support.';

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }

    public function notice_not_writeable_config()
    {
        $class = 'notice notice-error';
        $message = '<strong>Error!</strong> Seems like we are unable to write to your config file, please contact support.';

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }

    public function notice_not_writeable_adv_cache()
    {
        $class = 'notice notice-error';
        $message = '<strong>Error!</strong> Seems like we are unable to write to your advanced cache file, please contact support.';

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }

    public function notice_not_writeable_htaccess()
    {
        $class = 'notice notice-error';
        $message = '<strong>Error!</strong> Seems like we are unable to write to your htaccess file, please contact support.';

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }


}