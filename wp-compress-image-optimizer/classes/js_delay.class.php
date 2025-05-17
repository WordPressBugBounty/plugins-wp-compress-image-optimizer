<?php


class wps_ic_js_delay
{

    public static $excludes;
    public static $footerScripts;


    public static $doNotDelay = ['n489d_vars', 'flatsomeVars', 'ngf298gh738qwbdh0s87v_vars', 'optimize.js', 'optimize.dev.js', 'mhcookie', '_happyFormsSettings','wcpay_assets','trust','divi-custom-script-js-extra','jetpack-stats','stats.wp','checkout-js-extra','config-js-extra', 'nekitWidgetData', 'document.write', 'document.documentElement', 'presto', 'cdn-cookieyes.com', 'borlabs-cookie-config-js'];
    public static $lastLoadScripts = ['scripts.min.js', 'elementor', 'fusion-scripts', 'tracking', 'googletagmanager', 'gtag', 'jquery(document).ready', 'mouse', 'elementskit', 'ekit', 'gtranslate', 'translate', 'globe', 'draggable', 'theme-script', 'jet-', 'sortable', 'usercentric', 'parallax', 'dhvc-woocommerce/assets/js/script.js', 'repeater.js','fitvids', 'fusion', 'avada-scrollspy.js', 'jupiter','sticky','customer-reviews-woocommerce/js/frontend.js','tawk','jnews-main','/plugins/wpdatatables/','/plugins/wdt-powerful-filters/'];


    // Todo: Maybe add for newskit plugin "frontend-data-source,nekitWidgetData"
    public static $deferScripts = ['mediaelement', 'fitvid', 'jquery.min.js', 'jquery/ui', 'flexslide'];


    public function __construct()
    {
        self::$excludes = new wps_ic_excludes();
        self::$lastLoadScripts = array_merge(self::$lastLoadScripts, self::$excludes->lastLoadScripts());
        self::$deferScripts = array_merge(self::$deferScripts, self::$excludes->deferScripts());
    }


    public function printFooterScripts()
    {
        $html = '';

        if (!empty(self::$footerScripts)) {
            foreach (self::$footerScripts as $script) {
                $html .= $script[0];
            }
        }

        return $html . '</body>';
    }

    public function scriptsToFooter($tag)
    {
        $original_tag = $tag;
        if (is_array($tag)) {
            $tag = $tag[0];
        }
        if (is_array($tag)) {
            $tag = $tag[0];
        }
        if (self::$excludes->strInArray($tag, self::$excludes->scriptsToFooterExcludes())) {
            return $tag;
        }
        self::$footerScripts[] = $original_tag;
        return '';
    }


    public function preload_scripts($html)
    {
        $pattern = '/<script[^>]*src=[\'"]([^\'"]+)[\'"][^>]*id=[\'"]([^\'"]+)[\'"][^>]*>/si';
        $preloadTags = [];
        $matchesCount = 0;

        $html = preg_replace_callback($pattern, function ($matches) use (&$preloadTags, &$matchesCount) {
            $matchesCount++;

            if ($matchesCount > 2) {
                $fullTag = $matches[0];
                $src = $matches[1];
                $id = $matches[2];
                if (!empty($src)) {
                    if (strpos($src, 'google') === false && strpos($src, 'tracking') === false && strpos($src, 'optimize.js') === false && strpos($src, 'optimize.dev.js') === false && strpos($src, 'mediaelement') === false && strpos($src, 'stats.wp') === false) {
                        $preloadTags[] = '<link rel="none" href="' . htmlspecialchars($src) . '" as="script" class="wpc-preload-links" id="'.$id.'">';
                    }
                }

                return $fullTag;
            } else {
                return $matches[0];
            }
        }, $html);

        if (!empty($preloadTags)) {
            $html = preg_replace('/<\/head>/i', implode("\n", $preloadTags) . '</head>', $html, 1);
        }

        return $html;
    }


    public function removeNoDelay($tag)
    {
        if (is_array($tag)) {
            $tag = $tag[0];
        }

        $tagLower = strtolower($tag);

        // It's excluded
        if (strpos($tagLower, 'text/javascript-no-delay') !== false) {
            $tag = str_replace('type="text/javascript-no-delay"', 'type="text/javascript"', $tag);
        }

        return $tag;
    }


    public function isWooCartOrCheckout() {
        // Check if WooCommerce is active
        if ( class_exists( 'WooCommerce' ) ) {
            // Check if current page is Cart or Checkout
            if ( is_cart() || is_checkout() ) {
                return true;
            }
        }
        return false;
    }


    public function delay_script_replace($tag)
    {

        if (!empty($_GET['removeScripts'])) {
            return '';
        }

        if ($this->isWooCartOrCheckout()) {
            return $tag[0];
        }

        if (is_array($tag)) {
            $tag = $tag[0];
        }

        $tagLower = strtolower($tag);


        // Is the script excluded from DelayJS?
        if (self::$excludes->excludedFromDelay($tagLower)) {
            if (!strpos($tagLower, 'defer') && strpos($tagLower, 'jquery') === false) {
                $tag = str_replace('<script ', '<script data-wpc-att="excluded" ', $tag);
            }

            return $tag;
        }


        if ($this->checkKeyword($tagLower, self::$doNotDelay)) {
            // Do not delay these!!!
            return $tag;
        } else if ($this->checkKeyword($tagLower, self::$deferScripts)) {

            // Required for usercentrics plugin
            if (strpos($tagLower, 'loader.js') !== false || strpos($tagLower, 'uc-block') !== false) {
                // Find & Replace with defer
                $tag = preg_replace('/<script/i', '<script defer ', $tag, 1);
                return $tag;
            } else {
                // Find & Replace with defer
                $tag = preg_replace('/<script/i', '<script defer ', $tag, 1);
                return $tag;
            }

        } else if ($this->checkKeyword($tagLower, self::$lastLoadScripts)) {

            // Required for usercentrics plugin
            if (strpos($tagLower, 'loader.js') !== false || strpos($tagLower, 'uc-block') !== false) {
                // Find & Replace with delay
                if (preg_match('/<script[^>]*>/i', $tagLower, $matches) && strpos($matches[0], 'type=') === false) {
                    $tag = preg_replace('/<script/i', '<script type="wpc-delay-script"', $tag, 1);
                } else {
                    $tag = str_replace(['type="text/javascript"', "type='text/javascript'", 'type="application/javascript"', "type='application/javascript'"], 'type="wpc-delay-script"', $tag);
                }
                return $tag;
            }


            // Patches for scripts that need to run last?
            if (preg_match('/<script[^>]*>/i', $tagLower, $matches) && strpos($matches[0], 'type=') === false) {
                #$tag = preg_replace('/<script/i', '<script type="wpc-delay-last-script" data-from-wpc="128"', $tag, 1);
                $tag = preg_replace('/<script(?![^>]*\btype=)/i', '<script type="wpc-delay-last-script" data-from-wpc="128"', $tag, 1);
            } else {
                $tag = str_replace(['type="text/javascript"', "type='text/javascript'", 'type="application/javascript"', "type='application/javascript'"], 'type="wpc-delay-last-script" data-from-wpc="128"', $tag);
            }

            return $tag;
        } else {

            // Find & Replace with delay
            if (preg_match('/<script[^>]*>/i', $tagLower, $matches) && strpos($matches[0], 'type=') === false) {
                $tag = preg_replace('/<script/i', '<script type="wpc-delay-script"', $tag, 1);
            } else {
                $tag = str_replace(['type="text/javascript"', "type='text/javascript'", 'type="application/javascript"', "type='application/javascript'"], 'type="wpc-delay-script"', $tag);
            }

            return $tag;
        }

    }


    public function checkKeyword($tag, $keywordArray)
    {
        if (!empty($keywordArray)) {
            foreach ($keywordArray as $needle) {
                if (strpos($tag, $needle) !== false) {
                    return true; // Match found
                }
            }
        }

        return false; // No match found
    }


}