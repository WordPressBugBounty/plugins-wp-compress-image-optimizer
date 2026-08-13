<?php


if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$statsDir = dirname(__DIR__, 2) . '/../../uploads/wpc-vitals/';
$cfgFile  = $statsDir . 'config.json';
if (!is_readable($cfgFile)) {
    http_response_code(204);
    exit;
}
$cfg = json_decode((string) @file_get_contents($cfgFile), true);
if (!is_array($cfg) || empty($cfg['enabled'])) {
    http_response_code(410);
    exit;
}

$raw = (string) file_get_contents('php://input', false, null, 0, 600);
parse_str($raw, $q);


$tok  = isset($q['t']) ? (string) $q['t'] : '';
$salt = isset($cfg['salt']) ? (string) $cfg['salt'] : '';
if ($salt === '' || $tok === '' || !hash_equals(sha1($salt), $tok)) {
    http_response_code(204);
    exit;
}

/** log-ish fixed bucket edges — small where thresholds live, coarse in the tail (30 buckets) */
if (!function_exists('wpc_vitals_bucket')) {
function wpc_vitals_bucket($v, $edges)
{
    if (!is_numeric($v)) {
        return 255;
    }
    $v = (float) $v;
    foreach ($edges as $i => $e) {
        if ($v <= $e) {
            return $i;
        }
    }
    return count($edges);
}
}
$MS = [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000, 1200, 1400, 1600, 1800, 2000, 2250, 2500, 2750, 3000, 3500, 4000, 4500, 5000, 6000, 7000, 8500, 10000, 15000, 25000];
$CLSx1000 = [0, 5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 120, 140, 160, 180, 200, 225, 250, 300, 350, 400, 500, 600, 750, 1000, 1500, 2000, 3000];

$flags = 0;
$flags |= (isset($q['d']) && $q['d'] === 'm') ? 0x01 : 0x00;
$flags |= (isset($q['h']) && $q['h'] === '1') ? 0x02 : 0x00;
$flags |= (isset($q['b']) && $q['b'] === '1') ? 0x10 : 0x00;

/** coarse region enum for byte 8 — derived from edge/host geo headers at receive time; the IP
 *  itself is never read into a variable or stored. 0 unknown · 1 NA · 2 EU · 3 APAC · 4 LATAM · 5 MEA */
if (!function_exists('wpc_vitals_region')) {
function wpc_vitals_region()
{
    $cc = '';
    foreach (['HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE'] as $h) {
        if (!empty($_SERVER[$h]) && preg_match('/^[A-Za-z]{2}$/', (string) $_SERVER[$h])) {
            $cc = strtoupper((string) $_SERVER[$h]);
            break;
        }
    }
    if ($cc === '' || $cc === 'XX' || $cc === 'T1') {
        return 0;
    }
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (explode(' ', 'US CA MX BM GL PM') as $c) { $map[$c] = 1; }
        foreach (explode(' ', 'AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE GB UK CH NO IS LI MC AD SM VA UA RS BA MK ME AL MD BY RU GI JE GG IM FO AX') as $c) { $map[$c] = 2; }
        foreach (explode(' ', 'CN JP KR KP TW HK MO IN PK BD LK NP BT MV ID MY SG TH VN PH KH LA MM BN TL MN AU NZ FJ PG SB VU NC PF WS TO TV KI NR FM MH PW CK NU GU MP AS KZ KG TJ TM UZ AF') as $c) { $map[$c] = 3; }
        foreach (explode(' ', 'BR AR CL CO PE VE EC BO PY UY GY SR GF PA CR NI HN SV GT BZ CU DO HT JM TT BB BS PR AW CW SX KY VG VI AG DM GD KN LC VC MQ GP') as $c) { $map[$c] = 4; }
        foreach (explode(' ', 'AE SA QA KW BH OM YE IQ IR IL PS JO LB SY TR EG LY TN DZ MA EH SD SS ET ER DJ SO KE UG TZ RW BI CD CG GA GQ CM CF TD NE NG BJ TG GH CI LR SL GN GW SN GM ML BF MR CV ST AO ZM MW MZ ZW BW NA SZ LS ZA MG MU SC KM RE YT') as $c) { $map[$c] = 5; }
    }
    return isset($map[$cc]) ? $map[$cc] : 0;
}
}
$wpc_region = wpc_vitals_region();

// v7.10.831 — VIEW PING (k=p): a tiny load-time record so views are counted even when the
// metrics beacon never flushes (tab never hidden, crash, kill). Magic 0xA6, flags only.
if (isset($q['k']) && $q['k'] === 'p') {
    $ping = pack('C8', 0xA6, $flags, 255, 255, 255, 255, 255, $wpc_region);
    $day  = $statsDir . gmdate('Ymd') . '.bin';
    $size = @filesize($day);
    if ($size === false || $size < 8388608) {
        @file_put_contents($day, $ping, FILE_APPEND);
    }
    http_response_code(204);
    exit;
}
$eng = isset($q['e']) ? (string) $q['e'] : 'o';
$flags |= ($eng === 'c' ? 0x00 : ($eng === 's' ? 0x04 : ($eng === 'f' ? 0x08 : 0x0C)));

$rec = pack(
    'C8',
    0xA7,
    $flags,
    wpc_vitals_bucket($q['lcp'] ?? null, $MS),
    wpc_vitals_bucket($q['cls'] ?? null, $CLSx1000),
    wpc_vitals_bucket($q['inp'] ?? null, $MS),
    wpc_vitals_bucket($q['ttfb'] ?? null, $MS),
    wpc_vitals_bucket($q['fcp'] ?? null, $MS),
    $wpc_region
);

$day  = $statsDir . gmdate('Ymd') . '.bin';
$size = @filesize($day);
if ($size === false || $size < 8388608) { // 8MB/day cap ≈ 1M views — beyond it, accept + drop
    @file_put_contents($day, $rec, FILE_APPEND); // O_APPEND: atomic for 8-byte writes
}
http_response_code(204);
exit;
