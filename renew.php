<?php
/** Browser-session playback renewal; never accepts a mobile bearer in a URL. */
require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $url = required_param('url', PARAM_RAW);
    echo json_encode(\filter_impronta\renewal::issue($url, (int) $USER->id, false));
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => 'renewal denied']);
}
