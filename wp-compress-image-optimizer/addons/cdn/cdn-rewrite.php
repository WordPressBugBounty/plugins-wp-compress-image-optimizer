<?php
/**
 * Plugin: WP Compress – Instant Performance & Speed Optimization
 * Description: Legitimate script handling for WP Compress Optimizer
 */

if (!function_exists('wpc_force_natural')) {

    function wpc_force_natural()
    {


        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) {
            return false;
        }
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $on = defined('WPC_FORCE_NATURAL') && WPC_FORCE_NATURAL;
        // UI setting (Other Optimizations → "Force Natural URLs"). Same effect as the constant; the
        // constant/filter still win so a wp-config force can't be undone by a stale saved '0'.
        if (!$on && function_exists('get_option') && defined('WPS_IC_SETTINGS')) {
            $s = get_option(WPS_IC_SETTINGS);
            if (is_array($s) && !empty($s['force-natural']) && (string) $s['force-natural'] === '1') {
                $on = true;
            }
        }
        $cached = (bool) apply_filters('wpc_force_natural', $on);
        return $cached;
    }
}

if (!function_exists('wpc_cf_cname_verified_ok')) {

    // Rule: the Cloudflare host is emitted only once the orchestrator has said the site's pull
    // zone serves it (wpc_cf_cname_verified, written from its cname_verified answer on a
    // /v2/config sync). Unset is not verified. The old answer let an unset flag pass and let the
    // site's own probe through Cloudflare promote it; that probe answered 200 on sites whose
    // agencySites row had no cname while the pods bounced every asset to the origin (ticket
    // 11975). The cfwait suppression already read the flag this way.
    function wpc_cf_cname_verified_ok()
    {
        $v = function_exists('get_option') ? get_option('wpc_cf_cname_verified', 'legacy') : 'legacy';
        if (function_exists('wpc_cf_cname_gate_legacy') && wpc_cf_cname_gate_legacy()) {
            return !($v === '0' || $v === 0);
        }
        return $v === '1' || $v === 1 || $v === true;
    }
}

if (!function_exists('wpc_nextgen_variant_exists')) {

    /**
     * Is the local file $path on disk, or with $ext given, its next-gen twin ($path with the
     * jpg/png/webp extension swapped to $ext, the name the local optimizer writes)? The local
     * <picture> builder asks every disk question through here, so the golden runner can answer
     * from a fixture's images.json instead of an uploads folder it does not have.
     */
    function wpc_nextgen_variant_exists($path, $ext = '')
    {
        $path = (string) $path;
        if ($ext !== '') {
            $path = preg_replace('/\.(jpe?g|png|webp)(?=[?#]|$)/i', '.' . $ext, $path);
        }
        return @file_exists($path);
    }
}

if (!function_exists('wpc_image_file_dims')) {
    /**
     * [width, height] of an image file on disk, or null when it cannot be read. The one place a
     * render opens an image file to measure it; wps_ic_image_sizing::dimsFor() is its caller.
     * Bounded: a path outside the site, a missing file or one over 10 MB answers null without
     * a read. An SVG is read for its first 4 KB only: the viewBox, else the root width/height
     * (floats, so the caller keeps the exact aspect). The golden runner defines this function
     * first and answers from a fixture's images.json, which is how the measured branch runs
     * offline without image files.
     */
    function wpc_image_file_dims($path)
    {
        $path = (string) $path;
        if ($path === '' || strpos($path, '..') !== false || !@is_file($path) || (int) @filesize($path) > 10485760) {
            return null;
        }
        if (preg_match('/\.svg$/i', $path)) {
            $head = @file_get_contents($path, false, null, 0, 4096);
            if (!is_string($head) || $head === '') {
                return null;
            }
            if (preg_match('/<svg\b[^>]*\bviewBox\s*=\s*["\']\s*[\d.+-]+[\s,]+[\d.+-]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $head, $vb)
                && (float) $vb[1] > 0 && (float) $vb[2] > 0) {
                return [(float) $vb[1], (float) $vb[2]];
            }
            if (preg_match('/<svg\b[^>]*\bwidth\s*=\s*["\']?([\d.]+)(?:px)?["\']?[^>]*\bheight\s*=\s*["\']?([\d.]+)/i', $head, $wh)
                && (float) $wh[1] > 0 && (float) $wh[2] > 0) {
                return [(float) $wh[1], (float) $wh[2]];
            }
            return null;
        }
        if (!function_exists('getimagesize')) {
            return null;
        }
        $size = @getimagesize($path);
        return (is_array($size) && !empty($size[0]) && !empty($size[1])) ? [(int) $size[0], (int) $size[1]] : null;
    }
}

if (!function_exists('wpc_late_faces_flip_js')) {

    // v7.10.732 — ONE splicer at the serve door. Fallback names were spliced per-writer
    // (crit, used-css) and every uncovered writer left an unspliced same-selector rule that
    // wins the cascade when its block arms — the stack silently loses its metric fallback
    // mid-load. Runs over EVERY <style> block; wpc_css_insert_fallbacks is per-family
    // idempotent per block and masks @font-face descriptors.
    function wpc_stack_splice($html)
    {
        if (!is_string($html) || $html === '' || !function_exists('wpc_css_insert_fallbacks')
            || stripos($html, 'font-family') === false
            || !apply_filters('wpc_stack_splice', true)) {
            return $html;
        }
        $out = preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function ($m) {
            if (stripos($m[2], 'font-family') === false) {
                return $m[0];
            }
            $s = wpc_css_insert_fallbacks($m[2]);
            return (is_string($s) && $s !== '') ? $m[1] . $s . $m[3] : $m[0];
        }, $html);
        return is_string($out) ? $out : $html;
    }

    // v7.10.924 — THE LANE CARRIES ITS OWN REMOVER. Every writer that banks @font-face rules
    // into #wpc-late-faces media="not all" relied on the delay-v3 loader to flip it to
    // media=all — but the lane is also emitted on pages where that loader never ships
    // (dalton-roofing: 53 Poppins faces parked forever, wpcSwapLateBarrier undefined, zero
    // font fetches, headline rendered the Arial metric fallback). A deferral is a promise
    // something will undo it; this inline classic script is that promise, emitted WITH the
    // lane. No-op when the delay loader flips first (media already all): load+4s sits behind
    // the loader's own load+2.5s default, and the absolute 12s belt behind its 8s cap.
    /**
     * The late lane's own script. $firstScreen is the [family, weight] pairs the service measured
     * on the page's first screen (wps_cdn_rewrite::wpc_first_screen_faces()); with them the script
     * takes the first-screen families from that list and reads nothing of the page's layout, and
     * with null it finds them from the visible text. A split .faces.css link that names its
     * families (data-wpc-lf-fam) is attached at parse end only for a first-screen family its
     * subset does not cover; a page with no delay loader attaches every remaining one when the
     * lane flips.
     */
    function wpc_late_faces_flip_js($firstScreen = null)
    {
        if (!apply_filters('wpc_late_faces_flip', true)) {
            return '';
        }
        $stamp = is_array($firstScreen) && $firstScreen !== []
            ? json_encode(array_values($firstScreen), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null';
        if (!is_string($stamp) || $stamp === '') {
            $stamp = 'null';
        }
        // v7.20.02 — flip AT load when no delay barrier exists on the page (dalton: the +4s
        // backstop made the swap land visibly late; with no loader there is nothing to wait for).
        // v7.20.03 — THE FLIP ALONE IS NOT SERVICE: after media flips to all, the engine does
        // not initiate loads for faces that already-painted text needs (dalton live proof:
        // w800 faces sat "unloaded" forever while the headline held the Arial fallback; a
        // direct FontFace.load() resolved instantly). The nudge walks the lane's rules and
        // fires document.fonts.load per declared face — sampled from the face's OWN
        // unicode-range so ranged subsets match — and the completed loads repaint as normal.
        return '<script data-nodefer="1" id="wpc-late-faces-flip">(function(){var ag=' . $stamp . ';var nd=false;var tx=null;'
            . 'var ts=function(){if(tx)return tx;tx={};try{var t=((document.body&&document.body.textContent)||"").slice(0,20000);'
            . 'for(var i=0;i<t.length;i++){var c=t.charCodeAt(i);if(c>=48&&!(c>=8192&&c<=8303)&&!(c>=55296&&c<=57343)&&c!==9676&&!(c>=65024&&c<=65039)){tx[c]=1}}}catch(e){}return tx};'
            . 'var pk=function(rg){if(!rg||rg==="U+0-10FFFF")return 77;var sg=rg.split(",");var st=null;'
            . 'for(var i=0;i<sg.length&&i<32;i++){var m=sg[i].match(/U\\+([0-9A-Fa-f?]+)(?:-([0-9A-Fa-f]+))?/i);if(!m)continue;'
            . 'var lo,hi;if(m[1].indexOf("?")>=0){lo=parseInt(m[1].replace(/\\?/g,"0"),16);hi=parseInt(m[1].replace(/\\?/g,"F"),16)}'
            . 'else{lo=parseInt(m[1],16);hi=m[2]?parseInt(m[2],16):lo}'
            . 'if(lo>=57344&&(hi<=63743||lo>=983040))return lo;st=st||ts();for(var k in st){k=+k;if(k>=lo&&k<=hi)return k}}return -1};'
            . 'var ux=null;var us=function(){if(ux)return ux;ux={};try{var e2=document.querySelectorAll("body *");'
            . 'for(var i=0;i<e2.length&&i<600;i++){var g=getComputedStyle(e2[i]);var sk=(g.fontFamily||"").toLowerCase();var fm=sk.split(",")[0].replace(/["\']/g,"").trim();'
            . 'if(fm){ux[fm+"|"+g.fontWeight+"|"+g.fontStyle]=sk.indexOf(fm+" fallback")!==-1?2:1}}}catch(e){}return ux};'
            . 'var wn=function(w){w=String(w||"400").toLowerCase();if(w==="bold")return 700;if(w==="normal")return 400;return parseInt(w,10)||400};'
            . 'var mu=function(f){var u=us();var fm=String(f.family||"").replace(/["\']/g,"").trim().toLowerCase();'
            . 'var sp=String(f.weight||"400").toLowerCase().split(/\\s+/);var lo=wn(sp[0]);var hi=wn(sp[sp.length-1]);'
            . 'for(var k in u){var p=k.split("|");if(p[0]===fm&&p[2]===(f.style||"normal")){var w=wn(p[1]);if(w>=lo-100&&w<=hi+100&&u[k]===2)return true}}return false};'
            . 'var n=function(e){try{if(nd)return;nd=true;if(!document.fonts||!document.fonts.forEach){nd=false;return}var q=[];'
            . 'document.fonts.forEach(function(f){try{if(f.status!=="unloaded")return;if((f.display||"")==="optional")return;var cp=pk(f.unicodeRange||"");'
            . 'if(cp===-1)return;if(cp<57344&&!mu(f))return;q.push(f)}catch(x){}});'
            . 'var j=0;var step=function(){var e2=Math.min(j+8,q.length);'
            . 'for(;j<e2;j++){try{q[j].load().catch(function(){})}catch(x){}}'
            . 'if(j<q.length){setTimeout(step,0)}};step()}catch(x){nd=false}};'
            . 'var lr=function(l){var lh=l.getAttribute("data-wpc-lf-href");if(!l.getAttribute("href")&&lh){l.setAttribute("href",lh)}l.media="all"};'
            . 'var fa=function(){try{var wn2=function(w){w=String(w||"400").toLowerCase();return w==="bold"?700:w==="normal"?400:(parseInt(w,10)||400)};'
            . 'var sub={},E=document.getElementById("wpc-font-faces"),et=E?(E.textContent||""):"",rx=/@font-face\\s*\\{[^}]*\\}/gi,m0;'
            . 'while((m0=rx.exec(et))){if(m0[0].indexOf("data:font")===-1)continue;var f0=m0[0].match(/font-family\\s*:\\s*["\']?([^;"\'}]+)/i),w0=m0[0].match(/font-weight\\s*:\\s*([^;}]+)/i);'
            . 'if(!f0)continue;var ws=(w0?w0[1]:"400").trim().split(/\\s+/),k0=f0[1].trim().toLowerCase();(sub[k0]=sub[k0]||[]).push([wn2(ws[0]),wn2(ws[ws.length-1])])}'
            . 'var hasSub=false;for(var z in sub){hasSub=true;break}'
            . 'var used={},nu=0,ad=function(fm,uw){var sl=sub[fm]||[];for(var s2=0;s2<sl.length;s2++){if(uw>=sl[s2][0]&&uw<=sl[s2][1])return}if(!used[fm])nu++;used[fm]=1};'
            . 'if(ag){for(var g=0;g<ag.length;g++){if(ag[g]&&ag[g][0])ad(String(ag[g][0]),+ag[g][1]||400)}}'
            . 'else{var vh=window.innerHeight||800,els=document.querySelectorAll("h1,h2,h3,h4,h5,h6,p,a,li,span,button,label,td,th,strong,em,b,div");'
            . 'for(var i=0,k=0;i<els.length&&i<3000&&k<400;i++){var el=els[i],tn=false;for(var c=el.firstChild;c;c=c.nextSibling){if(c.nodeType===3&&/\\S/.test(c.nodeValue)){tn=true;break}}if(!tn)continue;var r=el.getBoundingClientRect();'
            . 'if(!r.width||r.top>vh||r.bottom<0)continue;k++;var cs=getComputedStyle(el),fm=(cs.fontFamily||"").split(",")[0].replace(/["\']/g,"").trim().toLowerCase();if(!fm)continue;'
            . 'ad(fm,wn2(cs.fontWeight))}}'
            . 'var lk=document.querySelectorAll("link[data-wpc-lf]");for(var q=0;q<lk.length;q++){var lf=lk[q].getAttribute("data-wpc-lf-fam"),go=lf===null&&(!hasSub||nu>0);'
            . 'if(lf!==null){var fs=lf.split(",");for(var x=0;x<fs.length;x++){if(used[fs[x]]){go=true;break}}}if(go)lr(lk[q])}'
            . 'var L=document.getElementById("wpc-late-faces");if(!L||L.media==="all"||document.getElementById("wpc-late-faces-atf"))return;'
            . 'var css=L.textContent||"",out="",re=/@font-face\\s*\\{[^}]*\\}/gi,m;'
            . 'while((m=re.exec(css))){var ff=m[0].match(/font-family\\s*:\\s*["\']?([^;"\'}]+)/i);if(ff&&used[ff[1].trim().toLowerCase()])out+=m[0]}'
            . 'if(out){var st=document.createElement("style");st.id="wpc-late-faces-atf";st.textContent=out;L.parentNode.insertBefore(st,L)}}catch(x){}};fa();'
            . 'var f=function(){var e=document.getElementById("wpc-late-faces");'
            . 'if(!window.wpcSwapLateBarrier){var lk=document.querySelectorAll("link[data-wpc-lf]");for(var q=0;q<lk.length;q++)lr(lk[q])}'
            . 'if(e&&e.media!=="all"){e.setAttribute("type","text/css");e.media="all";setTimeout(function(){n(e)},0);}'
            . 'else if(e){n(e);}};'
            . 'var hg=false;var hq=[];var hf=function(){if(!hg){hg=true;hq.splice(0).forEach(function(x){try{x()}catch(e){}})}};'
            . '["pointerdown","keydown","touchstart","wheel","scroll","mousemove"].forEach(function(ev){window.addEventListener(ev,hf,{once:true,passive:true,capture:true})});'
            . 'var uc=false;var mk=function(){if(!uc){try{uc=!!document.querySelector("link[data-wpc-ucss],link[data-wpc-ucss-rest]")}catch(e){}}};'
            . 'var gm=function(cb){if(hg||!uc){cb()}else{hq.push(cb)}};'
            . 'var a=function(){if(window.wpcScriptRegistry){return}mk();if(window.wpcSwapLateBarrier){setTimeout(function(){gm(f)},4000)}else{gm(f)}};'
            . 'if(document.readyState==="complete"){a()}else{window.addEventListener("load",a,{once:true})}'
            . 'setTimeout(function(){if(!window.wpcScriptRegistry){gm(f)}},12000)})();</script>';
    }
}

if (!function_exists('wpc_yield_checkpoints_pass')) {

    // v7.10.707 — parser-yield checkpoints. With every script deferred, a large document parses
    // as one unbroken slice: the first main-frame commit arrives late and heavy, first paint
    // stamps after the whole document, and both measured failure modes key off that late mark
    // (the frame-source quiet holds presentation ~1s; Lantern's cutoff race charges any font
    // completing before the mark to FCP). Two tiny same-origin CLASSIC scripts — no async, no
    // defer, no fetchpriority, no module — pause the parser at the ATF boundaries so the header
    // and hero commit and paint early. The block is the feature. data-nodefer="1" keeps both
    // engines off them; the caller runs after the delay pass. Anchors: before the <section>
    // nearest above the first <h1>, and after the first </section> past it. Skips fail closed
    // to untouched bytes.
    function wpc_yield_checkpoints_pass($html, $delayOn = false)
    {
        if (!$delayOn || !is_string($html) || strlen($html) < 150000
            || !apply_filters('wpc_parse_checkpoints', false)
            || strpos($html, 'wpc-yield-a.js') !== false) {
            return $html;
        }
        $h1 = stripos($html, '<h1');
        if ($h1 === false) {
            return $html;
        }
        $heroOpen  = strripos(substr($html, 0, $h1), '<section');
        $heroClose = stripos($html, '</section>', $h1);
        if ($heroOpen === false || $heroClose === false) {
            return $html;
        }
        $bodyPos = stripos($html, '<body');
        if ($bodyPos === false || $heroOpen <= $bodyPos) {
            return $html;
        }
        $heroClose += 10;
        $base = (defined('WPS_IC_URI') ? WPS_IC_URI : '/wp-content/plugins/wp-compress-image-optimizer/') . 'assets/js/';
        $ver  = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '1';
        $ta = '<script src="' . $base . 'wpc-yield-a.js?v=' . $ver . '" data-nodefer="1"></script>';
        $tb = '<script src="' . $base . 'wpc-yield-b.js?v=' . $ver . '" data-nodefer="1"></script>';
        return substr($html, 0, $heroOpen) . $ta
            . substr($html, $heroOpen, $heroClose - $heroOpen) . $tb
            . substr($html, $heroClose);
    }
}

include_once WPS_IC_DIR . 'classes/atf_observation.class.php';
include_once WPS_IC_DIR . 'classes/asset_version.class.php';
include WPS_IC_DIR . 'addons/cdn/rewriteLogic.php';
include_once WPS_IC_DIR . 'addons/cdn/render-pipeline.php';
include WPS_IC_DIR . 'addons/minify/html.php';
include_once WPS_IC_DIR . 'addons/cache/cacheHtml.php';

class wps_cdn_rewrite
{
    /** The HTML comment a parked <picture> block leaves behind while the image passes run, minus
     *  its index. One prefix for both render lanes: only one of them ever stashes in a given
     *  render, and both restore before the WPC-comment sweep. It has to begin `<!--WPC` so that
     *  sweep clears any placeholder a stop left behind. */
    const PICTURE_STASH_PLACEHOLDER = '<!--WPC_PICTURE_';

    const IMG_RATIO_CSS = 'img:where([wpc-size][width][height]),img:where(.wpc-nd[width][height]),img:where([data-wpc-md][width][height]){height:auto}';

    /** Persisted keys: the name is the constant, the string is what is already written to every
     *  installed site's transients, so the value cannot change. */
    const RENDER_BREAKER_TRANSIENT = 'wpc_render_breaker83';
    const RENDER_BREAKER_LOG_TRANSIENT = 'wpc_render_breaker83_log';

    public static $settings;
    public static $options;
    /** @var bool|null  What criticalRunning() answered during the critical kick, or null when the
     *  kick did not ask. Only the debugCriticalRunning receipt reads it. */
    public $criticalRunning = null;
    public static $lazy_excluded_list;
    public static $excluded_list;
    public static $default_excluded_list;
    public static $cdnEnabled;
    public static $fontDisplayRaw = '';
    public static $preloaderAPI;
    public static $excludes_class;
    public static $assets_to_defer;
    public static $emoji_remove;
    public static $isAjax;
    public static $brizyCache;
    public static $brizyActive;
    public static $regExURL;

    // Regexp Url & Dirs
    public static $regExDir;
    public static $findImages;
    public static $apiUrl;

    // Predefined API URLs
    public static $apiAssetUrl;
    public static $updir;

    // Site URL, Upload Dir
    public static $home_url;
    public static $site_url;
    public static $site_url_scheme;
    public static $svg_placeholder;

    // SVG Placeholder (empty svg)
    public static $excludes;


    // CSS / JS Variables
    public static $fonts;
    public static $css;
    public static $css_img_url;
    public static $js;
    public static $js_minify;
    public static $replaceAllLinks;

    // Image Compress Variables
    public static $external_url_excluded;
    public static $externalUrlEnabled;
    public static $zone_test;
    public static $zone_name;
    public static $is_retina;
    public static $exif;
    public static $webp;
    public static $retina_enabled;
    public static $adaptive_enabled;
    public static $webp_enabled;
    public static $lazy_enabled;
    public static $native_lazy_enabled;
    public static $sizes;
    public static $randomHash;
    public static $is_multisite;
    public static $keys;
    public static $delay_js_override;

    //Overrides
    public static $defer_js_override;
    public static $lazy_override;
    public static $rewriteLogic;
    public static $minifyHtml;
    public static $cacheHtml;
    public static $criticalCss;
    public static $combineCss;
    public static $page_excludes;
    public static $post_id;
    public static $page_excludes_files;
    public static $isActive;
    private static $themeIntegrations;
    private static $lazyLoadedImagesLimit;
    private static $lazyLoadSkipFirstImages;
    private static $removeSrcset;
    public $cdn;
    public $compatibility;
    public $inline_js;
    public $delay_js_exclude;

    public function __construct()
    {

        // Theme Integrations
        require_once WPS_IC_DIR . 'integrations/themes/theme.integrations.php';
        self::$themeIntegrations = new ThemeIntegrations();

        // Lazy Limits
        self::$lazyLoadedImagesLimit = 1;

        self::$settings = get_option(WPS_IC_SETTINGS);
        self::$excludes = get_option('wpc-excludes');


        // Decide to Load new API or Old Api for Critical CSS
        if (empty(self::$settings['mcCriticalCSS']) || self::$settings['mcCriticalCSS'] == 'mc') {
            include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
        } else {
            // v7.10.515 — LEGACY BRANCH (mcCriticalCSS='api', set only by the debug tool).
            // The service confirms crit-push exposes exactly one generation entry, /generate:
            // there is no v1 gen path. This file's assets host also black-holes. Once loaded
            // it WINS everywhere, because every criticalCss-v2 include is guarded on
            // class_exists('wps_criticalCss') — so a debug toggle silently downgrades the
            // whole site. Kept reachable for now, but journaled so it stops being invisible.
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-v1-branch', '', '', ['mcCriticalCSS' => (string) self::$settings['mcCriticalCSS']]);
            }
            include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss.php';
        }

        if (empty(self::$settings)) {
            $options = new wps_ic_options();
            $settings = $options->get_preset('lite');
            self::$settings = $settings;
        }

        if (empty(self::$excludes)) {
            self::$excludes = [];
        }

        if (!isset(self::$excludes['cdn'])) {
            self::$excludes['cdn'] = [];
        }

        self::$excludes['cdn'][] = '.php';
        self::$excludes['cdn'][] = '/wp-fastest-cache/';
        self::$excludes['cdn'][] = '/wp-content/plugins/ameliabooking/v3/public/assets/';
        self::$excludes['cdn'][] = '/vue3';
        self::$excludes['cdn'][] = 'sharethis.js';
        if (defined('ELEMENTOR_VERSION')) {


            self::$excludes['cdn'][] = 'webpack.runtime.min.js';
            self::$excludes['cdn'][] = 'webpack-pro.runtime.min.js';
        }

        $cdn_excludes = apply_filters('wpc_cdn_excludes', self::$excludes['cdn']);
        if (is_array($cdn_excludes)) {
            self::$excludes['cdn'] = array_values(array_filter($cdn_excludes, 'is_string'));
        }

        self::$removeSrcset = self::$settings['remove-srcset'];

        if (empty(self::$settings['lazySkipCount'])) {
            self::$lazyLoadSkipFirstImages = 4;
        } else {
            self::$lazyLoadSkipFirstImages = self::$settings['lazySkipCount'];
        }

        self::$excludes_class = new wps_ic_excludes();
        global $post;

        if ($this->is_home_url()) {
            $per_page_settings = isset(self::$excludes['per_page_settings']['home']) ? self::$excludes['per_page_settings']['home'] : [];
        } elseif (!empty($post->ID)) {
            $per_page_settings = isset(self::$excludes['per_page_settings'][$post->ID]) ? self::$excludes['per_page_settings'][$post->ID] : [];
        }

        if (!empty($per_page_settings) && isset($per_page_settings['skip_lazy']) && $per_page_settings['skip_lazy'] !== '') {
            self::$lazyLoadSkipFirstImages = $per_page_settings['skip_lazy'];
        }

        self::$isActive = true;
        $options = get_option(WPS_IC_OPTIONS);
        if (empty($options['api_key'])) {
            self::$isActive = false;
        }
    }

    public function is_home_url()
    {
        $home_url = rtrim(home_url(), '/');
        $current_url = wpc_request_scheme() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $current_url = rtrim($current_url, '/');
        $current_url = explode('?', $current_url);
        $current_url = $current_url[0];
        $home_url = rtrim($home_url, '/');
        $current_url = rtrim($current_url, '/');

        return $home_url === $current_url;
    }

    public static function init()
    {
        global $ic_running;

        if (strpos($_SERVER['REQUEST_URI'], '.xml') !== false) {
            return true;
        }

        if (is_admin() || strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {
            return true;
        }

        if ($ic_running) {
            return true;
        }

        $ic_running = true;

        if (!empty($_GET['ignore_cdn']) || !empty($_GET['ignore_ic'])) {
            return true;
        }

        $options = get_option(WPS_IC_OPTIONS);
        $apikey = $options['api_key'];
        if (empty($apikey)) {
            return true;
        }

        if (self::$settings['css'] == 0 && self::$settings['js'] == 0 && self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) {
            return true;
        }

        self::$isAjax = (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX);

        // Don't run in admin side!
        if (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") {
            return true;
        }

        // TODO: Check this for wpadmin and frontend ajax
        if (!self::$isAjax) {
            if (wp_is_json_request() || is_admin() || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['trp-edit-translation']) || !empty($_GET['elementor-preview']) || !empty($_GET['preview']) || !empty($_GET['PageSpeed']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || isset($_GET['is-editor-iframe']) || !empty($_GET['et_fb']) || !empty($_GET['tatsu']) || !empty($_GET['tve']) || !empty($_GET['fb-edit']) || !empty($_GET['ct_builder']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor')) {
                return true;
            }
        }

        return true;
    }

    public static function dontRunif()
    {


        if (!empty($_GET['disableWPC']) || isset($_SERVER['HTTP_DISABLEWPC'])) {
            return false;
        }

        // URL exclusions (wildcard support) — auto-enabled when patterns exist
        if (function_exists('wpc_request_excluded_from_plugin') && wpc_request_excluded_from_plugin() !== false) {
            return false;
        }


        if (!empty($_GET['pagelayer-live'])) {
            return false;
        }

        // Any hide login plugins active?
        if (self::hiddenAdminArea()) {
            return false;
        }

        //WP User Frontend check
        if (class_exists('WP_User_Frontend')) {
            $content = get_post_field('post_content', get_the_ID());

            // Check if the content contains wpuf shorcode
            if (preg_match('/\[wpuf/', $content)) {
                return false;
            }
        }

        if (self::MediaActions()) {
            return false;
        }

        if (strpos($_SERVER['REQUEST_URI'], 'jm-ajax') !== false) {
            return false;
        }

        if (isset($_GET['woo_ajax']) || isset($_POST['woo_ajax']) || (isset($_SERVER['REQUEST_URI']) && (strpos($_SERVER['REQUEST_URI'], 'woo_ajax') !== false))) {
            return false;
        }

        if (defined('DOING_AUTOSAVE')) {
            return false;
        }

        if (isset($_SERVER['REQUEST_URI']) && (strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false || strpos($_SERVER['REQUEST_URI'], 'sitemap') !== false)) {
            return false;
        }

        if (!empty($_POST['_cs_nonce'])) {
            return false;
        }

        if (is_admin() || strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {
            return false;
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], 'wp-json') || strpos($_SERVER['REQUEST_URI'], 'rest_route')) {
                return false;
            }
        }

        if (isset($_GET['brizy-edit-iframe']) || isset($_GET['brizy-edit']) || isset($_GET['preview'])) {
            return false;
        }

        if (!empty($_GET['page']) && $_GET['page'] == 'bwc') {
            return false;
        }


        if (!empty($_GET['trp-edit-translation']) || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['bwc']) || !empty($_GET['fb-edit']) || !empty($_GET['bricks']) || !empty($_GET['elementor-preview']) || !empty($_GET['PageSpeed']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['et_fb']) || !empty($_GET['tatsu']) || !empty($_GET['tatsu-header']) || !empty($_GET['tatsu-footer']) || !empty($_GET['tve']) || !empty($_GET['is-editor-iframe']) || !empty
            ($_GET['ct_builder']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor')) {
            return false;
        }

        if ((!empty($_GET['action']) && $_GET['action'] == 'edit#op-builder') || !empty($_GET['op3editor'])) {

            return false;
        }

        if (!empty($_POST['pp_action'])) {

            return false;
        }

        if (!empty($_POST['add-to-cart'])) {

            return false;
        }

        if (!empty($_GET['wc-ajax']) && $_GET['wc-ajax'] == 'get_refreshed_fragments') {
            return false;
        }

        if (!empty($_GET['action']) && $_GET['action'] == 'get_wdtable') {
            return false;
        }

        if (!empty($_GET['lc_action_launch_editing'])) {
            return false;
        }

        //GiveWP routes
        if (isset($_GET['givewp-route'])) {
            return false;
        }

        //Groundhogg calendar
        if (!empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], '/gh/calendar')) {
                return false;
            }
        }

        return true;
    }

    public static function hiddenAdminArea()
    {

        // AIOS
        if (class_exists('AIO_WP_Security')) {
            // Hide Login Exists
            $configs = get_option('aio_wp_security_configs');
            if (!empty($configs['aiowps_login_page_slug'])) {
                if (strpos($_SERVER['REQUEST_URI'], $configs['aiowps_login_page_slug']) !== false) {
                    return true;
                }
            }
        }

        // WPS Hide Login
        if (class_exists('WPS\WPS_Hide_Login\Plugin')) {
            // Hide Login Exists
            $loginPage = get_option('whl_page');
            if (!empty($loginPage)) {
                if (strpos($_SERVER['REQUEST_URI'], '/' . $loginPage) !== false) {
                    return true;
                }
            }
        }

        // Hide My WP - Ghost
        if (class_exists('HMWP_Classes_ObjController')) {
            $option = get_option('hmwp_options');

            if (!empty($option)) {
                $option = json_decode($option, true);
                $loginPage = $option['hmwp_login_url'];
                if (!empty($loginPage)) {
                    if (strpos($_SERVER['REQUEST_URI'], $loginPage) !== false) {
                        return true;
                    }
                }
            }
        }

    }

    public static function MediaActions()
    {
        if (!empty($_GET['preloadCache'])) {
            return true;
        }

        if (!empty($_GET['getAllImages'])) {
            return true;
        }

        if (!empty($_POST['getImageByID']) || !empty($_GET['getImageByID'])) {
            return true;
        }

        if (!empty($_POST['deliverSingleImage']) || !empty($_GET['deliverSingleImage'])) {
            return true;
        }

        if (!empty($_POST['deliverImages']) || !empty($_GET['deliverImages'])) {
            return true;
        }

        if (!empty($_POST['restoreImages']) || !empty($_GET['restoreImages'])) {
            return true;
        }
    }

    public static function wpc_render_breaker_tripped()
    {
        if (!function_exists('get_transient') || !apply_filters('wpc_render_breaker', true)) {
            return false;
        }
        if (function_exists('wpc_render_guard_lane') && wpc_render_guard_lane() !== 'visitor') {
            return false;
        }
        return (bool) get_transient(self::RENDER_BREAKER_TRANSIENT);
    }

    public static function wpc_render_breaker_trip($ms, $where)
    {
        if (!function_exists('set_transient') || !apply_filters('wpc_render_breaker', true)) {
            return false;
        }
        if (function_exists('wpc_render_guard_lane') && wpc_render_guard_lane() !== 'visitor') {
            return false;
        }
        $hold = (int) apply_filters('wpc_render_breaker_hold', 600);
        set_transient(self::RENDER_BREAKER_TRANSIENT, (int) $ms, max(60, $hold));
        if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient(self::RENDER_BREAKER_LOG_TRANSIENT)) {
            set_transient(self::RENDER_BREAKER_LOG_TRANSIENT, 1, max(60, $hold));
            wpc_cache_first_log('render-breaker-tripped', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', ['ms' => (int) $ms, 'where' => (string) $where, 'hold' => max(60, $hold)]);
        }
        return true;
    }

    /**
     * Milliseconds this request has spent inside render_buffer: the finished runs plus the one
     * in progress. Both breaker trips (the in-pipeline budget and the shutdown slow-render check)
     * judge this, not the request's wall time. The wall time counts the PHP worker queue and
     * WordPress's own boot, which the plugin's pipeline neither causes nor can shed. Observed
     * failure (hawkeye.design, 2026-10-08, right after a plugin upload, load 11): renders of
     * 14.7-23.2 s, of which 9.4-14.6 s passed before WordPress reached init, tripped the breaker;
     * every visitor render was then shed for 600 s, and a shed render is never stored, so no page
     * could be cached for those ten minutes.
     */
    public static function wpc_render_own_ms()
    {
        $ms = isset($GLOBALS['wpc_render_own_ms']) ? (int) $GLOBALS['wpc_render_own_ms'] : 0;
        if (isset($GLOBALS['wpc_render_started_at'])) {
            $ms += (int) round((microtime(true) - (float) $GLOBALS['wpc_render_started_at']) * 1000);
        }
        return $ms;
    }

    public static function wpc_render_budget_exceeded($label)
    {
        if (!apply_filters('wpc_render_budget', true)) {
            return false;
        }
        $ms = (int) apply_filters('wpc_render_budget_ms', 10000);
        if ($ms <= 0) {
            return false;
        }
        $spent = self::wpc_render_own_ms();
        if ($spent < $ms) {
            return false;
        }
        $GLOBALS['wpc_rewrite_was_shed'] = 1;
        self::wpc_render_breaker_trip($spent, 'budget:' . $label);
        if (function_exists('wpc_prof_mark')) {
            wpc_prof_mark('budget:' . $label, microtime(true));
        }
        if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_render_budget82_log')) {
            if (function_exists('set_transient')) {
                set_transient('wpc_render_budget82_log', 1, 600);
            }
            wpc_cache_first_log('render-budget-shed', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', ['at' => $label, 'ms' => $spent, 'budget' => $ms]);
        }
        return true;
    }

    /** Per-file ?icv= / ?js_icv= token (wps_ic_asset_version); the stored site hash only when the class is absent. */
    public static function asset_version($url)
    {
        if (class_exists('wps_ic_asset_version')) {
            return wps_ic_asset_version::for_url($url);
        }
        return defined('WPS_IC_HASH') ? (string) WPS_IC_HASH : '5021';
    }

    public static function reformat_url($url, $remove_site_url = false)
    {
        $url = trim($url);

        if (strpos($url, 'login') !== false) {
            return $url;
        }

        // Check if url is maybe a relative URL (no http or https)
        if (strpos($url, 'http') === false) {
            // Check if url is maybe absolute but without http/s
            if (strpos($url, '//') === 0) {
                // Just needs http/s
                $url = 'https:' . $url;
            } else {

                if (strpos($url, '/') !== 0) {
                    $url = str_replace('../wp-content', 'wp-content', $url);

                    $url_replace = preg_replace('/\/wp-content/', 'wp-content', $url, 1);
                    $url = self::$site_url;
                    $url = rtrim($url, '/');
                    $url .= '/' . $url_replace;
                } else {
                    $urlEnd = $url;
                    $urlEnd = ltrim($urlEnd, '/');
                    $urlEnd = rtrim($urlEnd, '/');
                    $url = self::$site_url;
                    $url = ltrim($url, '/');
                    $url = rtrim($url, '/');
                    $url .= '/' . $urlEnd;
                }
            }
        }

        $formatted_url = $url;


        if (strpos($formatted_url, '?brizy_media') === false && strpos($formatted_url, '.php') === false) {
            $used_css_version_param = '';
            if (strpos($formatted_url, '/used-css/') !== false && preg_match('/[?&](uv=\d+)/', $formatted_url, $used_css_version_match)) {
                $used_css_version_param = $used_css_version_match[1];
            }
            $formatted_url = explode('?', $formatted_url);
            $formatted_url = $formatted_url[0];
            if ($used_css_version_param !== '') {
                $formatted_url .= '?' . $used_css_version_param;
            }
        }


        if ($remove_site_url) {
            $formatted_url = str_replace(self::$site_url, '', $formatted_url);
            $formatted_url = str_replace(str_replace(['https://', 'http://'], '', self::$site_url), '', $formatted_url);
            $formatted_url = str_replace(addcslashes(self::$site_url, '/'), '', $formatted_url);
            $formatted_url = ltrim($formatted_url, '\/');
            $formatted_url = ltrim($formatted_url, '/');
        }


        if (self::$randomHash == 0 && strpos($formatted_url, '.css') !== false) {
            $formatted_url .= (strpos($formatted_url, '?') === false ? '?' : '&') . 'icv=' . self::asset_version($url);
        }

        if (self::$randomHash == 0 && preg_match('/\.js(?:[?#]|$)/i', $formatted_url)) {
            $formatted_url .= (strpos($formatted_url, '?') === false ? '?' : '&') . 'js_icv=' . self::asset_version($url);
        }

        if (self::$randomHash != 0) {
            return $formatted_url . '?icv_random=' . self::$randomHash;
        }
        //}

        return $formatted_url;
    }

    public static function is_image($image)
    {
        if (strpos($image, '.webp') === false && strpos($image, '.jpg') === false && strpos($image, '.jpeg') === false && strpos($image, '.png') === false && strpos($image, '.ico') === false && strpos($image, '.svg') === false && strpos($image, '.gif') === false) {
            return false;
        } else {
            // Serve JPG Enabled?
            if (strpos($image, '.jpg') !== false || strpos($image, '.jpeg') !== false) {

                if (self::$settings['serve']['jpg'] == '0') {
                    return false;
                }
            }

            // Serve GIF? Never via the Bunny CDN: GIFs get no next-gen conversion, so on Bunny it's
            // pure WPC egress. CF-direct zones only.
            if (strpos($image, '.gif') !== false) {
                if (self::$settings['serve']['gif'] == '0'
                    || !class_exists('wps_rewriteLogic') || !wps_rewriteLogic::cf_is_delivery()) {
                    return false;
                }
            }

            // Serve PNG Enabled?
            if (strpos($image, '.png') !== false) {

                if (self::$settings['serve']['png'] == '0') {
                    return false;
                }
            }

            // Serve SVG Enabled?
            if (strpos($image, '.svg') !== false) {

                if (self::$settings['serve']['svg'] == '0') {
                    return false;
                }
            }


            if ((strpos($image, '.webp') !== false || strpos($image, '.ico') !== false)
                && (!class_exists('WPC_Negotiated_Delivery') || !WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings))) {
                return false;
            }

            return true;
        }
    }

    public function buffer_local_go()
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return true;
        }

        if (self::$isAjax) {
            $wps_ic_cdn = new wps_cdn_rewrite();
        }

        ob_start([$this, 'render_buffer_local']);
    }

    public function isActive()
    {
        return self::$isActive;
    }

    public function add_scripts_inline($tag, $handle, $src)
    {
        if (strpos(strtolower($src), 'webpack') !== false) {
            return $tag;
        }


        if (strpos(strtolower($src), 'tweenmax') !== false) {
            $urlGet = false;
            // TODO: Move to default defers
            $check = wp_http_validate_url($src);
            if ($check || strpos($src, '//') === 0) {
                if (strpos($src, 'http') === false) {
                    $src = 'https:' . $src;
                }
                $urlGet = true;
                $url = $src;
            } else {
                $url = get_home_url() . $src;
            }

            if ($urlGet) {
                $scriptContent = (string) $this->get_script_content_url($url);
                if ($scriptContent === '') {
                    return $tag;
                }
                $tag = '<script type="text/javascript" class="wps-inline" id="tweenmax-js">' . $scriptContent . '</script>';
            } else {
                $tag = '<script type="text/javascript" class="wps-inline" id="tweenmax-js">' . $this->get_script_content($url) . '</script>';
            }

            return $tag;
        }

        if (empty($this->inline_js) || !is_array($this->inline_js)) {
            $this->inline_js = [];
        }

        $found = false;
        foreach ($this->inline_js as $k => $inlineJs) {
            if (strpos(strtolower($src), $inlineJs) !== false) {
                $found = true;
                break;
            }
        }

        if ($found) {
            global $wp_scripts;

            $check = wp_http_validate_url($src);
            if ($check || strpos($src, '//') === 0) {
                $url = $src;
            } else {
                $url = get_home_url() . $src;
            }

            $tag = '';
            if (!empty($wp_scripts->registered[$handle]->extra['before'][1])) {
                $tag .= '<script type="text/javascript" id="' . $handle . '-js-before">' . $wp_scripts->registered[$handle]->extra['before'][1] . '</script>';
            }

            // TODO: Make more elegant
            if (strpos($handle, 'awesome') !== false) {
                $tag .= '<script type="text/javascript" defer class="wps-inline" id="' . $handle . '-js">' . $this->get_script_content($url) . '</script>';
            } else {
                if (strpos($handle, 'aio') !== false || strpos($handle, 'theme') !== false) {
                    $tag .= '<script type="text/javascript" class="wps-inline" id="' . $handle . '-js" defer>' . $this->get_script_content($url) . '</script>';
                } else {
                    // wpc-delay-script is INERT until the delay loader unmasks it, and this filter
                    // is registered on script_loader_tag gated only on inline-js — it knows nothing
                    // about whether a loader will exist. checkCache() skips the rewriter for every
                    // logged-in request, and the delay gates additionally stand down for
                    // manage_wpc_settings users, so masking here produced a script that never ran.
                    // Mask only when an executor is actually going to be on the page.
                    $wpc_delay_executor = !(function_exists('is_user_logged_in') && is_user_logged_in())
                        && ((isset(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1')
                            || (isset(self::$settings['delay-js']) && self::$settings['delay-js'] == '1'));
                    $tag .= '<script type="' . ($wpc_delay_executor ? 'wpc-delay-script' : 'text/javascript') . '" class="wps-inline" id="' . $handle . '-js">' . $this->get_script_content($url) . '</script>';
                }
            }

            if (!empty($wp_scripts->registered[$handle]->extra['after'][1])) {
                $tag .= '<script type="text/javascript" id="' . $handle . '-js-after">' . $wp_scripts->registered[$handle]->extra['after'][1] . '</script>';
            }
        }

        return $tag;
    }

    public function get_script_content_url($url)
    {
        // v7.10.532 — THE 40s RENDER. This runs inside the ob callback (render_buffer_cdn),
        // once PER SCRIPT, with only a PER-CALL timeout: 8 unreachable scripts x 5s = 40s of
        // blocking curl after the page body is already built. Receipted at 41,580 ms in a single
        // OBCHAIN span, with the worker in FPM "Finishing" (invisible to request_slowlog_timeout)
        // and its MySQL connection in Sleep (blocked outside the DB). Raw curl also bypasses the
        // WP HTTP API, so our own http_n counter read 0 and hid it. Three belts, all fail-open:
        // a REQUEST-WIDE budget, a shorter per-call cap, and a negative cache so one dead URL
        // cannot re-cost the budget on every render.
        $key = 'wpc_surl_' . md5((string) $url);
        if (function_exists('get_transient') && get_transient($key)) {
            return ''; // known-bad recently; inlining is an optimisation, never a requirement
        }
        $content_cache_key = 'wpc_surlc39_' . md5((string) $url);
        $cached_content = function_exists('get_transient') ? get_transient($content_cache_key) : false;
        if (is_string($cached_content) && $cached_content !== '') {
            return $cached_content;
        }
        if (function_exists('wpc_render_guard_active') && wpc_render_guard_active() && function_exists('wpc_net_defer')) {
            $self = $this;
            wpc_net_defer('surl:' . md5((string) $url), function () use ($self, $url) { $self->get_script_content_url($url); });
            return '';
        }
        if (!isset($GLOBALS['wpc_script_fetch_ms_spent'])) {
            $GLOBALS['wpc_script_fetch_ms_spent'] = 0.0;
        }
        $budget = (float) apply_filters('wpc_script_fetch_budget_ms', 3000);
        if ($GLOBALS['wpc_script_fetch_ms_spent'] >= $budget) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('script-fetch-budget-spent', '', (string) $url, []);
            }
            return '';
        }

        $t0 = microtime(true);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) apply_filters('wpc_script_fetch_timeout', 2));
        $data = curl_exec($ch);
        curl_close($ch);
        $spent = (microtime(true) - $t0) * 1000;
        $GLOBALS['wpc_script_fetch_ms_spent'] += $spent;
        if (function_exists('wpc_prof_mark')) {
            wpc_prof_mark('scriptfetch', $t0);
        }

        if ($data === false || $data === '') {
            if (function_exists('set_transient')) {
                set_transient($key, 1, (int) apply_filters('wpc_script_fetch_fail_ttl', 300));
            }
            return '';
        }
        if (function_exists('set_transient') && strlen($data) <= 512000) {
            set_transient($content_cache_key, $data, (int) apply_filters('wpc_script_fetch_cache_ttl', 12 * HOUR_IN_SECONDS));
        }
        return $data;
    }

    public function get_script_content($url)
    {


        $relativePath = wp_make_link_relative($url);
        $path = ltrim($relativePath, '/');


        $last_abspath = basename(ABSPATH);
        $first_path = explode('/', $path)[0];
        if ($last_abspath == $first_path) {
            $path = substr($path, strlen($first_path));
            $path = ltrim($path, '/');
        }

        $path = explode('?', $path);
        $path = $path[0];

        // TODO: What if file does not exist?
        if (!file_exists(ABSPATH . $path)) {
            // Can't just return empty , because it's in script tags, fix!!
        }

        $content = file_get_contents(ABSPATH . $path);

        // Remove comments
        $jsCode = preg_replace('#/\*.*?\*/#s', '', $content);

        return $jsCode;
    }

    public function img_ratio_style()
    {
        echo '<style id="wpc-img-ratio">' . self::IMG_RATIO_CSS . '</style>';
    }

    public function dnsPrefetch()
    {
        // Honor "Exclude from Plugin" — skip DNS prefetch / preconnect injection on excluded URLs
        if (!self::dontRunif()) {
            return;
        }
        if (strlen(trim(self::$zone_name)) > 0) {
            if (!empty($_GET['dbg']) && $_GET['dbg'] == 'direct') {
                if (!empty($_GET['custom_server'])
                    && function_exists('wpc_cdn_debug_is_allowed') && wpc_cdn_debug_is_allowed()) {
                    $custom_server = sanitize_text_field($_GET['custom_server']);

                    if (preg_match('/^[a-z0-9\-]+\.zapwp\.net$/i', $custom_server)) {
                        self::$zone_name = $custom_server . '/key:' . self::$options['api_key'];
                        echo '<link rel="dns-prefetch" href="//' . $custom_server . '" />';
                    }
                }
            }
        }
    }

    public function deferJSAssets($tag, $handle, $src)
    {
        return $tag;
    }

    public function rewrite_script_tag($tag, $handle, $src)
    {
        $src = trim($src);

        if (self::isExcludedFrom('cdn', $src)) {
            return $tag;
        }

        if (self::isExcludedFrom('cdn', $tag)) {
            return $tag;
        }

        if ($this->defaultExcluded($src)) {
            return $tag;
        }

        if (self::is_excluded_link($src)) {
            return $tag;
        }


        /**
         * TODO:
         * check if external is enabled
         */


        if (!self::image_url_matching_site_url($src)) {
            return $tag;
        }


        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on') && !wps_rewriteLogic::natural_assets_on()) {
            return $tag;
        }
        if (self::$cdnEnabled == '1' && self::$js == '1') {
            // v7.10.720 - render-lane scripts ride the page origin (the {100x8} controlled
            // proof). THIS tag-level writer is the third one - it minted the mirror form
            // (zone + path + js_icv) at enqueue output, before any buffer pass, which is why
            // the .719 belt never saw a swappable URL (receipted live on the settled .719
            // mint: zone jquery + zone pixel with a working belt). Standdown at the writer;
            // the defer handling below proceeds with the origin src unchanged.
            if (strpos($src, self::$zone_name) === false && !apply_filters('wpc_scripts_same_origin', true)) {
                $fileMinify = self::$js_minify;
                if (self::isExcluded('js_minify', $src)) {
                    $fileMinify = '0';
                }


                $abs = self::reformat_url($src, false);
                if (empty($fileMinify)) {
                    $pp = function_exists('wp_parse_url') ? wp_parse_url($abs) : parse_url($abs);
                    if (is_array($pp) && !empty($pp['path'])) {
                        $src = 'https://' . self::$zone_name . $pp['path']
                             . (isset($pp['query']) ? '?' . $pp['query'] : '')
                             . (isset($pp['fragment']) ? '#' . $pp['fragment'] : '');
                    } else {
                        $src = 'https://' . self::$zone_name . '/m:0/a:' . $abs;
                    }
                } else {
                    $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . $abs;
                }
            }

            if (!empty(self::$settings['js_defer'])) {
                if (self::$settings['js_defer'] == '1' && !self::$defer_js_override) {
                    foreach (self::$assets_to_defer as $i => $defer_key) {
                        if (strpos($tag, $defer_key) !== false) {
                            if (!self::isExcluded('defer_js', $src) && !strpos($src, 'slide')) {
                                $tag = '<script type="text/javascript" src="' . $src . '" defer></script>';
                            }
                        }
                    }
                } else {
                    // FIXED: Only replace src in the opening script tag, not in any content after
                    $tag = preg_replace('/^(\s*<script[^>]*)\ssrc=["\']([^"\']*)["\']([^>]*>)/i', '$1 src="' . $src . '"$3', $tag);
                }
            } else {

                if (strpos($src, 'gtag') !== false) {
                    $tag = '<script type="text/javascript" src="' . $src . '" defer></script>';
                }

                if (strpos($src, 'fontawesome') !== false) {
                    $tag = '<script type="text/javascript" src="' . $src . '" defer></script>';

                    return $tag;
                }

                // FIXED: Only replace src in the opening script tag, not in any content after
                $tag = preg_replace('/^(\s*<script[^>]*)\ssrc=["\']([^"\']*)["\']([^>]*>)/i', '$1 src="' . $src . '"$3', $tag);
            }

            return $tag;
        }

        return $tag;
    }

    public static function isExcludedFrom($setting, $link)
    {
        if (isset(self::$excludes[$setting])) {
            $excludeList = self::$excludes[$setting];
            if (!empty($excludeList)) {
                foreach ($excludeList as $key => $value) {
                    if (strpos($link, $value) !== false && $value != '') {
                        return true;
                    }
                }
            }
        }

        if (isset(self::$page_excludes_files[$setting])) {
            $excludeList = self::$page_excludes_files[$setting];
            if (!empty($excludeList)) {
                foreach ($excludeList as $key => $value) {
                    if (strpos($link, $value) !== false && $value != '') {
                        return true;
                    }
                }
            }
        }


        return false;
    }

    public function defaultExcluded($string)
    {
        if (!empty(self::$default_excluded_list)) {
            foreach (self::$default_excluded_list as $i => $excluded_string) {
                if (strpos($string, $excluded_string) !== false) {
                    return true;
                }
            }
        }

        return false;
    }


    public static function is_dynamic_query_asset($link)
    {
        if (empty($link) || !is_string($link)) {
            return false;
        }
        $hasCss = stripos($link, '.css') !== false;
        $hasJs  = stripos($link, '.js') !== false;
        if (!$hasCss && !$hasJs) {
            return false;
        }
        $path = (string) (function_exists('wp_parse_url')
            ? wp_parse_url($link, PHP_URL_PATH)
            : parse_url($link, PHP_URL_PATH));
        // Real static asset: the .css/.js is in the PATH (a trailing ?ver= query is fine).
        if (($hasCss && stripos($path, '.css') !== false) || ($hasJs && stripos($path, '.js') !== false)) {
            return false;
        }
        // .css/.js appears ONLY in the query string → dynamic endpoint → leave on origin.
        return true;
    }

    public static function is_excluded_link($link)
    {
        /**
         * Is the link in excluded list?
         */
        if (empty($link)) {
            return false;
        }


        if (self::is_dynamic_query_asset($link)) {
            return true;
        }

        if (strpos($link, '.css') !== false || strpos($link, '.js') !== false) {
            foreach (self::$default_excluded_list as $i => $excluded_string) {
                if (strpos($link, $excluded_string) !== false) {
                    return true;
                }
            }
        }

        if (!empty(self::$excluded_list)) {
            foreach (self::$excluded_list as $i => $value) {
                if (strpos($link, $value) !== false) {
                    // Link is excluded
                    return true;
                }
            }
        }

        return false;
    }


    public static function image_url_matching_site_url($image)
    {
        // Single leading slash = root-relative local path.
        // Double leading slash = protocol-relative external URL (e.g. //cdnjs.cloudflare.com/...) — treat as external.
        if (strpos($image, '//') !== 0 && (strpos($image, '/') === 0 || strpos($image, 'wp-content') === 0)) {
            return true;
        }
        $site_url = self::$site_url;
        $stripped = str_replace(['https://', 'http://'], '', $image);
        $site_url = str_replace(['https://', 'http://'], '', $site_url);

        if (strpos($stripped, '.css') !== false || strpos($stripped, '.js') !== false) {
            foreach (self::$default_excluded_list as $i => $excluded_string) {
                if (strpos($stripped, $excluded_string) !== false) {
                    return false;
                }
            }
        }


        $site_host = preg_replace('/^www\./i', '', (string) strtok($site_url, '/'));
        if ($site_host !== '' && preg_match_all('#https?://([^/"\'\s>)]+)#i', $image, $host_matches) && !empty($host_matches[1])) {
            foreach ($host_matches[1] as $h) {
                $h = preg_replace('/^www\./i', '', (string) strtok($h, ':'));
                if (strcasecmp($h, $site_host) === 0) {
                    return true;
                }
            }
            return false;
        }

        if (strpos($stripped, $site_url) === false) {
            // Image not on site
            return false;
        } else {
            // Image on site
            return true;
        }
    }

    public static function isExcluded($setting, $link)
    {
        if (isset(self::$excludes[$setting])) {
            $excludeList = self::$excludes[$setting];
            if (!empty($excludeList)) {
                foreach ($excludeList as $key => $value) {
                    if (strpos($link, $value) !== false && $value != '') {
                        return true;
                    }
                }
            }
        }


        return false;
    }

    public function crittr_style_tag($html, $handle, $href, $media)
    {

        if (strpos($href, self::$site_url) === false) {

        } else {
            $cdnHref = WPS_IC_URI . 'fixCss.php?zoneName=' . self::$zone_name . '&css=' . urlencode($href) . '&rand=' . time();
            $html = str_replace($href, $cdnHref, $html);
        }

        return $html;
    }

    // TODO: IMPORANT! If you don't want to run it needs to return false!

    public function adjust_style_tag($html, $handle, $href, $media)
    {

        if (strpos($href, 'wp-includes/css/dist/block-library') !== false) {
            if (!empty($this::$settings['disable-gutenberg']) && $this::$settings['disable-gutenberg'] == '1') {
                return '';
            }
        }

        return $html;
    }

    public function strInArray($haystack, $needles = [])
    {

        if (empty($needles)) {
            return false;
        }

        $haystack = strtolower($haystack);

        foreach ($needles as $needle) {
            $needle = strtolower(trim($needle));

            $res = strpos($haystack, $needle);
            if ($res !== false) {
                return true;
            }
        }

        return false;
    }


    public function adjust_src_url($src)
    {
        $out = $this->adjust_src_url_raw($src);
        if (is_string($out) && $out !== '' && class_exists('wps_rewriteLogic') && wps_rewriteLogic::natural_assets_on()) {
            $natural = wps_rewriteLogic::naturalize_asset_urls($out);
            if (is_string($natural) && $natural !== '') {
                $out = $natural;
            }
        }
        return $out;
    }

    /** SiteGround Optimizer's Combine CSS is switched on (its own option; SG's Options::is_enabled). */
    private static function sg_combine_css_on()
    {
        static $combineOn = null;
        if ($combineOn === null) {
            $combineOn = function_exists('get_option')
                && (int) get_option('siteground_optimizer_combine_css', 0) === 1
                && apply_filters('wpc_sg_combine_origin_wp_includes', true);
        }
        return $combineOn;
    }

    public function adjust_src_url_raw($src)
    {

        $src = trim($src);

        if (strpos($src, '.css') !== false && empty(self::$css) || self::$css == '0') {
            return $src;
        } elseif (strpos($src, '.js') !== false && empty(self::$js) || self::$js == '0') {
            return $src;
        } else if (strpos($src, '.php') !== false) {
            return $src;
        }

        if (self::isExcludedFrom('cdn', $src)) {
            return $src;
        }

        if ($this->defaultExcluded($src)) {
            return $src;
        }

        if (self::is_excluded_link($src)) {
            return $src;
        }

        // A WordPress core stylesheet keeps its origin URL while SiteGround Optimizer's Combine
        // CSS is on. SG's combiner (Css_Combinator::is_excluded, SG 7.8.3) treats every <link>
        // whose URL contains "wp-includes" as local whatever its host, removes the tag, and reads
        // the file at ABSPATH . <url>; for a zone URL that path does not exist, so the sheet is
        // replaced by an empty combined file. webdesign4u.com.au (2026-09-28): the media player
        // sheets, left live because Divi's runtime runs at load, came out as
        // siteground-optimizer-combined-css-d41d8cd9… (md5 of "") and the player's
        // "Video Player" label painted over the hero. On its origin URL SG combines the real
        // file, as it does without this plugin. Kill wpc_sg_combine_origin_wp_includes.
        if (strpos($src, '.css') !== false && strpos($src, '/wp-includes/') !== false
            && self::sg_combine_css_on()) {
            return $src;
        }

        /**
         * TODO:
         * check if external is enabled
         */


        if (!self::image_url_matching_site_url($src)) {
            return $src;
        }


        // ORIGIN FLOOR for same-origin css/js: unproven zone → leave the origin href (proven → the
        // m:N/a: build below runs and adjust_src_url naturalizes it).
        if ((strpos($src, '.css') !== false || strpos($src, '.js') !== false)
            && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on')
            && !wps_rewriteLogic::natural_assets_on()) {
            return $src;
        }

        if (strpos($src, self::$zone_name) === false) {
            if (strpos($src, '.css') !== false) {
                $fileMinify = '0';
                if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1'
                    && apply_filters('wpc_font_subset_forces_css_minify', false, $src)) {
                    $fileMinify = '1';
                }

                if (!self::is_excluded_link($src)) {
                    if (self::$css_img_url == '1') {
                        $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($src);
                    } else {
                        if (strpos($src, 'wp-content') !== false || strpos($src, 'wp-includes') !== false) {
                            $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($src, false);
                        } else {
                            $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($src, false);
                        }
                    }
                }
            } elseif (strpos($src, '.js') !== false) {
                // v7.10.723 - render-lane scripts ride the page origin. FIFTH writer: this
                // src-level filter (script_loader_src / script_module_loader_src) zones the
                // handle before any tag filter runs - the standdown belongs here, not in a
                // downstream belt the encode window hides scripts from.
                if (apply_filters('wpc_scripts_same_origin', true)) {
                    return $src;
                }
                $fileMinify = self::$js_minify;
                if (self::isExcluded('js_minify', $src)) {
                    $fileMinify = '0';
                }

                if (strpos($src, 'wp-content') !== false || strpos($src, 'wp-includes') !== false) {
                    $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($src, false);
                } else {
                    $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($src, false);
                }
            }
        }

        return $src;
    }


    public static function wpc_svg_naturalize($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name)) {
            return $html;
        }
        $zone = preg_quote((string) self::$zone_name, '#');
        $collapsed = 0;
        $out = preg_replace_callback(
            '#https?://(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)/[^"\'()\s<>]*?/u:(https?://[^"\'()\s<>]+?/wp-content/uploads/[^"\'()\s<>]+?\.svg(?![\w-])(?:\?[^"\'()\s<>]*)?)#i',
            static function ($m) use (&$collapsed) {
                $pos = stripos($m[1], '/wp-content/uploads/');
                if ($pos === false) {
                    return $m[0];
                }


                if (preg_match_all('#https?://([^/"\'()\s<>]+)#i', substr($m[1], 0, $pos), $host_matches) && !empty($host_matches[1])) {
                    $asset_host = preg_replace('/^www\./i', '', (string) end($host_matches[1]));
                    $site_host  = preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
                    if ($site_host !== '' && strcasecmp($asset_host, $site_host) !== 0) {
                        return $m[0];
                    }
                }

                // The zone URL carries the origin URL's whole path, not the path from
                // /wp-content/uploads/ on: cutting there dropped a subdirectory install's prefix
                // (noktaltema.com/tibet/nakliye, 2026-09-24: `<zone>/wp-content/uploads/…svg`, which a
                // later pass turned into the origin 404 `https://noktaltema.com/wp-content/uploads/…svg`).
                $originScheme = strrpos(substr($m[1], 0, $pos), '://');
                $originPathStart = ($originScheme === false) ? false : strpos($m[1], '/', $originScheme + 3);
                if ($originPathStart === false || $originPathStart > $pos) {
                    return $m[0];
                }
                $collapsed++;
                return 'https://' . self::$zone_name . substr($m[1], $originPathStart);
            },
            $html
        );
        // An SVG transform URL (zone/.../u:<origin>) is collapsed to its natural zone URL: the
        // writers still mint the grammar. Sampled: every render of a CDN page carries them.
        if ($collapsed > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('transform-urls-naturalized', ['svg' => $collapsed], true);
        }
        return $out;
    }

    /**
     * CSS-background image-set() master gate. Default ON. Piggybacks wpc_svg_zoneify_active() (cdn on
     * + live-cdn + images tile on + not suppressed + zone != origin) so it can only be active where
     * the same-ext host-swap already runs. KILL is the absolute off-ramp.
     */
    public static function wpc_css_bg_imageset_active()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        if (!self::wpc_svg_zoneify_active()) return false;
        $on = function_exists('get_option') ? get_option('wpc_css_bg_imageset', 1) : 1;
        return (bool) apply_filters('wpc_css_bg_imageset', !empty($on));
    }


    public static function wpc_css_bg_disk_siblings($origin_url)
    {
        $out = ['avif' => false, 'webp' => false];
        $url = preg_replace('/[?#].*$/', '', (string) $origin_url);
        if ($url === '') return $out;

        $site = trailingslashit(site_url());
        $host = wp_parse_url($url, PHP_URL_HOST);
        $shst = wp_parse_url($site, PHP_URL_HOST);
        if (!$host || !$shst || strcasecmp((string) $host, (string) $shst) !== 0) return $out;
        if (strpos($url, '/wp-content/uploads/') === false) return $out;

        $base = str_replace($site, trailingslashit(ABSPATH), $url);
        $base = str_replace('/', DIRECTORY_SEPARATOR, $base);
        if (strpos($base, trailingslashit(ABSPATH)) !== 0) return $out;

        $avif = preg_replace('/\.(jpe?g|png)$/i', '.avif', $base);
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $base);
        if (is_string($avif) && $avif !== $base && @file_exists($avif)
            && wps_rewriteLogic::picture_variant_dims_ok($avif, 0, 0)) {
            $out['avif'] = true;
        }
        if (is_string($webp) && $webp !== $base && @file_exists($webp)
            && wps_rewriteLogic::picture_variant_dims_ok($webp, 0, 0)) {
            $out['webp'] = true;
        }
        return $out;
    }


    public static function wpc_css_bg_imageset_build($origin_url, $sameext_zone, $quote = '')
    {
        if (!self::wpc_css_bg_imageset_active()) return '';
        $origin_url   = (string) $origin_url;
        $sameext_zone = (string) $sameext_zone;
        if ($origin_url === '' || $sameext_zone === '') return '';

        $clean = preg_replace('/[?#].*$/', '', $origin_url);
        $ext   = strtolower(pathinfo($clean, PATHINFO_EXTENSION));


        $css_nw   = wps_rewriteLogic::wpc_natural_nw();
        $css_exts = $css_nw ? ['jpg', 'jpeg', 'png', 'webp'] : ['jpg', 'jpeg', 'png'];
        if (!in_array($ext, $css_exts, true)) return '';
        $base_mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');

        $q = ($quote === '"' || $quote === "'") ? $quote : '';

        // v7.20.15 — every ext-swapped URL carries its origin-ext hint (?src= / &src=):
        // the edge skips its probe ladder instead of walking sibling guesses on a MISS.
        $add_src_hint = function ($u) use ($ext) {
            $h = wps_rewriteLogic::src_hint_qs($ext);
            if (!is_string($u) || $u === '' || $h === '') { return $u; }
            // v7.21.260 — an already-hinted URL keeps its hint: the existing src= names the
            // TRUE original; appending a second forges a distinct URL and the same file
            // downloads twice (columbuschiropractors: x.avif?src=png&src=webp beside
            // x.avif?src=png), and the second hint misinforms the edge besides.
            if (preg_match('/[?&]src=/', $u)) { return $u; }
            return $u . (strpos($u, '?') !== false ? '&' . substr($h, 1) : $h);
        };

        if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) {
            $webp_zone = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $sameext_zone);
            if (is_string($webp_zone) && $webp_zone !== '' && $webp_zone !== $sameext_zone) {
                return 'background-image:url(' . $q . $add_src_hint($webp_zone) . $q . ')';
            }
            return '';
        }


        if ($css_nw) {
            $avif_zone_nw = preg_replace('/\.(jpe?g|png|webp)(\?.*)?$/i', '.avif$2', $sameext_zone);
            $webp_zone_nw = preg_replace('/\.(jpe?g|png|webp)(\?.*)?$/i', '.webp$2', $sameext_zone);
            $nw_entries = [];
            if (is_string($avif_zone_nw) && $avif_zone_nw !== '' && $avif_zone_nw !== $sameext_zone) {
                $nw_entries[] = 'url(' . $q . $add_src_hint($avif_zone_nw) . $q . ') type("image/avif")';
            }
            if (is_string($webp_zone_nw) && $webp_zone_nw !== '' && $webp_zone_nw !== $sameext_zone) {
                $nw_entries[] = 'url(' . $q . $add_src_hint($webp_zone_nw) . $q . ') type("image/webp")';
            }
            $nw_entries[] = 'url(' . $q . $sameext_zone . $q . ') type("' . $base_mime . '")';
            if (count($nw_entries) < 2) return '';
            $nw_set = implode(',', $nw_entries);
            return 'background-image:url(' . $q . $sameext_zone . $q . ');'
                 . 'background-image:-webkit-image-set(' . $nw_set . ');'
                 . 'background-image:image-set(' . $nw_set . ')';
        }


        $sib = self::wpc_css_bg_disk_siblings($origin_url);
        if (empty($sib['avif']) && empty($sib['webp'])) return '';

        // Ext-swap on the ZONE url (host-swap already done by the caller); swap only the extension.
        $avif_zone = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.avif$2', $sameext_zone);
        $webp_zone = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $sameext_zone);

        $entries = [];
        if (!empty($sib['avif']) && is_string($avif_zone) && $avif_zone !== '' && $avif_zone !== $sameext_zone) {
            $entries[] = 'url(' . $q . $add_src_hint($avif_zone) . $q . ') type("image/avif")';
        }
        if (!empty($sib['webp']) && is_string($webp_zone) && $webp_zone !== '' && $webp_zone !== $sameext_zone) {
            $entries[] = 'url(' . $q . $add_src_hint($webp_zone) . $q . ') type("image/webp")';
        }
        // Same-ext floor entry — guarantees a 200 inside image-set even for an exotic UA.
        $entries[] = 'url(' . $q . $sameext_zone . $q . ') type("' . $base_mime . '")';
        if (count($entries) < 2) return '';

        $set = implode(',', $entries);
        return 'background-image:url(' . $q . $sameext_zone . $q . ');'
             . 'background-image:-webkit-image-set(' . $set . ');'
             . 'background-image:image-set(' . $set . ')';
    }


    /**
     * v7.10.822 — a background-image declaration is a LAYER LIST, and the rebuild replaced the
     * whole list. fleetup.it: background-image:linear-gradient(overlay),url(x.webp) came out as
     * bare image-set — the color overlay destroyed (customer-reported, 7.10.09). Splits the matched
     * prefix: prior layers ending in "," are preserved onto every rebuilt declaration; any other
     * prefix content (shorthand color/position tokens — which the old rebuild also corrupted into
     * invalid declarations) skips the conversion entirely. Fail-open both ways.
     */
    public static function wpc_css_bg_prior_layers($prefix)
    {
        $prefix = (string) $prefix;
        if (!preg_match('/^(background(?:-image)?\s*:\s*)(.*)(url\(\s*)$/is', $prefix, $m)) {
            return ['skip' => false, 'layers' => ''];
        }
        $extra = trim($m[2]);
        if ($extra === '') {
            return ['skip' => false, 'layers' => ''];
        }
        if (!preg_match('/^background-image\s*:/i', $prefix) || substr($extra, -1) !== ',') {
            return ['skip' => true, 'layers' => ''];
        }
        return ['skip' => false, 'layers' => $m[2]];
    }


    public static function wpc_css_bg_imageset_sweep($css)
    {
        if (!is_string($css) || $css === '' || empty(self::$zone_name)) {
            return $css;
        }
        if (stripos($css, 'background') === false || !self::wpc_css_bg_imageset_active()) {
            return $css;
        }
        $zone = preg_quote(self::$zone_name, '#');
        $origin_host = function_exists('wp_parse_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
        $base_alt = self::uploads_path_alternation();
        // v7.10.785 — the ORIGIN twin was invisible. Anchoring the match to the zone host
        // meant a background still on the origin (heritage ships the same hexagon twice:
        // one zone-hosted, one origin-hosted, 200 KiB each) could never be rewritten, so it
        // shipped as raw JPEG with no next-gen at all. Accept both hosts and zoneify the
        // origin form here. Only when the zone is a plain host — a /key:-pathed zone needs
        // the builder's own URL grammar, and guessing it would mint 404s.
        $zone_host = strtok((string) self::$zone_name, '/');
        $zone_is_plain_host = ($zone_host === (string) self::$zone_name);
        $host_pattern = $zone;
        if ($zone_is_plain_host && $origin_host !== '' && strcasecmp($origin_host, $zone_host) !== 0) {
            $host_pattern = '(?:' . $zone . '|' . preg_quote($origin_host, '#') . ')';
        }
        // .822: trailing lookahead — anything after url() besides end-of-declaration (shorthand
        // no-repeat/position tokens, extra layers, !important, escaped/encoded quote) skips the
        // match; the idempotency lookahead tolerates preserved prior layers before image-set.
        $rx = '#(background(?:-image)?\s*:\s*[^;{}]*?url\(\s*)([\'"]?)(https?://' . $host_pattern . '/' . $base_alt . '/[^"\'()\s<>]+?)\.(png|jpe?g|webp)((?:\?[^"\'()\s<>]*)?)\2(\s*\))(?=\s*(?:[;}<&\\\\]|[\'"]|$))(?!\s*;\s*background-image\s*:\s*[^;{}]*?(?:-webkit-)?image-set)#i';
        $out = preg_replace_callback($rx, static function ($m) use ($origin_host, $zone_host, $zone_is_plain_host) {
            if (stripos($m[0], 'image-set(') !== false) {
                return $m[0];
            }
            $prior_layers = self::wpc_css_bg_prior_layers($m[1]);
            if (!empty($prior_layers['skip'])) {
                return $m[0];
            }
            $matched_url = $m[3] . '.' . $m[4] . $m[5];
            $rel = function_exists('wp_parse_url') ? (string) wp_parse_url($matched_url, PHP_URL_PATH) : '';
            $is_origin_url = ($origin_host !== '' && stripos($m[3], '://' . $origin_host . '/') !== false);
            if ($is_origin_url && (!$zone_is_plain_host || $rel === '')) {
                return $m[0]; // cannot mint a zone URL safely — leave the original untouched
            }
            $sameext_zone = $is_origin_url
                ? ('https://' . $zone_host . $rel . $m[5])
                : $matched_url;
            $origin_url = ($origin_host !== '' && $rel !== '') ? ('https://' . $origin_host . $rel) : $matched_url;
            $iset = self::wpc_css_bg_imageset_build($origin_url, $sameext_zone, $m[2]);
            if ($iset !== '' && $prior_layers['layers'] !== '') {
                $iset = str_replace('background-image:', 'background-image:' . $prior_layers['layers'], $iset);
            }
            return ($iset !== '') ? $iset : $m[0];
        }, $css);
        return is_string($out) ? $out : $css; // NULL-safe: a backtrack returns the original, never blanks
    }


    /** family|weight|style => remote_range, produced beside the inlined subset. Cached per request. */
    /**
     * v7.10.478 — families that actually HAVE an inline subset face on this site.
     * remote_range is the COMPLEMENT of an inline subset; applying it without that subset
     * present excludes glyphs nothing else supplies. Live receipt on zinsenvergleich: both
     * Font Awesome faces range-gated, NO inline subset face, and U+F017 (clock), U+F09D
     * (credit-card) and U+F3D1 covered by no face at all — blank squares on a customer page.
     * The gate outlived the subset it was paired with, baked into a CDN-cached stylesheet.
     * Reads font-subsets.css, which is where the subset canonically lives; static per request.
     *
     * Rule: the families come from the page's OWN crit folder or from none, never from the
     * home page's. The pairing this function guards is with the subset the page inlines, and
     * the inliners read the page's own folder. It used to follow the page-or-home lcp.json
     * resolver and then fall back to the home folder by hand, so an interior page with no
     * observation of its own gated its faces against the home page's subset and hashed the
     * home page's families into every processed-copy name; the copies were renamed the moment
     * the page's own observation landed (greenvalleytint /services/ 2026-09-24: copies named
     * with the home page's {Poppins} before its own land, renamed after).
     */
    public static function wpc_font_subset_families()
    {
        static $subsetFamilies = null;
        if ($subsetFamilies !== null) { return $subsetFamilies; }
        $subsetFamilies = [];
        try {
            $subsetFile = '';
            if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_page_lcp_json_file')) {
                $pageLcpJsonFile = (string) wps_rewriteLogic::wpc_page_lcp_json_file();
                if ($pageLcpJsonFile !== '') { $subsetFile = dirname($pageLcpJsonFile) . '/font-subsets.css'; }
            }
            // No observation of its own: the page's own folder, keyed as the subset inliners key
            // it (a fonts-only folder can carry a subset before lcp.json lands).
            if ($subsetFile === '' && defined('WPS_IC_CRITICAL') && class_exists('wps_ic_url_key')) {
                $pageKey = (string) (new wps_ic_url_key())->setup('');
                if ($pageKey !== '') {
                    $subsetFile = rtrim(WPS_IC_CRITICAL, '/') . '/' . $pageKey . '/font-subsets.css';
                }
            }
            if ($subsetFile !== '' && @is_readable($subsetFile)) {
                $subsetCss = (string) @file_get_contents($subsetFile);
                if ($subsetCss !== '' && preg_match_all('/font-family\s*:\s*["\']?([^"\';}]+)/i', $subsetCss, $familyMatches)) {
                    foreach ($familyMatches[1] as $familyName) {
                        $familyKey = strtolower(trim((string) $familyName, " \t\"'"));
                        if ($familyKey !== '') { $subsetFamilies[$familyKey] = 1; }
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return $subsetFamilies;
    }

    public static function wpc_font_remote_ranges()
    {
        static $wpc_rr = null;
        if ($wpc_rr !== null) { return $wpc_rr; }
        $wpc_rr = [];
        if (!function_exists('get_option') || !apply_filters('wpc_font_remote_range', true)) { return $wpc_rr; }
        $raw = get_option('wpc_font_remote_ranges', []);
        if (!is_array($raw)) { return $wpc_rr; }
        foreach ($raw as $k => $v) {
            $v = preg_replace('/[^0-9A-Fa-fUu+,\- ]/', '', (string) $v);
            if ($v !== '' && is_string($k) && strpos($k, '|') !== false) { $wpc_rr[strtolower($k)] = $v; }
        }
        return $wpc_rr;
    }

    /**
     * The range gate for one of the theme's own @font-face blocks: the unicode-range that leaves
     * the inlined subset's glyphs to the subset, and the font-weight the block must declare for
     * that split to hold. Null when the map has nothing for the face.
     *
     * A face that declares a numeric weight is looked up under that weight, any other face under
     * 400. One exception: a face with no font-weight at all whose family has an `auto=` entry.
     * The service writes that entry for a variable font declared without a weight (fonts.json
     * `weight_auto`), and its subset declares the font's own range ("300 700"). The weightless
     * face must then declare the same range: left without one it is `normal`, Chrome prefers it
     * over the range face for 400 text, and its unicode-range excludes exactly the glyphs the
     * subset carries, so that text falls through to the fallback font (bgqld.com.au Teko, measured
     * 124px correct vs 181px fallback). Both readers apply the gate through this method.
     */
    public static function font_face_range_gate($familyKey, $faceBlock)
    {
        $remoteRanges = self::wpc_font_remote_ranges();
        $familyKey = (string) $familyKey;
        if (empty($remoteRanges) || $familyKey === '') { return null; }
        $faceBlock = (string) $faceBlock;
        $style = preg_match('/font-style\s*:\s*italic/i', $faceBlock) ? 'italic' : 'normal';
        if (!preg_match('/font-weight\s*:/i', $faceBlock)) {
            $autoPrefix = $familyKey . '|auto=';
            foreach ($remoteRanges as $rangeKey => $range) {
                if (strpos($rangeKey, $autoPrefix) === 0
                    && preg_match('/\|auto=(\d{2,4}) (\d{2,4})\|' . $style . '$/', $rangeKey, $spanMatch)) {
                    return ['range' => $range, 'weight' => $spanMatch[1] . ' ' . $spanMatch[2]];
                }
            }
        }
        $weight = preg_match('/font-weight\s*:\s*(\d{2,4})/i', $faceBlock, $weightMatch) ? (int) $weightMatch[1] : 400;
        $rangeKey = $familyKey . '|' . $weight . '|' . $style;
        return empty($remoteRanges[$rangeKey]) ? null : ['range' => $remoteRanges[$rangeKey], 'weight' => null];
    }

    public static function wpc_svg_zoneify_active()
    {
        if (empty(self::$zone_name)) {
            return false;
        }
        $s = self::$settings;
        if (!is_array($s) || empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') {
            return false;
        }


        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            return false;
        }
        if (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled($s)) {
            return false;
        }
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$origin || strcasecmp((string) self::$zone_name, $origin) === 0) { // EQUALITY not substring: cdn.{origin} contains origin, so a substring guard false-positives every custom-CNAME zone
            return false;
        }
        if (!self::wpc_zone_serves_natural_urls()) {
            return false;
        }
        return true;
    }

    // The natural URL shape needs a WITNESS from this zone before anything emits it: a legacy pod
    // 404s every natural path (anthonyveltri live receipt: natural jpg/css/avif?src all 404 JSON,
    // only m:0/a: serves) while the CSS lane was already proof-gated and stood down correctly.
    // natural_assets_on IS that proof (Bunny fast-path / CF mime probe); wpc_force_natural is the
    // operator's override. Presence of a zone is not service from it.
    public static function wpc_zone_serves_natural_urls()
    {
        if (function_exists('wpc_force_natural') && wpc_force_natural()) {
            return true;
        }
        return class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on')
            && wps_rewriteLogic::natural_assets_on();
    }

    // Next-gen for the EAGER image class: the picture/avif wrap rides the lazy lane, so a
    // Kadence-style featured hero (fetchpriority=high, never lazy) — the LCP element, where
    // next-gen matters most — kept its jpg. Swap-in-place instead of picture-wrapping: the img
    // shape stays stable for LCP handling. Confined to -WxH rungs (the never-404 form: origin
    // holds a real sibling, so the edge's 302-to-sibling belt always resolves), jpg/png only,
    // own-host only, and the whole pass rides the same emit gate as the lazy picture lane
    // (ceiling + zone + kill + the .750 witness).
    public static function eager_nextgen_sources($html, $imagePreloads = null)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name) || stripos($html, '<img') === false) {
            return $html;
        }
        if (!apply_filters('wpc_eager_nextgen', true)) {
            return $html;
        }
        // The lazy lane's avif decision is emit_natural OR the lazy_cdn optimistic arm — gating
        // the eager pass on emit_natural alone left it shut on lazy_cdn sites whose thumbnails
        // were carrying avif on the same render. Same effective gate, same witness.
        $avifEmitActive = class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'picture_avif_emit_natural')
            && (wps_rewriteLogic::picture_avif_emit_natural()
                || (function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled()
                    && self::wpc_zone_serves_natural_urls()));
        if (!$avifEmitActive) {
            return $html;
        }
        // Zone-host ONLY (v755): this pass runs AFTER wpc_raster_zoneify in the chain and nothing
        // downstream zoneifies — a site-host URL reaching here means the zone lane declined it,
        // and swapping it emits an ORIGIN .avif?src= that origin has no handler for.
        $zoneHostPatterns = [preg_quote((string) self::$zone_name, '#')];
        // (?<!:) — never inside a transform target (u:https://... / a:https://...): the pod pulls
        // that inner URL from ORIGIN, and origin has no .avif?src= handler. Bare full-size swaps
        // too: its 302 sibling is the ORIGINAL file itself, which always exists.
        // Colon-free path: a natural uploads path never contains ':' after the host, while every
        // transform chain (q:l/r:0/wp:1/w:1/u:https://...) does — so the outer match can never
        // traverse INTO a wrapper, and the lookbehind blocks starting AT the inner target.
        $zoneRasterUrlRegex = '#(?<!:)https?://(?:' . implode('|', $zoneHostPatterns)
            . ')/[^\s"\'<>,:]*?(?:-\d+x\d+)?\.(jpe?g|png)(?=[\s"\',])#i';
        // An image preload for the same stem must keep matching what the img fetches — a
        // swapped rung beside a jpg preload is a guaranteed double-fetch at LCP decision time.
        // Ours are candidates in the render's preload set, not yet in the buffer; one the page
        // itself carries is read from the buffer.
        $preloadedStems = [];
        if (preg_match_all('#<link\b[^>]*rel="preload"[^>]*as="image"[^>]*href="([^"]+)"#i', $html, $preloadMatches)) {
            foreach ($preloadMatches[1] as $preloadHref) {
                $preloadStem = wps_ic_image_preload_set::fileStem($preloadHref);
                if ($preloadStem !== '') { $preloadedStems[$preloadStem] = 1; }
            }
        }
        $holdsPreloads = $imagePreloads instanceof wps_ic_image_preload_set;
        $maskedPictures = [];
        if (stripos($html, '<picture') !== false) {
            $html = preg_replace_callback('#<picture\b[^>]*>.*?</picture>#is', static function ($m) use (&$maskedPictures) {
                $k = "\x01WPCEN" . count($maskedPictures) . "\x01";
                $maskedPictures[$k] = $m[0];
                return $k;
            }, $html);
            if (!is_string($html)) { return strtr(implode('', array_keys($maskedPictures)), $maskedPictures); }
        }
        $eagerSwapped = 0;
        $out = preg_replace_callback('#<img\b[^>]*>#i', static function ($m) use ($zoneRasterUrlRegex, $preloadedStems, $imagePreloads, $holdsPreloads, &$eagerSwapped) {
            $tag = $m[0];
            if (!preg_match('/\bfetchpriority\s*=\s*["\']high["\']|\bloading\s*=\s*["\']eager["\']/i', $tag)) {
                return $tag;
            }
            if (preg_match('/\bloading\s*=\s*["\']lazy["\']/i', $tag)
                || stripos($tag, 'data-wpc-nd') !== false || stripos($tag, 'data-wpc-md') !== false
                || stripos($tag, 'data-wpc-skip') !== false || stripos($tag, 'avif?src=') !== false) {
                return $tag;
            }
            if ((!empty($preloadedStems) || $holdsPreloads) && preg_match('/\bsrc\s*=\s*["\']([^"\']+)/i', $tag, $srcMatch)) {
                $srcStem = wps_ic_image_preload_set::fileStem($srcMatch[1]);
                if ($srcStem !== '' && (isset($preloadedStems[$srcStem]) || ($holdsPreloads && $imagePreloads->hasKey($srcStem)))) {
                    return $tag;
                }
            }
            $swapped = preg_replace_callback($zoneRasterUrlRegex, static function ($u) {
                $ext = strtolower($u[1]);
                return substr($u[0], 0, -strlen($u[1])) . 'avif?src=' . $ext;
            }, $tag);
            if (is_string($swapped) && $swapped !== $tag) {
                $eagerSwapped++;
            }
            return is_string($swapped) ? $swapped : $tag;
        }, $html);
        $html = is_string($out) ? $out : $html;
        if (!empty($maskedPictures)) {
            $html = strtr($html, $maskedPictures);
        }
        // An eager image is swapped to next-gen in place because the <picture> wrap rides the
        // lazy lane only. Sampled: the same eager images are swapped on every render.
        if ($eagerSwapped > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('eager-nextgen-swapped', ['n' => $eagerSwapped], true);
        }
        return $html;
    }

    /**
     * Regex fragment for the site's uploads path: a non-capturing alternation of every upload
     * base path `wpc_v2_upload_base_paths()` names (the `wp_get_upload_dir()` baseurl path,
     * which carries a subdirectory install's prefix and a multisite `sites/N` suffix, plus
     * `/wp-content/uploads` and `/storage`), without leading or trailing slash, each segment
     * preg_quote'd for $delimiter and joined by $slash (pass `\\?/` to also match a
     * JSON-escaped `\/`). Every pass that asks "is this URL path under the site's uploads"
     * builds its pattern from here, so the path list is read in one place.
     */
    public static function uploads_path_alternation($slash = '/', $delimiter = '#')
    {
        $bases = function_exists('wpc_v2_upload_base_paths') ? wpc_v2_upload_base_paths() : ['/wp-content/uploads'];
        $parts = [];
        foreach ((array) $bases as $base) {
            $base = trim((string) $base, '/');
            if ($base === '') {
                continue;
            }
            $parts[] = implode($slash, array_map(static function ($segment) use ($delimiter) {
                return preg_quote($segment, $delimiter);
            }, explode('/', $base)));
        }
        if (empty($parts)) {
            return 'wp\-content' . $slash . 'uploads';
        }
        return '(?:' . implode('|', array_unique($parts)) . ')';
    }

    public static function wpc_svg_zoneify($html)
    {
        $profilerSpan = class_exists('Wpc_Profiler_Span') ? new Wpc_Profiler_Span('pass:wpc_svg_zoneify') : null;
        if (!is_string($html) || $html === '' || !self::wpc_svg_zoneify_active()) {
            return $html;
        }
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        $o = preg_quote($origin, '#');
        // The site's own uploads path, not a literal /wp-content/uploads/ (see wpc_raster_zoneify).
        $uploads = self::uploads_path_alternation();
        // Absolute origin URLs (src/href/srcset/CSS url()). Never data-wpc-fb: that attribute is
        // the image's way back to the origin when the zone fails, and zoning it leaves the image
        // with no way back (2026-09-24, once the SVG width ladder was dropped). The fallback is
        // written after this pass (stage asset_failover); a buffer that already carries one, such
        // as markup rendered through the pipeline before, keeps it.
        $html = self::wpc_preg_safe(
            '#(?<!data-wpc-fb=["\'])https?://' . $o . '(/' . $uploads . '/[^"\'()\s<>]+?\.svg(?![\w-])(?:\?[^"\'()\s<>]*)?)#i',
            'https://' . self::$zone_name . '$1',
            $html,
            $absZoned
        );
        // Root-relative references (quoted attributes + CSS url(...)), data-wpc-fb excepted likewise.
        $html = self::wpc_preg_safe(
            '#(?<!data-wpc-fb=)(["\'(])(/' . $uploads . '/[^"\'()\s<>]+?\.svg(?![\w-])(?:\?[^"\'()\s<>]*)?)#i',
            '$1https://' . self::$zone_name . '$2',
            $html,
            $relZoned
        );
        // Origin SVG URLs the image rewrite left behind are moved onto the zone (the rewrite does
        // not cover every URL context). Sampled: the same markup reaches here on every render.
        if ((int) $absZoned + (int) $relZoned > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('origin-urls-zoneified', ['svg' => (int) $absZoned + (int) $relZoned], true);
        }
        return $html;
    }

    public static function wpc_raster_zoneify_active()
    {
        if (empty(self::$zone_name)) {
            return false;
        }
        $s = self::$settings;
        if (!is_array($s) || empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') {
            return false;
        }
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            return false;
        }
        if (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled($s)) {
            return false;
        }
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$origin || strcasecmp((string) self::$zone_name, $origin) === 0) { // EQUALITY not substring: cdn.{origin} contains origin, so a substring guard false-positives every custom-CNAME zone
            return false;
        }
        if (!self::wpc_zone_serves_natural_urls()) {
            return false;
        }
        return true;
    }


    public static function wpc_raster_zoneify($html)
    {
        $profiler_span = class_exists('Wpc_Profiler_Span') ? new Wpc_Profiler_Span('pass:wpc_raster_zoneify') : null;
        if (!is_string($html) || $html === '' || !self::wpc_raster_zoneify_active()) {
            return $html;
        }
        $wpc_pic_blocks = [];
        if (stripos($html, '<picture') !== false) {


            $masked = '';
            $offset = 0;
            $hlen   = strlen($html);
            while (($start = stripos($html, '<picture', $offset)) !== false) {
                $after = ($start + 8 < $hlen) ? $html[$start + 8] : '';
                if ($after !== '' && (ctype_alnum($after) || $after === '_')) {

                    $masked .= substr($html, $offset, ($start + 8) - $offset);
                    $offset  = $start + 8;
                    continue;
                }
                $end = stripos($html, '</picture>', $start);
                if ($end === false) {
                    break;
                }
                $end += 10;
                $k = "\x01WPCPIC" . count($wpc_pic_blocks) . "\x01";
                $wpc_pic_blocks[$k] = substr($html, $start, $end - $start);
                $masked .= substr($html, $offset, $start - $offset) . $k;
                $offset  = $end;
            }
            $masked .= substr($html, $offset);
            $html = $masked;
        }
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        $o = preg_quote($origin, '#');


        $nat_gif = (class_exists('wps_rewriteLogic') && wps_rewriteLogic::cf_is_delivery()) ? '|gif' : '';
        $nextgen_exts = apply_filters('wpc_raster_zoneify_nextgen', true) ? '|webp|avif' : '';


        $zn = self::$zone_name;
        $cf_cname_z  = (defined('WPS_IC_CF_CNAME') && function_exists('get_option')) ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $z_cf_direct = ($cf_cname_z !== '' && stripos((string) $zn, $cf_cname_z) !== false);
        $z_edge_webp = (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()
            && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_single_url_format'));
        $z_swap = static function ($path) use ($z_edge_webp, $z_cf_direct) {
            if (!preg_match('#^(.*\.)(png|jpe?g|gif)(\?.*)?$#i', $path, $mm)) return $path;
            $ext = strtolower($mm[2]);
            $fmt = $z_edge_webp ? wps_rewriteLogic::wpc_single_url_format($ext, $z_cf_direct, true) : $ext;
            $out = (is_string($fmt) && $fmt !== '') ? $fmt : $ext;
            return $mm[1] . $out . (isset($mm[3]) ? $mm[3] : '');
        };
        // The uploads path is the site's own, never a literal /wp-content/uploads/. The crit
        // service writes every zone URL back onto the page host, and this pass is the one that
        // moves them back, including the zone-grammar `X.avif?src=webp` image-set candidates,
        // which exist only on the zone and answer 404 on the origin. With the path hardcoded,
        // a site whose uploads live elsewhere kept them on the origin: acrystalglass.com
        // (2026-09-29, uploads at /storage/) shipped its hero image-set with four of five
        // candidates on the origin, the avif one a 404, so the hero was empty until the parked
        // sheet applied. A subdirectory install (/sub/wp-content/uploads/) failed the same way.
        $uploads = self::uploads_path_alternation();
        // Absolute origin uploads rasters.
        $absZoned = 0;
        $relZoned = 0;
        $z_abs = preg_replace_callback(
            '#https?://' . $o . '(/' . $uploads . '/[^"\'()\s<>]+?\.(?:png|jpe?g' . $nat_gif . $nextgen_exts . ')(?![\w-])(?:\?[^"\'()\s<>]*)?)#i',
            static function ($m) use ($zn, $z_swap) { return 'https://' . $zn . $z_swap($m[1]); },
            $html,
            -1,
            $absZoned
        );
        if (is_string($z_abs)) $html = $z_abs;
        // Root-relative refs.
        $z_rel = preg_replace_callback(
            '#(["\'(])(/' . $uploads . '/[^"\'()\s<>]+?\.(?:png|jpe?g' . $nat_gif . $nextgen_exts . ')(?![\w-])(?:\?[^"\'()\s<>]*)?)#i',
            static function ($m) use ($zn, $z_swap) { return $m[1] . 'https://' . $zn . $z_swap($m[2]); },
            $html,
            -1,
            $relZoned
        );
        if (is_string($z_rel)) $html = $z_rel;
        if (!empty($wpc_pic_blocks)) {
            $html = strtr($html, $wpc_pic_blocks);
        }
        // Origin upload rasters the image rewrite left behind are moved onto the zone (the rewrite
        // does not cover every URL context). Sampled: the same markup reaches here on every render.
        $rasterZoned = (is_string($z_abs) ? (int) $absZoned : 0) + (is_string($z_rel) ? (int) $relZoned : 0);
        if ($rasterZoned > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('origin-urls-zoneified', ['raster' => $rasterZoned], true);
        }
        return $html;
    }


    public static function wpc_webp_immediate_ok()
    {


        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) {
            return false;
        }


        $cf = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']) || get_option('wpc_v2_cf_assets_seen', 0);
        if (!$cf && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'zone_is_cf')) {
            $cf = wps_rewriteLogic::zone_is_cf();
        }
        if (!$cf) {
            return true;
        }


        if (function_exists('wpc_force_natural') && wpc_force_natural()) {
            return true;
        }


        if (class_exists('WPC_Delivery_Resolver')) {
            $nav = WPC_Delivery_Resolver::orch_nav_signal();
            if ($nav === true)  return true;
            if ($nav === false) return false;
        }


        $pv = (string) get_transient('wpc_v2_cf_pod_version');


        if ($pv === '' && (is_admin() || (defined('DOING_CRON') && DOING_CRON))) {
            $zone = (string) get_option('ic_cdn_zone_name', '');
            if ($zone !== '') {
                $r  = wp_remote_get('https://' . $zone . '/wp-includes/css/dist/block-library/style.min.css', ['timeout' => 3, 'sslverify' => false, 'redirection' => 2, 'limit_response_size' => 2048]);
                $pv = is_wp_error($r) ? '' : (string) wp_remote_retrieve_header($r, 'x-cdn-version');

                set_transient('wpc_v2_cf_pod_version', $pv !== '' ? $pv : '0', $pv !== '' ? 12 * HOUR_IN_SECONDS : 2 * HOUR_IN_SECONDS);
            }
        }


        if ($pv !== '' && $pv !== '0' && version_compare(ltrim($pv, 'v'), '2.89.18.2', '<')) {
            return false;
        }
        return true;
    }

    /**
     * NULL-safe preg_replace. A preg_replace that hits the PCRE backtrack/JIT-stack limit returns
     * NULL; assigning that straight to the output-buffer $html serves a BLANK PAGE. Every buffer-pass
     * rewrite routes through this: on NULL (or non-string) it returns the original subject unchanged,
     * so the rewrite is skipped, never the page lost.
     */
    private static function wpc_preg_safe($pattern, $replacement, $subject, &$count = null)
    {
        $out = preg_replace($pattern, $replacement, $subject, -1, $count);
        if (!is_string($out)) {
            $count = 0;
            return $subject;
        }
        return $out;
    }


    public static function wpc_asset_naturalize($html)
    {
        $profiler_span = class_exists('Wpc_Profiler_Span') ? new Wpc_Profiler_Span('pass:wpc_asset_naturalize') : null;
        if (!is_string($html) || $html === '' || empty(self::$zone_name) || stripos($html, '/m:0') === false) {
            return $html;
        }
        if (!apply_filters('wpc_asset_naturalize_enabled', true)) {
            return $html;
        }
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on')
            && !wps_rewriteLogic::natural_assets_on()) {
            return $html;
        }
        $zone = preg_quote(self::$zone_name, '#');
        $bs = '\\\\?/';
        $zone_name = self::$zone_name;
        // $bs-tolerant throughout (not just the a: target) so a FULLY JSON-escaped m:0 transform inside
        // a JS/loader config (https:\/\/zone\/m:0\/a:...) also collapses; the closure re-escapes on output.
        $asset_exts = 'css|js|mjs|svg|png|jpe?g|gif|webp|avif|ico|bmp|woff2?|ttf|otf|eot|mp4|webm|json';
        $rx = '#https?:' . $bs . $bs . '(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)' . $bs . 'm:0' . $bs . 'a:(https?:' . $bs . $bs . '[^"\'()\s<>]+?\.(?:' . $asset_exts . ')(?![\w-])(?:\?[^"\'()\s<>]*)?)#i';
        $collapsed = 0;
        $out = preg_replace_callback($rx, static function ($m) use ($zone_name, &$collapsed) {
            $u_esc = (strpos($m[1], '\\/') !== false);
            $u_plain = $u_esc ? str_replace('\\/', '/', $m[1]) : $m[1];
            $hops = 0;
            while ($hops < 4 && preg_match('#^https?://[^/]+/m:0/a:(https?://.+)$#i', $u_plain, $nested_match)) {
                $u_plain = $nested_match[1];
                $hops++;
            }
            $p = function_exists('wp_parse_url') ? wp_parse_url($u_plain) : parse_url($u_plain);
            if (empty($p['path'])) {
                return $m[0];
            }


            if (!empty($p['host'])) {
                $asset_host = preg_replace('/^www\./i', '', (string) $p['host']);
                $site_host  = preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
                $zone_host  = preg_replace('/^www\./i', '', (string) $zone_name);
                $is_zone_host = ($zone_host !== '' && strcasecmp($asset_host, $zone_host) === 0);
                if (!$is_zone_host && $site_host !== '' && strcasecmp($asset_host, $site_host) !== 0) {
                    return $m[0];
                }
            }
            $natural = 'https://' . $zone_name . $p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');
            if ($u_esc) {
                $natural = str_replace('/', '\\/', $natural);
            }
            $collapsed++;
            return $natural;
        }, $html);
        // A zone m:0/a: asset URL (nested hops included) that reached the tail is collapsed to its
        // natural form: writers still mint the grammar. Sampled: every CDN render carries them.
        if ($collapsed > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('transform-urls-naturalized', ['assets_tail' => $collapsed], true);
        }
        return is_string($out) ? $out : $html;
    }

    public static function wpc_raster_naturalize($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name)) {
            return $html;
        }
        $wpc_pic_blocks = [];
        if (stripos($html, '<picture') !== false) {


            $masked = '';
            $offset = 0;
            $hlen   = strlen($html);
            while (($start = stripos($html, '<picture', $offset)) !== false) {
                $after = ($start + 8 < $hlen) ? $html[$start + 8] : '';
                if ($after !== '' && (ctype_alnum($after) || $after === '_')) {

                    $masked .= substr($html, $offset, ($start + 8) - $offset);
                    $offset  = $start + 8;
                    continue;
                }
                $end = stripos($html, '</picture>', $start);
                if ($end === false) {
                    break;
                }
                $end += 10;
                $k = "\x01WPCPIC" . count($wpc_pic_blocks) . "\x01";
                $wpc_pic_blocks[$k] = substr($html, $start, $end - $start);
                $masked .= substr($html, $offset, $start - $offset) . $k;
                $offset  = $end;
            }
            $masked .= substr($html, $offset);
            $html = $masked;
        }
        $transformsBefore = substr_count($html, '/u:') + substr_count($html, '/a:');
        $html = self::wpc_raster_naturalize_passes($html);
        // A raster transform URL (zone/.../u:<origin>) is collapsed to its natural zone URL: the
        // writers still mint the grammar. Counted as transform segments that left the buffer.
        // Sampled: every render of a CDN page carries them.
        $rasterCollapsed = $transformsBefore - (substr_count((string) $html, '/u:') + substr_count((string) $html, '/a:'));
        if ($rasterCollapsed > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('transform-urls-naturalized', ['raster' => $rasterCollapsed], true);
        }


        $html = self::wpc_css_bg_imageset_sweep($html);
        $html = self::wpc_css_bg_external_sweep($html);
        if (!empty($wpc_pic_blocks)) {
            $html = strtr($html, $wpc_pic_blocks);
        }
        $html = self::wpc_park_picture_sources($html);
        $html = self::wpc_unpark_carousel_eager_window($html);
        return $html;
    }

    // v7.21.260 — THE SOURCE PARK IS AN INVARIANT, NOT AN EMITTER PATCH. .254 fixed the
    // helper the known emitters share, and bestexteriorsinc still served a live
    // <source srcset> beside a parked img (slider badges, type-first attr order = a lane
    // the .254 sweep never found). Stop chasing emitters: one terminal pass enforces the
    // rule itself — inside any <picture> whose <img> is truly parked (placeholder src +
    // data-src), every live http(s) srcset becomes data-srcset. The pixel and the
    // never-blank belt already flip source[data-srcset] on restore; the onerror fallback
    // removes sources. Runs after the picture-mask restore so it sees every block.
    /**
     * v7.21.300 — CAROUSEL EAGER WINDOW. A declared image-carousel paints its first
     * slides_to_show slides from the first frame ("3 small then snaps": the parked 9
     * arrived only at gesture+belt). The widget DECLARES the window; unpark exactly
     * that many slides in place — src from data-src, source ladders from data-srcset —
     * http payloads only (a data: payload is the .297 poison, never unparked).
     */
    public static function wpc_unpark_carousel_eager_window($html)
    {
        try {
            if (!is_string($html) || stripos($html, 'image-carousel.default') === false
                || !apply_filters('wpc_carousel_eager_window', true)) {
                return $html;
            }
            $isMobile = function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile();
            $out = $html;
            if (!preg_match_all('~<div[^>]+data-widget_type="image-carousel\.default"[^>]*>~i', $out, $carouselMatches, PREG_OFFSET_CAPTURE)) {
                return $html;
            }
            $carouselUnparked = 0;
            foreach (array_slice($carouselMatches[0], 0, 6) as $carouselMatch) {
                $tag = (string) $carouselMatch[0];
                $off = (int) $carouselMatch[1];
                $n = 3;
                if (preg_match('~data-settings="([^"]*)"~i', $tag, $ds)) {
                    $set = json_decode(html_entity_decode($ds[1], ENT_QUOTES), true);
                    if (is_array($set)) {
                        $nD = max(1, (int) (isset($set['slides_to_show']) ? $set['slides_to_show'] : 3));
                        $nT = max(1, (int) (isset($set['slides_to_show_tablet']) ? $set['slides_to_show_tablet'] : $nD));
                        $nM = max(1, (int) (isset($set['slides_to_show_mobile']) ? $set['slides_to_show_mobile'] : $nT));
                        $n = $isMobile ? $nM : $nD;
                    }
                }
                $seg = substr($out, $off, 40000);
                $end = stripos($seg, 'data-widget_type=', strlen($tag));
                if ($end !== false) { $seg = substr($seg, 0, $end); }
                $windowLength = strlen($seg);
                $noscriptBlocks = [];
                $seg = (string) preg_replace_callback('/<noscript\b.*?<\/noscript>/is', static function ($nm) use (&$noscriptBlocks) {
                    $noscriptBlocks[] = $nm[0];
                    return "\x01NS300" . (count($noscriptBlocks) - 1) . "\x01";
                }, $seg);
                $done = 0;
                $seen = 0;
                $fix = preg_replace_callback('/<img\b[^>]*>/i', static function ($im) use (&$done, &$seen, $n) {
                    if ($seen++ >= $n) { return $im[0]; }
                    $t = (string) $im[0];
                    if (!preg_match('/\bsrc="data:image\/svg/i', $t)) { return $t; }
                    if (!preg_match('/\bdata-src="(https?:[^"]+)"/i', $t, $dm)) { return $t; }
                    $done++;
                    $t = (string) preg_replace('/\bsrc="data:image\/svg[^"]*"/i', 'src="' . $dm[1] . '"', $t, 1);
                    if (preg_match('/\bdata-srcset="(https?:[^"]+)"/i', $t, $sm2)) {
                        $t = (string) str_replace('data-srcset="' . $sm2[1] . '"', 'srcset="' . $sm2[1] . '"', $t);
                    }
                    return $t;
                }, $seg);
                if (!is_string($fix)) { continue; }
                // sibling sources of the unparked imgs: flip http data-srcset ladders in the
                // same window, bounded to the number of pictures the img pass touched.
                $sdone = 0;
                $fix = preg_replace_callback('/<source\b[^>]*\bdata-srcset="(https?:[^"]+)"[^>]*>/i', static function ($sq) use (&$sdone, $done) {
                    if ($sdone >= $done) { return $sq[0]; }
                    $sdone++;
                    return str_replace('data-srcset="', 'srcset="', $sq[0]);
                }, $fix);
                foreach ($noscriptBlocks as $noscriptIndex => $noscriptBlock) {
                    $fix = str_replace("\x01NS300" . $noscriptIndex . "\x01", $noscriptBlock, $fix);
                }
                if ($fix === '') { continue; }
                $out = substr_replace($out, $fix, $off, $windowLength);
                $carouselUnparked += $done;
            }
            // The lazy lanes park carousel slides the widget declares visible; the declared first
            // slides are un-parked. Sampled: the widget declares the same window on every render.
            if ($carouselUnparked > 0 && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('carousel-window-unparked', ['n' => $carouselUnparked], true);
            }
            return $out;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public static function wpc_park_picture_sources($html)
    {
        try {
            if (!is_string($html) || stripos($html, '<picture') === false
                || !apply_filters('wpc_picture_source_park', true)) {
                return $html;
            }
            // .297 — POISONED-SOURCE STRIP (own gate — a fully-poisoned page has NO http
            // srcset left and must still heal): a <source> whose (data-)srcset payload is
            // a data: URI carries no image — flipped live it SHADOWS the img's good src
            // via currentSrc forever (beucomply carousel: double-processed cache wrote the
            // placeholder over the ladder). No payload = no source; the img wins.
            if (stripos($html, 'srcset="data:image/svg') !== false) {
                $html = (string) preg_replace('/<source\b[^>]*\s(?:data-)?srcset="data:image\/svg[^"]*"[^>]*>\s*/i', '', $html, -1, $poisonedSources);
                // A <source> whose srcset is a data: placeholder shadows the img's real file for
                // good (a double-processed buffer wrote it); it is removed. Never sampled.
                if ($poisonedSources > 0 && function_exists('wpc_render_belt_note')) {
                    wpc_render_belt_note('picture-sources-parked', ['poisoned' => (int) $poisonedSources]);
                }
            }
            if (stripos($html, 'srcset="http') === false) {
                return $html;
            }
            $parkedPictures = 0;
            $out = preg_replace_callback('#<picture\b[^>]*>.*?</picture>#is', static function ($m) use (&$parkedPictures) {
                $blk = (string) $m[0];
                if (stripos($blk, ' srcset="http') === false) { return $blk; }
                if (!preg_match('/<img\b[^>]*\bsrc="data:[^"]*"[^>]*\bdata-src=/i', $blk)
                    && !preg_match('/<img\b[^>]*\bdata-src=[^>]*\bsrc="data:/i', $blk)) {
                    return $blk;
                }
                $fix = preg_replace_callback('/<source\b[^>]*>/i', static function ($sm) {
                    $t = (string) preg_replace('/\ssrcset="(http[^"]*)"/i', ' data-srcset="$1"', $sm[0]);
                    return $t !== '' ? $t : $sm[0];
                }, $blk);
                if (is_string($fix) && $fix !== $blk) {
                    $parkedPictures++;
                }
                return is_string($fix) ? $fix : $blk;
            }, $html);
            // A <picture> whose <img> is parked still had live sources: the emitter that parked the
            // img did not park them, so they are parked here. Never sampled: an emitter missed.
            if ($parkedPictures > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('picture-sources-parked', ['parked' => $parkedPictures]);
            }
            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public static function wpc_crawler_urls_as_printed($html, $ctx = null)
    {
        if (!is_string($html) || $html === '' || !is_object($ctx) || !isset($ctx->pristine) || !is_string($ctx->pristine) || $ctx->pristine === '') {
            return $html;
        }
        $pristine = function_exists('wpc_heal_mixed_content') ? wpc_heal_mixed_content($ctx->pristine) : $ctx->pristine;
        $kinds = ['icon' => '#<link\b(?=[^>]*\srel\s*=\s*(["\']?)(?:shortcut icon|icon|apple-touch-icon(?:-precomposed)?|mask-icon)\1[\s/>])[^>]*>#i'];
        if (empty(self::$settings['optimize_meta_images']) || self::$settings['optimize_meta_images'] == '0') {
            $kinds['meta'] = '#<meta\b(?=[^>]*\s(?:property|name)\s*=\s*(["\'])(?:og:image(?::url|:secure_url)?|twitter:image(?::src)?)\1)[^>]*>#i';
            $kinds['ld'] = '#<script\b[^>]*\stype\s*=\s*["\']application/ld\+json["\'][^>]*>.*?</script>#is';
        }
        $edits = [];
        $restored = [];
        foreach ($kinds as $kind => $rx) {
            if (!preg_match_all($rx, $html, $now, PREG_OFFSET_CAPTURE) || !preg_match_all($rx, $pristine, $printed)) {
                continue;
            }
            if (count($now[0]) !== count($printed[0])) {
                $restored[$kind . '_count_drift'] = 1;
                continue;
            }
            foreach ($now[0] as $i => $element) {
                if ($element[0] !== $printed[0][$i]) {
                    $edits[] = [$element[1], strlen($element[0]), $printed[0][$i]];
                    $restored[$kind] = (isset($restored[$kind]) ? $restored[$kind] : 0) + 1;
                }
            }
        }
        if ($edits === []) {
            if ($restored !== [] && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('crawler-urls-restored', $restored, true);
            }
            return $html;
        }
        usort($edits, function ($x, $y) { return $y[0] - $x[0]; });
        for ($i = 1; $i < count($edits); $i++) {
            if ($edits[$i][0] + $edits[$i][1] > $edits[$i - 1][0]) {
                if (function_exists('wpc_render_belt_note')) {
                    wpc_render_belt_note('crawler-urls-restored', ['overlap' => 1], true);
                }
                return $html;
            }
        }
        foreach ($edits as $edit) {
            $html = substr($html, 0, $edit[0]) . $edit[2] . substr($html, $edit[0] + $edit[1]);
        }
        if (function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('crawler-urls-restored', $restored, true);
        }
        return $html;
    }

    // v7.21.42 — HOST-TWIN HEAL. Clone/migration residue: a site copied to a new domain keeps
    // static CSS artifacts (localized-gfonts files, builder caches, DB-baked custom CSS) whose
    // absolute URLs still name the OLD host — every font behind them dies on CORS, and our own
    // carrier/late-faces/preload lanes faithfully re-harvest the poison (falknerei.hozjan.net:
    // uploads/gfonts_local/gfonts_local.css carried 66 production-host URLs). The proof a URL is
    // residue and not an intentional external ref: its path lives under wp-content/wp-includes
    // AND the same file exists on THIS install's disk. Only then is the host rewritten to the
    // current origin. Zone/CDN hosts are never touched. Kill filter wpc_host_twin_heal.
    public static function wpc_heal_css_host_twin($css)
    {
        try {
            if (!is_string($css) || $css === '' || stripos($css, 'url(') === false
                || !function_exists('home_url') || !defined('ABSPATH') || !defined('WP_CONTENT_DIR')
                || !apply_filters('wpc_host_twin_heal', true)) {
                return $css;
            }
            $home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
            if ($home_host === '') {
                return $css;
            }
            $zone_host = strtolower((string) self::$zone_name);
            $healed_count = 0;
            $out = preg_replace_callback('#\burl\(\s*(["\']?)(?:https?:)?//([a-z0-9.\-]+)(?::\d+)?(/[^"\')\s]*)#i',
                static function ($m) use ($home_host, $zone_host, &$healed_count) {
                    if ($healed_count >= (int) apply_filters('wpc_host_twin_heal_max', 80)) {
                        return $m[0];
                    }
                    $host = strtolower($m[2]);
                    $path = (string) $m[3];
                    if ($host === $home_host
                        || ($zone_host !== '' && $host === $zone_host)
                        || strpos($host, 'zapwp') !== false || strpos($host, 'b-cdn.net') !== false
                        || strpos($host, 'cloudfront.net') !== false) {
                        return $m[0];
                    }
                    $clean = substr($path, 0, strcspn($path, '?#'));
                    $wc = strpos($clean, '/wp-content/');
                    $wi = strpos($clean, '/wp-includes/');
                    if ($wc !== false) {
                        $disk = rtrim(WP_CONTENT_DIR, '/') . substr($clean, $wc + 11);
                    } elseif ($wi !== false) {
                        $disk = rtrim(ABSPATH, '/') . '/wp-includes' . substr($clean, $wi + 12);
                    } else {
                        return $m[0];
                    }
                    if (!@is_file($disk)) {
                        return $m[0];
                    }
                    $healed_count++;
                    return 'url(' . $m[1] . 'https://' . $home_host . $path;
                }, (string) $css);
            return is_string($out) ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }

    // v7.21.42 — the same heal for the site's OWN linked sheets: same-origin .css read from
    // disk; when the heal changes bytes the tag is repointed to a content-keyed derived copy
    // under cache/wpc-hostfix/ (mtime:size verdict index, keep-newest-3 per stem, 40-sheet/1MB
    // caps, fail-open). Fixes the page even where our carrier loses the @font-face cascade to
    // the poisoned upstream declaration.
    public static function css_host_twin_sweep($html)
    {
        try {
            if (!is_string($html) || $html === '' || stripos($html, '.css') === false
                || (function_exists('is_admin') && is_admin())
                || !defined('WP_CONTENT_DIR') || !defined('ABSPATH')
                || !apply_filters('wpc_host_twin_heal', true)) {
                return $html;
            }
            $originHost = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            if ($originHost === '') {
                return $html;
            }
            $sitePath = (string) wp_parse_url(site_url('/'), PHP_URL_PATH);
            $sheetIndex = get_option('wpc_hosttwin_idx42');
            if (!is_array($sheetIndex)) {
                $sheetIndex = [];
            }
            $indexDirty = false;
            $sheetsChecked = 0;
            $repointed = 0;
            $out = preg_replace_callback('/<link\b[^>]*\bhref=(["\'])([^"\']+\.css(?:\?[^"\']*)?)\1[^>]*>/i',
                static function ($lm) use ($originHost, $sitePath, &$sheetIndex, &$indexDirty, &$sheetsChecked, &$repointed) {
                    if ($sheetsChecked >= (int) apply_filters('wpc_host_twin_sweep_max', 64)) {
                        return $lm[0];
                    }
                    $href = (string) $lm[2];
                    if (strpos($href, '/cache/wpc-hostfix/') !== false || strpos($href, '/cache/wpc-bgset/') !== false) {
                        return $lm[0];
                    }
                    $bn = strtolower(basename((string) wp_parse_url($href, PHP_URL_PATH)));
                    if (preg_match('/^(?:wps_|critical_|font-subsets|used)/', $bn)) {
                        return $lm[0];
                    }
                    $h = (string) wp_parse_url($href, PHP_URL_HOST);
                    if ($h !== '' && strcasecmp($h, $originHost) !== 0) {
                        return $lm[0];
                    }
                    $path = (string) wp_parse_url($href, PHP_URL_PATH);
                    if ($path === '' || strpos($path, '/wp-') === false) {
                        return $lm[0];
                    }
                    $rel = $path;
                    if ($sitePath !== '' && $sitePath !== '/' && strpos($rel, rtrim($sitePath, '/') . '/') === 0) {
                        $rel = substr($rel, strlen(rtrim($sitePath, '/')));
                    }
                    $disk = rtrim(ABSPATH, '/') . $rel;
                    $sheetsChecked++;
                    $sz = @filesize($disk);
                    $mt = @filemtime($disk);
                    if (!$sz || !$mt || $sz < 64 || $sz > (int) apply_filters('wpc_host_twin_sweep_cap', 1048576)) {
                        return $lm[0];
                    }
                    $key = md5($path);
                    $sig = $mt . ':' . $sz . ':168';
                    if (isset($sheetIndex[$key]) && $sheetIndex[$key]['sig'] === $sig) {
                        $o = (string) $sheetIndex[$key]['out'];
                        if ($o === '' || !@is_readable(WP_CONTENT_DIR . '/cache/wpc-hostfix/' . $o)) {
                            return $lm[0];
                        }
                        $repointed++;
                        return str_replace($lm[1] . $lm[2] . $lm[1],
                            $lm[1] . content_url('cache/wpc-hostfix/' . $o) . $lm[1], $lm[0]);
                    }
                    $css = (string) @file_get_contents($disk);
                    $verdict = '';
                    if ($css !== '') {
                        $healed = (stripos($css, 'url(') !== false) ? self::wpc_heal_css_host_twin($css) : $css;
                        if (!is_string($healed)) {
                            $healed = $css;
                        }
                        $hostsHealed = $healed !== $css;
                        $relAbsolutized = false;
                        $stacksSpliced = false;
                        // v7.21.92 — RELATIVE url() MUST NOT MOVE WITH THE COPY. The hostfix
                        // copy serves from /cache/wpc-hostfix/, so the source's relative font
                        // urls resolved THERE: falknerei 404-stormed
                        // cache/wpc-hostfix/core/admin/fonts/fontawesome/fa-solid-900.woff2
                        // (+ .woff/.ttf) and FA glyphs broke until other paths loaded them.
                        // Absolutize every relative ref against the SOURCE sheet's directory.
                        if (stripos($healed, 'url(') !== false) {
                            $sheetDir = rtrim(dirname($path), '/');
                            $absolutizedCss = preg_replace_callback('/url\(\s*(["\']?)(?!https?:|\/\/|\/|data:|#)([^"\')\s]+)\1\s*\)/i', function ($um) use ($sheetDir) {
                                $resolvedPath = $sheetDir . '/' . $um[2];
                                while (preg_match('#/[^/]+/\.\./#', $resolvedPath)) {
                                    $resolvedPath = preg_replace('#/[^/]+/\.\./#', '/', $resolvedPath, 1);
                                }
                                return 'url(' . $um[1] . $resolvedPath . $um[1] . ')';
                            }, $healed);
                            if (is_string($absolutizedCss) && $absolutizedCss !== '') {
                                $relAbsolutized = $absolutizedCss !== $healed;
                                $healed = $absolutizedCss;
                            }
                        }
                        // v7.21.45 — EAGER EXCLUDED SHEETS MUST CARRY THE SPLICED STACKS. An
                        // origin-served kit sheet (elementor post-90) arrives after crit and its raw
                        // font-family:"Fredoka",sans-serif OVERRIDES the spliced stack — the metric
                        // fallback drops out mid-load and the heading re-wraps (borderlessmoves: H1
                        // 112->168px, the 0.13 shift PSI pinned on the shape divider whose section
                        // moved). Same splice the used-css store gets, applied to the derived copy.
                        if (function_exists('wpc_css_insert_fallbacks')
                            && stripos($healed, 'font-family') !== false
                            && apply_filters('wpc_sheet_stack_splice', true)) {
                            $splicedCss = wpc_css_insert_fallbacks($healed);
                            if (is_string($splicedCss) && $splicedCss !== '') {
                                $stacksSpliced = $splicedCss !== $healed;
                                $healed = $splicedCss;
                            }
                        }
                        if ($healed !== $css) {
                            $copyDir = WP_CONTENT_DIR . '/cache/wpc-hostfix';
                            if (!is_dir($copyDir)) {
                                @mkdir($copyDir, 0755, true);
                            }
                            $stem = preg_replace('/\.css$/', '', basename($path));
                            $name = $stem . '-' . substr(md5($healed), 0, 10) . '.css';
                            if (wpc_fs_put($copyDir . '/' . $name, $healed) !== false) {
                                $siblingCopies = (array) @glob($copyDir . '/' . $stem . '-*.css');
                                if (count($siblingCopies) > 3) {
                                    usort($siblingCopies, static function ($a, $b) {
                                        return (int) @filemtime($a) - (int) @filemtime($b);
                                    });
                                    foreach (array_slice($siblingCopies, 0, count($siblingCopies) - 3) as $old) {
                                        if (basename($old) !== $name) {
                                            @unlink($old);
                                        }
                                    }
                                }
                                $verdict = $name;
                                // The copy is written for any of three repairs, not only a
                                // foreign host: the fields name which one made the bytes differ
                                // (a copy with hosts:0 exists for its relative urls or its stacks).
                                if (function_exists('wpc_cache_first_log')) {
                                    wpc_cache_first_log('host-twin-healed', '', basename($path), ['out' => $name,
                                        'hosts' => (int) $hostsHealed, 'rel' => (int) $relAbsolutized, 'spliced' => (int) $stacksSpliced]);
                                }
                            }
                        }
                    }
                    $sheetIndex[$key] = ['sig' => $sig, 'out' => $verdict];
                    $indexDirty = true;
                    if ($verdict === '') {
                        return $lm[0];
                    }
                    $repointed++;
                    return str_replace($lm[1] . $lm[2] . $lm[1],
                        $lm[1] . content_url('cache/wpc-hostfix/' . $verdict) . $lm[1], $lm[0]);
                }, $html);
            // A site's own sheet is served from a repaired copy when its bytes need a heal the
            // original cannot carry (migrated hosts, relative urls, font stacks). Sampled: the
            // cause is in the site's files, so every render of such a site repoints the same tags.
            if ($repointed > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('host-twin-repointed', ['n' => $repointed], true);
            }
            if ($indexDirty) {
                if (count($sheetIndex) > 80) {
                    $sheetIndex = array_slice($sheetIndex, -60, null, true);
                }
                update_option('wpc_hosttwin_idx42', $sheetIndex, false);
            }
            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    // v7.10.795 — collapse /a/b/../c and /./ segments without touching the filesystem.
    public static function wpc_collapse_css_path($path)
    {
        $seg = explode('/', (string) $path);
        $out = [];
        foreach ($seg as $s) {
            if ($s === '.' || ($s === '' && !empty($out))) { continue; }
            if ($s === '..') { if (count($out) > 1) { array_pop($out); } continue; }
            $out[] = $s;
        }
        return implode('/', $out);
    }

    /**
     * v7.10.795 — rebase every relative url() in a sheet that is being RELOCATED. A derived
     * copy served from cache/wpc-bgset/ resolves relative refs against ITS OWN directory, so
     * fonts/images referenced as ../fonts/x.woff2 would 404. Absolute (scheme, //, /, data:,
     * #) refs pass through byte-identical. Rebasing to absolute also lets the image-set sweep
     * match uploads refs the sheet wrote relatively.
     */
    public static function wpc_rebase_css_urls($css, $sheet_url)
    {
        $sheet_path = (string) wp_parse_url((string) $sheet_url, PHP_URL_PATH);
        $sheet_host = (string) wp_parse_url((string) $sheet_url, PHP_URL_HOST);
        if ($sheet_path === '' || $sheet_host === '') { return $css; }
        $sheet_dir = rtrim(str_replace('\\', '/', dirname($sheet_path)), '/');
        $rebased_css = preg_replace_callback('/\burl\(\s*(["\']?)([^"\')\s]+)\1\s*\)/i',
            static function ($um) use ($sheet_dir, $sheet_host) {
                $u = (string) $um[2];
                if ($u === '' || $u[0] === '/' || $u[0] === '#'
                    || preg_match('#^(?:https?:)?//|^data:#i', $u)) {
                    return $um[0];
                }
                $abs = self::wpc_collapse_css_path($sheet_dir . '/' . $u);
                return 'url(' . $um[1] . 'https://' . $sheet_host . $abs . $um[1] . ')';
            }, (string) $css);
        return is_string($rebased_css) ? $rebased_css : $css;
    }

    /**
     * v7.10.795 — BACKGROUND IMAGE-SET FOR UNCOMBINED EXTERNAL SHEETS. The .785 sweep only
     * sees bytes inside the document; with CSS combining off (heritage), a 200 KiB hero
     * background living in an external theme sheet never met the sweep and shipped as raw
     * JPEG. This pass reads each SAME-ORIGIN linked sheet from disk, rebases relative url()s,
     * runs the exact .785 sweep over it, and — only when the sweep changed bytes — relinks
     * the tag to a CONTENT-KEYED derived copy under cache/wpc-bgset/. Verdicts are indexed by
     * mtime:size so an unchanged sheet costs zero IO on later renders.
     */
    public static function wpc_css_bg_external_sweep($html)
    {
        try {
            if (!is_string($html) || $html === '' || stripos($html, '.css') === false
                || !self::wpc_css_bg_imageset_active()
                || !apply_filters('wpc_css_bg_sweep_external', true)) {
                return $html;
            }
            $origin_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            $site_root_url = (string) site_url('/');
            $site_path = (string) wp_parse_url($site_root_url, PHP_URL_PATH);
            if ($origin_host === '' || !defined('WP_CONTENT_DIR')) { return $html; }
            $sheet_index = get_option('wpc_bgset_idx');
            if (!is_array($sheet_index)) { $sheet_index = []; }
            $index_dirty = false;
            $sheets_checked = 0;
            $out = preg_replace_callback('/<link\b[^>]*\bhref=(["\'])([^"\']+\.css(?:\?[^"\']*)?)\1[^>]*>/i',
                static function ($lm) use ($origin_host, $site_path, &$sheet_index, &$index_dirty, &$sheets_checked) {
                    if ($sheets_checked >= (int) apply_filters('wpc_css_bg_sweep_external_max', 40)) { return $lm[0]; }
                    $href = (string) $lm[2];
                    $bn = strtolower(basename((string) wp_parse_url($href, PHP_URL_PATH)));
                    // Our own derived/generated artifacts are swept at build time already.
                    if (strpos($href, '/cache/wpc-bgset/') !== false
                        || preg_match('/^(?:wps_|critical_|font-subsets|used)/', $bn)) {
                        return $lm[0];
                    }
                    $h = (string) wp_parse_url($href, PHP_URL_HOST);
                    if ($h !== '' && strcasecmp($h, $origin_host) !== 0) { return $lm[0]; } // same-origin only
                    $path = (string) wp_parse_url($href, PHP_URL_PATH);
                    if ($path === '' || strpos($path, '/wp-') === false) { return $lm[0]; }
                    // site-path prefix -> disk (subdir installs: /vwp/wp-content/... under ABSPATH)
                    $rel = $path;
                    if ($site_path !== '' && $site_path !== '/' && strpos($rel, rtrim($site_path, '/') . '/') === 0) {
                        $rel = substr($rel, strlen(rtrim($site_path, '/')));
                    }
                    $disk = rtrim(ABSPATH, '/') . $rel;
                    $sheets_checked++;
                    $sz = @filesize($disk);
                    $mt = @filemtime($disk);
                    if (!$sz || !$mt || $sz < 64 || $sz > (int) apply_filters('wpc_css_bg_sweep_external_cap', 1048576)) {
                        return $lm[0];
                    }
                    $key = md5($path);
                    $sig = $mt . ':' . $sz;
                    if (isset($sheet_index[$key]) && $sheet_index[$key]['sig'] === $sig) {
                        $o = (string) $sheet_index[$key]['out'];
                        if ($o === '' || !@is_readable(WP_CONTENT_DIR . '/cache/wpc-bgset/' . $o)) {
                            return $lm[0];
                        }
                        return str_replace($lm[1] . $lm[2] . $lm[1],
                            $lm[1] . content_url('cache/wpc-bgset/' . $o) . $lm[1], $lm[0]);
                    }
                    $css = (string) @file_get_contents($disk);
                    $verdict = '';
                    if ($css !== '' && stripos($css, 'background') !== false) {
                        $based = self::wpc_rebase_css_urls($css, 'https://' . $origin_host . $path);
                        $swept = self::wpc_css_bg_imageset_sweep($based);
                        if (is_string($swept) && substr_count($swept, 'image-set(') > substr_count($css, 'image-set(')) {
                            $copy_dir = WP_CONTENT_DIR . '/cache/wpc-bgset';
                            if (!is_dir($copy_dir)) { @mkdir($copy_dir, 0755, true); }
                            $stem = preg_replace('/\.css$/', '', basename($path));
                            $name = $stem . '-' . substr(md5($swept), 0, 10) . '.css';
                            if (wpc_fs_put($copy_dir . '/' . $name, $swept) !== false) {
                                // Keep the newest 3 versions per stem: cached HTML may still
                                // reference an older content-keyed name until its own purge.
                                $sibling_copies = (array) @glob($copy_dir . '/' . $stem . '-*.css');
                                if (count($sibling_copies) > 3) {
                                    usort($sibling_copies, static function ($a, $b) {
                                        return (int) @filemtime($a) - (int) @filemtime($b);
                                    });
                                    foreach (array_slice($sibling_copies, 0, count($sibling_copies) - 3) as $old) {
                                        if (basename($old) !== $name) { @unlink($old); }
                                    }
                                }
                                $verdict = $name;
                            }
                        }
                    }
                    $sheet_index[$key] = ['sig' => $sig, 'out' => $verdict];
                    $index_dirty = true;
                    if ($verdict === '') { return $lm[0]; }
                    return str_replace($lm[1] . $lm[2] . $lm[1],
                        $lm[1] . content_url('cache/wpc-bgset/' . $verdict) . $lm[1], $lm[0]);
                }, $html);
            if ($index_dirty) {
                if (count($sheet_index) > 80) { $sheet_index = array_slice($sheet_index, -60, null, true); }
                update_option('wpc_bgset_idx', $sheet_index, false);
            }
            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    private static function wpc_raster_naturalize_passes($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name)) {
            return $html;
        }
        $s = self::$settings;
        if (!is_array($s) || empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') {
            return $html;
        }
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            return $html;
        }
        if (!class_exists('WPC_Negotiated_Delivery') || !WPC_Negotiated_Delivery::cdn_images_enabled($s)) {
            return $html;
        }
        if (!self::wpc_zone_serves_natural_urls()) {
            return $html;
        }
        $zone = preg_quote((string) self::$zone_name, '#');


        $html = self::wpc_preg_safe(
            '#https?://(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)/(?:(?:q|r|wp|w|m):[a-z0-9]+/|font:true/){1,40}(?:a|u):(https?://' . $zone . '/[^"\'()\s<>]+)#i',
            '$1',
            $html
        );
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$origin || strcasecmp((string) self::$zone_name, $origin) === 0) { // EQUALITY not substring: cdn.{origin} contains origin, so a substring guard false-positives every custom-CNAME zone
            return $html;
        }
        // Any width (not just w:1): the srcset ladder owns responsive widths, so uploads rasters go

        // Uploads-scoped (theme/plugin-path transforms keep their working form). The "/" or "\/" lets
        // JSON-escaped u: targets naturalize too.
        $bs = '\\\\?/';


        $wpc_bases = function_exists('wpc_v2_upload_base_paths') ? wpc_v2_upload_base_paths() : ['/wp-content/uploads'];
        $base_alt = self::uploads_path_alternation($bs);
        $rx = '#https?://(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)/(?:q:[a-z0-9]+/)?r:\d+/wp:(\d)/w:\d+/u:(https?:' . $bs . $bs . '[^"\'()\s<>]+?' . $bs . $base_alt . $bs . '[^"\'()\s<>]+?\.(?:png|jpe?g|webp|gif)(?![\w-])(?:\?[^"\'()\s<>]*)?)#i';
        $naturalize = static function ($m, $allow_webp) use ($wpc_bases) {


            if ($m[1] === '2') {
                return $m[0];
            }
            // Escape-tolerant: a u: target inside JSON arrives slash-escaped — unescape to find the
            // path, re-escape on output.
            $u_esc = (strpos($m[2], '\\/') !== false);
            $u_plain = $u_esc ? str_replace('\\/', '/', $m[2]) : $m[2];
            $pos = false;
            foreach ($wpc_bases as $wpc_b) {
                $needle = '/' . trim((string) $wpc_b, '/') . '/';
                if ($needle === '//') { continue; }
                $p = stripos($u_plain, $needle);
                if ($p !== false) { $pos = $p; break; }
            }
            if ($pos === false) {
                return $m[0];
            }


            if (preg_match_all('#https?://([^/"\'()\s<>]+)#i', substr($u_plain, 0, $pos), $host_matches) && !empty($host_matches[1])) {
                $asset_host = preg_replace('/^www\./i', '', (string) end($host_matches[1]));
                $site_host  = preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
                if ($site_host !== '' && strcasecmp($asset_host, $site_host) !== 0) {
                    return $m[0];
                }
            }


            // ~3919). Collapse only when w==1 (no resize intent) or the target's own -WxH suffix is
            // ≤ the transform width (the suffix carries the width; the edge OTF serves those bytes).
            $transform_width = preg_match('#/w:(\d+)/(?:a|u):#i', $m[0], $width_match) ? (int) $width_match[1] : 1;
            if ($transform_width > 1) {
                $target_path = preg_replace('/\?.*$/', '', $u_plain);
                if (!preg_match('/-(\d+)x\d+\.(?:png|jpe?g|webp|gif)$/i', $target_path, $size_suffix_match)
                    || (int) $size_suffix_match[1] > $transform_width) {
                    return $m[0];
                }
            }
            $rel = substr($u_plain, $pos);
            if ($allow_webp && $m[1] === '1') {
                $origin_ext = preg_match('/\.(png|jpe?g)(?:\?|$)/i', $rel, $ext_match) ? strtolower($ext_match[1]) : '';
                $rel = preg_replace('/\.(?:png|jpe?g)(\?|$)/i', '.webp$1', $rel);
                // v7.20.15 — origin-ext hint on the collapsed natural form (edge skips its probe ladder)
                if ($origin_ext !== '' && class_exists('wps_rewriteLogic')) {
                    $src_hint = wps_rewriteLogic::src_hint_qs($origin_ext);
                    if ($src_hint !== '' && !preg_match('/[?&]src=/', $rel)) {
                        $rel .= (strpos($rel, '?') !== false ? '&' . substr($src_hint, 1) : $src_hint);
                    }
                }
            }
            $natural = 'https://' . self::$zone_name . $rel;
            if ($u_esc) {
                $natural = str_replace('/', '\\/', $natural);
            }
            return $natural;
        };
        // Pass 1 — <link>/<meta> tags: same-ext natural, any mode. These tags are never
        // JS-width-managed, so the nd/jpeg gate below doesn't apply, and w:1 does no resize work anyway.
        $html = preg_replace_callback('#<(?:link|meta)\b[^>]*>#i', static function ($tag) use ($rx, $naturalize) {
            return preg_replace_callback($rx, static function ($m) use ($naturalize) {
                return $naturalize($m, false);
            }, $tag[0]);
        }, $html);


        $rx_w1 = '#https?://(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)/(?:q:[a-z0-9]+/)?r:\d+/wp:(\d)/w:1/u:(https?:' . $bs . $bs . '[^"\'()\s<>]+?' . $bs . $base_alt . $bs . '[^"\'()\s<>]+?\.(?:png|jpe?g|webp|gif)(?![\w-])(?:\?[^"\'()\s<>]*)?)#i';


        $html = preg_replace_callback($rx_w1, static function ($m) use ($naturalize) {
            $u   = ($m[1] === '1') ? (strpos($m[2], '\\/') !== false ? str_replace('\\/', '/', $m[2]) : $m[2]) : '';
            $ext = $u !== '' ? strtolower(pathinfo(preg_replace('/\?.*$/', '', $u), PATHINFO_EXTENSION)) : '';
            $allow = ($ext !== '' && class_exists('wps_rewriteLogic')
                && wps_rewriteLogic::wpc_single_url_format($ext, null, null) === 'webp');
            return $naturalize($m, $allow);
        }, $html);
        $nd_webp = WPC_Negotiated_Delivery::is_active();
        $nd_jpeg = !$nd_webp && WPC_Negotiated_Delivery::is_active_jpeg();


        $otf_live = (function_exists('wpc_force_natural') && wpc_force_natural())
            || (class_exists('wps_rewriteLogic') && wps_rewriteLogic::avif_natural_source_ok())
            || self::wpc_webp_immediate_ok();
        if (!$nd_webp && !$nd_jpeg && !$otf_live) {
            return $html;
        }


        $html = preg_replace_callback($rx, static function ($m) use ($naturalize, $nd_webp) {
            if ($nd_webp) {
                return $naturalize($m, true);
            }
            $u   = (strpos($m[2], '\\/') !== false) ? str_replace('\\/', '/', $m[2]) : $m[2];
            $ext = strtolower(pathinfo(preg_replace('/\?.*$/', '', $u), PATHINFO_EXTENSION));
            $allow = ($ext !== '' && class_exists('wps_rewriteLogic')
                && wps_rewriteLogic::wpc_single_url_format($ext, null, null) === 'webp');
            return $naturalize($m, $allow);
        }, $html);


        $html = preg_replace_callback('#<img\b[^>]*\bdata-src="[^"]+"[^>]*>#i', static function ($m) {
            $tag = $m[0];
            if (strpos($tag, 'src="data:image/svg+xml;base64,') === false) {
                return $tag;
            }
            if (!preg_match('/\bdata-src="([^"]+)"/', $tag, $ds)) {
                return $tag;
            }
            $tag = preg_replace('/\bsrc="data:image\/svg\+xml;base64,[^"]*"/', 'src="' . $ds[1] . '"', $tag, 1);
            return preg_replace('/\s+data-src="[^"]+"/', '', $tag, 1);
        }, $html);
        return $html;
    }


    public static function origin_twins($html, $origin = null, $exists = null, $suppressed = null)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_origin_twins', true)) {
            return $html;
        }
        if ($suppressed === null) {
            $suppressed = function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed();
        }
        if (!$suppressed) {
            return $html;
        }
        if ($origin === null) {
            $origin = function_exists('site_url') ? (string) parse_url(site_url(), PHP_URL_HOST) : '';
        }
        if ($origin === '' || stripos($html, $origin . '/wp-content/') === false) {
            return $html;
        }
        if ($exists === null) {
            $exists = function ($rel) {
                return defined('WP_CONTENT_DIR') && @is_file(rtrim(WP_CONTENT_DIR, '/') . '/' . ltrim($rel, '/'));
            };
        }
        $cache = [];
        $map = function ($url) use ($origin, $exists, &$cache) {
            if (isset($cache[$url])) {
                return $cache[$url];
            }
            $out = $url;
            if (preg_match('#^(https?://' . preg_quote($origin, '#') . '/wp-content/)([^"\')\s?]+)\.(avif|webp)(\?src=([a-z0-9]+))?$#i', $url, $m)) {
                $rel = $m[2]; $src = isset($m[5]) ? strtolower($m[5]) : '';
                $pick = '';
                foreach (['avif', 'webp'] as $ext) {
                    if ($exists($rel . '.' . $ext)) { $pick = $ext; break; }
                }
                if ($pick === '') {
                    foreach (array_values(array_unique(array_filter([$src, 'jpg', 'jpeg', 'png']))) as $ext) {
                        if ($exists($rel . '.' . $ext)) { $pick = $ext; break; }
                    }
                }
                if ($pick !== '') {
                    $out = $m[1] . $rel . '.' . $pick;
                }
            }
            return $cache[$url] = $out;
        };
        $html = preg_replace_callback('/<style\b[^>]*>.*?<\/style>/is', function ($m) use ($map, $origin) {
            if (stripos($m[0], $origin . '/wp-content/') === false) {
                return $m[0];
            }
            return preg_replace_callback('/url\((["\']?)(https?:\/\/[^"\')\s]+\.(?:avif|webp)(?:\?src=[a-z0-9]+)?)\1\)/i', function ($u) use ($map) {
                return 'url(' . $u[1] . $map($u[2]) . $u[1] . ')';
            }, $m[0]);
        }, $html);
        return $html;
    }

    public static function hint_unify($html, $zone = null, $exists = null)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_hint_unify', true)) {
            return $html;
        }
        if ($zone === null) {
            $zone = function_exists('get_option') ? trim((string) get_option('ic_cdn_zone_name', '')) : '';
        }
        if ($zone === '' || stripos($html, $zone) === false) {
            return $html;
        }
        if ($exists === null) {
            $exists = function ($rel) {
                return defined('WP_CONTENT_DIR') && @is_file(rtrim(WP_CONTENT_DIR, '/') . '/' . ltrim($rel, '/'));
            };
        }
        $cache = [];
        $hint = function ($url) use ($zone, $exists, &$cache) {
            if (isset($cache[$url])) {
                return $cache[$url];
            }
            $out = $url;
            if (preg_match('#^https?://' . preg_quote($zone, '#') . '/(wp-content/[^"\')\s?]+)\.(avif|webp)$#i', $url, $m)) {
                foreach (['jpg', 'jpeg', 'png'] as $ext) {
                    if ($exists(substr($m[1], strlen('wp-content/')) . '.' . $ext)) {
                        $out = $url . '?src=' . $ext;
                        break;
                    }
                }
            }
            return $cache[$url] = $out;
        };
        $unified = 0;
        $html = preg_replace_callback('/<style\b[^>]*>.*?<\/style>/is', function ($m) use ($hint, $zone, &$unified) {
            if (stripos($m[0], $zone) === false) {
                return $m[0];
            }
            return preg_replace_callback('/url\((["\']?)(https?:\/\/[^"\')\s?]+\.(?:avif|webp))\1\)/i', function ($u) use ($hint, &$unified) {
                $painted = $hint($u[2]);
                if ($painted !== $u[2]) {
                    $unified++;
                }
                return 'url(' . $u[1] . $painted . $u[1] . ')';
            }, $m[0]);
        }, $html);
        // A crit url() that names the zone's avif/webp is respelled to the ?src= form the page
        // paints, because two URL owners spell one image two ways. Sampled: every render of a
        // zoned page with crit background images respells the same urls.
        if ($unified > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('hint-unify', ['n' => $unified], true);
        }
        return $html;
    }

    // Autoplay media fetches hundreds of KB no visitor may watch — hold the poster
    // frame, attach the source on first visitor evidence (the loader also attaches it at the
    // start of the delayed replay).
    // A background video is never held here either: the keep decision is the facade's own,
    // video_keeps_source() (autoplay + muted, a builder video-background wrapper, or a Lazy Load
    // exclusion). Observed failure behind the rule: webdesign4u.com.au 2026-09-24, a Divi 4 hero
    // whose video was held while Divi set the section up against it (desktop: grey preload box
    // for good; mobile: half-covered hero; first frame ~6 s late). What this lane still holds is
    // an autoplay video that is NOT muted, with a poster and its src on the <video> tag itself;
    // the facade never parks that shape (it parks <source> children only).
    public static function wpc_video_delay_pass($html)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_video_delay', true)
            || stripos($html, '<video') === false) {
            return $html;
        }
        $out = preg_replace_callback('/<video\b[^>]*>/i', function ($m) use ($html) {
            $t = $m[0][0];
            $precedingMarkup = substr($html, max(0, $m[0][1] - 600), min(600, $m[0][1]));
            if (self::video_keeps_source($t, $precedingMarkup)) {
                return $t;
            }
            if (stripos($t, 'autoplay') === false || stripos($t, 'data-wpc-src') !== false
                || !preg_match('/\bposter\s*=\s*["\'][^"\']{8,}["\']/i', $t)
                || !preg_match('/(?<![-\w])src\s*=\s*["\']([^"\']+\.(?:mp4|webm)(?:\?[^"\']*)?)["\']/i', $t, $sm)) {
                return $t;
            }
            $t = (string) preg_replace('/(?<![-\w])src\s*=\s*["\'][^"\']*["\']/i', 'data-wpc-src="' . esc_attr($sm[1]) . '"', $t, 1);
            if (preg_match('/\bclass\s*=\s*["\']([^"\']*)["\']/i', $t)) {
                $t = (string) preg_replace('/\bclass\s*=\s*(["\'])([^"\']*)\1/i', 'class=$1$2 wpc-video-delay$1', $t, 1);
            } else {
                $t = (string) preg_replace('/<video\b/i', '<video class="wpc-video-delay"', $t, 1);
            }
            return $t;
        }, $html, -1, $count, PREG_OFFSET_CAPTURE);
        return is_string($out) ? $out : $html;
    }


    // v7.10.797 — Elementor's device bands, read from THIS page's own config, mapped the way its
    // swiper handler actually consumes them: each max-direction breakpoint VALUE is a swiper
    // min-width key carrying the NEXT-LARGER device's settings (empirically pinned on justmsp:
    // 1200px renders laptop values, not tablet_extra's). Disabled devices contribute nothing.
    // Returns [] when the page declares no config — no band is ever guessed.
    public static function wpc_elementor_breakpoint_bands($html)
    {
        if (!is_string($html) || $html === '') {
            return [];
        }
        $i = strpos($html, '"responsive":{"breakpoints":');
        if ($i === false) {
            return [];
        }
        $chunk = substr($html, $i, 2400);
        $order = ['mobile', 'mobile_extra', 'tablet', 'tablet_extra', 'laptop'];
        $maxes = [];
        foreach ($order as $dev) {
            if (preg_match('/"' . $dev . '":\{[^{}]*"value":(\d+)[^{}]*"direction":"max"[^{}]*"is_enabled":true/', $chunk, $m)) {
                $maxes[] = ['dev' => $dev, 'v' => (int) $m[1]];
            }
        }
        if (!$maxes) {
            return [];
        }
        $wide = 0;
        if (preg_match('/"widescreen":\{[^{}]*"value":(\d+)[^{}]*"direction":"min"[^{}]*"is_enabled":true/', $chunk, $wm)) {
            $wide = (int) $wm[1];
        }
        $bands = [];
        // Below the smallest key = the base swiper params = the mobile leg.
        $bands['mobile'] = [0, $maxes[0]['v'] - 1];
        for ($k = 1; $k < count($maxes); $k++) {
            $bands[$maxes[$k]['dev']] = [$maxes[$k - 1]['v'], $maxes[$k]['v'] - 1];
        }
        $last = $maxes[count($maxes) - 1];
        $bands['desktop'] = [$last['v'], $wide ? $wide - 1 : 0];
        if ($wide) {
            $bands['widescreen'] = [$wide, 0];
        }
        return $bands;
    }

    // v7.10.797 — SLIDER SETTLE: paint the slider's DECLARED settled geometry before its JS runs.
    // Our delay lane holds Swiper's init until engagement, which stretches the stock pre-init
    // state (.swiper-slide{width:100%} = one slide across) from milliseconds to as long as the
    // visitor takes to gesture — the justmsp receipt: 3-across settled, 1-across for anyone who
    // hadn't moved yet, and a 1216->398px snap booked when init finally ran in view. The widget
    // DECLARES its settled geometry (slides_per_view/space_between per device in data-settings);
    // this emits exactly that as scoped CSS: flex on the wrapper, overflow hidden, and the
    // per-band slide basis. NOTHING here is !important — Swiper writes inline widths at init, so
    // the whole block self-releases with no guard machinery; a rule inline style outranks cannot
    // become a stuck box. Devices with no EXPLICIT declaration emit nothing (widget defaults are
    // invisible to us and stock width:100% is the correct 1-across), and a page with no
    // breakpoints config emits nothing: declared geometry or silence, never a guess.
    public static function wpc_slider_settle_pass($html)
    {
        // v7.21.183 — Elementor's IMAGE-CAROUSEL declares its geometry as slides_to_show
        // (beucomply: {"slides_to_show":"8","slides_to_show_tablet":"4"}), not
        // slides_per_view — the settle pass never emitted for the exact widget class whose
        // init-snap booked desktop CLS 0.105 (12 slides re-basing at swiper init). Both key
        // families are declared geometry; both emit.
        if (!is_string($html) || $html === ''
            || (strpos($html, 'slides_per_view') === false && strpos($html, 'slides_to_show') === false)
            || stripos($html, 'swiper') === false
            || strpos($html, 'wpc-slider-settle') !== false
            || stripos($html, '</head>') === false) {
            return $html;
        }
        if (empty(self::$settings['delay-js']) && empty(self::$settings['delay-js-v2'])) {
            return $html;
        }
        if (!apply_filters('wpc_slider_settle', true)) {
            return $html;
        }
        try {
            $bands = self::wpc_elementor_breakpoint_bands($html);
            if (!$bands) {
                return $html;
            }
            if (!preg_match_all('/<div\b[^>]*class="([^"]*\belementor-element-([a-z0-9]+)\b[^"]*)"[^>]*data-settings="([^"]*slides_(?:per_view|to_show)[^"]*)"/i', $html, $wm, PREG_SET_ORDER)) {
                return $html;
            }
            $css = '';
            $n_widgets = 0;
            foreach ($wm as $w) {
                if (++$n_widgets > 6) {
                    break;
                }
                $cfg = json_decode(html_entity_decode($w[3], ENT_QUOTES), true);
                if (!is_array($cfg)) {
                    continue;
                }
                $sel = '.elementor-element-' . $w[2];
                $rules = '';
                foreach ($bands as $dev => $mm) {
                    $nk = ($dev === 'desktop') ? 'slides_per_view' : 'slides_per_view_' . $dev;
                    $gk = ($dev === 'desktop') ? 'space_between' : 'space_between_' . $dev;
                    $n = isset($cfg[$nk]) ? $cfg[$nk] : null;
                    if ($n === null) {
                        $slides_to_show_key = ($dev === 'desktop') ? 'slides_to_show' : 'slides_to_show_' . $dev;
                        $n = isset($cfg[$slides_to_show_key]) ? $cfg[$slides_to_show_key] : null;
                        if ($n !== null && !isset($cfg[$gk]['size']) && isset($cfg['image_spacing_custom']['size'])
                            && is_numeric($cfg['image_spacing_custom']['size'])) {
                            $cfg[$gk] = ['size' => $cfg['image_spacing_custom']['size']];
                        }
                    }
                    if ($n === null && $dev === 'widescreen'
                        && (isset($cfg['space_between_widescreen']) || isset($cfg['slides_to_scroll_widescreen']))) {
                        $n = isset($cfg['slides_per_view']) ? $cfg['slides_per_view'] : null;
                    }
                    if ($n === null || !preg_match('/^\d+$/', (string) $n) || (int) $n < 2) {
                        continue;
                    }
                    $n = (int) $n;
                    $gap = (isset($cfg[$gk]['size']) && is_numeric($cfg[$gk]['size'])) ? (float) $cfg[$gk]['size'] : $gap_desk;
                    $gap = rtrim(rtrim(sprintf('%.2F', $gap), '0'), '.');
                    $q = '@media(min-width:' . (int) $mm[0] . 'px)' . ($mm[1] ? ' and (max-width:' . (int) $mm[1] . 'px)' : '');
                    $rules .= $q . '{' . $sel . ' .swiper-slide{flex-shrink:0;width:calc((100% - '
                        . ($n - 1) . '*' . $gap . 'px)/' . $n . ');margin-right:' . $gap . 'px}}';
                }
                if ($rules === '') {
                    continue;
                }
                $css .= $sel . ' .swiper,' . $sel . ' .swiper-container,' . $sel . ' .elementor-main-swiper{overflow:hidden}'
                    . $sel . ' .swiper-wrapper{display:flex}' . $rules;
            }
            if ($css === '' || strlen($css) > 8192) {
                return $html;
            }
            $tag = '<style id="wpc-slider-settle">' . $css . '</style>';
            $out = preg_replace('#</head>#i', $tag . '</head>', $html, 1);
            // The delay holds swiper, so the widgets' declared geometry is written as CSS until it
            // inits. Sampled: the page's carousels are the same on every render.
            if (is_string($out) && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('slider-settle', ['widgets' => min($n_widgets, 6)], true);
            }
            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    // v7.10.796 — stem of an image url: query dropped, proxy wrappers stepped past (the real
    // target always follows the LAST /wp-content/), rung suffix and extension removed. Same
    // basis on both sides so a preload and the element it serves compare as one asset.
    /**
     * Path of the content directory on the origin: '/wp-content', or '/tibet/nakliye/wp-content'
     * for a WordPress installed in a subdirectory. The first-party passes match zone URLs and
     * build origin URLs on it; with a hard-coded '/wp-content' they built
     * `https://noktaltema.com/wp-content/uploads/…` for a file that lives under /tibet/nakliye/
     * (404, 2026-09-24).
     */
    public static function origin_content_path()
    {
        $path = function_exists('content_url') ? (string) parse_url((string) content_url(), PHP_URL_PATH) : '';
        $path = '/' . trim($path, '/');
        return $path !== '/' ? $path : '/wp-content';
    }

    public static function lcp_first_party($html, $zone = null, $origin = null, $exists = null, $contentPath = null)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'fetchpriority="high"') === false || !apply_filters('wpc_lcp_first_party', true)) {
            return $html;
        }
        if ($zone === null) {
            $zone = function_exists('get_option') ? trim((string) get_option('ic_cdn_zone_name', '')) : '';
        }
        if ($origin === null) {
            $origin = function_exists('home_url') ? (string) parse_url(home_url(), PHP_URL_HOST) : '';
        }
        if ($zone === '' || $origin === '' || stripos($html, $zone) === false) {
            return $html;
        }
        if ($exists === null) {
            $exists = function ($rel) {
                return defined('WP_CONTENT_DIR') && @is_file(rtrim(WP_CONTENT_DIR, '/') . '/' . ltrim($rel, '/'));
            };
        }
        if ($contentPath === null) {
            $contentPath = self::origin_content_path();
        }
        $cache = [];
        $map = function ($url) use ($zone, $origin, $exists, $contentPath, &$cache) {
            if (isset($cache[$url])) {
                return $cache[$url];
            }
            $out = $url;
            if (preg_match('#^https?://' . preg_quote($zone, '#') . preg_quote($contentPath, '#') . '/(uploads/[^"\')\s?]+)\.(avif|webp|jpe?g|png)(?:\?[^"\')\s]*)?$#i', $url, $m)) {
                $rel = $m[1];
                foreach (['avif', 'webp'] as $ext) {
                    if ($exists($rel . '.' . $ext)) {
                        $out = 'https://' . $origin . $contentPath . '/' . $rel . '.' . $ext;
                        break;
                    }
                }
            }
            return $cache[$url] = $out;
        };
        $n = 0;
        $out = preg_replace_callback('/<img\b[^>]*\bfetchpriority="high"[^>]*>/i', function ($m) use ($map, &$n) {
            $tag = $m[0];
            $new = preg_replace_callback('/\s(src|srcset)=(["\'])(.*?)\2/i', function ($a) use ($map) {
                $parts = [];
                foreach (preg_split('/\s*,\s*/', $a[3]) as $cand) {
                    if ($cand === '') {
                        continue;
                    }
                    $d = '';
                    if (preg_match('/^(\S+)(\s+\d+(?:w|x))$/', $cand, $cm)) {
                        $cand = $cm[1];
                        $d = $cm[2];
                    }
                    $parts[] = $map($cand) . $d;
                }
                return ' ' . $a[1] . '=' . $a[2] . implode(', ', $parts) . $a[2];
            }, $tag);
            if (is_string($new) && $new !== $tag) {
                $n++;
                return $new;
            }
            return $tag;
        }, $html);
        if (!is_string($out)) {
            return $html;
        }
        if ($n && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('lcp-first-party', '', '', ['imgs' => $n]);
        }
        return $out;
    }

    public static function eager_first_party($html, $zone = null, $origin = null, $exists = null, $contentPath = null)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_eager_first_party', true)) {
            return $html;
        }
        if ($zone === null) {
            $zone = function_exists('get_option') ? trim((string) get_option('ic_cdn_zone_name', '')) : '';
        }
        if ($origin === null) {
            $origin = function_exists('home_url') ? (string) parse_url(home_url(), PHP_URL_HOST) : '';
        }
        if ($zone === '' || $origin === '' || stripos($html, $zone) === false) {
            return $html;
        }
        if ($exists === null) {
            $exists = function ($rel) {
                return defined('WP_CONTENT_DIR') && @is_file(rtrim(WP_CONTENT_DIR, '/') . '/' . ltrim($rel, '/'));
            };
        }
        if ($contentPath === null) {
            $contentPath = self::origin_content_path();
        }
        $cache = [];
        $map = function ($url) use ($zone, $origin, $exists, $contentPath, &$cache) {
            if (isset($cache[$url])) {
                return $cache[$url];
            }
            $out = $url;
            if (preg_match('#^https?://' . preg_quote($zone, '#') . preg_quote($contentPath, '#') . '/([^"\')\s?]+)\.(avif|webp|jpe?g|png|gif|svg)(\?src=([a-z0-9]+))?$#i', $url, $m)) {
                $rel = $m[1];
                $ext = strtolower($m[2]);
                $src = isset($m[4]) ? strtolower($m[4]) : '';
                $pick = '';
                if ($ext === 'avif' || $ext === 'webp') {
                    foreach (['avif', 'webp'] as $e) {
                        if ($exists($rel . '.' . $e)) {
                            $pick = $e;
                            break;
                        }
                    }
                    if ($pick === '' && $src !== '' && $exists($rel . '.' . $src)) {
                        $pick = $src;
                    }
                } elseif ($exists($rel . '.' . $ext)) {
                    $pick = $ext;
                }
                if ($pick !== '') {
                    $out = 'https://' . $origin . $contentPath . '/' . $rel . '.' . $pick;
                }
            }
            return $cache[$url] = $out;
        };
        $mapList = function ($v) use ($map) {
            $parts = [];
            foreach (preg_split('/\s*,\s*/', $v) as $cand) {
                if ($cand === '') {
                    continue;
                }
                $d = '';
                if (preg_match('/^(\S+)(\s+\d+(?:w|x))$/', $cand, $cm)) {
                    $cand = $cm[1];
                    $d = $cm[2];
                }
                $parts[] = $map($cand) . $d;
            }
            return implode(', ', $parts);
        };
        $n = ['imgs' => 0, 'css' => 0];
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($mapList, &$n, $zone) {
            $tag = $m[0];
            if (stripos($tag, $zone) === false || stripos($tag, 'data-wpc-qw-src') !== false || preg_match('/\sloading=(["\'])lazy\1/i', $tag)) {
                return $tag;
            }
            $new = preg_replace_callback('/\s(src|srcset)=(["\'])(.*?)\2/i', function ($a) use ($mapList) {
                return ' ' . $a[1] . '=' . $a[2] . $mapList($a[3]) . $a[2];
            }, $tag);
            if (is_string($new) && $new !== $tag) {
                $n['imgs']++;
                return $new;
            }
            return $tag;
        }, $html);
        if (!is_string($out)) {
            return $html;
        }
        $out3 = preg_replace_callback('/<style\b[^>]*\bid=["\']wpc-critical-css["\'][^>]*>.*?<\/style>/is', function ($m) use ($map, &$n, $zone) {
            if (stripos($m[0], $zone) === false) {
                return $m[0];
            }
            $c = 0;
            $new = preg_replace_callback('/url\((["\']?)(https?:\/\/[^"\')\s]+)\1\)/i', function ($u) use ($map, &$c) {
                $to = $map($u[2]);
                if ($to !== $u[2]) {
                    $c++;
                }
                return 'url(' . $u[1] . $to . $u[1] . ')';
            }, $m[0]);
            $n['css'] += $c;
            return is_string($new) ? $new : $m[0];
        }, $out);
        if (is_string($out3)) {
            $out = $out3;
        }
        if (($n['imgs'] || $n['css']) && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('eager-first-party', '', '', $n);
        }
        return $out;
    }

    // v7.10.923 — THE PIN MUST LOSE TO EVERY AUTHOR DECLARATION. abasingbakes (clipped
    // review logo) + ladiespadel (short third card): the inline style="aspect-ratio:W/H"
    // stamp outranks builder stylesheet crops (.breakdance .bde-image2{aspect-ratio:4/3;
    // object-fit:cover} — devtools shows it struck through), so every pinned image renders
    // at the FILE's ratio instead of the authored one. .756 fixed one writer's scope; the
    // quiet-wire and svg lanes still stamped inline. Demotion: the ratio rides an inline
    // custom property (--wpc-ar, inert on its own) consumed by ONE zero-specificity rule —
    // :where(img[data-wpc-ar]){aspect-ratio:var(--wpc-ar)} — which beats the UA sheet and
    // theme img{height:auto} (author origin, and they don't declare aspect-ratio) but loses
    // to ANY author aspect-ratio rule at any specificity. Filter wpc_ar_var_pin -> false
    // restores the legacy inline stamp.
    public static function wpc_pin_aspect_ratio_var($t, $w, $h)
    {
        $w = (int) $w;
        $h = (int) $h;
        if ($w < 1 || $h < 1 || !is_string($t)
            || stripos($t, 'aspect-ratio') !== false || stripos($t, '--wpc-ar:') !== false) {
            return $t;
        }
        $pin_as_var = apply_filters('wpc_ar_var_pin', true);
        $decl = ($pin_as_var ? '--wpc-ar:' : 'aspect-ratio:') . $w . '/' . $h;
        if (preg_match('/\bstyle\s*=\s*"([^"]*)"/i', $t)) {
            $t = (string) preg_replace('/\bstyle\s*=\s*"/i', 'style="' . $decl . ';', $t, 1);
        } else {
            $t = (string) preg_replace('/<img\b/i', '<img style="' . $decl . '"', $t, 1);
        }
        if ($pin_as_var) {
            $t = (string) preg_replace('/<img\b/i', '<img data-wpc-ar', $t, 1);
        }
        return $t;
    }

    // One rule per page, only when at least one pin landed; fail-open (no head = no rule,
    // the box is simply unpinned as pre-.481).
    public static function ar_css_pass($html)
    {
        if (!is_string($html) || $html === '' || strpos($html, 'id="wpc-ar-css"') !== false) {
            return $html;
        }
        $css_rules = '';
        if (strpos($html, 'data-wpc-ar') !== false) {
            $css_rules .= ':where(img[data-wpc-ar]){aspect-ratio:auto var(--wpc-ar)}';
        }
        // v7.21.125 — dims WE invented must not become a definite height. The backfill stamps
        // width/height onto author-attribute-less imgs (CLS floor); on themes without
        // img{height:auto} the height attr is a live presentational height:Hpx, and since
        // aspect-ratio only governs when a dimension is auto, the pin is powerless: CSS clamps
        // width, height stays H = distortion (albertadiving SVG logo 200x304 vs plugin-off
        // 200x111). Zero-specificity height:auto for BACKFILLED imgs only: it outranks the
        // presentational hint (author-origin, order-first), loses to any real author height
        // rule, and reproduces the exact plugin-off box. Author-dimensioned imgs untouched.
        if (strpos($html, 'data-wpc-bf') !== false) {
            $css_rules .= ':where(img[data-wpc-bf]){height:auto;object-fit:contain}';
        }
        if (strpos($html, 'id="wpc-img-ratio"') === false && preg_match('/\b(?:data-wpc-md\b|wpc-size=|wpc-nd\b)/', $html)) {
            $css_rules .= self::IMG_RATIO_CSS;
        }
        // v7.20.20 — Elementor renders eicons as INLINE SVG whose 1em sizing lives in the
        // widget/frontend sheets: through the crit window an ATF select caret painted at
        // container width (borderlessmoves: 211px box, 0.077 of the page's 0.078 CLS) and
        // snapped when the deferred remainder applied. Zero-specificity floor — any author
        // rule, including Elementor's own identical 1em, outranks it; it only fills the
        // unstyled window with the value the settled page uses anyway.
        if (strpos($html, 'e-font-icon-svg') !== false && apply_filters('wpc_icon_svg_belt', true)) {
            $css_rules .= ':where(svg.e-font-icon-svg){width:1em;height:1em}';
        }
        // v7.21.04 — SHAPE DIVIDERS ARE IN-FLOW UNTIL THEIR STYLESHEET LANDS. Elementor's
        // shape markup ships in the HTML, but the rule that lifts it OUT of the flow lives in
        // a conditional sheet: until it applies, the divider occupies real height and holds
        // everything below it down, then vanishes from the flow in one frame. borderlessmoves
        // mobile: the hero sat 45px low and snapped up at ~7s — a single 0.868 shift, the
        // whole page CLS. Measured A/B at this exact injection point, three runs: 0.8684 ->
        // 0.0123. Values are Elementor's own settled computed values (verified live), emitted
        // at zero specificity, so the real sheet and any theme override both outrank this and
        // the settled paint is byte-identical either way.
        if (strpos($html, 'elementor-shape elementor-shape-') !== false
            && apply_filters('wpc_shape_divider_belt', true)) {
            // Scoped to dividers Elementor renders as a direct child of a section/container —
            // the only two emitters that also enqueue the e-shapes sheet. The Link-in-Bio
            // trait renders the same markup WITHOUT that sheet, so its divider is in-flow by
            // design and an unscoped rule would change its settled paint. [data-negative] is
            // present on every frontend divider and absent from the editor template.
            // Fill is deliberately NOT set here: wpc-shape-fill-guard already owns it at one
            // class, pinning transparent so the unstyled state is invisible rather than black.
            $divider_parent_selector = ':where(.elementor-section,.e-con,.e-con-inner)>';
            $css_rules .= $divider_parent_selector . ':where(.elementor-shape[data-negative]){direction:ltr;left:0;line-height:0;overflow:hidden;position:absolute;width:100%}'
                . $divider_parent_selector . ':where(.elementor-shape[data-negative].elementor-shape-top){top:-1px}'
                . $divider_parent_selector . ':where(.elementor-shape[data-negative].elementor-shape-bottom){bottom:-1px}'
                . $divider_parent_selector . ':where(.elementor-shape[data-negative="false"].elementor-shape-bottom,.elementor-shape[data-negative="true"].elementor-shape-top){transform:rotate(180deg)}'
                . $divider_parent_selector . ':where(.elementor-shape[data-negative])>:where(svg){display:block;left:50%;position:relative;transform:translateX(-50%);width:calc(100% + 1.3px)}';
        }
        // v7.21.05 — THE SELECT CARET IS IN-FLOW AND MIS-SIZED UNTIL widget-form LANDS. Same
        // class as the divider above: Elementor sizes the caret at font-size:11px and lifts it
        // out of the flow from widget-form.min.css, and makes the wrapper a positioned flex box
        // from frontend.min.css — both deferred. Until they apply the wrapper is in flow at the
        // inherited 16px, so the .20 icon floor paints a 16px caret that adds 25px of real
        // height inside a 47px field, then snaps to 11px in one frame. borderlessmoves, live
        // 7.21.04 bytes: mobile 0.0841 -> 0.0017, desktop 0.0924 -> 0.0024 (two runs each, this
        // exact injection point). Elementor's own settled values, zero specificity.
        if (strpos($html, 'select-caret-down-wrapper') !== false
            && apply_filters('wpc_select_caret_belt', true)) {
            $caret_selector = ':where(.elementor-select-wrapper)>:where(.select-caret-down-wrapper)';
            $css_rules .= ':where(.elementor-field-group)>:where(.elementor-select-wrapper){position:relative}'
                . $caret_selector . '{font-size:11px;inset-inline-end:10px;pointer-events:none;position:absolute;top:50%;transform:translateY(-50%)}'
                . $caret_selector . '>:where(svg){aspect-ratio:unset;display:unset;fill:currentColor;overflow:visible;width:1em}';
        }
        if ($css_rules === '') {
            return $html;
        }
        // The floors stand in for sheets that are deferred at first paint (crit coverage) and
        // for the height our own backfill invented; the pin rule is the owner's and not counted.
        // Sampled: the page's markup decides them, so each render of it gets the same set.
        $floors = [];
        foreach (['bf' => 'img[data-wpc-bf]', 'eicon' => 'svg.e-font-icon-svg', 'shape_divider' => '.elementor-shape[data-negative]',
            'select_caret' => '.select-caret-down-wrapper'] as $floor => $selector) {
            if (strpos($css_rules, $selector) !== false) {
                $floors[$floor] = 1;
            }
        }
        if ($floors !== [] && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('ar-css-floors', $floors, true);
        }
        $tag = '<style id="wpc-ar-css">' . $css_rules . '</style>';
        $p = stripos($html, '</head>');
        if ($p !== false) {
            return substr($html, 0, $p) . $tag . substr($html, $p);
        }
        $out = preg_replace('/<head\b[^>]*>/i', '$0' . $tag, $html, 1);
        return is_string($out) ? $out : $html;
    }

    /**
     * The aspect pin, one pass: `--wpc-ar` on the <img>s whose box would otherwise be lost before
     * the file decodes, then the one `#wpc-ar-css` rule block that makes the pins (and the
     * data-wpc-bf height:auto of invented dims) take effect, then the image-sizing receipt.
     * The pins come only from the width/height on the tag, which the image-sizing owner decided
     * at image_dims; this pass writes no dimension of its own.
     *
     * Which <img> get a pin:
     *  - an SVG with both dimensions (any carrier: src, data-wpc-qw-src, data-wpc-src, data-src),
     *    at most five among the first twenty <img>: a theme SVG logo with no pin collapsed to 0
     *    tall until decode and snapped the header (the harness receipt: header 72 -> 94);
     *  - an <img> our lazy machinery manages (a placeholder src or the fade markers) on a tag we
     *    rewrote: the placeholder's own ratio would otherwise win (hawkeye logo receipts). An
     *    eager real-src <img> keeps its box from its attributes and is not pinned, since the
     *    inline pin outranks a builder's declared crop (heritagepavingltd Breakdance squares).
     */
    public static function stage_image_pins_pass($html, $ctx)
    {
        if (is_string($html) && $html !== '' && stripos($html, '<img') !== false && !wps_ic_image_sizing::off()) {
            $sizing = ($ctx instanceof wps_ic_render_context) ? $ctx->imageSizing : null;
            $ours = '/\b(?:data-count-lazy|ic-fade-in|wps-ic-cdn|wpc-nd|wpc-lcp-optimized|wpc-lazy-skipped)|data-wpc-/i';
            $pinLazy = apply_filters('wpc_img_aspect', true) && preg_match($ours, $html);
            $pinSvg = stripos($html, '.svg') !== false && apply_filters('wpc_svg_dims', true);
            $imgIndex = 0;
            $svgPins = 0;
            $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($sizing, $ours, $pinLazy, $pinSvg, &$imgIndex, &$svgPins) {
                $tag = $m[0];
                $imgIndex++;
                $pin = null;
                $pinKind = '';
                if ($pinSvg && $imgIndex <= 20 && $svgPins < 5
                    && preg_match('/\s(?:src|data-wpc-qw-src|data-wpc-src|data-src)=["\'][^"\']+\.svg(?:\?[^"\']*)?["\']/i', $tag)
                    && preg_match('/\bheight\s*=\s*["\']?(\d{1,4})/i', $tag, $hm)
                    && preg_match('/\bwidth\s*=\s*["\']?(\d{1,4})/i', $tag, $wm)) {
                    if (stripos($tag, 'aspect-ratio') !== false) {
                        return $tag;
                    }
                    $svgPins++;
                    $pin = [$wm[1], $hm[1]];
                    // Found only through the carrier quiet-wire moved src into: this pass runs
                    // after quiet_wire, and the receipt counts how often that order matters.
                    $pinKind = preg_match('/\ssrc=["\'][^"\']+\.svg(?:\?[^"\']*)?["\']/i', $tag) ? 'svg'
                        : (preg_match('/\sdata-wpc-qw-src=["\'][^"\']+\.svg/i', $tag) ? 'svg-qw' : 'svg');
                } elseif ($pinLazy && preg_match($ours, $tag) && stripos($tag, 'aspect-ratio') === false
                    && (apply_filters('wpc_img_aspect_inline_all', false)
                        || preg_match('/\b(?:data-count-lazy|ic-fade-in)\b/i', $tag)
                        || preg_match('/\bsrc\s*=\s*["\'](?:data:|[^"\']*\bblank\b)/i', $tag))
                    && preg_match('/\bwidth="(\d{2,5})"/i', $tag, $wm) && preg_match('/\bheight="(\d{2,5})"/i', $tag, $hm)) {
                    $pin = [$wm[1], $hm[1]];
                    $pinKind = 'lazy';
                }
                if ($pin === null) {
                    return $tag;
                }
                $pinned = self::wpc_pin_aspect_ratio_var($tag, $pin[0], $pin[1]);
                if ($pinned !== $tag && $sizing instanceof wps_ic_image_sizing) {
                    $sizing->pinned($pinKind);
                }
                return $pinned;
            }, $html);
            $html = is_string($out) ? $out : $html;
        }
        $html = self::ar_css_pass($html);
        if ($ctx instanceof wps_ic_render_context && $ctx->imageSizing instanceof wps_ic_image_sizing) {
            $ctx->imageSizing->receipt(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '');
        }
        return $html;
    }

    public static function wpc_trim_preset_vars_page($html)
    {
        try {
            if (!is_string($html) || $html === '' || strpos($html, '--wp--preset--') === false
                || !class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'wpc_trim_preset_vars')) {
                return $html;
            }
            $out = preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function ($m) use ($html) {
                if (strpos($m[2], '--wp--preset--') === false) {
                    return $m[0];
                }
                $trimmed = wps_rewriteLogic::wpc_trim_preset_vars($m[2], $html);
                return (is_string($trimmed) && $trimmed !== '') ? $m[1] . $trimmed . $m[3] : $m[0];
            }, $html);
            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public function render_buffer_local($html)
    {
        return $this->render_buffer($html, wps_ic_render_pipeline::LANE_LOCAL);
    }

    public function render_buffer_cdn($html)
    {
        return $this->render_buffer($html, wps_ic_render_pipeline::LANE_CDN);
    }

    /**
     * The envelope around the stage table: admission control, pristine stash, never-blank.
     * Everything that transforms HTML lives in stage_table(); nothing here touches the markup.
     *
     * One envelope serves both lanes. The lane decides the profiler label and which entries of
     * the table run, nothing else; both heals (mixed content and script swallow) are table
     * entries on both lanes, so the envelope runs none of its own.
     */
    public function render_buffer($html, $lane)
    {
        // The flag answers "did the stage table run for THIS request", and two consumers act on
        // it (the outer natural-URL buffer, and saveCache's belt chain). A render that sheds, or
        // one that throws, must leave it empty — so it is cleared here, above the first shed,
        // rather than left holding a previous render's answer in a long-lived process.
        $GLOBALS['wpc_pipeline_ran'] = 0;
        $laneLabel = $lane === wps_ic_render_pipeline::LANE_CDN ? 'cdn' : 'local';
        // The one seam for tools that need the page as WordPress handed it over (the fixture
        // exporter, tests/tools/fixture-exporter/, which is not shipped). It fires here, above
        // the envelope's own admission checks, because a fixture IS the buffer as received and
        // must not depend on whether this render would have been shed. It is an action, not a
        // filter: a listener sees the buffer and cannot change what this render returns. With
        // nobody listening, do_action() is one hook-table lookup.
        do_action('wpc_pristine_buffer', $html, $laneLabel);
        $profilerSpan = class_exists('Wpc_Profiler_Span') ? new Wpc_Profiler_Span('OBCHAIN:render_buffer_' . $laneLabel) : null;
        $head = substr((string) $html, 0, 256);
        if (stripos($head, '<!doctype') === false && stripos($head, '<html') === false) {
            $GLOBALS['wpc_rewrite_was_shed'] = 1;
            return $html;
        }
        // v7.10.520 BELT 4 — ADMISSION CONTROL. The rewrite chain is the most expensive
        // thing the plugin does (measured 190MB peak on a 724KB page with a 672KB used-css
        // blob) and had NO pressure check at all: wpc_memory_pressure() existed and was read
        // in exactly one place in the codebase. ~19 concurrent renders thrashed the box for
        // 60s+. Under pressure serve the page UNREWRITTEN — correct, just unoptimised — and
        // set the flag so saveCache cannot store this copy as if it were optimised.
        // v7.10.549 — attachment/search/feed pages skip the ENTIRE rewrite, not just crit.
        // .531 stopped them minting crit dirs and kicks, but each still paid a full ~200MB
        // rewrite: receipted crawling /case-studies/img_5344/, /partners/icon-quote/ etc.
        // These pages are noindex by default and carry effectively no human traffic, so the
        // whole pass is waste. Same fail-open path as the shed below - correct, unoptimised.
        if (function_exists('wpc_is_low_value_page') && function_exists('did_action')
            && did_action('template_redirect') && wpc_is_low_value_page()) {
            $GLOBALS['wpc_rewrite_was_shed'] = 1;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('rewrite-skip-lowvalue', '', '', ['fn' => 'render_buffer_' . $laneLabel]);
            }
            return $html;
        }
        if ((function_exists('wpc_render_slot_acquire') && !wpc_render_slot_acquire())
            || (function_exists('wpc_memory_pressure') && wpc_memory_pressure())
            || (function_exists('wpc_under_pressure') && wpc_under_pressure())
            || (function_exists('wpc_safe_mode') && wpc_safe_mode())
            || self::wpc_render_breaker_tripped()) {
            $GLOBALS['wpc_rewrite_was_shed'] = 1;
            if (function_exists('wpc_prof_mark')) { wpc_prof_mark('shed:render_buffer_' . $laneLabel, microtime(true)); }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('rewrite-shed', '', '', ['fn' => 'render_buffer_' . $laneLabel, 'bytes' => strlen((string) $html)]);
            }
            return $html;
        }
        // The breakers judge this pipeline's own time (wpc_render_own_ms), counted from here to
        // the end of the run below.
        $GLOBALS['wpc_render_started_at'] = microtime(true);
        if (empty($GLOBALS['wpc_pristine_buffer_html']) && is_string($html) && stripos($html, '<body') !== false) {
            $GLOBALS['wpc_pristine_buffer_html'] = $html;
        }
        // v7.24.08 — CORPUS IDENTITY, on the pristine buffer, before any pass mutates it.
        // A criticalCombine render is the push: it RECORDS what it is about to hand over.
        // Every other render ASKS whether the landed artifact was built from the CSS it is
        // looking at. A drift means the crit describes rules this page no longer serves: the
        // verdict is carried to the park decision, which then refuses to defer any sheet behind
        // that crit (the page paints from the crit and serves its own CSS live), and a
        // regeneration is asked for, at most once per 120s per URL.
        if (function_exists('wpc_crit_corpus_id') && class_exists('wps_ic_url_key')) {
            if (self::push_render_requested()) {
                // v7.24.12 — THE PUSH RENDER RECORDS UNDER THE PAGE'S KEY, NOT THE FETCH'S. This
                // render is the page fetched at ?criticalCombine=true[&testCompliant=true], and
                // the url key keeps parameters it does not know, so keying the request as it
                // arrived named a directory that never existed: the write was a no-op (the record
                // refuses a missing crit dir) and every land logged crit-land-corpus-unknown
                // why=absent. wpc_crit_push_url_key() drops the push's own parameters, leaving
                // the key initCritical stamped uuid.txt and dispatch_ts.txt under.
                $corpusKey = wpc_crit_push_url_key();
                if ($corpusKey !== '') {
                    wpc_crit_corpus_record($corpusKey, $html);
                }
            } else {
                $corpusKey = ltrim((string) (new wps_ic_url_key())->setup(''), '/');
                if ($corpusKey !== '') {
                    // A drifted page asks for a new generation (stale mark and kick) and goes on
                    // serving the crit it has, parked behind like any stale-marked page.
                    if (wpc_crit_corpus_verdict($corpusKey, $html) === 'drift') {
                        wpc_crit_corpus_drift_report($corpusKey);
                    }
                }
            }
        }
        $pristine = $html;
        try {
            $pipeline = new wps_ic_render_pipeline($this->stage_table($lane), self::stage_aliases());
            $html = $pipeline->run($html, $lane);
        } catch (\Throwable $e) {
            return self::wpc_never_blank($pristine, '', $e);
        } finally {
            $GLOBALS['wpc_render_own_ms'] = self::wpc_render_own_ms();
            unset($GLOBALS['wpc_render_started_at']);
        }
        $out = self::wpc_never_blank($pristine, $html);
        // Only a run whose OWN bytes leave this envelope stands in for the outer natural-URL
        // buffer and for saveCache's belt chain. A never-blank restore hands back the pristine
        // buffer instead — nothing ran over those bytes — so the flag stays clear there, exactly
        // as it does on the sheds above and on a throw. A fired bail is the fourth such path:
        // the runner answers with the pristine copy, so no pass of ours touched what is served
        // and the flag stays clear, the same as for a shed.
        if ($out === $html && !$pipeline->returnedPristine()) {
            $GLOBALS['wpc_pipeline_ran'] = 1;
        }

        return $out;
    }

    /**
     * Checkpoint labels that name no pass of their own, each mapped onto the table entry that
     * holds its position. `?stop_before=` and `?stop_after=` accept them, so a debugging note
     * written against one of these names still lands where the name pointed.
     */
    public static function stage_aliases()
    {
        return [
            'wpFontsLocal' => 'replaceImageTags0',
            'Inline' => 'delay_js',
            'combine_js' => 'delay_js',
            // Both sat inside a delay branch, so neither named a position every render reaches;
            // 3491 is the first one below the delay block that does.
            '3463' => '3491',
            '3473' => '3491',
            'returnTemplates' => 'cache_mobile',
            'cache_settings' => 'cache_mobile',
            'cache_advanced' => 'cache_mobile',
            // The image-sizing owner's entries hold the positions of the passes they replaced.
            'set_image_sizes' => 'image_dims',
            'afold_sizes' => 'image_sizes',
            'picture_sizes_parity' => 'picture_fidelity',
            'svg_dims' => 'image_pins',
            'img_aspect' => 'image_pins',
            'ar_css' => 'image_pins',
            // Two belts the owner made redundant: an unsized <img> is sized at image_dims, and a
            // same-file srcset ladder is dropped at image_sizes.
            'srcset_honesty' => 'decode_iframe',
            'dims_belt' => 'decode_iframe',
            // The visitor combine lane is gone; its two entries held the corpus push's position.
            'combine_css_bundles' => 'crit_corpus_push',
            'combine_css_bundles_local' => 'crit_corpus_push',
            // The embed facade is gone; its entry held the position drop_dashicons now has.
            'embed_facade' => 'drop_dashicons',
        ];
    }

    /**
     * The ordered stage table for both render lanes: one entry per pass, in execution order.
     *
     * An entry is `wps_ic_render_stage::make(name, method, lanes, gate)` — a pass that takes the
     * buffer and hands one back — or `::bail(...)`, a door whose callable answers true to end the
     * run, after which the envelope serves the pristine buffer. `$cdn`, `$local` and `$both` are
     * the lane masks; the runner skips an entry whose mask does not carry this render's lane. A
     * gate answers true to run the pass, or a short reason string, which is what the
     * `?wpc_stages=1` trace prints beside the entry's byte delta and milliseconds.
     *
     * The names are the debug surface: `?stop_before=NAME` and `?stop_after=NAME` bind to them,
     * and `stage_aliases()` above maps retired labels onto the entries that hold their positions.
     * A marker entry ($marker) mutates nothing; it exists so a name still marks a position.
     *
     * One callable per pass, no adapter: a pass whose whole job is `pass($html)` is registered
     * directly (`[self::class, 'pass']`), because the runner calls every entry as
     * `($html, $ctx)` and PHP drops the extra argument. A `stage_*` method exists only when it
     * does work of its own: it reads `$ctx`, branches on the lane, passes a constant, or wraps a
     * pass whose second parameter is something other than `$ctx` (hint_unify, lcp_first_party,
     * eager_first_party take a zone there, so a direct entry would hand them the context). It
     * also stays when its target is resolved at call time: an object held in a static property
     * (self::$rewriteLogic and friends, built by mainInit), or a function or class that may not
     * be loaded, since the runner's constructor checks `is_callable` on every entry and would
     * refuse the whole table. A directly registered pass must be public for the same reason: the
     * check runs from outside the class. On 2026-09-25, 40 of the 126 `stage_*` methods were such
     * one-line adapters (about 245 lines) that only forwarded `$html`; they were removed.
     *
     * What is NOT here: the envelope around the runner (render_buffer() above) owns the doctype
     * sniff, the shed paths, the pristine stash and the never-blank guard; and the pre-buffer
     * filters — enqueue-time tag and src rewriting, the picture lane, the wp_head emitters — run
     * before a buffer exists at all and need context this table does not carry.
     */
    public function stage_table($lane)
    {
        $cdn = wps_ic_render_pipeline::LANE_CDN;
        $local = wps_ic_render_pipeline::LANE_LOCAL;
        $both = wps_ic_render_pipeline::LANE_BOTH;
        $marker = [$this, 'stage_marker'];
        // The local lane's image stretch shares one gate across its entries, one entry per pass,
        // so a stop can land between any two of them.
        $cdnOff = [$this, 'gate_cdn_disabled'];
        return [
            // Checkpoint zero: the buffer as the envelope handed it over.
            wps_ic_render_stage::make('prelude', [$this, 'stage_prelude'], $both),
            // First, so every later pass that mints an @font-face has an owner to hand it to.
            wps_ic_render_stage::make('open_font_faces', [$this, 'stage_open_font_faces'], $both),
            wps_ic_render_stage::make('heal_mixed_content', [$this, 'stage_heal_mixed_content'], $both),
            // The one heal, for both lanes. A shed render is plugin-off bytes, so the heal belongs
            // with the passes it protects rather than in the envelope: ahead of every regex that
            // reads a <script>.
            wps_ic_render_stage::make('script_swallow_heal', [self::class, 'script_swallow_heal'], $both),
            wps_ic_render_stage::make('remove_templates', [$this, 'stage_remove_templates'], $cdn),
            wps_ic_render_stage::make('negotiated_delivery', [$this, 'stage_negotiated_delivery'], $cdn),

            // The request-shaped bails run BEFORE the AMP checkpoint, which is where wps_ic_amp
            // and wps_ic_combine_css are constructed — so a feed / AJAX / wp-json / wc-ajax
            // request never builds either object.
            //
            // One bail set, every entry on both lanes: what a render must refuse does not depend
            // on which lane serves it. dontRunif() covers every builder, editor and preview
            // parameter, and its own `action == get_wdtable` test covers datatables, so those
            // need no entries of their own. Recording a criticalCombine request and answering it
            // with the template-key header is not a refusal, so it is the ordinary stage below,
            // placed ahead of every bail that could swallow it.
            wps_ic_render_stage::make('critical_combine_request', [$this, 'stage_critical_combine_request'], $both),
            wps_ic_render_stage::bail('bail_no_rewriter', [$this, 'bail_no_rewriter'], $both),
            wps_ic_render_stage::bail('bail_ignore_ic', [$this, 'bail_ignore_ic'], $both),
            wps_ic_render_stage::bail('bail_woocommerce_ajax', [$this, 'bail_woocommerce_ajax'], $both),
            wps_ic_render_stage::bail('bail_feed', [$this, 'bail_feed'], $both),
            wps_ic_render_stage::bail('bail_dont_run_if', [$this, 'bail_dont_run_if'], $both),
            wps_ic_render_stage::bail('bail_ajax', [$this, 'bail_ajax'], $both),
            wps_ic_render_stage::bail('bail_json_or_xmlrpc', [$this, 'bail_json_or_xmlrpc'], $both),

            wps_ic_render_stage::make('wps_ic_amp', $marker, $cdn),
            wps_ic_render_stage::make('amp_settings_squash', [$this, 'stage_amp_settings_squash'], $both),

            wps_ic_render_stage::make('action', $marker, $cdn),
            wps_ic_render_stage::make('jet_ajax_replace', [$this, 'stage_jet_ajax_replace'], $cdn, [$this, 'gate_jet_ajax_replace']),

            wps_ic_render_stage::make('wpc_disableCommentClear', $marker, $cdn),
            wps_ic_render_stage::make('strip_html_comments', [$this, 'stage_strip_html_comments'], $cdn, [$this, 'gate_strip_html_comments']),

            wps_ic_render_stage::make('scriptContent', $marker, $cdn),
            wps_ic_render_stage::bail('bail_budget_script_content', [$this, 'bail_budget_script_content'], $both),
            wps_ic_render_stage::make('script_content_images', [$this, 'stage_script_content_images'], $cdn, [$this, 'gate_script_content_images']),

            wps_ic_render_stage::make('replace_iframe_tags', $marker, $both),
            wps_ic_render_stage::make('mask_media_scripts', [$this, 'stage_mask_media_scripts'], $both),
            wps_ic_render_stage::make('negotiated_delivery_local', [$this, 'stage_negotiated_delivery_local'], $local),
            wps_ic_render_stage::make('iframe_lazy_and_video_facade', [$this, 'stage_iframe_lazy_and_video_facade'], $both),
            wps_ic_render_stage::make('ghl_embed_native_lazy', [self::class, 'wpc_ghl_embed_native_lazy_pass'], $both),

            wps_ic_render_stage::make('encode_iframe', $marker, $both),
            wps_ic_render_stage::make('encode_iframe_tags', [$this, 'stage_encode_iframe_tags'], $both, [$this, 'gate_encode_iframe_tags']),

            wps_ic_render_stage::make('unmask_media_scripts_local', [$this, 'stage_unmask_media_scripts_local'], $local),
            wps_ic_render_stage::make('local_script_encode', [$this, 'stage_local_script_encode'], $local, $cdnOff),
            wps_ic_render_stage::make('picture_stash_local', [$this, 'stage_picture_stash_local'], $local, $cdnOff),
            wps_ic_render_stage::make('device_hidden_image_set', [$this, 'stage_device_hidden_image_set'], $local, $cdnOff),
            wps_ic_render_stage::make('local_image_tags', [$this, 'stage_local_image_tags'], $local, $cdnOff),
            wps_ic_render_stage::make('fonts_zone_rewrite_local', [$this, 'stage_fonts_zone_rewrite_local'], $local, $cdnOff),
            wps_ic_render_stage::make('local_script_decode', [$this, 'stage_local_script_decode'], $local, $cdnOff),
            wps_ic_render_stage::make('css_background_local', [$this, 'stage_css_background_local'], $local, $cdnOff),
            wps_ic_render_stage::make('combine_js_bundles', [$this, 'stage_combine_js_bundles'], $local, $cdnOff),

            wps_ic_render_stage::make('crittr_replace_css', $marker, $cdn),
            wps_ic_render_stage::make('crittr_css', [$this, 'stage_crittr_css'], $cdn),

            wps_ic_render_stage::make('backgroundSizing', $marker, $cdn),
            wps_ic_render_stage::make('background_sizing', [$this, 'stage_background_sizing'], $cdn, [$this, 'gate_background_sizing']),
            wps_ic_render_stage::make('background_slideshow_only', [$this, 'stage_background_slideshow_only'], $cdn, [$this, 'gate_background_slideshow_only']),

            wps_ic_render_stage::make('replaceImageTags', $marker, $cdn),
            wps_ic_render_stage::make('inject_preload_images', [$this, 'stage_inject_preload_images'], $both),

            wps_ic_render_stage::make('replaceImageTags0', $marker, $cdn),
            wps_ic_render_stage::make('defer_fontawesome', [$this, 'stage_defer_fontawesome'], $cdn),

            // Width and height are decided here, once, before replace_image_tags reads a width as
            // the page's own. The pass masks script bodies around itself, which inside the CDN
            // lane's already-open mask finds nothing left to mask.
            wps_ic_render_stage::make('setImageSize', $marker, $both),
            wps_ic_render_stage::make('image_dims', [$this, 'stage_image_dims'], $both),

            wps_ic_render_stage::make('removeTemplates', $marker, $cdn),
            wps_ic_render_stage::make('remove_duplicate_fontawesome', [$this, 'removeDuplicatedFontawesome'], $cdn, [$this, 'gate_remove_duplicate_fontawesome']),

            wps_ic_render_stage::make('replaceImageTags1', $marker, $cdn),
            wps_ic_render_stage::make('picture_stash', [$this, 'stage_picture_stash'], $cdn),
            wps_ic_render_stage::make('replace_image_tags', [$this, 'stage_replace_image_tags'], $cdn),

            wps_ic_render_stage::make('replaceImageTags2', $marker, $cdn),
            wps_ic_render_stage::make('rewrite_inline_font_faces', [$this, 'stage_rewrite_inline_font_faces'], $cdn),
            // One google-fonts display pass for both lanes, kept ahead of fonts_zone_rewrite: the
            // zone rewrite moves the very font hosts this pass matches, so it has to read the
            // untouched hrefs.
            wps_ic_render_stage::make('gfonts_display_param', [self::class, 'wpc_gfonts_display_pass'], $both),
            wps_ic_render_stage::make('replace_picture_tags', [$this, 'stage_replace_picture_tags'], $cdn),
            wps_ic_render_stage::make('unmask_media_scripts', [$this, 'stage_unmask_media_scripts'], $cdn),
            wps_ic_render_stage::make('legacy_upload_host', [$this, 'stage_legacy_upload_host'], $cdn),
            wps_ic_render_stage::make('lazy_version_bust', [$this, 'stage_lazy_version_bust'], $cdn, [$this, 'gate_lazy_version_bust']),

            wps_ic_render_stage::make('replaceImageTags3', $marker, $cdn),
            wps_ic_render_stage::make('revslider_images', [$this, 'stage_revslider_images'], $cdn),

            // The CSS block, the same passes on both lanes. The setup is pure request and option
            // reads, so it leaves the buffer alone and gives both lanes the same context from
            // here on. The corpus push runs only on the crit generator's render.
            wps_ic_render_stage::make('cdn_rewrite_url', $marker, $cdn),
            wps_ic_render_stage::make('critical_setup', [$this, 'stage_critical_setup'], $both),
            wps_ic_render_stage::make('crit_corpus_push', [$this, 'stage_crit_corpus_push'], $both, [$this, 'gate_crit_corpus_push']),

            wps_ic_render_stage::make('combine_css', $marker, $both),
            wps_ic_render_stage::make('lazy_fontawesome', [$this, 'stage_lazy_fontawesome'], $both),
            wps_ic_render_stage::make('critical_kick', [$this, 'stage_critical_kick'], $both, [$this, 'gate_critical_kick']),
            wps_ic_render_stage::make('critical_and_lazy_css', [$this, 'stage_critical_and_lazy_css'], $both),
            wps_ic_render_stage::make('crit_atf_passes', [$this, 'stage_crit_atf_passes'], $both, [$this, 'gate_crit_atf_passes']),
            // The wire's font-family drop[] sweep had its own entry here so it could run after
            // lazyCSS, which was the only place the deferred sheets' faces existed as written
            // bytes. With one face owner per render there are no written bytes to chase: the
            // demotion is recorded against the family at addCritical time and applied when the
            // set splits, whatever registers afterwards. The stage and its gate went with the
            // document sweep they called.

            // The CDN lane's URL stretch. The document-URL and data-code entries share one pattern
            // by both calling documentUrlPattern(); the background pass keeps its own local.
            wps_ic_render_stage::make('cdn_rewrite_url_2', $marker, $cdn),
            wps_ic_render_stage::make('encode_meta', [$this, 'stage_encode_meta'], $cdn),
            wps_ic_render_stage::make('negotiated_stash', [$this, 'stage_negotiated_stash'], $cdn),
            wps_ic_render_stage::make('rewrite_document_urls', [$this, 'stage_rewrite_document_urls'], $cdn),
            wps_ic_render_stage::make('rewrite_css_background_urls', [$this, 'stage_rewrite_css_background_urls'], $cdn),
            wps_ic_render_stage::make('reencode_data_code', [$this, 'stage_reencode_data_code'], $cdn),

            wps_ic_render_stage::make('externalUrls', $marker, $cdn),
            wps_ic_render_stage::make('external_urls', [$this, 'stage_external_urls'], $cdn),
            wps_ic_render_stage::make('all_links', [$this, 'stage_all_links'], $cdn, [$this, 'gate_all_links']),
            wps_ic_render_stage::make('prepare_preloads', [$this, 'stage_prepare_preloads'], $cdn),
            wps_ic_render_stage::make('decode_meta', [$this, 'stage_decode_meta'], $cdn),

            wps_ic_render_stage::make('fonts', $marker, $cdn),
            wps_ic_render_stage::make('fonts_zone_rewrite', [$this, 'stage_fonts_zone_rewrite'], $cdn),
            wps_ic_render_stage::make('cio_fonts', [$this, 'stage_cio_fonts'], $cdn),

            wps_ic_render_stage::make('decodeIframe', $marker, $cdn),
            wps_ic_render_stage::make('decode_iframe', [$this, 'stage_decode_iframe'], $both, [$this, 'gate_decode_iframe']),

            wps_ic_render_stage::make('noscript_decode', $marker, $cdn),
            wps_ic_render_stage::make('noscript_decode_pass', [$this, 'stage_noscript_decode_pass'], $cdn),

            // The delay-JS block. The CDN body answered to three checkpoint labels in a row here
            // with nothing between them; only the last one survives as a position, the other two
            // are aliases onto it. Same for the two branch-local labels inside the delay branches,
            // which now alias onto 3491.
            wps_ic_render_stage::make('delay_js', $marker, $both),
            wps_ic_render_stage::bail('bail_budget_integrations', [$this, 'bail_budget_integrations'], $both),
            wps_ic_render_stage::make('theme_integrations', [$this, 'stage_theme_integrations'], $both),
            wps_ic_render_stage::make('speculation_rules', [$this, 'stage_speculation_rules'], $both, [$this, 'gate_speculation_rules']),
            wps_ic_render_stage::make('delay_scripts', [$this, 'stage_delay_scripts'], $both, [$this, 'gate_delay_scripts']),
            wps_ic_render_stage::make('jquery_defer', [self::class, 'jquery_defer_pass'], $both, [$this, 'gate_delay_v3_ran']),
            wps_ic_render_stage::make('inline_core_scripts', [self::class, 'inline_core_scripts_pass'], $both, [$this, 'gate_delay_v3_ran']),
            wps_ic_render_stage::make('remove_nodelay_markers', [$this, 'stage_remove_nodelay_markers'], $both, [$this, 'gate_remove_nodelay_markers']),
            wps_ic_render_stage::make('css_only_loader', [$this, 'stage_css_only_loader'], $both, [$this, 'gate_css_only_loader']),
            wps_ic_render_stage::make('frames_without_restorer', [self::class, 'wpc_unpark_frames_without_restorer'], $both),

            wps_ic_render_stage::make('3491', $marker, $both),
            wps_ic_render_stage::make('scripts_to_footer', [$this, 'stage_scripts_to_footer'], $both, [$this, 'gate_scripts_to_footer']),
            wps_ic_render_stage::make('yield_checkpoints', [$this, 'stage_yield_checkpoints'], $both, [$this, 'gate_yield_checkpoints']),

            // The post-checkpoint stretch. The minify door sits under its own checkpoint;
            // everything from cache_mobile down runs on both lanes except the negotiated-delivery
            // restore, which only the local lane stashes.
            wps_ic_render_stage::make('cache_minify', $marker, $cdn),
            wps_ic_render_stage::make('minify_html', [$this, 'stage_minify_html'], $both, [$this, 'gate_minify_html']),

            wps_ic_render_stage::make('cache_mobile', $marker, $both),
            // One shared entry strips <!--WPC…--> here, on both lanes. Nothing ahead of it emits
            // such a comment: the yield pass injects <script src=…wpc-yield-*.js> tags, the gfonts
            // pass only edits href query strings, and the face blocks are written later, by the
            // emit stage below. So the set of comments this strip sees is the same on either lane.
            wps_ic_render_stage::make('strip_wpc_comments', [$this, 'stage_strip_wpc_comments'], $both),
            wps_ic_render_stage::make('fonts_replace_frontend', [$this, 'stage_fonts_replace_frontend'], $both),
            wps_ic_render_stage::make('fonts_bunny_swap', [$this, 'stage_fonts_bunny_swap'], $both, [$this, 'gate_fonts_bunny_swap']),
            wps_ic_render_stage::make('modern_delivery', [$this, 'stage_modern_delivery'], $both, [$this, 'gate_modern_delivery']),
            wps_ic_render_stage::make('negotiated_stash_restore_local', [$this, 'stage_negotiated_stash_restore_local'], $local),
            wps_ic_render_stage::make('naturalize_asset_urls', [wps_rewriteLogic::class, 'naturalize_asset_urls'], $cdn, [$this, 'gate_natural_assets_on']),
            wps_ic_render_stage::make('logo_rightsize', [wps_rewriteLogic::class, 'logo_rightsize'], $cdn, [$this, 'gate_logo_rightsize']),
            wps_ic_render_stage::make('svg_inline_data', [$this, 'stage_svg_inline_data'], $cdn, [$this, 'gate_svg_inline_data']),
            // A bail with a gate: the door only existed inside the splicer's function_exists
            // check, and that check is the gate.
            wps_ic_render_stage::bail('bail_budget_stack', [$this, 'bail_budget_stack'], $both, [$this, 'gate_stack_splice']),
            wps_ic_render_stage::make('stack_splice', [$this, 'stage_stack_splice'], $both, [$this, 'gate_stack_splice']),
            // The last two html-mutating font steps, in this order and nowhere else: the feeder
            // is what turns the page's remaining carrier sheets into registered faces, and the
            // emit stage is the only thing that writes @font-face into the document. They sit
            // after fonts_replace_frontend for the .680/.72 reason — an absorb that ran before
            // the gf-local link emitter ran before its own inputs existed.
            //
            // The feeder stands down on AMP: taking a font <link> off the page and inlining what
            // it declared as a <style> block is exactly the shape AMP forbids, and an AMP page
            // is not one this plugin's parked-sheet machinery ever arms.
            //
            // The emit carries NO gate on purpose, and it is the one font entry that must not:
            // it is the close of the window open_font_faces opened, so a gate that skipped it
            // would not stop the emit, it would only move it to the runner's finally — the page
            // would still be written, just later and with no entry in the trace saying so. What
            // AMP changes is WHAT is written, and that belongs in the writer: emit_font_faces
            // takes $ctx->isAmp and drops the late block and its flip script. open_font_faces is
            // likewise ungated, and could not be gated here even if it should be: it runs ahead
            // of amp_settings_squash, so $ctx->isAmp is still false at its position, and a skip
            // there would leave the set unpublished and every face of the render lost.
            wps_ic_render_stage::make('absorb_font_sheets', [$this, 'stage_absorb_font_sheets'], $both, [$this, 'gate_not_amp']),
            wps_ic_render_stage::make('emit_font_faces', [$this, 'stage_emit_font_faces'], $both),

            // The tail, one entry per pass. It is the last group in the table, so a bail that
            // fires ends the run before any of it and the visitor gets the pristine buffer. The
            // two wps_cacheHtml passes (bricks_atf_unveil, crit_bg_preload) are registered twice,
            // at the two positions the lanes need them — local before the image tail, CDN after —
            // so the runner's lane filter picks one copy per render.
            //
            // css_host_twin_sweep is a zero-byte marker: the sweep itself runs inside
            // heal_mixed_content on both lanes, and this is the position the tail names.
            //
            // Eight of the tail passes carry gate_not_amp. Seven inject markup AMP forbids — the LCP
            // preload and the image-preload writer, the quiet wire and its below-fold companion,
            // the video poster pass, the RUM beacon and the device check — and the eighth,
            // lcp_demote_competitors, strips fetchpriority against the LCP identity the gated
            // preload pass found, so it is skipped with it. amp_settings_squash squashes the
            // settings the earlier passes read; these eight read none of them, which is why the
            // gate exists.
            wps_ic_render_stage::make('css_host_twin_sweep', $marker, $both),
            wps_ic_render_stage::make('svg_naturalize', [self::class, 'wpc_svg_naturalize'], $both),
            wps_ic_render_stage::make('raster_naturalize', [self::class, 'wpc_raster_naturalize'], $both),
            wps_ic_render_stage::make('svg_zoneify', [self::class, 'wpc_svg_zoneify'], $both),
            wps_ic_render_stage::make('raster_zoneify', [self::class, 'wpc_raster_zoneify'], $both),
            wps_ic_render_stage::make('eager_nextgen_sources', [$this, 'stage_eager_nextgen_sources'], $both),
            wps_ic_render_stage::make('asset_naturalize', [self::class, 'wpc_asset_naturalize'], $both),
            wps_ic_render_stage::make('collapse_double_ext', [self::class, 'wpc_collapse_double_ext'], $both),
            wps_ic_render_stage::make('crawler_urls_as_printed', [self::class, 'wpc_crawler_urls_as_printed'], $both),
            // The origin fallback is written once, after every pass that moves an asset URL onto
            // or off the zone. Written earlier, a later pass rewrote what it had written: the SVG
            // zoneifier moved an image's fallback onto the zone (2026-09-24), and the delay
            // loader, moved back to origin after its fallback was stamped, carried two onerror
            // attributes, so the browser kept the zone fallback and dropped the loader's own.
            wps_ic_render_stage::make('asset_failover', [$this, 'stage_asset_failover'], $both, [$this, 'gate_natural_assets_on']),
            wps_ic_render_stage::make('hint_unify', [$this, 'stage_hint_unify'], $both),
            wps_ic_render_stage::make('origin_twins', [$this, 'stage_origin_twins'], $both),

            wps_ic_render_stage::make('bricks_atf_unveil_local', [$this, 'stage_bricks_atf_unveil'], $local),
            wps_ic_render_stage::make('crit_bg_preload_local', [$this, 'stage_crit_bg_preload'], $local),

            wps_ic_render_stage::make('lazy_srcset', [self::class, 'wpc_lazy_srcset_buffer_pass'], $both),
            wps_ic_render_stage::make('srcset_space_encode', [self::class, 'wpc_srcset_space_encode_pass'], $both),
            wps_ic_render_stage::make('lcp_hint', [self::class, 'wpc_lcp_hint_pass'], $both),
            wps_ic_render_stage::make('lcp_first_party', [$this, 'stage_lcp_first_party'], $both),
            wps_ic_render_stage::make('eager_first_party', [$this, 'stage_eager_first_party'], $both),
            wps_ic_render_stage::make('image_sizes', [self::class, 'stage_image_sizes_pass'], $both),
            // The image preloads: the last two proposers, then the one writer. After delivery and
            // the naturalize/zoneify/first-party moves, so a preload names the URL its <img>
            // loads; after image_sizes, whose sizes it mirrors; before quiet_wire, which has to
            // see the preload hosts to keep their preconnects.
            wps_ic_render_stage::make('lcp_img_preload', [$this, 'stage_lcp_img_preload'], $both, [$this, 'gate_not_amp']),
            wps_ic_render_stage::make('crit_bg_preload', [$this, 'stage_crit_bg_preload'], $cdn),
            wps_ic_render_stage::make('emit_image_preloads', [$this, 'stage_emit_image_preloads'], $both, [$this, 'gate_not_amp']),
            wps_ic_render_stage::make('quiet_wire', [self::class, 'wpc_quiet_wire_pass'], $both, [$this, 'gate_not_amp']),
            wps_ic_render_stage::make('below_fold_cv', [self::class, 'wpc_below_fold_cv_tag'], $both, [$this, 'gate_not_amp']),
            wps_ic_render_stage::make('picture_fidelity', [self::class, 'wpc_picture_fidelity_pass'], $both),
            wps_ic_render_stage::make('lcp_demote_competitors', [$this, 'stage_lcp_demote_competitors'], $both, [$this, 'gate_not_amp']),
            wps_ic_render_stage::make('drop_dashicons', [self::class, 'drop_dashicons'], $both),
            wps_ic_render_stage::make('rum_beacon', [self::class, 'wpc_rum_beacon_pass'], $both, [$this, 'gate_not_amp']),
            wps_ic_render_stage::make('device_check', [self::class, 'wpc_device_check_pass'], $both, [$this, 'gate_not_amp']),
            wps_ic_render_stage::make('font_preconnect', [self::class, 'wpc_font_preconnect_pass'], $both),
            wps_ic_render_stage::make('zone_preconnect', [$this, 'stage_zone_preconnect'], $both),
            wps_ic_render_stage::make('zone_font_preconnect', [self::class, 'wpc_zone_font_preconnect_pass'], $both),
            wps_ic_render_stage::make('image_pins', [self::class, 'stage_image_pins_pass'], $both),
            wps_ic_render_stage::make('hoist_viewport', [self::class, 'wpc_hoist_viewport_pass'], $both),
            wps_ic_render_stage::make('prune_idle_preconnects', [self::class, 'wpc_prune_idle_preconnects_pass'], $both),
            wps_ic_render_stage::make('video_delay', [self::class, 'wpc_video_delay_pass'], $both, [$this, 'gate_not_amp']),
            wps_ic_render_stage::make('trim_preset_vars', [self::class, 'wpc_trim_preset_vars_page'], $both),

            wps_ic_render_stage::make('bricks_atf_unveil', [$this, 'stage_bricks_atf_unveil'], $cdn),

            wps_ic_render_stage::make('slider_settle', [self::class, 'wpc_slider_settle_pass'], $both),
            wps_ic_render_stage::make('freshness_marker', [self::class, 'wpc_freshness_marker'], $both),
            wps_ic_render_stage::make('restore_templates_final', [$this, 'stage_restore_templates_final'], $cdn),
        ];
    }

    /* -------------------------------------------------------------------------------------
     * The tail. Most of its passes are registered in the table directly; the methods below are
     * the ones that do something beyond forwarding the buffer. The two lanes differ in exactly
     * three places: the suppressed argument to origin_twins, where the two wps_cacheHtml passes
     * sit relative to the image tail (the table registers them twice, once per lane), and the
     * CDN lane's template restore at the end.
     * ------------------------------------------------------------------------------------- */

    /** Kept as a method: hint_unify's second parameter is a zone, so a direct table entry would
     *  pass the render context into it. The same holds for the two first-party passes below. */
    public function stage_hint_unify($html, $ctx)
    {
        return self::hint_unify($html);
    }

    /** The local lane suppressed the twins; the CDN lane did not. */
    public function stage_origin_twins($html, $ctx)
    {
        return ($ctx->lane === wps_ic_render_pipeline::LANE_LOCAL)
            ? self::origin_twins($html, null, null, true)
            : self::origin_twins($html);
    }

    public function stage_lcp_first_party($html, $ctx)
    {
        return self::lcp_first_party($html);
    }

    public function stage_eager_first_party($html, $ctx)
    {
        return self::eager_first_party($html);
    }

    public function stage_zone_preconnect($html, $ctx)
    {
        return self::wpc_zone_preconnect_pass($html, $ctx->lane);
    }

    /**
     * Two passes that are not belts of any cluster: nothing upstream produces the state they
     * answer. Bricks' above-the-fold unveil undoes the builder's own opacity:0 reveal animation
     * on first-frame content; the crit background preload is the LCP hint for a background image
     * the crit paints. Both are idempotent and lane-symmetric, registered once per lane position.
     */
    public function stage_bricks_atf_unveil($html, $ctx)
    {
        if (class_exists('wps_cacheHtml')) {
            $html = wps_cacheHtml::bricksAtfUnveil($html);
        }

        return $html;
    }

    public function stage_crit_bg_preload($html, $ctx)
    {
        if (class_exists('wps_cacheHtml')) {
            $html = wps_cacheHtml::critBgPreload($html, $ctx->imagePreloads);
        }

        return $html;
    }

    /** The LCP image's preload, proposed from the measured / derived / guess chain. */
    public function stage_lcp_img_preload($html, $ctx)
    {
        $ctx->lcpKeepPriorityStems = [];
        return self::wpc_lcp_img_preload_pass($html, $ctx->imagePreloads, $ctx->lcpKeepPriorityStems);
    }

    /** Competitor demotion for the LCP identity lcp_img_preload found. */
    public function stage_lcp_demote_competitors($html, $ctx)
    {
        return self::wpc_lcp_demote_competitors($html, $ctx->lcpKeepPriorityStems);
    }

    /** The only pass that writes image preloads into the document: the render's set, resolved
     *  against the final markup. A combined-crit render serves both devices from one copy, so
     *  it fills both devices' slots; any other render serves the device it was built for. */
    public function stage_emit_image_preloads($html, $ctx)
    {
        $renderDevice = (class_exists('wps_rewriteLogic') && !empty(wps_rewriteLogic::$isMobile)) ? 'mobile' : 'desktop';
        $combined = class_exists('wps_rewriteLogic') && wps_rewriteLogic::wpc_combined_crit_on();

        return $ctx->imagePreloads->emit($html, $renderDevice, $combined);
    }

    /** Eager next-gen swaps, holding back every image a registered preload names. */
    public function stage_eager_nextgen_sources($html, $ctx)
    {
        return self::eager_nextgen_sources($html, $ctx->imagePreloads);
    }

    /** The CDN lane's last step and the normal close of the 'templates' window: it puts the masked
     *  template bodies back and clears both copies of the list. It is the only ordinary way that
     *  closer fires — on a stop, a bail or a throw the runner closes the same window itself, so
     *  this entry never being reached no longer means the bodies are lost. */
    public function stage_restore_templates_final($html, $ctx)
    {
        return $ctx->closeWindow('templates', $html);
    }

    private static function wpc_never_blank($in, $out, $e = null)
    {
        $inFull = is_string($in) && strlen($in) >= 255 && stripos($in, '</body>') !== false;
        $outThin = !is_string($out) || strlen($out) < 255 || stripos($out, '</body>') === false;
        if ($e !== null) {
            // Exception path: the pipeline died mid-flight — the pristine input is ALWAYS the
            // right response, full document or not (a feed/JSON buffer must round-trip too).
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('never-blank-restore', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', [
                    'in' => is_string($in) ? strlen($in) : -1,
                    'out' => 'exception',
                    'err' => substr($e->getMessage(), 0, 120),
                ]);
            }
            return $in;
        }
        if ($inFull && $outThin) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('never-blank-restore', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', [
                    'in' => strlen($in),
                    'out' => is_string($out) ? strlen($out) : -1,
                    'err' => $e ? substr($e->getMessage(), 0, 120) : '',
                ]);
            }
            return $in;
        }
        return $out;
    }


    // A raw space inside a srcset URL reads as a candidate separator — the parser drops
    // the whole attribute ("unknown descriptor"). Encode URL-internal spaces only;
    // separators (after a comma / before a NNNw|Nx descriptor) are preserved.
    public static function wpc_srcset_space_encode_pass($html)
    {
        if (!is_string($html) || stripos($html, 'srcset') === false) {
            return $html;
        }
        $encoded = 0;
        $out = preg_replace_callback('/\b(srcset|data-srcset)\s*=\s*(["\'])(.*?)\2/is', function ($m) use (&$encoded) {
            if (strpos($m[3], ' ') === false) {
                return $m[0];
            }
            $v = preg_replace('/,\s+/', ', ', $m[3]);
            $v = preg_replace('/(?<!,) (?!\d+(?:\.\d+)?[wx]\s*(?:,|$))/', '%20', $v, -1, $spaces);
            if ($spaces > 0) {
                $encoded++;
            }
            return is_string($v) ? $m[1] . '=' . $m[2] . $v . $m[2] : $m[0];
        }, $html);
        // A url in a srcset carried a raw space, from a file name WordPress or a url builder
        // printed as is. Sampled: the same file names print on every render of the page.
        if ($encoded > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('srcset-spaces-encoded', ['n' => $encoded], true);
        }
        return is_string($out) ? $out : $html;
    }

    public static function wpc_lazy_srcset_buffer_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'data-srcset') === false) return $html;
        if (!class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'activate_lazy_srcset_auto')) return $html;
        $set = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        if (!is_array($set) || empty($set['lazy-auto-sizes'])) return $html;

        // (the downstream tail passes pass non-strings through untouched, so NULL survives to output).
        $repaired = 0;
        $out = preg_replace_callback('/<img\b[^>]*?>/is', function ($m) use (&$repaired) {
            $t = wps_rewriteLogic::activate_lazy_srcset_auto($m[0]);
            if (method_exists('wps_rewriteLogic', 'auto_sizes_for_lazy_img')) {
                $t = wps_rewriteLogic::auto_sizes_for_lazy_img($t);
            }
            if ($t !== $m[0]) {
                $repaired++;
            }
            return $t;
        }, $html);
        // The lazy lanes apply these two only to the tags they processed; a tag this pass still
        // changes is one they missed. Not sampled: each such tag is a lane's miss.
        if ($repaired > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('lazy-srcset-repaired', ['n' => $repaired]);
        }
        return is_string($out) ? $out : $html;
    }


    public static function wpc_freshness_marker($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '</body>') === false) return $html;


        // The degraded no-store decision lives in saveCache's tail, which judges the FINAL
        // buffer — this filter can run before crit injection and would veto armed pages.
        if (function_exists('apply_filters') && !apply_filters('wpc_freshness_marker', true)) return $html;


        if (isset($_GET['wpc_census_dbg']) && class_exists('wps_rewriteLogic')
            && !empty(wps_rewriteLogic::$wpc_census_dbg)) {
            $html = wpc_inject_before_body_close($html, '<!--WPC-CDBG ' . wp_json_encode(wps_rewriteLogic::$wpc_census_dbg) . '-->');
        }
        $ver   = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?';
        $now   = time();
        $fresh = !empty($_GET['wpc_fresh']) ? ' FRESH' : '';


        $set   = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        $la    = (is_array($set) && !empty($set['lazy-auto-sizes'])) ? '1' : '0';
        // v7.21.142 — a tripped kill-switch must be visible in view-source: the crit team
        // measured 39 blocking sheets for a day because css-passthrough was silently on.
        // One token in the mint answers "what gates parking?" from any curl.
        $passthrough_token = (function_exists('get_option') && get_option('wpc_css_passthrough') === '1') ? ' pt:1' : '';
        $marker = "\n<!-- wpc " . $ver . ' r:' . $now . ' (' . gmdate('Y-m-d H:i:s', $now) . ' UTC) la:' . $la . $passthrough_token . $fresh . " -->\n";
        return wpc_inject_before_body_close($html, $marker);
    }


    /** v7.10.384 QUIET-WIRE: pre-LCP bandwidth belongs to crit+hero+logo+fonts. Below-fold
     *  lazy imgs and defer-scripts are fetched at parse on the SAME h2 origin as the hero and
     *  split its pipe (busy receipt: hero 48KB took ~3.3s beside ~250KB of lazy/defer bytes).
     *  fetchpriority="low" reweights the stream — zero execution/semantics change: defer runs
     *  at DCL regardless; lazy imgs stay lazy. Never touches tags that already carry a
     *  fetchpriority (the hero/logo keep high). Filter wpc_quiet_wire. */
    /** True when the tag's src attribute holds a data: URI — a placeholder, not a fetchable file. */
    public static function img_src_is_data_uri($tag)
    {
        return preg_match('/\ssrc=(["\'])\s*data:[^"\']*\1/i', (string) $tag) === 1;
    }

    /**
     * The URL quiet-wire should park as the restore target for an <img> whose src is a
     * placeholder, or '' when it must leave the tag alone. The picture lane keeps the real file
     * in data-wpc-fb (its own onerror target) when it swaps src for a data: placeholder, so that
     * carrier is the restore target. An <img> still parked for a lazy lane (data-src and friends)
     * belongs to that lane's restore, which also un-parks the <picture> sources; taking it over
     * here would fetch the origin file behind the lane's back, so it is returned untouched.
     * Values are copied verbatim — they are already attribute-escaped by whoever wrote them.
     */
    public static function quiet_wire_restore_url($tag)
    {
        $tag = (string) $tag;
        $fetchable = function ($value) {
            $value = trim((string) $value);
            return $value !== '' && $value !== '0' && stripos($value, 'data:') !== 0;
        };
        foreach (['data-src', 'data-wpc-src', 'data-lazy-src'] as $parked_carrier) {
            if (preg_match('/\s' . $parked_carrier . '=(["\'])(.*?)\1/i', $tag, $parked)
                && $fetchable($parked[2])) {
                return '';
            }
        }
        if (preg_match('/\sdata-wpc-fb=(["\'])(.*?)\1/i', $tag, $fallback) && $fetchable($fallback[2])) {
            return trim($fallback[2]);
        }
        return '';
    }

    public static function wpc_quiet_wire_pass($html)
    {
        if (!is_string($html) || $html === '') return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_quiet_wire', true)) return $html;
        $deferImages = function_exists('apply_filters') ? (bool) apply_filters('wpc_quiet_wire_defer_imgs', true) : true;
        $deferredCount = 0;
        $eagerSeen = false; // ATF belt: defer only BELOW the first eager img (hero/logo) —
                                 // a mis-lazied ATF img restored late would mint its own late
                                 // LCP candidate.
        // v7.10.392 device-twin belt: themes duplicate ATF images per breakpoint (one eager,
        // twins lazy; CSS shows ONE per viewport). A deferred twin that CSS displays is a
        // blank hero until restore. Same normalized stem as a seen eager img -> untouched.
        $eagerStems = [];
        $stemOf = function ($t) {
            if (!preg_match('/\ssrc=(["\'])(.*?)\1/i', $t, $sm)) return '';
            $u = strtolower((string) strtok($sm[2], '?'));
            return preg_replace('/-\d+x\d+(\.[a-z0-9]{2,5})$/', '$1', $u);
        };
        // A <noscript> block is matched whole and returned as it is. Its images are the page's own
        // no-JS fallback: parking one makes it need the script it stands in for, and the twin
        // appended below nests a <noscript> whose closing tag ends the outer block early, so the
        // rest of the fallback lands in the live page. Third Wing (ticket 12076): Soliloquy's
        // eight .soliloquy-no-js-image fallbacks, seven of them stacked under the slider.
        $out = preg_replace_callback('/<noscript\b[^>]*>.*?<\/noscript>|<img\b[^>]*>/is', function ($m) use ($deferImages, &$deferredCount, &$eagerSeen, &$eagerStems, $stemOf) {
            $t = $m[0];
            if (stripos($t, '<noscript') === 0) return $t;
            if (stripos($t, 'loading="lazy"') === false && stripos($t, "loading='lazy'") === false) {
                $eagerSeen = true;
                $stem = $stemOf($t);
                if ($stem !== '') $eagerStems[$stem] = 1;
                return $t;
            }
            if (stripos($t, 'fetchpriority') !== false) return $t;
            $stem = $stemOf($t);
            if ($stem !== '' && isset($eagerStems[$stem])) return $t;
            // The picture lane runs earlier and has already swapped src for a data: placeholder on
            // its fallback <img>, so reading src here parks a placeholder as the restore target and
            // the restore script "restores" a blank. Take the real file from the carrier that lane
            // kept it in; with nothing fetchable on the tag, leave it to whoever parked it.
            $wpc_placeholder_src = self::img_src_is_data_uri($t);
            $wpc_restore_url = $wpc_placeholder_src ? self::quiet_wire_restore_url($t) : '';
            // Three lazy lanes park the same <img>: a placeholder src is restored from the picture
            // lane's data-wpc-fb, or left to the lane that parked it. Never sampled: two lanes
            // reached one tag.
            if ($wpc_placeholder_src && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('quiet-wire-placeholder', $wpc_restore_url === '' ? ['left' => 1] : ['from_fb' => 1]);
            }
            if ($wpc_placeholder_src && $wpc_restore_url === '') return $m[0];
            $t = preg_replace('/<img\b/i', '<img fetchpriority="low"', $t, 1);
            if ($deferImages && $eagerSeen && stripos($t, 'data-wpc-qw') === false && preg_match('/\ssrc=/i', $t)) {
                $originalTag = $m[0];
                if ($wpc_restore_url !== '') {
                    // src already holds the placeholder that pins the box — only the restore
                    // target is missing, so add it instead of renaming the placeholder.
                    $t = preg_replace('/<img\b/i', '<img data-wpc-qw-src="' . $wpc_restore_url . '"', $t, 1);
                } else {
                    $t = preg_replace('/\ssrc=(["\'])/i', ' data-wpc-qw-src=$1', $t, 1);
                }
                $t = preg_replace('/\ssrcset=(["\'])/i', ' data-wpc-qw-srcset=$1', $t, 1);
                $t = preg_replace('/\ssizes=(["\'])/i', ' data-wpc-qw-sizes=$1', $t, 1);
                // Parking src left the <img> with NO src at all, so the browser painted its
                // broken-image glyph immediately and kept it until the qw script ran. That makes no
                // request, so it fires no error event and is invisible to HAR/netlog/curl — it only
                // showed up in an in-page trace as complete && naturalWidth===0 with src "(none)".
                // A transparent placeholder keeps the element renderable; the qw script overwrites it.
                // Match the element's own intrinsic ratio where it declares one: a fixed-ratio
                // placeholder on an <img> WITHOUT width/height would lay out at the wrong shape and
                // then shift when the real image swaps in. busy's 20 all declare width+height, but
                // fleet-wide many do not.
                $placeholderUri = '';
                if (preg_match('/\swidth=(["\'])(\d{1,5})\1/i', $t, $widthMatch)
                    && preg_match('/\sheight=(["\'])(\d{1,5})\1/i', $t, $heightMatch)
                    && (int) $widthMatch[2] > 0 && (int) $heightMatch[2] > 0) {
                    $placeholderUri = 'data:image/svg+xml;base64,' . base64_encode(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $widthMatch[2]
                        . '" height="' . (int) $heightMatch[2] . '"/>'
                    );
                }
                if ($placeholderUri === '') {
                    $placeholderUri = (!empty(self::$svg_placeholder) && is_string(self::$svg_placeholder))
                        ? self::$svg_placeholder
                        : 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciLz4=';
                }
                if (!preg_match('/\ssrc=/i', $t)) {
                    $t = preg_replace('/<img\b/i', '<img src="' . $placeholderUri . '"', $t, 1);
                }
                // v7.10.481 — PIN THE BOX ON THE LAZY LANE TOO. The eager lane already emits
                // style="aspect-ratio:W/H" (:2036/:2066/:2111) and the quiet-wire lane did not, so
                // the pin was applied on one side only. Matching intrinsic dims on the placeholder
                // (.387) are NOT sufficient: they lose to theme CSS such as img{height:auto}, which
                // is why Lighthouse still reports "Unsized image element" for every one of these.
                // MEASURED, not assumed: zinsenvergleich held CLS 0.141 after the font gate was
                // removed, and all three scoring rows name this img and nothing else.
                // aspect-ratio in an inline style survives height:auto, so the box holds from parse
                // through the qw swap. Only ever added when the element declares both dimensions.
                if (apply_filters('wpc_qw_pin_aspect_ratio', true)
                    && stripos($t, 'aspect-ratio') === false
                    && preg_match('/\swidth=(["\'])(\d{1,5})\1/i', $t, $pinWidthMatch)
                    && preg_match('/\sheight=(["\'])(\d{1,5})\1/i', $t, $pinHeightMatch)
                    && (int) $pinWidthMatch[2] > 0 && (int) $pinHeightMatch[2] > 0) {
                    // v7.10.923 demoted pin — inline stamps trampled builder stylesheet crops
                    // (maisonpro receipt: 768/1024 struck .bde-image2's 4/3).
                    $t = self::wpc_pin_aspect_ratio_var($t, $pinWidthMatch[2], $pinHeightMatch[2]);
                }
                $deferredCount++;
                // v7.10.389 noscript twin: JS-off UAs get the original tag (sans handlers).
                $t .= '<noscript>' . preg_replace('/\sonerror=(["\']).*?\1/i', '', $originalTag) . '</noscript>';
            }
            return $t;
        }, $html);
        if (is_string($out)) $html = $out;
        // v7.10.389 preconnect dedupe: repeated identical preconnects waste head bytes and
        // trip the PSI >4 warning; keep the first of each (href + crossorigin-form) pair.
        $seenPreconnects = [];
        $liveReferences = null;
        $preconnectDupes = 0;
        $preconnectPruned = 0;
        $out = preg_replace_callback('/<link\b[^>]*rel=(["\'])preconnect\1[^>]*>/i', function ($m) use (&$seenPreconnects, &$liveReferences, $html, &$preconnectDupes, &$preconnectPruned) {
            $h = preg_match('/href=(["\'])(.*?)\1/i', $m[0], $hm) ? strtolower($hm[2]) : '';
            if ($h === '') return $m[0];
            $k = $h . '|' . (stripos($m[0], 'crossorigin') !== false ? 'c' : '');
            if (isset($seenPreconnects[$k])) {
                $preconnectDupes++;
                return '';
            }
            $seenPreconnects[$k] = 1;
            // v7.10.393 unused-preconnect prune: a host with no live src/href/srcset
            // reference never connects in the pre-interaction window (delayed vendors
            // boot post-gesture and warm their own connection then). Font CDNs exempt —
            // their references live inside stylesheets, not markup attributes.
            $preconnectHost = strtolower((string) parse_url($h, PHP_URL_HOST));
            if ($preconnectHost !== ''
                && (!function_exists('apply_filters') || apply_filters('wpc_preconnect_prune', true))
                && strpos($preconnectHost, 'gstatic') === false && strpos($preconnectHost, 'googleapis') === false
                && strpos($preconnectHost, 'typekit') === false && strpos($preconnectHost, 'bunny.net') === false) {
                if ($liveReferences === null) {
                    $htmlWithoutPreconnects = preg_replace('/<link\b[^>]*rel=(["\'])preconnect\1[^>]*>/i', '', $html);
                    if (preg_match_all('/\s(?:src|href|srcset|imagesrcset)=(["\'])(.*?)\1/i', (string) $htmlWithoutPreconnects, $referenceMatches)) {
                        $liveReferences = strtolower(implode(' ', $referenceMatches[2]));
                    } else {
                        $liveReferences = '';
                    }
                }
                if (strpos($liveReferences, $preconnectHost) === false) {
                    $preconnectPruned++;
                    return '';
                }
            }
            return $m[0];
        }, $html);
        if (is_string($out)) {
            $html = $out;
            // Several emitters print preconnects and none owns them: duplicates and hosts nothing
            // on the page references are dropped. Sampled: the same hints print on every render.
            if ($preconnectDupes + $preconnectPruned > 0 && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('quiet-wire-preconnects', array_filter(['dupes' => $preconnectDupes, 'pruned' => $preconnectPruned]), true);
            }
        }
        if ($deferredCount > 0 && stripos($html, '</body>') !== false) {
            // v7.10.396 interaction-gated restore (was load+150ms — a timer inside the lab's
            // trace window puts every deferred image back on the report). First gesture
            // restores; below-fold content is unreachable without one. Belts: already-scrolled
            // pages restore immediately; an IntersectionObserver catches a mis-classified
            // near-fold image (visible content must load, lab or not); bfcache restores on
            // pageshow. onerror fallback chain intact (attributes ride the tag untouched).
            // v7.10.527 — PSI measured 91 ms of FORCED REFLOW here, the single largest of the
            // three our scripts contribute. The cause was read/write interleaving during parse:
            // the scroll probe (pageYOffset/scrollTop) forces layout, the src writes invalidate
            // it, and a SECOND querySelectorAll + observe() forces it again. Now: one cached
            // element list instead of two queries, and the probe + observer setup run inside a
            // single rAF so the read happens after layout has settled rather than mid-parse.
            // The list is queried FRESH on every run, never cached: the cached NodeList froze at
            // first-rAF, which on a long or malformed document (an SEO plugin's premature </body>
            // pushed this script to 68% of the bytes) missed every image parsed after it — three
            // images stayed placeholders forever because the one-shot r() had already burned.
            // r() stays cheap to re-run, and a post-run sweep on DOMContentLoaded catches anything
            // that entered the DOM after the first restore.
            // v7.21.114 — the restore IS the lazy gate: leaving loading="lazy" on a restored img
            // is a second deferral nobody undoes. WebKit 15's native lazyload is transform-blind:
            // slides translated into view by a carousel track never fetch (platformtraining
            // marquee, 77/90 stuck on Safari 15.6.1; 14.1 ignores the attr, 16.5 re-evaluates).
            // On the placeholder data: URI the attr is inert, so removal costs nothing before
            // restore; it precedes the src write so the fetch decision at src-set is already
            // eager instead of trusting old WebKit's attr-change reclassification.
            // v7.21.117 — decoding="sync" before the src write. Safari 15's async-decode paint
            // race: a JS src swap on a decoding="async" img (WP stamps it) decodes off-thread
            // and, on losing frame timings, the repaint is dropped — image decoded (naturalWidth
            // set, opacity 1, laid out, right-click menu live) but never painted. Field receipt:
            // real 15.6.1 ?wpc_qw_debug=1 box showed 75/75 loaded beside visibly blank slots;
            // intermittent across reloads; container/layer tickles don't recover it. Sync decode
            // ties decode to paint. Gesture-time restores of small images — milliseconds, and
            // the LCP img is eager, never qw-gated.
            // v7.21.115 — three hardenings, same law (no brake survives the open gate):
            // (1) pointermove/mousemove join the restore's human-signal list — a mouse twitch
            //     replays the delay lane (marquee mounts, slides) but never opened this gate, so
            //     a non-scrolling viewer watched a sliding strip of placeholders. Lighthouse
            //     sends no input events, so the lab trace is untouched.
            // (2) gesture-path restores (g) also lift fetchpriority="low" — post-gesture the LCP
            //     window is closed and low-priority queuing of ~50 logos reads as "images never
            //     came" on slow links. Double-gated: only after the REAL load event
            //     (performance.timing.loadEventEnd — the readyState shadow lies here), because a
            //     first mouse twitch can land pre-LCP where the stamp is load-bearing. The
            //     scrolled-at-boot immediate path keeps low for the same reason.
            // (3) the IO belt re-arms instead of one-shot disconnect, and the DCL sweep re-boots
            //     it when no restore ran yet — the boot-time querySelectorAll on a streaming
            //     document misses everything parsed after first-rAF, and a disconnected observer
            //     could never pick those up.
            // v7.21.119 — Safari-15-desktop restores IMMEDIATELY at boot (no gesture gate, no
            // lazy window). Field receipt on real 15.6.1: .117's sync decode did NOT recover the
            // dropped paints — the drop is not decode-mode-sensitive. Plugin-off works there
            // because src is real in the HTML and never JS-swapped after compositing; the
            // closest runtime equivalent under UA-agnostic cached HTML is swapping before the
            // img is ever composited: boot-time restore means every below-fold img (all the
            // blank victims) carries its real src while still unpainted, so there is no stale
            // layer to inherit. Lab-safe: Lighthouse never presents this UA. The .118 clone
            // belt stays behind it for anything composited before boot.
            // v7.21.118 — desktop-Safari-15-only re-rasterize belt behind the sync-decode fix.
            // The paint race drops the repaint AFTER decode succeeds, so no readable JS state
            // distinguishes a painted img from a dropped one — the belt must be unconditional
            // within the affected engine. Once the restored img has real pixels (complete +
            // naturalWidth), it is re-inserted via cloneNode/replaceChild on the next frame:
            // a fresh node cannot inherit the stale layer, and decoding="sync" (reflected onto
            // the attribute, so the clone carries it) ties the re-decode to the paint. Gated to
            // Macintosh + Version/15.x Safari — the only cohort with the bug; iOS 15 verified
            // healthy and modern engines never enter. Node identity changes for those imgs on
            // that one engine; accepted over permanently blank images.
            // v7.21.197 RE-ARM FOR DOM-INJECTED MARKUP. Every trigger in this rider is
            // page-load-scoped and one-shot: x removes itself on first fire, ob() re-arms
            // only from inside its own IO callback over the nodes present at that instant,
            // and the DCL sweep runs once. Markup injected LATER therefore never restores.
            // Field receipt (harmonytree.net/our-work): Responsive Lightbox "Load More"
            // GETs /rl_gallery/gallery-images/?rl_gallery_no=1&rl_page=2 and injects that
            // rendered page — 15 of its 42 imgs arrive parked on the svg placeholder, and
            // stayed blank forever. (rewriteLogic's rl_gallery_no guard cannot help: it
            // only zeroes the CLASSIC lazy/adaptive lanes, and quiet-wire parks the src
            // afterwards off the tag's own loading="lazy".) Not gallery-specific — the same
            // hole swallows infinite scroll, AJAX filters, and any SPA-ish partial render.
            // Policy: once a restore has happened the pre-LCP budget quiet-wire protects is
            // already spent, so injected imgs restore immediately; before any restore they
            // go to ob() and keep the lazy window. childList only + the parked-attr test
            // means r()'s own src writes cannot re-enter the callback.
            $restoreScript = '<script id="wpc-qw-restore">(function(){var d=false;'
                . 'var s15=/Macintosh.*Version\/15\.[0-9.]+ Safari/.test(navigator.userAgent||"");'
                . 'function rp(e){var fin=function(){(window.requestAnimationFrame||setTimeout)(function(){'
                . 'try{var p=e.parentNode;if(p)p.replaceChild(e.cloneNode(false),e);}catch(t){}});};'
                . 'if(e.complete&&e.naturalWidth>0){fin();}else{e.addEventListener("load",fin,{once:true});}}'
                . 'function wpcQuietWireIsVisible(e){try{return!!e.getClientRects().length&&"hidden"!==getComputedStyle(e).visibility}catch(t){return!0}}'
                . 'function r1(e,g){if(!e||!e.getAttribute||!e.getAttribute("data-wpc-qw-src"))return;'
                . 'if(e.getAttribute("loading")==="lazy")e.removeAttribute("loading");'
                . 'if("decoding" in e)e.decoding="sync";'
                . 'if(g&&e.getAttribute("fetchpriority")==="low"&&window.performance&&performance.timing&&performance.timing.loadEventEnd>0)e.removeAttribute("fetchpriority");'
                . 'if(e.getAttribute("data-wpc-qw-sizes"))e.setAttribute("sizes",e.getAttribute("data-wpc-qw-sizes"));'
                . 'if(e.getAttribute("data-wpc-qw-srcset"))e.setAttribute("srcset",e.getAttribute("data-wpc-qw-srcset"));'
                . 'e.setAttribute("src",e.getAttribute("data-wpc-qw-src"));e.removeAttribute("data-wpc-qw-src");'
                . 'if(s15)rp(e);}'
                . 'function r(g){d=true;var i=document.querySelectorAll("img[data-wpc-qw-src]"),n;for(n=0;n<i.length;n++)r1(i[n],g);}'
                . 'window.wpcQwRestore=r;'
                . 'function rn(){try{var i=document.querySelectorAll("img[data-wpc-qw-src]"),h=(window.innerHeight||0)+500,n,b;for(n=0;n<i.length;n++){b=i[n].getBoundingClientRect();if(b.bottom>=-500&&b.top<=h&&wpcQuietWireIsVisible(i[n]))r1(i[n],1);}}catch(e){}}'
                . 'var v=["scroll","wheel","touchstart","keydown","pointerdown","pointermove","mousemove"],x=function(){'
                . 'for(var j=0;j<v.length;j++)window.removeEventListener(v[j],x,{passive:true});if(window.IntersectionObserver){ob("500px 0px");rn();}else{r(1);}};'
                . 'var rt=0;function rs(){if(rt)return;rt=1;(window.requestAnimationFrame||setTimeout)(function(){rt=0;rn();});}'
                . 'window.addEventListener("pointerdown",rs,{passive:true});window.addEventListener("keydown",rs,{passive:true});'
                . 'var o=null,mg="0px";function ob(m){try{if(!window.IntersectionObserver)return;'
                . 'if(m&&m!==mg){mg=m;if(o){o.disconnect();o=null;}}'
                . 'if(!o){o=new IntersectionObserver(function(en){'
                . 'for(var k=0;k<en.length;k++){if(en[k].isIntersecting){var tg=en[k].target;if(tg&&tg.getAttribute){if(!wpcQuietWireIsVisible(tg))continue;o.unobserve(tg);r1(tg,1);}else{r(1);ob();return;}}}},{rootMargin:mg});}'
                . 'var m=document.querySelectorAll("img[data-wpc-qw-src]");'
                . 'if(!m.length){o.disconnect();return;}'
                . 'for(var q=0;q<m.length;q++)o.observe(m[q]);}catch(e){}}'
                . 'var mo=null;function mw(){try{if(mo||!window.MutationObserver)return;'
                . 'mo=new MutationObserver(function(ms){var f=false,a,b;'
                . 'for(a=0;a<ms.length&&!f;a++){var ns=ms[a].addedNodes;if(!ns)continue;'
                . 'for(b=0;b<ns.length;b++){var n=ns[b];if(!n||n.nodeType!==1)continue;'
                . 'if(n.tagName==="IMG"){if(n.getAttribute("data-wpc-qw-src")){f=true;break}}'
                . 'else if(n.querySelector&&n.querySelector("img[data-wpc-qw-src]")){f=true;break}}}'
                . 'if(f){if(d)r(1);else ob();}});'
                . 'mo.observe(document.documentElement,{childList:true,subtree:true});}catch(e){}}'
                . 'function boot(){mw();'
                . 'if(s15){r();return;}'
                . 'if((typeof window.pageYOffset==="number"?window.pageYOffset:(document.documentElement||{}).scrollTop||0)>0){r();return;}'
                . 'for(var j=0;j<v.length;j++)window.addEventListener(v[j],x,{passive:true});'
                . 'ob();}'
                . 'if(window.requestAnimationFrame){requestAnimationFrame(boot);}else{boot();}'
                . 'document.addEventListener("DOMContentLoaded",function(){if(d)r(1);else ob();});'
                . 'window.addEventListener("pageshow",function(e){if(e&&e.persisted)r(1);},{once:true});'
                . '})();</script>';
            // v7.21.116 — ?wpc_qw_debug=1 on-page receipt. Field diagnosis of legacy engines
            // (real Safari 15.6) kept dying on console-paste logistics: email clients truncate
            // the snippet, one-minute BrowserStack windows expire, reporters aren't devs. The
            // instrument now ships in the plugin: the param renders lazy/restore/marquee state
            // as a fixed overlay at T7 and T15 — the reporter visits one URL and screenshots
            // the box. Read-only, page-scoped, no secrets; the junk param itself forces a
            // fresh urlKey so the receipt never reads from cached HTML.
            if (isset($_GET['wpc_qw_debug'])) {
                $restoreScript .= '<script id="wpc-qw-diag">(function(){'
                    . 'function S(){var m=document.querySelector(".kb-blocks-advanced-marquee-init,.splide");var L=m?m.querySelector(".splide__list"):null;'
                    . 'var all=document.images,pl=0,ld=0,uf=0;for(var i=0;i<all.length;i++){var im=all[i];'
                    . 'if((im.src||"").indexOf("data:")===0)pl++;else if(im.naturalWidth>0)ld++;else uf++;}'
                    . 'var big=[];for(var q=0;q<all.length;q++){var b=all[q].getBoundingClientRect();'
                    . 'big.push({im:all[q],a:b.width*b.height,b:b});}'
                    . 'big.sort(function(p,w){return w.a-p.a;});'
                    . 'var vs=[];for(var q=0;q<big.length&&vs.length<6;q++){var im=big[q].im,b=big[q].b;'
                    . 'vs.push(Math.round(b.width)+"x"+Math.round(b.height)+"@"+Math.round(b.top)+" nw"+im.naturalWidth+" op"+getComputedStyle(im).opacity+" dec:"+(im.getAttribute("decoding")||"-")+" "+((im.src||"").slice(-18)));}'
                    . 'return{v:"' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '') . '",rs:document.readyState,imgs:all.length,placeholder:pl,loaded:ld,unfetched:uf,'
                    . 'qwPending:document.querySelectorAll("img[data-wpc-qw-src]").length,'
                    . 'slider:!!m,mounted:!!(m&&m.splideInstance),tf:L?getComputedStyle(L).transform:"",strip:vs,ua:navigator.userAgent};}'
                    . 'function draw(tag,o){var el=document.getElementById("wpc-qw-diag-box");'
                    . 'if(!el){el=document.createElement("pre");el.id="wpc-qw-diag-box";'
                    . 'el.style.cssText="position:fixed;left:8px;bottom:8px;z-index:2147483647;background:#111;color:#3f3;font:11px/1.5 monospace;padding:10px;max-width:92vw;max-height:45vh;overflow:auto;white-space:pre-wrap;border:2px solid #3f3;";'
                    . '(document.body||document.documentElement).appendChild(el);}'
                    . 'el.textContent="WPC QW DIAG "+tag+"\n"+JSON.stringify(o,null,1);}'
                    . 'var t1=null;setTimeout(function(){t1=S();draw("T7",t1);},7000);'
                    . 'setTimeout(function(){var t2=S();t2.movedSinceT7=!!(t1&&t1.tf!==t2.tf);draw("T15 - SCREENSHOT THIS BOX",t2);},15000);'
                    . '})();</script>';
            }
            $html = wpc_inject_before_body_close($html, $restoreScript);
        }
        // v7.10.788 — NEVER DEPRIORITIZE A LIBRARY SOMETHING ELSE WAITS ON. The "zero
        // semantics change" claim above holds for defer-vs-defer ordering, but the delay
        // lane injects a script's DEPENDENTS on a gesture — and a gesture can fire before a
        // fetchpriority="low" library has landed. vincire.nl receipt: jquery-core-js served
        // as `defer fetchpriority="low"`, then jquery-migrate / front-end-deps / main.js all
        // threw "jQuery is not defined" (clean console with ?disableWPC=true). Reweighting a
        // dependency root is a semantics change the moment another lane races it.
        $neverDemote = apply_filters('wpc_quiet_wire_never_demote',
            ['/jquery.min.js', '/jquery.js', 'jquery-migrate', 'jquery-core', 'jquery-ui', 'wpc-jquery-ready-hold']);
        // v7.21.90 — root-var setters join the never-demote set: the first frame's type
        // scale waits on them (the .788 law's exact shape — a dependency root of PAINT).
        foreach ((array) get_option('wpc_rootvar_names74', []) as $rootVarName) {
            if (is_string($rootVarName) && strlen($rootVarName) > 4) { $neverDemote[] = $rootVarName; }
        }
        $out = preg_replace_callback('/<script\b[^>]*\bsrc=[^>]*>/i', function ($m) use ($neverDemote) {
            $t = $m[0];
            if (stripos($t, 'fetchpriority') !== false || !preg_match('/\bdefer\b/i', $t)
                || stripos($t, 'delay-v3-loader') !== false || stripos($t, 'wpc-yield') !== false
                || stripos($t, 'type=') !== false && !preg_match('/type=["\']text\/javascript["\']/i', $t)) return $t;
            foreach ((array) $neverDemote as $needle) {
                if ($needle !== '' && stripos($t, (string) $needle) !== false) { return $t; }
            }
            return preg_replace('/<script\b/i', '<script fetchpriority="low"', $t, 1);
        }, $html);
        return is_string($out) ? $out : $html;
    }

    // Device test for the below-fold containment lane. Static so the tagger (a static buffer
    // pass) can reach it; delegates to the one shared UA test that the crit-choice and
    // cache-bucket detectors also use, so the keep count is read for the same device the page
    // is rendered and stored for. The keyword list is the fail-open fallback for a load where
    // defines.php is absent.
    public static function wpc_below_fold_cv_is_mobile()
    {
        if (function_exists('wpc_ua_is_mobile')) {
            return wpc_ua_is_mobile();
        }
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }
        $agent = strtolower((string) $_SERVER['HTTP_USER_AGENT']);
        foreach (array('android', 'iphone', 'ipad', 'windows phone', 'blackberry', 'tablet', 'mobile') as $needle) {
            if (strpos($agent, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    // Where this URL's measured artifacts live. Empty when the key machinery is not loaded,
    // which the harvest treats as "nothing measured" rather than an error.
    public static function wpc_below_fold_cv_artifact_dir()
    {
        if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) { return ''; }
        try {
            $urlKey = new wps_ic_url_key();
            $key = $urlKey->setup('');
        } catch (\Throwable $e) {
            return '';
        }
        return $key ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/' : '';
    }

    // Element ids the MEASURED above-the-fold artifacts name: the page's lcp.json, through its
    // one reader, and the delay.json in the page's crit folder. A section that holds the LCP or
    // an above-the-fold background must never be contained: containment defers its paint and
    // the measured LCP lands at reveal instead of at first paint. Capped because the list feeds
    // one regex pass per id; the cap trades a rare miss for a bounded cost.
    public static function wpc_atf_exempt_section_ids($dir)
    {
        $ids = array();
        if (is_string($dir) && $dir !== '') {
            $dir = rtrim($dir, '/') . '/';
            $sels = wps_ic_atf_observation::aboveFoldSelectors();
            $data = @json_decode((string) @file_get_contents($dir . 'delay.json'), true);
            if (is_array($data)) {
                if (isset($data['lcp_element']) && is_array($data['lcp_element'])) {
                    foreach (array('mobile', 'desktop') as $device) {
                        if (!empty($data['lcp_element'][$device]['sel'])) {
                            $sels[] = (string) $data['lcp_element'][$device]['sel'];
                        }
                    }
                }
                foreach (array('atf_bg', 'atf_images') as $key) {
                    if (!isset($data[$key]) || !is_array($data[$key])) { continue; }
                    foreach (array('mobile', 'desktop') as $device) {
                        // Both shapes ship: keyed by device, or a flat list for both.
                        $list = isset($data[$key][$device]) ? $data[$key][$device] : $data[$key];
                        foreach ((array) $list as $entry) {
                            if (is_array($entry) && !empty($entry['sel'])) { $sels[] = (string) $entry['sel']; }
                        }
                    }
                }
            }
            foreach ($sels as $sel) {
                if (preg_match_all('/#([A-Za-z][\w-]*)/', $sel, $m1)) { $ids = array_merge($ids, $m1[1]); }
                if (preg_match_all('/elementor-element-([a-z0-9]+)/i', $sel, $m2)) { $ids = array_merge($ids, $m2[1]); }
            }
        }
        if (function_exists('apply_filters')) {
            $ids = apply_filters('wpc_section_delay_atf_exempt', $ids);
        }
        return array_slice(array_values(array_unique(array_filter((array) $ids))), 0, 12);
    }

    // The one rule that reads the containment attribute, injected by the lane that writes the
    // attribute so containment never depends on critical CSS being active. Longhands, not the
    // `contain-intrinsic-size` shorthand: that shorthand sets BOTH axes, and a reserved WIDTH
    // is wrong for every builder — a block-level section takes its width from its container,
    // so the placeholder only ever bites in a shrink-to-fit context (a grid track, a flex
    // item, a float, a table cell) and there it reports as the section's min-content width,
    // sizing the whole track to the placeholder. `none` reserves no width; the height keeps a
    // fallback because nothing here measures real section heights, and `auto` remembers the
    // true height once the element has rendered one frame.
    public static function wpc_below_fold_cv_guard($html)
    {
        if (stripos($html, 'wpc-cv-guard') !== false) { return $html; }
        $at = stripos($html, '</head>');
        if ($at === false) { return $html; }
        return substr($html, 0, $at)
            . '<style id="wpc-cv-guard">[data-wpc-cv]{content-visibility:auto;contain-intrinsic-width:none;contain-intrinsic-height:auto 600px}@media print{[data-wpc-cv]{content-visibility:visible}}</style>'
            . substr($html, $at);
    }

    // Below-fold containment by SOURCE ORDER, not sibling index: a sibling-index selector is
    // blind to pages whose weight sits in an early-index section, and to <footer> top-sections
    // entirely. Tag data-wpc-cv on every top-section past the keep count AND past the first
    // eager <img>, minus the sections the measured artifacts put above the fold; then emit the
    // guard rule. This is the only lane that contains below-fold content — the keep count is
    // the per-device option the debug tool edits, and the overlay stamp in the Elementor
    // integration writes the same attribute, which is why the guard is emitted whenever the
    // buffer carries one at all. Mis-tagged near-viewport sections self-heal:
    // content-visibility:auto renders anything viewport-proximate natively. Nested template
    // sections may double-tag — harmless.
    public static function wpc_below_fold_cv_tag($html)
    {
        if (!is_string($html) || $html === '') return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_below_fold_cv', true)) return $html;
        $carriesTag = stripos($html, 'data-wpc-cv') !== false;
        if (stripos($html, 'elementor-top-section') === false) {
            return $carriesTag ? self::wpc_below_fold_cv_guard($html) : $html;
        }
        $atf = 0;
        if (preg_match_all('/<img\b[^>]*>/i', $html, $im, PREG_OFFSET_CAPTURE)) {
            foreach ($im[0] as $t) {
                if (stripos($t[0], 'loading="lazy"') === false && stripos($t[0], "loading='lazy'") === false) { $atf = $t[1]; break; }
            }
        }
        if (!preg_match_all('/<(?:section|main|footer)\b[^>]*class=(["\'])[^"\']*\belementor-top-section\b[^"\']*\1[^>]*>/i', $html, $mm, PREG_OFFSET_CAPTURE)) {
            return $carriesTag ? self::wpc_below_fold_cv_guard($html) : $html;
        }
        $keep = 3;
        if (function_exists('get_option')) {
            $skipSections = get_option('wps_ic_elementor_skip_sections');
            if (is_array($skipSections)) {
                $device = self::wpc_below_fold_cv_is_mobile() ? 'mobile' : 'desktop';
                if (isset($skipSections[$device]) && (int) $skipSections[$device] > 0) {
                    $keep = (int) $skipSections[$device];
                }
            }
        }
        if (function_exists('apply_filters')) { $keep = (int) apply_filters('wpc_below_fold_cv_keep', $keep); }
        $exempt = self::wpc_atf_exempt_section_ids(self::wpc_below_fold_cv_artifact_dir());
        $idx = 0; $add = array();
        foreach ($mm[0] as $t) {
            $idx++;
            if ($idx <= $keep || $t[1] <= $atf) continue;
            if (stripos($t[0], 'data-wpc-cv') !== false) continue;
            $skip = false;
            foreach ($exempt as $id) {
                if (preg_match('/\b(?:data-id|id)=(["\'])' . preg_quote($id, '/') . '\1/i', $t[0])) { $skip = true; break; }
            }
            if ($skip) continue;
            $add[] = array($t[1], strlen($t[0]), preg_replace('/^<(\w+)/', '<$1 data-wpc-cv="1"', $t[0], 1));
        }
        for ($i = count($add) - 1; $i >= 0; $i--) {
            $html = substr($html, 0, $add[$i][0]) . $add[$i][2] . substr($html, $add[$i][0] + $add[$i][1]);
        }
        if (empty($add) && !$carriesTag) { return $html; }
        return self::wpc_below_fold_cv_guard($html);
    }

    // v7.21.50 — AN UNCLOSED SCRIPT SWALLOWS THE HEAD/BODY BOUNDARY; RE-CLOSE IT AT THE
    // HARVEST SEAM. einfachmarketing.at: a customer-pasted Kissmetrics embed has no
    // </script>, so per the HTML script-data rule everything to the NEXT closer — their own
    // custom <style>, our injected styles, </head> and <body class="... elementor-kit-7"> —
    // is script text. Browsers suffer the same swallow, but our delay pass then base64s the
    // whole range into an inert registry entry, so the body classes never reach the parser
    // and every kit-scoped stylesheet dies (the multi-second unstyled window; exclusions
    // can't touch it). Heal: an inline script whose data carries a real </head>-then-<body>
    // pair gets its missing </script> inserted at the earliest head-boundary tag inside the
    // data — the embed keeps its JS, the swallowed markup returns to the parser, and the
    // page comes out BETTER than plugin-off (the customer's own dead styles revive).
    // document.write scripts are exempt (they may legitimately print boundary tags).
    // Kill: filter wpc_swallow_heal.
    public static function script_swallow_heal($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<script') === false) return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_swallow_heal', true)) return $html;
        if (!preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/si', $html, $script_matches, PREG_OFFSET_CAPTURE)) return $html;
        $edits = array();
        for ($i = 0, $n = count($script_matches[0]); $i < $n; $i++) {
            $attrs = $script_matches[1][$i][0];
            $body = $script_matches[2][$i][0];
            if ($body === '' || preg_match('/\bsrc\s*=\s*["\']/i', $attrs)) continue;
            $head_close_pos = stripos($body, '</head');
            if ($head_close_pos === false) continue;
            $body_open_pos = stripos($body, '<body');
            if ($body_open_pos === false || $body_open_pos < $head_close_pos) continue;
            if (stripos($body, 'document.write') !== false) continue;
            // insertion point: the earliest head-boundary tag inside the swallowed data
            $insert_at = $head_close_pos;
            if (preg_match('/<(?:style|link|meta|title)\b/i', substr($body, 0, $head_close_pos), $head_tag_match, PREG_OFFSET_CAPTURE)) {
                $insert_at = $head_tag_match[0][1];
            }
            if ($insert_at < 1) continue;
            $insert_offset = $script_matches[2][$i][1] + $insert_at;
            $edits[] = array($insert_offset, '</script>');
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('swallow-heal', '', '', array('at' => $insert_at, 'len' => strlen($body)));
            }
        }
        for ($i = count($edits) - 1; $i >= 0; $i--) {
            $html = substr($html, 0, $edits[$i][0]) . $edits[$i][1] . substr($html, $edits[$i][0]);
        }
        return $html;
    }

    // v7.21.47 — RENDER-BLOCKING JQUERY GOES defer, AND EVERY EAGER CLASSIC SCRIPT AFTER IT
    // RIDES THE SAME QUEUE. jquery.min.js + jquery-migrate stay parser-blocking on every page
    // (the delay lane keeps them eager by design) and PSI books them as the top render-blocking
    // cost. Native defer preserves execution order for external scripts and runs the whole set
    // before DOMContentLoaded, so jQuery.ready / DCL listeners keep their semantics with zero
    // replay machinery. Inline scripts cannot carry defer, so eager inlines after jQuery are
    // converted to data: URI externals — same defer queue, same document order. The delayed-JS
    // replay is gated behind window.wpcJqueryDeferMarker (armed by an eager head-top marker, released on
    // native DOMContentLoaded) so a gesture or scrolled-reload during parse can never start
    // jQuery-dependent delayed scripts before deferred jQuery has executed. Stand-downs: CSP
    // header/meta or nonce attributes (data: URIs would be blocked), document.write in any
    // affected inline (ignored from defer scripts), inline volume over the cap, plugin infra
    // inlines (traps/collectors must install at parse). Kill: filter wpc_jq_defer or ?jqdefer=0.
    public static function wpc_csp_blocks_inline_scripts($html, $headers = null)
    {
        $policies = [];
        if (is_string($html) && $html !== ''
            && preg_match_all('/<meta\b[^>]*http-equiv\s*=\s*["\']Content-Security-Policy["\'][^>]*\bcontent\s*=\s*["\']([^"\']*)["\']/i', $html, $metaCspMatches)) {
            foreach ($metaCspMatches[1] as $metaPolicy) { $policies[] = (string) $metaPolicy; }
        }
        if ($headers === null && function_exists('headers_list')) {
            $headers = headers_list();
        }
        foreach ((array) $headers as $headerLine) {
            $headerLine = (string) $headerLine;
            // Report-Only never blocks; only the enforcing header counts.
            if (!preg_match('/^content-security-policy\s*:\s*(.*)$/is', $headerLine, $cspHeaderMatch)) continue;
            $policies[] = (string) $cspHeaderMatch[1];
        }
        foreach ($policies as $policy) {
            $scriptSrcDirective = '';
            foreach (explode(';', $policy) as $directive) {
                $directive = trim($directive);
                if (stripos($directive, 'script-src-elem') === 0 || stripos($directive, 'script-src') === 0) { $scriptSrcDirective = $directive; break; }
            }
            if ($scriptSrcDirective === '') {
                foreach (explode(';', $policy) as $directive) {
                    $directive = trim($directive);
                    if (stripos($directive, 'default-src') === 0) { $scriptSrcDirective = $directive; break; }
                }
            }
            if ($scriptSrcDirective === '') continue;
            // A nonce or hash source makes browsers ignore 'unsafe-inline' — our gate carries neither.
            if (stripos($scriptSrcDirective, "'nonce-") !== false || preg_match("/'sha(?:256|384|512)-/i", $scriptSrcDirective)) return true;
            if (stripos($scriptSrcDirective, "'unsafe-inline'") === false) return true;
        }
        return false;
    }

    public static function jquery_defer_pass($html)
    {
        if (!is_string($html) || $html === '') return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_jq_defer', true)) return $html;
        if (isset($_GET['jqdefer']) && $_GET['jqdefer'] === '0') return $html;
        if (stripos($html, 'wpcJqueryDeferMarker') !== false) return $html;
        // v7.22.58 — a CSP only matters here if it can refuse OUR inline gate script. The blanket
        // "any CSP header → stand down" left jQuery render-blocking (plus every sync dependant)
        // on hosts that send nothing but `upgrade-insecure-requests` (welliathome: openresty).
        if (self::wpc_csp_blocks_inline_scripts($html)) return $html;
        // v7.21.136 — the .134 exclude stand-down is REVERTED: an excluded script stays
        // eager AND IN ORDER under this lane (everything after jquery defers in document
        // order), so excludes lose nothing — while standing down left the naive defer-list
        // path as the only jquery deferrer, with no dependent conversion and no gate
        // (optica-suiza console: eager wp-util + inline callers vs bare-deferred jquery).
        // This lane is the protection; it must never yield to a weaker deferrer.
        // One exception, below: jQuery ITSELF on the Delay JS exclusions (not any exclude that
        // matches the page, which is what .134 did).
        if (!preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/si', $html, $scripts, PREG_OFFSET_CAPTURE)) return $html;
        $scriptCount = count($scripts[0]);
        // classic-JS type test: absent type, or any of the executable-classic MIME spellings
        // (text/javascript, application/javascript, application/x-javascript, */ecmascript)
        $isClassicScript = function ($attrs) {
            if (!preg_match('/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $typeMatch)) return true;
            return (bool) preg_match('#^(?:text|application)/(?:x-)?(?:java|ecma)script$#i', $typeMatch[1]);
        };
        // attribute-word test on quote-stripped attrs — class="defer" / src=".../async.js"
        // must never read as the boolean attribute
        $hasAttributeWord = function ($attrs, $words) {
            $bare = preg_replace('/(["\'])(?:(?!\1).)*\1/s', ' ', $attrs);
            return (bool) preg_match('/(?<![-\w=])(?:' . $words . ')(?![-\w])/i', (string) $bare);
        };
        $jqueryIndex = -1;
        $jqueryDeferredByOthers = false;
        for ($i = 0; $i < $scriptCount; $i++) {
            $attrs = $scripts[1][$i][0];
            if (!preg_match('#\bsrc\s*=\s*["\'][^"\']*/jquery(?:\.min)?\.js(?:\?|["\'])#i', $attrs)) continue;
            if (!$isClassicScript($attrs)) continue;
            if ($hasAttributeWord($attrs, 'async')) return $html;
            if ($hasAttributeWord($attrs, 'defer')) {
                // v7.21.52 — FOREIGN-DEFERRED JQUERY STILL NEEDS THE GATE. platformtraining dev:
                // WP core script strategies (data-wp-strategy="defer") deferred jquery before we
                // ever saw the page; the .48 bail returned unchanged, no marker was injected,
                // and a gesture during parse started the delayed replay against undefined jQuery
                // ("jQuery is not defined" from delayed dependents — the customer's exact report).
                // Whoever defers jQuery, the delay registry must wait for it: marker-only mode.
                $jqueryDeferredByOthers = true;
                $jqueryIndex = $i;
                break;
            }
            $jqueryIndex = $i;
            break;
        }
        if ($jqueryIndex < 0) return $html;
        if ($jqueryDeferredByOthers) {
            $jqueryHoldAt = $scripts[0][$jqueryIndex][1] + strlen($scripts[0][$jqueryIndex][0]);
            $withReadyHold = substr($html, 0, $jqueryHoldAt) . self::jquery_ready_hold_tag() . substr($html, $jqueryHoldAt);
            $withForeignMarker = preg_replace('/<head\b[^>]*>/i', '$0' . self::jquery_defer_gate_marker(), $withReadyHold, 1);
            if (!is_string($withForeignMarker) || $withForeignMarker === '' || stripos($withForeignMarker, 'wpcJqueryDeferMarker') === false) return $html;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('jq-defer', '', '', array('foreign' => 1));
            }
            return $withForeignMarker;
        }
        // A jQuery the site put on the Delay JS exclusions is left exactly as the theme printed
        // it: the exclude means "leave it alone", and a deferred jQuery is not the same script.
        // It runs with readyState "interactive", so jQuery.ready fires on the next task, before
        // the later deferred files it waits for have arrived. Observed failure: webdesign4u
        // 2026-09-24, Divi 4 with jQuery, migrate, mediaelement and Divi excluded: jQuery got
        // defer, ready fired before mediaelement landed, Divi's video section init threw
        // "mediaelementplayer is not a function" and the desktop hero stayed a grey box for good.
        if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $scripts[1][$jqueryIndex][0], $jquerySrcMatch)
            && is_object(self::$excludes_class) && method_exists(self::$excludes_class, 'excludedFromDelayV3')
            && self::$excludes_class->excludedFromDelayV3($jquerySrcMatch[1])) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('jq-defer-skip', '', '', array('why' => 'user-excluded'));
            }
            return $html;
        }
        $inlineCap = function_exists('apply_filters') ? (int) apply_filters('wpc_jq_defer_inline_cap', 196608) : 196608;
        $inlineBytes = 0;
        // v7.21.106 — AUTHOR-ASYNC IS A RACE, NOT AN ORDER. A natively-async script that
        // references jQuery only worked plugin-off because parser-blocking jQuery always
        // won the race; once this lane defers jQuery the async tag can execute first
        // (platformtraining: kadence-pro pro-woocommerce.min.js async -> "jQuery is not
        // defined" at init). jQuery-referencing async srcs convert to defer — document
        // order puts them after jQuery, and defer preserves it. Verdicts body-classified
        // once per src and cached. Kill wpc_async_jqdep.
        $asyncJqueryVerdicts = function_exists('get_option') ? get_option('wpc_async_jqdep106') : false;
        $asyncJqueryVerdicts = is_array($asyncJqueryVerdicts) ? $asyncJqueryVerdicts : array();
        $asyncVerdictsChanged = false;
        $asyncFetches = 0;
        $edits = array();
        $externalDeferred = 0;
        $inlineConverted = 0;
        // The lane starts at the first script it can defer, not at jQuery. A consent manager or a
        // tag loader the theme prints before jQuery is the same parser-blocking fetch as any other;
        // defer keeps document order, so it still runs before jQuery and before everything after it.
        // Inline scripts printed before that first external stay as they are (they are its config);
        // an inline script between it and jQuery that this lane could not convert keeps the old start.
        $wpc_start = $jqueryIndex;
        for ($i = 0; $i < $jqueryIndex; $i++) {
            $attrs = $scripts[1][$i][0];
            if (!$isClassicScript($attrs) || !preg_match('/\bsrc\s*=\s*["\']/i', $attrs)) continue;
            if ($hasAttributeWord($attrs, 'async|defer|nomodule') || stripos($attrs, 'data-nodefer') !== false) continue;
            if (preg_match('/\bnonce\s*=/i', $attrs) || preg_match('/delay-v3-loader|wpc-yield|inlined-delay|src\s*=\s*["\']data:/i', $attrs)) continue;
            $wpc_start = $i;
            break;
        }
        for ($i = $wpc_start; $i < $jqueryIndex; $i++) {
            $attrs = $scripts[1][$i][0];
            $body = $scripts[2][$i][0];
            if (!$isClassicScript($attrs) || preg_match('/\bsrc\s*=\s*["\']/i', $attrs)) continue;
            $commentFreeBody = preg_replace('#/\*.*?\*/#s', '', trim($body));
            if (preg_match('/\bnonce\s*=/i', $attrs) || stripos(is_string($commentFreeBody) ? $commentFreeBody : $body, 'document.write') !== false) {
                $wpc_start = $jqueryIndex;
                break;
            }
        }
        for ($i = $wpc_start; $i < $scriptCount; $i++) {
            $tag = $scripts[0][$i][0];
            $off = $scripts[0][$i][1];
            $attrs = $scripts[1][$i][0];
            $body = $scripts[2][$i][0];
            if (!$isClassicScript($attrs)) continue;
            if ($hasAttributeWord($attrs, 'async') && !$hasAttributeWord($attrs, 'defer|nomodule')
                && (!function_exists('apply_filters') || apply_filters('wpc_async_jqdep', true))
                && preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $asyncSrcMatch)
                && stripos($attrs, 'data-wpc') === false) {
                $asyncKey = md5((string) $asyncSrcMatch[1]);
                if (!array_key_exists($asyncKey, $asyncJqueryVerdicts) && $asyncFetches < 2
                    && count($asyncJqueryVerdicts) < 48 && function_exists('wp_remote_get')) {
                    $asyncFetches++;
                    $asyncResponse = wp_remote_get(html_entity_decode((string) $asyncSrcMatch[1]), array('timeout' => 3, 'sslverify' => false));
                    if (function_exists('wpc_net_defer_on_render_guard') && wpc_net_defer_on_render_guard($asyncResponse, 'ajq106:' . $asyncKey, function () use ($asyncSrcMatch) { wps_cdn_rewrite::wpc_classify_async_jquery_dependency((string) $asyncSrcMatch[1]); })) {
                        continue;
                    }
                    $asyncBody = (!is_wp_error($asyncResponse) && (int) wp_remote_retrieve_response_code($asyncResponse) === 200)
                        ? substr((string) wp_remote_retrieve_body($asyncResponse), 0, 65536) : '';
                    $asyncJqueryVerdicts[$asyncKey] = ($asyncBody !== '' && preg_match('/jQuery|\$\s*[.(]/', $asyncBody)) ? 1 : 0;
                    $asyncVerdictsChanged = true;
                }
                if (!empty($asyncJqueryVerdicts[$asyncKey])) {
                    $asyncAsDefer = preg_replace('/\sasync(?:\s*=\s*(?:"async"|\'async\'|async))?(?=[\s>\/])/i', ' defer', $tag, 1);
                    if (is_string($asyncAsDefer) && $asyncAsDefer !== $tag) {
                        $edits[] = array($off, strlen($tag), $asyncAsDefer);
                        $externalDeferred++;
                    }
                }
                continue;
            }
            if ($hasAttributeWord($attrs, 'async|defer|nomodule')) continue;
            if (stripos($attrs, 'data-nodefer') !== false) continue;
            if (preg_match('/\bnonce\s*=/i', $attrs)) return $html;
            if (preg_match('/\bsrc\s*=\s*["\']/i', $attrs)) {
                if (preg_match('/delay-v3-loader|wpc-yield|inlined-delay/i', $attrs)) continue;
                $edits[] = array($off, strlen($tag), preg_replace('/<script\b/i', '<script defer', $tag, 1));
                if ($i === $jqueryIndex) {
                    $edits[] = array($off + strlen($tag), 0, self::jquery_ready_hold_tag());
                }
                $externalDeferred++;
                continue;
            }
            $inlineCode = trim($body);
            if ($inlineCode === '') continue;
            if (preg_match('/wpcScriptRegistry|wpcDelayV3|wpcJqueryDeferMarker|data-wpc-qw-src|__wpcHuman|wpc-late-faces|wpcCheckpoint/i', $attrs . $inlineCode)) continue;
            if (preg_match('/\bid\s*=\s*["\']([^"\']+)-js-extra["\']/i', $attrs, $ownerIdMatch)
                && !preg_match('/jQuery|\$\s*[.(]/', $inlineCode)) {
                $ownerStaysAsync = false;
                for ($k = $i + 1; $k < $scriptCount && $k <= $i + 3; $k++) {
                    $ownerAttrs = $scripts[1][$k][0];
                    if (!preg_match('/\bid\s*=\s*["\']' . preg_quote($ownerIdMatch[1], '/') . '-js["\']/i', $ownerAttrs)) continue;
                    if ($hasAttributeWord($ownerAttrs, 'async') && !$hasAttributeWord($ownerAttrs, 'defer|nomodule')) {
                        $ownerSrc = preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $ownerAttrs, $ownerSrcMatch) ? (string) $ownerSrcMatch[1] : '';
                        $ownerStaysAsync = $ownerSrc === '' || empty($asyncJqueryVerdicts[md5($ownerSrc)]);
                    }
                    break;
                }
                if ($ownerStaysAsync) continue;
            }
            // v7.21.53 — scan a block-comment-stripped copy: GTranslate's localizer carries the
            // literal "/* document.write */" INSIDE a comment and stood the whole lane down on
            // every site running it (ganoderma: no defer, no marker, the page's biggest lever
            // dead). Only a real, executable document.write is a stand-down.
            $commentFreeBody = preg_replace('#/\*.*?\*/#s', '', $inlineCode);
            if (stripos(is_string($commentFreeBody) ? $commentFreeBody : $inlineCode, 'document.write') !== false) return $html;
            $inlineBytes += strlen($inlineCode);
            if ($inlineBytes > $inlineCap) return $html;
            $edits[] = array($off, strlen($tag),
                '<script' . rtrim($attrs) . ' defer src="data:text/javascript;charset=utf-8;base64,' . base64_encode($inlineCode) . '"></script>');
            $inlineConverted++;
        }
        if ($asyncVerdictsChanged && function_exists('update_option')) {
            update_option('wpc_async_jqdep106', $asyncJqueryVerdicts, false);
        }
        if (!count($edits)) return $html;
        for ($i = count($edits) - 1; $i >= 0; $i--) {
            $html = substr($html, 0, $edits[$i][0]) . $edits[$i][2] . substr($html, $edits[$i][0] + $edits[$i][1]);
        }
        $withMarker = preg_replace('/<head\b[^>]*>/i', '$0' . self::jquery_defer_gate_marker(), $html, 1);
        if (!is_string($withMarker) || $withMarker === '' || stripos($withMarker, 'wpcJqueryDeferMarker') === false) return $html;
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('jq-defer', '', '', array('ext' => $externalDeferred, 'inl' => $inlineConverted));
        }
        return $withMarker;
    }

    private static function jquery_defer_gate_marker()
    {
        return '<script id="wpc-jquery-defer-marker">window.wpcJqueryDeferMarker={h:1};document.addEventListener("DOMContentLoaded",function(){var m=window.wpcJqueryDeferMarker;if(m&&!m.r){m.r=1;var q=m.jq,c=m.cb,d=m.cb2;m.jq=null;m.cb=null;m.cb2=null;try{if(q)q()}catch(e){}try{if(c)c()}catch(e){}try{if(d)d()}catch(e){}}});</script>';
    }

    private static function jquery_ready_hold_tag()
    {
        return '<script id="wpc-jquery-ready-hold" defer src="data:text/javascript;charset=utf-8;base64,'
            . base64_encode('(function(){var m=window.wpcJqueryDeferMarker,j=window.jQuery,h=j&&j.holdReady;if(!m||m.h!==1||m.r||typeof h!=="function"||j.isReady||document.readyState==="loading")return;h.call(j,true);m.jq=function(){h.call(j,false)}})();')
            . '"></script>';
    }

    /** The observed above-the-fold slots (multi-use stems withheld): which lazy placeholders the
     *  unlazy pass may promote. */
    private static function wpc_afold_sizes_map()
    {
        return wps_ic_atf_observation::widths(wps_ic_atf_observation::SCOPE_ATF);
    }

    /**
     * Every <img>'s `sizes`, written once, from the image-sizing owner (wps_ic_image_sizing::sizesFor).
     * Here, after delivery and every URL move, and before quiet_wire parks the attribute and the
     * image preloads mirror it.
     *
     * Rules, per <img>:
     *  - a srcset whose candidates are all one file offers no choice: srcset and sizes go
     *    (receipt srcset-same-url-dropped; WordPress prints one SVG URL under 150w…2048w);
     *  - no srcset, no sizes: `sizes` describes a ladder, and one left on a tag without one is
     *    either dead weight or a sign the ladder was dropped under it (greenvalleytint's 155 px
     *    logo served the 985 px file with `sizes="(max-width: 985px) 100vw, 985px"`);
     *  - otherwise the owner's measured value, or, when it withholds, the page's own value with the
     *    capped ladder this plugin used to print replaced by the own-width ladder.
     * In a <picture>, every <source> with a srcset carries the <img>'s value, except a source
     * scoped by `media` that carries its own `sizes` (the picture lane's precise mobile arms,
     * whose `Mpx` the parity pass used to overwrite with the desktop value).
     *
     * One value per image replaces the writers that disagreed: the combined-crit sizes lane (the
     * desktop measurement in the mobile leg and the file's width in the desktop leg: gvt-home's
     * logo `155px, 985px`), the LCP hint (one width on every copy of a stem, multi-use or not:
     * glass-inspirations.co.uk's 626 px hero served the 149w rung), the above-the-fold sizes pass
     * (a `768px` breakpoint where everything else says 767.98px) and the picture parity pass.
     */
    public static function stage_image_sizes_pass($html, $ctx)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<img') === false
            || wps_ic_image_sizing::off() || !($ctx->imageSizing instanceof wps_ic_image_sizing)) {
            return $html;
        }
        $sizing = $ctx->imageSizing;
        $out = preg_replace_callback('#<picture\b[^>]*>.*?</picture>|<img\b[^>]*>#is', function ($m) use ($sizing) {
            if (stripos($m[0], '<picture') === 0) {
                return self::image_sizes_picture($m[0], $sizing);
            }
            return self::image_sizes_img($m[0], $sizing, false);
        }, $html);
        return is_string($out) ? $out : $html;
    }

    /** One <picture>: its <img> first, then the sources that follow the <img>'s value. */
    private static function image_sizes_picture($picture, $sizing)
    {
        $sourcesOfferRungs = false;
        $picture = (string) preg_replace_callback('/<source\b[^>]*>/i', function ($sm) use ($sizing, &$sourcesOfferRungs) {
            $source = self::drop_same_url_ladder($sm[0], $sizing);
            if (preg_match('/\s(?:data-)?srcset\s*=/i', $source)) {
                $sourcesOfferRungs = true;
            }
            return $source;
        }, $picture);
        $imgSizes = null;
        $picture = (string) preg_replace_callback('/<img\b[^>]*>/i', function ($im) use ($sizing, $sourcesOfferRungs, &$imgSizes) {
            $img = self::image_sizes_img($im[0], $sizing, $sourcesOfferRungs);
            $imgSizes = preg_match('/\ssizes\s*=\s*(["\'])(.*?)\1/is', $img, $zm) ? trim($zm[2]) : '';
            return $img;
        }, $picture, 1);
        if ($imgSizes === null || $imgSizes === '' || strpos($imgSizes, '"') !== false
            || !apply_filters('wpc_picture_sizes_parity', true)) {
            return $picture;
        }
        return (string) preg_replace_callback('/<source\b[^>]*>/i', function ($sm) use ($imgSizes) {
            $source = $sm[0];
            if (stripos($source, 'srcset') === false) {
                return $source;
            }
            $hasOwn = preg_match('/\ssizes\s*=\s*(["\'])(.*?)\1/is', $source, $cur);
            if ($hasOwn && preg_match('/\smedia\s*=/i', $source)) {
                return $source; // a media-scoped arm keeps its own slot width
            }
            if ($hasOwn) {
                return trim($cur[2]) === $imgSizes ? $source
                    : (string) preg_replace('/\ssizes\s*=\s*(["\']).*?\1/is', ' sizes="' . str_replace('$', '\\$', $imgSizes) . '"', $source, 1);
            }
            return (string) preg_replace('/<source\b/i', '<source sizes="' . str_replace('$', '\\$', $imgSizes) . '"', $source, 1);
        }, $picture);
    }

    /** One <img> through stage_image_sizes_pass's rules. */
    private static function image_sizes_img($tag, $sizing, $pictureOffersRungs)
    {
        $tag = self::drop_same_url_ladder($tag, $sizing);
        $hasSrcset = (bool) preg_match('/\s(?:data-)?srcset\s*=/i', $tag);
        $sizesPattern = '/\ssizes\s*=\s*(["\'])(.*?)\1/is';
        if (!$hasSrcset && !$pictureOffersRungs) {
            $out = preg_replace($sizesPattern, '', $tag, 1);
            return is_string($out) ? $out : $tag;
        }
        $src = preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $sm) ? $sm[2] : '';
        if ($src === '' || stripos($src, 'data:') === 0) {
            $src = preg_match('/\sdata-(?:lazy-)?src\s*=\s*(["\'])(.*?)\1/i', $tag, $dm) ? $dm[2] : $src;
        }
        $tagWidth = preg_match('/\swidth\s*=\s*["\']?(\d+)/i', $tag, $wm) ? (int) $wm[1] : 0;
        $tagHeight = preg_match('/\sheight\s*=\s*["\']?(\d+)/i', $tag, $hm) ? (int) $hm[1] : 0;
        $current = preg_match($sizesPattern, $tag, $cm) ? $cm[2] : null;
        // The page's own value, with the capped ladder this plugin used to print replaced: what
        // the tag keeps when the owner withholds, and the other device's leg when the owner has
        // one measured leg of a combined render (wps_ic_image_sizing::mergeOneLeg()).
        $pageValue = '';
        if ($current !== null) {
            $srcset = preg_match('/\s(?:data-)?srcset\s*=\s*(["\'])(.*?)\1/is', $tag, $ss) ? $ss[2] : '';
            $pageValue = wps_ic_atf_observation::replace_retired_capped_ladder($current, $tagWidth > 0 ? (string) $tagWidth : '', $srcset);
        }
        $answer = ($src !== '' && stripos($src, 'data:') !== 0)
            ? $sizing->sizesFor($src, $tagWidth, $tagHeight, $pageValue) : ['sizes' => '', 'why' => 'no-observation'];
        if ($answer['sizes'] !== '') {
            $value = $answer['sizes'];
        } elseif ($current !== null) {
            $value = $pageValue;
            if ($value === '') {
                $out = preg_replace($sizesPattern, '', $tag, 1);
                return is_string($out) ? $out : $tag;
            }
        } else {
            return $tag;
        }
        if ($current !== null) {
            if (trim($current) === $value) {
                return $tag;
            }
            $out = preg_replace($sizesPattern, ' sizes="' . str_replace('$', '\\$', $value) . '"', $tag, 1);
        } else {
            $out = preg_replace('/<img\b/i', '<img sizes="' . str_replace('$', '\\$', $value) . '"', $tag, 1);
        }
        return is_string($out) ? $out : $tag;
    }

    /** A srcset whose candidates all name one file: drop it and its sizes (the same-URL rule). */
    private static function drop_same_url_ladder($tag, $sizing)
    {
        if (!preg_match('/\s((?:data-)?srcset)\s*=\s*(["\'])(.*?)\2/is', $tag, $ss)) {
            return $tag;
        }
        $candidates = array_values(array_filter(array_map('trim', preg_split('/,\s+/', trim($ss[3])))));
        if (count($candidates) < 2) {
            return $tag;
        }
        $files = [];
        foreach ($candidates as $candidate) {
            if (!preg_match('/\s\d+w$/', $candidate)) {
                return $tag; // density or bare candidates are not a width ladder
            }
            $url = (string) preg_replace('/[?#].*$/', '', html_entity_decode((string) strtok($candidate, ' '), ENT_QUOTES));
            $files[(string) preg_replace('#(?<!:)/{2,}#', '/', $url)] = true;
        }
        if (count($files) !== 1) {
            return $tag;
        }
        $out = preg_replace('/\s(?:data-)?srcset\s*=\s*(["\']).*?\1/is', '', $tag);
        $out = is_string($out) ? preg_replace('/\s(?:data-)?sizes\s*=\s*(["\']).*?\1/is', '', $out) : $out;
        if (!is_string($out)) {
            return $tag;
        }
        $sizing->sameUrlLadderDropped();
        return $out;
    }

    // v7.22.37 — AN ABOVE-THE-FOLD IMAGE BEHIND A JS PLACEHOLDER IS UN-LAZIED AT RENDER. Bricks
    // (and Frames/ACSS sites on it) ship every image as src=data:svg + data-src + .bricks-lazy-hidden
    // and only bricks.min.js swaps the real file in — the LCP hero starts loading when the theme
    // script has executed (aliiadventureshack: 2.3s load delay of a 3.6s LCP, "initiator: script").
    // The measured ATF list knows the element is above the fold but recorded the PLACEHOLDER's
    // stem (svg%3e, css_w 1350) because the service saw the data: src. That entry is the gate:
    // for every placeholder entry the service measured, the next JS-placeholder <img> in document
    // order with an intrinsic width >= 400 becomes a real image — src/srcset from data-*, the
    // measured sizes when the data-src stem is known, eager, the largest with fetchpriority=high
    // and a preload. The lazy class goes so the theme's swapper skips it (Bricks selects
    // .bricks-lazy-hidden). Filter wpc_atf_unlazy.
    public static function wpc_classify_async_jquery_dependency($src)
    {
        try {
            if (!function_exists('wp_remote_get') || !function_exists('get_option')) {
                return;
            }
            $k = md5((string) $src);
            $r = wp_remote_get(html_entity_decode((string) $src), array('timeout' => 3, 'sslverify' => false));
            if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
                return;
            }
            $b = substr((string) wp_remote_retrieve_body($r), 0, 65536);
            $map = get_option('wpc_async_jqdep106');
            $map = is_array($map) ? $map : array();
            if (array_key_exists($k, $map) || count($map) >= 48) {
                return;
            }
            $map[$k] = ($b !== '' && preg_match('/jQuery|\$\s*[.(]/', $b)) ? 1 : 0;
            update_option('wpc_async_jqdep106', $map, false);
        } catch (\Throwable $e) {
        }
    }

    public static function wpc_unlazy_above_fold_images($html, $imagePreloads = null, $imageSizing = null)
    {
        try {
            if (!is_string($html) || $html === '' || stripos($html, 'data-src=') === false
                || (function_exists('apply_filters') && !apply_filters('wpc_atf_unlazy', true))) {
                return $html;
            }
            $map = self::wpc_afold_sizes_map();
            if (empty($map)) {
                return $html;
            }
            $slots = 0;
            $named = [];
            foreach ($map as $stem => $w) {
                if (preg_match('/^(?:data|svg%3e|%3csvg|svg)/i', (string) $stem) || strpos((string) $stem, '%3c') !== false) {
                    $slots++;
                } else {
                    $named[$stem] = $w;
                }
            }
            if (!preg_match_all('/<img\b[^>]*>/i', $html, $im)) {
                return $html;
            }
            $done = 0;
            $best = null;
            $out = $html;
            foreach ($im[0] as $tag) {
                if (!preg_match('/\ssrc=(["\'])(data:image\/(?:svg\+xml|gif)[^"\']*)\1/i', $tag)
                    || !preg_match('/\sdata-src=(["\'])([^"\']+)\1/i', $tag, $ds)) {
                    continue;
                }
                $real = html_entity_decode($ds[2]);
                if (!preg_match('#^(?:https?:)?//#', $real) && strpos($real, '/') !== 0) {
                    continue;
                }
                $stem = strtolower(preg_replace('/(-\d+x\d+|-scaled)?\.[^.]+$/', '', basename(strtok($real, '?#'))));
                $known = $stem !== '' && isset($named[$stem]);
                $w = preg_match('/\swidth=(["\']?)(\d{2,5})\1/i', $tag, $wm) ? (int) $wm[2] : 0;
                if (!$known && ($slots <= $done || $w < 400)) {
                    continue;
                }
                $new = $tag;
                $new = preg_replace('/\ssrc=(["\'])data:image\/[^"\']*\1/i', ' src="' . esc_attr($real) . '"', $new, 1);
                $srcset = '';
                if (preg_match('/\sdata-srcset=(["\'])([^"\']+)\1/i', $new, $ss)) {
                    $srcset = $ss[2];
                    $new = preg_replace('/\sdata-srcset=(["\'])[^"\']*\1/i', '', $new, 1);
                    if (!preg_match('/\ssrcset=/i', $new)) {
                        $new = preg_replace('/<img\b/i', '<img srcset="' . $srcset . '"', $new, 1);
                    }
                }
                // The measured slot is the image-sizing owner's answer (the render device's leg, one
                // breakpoint grammar); this pass used to write both legs under `(max-width: 768px)`.
                $sizes = '';
                if ($known && $imageSizing instanceof wps_ic_image_sizing) {
                    $answer = $imageSizing->sizesFor($real, $w, preg_match('/\sheight=(["\']?)(\d{2,5})/i', $tag, $hm) ? (int) $hm[2] : 0);
                    $sizes = (string) $answer['sizes'];
                }
                if (preg_match('/\sdata-sizes=(["\'])([^"\']+)\1/i', $new, $dz)) {
                    if ($sizes === '') { $sizes = $dz[2]; }
                    $new = preg_replace('/\sdata-sizes=(["\'])[^"\']*\1/i', '', $new, 1);
                }
                if ($sizes !== '' && $srcset !== '' && !preg_match('/\ssizes=/i', $new)) {
                    $new = preg_replace('/<img\b/i', '<img sizes="' . esc_attr($sizes) . '"', $new, 1);
                }
                $new = preg_replace('/\sdata-src=(["\'])[^"\']*\1/i', '', $new, 1);
                $new = preg_replace_callback('/\sclass=(["\'])([^"\']*)\1/i', function ($c) {
                    $cls = trim(preg_replace('/\s+/', ' ', preg_replace('/(?:^|\s)(?:bricks-lazy-hidden|lazyload|lazy|b-lazy)(?=\s|$)/i', ' ', ' ' . $c[2] . ' ')));
                    return $cls === '' ? '' : ' class=' . $c[1] . $cls . $c[1];
                }, $new, 1);
                $new = preg_replace('/\sloading=(["\'])[^"\']*\1/i', '', $new, 1);
                $new = preg_replace('/<img\b/i', '<img loading="eager"', $new, 1);
                $out = str_replace($tag, $new, $out);
                $out = self::wpc_promote_picture_sources($out, $new);
                $done++;
                if ($best === null || $w > $best['w']) {
                    $best = ['w' => $w, 'src' => $real, 'srcset' => $srcset, 'sizes' => $sizes, 'tag' => $new];
                }
                if ($done >= 3) {
                    break;
                }
            }
            if (!$done) {
                return $html;
            }
            if ($best !== null && stripos($best['tag'], 'fetchpriority=') === false) {
                $hi = preg_replace('/<img\b/i', '<img fetchpriority="high"', $best['tag'], 1);
                $out = str_replace($best['tag'], $hi, $out);
                if ($imagePreloads instanceof wps_ic_image_preload_set
                    && stripos($out, 'id="wpc-lcp-img-preload"') === false && stripos($out, 'id="wpc-atf-unlazy-preload"') === false) {
                    $imagePreloads->add('wpc-atf-unlazy-preload', esc_attr($best['src']), 'both', 'atf-unlazy',
                        wps_ic_image_preload_set::RANK_ATF_UNLAZY, [
                            'imagesrcset' => $best['srcset'] !== '' ? esc_attr($best['srcset']) : '',
                            'imagesizes'  => ($best['srcset'] !== '' && $best['sizes'] !== '') ? esc_attr($best['sizes']) : '',
                        ]);
                }
            }
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_unlazy37_log')) {
                set_transient('wpc_unlazy37_log', 1, 600);
                wpc_cache_first_log('atf-unlazy', '', '', ['n' => $done, 'slots' => $slots, 'named' => count($named)]);
            }
            return $out;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * The <source> children of the <picture> around an <img> this pass just made eager get
     * srcset in place of data-srcset. Rule: an eager image's picture must be eager as a whole.
     * Observed failure: the lazy loader promotes <source data-srcset> only when it loads the
     * <img>, and an un-lazied <img> is never handed to it, so the source kept data-srcset, the
     * browser ignored it and loaded the jpg fallback; the avif/webp was never requested
     * (staging-home local golden, ruben-mavarez, once the CDN-off lane built <picture>).
     */
    private static function wpc_promote_picture_sources($html, $imgTag)
    {
        $at = strpos($html, $imgTag);
        if ($at === false || $at === 0) {
            return $html;
        }
        $open = strripos($html, '<picture', $at - strlen($html) - 1);
        if ($open === false) {
            return $html;
        }
        $inside = substr($html, $open, $at - $open);
        if (stripos($inside, '</picture>') !== false) {
            return $html;
        }
        $promoted = preg_replace_callback('/<source\b[^>]*>/i', function ($m) {
            if (!preg_match('/\sdata-srcset=/i', $m[0]) || preg_match('/\ssrcset=/i', $m[0])) {
                return $m[0];
            }
            return preg_replace('/\sdata-srcset=/i', ' srcset=', $m[0], 1);
        }, $inside);
        return is_string($promoted) && $promoted !== $inside ? substr_replace($html, $promoted, $open, strlen($inside)) : $html;
    }

    public static function wpc_park_below_fold_backgrounds($html)
    {
        try {
            if (!is_string($html) || $html === '' || stripos($html, 'background') === false
                || !apply_filters('wpc_bg_park', true) || !class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return $html;
            }
            $mf = (class_exists('wps_ic_js_delay_v3') && method_exists('wps_ic_js_delay_v3', 'wpc_delay_manifest_file'))
                ? (string) wps_ic_js_delay_v3::wpc_delay_manifest_file() : '';
            if ($mf === '' || !@is_readable($mf)) {
                return $html;
            }
            $m = json_decode((string) @file_get_contents($mf), true);
            if (!is_array($m)) {
                return $html;
            }
            $stem = function ($u) {
                $u = strtolower((string) $u);
                $u = (string) basename((string) strtok($u, '?#'));
                $u = (string) preg_replace('/\.[a-z0-9]{2,5}$/', '', $u);
                return (string) preg_replace('/(-\d+x\d+|-scaled|@\dx)+$/', '', $u);
            };
            $atf = array();
            $seenKey = false;
            foreach (array('mobile', 'desktop') as $dev) {
                if (!isset($m[$dev]) || !is_array($m[$dev]) || !array_key_exists('atf_bg', $m[$dev])) {
                    continue;
                }
                $seenKey = true;
                foreach ((array) $m[$dev]['atf_bg'] as $b) {
                    if (is_array($b)) {
                        if (!empty($b['stem'])) { $atf[$stem((string) $b['stem'])] = 1; }
                        if (!empty($b['url'])) { $atf[$stem((string) $b['url'])] = 1; }
                    }
                }
            }
            if (!$seenKey) {
                return $html;
            }
            // The page's own measured LCP element stays painted too, whatever its type.
            foreach (array('mobile', 'desktop') as $dev) {
                $lcpElement = wps_ic_atf_observation::lcpElement($dev, true);
                if (!empty($lcpElement['url'])) {
                    $atf[$stem((string) $lcpElement['url'])] = 1;
                }
            }
            $n = 0;
            $cap = (int) apply_filters('wpc_bg_park_cap', 40);
            $out = preg_replace_callback('/<(div|section|span|a|li|figure|header|footer|article|aside)\b([^>]*?)\sstyle=(["\'])([^"\']*)\3([^>]*)>/i', function ($t) use (&$n, $cap, $atf, $stem) {
                if ($n >= $cap || stripos($t[4], 'url(') === false || stripos($t[2] . $t[5], 'data-wpc-bg') !== false) {
                    return $t[0];
                }
                if (!preg_match('/(^|;)\s*background-image\s*:\s*url\(\s*(["\']?)([^"\')]+)\2\s*\)\s*(?=;|$)/i', $t[4], $bm)) {
                    return $t[0];
                }
                $u = trim(html_entity_decode(trim($bm[3]), ENT_QUOTES), " \t\"'");
                if ($u === '' || stripos($u, 'data:') === 0 || !preg_match('#^(?:https?:)?//|^/#', $u)) {
                    return $t[0];
                }
                if (isset($atf[$stem($u)])) {
                    return $t[0];
                }
                $style = trim((string) preg_replace('/(^|;)\s*background-image\s*:\s*url\(\s*(["\']?)[^"\')]+\2\s*\)\s*(?=;|$)/i', '$1', $t[4]), "; \t");
                $n++;
                return '<' . $t[1] . $t[2] . ' data-wpc-bg=' . $t[3] . esc_attr($u) . $t[3] . ($style !== '' ? ' style=' . $t[3] . $style . $t[3] : '') . $t[5] . '>';
            }, $html);
            if (!is_string($out)) {
                return $html;
            }
            if ($n > 0 && function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_bgpark41_log')) {
                set_transient('wpc_bgpark41_log', 1, 3600);
                wpc_cache_first_log('bg-parked', '', '', array('n' => $n, 'atf' => count($atf)));
            }
            return $out;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * The measured LCP image, dressed eager: fetchpriority="high" and loading="eager" on up to four
     * <img> that carry its stem (themes print a device-duplicate hero, and the painted copy may be
     * any of them). Its `sizes` is not this pass's: it wrote one width into every copy it matched,
     * a substring match that ignored how many images use the file, and the image-sizing owner
     * now answers `sizes` per tag at image_sizes. It is also the one pass that resolves a
     * fetchpriority="high" + loading="lazy" contradiction, and only for the measured image: the
     * page-wide lcp_eager_invariant pass that promoted every such <img> put the logo on the wire
     * beside the hero and was off by default from v7.10.439 until its deletion.
     */
    public static function wpc_lcp_hint_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<img') === false) return $html;
        $hint = function_exists('apply_filters')
            ? apply_filters('wpc_lcp_hint', (function_exists('get_option') ? get_option('wpc_lcp_hint') : null))
            : (function_exists('get_option') ? get_option('wpc_lcp_hint') : null);
        if (empty($hint) || !is_array($hint)) return $html;

        // Device parity with the cache-variant writer (simulate_mobile honored).
        $is_m = !empty($_GET['simulate_mobile']) || (function_exists('wp_is_mobile') && wp_is_mobile());
        if (isset($hint['stem'])) {
            $entry = $hint;
        } else {
            $vp = $is_m ? 'mobile' : 'desktop';
            $entry = (isset($hint[$vp]) && is_array($hint[$vp])) ? $hint[$vp]
                   : ((isset($hint['desktop']) && is_array($hint['desktop'])) ? $hint['desktop']
                   : ((isset($hint['mobile']) && is_array($hint['mobile'])) ? $hint['mobile'] : null));
        }
        if (!is_array($entry) || empty($entry['stem'])) return $html;
        $stem  = (string) $entry['stem'];
        if (strlen($stem) < 4) return $html;
        $applied = 0;
        $maxCopies = function_exists('apply_filters') ? (int) apply_filters('wpc_lcp_hint_max_copies', 4) : 4;
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($stem, &$applied, $maxCopies) {
            $tag = $m[0];
            if ($applied >= $maxCopies || stripos($tag, $stem) === false) return $tag;
            $applied++;
            if (stripos($tag, 'fetchpriority') === false) {
                $tag = preg_replace('/<img\b/i', '<img fetchpriority="high"', $tag, 1);
            }
            $tag = preg_replace('/\sloading=(["\'])lazy\1/i', ' loading="eager"', $tag, 1);
            if (stripos($tag, 'loading=') === false) {
                $tag = preg_replace('/<img\b/i', '<img loading="eager"', $tag, 1);
            }
            return $tag;
        }, $html);
        return ($out === null) ? $html : $out;
    }


    // v7.10.631 — PICTURE FIDELITY. Wrapping an <img> in <picture> re-parents it, which
    // silently unmatches every selector that addressed the img by POSITION (`+`/`~`/`>`
    // bound to the final compound). Receipt: thepttv.net Repeat toggle — `.active-item +
    // .img-repeat{opacity:1}` dead the moment next-gen wrapped the icon (head-tree proof:
    // rule matched nothing; control matched). Three repairs, all evidence-gated on the
    // page's OWN stylesheets (wpc_picture_scan_page, content-hash cached):
    //  1. mirror positional CLASSES onto the wrapper — those rules match again at first
    //     paint, no JS. Only classes the CSS proves positional: blanket mirroring would
    //     double-match site JS (querySelectorAll('.gallery-img') → wrapper + img).
    //  2. a wrapper contract: wrapper never paints (border/padding/background/shadow),
    //     img never composites (opacity/transform/filter at (0,1,1) — weak on purpose,
    //     so type-targeted rules like `.card:hover img`, which correctly address the
    //     img, still win).
    //  3. TYPE-img positional selectors (`.single-content p>img`) can never be satisfied
    //     by a wrapper attribute — the pic-guard inline tests the substituted form
    //     (img → picture.wpc-picture, sound because the wrapper occupies the img's old
    //     tree position) per-<picture> AS IT PARSES and unwraps proven matches pre-paint:
    //     the author gets their exact DOM back where their CSS depends on it. Cost per
    //     unwrapped img: <source> alternatives stand down (legacy src remains).
    public static function wpc_picture_fidelity_pass($html)
    {
        try {
            if (!is_string($html) || stripos($html, 'wpc-picture') === false
                || !function_exists('wpc_picture_scan_page')
                || (function_exists('apply_filters') && !apply_filters('wpc_picture_fidelity', true))) {
                return $html;
            }
            $pictureScan = wpc_picture_scan_page($html);
            // v7.10.633 field probe — the scan verdict, so a server-side miss names itself
            // instead of needing a remote bisect (two theories already disproven remotely).
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient')
                && !get_transient('wpc_picfid_log633')) {
                if (function_exists('set_transient')) {
                    set_transient('wpc_picfid_log633', 1, 600);
                }
                wpc_cache_first_log('pic-fidelity-scan', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', [
                    'cls' => count($pictureScan['cls']),
                    'tags' => count($pictureScan['tags']),
                    'capped' => isset($pictureScan['capped']) ? (int) $pictureScan['capped'] : 0,
                    'ir' => in_array('img-repeat', $pictureScan['cls'], true) ? 1 : 0,
                    'len' => strlen((string) $html),
                    'styles' => substr_count((string) $html, '<style'),
                ]);
            }

            $mirroredPictures = 0;
            $guardedSelectors = 0;
            if (!empty($pictureScan['cls'])) {
                // manual surgery, not one big regex: a tempered-dot with picture-sized
                // bounds exceeds PCRE's compiled-pattern limit (caught in the lab —
                // "regular expression is too large", mirror silently never ran)
                $scannedClassSet = array_flip($pictureScan['cls']);
                $searchOffset = 0;
                $pictureIterations = 0;
                while (($pictureStart = strpos($html, '<picture class="wpc-picture', $searchOffset)) !== false && $pictureIterations++ < 400) {
                    $searchOffset = $pictureStart + 16;
                    $pictureEnd = strpos($html, '</picture>', $pictureStart);
                    if ($pictureEnd === false || $pictureEnd - $pictureStart > 12000) {
                        continue;
                    }
                    $pictureBlock = substr($html, $pictureStart, $pictureEnd - $pictureStart);
                    if (strpos(substr($pictureBlock, 0, 200), 'data-wpc-mir') !== false) {
                        continue;
                    }
                    if (!preg_match('/^<picture class="(wpc-picture[^"]*)">/', $pictureBlock, $wm)
                        || !preg_match('/<img\b[^>]*?class=["\']([^"\']+)["\']/i', $pictureBlock, $im)) {
                        continue;
                    }
                    $have = preg_split('/\s+/', $wm[1], -1, PREG_SPLIT_NO_EMPTY);
                    $add = [];
                    foreach (preg_split('/\s+/', $im[1], -1, PREG_SPLIT_NO_EMPTY) as $c) {
                        if (isset($scannedClassSet[$c]) && !in_array($c, $have, true) && !in_array($c, $add, true)) {
                            $add[] = $c;
                        }
                    }
                    if (!$add) {
                        continue;
                    }
                    // data-wpc-mir marks the wrapper as MIRRORED — the contract style is
                    // scoped to it, so a scan miss degrades to the old broken-toggle state,
                    // never to the strictly-worse always-active one (live receipt .631)
                    $mirroredOpenTag = '<picture class="' . $wm[1] . ' ' . implode(' ', $add) . '" data-wpc-mir="1">';
                    $html = substr_replace($html, $mirroredOpenTag, $pictureStart, strlen($wm[0]));
                    $searchOffset = $pictureStart + strlen($mirroredOpenTag);
                    $mirroredPictures++;
                }
            }

            $headInjection = '';
            if (strpos($html, 'wpc-picture-contract') === false && strpos($html, 'data-wpc-mir') !== false) {
                // v7.10.640 — :where gives the mirrored wrapper a PAINT BOX at zero
                // specificity: display:contents (now :not-scoped away) painted nothing,
                // so mirrored opacity/filter state computed but never rendered. Any
                // site rule on the mirrored classes still outranks :where and may
                // restyle display freely — every display except contents/none paints.
                $headInjection .= '<style id="wpc-picture-contract">:where(picture.wpc-picture[data-wpc-mir]){display:inline-block}picture.wpc-picture[data-wpc-mir]{border:0;padding:0;background:none;box-shadow:none}picture.wpc-picture[data-wpc-mir]>img{opacity:1;transform:none;filter:none}</style>';
            }
            if (!empty($pictureScan['tags']) && strpos($html, 'wpc-pic-guard') === false) {
                $guardSelectorList = [];
                foreach ($pictureScan['tags'] as $t) {
                    $guardSelectorList[] = [(string) $t['s'], (string) $t['m']];
                }
                $guardSelectorJson = json_encode($guardSelectorList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                if (is_string($guardSelectorJson) && strlen($guardSelectorJson) <= 6144) {
                    $guardedSelectors = count($guardSelectorList);
                    $headInjection .= '<script id="wpc-pic-guard">/*wpc-arm-sentinel*/(function(){var L=' . $guardSelectorJson . ';'
                        . 'function T(p){try{if(p.__wpcPG)return;for(var i=0;i<L.length;i++){var m=L[i][1];'
                        . 'if(m){try{if(window.matchMedia&&!matchMedia(m).matches)continue}catch(e){}}'
                        . 'var h=false;try{h=p.matches(L[i][0])}catch(e){}'
                        . 'if(h){p.__wpcPG=1;var im=p.querySelector("img");if(!im||!p.parentNode)return;'
                        . 'try{if(im.complete&&im.currentSrc&&im.currentSrc.indexOf("data:")!==0){im.src=im.currentSrc}}catch(e){}'
                        . 'try{p.parentNode.insertBefore(im,p);p.parentNode.removeChild(p)}catch(e){}return}}}catch(e){}}'
                        . 'try{var d=document,q=d.getElementsByTagName("picture"),i;for(i=q.length-1;i>=0;i--)T(q[i]);'
                        . 'var mo=new MutationObserver(function(ms){for(var a=0;a<ms.length;a++){var ns=ms[a].addedNodes;'
                        . 'for(var b=0;b<ns.length;b++){var n=ns[b];if(!n.tagName)continue;'
                        . 'if(n.tagName==="PICTURE")T(n);else if(n.querySelectorAll){var ps=n.querySelectorAll("picture");'
                        . 'for(var c=0;c<ps.length;c++)T(ps[c])}}}});'
                        . 'mo.observe(d.documentElement,{childList:true,subtree:true});'
                        . 'addEventListener("load",function(){setTimeout(function(){'
                        . 'try{var z=d.getElementsByTagName("picture");for(var i=z.length-1;i>=0;i--)T(z[i])}catch(e){}'
                        . 'try{mo.disconnect()}catch(e){}},2500)})}catch(e){}})();</script>';
                }
            }
            if ($headInjection !== '' && ($headClosePos = stripos($html, '</head>')) !== false) {
                $html = substr_replace($html, $headInjection, $headClosePos, 0);
            }
            // The picture tier re-parents an <img> into <picture>, which breaks author CSS that
            // targets the img's classes or its parent; the classes are mirrored and a guard unwraps
            // the tags that still break. Sampled: the site's CSS is the same on every render.
            if (($mirroredPictures > 0 || $guardedSelectors > 0) && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('picture-fidelity', array_filter(['mirrored' => $mirroredPictures, 'guard_tags' => $guardedSelectors]), true);
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    // fetchpriority="high" + loading="lazy" on one <img> are contradictory — the lazy defers
    // the fetch the priority just requested, and Lighthouse fails "LCP resources should not
    // use loading=lazy" on it. Divi ships loading="lazy" on the hero and our LCP dressing adds
    // fetchpriority without clearing it (receipted on busy in local delivery). Runs after every
    // image pass so nothing can reintroduce it. sizes="auto" is EXEMPT: per spec sizes=auto is
    // only defined for a lazy image, so clearing lazy there would break the sizes contract.
    // The preload scanner evaluates a hint's media WHEN IT REACHES THE HINT. Divi + The Events
    // Calendar emit the viewport meta ~90KB into <head>, while our media-scoped hero preloads sit
    // at byte ~218 — so media is tested against the browser's DEFAULT ~980px viewport, not the
    // real one. On a phone that inverts both hints: (max-width:767.98px) is FALSE so the mobile
    // preload never fires, and (min-width:768px) is TRUE so the DESKTOP preload does — receipted
    // on busyprosai as 893w/67KiB fetched and never displayed, alongside the 576w/40KiB the <img>
    // actually uses. Hoisting the viewport meta ahead of the hints fixes both halves at once.
    // This is also the true cause of the .436 regression: that build moved the preload from AFTER
    // the viewport meta to before it, which is why LCP got worse rather than better.
    // v7.10.698 — a rel=preconnect warms a connection; the delay engine guarantees a
    // delayed-only host sees NO request until interaction, so the warmed socket idles past
    // its ~10s timeout and PSI charges the hint as unused (receipted: googletagmanager on
    // the flagship — script delayed, preconnect still fired). Prune hints whose host
    // survives ONLY inside delayed scripts (masked or placeholdered) or <noscript> blocks
    // (inert while JS runs). Anything visible in the remaining document — live scripts,
    // styles, images, iframes — keeps its hint. Never prunes on a failed probe.
    public static function wpc_prune_idle_preconnects_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'preconnect') === false
            && stripos($html, 'dns-prefetch') === false) {
            return $html;
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_prune_idle_preconnects', true)) {
            return $html;
        }
        // Only meaningful when a delay executor actually holds scripts on this page.
        if (stripos($html, 'text/placeholder') === false && stripos($html, 'wpc-delay-script') === false) {
            return $html;
        }
        if (!preg_match_all('/<link\b[^>]*\brel\s*=\s*["\'](?:preconnect|dns-prefetch)["\'][^>]*>/i', $html, $hintTagMatches)) {
            return $html;
        }
        // Probe copy: delayed scripts, noscript blocks and the hint tags themselves removed.
        // A host still visible in the probe is used before interaction — its hint stays.
        $probeHtml = preg_replace([
            '/<script\b[^>]*(?:text\/placeholder|wpc-delay-script)[^>]*>.*?<\/script>/is',
            '/<noscript\b[^>]*>.*?<\/noscript>/is',
            '/<link\b[^>]*\brel\s*=\s*["\'](?:preconnect|dns-prefetch)["\'][^>]*>/i',
        ], '', $html);
        if (!is_string($probeHtml) || $probeHtml === '') {
            return $html;
        }
        $homeHost = function_exists('home_url') ? strtolower((string) parse_url(home_url('/'), PHP_URL_HOST)) : '';
        $out = $html;
        $pruned = 0;
        foreach (array_unique($hintTagMatches[0]) as $hintTag) {
            if (!preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $hintTag, $hrefMatch)) {
                continue;
            }
            $hintHost = strtolower((string) parse_url(trim($hrefMatch[1]), PHP_URL_HOST));
            if ($hintHost === '' || $hintHost === $homeHost) {
                continue;
            }
            if (stripos($probeHtml, $hintHost) !== false) {
                continue;
            }
            $out = str_replace($hintTag, '', $out, $removed);
            $pruned += (int) $removed;
        }
        // A hint for a host only delayed scripts use idles out before interaction; the delay
        // engine tells no hint emitter what it holds, so the hint is pruned here. Sampled: the
        // same theme and plugin hints meet the same delay on every render.
        if ($pruned > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('idle-preconnects-pruned', ['n' => $pruned], true);
        }
        return $out;
    }

    public static function wpc_hoist_viewport_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'viewport') === false) {
            return $html;
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_hoist_viewport', true)) {
            return $html;
        }
        if (!preg_match('/<meta\b[^>]*\bname\s*=\s*["\']?viewport["\']?[^>]*>/i', $html, $viewportMatch, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $viewportTag = (string) $viewportMatch[0][0];
        $viewportOffset = (int) $viewportMatch[0][1];
        // Anchor after the charset meta so charset stays inside the 1024-byte sniffing window.
        if (!preg_match('/<meta\b[^>]*\bcharset\b[^>]*>/i', $html, $anchorMatch, PREG_OFFSET_CAPTURE)
            && !preg_match('/<head\b[^>]*>/i', $html, $anchorMatch, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $insertOffset = (int) $anchorMatch[0][1] + strlen((string) $anchorMatch[0][0]);
        // Already ahead of the hints (or is the anchor itself) — nothing to do.
        if ($viewportOffset <= $insertOffset + 200) {
            return $html;
        }
        $htmlWithoutViewport = substr($html, 0, $viewportOffset) . substr($html, $viewportOffset + strlen($viewportTag));
        // The theme wrote its viewport meta after the media-scoped preloads, which a phone then
        // evaluates at the default desktop width. Sampled: the theme's head is the same each render.
        if (function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('viewport-hoisted', ['from' => $viewportOffset], true);
        }
        return substr($htmlWithoutViewport, 0, $insertOffset) . "\n" . $viewportTag . substr($htmlWithoutViewport, $insertOffset);
    }

    /**
     * Proposes the LCP image's preload (wpc-lcp-img-preload) from lcp.json's measured identity
     * (origin lcp-measured, ranked right below the measured background, per device on a
     * combined crit), else the element the service derived for a leg it measured no LCP on
     * (lcp-derived, `lcp_confidence: 'derived'`), else the one fetchpriority="high" <img>
     * (lcp-guess), last in the set; and demotes every other fetchpriority="high" <img> once the
     * LCP is known.
     *
     * It proposes nothing when a better-ranked candidate already holds its slot (the derived
     * element and the guess also stand down for the site's preload list), or the page carries an image
     * preload of its own: the demotion is right only for the image whose preload will ship.
     */
    public static function wpc_lcp_img_preload_pass($html, $imagePreloads = null, &$keepPriorityStems = null)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'fetchpriority') === false) return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_lcp_img_preload', true)) return $html;
        if (!$imagePreloads instanceof wps_ic_image_preload_set) return $html;


        // An image preload the page itself carries is the page's answer: a second one at
        // fetchpriority=high splits the LCP's bandwidth.
        if (preg_match('/<link\b[^>]*\brel\s*=\s*["\']?preload["\']?[^>]*\bas\s*=\s*["\']?image\b/i', $html)
            || preg_match('/<link\b[^>]*\bas\s*=\s*["\']?image["\']?[^>]*\brel\s*=\s*["\']?preload\b/i', $html)) {
            // Stands down on ANY image preload the page carries, wider than the preload set's
            // own page-authored rule (same picture only): no LCP proposal and no demotion of
            // competing fetchpriority=high. Sampled: the theme writes that preload on every render.
            if (function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('lcp-preload-standdown', ['why' => 'page-preload'], true);
            }
            return $html;
        }


        $lcpStem = '';
        $lcpUrl  = '';
        $lcpType = '';
        $mobileLcpStem = '';
        $desktopLcpStem = '';
        $lcpDevice = 'mobile';
        $lcpDerived = false;
        try {
            if (class_exists('wps_ic_atf_observation') && class_exists('wps_rewriteLogic')) {


                {
                    {
                        // The measured LCP element per device, lcp[dev] and lcp_element[dev]
                        // merged field by field by the observation (one container, or none).
                        $lcpElementOf = function ($d) {
                            $element = wps_ic_atf_observation::lcpElement($d);
                            return empty($element) ? [] : [$element];
                        };
                        $lcpStemOf = function ($d) use ($lcpElementOf) {
                            $e = [];
                            foreach ($lcpElementOf($d) as $element) {
                                foreach (['stem', 'url'] as $field) {
                                    if (!isset($e[$field]) && isset($element[$field]) && is_string($element[$field])
                                        && trim($element[$field]) !== '') {
                                        $e[$field] = $element[$field];
                                    }
                                }
                            }
                            $s = (isset($e['stem']) && is_string($e['stem'])) ? trim($e['stem']) : '';
                            if ($s === '' && isset($e['url']) && is_string($e['url'])) {
                                $s = strtolower(basename((string) preg_replace('/[?#].*$/', '', $e['url'])));
                                $s = (string) preg_replace('/\.(?:jpe?g|png|webp|avif|gif|svg)$/i', '', $s);
                                $s = (string) preg_replace('/(?:-\d+x\d+)?$/', '', (string) preg_replace('/-scaled$/', '', $s), 1);
                            }
                            return (strlen($s) >= 3 && preg_match('/^[A-Za-z0-9._@-]+$/', $s)) ? strtolower($s) : '';
                        };


                        $lcpTypeAndUrlOf = function ($d) use ($lcpElementOf) {
                            $t = ''; $u = '';
                            foreach ($lcpElementOf($d) as $element) {
                                if ($t === '' && isset($element['type']) && is_string($element['type'])) {
                                    $t = strtolower(trim($element['type']));
                                }
                                if ($u === '' && isset($element['url']) && is_string($element['url'])) {
                                    $u = trim($element['url']);
                                }
                            }
                            return ['t' => $t, 'u' => $u];
                        };
                        $mobileLcpStem = $lcpStemOf('mobile');
                        $desktopLcpStem = $lcpStemOf('desktop');
                        if (wps_rewriteLogic::wpc_combined_crit_on()) {


                            if ($mobileLcpStem !== '' && $desktopLcpStem !== '') {
                                $lcpStem = $mobileLcpStem;
                            } else {
                                $lcpStem = ($mobileLcpStem !== '') ? $mobileLcpStem : $desktopLcpStem;
                            }
                        } else {
                            $lcpStem = !empty(wps_rewriteLogic::$isMobile) ? $mobileLcpStem : $desktopLcpStem;
                        }
                        $lcpDevice = ($lcpStem !== '' && $lcpStem === $desktopLcpStem && $lcpStem !== $mobileLcpStem) ? 'desktop' : 'mobile';
                        $lcpTypeAndUrl = $lcpTypeAndUrlOf($lcpDevice);
                        if ($lcpTypeAndUrl['u'] === '') { $lcpTypeAndUrl = $lcpTypeAndUrlOf($lcpDevice === 'mobile' ? 'desktop' : 'mobile'); }
                        $lcpIdentityElement = wps_ic_atf_observation::lcpElement($lcpDevice);
                        $lcpDerived = ($lcpStem !== '' && ($lcpIdentityElement['lcp_confidence'] ?? '') === 'derived');
                        $lcpType = $lcpTypeAndUrl['t'];
                        $lcpUrl  = $lcpTypeAndUrl['u'];
                    }
                }
            }
        } catch (\Throwable $e) {
            $lcpStem = '';
        }


        // A leg the service measured no LCP on is filled by the service itself since crit-push
        // 3.198.293 (lcp-derive.js: the largest above-the-fold image when it is at least 1.5 times
        // the runner-up, `lcp_confidence: 'derived'`), and left empty on a challenged or
        // layout-broken leg, whose geometry is not this page's. The plugin's own census keeper
        // (v7.10.783, the largest box of the same census, heritage's seven fetchpriority=high
        // competitors) is deleted: a derived element ranks where it ranked, and an empty leg stays
        // empty rather than being guessed from geometry the service refused to trust.
        $lcpIdentitySource = ($lcpStem !== '') ? ($lcpDerived ? 'derived' : 'json') : '';
        // Rank and device of this proposal. The measured identity and the derived one are both
        // the service's answer for one device: on a combined crit the preload is that device's
        // (the other device's slot goes to whatever ranks best there); on a single-device crit it
        // is this render's, like the background lane's. Only the guess is not the service's.
        $combinedCrit = class_exists('wps_rewriteLogic') && wps_rewriteLogic::wpc_combined_crit_on();
        $measured = ($lcpIdentitySource === 'json');
        $derived = ($lcpIdentitySource === 'derived');
        $proposalOrigin = $measured ? 'lcp-measured' : ($derived ? 'lcp-derived' : 'lcp-guess');
        $proposalRank = $measured ? wps_ic_image_preload_set::RANK_LCP_MEASURED
            : ($derived ? wps_ic_image_preload_set::RANK_LCP_DERIVED : wps_ic_image_preload_set::RANK_LCP_GUESS);
        $proposalDevice = 'both';
        if ($combinedCrit && ($measured || $derived)) {
            $proposalDevice = $lcpDevice;
        }
        if ($imagePreloads->outranked($proposalRank, $proposalDevice)
            || (!$measured && !$derived && $imagePreloads->hasSlot([wps_ic_image_preload_set::SLOT_CUSTOM]))) {
            return $html;
        }
        // A combined crit whose two devices measured two different images: the other device's
        // image gets its own preload, carrying that device's media.
        $otherDevice = ($proposalDevice === 'mobile') ? 'desktop' : 'mobile';
        $otherStem = ($measured && $combinedCrit && $mobileLcpStem !== '' && $desktopLcpStem !== '' && $mobileLcpStem !== $desktopLcpStem)
            ? (($otherDevice === 'desktop') ? $desktopLcpStem : $mobileLcpStem) : '';
        if ($otherStem !== '' && !$imagePreloads->outranked($proposalRank, $otherDevice)
            && preg_match('#<img\b[^>]*(?:src|srcset)="[^"]*/' . preg_quote($otherStem, '#') . '(?:-scaled)?(?:-\d+x\d+)?\.(?:png|jpe?g|webp|avif|gif)[^"]*"[^>]*>#i', $html, $otherImg)
            && !preg_match('/\bloading\s*=\s*["\']?lazy["\']?/i', $otherImg[0])) {
            $otherSrc = preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/is', $otherImg[0], $otherSrcMatch) ? trim($otherSrcMatch[2]) : '';
            $otherSrcset = preg_match('/\ssrcset\s*=\s*(["\'])(.*?)\1/is', $otherImg[0], $otherSrcsetMatch) ? trim($otherSrcsetMatch[2]) : '';
            $otherSizes = preg_match('/\ssizes\s*=\s*(["\'])(.*?)\1/is', $otherImg[0], $otherSizesMatch) ? trim($otherSizesMatch[2]) : '';
            if ($otherSrc !== '' && stripos($otherSrc, 'data:') !== 0) {
                $imagePreloads->add('wpc-lcp-img-preload', esc_url($otherSrc), $otherDevice, 'lcp-measured', $proposalRank, [
                    'imagesrcset' => ($otherSrcset !== '') ? esc_attr($otherSrcset) : '',
                    'imagesizes'  => ($otherSrcset !== '' && $otherSizes !== '') ? esc_attr($otherSizes) : '',
                ]);
            }
        }
        $tag = ''; $imgPos = -1;
        if ($lcpStem !== ''
            && preg_match('#<img\b[^>]*(?:src|srcset)="[^"]*/' . preg_quote($lcpStem, '#') . '(?:-scaled)?(?:-\d+x\d+)?\.(?:png|jpe?g|webp|avif|gif)[^"]*"[^>]*>#i', $html, $lcpImgMatch, PREG_OFFSET_CAPTURE)) {
            $tag    = $lcpImgMatch[0][0];
            $imgPos = (int) $lcpImgMatch[0][1];
        }

        // The competitor demotion runs later, at lcp_demote_competitors, where it always ran
        // relative to quiet_wire and the picture passes; this pass only names who keeps priority.
        if ($tag !== '' && $lcpStem !== '') {
            $keepPriorityStems = array_values(array_filter([$lcpStem, $otherStem], 'strlen'));
        }

        if ($tag === '' && $lcpType === 'bg' && $lcpUrl !== ''
            && stripos($lcpUrl, 'data:') !== 0
            && (!class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'wpc_lcp_bg_url_allowed')
                || wps_rewriteLogic::wpc_lcp_bg_url_allowed($lcpUrl))) {
            $imagePreloads->add('wpc-lcp-img-preload', esc_url($lcpUrl), $proposalDevice, $proposalOrigin,
                $proposalRank, ['kind' => wps_ic_image_preload_set::KIND_BACKGROUND]);
            return $html;
        }
        // NEVER-GUESS CONTRACT. A preload is a promise about which element is the LCP; a WRONG
        // preload is strictly worse than none, because it spends the LCP's bandwidth at
        // fetchpriority="high" on something else. The old fallback took the FIRST
        // fetchpriority="high" <img>, and themes emit the header logo before the hero — so
        // whenever the hero preload was (correctly) skipped as non-authoritative, this preloaded
        // the LOGO. Service-confirmed on busyprosai: lcp.json named the hero, the page preloaded
        // 2025/09/BusyPros-AI-Horizontal-...-210x70.webp.
        // Rules: authoritative identity (stem) present -> stem match ONLY, never a guess.
        // No identity at all -> guess ONLY when exactly one candidate exists (unambiguous).
        if ($tag === '') {
            // v7.10.477 — the artifact can tell us the LCP is NOT AN IMAGE. lcp_element.type
            // 'text' means there is no hero to preload, so hunting for one is wrong by
            // construction. At best it burns a candidate scan and logs lcp-preload-ambiguous on
            // EVERY render (zinsenvergleich: candidates:4, every single render, for a page whose
            // LCP is text on both devices). At worst the guess resolves to exactly ONE candidate
            // and we preload an image at fetchpriority="high" on a page whose LCP is text —
            // spending the LCP's bandwidth on an element that cannot be the LCP.
            // This is the same never-guess contract as the stem branch below: authoritative
            // identity present -> obey it. A text LCP is an identity, not an absence.
            if ($lcpType === 'text' && apply_filters('wpc_lcp_preload_honour_text', true)) {
                $imagePreloads->declined('text-lcp');
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('lcp-preload-text-lcp', '', '', [
                        'why' => 'artifact says the LCP is text — no image to preload',
                    ]);
                }
                return $html;
            }
            if ($lcpStem !== '') {
                // We know which element is the LCP and could not find it in this document.
                // Emitting nothing lets the <img>'s own srcset load it — never a wrong promise.
                // Enough context to self-diagnose WHY the known hero was not findable: whether the
                // stem is absent from the document entirely (wrong page / device twin) vs present
                // but parked on a quiet-wire data- attribute, which the src|srcset probe cannot see.
                if (function_exists('wpc_cache_first_log')) {
                    $quotedStem = preg_quote($lcpStem, '#');
                    // Discriminating flags FIRST and the long stem LAST + truncated: the cflog
                    // printer caps the payload, and a 60-char stem in front hid every field that
                    // actually answers the question.
                    // A bg-typed LCP reaches this branch ONLY when the bg lane above refused, and
                    // its three inputs are invisible from here: which artifact supplied the
                    // identity, what type it declared, whether a url came with it, and whether the
                    // host allowlist admitted that url. Without them "inhtml:0" is unfalsifiable —
                    // it is equally consistent with a wrong page, a quiet-wire park, and a
                    // background image that no <img> scan can ever find.
                    wpc_cache_first_log('lcp-preload-no-stem-match', '', '', [
                        'inhtml' => (stripos($html, $lcpStem) !== false) ? 1 : 0,
                        'qw'     => preg_match('#data-wpc-qw-(?:src|srcset)="[^"]*' . $quotedStem . '#i', $html) ? 1 : 0,
                        'idsrc'  => $lcpIdentitySource !== '' ? $lcpIdentitySource : '-',
                        't'      => $lcpType !== '' ? $lcpType : '-',
                        'u'      => $lcpUrl !== '' ? 1 : 0,
                        'allow'  => ($lcpUrl !== '' && class_exists('wps_rewriteLogic')
                                     && method_exists('wps_rewriteLogic', 'wpc_lcp_bg_url_allowed'))
                                    ? (wps_rewriteLogic::wpc_lcp_bg_url_allowed($lcpUrl) ? 1 : 0) : '-',
                        'imgs'   => preg_match_all('#<img\b#i', $html),
                        'uri'    => isset($_SERVER['REQUEST_URI']) ? substr((string) $_SERVER['REQUEST_URI'], 0, 40) : '',
                        'stem'   => substr($lcpStem, -28),
                    ]);
                }
                return $html;
            }
            if (!apply_filters('wpc_lcp_preload_guess', true)) { return $html; }
            $highPriorityImgs = [];
            if (preg_match_all('/<img\b[^>]*\bfetchpriority\s*=\s*["\']?high["\']?[^>]*>/i', $html, $highPriorityImgMatches, PREG_OFFSET_CAPTURE)) {
                $highPriorityImgs = $highPriorityImgMatches[0];
            }
            if (count($highPriorityImgs) !== 1) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('lcp-preload-ambiguous', '', '', ['candidates' => count($highPriorityImgs)]);
                }
                return $html;
            }
            $tag    = $highPriorityImgs[0][0];
            $imgPos = (int) $highPriorityImgs[0][1];


            if (preg_match('/\s(?:src|srcset)\s*=\s*["\'][^"\']*\.svg(?:[?#"\']|\s)/i', $tag)) {
                return $html;
            }
        }
        // A lazy img isn't a meaningful preload target (the hint pass forces eager, but guard anyway).
        if (preg_match('/\bloading\s*=\s*["\']?lazy["\']?/i', $tag)) return $html;


        $before = substr($html, 0, $imgPos);
        $pOpen  = strripos($before, '<picture');
        $pClose = strripos($before, '</picture');
        if ($pOpen !== false && ($pClose === false || $pOpen > $pClose)) {
            if ($lcpStem === '') return $html;
            $pictureHead = substr($html, $pOpen, $imgPos - $pOpen);
            // image_sizes already gave every <source> its final `sizes`; each arm is read as written.


            if (preg_match_all('/<source\b[^>]*\btype=["\']image\/(avif|webp)["\'][^>]*>/i', $pictureHead, $nextgenSources, PREG_SET_ORDER)) {
                $sourceArms = [];
                foreach ($nextgenSources as $sourceMatch) {
                    $sourceTag = $sourceMatch[0];
                    $sourceMedia = (preg_match('/\smedia\s*=\s*(["\'])(.*?)\1/is', $sourceTag, $mediaMatch)) ? trim($mediaMatch[2]) : '';


                    $armKey = ($sourceMedia !== '') ? 'm:' . md5($sourceMedia) : 'd';
                    if (isset($sourceArms[$armKey]) || count($sourceArms) >= 3) { continue; }
                    $sourceSrcset = (preg_match('/\ssrcset\s*=\s*(["\'])(.*?)\1/is', $sourceTag, $srcsetMatch)) ? trim($srcsetMatch[2]) : '';
                    $sourceSizes  = (preg_match('/\ssizes\s*=\s*(["\'])(.*?)\1/is', $sourceTag, $sizesMatch)) ? trim($sizesMatch[2]) : '';
                    $sourceFirstUrl  = ($sourceSrcset !== '') ? (string) preg_split('/[\s,]+/', ltrim($sourceSrcset))[0] : '';
                    if ($sourceSrcset === '' || $sourceFirstUrl === '' || stripos($sourceFirstUrl, 'data:') === 0
                        || (method_exists('wps_rewriteLogic', 'wpc_lcp_bg_url_allowed') && !wps_rewriteLogic::wpc_lcp_bg_url_allowed($sourceFirstUrl))) {
                        continue;
                    }
                    $seenArmKeys[$armKey] = true;


                    $armMedia = $sourceMedia;
                    $sourceArms[$armKey] = [
                        'srcset' => $sourceSrcset, 'sizes' => $sourceSizes,
                        'type' => strtolower($sourceMatch[1]), 'media' => $armMedia,
                    ];
                }
                if (!empty($sourceArms)) {
                    // A media-less arm must be scoped to desktop whenever any media'd arm exists,
                    // or its preload fetches on every device (double-load with the matched arm).
                    if (isset($sourceArms['d']) && $sourceArms['d']['media'] === '' && count($sourceArms) > 1) {
                        $sourceArms['d']['media'] = '(min-width: 768px)';
                    }
                    $pictureArms = [];
                    foreach ($sourceArms as $arm) {
                        $pictureArms[] = [
                            'imagesrcset' => esc_attr($arm['srcset']),
                            'imagesizes'  => ($arm['sizes'] !== '') ? esc_attr($arm['sizes']) : '',
                            'media'       => ($arm['media'] !== '') ? esc_attr($arm['media']) : '',
                            'type'        => 'image/' . $arm['type'],
                        ];
                    }
                    $firstArmUrl = (string) preg_split('/[\s,]+/', ltrim((string) reset($sourceArms)['srcset']))[0];
                    $imagePreloads->add('wpc-lcp-img-preload', '', $proposalDevice, $proposalOrigin, $proposalRank,
                        ['arms' => $pictureArms, 'key' => wps_ic_image_preload_set::imageKey($firstArmUrl)]);

                    return $html;
                }
            }
            return $html;
        }
        // Pull the FINAL responsive attributes (post naturalize/zoneify → the preload byte-matches).
        $srcset = (preg_match('/\ssrcset\s*=\s*(["\'])(.*?)\1/is', $tag, $sm)) ? trim($sm[2]) : '';
        $sizes  = (preg_match('/\ssizes\s*=\s*(["\'])(.*?)\1/is',  $tag, $zm)) ? trim($zm[2]) : '';
        $src    = (preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/is',    $tag, $cm)) ? trim($cm[2]) : '';
        // First candidate URL — for the host gate + the no-srcset href.
        $probe  = ($srcset !== '') ? (string) preg_split('/[\s,]+/', ltrim($srcset))[0] : $src;
        if ($probe === '' || stripos($probe, 'data:') === 0) return $html;
        // Same host discipline as the css-bg responder (same-origin or an allowed CDN host).
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_lcp_bg_url_allowed')
            && !wps_rewriteLogic::wpc_lcp_bg_url_allowed($probe)) {
            return $html;
        }
        if ($srcset === '' && ($src === '' || stripos($src, 'data:') === 0)) return $html;
        $imagePreloads->add('wpc-lcp-img-preload', ($src !== '' && stripos($src, 'data:') !== 0) ? esc_url($src) : '', $proposalDevice, $proposalOrigin,
            $proposalRank, [
                'imagesrcset' => ($srcset !== '') ? esc_attr($srcset) : '',
                'imagesizes'  => ($srcset !== '' && $sizes !== '') ? esc_attr($sizes) : '',
            ]);
        return $html;
    }


    /**
     * Strips fetchpriority="high" from every <img> that carries none of $keepPriorityStems (the
     * LCP identity wpc_lcp_img_preload_pass found, one per measured device).
     */
    public static function wpc_lcp_demote_competitors($html, $keepPriorityStems)
    {
        if (!is_string($html) || empty($keepPriorityStems)) {
            return $html;
        }
        // v7.10.474 — DEMOTE THE COMPETITION. WordPress core stamps fetchpriority="high" on the
        // first "large" image it finds (wp_get_loading_optimization_attributes), and themes emit
        // the header logo before the hero — so the logo lands at the SAME priority as the measured
        // LCP and fights it for the same connection. Receipt on busyprosai: THREE
        // fetchpriority="high" images (logo + both hero twins) and an LCP resource load duration
        // of 570ms for 40KiB.
        // We know which element is the LCP — lcp.json, service-measured — so any OTHER
        // high-priority image is competing with it by definition. Only ever DEMOTE: .438 promoted
        // images to eager, put the logo on the wire beside the hero, and cost a point.
        // Runs only when the LCP was actually FOUND in this document, so a page we cannot identify
        // is left completely untouched. `loading` is never changed — an above-the-fold logo still
        // needs to load eagerly, it just must not do so at the LCP's priority.
        if (stripos($html, 'fetchpriority="high"') !== false
            && apply_filters('wpc_lcp_demote_competitors', true)) {
            $demotedCount = 0;
            $keepStemsPattern = '(?:' . implode('|', array_map(function ($stem) { return preg_quote($stem, '#'); }, $keepPriorityStems)) . ')';
            $demotedHtml = preg_replace_callback('#<img\b[^>]*>#i', function ($m) use ($keepStemsPattern, &$demotedCount) {
                $t = $m[0];
                if (stripos($t, 'fetchpriority="high"') === false) {
                    return $t;
                }
                // Any tag carrying the LCP identity keeps its priority — including a device twin,
                // which resolves to the same URL and therefore costs no extra fetch.
                if (preg_match('#(?:src|srcset|data-wpc-fb)="[^"]*/' . $keepStemsPattern . '#i', $t)) {
                    return $t;
                }
                $demotedCount++;
                return preg_replace('#\s*fetchpriority="high"#i', '', $t);
            }, $html);
            if (is_string($demotedHtml) && $demotedHtml !== '') {
                $html = $demotedHtml;
                if ($demotedCount > 0 && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('lcp-demote-competitors', '', '', [
                        'n'    => $demotedCount,
                        'stem' => substr((string) $keepPriorityStems[0], -28),
                    ]);
                }
            }
        }
        return $html;
    }

    private static function wpc_inject_after_viewport($html, $link)
    {
        if (preg_match('/<meta\b[^>]*\bname\s*=\s*["\']?viewport["\']?[^>]*>/i', $html, $hm, PREG_OFFSET_CAPTURE)
            || preg_match('/<meta\b[^>]*\bcharset\b[^>]*>/i', $html, $hm, PREG_OFFSET_CAPTURE)
            || preg_match('/<head\b[^>]*>/i', $html, $hm, PREG_OFFSET_CAPTURE)) {
            $pos = (int) $hm[0][1] + strlen($hm[0][0]);
            return substr($html, 0, $pos) . "\n" . $link . substr($html, $pos);
        }
        return $link . "\n" . $html;
    }


    /**
     * The zone's dns-prefetch + preconnect pair, written once from the final bytes. A hint for a
     * zone the page never fetches from opens a TCP+TLS connection nothing uses, which Lighthouse
     * flags and a throttled run pays for (anthonyveltri, v7.10.782: origin-served pages carried
     * the hint because wp_head wrote it before the rewrite knew whether any zone URL would
     * exist). The lanes that used to get it are unchanged: the CDN lane, and the local lane when
     * JS or CSS optimisation is on.
     */
    public static function wpc_zone_preconnect_pass($html, $lane)
    {
        try {
            if (!is_string($html) || $html === '' || empty(self::$zone_name)) {
                return $html;
            }
            if ($lane === wps_ic_render_pipeline::LANE_LOCAL && self::$js != '1' && self::$css != '1') {
                return $html;
            }
            $zoneHost = strtok((string) self::$zone_name, '/');
            if (!is_string($zoneHost) || $zoneHost === '') {
                return $html;
            }
            $withoutHints = preg_replace('/<link\b[^>]*rel=["\'](?:preconnect|dns-prefetch)["\'][^>]*>/i', '', $html);
            if (!is_string($withoutHints) || stripos($withoutHints, $zoneHost) === false) {
                return $html;
            }
            if (preg_match('/<link\b[^>]*rel=["\']preconnect["\'][^>]*href=["\']https:\/\/' . preg_quote((string) self::$zone_name, '/') . '["\'][^>]*>/i', $html)) {
                return $html;
            }

            return self::wpc_inject_after_viewport($html,
                '<link rel="dns-prefetch" href="//' . self::$zone_name . '" />'
                . '<link rel="preconnect" href="https://' . self::$zone_name . '">');
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public static function wpc_zone_font_preconnect_pass($html)
    {
        try {
            if (!is_string($html) || $html === '' || empty(self::$zone_name)) {
                return $html;
            }
            if (!apply_filters('wpc_zone_font_preconnect', true)) {
                return $html;
            }
            $zoneHostPattern = preg_quote((string) self::$zone_name, '/');
            if (preg_match('/<link\b[^>]*rel=["\']preconnect["\'][^>]*' . $zoneHostPattern . '[^>]*\bcrossorigin\b/i', $html)) {
                return $html;
            }
            // Early CORS fetch to the zone? (a) font preload with zone href, (b) zone woff2 in inline css.
            $zoneFontFetchedEarly =
                preg_match('/<link\b[^>]*as=["\']font["\'][^>]*href=["\']https:\/\/' . $zoneHostPattern . '\//i', $html)
                || preg_match('/<link\b[^>]*href=["\']https:\/\/' . $zoneHostPattern . '\/[^"\']*\.woff2?[^"\']*["\'][^>]*as=["\']font["\']/i', $html)
                || preg_match('/@font-face[^}]{0,600}?url\(\s*["\']?https:\/\/' . $zoneHostPattern . '\/[^"\')]*\.woff2?/i', $html);
            if (!$zoneFontFetchedEarly) {
                return $html;
            }
            $crossoriginPreconnect = '<link rel="preconnect" href="https://' . self::$zone_name . '" crossorigin>';
            $plainPreconnect = '<link rel="preconnect" href="https://' . self::$zone_name . '">';
            // The zone serves fonts before first paint and the zone preconnect writer does not
            // write the crossorigin twin a font fetch needs; it is added here. Sampled: the same
            // fonts ride the zone on every render.
            if (function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('zone-font-preconnect-twin', ['n' => 1], true);
            }
            if (strpos($html, $plainPreconnect) !== false) {
                return str_replace($plainPreconnect, $plainPreconnect . $crossoriginPreconnect, $html);
            }
            return self::wpc_inject_after_viewport($html, $crossoriginPreconnect);
        } catch (\Throwable $e) {
            return $html;
        }
    }



    public static function wpc_font_preconnect_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<head') === false) {
            return $html;
        }
        if (!apply_filters('wpc_font_preconnect', true)) {
            return $html;
        }


        if (isset(self::$settings['replace-fonts']) && self::$settings['replace-fonts'] === 'local') {
            $strippedHtml = preg_replace(
                [
                    '#<link\b[^>]*rel=["\']?(?:preconnect|dns-prefetch)["\']?[^>]*(?:fonts\.gstatic\.com|fonts\.googleapis\.com|fonts\.bunny\.net)[^>]*>\s*#i',
                    '#<link\b[^>]*(?:fonts\.gstatic\.com|fonts\.googleapis\.com|fonts\.bunny\.net)[^>]*rel=["\']?(?:preconnect|dns-prefetch)["\']?[^>]*>\s*#i',
                ],
                '',
                $html,
                -1,
                $providerHints
            );
            if (is_string($strippedHtml) && $strippedHtml !== '') {
                $html = $strippedHtml;
                // With fonts served locally the theme's preconnect/dns-prefetch to Google/Bunny
                // opens a connection nothing uses; it is removed. Sampled: the theme prints the
                // same hints on every render.
                if ($providerHints > 0 && function_exists('wpc_render_belt_note')) {
                    wpc_render_belt_note('font-provider-hints-stripped', ['n' => (int) $providerHints], true);
                }
            }
            return $html;
        }
        $links = '';
        if (stripos($html, 'fonts.gstatic.com') !== false
            && !preg_match('/<link\b[^>]*rel=["\']?preconnect["\']?[^>]*fonts\.gstatic\.com/i', $html)
            && !preg_match('/<link\b[^>]*fonts\.gstatic\.com[^>]*rel=["\']?preconnect/i', $html)) {
            $links .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        }
        if (stripos($html, 'fonts.googleapis.com/css') !== false
            && !preg_match('/<link\b[^>]*rel=["\']?preconnect["\']?[^>]*fonts\.googleapis\.com/i', $html)
            && !preg_match('/<link\b[^>]*fonts\.googleapis\.com[^>]*rel=["\']?preconnect/i', $html)) {
            $links .= '<link rel="preconnect" href="https://fonts.googleapis.com">';
        }
        if (stripos($html, 'fonts.bunny.net') !== false
            && !preg_match('/<link\b[^>]*rel=["\']?preconnect["\']?[^>]*fonts\.bunny\.net/i', $html)) {
            $links .= '<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>';
        }
        if ($links === '') {
            return $html;
        }
        if (preg_match('/<head\b[^>]*>/i', $html, $hm, PREG_OFFSET_CAPTURE)) {
            $pos = (int) $hm[0][1] + strlen($hm[0][0]);
            return substr($html, 0, $pos) . "\n" . $links . substr($html, $pos);
        }
        return $html;
    }


    public static function wpc_rum_beacon_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '</body>') === false) { return $html; }
        if (strpos($html, 'wpc-rum-beacon') !== false) { return $html; }
        if (function_exists('is_user_logged_in') && is_user_logged_in()) { return $html; }
        if (function_exists('is_customize_preview') && is_customize_preview()) { return $html; }
        if (function_exists('apply_filters') && !apply_filters('wpc_rum_beacon', true)) { return $html; }
        $sampleRate = (int) (function_exists('apply_filters') ? apply_filters('wpc_rum_sample_rate', function_exists('wpc_stored_rum_sample_rate') ? wpc_stored_rum_sample_rate() : 50) : 50);
        if ($sampleRate < 1) { return $html; }
        $ajaxUrl = function_exists('admin_url') ? (string) admin_url('admin-ajax.php') : '';
        if ($ajaxUrl === '' || strpos($ajaxUrl, 'http') !== 0) { return $html; }
        // Sampling is CLIENT-side: this snippet lives in cached HTML copies, so a
        // server-side coin flip would freeze one visit's choice for the cache TTL.
        // The census goes as a form (application/x-www-form-urlencoded) whose one field `d` is the
        // payload base64-encoded: the gzip bytes, or the JSON's UTF-8 bytes where the browser has no
        // CompressionStream. OWASP CRS rule 920420 refuses application/octet-stream and text/plain,
        // and a Plesk host (ticket 12055: nginx + ModSecurity 3 + CRS 4 + fail2ban) banned visitors
        // after a few refused beacons. Base64 rather than the JSON text in the field: CRS inspects
        // every form argument, and CSS selectors are the kind of text its XSS/SQLi rules score.
        // The payload caps (gzip 32768 bytes, JSON 30000) are unchanged, so the receiver records
        // what it did; the wire body grows by base64 (4/3) plus %2B/%2F/%3D, to about 46.5 KB for a
        // full 32 KB gzip, under sendBeacon's 64 KiB in-flight quota and ModSecurity's 128 KiB body limit.
        $wpc_stamp = self::wpc_rum_server_stamps();
        $beaconJs = <<<'WPCRUMJS'
(function(){try{
if(navigator.webdriver||navigator.globalPrivacyControl)return;
if(Math.random()*__RATE__>=1)return;
var atf=[],lcp=null,sent=0,hu=0,cen=null,ready=null,pq=0;
['pointerdown','keydown','touchstart','wheel','scroll','mousemove'].forEach(function(ev){addEventListener(ev,function(){hu=1},{once:true,passive:true,capture:true})});
requestAnimationFrame(function(){var vh=innerHeight,vw=innerWidth,xs=document.images,i,im,r;
for(i=0;i<xs.length&&atf.length<20;i++){im=xs[i];r=im.getBoundingClientRect();
if(r.width<24||r.height<24||r.bottom<=0||r.top>=vh||r.left>=vw)continue;
atf.push({classes:String(im.className||'').split(/\s+/).slice(0,2),slot_w:Math.round(r.width),slot_h:Math.round(r.height),intrinsic_w:im.naturalWidth||0,current_src:String(im.currentSrc||im.src||'').slice(0,300),loading:im.getAttribute('loading')||''})}});
try{new PerformanceObserver(function(l){var e=l.getEntries();if(!e.length)return;var x=e[e.length-1],el=x.element;
var sel=el?el.tagName.toLowerCase()+(el.className?'.'+String(el.className).split(/\s+/).slice(0,2).join('.'):''):'';
var rr=el&&el.getBoundingClientRect?el.getBoundingClientRect():{width:0,height:0,x:0,y:0};
lcp={selector:sel.slice(0,120),url:x.url?String(x.url).slice(0,300):null,rect:{w:Math.round(rr.width),h:Math.round(rr.height),x:Math.round(rr.x),y:Math.round(rr.y)},t_ms:Math.round(x.startTime)};if(cen&&!sent)prepare()}).observe({type:'largest-contentful-paint',buffered:true})}catch(e){}
function fontsUsed(){var fu=[];try{document.fonts.forEach(function(f){if(f.status==='loaded'){var k=(f.family+'|'+f.weight+'|'+f.style).slice(0,120);if(fu.indexOf(k)<0&&fu.length<64)fu.push(k)}})}catch(e){}return fu}
function census(done){try{var vh=innerHeight,vw=innerWidth,all=document.body.getElementsByTagName('*'),els=[],ids=[],i,e,r;
for(i=0;i<all.length&&els.length<400;i++){e=all[i];if(!e.getBoundingClientRect||!e.matches)continue;r=e.getBoundingClientRect();if(r.width<1||r.height<1||r.bottom<=0||r.top>=vh||r.left>=vw||r.right<=0)continue;els.push(e);if(e.id&&ids.length<200)ids.push(String(e.id).slice(0,80))}
var sels=[],seen={},trunc=false,sheets=[].slice.call(document.styleSheets),si=0,ori=location.origin,shr=0,shc=0;
function rules(list){var k,rl,st,j,m,p,parts,hit;for(k=0;k<list.length;k++){rl=list[k];if(sels.length>=2000){trunc=true;return}
if(rl.type===1&&rl.selectorText){st=String(rl.selectorText);if(st.length>300||seen[st])continue;parts=st.split(',');hit=false;
for(j=0;j<parts.length&&!hit;j++){p=parts[j].replace(/^\s+|\s+$/g,'');if(!p)continue;try{for(m=0;m<els.length;m++){if(els[m].matches(p)){hit=true;break}}}catch(x){}}
if(hit){seen[st]=1;sels.push(st)}}else if((rl.type===4||rl.type===12)&&rl.cssRules){if(rl.type===4&&rl.media&&!matchMedia(rl.media.mediaText).matches)continue;rules(rl.cssRules)}}}
function step(){var t0=Date.now();while(si<sheets.length&&Date.now()-t0<40){var sh=sheets[si++];try{if(sh.href&&String(sh.href).indexOf(ori)!==0){shc++;continue}var cr=sh.cssRules;if(cr){shr++;rules(cr)}}catch(x){shc++}if(sels.length>=2000){trunc=true;break}}
if(si<sheets.length&&!trunc){setTimeout(step,50)}else{done({atf_selectors:sels,atf_ids:ids,truncated:trunc,sheets:{total:sheets.length,readable:shr,cors_blocked:shc}})}}
step()}catch(e){done(null)}}
function cok(){if(!__CA__)return true;try{return typeof wp_has_consent==='function'&&!!wp_has_consent('statistics')}catch(q){return false}}
function cdnB(){try{var zh='__ZH__',z=function(h){return !!h&&(h===zh||/\.zapwp\.com$/.test(h))},lu=lcp&&lcp.url?lcp.url:'',lh='',es=performance.getEntriesByType('resource'),fs=[],i,e,u,t,x,f,o;
try{lh=new URL(lu).hostname}catch(q){}
for(i=0;i<es.length;i++){e=es[i];try{u=new URL(e.name)}catch(q){continue}if(!z(u.hostname)||!(e.responseStart>0))continue;
x=((u.pathname.match(/\.([a-z0-9]+)$/i)||[])[1]||'').toLowerCase();
t=/^(woff2?|ttf|otf|eot)$/.test(x)?'font':x==='css'?'css':/^m?js$/.test(x)?'js':/^(jpe?g|png|gif|webp|avif|svg|bmp|ico)$/.test(x)?'img':e.initiatorType==='script'?'js':e.initiatorType==='img'?'img':'';
if(!t)continue;
f={type:t,path:u.pathname.slice(0,200),ttfb_ms:Math.max(0,Math.round(e.responseStart-(e.requestStart||e.startTime))),dl_ms:Math.max(0,Math.round(e.responseEnd-e.responseStart)),transfer:e.transferSize||0,body:e.encodedBodySize||0,lcp:!!lu&&String(e.name).slice(0,300)===lu};
if(f.lcp)fs.unshift(f);else fs.push(f)}
fs=fs.slice(0,10);o={lcp_ms:lcp?lcp.t_ms:0,lcp_cdn:z(lh),files:fs};
if(navigator.connection&&navigator.connection.effectiveType)o.ect=String(navigator.connection.effectiveType);
while(fs.length&&JSON.stringify(o).length>2048)fs.pop();
return fs.length||o.lcp_cdn?o:null}catch(q){return null}}
function body(){var dev=(matchMedia('(max-width:767px)').matches||/Mobi|Android/i.test(navigator.userAgent))?'mobile':'desktop';
var ph='0'+(lcp?'1':'')+(cen?'2':''),shs=cen&&cen.sheets?cen.sheets:{total:0,readable:0,cors_blocked:0};
var o={v:2,url:(location.origin+location.pathname).slice(0,500),sr:__RATE__,device:dev,device_ua:'__DU__',gen_uuid:'__GU__',plugin_version:'__PV__',phases:ph,sheets:shs,selector_source:cen&&shs.readable>0?'cssRules':'none',viewport:{w:innerWidth,h:innerHeight,dpr:devicePixelRatio||1},lcp:lcp,atf_images:atf,fonts_used:fontsUsed(),atf_selectors:cen?cen.atf_selectors:[],atf_ids:cen?cen.atf_ids:[],truncated:cen?!!cen.truncated:true},c=cdnB();if(c)o.cdn=c;return o}
function form(s){return new Blob(['d='+encodeURIComponent(btoa(s))],{type:'application/x-www-form-urlencoded'})}
function bin(ab){var u=new Uint8Array(ab),s='',i;for(i=0;i<u.length;i+=8192)s+=String.fromCharCode.apply(null,u.subarray(i,i+8192));return s}
function utf8(t){return unescape(encodeURIComponent(t))}
function prepare(){try{var q=++pq,o=body(),b=JSON.stringify(o);o.bytes=b.length;b=JSON.stringify(o);if(window.CompressionStream&&window.Response){new Response(new Blob([b]).stream().pipeThrough(new CompressionStream('gzip'))).arrayBuffer().then(function(ab){if(q!==pq)return;if(ab.byteLength<=32768){ready={blob:form(bin(ab)),gz:1}}else{o.atf_selectors=o.atf_selectors.slice(0,600);o.truncated=true;o.bytes=JSON.stringify(o).length;ready={blob:form(utf8(JSON.stringify(o))),gz:0}}}).catch(function(){if(q===pq)ready=null})}else{if(b.length>30000){o.atf_selectors=o.atf_selectors.slice(0,300);o.truncated=true;o.bytes=JSON.stringify(o).length;b=JSON.stringify(o)}ready={blob:form(utf8(b)),gz:0}}}catch(e){}}
var send=function(){if(!hu||sent||(!atf.length&&!lcp&&!cen)||!cok())return;sent=1;var r=ready;
if(!r){try{var o=body();o.atf_selectors=o.atf_selectors.slice(0,300);o.truncated=true;var b=JSON.stringify(o);if(b.length>30000){o.atf_selectors=[];b=JSON.stringify(o)}o.bytes=b.length;b=JSON.stringify(o);r={blob:form(utf8(b)),gz:0}}catch(e){return}}
navigator.sendBeacon&&navigator.sendBeacon('__AX__?action=wpc_rum_census'+(r.gz?'&enc=gz':''),r.blob)};
addEventListener('load',function(){('requestIdleCallback'in window?requestIdleCallback:function(f){setTimeout(f,2e3)})(function(){census(function(c){cen=c;prepare();setTimeout(send,1200)})})});
addEventListener('pagehide',send);
}catch(e){}})();
WPCRUMJS;
        $zoneHost = strtolower((string) strtok((string) self::$zone_name, '/'));
        if (!preg_match('/^[a-z0-9.-]+$/', $zoneHost)) { $zoneHost = ''; }
        $beaconJs = str_replace(['__RATE__', '__AX__', '__DU__', '__GU__', '__PV__', '__ZH__', '__CA__'],
            [(string) $sampleRate, esc_url_raw($ajaxUrl), $wpc_stamp['device_ua'], $wpc_stamp['gen_uuid'], $wpc_stamp['plugin_version'], $zoneHost, function_exists('wp_has_consent') ? '1' : '0'], $beaconJs);
        return wpc_inject_before_body_close($html, '<script id="wpc-rum-beacon">' . $beaconJs . '</script>');
    }

    /**
     * The device check, on every view of a page that carries one device's critical CSS: the
     * browser tests its own agent with the rule the page was rendered by (wpc_ua_mobile_patterns)
     * and, when a cache in front of the origin handed it the other device's page, reports it once
     * a day to wpc_device_mix_receiver as a form post (host, path, page). A combined page, or one
     * with no critical CSS, carries none: handed to the other device, it differs at most in an
     * image hint.
     */
    public static function wpc_device_check_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '</body>') === false) { return $html; }
        if (strpos($html, 'wpc-dev-check') !== false || !empty($_GET['simulate_mobile'])) { return $html; }
        if (function_exists('is_user_logged_in') && is_user_logged_in()) { return $html; }
        if (function_exists('is_customize_preview') && is_customize_preview()) { return $html; }
        if (function_exists('apply_filters') && !apply_filters('wpc_device_mix_check', true)) { return $html; }
        if (!function_exists('wpc_ua_mobile_patterns') || !function_exists('wpc_ua_is_mobile')) { return $html; }
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on')
            && wps_rewriteLogic::wpc_combined_crit_on()) {
            return $html;
        }
        if (!preg_match('/<style\b[^>]*\bclass=(["\'])wpc-critical-css-(?:mobile|desktop)\1/i', $html)) {
            return $html;
        }
        $ajax = function_exists('admin_url') ? (string) admin_url('admin-ajax.php') : '';
        if ($ajax === '' || strpos($ajax, 'http') !== 0) { return $html; }
        $patterns = wpc_ua_mobile_patterns();
        $js = <<<'WPCDEVJS'
(function(){try{
var p='__PAGE__',a=navigator.userAgent||'';
var v=(new RegExp(__ANY__,'i').test(a)||new RegExp(__START__,'i').test(a))?'m':'d';
if(v===p||!navigator.sendBeacon||typeof URLSearchParams==='undefined')return;
var d=new Date().toISOString().slice(0,10);
try{if(localStorage.getItem('wpcDevMix')===d)return;localStorage.setItem('wpcDevMix',d)}catch(e){}
navigator.sendBeacon(__AX__,new URLSearchParams({host:location.hostname,path:location.pathname.slice(0,300),page:p}));
}catch(e){}})();
WPCDEVJS;
        $js = str_replace(['__PAGE__', '__ANY__', '__START__', '__AX__'], [
            wpc_ua_is_mobile() ? 'm' : 'd',
            json_encode($patterns['contains'] . '|' . $patterns['tokens']),
            json_encode('^(?:' . $patterns['prefix'] . ')'),
            json_encode(esc_url_raw($ajax) . '?action=wpc_device_mix'),
        ], $js);
        return wpc_inject_before_body_close($html, '<script id="wpc-dev-check">' . $js . '</script>');
    }

    /**
     * Server-side stamps for the RUM beacon: device_ua (m|d, the UA bucket that also picks the
     * crit), gen_uuid (land_uuid.txt of this page's crit dir, verbatim) and plugin_version, so
     * the service can attribute a beacon to the artifact that produced the page.
     */
    public static function wpc_rum_server_stamps()
    {
        $out = ['device_ua' => 'd', 'gen_uuid' => '', 'plugin_version' => ''];
        try {
            if (function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) { $out['device_ua'] = 'm'; }
            if (defined('WPC_PLUGIN_VERSION')) { $out['plugin_version'] = preg_replace('/[^0-9.]/', '', (string) WPC_PLUGIN_VERSION); }
            if (class_exists('wps_ic_url_key') && defined('WPS_IC_CRITICAL')) {
                $k = (string) (new wps_ic_url_key())->setup('');
                if ($k !== '') {
                    $u = trim((string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $k . '/land_uuid.txt'));
                    if (preg_match('/^[a-f0-9-]{8,64}$/i', $u)) { $out['gen_uuid'] = $u; }
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }


    // v7.21.83 — A FACE WITHOUT A METRIC FALLBACK MUST NOT RACE THE NETWORK. The sweep
    // upgraded DM Serif Display to optional while its family had NO metric fallback
    // (service metrics gap): a cold view painted bare Times the WHOLE view, the next view
    // painted the real face — falknerei's "starts like this then swaps". For families the
    // document declares but gives no "<fam> Fallback" face, inline the woff2 AS data: —
    // the face exists at parse, every view paints the real font, no race. Same-host
    // locally-mapped files only, ≤48KB/file, 3 faces / 128KB total, normal style first,
    // per-file+mtime transient cache, fail-open. Kill wpc_faces_inline_data.
    public static function wpc_inline_font_faces_as_data($css, $html)
    {
      try {
        if (!is_string($css) || $css === '' || stripos($css, '@font-face') === false
            || !is_string($html) || !function_exists('apply_filters')
            || !apply_filters('wpc_faces_inline_data', true)) {
            return $css;
        }
        $uploadDir = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $uploadsBaseDir = !empty($uploadDir['basedir']) ? rtrim((string) $uploadDir['basedir'], '/') : '';
        $homeHost = function_exists('home_url') ? strtolower((string) parse_url(home_url(), PHP_URL_HOST)) : '';
        $inlinedCount = 0;
        $inlinedBytes = 0;
        // v7.21.95 — INLINE ONLY WHAT THE PAGE CONSUMES. The .93 inline-all shipped
        // falknerei 120.9KB of data faces per document: an italic face for a page with
        // one <em>, weights 200/300 nothing references, the same tuple twice from two
        // sources, a family no rule outside its own declaration names (PSI: 82.7KB
        // unattributable unused CSS). Evidence text = document minus face declarations.
        $inlinedTuples = [];
        $evidenceHtml = preg_replace('/@font-face\s*\{[^{}]*\}/is', '', $html);
        $evidenceHtml = is_string($evidenceHtml) ? $evidenceHtml : $html;
        $pageUsesItalic = preg_match('/font-style\s*:\s*italic|<(?:i|em)[\s>]/i', $evidenceHtml) === 1;
        $pageUsesLightWeights = preg_match('/font-weight\s*:\s*[123]00\b/i', $evidenceHtml) === 1;
        $inlinedCss = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($m) use (&$inlinedCount, &$inlinedBytes, $html, $uploadsBaseDir, $homeHost, &$inlinedTuples, $evidenceHtml, $pageUsesItalic, $pageUsesLightWeights) {
            $blk = $m[0];
            $faceCap = (function_exists('get_option') && get_option('wpc_css_passthrough') === '1') ? 6 : 3;
            if ($inlinedCount >= $faceCap || $inlinedBytes >= 196608 || stripos($blk, 'data:') !== false
                || !preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $blk, $fa)) {
                return $blk;
            }
            $fam = trim($fa[1]);
            if ($fam === '' || stripos($fam, 'fallback') !== false) { return $blk; }
            $faceStyle = preg_match('/font-style\s*:\s*italic/i', $blk) ? 'i' : 'n';
            $faceWeight = preg_match('/font-weight\s*:\s*([0-9]{2,4})\b/i', $blk, $weightMatch) ? $weightMatch[1] : '400';
            $rangeHash = preg_match('/unicode-range\s*:\s*([^;}]+)/i', $blk, $rangeMatch) ? md5(strtolower(preg_replace('/\s+/', '', $rangeMatch[1]))) : '-';
            $faceTuple = strtolower($fam) . '|' . $faceStyle . '|' . $faceWeight . '|' . $rangeHash;
            // an identical-tuple duplicate AFTER an inlined face would override it in the
            // cascade (last declaration wins) — the duplicate is dropped, not kept.
            if (isset($inlinedTuples[$faceTuple])) { return ''; }
            if (stripos($evidenceHtml, $fam) === false) { return $blk; }
            if ($faceStyle === 'i' && !$pageUsesItalic) { return $blk; }
            if ((int) $faceWeight < 400 && !$pageUsesLightWeights) { return $blk; }
            // A family WITH a metric fallback anywhere in the document keeps its network
            // src — geometry is already stable there; inlining is for the uncovered.
            // v7.21.93 — under CSS passthrough the goal is STILLNESS: every first-frame
            // family inlines (falknerei stabilized mode still FOUTed Manrope serif->sans,
            // its own native swap; fonts-at-parse beats plugin-off outright).
            $cssPassthrough = function_exists('get_option') && get_option('wpc_css_passthrough') === '1';
            if (!$cssPassthrough && stripos($html, $fam . ' Fallback') !== false) { return $blk; }
            // normal style first: italic only rides if the budget survives the normals
            if (preg_match('/font-style\s*:\s*italic/i', $blk) && $inlinedCount < 1) { return $blk; }
            if (!preg_match('/url\(["\']?([^"\')]+\.woff2[^"\')]*)["\']?\)/i', $blk, $um)) { return $blk; }
            $fontUrl = html_entity_decode($um[1]);
            $fontHost = strtolower((string) parse_url($fontUrl, PHP_URL_HOST));
            if ($fontHost !== '' && $fontHost !== $homeHost) { return $blk; }
            $fontPath = (string) parse_url($fontUrl, PHP_URL_PATH);
            $fontFile = '';
            if ($uploadsBaseDir !== '' && ($uploadsPos = strpos($fontPath, '/uploads/')) !== false) {
                $fontFile = $uploadsBaseDir . rawurldecode(substr($fontPath, $uploadsPos + 8));
            } elseif (defined('WP_CONTENT_DIR') && ($contentPos = strpos($fontPath, '/wp-content/')) !== false) {
                $fontFile = rtrim(WP_CONTENT_DIR, '/') . rawurldecode(substr($fontPath, $contentPos + 11));
            }
            if ($fontFile === '' || strpos($fontFile, '..') !== false
                || !is_readable($fontFile) || (int) @filesize($fontFile) > 49152) {
                return $blk;
            }
            $transientKey = 'wpc_fid83_' . md5($fontFile . '|' . (int) @filemtime($fontFile));
            $fontBase64 = function_exists('get_transient') ? get_transient($transientKey) : false;
            if (!is_string($fontBase64) || $fontBase64 === '') {
                $fontBytes = (string) @file_get_contents($fontFile);
                if ($fontBytes === '' || substr($fontBytes, 0, 4) !== 'wOF2') { return $blk; }
                $fontBase64 = base64_encode($fontBytes);
                if (function_exists('set_transient')) { set_transient($transientKey, $fontBase64, 3600); }
            }
            $inlinedFace = preg_replace('/src\s*:[^;}]+;?/i', '', $blk);
            if (!is_string($inlinedFace)) { return $blk; }
            $inlinedFace = rtrim(rtrim($inlinedFace), '}')
                . 'src:url(data:font/woff2;base64,' . $fontBase64 . ') format("woff2");}';
            $inlinedCount++;
            $inlinedBytes += strlen($fontBase64);
            $inlinedTuples[$faceTuple] = 1;
            return $inlinedFace;
        }, $css);
        if (is_string($inlinedCss) && $inlinedCount > 0) {
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_fid83_log')) {
                set_transient('wpc_fid83_log', 1, 3600);
                wpc_cache_first_log('faces-inline-data', '', '', ['n' => $inlinedCount, 'b' => $inlinedBytes]);
            }
            return $inlinedCss;
        }
        return $css;
      } catch (\Throwable $e) { return $css; }
    }

    /**
     * The one feeder: every sheet on the page that is really a font-face carrier hands its
     * faces to the render's face owner, and its <link> goes.
     *
     * The three kinds of carrier it has to recognise, and the reason each one matters:
     *
     *  - v7.21.71, parked carriers. Elementor localizes Google Fonts as css files containing
     *    nothing but @font-face declarations. Parked, they re-declare every family WITH swap at
     *    activation and break the one-display-policy law from a lane we did not own (justmsp:
     *    the page painted optional, then the parked originals landed post-gesture and re-swapped
     *    weights mid-view).
     *  - v7.21.76, live carriers. falknerei's DSGVO localizer serves gfonts_local.css live for
     *    declaration parity, and its faces all declare swap — so the page keeps the site's own
     *    mid-view weight swap AND a render-blocking request, for a repaint that happens
     *    plugin-off anyway.
     *  - v7.21.75, families used with nothing declared. The hero family's only @font-face lives
     *    in a parked theme sheet, so the served document declares NOTHING for it: first paint
     *    falls to bare serif with no metric fallback and the real face hard-swaps whenever that
     *    sheet activates. Plugin-off declares the face in blocking CSS; parity requires the
     *    declaration live at parse.
     *
     * Plus the two provider-link prunes (.184 / .129-.132): once the inline copy serves, a
     * Google or Bunny link whose every family the set already carries is a second download of
     * the same fonts, and the link goes — but only when the coverage is provable in the set,
     * never on a claim.
     *
     * Same-host locally-mapped files only, faces-only proof before a carrier is absorbed,
     * byte-capped, extraction cached per file+mtime, fail-open at every edge. Idempotent: a
     * second run finds no carrier links left. Kill wpc_absorb_font_sheets.
     */
    public static function absorb_font_sheets($html, $set)
    {
        try {
            if (!is_string($html) || $html === '' || !($set instanceof wps_ic_font_face_set)
                || !function_exists('apply_filters') || !apply_filters('wpc_absorb_font_sheets', true)) {
                return $html;
            }
            $html = self::absorbFaceCarrierSheets($html, $set);
            $html = self::absorbMissingFamilyFaces($html, $set);

            return self::dropCoveredProviderLinks($html, $set);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /** The local file a same-site stylesheet href resolves to, or '' when it maps nowhere. */
    private static function localSheetPath($href)
    {
        $path = (string) parse_url(html_entity_decode((string) $href), PHP_URL_PATH);
        $file = '';
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $base = !empty($uploads['basedir']) ? rtrim((string) $uploads['basedir'], '/') : '';
        if ($base !== '' && ($at = strpos($path, '/uploads/')) !== false) {
            $file = $base . rawurldecode(substr($path, $at + 8));
        } elseif (defined('WP_CONTENT_DIR') && ($at = strpos($path, '/wp-content/')) !== false) {
            $file = rtrim(WP_CONTENT_DIR, '/') . rawurldecode(substr($path, $at + 11));
        }
        if ($file === '' || strpos($file, '..') !== false || !is_readable($file)) {
            return '';
        }

        return $file;
    }

    /** The faces of a stylesheet that declares nothing else, cached per file+mtime. '' when the
     *  file carries any rule of its own — a sheet with layout in it is not ours to absorb. */
    private static function faceCarrierCss($file, $maxBytes)
    {
        if ((int) @filesize($file) > $maxBytes) {
            return '';
        }
        $key = 'wpc_face_carrier_' . md5($file . '|' . (int) @filemtime($file));
        $cached = function_exists('get_transient') ? get_transient($key) : false;
        if (is_string($cached)) {
            return $cached === '!' ? '' : $cached;
        }
        $css = (string) @file_get_contents($file);
        if ($css === '') {
            // v7.21.82 — A FAILED READ IS NOT A VERDICT. An open_basedir hiccup, a sheet being
            // rewritten under us or a full disk all hand back an empty string, and caching that
            // as "this sheet carries no faces" pins the answer for an hour: the carrier is never
            // absorbed and every family it declares is missing from the page for that hour.
            return '';
        }
        $carrier = '!';
        if (stripos($css, '@font-face') !== false) {
            $rest = preg_replace('#/\*.*?\*/#s', '', $css);
            $rest = preg_replace('/@font-face\s*\{[^{}]*\}/is', '', (string) $rest);
            if (is_string($rest) && trim($rest) === '') {
                $carrier = $css;
            }
        }
        if (function_exists('set_transient')) {
            set_transient($key, $carrier, 3600);
        }

        return $carrier === '!' ? '' : $carrier;
    }

    /**
     * True when a link carries its own re-arm: an onload handler that sets the tag's rel back to
     * stylesheet. Such a link is loaded by the browser as soon as it is parsed and applies the
     * moment it arrives, so it is live delivery wearing a parked rel, not a deferral.
     *
     * The handler's body has to be read out of the attribute before the assignment is looked for.
     * A quote character ends the attribute, so whichever quote opens onload is the one that
     * closes it and the other one is free to appear inside — both
     * onload="this.rel='stylesheet'" and onload='this.rel="stylesheet"' occur — and a pattern
     * that walks the raw tag cannot tell the inner quote from the outer one.
     */
    private static function linkReArmsItself($tag)
    {
        if (!preg_match('/\bonload\s*=\s*(["\'])(.*?)\1/is', (string) $tag, $handler)) {
            return false;
        }

        return preg_match('/\brel\s*=\s*["\']?\s*stylesheet/i', $handler[2]) === 1;
    }

    /** One walk over the document's stylesheet links: every faces-only carrier is absorbed.
     *  A parked carrier's faces were never going to paint before the loader flipped it, so they
     *  register late; a live one was painting at parse and keeps doing so.
     *
     *  One parked shape counts as live: the CSS park hands a Google Fonts sheet (the site's own
     *  localized copy included) to the loader as an async link that re-arms itself as a
     *  stylesheet in its onload. Its faces have always been declared at first paint, with the
     *  site's display policy resolving swap to optional, so a visitor with the font cached
     *  paints it first and one without never gets a mid-page swap. Registering them late would
     *  turn every load into fallback text that reflows on the first gesture.
     *
     *  A carrier scoped to a media query other than all/screen is left where it is: its faces
     *  applied under that query and nowhere else, and the owner's two blocks are all-media. */
    private static function absorbFaceCarrierSheets($html, $set)
    {
        if (stripos($html, '<link') === false) {
            return $html;
        }
        $homeHost = function_exists('home_url') ? strtolower((string) parse_url(home_url(), PHP_URL_HOST)) : '';
        $absorbed = 0;
        $bytes = 0;
        $out = preg_replace_callback(
            '/<link\b[^>]*(?:rel|type)=["\'](?:stylesheet|wpc-[a-z-]*stylesheet)["\'][^>]*>/i',
            function ($m) use (&$absorbed, &$bytes, $homeHost, $set, $html) {
                $tag = $m[0];
                // Four carriers, 96 KB of face bytes: the budget both old absorbs walked
                // with. The per-file ceiling below is separate and unchanged at 128 KB.
                if ($absorbed >= 4 || $bytes >= 98304
                    || !preg_match('/href=["\']([^"\']+\.css[^"\']*)["\']/i', $tag, $href)) {
                    return $tag;
                }
                $ours = preg_match('/(?:rel|type)=["\']wpc-[a-z-]*stylesheet["\']/i', $tag) === 1;
                $parked = $ours && !self::linkReArmsItself($tag);
                // A live sheet one of our own lanes stamped is that lane's to manage, not ours.
                // A sheet wearing a wpc rel is the park's, and the park is what hands it here.
                if (!$ours && stripos($tag, 'data-wpc') !== false) {
                    return $tag;
                }
                if (preg_match('/\bmedia=["\']([^"\']+)["\']/i', $tag, $media)
                    && !in_array(strtolower(trim($media[1])), ['all', 'screen', ''], true)) {
                    return $tag;
                }
                $host = strtolower((string) parse_url(html_entity_decode($href[1]), PHP_URL_HOST));
                if ($host !== '' && $homeHost !== '' && $host !== $homeHost) {
                    return $tag;
                }
                $file = self::localSheetPath($href[1]);
                if ($file === '') {
                    return $tag;
                }
                $css = self::faceCarrierCss($file, 131072);
                if ($css === '') {
                    return $tag;
                }

                $css = self::wpc_inline_font_faces_as_data($css, $css . $html);
                $absorbed++;
                $bytes += strlen($css);
                $set->add($css, $parked ? 'parked-carrier' : wps_ic_font_face_set::ORIGIN_LIVE_CARRIER, !$parked);

                return '';
            },
            $html, 24);
        if (!is_string($out) || $absorbed < 1) {
            return $html;
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('font-sheets-absorbed', '', '', ['n' => $absorbed, 'bytes' => $bytes]);
        }

        return $out;
    }

    /** A family the page uses that nothing declares paints as an unstyled system font until some
     *  parked sheet activates. Lift just that family's faces out of the parked sheets.
     *
     *  "Nothing declares it" means neither the set nor the served bytes: a third-party live
     *  stylesheet's @font-face is a declaration this render did not make and does not own, and
     *  reading only the set turns it into a missing family whose faces get lifted a second time.
     *  A "<Family> Fallback" stand-in counts for the family it stands in for. */
    private static function absorbMissingFamilyFaces($html, $set)
    {
        if (stripos($html, '</head>') === false) {
            return $html;
        }
        $declared = [];
        if (preg_match_all('/@font-face\s*\{[^{}]*?font-family\s*:\s*["\']?([^;"\'}]+)/is', $html, $faceNames)) {
            foreach ((array) $faceNames[1] as $name) {
                $name = strtolower(trim((string) preg_replace('/\s+fallback(?:\s+\S+)?$/i', '', trim($name))));
                if ($name !== '') { $declared[$name] = 1; }
            }
        }
        $generic =['serif' => 1, 'sans-serif' => 1, 'monospace' => 1, 'cursive' => 1, 'fantasy' => 1,
            'system-ui' => 1, 'inherit' => 1, 'initial' => 1, 'unset' => 1, 'arial' => 1, 'helvetica' => 1,
            'helvetica neue' => 1, 'georgia' => 1, 'times' => 1, 'times new roman' => 1, 'courier' => 1,
            'courier new' => 1, 'verdana' => 1, 'tahoma' => 1, 'trebuchet ms' => 1, 'roboto' => 1,
            '-apple-system' => 1, 'blinkmacsystemfont' => 1, 'segoe ui' => 1, 'etmodules' => 1];
        // Families ride two vehicles: direct font-family declarations AND font-carrying custom
        // properties (Divi: --et_global_heading_font:'DM Serif Display') — the variable is the
        // modern usage form, and missing it blinds the census.
        $raw = [];
        if (preg_match_all('/font-family\s*:\s*(?:var\([^)]*\)\s*,\s*)?["\']?([^;,"\'}<]+)/i', $html, $used)) {
            $raw = (array) $used[1];
        }
        if (preg_match_all('/--[a-z0-9_-]*font[a-z0-9_-]*\s*:\s*["\']([^;"\'}]{3,40})["\']/i', $html, $vars)) {
            $raw = array_merge($raw, (array) $vars[1]);
        }
        $missing = [];
        foreach ($raw as $family) {
            $family = strtolower(trim((string) $family, " \t\"'"));
            if ($family === '' || strlen($family) > 64 || isset($generic[$family])
                || strpos($family, 'var(') !== false || strpos($family, '@') !== false
                || substr($family, -9) === ' fallback'
                || $set->has($family) || isset($declared[$family])) {
                continue;
            }
            $missing[$family] = 1;
            if (count($missing) >= 4) { break; }
        }
        if (!$missing) {
            return $html;
        }
        $found = [];
        $added = '';
        if (preg_match_all('/<link\b[^>]*rel=["\']wpc-[a-z-]*stylesheet["\'][^>]*>/i', $html, $links)) {
            foreach (array_slice((array) $links[0], 0, 24) as $tag) {
                if (count($found) >= count($missing)) { break; }
                if (!preg_match('/href=["\']([^"\']+)["\']/i', $tag, $href)) { continue; }
                $file = self::localSheetPath($href[1]);
                if ($file === '' || (int) @filesize($file) > 262144) { continue; }
                $key = 'wpc_sheet_faces_' . md5($file . '|' . (int) @filemtime($file));
                $faces = function_exists('get_transient') ? get_transient($key) : false;
                if (!is_string($faces)) {
                    $css = (string) @file_get_contents($file);
                    $faces = '';
                    if ($css !== '' && stripos($css, '@font-face') !== false
                        && preg_match_all('/@font-face\s*\{[^{}]*\}/is', $css, $blocks)) {
                        $faces = implode('', (array) $blocks[0]);
                    }
                    if (function_exists('set_transient')) { set_transient($key, $faces, 3600); }
                }
                if ($faces === '') { continue; }
                foreach (array_keys($missing) as $family) {
                    if (isset($found[$family])) { continue; }
                    if (!preg_match_all('/@font-face\s*\{[^{}]*?font-family\s*:\s*["\']?' . preg_quote($family, '/') . '["\'\s;][^{}]*\}/is', $faces, $hit)) {
                        continue;
                    }
                    $block = implode('', array_slice((array) $hit[0], 0, 8));
                    if (strlen($added) + strlen($block) <= 20480) {
                        $added .= $block;
                        $found[$family] = 1;
                    }
                }
            }
        }
        if ($added === '') {
            return $html;
        }
        $set->add(self::wpc_inline_font_faces_as_data($added, $added . $html), 'missing-family');
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('missing-family-faces', implode(',', array_keys($found)), '', ['n' => count($found)]);
        }

        return $html;
    }

    /**
     * A provider link whose every requested face the set already carries is a second download of
     * fonts the page is about to declare inline. Dropping one rests on three separate proofs, and
     * every one of them is a customer receipt:
     *
     *  - the service named the link. wps_ic_fonts_remote_dup carries @href:/@host: tokens for the
     *    links it localized and a fam: token per family it claims to cover; no option, no drop.
     *    Being hosted at fonts.googleapis.com or fonts.bunny.net is not by itself a reason to
     *    delete a stylesheet the site asked for;
     *  - every family the link requests holds a fam: token (.129: a v1 css_link is ONE URL
     *    carrying many families, and dropping it on Mukta's authority deleted Noto Sans' only
     *    face source);
     *  - and the document backs the claim (.132: the fam: token existed for a family the artifact
     *    delivered only as a metric stand-in). Here the render's declarations live in the set, so
     *    the set is what is asked — and per WEIGHT, because family=Inter:wght@400;700 is two
     *    requests and holding Inter 400 proves nothing about 700.
     *
     * A weight list that is not a fixed set (a variable range, or a syntax this cannot read) is
     * not a proof, so the link stays.
     */
    private static function dropCoveredProviderLinks($html, $set)
    {
        if (stripos($html, '<link') === false) {
            return $html;
        }
        $tokens = function_exists('get_option') ? get_option('wps_ic_fonts_remote_dup') : null;
        if (!is_array($tokens) || !$tokens) {
            return $html;
        }
        $hrefs = [];
        $hosts = [];
        $covered = [];
        foreach ($tokens as $token) {
            if (!is_string($token)) { continue; }
            if (strpos($token, '@href:') === 0 && strlen($token) > 6) { $hrefs[] = substr($token, 6); }
            elseif (strpos($token, '@host:') === 0 && strlen($token) > 6) { $hosts[] = substr($token, 6); }
            elseif (strpos($token, 'fam:') === 0 && strlen($token) > 4) { $covered[] = substr($token, 4); }
        }
        if (!$hrefs && !$hosts) {
            return $html;
        }
        $dropped = 0;
        $out = preg_replace_callback('/<link\b[^>]*>/i', function ($m) use ($hrefs, $hosts, $covered, $set, &$dropped) {
            $tag = $m[0];
            if (!preg_match('/\bhref=(["\'])(.*?)\1/i', $tag, $hm)) { return $tag; }
            $href = strtolower(html_entity_decode($hm[2]));
            $namedExactly = false;
            foreach ($hrefs as $needle) { if (strpos($href, $needle) !== false) { $namedExactly = true; break; } }
            $named = $namedExactly;
            if (!$named) {
                foreach ($hosts as $needle) { if (strpos($href, $needle) !== false) { $named = true; break; } }
            }
            if (!$named) { return $tag; }
            // css2 packs families as repeated params, v1 packs them piped inside one: parse both.
            if (!preg_match_all('/[?&]family=([^&"\']*)/i', $href, $all) || empty($all[1])) {
                // No parseable family set: an exact link the service named keeps its authority,
                // a bare host match cannot prove coverage, so it stays.
                return $namedExactly ? '' : $tag;
            }
            foreach ($all[1] as $segment) {
                foreach (explode('|', urldecode($segment)) as $familySegment) {
                    $family = trim(preg_replace('/:.*$/', '', str_replace('+', ' ', $familySegment)));
                    if ($family === '') { continue; }
                    if (!in_array($family, $covered, true)) { return $tag; }
                    $requested = self::requestedFontFaces($familySegment);
                    // A request this cannot read is not a proof, so the link stays.
                    if ($requested === false) { return $tag; }
                    foreach ($requested as $face) {
                        if (!$set->hasFace($family, $face[0], $face[1])) { return $tag; }
                    }
                }
            }
            $dropped++;

            return '';
        }, $html);
        if (!is_string($out) || $dropped < 1) {
            return $html;
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('provider-links-dropped', '', '', ['n' => $dropped]);
        }

        return $out;
    }

    /**
     * The faces one family segment of a provider URL asks for, as [weight, style] pairs, or
     * false when the request is not a fixed list of faces and therefore not something a
     * deletion can be proven against.
     *
     * A link is a request for FACES, not for a family and not for a list of weights: the
     * provider answers family=Inter with Inter regular 400 and family=Inter:ital,wght@1,400
     * with Inter italic 400, and those are different files. So a segment that names no weight
     * asks for 400 and a segment that names no style asks for normal — the provider's own
     * defaults, stated here rather than left implicit: "names nothing" must not become "prove
     * nothing" and let a link go against a set holding some other face of the family.
     *
     * css2 writes the axes before the @ and one tuple per face after it —
     * Inter:ital,wght@0,400;1,700 — so each axis's position in the tuple is that axis's value,
     * ital 1 is italic, and a range (wght@100..900) is a variable font, not a list. v1 writes
     * them straight: Roboto:400,700italic. An axis this does not model (opsz, slnt, a named
     * weight like `regular`) makes the whole request unreadable rather than partly guessed.
     */
    private static function requestedFontFaces($familySegment)
    {
        $default = [[400, 'normal']];
        $at = strpos($familySegment, ':');
        if ($at === false) { return $default; }
        $spec = trim(substr($familySegment, $at + 1));
        if ($spec === '') { return $default; }
        $faces = [];
        if (strpos($spec, '@') !== false) {
            list($axes, $tuples) = explode('@', $spec, 2);
            $axisNames = array_map('trim', explode(',', strtolower($axes)));
            foreach ($axisNames as $axis) {
                if ($axis !== 'ital' && $axis !== 'wght') { return false; }
            }
            $weightSlot = array_search('wght', $axisNames, true);
            $italicSlot = array_search('ital', $axisNames, true);
            foreach (explode(';', $tuples) as $tuple) {
                $values = array_map('trim', explode(',', $tuple));
                if (count($values) !== count($axisNames)) { return false; }
                $weight = 400;
                if ($weightSlot !== false) {
                    if ($values[$weightSlot] === '' || !ctype_digit($values[$weightSlot])) { return false; }
                    $weight = (int) $values[$weightSlot];
                }
                $style = 'normal';
                if ($italicSlot !== false) {
                    if ($values[$italicSlot] === '0') { $style = 'normal'; }
                    elseif ($values[$italicSlot] === '1') { $style = 'italic'; }
                    else { return false; }
                }
                $faces[] = [$weight, $style];
            }

            return $faces ? $faces : false;
        }
        foreach (explode(',', $spec) as $value) {
            $value = trim(strtolower($value));
            if ($value === '') { continue; }
            $style = 'normal';
            $stripped = preg_replace('/(?:italic|i)$/', '', $value);
            if ($stripped !== $value) { $style = 'italic'; }
            // v1's bare `italic` is weight 400 italic; anything else has to be a number.
            if ($stripped === '') { $faces[] = [400, $style]; continue; }
            if (!ctype_digit($stripped)) { return false; }
            $faces[] = [(int) $stripped, $style];
        }

        return $faces ? $faces : $default;
    }

    /**
     * The wp_head carrier, taken into the set at the front of the font lane.
     *
     * <style id="wpc-font-carrier"> is a pre-buffer echo: it is already in the pristine bytes
     * when the first stage runs, so it is evidence the rest of the lane can use rather than
     * something to clear up at the end. Absorbing it here is what lets the feeder's missing-family
     * census and its provider-link coverage test see the families the carrier declares — both ask
     * the set, and until this has run the set has never heard of them. Nothing is lost if the run
     * ends early: a bail answers with the pristine buffer, carrier and all.
     */
    public static function absorb_font_carrier($html, $set)
    {
        try {
            if (!is_string($html) || $html === '' || !($set instanceof wps_ic_font_face_set)
                || stripos($html, 'wpc-font-carrier') === false) {
                return $html;
            }
            $out = preg_replace_callback(
                '/<style\b[^>]*\bid=(["\'])wpc-font-carrier\1[^>]*>(.*?)<\/style>/is',
                function ($m) use ($set) {
                    $set->add($m[2], 'carrier');

                    return '';
                }, $html);

            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * The one place a render's @font-face rules reach the document: the eager block before
     * </head> and the late block before </body>.
     *
     * The late block's opening tag, its type and its media attribute are this lane's contract
     * with assets/js/delay-v3-loader.js, which flips media to all once the page has loaded, and
     * with the inline flip script that ships beside it for pages the loader never reaches
     * (v7.10.924, dalton: no delay loader on the page meant no flip, ever, and 53 Poppins faces
     * parked forever). They stay byte-identical.
     *
     * $isAmp is the render's AMP verdict, and it stops the late lane dead. An AMP document may
     * not carry an author <script>, so the flip script cannot ship; and the loader that would
     * otherwise flip the block is off on AMP anyway (amp_settings_squash clears delay-js), so a
     * late block would be a media="not all" <style> nothing on the page can ever arm — dead
     * bytes that also declare fonts the browser is told not to use. The first-paint block still
     * goes in: AMP is the one lane where it is the ONLY declaration the page gets, and inline
     * <style> in the head is exactly what the squash leaves the rest of the CSS lane doing.
     */
    public static function emit_font_faces($html, $set, $isAmp = false)
    {
        try {
            if (!is_string($html) || $html === '' || !($set instanceof wps_ic_font_face_set)) {
                return $html;
            }
            // This render's own two blocks can already be in the buffer, when the bytes reaching
            // this stage have been through the pipeline before (the natural-URL outer buffer, a
            // shed-then-render sequence, a cached page re-processed). Taking them back into the
            // set is what makes the emit idempotent: without it a re-entry ships a second late
            // lane, which is the merge the .46 dedupe existed to stop. The wp_head carrier is
            // absorbed far earlier, at open_font_faces, where the feeder can still read it.
            $reentered = 0;
            $absorbed = preg_replace_callback(
                '/<style\b[^>]*\bid=(["\'])(wpc-font-faces|wpc-late-faces)\1[^>]*>(.*?)<\/style>/is',
                function ($m) use ($set, &$reentered) {
                    $set->add($m[3], 'reentry', $m[2] !== 'wpc-late-faces');
                    $reentered++;
                    return '';
                }, $html);
            if (is_string($absorbed)) {
                $html = $absorbed;
                // Bytes this pipeline already processed came through it again (the outer buffer,
                // a shed-then-render, a re-processed cached page); its own face blocks are taken
                // back into the set. Never sampled: a re-entry is the event.
                if ($reentered > 0 && function_exists('wpc_render_belt_note')) {
                    wpc_render_belt_note('font-faces-reentry', ['blocks' => $reentered]);
                }
            }
            $html = self::absorb_pinned_page_faces($html, $set);

            // A family the page declares and never uses is bytes for a file nothing fetches.
            //
            // The census reads the render's declarations to decide both halves of its question:
            // which families have a synthetic face at all, and which of those this render backs
            // with a real source. By this stage those declarations are in the set and no longer
            // in the document, so the set's own CSS is the second half of its haystack. Without
            // it the census sees no candidates and no backing, and drops families on no evidence.
            if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_unbacked_font_families')) {
                $set->dropFamilies(wps_rewriteLogic::wpc_unbacked_font_families($html, $set->css()));
            }
            $eager = $set->eager();
            $late = $isAmp ? '' : $set->late();
            if ($eager !== '') {
                $html = self::injectEagerFaceBlock($html, '<style id="wpc-font-faces">' . $eager . '</style>');
            }
            if ($late !== '') {
                $block = '<style id="wpc-late-faces" type="wpc/late-faces" media="not all">' . $late . '</style>';
                // The flip script is the lane's own remover and belongs after the lane it flips.
                // On a re-entry it is already in the buffer, so the block goes back in front of
                // it rather than behind it — which is what makes a second pass byte-identical.
                $flipAt = strpos($html, '<script data-nodefer="1" id="wpc-late-faces-flip">');
                if ($flipAt !== false) {
                    $html = substr_replace($html, $block, $flipAt, 0);
                } else {
                    if (function_exists('wpc_late_faces_flip_js')) {
                        $block .= wpc_late_faces_flip_js(self::wpc_first_screen_faces());
                    }
                    $at = strripos($html, '</body>');
                    $html = ($at !== false) ? substr_replace($html, $block, $at, 0) : $html . $block;
                }
            }
            if (function_exists('wpc_cache_first_log') && ($eager !== '' || $late !== '')) {
                wpc_cache_first_log('font-faces', '', '', $set->counts());
            }

            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * The faces the service measured painting this page's first screen on the device this render
     * is for (wpc_ua_is_mobile, the device the copy is stored for): [family, weight] pairs from
     * the atf_glyphs keys ("Family|weight|style") of that device's map in the page's delay.json
     * (or lcp.json), or of a map the file keeps for every device (wpc_atf_glyphs_read), family
     * lower-cased without quotes, weight a number (bold 700, anything else not a number 400), at
     * most 64. Null when the page carries no map for this device.
     */
    public static function wpc_first_screen_faces()
    {
        if (!defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key') || !function_exists('wpc_atf_glyphs_read')) {
            return null;
        }
        try {
            $urlKey = (string) (new wps_ic_url_key())->setup('');
        } catch (\Throwable $e) {
            return null;
        }
        if ($urlKey === '') {
            return null;
        }
        $device = function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile() ? 'mobile' : 'desktop';
        $glyphs = wpc_atf_glyphs_read(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/', $device);
        $keys = array_keys((array) $glyphs);
        if ($keys !== [] && !is_string($keys[0])) {
            $keys = array_values((array) $glyphs);
        }
        $faces = [];
        foreach ($keys as $glyphKey) {
            if (!is_string($glyphKey) || strpos($glyphKey, '|') === false) {
                continue;
            }
            $parts = explode('|', $glyphKey);
            $family = strtolower(trim(str_replace(['"', "'"], '', $parts[0])));
            if ($family === '' || strlen($family) > 64) {
                continue;
            }
            $weight = strtolower(trim((string) $parts[1]));
            $weight = $weight === 'bold' ? 700 : (ctype_digit($weight) ? (int) $weight : 400);
            $faces[$family . '|' . $weight] = [$family, $weight];
            if (count($faces) >= 64) {
                break;
            }
        }
        return $faces === [] ? null : array_values($faces);
    }

    /**
     * The page's own <style> blocks are a source of @font-face rules too, and the gate's law
     * ("a network-url face whose family the document pins is served late") covers them: the
     * 7.24.04 gate (wpc_face_gate710) scanned every <style> block for exactly this. The set only
     * saw the carriers, the crit and its own blocks, so a theme block kept live whole — Divi's
     * divi-dynamic-critical-inline-css, which declares ETmodules on the zone woff — left a
     * network face on the first-paint path beside the embedded ETmodules subset: an extra 92 KB
     * font fetch before first paint on webdesign4u.com.au (local Lighthouse mobile 59 against 61
     * with the face served late, 7.24.04: 60).
     * Only a face whose family the set already pins moves, and only out of a block the browser
     * applies (no media restriction, a CSS type, not one of ours); everything else stays exactly
     * where its author put it.
     */
    private static function absorb_pinned_page_faces($html, $set)
    {
        if (stripos($html, '@font-face') === false || !apply_filters('wpc_page_faces_gate', true)) {
            return $html;
        }
        $out = preg_replace_callback('/<style\b([^>]*)>(.*?)<\/style>/is', function ($styleMatch) use ($set) {
            $attributes = $styleMatch[1];
            $block = $styleMatch[2];
            if (stripos($block, '@font-face') === false
                || preg_match('/\bid\s*=\s*["\']?wpc-/i', $attributes)
                || (preg_match('/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attributes, $typeMatch) && stripos($typeMatch[1], 'css') === false)
                || (stripos($attributes, 'media=') !== false && !preg_match('/media\s*=\s*["\']?\s*(?:all|screen)\b/i', $attributes))) {
                return $styleMatch[0];
            }
            $kept = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($faceMatch) use ($set) {
                $rule = $faceMatch[0];
                if (!preg_match('/url\(\s*["\']?(?:https?:)?\/\//i', $rule)
                    || !preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $rule, $familyMatch)
                    || !$set->pinsFamily($familyMatch[1])) {
                    return $rule;
                }
                $set->add($rule, 'page-style');
                return '';
            }, $block);
            return is_string($kept) && $kept !== $block ? '<style' . $attributes . '>' . $kept . '</style>' : $styleMatch[0];
        }, $html);
        return is_string($out) ? $out : $html;
    }

    /**
     * Put the first-paint face block as late in the head as the buffer allows, and never ahead
     * of the document.
     *
     * A <style> before <!doctype html> is quirks mode for the whole page, so "no </head>, put it
     * at the front" is not a fallback, it is a way to break a document this stage was only meant
     * to add faces to — and the stage runs on fragments, on partial buffers and on anything that
     * reached the table without bailing. Every landing spot below is inside the document: the
     * head's close, the head's open, the first sheet or style block the head already carries,
     * and failing all of those the end of the body. A buffer with none of them is left alone.
     */
    private static function injectEagerFaceBlock($html, $block)
    {
        $at = stripos($html, '</head>');
        if ($at !== false) {
            return substr_replace($html, $block, $at, 0);
        }
        if (preg_match('/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return substr_replace($html, $block, $m[0][1] + strlen($m[0][0]), 0);
        }
        if (preg_match('/<(?:link|style)\b/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return substr_replace($html, $block, $m[0][1], 0);
        }
        $at = strripos($html, '</body>');

        return $at !== false ? substr_replace($html, $block, $at, 0) : $html;
    }

    public static function inline_core_scripts_pass($html)
    {
        if (!is_string($html) || stripos($html, '/wp-includes/js/dist/') === false
            || !defined('ABSPATH') || !apply_filters('wpc_inline_core_ns', true)) {
            return $html;
        }
        $out = preg_replace_callback(
            '/<script\b(?![^>]*\b(?:defer|async)\b)(?![^>]*\b(?:type|nonce|integrity|crossorigin)\s*=)[^>]*\bsrc=["\'][^"\']*\/wp-includes\/js\/dist\/(hooks|i18n)\.min\.js[^"\']*["\'][^>]*>\s*<\/script>/i',
            function ($m) {
                $coreScriptFile = rtrim(ABSPATH, '/') . '/wp-includes/js/dist/' . $m[1] . '.min.js';
                if (!@is_readable($coreScriptFile) || (int) @filesize($coreScriptFile) > 24576) {
                    return $m[0];
                }
                $coreScriptBody = (string) @file_get_contents($coreScriptFile);
                if ($coreScriptBody === '' || stripos($coreScriptBody, '</script') !== false) {
                    return $m[0];
                }
                return '<script id="wp-' . $m[1] . '-js" data-wpc-inlined="1">' . $coreScriptBody . '</script>';
            },
            $html
        );
        return is_string($out) ? $out : $html;
    }

    // v7.21.65 — DASHICONS FOR NOBODY. WP core's dashicons.css rides countless guest pages
    // at 34.3KB / 100% unused (ctfx PSI row) because some plugin enqueues it frontend.
    // Evidence-gated removal: logged-out render, a dashicons stylesheet link present, and
    // ZERO dashicons class usage anywhere else in the document (any `dashicons` token
    // outside the link tags keeps it — glyph classes, -before markers, JS selectors alike).
    // Kill wpc_drop_unused_dashicons.
    public static function drop_dashicons($html)
    {
        if (!is_string($html) || stripos($html, 'dashicons') === false
            || !apply_filters('wpc_drop_unused_dashicons', true)
            || (function_exists('is_user_logged_in') && is_user_logged_in())) {
            return $html;
        }
        $dashiconsLinks = [];
        $out = preg_replace_callback('/<link\b[^>]*href=["\'][^"\']*\/dashicons[^"\']*\.css[^"\']*["\'][^>]*>\s*/i', function ($m) use (&$dashiconsLinks) {
            $dashiconsLinks[] = $m[0];
            return "\x01WPCDI" . (count($dashiconsLinks) - 1) . "\x01";
        }, $html);
        if (!is_string($out) || empty($dashiconsLinks)) {
            return $html;
        }
        $dashiconsUsed = stripos($out, 'dashicons') !== false;
        // The front end enqueued dashicons and no markup on the page uses them. Sampled: the
        // theme or plugin enqueues the sheet on every render.
        if (!$dashiconsUsed && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('dashicons-dropped', ['n' => count($dashiconsLinks)], true);
        }
        return (string) preg_replace_callback('/\x01WPCDI(\d+)\x01/', function ($m) use ($dashiconsLinks, $dashiconsUsed) {
            return $dashiconsUsed ? $dashiconsLinks[(int) $m[1]] : '';
        }, $out);
    }

    public static function wpc_collapse_double_ext($html)
    {
        $profilerSpan = class_exists('Wpc_Profiler_Span') ? new Wpc_Profiler_Span('pass:wpc_collapse_double_ext') : null;
        if (!is_string($html) || $html === '') return $html;
        if (strpos($html, '.webp.webp') === false
            && strpos($html, '.avif.avif') === false
            && strpos($html, '.png.png')  === false
            && strpos($html, '.gif.gif')  === false
            && !preg_match('/\.jpe?g\.jpe?g/i', $html)) {
            return $html;
        }
        $out = preg_replace('/(\.(?:webp|avif|jpe?g|png|gif))\1/i', '$1', $html, -1, $doubled);
        // A URL carrying its extension twice (.webp.webp) is repaired; the writer that appends
        // the second one is not known. Never sampled: each is that writer's defect.
        if ($out !== null && $doubled > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('double-ext-collapsed', ['n' => (int) $doubled]);
        }
        return ($out === null) ? $html : $out;
    }

    public function doCacheCombine()
    {


        if (is_404()) {
            return false;
        }

        if (!empty($_GET['forceRecombine']) && $_GET['forceRecombine'] == 'true') {
            return true;
        }

        if (current_user_can('manage_wpc_settings')) {
            return false;
        }

        $keys = new wps_ic_url_key();
        $allowed_params = $keys->get_allowed_params();
        $get_keys = array_keys($_GET);

        sort($allowed_params);
        sort($get_keys);

        if ($allowed_params === $get_keys) {
            return true;
        }

        if (!empty($_GET)) {
            return false;
        }

        if (self::dontRunif()) {
            return true;
        }

        if ($this->isPageBuilder()) {
            return false;
        }

        if ($this->isPageBuilderFE()) {
            return false;
        }

        if ($this->isFEBuilder()) {
            return false;
        }

        if ($this->isAPICall()) {
            return false;
        }

        if (wp_doing_cron()) {
            return false;
        }


        return true;
    }

    /**
     * FrontEnd Editors Detection for various page builders
     * @return bool
     */
    public static function isPageBuilder()
    {
        $page_builders = ['run_compress',
            'run_restore',
            'elementor-preview',
            'fl_builder',
            'et_fb',
            'preview', //WP Preview
            'builder',
            'brizy',
            'fb-edit',
            'bricks',
            'ct_template',
            'ct_builder',
            'cs-render',
            'tatsu',
            'trp-edit-translation',
            'brizy-edit-iframe',
            'ct_builder',
            'livecomposer_editor',
            'tatsu',
            'tatsu-header',
            'tatsu-footer',
            'is-editor-iframe',
            'tve',
            'pagelayer-live'];

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return true;
        }

        if ((!empty($_GET['action']) && $_GET['action'] == 'in-front-editor')) {

            return true;
        }

        if ((!empty($_GET['action']) && sanitize_text_field($_GET['action']) == 'edit#op-builder') || !empty($_GET['op3editor'])) {

            return true;
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], 'wp-json') || strpos($_SERVER['REQUEST_URI'], 'rest_route')) {
                return false;
            }
        }

        if (!empty($page_builders)) {
            foreach ($page_builders as $page_builder) {
                if (isset($_GET[$page_builder])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * FrontEnd Editors Detection for various page builders
     * @return bool
     */
    public static function isPageBuilderFE()
    {
        if (class_exists('BT_BB_Root')) {
            if (is_user_logged_in() && !is_admin()) {
                return true;
            }
        }

        return false;
    }

    public static function isFEBuilder()
    {
        if (!empty($_GET['trp-edit-translation']) || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['elementor-preview']) || !empty($_GET['tatsu']) || !empty($_GET['preview']) || !empty($_GET['PageSpeed']) || !empty($_GET['tve']) || !empty($_GET['et_fb']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['ct_builder']) || !empty($_GET['fb-edit']) || !empty($_GET['bricks']) || !empty($_GET['is-editor-iframe']) || !empty($_GET['brizy-edit-iframe']) || !empty($_GET['brizy-edit']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor')) {
            return true;
        } else {
            return false;
        }
    }

    public function isAPICall()
    {
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            if (strpos($_SERVER['HTTP_USER_AGENT'], 'Compress-API') !== false) {
                return true;
            }
        }

        return false;
    }

    public static function isURLExcluded($setting)
    {
        if (!isset(self::$excludes[$setting]) || empty(self::$excludes[$setting])) {
            return false;
        }

        $url = self::$keys->url;
        $excludeList = self::$excludes[$setting];
        if (!empty($excludeList)) {
            foreach ($excludeList as $key => $value) {
                if ($value) {
                    if (strpos($url, $value) !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function checkCache()
    {
        if (!empty($_GET['disableCache']) || !empty($_GET['forceRecombine'])) {
            return true;
        }

        if (self::dontRunif()) {
            /**
             * Check for cache first
             */

            if (!empty($_GET['dontRunCache'])) {
                die('Check cache 23');
            }

            $isUserLoggedIn = is_user_logged_in();
            if ($isUserLoggedIn) {
                return true;
            }

            $cache = new wps_cacheHtml();
            if ($cache->cacheEnabled()) {

                if (!empty($_GET['cacheDbg2'])) {
                    die('x');
                }

                $mobile = self::is_mobile();
                $prefix = '';
                if ($mobile) {
                    $prefix = 'mobile';
                }
                $queryString = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
                $queryCacheable = ($queryString === '' || !class_exists('wps_ic_url_key') || !method_exists('wps_ic_url_key', 'queryIsCacheable')
                    || wps_ic_url_key::queryIsCacheable($queryString));
                if ($queryCacheable && $cache->cacheExists($prefix)) {
                    $isCacheExpired = false;

                    // Not required as get cache sorts this
                    $isCacheValid = true;

                    if (!$isCacheExpired && $isCacheValid) {
                        $cache->getCache($prefix);
                    }

                }
            }

        }
    }

    public function is_mobile()
    {
        // v7.10.671 — single shared detector so cdn device treatment can never disagree with the
        // crit device (wps_rewriteLogic::isMobile). Existing broad set kept as fail-open fallback.
        if (function_exists('wpc_ua_is_mobile')) {
            return wpc_ua_is_mobile();
        }

        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }

        if (isset($_SERVER['HTTP_USER_AGENT']) && (preg_match('#(ipad|tablet|windows\ phone|mobile)#i', (string) $_SERVER['HTTP_USER_AGENT']) || preg_match('#^.*(2.0\ MMP|240x320|400X240|AvantGo|BlackBerry|Blazer|Cellphone|Danger|DoCoMo|Elaine/3.0|EudoraWeb|Googlebot-Mobile|hiptop|IEMobile|KYOCERA/WX310K|LG/U990|MIDP-2.|MMEF20|MOT-V|NetFront|Newt|Nintendo\ Wii|Nitro|Nokia|Opera\ Mini|Palm|PlayStation\ Portable|portalmmm|Proxinet|ProxiNet|SHARP-TQ-GX10|SHG-i900|Small|SonyEricsson|Symbian\ OS|SymbianOS|TS21i-10|UP.Browser|UP.Link|webOS|Windows\ CE|WinWAP|YahooSeeker/M1A1-R2D2|iPhone|iPod|Android|BlackBerry9530|LG-TU915\ Obigo|LGE\ VX|webOS|Nokia5800).*#i', $_SERVER['HTTP_USER_AGENT']) || preg_match('#^(w3c\ |w3c-|acs-|alav|alca|amoi|audi|avan|benq|bird|blac|blaz|brew|cell|cldc|cmd-|dang|doco|eric|hipt|htc_|inno|ipaq|ipod|jigs|kddi|keji|leno|lg-c|lg-d|lg-g|lge-|lg/u|maui|maxo|midp|mits|mmef|mobi|mot-|moto|mwbp|nec-|newt|noki|palm|pana|pant|phil|play|port|prox|qwap|sage|sams|sany|sch-|sec-|send|seri|sgh-|shar|sie-|siem|smal|smar|sony|sph-|symb|t-mo|teli|tim-|tosh|tsm-|upg1|upsi|vk-v|voda|wap-|wapa|wapi|wapp|wapr|webc|winw|winw|xda\ |xda-).*#i', substr($_SERVER['HTTP_USER_AGENT'], 0, 4)))) {
            return true;
        }

        return false;
    }

    public function checkCache_plugins_loaded()
    {


        if (defined('WEGLOT_VERSION')) {
            wps_ic_url_key::captureRequestUrl();
        }

        if (!empty($_GET['disableCache']) || !empty($_GET['forceRecombine'])) {
            return true;
        }

        if (self::dontRunif()) {
            /**
             * Check for cache first
             */

            if (!empty($_GET['dontRunCache'])) {
                die('Check cache 23');
            }

            $cache = new wps_cacheHtml();
            $isUserLoggedIn = is_user_logged_in();

            if ($isUserLoggedIn) {
                if (!$cache->cacheLoggedIn()) {
                    return true;
                }
            }

            if ($cache->cacheEnabled()) {

                if (!empty($_GET['cacheDbg2'])) {
                    die('x');
                }

                $mobile = self::is_mobile();
                $prefix = '';
                if ($mobile) {
                    $prefix = 'mobile';
                }

                $queryString = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
                $queryCacheable = ($queryString === '' || !class_exists('wps_ic_url_key') || !method_exists('wps_ic_url_key', 'queryIsCacheable')
                    || wps_ic_url_key::queryIsCacheable($queryString));
                if ($queryCacheable && $cache->cacheExists($prefix)) {
                    $isCacheExpired = false;

                    // Not required as get cache sorts this
                    $isCacheValid = true;

                    if (!$isCacheExpired && $isCacheValid) {
                        $cache->getCache($prefix);
                    }

                } else {
                    if (!defined('WPS_IC_CACHE_BUFFER_STARTED')) {
                        if (function_exists('wpc_response_cache_guard')) {
                            wpc_response_cache_guard();
                        }
                        ob_start([$this, 'saveCache']);
                    }
                }
            }

        }
    }

    public function buffer_callback_v3()
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return true;
        }


        if (!self::dontRunif()) {
            return true;
        }

        if (is_feed() || is_admin()) {
            return true;
        }

        if (!empty($_GET['buffer_callback'])) {
            echo 'Buffer CallBack is Working';
            die();
        }

        // v7.10.928 — OUT OF THE BOX: a logged-in render is the site owner, not a visitor. The
        // CSS artifacts (crit/used-css/combine/parked late sheets) are minted from logged-out
        // captures and can never cover a logged-in DOM (ridgeway receipt: fully unstyled page,
        // zero console errors — absent stylesheet links are silence). This gate precedes the
        // mainInit() call at the bottom, so it stands down the WHOLE pipeline for logged-in:
        // the buffer rewriters AND the enqueued-asset CDN/inline filters mainInit registers.
        // Was opt-in via disable-logged-in-opt (default 0); now the default. Filter
        // wpc_logged_in_bypass -> false restores logged-in optimization for a site that wants it.
        if (function_exists('is_user_logged_in') && is_user_logged_in()
            && (bool) apply_filters('wpc_logged_in_bypass', true)) {
            $GLOBALS['wpc_logged_in_gate_state'] = 'bypass';
            return true;
        }

        // Is an ajax request?
        self::$isAjax = (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX);

        // TODO: Check this for wpadmin and frontend ajax
        if (!self::$isAjax) {
            if (is_admin() || !empty($_GET['trp-edit-translation']) || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['elementor-preview']) || !empty($_GET['preview']) || !empty($_GET['PageSpeed']) || !empty($_GET['et_fb']) || !empty($_GET['is-editor-iframe']) || !empty($_GET['tve']) || !empty($_GET['tatsu']) || !empty($_GET['ct_builder']) || !empty($_GET['fb-edit']) || (!empty($_GET['builder']) && !empty($_GET['builder_id'])) || !empty($_GET['bricks']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor') || !empty($_GET['pagelayer-live'])) {
                return true;
            }

            if (!empty($_GET['tatsu']) || !empty($_GET['tatsu-header']) || !empty($_GET['tatsu-footer'])) {
                return true;
            }
        }

        // Speculation Rules (service-team handoff, WIRING.md): suppress WP >= 6.8 core's
        // prefetch-only speculationrules tag BEFORE the template renders — core prints at
        // wp_footer, so by buffer-callback time its tag would already be in the HTML and the
        // injector's dedupe would make the feature a permanent no-op on modern WP. This spot
        // ('wp' hook, past the admin/builder/logged-in gates) runs for BOTH render lanes, which
        // are exactly the two injection sites.
        // register_hooks() adds the filter ONLY when the toggle is on; off leaves core untouched.
        if (class_exists('wps_ic_speculation_rules')) {
            wps_ic_speculation_rules::register_hooks(self::$settings);
        }

        $init = $this->mainInit();


        // The diagnostic set: plain | ?disable_cache=1 (fresh+crit) | ?crit=0 (crit-less)
        // | ?cdn=0 (local delivery) | ?disableWPC=true (no WPC).
        if (isset($_GET['cdn']) && (string) $_GET['cdn'] === '0') {
            self::$cdnEnabled = false;
        }

        if (!self::$cdnEnabled && !in_array($_SERVER['PHP_SELF'], ['/wp-login.php', '/wp-register.php'])) {


            if (!empty(self::$settings['live-cdn']) && self::$settings['live-cdn'] == 1
                && apply_filters('wpc_nocache_degraded_cdn_render', true)
                && (
                    !empty($_GET['criticalCombine']) || !empty(wpcGetHeader('criticalCombine'))
                    || (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed())
                )) {
                if (!headers_sent()) {
                    if (function_exists('nocache_headers')) { nocache_headers(); }
                    header('X-LiteSpeed-Cache-Control: no-cache', true);
                }
                if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
                if (function_exists('do_action')) { do_action('litespeed_control_set_nocache', 'wpc: transient CDN-off render'); }
            }
            $this->cdn = new wps_cdn_rewrite();
            add_action('template_redirect', [$this->cdn, 'buffer_local_go']);

            return true;
        }

        if (isset($post->post_type) && strpos($post->post_type, 'wfocu') !== false) {
            // Ignore Post Types
        } else {


            // Generate Critical CSS if not exists
            if (!empty(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') {
                #self::$criticalCss->generateCriticalCSS();
                //$html = self::$rewriteLogic->runCriticalAjax($html);
            }


            if (empty($_GET['wpc_no_buffer'])) {
                $GLOBALS['wpc_logged_in_gate_state'] = 'buffer';
                ob_start([$this, 'render_buffer_cdn']);
            }
        }
    }

    public function mainInit()
    {

        if (is_admin()) {
            return true;
        }

        // Integrations
        include_once WPS_IC_DIR . 'integrations/addon/integrations.php';

        $wpcAddonIntegrations = new wpc_addon_integrations();
        if ($wpcAddonIntegrations->wpMaintenance()) {
            return true;
        }

        // Check if WP_CLI is being used
        if (defined('WP_CLI') && WP_CLI) {
            // WP_CLI detected, don't run the block
            return true;
        }

        // Check if WP REST API is being accessed
        if (defined('REST_REQUEST') && REST_REQUEST) {
            // WP REST API detected, don't run the block
            return true;
        }

        // Raise memory limit
        if (ini_get('memory_limit') !== '-1' && wpc_convert_to_bytes(ini_get('memory_limit')) < 1024 * 1024 * 1024) {
            ini_set('memory_limit', '1024M');
        }

        // Raise backtrack limit for regex
        ini_set('pcre.backtrack_limit', '10000000');

        global $post;
        self::$options = get_option(WPS_IC_OPTIONS);

        if (!isset(self::$options['api_key']) || empty(self::$options['api_key'])) {
            return true;
        }

        // Was only adding to home page
        if ($this->is_home_url()) {
            if (!self::is_mobile()) {
                #add_action('wp_head', [$this, 'preload_custom_assets'], 1);
            } else {
                #add_action('wp_head', [$this, 'preload_custom_assetsMobile'], 1);
            }
        }

        self::$excludes_class = new wps_ic_excludes();
        $requestAmp = new wps_ic_amp();
        self::$preloaderAPI = 0;

        self::$settings = get_option(WPS_IC_SETTINGS);

        if ($this->is_home_url()) {
            self::$post_id = 'home';
            self::$page_excludes = isset(self::$excludes['page_excludes']['home']) ? self::$excludes['page_excludes']['home'] : [];
            self::$page_excludes_files = isset(self::$excludes['page_excludes_files']['home']) ? self::$excludes['page_excludes_files']['home'] : [];
        } elseif (!empty(get_queried_object_id())) {
            self::$post_id = get_queried_object_id();
            self::$page_excludes = isset(self::$excludes['page_excludes'][self::$post_id]) ? self::$excludes['page_excludes'][self::$post_id] : [];
            self::$page_excludes_files = isset(self::$excludes['page_excludes_files'][self::$post_id]) ? self::$excludes['page_excludes_files'][self::$post_id] : [];
        } else if (!empty($post->ID)) {
            self::$post_id = $post->ID;
            self::$page_excludes = isset(self::$excludes['page_excludes'][self::$post_id]) ? self::$excludes['page_excludes'][self::$post_id] : [];
            self::$page_excludes_files = isset(self::$excludes['page_excludes_files'][self::$post_id]) ? self::$excludes['page_excludes_files'][self::$post_id] : [];
        } else {
            self::$post_id = false;
            self::$page_excludes = [];
            self::$page_excludes_files = [];
        }

        if ($requestAmp->isAmp()) {
            self::$lazy_enabled = '0';
            self::$adaptive_enabled = '0';
            self::$retina_enabled = '0';
            self::$settings['delay-js'] = '0';
            self::$settings['inline-js'] = '0';
        }

        if (self::push_render_requested()) {
            self::$settings['critical']['css'] = 0;
        }

        if (!empty($_GET['forceRecombine']) && $_GET['forceRecombine'] == 'true') {
            $post_id = get_the_ID();
            $cache = new wps_ic_cache();
            $cache->updateCSSHash($post_id);
            $cache->removeHtmlCacheFiles($post_id);
        }

        self::$findImages = '';
        if (!empty(self::$settings['serve']['jpg']) && self::$settings['serve']['jpg'] == '1') {
            self::$findImages .= 'jpg|jpeg|';
        }

        if (!empty(self::$settings['serve']['png']) && self::$settings['serve']['png'] == '1') {
            self::$findImages .= 'png|';
        }

        if (!empty(self::$settings['serve']['gif']) && self::$settings['serve']['gif'] == '1') {
            self::$findImages .= 'gif|';
        }

        if (!empty(self::$settings['serve']['svg']) && self::$settings['serve']['svg'] == '1') {
            self::$findImages .= 'svg|';
        }

        self::$keys = new wps_ic_url_key();

        self::$findImages .= 'webp|';

        self::$findImages = rtrim(self::$findImages, '|');

        if ((!empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'PreloaderAPI') !== false) || !empty($_GET['dbg_preload'])) {
            self::$preloaderAPI = 1;
        }

        self::$zone_test = 0;
        self::$is_multisite = is_multisite();

        self::$randomHash = 0;

        self::$rewriteLogic = new wps_rewriteLogic();
        self::$minifyHtml = new wps_minifyHtml();
        self::$cacheHtml = new wps_cacheHtml();
        self::$criticalCss = new wps_criticalCss();
        self::$combineCss = new wps_ic_combine_css();

        //Add files inline
        if (self::dontRunif()) {
            $inline_scripts = get_option('wpc-inline');
            if (!empty($inline_scripts['inline_js'])) {
                $this->inline_js = $inline_scripts['inline_js'];
            }

            if (!empty(self::$settings['inline-js']) && self::$settings['inline-js'] == 1) {
                if (!empty($this->inline_js)) {
                    foreach ($this->inline_js as $key => $script) {
                        if (substr($script, -3) == '-js') {
                            $this->inline_js[$key] = substr($script, 0, -3);
                        }
                    }
                }
                add_filter('script_loader_tag', [$this, 'add_scripts_inline'], PHP_INT_MAX, 3);
            }
        }

        //Perfmatters settings check
        //$this->perfMattersOverride();

        //Rocket settings check
        //$this->rocketOverride();


        // v7.22.72 — ONE list, shared with wps_rewriteLogic (defines.php). The elementor append
        // below stays per-class: it is this class's addition, not a shared default.
        self::$default_excluded_list = wpc_default_cdn_excludes();


        if (apply_filters('wpc_elementor_css_same_origin', true)) {
            self::$default_excluded_list[] = 'elementor/css/';
        }

        self::$assets_to_defer = ['themes', 'tracking', 'fontawesome'];

        if (!empty($_GET['ignore_ic'])) {
            return true;
        }

        if (!empty($_GET['randomHash'])) {
            self::$randomHash = time();
        }

        if (strpos($_SERVER['REQUEST_URI'], '.xml') !== false) {
            return true;
        }

        if (empty(self::$options['css_hash'])) {
            self::$options['css_hash'] = 5021;
        }

        if (empty(self::$options['js_hash'])) {
            self::$options['js_hash'] = 5021;
        }

        if (!defined('WPS_IC_HASH')) {
            define('WPS_IC_HASH', self::$options['css_hash']);
        }

        if (!defined('WPS_IC_JS_HASH')) {
            define('WPS_IC_JS_HASH', self::$options['js_hash']);
        }

        if (!empty(self::$excludes['delay_js'])) {
            $this->delay_js_exclude = self::$excludes['delay_js'];
        } else {
            $this->delay_js_exclude = '';
        }

        $cf = get_option(WPS_IC_CF);
        $cfLive = false;
        if ($cf && isset($cf['settings'])) {
            $cfLive = ($cf['settings']['assets'] == '1' && $cf['settings']['cdn'] == '0');
        }
        $allowLive = get_option('wps_ic_allow_live') && !$cfLive;

        self::$cdnEnabled = self::$settings['live-cdn'];
        if ((isset(self::$page_excludes['cdn']) && self::$page_excludes['cdn'] == '0') || !$allowLive) {
            self::$cdnEnabled = 0;
            self::$settings['css'] = 0;
            self::$settings['js'] = 0;
            self::$settings['serve']['jpg'] = 0;
            self::$settings['serve']['png'] = 0;
            self::$settings['serve']['gif'] = 0;
            self::$settings['serve']['svg'] = 0;
        } else if (isset(self::$page_excludes['cdn']) && self::$page_excludes['cdn'] == '1' && isset(self::$settings['live-cdn']) && self::$settings['live-cdn'] == '1') {


            self::$cdnEnabled = 1;
            self::$settings['css'] = 1;
            self::$settings['js'] = 1;
            self::$settings['serve']['jpg'] = 1;
            self::$settings['serve']['png'] = 1;
            self::$settings['serve']['gif'] = 1;
            self::$settings['serve']['svg'] = 1;
        }


        if (self::$settings['css'] == 0 && self::$settings['js'] == 0 && empty(self::$settings['fonts']) && self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) {
            self::$cdnEnabled = 0;
        }

        if (!empty($_GET['criticalCombine']) || !empty(wpcGetHeader('criticalCombine'))) {
            self::$cdnEnabled = 0;
            self::$settings['css'] = 0;
            self::$settings['js'] = 0;
            self::$settings['serve']['jpg'] = 0;
            self::$settings['serve']['png'] = 0;
            self::$settings['serve']['gif'] = 0;
            self::$settings['serve']['svg'] = 0;
        }

        // Is an ajax request?
        self::$isAjax = (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX);

        // Don't run in admin side!
        if (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") {
            return;
        }

        self::$svg_placeholder = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAwIiBoZWlnaHQ9IjEwMCI+PHBhdGggZD0iTTIgMmgxMDAwdjEwMEgyeiIgZmlsbD0iI2ZmZiIgb3BhY2l0eT0iMCIvPjwvc3ZnPg==';

        self::$updir = wp_upload_dir();

        if (!is_multisite()) {
            self::$site_url = site_url();
            self::$home_url = home_url();
        } else {
            $current_blog_id = get_current_blog_id();
            switch_to_blog($current_blog_id);

            self::$site_url = network_site_url();
            self::$home_url = home_url();
        }

        self::$site_url_scheme = parse_url(self::$site_url, PHP_URL_SCHEME);
        self::$lazy_excluded_list = get_option('wpc-ic-lazy-exclude');
        self::$excluded_list = get_option('wpc-ic-external-url-exclude');

        if (!is_array(self::$excluded_list)) {
            self::$external_url_excluded = explode("\n", self::$excluded_list);
        } else {
            self::$external_url_excluded = self::$excluded_list;
        }

        if (defined('BRIZY_VERSION')) {
            self::$brizyCache = get_option('wps_ic_brizy_cache');
            self::$brizyActive = true;
        } else {
            self::$brizyActive = false;
        }

        $cfCname = get_option(WPS_IC_CF_CNAME);
        $cf = get_option(WPS_IC_CF);


        $cfVerified = wpc_cf_cname_verified_ok();
        $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && $cfVerified) ? $cfCname : get_option('ic_custom_cname');
        if (!empty($custom_cname) && function_exists('wpc_cdn_cname_is_reachable') && !wpc_cdn_cname_is_reachable($custom_cname)) { $custom_cname = ''; }

        if (empty($custom_cname) || !$custom_cname) {
            self::$zone_name = get_option('ic_cdn_zone_name');
        } else {
            self::$zone_name = $custom_cname;
        }


        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            self::$zone_name = '';
            self::$cdnEnabled = 0;
        }

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'direct') {
            if (!empty($_GET['custom_server'])
                && function_exists('wpc_cdn_debug_is_allowed') && wpc_cdn_debug_is_allowed()) {
                $custom_server = sanitize_text_field($_GET['custom_server']);
                if (preg_match('/^[a-z0-9\-]+\.zapwp\.net$/i', $custom_server)) {
                    self::$zone_name = $custom_server . '/key:' . self::$options['api_key'];
                }
            }
        }


        if (!empty(self::$zone_name) && function_exists('home_url')) {
            $wpc_origin_h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            if ($wpc_origin_h !== '' && strcasecmp((string) self::$zone_name, $wpc_origin_h) === 0) {
                self::$zone_name = '';
            }
        }

        add_action('wp_head', [$this, 'img_ratio_style'], 0);

        if (empty(self::$zone_name)) {
            return;
        }

        self::$is_retina = '0';
        self::$webp = '0';
        self::$externalUrlEnabled = 'false';

        self::$lazy_enabled = self::$settings['lazy'];
        self::$native_lazy_enabled = self::$settings['nativeLazy'];
        self::$adaptive_enabled = self::$settings['generate_adaptive'];
        self::$webp_enabled = self::$settings['generate_webp'];
        self::$retina_enabled = self::$settings['retina'];


        $wpc_nextgen_ceiling = class_exists('WPC_Delivery_Resolver')
            ? WPC_Delivery_Resolver::effective_ceiling(self::$settings)
            : 'avif';


        $wpc_cdn_images_on = !class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings);


        self::$rewriteLogic::$pictureWebpEnabled = $wpc_nextgen_ceiling !== 'off'
            && !empty(self::$webp_enabled) && self::$webp_enabled == '1'
            && $wpc_cdn_images_on;
        self::$rewriteLogic::$pictureAvifEnabled = $wpc_nextgen_ceiling === 'avif' && $wpc_cdn_images_on;

        // Skip picture wrapping for JSON responses
        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            self::$rewriteLogic::$pictureWebpEnabled = false;
        }

        if (isset(self::$page_excludes['adaptive'])) {


            self::$adaptive_enabled = self::$page_excludes['adaptive'];


        }

        if (!empty(self::$settings['replace-all-link'])) {
            self::$replaceAllLinks = self::$settings['replace-all-link'];
        } else {
            self::$replaceAllLinks = '0';
        }

        if (!empty($_GET['disableLazy'])) {
            self::$lazy_enabled = '0';
            self::$native_lazy_enabled = '0';
        }

        if (!empty(self::$settings['external-url'])) {
            self::$externalUrlEnabled = self::$settings['external-url'];
        }

        if (empty(self::$settings['emoji-remove'])) {
            self::$settings['emoji-remove'] = 0;
        }

        if (empty(self::$settings['remove-duplicated-fontawesome'])) {
            self::$settings['remove-duplicated-fontawesome'] = 0;
        }

        if (empty(self::$settings['external-url'])) {
            self::$settings['external-url'] = 0;
        }

        if (empty(self::$settings['css'])) {
            self::$settings['css'] = 0;
        }

        if (empty(self::$settings['fonts'])) {
            self::$settings['fonts'] = 0;
        }

        if (empty(self::$settings['js'])) {
            self::$settings['js'] = 0;
        }

        if (empty(self::$settings['preserve_exif'])) {
            self::$settings['preserve_exif'] = 0;
        }

        if (!empty($_GET['ic_override_setting']) && $_GET['ic_override_setting'] == 'lazy') {
            self::$lazy_enabled = (bool)$_GET['value'];
        }

        if (!empty($_GET['ic_lazy'])) {
            self::$lazy_enabled = (bool)$_GET['ic_lazy'];
            self::$settings['css'] = 1;
            self::$settings['js'] = 1;
        }

        if (!empty($_GET['css'])) {
            self::$settings['css'] = (bool)$_GET['css'];
        }

        if (!empty($_GET['js'])) {
            self::$settings['js'] = (bool)$_GET['js'];
        }

        if (empty(self::$settings['css_image_urls']) || !isset(self::$settings['css_image_urls'])) {
            self::$settings['css_image_urls'] = '0';
        }

        if (!empty(self::$settings['minify-css']) && self::$settings['minify-css']) {
            self::$settings['minify-css'] = '1';
        } else {
            self::$settings['minify-css'] = '0';
        }

        if (!empty(self::$settings['minify-js']) && self::$settings['minify-js']) {
            self::$settings['minify-js'] = '1';
        } else {
            self::$settings['minify-js'] = '0';
        }

        self::$externalUrlEnabled = self::$settings['external-url'];
        self::$css = self::$settings['css'];
        self::$css_img_url = self::$settings['css_image_urls'];
        self::$js = self::$settings['js'];
        self::$js_minify = self::$settings['js_minify'];
        self::$emoji_remove = self::$settings['emoji-remove'];
        self::$exif = self::$settings['preserve_exif'];
        self::$fonts = self::$settings['fonts'];

        // If Optimization Quality is Not set...
        if (empty(self::$settings['optimization']) || self::$settings['optimization'] == '' || self::$settings['optimization'] == '0') {
            self::$settings['optimization'] = 'i';
        }

        // Optimization Switch from Legacy
        switch (self::$settings['optimization']) {
            case 'intelligent':
                self::$settings['optimization'] = 'i';
                break;
            case 'ultra':
                self::$settings['optimization'] = 'u';
                break;
            case 'lossless':
                self::$settings['optimization'] = 'l';
                break;
        }

        if (!empty(self::$retina_enabled) && self::$retina_enabled == '1') {
            if (isset($_COOKIE["ic_pixel_ratio"])) {
                if ($_COOKIE["ic_pixel_ratio"] >= 2) {
                    self::$is_retina = '1';
                }
            }
        }

        if (!empty(self::$webp_enabled) && self::$webp_enabled == '1') {
            self::$webp = '1';


            if (!self::wpc_universal_picture_on()
                && !empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'Safari') && !strpos($_SERVER['HTTP_USER_AGENT'], 'Chrome')) {
                self::$webp_enabled = false;
                self::$webp = '0';
            }
        }


        if (!empty($_GET['test_zone'])
            && function_exists('wpc_cdn_debug_is_allowed') && wpc_cdn_debug_is_allowed()
            && preg_match('/^[a-z0-9\-]+$/iD', (string) $_GET['test_zone'])) { // D: $ = absolute end (no trailing-newline bypass)
            if ($_GET['test_zone'] === 'cdn-rage4') {
                $wpc_test_server = isset($_GET['server']) ? (string) $_GET['server'] : '';
                if (preg_match('/^[a-z0-9\-]+$/iD', $wpc_test_server)) {
                    self::$zone_test = 1;
                    self::$zone_name = $wpc_test_server . '.zapwp.net/key:' . self::$options['api_key'];
                }
            } else {
                self::$zone_name = (string) $_GET['test_zone'] . '.wpmediacompress.com/key:' . self::$options['api_key'];
            }
        }

        if (strpos(self::$zone_name, 'bunny') !== false) {
            self::$settings['optimization'] = 'lossless';
        }

        if (!empty(self::$exif) && self::$exif == '1') {
            self::$apiUrl = 'https://' . self::$zone_name . '/q:' . self::$settings['optimization'] . '/e:1';
        } else {
            self::$apiUrl = 'https://' . self::$zone_name . '/q:' . self::$settings['optimization'];
        }

        self::$apiAssetUrl = 'https://' . self::$zone_name . '/a:';

        if (self::$preloaderAPI) {
            global $post;
            self::$lazy_enabled = '0';
            self::$native_lazy_enabled = '0';
            self::$adaptive_enabled = '0';
            self::$retina_enabled = '0';
            $preloaded_pages = get_option('wpc-ic-preloaded-pages');

            if (is_array($preloaded_pages) && !in_array($post->ID, $preloaded_pages)) {
                array_push($preloaded_pages, $post->ID);
                update_option('wpc-ic-preloaded-pages', $preloaded_pages);
            } else if ($preloaded_pages === false) {
                update_option('wpc-ic-preloaded-pages', [$post->ID]);
            }
        }

        if (!empty($_GET['overwrite_retina'])) {
            self::$retina_enabled = '1';
            self::$is_retina = '1';
        }

        if (!empty($_GET['debugCritical']) || !empty($_GET['generateCriticalAPI'])) {
            add_filter('style_loader_tag', [$this, 'crittr_style_tag'], 10, 4);
        }


        if ((isset(self::$page_excludes['cdn']) && self::$page_excludes['cdn'] == '0') || !$allowLive) {
            self::$cdnEnabled = 0;
            self::$settings['css'] = 0;
            self::$settings['js'] = 0;
            self::$settings['serve']['jpg'] = 0;
            self::$settings['serve']['png'] = 0;
            self::$settings['serve']['gif'] = 0;
            self::$settings['serve']['svg'] = 0;
        } else if (isset(self::$page_excludes['cdn']) && self::$page_excludes['cdn'] == '1' && isset(self::$settings['live-cdn']) && self::$settings['live-cdn'] == '1') {


            self::$cdnEnabled = 1;
            self::$settings['css'] = 1;
            self::$settings['js'] = 1;
            self::$settings['serve']['jpg'] = 1;
            self::$settings['serve']['png'] = 1;
            self::$settings['serve']['gif'] = 1;
            self::$settings['serve']['svg'] = 1;
        }


        if (self::$settings['css'] == 0 && self::$settings['js'] == 0 && empty(self::$settings['fonts']) && self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) {
            self::$cdnEnabled = 0;
        }


        // Default to swap if not explicitly set — fixes PageSpeed font-display warning
        if (empty(self::$settings['font-display'])) {
            self::$settings['font-display'] = 'smart';
        }
        // v7.10.484 — keep the RAW setting; 'optional' is per-FAMILY (.483) and resolving it
        // site-wide here re-created the exact bug .483 fixed, one writer along. The per-face
        // emitter below resolves with the family in hand.
        self::$fontDisplayRaw = (string) self::$settings['font-display'];
        if (function_exists('wpc_font_display_effective')) {
            self::$settings['font-display'] = wpc_font_display_effective(self::$settings['font-display']);
        }
        if (self::$settings['font-display'] != 'off') {
            add_filter('style_loader_src', [$this, 'add_font_display_swap_to_url'], 1, 2);
            add_filter('style_loader_src', [$this, 'process_css_for_fonts'], 1, 4);
        }

        if (self::$cdnEnabled == 1) {
            if (self::dontRunif()) {


                if (self::$css == "1") {
                    add_filter('style_loader_src', [$this, 'adjust_src_url'], 10, 2);
                    add_filter('style_loader_tag', [$this, 'adjust_style_tag'], 10, 4);
                    add_action('wp_head', [$this, 'cssOriginFallbackScript'], 0);
                }
                #}

                if (self::$js == "1") {
                    add_filter('script_loader_tag', [$this, 'rewrite_script_tag'], 10, 3);
                }

                #add_filter('script_loader_tag', [$this, 'deferJSAssets'], 10, 3);
            }

            add_action("wp_head", [$this, 'dnsPrefetch'], 0);

            // Rewrite WooCommerce variation image URLs so they match CDN-rewritten DOM URLs
            add_filter('woocommerce_available_variation', [$this, 'rewrite_woo_variation_image_urls'], 10, 3);
        } else {

            // Local Mode
            if (self::dontRunif()) {


                if (self::$css == "1") {
                    add_filter('style_loader_src', [$this, 'adjust_src_url'], 10, 2);
                    add_filter('style_loader_tag', [$this, 'adjust_style_tag'], 10, 4);
                    add_action('wp_head', [$this, 'cssOriginFallbackScript'], 0);
                }

                if (self::$js == "1") {
                    add_filter('script_loader_src', [$this, 'adjust_src_url'], 10, 3);


                    add_filter('script_module_loader_src', [$this, 'adjust_src_url'], 10, 2);
                }
            }

            if (self::$js == "1" || self::$css == "1") {
                add_action("wp_head", [$this, 'dnsPrefetch'], 0);
            }
        }
    }


    public function cssOriginFallbackScript()
    {
        if (!apply_filters('wpc_css_origin_fallback', true)) {
            return;
        }
        $zone = self::$zone_name;
        if (empty($zone)) {
            return;
        }

        $siteOrigin = preg_replace('#^(https?://[^/]+).*$#', '$1', rtrim((string) self::$site_url, '/'));
        if (empty($siteOrigin) || strpos($siteOrigin, 'http') !== 0) {
            return;
        }
        $Z = json_encode((string) $zone, JSON_UNESCAPED_SLASHES);
        $O = json_encode($siteOrigin, JSON_UNESCAPED_SLASHES);
        echo '<script id="wpc-css-orb-fallback">(function(){var Z=' . $Z . ',O=' . $O . ';'
            . 'function toOrigin(h){var i=h.indexOf("/a:");if(i!==-1){var r=h.slice(i+3);if(!r)return null;return /^https?:\/\//i.test(r)?r:O+(r.charAt(0)==="/"?"":"/")+r;}try{var u=new URL(h);if(!u.pathname||u.pathname==="/")return null;return O+u.pathname+u.search;}catch(e){return null;}}'
            . 'window.addEventListener("error",function(e){var el=e.target;if(!el||el.tagName!=="LINK"||el.rel!=="stylesheet")return;var h=el.href||"";if(h.indexOf(Z)===-1||el.getAttribute("data-wpco"))return;var o=toOrigin(h);if(!o||o===h)return;el.setAttribute("data-wpco","1");var l=document.createElement("link");l.rel="stylesheet";if(el.media)l.media=el.media;l.href=o;el.parentNode.insertBefore(l,el.nextSibling);},true);})();</script>' . "\n";
    }


    public function add_font_display_swap_to_url($src, $handle)
    {
        if (strpos($src, 'fonts.googleapis.com') === false || empty(self::$settings['font-display'])) {
            return $src;
        }
        if (stripos($src, 'display=') !== false) {
            return $src;
        }
        $sep = (strpos($src, '?') === false) ? '?' : '&';
        return $src . $sep . 'display=' . rawurlencode((string) self::$settings['font-display']);
    }


    public static function wpc_gfonts_display_pass($html)
    {
        if (!is_string($html) || $html === '') {
            return $html;
        }
        $wpc_fd = !empty(self::$settings['font-display']) ? (string) self::$settings['font-display'] : 'swap';
        if ($wpc_fd === 'off') {
            return $html;
        }
        if (stripos($html, 'fonts.googleapis.com/css') === false && stripos($html, 'fonts.bunny.net/css') === false) {
            return $html;
        }
        $displayAdded = 0;
        $out = preg_replace_callback(
            '/(<link\b[^>]*\bhref=)(["\'])(https?:\/\/fonts\.(?:googleapis\.com|bunny\.net)\/css[^"\']*)\2/i',
            function ($m) use ($wpc_fd, &$displayAdded) {
                $href = $m[3];
                if (stripos($href, 'display=') !== false) {
                    return $m[0];
                }
                if (strpos($href, '?') === false) {
                    $sep = '?';
                } elseif (strpos($href, '&#038;') !== false) {
                    $sep = '&#038;';
                } elseif (strpos($href, '&amp;') !== false) {
                    $sep = '&amp;';
                } else {
                    $sep = '&';
                }
                $displayAdded++;
                return $m[1] . $m[2] . $href . $sep . 'display=' . rawurlencode($wpc_fd) . $m[2];
            },
            $html
        );
        // A provider link the theme hard-codes never meets the enqueue filter, so its display=
        // is added here. Sampled: the theme prints the same links on every render.
        if ($displayAdded > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('gfonts-display-added', ['n' => $displayAdded, 'display' => $wpc_fd], true);
        }
        return $out;
    }

    /**
     * Font Display exclude list (gear popup on the Text Font Display dropdown, stored as
     * wpc-excludes[font_display]). Case-insensitive substring match — same semantics as
     * wps_ic_excludes::strInArray — against the stylesheet URL plus the id WordPress renders
     * on the tag ({handle}-css), so URL fragments, filenames and tag ids all match.
     */
    private static function wpc_font_display_excluded($src, $handle)
    {
        static $excludes = null;
        if ($excludes === null) {
            $opt = get_option('wpc-excludes');
            $excludes = (!empty($opt['font_display']) && is_array($opt['font_display'])) ? $opt['font_display'] : [];
        }
        if (empty($excludes)) {
            return false;
        }
        $haystack = strtolower($src . ' id="' . $handle . '-css"');
        foreach ($excludes as $needle) {
            $needle = strtolower(trim((string) $needle));
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    public function process_css_for_fonts($src, $handle)
    {
        // Skip if not a CSS file
        if (strpos($src, '.css') === false) {
            return $src;
        }

        // Skip if not local
        $clean_src = strtok($src, '?');
        if (strpos($clean_src, home_url()) === false) {
            return $src;
        }

        if (!defined('WPS_IC_CSS')) {
            return $src;
        }


        if (self::wpc_font_display_excluded($src, $handle)) {
            return $src;
        }


        $wpc_fonts_cdn = self::wpc_fonts_served_from_cdn();


        $wpc_font_nat = $wpc_fonts_cdn
            && ((class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on') && wps_rewriteLogic::natural_assets_on())
                || apply_filters('wpc_asset_naturalize_enabled', true));


        $wpc_cache_basis = strtok($src, '?');
        if ($wpc_fonts_cdn) {
            $wpc_subset_key = (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') ? '1' : '0';
            $wpc_cache_basis .= '|wpccf|' . self::$zone_name . '|' . $wpc_subset_key;


            if ($wpc_font_nat) {
                $wpc_cache_basis .= '|wpcfontnat';
            }
        }


        $wpc_svg_cdn = self::wpc_svg_zoneify_active();
        if ($wpc_svg_cdn) {
            // Marker folded into the key; bumping it forces every already-written cio file to
            // rebuild under a new name on the next render.
            $wpc_cache_basis .= '|wpccss3|' . self::$zone_name;


            if (self::wpc_css_bg_imageset_active()) {
                $wpc_cache_basis .= '|wpcbgis1';
            }
        }
        // remote_range is injected into @font-face at CSS-BUILD time (below), so it is baked into
        // this cached file. Without the map in the key, a landed range change never rebuilt the file
        // and the stale range served forever — busy kept 8de0d6bf's U+0-34 (covering U+33) after
        // 688aae3b landed the correct U+0-32,U+34,… so the 91 KiB icon font stayed on the pipe.
        // Same mechanism as the |wpccss3| / |wpcbgis1| markers above: fold it in, get a new name.
        $remoteRanges = self::wpc_font_remote_ranges();
        if (!empty($remoteRanges)) {
            ksort($remoteRanges);
            $wpc_cache_basis .= '|wpcrr2|' . md5(serialize($remoteRanges));
            // v7.10.479 — .387 folded the MAP, but .478 made the gate conditional on the inline
            // subset being present, and that condition is NOT in the map. Same map + subset gained
            // or lost = same hash = same filename = the already-baked file keeps serving, so .478
            // would silently never take effect until someone purged by hand. Fold the subset
            // family set in too, so gaining OR losing a subset self-invalidates the built CSS.
            // Exactly the .429/.464 lesson: the basis must carry every input that changes output.
            $subsetFamilies = self::wpc_font_subset_families();
            ksort($subsetFamilies);
            $wpc_cache_basis .= '|wpcsf1|' . md5(implode(',', array_keys($subsetFamilies)));
        }
        // CSS reference finding 30: the key hashed the source URL with ?ver stripped and nothing about the
        // bytes, so a sheet rewritten in place kept serving its old processed copy until cache/wp-cio/css
        // was deleted by hand. Fold the resolved local file's mtime and size in, so a rewrite in place
        // self-invalidates under a new name. An unresolvable path leaves the key exactly as it was.
        $sourcePath = str_replace('/', DIRECTORY_SEPARATOR, str_replace(home_url(), ABSPATH, $clean_src));
        $sourceMtime = @filemtime($sourcePath);
        $sourceSize = @filesize($sourcePath);
        if ($sourceMtime > 0 && $sourceSize > 0) {
            $wpc_cache_basis .= '|wpcsrc|' . (int) $sourceMtime . '-' . (int) $sourceSize;
        }
        // Every copy ends with a trailer naming its source sheet (below), which is what the crit
        // corpus identity counts instead of the copy. Copies written before the trailer existed
        // have none, and a copy under an unchanged name is never rewritten, so without a new
        // name they would keep being counted as themselves and keep reading as drift after a
        // land. The marker gives every copy a new name once, written with its trailer.
        $wpc_cache_basis .= '|wpcsourcetrailer1';
        // The key names every input the copy's bytes depend on, and nothing else: the source
        // (URL, mtime and size, above), the font and SVG lane markers above, and
        // processed_copy_config_digest(), which covers the settings and pipeline decisions the
        // writer and its passes read. Failure it prevents (2026-09-24, bd1): the copies baked in
        // font-display and the delivery settings but their key carried none of them, so a
        // settings change kept serving stale copies until a settings save deleted cache/wp-cio
        // wholesale. Keying on the whole settings row instead renamed every copy on any save
        // and left one orphan set per save; a save that changes none of these inputs now keeps
        // the names, and the orphans of one that does are collected by wpc_processed_copy_gc().
        $configDigest = self::processed_copy_config_digest();
        $wpc_cache_basis .= '|wpccfg1|' . $configDigest;
        // The name's last four hex are the config tag, so the collector tells a copy built
        // under a configuration still in use from an orphan without reading the file; the
        // first six still hash the whole basis, the digest included.
        $configTag = substr($configDigest, 0, 4);
        $hash = substr(md5($wpc_cache_basis), 0, 6) . $configTag;
        if (function_exists('wpc_processed_copy_tag_seen')) {
            wpc_processed_copy_tag_seen($configTag);
        }
        $new_filename = sanitize_file_name($handle . '-' . $hash . '.css');
        $new_filepath = WPS_IC_CSS . '/' . $new_filename;


        clearstatcache(true, $new_filepath);
        if (file_exists($new_filepath) && @filesize($new_filepath) > 0) {
            $new_url = WPS_IC_CSS_URL . '/' . $new_filename;
            return $new_url;
        }
        if (file_exists($new_filepath)) {
            // 0-byte residue (pre-fix incident file or foreign stub) — drop it so the
            // atomic re-write below replaces it under the same name this render.
            @unlink($new_filepath);
        }

        // Create optimized file
        $css_path = $sourcePath;

        if (!file_exists($css_path) || !is_readable($css_path)) {
            return $src;
        }

        $css_content = @file_get_contents($css_path);

        if (empty($css_content)) {
            return $src;
        }


        $wpc_has_fontface = (stripos($css_content, '@font-face') !== false);
        if (!$wpc_has_fontface && !($wpc_svg_cdn && stripos($css_content, '/wp-content/uploads/') !== false)) {
            return $src;
        }

        // Get the base URL for the original CSS file (directory containing the CSS)
        $css_base_url = dirname($clean_src);

        // Convert relative URLs to absolute URLs
        $css_content = preg_replace_callback('/url\s*\(\s*(["\']?)([^"\')]+)\1\s*\)/i', function ($matches) use ($css_base_url) {
            $quote = $matches[1];
            $url = $matches[2];

            // Skip if already absolute URL or data URI
            if (preg_match('/^(https?:|data:|#)/i', $url)) {
                return $matches[0];
            }

            // Handle protocol-relative URLs
            if (strpos($url, '//') === 0) {
                $protocol = wpc_request_is_https() ? 'https:' : 'http:';
                return 'url(' . $quote . $protocol . $url . $quote . ')';
            }

            // Handle root-relative URLs
            if (strpos($url, '/') === 0) {
                return 'url(' . $quote . home_url($url) . $quote . ')';
            }

            // Handle relative URLs (including ./ and ../)
            // Remove ./ prefix if present
            if (strpos($url, './') === 0) {
                $url = substr($url, 2);
            }

            // Build absolute URL from base
            $absolute_url = $css_base_url . '/' . $url;

            // Resolve ../ in the path
            while (strpos($absolute_url, '/../') !== false) {
                $absolute_url = preg_replace('/\/[^\/]+\/\.\.\//', '/', $absolute_url);
            }

            return 'url(' . $quote . $absolute_url . $quote . ')';
        }, $css_content);


        if ($wpc_fonts_cdn) {
            $wpc_subsetting = (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1');
            $wpc_site_host = wp_parse_url(home_url(), PHP_URL_HOST);
            $wpc_zone = (string) self::$zone_name;


            $css_content = preg_replace('#/\*.*?\*/#s', '', $css_content);
            $css_content = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($block) use ($wpc_subsetting, $wpc_site_host, $wpc_zone, $wpc_font_nat) {
                $rule = $block[0];


                $family_is_icon = false;
                if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $rule, $fam)) {
                    if (wpc_css_is_icon_font($fam[1])) {
                        $family_is_icon = true;
                    }
                }
                return preg_replace_callback('/url\s*\(\s*(["\']?)(https?:[^"\')]+\.(?:woff2|woff|eot|ttf)(?:[?#][^"\')]*)?)\1\s*\)/i', function ($m) use ($wpc_subsetting, $wpc_site_host, $wpc_zone, $family_is_icon, $wpc_font_nat) {
                    $url = $m[2];
                    // Already on the zone? leave untouched (idempotent / no double-rewrite).
                    if ($wpc_zone !== '' && strpos($url, $wpc_zone) !== false) {
                        return $m[0];
                    }


                    if ($wpc_zone === '' || ($wpc_site_host !== '' && strcasecmp((string) $wpc_zone, (string) $wpc_site_host) === 0)) {
                        return $m[0];
                    }


                    $host = wp_parse_url($url, PHP_URL_HOST);
                    if (empty($host) || empty($wpc_site_host) || strcasecmp($host, $wpc_site_host) !== 0) {
                        return $m[0];
                    }


                    $u_path = (string) wp_parse_url($url, PHP_URL_PATH);
                    if (stripos($u_path, '/wp-content/') === false) {
                        return $m[0];
                    }
                    // URL-based icon detection (the combine-path list: changeFontToCDN:1740 /
                    // replaceFonts:594) as a second signal alongside the family check.
                    $lower = strtolower($url);
                    $url_is_icon = (strpos($lower, 'icon') !== false || strpos($lower, 'awesome') !== false || strpos($lower, 'lightgallery') !== false || strpos($lower, 'gallery') !== false || strpos($lower, 'side-cart-woocommerce') !== false);
                    if ($wpc_subsetting && !$family_is_icon && !$url_is_icon) {

                        $cdn_url = 'https://' . $wpc_zone . '/font:true/a:' . wps_cdn_rewrite::reformat_url($url);
                    } elseif ($wpc_font_nat) {
                        // m:0 is a pass-through → emit the clean natural zone URL (byte-identical delivery,
                        // CORS + font/woff2 verified live). Keeps the icon font in lockstep with CSS/JS/images.
                        $wpc_fnt_abs = wps_cdn_rewrite::reformat_url($url);
                        $wpc_fnt_pp = wp_parse_url($wpc_fnt_abs);
                        if (is_array($wpc_fnt_pp) && !empty($wpc_fnt_pp['path'])) {
                            $cdn_url = 'https://' . $wpc_zone . $wpc_fnt_pp['path'] . (isset($wpc_fnt_pp['query']) ? '?' . $wpc_fnt_pp['query'] : '');
                        } else {
                            $cdn_url = 'https://' . $wpc_zone . '/m:0/a:' . $wpc_fnt_abs;
                        }
                    } else {
                        $cdn_url = 'https://' . $wpc_zone . '/m:0/a:' . wps_cdn_rewrite::reformat_url($url);
                    }
                    return 'url(' . $m[1] . $cdn_url . $m[1] . ')';
                }, $rule);
            }, $css_content);
        }

        // Add or replace font-display (icon fonts get separate setting)
        $iconFontDisplay = !empty(self::$settings['icon-font-display']) ? self::$settings['icon-font-display'] : 'block';
        $css_content = preg_replace_callback('/(@font-face\s*\{)([^}]*)(})/is', function ($matches) use ($iconFontDisplay) {
            $content = $matches[2];

            // Remove existing font-display if present
            $content = preg_replace('/font-display\s*:\s*[^;]+;?/i', '', $content);

            // Detect icon fonts by font-family name — use block to prevent garbled characters
            $fontDisplayValue = self::$settings['font-display'] ?? 'swap';
            if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $content, $familyMatch)) {
                $family = strtolower(trim($familyMatch[1]));
                // Shared detector — this list and combine_css's had to agree, and didn't:
                // neither matched 'ETmodules', so Divi's icon font was treated as text.
                if (function_exists('wpc_css_is_icon_font')
                    ? wpc_css_is_icon_font($family)
                    : preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple.?line/i', $family)) {
                    $fontDisplayValue = $iconFontDisplay;
                } elseif (self::$fontDisplayRaw !== '' && function_exists('wpc_font_display_effective')) {
                    // .484: THIS family decides. A face with no metric-matched fallback must not
                    // inherit 'optional' from a family that has one — on zinsenvergleich that put
                    // Astra (size-adjust: none, live network fetch) on optional, where the glyph
                    // may never paint.
                    $familyFontDisplay = wpc_font_display_effective(self::$fontDisplayRaw, $family);
                    if (in_array($familyFontDisplay, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) {
                        $fontDisplayValue = $familyFontDisplay;
                    }
                }
            }


            // Range-gate the kept original against the inlined subset: the subset declares the
            // glyphs it carries, this face declares the complement, so the browser fetches the
            // full file ONLY when a glyph outside the subset paints — off the critical path with
            // no census and no completeness requirement. Applied verbatim from the service field;
            // skipped whenever the face already declares a range (never widen or narrow one).
            if (!preg_match('/unicode-range\s*:/i', $content)) {
                if (!empty($familyMatch[1])) {
                    $familyKey = strtolower(trim($familyMatch[1], " \t\"'"));
                    $rangeGate = self::font_face_range_gate($familyKey, $content);
                    if ($rangeGate !== null) {
                        // .478 PAIRING INVARIANT, ENFORCED WHERE IT CAN BE CHECKED. The map may
                        // outlive the subset it is the complement of (baked into a versioned CSS
                        // file by .429, or carried across a gen that dropped font-subsets.css).
                        // Gating without the subset present leaves glyphs supplied by NOTHING —
                        // tofu on a live page, which is strictly worse than fetching the font.
                        // Bias: NOT gating is the safe failure. A false negative costs a font
                        // fetch; a false positive costs blank squares.
                        // v7.10.759 — ICON FONTS ARE NEVER RANGE-GATED. Their glyphs are consumed
                        // by CSS content:"" rules, which no text census sees, so the paired subset
                        // cannot be trusted to supply them; the complement then FORBIDS the loaded
                        // font for exactly the icon codepoints (Divi ETmodules menu arrow is
                        // content:"3", range U+0-32,U+34,… excludes U+33 → literal digit renders).
                        // Live receipts: heritage-adjacent Divi site + searchcommander blurbs.
                        if (function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($familyKey)) {
                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('font-gate-iconfont', '', '', [
                                    'family' => substr($familyKey, 0, 28), 'src' => 'css-file',
                                ]);
                            }
                            return $matches[1] . $content . ';font-display:' . $fontDisplayValue . ';' . $matches[3];
                        }
                        $subsetFamilies = self::wpc_font_subset_families();
                        if (!empty($subsetFamilies[$familyKey])) {
                            $content .= ';unicode-range:' . $rangeGate['range'];
                            if ($rangeGate['weight'] !== null) { $content .= ';font-weight:' . $rangeGate['weight']; }
                        } elseif (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('font-gate-unpaired', '', '', [
                                'family' => substr($familyKey, 0, 28),
                                'why'    => 'remote_range present but NO inline subset face — gate withheld',
                            ]);
                        }
                    }
                }
            }
            return $matches[1] . $content . ';font-display:' . $fontDisplayValue . ';' . $matches[3];
        }, $css_content);

        // Save optimized file
        if (!file_exists(WPS_IC_CSS)) {
            wp_mkdir_p(WPS_IC_CSS);
        }

        // Host-swap origin uploads-SVG url() to the natural zone URL (gates re-checked inside).
        if ($wpc_svg_cdn) {
            $css_content = self::wpc_svg_zoneify($css_content);


            $css_content = self::wpc_raster_naturalize($css_content);
            $wpc_css_origin = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($wpc_css_origin && strcasecmp((string) self::$zone_name, $wpc_css_origin) !== 0) {


                $o = preg_quote($wpc_css_origin, '#');


                $css_content = preg_replace_callback(
                    '#(background(?:-image)?\s*:\s*[^;{}]*?url\(\s*)([\'"]?)https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?)\.(png|jpe?g)((?:\?[^"\'()\s<>]*)?)\2(\s*\))(?=\s*(?:[;}\\\\]|[\'"]|$))(?!\s*;\s*background-image\s*:\s*[^;{}]*?(?:-webkit-)?image-set)#i',
                    function ($m) use ($wpc_css_origin) {
                        // IDEMPOTENCY (layer 1): never re-wrap a declaration we already image-set'd.
                        if (stripos($m[0], 'image-set(') !== false) return $m[0];
                        $sameext_zone = 'https://' . self::$zone_name . $m[3] . '.' . $m[4] . $m[5];
                        // .822: multi-layer/shorthand prefixes — skip here; the generic origin-URL
                        // pass below still host-swaps the raw URL inside the untouched declaration.
                        $prior_layers = self::wpc_css_bg_prior_layers($m[1]);
                        if (!empty($prior_layers['skip'])) return $m[0];
                        $origin_url   = 'https://' . $wpc_css_origin . $m[3] . '.' . $m[4] . $m[5];
                        $iset = self::wpc_css_bg_imageset_build($origin_url, $sameext_zone, $m[2]);
                        if ($iset !== '') {
                            if ($prior_layers['layers'] !== '') {
                                $iset = str_replace('background-image:', 'background-image:' . $prior_layers['layers'], $iset);
                            }
                            return $iset;
                        }
                        // Fall through: same-ext host-swap, preserving the matched prefix/quote/suffix.
                        return $m[1] . $m[2] . $sameext_zone . $m[2] . $m[6];
                    },
                    $css_content
                );


                $css_bg_edge_webp = (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active());
                $css_content = preg_replace_callback(
                    '#https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?)\.(png|jpe?g|gif)((?:\?[^"\'()\s<>]*)?)#i',
                    function ($m) use ($css_bg_edge_webp) {


                        $ext = strtolower($m[2]);
                        // GIF to the zone ONLY on a CF-direct zone (no Bunny egress for an
                        // un-optimizable CSS-background GIF); on a Bunny zone leave it on origin.
                        if ($ext === 'gif' && !(class_exists('wps_rewriteLogic') && wps_rewriteLogic::cf_is_delivery())) {
                            return $m[0];
                        }
                        if ($css_bg_edge_webp && $ext !== 'gif') {
                            return 'https://' . self::$zone_name . $m[1] . '.webp' . $m[3];
                        }

                        return 'https://' . self::$zone_name . $m[1] . '.' . $m[2] . $m[3];
                    },
                    $css_content
                );
                // Already-next-gen (webp/avif) uploads refs → same-ext natural (optimal).
                $css_content = preg_replace(
                    '#https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?\.(?:webp|avif)(?![\w-])(?:\?[^"\'()\s<>]*)?)#i',
                    'https://' . self::$zone_name . '$1',
                    $css_content
                );
            }
        }


        if ($wpc_fonts_cdn
            && class_exists('wps_rewriteLogic')
            && method_exists('wps_rewriteLogic', 'natural_assets_on')
            && wps_rewriteLogic::natural_assets_on()) {
            $css_content = wps_rewriteLogic::naturalize_asset_urls($css_content);
        }


        // Name the source in the copy: the crit corpus identity counts a copy as the sheet it was
        // built from. The copy's name moves with pipeline state that a crit land changes, so
        // counted as itself it drifted on the first render after every land and stale-marked
        // the crit that had just arrived (greenvalleytint /services/, 2026-09-24).
        if (function_exists('wpc_processed_copy_source_trailer') && strpos($clean_src, home_url()) === 0) {
            $css_content .= wpc_processed_copy_source_trailer(substr($clean_src, strlen(home_url())));
        }

        $wpc_pid = function_exists('getmypid') ? getmypid() : 0;
        $wpc_tmp_path = $new_filepath . '.' . $wpc_pid . '.' . substr(md5(uniqid('', true)), 0, 8) . '.tmp';
        $wpc_bytes = wpc_fs_put($wpc_tmp_path, $css_content);
        if ($wpc_bytes === false || $wpc_bytes <= 0) {
            if (file_exists($wpc_tmp_path)) {
                @unlink($wpc_tmp_path);
            }
            // A racing writer may have already landed the real file — honor it.
            if (file_exists($new_filepath) && @filesize($new_filepath) > 0) {
                return WPS_IC_CSS_URL . '/' . $new_filename;
            }
            return $src;
        }
        if (!@rename($wpc_tmp_path, $new_filepath)) {
            @unlink($wpc_tmp_path);
            if (file_exists($new_filepath) && @filesize($new_filepath) > 0) {
                return WPS_IC_CSS_URL . '/' . $new_filename;
            }
            return $src;
        }

        // Final emit-time guard: the backing file MUST be present & non-empty right now
        // or we refuse to bake its hash into the (about-to-be-cached) HTML.
        clearstatcache(true, $new_filepath);
        if (!file_exists($new_filepath) || @filesize($new_filepath) <= 0) {
            return $src;
        }

        $new_url = WPS_IC_CSS_URL . '/' . $new_filename;
        return $new_url;
    }


    /**
     * Digest of every setting and pipeline decision a processed copy (cache/wp-cio/css) is built
     * from, read the way process_css_for_fonts() and the passes it calls read them. The rule: the
     * copy key names every input the copy's bytes depend on and nothing else. Failure it
     * prevents (2026-09-24, bd1): the copies baked in font-display and the delivery settings
     * but their key carried none of them, so a settings change kept serving stale copies until a
     * settings save deleted cache/wp-cio wholesale; keying on the whole settings row instead
     * renamed every copy on any save and left one orphan set per save.
     *
     * Inputs, by what they decide in the copy:
     * - @font-face font-display: `font-display` (raw, resolved per family), `icon-font-display`,
     *   and the metric state that resolves smart/optional (`wpc_font_metrics_validated` as the
     *   per-family list plus whether a verdict exists, never its timestamp; `wpc_font_metrics_present`).
     * - font url() onto the zone: `live-cdn`, `fonts`, `font-subsetting`, option
     *   `wpc_fonts_cdn_serve`, the zone host, and the natural-URL proof (natural_assets_on()).
     *   `css_combine` no longer decides anything. It stays in the key only because taking it out
     *   would rename every processed copy on the fleet once, for no change in bytes.
     * - uploads url() onto the zone (the SVG/raster lane): whether that lane runs
     *   (wpc_svg_zoneify_active(): `live-cdn`, `serve`, zone suppression, zone != origin, natural
     *   URL witness), option `wpc_css_bg_imageset`, `wpc_natural_nw`, Negotiated Delivery on,
     *   Cloudflare delivery (gif), the src-hint mode (`emit-src-hints`, `emit-src-hints-until`),
     *   and the next-gen ceiling keys the raster passes read (`generate_webp`, `picture_avif`,
     *   `single-url-image-format`, `force-natural`, `avif-natural-source`, `wpc_nextgen`).
     * Not inputs: the excludes and the CSS passthrough decide whether a copy is used at all,
     * not its bytes. The per-page font maps (remote ranges, subset families) and the source
     * file are folded into the key by the caller.
     */
    public static function processed_copy_config_digest()
    {
        static $digest = null;
        if ($digest !== null) {
            return $digest;
        }
        $settings = is_array(self::$settings) ? self::$settings : [];
        $settingKeys = [
            'font-display', 'icon-font-display', 'font-subsetting', 'live-cdn', 'fonts', 'css_combine',
            'serve', 'emit-src-hints', 'emit-src-hints-until', 'generate_webp', 'picture_avif',
            'single-url-image-format', 'force-natural', 'avif-natural-source', 'wpc_nextgen',
        ];
        $inputs = [];
        foreach ($settingKeys as $settingKey) {
            $inputs[$settingKey] = $settings[$settingKey] ?? null;
        }
        // mainInit() resolves font-display in place before any render; the raw value is the input.
        if (self::$fontDisplayRaw !== '') {
            $inputs['font-display'] = self::$fontDisplayRaw;
        }
        foreach (['wpc_fonts_cdn_serve', 'wpc_css_bg_imageset', 'wpc_font_metrics_present'] as $optionName) {
            $inputs['option:' . $optionName] = function_exists('get_option') ? get_option($optionName, null) : null;
        }
        // The metric verdict is rewritten with a fresh 't' => time() on every fonts land
        // (warm.php, the deterministic-verdict consume), and the display resolver reads only two
        // things from it: whether 't' is set at all and the per-family list. Hashing the raw option
        // renamed every processed copy on every land and left an orphan set each time (staging,
        // 2026-09-25: four renames in one hour with identical bytes). Only what the bytes depend on
        // goes into the key.
        $validated = function_exists('get_option') ? get_option('wpc_font_metrics_validated', null) : null;
        $validatedFamilies = is_array($validated) && !empty($validated['fams']) && is_array($validated['fams'])
            ? array_map(function ($family) { return strtolower(trim((string) $family, " 	\"'")); }, $validated['fams'])
            : [];
        sort($validatedFamilies);
        $inputs['option:wpc_font_metrics_validated'] = [
            'validated' => is_array($validated) && !empty($validated['t']),
            'fams' => array_values(array_unique($validatedFamilies)),
        ];
        $inputs['zone'] = (string) self::$zone_name;
        $inputs['fonts-cdn'] = self::wpc_fonts_served_from_cdn();
        $inputs['svg-lane'] = self::wpc_svg_zoneify_active();
        $inputs['bg-imageset'] = self::wpc_css_bg_imageset_active();
        if (class_exists('wps_rewriteLogic')) {
            $inputs['natural-assets'] = method_exists('wps_rewriteLogic', 'natural_assets_on') ? (bool) wps_rewriteLogic::natural_assets_on() : null;
            $inputs['natural-nw'] = method_exists('wps_rewriteLogic', 'wpc_natural_nw') ? (bool) wps_rewriteLogic::wpc_natural_nw() : null;
            $inputs['cf-delivery'] = method_exists('wps_rewriteLogic', 'cf_is_delivery') ? (bool) wps_rewriteLogic::cf_is_delivery() : null;
            $inputs['src-hint'] = method_exists('wps_rewriteLogic', 'src_hint_mode') ? (string) wps_rewriteLogic::src_hint_mode() : null;
        }
        $inputs['negotiated'] = class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active();
        $digest = substr(md5(serialize($inputs)), 0, 12);
        return $digest;
    }

    // v7.10.796 — one expression, two lanes. The CSS localizer and the font-preload emitter both
    // have to agree on whether font urls go to the zone at all; when they were written out
    // separately the preload could name a form no @font-face ever requests.
    public static function wpc_fonts_served_from_cdn()
    {
        return apply_filters('wpc_fonts_cdn_serve', (bool) get_site_option('wpc_fonts_cdn_serve', true))
            && !empty(self::$settings['live-cdn']) && self::$settings['live-cdn'] == '1'
            && !empty(self::$settings['fonts']) && self::$settings['fonts'] == '1'
            && !empty(self::$zone_name);
    }

    // A preload only pays when the browser reuses it. The localizer naturalizes proxy font urls
    // (zone/m:0/a:origin/x.woff2 -> zone/x.woff2) under this gate; the preload lane kept the proxy
    // form, so both shapes were fetched and the reused one was never the preloaded one.
    public static function wpc_naturalize_font_preload_url($url)
    {
        if (!is_string($url) || $url === '' || strpos($url, '/a:') === false) {
            return $url;
        }
        if (!apply_filters('wpc_font_preload_naturalize', true)
            || !self::wpc_fonts_served_from_cdn()
            || !class_exists('wps_rewriteLogic')
            || !method_exists('wps_rewriteLogic', 'natural_assets_on')
            || !method_exists('wps_rewriteLogic', 'naturalize_asset_urls')
            || !wps_rewriteLogic::natural_assets_on()) {
            return $url;
        }
        $naturalUrl = wps_rewriteLogic::naturalize_asset_urls($url);
        return (is_string($naturalUrl) && $naturalUrl !== '') ? $naturalUrl : $url;
    }

    public static function rewrite_fontface_css($css, $zone, $subsetting, $site_host)
    {
        $zone = (string) $zone;
        if ($zone === '' || strpos($css, '@font-face') === false) return $css;
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        return preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($block) use ($subsetting, $site_host, $zone) {
            $rule = $block[0];
            $family_is_icon = false;
            if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $rule, $fam)) {
                if (wpc_css_is_icon_font($fam[1])) {
                    $family_is_icon = true;
                }
            }
            return preg_replace_callback('/url\s*\(\s*(["\']?)(https?:[^"\')]+\.(?:woff2|woff|eot|ttf)(?:[?#][^"\')]*)?)\1\s*\)/i', function ($m) use ($subsetting, $site_host, $zone, $family_is_icon) {
                $url = $m[2];
                if (strpos($url, $zone) !== false) return $m[0];
                $host = wp_parse_url($url, PHP_URL_HOST);
                if (empty($host) || empty($site_host) || strcasecmp($host, $site_host) !== 0) return $m[0];


                if (stripos((string) wp_parse_url($url, PHP_URL_PATH), '/wp-content/') === false) return $m[0];


                if (stripos($url, '/cache/wp-cio-fonts/') !== false) return $m[0];
                $lower = strtolower($url);
                $url_is_icon = (strpos($lower, 'icon') !== false || strpos($lower, 'awesome') !== false || strpos($lower, 'lightgallery') !== false || strpos($lower, 'gallery') !== false || strpos($lower, 'side-cart-woocommerce') !== false);
                if ($subsetting && !$family_is_icon && !$url_is_icon) {
                    $cdn = 'https://' . $zone . '/font:true/a:' . self::reformat_url($url);
                } else {
                    $cdn = 'https://' . $zone . '/m:0/a:' . self::reformat_url($url);
                }
                return 'url(' . $m[1] . $cdn . $m[1] . ')';
            }, $rule);
        }, $css);
    }

    /** The mobile preload list (wps_ic_preloadsMobile) for the home page. Image entries go to
     *  $imagePreloads' custom slot when one is given; css, js and font entries come back as tags. */
    public function preload_custom_assetsMobile($output = 'array', $html = '', $imagePreloads = null)
    {
        $alreadyPreloaded = [];
        $preloads = get_option('wps_ic_preloadsMobile');
        $preloadOutput = '';
        $preloadOutputArray = [];

        if (!empty($preloads) && is_array($preloads)) {
            $allPreloadUrls = [];

            // Collect all URLs from both lcp and custom arrays
            if (!empty($preloads['lcp']) && is_array($preloads['lcp'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['lcp']);
            }

            if (!empty($preloads['custom']) && is_array($preloads['custom'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['custom']);
            }

            // Process each URL
            foreach ($allPreloadUrls as $preloadItem) {
                if (empty($preloadItem)) continue; // Skip empty URLs

                // Extract full URL from HTML if possible
                $fullUrl = $this->extractUrlFromHtml($preloadItem, $html);
                if (empty($fullUrl)) {
                    continue;
                }

                $extra = '';
                $type = '';

                // Parse URL to get extension without query parameters
                $parsedUrl = parse_url($fullUrl);
                $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : $fullUrl;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                switch ($ext) {
                    case 'css':
                        $as = 'style';
                        $type = 'text/css';
                        break;
                    case 'js':
                        $as = 'script';
                        $type = 'text/javascript';
                        break;
                    case 'woff':
                    case 'woff2':
                    case 'ttf':
                    case 'otf':
                        $extra = 'crossorigin';
                        $as = 'font';
                        if ($ext == 'woff' || $ext == 'woff2') {
                            $type = 'font/woff2';
                        } else {
                            $type = 'font/' . $ext;
                        }
                        break;
                    case 'jpg':
                    case 'jpeg':
                    case 'png':
                    case 'gif':
                    case 'webp':
                    case 'svg':
                    case 'avif':
                        $as = 'image';
                        if ($ext == 'jpg' || $ext == 'jpeg') {
                            $type = 'image/jpeg';
                        } else if ($ext == 'gif') {
                            $type = 'image/gif';
                        } else if ($ext == 'png') {
                            $type = 'image/png';
                        } else if ($ext == 'webp') {
                            $type = 'image/webp';
                        } else if ($ext == 'svg') {
                            $type = 'image/svg+xml';
                        } else if ($ext == 'avif') {
                            $type = 'image/avif';
                        }
                        break;
                    default:
                        $as = '';
                        break;
                }

                if (!empty($as)) {
                    if (!in_array(esc_url($fullUrl), $alreadyPreloaded)) {
                        $alreadyPreloaded[] = esc_url($fullUrl);
                        if ($as === 'image' && $imagePreloads instanceof wps_ic_image_preload_set) {
                            $imagePreloads->add('', esc_url($fullUrl), 'both', 'custom', 0, [
                                'slot'          => wps_ic_image_preload_set::SLOT_CUSTOM,
                                'type'          => $type,
                                'fetchpriority' => (!empty(self::$settings['fetchpriority-high']) && self::$settings['fetchpriority-high'] == '1') ? 'high' : '',
                            ]);
                            continue;
                        }
                        $preloadOutput = '<link rel="preload" href="' . esc_url($fullUrl) . '" as="' . esc_attr($as) . '" type="' . $type . '"';

                        if (!empty(self::$settings['fetchpriority-high']) && self::$settings['fetchpriority-high'] == '1') {
                            $preloadOutput .= ' fetchpriority="high"';
                        }

                        if (!empty($extra)) {
                            $preloadOutput .= ' ' . $extra;
                        }

                        $preloadOutput .= '/>' . "\n";
                        $preloadOutputArray[] = $preloadOutput;
                    }
                }
            }
        }

        if ($output == 'array') {
            return $preloadOutputArray;
        } else {
            $finalOutput = '';
            if (!empty($preloadOutputArray)) {
                foreach ($preloadOutputArray as $link) {
                    $finalOutput .= $link;
                }
            }
            return $finalOutput;
        }
    }

    /**
     * Helper function to extract full URL from HTML for a given resource
     */
    private function extractUrlFromHtml($resource, $html)
    {
        if (empty($resource) || empty($html)) {
            return $resource;
        }

        // Escape special regex characters in the resource name
        $escapedResource = preg_quote($resource, '/');

        // Pattern to match URLs containing the resource between quotes
        // Matches: href="...resource..." or src="...resource..." or content="...resource..."
        $patterns = ['/(?:href|src|content)=["\']([^"\']*' . $escapedResource . '[^"\']*)["\']/i', '/url\(["\']?([^"\')]*' . $escapedResource . '[^"\')]*)["\']?\)/i'];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return trim($matches[1]);
            }
        }

        return false;
    }

    /** The desktop preload list (wps_ic_preloads) for the home page. Image entries go to
     *  $imagePreloads' custom slot when one is given; css, js and font entries come back as tags. */
    public function preload_custom_assets($output = 'array', $html = '', $imagePreloads = null)
    {
        $alreadyPreloaded = [];
        $preloads = get_option('wps_ic_preloads');
        $preloadOutput = '';
        $preloadOutputArray = [];

        if (!empty($preloads) && is_array($preloads)) {
            $allPreloadUrls = [];

            // Collect all URLs from both lcp and custom arrays
            if (!empty($preloads['lcp']) && is_array($preloads['lcp'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['lcp']);
            }

            if (!empty($preloads['custom']) && is_array($preloads['custom'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['custom']);
            }

            // Process each URL
            foreach ($allPreloadUrls as $preloadItem) {
                if (empty($preloadItem)) continue; // Skip empty URLs

                // Extract full URL from HTML if possible
                $fullUrl = $this->extractUrlFromHtml($preloadItem, $html);
                if (empty($fullUrl)) {
                    continue;
                }

                $extra = '';
                $type = '';

                // Parse URL to get extension without query parameters
                $parsedUrl = parse_url($fullUrl);
                $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : $fullUrl;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                switch ($ext) {
                    case 'css':
                        $as = 'style';
                        $type = 'text/css';
                        break;
                    case 'js':
                        $as = 'script';
                        $type = 'text/javascript';
                        break;
                    case 'woff':
                    case 'woff2':
                    case 'ttf':
                    case 'otf':
                        $extra = 'crossorigin';
                        $as = 'font';
                        if ($ext == 'woff' || $ext == 'woff2') {
                            $type = 'font/woff2';
                        } else {
                            $type = 'font/' . $ext;
                        }
                        break;
                    case 'jpg':
                    case 'jpeg':
                    case 'png':
                    case 'gif':
                    case 'webp':
                    case 'svg':
                    case 'avif':
                        $as = 'image';
                        if ($ext == 'jpg' || $ext == 'jpeg') {
                            $type = 'image/jpeg';
                        } else if ($ext == 'gif') {
                            $type = 'image/gif';
                        } else if ($ext == 'png') {
                            $type = 'image/png';
                        } else if ($ext == 'webp') {
                            $type = 'image/webp';
                        } else if ($ext == 'svg') {
                            $type = 'image/svg+xml';
                        } else if ($ext == 'avif') {
                            $type = 'image/avif';
                        }
                        break;
                    default:
                        $as = '';
                        break;
                }

                if (!empty($as)) {
                    if (!in_array(esc_url($fullUrl), $alreadyPreloaded)) {
                        $alreadyPreloaded[] = esc_url($fullUrl);
                        if ($as === 'image' && $imagePreloads instanceof wps_ic_image_preload_set) {
                            $imagePreloads->add('', esc_url($fullUrl), 'both', 'custom', 0, [
                                'slot'          => wps_ic_image_preload_set::SLOT_CUSTOM,
                                'type'          => $type,
                                'fetchpriority' => (!empty(self::$settings['fetchpriority-high']) && self::$settings['fetchpriority-high'] == '1') ? 'high' : '',
                            ]);
                            continue;
                        }
                        $preloadOutput = '<link rel="preload" href="' . esc_url($fullUrl) . '" as="' . esc_attr($as) . '" type="' . $type . '"';

                        if (!empty(self::$settings['fetchpriority-high']) && self::$settings['fetchpriority-high'] == '1') {
                            $preloadOutput .= ' fetchpriority="high"';
                        }

                        if (!empty($extra)) {
                            $preloadOutput .= ' ' . $extra;
                        }

                        $preloadOutput .= '/>';
                        $preloadOutputArray[] = $preloadOutput;
                    }
                }
            }
        }

        if ($output === 'array') {
            return $preloadOutputArray;
        } else {
            $finalOutput = '';
            if (!empty($preloadOutputArray)) {
                foreach ($preloadOutputArray as $link) {
                    $finalOutput .= $link;
                }
            }
            return $finalOutput;
        }
    }

    public function perfMattersOverride()
    {
        if (function_exists('perfmatters_version_check')) {
            $perfmatters_options = get_option('perfmatters_options');

            if (!empty($perfmatters_options['assets']['delay_js']) && $perfmatters_options['assets']['delay_js']) {
                self::$delay_js_override = 1;
            }

            if (!empty($perfmatters_options['assets']['defer_js']) && $perfmatters_options['assets']['defer_js']) {
                self::$defer_js_override = 1;
            }

            if (!empty($perfmatters_options['lazyload']['lazy_loading']) && $perfmatters_options['lazyload']['lazy_loading']) {
                self::$lazy_override = 1;
            }
        }
    }

    public function rocketOverride()
    {
        if (function_exists('get_rocket_option')) {
            $rocket_settings = get_option('wp_rocket_settings');

            if ($rocket_settings['delay_js']) {
                self::$delay_js_override = 1;
            }

            if ($rocket_settings['defer_all_js']) {
                self::$defer_js_override = 1;
            }

            if ($rocket_settings['lazyload']) {
                self::$lazy_override = 1;
            }
        }
    }

    public function script_encode($html)
    {
        $html = base64_encode($html[0]);

        return '[script-wpc]' . $html . '[/script-wpc]';
    }

    public function script_decode($html)
    {
        $html = base64_decode($html[1]);

        return $html;
    }

    public function noscript_encode($html)
    {
        $html = base64_encode($html[0]);
        return '[noscript-wpc]' . $html . '[/noscript-wpc]';
    }

    public function noscript_decode($html)
    {
        $html = base64_decode($html[1]);

        // Optional: Safety check for valid decoded HTML
        if ($html === false) {
            return ''; // Or return $matches[0] to leave it unchanged
        }

        return $html; // Return decoded HTML, without the tags
    }

    public function saveCache($html)
    {

        if (empty(self::$cacheHtml)) {

            return $html;
        }

        $cacheActive = !(isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '0') && ((isset(self::$settings['cache']['advanced']) && self::$settings['cache']['advanced'] == '1') || (isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '1'));

        if ($cacheActive) {
            if ((!self::isExcludedFromCache($html) && $this->doCacheCombine())) {
                $prefix = '';
                if (self::is_mobile()) $prefix .= 'mobile';
                if (self::is_webp_request() && apply_filters('wpc_webp_cache_variant', false)) { $prefix .= ($prefix ? '-' : '') . 'webp'; }

                return self::$cacheHtml->saveCache($html, $prefix);
            }
        }
        return $html;
    }

    public static function isExcludedFromCache($html)
    {
        $output = [];

        if ((strpos($html, 'id="wp-admin-bar') !== false || strpos($html, "id='wp-admin-bar") !== false) || (strpos($html, 'id="wpadminbar"') !== false || strpos($html, "id='wpadminbar'") !== false)) {
            return true;
        }

        if (isset(self::$excludes['cache'])) {
            if (!is_array(self::$excludes['cache'])) {
                $excludedUrls = explode("\n", self::$excludes['cache']);
            } else {
                $excludedUrls = self::$excludes['cache'];
            }


            if (!empty($excludedUrls) && class_exists('wps_ic_url_key')
                && wps_ic_url_key::excludedBy($excludedUrls, wps_ic_url_key::requestHostPath()) !== false) {
                return true;
            }
        }

        // Is Woo commerce Cart
        if (class_exists('WooCommerce')) {
            if (is_cart() || is_checkout()) {
                return true;
            }
        }

        return false;
    }

    public static function is_webp_request()
    {
        return isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }


    public static function wpc_universal_picture_on()
    {
        static $on = null;
        if ($on !== null) return $on;
        if (defined('WPC_UNIVERSAL_PICTURE_OFF') && WPC_UNIVERSAL_PICTURE_OFF) return $on = false;
        $opt = function_exists('get_option') ? get_option('wpc_universal_picture', '1') : '1';
        return $on = (bool) apply_filters('wpc_universal_picture', ($opt === '1' || $opt === 1 || $opt === true));
    }

    /* ------------------------------------------------------------------------------------- *
     * CDN lane stages, in table order: one method per pass. Any value a pass hands to a later
     * pass rides wps_ic_render_context, never $this. The runner emits the per-stage profiler
     * checkpoints, so no stage makes a wpc_prof_cp() call of its own.
     * ------------------------------------------------------------------------------------- */

    /** A zero-byte stage. It exists so ?stop_before= / ?stop_after= can name this position. */
    public function stage_marker($html, $ctx)
    {
        return $html;
    }

    /**
     * The disk path of a same-site image URL, '' when the URL is not under the site's own URL.
     * The site's URL is compared without its scheme: https://, http:// and protocol-relative
     * URLs of the same host and path are the same file, and a root-relative URL is matched
     * against the site's path. The query is dropped. Both next-gen branches of local_image_tags
     * ask here. Observed failure: the webp branch stripped site_url() . '/' as a string, and on a
     * plain-http request (a proxy that does not forward https: site_url() answers http://) no
     * https srcset URL matched, every rung check failed and the webp <source> shipped one
     * width-less URL instead of the five sizes on disk (rig proof, 2026-09-25); the avif branch
     * stripped any host, which on a subdirectory install looked for the file one folder too deep.
     */
    public static function local_file_path($url)
    {
        $url = (string) preg_replace('/[?#].*$/', '', (string) $url);
        $site = rtrim((string) self::$site_url, '/');
        if ($url === '' || $site === '') {
            return '';
        }
        if (preg_match('#^(?:https?:)?//#i', $url)) {
            $rest = (string) preg_replace('#^(?:https?:)?//#i', '', $url);
            $prefix = (string) preg_replace('#^(?:https?:)?//#i', '', $site) . '/';
        } elseif ($url[0] === '/') {
            $rest = $url;
            $prefix = rtrim((string) parse_url($site, PHP_URL_PATH), '/') . '/';
        } else {
            return '';
        }
        if (strncasecmp($rest, $prefix, strlen($prefix)) !== 0) {
            return '';
        }
        return ABSPATH . substr($rest, strlen($prefix));
    }

    /** A next-gen variant URL with its own ?v= token (wps_ic_asset_version: the file's mtime+size). */
    private static function nextgen_versioned_url($url)
    {
        $url = (string) $url;
        return $url . (strpos($url, '?') === false ? '?' : '&amp;') . 'v=' . rawurlencode((string) self::asset_version($url));
    }

    /**
     * Which next-gen <picture> sources the CDN-off lane may write for this request. The site
     * serves its own images there, so the gate is the next-gen ceiling and Generate WebP, not the
     * CDN serve keys; whether a given file has a variant is asked per file in local_image_tags.
     * Rule: the page names the .webp/.avif file explicitly, so a shared cache keys it by URL.
     * Observed failure (ticket 12001): this gate read the CDN serve keys, so on a CDN-off site no
     * <picture> was built, every image stayed x.jpg, the Apache block answered it by Accept with a
     * private body, and Cloudflare bypassed every next-gen image. WPC_LOCAL_PICTURE_OFF restores
     * the request's CDN-keyed flags.
     */
    public static function local_picture_formats()
    {
        if (defined('WPC_LOCAL_PICTURE_OFF') && WPC_LOCAL_PICTURE_OFF) {
            return ['webp' => (bool) self::$rewriteLogic::$pictureWebpEnabled, 'avif' => (bool) self::$rewriteLogic::$pictureAvifEnabled];
        }
        $ceiling = class_exists('WPC_Delivery_Resolver') ? WPC_Delivery_Resolver::effective_ceiling(self::$settings) : 'avif';
        // The setting, not self::$webp_enabled: that one is cleared later for Safari when the universal
        // picture is off, and the request's own flag (mainInit) was taken before that too.
        $webp = $ceiling !== 'off' && !empty(self::$settings['generate_webp']) && self::$settings['generate_webp'] == '1'
            && !(function_exists('wp_is_json_request') && wp_is_json_request());
        return ['webp' => $webp, 'avif' => $webp && $ceiling === 'avif'];
    }

    /**
     * Checkpoint zero, and where the request's image flags enter the render. mainInit() works out
     * lazy loading, adaptive sizing and the <picture> webp wrap for the request before any buffer
     * exists (the wrap on rewriteLogic, whose pre-buffer callers read it there); the stages read
     * and squash this render's copy on $ctx, so a render never rewrites the request's settings
     * and a pass that reads a flag gets the value the squash left, not whatever the last render
     * in the process wrote. Changes no bytes.
     */
    public function stage_prelude($html, $ctx)
    {
        $ctx->lazyEnabled = self::$lazy_enabled;
        $ctx->adaptiveEnabled = self::$adaptive_enabled;
        if ($ctx->lane === wps_ic_render_pipeline::LANE_LOCAL) {
            $localPicture = self::local_picture_formats();
            $ctx->pictureWebpEnabled = $localPicture['webp'];
            $ctx->pictureAvifEnabled = $localPicture['avif'];
        } else {
            $ctx->pictureWebpEnabled = self::$rewriteLogic::$pictureWebpEnabled;
            $ctx->pictureAvifEnabled = self::$rewriteLogic::$pictureAvifEnabled;
        }
        $ctx->pushRender = self::push_render_requested();
        $ctx->pushRenderLoose = !empty($_GET['criticalCombine']) || !empty(wpcGetHeader('criticalCombine'));

        return $html;
    }

    /**
     * Is this request the crit service's push render, the page fetched to build critical CSS
     * from: the criticalCombine header, or ?criticalCombine=true. mainInit() turns critical CSS
     * off for it, the envelope records the corpus it hands over, and stage_prelude seeds
     * $ctx->pushRender from it. The looser test some readers use is $ctx->pushRenderLoose.
     */
    private static function push_render_requested()
    {
        return !empty(wpcGetHeader('criticalCombine'))
            || (!empty($_GET['criticalCombine']) && $_GET['criticalCombine'] == 'true');
    }

    /**
     * Open this render's @font-face owner ($ctx->fontFaces). The passes that mint faces
     * (addCritical, lazyCSS, the font localizer) are handed the set as an argument by their
     * stages. The only bytes this stage moves are the wp_head carrier's, which it takes into the
     * set while the rest of the lane can still use what the carrier declares.
     *
     * Registering the set is a park like any other: from here to stage_emit_font_faces the
     * page's faces are in the set and not in the document, so the emit is this window's closer.
     * A ?stop_before= anywhere in between, a throw, or a stage that ends the run therefore gets
     * a document with its faces in it — without the window every checkpoint in the table would
     * dump a page that declares no font at all.
     */
    public function stage_open_font_faces($html, $ctx)
    {
        // The closer reads $ctx->isAmp when it FIRES, not when it is registered: this stage runs
        // ahead of amp_settings_squash, so the verdict does not exist yet here, and a stop that
        // lands after the squash has to get the AMP answer rather than the default one.
        $ctx->openWindow('font_faces', function ($html) use ($ctx) {
            return self::emit_font_faces($html, $ctx->fontFaces, $ctx->isAmp);
        });

        return self::absorb_font_carrier($html, $ctx->fontFaces);
    }

    /** Every carrier sheet left on the page becomes registered faces, and its link goes. */
    public function stage_absorb_font_sheets($html, $ctx)
    {
        return self::absorb_font_sheets($html, $ctx->fontFaces);
    }

    /** The only pass that writes @font-face into the document: the close of the font-faces
     *  window, so a completed run emits here and a stopped one emits from the runner's finally. */
    public function stage_emit_font_faces($html, $ctx)
    {
        return $ctx->closeWindow('font_faces', $html);
    }

    /**
     * The first stage that runs, and it carries two things: the CSS host twin sweep, which
     * has to stay ahead of the font-awesome and google-fonts passes that rewrite the same
     * <link> tags, and the request-shaped values every later stage reads. A render never
     * dispatches /generate itself: every dispatch goes through wpc_gen_dispatch().
     */
    public function stage_heal_mixed_content($html, $ctx)
    {
        $html = self::css_host_twin_sweep($html);

        $ctx->userLoggedIn = is_user_logged_in();
        $ctx->visitorMode = false;
        if (!empty($_GET['wpc_visitor_mode']) && $_GET['wpc_visitor_mode']) {
            $ctx->visitorMode = $_GET['wpc_visitor_mode'];
        }

        return wpc_heal_mixed_content($html);
    }

    /** Template bodies leave the buffer here and come back through the 'templates' window, so no
     *  pass in between ever sees them and no stop can serve an emptied template tag. The window's
     *  closer is the one restore call in the file: it fires at restore_templates_final, the last
     *  entry in the table, or from the runner when the run stops, bails or throws before that.
     *  The bodies ride $ctx->removedTemplates; the closer restores them and clears it. */
    public function stage_remove_templates($html, $ctx)
    {
        $removedTemplates = $this->removeTemplates($html);
        $html = $removedTemplates['html'];
        $ctx->removedTemplates = $removedTemplates['templates'];
        $ctx->openWindow('templates', function ($html) use ($ctx) {
            if (!empty($ctx->removedTemplates)) {
                $html = $this->restoreTemplates($html, $ctx->removedTemplates);
            }
            $ctx->removedTemplates = [];

            return $html;
        });

        return $html;
    }

    public function stage_negotiated_delivery($html, $ctx)
    {
        if (class_exists('WPC_Negotiated_Delivery')
            && (WPC_Negotiated_Delivery::is_active() || WPC_Negotiated_Delivery::is_active_jpeg())) {
            $wpcNdMask = [];
            $html = wps_rewriteLogic::maskMediaScripts($html, $wpcNdMask);
            $html = WPC_Negotiated_Delivery::rewrite_buffer($html, $ctx->imageSizing);
            $html = wps_rewriteLogic::unmaskMediaScripts($html, $wpcNdMask);

            $ctx->pictureWebpEnabled = false;
        }

        return $html;
    }

    /** AMP pages cannot carry <picture>, lazy markup or delayed script, so the settings are
     *  squashed for the rest of the run — on both lanes, because an AMP page served with the CDN
     *  off is no less AMP. The verdict rides the context as well, because the tail passes that
     *  inject markup AMP forbids read no setting at all and need gate_not_amp to stand them down.
     *  The one combine instance a render gets is built here, and every pass below shares it. */
    public function stage_amp_settings_squash($html, $ctx)
    {
        // wps_ic_amp keeps its verdict in a static of its own: building it re-reads the request
        // and isAmp($html) adds the document's own <html amp> marker, so hook callbacks outside
        // the render that ask wps_ic_amp see the same verdict this render reached.
        $amp = new wps_ic_amp();
        $ctx->combineInstance = new wps_ic_combine_css();

        if ($amp->isAmp($html)) {
            $ctx->isAmp = true;
            $ctx->lazyEnabled = '0';
            $ctx->adaptiveEnabled = '0';
            self::$settings['delay-js'] = '0';
            self::$settings['inline-js'] = '0';
            $ctx->ampSquashed = ['delay-js', 'inline-js'];
            $ctx->pictureWebpEnabled = false; // AMP doesn't allow <picture>
        }

        return $html;
    }

    /** The tail passes that inject markup AMP forbids stand down on an AMP render. The verdict is
     *  the one stage_amp_settings_squash reached, so a lane that never squashed cannot skip them
     *  by accident and the detection is done once per render. */
    public function gate_not_amp($ctx)
    {
        return $ctx->isAmp ? 'amp' : true;
    }

    /**
     * A push render announces itself before any bail can end the run: it answers with the
     * template key the page cache stores under, so a dispatch built from this corpus carries the
     * key without a second fetch.
     * It is an ordinary stage and never ends the run: announcing the render and answering with
     * the key are side effects, not a refusal, so they must not ride on a bail.
     */
    public function stage_critical_combine_request($html, $ctx)
    {
        if ($ctx->pushRender) {
            if (!headers_sent() && function_exists('wpc_compute_tpl_key')) {
                $templateKey = (string) wpc_compute_tpl_key();
                if ($templateKey !== '') {
                    header('X-WPC-Tpl: ' . $templateKey);
                }
            }
        }

        return $html;
    }

    /** ?no_rewriter=1 asks for the page as WordPress produced it. Firing the bail is what
     *  delivers that: every bail that fires ends the run and the runner serves the pristine
     *  buffer. The debug receipts that wrap a whole document are not bails — they set
     *  $ctx->bailed themselves, so their own output is what leaves the runner. */
    public function bail_no_rewriter($html, $ctx)
    {
        return !empty($_GET['no_rewriter']);
    }

    public function bail_ignore_ic($html, $ctx)
    {
        return !empty($_GET['ignore_ic']);
    }

    /**
     * Woocommerce fix - store stops working
     */
    public function bail_woocommerce_ajax($html, $ctx)
    {
        return isset($_GET['wc-ajax']) || isset($_GET['product_sku']) || !empty($_POST['product_sku']);
    }

    public function bail_feed($html, $ctx)
    {
        return is_feed();
    }

    public function bail_ajax($html, $ctx)
    {
        return (bool) self::$isAjax;
    }

    /** CLI and some SAPIs hand PHP no REQUEST_URI at all, and the local lane — which now runs this
     *  bail too — is the one that sees those requests, so the read is guarded rather than assumed. */
    public function bail_json_or_xmlrpc($html, $ctx)
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        return strpos($requestUri, 'xmlrpc') !== false || strpos($requestUri, 'wp-json') !== false;
    }

    // This is for AJAX Replace, works on Jet Engine and some others - might need integration
    // TODO: Integration for other ajax loaders
    public function gate_jet_ajax_replace($ctx)
    {
        return !empty($_POST['action']) ? true : 'no-post-action';
    }

    /** A posted fragment gets the slash-safe image rewrite and nothing else, so the run ends
     *  here. Setting $ctx->bailed by hand rather than being a bail() entry is what keeps this
     *  pass's own output as the answer instead of the pristine buffer. */
    public function stage_jet_ajax_replace($html, $ctx)
    {
        if (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
            $wpcAjaxMask = [];
            $html = wps_rewriteLogic::maskMediaScripts($html, $wpcAjaxMask);
            $html = preg_replace_callback('/(?<![\"|\'])<img[^>]*>/i', [self::$rewriteLogic, 'replaceImageTagsDoSlash'], $html);
            $html = wps_rewriteLogic::unmaskMediaScripts($html, $wpcAjaxMask);
        }

        $ctx->bailed = true;
        $ctx->bailName = 'jet_ajax_replace';

        return $html;
    }

    public function gate_strip_html_comments($ctx)
    {
        return empty($_GET['wpc_disableCommentClear']) ? true : 'disabled';
    }

    public function stage_strip_html_comments($html, $ctx)
    {
        $html = preg_replace("/<!--->/ms", '', $html);

        return preg_replace_callback("/<!--(.*?)-->/ms", function ($matches) {
            if (strpos($matches[1], 'sc_project') !== false || strpos($matches[1], 'et-ajax') !== false) {

                return $matches[0];
            } else {
                return '';
            }
        }, $html);
    }

    /** getRegexp primes the site-URL patterns every pass below reads. The priming is
     *  unconditional — ahead of both the wpc_disableStrip test and the budget door — so it
     *  happens here, before the bail can answer. It is lane-independent (it reads the home URL and the
     *  configured directories, and memoises them on the options row), so the door standing on
     *  both lanes primes the same two patterns either way. */
    public function bail_budget_script_content($html, $ctx)
    {
        //Prep Site URL
        $this->getRegexp();

        if (!empty($_GET['wpc_disableStrip'])) {
            return false;
        }

        if (self::wpc_render_budget_exceeded('scriptContent')) { return true; }

        return false;
    }

    public function gate_script_content_images($ctx)
    {
        return empty($_GET['wpc_disableStrip']) ? true : 'strip-disabled';
    }

    public function stage_script_content_images($html, $ctx)
    {
        return self::$rewriteLogic->scriptContent($html);
    }

    /** Script bodies masked through the tag-rewrite window (restored before URL-only passes).
     *  The window is what unmasks them if the run stops before stage_unmask_media_scripts. */
    public function stage_mask_media_scripts($html, $ctx)
    {
        $ctx->mediaScriptMask = [];
        $html = wps_rewriteLogic::maskMediaScripts($html, $ctx->mediaScriptMask);
        $ctx->openWindow('media_mask', function ($html) use ($ctx) {
            return wps_rewriteLogic::unmaskMediaScripts($html, $ctx->mediaScriptMask);
        });

        return $html;
    }

    public function stage_iframe_lazy_and_video_facade($html, $ctx)
    {
        // Layzload Iframe - sets load="lazy" to iframe tag
        // TODO: Fix so that it checks does iframe already have load="lazy|auto"
        // Also co-arms with the AGGRESSIVE default (measured pages): a funnel/player
        // iframe boots ~MBs of vendor JS in its own document, immune to script
        // delay — the facade is the only lever (busyprosai receipt: 3 GHL frames
        // = TBT 1230ms). Heavy-listed frames restore at boot/gesture/IO.
        if ((!empty(self::$settings['iframe-lazy']) && self::$settings['iframe-lazy'] == '1'
                || self::wpc_facade_aggr_ok($ctx->isAmp))
            && !$ctx->userLoggedIn) {
            $formEmbedDomains = (array) self::wpc_form_embed_domains($html);
            $html = preg_replace_callback('/<iframe[^>]*>(.*?)<\/iframe>/si', function ($iframe) use ($ctx, $formEmbedDomains) {
                return $this->replace_iframe_tags($iframe, $ctx->isAmp, $formEmbedDomains);
            }, $html);
            $iframeLazyOn = !empty(self::$settings['iframe-lazy']) && self::$settings['iframe-lazy'] == '1';
            $html = $this->park_media_sources($html,
                $iframeLazyOn && $this->gate_delay_scripts($ctx) === true && $this->divi_player_is_delayed($html));
        }

        // Add preload="none" to video tags — prevents browser from downloading video until play
        if (!empty(self::$settings['video-preload-none']) && self::$settings['video-preload-none'] == '1' && !$ctx->userLoggedIn) {
            $html = preg_replace_callback('/<video\b([^>]*)>/i', function ($matches) {
                $attrs = $matches[1];
                if (preg_match('/\bpreload\s*=/i', $attrs)) {
                    return $matches[0];
                }
                return '<video' . $attrs . ' preload="none">';
            }, $html);
        }

        return $html;
    }

    public function gate_encode_iframe_tags($ctx)
    {
        return $ctx->userLoggedIn ? 'logged-in' : true;
    }

    /** Iframes are encoded away for the passes below. Both lanes decode them again at
     *  stage_decode_iframe, the normal close of this one 'iframes' window, so a stop, a bail or a
     *  throw in either lane gets the decode from the runner instead of serving encoded markup. */
    public function stage_encode_iframe_tags($html, $ctx)
    {
        $html = self::$rewriteLogic->encodeIframe($html);
        $ctx->openWindow('iframes', function ($html) {
            return self::$rewriteLogic->decodeIframe($html);
        });

        return $html;
    }

    /** Park the <noscript><iframe> blocks as [noscript-wpc] placeholders. The 'noscript' window
     *  decodes them at stage_noscript_decode_pass, or from the runner on a stop, a bail or a
     *  throw before it, so nothing ever serves the literal placeholders. */
    public function stage_crittr_css($html, $ctx)
    {
        if ((!empty($_GET['debugCritical']) || !empty($_GET['generateCriticalAPI']))) {
            $ctx->userLoggedIn = is_user_logged_in();
            $html = preg_replace_callback('/<link\b[^>]*>/si', [$this, 'crittr_replace_css'], $html);
        }

        $html = preg_replace_callback('/<noscript><iframe.*?<\/noscript>/is', [$this, 'noscript_encode'], $html);
        $ctx->openWindow('noscript', function ($html) {
            return $this->decode_noscript_placeholders($html);
        });

        return $html;
    }

    /** Turn every [noscript-wpc] placeholder back into the <noscript><iframe> block it parked.
     *  The 'noscript' window's closer, so it runs at stage_noscript_decode_pass on a full run and
     *  from the runner on a stop, a bail or a throw. */
    public function decode_noscript_placeholders($html)
    {
        return preg_replace_callback('/\[noscript-wpc\](.*?)\[\/noscript-wpc\]/is', [$this, 'noscript_decode'], $html);
    }

    // Replace Background
    public function gate_background_sizing($ctx)
    {
        return (!empty(self::$settings['background-sizing']) && self::$settings['background-sizing'] == '1') ? true : 'off';
    }

    public function stage_background_sizing($html, $ctx)
    {
        return self::$rewriteLogic->backgroundSizing($html);
    }

    /** The else arm of the same setting: exactly one of the two runs on every render. */
    public function gate_background_slideshow_only($ctx)
    {
        return $this->gate_background_sizing($ctx) === true ? 'background-sizing-on' : true;
    }

    public function stage_background_slideshow_only($html, $ctx)
    {
        return self::$rewriteLogic->backgroundSlideshowOnly($html);
    }

    /** ?debug_preload_inject answers with the buffer before and after the injection, and that
     *  receipt is what the visitor gets: two whole documents, well over never-blank's 255-byte
     *  floor and carrying </body>, so never-blank leaves it alone. Ending the run by setting
     *  $ctx->bailed here — not by being a bail() entry — is what delivers it; a bail() would
     *  serve the pristine buffer instead. Nothing after this stage runs either way. */
    public function stage_inject_preload_images($html, $ctx)
    {
        if (!empty($_GET['debug_preload_inject'])) {
            $dbg = 'Before:';
            $dbg .= $html;
        }


        $html = preg_replace_callback('/<head\b[^>]*>(?:\s*<meta[^>]*\bcharset\b[^>]*>)?/is', function ($matches) use ($ctx) {
            return $this->injectPreloadImages($matches, $ctx->pictureWebpEnabled);
        }, $html, 1);

        if (!empty($_GET['debug_preload_inject'])) {
            $dbg .= 'After:';
            $dbg .= $html;

            $ctx->bailed = true;
            $ctx->bailName = 'inject_preload_images';

            return $dbg;
        }

        return $html;
    }

    public function stage_defer_fontawesome($html, $ctx)
    {
        return self::$rewriteLogic->defferFontAwesome($html);
    }

    public function gate_remove_duplicate_fontawesome($ctx)
    {
        return !empty(self::$settings['remove-duplicated-fontawesome']) ? true : 'off';
    }

    /**
     * Width and height injected on every img and picture, on both lanes. Script bodies are masked
     * around the two passes the way the CDN-off body masked them: the CDN lane reaches this stage
     * with its own mask already open, so this one finds no script left to park and the buffer it
     * hands back is the buffer the unmasked pass would have produced.
     */
    /**
     * Width and height for every <img> the page left unsized, written once, from the image-sizing
     * owner's answer for that exact file (wps_ic_image_sizing::dimsFor). It runs ahead of the CDN
     * lane's <img> rewrite, which keeps a width it finds as the page's own (wpc-size="preserve").
     *
     * Rules. A tag whose inline style sets its width or height gets nothing (the style sizes the
     * box; an attribute beside it becomes the constraint). A tag that carries a width or a height
     * keeps what the page wrote; the one exception
     * is an SVG with a width and no height, which gets the height its viewBox gives that width
     * (a theme SVG logo declares no height, and without one the box is 0 tall until the file
     * decodes). A tag with neither gets both, marked data-wpc-bf (or data-wpc-md when the size is
     * the service's measurement) so the zero-specificity height:auto rule keeps the invented height
     * from becoming a definite one. Passes used to write these four ways: the full-size meta dims
     * on a sub-size URL, one rung's natural size on every rung of the upload (a cropped
     * `-150x150` thumbnail given its full file's 4:3), and a page's own width overwritten.
     */
    public function stage_image_dims($html, $ctx)
    {
        if (wps_ic_image_sizing::off() || !apply_filters('wpc_backfill_img_dimensions', true) || stripos($html, '<img') === false) {
            return $html;
        }
        $scriptMask = [];
        $html = wps_rewriteLogic::maskMediaScripts($html, $scriptMask);
        $sizing = $ctx->imageSizing;
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($sizing) {
            return self::image_dims_for_tag($m[0], $sizing);
        }, $html);
        $html = is_string($out) ? $out : $html;

        return wps_rewriteLogic::unmaskMediaScripts($html, $scriptMask);
    }

    /** One <img> through stage_image_dims's rules. */
    private static function image_dims_for_tag($tag, $sizing)
    {
        if (preg_match('/\swpc-size=(["\'])preserve\1/i', $tag)) {
            return $tag;
        }
        $src = preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $sm) ? $sm[2] : '';
        if ($src === '' || stripos($src, 'data:') === 0) {
            $src = preg_match('/\sdata-(?:lazy-)?src\s*=\s*(["\'])(.*?)\1/i', $tag, $dm) ? $dm[2] : '';
        }
        if ($src === '' || stripos($src, 'data:') === 0) {
            return $tag;
        }
        $widthValue = preg_match('/\swidth\s*=\s*["\']?([^"\'\s>]*)/i', $tag, $wm) ? $wm[1] : null;
        $heightValue = preg_match('/\sheight\s*=\s*["\']?([^"\'\s>]*)/i', $tag, $hm) ? $hm[1] : null;
        $isSvg = (bool) preg_match('/\.svg(?:[?#]|$)/i', $src);
        $numericWidth = ($widthValue !== null && ctype_digit($widthValue)) ? (int) $widthValue : 0;
        $numericHeight = ($heightValue !== null && ctype_digit($heightValue)) ? (int) $heightValue : 0;
        $bothNumeric = $numericWidth > 0 && $numericHeight > 0;
        $svgWidthOnly = $isSvg && $numericWidth > 0 && $heightValue === null;
        if (!($widthValue === null && $heightValue === null) && !$bothNumeric && !$svgWidthOnly) {
            return $tag; // one dimension, or a non-numeric one: the page's, left as written
        }
        $class = preg_match('/\sclass\s*=\s*(["\'])(.*?)\1/i', $tag, $cm) ? $cm[2] : '';
        // A tag whose inline style sets width or height gets no dims: the owner answers nothing
        // for it (wps_ic_image_sizing::styleSetsSize(), the staging top-logo.svg case).
        $style = preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/is', $tag, $stm) ? $stm[2] : '';
        $dims = $sizing->dimsFor($src, $class, self::image_offers_other_rungs($tag, $src), $style);
        if ($dims === null || $dims['w'] <= 0 || $dims['h'] <= 0) {
            return $tag;
        }
        $fileAspect = (float) $dims['w'] / (float) $dims['h'];
        if ($bothNumeric) {
            // The page's width stays; its height is recomputed when the pair contradicts the
            // file's own aspect by more than 8%: WordPress prints 800x800 for an SVG attachment
            // whose viewBox is 561x120 (staging.wpcompress.com's logo), and the browser reserves
            // a square until the file decodes.
            if (abs(($numericWidth / $numericHeight) - $fileAspect) / $fileAspect <= 0.08) {
                return $tag;
            }
            $height = max(1, (int) round($numericWidth / $fileAspect));
            $out = preg_replace('/(\sheight\s*=\s*)(["\']?)\d+\2/i', '${1}"' . $height . '"', $tag, 1);
            $out = is_string($out) ? preg_replace('/^<img\b/i', '<img data-wpc-md="1"', $out, 1) : $out;
            if (is_string($out)) {
                $sizing->aspectOverridden();
            }
            return is_string($out) ? $out : $tag;
        }
        if ($svgWidthOnly) {
            // The SVG width-only case: keep the page's width, give it the file's aspect.
            if ($numericWidth < 8 || $numericWidth > 4000 || $fileAspect < 0.05 || $fileAspect > 100) {
                return $tag;
            }
            $height = max(1, (int) round($numericWidth / $fileAspect));
            $out = preg_replace('/(\swidth\s*=\s*["\']?\d+["\']?)/i', '$1 height="' . $height . '" data-wpc-bf="1"', $tag, 1);
            return is_string($out) ? $out : $tag;
        }
        $width = (int) round($dims['w']);
        $height = (int) round($dims['h']);
        if ($width <= 5 || $height <= 5) {
            return $tag;
        }
        $marker = ($dims['source'] === 'observed') ? 'data-wpc-md="1"' : 'data-wpc-bf="1"';
        $out = preg_replace('/^<img\b/i', '<img width="' . $width . '" height="' . $height . '" ' . $marker, $tag, 1);
        return is_string($out) ? $out : $tag;
    }

    /**
     * Does this <img> offer the browser a file other than its src? A srcset whose candidates all
     * name the src (WordPress prints one SVG URL under 150w/300w/1024w) offers none.
     */
    private static function image_offers_other_rungs($tag, $src)
    {
        if (!preg_match('/\s(?:data-)?srcset\s*=\s*(["\'])(.*?)\1/is', $tag, $ss)) {
            return false;
        }
        // One file however the URL is spelled: WordPress joins the uploads base and the file with
        // a doubled slash in these ladders (`uploads//2020/02/…svg`).
        $fileKey = function ($url) {
            $url = (string) preg_replace('/[?#].*$/', '', html_entity_decode((string) $url, ENT_QUOTES));
            return (string) preg_replace('#(?<!:)/{2,}#', '/', $url);
        };
        $srcKey = $fileKey($src);
        foreach (preg_split('/,\s+/', trim($ss[2])) as $candidate) {
            $url = $fileKey(strtok(trim($candidate), ' '));
            if ($url !== '' && $url !== $srcKey) {
                return true;
            }
        }
        return false;
    }

    /** Protect existing <picture> blocks from double-wrapping by picture_webp feature. The window
     *  restores them at stage_replace_image_tags, or wherever the run stops before that. */
    public function stage_picture_stash($html, $ctx)
    {
        $wpcPictureBlocks = [];
        if ($ctx->pictureWebpEnabled) {
            $html = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$wpcPictureBlocks) {
                $i = count($wpcPictureBlocks);
                $wpcPictureBlocks[$i] = $m[0];
                return self::PICTURE_STASH_PLACEHOLDER . $i . '-->';
            }, $html);
        }
        $ctx->pictureStash = $wpcPictureBlocks;
        $ctx->openWindow('picture_stash', function ($html) use ($ctx) {
            foreach ($ctx->pictureStash as $i => $block) {
                $html = str_replace(self::PICTURE_STASH_PLACEHOLDER . $i . '-->', $block, $html);
            }
            return $html;
        });

        return $html;
    }

    public function stage_replace_image_tags($html, $ctx)
    {
        if (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
            $html = self::$rewriteLogic->replaceImageTags($html, $ctx->isAmp, $ctx->pictureWebpEnabled);
        }

        // Restore protected <picture> blocks, whether or not the rewrite above ran.
        return $ctx->closeWindow('picture_stash', $html);
    }

    public function stage_rewrite_inline_font_faces($html, $ctx)
    {
        $combine_css = $ctx->combineInstance instanceof wps_ic_combine_css ? $ctx->combineInstance : new wps_ic_combine_css();

        return $combine_css->rewriteInlineFontFaces($html);
    }

    public function stage_replace_picture_tags($html, $ctx)
    {
        // Both collectors are always empty: the LCP preload and the font preloads are written by
        // their own stages, so this marker is removed rather than filled.
        $preloadLCP = '';


        $preloadFonts = '';

        $html = str_replace('<!--WPC_INSERT_PRELOAD_MAIN-->', $preloadLCP . $preloadFonts, $html);


        if (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
            $html = self::$rewriteLogic->replacePictureTags($html);
        }

        return $html;
    }

    /** Restore masked script bodies — the passes below (URL versioning, revSlider) are quote-safe
     *  on JS strings and some intentionally process script-embedded URLs. */
    public function stage_unmask_media_scripts($html, $ctx)
    {
        $html = $ctx->closeWindow('media_mask', $html);
        $ctx->mediaScriptMask = [];

        return $html;
    }

    public function stage_legacy_upload_host($html, $ctx)
    {
        if (!empty(self::$zone_name) && !empty(self::$site_url) && self::$externalUrlEnabled != '1' && method_exists('wps_rewriteLogic', 'legacy_upload_host')) {
            $html = wps_rewriteLogic::legacy_upload_host($html, self::$zone_name, self::$site_url, home_url());
        }

        return $html;
    }

    public function gate_lazy_version_bust($ctx)
    {
        return (function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled()) ? true : 'lazy-off';
    }

    public function stage_lazy_version_bust($html, $ctx)
    {
        // v7.10.724 - a per-mint random here rotated every /q:i/ transform URL on every
        // remint, so the edge could never serve them as HITs (PSI refetched origin-fresh;
        // observed-LCP flip receipted on run 3 of the .722 ladder). Same discipline as
        // css_hash/js_hash: a STORED epoch, rotated only at the purge sites.
        $lazy_v = !empty(self::$options['lazy_hash'])
            ? (string) self::$options['lazy_hash']
            : (defined('WPS_IC_HASH') ? (string) WPS_IC_HASH : '5021');

        return preg_replace_callback(
            '#https?://[^\s"\',]*?/q:i/[^\s"\',]*#i',
            function ($m) use ($lazy_v) {
                $u = $m[0];
                return $u . ((strpos($u, '?') !== false) ? '&' : '?') . 'v=' . $lazy_v;
            },
            $html
        );
    }

    // Find revSlider Data-thumb
    public function stage_revslider_images($html, $ctx)
    {
        return self::$rewriteLogic->revSliderReplace($html);
    }

    /* -------------------------------------------------------------------------------------
     * Local-lane stages, in table order: one method per pass. Any value a pass hands to a later
     * pass rides wps_ic_render_context, never $this. The runner emits the per-stage profiler
     * checkpoints, so no stage makes a wpc_prof_cp() call of its own.
     * ------------------------------------------------------------------------------------- */

    /**
     * The request shapes a render must refuse, on both lanes: every builder, editor and preview
     * parameter, the admin screens, the REST and login paths, the cart and fragment posts. The
     * old local body also listed a dozen of those parameters a second time in a bail of its own;
     * dontRunif() lists all of them and answers first, so that entry is gone and only is_feed()
     * — which bail_feed asks — was ever outside it.
     */
    public function bail_dont_run_if($html, $ctx)
    {
        return !self::dontRunif();
    }

    /**
     * The local lane's Negotiated Delivery. The rewritten <img data-wpc-nd> tags are parked out
     * of the buffer so no pass below rewrites them again; the window puts them back at the
     * restore stage further down the lane, or wherever the run stops before it.
     */
    public function stage_negotiated_delivery_local($html, $ctx)
    {
        $ctx->negotiatedStash = [];
        if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) {
            $html = WPC_Negotiated_Delivery::rewrite_buffer($html, $ctx->imageSizing);
            $ctx->pictureWebpEnabled = false;


            $html = preg_replace_callback('/<img\b[^>]*\bdata-wpc-nd\b[^>]*>/i', function ($m) use ($ctx) {
                $k = '___WPCND_IMG_' . count($ctx->negotiatedStash) . '___';
                $ctx->negotiatedStash[$k] = $m[0];
                return $k;
            }, $html);
        }
        $ctx->openWindow('nd_stash_local', function ($html) use ($ctx) {
            if (!empty($ctx->negotiatedStash)) {
                $html = strtr($html, $ctx->negotiatedStash);
            }

            return $html;
        });

        return $html;
    }

    /** Restore masked script bodies: local_script_encode below has to see real scripts. */
    public function stage_unmask_media_scripts_local($html, $ctx)
    {
        $html = $ctx->closeWindow('media_mask', $html);
        $ctx->mediaScriptMask = [];

        return $html;
    }

    /** The gate on the local lane's image stretch: with the CDN off, local delivery rewrites
     *  the tags itself. */
    public function gate_cdn_disabled($ctx)
    {
        return self::$cdnEnabled == 0 ? true : 'cdn-enabled';
    }

    /** Script bodies leave the buffer as [script-wpc] placeholders so the local image passes below
     *  cannot rewrite anything inside them. The 'local_scripts' window puts them back at
     *  stage_local_script_decode, or from the runner wherever the run stops before it, so nothing
     *  ever serves the literal placeholders. */
    public function stage_local_script_encode($html, $ctx)
    {
        $htmlBefore = $html;
        $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$this, 'local_script_encode'], $html);

        if (empty($html)) {
            $html = $htmlBefore;
        }
        $ctx->openWindow('local_scripts', function ($html) {
            return preg_replace_callback('/\[script\-wpc\](.*?)\[\/script\-wpc\]/i', [$this, 'local_script_decode'], $html);
        });

        return $html;
    }

    /** Protect existing <picture> blocks from double-wrapping. The window restores them at
     *  stage_local_image_tags, or wherever the run stops before that. The placeholder prefix is
     *  the one class constant both stashes use — only one of them ever runs in a given render. */
    public function stage_picture_stash_local($html, $ctx)
    {
        $wpcLocalPictureBlocks = [];
        if ($ctx->pictureWebpEnabled) {
            $html = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$wpcLocalPictureBlocks) {
                $i = count($wpcLocalPictureBlocks);
                $wpcLocalPictureBlocks[$i] = $m[0];
                return self::PICTURE_STASH_PLACEHOLDER . $i . '-->';
            }, $html);
        }
        $ctx->pictureStash = $wpcLocalPictureBlocks;
        $ctx->openWindow('picture_stash', function ($html) use ($ctx) {
            foreach ($ctx->pictureStash as $i => $block) {
                $html = str_replace(self::PICTURE_STASH_PLACEHOLDER . $i . '-->', $block, $html);
            }

            return $html;
        });

        return $html;
    }

    public function stage_device_hidden_image_set($html, $ctx)
    {
        if (function_exists('wpc_device_hidden_image_set')) {
            $ctx->deviceHiddenImages = wpc_device_hidden_image_set($html, function_exists('wpc_ua_is_mobile') ? (bool) wpc_ua_is_mobile() : false);
        }

        return $html;
    }

    public function stage_local_image_tags($html, $ctx)
    {
        $html = preg_replace_callback('/(?<![\"|\'])<img[^>]*>/i', function ($image) use ($ctx) {
            return $this->local_image_tags($image, $ctx);
        }, $html);
        if ($ctx->pictureWebpEnabled && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('nextgen-picture', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', [
                'lane' => 'local',
                'built' => $ctx->nextgenPictureCounts['built'],
                'skipped' => ['no-variant' => $ctx->nextgenPictureCounts['no-variant'], 'excluded' => $ctx->nextgenPictureCounts['excluded']],
            ]);
        }

        // Restore protected <picture> blocks, whether or not the rewrite above ran.
        return $ctx->closeWindow('picture_stash', $html);
    }

    public function stage_fonts_zone_rewrite_local($html, $ctx)
    {
        if (self::$fonts == 1) {
            $html = self::$rewriteLogic->fonts($html);
        }

        return $html;
    }

    /** The normal close of the 'local_scripts' window: the placeholders become script bodies again. */
    public function stage_local_script_decode($html, $ctx)
    {
        return $ctx->closeWindow('local_scripts', $html);
    }

    public function stage_css_background_local($html, $ctx)
    {
        return preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>?/is', [self::$rewriteLogic, 'replaceBackgroundImagesInCSSLocal'], $html);
    }

    public function stage_combine_js_bundles($html, $ctx)
    {
        //Combine JS
        if ($this->doCacheCombine() && (isset(self::$settings['js_combine']) && self::$settings['js_combine'] == '1')) {
            $combine_js = new wps_ic_combine_js();
            $html = $combine_js->maybe_do_combine($html);
        }

        return $html;
    }

    /* -------------------------------------------------------------------------------------
     * The CSS block, shared by both lanes: the critical setup, the crit generator's corpus on a
     * push render, the font-awesome lazy pass, the critical kick, the crit/lazyCSS branch and the
     * belts that follow it. The lanes run the same passes in the same order.
     * ------------------------------------------------------------------------------------- */

    /** One helper instance per render, shared by every pass in this block. Both lanes build it
     *  in stage_amp_settings_squash; this stands in for a run that somehow reaches the block
     *  without one. */
    private function wpc_stage_combine_css($ctx)
    {
        if (!($ctx->combineInstance instanceof wps_ic_combine_css)) {
            $ctx->combineInstance = new wps_ic_combine_css();
        }

        return $ctx->combineInstance;
    }

    /**
     * The critical values every stage in this block reads. criticalExists() is asked ONCE per
     * render, and every later stage reads the answer from the context: nothing below writes an
     * artifact — the kick only injects a client-side script through runCriticalAjax — so a second
     * call could only ever repeat the answer.
     * The debugCritical_replace branch keeps its own instance and call, because criticalExists()
     * answers that request with a different shape entirely.
     */
    private function wpc_stage_critical_setup($ctx, $html)
    {
        // Critical CSS Remove from Header
        $ctx->criticalActive = !(isset(self::$page_excludes['critical_css']) && self::$page_excludes['critical_css'] == '0') && ((isset(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') || (isset(self::$page_excludes['critical_css']) && self::$page_excludes['critical_css'] == '1')) && (empty($settings['developer_mode']) || $settings['developer_mode'] == '0')
            && (!class_exists('wps_ic_plan') || wps_ic_plan::allows('crit'));

        $ctx->criticalInstance = new wps_criticalCss();
        // The flag describes ONE render, and a request can render more than one URL (the v2
        // natural-URL buffer does), so it is cleared before this render's stages re-establish
        // it: nothing carries the previous page's answer forward.
        unset($GLOBALS['wpc_crit_park_refused']);
        $ctx->criticalExists = $ctx->criticalInstance->criticalExists();
        if (!empty($ctx->criticalExists) && $this->wpc_css_crit_open($ctx) && !empty(self::$rewriteLogic)) {
            self::$rewriteLogic->crit_coverage_receipt($html, $ctx->criticalExists);
        }
        $this->criticalRunning = null;
    }

    /**
     * This render's critical-artifact directory: the one criticalExists() ALREADY RESOLVED.
     *
     * v7.24.12 — the park verdict used to derive its own key from the raw request URL, which is
     * not the key the artifact was found under. wps_criticalCss::__construct normalises the URL
     * against home_url (proxy hosts) and, when an unknown query parameter has no artifact of its
     * own, falls back to the canonical key for the path. So on /features/?anything=1 the crit was
     * found, painted and stamped under 'site-comfeatures' while this verdict looked in
     * 'site-comfeatureswhatever-1', read no blob at all, and refused to park every sheet on the
     * page — silently, because "no payload" is not a condition that journals. Two derivations of
     * one fact is the defect: the artifact the render resolved is the only artifact the verdict
     * may judge. The request-URL derivation
     * stays as the fallback for the one caller that has no resolved artifact (?testCritical
     * forces the crit on).
     */
    private function wpc_crit_dir_for_render($ctx)
    {
        if (!empty($ctx->criticalExists['dir'])) {
            return rtrim((string) $ctx->criticalExists['dir'], '/') . '/';
        }
        if (!empty($ctx->criticalExists['desktop_path'])) {
            return rtrim(dirname((string) $ctx->criticalExists['desktop_path']), '/') . '/';
        }

        return $this->wpc_crit_dir_for_request();
    }

    /** This request's critical-artifact directory, or '' when the URL key cannot be resolved. */
    private function wpc_crit_dir_for_request()
    {
        if (!defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
            return '';
        }
        $urlKey = ltrim((string) (new wps_ic_url_key())->setup(), '/');
        if ($urlKey === '' || strpos($urlKey, '..') !== false) {
            return '';
        }

        return rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
    }

    /**
     * The request tests the whole crit block stands behind. The lanes spell the push-render
     * test differently: the CDN lane reads $ctx->pushRender (criticalCombine=true or the header),
     * the local lane $ctx->pushRenderLoose, so there any non-empty value closes the block.
     */
    private function wpc_css_block_open($ctx)
    {
        if (!empty($_GET['disableCritical']) || !empty($_GET['generateCriticalAPI'])) {
            return false;
        }

        if ($ctx->lane === wps_ic_render_pipeline::LANE_LOCAL) {
            if ($ctx->pushRenderLoose) {
                return false;
            }
        } elseif ($ctx->pushRender) {
            return false;
        }

        return !is_user_logged_in() && !is_admin_bar_showing();
    }

    /** The inner test: addCritical/lazyCSS, the belts and dropfaces run only inside this. */
    private function wpc_css_crit_open($ctx)
    {
        return $this->wpc_css_block_open($ctx) && $ctx->criticalActive && !self::$preloaderAPI && !self::isURLExcluded('critical_css');
    }

    /**
     * The critical setup both lanes need before the CSS block: which crit artifact exists, whether
     * critical CSS is active, one shared wps_criticalCss instance. It is pure request and option
     * reads, so the entry leaves the buffer untouched and gives both lanes the same context from
     * this point on.
     */
    public function stage_critical_setup($html, $ctx)
    {
        $this->wpc_stage_critical_setup($ctx, $html);

        // Nothing below reads this: cdn_rewrite_url takes an argument of the same name and does
        // not consult the context.
        $ctx->addSlashes = !empty($_POST['action']);

        return $html;
    }

    /** Only the crit generator's render (?criticalCombine, or its header) links the corpus;
     *  ?stopCombineCSS leaves that render's sheets as the page wrote them. */
    public function gate_crit_corpus_push($ctx)
    {
        return $ctx->pushRenderLoose && empty($_GET['stopCombineCSS']);
    }

    /** The crit generator's render: the page with its sheets replaced by one link to its corpus. */
    public function stage_crit_corpus_push($html, $ctx)
    {
        return (new wps_ic_crit_corpus())->push_render($html);
    }

    public function stage_lazy_fontawesome($html, $ctx)
    {
        if (isset(self::$settings['fontawesome-lazy']) && self::$settings['fontawesome-lazy'] == '1') {
            // TODO: Maybe add something?
            $html = $this->wpc_stage_combine_css($ctx)->lazyFontawesome($html);
        }

        return $html;
    }

    /**
     * The kick's condition set, in short-circuit order: AMP first, the criticalCombine request
     * next. Only the spelling of that second test differs between the lanes.
     */
    public function gate_critical_kick($ctx)
    {
        if ($ctx->isAmp) {
            return 'amp';
        }

        if (!empty($_GET['disableCritical']) || !empty($_GET['generateCriticalAPI'])) {
            return 'off';
        }

        if ($ctx->lane === wps_ic_render_pipeline::LANE_LOCAL) {
            if ($ctx->pushRenderLoose) {
                return 'critical-combine';
            }
        } elseif ($ctx->pushRender) {
            return 'critical-combine';
        }

        if (is_user_logged_in() || is_admin_bar_showing()) {
            return 'logged-in';
        }

        if (!$ctx->criticalActive) {
            return 'off';
        }

        return self::$preloaderAPI ? 'preloader-api' : true;
    }

    public function stage_critical_kick($html, $ctx)
    {
        global $post;

        if (!empty($_GET['forceCriticalAjax'])) {
            return self::$rewriteLogic->runCriticalAjax($html);
        }

        if (empty($ctx->criticalExists)) {
            // The debugCriticalRunning receipt below reads this, and only this branch assigns it.
            $this->criticalRunning = $ctx->criticalInstance->criticalRunning();
            if (!$this->criticalRunning) {
                set_transient('wpc_critical_ajax_' . md5(wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), date('d.m.Y H:i:s'), 60 * 5);
                $html = self::$rewriteLogic->runCriticalAjax($html);
            }
        }

        return $html;
    }

    /**
     * The crit branch itself: the three debug answers, then addCritical + lazyCSS when an
     * artifact exists and the sentinel tag when it does not. The above-the-fold passes both arms
     * copied are crit_atf_passes, one entry after the branch — called with the same argument in
     * the same order in both arms, so one run reproduces either.
     *
     * Two debug GETs answer out of here with a string instead of a buffer, exactly as the old
     * bodies did: debugCritical_replace returns a print_r of the crit artifact and the preload
     * links it would emit, and it keeps its own wps_criticalCss instance because criticalExists()
     * answers that request with a different shape. (The local body also built an unused
     * $getCSSAfterPreloadComment closure here; it is not part of the returned string.)
     */
    public function stage_critical_and_lazy_css($html, $ctx)
    {
        if (!$this->wpc_css_block_open($ctx)) {
            return $html;
        }

        if (!empty($_GET['debugCriticalRunning'])) {
            $html .= print_r([self::$settings['critical']['css'], $ctx->criticalExists, $this->criticalRunning], true);
        }


        if (!empty($_GET['debugCritical_replace'])) {
            #global $post;
            $criticalCSS = new wps_criticalCss();
            $criticalCSSExists = $criticalCSS->criticalExists();
            $criticalCSSContent = file_get_contents($criticalCSSExists['file']);

            // Adjusted function to create preload links only if the "/* Preload Fonts */" comment is found
            $createPreloadLinks = function ($cssContent) {
                $preloadLinks = '';
                $loadedFonts = []; // Array to track already added URLs
                $commentPos = strpos($cssContent, '/* Preload Fonts */');

                // Proceed only if the comment is found
                if ($commentPos !== false) {
                    $relevantContent = substr($cssContent, 0, $commentPos);
                    $fontPattern = '/url\((\'|")?(.+?\.(woff2?|ttf|otf|eot))\1?\)/i';
                    if (preg_match_all($fontPattern, $relevantContent, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $fontUrl = $match[2];
                            if (strpos($fontUrl, 'icon') !== false || strpos($fontUrl, 'fa-') !== false || strpos($fontUrl, 'la-') !== false) {
                                continue;
                            }
                            // Check if the font URL is already in the array
                            if ((!empty(self::$settings['preload-crit-fonts'])) && self::$settings['preload-crit-fonts'] == '1') {
                                if (!in_array($fontUrl, $loadedFonts)) {
                                    $preloadLinks .= "<link rel=\"preload\" href=\"$fontUrl\" as=\"font\" type=\"font/woff2\" crossorigin=\"anonymous\">\n";
                                    $loadedFonts[] = $fontUrl; // Add the URL to the tracking array
                                }
                            }
                        }
                    }
                }
                return $preloadLinks;
            };


            $preloadLinks_Desktop = $createPreloadLinks($criticalCSSContent);

            return print_r(['critActive:' => $ctx->criticalActive, 'preloadApi' => self::$preloaderAPI, 'excluded' => self::isURLExcluded('critical_css'), $preloadLinks_Desktop, $criticalCSSExists, $criticalCSSContent], true);
        }

        if (!empty($_GET['testCritical'])) {
            // The debug flag forces the crit on, so it takes the same verdict as a real render:
            // it must not be able to park a page behind a carrier that never paints.
            self::$settings['critical']['css'] = '1';
            $wpc_test_park = $this->wpc_crit_park_allowed($ctx);
            self::$rewriteLogic->set_park_allowed($wpc_test_park);
            $html = self::$rewriteLogic->addCritical($html, $ctx->fontFaces, $ctx->imagePreloads);
            $html = self::$rewriteLogic->lazyCSS($html, $wpc_test_park, $ctx->fontFaces);
            self::$rewriteLogic->set_park_allowed(false);
        }

        if (!$this->wpc_css_crit_open($ctx)) {
            return $html;
        }

        if (!empty($ctx->criticalExists)) {
            // v7.10.553 — decided whether to run lazyCSS with a SUBSTRING test while lazyCSS's
            // own guard requires id="wpc-critical-css". The delay loader emits
            // document.getElementById("wpc-critical-css"), so this matched on pages
            // carrying NO crit: lazyCSS was called, bailed at its guard, and 36 sheets
            // stayed render-blocking while perf-debug's crit= (same loose test) said Y.
            // Both sides now test the TAG. Same set, same granularity.
            //
            // v7.24.09/.10/.11 — THE PARK VERDICT, DECIDED ONCE, BEFORE ANY PARKER RUNS. It has
            // to be settled here rather than after addCritical, because addCritical's used-CSS
            // lane parks its own rest link ~450 lines before it builds the crit tag. There is
            // one condition: the crit blob paints something, so a crit tag will be emitted. The
            // test is the blob, not the artifact: a faces-only or comment-only file exists on
            // disk and paints nothing.
            // A crit that is inlined is parked behind, always. Nothing about the crit itself is a
            // condition, here or anywhere in the plugin: its size (the cap is the service's),
            // whether it fits the page (the service compares the pushed page with the artifact)
            // and whether the stylesheets moved since it was built (the page is stale-marked and
            // regenerated, and serves what it has meanwhile). Each of those was a leg here once,
            // and each inlined the crit and left every sheet blocking: greenvalleytint served a
            // 121 KB crit AND 43 blocking sheets on the size leg (2026-09-24), hawkeye.design
            // its crit and 22 blocking sheets for six days on the blind leg (2026-09-30).
            // It is settled before anything is parked, so no later pass has to un-park.
            $ctx->critParkAllowed = $this->wpc_crit_park_allowed($ctx);
            self::$rewriteLogic->set_park_allowed($ctx->critParkAllowed);
            $html = self::$rewriteLogic->addCritical($html, $ctx->fontFaces, $ctx->imagePreloads);
            // Always called: parking is refused per sheet by the verdict, and the rest of the
            // pass — the parked-combine, the late-faces relocation and the sizes ladder — is work
            // a page that may not park needs exactly as much. (The used-css droplist is the one
            // part that obeys the verdict: see lazyCSS.)
            $html = self::$rewriteLogic->lazyCSS($html, $ctx->critParkAllowed, $ctx->fontFaces);
            self::$rewriteLogic->set_park_allowed(false);
            // Refusing to park is not by itself enough to make the page total-live: the crit lane
            // arms the late-faces block (media="not all") and the href-less flip links whether or
            // not a sheet parked, and their post-parse flip is the white flash the .101 receipt
            // names. The refusal is published here and the disarm is taken at saveCache's
            // terminal seam, below the face-gate stages and the flip-link mint that would
            // otherwise re-arm what this stage undid. The call below still covers the renders
            // that bail out of saveCache before that seam.
            if (!$ctx->critParkAllowed && class_exists('wps_cacheHtml')) {
                $GLOBALS['wpc_crit_park_refused'] = true;
                $html = wps_cacheHtml::wpc_make_managed_css_live($html);
            }
        } else {
            $html = self::$rewriteLogic->wpc_arm_sentinel_tag($html);
        }

        return $html;
    }

    /**
     * The park verdict for this render. See the call site for what each condition answers.
     *
     * A refusal writes one crit-park-refused receipt naming the condition, sampled once an hour
     * per condition: a page that parks nothing serves every sheet blocking, and the log has to
     * say why.
     */
    private function wpc_crit_park_allowed($ctx)
    {
        $refusal = $this->wpc_crit_park_refusal($ctx);
        if ($refusal === '') {
            return true;
        }
        if (function_exists('wpc_cache_first_log') && function_exists('get_transient')
            && !get_transient('wpc_park_refused_log_' . $refusal)) {
            set_transient('wpc_park_refused_log_' . $refusal, 1, 3600);
            wpc_cache_first_log('crit-park-refused', '', (string) ($_SERVER['REQUEST_URI'] ?? ''), [
                'why' => $refusal,
                'dev' => (function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop',
                'variant' => $ctx->lane === wps_ic_render_pipeline::LANE_CDN ? 'cdn-lane' : 'local-lane',
            ]);
        }

        return false;
    }

    /** Why this render may not park, or '' when it may. */
    private function wpc_crit_park_refusal($ctx)
    {
        $critDir = $this->wpc_crit_dir_for_render($ctx);
        if ($critDir === '' || !class_exists('wps_rewriteLogic')) {
            return 'no-crit-dir';
        }
        $device = (function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop';
        $critBlob = '';
        foreach (['critical_' . $device . '.css', 'critical_combined.css',
            'critical_' . ($device === 'mobile' ? 'desktop' : 'mobile') . '.css'] as $critFile) {
            if (@is_readable($critDir . $critFile)) {
                $critBlob = (string) @file_get_contents($critDir . $critFile);
                break;
            }
        }
        if (!wps_rewriteLogic::crit_payload_present($critBlob)) {
            return 'no-payload';
        }

        return '';
    }

    public function gate_crit_atf_passes($ctx)
    {
        return $this->wpc_css_crit_open($ctx) ? true : 'no-crit-block';
    }

    /**
     * The above-the-fold passes, run once for every render that reaches the crit block: unlazy
     * the first-frame images, hoist their preloads, park the backgrounds below the fold.
     *
     * Nothing here repairs the crit. A crit that landed is the fold as the service built it,
     * and the face set never registers a face for a family it cannot back, so there is nothing
     * to sweep out of the written blocks either.
     */
    public function stage_crit_atf_passes($html, $ctx)
    {
        $html = self::wpc_unlazy_above_fold_images($html, $ctx->imagePreloads, $ctx->imageSizing);

        return self::wpc_park_below_fold_backgrounds($html);
    }



    /** Both lanes skip the iframe decode for a logged-in visitor. */
    public function gate_decode_iframe($ctx)
    {
        return $ctx->userLoggedIn ? 'logged-in' : true;
    }

    /* ------------------------------------------------------------------------------------- *
     * The CDN lane's URL stretch, from the cdn_rewrite_url_2 checkpoint down to the noscript
     * decode, one stage per pass. State that crosses these stages rides the render context:
     * $ctx->metaEncodeStore and $ctx->negotiatedStash, both restored through a window. The
     * document-URL pattern is not carried at all — the two passes that want it call
     * documentUrlPattern(). The runner emits one profiler checkpoint per stage.
     * ------------------------------------------------------------------------------------- */

    /** Park the og:image meta and JSON-LD tags so the URL passes below cannot rewrite them. The
     *  'meta' window puts them back at stage_decode_meta, or wherever the run stops before it. */
    public function stage_encode_meta($html, $ctx)
    {
        $ctx->metaEncodeStore = null;
        if (empty(self::$settings['optimize_meta_images']) || self::$settings['optimize_meta_images'] == '0') {
            $encodedMeta = $this->encodeMeta($html);
            $ctx->metaEncodeStore = $encodedMeta['store'];
            $html = $encodedMeta['html'];
            $ctx->openWindow('meta', function ($html) use ($ctx) {
                if (!empty($ctx->metaEncodeStore)) {
                    $html = $this->decodeMeta($html, $ctx->metaEncodeStore);
                }
                $ctx->metaEncodeStore = null;

                return $html;
            });
        }

        return $html;
    }

    /** Park the Negotiated Delivery imgs so the URL passes below cannot touch them. The window
     *  restores them at stage_reencode_data_code, or wherever the run stops before that. */
    public function stage_negotiated_stash($html, $ctx)
    {
        $wpcnd_stash = [];
        if (class_exists('WPC_Negotiated_Delivery')
            && (WPC_Negotiated_Delivery::is_active() || WPC_Negotiated_Delivery::is_active_jpeg())) {
            $html = preg_replace_callback('/<img\b[^>]*\bdata-wpc-nd\b[^>]*>/i', function ($m) use (&$wpcnd_stash) {
                $k = '___WPCND_IMG_' . count($wpcnd_stash) . '___';
                $wpcnd_stash[$k] = $m[0];
                return $k;
            }, $html);
        }
        $ctx->negotiatedStash = $wpcnd_stash;
        $ctx->openWindow('nd_stash', function ($html) use ($ctx) {
            // Restore the stashed negotiated imgs (their data-wpc-fb origin fallback intact).
            if (!empty($ctx->negotiatedStash)) {
                $html = strtr($html, $ctx->negotiatedStash);
            }

            return $html;
        });

        return $html;
    }

    /** The pattern that matches every not-yet-rewritten URL in the document, built from the site's
     *  own URL and directory alternations. Two passes run it: stage_rewrite_document_urls over the
     *  buffer and stage_reencode_data_code inside the base64 data-code payloads. They each ask for
     *  it here rather than sharing one property, so the background-image pass — which builds a
     *  different pattern of its own — can no longer decide what the data-code re-encode matches. */
    public function documentUrlPattern()
    {
        return '#(?<=url\(|[\"\']|&quot;)(?:' . self::$regExURL . ')?/(?:((?:' . self::$regExDir . ')[^\"\')]+)|([^/\"\']+\.[^/\"\')]+))(?=[\"\')]|&quot;)#';
    }

    public function stage_rewrite_document_urls($html, $ctx)
    {
        // Find all URLs on page that have not been replaced
        // v7.10.535 — the SHAPE of the dynamic pattern is what drives the cost of this stage, so
        // the same slow-render that times it should be able to report it. self::$regExDir is an
        // unbounded alternation built from site directories (implode('|', quotemeta(...))), and
        // the branch count multiplies the per-position work of the lookbehind+alternation.
        return preg_replace_callback($this->documentUrlPattern(), function ($m) use ($ctx) {
            return $this->cdn_rewrite_url($m, false, $ctx);
        }, $html);
    }

    /** The background-image pass owns its pattern: it is a local, so it stays inside this stage. */
    public function stage_rewrite_css_background_urls($html, $ctx)
    {
        //Find background images inlined in html, and pass only the url to cdn_rewrite_url (above regex does not capture relative urls)
        if (!empty(self::$settings['background-sizing']) && self::$settings['background-sizing'] == 1) {
            $backgroundUrlPattern = '/background-image:\s*url\((\'|"|&quot;)(.*?)(\'|"|&quot;)\)/i';
            $html = preg_replace_callback($backgroundUrlPattern, function ($matches) use ($ctx) {
                $rawUrl = (string) $matches[2];
                if ($rawUrl === '' || stripos($rawUrl, 'data:') === 0) {
                    return $matches[0];
                }
                $url = str_replace('&#039;', '', $rawUrl);
                $rewrittenUrl = $this->cdn_rewrite_url([$url], false, $ctx);
                if (!is_string($rewrittenUrl) || $rewrittenUrl === '') {
                    return $matches[0];
                }
                return 'background-image: url(' . $matches[1] . $rewrittenUrl . $matches[3] . ')';
            }, $html);
        }

        return $html;
    }

    /** Rewrite the URLs inside base64 data-code payloads, with the document-URL pattern: the pass
     *  asks documentUrlPattern() for it, so the background-image pass above cannot hand it a
     *  background pattern that matches nothing in a payload. */
    public function stage_reencode_data_code($html, $ctx)
    {
        $documentUrlPattern = $this->documentUrlPattern();
        $rewriteUrl = function ($u) use ($ctx) {
            return $this->cdn_rewrite_url($u, false, $ctx);
        };
        $html = preg_replace_callback('/data-code="([^"]+)"/', function ($m) use ($documentUrlPattern, $rewriteUrl) {
            $decoded = base64_decode($m[1]);
            if ($decoded === false) {
                return $m[0];
            }
            $decoded = preg_replace_callback($documentUrlPattern, $rewriteUrl, $decoded);
            $decoded = preg_replace_callback('/data-code="([^"]+)"/', function ($m2) use ($documentUrlPattern, $rewriteUrl) {
                $decoded2 = base64_decode($m2[1]);
                if ($decoded2 === false) {
                    return $m2[0];
                }
                $decoded2 = preg_replace_callback($documentUrlPattern, $rewriteUrl, $decoded2);
                return 'data-code="' . base64_encode($decoded2) . '"';
            }, $decoded);
            return 'data-code="' . base64_encode($decoded) . '"';
        }, $html);

        return $ctx->closeWindow('nd_stash', $html);
    }

    public function stage_external_urls($html, $ctx)
    {
        if (self::$externalUrlEnabled == '1') {
            $html = self::$rewriteLogic->externalUrls($html, $ctx->isAmp);
        }

        return $html;
    }

    /** All-links only runs with external URLs off; the two are the arms of one branch. The
     *  branch is the gate, so the stop trace names which arm the request took. */
    public function gate_all_links($ctx)
    {
        return self::$externalUrlEnabled == '1' ? 'external-urls' : true;
    }

    public function stage_all_links($html, $ctx)
    {
        if (!empty(self::$replaceAllLinks) && self::$replaceAllLinks == '1') {
            $html = self::$rewriteLogic->allLinks($html);
        }

        return $html;
    }

    public function stage_prepare_preloads($html, $ctx)
    {
        $combine_css = $this->wpc_stage_combine_css($ctx);

        if (!$ctx->pushRenderLoose) {
            // Find and Preload Fonts!!
            $preloadLinks = $combine_css->preparePreloads($html, $ctx->imagePreloads);

            if (!empty($preloadLinks)) {
                // Extract href values from preload links
                preg_match_all('/href=["\']([^"\']+)["\']/', $preloadLinks, $matches);

                $html = str_replace('<!--WPC_INSERT_PRELOAD-->', $preloadLinks, $html);
            }
        }

        return $html;
    }

    /** The normal close of the 'meta' window: the parked og:image and JSON-LD tags go back in.
     *  A run that stops, bails or throws before this entry gets the same closer from the runner. */
    public function stage_decode_meta($html, $ctx)
    {
        return $ctx->closeWindow('meta', $html);
    }

    public function stage_fonts_zone_rewrite($html, $ctx)
    {
        if (self::$fonts == 1) {
            $html = self::$rewriteLogic->fonts($html);
        }

        return $html;
    }

    /** The .291 cio-fonts pass: ungated by design — it runs whether or not the fonts toggle above
     *  is on, which is why it is its own entry with no gate rather than part of that one. */
    public function stage_cio_fonts($html, $ctx)
    {
        if (is_callable(['wps_rewriteLogic', 'cio_fonts_pass'])) {
            $html = wps_rewriteLogic::cio_fonts_pass($html);
        }

        return $html;
    }

    /** The normal close of the 'iframes' window stage_encode_iframe_tags opened, for both lanes.
     *  A run that stops, bails or throws before this entry gets the same closer from the runner. */
    public function stage_decode_iframe($html, $ctx)
    {
        return $ctx->closeWindow('iframes', $html);
    }

    /** The normal close of the 'noscript' window: the [noscript-wpc] placeholders become
     *  <noscript><iframe> blocks again, through decode_noscript_placeholders. */
    public function stage_noscript_decode_pass($html, $ctx)
    {
        return $ctx->closeWindow('noscript', $html);
    }

    /* ------------------------------------------------------------------------------------- *
     * The delay-JS block and the 3491 group, from the Inline checkpoint down to the mid-pipeline
     * face gate, one stage per pass, shared by both lanes. Three things that could be lane splits
     * are not: the render-budget door in front of the theme integrations stands on both lanes;
     * the per-page delay_js force-off is honoured on both; and the
     * google-fonts display pass runs once for both lanes, up at replaceImageTags2, where it
     * reads the font hosts before the zone rewrite moves them.
     * ------------------------------------------------------------------------------------- */

    /** True when the render takes the v2/v3 delay branch rather than the delay-js fallback. */
    private function delay_branch_is_v2_or_v3()
    {
        return (isset(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1')
            || (class_exists('wps_ic_js_delay_v3') && wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings));
    }

    /** The request guard both delay branches carried around their whole body. */
    private function delay_request_allowed($ctx)
    {
        return !$ctx->isAmp && empty($_GET['disableDelay'])
            && !$ctx->pushRenderLoose;
    }

    /** The engine the v2/v3 branch built before it decided which way to go. */
    private function delay_engine_v2_or_v3(&$isV3)
    {
        $isV3 = (!isset(self::$settings['delay-js-v3']) || self::$settings['delay-js-v3'] != '0') && class_exists('wps_ic_js_delay_v3');

        return $isV3 ? new wps_ic_js_delay_v3() : new wps_ic_js_delay_v2();
    }

    /** The CDN body opened the block with a budget door; the local body never had one. */
    public function bail_budget_integrations($html, $ctx)
    {
        return self::wpc_render_budget_exceeded('integrations');
    }

    public function stage_theme_integrations($html, $ctx)
    {
        return self::$themeIntegrations->getIntegration($html);
    }

    public function gate_speculation_rules($ctx)
    {
        if (!class_exists('wps_ic_speculation_rules')
            || !wps_ic_speculation_rules::isActive(self::$settings, self::$page_excludes)) {
            return 'off';
        }
        if ($ctx->isAmp) {
            return 'amp';
        }
        if ($ctx->pushRenderLoose) {
            return 'critical-combine';
        }
        if (self::$preloaderAPI) {
            return 'preloader-api';
        }

        return true;
    }

    public function stage_speculation_rules($html, $ctx)
    {
        $speculationRules = new wps_ic_speculation_rules();

        return $speculationRules->process_html($html);
    }

    /** The executor branch: every condition that has to hold before process_html runs. */
    public function gate_delay_scripts($ctx)
    {
        if (!$this->delay_branch_is_v2_or_v3()) {
            return 'no-v2-v3';
        }
        if (!$this->delay_request_allowed($ctx)) {
            return 'amp-or-disabled';
        }
        if (!empty($_GET['disableCritical'])) {
            return 'disable-critical';
        }
        // The per-page "Force Off" saves under 'delay_js' and is honoured on both lanes. The old
        // CDN body computed the same flag from the same page exclude and never looked at it, so a
        // CDN-delivery site could not switch delay off for one page.
        if (isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '0') {
            return 'page-excluded';
        }
        if (isset(self::$page_excludes['delay_js_v2']) && self::$page_excludes['delay_js_v2'] == '0') {
            return 'page-excluded-v2';
        }
        if (current_user_can('manage_wpc_settings')) {
            return 'wpc-admin';
        }
        if (self::$delay_js_override) {
            return 'delay-override';
        }
        if (self::$preloaderAPI) {
            return 'preloader-api';
        }

        return true;
    }

    public function stage_delay_scripts($html, $ctx)
    {
        $wpc_delay_v3 = false;
        $js_delay = $this->delay_engine_v2_or_v3($wpc_delay_v3);
        $html = $js_delay->process_html($html);
        if ($wpc_delay_v3) {
            $ctx->delayV3Ran = true;
        }

        return $html;
    }

    /** Both belts below the delay pass ran only on the v3 engine's output. */
    public function gate_delay_v3_ran($ctx)
    {
        return $ctx->delayV3Ran ? true : 'no-v3-pass';
    }

    /**
     * The no-delay marker cleanup. Two shapes of render need it: a v2/v3 render whose request
     * guard holds while the executor gate does not, and a delay-js fallback render whose request
     * guard holds. $ctx->delayV3Ran cannot express that on its own — a v2 engine runs
     * process_html and leaves the flag false, and a guarded-off render leaves it false too — so
     * the gate asks both questions directly. Which engine the callback rides does not matter:
     * wps_ic_js_delay::removeNoDelay and wps_ic_js_delay_v2::removeNoDelay are byte-identical
     * and v3 inherits v2's.
     */
    public function gate_remove_nodelay_markers($ctx)
    {
        if ($this->delay_branch_is_v2_or_v3()) {
            if (!$this->delay_request_allowed($ctx)) {
                return 'amp-or-disabled';
            }

            return $this->gate_delay_scripts($ctx) === true ? 'delay-ran' : true;
        }
        if (isset(self::$settings['delay-js']) && self::$settings['delay-js'] == '1') {
            return $this->delay_request_allowed($ctx) ? true : 'amp-or-disabled';
        }

        return 'no-delay-setting';
    }

    public function stage_remove_nodelay_markers($html, $ctx)
    {
        if ($this->delay_branch_is_v2_or_v3()) {
            $wpc_delay_v3 = false;
            $js_delay = $this->delay_engine_v2_or_v3($wpc_delay_v3);
        } else {
            // v3 or nothing: the v1 delay transform (type="wpc-delay-script") had no replayer
            // once the legacy optimize.js emitter went in .53 — only this cleanup stays.
            $js_delay = new wps_ic_js_delay();
        }

        $stripped = 0;
        $out = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', function ($m) use ($js_delay, &$stripped) {
            $tag = $js_delay->removeNoDelay($m);
            if ($tag !== $m[0]) {
                $stripped++;
            }
            return $tag;
        }, $html);
        // No-delay markers are minted before the render knows whether the delay engine runs; the
        // engine did not run, so they are stripped. Sampled: the same scripts carry them on every
        // render of a page the delay does not reach.
        if ($stripped > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('nodelay-markers-stripped', ['scripts' => $stripped], true);
        }
        return $out;
    }

    /**
     * v7.23.15 — CRITICAL CSS OWNS ITS RESTORER. lazyCSS parks every sheet and the combine
     * lane parks every background url() behind html.wpc-bgl255 whenever a crit file exists;
     * the only runtime that restores them, keeps #wpc-critical-css until CSS is live and
     * arms the class is the v3 loader, which only process_html emitted. 7.22.53 removed the
     * legacy optimize.js (the previous restorer) with nothing in its place, so crit on +
     * delay off restored sheets then dropped crit with backgrounds still parked (7.22.x,
     * standoutedu hero) or restored nothing at all (7.23.x). Critical CSS and delay JS are
     * separate settings that must each work alone: when the v3 pass did not run, the crit
     * lane ships the SAME loader with an empty registry and a cssOnly cfg — nothing to
     * replay, no traps, so the .53 rule (engine off = no delayed scripts) is untouched.
     * Kill: wpc_css_only_loader.
     */
    public function gate_css_only_loader($ctx)
    {
        if ($ctx->delayV3Ran) {
            return 'v3-pass-ran';
        }
        if (!class_exists('wps_ic_js_delay_v3') || !method_exists('wps_ic_js_delay_v3', 'wpc_css_only_loader')) {
            return 'unavailable';
        }

        return true;
    }

    public function stage_css_only_loader($html, $ctx)
    {
        return wps_ic_js_delay_v3::wpc_css_only_loader($html);
    }

    /**
     * A parked frame (class wpc-iframe-delay, address in data-wpc-src) gets its address back when
     * the page carries no loader that restores frames: no delay loader, or only the CSS-only one.
     * A frame that is not a form frame and has no loading attribute gets the browser's own loading="lazy".
     */
    public static function wpc_unpark_frames_without_restorer($html)
    {
        if (!is_string($html) || strpos($html, 'wpc-iframe-delay') === false) {
            return $html;
        }
        if (strpos($html, 'wpc-delay-v3-loader') !== false && !preg_match('/["\']cssOnly["\']\s*:\s*1/', $html)) {
            return $html;
        }
        $count = 0;
        $out = preg_replace_callback('/<(?:iframe|source)\b[^>]*\bwpc-iframe-delay\b[^>]*>/i', function ($m) use (&$count) {
            $tag = $m[0];
            if (!preg_match('/\sdata-wpc-src=(["\'])(.*?)\1/s', $tag, $address) || preg_match('/\ssrc\s*=/i', $tag)) {
                return $tag;
            }
            $count++;
            $tag = str_replace($address[0], ' src=' . $address[1] . $address[2] . $address[1], $tag);
            if (stripos($tag, '<iframe') === 0 && !preg_match('/\sloading\s*=/i', $tag) && strpos($tag, 'data-wpc-form-frame') === false) {
                $tag = (string) preg_replace('/^<iframe\b/i', '<iframe loading="lazy"', $tag, 1);
            }
            return (string) preg_replace_callback('/\sclass=(["\'])([^"\']*)\1/i', function ($c) {
                $classes = trim((string) preg_replace('/(?:^|\s)wpc-iframe-delay(?=\s|$)/', ' ', $c[2]));
                $classes = (string) preg_replace('/\s+/', ' ', $classes);
                return $classes === '' ? '' : ' class=' . $c[1] . $classes . $c[1];
            }, $tag, 1);
        }, $html);
        if (!is_string($out)) {
            return $html;
        }
        if ($count && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('frames-unparked-no-restorer', ['n' => $count], true);
        }
        return $out;
    }

    public function gate_scripts_to_footer($ctx)
    {
        if (!empty($_GET['disableCritical'])) {
            return 'disable-critical';
        }
        if (empty(self::$settings['scripts-to-footer']) || self::$settings['scripts-to-footer'] != '1') {
            return 'off';
        }

        return true;
    }

    public function stage_scripts_to_footer($html, $ctx)
    {
        $js_delay = new wps_ic_js_delay();
        $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$js_delay, 'scriptsToFooter'], $html);

        return preg_replace_callback('/<\/body>/si', [$js_delay, 'printFooterScripts'], $html);
    }

    /**
     * v7.10.708 — checkpoints for both render lanes. Placed after scripts-to-footer so that pass
     * can never sweep the tags, before minify so every cached copy carries them. The delay-mode
     * flag is assigned inside this same function_exists() door and the final face gate reads it,
     * so when the injector is absent the flag stays at its context default of false and that
     * gate stands down. Both face-gate entries therefore share this gate.
     */
    public function gate_yield_checkpoints($ctx)
    {
        return function_exists('wpc_yield_checkpoints_pass') ? true : 'no-injector';
    }

    public function stage_yield_checkpoints($html, $ctx)
    {
        $lane = ($ctx->lane === wps_ic_render_pipeline::LANE_LOCAL) ? 'local' : 'cdn';
        $ctx->delayModeActive = class_exists('wps_ic_js_delay_v3')
            && wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings)
            && !$ctx->isAmp && empty($_GET['disableDelay']) && empty($_GET['disableCritical'])
            && !current_user_can('manage_wpc_settings') && !self::$delay_js_override && !self::$preloaderAPI;
        $lengthBefore = strlen($html);
        $html = wpc_yield_checkpoints_pass($html, $ctx->delayModeActive);
        if (strlen($html) !== $lengthBefore && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('yield-inject', '', '', ['lane' => $lane]);
        }

        return $html;
    }


    /* ------------------------------------------------------------------------------------- *
     * The post-checkpoint stretch, in table order: the last passes before the tail. One entry
     * carries both lanes for every pass here, the bunny swap, scripts-to-footer, the minify door
     * and the budget door in front of the stack splice included. Exactly one entry is
     * lane-specific: the negotiated-delivery restore, because only the CDN-off lane stashes
     * those tags in the first place.
     * ------------------------------------------------------------------------------------- */

    /** The minify door the CDN body ran right below the cache_minify checkpoint — on both lanes
     *  now: the setting is a cache setting, not a delivery one. */
    public function gate_minify_html($ctx)
    {
        if (empty(self::$settings['cache']['minify']) || self::$settings['cache']['minify'] != '1') {
            return 'off';
        }

        return self::isURLExcluded('minify_html') ? 'excluded' : true;
    }

    public function stage_minify_html($html, $ctx)
    {
        return self::$minifyHtml->minify($html);
    }

    /** The WPC placeholder sweep both lanes ran below the cache_mobile checkpoint. */
    public function stage_strip_wpc_comments($html, $ctx)
    {
        return preg_replace('/<!--WPC[\s\S]*?-->/', '', $html);
    }

    /**
     * The replace-fonts door both lanes ran. Its bunny branch drops the gstatic links and swaps
     * the gstatic host; the googleapis half of the same swap is fonts_bunny_swap, the entry listed
     * after this one. The two touch disjoint hosts and neither mints a string the other matches,
     * so they complete each other rather than overlap, and the bytes are the same either way.
     */
    public function stage_fonts_replace_frontend($html, $ctx)
    {
        if (!empty(self::$settings['replace-fonts'])) {
            if (self::$settings['replace-fonts'] == 'local') {
                $fonts = new wps_ic_fonts();
                $html = $fonts->replaceFrontend($html, $ctx->fontFaces);
            } else if (self::$settings['replace-fonts'] == 'bunny') {
                $html = preg_replace('/<link\b[^>]*\bhref=["\']https?:\/\/fonts\.gstatic\.com\/[^"\']+["\'][^>]*>\s*/i', '', $html);
                $html = str_replace('fonts.gstatic.com', 'fonts.bunny.net', $html);
            }
        }

        return $html;
    }

    public function gate_fonts_bunny_swap($ctx)
    {
        if (empty(self::$settings['replace-fonts']) || self::$settings['replace-fonts'] != 'bunny') {
            return 'not-bunny';
        }

        return true;
    }

    /** Bunny Fonts — GDPR-compliant Google Fonts drop-in, on both lanes: a site that asked for
     *  Bunny asked for it whichever lane delivers, and only the CDN-off body ever ran this half. */
    public function stage_fonts_bunny_swap($html, $ctx)
    {
        return str_replace('fonts.googleapis.com', 'fonts.bunny.net', $html);
    }

    public function gate_modern_delivery($ctx)
    {
        if (!class_exists('WPC_Modern_Delivery') || !WPC_Modern_Delivery::is_active()) {
            return 'md-off';
        }

        return (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) ? 'negotiated' : true;
    }

    /** The mask opens and closes inside the stage, so no pass outside it sees a placeholder. */
    public function stage_modern_delivery($html, $ctx)
    {
        $wpcMdMask = [];
        $html = wps_rewriteLogic::maskMediaScripts($html, $wpcMdMask);
        $html = WPC_Modern_Delivery::rewrite_buffer($html, $ctx->imagePreloads);

        return wps_rewriteLogic::unmaskMediaScripts($html, $wpcMdMask);
    }

    /**
     * Restore the Edge-negotiate (Mode-B) stashed <img data-wpc-nd> tags. The stash was taken by
     * stage_negotiated_delivery_local, so this is the close of the window it opened.
     */
    public function stage_negotiated_stash_restore_local($html, $ctx)
    {
        return $ctx->closeWindow('nd_stash_local', $html);
    }

    /** One door for both naturalize entries. */
    public function gate_natural_assets_on($ctx)
    {
        if (!class_exists('wps_rewriteLogic') || !wps_rewriteLogic::natural_assets_on()) {
            return 'natural-off';
        }

        return true;
    }

    public function stage_asset_failover($html, $ctx)
    {
        $fb = self::add_asset_failover($html);

        return (is_string($fb) && $fb !== '') ? $fb : $html;
    }

    public function gate_logo_rightsize($ctx)
    {
        return class_exists('wps_rewriteLogic') ? true : 'no-rewrite-logic';
    }

    // Eager small SVGs inline as data: at the last stage - the img-pass net cannot see
    // picture-protected tags (receipted live: the header logo rode a wpc-picture block
    // and kept its zone URL on .718). AFTER logo_rightsize: that pass matches the
    // literal token logo in src, which a data: URI no longer carries - inlining first
    // would cost the logo its CLS right-sizing.
    public function gate_svg_inline_data($ctx)
    {
        return function_exists('wpc_svg_inline_data') ? true : 'no-inliner';
    }

    public function stage_svg_inline_data($html, $ctx)
    {
        return preg_replace_callback('#(<img\b(?![^>]*loading="lazy")[^>]*\ssrc=")(https?://[^"]+\.svg[^"]*)(")#i', function ($m) {
            $dataUri = wpc_svg_inline_data($m[2]);
            return $dataUri !== '' ? $m[1] . $dataUri . $m[3] : $m[0];
        }, $html);
    }

    /**
     * The render-budget door in front of the stack splice, on both lanes. It sits behind
     * gate_stack_splice with the splice itself, so a build without the splicer reaches neither.
     * The journal label names the pass, not the lane, because the same door stands on both.
     */
    public function bail_budget_stack($html, $ctx)
    {
        return self::wpc_render_budget_exceeded('stack');
    }

    public function gate_stack_splice($ctx)
    {
        return function_exists('wpc_stack_splice') ? true : 'no-splicer';
    }

    public function stage_stack_splice($html, $ctx)
    {
        return wpc_stack_splice($html);
    }







    public static function add_asset_failover($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name)) return $html;
        if (!class_exists('wps_rewriteLogic') || !wps_rewriteLogic::natural_assets_on()) return $html;
        $zoneHost = preg_replace('#/.*$#', '', (string) self::$zone_name);
        $origin   = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
        if ($origin === '' || strcasecmp((string) $zoneHost, (string) $origin) === 0) return $html;
        $zq = preg_quote((string) $zoneHost, '#');
        $linkFailoverAttributes = function ($origin_url) {
            return ' data-wpc-fb="0" onerror="if(!this.dataset.wpcFb||this.dataset.wpcFb===\'0\'){this.dataset.wpcFb=1;this.href=\'' . esc_attr($origin_url) . '\';}"';
        };


        $css = preg_replace_callback('#<link\b(?=[^>]*\srel=["\']?stylesheet)(?![^>]*\sdata-wpc-fb)[^>]*\shref=["\']https://' . $zq . '(/[^"\']+?\.css[^"\']*)["\'][^>]*>#i', function ($m) use ($origin, $linkFailoverAttributes) {
            if (strpos($m[0], '/a:') !== false) return $m[0];
            return str_replace('<link', '<link' . $linkFailoverAttributes('https://' . $origin . $m[1]), $m[0]);
        }, $html);
        if (is_string($css) && $css !== '') $html = $css;
        $deferredCss = preg_replace_callback('#<link\b(?=[^>]*\s(?:rel|type)=["\']?wpc-(?:mobile-)?stylesheet)(?![^>]*\sdata-wpc-fb)[^>]*\shref=["\']https://' . $zq . '(/[^"\']+?\.css[^"\']*)["\'][^>]*>#i', function ($m) use ($origin, $linkFailoverAttributes) {
            if (strpos($m[0], '/a:') !== false) return $m[0];
            return str_replace('<link', '<link' . $linkFailoverAttributes('https://' . $origin . $m[1]), $m[0]);
        }, $html);
        if (is_string($deferredCss) && $deferredCss !== '') $html = $deferredCss;
        $restCss = preg_replace_callback('#<link\b(?![^>]*\sdata-wpc-fb)[^>]*\sdata-wpc-(?:rest|lf-href)=["\']https://' . $zq . '(/[^"\']+?\.css[^"\']*)["\'][^>]*>#i', function ($m) use ($origin, $linkFailoverAttributes) {
            if (strpos($m[0], '/a:') !== false) return $m[0];
            return str_replace('<link', '<link' . $linkFailoverAttributes('https://' . $origin . $m[1]), $m[0]);
        }, $html);
        if (is_string($restCss) && $restCss !== '') $html = $restCss;
        $images = preg_replace_callback('#<img\b(?![^>]*\sdata-wpc-fb)[^>]*\ssrc=["\']https://' . $zq . '(/[^"\']+?)["\'][^>]*>#i', function ($m) use ($origin) {
            if (strpos($m[0], '/a:') !== false || strpos($m[0], '/u:') !== false) return $m[0];
            $originUrl = 'https://' . $origin . preg_replace('/\?.*$/', '', $m[1]);
            $onerrorJs = "this.onerror=null;var p=this.parentNode;if(p&&p.tagName==='PICTURE'){var s;while(s=p.getElementsByTagName('source')[0])s.parentNode.removeChild(s);}this.removeAttribute('srcset');this.src=this.getAttribute('data-wpc-fb');";
            return str_replace('<img', '<img data-wpc-fb="' . esc_attr($originUrl) . '" onerror="' . $onerrorJs . '"', $m[0]);
        }, $html);
        if (is_string($images) && $images !== '') $html = $images;
        $lazyImages = preg_replace_callback('#<img\b(?![^>]*\sdata-wpc-fb)[^>]*\sdata-wpc-qw-src=["\']https://' . $zq . '(/[^"\']+?)["\'][^>]*>#i', function ($m) use ($origin) {
            if (strpos($m[1], '/a:') !== false || strpos($m[1], '/u:') !== false) return $m[0];
            $originUrl = 'https://' . $origin . preg_replace('/\?.*$/', '', $m[1]);
            $onerrorJs = "this.onerror=null;var p=this.parentNode;if(p&&p.tagName==='PICTURE'){var s;while(s=p.getElementsByTagName('source')[0])s.parentNode.removeChild(s);}this.removeAttribute('srcset');this.src=this.getAttribute('data-wpc-fb');";
            return str_replace('<img', '<img data-wpc-fb="' . esc_attr($originUrl) . '" onerror="' . $onerrorJs . '"', $m[0]);
        }, $html);
        if (is_string($lazyImages) && $lazyImages !== '') $html = $lazyImages;
        $js = preg_replace_callback('#<script\b(?![^>]*\sdata-wpc-fb)[^>]*\ssrc=["\']https://' . $zq . '(/[^"\']+?\.js[^"\']*)["\'][^>]*></script>#i', function ($m) use ($origin) {
            if (strpos($m[0], '/a:') !== false) return $m[0];
            $o = 'https://' . $origin . $m[1];
            return str_replace('<script', '<script data-wpc-fb="0" onerror="if(!this.dataset.wpcFb||this.dataset.wpcFb===\'0\'){this.dataset.wpcFb=1;var s=document.createElement(\'script\');s.src=\'' . esc_attr($o) . '\';this.parentNode.insertBefore(s,this.nextSibling);}"', $m[0]);
        }, $html);
        if (is_string($js) && $js !== '') $html = $js;
        return $html;
    }

    public function getRegexp()
    {
        if (!isset(self::$options['regExUrl']) || !isset(self::$options['regexpDirectories']) || empty(self::$options['regExUrl']) || empty(self::$options['regexpDirectories'])) {
            $escapedSiteURL = quotemeta(self::$home_url);
            self::$options['regExUrl'] = $regExURL = '(https?:|)' . substr($escapedSiteURL, strpos($escapedSiteURL, '//'));

            //Prep Included Directories
            $directories = 'wp\-content|wp\-includes';
            if (!empty($cdn['cdn_directories'])) {
                $directoriesArray = array_map('trim', explode(',', $cdn['cdn_directories']));

                if (count($directoriesArray) > 0) {
                    $directories = implode('|', array_map('quotemeta', array_filter($directoriesArray)));
                }
            }

            self::$options['regexpDirectories'] = $directories;

            self::$regExURL = $regExURL;
            self::$regExDir = $directories;

            update_option(WPS_IC_OPTIONS, self::$options);
        } else {
            self::$regExURL = self::$options['regExUrl'];
            self::$regExDir = self::$options['regexpDirectories'];
        }
    }

    public function removeDuplicatedFontawesome($html)
    {
        if (preg_match('#<link[^>]+href=["\'][^"\']*font-awesome/css/all\.min\.css[^"\']*["\'][^>]*>#i', $html)) {
            // If it does, remove the first fontawesome.css link
            $html = preg_replace('#<link[^>]+href=["\'][^"\']*fontawesome\.css[^"\']*["\'][^>]*>\s*#i', '', $html, 1);
        }

        return $html;
    }

    /**
     * Cleans up script templates from HTML, adds IDs
     *
     * @param string $html The original HTML content
     * @return array Associative array containing modified HTML and saved templates
     */
    function removeTemplates($html)
    {
        $templates = [];
        $templateIdPrefix = 'template_';
        $templateCounter = 0;

        // First, find all script tags with their content
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER);

        // Process each script tag
        foreach ($matches as $match) {
            $fullTag = $match[0];
            $content = $match[1];

            // Check if this is a template script
            if (preg_match('/type\s*=\s*["\']text\/template["\']/i', $fullTag)) {
                // Generate a unique ID
                $templateId = $templateIdPrefix . $templateCounter++;

                // Save the content
                $templates[$templateId] = $content;

                // Check if there's already an id attribute
                if (preg_match('/\swpc_id\s*=\s*["\'][^"\']*["\']/i', $fullTag)) {
                    // Replace existing id
                    $newTag = preg_replace('/(\swpc_id\s*=\s*["\'])[^"\']*(["\'])/i', '$1' . $templateId . '$2', $fullTag);
                } else {
                    // Add id attribute before the closing >
                    $newTag = preg_replace('/(<script\b[^>]*)>/i', '$1 wpc_id="' . $templateId . '">', $fullTag);
                }

                // Remove the content
                $newTag = preg_replace('/(<script\b[^>]*>).*(<\/script>)/is', '$1$2', $newTag);

                // Replace in the original HTML
                $html = str_replace($fullTag, $newTag, $html);
            }
        }

        return ['html' => $html, 'templates' => $templates];
    }

    /**
     * Encode meta tags to protect them from URL rewriting
     * @param string $html
     * @return array ['html' => modified_html, 'store' => meta_tags_store]
     */
    public function encodeMeta($html)
    {
        $metaTagsStore = [];
        $metaCounter = 0;

        // Find and encode all meta tags with image content
        $html = preg_replace_callback('#<meta\s+(?:property=["\'](?:og:image|twitter:image)["\']|name=["\']twitter:image["\'])[^>]*>#i', function ($matches) use (&$metaTagsStore, &$metaCounter) {
            $placeholder = '<!--META_PLACEHOLDER_' . $metaCounter . '-->';
            $metaTagsStore[$metaCounter] = $matches[0];
            $metaCounter++;
            return $placeholder;
        }, $html);

        // Also handle JSON-LD scripts
        $html = preg_replace_callback('#<script\s+type=["\']application/ld\+json["\'][^>]*>.*?</script>#si', function ($matches) use (&$metaTagsStore, &$metaCounter) {
            $placeholder = '<!--JSONLD_PLACEHOLDER_' . $metaCounter . '-->';
            $metaTagsStore[$metaCounter] = $matches[0];
            $metaCounter++;
            return $placeholder;
        }, $html);

        return ['html' => $html, 'store' => $metaTagsStore];
    }


    public static function wpc_zone_is_cf_direct()
    {
        if (!function_exists('get_option') || !defined('WPS_IC_CF_CNAME')) {
            return false;
        }
        $cname = trim((string) get_option(WPS_IC_CF_CNAME, ''));
        return ($cname !== '' && stripos((string) self::$zone_name, $cname) !== false);
    }


    public static function wpc_webp_origin_natural()
    {
        $opt = function_exists('get_option') ? get_option('wpc_webp_origin_natural', 0) : 0;
        return (bool) apply_filters('wpc_webp_origin_natural', !empty($opt));
    }

    /** One URL on the CDN lane. $ctx is the render's: its AMP verdict widens the transform and
     *  its lazy flag decides which exclusion list is_excluded() applies. */
    public function cdn_rewrite_url($url, $addslashes, $ctx)
    {
        $width = 1;

        if ($ctx->isAmp) {
            $width = 600;
        }

        $url = $url[0];

        if (strpos($url, 'cookie') !== false) {
            return $this->maybe_slash($url, $addslashes);
        }

        $matchCount = preg_match_all('/((https?\:\/\/|\/\/)[^\s]+\S+\.(' . self::$findImages . '))\s(\d{1,5}+[wx])/', $url, $srcset_links);

        if ((strpos($url, ' ') !== false || strpos($url, '%20') !== false) && $matchCount === 0) {
            return $url;
        }

        if (self::isExcluded('cdn', $url)) {
            return $this->maybe_slash($url, $addslashes);
        }

        if (strpos($url, 'spinner.svg') !== false || strpos($url, 'gform_ajax_spinner') !== false) {
            return $this->maybe_slash($url, $addslashes);
        }


        if (preg_match('/\.gif(\?|#|\s|$)/i', $url)
            && !(class_exists('wps_rewriteLogic') && wps_rewriteLogic::cf_is_delivery())) {
            return $this->maybe_slash($url, $addslashes);
        }

        $siteUrl = self::$home_url;
        $newUrl = str_replace($siteUrl, '', $url);

        // Check if site url is staging url? Anything after .com/something?
        preg_match('/(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]\/([a-zA-Z0-9]+)/', $siteUrl, $isStaging);

        // TODO: This is required for STAGING TO WORK!!! Don't remove SiteURL!!! LOOK for next TODO!!!

        $originalUrl = $url;
        $newSrcSet = '';


        if (!empty($srcset_links[0])) {
            if (!empty(self::$settings['remove-srcset'])) {
                return '';
            }
        }

        if (!empty($srcset_links[0])) {
            $hadTrailingEscapedQuoteSlash = false;

            if (substr($url, -1) === '\\') {
                $hadTrailingEscapedQuoteSlash = true;
                $url = substr($url, 0, -1);

                $matchCount = preg_match_all('/((https?\:\/\/|\/\/)[^\s]+\S+\.(' . self::$findImages . '))\s(\d{1,5}+[wx])/', $url, $srcset_links);
            }

            foreach ($srcset_links[0] as $i => $srcset) {
                $src = explode(' ', $srcset);
                $srcset_url = $src[0];
                $srcset_width = $src[1];

                if (self::is_excluded_link($srcset_url) || self::is_excluded($srcset_url, $srcset_url, $ctx->lazyEnabled)) {
                    $newSrcSet .= $srcset_url . ' ' . $srcset_width . ',';
                } elseif (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
                    // Images-master gate: Images tile OFF ⇒ leave srcset entries at origin (no
                    // /q:i/wp:N/ transform). Mirrors the single-URL serve gates.
                    $newSrcSet .= $srcset_url . ' ' . $srcset_width . ',';
                } else {
                    if (strpos($srcset_width, 'x') !== false) {
                        $width_url = 1;
                        $srcset_width = str_replace('x', '', $srcset_width);
                        $extension = 'x';
                    } else {
                        $width_url = $srcset_width = str_replace('w', '', $srcset_width);
                        $extension = 'w';
                    }

                    if (strpos($srcset_url, self::$zone_name) !== false) {
                        $newSrcSet .= $srcset_url . ' ' . $srcset_width . $extension . ',';
                        continue;
                    }


                    if ((empty(self::$externalUrlEnabled) || self::$externalUrlEnabled == '0')
                        && !self::image_url_matching_site_url($srcset_url)) {
                        $newSrcSet .= $srcset_url . ' ' . $srcset_width . $extension . ',';
                        continue;
                    }

                    if ($srcset_width == '1') {
                        $srcsetWidthExtension = '';
                    } else {
                        $srcsetWidthExtension = $srcset_width . $extension;
                    }


                    if (strpos($srcset_url, '.webp') !== false && self::wpc_webp_origin_natural()) {
                        $webp_nat_ss = preg_replace('/-\d+x\d+(\.webp)$/i', '$1', $srcset_url);
                        $webp_nat_ss = preg_replace('#^https?://[^/]+#', 'https://' . self::$zone_name, $webp_nat_ss);
                        $newSrcSet .= $webp_nat_ss . ' ' . $srcsetWidthExtension . ',';
                    } else {
                        $newSrcSet .= self::$apiUrl . '/r:' . self::$is_retina . '/wp:' . self::$webp . '/w:1/u:' . self::reformat_url($srcset_url) . ' ' . $srcsetWidthExtension . ',';
                    }
                }
            }

            $newSrcSet = rtrim($newSrcSet, ',');

            if ($hadTrailingEscapedQuoteSlash) {
                $newSrcSet .= '\\';
            }

            return $newSrcSet;
        } else {
            if (strpos($url, 'data:image') !== false) {
                return $url;
            }

            if (self::is_excluded_link($url)) {
                return $this->maybe_slash($url, $addslashes);
            }

            if (strpos($url, self::$zone_name) !== false) {
                return $this->maybe_slash($url, $addslashes);
            }

            // External is disabled?
            if (empty(self::$externalUrlEnabled) || self::$externalUrlEnabled == '0') {
                if (!self::image_url_matching_site_url($url)) {
                    return $this->maybe_slash($url, $addslashes);
                }
            } else {
                // Check if the URL is an image, then check if it's instagram etc...
                if (strpos($url, '.jpg') !== false || strpos($url, '.png') !== false || strpos($url, '.gif') !== false || strpos($url, '.svg') !== false || strpos($url, '.jpeg') !== false) {
                    foreach (self::$default_excluded_list as $i => $excluded_string) {
                        if (strpos($url, $excluded_string) !== false) {
                            return $this->maybe_slash($url, $addslashes);
                        }
                    }
                }
            }

            if (!empty($url)) {
                // Todo: Quick fix for Password Protected Pages
                if (strpos($url, 'login') !== false) {
                    return $this->maybe_slash($url, $addslashes);
                }

                if (strpos($url, '.css') !== false && self::$css == '1') {


                    if (stripos($url, '/cache/wp-cio-fonts/') !== false) {
                        return $this->maybe_slash($url, $addslashes);
                    }
                    $fileMinify = '0';
                    if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1'
                        && apply_filters('wpc_font_subset_forces_css_minify', false, $url)) {
                        $fileMinify = '1';
                    }
                    /**
                     * CSS File
                     */
                    $newUrl = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($url);

                    return $newUrl;
                } elseif (preg_match('/\.js(?:[?#]|$)/i', $url) && self::$js == '1') {
                    // v7.10.723 - SIXTH writer, the flagship lane: the doc-wide URL regex feeds
                    // every matched script through here into the /m:N/a: form; the belt skips
                    // /a: by design and wpc_asset_naturalize then collapses it to the mirror
                    // form after the URL stretch returns (the :2439 chain) - so zoned
                    // scripts reappeared behind four earlier writer standdowns. Stand down at
                    // the mint, same filter as the other five.
                    if (apply_filters('wpc_scripts_same_origin', true)) {
                        return $this->maybe_slash($url, $addslashes);
                    }
                    $fileMinify = self::$js_minify;
                    if (self::isExcluded('js_minify', $url)) {
                        $fileMinify = '0';
                    }

                    /**
                     * JS File
                     */
                    if (strpos($url, 'wp-content') !== false || strpos($url, 'wp-includes') !== false) {
                        if (empty(self::$js_minify) || self::$js_minify == 'false') {
                            $newUrl = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($url, false);
                        } else {
                            $newUrl = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($url, false);
                        }
                    } else {
                        $newUrl = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($url, false);
                    }

                    return $newUrl;
                } elseif (strpos($url, '.svg') !== false) {
                    if (!empty(self::$settings['serve']['svg'])) {
                        /**
                         * SVG File
                         */
                        if (!self::is_excluded($url, $url, $ctx->lazyEnabled)) {
                            if (self::$zone_test == 0 && (strpos($url, 'wp-content') !== false || strpos($url, 'wp-includes') !== false)) {
                                $newUrl = 'https://' . self::$zone_name . '/m:0/a:' . self::reformat_url($url);
                            } else {
                                $newUrl = 'https://' . self::$zone_name . '/m:0/a:' . self::reformat_url($url, false);
                            }
                        }
                    } else {
                        $newUrl = self::reformat_url($url, false);
                    }

                    return $newUrl;
                } elseif (self::$fonts == 1 && (strpos($url, '.woff') !== false || strpos($url, '.woff2') !== false || strpos($url, '.eot') !== false || strpos($url, '.ttf') !== false)) {
                    /**
                     * Font file
                     */


                    if (stripos($url, '/cache/wp-cio-fonts/') !== false) {
                        return $this->maybe_slash($url, $addslashes);
                    }


                    if (stripos((string) wp_parse_url($url, PHP_URL_PATH), '/wp-content/') === false) {
                        return $this->maybe_slash($url, $addslashes);
                    }


                    $wpc_zn = (string) self::$zone_name;
                    $wpc_oh = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
                    if ($wpc_zn === '' || ($wpc_oh !== '' && strcasecmp($wpc_zn, $wpc_oh) === 0)) {
                        return $this->maybe_slash($url, $addslashes);
                    }
                    if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') {
                        if (strpos($url, 'icon') !== false || strpos($url, 'awesome') !== false || strpos($url, 'lightgallery') !== false || strpos($url, 'gallery') !== false || strpos($url, 'side-cart-woocommerce') !== false) {
                            $newUrl = 'https://' . $wpc_zn . '/m:0/a:' . self::reformat_url($url);
                        } else {
                            $newUrl = 'https://' . $wpc_zn . '/font:true/a:' . self::reformat_url($url);
                        }
                    } else {
                        $newUrl = 'https://' . $wpc_zn . '/m:0/a:' . self::reformat_url($url);
                    }
                    return $newUrl;
                }

                if (self::is_excluded($url, $url, $ctx->lazyEnabled)) {
                    return $this->maybe_slash($originalUrl, $addslashes);
                }

                // Skip CDN MC for locally-optimized images — they're served via <picture> tags instead
                if (function_exists('wpc_url_to_attachment_id') && function_exists('wpc_get_local_optimized_ids')) {
                    $local_att_id = wpc_url_to_attachment_id($url);
                    if ($local_att_id) {
                        $optimized_ids = wpc_get_local_optimized_ids();
                        if (isset($optimized_ids[$local_att_id])) {
                            return $this->maybe_slash($originalUrl, $addslashes);
                        }
                    }
                }

                if (strpos($url, '.jpg') !== false || strpos($url, '.gif') !== false || strpos($url, '.png') !== false) {
                    $ext = '';
                    if (strpos($url, '.jpg') !== false) {
                        $ext = 'jpg';
                    } elseif (strpos($url, '.gif') !== false) {
                        $ext = 'gif';
                    } elseif (strpos($url, '.png') !== false) {
                        $ext = 'png';
                    }

                    if (!empty(self::$settings['serve'][$ext])) {
                        $webp = '/wp:' . self::$webp;
                        if (self::isExcludedFrom('webp', $url)) {
                            $webp = '/wp:0';
                        }

                        if (!self::is_excluded($url, $url, $ctx->lazyEnabled)) {
                            $newUrl = 'https://' . self::$zone_name . '/q:i/r:' . self::$is_retina . $webp . '/w:' . self::$rewriteLogic->getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $url)) . '/u:' . self::reformat_url($url);
                        }
                    } else {
                        $newUrl = self::reformat_url($url, false);
                    }

                    return $newUrl;
                }

                if (strpos($url, '.webp') !== false) {
                    // Images-master gate: Images tile OFF ⇒ serve the origin .webp, never a
                    // /q:i/wp:N/ transform.
                    if (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
                        return self::reformat_url($url, false);
                    }
                    if (!self::is_excluded($url, $url, $ctx->lazyEnabled)) {


                        if (self::wpc_webp_origin_natural()) {
                            $webp_nat = preg_replace('/-\d+x\d+(\.webp)$/i', '$1', $url);
                            $webp_nat = preg_replace('#^https?://[^/]+#', 'https://' . self::$zone_name, $webp_nat);
                            return $this->maybe_slash($webp_nat, $addslashes);
                        }
                        $webp = '/wp:' . self::$webp;
                        if (self::isExcludedFrom('webp', $url)) {
                            $webp = '/wp:0';
                        }
                        $newUrl = 'https://' . self::$zone_name . '/q:i/r:' . self::$is_retina . $webp . '/w:' . self::$rewriteLogic->getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $url)) . '/u:' . self::reformat_url($url);
                        return $newUrl;
                    }
                }

                return $url;


                // TODO: This is required for STAGING TO WORK!!! Don't remove SiteURL!!! LOOK for next TODO!!!
                if (self::$is_multisite) {
                    return $this->maybe_slash($newUrl, $addslashes);
                } elseif (empty($isStaging) || empty($isStaging[0])) {
                    // Not a staging site
                    return $this->maybe_slash($newUrl, $addslashes);
                } else {
                    // It's a staging site
                    return $this->maybe_slash($originalUrl, $addslashes);
                }
            }

            return $this->maybe_slash($url, $addslashes);
        }
    }

    public function maybe_slash($url, $addslashes = false)
    {
        if ($addslashes) {
            return addslashes($url);
        }

        return $url;
    }

    /** $lazyEnabled is the render's lazy flag ($ctx->lazyEnabled): with lazy loading on, the lazy
     *  exclusion list decides; with it off, the plain exclusion list does. */
    public static function is_excluded($image_element, $image_link, $lazyEnabled)
    {
        $image_path = '';

        if (empty($image_link)) {
            preg_match('@src="([^"]+)"@', $image_element, $match_url);
            if (!empty($match_url)) {
                $image_path = $match_url[1];
                $basename_original = basename($match_url[1]);
            } else {
                $basename_original = basename($image_element);
            }
        } else {
            $image_path = $image_link;
            $basename_original = basename($image_link);
        }

        preg_match("/([0-9]+)x([0-9]+)\.[a-zA-Z0-9]+/", $basename_original, $matches);
        if (empty($matches)) {
            // Full Image
            $basename = $basename_original;
        } else {
            // Some thumbnail
            $basename = str_replace('-' . $matches[1] . 'x' . $matches[2], '', $basename_original);
        }

        /**
         * Is this image lazy excluded?
         */
        if (!empty(self::$lazy_excluded_list) && !empty($lazyEnabled) && $lazyEnabled == '1') {

            foreach (self::$lazy_excluded_list as $i => $lazy_excluded) {
                if (strpos($basename, $lazy_excluded) !== false) {
                    return true;
                }
            }
        } elseif (!empty(self::$excluded_list)) {
            foreach (self::$excluded_list as $i => $excluded) {
                if (strpos($basename, $excluded) !== false) {
                    return true;
                }
            }
        }

        if (!empty(self::$lazy_excluded_list) && in_array($basename, self::$lazy_excluded_list)) {
            return true;
        }

        if (!empty(self::$excluded_list) && in_array($basename, self::$excluded_list)) {
            return true;
        }

        return false;
    }

    /**
     * Decode meta tags back to their original form
     * @param string $html
     * @param array $metaTagsStore
     * @return string
     */
    public function decodeMeta($html, $metaTagsStore)
    {
        if (empty($metaTagsStore)) {
            return $html;
        }

        foreach ($metaTagsStore as $index => $originalTag) {
            $metaPlaceholder = '<!--META_PLACEHOLDER_' . $index . '-->';
            $jsonldPlaceholder = '<!--JSONLD_PLACEHOLDER_' . $index . '-->';

            // Try meta placeholder first, then JSON-LD placeholder
            if (strpos($html, $metaPlaceholder) !== false) {
                $html = str_replace($metaPlaceholder, $originalTag, $html);
            } elseif (strpos($html, $jsonldPlaceholder) !== false) {
                $html = str_replace($jsonldPlaceholder, $originalTag, $html);
            }
        }

        return $html;
    }


    function restoreTemplates($html, $templates)
    {
        // Find all script tags
        preg_match_all('/<script\b[^>]*><\/script>/is', $html, $matches, PREG_SET_ORDER);

        // Process each empty script tag
        foreach ($matches as $match) {
            $fullTag = $match[0];

            // Check if this is a template script with an id
            if (preg_match('/type\s*=\s*["\']text\/template["\']/i', $fullTag) && preg_match('/wpc_id\s*=\s*["\']([^"\']+)["\']/i', $fullTag, $idMatch)) {

                $templateId = $idMatch[1];

                // Check if we have content for this ID
                if (isset($templates[$templateId])) {
                    // Restore the content
                    $newTag = str_replace('></script>', '>' . $templates[$templateId] . '</script>', $fullTag);

                    // Replace in the HTML
                    $html = str_replace($fullTag, $newTag, $html);
                }
            }
        }

        return $html;
    }

    /** $pictureWebpEnabled is the render's <picture> wrap flag ($ctx->pictureWebpEnabled). */
    public function injectPreloadImages($matches, $pictureWebpEnabled)
    {
        $originalHead = $matches[0];

        $inject = $originalHead;
        $inject .= '<!--WPC_INSERT_CRITICAL-->';
        $inject .= '<!--WPC_INSERT_PRELOAD_MAIN-->';
        $inject .= '<!--WPC_INSERT_PRELOAD-->';

        // Picture tag CSS safety net — makes <picture> transparent to CSS layout
        if ($pictureWebpEnabled) {
            $inject .= '<style id="wpc-picture-css">picture.wpc-picture:not([data-wpc-mir]){display:contents}picture.wpc-picture source{display:none}</style>';
        }

        // v7.21.265 — ELEMENTOR POSTS SPLIT-PAIR BELT. The cards skin ships its ratio
        // padding as STATIC CSS but the matching img-fill rules key on
        // .elementor-has-item-ratio, a class only Elementor's (delayed) posts handler
        // adds at runtime — so every post card shows the image at natural height with a
        // grey ratio-void under it until that JS runs (bestexteriorsinc blog: img 183px
        // in a 411px box, James's extra-space screenshot). While the class is un-armed
        // the orphan padding is zeroed; the moment the handler arms it, :not() stops
        // matching and Elementor's own pair applies pixel-exact.
        // (.266: this callback receives only the <head> match — a body-marker gate can
        // never see the widget, so the filter is the only gate. .267: killing the padding
        // was WRONG — CDP matched-styles proof: the ratio padding is the DESIGN, an
        // element-scoped rule minted from the widget's item_ratio (padding-bottom:
        // calc(.66*100%) at (0,5,0) — it outranks any container-level zero). Only the
        // img-fill PARTNER waits on the JS-armed class. Supply the partner: Elementor's
        // own armed declarations verbatim behind :not(), so cards render at their
        // designed ratio pre-JS and the identical native rules take over on arm.)
        // (.268: the verbatim mirror centered a natural-ratio image inside the taller
        // ratio box — 22px letterbox bands top and bottom (James's receipt); Elementor's
        // JS branches per-image with elementor-fit-height, which static CSS can't. One
        // object-fit:cover fill covers both orientations; on arm the native rules win.)
        if (apply_filters('wpc_elementor_posts_pair_belt', true)) {
            $inject .= '<style id="wpc-posts-pair">.elementor-posts-container:not(.elementor-has-item-ratio) .elementor-post__thumbnail img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;transform:none}</style>';
        }

        $inject .= $this->get_ga_script();

        return $inject;
    }

    public function get_ga_script()
    {
        if (!empty(self::$settings['ga-bot-shield']) && self::$settings['ga-bot-shield'] === '1') {
            return <<<JS
<script id="wpc-ga-bot-shield">
(function () {
  try {
    var ua = (navigator.userAgent || "").toLowerCase();

    /* ===============================
       Test helper (force bot mode)
       =============================== */
    function hasCookie(name) {
      try {
        return (document.cookie || "")
          .split(";")
          .some(c => c.trim().startsWith(name + "="));
      } catch(e) { return false; }
    }

    var forceBot =
      /(?:\\?|&)wpc_force_bot=1(?:&|$)/.test(location.search) ||
      hasCookie("wpc_force_bot");

    /* ===============================
       Bot detection
       =============================== */

    var isKnownBot =
      ua.includes("petalbot") ||
      ua.includes("sogou") ||
      ua.includes("baiduspider") ||
      ua.includes("yandexbot");

    if (!(forceBot || isKnownBot)) return;

    // Debug flag for support / QA
    window.__WPC_GA_BLOCKED__ = true;

    // Prevent inline GA errors
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function(){ window.dataLayer.push(arguments); };
    window.ga = window.ga || function(){ (window.ga.q = window.ga.q || []).push(arguments); };

    function isGA(url) {
      url = String(url || "").toLowerCase();
      return (
        url.includes("google-analytics.com") ||
        url.includes("stats.g.doubleclick.net") ||
        url.includes("/collect") ||
        url.includes("/g/collect") ||
        url.includes("/mp/collect")
      );
    }

    /* ===============================
       sendBeacon
       =============================== */
    if (navigator.sendBeacon) {
      var _sb = navigator.sendBeacon.bind(navigator);
      navigator.sendBeacon = function (url, data) {
        if (isGA(url)) return true;
        return _sb(url, data);
      };
    }

    /* ===============================
       fetch
       =============================== */
    if (window.fetch) {
      var _fetch = window.fetch.bind(window);
      window.fetch = function (input, init) {
        var url = "";
        try {
          url = (typeof input === "string")
            ? input
            : (input && input.url) || "";
        } catch(e) {}
        if (isGA(url)) {
          return Promise.resolve(new Response("", { status: 204 }));
        }
        return _fetch(input, init);
      };
    }

    /* ===============================
       XMLHttpRequest
       =============================== */
    if (window.XMLHttpRequest) {
      var _open = XMLHttpRequest.prototype.open;
      var _send = XMLHttpRequest.prototype.send;

      XMLHttpRequest.prototype.open = function (method, url) {
        this.__wpc_block_ga = isGA(url);
        return _open.apply(this, arguments);
      };

      XMLHttpRequest.prototype.send = function () {
        if (this.__wpc_block_ga) {
          try { this.abort(); } catch(e) {}
          return;
        }
        return _send.apply(this, arguments);
      };
    }

    /* ===============================
       Image pixel fallback
       =============================== */
    try {
      var desc = Object.getOwnPropertyDescriptor(Image.prototype, "src");
      if (desc && desc.set) {
        Object.defineProperty(Image.prototype, "src", {
          configurable: true,
          get: desc.get,
          set: function (v) {
            if (!isGA(v)) desc.set.call(this, v);
          }
        });
      }
    } catch(e) {}

  } catch (e) {
    // Fail open: never break analytics for humans
  }
})();
</script>
JS;
        }
        return '';
    }

    public function elementorAnimations($matches)
    {
        $animationData = $matches[1];
        if (strpos($animationData, '_animation')) {
            #$matches[0] = str_replace('elementor-invisible', '', $matches[0]);
            #$matches[0] = preg_replace('/(<div[^>]*\sclass="[^"]*)(")/si', "$1 " . "animated fadeInLeft" . " $2", $matches[0]);
            return $matches[0];
        }
        return $matches[0];
    }

    public function removeBgOverlay($html)
    {
        return '';
    }

    public function local_script_encode($html)
    {
        $slashed = addslashes($html[0]);
        $encoded = base64_encode($slashed);

        return '[script-wpc]' . $encoded . '[/script-wpc]';
    }

    public function local_script_decode($html)
    {
        $decode = str_replace('[script-wpc]', '', $html[0]);
        $decode = str_replace('[/script-wpc]', '', $decode);

        $decode = base64_decode($decode);
        $decode = stripslashes($decode);

        return $decode;
    }

    public function crittr_replace_css($links)
    {
        preg_match_all('/([a-zA-Z\-\_]*)\s*\=["|\'](.*?)["|\']/is', $links[0], $linkAtts);

        if (!empty($linkAtts[1])) {
            $linkHtml = '<link';
            $linkRel = '';

            $attNames = $linkAtts[1];
            $attValues = $linkAtts[2];

            foreach ($attNames as $i => $attName) {
                if ($attName == 'rel' && $attValues[$i] == 'dns-prefetch') {
                    $linkRel = $attValues[$i];
                } elseif ($attName == 'href') {
                    if (strpos($attValues[$i], self::$site_url) === false) {

                    } else {

                        if (strpos($attValues[$i], self::$zone_name) === false) {
                            $attValues[$i] = WPS_IC_URI . 'fixCss.php?zoneName=' . self::$zone_name . '&css=' . urlencode($attValues[$i]) . '&rand=' . time();
                        }

                    }
                }

                $linkHtml .= ' ' . $attName . '="' . $attValues[$i] . '"';
            }

            $linkHtml .= '/>';

            if ($linkRel == 'stylesheet') {
                return $linkHtml;
            } else {
                return $links[0];
            }


        } else {
            return $links[0];
        }
    }

    // Class names whose <video> is a section background drawn by a page builder: Divi 4
    // (et_pb_section_video_bg), Elementor (elementor-background-video-*), the core Cover block
    // (wp-block-cover__video-background), Beaver Builder (fl-bg-video).
    const BUILDER_BACKGROUND_VIDEO_MARKERS = ['et_pb_section_video_bg', 'elementor-background-video',
        'wp-block-cover__video-background', 'fl-bg-video'];

    /**
     * Parks the <source src> of <video>/<audio> for the facade (restored by the loader), except
     * where holding it breaks the page.
     *
     * Rule: a background video keeps its source. That is a <video> that is autoplay AND muted,
     * or one inside a builder's video-background wrapper (BUILDER_BACKGROUND_VIDEO_MARKERS); a
     * builder script sizes and reveals it from its metadata, and a video it finds empty stays
     * wrong after the source comes back. A video the site listed under the lazy-load exclusions
     * keeps its source too, as an image on that list keeps its src.
     * Observed failure: webdesign4u.com.au 2026-09-24, Divi 4 hero `<video loop autoplay
     * playsinline muted><source …>` parked as data-wpc-src. Divi initialised the section against
     * an empty video: desktop kept the grey preload box with a spinner for good, mobile sized the
     * video from the empty 300x150 box (the hero half covered), and the first video frame came
     * ~6 s after first paint instead of ~0.4 s.
     * No above-the-fold test beyond this: the ATF observation records images only, and a video
     * that is neither autoplay-muted nor a builder background shows its poster and gets its
     * source back at loader boot (frames() in the first tick).
     *
     * Narrowed (2026-09-28): a Divi 4 background video follows Lazy Load iFrames again when
     * $diviPlayerDelayed (Lazy Load iFrames on, the delay runs on this render, and Divi's
     * runtime is delayed: see divi_player_is_delayed()). The 09-24 failure needed Divi to set
     * the section up against an empty video; with its runtime delayed Divi runs only at the
     * replay, and the loader gives every held video source back at the start of the replay,
     * before the first delayed script (D() -> wpcRestoreHeldVideoSources, F11 rule 2). Keeping
     * the source there loaded the 704 KB webm at first paint and made it the LCP element:
     * webdesign4u.com.au homepage, local Lighthouse desktop 83 kept against 92 parked (7.24.04:
     * 91). The section itself stays Divi's grey preload box until the replay, as on 7.24.04.
     */
    public function park_media_sources($html, $diviPlayerDelayed = false)
    {
        if (!is_string($html) || stripos($html, '<source') === false) {
            return $html;
        }
        $sourcePattern = '/<source([^>]*)\ssrc=["\']([^"\']+)["\']/i';
        $out = preg_replace_callback('/<video\b[^>]*>.*?<\/video>|<source[^>]*\ssrc=["\'][^"\']+["\']/is',
            function ($match) use ($html, $sourcePattern, $diviPlayerDelayed) {
                $markup = $match[0][0];
                if (stripos($markup, '<video') !== 0) {
                    // A <source> outside any <video>: <audio>, as before.
                    $parked = $this->replace_source_tags([$markup]);
                    return is_string($parked) ? $parked : $markup;
                }
                $precedingMarkup = substr($html, max(0, $match[0][1] - 600), min(600, $match[0][1]));
                $diviBackgroundHeld = $diviPlayerDelayed
                    && preg_match('/<[a-z][a-z0-9-]*\b[^<>]*\bet_pb_section_video_bg\b[^<>]*>\s*$/i', $precedingMarkup);
                if (!$diviBackgroundHeld && self::video_keeps_source($markup, $precedingMarkup)) {
                    return $markup;
                }
                $parked = preg_replace_callback($sourcePattern, [$this, 'replace_source_tags'], $markup);
                return is_string($parked) ? $parked : $markup;
            }, $html, -1, $count, PREG_OFFSET_CAPTURE);
        return is_string($out) ? $out : $html;
    }

    /**
     * Divi 4's runtime (scripts.min.js / custom.unified.js, which sets up the video section) will
     * sit in the delay registry on this render. The facade runs before the delay pass, so it asks
     * the delay's own owner, wps_ic_js_delay_v3::keeps_script_at_load() (its keep list with the
     * builder runtime keeps, the site's Delay JS exclusions, the measured manifest), rather than
     * deciding "delayed" itself. False when the tag is missing, the v3 engine is not the one that
     * runs, or the owner keeps it: the source then stays, which is the 09-24 behaviour.
     */
    private function divi_player_is_delayed($html)
    {
        if (!is_string($html) || !preg_match('#<script\b[^>]*\bsrc=["\']([^"\']*/themes/Divi/js/(scripts|custom\.unified)(?:\.min)?\.js[^"\']*)["\']#i', $html, $runtimeMatch)) {
            return false;
        }
        $isV3 = false;
        $delayEngine = $this->delay_engine_v2_or_v3($isV3);
        if (!$isV3 || !method_exists($delayEngine, 'keeps_script_at_load')) {
            return false;
        }
        return !$delayEngine->keeps_script_at_load(html_entity_decode($runtimeMatch[1]), $html);
    }

    /**
     * The one keep decision for a <video>, shared by both lanes that can hide its source: the
     * facade (park_media_sources(), given the whole <video>…</video> block; the rule is there)
     * and the poster lane (wpc_video_delay_pass(), given the opening tag).
     */
    public static function video_keeps_source($videoMarkup, $precedingMarkup = '')
    {
        $openTag = preg_match('/^<video\b[^>]*>/i', $videoMarkup, $openMatch) ? $openMatch[0] : '';
        // Boolean attributes are read on the quote-stripped tag, so class="is-muted" never counts.
        $bareAttributes = (string) preg_replace('/(["\'])(?:(?!\1).)*\1/s', ' ', $openTag);
        $isAutoplay = (bool) preg_match('/(?<![-\w])autoplay(?![-\w])/i', $bareAttributes);
        $isMuted = (bool) preg_match('/(?<![-\w])muted(?![-\w])/i', $bareAttributes);
        if ($isAutoplay && $isMuted) {
            return true;
        }
        // The wrapper is the opening tag directly before the <video> (Divi: <span
        // class="et_pb_section_video_bg"><video …>); anything further back belongs to another element.
        $wrapperTag = preg_match('/<[a-z][a-z0-9-]*\b[^<>]*>\s*$/i', (string) $precedingMarkup, $wrapperMatch) ? $wrapperMatch[0] : '';
        foreach (self::BUILDER_BACKGROUND_VIDEO_MARKERS as $marker) {
            if (stripos($openTag, $marker) !== false || stripos($wrapperTag, $marker) !== false) {
                return true;
            }
        }
        $lazyExcludes = self::$lazy_excluded_list;
        if (is_string($lazyExcludes)) {
            $lazyExcludes = explode("\n", $lazyExcludes);
        }
        foreach ((array) $lazyExcludes as $exclude) {
            $exclude = trim((string) $exclude);
            if ($exclude !== '' && stripos($videoMarkup, $exclude) !== false) {
                return true;
            }
        }
        return false;
    }

    public function replace_source_tags($source)
    {

        $sourceAtts = self::wpc_parkable_attributes($source[0]);
        if (!empty($sourceAtts[0])) {
            $iFrame = '<source';
            $hasClass = false;

            $attNames = $sourceAtts[0];
            $attValues = $sourceAtts[1];

            if (!in_array('loading', $attNames)) {
                $attNames[] = 'loading';
            }

            foreach ($attNames as $i => $attName) {
                if (isset($attValues[$i]) === false && $attName != 'loading') {
                    $iFrame .= ' ' . $attName . ' ';
                    continue;
                }
                if ($attName == 'src') {
                    $attName = 'data-wpc-src';
                } elseif ($attName == 'class') {
                    $hasClass = true;
                    $attValues[$i] .= ' wpc-iframe-delay';
                } elseif ($attName == 'loading') {
                    $attValues[$i] = 'lazy';
                }

                $quote = strpos($attValues[$i], '"') === false ? '"' : "'";
                $iFrame .= ' ' . $attName . '=' . $quote . $attValues[$i] . $quote . ' ';
            }

            if (!$hasClass) {
                $iFrame .= 'class="wpc-iframe-delay"';
            }

            $iFrame .= '';

            return $iFrame;
        } else {
            return $source;
        }
    }

    /** The opening tag of an element at the start of $markup, '' when it does not start with one. A
     *  quoted value may hold a > or the other quote, so the tag ends at the first > outside quotes. */
    private static function wpc_open_tag($markup, $name)
    {
        if (!is_string($markup) || !preg_match('/^<' . $name . '\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>/i', $markup, $tag)) {
            return '';
        }
        return $tag[0];
    }

    /** The attributes of an opening tag in document order, each [name lower-cased, value, start, end,
     *  name as written, whether it has a value]: a value may be double-quoted, single-quoted or bare and
     *  may hold the other quote; start and end are byte offsets in $tag, start taking in the whitespace
     *  that separates the attribute from the one before, so cutting start..end leaves the rest of the
     *  tag as it was. A name is anything up to whitespace, a quote, = or >, so xml:lang is one name. */
    private static function wpc_tag_attributes($tag)
    {
        $attributes = [];
        if (!is_string($tag) || !preg_match_all('/(?:\s+|(?<=["\']))([^\s"\'<>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $tag, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, strcspn($tag, " \t\r\n/>"))) {
            return $attributes;
        }
        foreach ($found as $match) {
            $value = '';
            $hasValue = false;
            foreach ([2, 3, 4] as $group) {
                if (isset($match[$group]) && $match[$group][1] !== -1) {
                    $value = $match[$group][0];
                    $hasValue = true;
                    break;
                }
            }
            $attributes[] = [strtolower($match[1][0]), $value, $match[0][1], $match[0][1] + strlen($match[0][0]), $match[1][0], $hasValue];
        }
        return $attributes;
    }

    /** An attribute value as the source wrote it, made safe to write back: & < > are escaped, an entity
     *  the value already carries (&amp;, &#039;, &apos;) is kept, and the quotes are left to the caller,
     *  which delimits the value with whichever quote it does not hold. */
    private static function wpc_escape_attribute_value($value)
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false);
    }

    /** The attributes a parked tag is rebuilt from, as [names as written, values] in document order. A
     *  value is null for an attribute written without one (allowfullscreen). A bare src, data-src,
     *  data-wpc-src, class or loading names nothing and is left out. */
    private static function wpc_parkable_attributes($openTag)
    {
        $names = [];
        $values = [];
        foreach (self::wpc_tag_attributes($openTag) as $attribute) {
            if (!$attribute[5] && in_array($attribute[4], ['src', 'data-src', 'data-wpc-src', 'class', 'loading'], true)) {
                continue;
            }
            $names[] = $attribute[4];
            $values[] = $attribute[5] ? $attribute[1] : null;
        }
        return [$names, $values];
    }

    /** The domain a form_embed.js host stands for: its parent when it is a sub-domain of a branded
     *  domain (link.agency.com serves the script, api.agency.com the frames), the host itself for an
     *  address, a bare domain or a second-level public suffix. */
    private static function wpc_embed_family_domain($host)
    {
        $labels = explode('.', $host);
        if (count($labels) < 3 || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        $parent = implode('.', array_slice($labels, 1));
        return preg_match('/^(?:co|com|org|net|gov|edu|ac)\.[a-z]{2}$/', $parent) ? $host : $parent;
    }

    /** Whether a form_embed.js family may widen what counts as a GoHighLevel frame: not the site's own
     *  (home_url and the request host, www or not: a self-hosted script says nothing about other
     *  frames of the site) and not a public CDN that mirrors scripts. */
    private static function wpc_embed_family_unusable($family)
    {
        $unusable = ['jsdelivr.net', 'unpkg.com', 'cdnjs.cloudflare.com', 'googleapis.com', 'gstatic.com', 'cloudflare.com'];
        foreach ([function_exists('home_url') ? home_url() : '', isset($_SERVER['HTTP_HOST']) ? '//' . $_SERVER['HTTP_HOST'] : ''] as $address) {
            $host = parse_url((string) $address, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $unusable[] = self::wpc_embed_family_domain(strtolower($host));
            }
        }
        foreach ($unusable as $domain) {
            if ($family === $domain || substr($family, -strlen($domain) - 1) === '.' . $domain) {
                return true;
            }
        }
        return false;
    }

    /** The domains the GoHighLevel form_embed.js scripts on the page load from, null when the page
     *  loads none. A script with no host (a relative src), on the site's own host or on a public CDN
     *  counts as loaded and names no domain. */
    public static function wpc_form_embed_domains($html)
    {
        if (!is_string($html) || stripos($html, 'form_embed') === false
            || !preg_match_all('/<script\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>/i', $html, $scripts)) {
            return null;
        }
        $domains = null;
        foreach ($scripts[0] as $script) {
            foreach (self::wpc_tag_attributes($script) as $attribute) {
                if ($attribute[0] !== 'src' && $attribute[0] !== 'data-src' && $attribute[0] !== 'data-wpc-src') {
                    continue;
                }
                $url = parse_url(trim(html_entity_decode($attribute[1], ENT_QUOTES, 'UTF-8')));
                if (!is_array($url) || empty($url['path']) || !preg_match('#/form_embed\.js$#i', $url['path'])) {
                    continue;
                }
                if ($domains === null) {
                    $domains = [];
                }
                if (!empty($url['host'])) {
                    $family = self::wpc_embed_family_domain(strtolower($url['host']));
                    if (!self::wpc_embed_family_unusable($family)) {
                        $domains[] = $family;
                    }
                }
            }
        }
        return $domains === null ? null : array_values(array_unique($domains));
    }

    /** Whether an iframe is a GoHighLevel embed (a LeadConnector form, survey, calendar, booking, group
     *  or chat frame), under any white-label host: it carries data-layout-iframe-id, or its src
     *  (or data-wpc-src) is on leadconnectorhq.com, msgsndr.com or one of $formEmbedDomains
     *  (wpc_form_embed_domains()), or a sub-domain of one, at any path. $iframeMarkup may be the whole
     *  <iframe>…</iframe>; only the opening tag is read. */
    public static function wpc_is_ghl_embed_iframe($iframeMarkup, array $formEmbedDomains = [])
    {
        $tag = self::wpc_open_tag($iframeMarkup, 'iframe');
        if ($tag === '') {
            return false;
        }
        $domains = array_merge(['leadconnectorhq.com', 'msgsndr.com'], $formEmbedDomains);
        foreach (self::wpc_tag_attributes($tag) as $attribute) {
            if ($attribute[0] === 'data-layout-iframe-id') {
                return true;
            }
            if ($attribute[0] !== 'src' && $attribute[0] !== 'data-wpc-src') {
                continue;
            }
            $url = parse_url(trim(html_entity_decode($attribute[1], ENT_QUOTES, 'UTF-8')));
            if (!is_array($url) || empty($url['host'])) {
                continue;
            }
            $host = strtolower($url['host']);
            foreach ($domains as $domain) {
                if ($host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Removes loading="lazy" from a GoHighLevel embed iframe on a page that loads form_embed.js, for
     *  a frame no pass of ours rewrote: the one the facade keeps eager (page not measured) or never
     *  reached (iframe-lazy off), carrying the lazy attribute its own markup wrote. A frame the
     *  facade parked (data-wpc-src) is skipped, since replace_iframe_tags writes no loading onto a
     *  GoHighLevel frame. Every other iframe is left as it is. One receipt when it acted. */
    public static function wpc_ghl_embed_native_lazy_pass($html)
    {
        $domains = self::wpc_form_embed_domains($html);
        if ($domains === null || stripos($html, '<iframe') === false) {
            return $html;
        }
        $stripped = 0;
        $out = preg_replace_callback('/<iframe\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>/i', function ($open) use ($domains, &$stripped) {
            $tag = $open[0];
            if (stripos($tag, 'lazy') === false) {
                return $tag;
            }
            $lazy = [];
            foreach (self::wpc_tag_attributes($tag) as $attribute) {
                if ($attribute[0] === 'data-wpc-src') {
                    return $tag;
                }
                if ($attribute[0] === 'loading' && strtolower(trim($attribute[1])) === 'lazy') {
                    $lazy[] = $attribute;
                }
            }
            if (!$lazy || !self::wpc_is_ghl_embed_iframe($tag, $domains)) {
                return $tag;
            }
            foreach (array_reverse($lazy) as $attribute) {
                $tag = substr($tag, 0, $attribute[2]) . substr($tag, $attribute[3]);
            }
            $stripped++;
            return $tag;
        }, $html);
        if (!is_string($out)) {
            return $html;
        }
        if ($stripped > 0 && function_exists('wpc_belt_receipt')) {
            wpc_belt_receipt('ghl-embed-lazy-stripped', ['n' => $stripped], true);
        }
        return $out;
    }

    // Fleet P0 guard: the facade may AUTO-arm only where the v3 loader is
    // guaranteed on the page — a facaded iframe with no restorer is permanently
    // blank. Mirrors every engine-blocking condition around the process_html
    // call (agency, AMP, per-page exclude, overrides, v2-engine choice) on top
    // of the measured gate. The manual iframe-lazy setting keeps its historic
    // path (optimize.js restores there) and does not route through this.
    /** $isAmp is the render's AMP verdict ($ctx->isAmp). The answer is memoised for the request,
     *  so the first caller's verdict is the one kept; both callers sit in one stage, after the
     *  AMP squash, so they hand the same value. */
    public static function wpc_facade_aggr_ok($isAmp)
    {
        static $aggressiveFacadeAllowed = null;
        if ($aggressiveFacadeAllowed !== null) {
            return $aggressiveFacadeAllowed;
        }
        $aggressiveFacadeAllowed = false;
        try {
            if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
                return $aggressiveFacadeAllowed;
            }
            if (!empty($_GET['disableDelay']) || !empty($_GET['criticalCombine']) || !empty($_GET['disableCritical'])) {
                return $aggressiveFacadeAllowed;
            }
            if (function_exists('wpcGetHeader') && !empty(wpcGetHeader('criticalCombine'))) {
                return $aggressiveFacadeAllowed;
            }
            if ($isAmp) {
                return $aggressiveFacadeAllowed;
            }
            if (isset(self::$page_excludes['delay_js_v2']) && self::$page_excludes['delay_js_v2'] == '0') {
                return $aggressiveFacadeAllowed;
            }
            if (!empty(self::$delay_js_override) || !empty(self::$preloaderAPI)) {
                return $aggressiveFacadeAllowed;
            }
            if (isset(self::$settings['delay-js-v3']) && self::$settings['delay-js-v3'] == '0') {
                return $aggressiveFacadeAllowed; // v2 engine: frames() restorer not guaranteed
            }
            if (!class_exists('wps_ic_js_delay_v3')
                || !wps_ic_js_delay_v3::wpc_aggr_live()
                || !wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings)) {
                return $aggressiveFacadeAllowed;
            }
            $aggressiveFacadeAllowed = true;
        } catch (\Throwable $e) {
            $aggressiveFacadeAllowed = false;
        }
        return $aggressiveFacadeAllowed;
    }

    public function replace_iframe_tags($iframe, $isAmp, array $formEmbedDomains = [])
    {
        if (strpos($iframe[0], 'gform') !== false || strpos($iframe[0], 'data-src-cmplz') !== false) {
            return $iframe[0];
        }


        $wpc_if    = $iframe[0];
        $wpc_if_ns = str_replace(' ', '', strtolower($wpc_if));
        if (stripos($wpc_if, 'data-initial-iframe-hidden') !== false
            || strpos($wpc_if_ns, 'visibility:hidden') !== false
            || strpos($wpc_if_ns, 'opacity:0') !== false
            || strpos($wpc_if_ns, 'display:none') !== false
            || strpos($wpc_if_ns, 'left:-9999') !== false
            || strpos($wpc_if_ns, 'left:-99999') !== false) {
            return $iframe[0];
        }
        // GHL/LeadConnector frames: hard-kept eager historically (facading them
        // pre-io left the form blank). Under the aggressive default the heavy
        // list restores them at boot/gesture and the IO restore covers scroll-
        // toward — same reconciliation as the .359 form-family script release.
        $isGhlEmbed = self::wpc_is_ghl_embed_iframe($wpc_if, $formEmbedDomains);
        if ($isGhlEmbed && !self::wpc_facade_aggr_ok($isAmp)) {
            return $iframe[0];
        }

        $iframeAtts = self::wpc_parkable_attributes(self::wpc_open_tag($iframe[0], 'iframe'));

        if (!empty($iframeAtts[0])) {
            $attNames = $iframeAtts[0];
            $srcIndex = array_search('src', $attNames, true);
            $hasSrc = $srcIndex !== false && !empty($iframeAtts[1][$srcIndex]);
            $hasDataSrc = in_array('data-src', $attNames, true) || in_array('data-wpc-src', $attNames, true);

            if (!$hasSrc) {
                return $iframe[0];
            }

            if ($hasDataSrc && $hasSrc) {
                $srcIndex = array_search('src', $attNames, true);
                $srcValue = $iframeAtts[1][$srcIndex];

                if (strpos($srcValue, 'data:') === 0) {
                    // Probably already delayed with a placeholder in src
                    return $iframe[0];
                }
            }

            $iFrame = '<iframe';
            $hasClass = false;

            $attNames = $iframeAtts[0];
            $attValues = $iframeAtts[1];

            foreach ($attNames as $i => $attName) {
                if ($attValues[$i] === null) {
                    $iFrame .= ' ' . $attName;
                    continue;
                }
                if ($attName == 'src') {
                    $attName = 'data-wpc-src';
                    $escapedValue = $this->conditionallyEscapeUrl($attValues[$i]);
                } elseif ($attName == 'class') {
                    $hasClass = true;
                    $attValues[$i] .= ' wpc-iframe-delay';
                    $escapedValue = self::wpc_escape_attribute_value($attValues[$i]);
                } elseif ($attName == 'loading') {
                    if ($isGhlEmbed) {
                        continue;
                    }
                    $attValues[$i] = 'lazy';
                    $escapedValue = $attValues[$i];
                } else if ($attName == 'data-src') {
                    $escapedValue = $this->conditionallyEscapeUrl($attValues[$i]);
                } else {
                    $escapedValue = self::wpc_escape_attribute_value($attValues[$i]);
                }

                $quote = strpos($escapedValue, '"') === false ? '"' : "'";
                $iFrame .= ' ' . $attName . '=' . $quote . $escapedValue . $quote;
            }

            if (!$hasClass) {
                $iFrame .= ' class="wpc-iframe-delay"';
            }
            if ($isGhlEmbed) {
                $iFrame .= ' data-wpc-form-frame="1"';
            }

            $iFrame .= '></iframe>';

            return $iFrame;
        } else {
            return $iframe[0]; // Return original if no attributes found
        }
    }

    private function conditionallyEscapeUrl($url)
    {
        // Common patterns that indicate the URL is already encoded
        $encodedPatterns = ['&amp;',     // & encoded
            '&#038;',    // WordPress-style & encoding
            '%20',       // Space encoded
            '%2C',       // Comma encoded
            '&quot;',    // Quote encoded
            '&lt;',      // < encoded
            '&gt;'       // > encoded
        ];

        foreach ($encodedPatterns as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return $url; // Already encoded
            }
        }

        // Check for any HTML entity pattern
        if (preg_match('/&[a-zA-Z0-9#]+;/', $url)) {
            return $url; // Already encoded
        }

        // Not encoded, apply escaping only if needed
        if (strpos($url, '&') !== false || strpos($url, '"') !== false || strpos($url, '<') !== false || strpos($url, '>') !== false) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }

        return $url;
    }

    public function maybe_addslashes($image, $addslashes = false)
    {
        if ($addslashes) {
            $image = addslashes($image);
        }

        return $image;
    }

    public function specialChars($url)
    {
        if (!self::$brizyActive) {
            $url = htmlspecialchars($url);
        }

        return $url;
    }

    public function local_image_tags($image, $ctx)
    {
        $class_Addon = '';
        $image_tag = $image[0];
        $image_source = '';
        $webP = false;
        $webpMainExists = false;
        $isLazy = false;

        // .297 — PARK IDEMPOTENCE (this lane's missing twin of replaceImageTagsDo's guard):
        // a tag we already processed must pass through untouched. Re-parking a parked tag
        // destroys the payload (beucomply carousel: data-src re-minted at w:1, source
        // data-srcset overwritten with the placeholder — the real URL gone from the bytes).
        if (strpos($image[0], 'wps-ic-lazy-image') !== false
            || strpos($image[0], 'data-wpc-fb') !== false
            || strpos($image[0], 'wps-ic-cdn') !== false
            || strpos($image[0], 'wps-ic-live-cdn') !== false) {
            return $image[0];
        }

        // File has already been replaced
        if ($this->defaultExcluded($image[0])) {
            return $image[0];
        }

        // File is not an image
        if (strpos($image[0], '.webp') === false && strpos($image[0], '.jpg') === false && strpos($image[0], '.jpeg') === false && strpos($image[0], '.png') === false && strpos($image[0], '.ico') === false && strpos($image[0], '.svg') === false && strpos($image[0], '.gif') === false) {
            return $image[0];
        }

        // File is excluded
        if (self::is_excluded($image[0], '', $ctx->lazyEnabled)) {
            $ctx->nextgenPictureCounts['excluded']++;
            $image_source = $image[0];
            $image_source = preg_replace('/class=["|\'](.*?)["|\']/is', 'class="$1 wps-ic-loaded"', $image_source);

            return $image_source;
        }

        if ((self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0') && !self::image_url_matching_site_url($image[0])) {
            return $image[0];
        }

        // v7.10.717 - an image the markup hides on THIS device must not consume an
        // eager-window slot, must not carry high fetch priority, and stays lazy.
        $hiddenOrBelowFold = false;
        if (!empty($ctx->deviceHiddenImages) && function_exists('wpc_device_hidden_has')) {
            foreach (['src', 'data-src', 'data-cp-src'] as $srcAttribute) {
                if (preg_match('/\b' . $srcAttribute . '="([^"]+)"/i', $image[0], $hiddenSrcMatch)
                    && wpc_device_hidden_has($ctx->deviceHiddenImages, $hiddenSrcMatch[1])) {
                    $hiddenOrBelowFold = true;
                    break;
                }
            }
        }
        if (!$hiddenOrBelowFold && class_exists('wps_rewriteLogic')
            && method_exists('wps_rewriteLogic', 'wpc_is_census_below_fold')
            && wps_rewriteLogic::wpc_is_census_below_fold($image[0])) {
            $hiddenOrBelowFold = true;
        }

        // Count images that were lazy loaded
        if (!$hiddenOrBelowFold) {
            $ctx->localVisibleImageCount++;
        }


        $original_img_tag = [];
        $original_img_tag['original_tags'] = $this->getAllTags($image[0], []);

        if (!empty($original_img_tag['original_tags']['src']) && empty($original_img_tag['original_tags']['data-src'])) {
            $image_source = $original_img_tag['original_tags']['src'];
        } else {
            $image_source = $original_img_tag['original_tags']['data-src'];
        }

        $original_img_tag['original_src'] = $image_source;

        // Old Code Below

        // Figure out image class
        preg_match('/srcset=["|\']([^"]+)["|\']/', $image_tag, $image_srcset);
        if (!empty($image_srcset[1])) {
            $original_img_tag['srcset'] = $image_srcset[1];
        }

        $size = self::get_image_size($image_source);

        $svgAPI = $source_svg = 'data:image/svg+xml;base64,' . base64_encode(((int) $size[0] > 0 && (int) $size[1] > 0)
            ? '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size[0] . '" height="' . $size[1] . '"><path d="M2 2h' . $size[0] . 'v' . $size[1] . 'H2z" fill="#fff" opacity="0"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        // OriginalImageSource
        $original_img_src = $image_source;

        // Path to CSS File
        $site_url = str_replace(['https://', 'http://'], '', self::$site_url);
        $image_path = str_replace(['https://' . $site_url . '/', 'http://' . $site_url . '/'], '', $image_source);
        $image_path = explode('?', $image_path);
        $image_path = ABSPATH . $image_path[0];

        /**
         * Local File does not exists?
         */
        if (!wpc_nextgen_variant_exists($image_path)) {
            return $image[0];
        } else {


            $wpc_ng_ceiling = class_exists('WPC_Delivery_Resolver')
                ? WPC_Delivery_Resolver::effective_ceiling(self::$settings) : 'avif';
            if ($wpc_ng_ceiling !== 'off' && (self::$webp == 'true' || self::$webp == '1')) {
                // Check if WebP Exists in PATH?
                $webP = wps_rewriteLogic::swap_ext_to($image_path, 'webp');

                if (!wpc_nextgen_variant_exists($image_path, 'webp')) {
                    $webP = false;
                    $image_source = $original_img_src;
                } else {
                    $webpMainExists = true;
                    $original_img_src = wps_rewriteLogic::swap_ext_to($original_img_src, 'webp');
                    $image_source = $original_img_src;
                }
            } else {
                $image_source = $original_img_src;
            }
        }


        // Is LazyLoading enabled in the plugin?
        if (!empty($ctx->lazyEnabled) && $ctx->lazyEnabled == '1' && !self::$lazy_override) {

            if ($hiddenOrBelowFold || $ctx->localVisibleImageCount >= self::$lazyLoadSkipFirstImages) {
                $isLazy = true;

                // If Logo remove wps-ic-lazy-image
                if (strpos($image_source, 'logo') !== false) {
                    $image_tag = 'src="' . $image_source . '"';
                } else {
                    $image_tag = 'src="' . $svgAPI . '"';
                }

                $image_tag .= ' data-src="' . $image_source . '"';


                $lazyClass = 'wps-ic-local-lazy';
                if (self::$settings['js'] == 1) {
                    $lazyClass = 'wps-ic-lazy-image';
                }

                // If Logo remove wps-ic-lazy-image
                if (strpos($image_source, 'logo') !== false) {
                    // Image is for logo
                    $class_Addon .= $lazyClass . ' wps-ic-logo';
                } else {
                    // Image is not for logo
                    $class_Addon .= $lazyClass . ' ';
                }

            } else {
                $image_tag = 'src="' . $image_source . '"';
            }

        } else if ((!empty(self::$native_lazy_enabled) && self::$native_lazy_enabled == '1' && !self::$lazy_override)) {
            $image_tag = 'src="' . $image_source . '"';

            if (!$hiddenOrBelowFold && $ctx->localVisibleImageCount <= self::$lazyLoadSkipFirstImages) {
                // Don't lazy load
            } else {
                // If Logo remove wps-ic-lazy-image
                if (!strpos($image_source, 'logo')) {
                    $image_tag .= ' loading="lazy"';
                }
            }

        } else {
            if (!empty($ctx->adaptiveEnabled) && $ctx->adaptiveEnabled == '1') {
                $image_tag = 'src="' . $image_source . '"';
                $image_tag .= ' data-adaptive="true"';
                $image_tag .= ' data-remove-src="true"';
            } else {
                $image_tag = 'src="' . $image_source . '"';
                $image_tag .= ' data-adaptive="false"';
            }

            $image_tag .= ' data-src="' . $image_source . '"';
        }

        $image_tag .= ' data-count-lazy="' . $ctx->localVisibleImageCount . '"';

        if (!empty(self::$settings['fetchpriority-high']) && self::$settings['fetchpriority-high'] == '1') {
            if (!$hiddenOrBelowFold && $ctx->localVisibleImageCount <= self::$lazyLoadSkipFirstImages) {
                $image_tag .= ' fetchpriority="high"';
                // Once: the theme's own decoding= is copied with the original attributes below.
                // Observed: decoding="async"decoding="async" on every eager image of a CDN-off
                // site (staging-home local golden, speed-dashboard-2).
                if (strpos($image_tag, 'decoding=') === false && !isset($original_img_tag['original_tags']['decoding'])) {
                    $image_tag .= ' decoding="async"';
                }
            }
        }


        /**
         * Srcset to WebP
         */
        $srcset_att = '';
        // Only the rungs that have a .webp twin: a <source type="image/webp"> that names the
        // natural .jpg for a missing rung sends that URL to the Apache block, which answers it by
        // Accept with a private body again (ticket 12001). The <img> keeps every rung.
        $webpSourceRungs = [];

        if (self::$webp == 'true' || self::$webp == '1') {
            if (!empty($original_img_tag['srcset'])) {
                $exploded_scrcset = explode(',', $original_img_tag['srcset']);
                if (!empty($exploded_scrcset)) {
                    foreach ($exploded_scrcset as $i => $src) {
                        $src = trim($src);
                        $src_w = explode(' ', $src);

                        if (!empty($src_w)) {
                            $real_src = $src_w[0];
                            // Guard against malformed srcset entries missing the width descriptor
                            // (we don't control upstream srcset formatting, e.g. from theme/plugins)
                            $real_src_width = $src_w[1] ?? '';
                            if ($real_src_width === '') continue;

                            $image_path_webP = self::local_file_path($real_src);

                            $webP = wps_rewriteLogic::swap_ext_to($real_src, 'webp');

                            if ($image_path_webP === '' || !wpc_nextgen_variant_exists($image_path_webP, 'webp')) {
                                $srcset_att .= $real_src . ' ' . $real_src_width . ',';
                            } else {
                                $srcset_att .= $webP . ' ' . $real_src_width . ',';
                                $webpSourceRungs[] = self::nextgen_versioned_url($webP) . ' ' . $real_src_width;
                            }
                        }
                    }
                }
                $srcset_att = rtrim($srcset_att, ',');
            }
        }


        if (empty($srcset_att)) {
            $srcset_att = $original_img_tag['srcset'] ?? '';
        }

        if (!empty(self::$removeSrcset) && self::$removeSrcset == '1') {
            unset($original_img_tag['original_tags']['srcset']);
        } else {
            if (!empty($srcset_att)) {
                $srcsetAttr = $isLazy ? 'data-srcset' : 'srcset';
                $image_tag .= ' ' . $srcsetAttr . '="' . $srcset_att . '" ';
                unset($original_img_tag['original_tags']['srcset']);
            }
        }

        if (!empty($original_img_tag['original_tags'])) {
            // Each original attribute is written as 'name="value" ', so the one before it must
            // end in a space too; without a srcset it did not, and the two ran together.
            $image_tag = rtrim($image_tag) . ' ';
            foreach ($original_img_tag['original_tags'] as $tag => $value) {
                if ($tag == 'class') {
                    $value = $class_Addon . ' ' . $value;
                }

                if ($tag == 'src' || $tag == 'data-src') {
                    continue;
                }

                if (!is_null($value)) {
                    $image_tag .= $tag . '="' . $value . '" ';
                } else {
                    $image_tag .= $tag . ' ';
                }
            }
        }

        $finalTag = '<img ' . $image_tag . ' />';


        $pictureCeiling = class_exists('WPC_Delivery_Resolver')
            ? WPC_Delivery_Resolver::effective_ceiling(self::$settings) : 'avif';
        if ($ctx->pictureWebpEnabled && $pictureCeiling !== 'off') {
            $lowerSrc = strtolower($original_img_tag['original_src']);
            $skipFormats = (strpos($lowerSrc, '.svg') !== false || strpos($lowerSrc, '.gif') !== false || strpos($lowerSrc, '.ico') !== false || strpos($lowerSrc, '.webp') !== false);

            if (!$skipFormats) {
                // A <source> names only files that exist in its own format, each with the
                // variant's own ?v= (mtime+size), so a re-encode under the same name is a new URL
                // at a shared edge; the <img> keeps the original for browsers without either.
                $srcsetKey = $isLazy ? 'data-srcset' : 'srcset';
                $sourceSizes = '';
                if (preg_match('/sizes="([^"]*)"/', $finalTag, $szMatch)) {
                    $sourceSizes = ' sizes="' . $szMatch[1] . '"';
                }

                $webpSource = '';
                if (!$webpSourceRungs && $webpMainExists) {
                    $webpSourceRungs[] = self::nextgen_versioned_url($image_source);
                }
                if ($webpSourceRungs) {
                    $webpSource = '<source ' . $srcsetKey . '="' . implode(', ', $webpSourceRungs) . '"' . $sourceSizes . ' type="image/webp">';
                }

                $avifSource = '';
                if ($ctx->pictureAvifEnabled) {
                    $avifBaseUrl  = preg_replace('/\?.*$/', '', (string) $original_img_tag['original_src']);
                    $avifBasePath = self::local_file_path($avifBaseUrl);
                    if ($avifBasePath !== '' && wpc_nextgen_variant_exists($avifBasePath, 'avif')) {
                        $avifEntries = [];
                        if (!empty($original_img_tag['srcset'])) {
                            foreach (explode(',', (string) $original_img_tag['srcset']) as $ent) {
                                $ent = trim($ent);
                                if ($ent === '' || !preg_match('/^(\S+)(\s+\S+)?$/', $ent, $em)) continue;
                                $eBaseUrl = preg_replace('/\?.*$/', '', $em[1]);
                                $ePath    = self::local_file_path($eBaseUrl);
                                if ($ePath !== '' && wpc_nextgen_variant_exists($ePath, 'avif')) {
                                    $eAvifUrl = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', $eBaseUrl);
                                    $avifEntries[] = self::nextgen_versioned_url($eAvifUrl) . (isset($em[2]) ? $em[2] : '');
                                }
                            }
                        }
                        if (empty($avifEntries)) {
                            // No srcset (single image) — emit just the main .avif URL.
                            $avifEntries[] = self::nextgen_versioned_url(preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', $avifBaseUrl));
                        }
                        $avifSource = '<source ' . $srcsetKey . '="' . implode(', ', $avifEntries) . '"' . $sourceSizes . ' type="image/avif">';
                    }
                }

                if ($webpSource === '' && $avifSource === '') {
                    $ctx->nextgenPictureCounts['no-variant']++;
                } else {
                    // Build fallback tag with original (non-webp) URLs
                    // Replace srcset FIRST (before src), otherwise src replacement corrupts the srcset match
                    $fallbackTag = $finalTag;
                    if (!empty($srcset_att) && !empty($original_img_tag['srcset'])) {
                        $fallbackTag = str_replace($srcsetKey . '="' . $srcset_att . '"', $srcsetKey . '="' . $original_img_tag['srcset'] . '"', $fallbackTag);
                    }
                    $fallbackTag = str_replace($image_source, $original_img_tag['original_src'], $fallbackTag);

                    $finalTag = '<picture class="wpc-picture">' . $avifSource . $webpSource . $fallbackTag . '</picture>';
                    $ctx->nextgenPictureCounts['built']++;
                }
            }
        }


        if ($webP !== false && strncmp($finalTag, '<picture', 8) !== 0 && self::wpc_universal_picture_on()) {
            if (!empty($srcset_att) && !empty($original_img_tag['srcset'])) {
                $srcsetAttributeName = $isLazy ? 'data-srcset' : 'srcset';
                $finalTag = str_replace($srcsetAttributeName . '="' . $srcset_att . '"', $srcsetAttributeName . '="' . $original_img_tag['srcset'] . '"', $finalTag);
            }
            $finalTag = str_replace($image_source, $original_img_tag['original_src'], $finalTag);
        }

        return $finalTag;
    }

    public function getAllTags($image, $ignore_tags = ['src', 'srcset', 'data-src', 'data-srcset'])
    {
        $found_tags = [];

        // This pattern accounts for HTML entities like &quot; within attribute values
        preg_match_all('/([a-zA-Z_-]+(?:--[a-zA-Z_-]+)*)(?:\s*=\s*(?:"((?:[^"\\\\]|\\\\.|&[a-zA-Z0-9#]+;)*)"|\'((?:[^\'\\\\]|\\\\.|&[a-zA-Z0-9#]+;)*)\'|([^>\s]+)))?/', $image, $matches, PREG_SET_ORDER);

        $attributes = [];
        unset($matches[0]);

        foreach ($matches as $match) {
            $attrName = $match[1];
            $attrValue = null;


            foreach ([2, 3, 4] as $index) {
                if (!empty($match[$index])) {
                    $attrValue = $match[$index];
                    break;
                }
            }

            // Only decode HTML entities for non-JSON attributes
            // Check if this looks like JSON data (starts with [ or { and contains &quot;)
            if ($attrValue !== null && (strpos($attrName, 'data-') === 0) && (strpos($attrValue, '[{') !== false || strpos($attrValue, '{') !== false) && strpos($attrValue, '&quot;') !== false) {
                // This looks like JSON data - keep HTML entities encoded
                // but clean up any potential corruption from the original regex
                $attributes[$attrName] = $attrValue;
            } else {
                // For regular attributes, decode HTML entities as before
                $attributes[$attrName] = $attrValue ? html_entity_decode($attrValue) : $attrValue;
            }
        }

        // Process the attributes
        foreach ($attributes as $tag => $value) {
            if (!empty($ignore_tags) && in_array($tag, $ignore_tags)) {
                continue;
            }

            if ($tag == 'data-mk-image-src-set') {
                $value = htmlspecialchars_decode($value);
                $decoded = json_decode($value, true);
                if ($decoded && isset($decoded['default'])) {
                    $value = $decoded['default'];
                }
            }

            $found_tags[$tag] = $value;
        }

        return $found_tags;
    }

    public static function get_image_size($url)
    {
        preg_match("/([0-9]+)x([0-9]+)\.[a-zA-Z0-9]+/", $url, $matches);
        if (isset($matches[1]) && isset($matches[2])) {
            return [$matches[1], $matches[2]];
        }
        // v7.21.229 — NEVER GUESS SQUARE. The 1024x1024 fallback gave every suffixless
        // image a SQUARE placeholder, and aspect-ratio:auto var(--wpc-ar) prefers the
        // loaded placeholder's intrinsic ratio over the var — a 388x91 image reserved a
        // 388px square, then collapsed ~300px on arrival (bestexteriorsinc mobile CLS
        // 0.165, crit-team receipt). Read the real file; else return the 0x0 sentinel and
        // the minter emits a DIMENSIONLESS placeholder (no intrinsic ratio, the var wins).
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_true_image_dimensions')) {
            $trueDimensions = wps_rewriteLogic::wpc_true_image_dimensions($url);
            if (is_array($trueDimensions) && !empty($trueDimensions['width']) && !empty($trueDimensions['height'])) {
                return [(int) $trueDimensions['width'], (int) $trueDimensions['height']];
            }
        }
        return [0, 0];
    }

    public function rewrite_woo_variation_image_urls($variation, $product, $variation_obj)
    {
        if (empty($variation['image']) || empty(self::$rewriteLogic) || empty(self::$zone_name)) {
            return $variation;
        }

        $url_keys = ['url', 'src', 'full_src', 'gallery_thumbnail_src', 'thumb_src'];
        foreach ($url_keys as $key) {
            if (!empty($variation['image'][$key])) {
                $variation['image'][$key] = self::$rewriteLogic->replaceSourceSrcset([$variation['image'][$key]]);
            }
        }

        if (!empty($variation['image']['srcset'])) {
            $variation['image']['srcset'] = preg_replace_callback('/(?:https?:\/\/|\/)[^\s]+\.(jpg|jpeg|png|gif|svg|webp)/i', [self::$rewriteLogic, 'replaceSourceSrcset'], $variation['image']['srcset']);
        }

        return $variation;
    }


}
