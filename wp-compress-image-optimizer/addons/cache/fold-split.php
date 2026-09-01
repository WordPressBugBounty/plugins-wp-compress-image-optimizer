<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cache/fold-split.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.353
 */






















if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_fs_stamper_js660')) {

    
    function wpc_fs_open_tag_re660()
    {
        return '/<(section|main|article|div|aside|nav|footer|header)\b([^>]*?)(\/?)>|<\/(section|main|article|div|aside|nav|footer|header)>/i';
    }

    
    function wpc_fs_container_re660()
    {
        return '/<(section|main|article|div|aside|nav|footer|header)\b[^>]*?(\/?)>|<\/(section|main|article|div|aside|nav|footer|header)>/i';
    }

    
    function wpc_fs_stamper_js660()
    {
        return <<<'WPCFS'
<script id="wpc-fs">(function(){var done=0;
function stamp(){if(done)return;done=1;
 try{var ts=document.querySelectorAll("template.wpc-rest");
  for(var i=0;i<ts.length;i++){var t=ts[i];if(t.content){t.parentNode.replaceChild(t.content,t);}else{var d=document.createElement("div");d.innerHTML=t.innerHTML;while(d.firstChild)t.parentNode.insertBefore(d.firstChild,t);t.parentNode.removeChild(t);}}
  document.dispatchEvent(new CustomEvent("wpc:rest-loaded"));
 }catch(e){try{navigator.sendBeacon&&navigator.sendBeacon("/wpc-fs-beacon","stamp:"+(e&&e.message||e))}catch(_){}}}
function splitVisible(){try{var t=document.querySelector("template.wpc-rest");if(!t)return true;
 var p=t.previousElementSibling;if(!p)return true;
 return p.getBoundingClientRect().bottom < innerHeight + 50;}catch(e){return true}}
if(location.hash||splitVisible())stamp();
else{
 if("requestIdleCallback" in window){requestIdleCallback(stamp,{timeout:1500})}else{setTimeout(stamp,800)}
 ["scroll","pointerdown","keydown","touchstart","wheel"].forEach(function(e){addEventListener(e,stamp,{once:true,passive:true})});
 addEventListener("beforeprint",stamp);addEventListener("pageshow",function(ev){if(ev.persisted)stamp()});
 setTimeout(stamp,2500);
}})();</script>
WPCFS;
    }

    
    function wpc_fs_in_ranges660($pos, $ranges)
    {
        foreach ($ranges as $r) {
            if ($pos > $r[0] && $pos < $r[1]) {
                return true;
            }
        }
        return false;
    }

    
    function wpc_fs_forbidden_ranges660($html)
    {
        $out = [];
        $push = function ($re) use ($html, &$out) {
            if (preg_match_all($re, $html, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as $m) {
                    $out[] = [$m[1], $m[1] + strlen($m[0])];
                }
            }
        };
        $push('/<script\b.*?<\/script>/is');
        $push('/<style\b.*?<\/style>/is');
        $push('/<textarea\b.*?<\/textarea>/is');
        $push('/<!--.*?-->/is');
        $push('/<pre\b.*?<\/pre>/is');
        usort($out, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        return $out;
    }

    
    function wpc_fs_fragment_balance660($fragment)
    {
        $forbidden = wpc_fs_forbidden_ranges660($fragment);
        $depth = 0;
        $minDepth = 0;
        if (preg_match_all(wpc_fs_container_re660(), $fragment, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $index = $m[0][1];
                if (wpc_fs_in_ranges660($index, $forbidden)) {
                    continue;
                }
                $g2 = isset($m[2]) && $m[2][1] !== -1 ? $m[2][0] : '';
                $g3 = isset($m[3]) && $m[3][1] !== -1 ? $m[3][0] : '';
                if ($g3 !== '') {                       
                    $depth--;
                    if ($depth < $minDepth) {
                        $minDepth = $depth;
                    }
                } elseif ($g2 !== '/') {                
                    $depth++;
                }
            }
        }
        return ['depth' => $depth, 'minDepth' => $minDepth, 'balanced' => ($depth === 0 && $minDepth === 0)];
    }

    
    function wpc_fs_template_segments660($fragment)
    {
        if (!is_string($fragment) || $fragment === '') {
            return ['ok' => false, 'reason' => 'no_fragment'];
        }
        $forbidden = wpc_fs_forbidden_ranges660($fragment);
        $depth = 0;
        $orphans = [];
        if (preg_match_all(wpc_fs_open_tag_re660(), $fragment, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $index = $m[0][1];
                if (wpc_fs_in_ranges660($index, $forbidden)) {
                    continue;
                }
                $g3 = isset($m[3]) && $m[3][1] !== -1 ? $m[3][0] : '';
                $g4 = isset($m[4]) && $m[4][1] !== -1 ? $m[4][0] : '';
                if ($g4 !== '') {                       
                    if ($depth === 0) {
                        $orphans[] = ['at' => $index, 'end' => $index + strlen($m[0][0])];
                    } else {
                        $depth--;
                    }
                    continue;
                }
                if ($g3 !== '/') {                      
                    $depth++;
                }
            }
        }
        if ($depth !== 0) {
            return ['ok' => false, 'reason' => 'fragment_leaves_open'];
        }
        $parts = [];
        $pos = 0;
        foreach ($orphans as $o) {
            if ($o['at'] > $pos) {
                $parts[] = ['tpl' => substr($fragment, $pos, $o['at'] - $pos)];
            }
            $parts[] = ['raw' => substr($fragment, $o['at'], $o['end'] - $o['at'])];
            $pos = $o['end'];
        }
        if ($pos < strlen($fragment)) {
            $parts[] = ['tpl' => substr($fragment, $pos)];
        }
        foreach ($parts as $p) {
            if (!array_key_exists('tpl', $p)) {
                continue;
            }
            $b = wpc_fs_fragment_balance660($p['tpl']);
            if (!$b['balanced']) {
                return ['ok' => false, 'reason' => 'segment_unbalanced'];
            }
        }
        return ['ok' => true, 'parts' => $parts, 'orphan_count' => count($orphans)];
    }

    
    
    function wpc_fs_build_template_document660($html, $plan)
    {
        if (!is_string($html) || empty($plan) || empty($plan['ok']) || ($plan['mode'] ?? '') !== 'template') {
            return null;
        }
        if (strpos($html, 'template class="wpc-rest"') !== false || strpos($html, 'id="wpc-fs"') !== false) {
            return null;                                
        }
        $bodyEnd = strrpos($html, '</body>');
        if ($bodyEnd === false || $plan['offset'] >= $bodyEnd) {
            return null;
        }
        $seg = wpc_fs_template_segments660(substr($html, $plan['offset'], $bodyEnd - $plan['offset']));
        if (empty($seg['ok'])) {
            return null;
        }
        if ($seg['orphan_count'] !== $plan['orphan_count']) {
            return null;                                
        }
        $tpls = 0;
        foreach ($seg['parts'] as $p) {
            if (array_key_exists('tpl', $p) && trim($p['tpl']) !== '') {
                $tpls++;
            }
        }
        if ($tpls !== $plan['templates']) {
            return null;
        }
        $wrapped = '';
        foreach ($seg['parts'] as $p) {
            if (array_key_exists('raw', $p)) {
                $wrapped .= $p['raw'];
            } elseif (trim($p['tpl']) !== '') {
                $wrapped .= '<template class="wpc-rest">' . $p['tpl'] . '</template>';
            } else {
                $wrapped .= $p['tpl'];
            }
        }
        return [
            'doc' => substr($html, 0, $plan['offset']) . $wrapped . wpc_fs_stamper_js660() . substr($html, $bodyEnd),
            'templates' => $tpls,
            'orphans' => $seg['orphan_count'],
        ];
    }

    
    
    

    function wpc_fs_artifact_key660()
    {
        
        
        $host = strtolower((string) preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : ''));
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
        $path = (string) (parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH) ?: '/');
        return md5($host . $path);
    }

    function wpc_fs_fetch_artifact660()
    {
        if (!function_exists('get_transient')) {
            return [];
        }
        $key = wpc_fs_artifact_key660();
        $tk = 'wpc_fs_art_' . $key;
        $cached = get_transient($tk);
        if ($cached !== false) {                        
            return is_array($cached) ? $cached : [];
        }
        $base = (string) apply_filters('wpc_fs_artifact_base', 'https://critical-css-mc.b-cdn.net/foldsplit/');
        $resp = wp_remote_get($base . $key . '.json', ['timeout' => (int) apply_filters('wpc_fs_fetch_timeout', 3)]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            set_transient($tk, 'none', (int) apply_filters('wpc_fs_miss_ttl', 600));   
            return [];
        }
        $art = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($art) || (int) ($art['v'] ?? 0) !== 2 || ($art['mode'] ?? '') !== 'template' || empty($art['anchor'])) {
            set_transient($tk, 'none', (int) apply_filters('wpc_fs_miss_ttl', 600));
            return [];
        }
        set_transient($tk, $art, (int) apply_filters('wpc_fs_hit_ttl', 3600));
        return $art;
    }

    
    
    
    
    
    function wpc_fs_enabled660()
    {
        $opt = false;
        if (function_exists('get_option')) {
            $s = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
            $opt = is_array($s) && isset($s['fold-split']) && (string) $s['fold-split'] === '1';
        }
        return (bool) apply_filters('wpc_fold_split', $opt);
    }

    function wpc_fs_maybe_wrap660($buffer)
    {
        try {
            if (!wpc_fs_enabled660()) {
                return $buffer;                         
            }
            if (!is_string($buffer) || $buffer === ''
                || strpos($buffer, 'template class="wpc-rest"') !== false
                || strpos($buffer, 'id="wpc-fs"') !== false) {
                return $buffer;
            }
            $art = wpc_fs_fetch_artifact660();
            if (empty($art) || empty($art['anchor'])) {
                return $buffer;
            }
            $anchor = (string) $art['anchor'];
            $offset = strpos($buffer, $anchor);
            if ($offset === false || strpos($buffer, $anchor, $offset + 1) !== false) {
                return $buffer;                         
            }
            $r = wpc_fs_build_template_document660($buffer, [
                'ok'           => true,
                'mode'         => 'template',
                'offset'       => $offset,               
                'orphan_count' => (int) ($art['orphan_count'] ?? -1),
                'templates'    => (int) ($art['templates'] ?? -1),
            ]);
            return ($r !== null && !empty($r['doc'])) ? $r['doc'] : $buffer;
        } catch (\Throwable $e) {
            return $buffer;                             
        }
    }
}
