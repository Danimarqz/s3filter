<?php
/**
 * Emite las cookies firmadas de CloudFront para una clase.
 *
 * Es la alternativa a incrustar la firma en la query de cada segmento. Mismo
 * control de acceso que playlist.php —token HMAC y matrícula en el curso— y
 * la misma firma de Reelo: lo único que cambia es el envoltorio. Reelo
 * devuelve Policy, Signature y Key-Pair-Id; mandarlos en la URL o en cookies
 * CloudFront-* es una decisión de empaquetado, no de backend.
 *
 * Solo sirve cuando el CDN del tenant comparte dominio registrable con este
 * Moodle (dominio propio del tenant). Si no lo comparte, la cookie no viajaría
 * al CDN y se responde 409 para que el reproductor siga con URLs firmadas.
 *
 * @package   filter_s3video
 */

require_once(__DIR__ . '/../../config.php');

use filter_s3video\cloudfront;
use filter_s3video\reelo_api;
use filter_s3video\request;
use filter_s3video\token;

// El reproductor pide esto con credenciales desde la página del curso, que
// está en otro subdominio: sin CORS la respuesta se descarta y sin
// Allow-Credentials el navegador ni siquiera guarda las cookies.
request::send_cors_headers();

$rawf = optional_param('f', null, PARAM_RAW_TRIMMED);
$token = optional_param('t', null, PARAM_ALPHANUMEXT);
$expires = optional_param('e', null, PARAM_INT);
$courseid = optional_param('c', 0, PARAM_INT);
$userid = optional_param('u', 0, PARAM_INT);

/**
 * Termina la petición con un código y un cuerpo mínimo.
 *
 * @param int $status
 * @param string $message
 */
function s3video_cookies_fail(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$path = trim((string) $rawf, '/');
if ($path === '' || strpos($path, '..') !== false || substr_count($path, '/') !== 1) {
    s3video_cookies_fail(400, 'ruta no valida');
}

$denied = token::authorize($path, $token, (int) $expires, (int) $courseid, (int) $userid);
if ($denied !== null) {
    s3video_cookies_fail(403, get_string($denied, 'filter_s3video'));
}

$reason = null;
$signature = reelo_api::signature($path, $reason, (int) $userid);
if (!$signature) {
    s3video_cookies_fail(502, 'sin firma: ' . ($reason ?: 'desconocido'));
}

if (!cloudfront::set_cookies($signature)) {
    // Este tenant no tiene dominio propio bajo el del Moodle: el modo cookie
    // no es posible y el reproductor debe seguir con URLs firmadas. No es un
    // error, es el otro modo.
    s3video_cookies_fail(409, 'sin dominio comun; usar urls firmadas');
}

http_response_code(204);
