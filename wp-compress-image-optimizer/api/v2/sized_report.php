<?php

define('WPC_V2_DIRECT_ENTRY', true);
define('WPC_V2_SKIP_METHOD_GUARD', true);

function wpc_sized_report_end($status, $verdict)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-WPC-Sized: ' . $verdict);
    echo $verdict;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    wpc_sized_report_end(405, 'refused:method');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
    wpc_sized_report_end(413, 'refused:size');
}

require __DIR__ . '/_shared.php';
require_once __DIR__ . '/../../addons/v2/v2-sized-spool.php';

$wpc_out = wpc_sized_report_handle('direct');
wpc_sized_report_end($wpc_out[0], $wpc_out[1]);
