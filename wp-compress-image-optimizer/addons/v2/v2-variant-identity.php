<?php
/**
 * Is a variant a picture of the file it stands for? The one owner of that question.
 *
 * A next-gen sibling ({base}.avif / {base}.webp) or an in-place jpeg/png replaces the file the
 * page links; the bytes may not be a picture of that file at all. The write doors ask here
 * before landing (wpc_v2_store_bytes, the lazy-CDN ingest) and the sweep asks again of what
 * already landed. The answer is one of three:
 *
 *   match       the picture is the file's (a 16x16 luminance fingerprint within the threshold under
 *               one of the eight orientations, or an exact source hash when nothing can decode)
 *   mismatch    a different picture or a different shape: aspect differs, or the fingerprint is
 *               farther than the threshold. Only this refuses or deletes.
 *   unverified  the question cannot be answered here: no source on disk, a decoder missing, an
 *               image over the pixel or memory guard, a CMYK source, a transparent source against
 *               a flattened variant. A variant that is unverified lands as before.
 *
 * Observed failure (www.warriortreeservicessc.com, ticket 12192, 2026-10-02): three gallery
 * images showed another site's photos. The AVIF siblings this plugin had written beside the
 * originals were a different picture (3,4: 2026-09-15, 5: 2026-10-02); the CDN's fresh encode of
 * the same PNG was right and delivery preferred the site's own copy. Nothing between the service
 * and the disk asked whether the bytes were a picture of the file.
 *
 * A SUB-SIZE WORDPRESS GENERATED (a `-WxH` name with a file at that stem that has those dimensions and an image it was
 * cut from beside it) is held to a stricter rule than any other destination: the variant has that file's
 * dimensions (within a pixel of rounding), and is the same crop of the picture, which is a fingerprint distance of
 * wpc_v2_variant_identity_size_threshold() at most, in the file's own orientation. WordPress decides the shape of its
 * sizes; a variant of another shape or another crop is never a better copy of that file, whatever format it is in.
 *
 * FIELDS THE SERVICE MAY SEND WITH ANY LANDING (bg_swap single and batch, pull-manifest and
 * lazy-CDN entries, direct entry, Phase A parents; an announce lands nothing and is not read).
 * Both are optional; one that is malformed is ignored, and neither is trusted for anything but
 * what is written here.
 *
 *   variantGrid (alias variant_grid) -- the 16x16 luminance grid of the variant being sent, for a
 *     server that cannot decode it (AVIF on PHP below 8.1, or without libavif).
 *       encoding   standard base64 (RFC 4648 with padding) of EXACTLY 256 bytes: 16 rows of 16
 *                  bytes, row-major, row 0 = top of the picture, column 0 = left; any other length
 *                  or a non-base64 string is ignored.
 *       pixels     decode the variant to 8-bit sRGB(A) as it is sent (EXIF orientation not applied:
 *                  the grid is of the pixels as stored); composite any alpha onto opaque WHITE
 *                  (255,255,255).
 *       resample   the WHOLE picture to 16x16, no crop and no aspect preservation: cell (row r,
 *                  column c) is the area average (box filter) of the pixels its rectangle
 *                  [c*W/16, (c+1)*W/16) x [r*H/16, (r+1)*H/16) covers, edge pixels weighted by the
 *                  fraction covered, on the 8-bit values (no gamma conversion). Reference: GD
 *                  imagecopyresampled onto a 16x16 truecolor image filled white (wpc_v2_variant_identity_gd_grid).
 *                  It must average the whole area: bilinear, bicubic or nearest sampling of a large
 *                  reduction reads sparse pixels and lands 20 to 40 levels away on a 768 px photo
 *                  (measured), which the receiver reads as a different picture. Check an
 *                  implementation against the reference: a mean absolute difference of 3 or less on
 *                  photographs is fine, the receiver's threshold is 15.
 *       luma       byte = round(0.299 * R + 0.587 * G + 0.114 * B) of the resampled cell, 0 to 255.
 *     Used only when the variant cannot be decoded here and the local file it stands for can: the
 *     grid is compared with that file's own grid under the eight orientations (WordPress rotates
 *     sub-sizes by EXIF, the grid is of the stored pixels); farther than the threshold refuses the
 *     landing (settled, as any mismatch), within it accepts. When the variant can be decoded here
 *     the grid is ignored and our own decode wins; a grid that disagrees with our decode beyond the
 *     threshold leaves a `variant-grid-disagrees` receipt (the service's grid is wrong).
 *
 *   sourceSha256 (alias source_sha256), sourceSize (alias source_bytes) -- sha256 (hex) and byte
 *     length of the source file the variant was encoded from. A hash that equals a local candidate
 *     (the file the name stands for, or its parent) settles a variant nothing could decode. It
 *     never refuses and never overrules a decode.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_variant_identity')) {

    /**
     * The fingerprint distance (mean absolute luminance difference of the 16x16 grids, 0 to 255)
     * above which a variant is a different picture. Measured 2026-10-02 on the ticket's own files:
     * the right pairs read 0.14 to 0.19, the wrong ones 43.5 to 60.5 (best of eight orientations),
     * and the nearest two different photos of that site 28.1.
     */
    function wpc_v2_variant_identity_threshold()
    {
        $t = 15.0;
        return function_exists('apply_filters') ? (float) apply_filters('wpc_variant_identity_threshold', $t) : $t;
    }

    /**
     * The fingerprint distance above which a variant of a sub-size WordPress generated is another crop of the
     * picture. Measured 2026-10-03: the service's own WebP and AVIF of the same crop read 0.15 to 0.45 against
     * WordPress's file; this plugin's re-encodes of one crop (q20 to q90, JPEG, WebP, AVIF, 100 to 1000 px) read
     * at most 3.8; a crop moved 5 % of the image reads 11.0 and over, the whole picture squeezed into a square 17
     * and over.
     */
    function wpc_v2_variant_identity_size_threshold()
    {
        $t = 6.0;
        return function_exists('apply_filters') ? (float) apply_filters('wpc_variant_identity_size_threshold', $t) : $t;
    }

    /** Pixels a variant's width or height may differ from the dimensions of the WordPress file it replaces (rounding: 683.4 px is 683 or 684). */
    function wpc_v2_variant_identity_size_slack()
    {
        return 1;
    }

    /** Pixels above which an image is not decoded here (the memory guard's hard ceiling). */
    function wpc_v2_variant_identity_max_pixels()
    {
        return 25000000;
    }

    /** Image extensions the check applies to. */
    function wpc_v2_variant_identity_exts()
    {
        return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
    }

    /** The kill switch: the filter answers false and every variant reads unverified. */
    function wpc_v2_variant_identity_on()
    {
        return function_exists('apply_filters') ? (bool) apply_filters('wpc_variant_identity_on', true) : true;
    }

    /**
     * What this server can decode, probed once per request by a real round trip (a 2x2 image
     * through the encoder and back through the decoder), never by a function name alone: GD decodes
     * AVIF only from PHP 8.1 on a libavif build, and Imagick only with a heif delegate.
     * Answers ['gd', 'avif', 'webp', 'imagick'] booleans.
     */
    function wpc_v2_variant_identity_decoders()
    {
        static $caps = null;
        if ($caps !== null) {
            return $caps;
        }
        $caps = ['gd' => false, 'avif' => false, 'webp' => false, 'imagick' => false];
        try {
            if (function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor')) {
                $caps['gd'] = true;
                foreach (['webp' => 'imagewebp', 'avif' => 'imageavif'] as $format => $encoder) {
                    if (!function_exists($encoder)) {
                        continue;
                    }
                    $probe = @imagecreatetruecolor(2, 2);
                    if (!$probe) {
                        continue;
                    }
                    ob_start();
                    $wrote = @$encoder($probe, null, 60);
                    $blob = (string) ob_get_clean();
                    wpc_v2_variant_identity_free($probe);
                    if ($wrote && $blob !== '') {
                        $back = @imagecreatefromstring($blob);
                        if ($back) {
                            $caps[$format] = true;
                            wpc_v2_variant_identity_free($back);
                        }
                    }
                }
            }
            if (class_exists('Imagick')) {
                $caps['imagick'] = true;
                $formats = (array) @Imagick::queryFormats('*');
                if (in_array('AVIF', $formats, true) || in_array('HEIC', $formats, true)) {
                    $caps['avif'] = true;
                }
                if (in_array('WEBP', $formats, true)) {
                    $caps['webp'] = true;
                }
            }
        } catch (\Throwable $e) {
            return $caps;
        }
        return $caps;
    }

    /** The decoders as the request sees them: the probe, then the filter an operator (or a test) uses to take one away. */
    function wpc_v2_variant_identity_decoders_seen()
    {
        $caps = wpc_v2_variant_identity_decoders();
        return function_exists('apply_filters') ? (array) apply_filters('wpc_variant_identity_decoders', $caps) + $caps : $caps;
    }

    /** Frees a GD image on the runtimes where it is a resource (PHP 8 frees the object itself). */
    function wpc_v2_variant_identity_free(&$image)
    {
        if (PHP_VERSION_ID < 80000 && is_resource($image) && function_exists('imagedestroy')) {
            @imagedestroy($image);
        }
        $image = null;
    }

    /** The format a byte string says it is: avif, webp, jpeg, png, gif or ''. */
    function wpc_v2_variant_identity_format($bytes)
    {
        $head = substr((string) $bytes, 0, 16);
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpeg';
        }
        if (strncmp($head, "\x89PNG", 4) === 0) {
            return 'png';
        }
        if (strncmp($head, 'GIF8', 4) === 0) {
            return 'gif';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['avif', 'avis'], true)) {
            return 'avif';
        }
        return '';
    }

    /** memory_limit in bytes; 0 when unlimited. */
    function wpc_v2_variant_identity_memory_limit()
    {
        $raw = trim((string) @ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }
        $n = (float) $raw;
        $unit = strtolower(substr($raw, -1));
        if ($unit === 'g') {
            $n *= 1073741824;
        } elseif ($unit === 'm') {
            $n *= 1048576;
        } elseif ($unit === 'k') {
            $n *= 1024;
        }
        return (int) $n;
    }

    /** Whether one decoded image of $w x $h fits in what the request has left. */
    function wpc_v2_variant_identity_fits($w, $h)
    {
        $limit = wpc_v2_variant_identity_memory_limit();
        if ($limit <= 0) {
            return true;
        }
        $need = ((int) $w * (int) $h * 6) + 4194304;
        return $need <= ($limit - (int) memory_get_usage());
    }

    /** The 16x16 luminance grid of a GD image composited on a solid grey: 256 floats, row major. */
    function wpc_v2_variant_identity_gd_grid($image, $background)
    {
        $cell = @imagecreatetruecolor(16, 16);
        if (!$cell) {
            return null;
        }
        imagealphablending($cell, true);
        imagefill($cell, 0, 0, imagecolorallocate($cell, $background, $background, $background));
        imagecopyresampled($cell, $image, 0, 0, 0, 0, 16, 16, imagesx($image), imagesy($image));
        $grid = [];
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $c = imagecolorat($cell, $x, $y);
                $grid[] = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
            }
        }
        wpc_v2_variant_identity_free($cell);
        return $grid;
    }

    /** The grids of a GD image on white, black and mid grey (the last only matters for a transparent source). */
    function wpc_v2_variant_identity_gd_print($image)
    {
        if (!imageistruecolor($image) && function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $grids = [];
        foreach ([255, 0, 128] as $bg) {
            $g = wpc_v2_variant_identity_gd_grid($image, $bg);
            if ($g === null) {
                return null;
            }
            $grids[$bg] = $g;
        }
        return ['w' => $w, 'h' => $h, 'g' => $grids];
    }

    /** The same grids through Imagick, for what GD cannot decode. */
    function wpc_v2_variant_identity_imagick_print($bytes)
    {
        $im = new Imagick();
        try {
            $im->pingImageBlob($bytes);
            $w = (int) $im->getImageWidth();
            $h = (int) $im->getImageHeight();
            $im->clear();
            if ($w <= 0 || $h <= 0) {
                return ['ok' => false, 'reason' => 'undecodable'];
            }
            if ($w * $h > wpc_v2_variant_identity_max_pixels()) {
                return ['ok' => false, 'reason' => 'large'];
            }
            if (!wpc_v2_variant_identity_fits($w, $h)) {
                return ['ok' => false, 'reason' => 'memory'];
            }
            $im->readImageBlob($bytes);
            $im->setFirstIterator();
            $frame = $im->getImage();
            $grids = [];
            foreach ([255, 0, 128] as $bg) {
                $flat = clone $frame;
                $flat->setImageBackgroundColor(new ImagickPixel('rgb(' . $bg . ',' . $bg . ',' . $bg . ')'));
                $flat = $flat->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $flat->resizeImage(16, 16, Imagick::FILTER_BOX, 1);
                $px = $flat->exportImagePixels(0, 0, 16, 16, 'RGB', Imagick::PIXEL_CHAR);
                $flat->clear();
                $grid = [];
                for ($i = 0; $i + 2 < count($px); $i += 3) {
                    $grid[] = 0.299 * $px[$i] + 0.587 * $px[$i + 1] + 0.114 * $px[$i + 2];
                }
                if (count($grid) !== 256) {
                    return ['ok' => false, 'reason' => 'undecodable'];
                }
                $grids[$bg] = $grid;
            }
            $frame->clear();
            return ['ok' => true, 'w' => $w, 'h' => $h, 'g' => $grids];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'undecodable'];
        } finally {
            $im->clear();
        }
    }

    /**
     * The fingerprint of a byte string: ['ok' => true, 'w', 'h', 'g' => [255 => grid, 0 => grid,
     * 128 => grid]] or ['ok' => false, 'reason' => ...] where it cannot be taken.
     */
    function wpc_v2_variant_identity_print($bytes)
    {
        $bytes = (string) $bytes;
        $format = wpc_v2_variant_identity_format($bytes);
        if ($format === '') {
            return ['ok' => false, 'reason' => 'undecodable'];
        }
        $caps = wpc_v2_variant_identity_decoders_seen();
        $info = @getimagesizefromstring($bytes);
        $w = is_array($info) ? (int) $info[0] : 0;
        $h = is_array($info) ? (int) $info[1] : 0;
        if ($w > 0 && $h > 0) {
            if ($w * $h > wpc_v2_variant_identity_max_pixels()) {
                return ['ok' => false, 'reason' => 'large'];
            }
            if ($format === 'jpeg' && isset($info['channels']) && (int) $info['channels'] === 4) {
                return ['ok' => false, 'reason' => 'cmyk'];
            }
            if (!wpc_v2_variant_identity_fits($w, $h)) {
                return ['ok' => false, 'reason' => 'memory'];
            }
            $gd_decodes = $caps['gd'] && ($format === 'avif' ? $caps['avif'] : ($format === 'webp' ? $caps['webp'] : true));
            if ($gd_decodes) {
                $image = @imagecreatefromstring($bytes);
                if ($image) {
                    $print = wpc_v2_variant_identity_gd_print($image);
                    wpc_v2_variant_identity_free($image);
                    if ($print !== null) {
                        return ['ok' => true] + $print;
                    }
                }
            }
        }
        if ($caps['imagick'] && class_exists('Imagick')) {
            return wpc_v2_variant_identity_imagick_print($bytes);
        }
        return ['ok' => false, 'reason' => ($format === 'avif' || $format === 'webp') && !$caps[$format] ? 'no-decoder' : 'undecodable'];
    }

    /** One grid under one of the eight orientations (k: low two bits are quarter turns, bit 2 a mirror first). */
    function wpc_v2_variant_identity_orient(array $grid, $k)
    {
        $rows = [];
        for ($y = 0; $y < 16; $y++) {
            $rows[$y] = array_slice($grid, $y * 16, 16);
            if ($k >> 2) {
                $rows[$y] = array_reverse($rows[$y]);
            }
        }
        for ($turn = 0; $turn < ($k & 3); $turn++) {
            $next = [];
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $next[$y][$x] = $rows[15 - $x][$y];
                }
            }
            $rows = $next;
        }
        $out = [];
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $out[] = $rows[$y][$x];
            }
        }
        return $out;
    }

    function wpc_v2_variant_identity_mad(array $a, array $b)
    {
        $sum = 0.0;
        foreach ($a as $i => $v) {
            $sum += abs($v - $b[$i]);
        }
        return $sum / count($a);
    }

    /** Whether a print has meaningful transparency (its white and black composites differ). */
    function wpc_v2_variant_identity_has_alpha(array $print)
    {
        return wpc_v2_variant_identity_mad($print['g'][255], $print['g'][0]) > 1.5;
    }

    /**
     * Compares two prints: the source's and the variant's. $strict says the source is the file the
     * variant's own name stands for; a source inferred from the parent's name (non-strict) can be a
     * crop, so a differing aspect there is unverified, never a mismatch. $size says the source is a
     * sub-size WordPress generated: the picture is compared in the file's own orientation against
     * wpc_v2_variant_identity_size_threshold(), and a picture beyond it is a mismatch with reason `size-crop`.
     * Answers ['verdict', 'reason', 'distance'].
     */
    function wpc_v2_variant_identity_compare(array $source, array $variant, $strict, $size = false)
    {
        $threshold = $size ? wpc_v2_variant_identity_size_threshold() : wpc_v2_variant_identity_threshold();
        $source_ar = $source['w'] / max(1, $source['h']);
        $variant_ar = $variant['w'] / max(1, $variant['h']);
        $src_alpha = wpc_v2_variant_identity_has_alpha($source);
        $var_alpha = wpc_v2_variant_identity_has_alpha($variant);
        $best = null;
        for ($k = 0; $k < 8; $k++) {
            if ($size && $k !== 0) {
                continue;
            }
            $ar = ($k & 1) ? 1 / $source_ar : $source_ar;
            if (abs($variant_ar / $ar - 1) > 0.03) {
                continue;
            }
            $oriented = [];
            foreach ([255, 0, 128] as $bg) {
                $oriented[$bg] = wpc_v2_variant_identity_orient($source['g'][$bg], $k);
            }
            foreach ([255, 0] as $bg) {
                $d = wpc_v2_variant_identity_mad($oriented[$bg], $variant['g'][$bg]);
                $best = $best === null ? $d : min($best, $d);
            }
            if ($src_alpha && !$var_alpha) {
                $d = wpc_v2_variant_identity_mad($oriented[128], $variant['g'][255]);
                $best = min($best, $d);
            }
        }
        if ($best === null) {
            return $strict
                ? ['verdict' => 'mismatch', 'reason' => 'aspect', 'distance' => null]
                : ['verdict' => 'unverified', 'reason' => 'aspect-differs', 'distance' => null];
        }
        $best = round($best, 2);
        if ($best <= $threshold) {
            return ['verdict' => 'match', 'reason' => 'picture', 'distance' => $best];
        }
        if ($src_alpha && !$var_alpha) {
            return ['verdict' => 'unverified', 'reason' => 'alpha-flattened', 'distance' => $best];
        }
        return ['verdict' => 'mismatch', 'reason' => $size ? 'size-crop' : 'picture', 'distance' => $best];
    }

    /**
     * The file WordPress generated for the sub-size a destination names, when it is on disk: $dest ends
     * `-WxH.ext`, a file at that stem has those dimensions (within the slack) and the image it was cut from
     * (`{base}` or `{base}-scaled`) is beside it. Where that image is a jpg, jpeg, png or gif the file is the one
     * of that kind at the stem; where it is a webp or avif (and no other kind is beside it: a webp beside a png is
     * this plugin's bare-name alias) the one of that kind. Answers ['path', 'w', 'h'] (the dimensions as the file
     * has them) or null.
     */
    function wpc_v2_variant_identity_wp_size($dest)
    {
        $dest = (string) $dest;
        $ext = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
        if ($ext === '') {
            return null;
        }
        $stem = substr($dest, 0, -(strlen($ext) + 1));
        if (!preg_match('/-(\d+)x(\d+)$/', $stem, $name)) {
            return null;
        }
        $bases = [substr($stem, 0, -strlen($name[0]))];
        $bases[] = $bases[0] . '-scaled';
        $raster = ['jpg', 'jpeg', 'png', 'gif', 'JPG', 'JPEG', 'PNG', 'GIF'];
        $parents = [];
        foreach (array_merge($raster, ['webp', 'avif']) as $e) {
            foreach ($bases as $base) {
                if (!isset($parents[$e]) && @is_file($base . '.' . $e) && $base . '.' . $e !== $dest) {
                    $parents[$e] = $base . '.' . $e;
                }
            }
        }
        if (empty($parents)) {
            return null;
        }
        $slack = wpc_v2_variant_identity_size_slack();
        $candidates = array_intersect($raster, array_keys($parents)) ? $raster : array_keys($parents);
        foreach ($candidates as $e) {
            $path = $stem . '.' . $e;
            $info = @is_file($path) ? @getimagesize($path) : false;
            if (is_array($info) && abs((int) $info[0] - (int) $name[1]) <= $slack && abs((int) $info[1] - (int) $name[2]) <= $slack) {
                $parent = reset($parents);
                foreach ($raster as $pe) {
                    if (@is_file($bases[1] . '.' . $pe)) {
                        $parent = $bases[1] . '.' . $pe;
                        break;
                    }
                }
                return ['path' => $path, 'w' => (int) $info[0], 'h' => (int) $info[1], 'crop' => wpc_v2_variant_identity_is_crop((int) $info[0], (int) $info[1], $parent)];
            }
        }
        return null;
    }

    /** Whether $w x $h is not the aspect of an $image_w x $image_h image, within the size slack either way: a crop, not a fit. */
    function wpc_v2_aspect_is_crop($w, $h, $image_w, $image_h)
    {
        $w = (int) $w; $h = (int) $h; $image_w = (int) $image_w; $image_h = (int) $image_h;
        if ($w <= 0 || $h <= 0 || $image_w <= 0 || $image_h <= 0) {
            return false;
        }
        $slack = wpc_v2_variant_identity_size_slack();
        return abs($h - (int) round($w * $image_h / $image_w)) > $slack && abs($w - (int) round($h * $image_w / $image_h)) > $slack;
    }

    /** The EXIF orientation of a JPEG file (1 to 8), read from its APP1 segment; 1 when there is none. */
    function wpc_v2_variant_identity_jpeg_orientation($path)
    {
        $fh = @fopen((string) $path, 'rb');
        if (!$fh) {
            return 1;
        }
        $head = (string) fread($fh, 65536);
        fclose($fh);
        if (substr($head, 0, 2) !== "\xFF\xD8") {
            return 1;
        }
        $at = 2;
        $len = strlen($head);
        while ($at + 4 <= $len && $head[$at] === "\xFF") {
            $marker = ord($head[$at + 1]);
            $size = unpack('n', substr($head, $at + 2, 2));
            $size = (int) $size[1];
            if ($marker === 0xE1 && substr($head, $at + 4, 6) === "Exif\0\0") {
                $tiff = (string) substr($head, $at + 10, max(0, $size - 8));
                $le = substr($tiff, 0, 2) === 'II';
                $u16 = function ($o) use ($tiff, $le) { $b = (string) substr($tiff, $o, 2); if (strlen($b) !== 2) { return 0; } $v = unpack($le ? 'v' : 'n', $b); return (int) $v[1]; };
                $u32 = function ($o) use ($tiff, $le) { $b = (string) substr($tiff, $o, 4); if (strlen($b) !== 4) { return 0; } $v = unpack($le ? 'V' : 'N', $b); return (int) $v[1]; };
                $ifd = $u32(4);
                $n = $u16($ifd);
                for ($i = 0; $i < $n && $i < 64; $i++) {
                    if ($u16($ifd + 2 + $i * 12) === 0x0112) {
                        $o = $u16($ifd + 2 + $i * 12 + 8);
                        return ($o >= 1 && $o <= 8) ? $o : 1;
                    }
                }
                return 1;
            }
            if ($marker === 0xDA || $size < 2) {
                break;
            }
            $at += 2 + $size;
        }
        return 1;
    }

    /** Whether a sub-size of $w x $h is a crop of the image it was cut from ($parent, as displayed: EXIF orientation 5 to 8 turns it): true, false, or null when the image's dimensions cannot be read. */
    function wpc_v2_variant_identity_is_crop($w, $h, $parent)
    {
        $info = @getimagesize((string) $parent);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return null;
        }
        $pw = (int) $info[0];
        $ph = (int) $info[1];
        if (isset($info[2]) && (int) $info[2] === IMAGETYPE_JPEG && wpc_v2_variant_identity_jpeg_orientation($parent) >= 5) {
            list($pw, $ph) = [$ph, $pw];
        }
        return wpc_v2_aspect_is_crop($w, $h, $pw, $ph);
    }

    /** The width and height of every `ispe` box in an AVIF header: [[w, h], ...], or [] (not an AVIF, or a rotated one). */
    function wpc_v2_variant_identity_avif_dims($bytes)
    {
        $head = substr((string) $bytes, 0, 65536);
        if (substr($head, 4, 4) !== 'ftyp' || strpos($head, 'irot') !== false) {
            return [];
        }
        $dims = [];
        $at = 0;
        while (count($dims) < 16 && ($at = strpos($head, 'ispe', $at)) !== false) {
            if ($at + 16 <= strlen($head)) {
                $wh = unpack('Nw/Nh', substr($head, $at + 8, 8));
                if (!empty($wh['w']) && !empty($wh['h'])) {
                    $dims[] = [(int) $wh['w'], (int) $wh['h']];
                }
            }
            $at += 4;
        }
        return $dims;
    }

    /**
     * Whether the variant bytes have the dimensions of the WordPress file ($size: ['w', 'h'] as
     * wpc_v2_variant_identity_wp_size() answers them), read from the header without decoding. Answers null
     * when they do (or when the header cannot be read: that is no evidence), or the mismatch verdict with
     * reason `size-shape`.
     */
    function wpc_v2_variant_identity_shape($bytes, array $size)
    {
        $info = @getimagesizefromstring((string) $bytes);
        $dims = (is_array($info) && !empty($info[0]) && !empty($info[1])) ? [[(int) $info[0], (int) $info[1]]] : wpc_v2_variant_identity_avif_dims($bytes);
        if (empty($dims)) {
            return null;
        }
        $slack = wpc_v2_variant_identity_size_slack();
        foreach ($dims as $d) {
            if (abs($d[0] - (int) $size['w']) <= $slack && abs($d[1] - (int) $size['h']) <= $slack) {
                return null;
            }
        }
        return ['verdict' => 'mismatch', 'reason' => 'size-shape', 'distance' => null];
    }

    /**
     * Whether WordPress cut the sub-size $label out of its image (a hard crop) rather than scaling the image to fit
     * a box: the envelope's `crop` for that size. The registered size says (wp_get_registered_image_subsizes(), where
     * the thumbnail's is the thumbnail_crop option), and so does the file already generated: an entry whose width and
     * height are not the image's aspect was cropped, whatever the size is registered as now. $info is the entry of
     * the attachment metadata's `sizes`, $meta the metadata.
     */
    function wpc_v2_subsize_crop($label, array $info, $meta)
    {
        $registered = function_exists('wp_get_registered_image_subsizes') ? (array) wp_get_registered_image_subsizes() : [];
        if (isset($registered[(string) $label]) && !empty($registered[(string) $label]['crop'])) {
            return true;
        }
        $w = isset($info['width']) ? (int) $info['width'] : 0;
        $h = isset($info['height']) ? (int) $info['height'] : 0;
        $image_w = is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : 0;
        $image_h = is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : 0;
        if ($w <= 0 || $h <= 0 || $image_w <= 0 || $image_h <= 0) {
            return false;
        }
        return wpc_v2_aspect_is_crop($w, $h, $image_w, $image_h);
    }

    /**
     * Files a variant at $dest may stand for, from the destination path alone (no WordPress): the
     * same-name file of another extension, the file itself for an in-place jpeg/png, and, for a
     * -WxH name with no file of its own, the parent file the name derives from. A sub-size WordPress
     * generated (wpc_v2_variant_identity_wp_size) is always the strict source of its own name and carries
     * its dimensions as `size`. Answers ['path' => ..., 'strict' => bool, 'size'? => ['w', 'h']] or null.
     */
    function wpc_v2_variant_identity_source($dest)
    {
        $dest = (string) $dest;
        $ext = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
        if ($ext === '') {
            return null;
        }
        $wp = wpc_v2_variant_identity_wp_size($dest);
        if ($wp !== null) {
            return ['path' => $wp['path'], 'strict' => true, 'size' => ['w' => $wp['w'], 'h' => $wp['h']], 'crop' => !empty($wp['crop'])];
        }
        $rival = function_exists('wpc_v2_variant_disk_rival') ? wpc_v2_variant_disk_rival($dest) : '';
        if ($rival !== '') {
            return ['path' => $rival, 'strict' => true];
        }
        $stem = substr($dest, 0, -(strlen($ext) + 1));
        foreach (['JPG', 'JPEG', 'PNG', 'gif', 'GIF'] as $other) {
            if (strcasecmp($other, $ext) !== 0 && @is_file($stem . '.' . $other)) {
                return ['path' => $stem . '.' . $other, 'strict' => true];
            }
        }
        $parents = wpc_v2_variant_identity_parents($dest);
        return empty($parents) ? null : ['path' => $parents[0], 'strict' => false];
    }

    /** The parent files a -WxH (or -scaled) name derives from, as they exist on disk. */
    function wpc_v2_variant_identity_parents($dest)
    {
        $dest = (string) $dest;
        $ext = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
        $stem = substr($dest, 0, -(strlen($ext) + 1));
        if (!preg_match('/^(.*)-\d+x\d+$/', $stem, $m)) {
            return [];
        }
        $found = [];
        foreach ([$m[1], $m[1] . '-scaled'] as $parent) {
            foreach (['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG', 'gif', 'GIF'] as $e) {
                if (@is_file($parent . '.' . $e) && $parent . '.' . $e !== $dest) {
                    $found[] = $parent . '.' . $e;
                }
            }
        }
        return $found;
    }

    /** The local candidate (a strict source or a parent) whose bytes are the ones the claim names, or ''. */
    function wpc_v2_variant_identity_claim_hit(array $claim, $dest, $source)
    {
        static $hashes = [];
        if (empty($claim['sha256'])) {
            return '';
        }
        $candidates = [];
        if (is_array($source)) {
            $candidates[] = $source['path'];
        }
        foreach (wpc_v2_variant_identity_parents($dest) as $parent) {
            $candidates[] = $parent;
        }
        foreach (array_unique($candidates) as $path) {
            clearstatcache(true, $path);
            $size = (int) @filesize($path);
            if ($size <= 0 || (!empty($claim['size']) && $size !== (int) $claim['size'])) {
                continue;
            }
            $key = $path . '|' . $size . '|' . (int) @filemtime($path);
            if (!isset($hashes[$key])) {
                if (count($hashes) >= 12) {
                    $hashes = [];
                }
                $hashes[$key] = (string) @hash_file('sha256', $path);
            }
            if ($hashes[$key] === $claim['sha256']) {
                return $path;
            }
        }
        return '';
    }

    /** The raw value of a claim field under either spelling, from the delivery or its `tags`. */
    function wpc_v2_variant_claim_field($from, array $names)
    {
        foreach ([$from, isset($from['tags']) && is_array($from['tags']) ? $from['tags'] : []] as $where) {
            foreach ($names as $name) {
                if (isset($where[$name]) && (is_string($where[$name]) || is_int($where[$name]))) {
                    return $where[$name];
                }
            }
        }
        return null;
    }

    /** The 256 luminance bytes of a variantGrid field, or null when it is not exactly that. */
    function wpc_v2_variant_grid_bytes($encoded)
    {
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 400) {
            return null;
        }
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) !== 256) {
            return null;
        }
        return array_values(unpack('C*', $raw));
    }

    /** See wpc_v2_variant_claim(): ['sha256', 'size', 'grid' => 256 ints], each only when well formed. */
    function wpc_v2_variant_claim_parse($from)
    {
        if (!is_array($from)) {
            return [];
        }
        $claim = [];
        $sha = wpc_v2_variant_claim_field($from, ['sourceSha256', 'source_sha256']);
        if (is_string($sha) && preg_match('/^[a-f0-9]{64}$/', strtolower(trim($sha)))) {
            $claim['sha256'] = strtolower(trim($sha));
            $size = wpc_v2_variant_claim_field($from, ['sourceSize', 'source_bytes']);
            $claim['size'] = is_numeric($size) ? (int) $size : 0;
        }
        $grid = wpc_v2_variant_grid_bytes(wpc_v2_variant_claim_field($from, ['variantGrid', 'variant_grid']));
        if ($grid !== null) {
            $claim['grid'] = $grid;
        }
        return $claim;
    }

    /** The well-formed claim fields under their canonical names (what a journal entry carries to the drain). */
    function wpc_v2_variant_claim_wire_fields($from)
    {
        $claim = wpc_v2_variant_claim_parse($from);
        $wire = [];
        if (!empty($claim['sha256'])) {
            $wire['sourceSha256'] = $claim['sha256'];
            $wire['sourceSize'] = (int) $claim['size'];
        }
        if (!empty($claim['grid'])) {
            $wire['variantGrid'] = base64_encode(pack('C*', ...$claim['grid']));
        }
        return $wire;
    }

    /**
     * The verdict of the service's grid against the local source's: the source is a print
     * (wpc_v2_variant_identity_print), the grid is 256 ints. The grid is of the variant composited
     * on white, so the source is compared on white too, under the eight orientations. It carries no
     * dimensions, so a source inferred from the parent's name (non-strict) answers only when the
     * -WxH in the name has the parent's aspect (otherwise the variant may be a WordPress crop), and
     * a transparent source never refuses (the service's ground is not known to be white). $size says
     * the source is a sub-size WordPress generated: one orientation, the size threshold, reason `size-crop`.
     */
    function wpc_v2_variant_identity_grid_verdict(array $source, array $grid, $strict, $dest, $size = false)
    {
        if (!$strict) {
            $stem = substr((string) $dest, 0, -(strlen((string) pathinfo((string) $dest, PATHINFO_EXTENSION)) + 1));
            if (!preg_match('/-(\d+)x(\d+)$/', $stem, $dims) || (int) $dims[2] <= 0) {
                return ['verdict' => 'unverified', 'reason' => 'aspect-differs', 'distance' => null];
            }
            $name_ar = (int) $dims[1] / (int) $dims[2];
            $source_ar = $source['w'] / max(1, $source['h']);
            if (abs($name_ar / $source_ar - 1) > 0.03 && abs($name_ar * $source_ar - 1) > 0.03) {
                return ['verdict' => 'unverified', 'reason' => 'aspect-differs', 'distance' => null];
            }
        }
        $best = null;
        for ($k = 0; $k < ($size ? 1 : 8); $k++) {
            $d = wpc_v2_variant_identity_mad(wpc_v2_variant_identity_orient($source['g'][255], $k), $grid);
            $best = $best === null ? $d : min($best, $d);
        }
        $best = round($best, 2);
        if ($best <= ($size ? wpc_v2_variant_identity_size_threshold() : wpc_v2_variant_identity_threshold())) {
            return ['verdict' => 'match', 'reason' => 'grid', 'distance' => $best];
        }
        if (wpc_v2_variant_identity_has_alpha($source)) {
            return ['verdict' => 'unverified', 'reason' => 'alpha-flattened', 'distance' => $best];
        }
        return ['verdict' => 'mismatch', 'reason' => $size ? 'size-crop' : 'grid', 'distance' => $best];
    }

    /** Pixels from which a source's fingerprint is kept between requests (below it a decode costs less than the rows). */
    function wpc_v2_variant_identity_persist_pixels()
    {
        $n = 2000000;
        return function_exists('apply_filters') ? (int) apply_filters('wpc_variant_identity_persist_pixels', $n) : $n;
    }

    /** The key a source's fingerprint is kept under: path, size and mtime, so an edited file starts afresh. */
    function wpc_v2_variant_identity_fp_key($path)
    {
        clearstatcache(true, $path);
        return 'wpc_vi_fp_' . md5($path . '|' . (int) @filesize($path) . '|' . (int) @filemtime($path));
    }

    /** A print as 768 bytes (the three grids, rounded) and its size: what a transient keeps. */
    function wpc_v2_variant_identity_fp_pack(array $print)
    {
        $bytes = [];
        foreach ([255, 0, 128] as $bg) {
            foreach ($print['g'][$bg] as $v) {
                $bytes[] = max(0, min(255, (int) round($v)));
            }
        }
        return ['w' => (int) $print['w'], 'h' => (int) $print['h'], 'g' => base64_encode(pack('C*', ...$bytes))];
    }

    /** The print a transient kept, or null when it is not one. */
    function wpc_v2_variant_identity_fp_unpack($stored)
    {
        if (!is_array($stored) || empty($stored['w']) || empty($stored['h']) || !isset($stored['g']) || !is_string($stored['g'])) {
            return null;
        }
        $raw = base64_decode($stored['g'], true);
        if (!is_string($raw) || strlen($raw) !== 768) {
            return null;
        }
        $all = array_values(unpack('C*', $raw));
        return ['ok' => true, 'w' => (int) $stored['w'], 'h' => (int) $stored['h'],
            'g' => [255 => array_slice($all, 0, 256), 0 => array_slice($all, 256, 256), 128 => array_slice($all, 512, 256)]];
    }

    /**
     * The fingerprint of a local file. Memoised for the request and, for a large source, kept in a
     * transient under wpc_v2_variant_identity_fp_key(): a 24 MP original decodes once, not on every
     * signed write and again in the sweep. The memory guard stays in wpc_v2_variant_identity_print().
     * The sweep passes $persist false: it visits each attachment once, and on a library of 100k a row
     * per large source would leave that many wp_options rows behind (it still reads a kept one).
     */
    function wpc_v2_variant_identity_file_print($path, $persist = true)
    {
        static $memo = [];
        clearstatcache(true, $path);
        $size = (int) @filesize($path);
        $key = $path . '|' . $size . '|' . (int) @filemtime($path);
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        if ($size <= 0 || $size > 40 * 1048576) {
            return ['ok' => false, 'reason' => 'source-large'];
        }
        $print = function_exists('get_transient') ? wpc_v2_variant_identity_fp_unpack(get_transient(wpc_v2_variant_identity_fp_key($path))) : null;
        if ($print === null) {
            $bytes = @file_get_contents($path);
            $print = ($bytes === false || $bytes === '') ? ['ok' => false, 'reason' => 'undecodable'] : wpc_v2_variant_identity_print($bytes);
            unset($bytes);
            if ($persist && !empty($print['ok']) && $print['w'] * $print['h'] >= wpc_v2_variant_identity_persist_pixels() && function_exists('set_transient')) {
                set_transient(wpc_v2_variant_identity_fp_key($path), wpc_v2_variant_identity_fp_pack($print), 7 * 86400);
            }
        }
        if (count($memo) >= 6) {
            $memo = [];
        }
        $memo[$key] = $print;
        return $print;
    }

    /**
     * Is $bytes (headed for $dest) a picture of the file $dest stands for?
     *
     * @param string $bytes The variant bytes.
     * @param string $dest  Absolute destination path (a file there, or not yet).
     * @param array  $claim wpc_v2_variant_claim() of the delivery, or [].
     * @param bool   $persist_source false keeps a large source's fingerprint from being stored (the sweep).
     * @param bool   $size_rule false leaves the sub-size rule out (the sweep judges what landed by its own threshold).
     * @return array ['verdict' => match|mismatch|unverified, 'reason' => string, 'distance' => float|null,
     *                'source' => basename|'', 'claim' => hit|miss|none, 'grid' => none|used|agrees|disagrees|ignored]
     */
    function wpc_v2_variant_identity($bytes, $dest, array $claim = [], $persist_source = true, $size_rule = true)
    {
        static $memo = [];
        $out = ['verdict' => 'unverified', 'reason' => '', 'distance' => null, 'source' => '', 'claim' => empty($claim['sha256']) ? 'none' : 'miss', 'grid' => empty($claim['grid']) ? 'none' : 'ignored'];
        try {
            $dest = (string) $dest;
            $ext = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
            if (!wpc_v2_variant_identity_on()) {
                $out['reason'] = 'off';
                return $out;
            }
            if (!in_array($ext, wpc_v2_variant_identity_exts(), true) || !is_string($bytes) || $bytes === '') {
                $out['reason'] = 'not-image';
                return $out;
            }
            $source = wpc_v2_variant_identity_source($dest);
            $wp_size = ($size_rule && $source !== null && !empty($source['size'])) ? $source['size'] : null;
            $hit = wpc_v2_variant_identity_claim_hit($claim, $dest, $source);
            $out['claim'] = empty($claim['sha256']) ? 'none' : ($hit !== '' ? 'hit' : 'miss');
            if ($source === null && $hit === '') {
                $out['reason'] = 'no-source';
                return $out;
            }
            $out['source'] = basename($source !== null ? $source['path'] : $hit);

            $memo_key = md5($bytes) . '|' . $dest . '|' . ($source !== null ? $source['path'] . '|' . (int) @filemtime($source['path']) . '|' . (int) @filesize($source['path']) : '') . '|' . $out['claim'] . '|' . (empty($claim['grid']) ? '' : md5(implode(',', $claim['grid']))) . '|' . implode(',', array_map('intval', wpc_v2_variant_identity_decoders_seen())) . '|' . ($wp_size !== null ? 'size' : '');
            if (isset($memo[$memo_key])) {
                return $memo[$memo_key];
            }

            $verdict = ['verdict' => 'unverified', 'reason' => 'no-source', 'distance' => null];
            $grid_state = empty($claim['grid']) ? 'none' : 'ignored';
            $shape = $wp_size !== null ? wpc_v2_variant_identity_shape($bytes, $wp_size) : null;
            if ($shape !== null) {
                $verdict = $shape;
            } elseif ($source !== null) {
                $source_print = wpc_v2_variant_identity_file_print($source['path'], $persist_source);
                if (empty($source_print['ok'])) {
                    $verdict['reason'] = 'source-' . $source_print['reason'];
                } elseif ($source_print['w'] < 8 || $source_print['h'] < 8) {
                    $verdict['reason'] = 'source-tiny';
                } else {
                    $variant_print = wpc_v2_variant_identity_print($bytes);
                    if (empty($variant_print['ok'])) {
                        $verdict['reason'] = 'variant-' . $variant_print['reason'];
                        if (!empty($claim['grid'])) {
                            $verdict = wpc_v2_variant_identity_grid_verdict($source_print, $claim['grid'], !empty($source['strict']), $dest, $wp_size !== null);
                            $grid_state = 'used';
                        } elseif ($wp_size !== null && !empty($source['crop'])) {
                            $verdict = ['verdict' => 'mismatch', 'reason' => 'size-unverified', 'distance' => null];
                        }
                    } else {
                        $verdict = wpc_v2_variant_identity_compare($source_print, $variant_print, !empty($source['strict']), $wp_size !== null);
                        if (!empty($claim['grid'])) {
                            $disagrees = wpc_v2_variant_identity_mad($variant_print['g'][255], $claim['grid']);
                            $grid_state = $disagrees > wpc_v2_variant_identity_threshold() ? 'disagrees' : 'agrees';
                            if ($grid_state === 'disagrees') {
                                wpc_v2_variant_identity_count('grid_disagrees');
                                if (function_exists('wpc_cache_first_log')) {
                                    wpc_cache_first_log('variant-grid-disagrees', basename($dest), '', ['distance' => round($disagrees, 2), 'ext' => $ext, 'verdict' => (string) $verdict['verdict']]);
                                }
                            }
                        }
                    }
                }
            }
            if ($verdict['verdict'] === 'unverified' && $hit !== '') {
                $verdict = ['verdict' => 'match', 'reason' => 'source-hash', 'distance' => null];
            }
            $out = $verdict + $out;
            $out['claim'] = empty($claim['sha256']) ? 'none' : ($hit !== '' ? 'hit' : 'miss');
            $out['grid'] = $grid_state;
            if (count($memo) >= 24) {
                $memo = [];
            }
            $memo[$memo_key] = $out;
            return $out;
        } catch (\Throwable $e) {
            $out['verdict'] = 'unverified';
            $out['reason'] = 'error';
            return $out;
        }
    }

    /**
     * Whether a landing is refused: a mismatch always, and for an entry accepted only because its
     * host matched the *.zapwp.com wildcard (a zone this site cannot name) anything but a match.
     */
    function wpc_v2_variant_identity_refused(array $identity, $foreign_host = '')
    {
        if (($identity['verdict'] ?? '') === 'mismatch') {
            return true;
        }
        return $foreign_host !== '' && ($identity['verdict'] ?? '') !== 'match';
    }

    /**
     * A receipt for an act that corrects what the service delivered (a landing refused, a file
     * removed): through wpc_belt_receipt, not sampled (each line is the evidence), one line per
     * request per event. A request that refuses several variants leaves the first as its line; the
     * counter option below counts them all.
     */
    function wpc_v2_variant_identity_belt($event, array $fields, $key)
    {
        if (function_exists('wpc_belt_receipt')) {
            return wpc_belt_receipt($event, $fields, false, $key);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($event, (string) $key, '', $fields);
        }
        return true;
    }

    /**
     * The receipt and the counter of a refused landing: `variant-identity {verdict, distance, ext,
     * reason, src, claim, grid, host?}` and the option the healthcheck reads (wpc_v2_variant_identity_report).
     */
    function wpc_v2_variant_identity_refuse(array $identity, $dest, $via, array $extra = [])
    {
        $ext = strtolower((string) pathinfo((string) $dest, PATHINFO_EXTENSION));
        $fields = [
            'verdict'  => (string) ($identity['verdict'] ?? ''),
            'distance' => $identity['distance'] ?? null,
            'ext'      => $ext,
            'reason'   => (string) ($identity['reason'] ?? ''),
            'src'      => (string) $via,
            'claim'    => (string) ($identity['claim'] ?? 'none'),
            'grid'     => (string) ($identity['grid'] ?? 'none'),
        ] + $extra;
        wpc_v2_variant_identity_belt('variant-identity', $fields, basename((string) $dest));
        wpc_v2_variant_identity_count('refused', $via, [
            't' => time(), 'file' => basename((string) $dest), 'src' => (string) $via,
            'verdict' => (string) ($identity['verdict'] ?? ''), 'distance' => $identity['distance'] ?? null,
        ]);
    }

    /**
     * The one writer of the sweep's state (cursor, counts, histogram, done): the arm and each run store it here,
     * the healthcheck block (wpc_v2_variant_identity_report) reads it. Autoloaded: the arm reads it every request.
     */
    function wpc_v2_variant_identity_sweep_save(array $state)
    {
        update_option('wpc_variant_identity_sweep', $state, true);
    }

    /**
     * The one writer of the sweep's place inside an attachment it stopped in the middle of (the attachment,
     * the last sibling checked, the hashes it removed, the siblings it kept that are not a match); null
     * deletes it. Not autoloaded: only a sweep run reads it.
     */
    function wpc_v2_variant_identity_sweep_part_save($part)
    {
        if ($part === null) {
            delete_option('wpc_variant_identity_sweep_part');
            return;
        }
        update_option('wpc_variant_identity_sweep_part', $part, false);
    }

    /**
     * The one writer of the purge cadence (last purge, files removed since): the sweep's note, flush and arm
     * store it here. Not autoloaded: it is read only when a purge is asked.
     */
    function wpc_v2_variant_identity_purge_save(array $state)
    {
        update_option('wpc_variant_identity_purge', $state, false);
    }

    /** Adds one to a counter of the stats option; $last (when given) replaces the last-refusal record. */
    function wpc_v2_variant_identity_count($counter, $via = '', array $last = [], $by = 1)
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }
        $stats = get_option('wpc_variant_identity', []);
        $stats = is_array($stats) ? $stats : [];
        $stats[$counter] = (isset($stats[$counter]) ? (int) $stats[$counter] : 0) + (int) $by;
        if ($via !== '' && $counter === 'refused') {
            $by_src = isset($stats['by_src']) && is_array($stats['by_src']) ? $stats['by_src'] : [];
            $by_src[(string) $via] = (isset($by_src[(string) $via]) ? (int) $by_src[(string) $via] : 0) + 1;
            $stats['by_src'] = $by_src;
        }
        if (!empty($last)) {
            $stats['last'] = $last;
        }
        update_option('wpc_variant_identity', $stats, false);
    }

    /**
     * What the healthcheck shows: whether this server can verify avif and webp (and through which
     * decoder), the threshold, the refusal and sweep counters, and the sweep's cursor.
     */
    function wpc_v2_variant_identity_report()
    {
        $caps = wpc_v2_variant_identity_decoders_seen();
        $stats = function_exists('get_option') ? get_option('wpc_variant_identity', []) : [];
        $sweep = function_exists('get_option') ? get_option('wpc_variant_identity_sweep', []) : [];
        return [
            'on'        => wpc_v2_variant_identity_on(),
            'threshold' => wpc_v2_variant_identity_threshold(),
            'can_verify' => ['avif' => (bool) $caps['avif'], 'webp' => (bool) $caps['webp'], 'jpeg_png' => (bool) $caps['gd'] || (bool) $caps['imagick']],
            'decoders'  => ['gd' => (bool) $caps['gd'], 'imagick' => (bool) $caps['imagick']],
            'php'       => PHP_VERSION,
            'stats'     => is_array($stats) ? $stats : [],
            'sweep'     => is_array($sweep) ? $sweep : [],
        ];
    }
}

if (!function_exists('wpc_v2_variant_identity_sweep_run')) {

    /** The WP-cron hook of the library sweep. */
    function wpc_v2_variant_identity_sweep_hook()
    {
        return 'wpc_variant_identity_sweep';
    }

    /** The WP-cron hook of the coalesced hard purge the sweep asks for. */
    function wpc_v2_variant_identity_purge_hook()
    {
        return 'wpc_variant_identity_purge';
    }

    /**
     * The distance at which the sweep deletes. The write door refuses at the threshold (15: a refusal
     * is cheap); the sweep removes a file that landed, so it waits for 20. Honest re-encodes of the
     * ticket's source read up to 12.2 (an unsharp mask and q20 9.9, contrast +15 12.2, a 12 px shift
     * 10.6), the nearest different photo 28.1 and the wrong files 43 and over. Between the two it
     * reports and deletes nothing.
     */
    function wpc_v2_variant_identity_sweep_threshold()
    {
        $t = 20.0;
        return function_exists('apply_filters') ? (float) apply_filters('wpc_variant_identity_sweep_threshold', $t) : $t;
    }

    /** The attachment types the sweep walks: the formats a next-gen sibling is made from. Never a WebP or AVIF upload. */
    function wpc_v2_variant_identity_sweep_mimes()
    {
        return ['image/jpeg', 'image/png', 'image/gif'];
    }

    /** Where a distance falls in the healthcheck's histogram. */
    function wpc_v2_variant_identity_hist_bucket($distance)
    {
        foreach ([1 => '<1', 5 => '1-5', 10 => '5-10', 15 => '10-15', 20 => '15-20', 30 => '20-30', 45 => '30-45'] as $edge => $name) {
            if ($distance < $edge) {
                return $name;
            }
        }
        return '45+';
    }

    /**
     * Next-gen siblings of an attachment that exist on disk: the attached file, the original, every
     * size and the full-dimension names, minus every file WordPress itself holds for the attachment
     * (the attached file, the original image and each size in its metadata): a sibling is something
     * this plugin wrote, never something the library owns.
     */
    function wpc_v2_variant_identity_sweep_candidates($attachment_id)
    {
        $file = function_exists('get_attached_file') ? (string) get_attached_file($attachment_id) : '';
        if ($file === '') {
            return [];
        }
        $dir = dirname($file);
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($attachment_id) : [];
        $meta = is_array($meta) ? $meta : [];
        $names = [basename($file)];
        $owned = [$file => true];
        $original = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($attachment_id) : '';
        if ($original !== '') {
            $names[] = basename($original);
            $owned[$original] = true;
        }
        if (!empty($meta['original_image'])) {
            $owned[$dir . '/' . basename((string) $meta['original_image'])] = true;
        }
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (is_array($size) && !empty($size['file'])) {
                    $names[] = basename((string) $size['file']);
                    $owned[$dir . '/' . basename((string) $size['file'])] = true;
                }
            }
        }
        $stems = [];
        foreach ($names as $name) {
            $dot = strrpos($name, '.');
            $stem = $dot === false ? $name : substr($name, 0, $dot);
            $stems[$stem] = true;
            if (!empty($meta['width']) && !empty($meta['height'])) {
                $base = preg_replace('/-scaled$/', '', $stem);
                if (!preg_match('/-\d+x\d+$/', $base)) {
                    $stems[$base . '-' . (int) $meta['width'] . 'x' . (int) $meta['height']] = true;
                }
            }
        }
        $found = [];
        foreach (array_keys($stems) as $stem) {
            foreach (['avif', 'webp'] as $ext) {
                $path = $dir . '/' . $stem . '.' . $ext;
                if (@is_file($path) && !isset($owned[$path])) {
                    $found[$path] = true;
                }
            }
        }
        return array_keys($found);
    }

    /**
     * Whether a file is one WordPress holds for an attachment of its own: the path is some
     * attachment's `_wp_attached_file`, or it is a size or the -scaled copy of one that is (a WebP or
     * AVIF upload beside a PNG of the same name). A file that is never unlinked.
     */
    function wpc_v2_variant_identity_sweep_referenced($path)
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var') || !function_exists('wp_get_upload_dir')) {
            return true;
        }
        $up = wp_get_upload_dir();
        $base = !empty($up['basedir']) ? rtrim(str_replace('\\', '/', (string) $up['basedir']), '/') . '/' : '';
        $norm = str_replace('\\', '/', (string) $path);
        if ($base === '' || strpos($norm, $base) !== 0) {
            return true;
        }
        $rel = substr($norm, strlen($base));
        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
        $stem = substr($rel, 0, -(strlen($ext) + 1));
        $rels = [$rel];
        $bare = preg_replace('/(-\d+x\d+)?(-scaled)?$/', '', $stem);
        if ($bare !== $stem && $bare !== '') {
            $rels[] = $bare . '.' . $ext;
            $rels[] = $bare . '-scaled.' . $ext;
        }
        $rels = array_values(array_unique($rels));
        $marks = implode(',', array_fill(0, count($rels), '%s'));
        $hit = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($marks) LIMIT 1", ...$rels));
        return !empty($hit);
    }

    /**
     * Checks one attachment's next-gen siblings, in path order. A sibling that is a different picture
     * by at least the sweep threshold is removed: the file, its twin-byte count, the zone copies (the
     * purge queue), the variant-set entry the renderer reads and the service's stored copy (the forget
     * queue); a sibling with the same bytes as a removed one (the bare-name alias) goes with it unless
     * its own source vouches for it. A mismatch below the threshold is reported and kept.
     * $spent, when given, is asked before each sibling once $decoded counts a read: when it answers
     * true the attachment stops before that sibling and the answer carries 'resume' (the last sibling
     * checked, the hashes removed, the non-matching siblings kept), which a later call passes back as
     * $resume to go on after that sibling with the same alias rule. The alias pass runs when the last
     * sibling has been checked.
     * Answers ['checked', 'deleted' => [paths], 'files' => [name:distance], 'reported', 'unverified', 'protected', 'hist' => [bucket => n]] (+ 'resume').
     */
    function wpc_v2_variant_identity_sweep_attachment($attachment_id, &$decoded, $spent = null, array $resume = [])
    {
        $result = ['checked' => 0, 'deleted' => [], 'files' => [], 'reported' => 0, 'unverified' => 0, 'protected' => 0, 'hist' => []];
        if (function_exists('get_post_mime_type') && !in_array((string) get_post_mime_type($attachment_id), wpc_v2_variant_identity_sweep_mimes(), true)) {
            return $result;
        }
        $threshold = wpc_v2_variant_identity_sweep_threshold();
        $removed_hashes = isset($resume['removed']) && is_array($resume['removed']) ? array_fill_keys(array_map('strval', $resume['removed']), true) : [];
        $rest = isset($resume['rest']) && is_array($resume['rest']) ? array_values($resume['rest']) : [];
        $after = isset($resume['after']) ? (string) $resume['after'] : '';
        $last = $after;
        $candidates = wpc_v2_variant_identity_sweep_candidates($attachment_id);
        sort($candidates, SORT_STRING);
        foreach ($candidates as $path) {
            if ($after !== '' && strcmp($path, $after) <= 0) {
                continue;
            }
            if (is_callable($spent) && $decoded > 0 && $spent()) {
                if (!empty($result['deleted'])) {
                    wpc_v2_variant_identity_sweep_forget($attachment_id, $result['deleted']);
                }
                $result['resume'] = ['after' => $last, 'removed' => array_keys($removed_hashes), 'rest' => $rest];
                return $result;
            }
            $last = $path;
            $size = (int) @filesize($path);
            if ($size <= 0 || $size > 40 * 1048576) {
                continue;
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $decoded++;
            $result['checked']++;
            $identity = wpc_v2_variant_identity($bytes, $path, [], false, false);
            if ($identity['distance'] !== null) {
                $bucket = wpc_v2_variant_identity_hist_bucket((float) $identity['distance']);
                $result['hist'][$bucket] = (isset($result['hist'][$bucket]) ? $result['hist'][$bucket] : 0) + 1;
            }
            if ($identity['verdict'] === 'mismatch') {
                if ($identity['distance'] !== null && $identity['distance'] >= $threshold) {
                    $removed_hashes[md5($bytes)] = true;
                    $gone = wpc_v2_variant_identity_sweep_remove($attachment_id, $path, $identity, $size);
                    if ($gone === true) {
                        $result['deleted'][] = $path;
                        $result['files'][] = basename($path) . ':' . $identity['distance'];
                    } else {
                        $result['protected']++;
                    }
                } else {
                    $result['reported']++;
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('variant-identity-reported', basename($path), '', [
                            'id' => (int) $attachment_id, 'distance' => $identity['distance'], 'reason' => (string) $identity['reason'],
                            'ext' => strtolower((string) pathinfo($path, PATHINFO_EXTENSION)),
                        ]);
                    }
                }
                continue;
            }
            if ($identity['verdict'] === 'unverified') {
                $result['unverified']++;
            }
            if ($identity['verdict'] !== 'match') {
                $rest[] = [$path, md5($bytes), $size, ['verdict' => (string) $identity['verdict'], 'distance' => $identity['distance'], 'reason' => (string) $identity['reason']]];
            }
        }
        foreach ($rest as $row) {
            if (isset($removed_hashes[$row[1]]) && @is_file($row[0])) {
                if (wpc_v2_variant_identity_sweep_remove($attachment_id, $row[0], $row[3], $row[2]) === true) {
                    $result['deleted'][] = $row[0];
                    $result['files'][] = basename($row[0]) . ':alias';
                } else {
                    $result['protected']++;
                }
            }
        }
        if (!empty($result['deleted'])) {
            wpc_v2_variant_identity_sweep_forget($attachment_id, $result['deleted']);
        }
        return $result;
    }

    /**
     * Removes one wrong sibling and queues the zone purge for it. Answers true when the file is
     * gone, false when it is not ours to remove (some attachment's own file) or the unlink failed.
     */
    function wpc_v2_variant_identity_sweep_remove($attachment_id, $path, array $identity, $size)
    {
        if (wpc_v2_variant_identity_sweep_referenced($path)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('variant-identity-kept', basename($path), '', ['id' => (int) $attachment_id, 'why' => 'attachment-file']);
            }
            return false;
        }
        if (!@unlink($path)) {
            return false;
        }
        if (function_exists('wpc_add_twin_bytes')) {
            wpc_add_twin_bytes(-(int) $size);
        }
        if (function_exists('wpc_v2_enqueue_landed_purge') && function_exists('wpc_v2_get_apikey') && (string) wpc_v2_get_apikey() !== '') {
            wpc_v2_enqueue_landed_purge($path);
        }
        wpc_v2_variant_identity_belt('variant-identity-swept', [
            'id'       => (int) $attachment_id,
            'verdict'  => (string) $identity['verdict'],
            'distance' => $identity['distance'],
            'ext'      => strtolower((string) pathinfo($path, PATHINFO_EXTENSION)),
            'reason'   => (string) $identity['reason'],
        ], basename($path));
        return true;
    }

    /** After a removal: the set entries that pointed at the file, and the service's stored copy of the image. */
    function wpc_v2_variant_identity_sweep_forget($attachment_id, array $paths)
    {
        $names = [];
        foreach ($paths as $path) {
            $names[basename($path)] = true;
        }
        if (class_exists('wps_ic_image_variants') && method_exists('wps_ic_image_variants', 'forget')) {
            $keys = [];
            foreach (wps_ic_image_variants::get($attachment_id) as $key => $entry) {
                $url = is_array($entry) && !empty($entry['url']) ? basename((string) preg_replace('/\?.*$/', '', (string) $entry['url'])) : '';
                if ($url !== '' && isset($names[$url])) {
                    $keys[] = $key;
                }
            }
            if (!empty($keys)) {
                wps_ic_image_variants::forget($attachment_id, $keys, 'variant-identity');
            }
        }
        if (function_exists('wpc_v2_forget_variants_queue')) {
            wpc_v2_forget_variants_queue((int) $attachment_id, 'variant-identity');
        }
    }

    /**
     * The stored pages link the removed siblings in their <picture> sources and would 404 them, so a
     * removal is followed by a hard purge of the stored pages: at most one an hour (a sweep that
     * removes files for days must not clear the page cache for days). The count of files removed
     * since the last purge is kept; a purge asked inside the hour is scheduled for its end.
     * Receipt `variant-identity-purge {deleted}`.
     */
    function wpc_v2_variant_identity_purge_note($deleted)
    {
        if ((int) $deleted <= 0) {
            return;
        }
        $st = get_option('wpc_variant_identity_purge', []);
        $st = is_array($st) ? $st : [];
        $st['pending'] = (int) ($st['pending'] ?? 0) + (int) $deleted;
        wpc_v2_variant_identity_purge_save($st);
    }

    function wpc_v2_variant_identity_purge_flush()
    {
        $st = get_option('wpc_variant_identity_purge', []);
        if (!is_array($st) || (int) ($st['pending'] ?? 0) <= 0) {
            return;
        }
        $now = time();
        $last = (int) ($st['last'] ?? 0);
        if ($now - $last >= 3600) {
            if (function_exists('wpc_hard_html_purge_after_response')) {
                wpc_hard_html_purge_after_response('variant-identity', false);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('variant-identity-purge', '', '', ['deleted' => (int) $st['pending']]);
            }
            wpc_v2_variant_identity_purge_save(['last' => $now, 'pending' => 0]);
            return;
        }
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled') && !wp_next_scheduled(wpc_v2_variant_identity_purge_hook())) {
            wp_schedule_single_event($last + 3600 + 1, wpc_v2_variant_identity_purge_hook());
        }
    }

    /**
     * One cron run of the library sweep: attachments after the stored cursor, in id order, until the
     * time budget or the decode cap is spent. The budget is asked before each file is read (one read
     * a run always happens), so a run stops inside an attachment when that is where it is spent: the
     * cursor stays before that attachment and its place inside it is stored
     * (wpc_v2_variant_identity_sweep_part_save) for the next run to go on from. The next run is queued
     * before any work (WordPress has taken this event off the schedule), so a run that dies mid-loop
     * leaves its successor, and the cursor is stored after each attachment that did work (and every
     * 25 others), so the successor resumes there. A run that finds the server under pressure
     * (wpc_under_pressure) reads nothing and leaves the queued run to try again
     * (`variant-identity-sweep-deferred`, at most once an hour). The run that passes the last attachment marks
     * the sweep done and unschedules it.
     */
    function wpc_v2_variant_identity_sweep_run()
    {
        global $wpdb;
        $state = get_option('wpc_variant_identity_sweep', []);
        if (!is_array($state) || !empty($state['done'])) {
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(wpc_v2_variant_identity_sweep_hook());
            }
            return;
        }
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled') && !wp_next_scheduled(wpc_v2_variant_identity_sweep_hook())) {
            wp_schedule_single_event(time() + 60, wpc_v2_variant_identity_sweep_hook());
        }
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_variant_identity_sweep_shed')) {
                set_transient('wpc_variant_identity_sweep_shed', 1, 3600);
                wpc_cache_first_log('variant-identity-sweep-deferred', '', '', ['why' => 'pressure', 'cursor' => (int) ($state['cursor'] ?? 0)]);
            }
            return;
        }
        wpc_v2_variant_identity_purge_flush();
        $budget = (float) (function_exists('apply_filters') ? apply_filters('wpc_variant_identity_sweep_seconds', 5.0) : 5.0);
        $decode_cap = (int) (function_exists('apply_filters') ? apply_filters('wpc_variant_identity_sweep_files', 60) : 60);
        $started = microtime(true);
        $decoded = 0;
        $spent = function () use (&$decoded, $started, $budget, $decode_cap) {
            return (microtime(true) - $started) >= $budget || $decoded >= $decode_cap;
        };
        $part = get_option('wpc_variant_identity_sweep_part', []);
        $part = is_array($part) ? $part : [];
        $cursor = (int) ($state['cursor'] ?? 0);
        $ids = [];
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_col')) {
            $mimes = wpc_v2_variant_identity_sweep_mimes();
            $ids = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (" . implode(',', array_fill(0, count($mimes), '%s')) . ") AND ID > %d ORDER BY ID ASC LIMIT %d",
                ...array_merge($mimes, [$cursor, 300])
            ));
        }
        if (empty($ids) && isset($wpdb) && is_object($wpdb) && !empty($wpdb->last_error)) {
            return;
        }
        if (!empty($part) && (empty($ids) || (int) ($part['id'] ?? 0) !== (int) $ids[0])) {
            wpc_v2_variant_identity_sweep_part_save(null);
            $part = [];
        }
        $state['runs'] = (int) ($state['runs'] ?? 0) + 1;
        $state['t'] = time();
        $run = ['attachments' => 0, 'deleted' => 0, 'files' => [], 'stopped_in' => 0];
        $processed_all = true;
        $since_stored = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($run['attachments'] > 0 && $spent()) {
                $processed_all = false;
                break;
            }
            $one = wpc_v2_variant_identity_sweep_attachment($id, $decoded, $spent, (int) ($part['id'] ?? 0) === $id ? $part : []);
            $run['deleted'] += count($one['deleted']);
            $run['files'] = array_merge($run['files'], $one['files']);
            foreach (['checked', 'reported', 'unverified', 'protected'] as $key) {
                $state[$key] = (int) ($state[$key] ?? 0) + $one[$key];
            }
            $state['deleted'] = (int) ($state['deleted'] ?? 0) + count($one['deleted']);
            $hist = isset($state['hist']) && is_array($state['hist']) ? $state['hist'] : [];
            foreach ($one['hist'] as $bucket => $n) {
                $hist[$bucket] = (isset($hist[$bucket]) ? (int) $hist[$bucket] : 0) + $n;
            }
            $state['hist'] = $hist;
            if (!empty($one['deleted'])) {
                wpc_v2_variant_identity_purge_note(count($one['deleted']));
            }
            if (isset($one['resume'])) {
                wpc_v2_variant_identity_sweep_part_save(['id' => $id] + $one['resume']);
                $run['stopped_in'] = $id;
                $processed_all = false;
                break;
            }
            if (!empty($part)) {
                wpc_v2_variant_identity_sweep_part_save(null);
                $part = [];
            }
            $run['attachments']++;
            $state['attachments'] = (int) ($state['attachments'] ?? 0) + 1;
            $state['cursor'] = $id;
            $cursor = $id;
            $since_stored++;
            if ($one['checked'] > 0 || $since_stored >= 25) {
                wpc_v2_variant_identity_sweep_save($state);
                $since_stored = 0;
            }
        }
        $reached_end = $processed_all && count($ids) < 300;
        if ($reached_end) {
            $state['done'] = 1;
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(wpc_v2_variant_identity_sweep_hook());
            }
        }
        wpc_v2_variant_identity_sweep_save($state);
        if ($run['deleted'] > 0) {
            wpc_v2_variant_identity_count('swept', '', [], $run['deleted']);
            wpc_v2_variant_identity_purge_flush();
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('variant-identity-sweep', '', '', [
                'cursor' => $cursor, 'attachments' => $run['attachments'], 'deleted' => $run['deleted'], 'done' => $reached_end ? 1 : 0,
                'files' => implode(',', array_slice($run['files'], 0, 10)), 'more' => max(0, count($run['files']) - 10),
                'read' => $decoded, 'stopped_in' => $run['stopped_in'],
            ]);
        }
    }

    /**
     * Arms the sweep once per site: the first request after the update that carries it finds no
     * state, stores the cursor at 0 and queues the first run. The purge cadence starts at this
     * moment (the update's own hard purge has just run), so the first sweep purge comes an hour
     * after it and never stacks on it. A finished sweep leaves its state
     * (done) and is never armed again. A sweep that is not done, has nothing scheduled and has not
     * run for an hour (a run that died took its event with it) is queued again.
     */
    function wpc_v2_variant_identity_sweep_arm()
    {
        if (!function_exists('get_option') || !function_exists('wp_schedule_single_event') || !function_exists('wp_next_scheduled')) {
            return;
        }
        $state = get_option('wpc_variant_identity_sweep', null);
        if (is_array($state)) {
            if (empty($state['done']) && !wp_next_scheduled(wpc_v2_variant_identity_sweep_hook())
                && time() - (int) (isset($state['t']) ? $state['t'] : (isset($state['armed']) ? $state['armed'] : 0)) > 3600) {
                wp_schedule_single_event(time() + 60, wpc_v2_variant_identity_sweep_hook());
            }
            return;
        }
        wpc_v2_variant_identity_sweep_save(['cursor' => 0, 'armed' => time(), 'done' => 0]);
        wpc_v2_variant_identity_purge_save(['last' => time(), 'pending' => 0]);
        if (!wp_next_scheduled(wpc_v2_variant_identity_sweep_hook())) {
            wp_schedule_single_event(time() + 120, wpc_v2_variant_identity_sweep_hook());
        }
    }

    if (function_exists('add_action') && !defined('SHORTINIT')) {
        add_action(wpc_v2_variant_identity_sweep_hook(), 'wpc_v2_variant_identity_sweep_run');
        add_action(wpc_v2_variant_identity_purge_hook(), 'wpc_v2_variant_identity_purge_flush');
        add_action('init', 'wpc_v2_variant_identity_sweep_arm', 20);
    }
}
