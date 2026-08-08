<?php
/**
 * Latido de una sesión de reproducción, reenviado a Reelo server-side.
 *
 * El reproductor late aquí en vez de llamar a Reelo directamente por el mismo
 * motivo que events.php: el apikey del tenant no puede bajar al navegador.
 * Filtrarlo dejaría a cualquier alumno pedir la playlist de cualquier clase del
 * catálogo, que es lo que ese apikey autoriza.
 *
 * Para qué sirve latir:
 *
 *   1. Mantiene viva la sesión. Sin latidos muere sola a los cinco minutos, y
 *      con ella se pierde el recuento de reproducciones simultáneas que detecta
 *      cuentas compartidas.
 *   2. Reporta cuánto vídeo se ha visto. Ese número es el DENOMINADOR de la
 *      detección de descarga masiva: sin él, un alumno viendo clase y alguien
 *      bajándose el temario piden segmentos a un ritmo parecido y no se
 *      distinguen.
 *   3. Devuelve si la sesión fue expulsada o el acceso revocado, que es como el
 *      reproductor se entera de que tiene que parar y decir por qué.
 *
 * El identificador de sesión NO viaja en la petición: lo guardó playlist.php en
 * la caché del sitio y se recupera aquí. Así el cliente no puede latir por una
 * sesión que no sea la suya.
 *
 * Control de acceso: el mismo que playlist.php y events.php — token HMAC (t/e/c)
 * atado a la ruta del vídeo, y matrícula viva en el curso.
 *
 * @package   filter_impronta
 */

require_once(__DIR__ . '/../../config.php');

use filter_impronta\reelo_api;
use filter_impronta\request;
use filter_impronta\token;

// Con OPTIONS: es un POST con Content-Type JSON, que dispara comprobación
// previa desde el webview de la app.
request::send_cors_headers(true);

$rawf = optional_param('f', null, PARAM_RAW_TRIMMED);
$token = optional_param('t', null, PARAM_ALPHANUMEXT);
$expires = optional_param('e', null, PARAM_INT);
$courseid = optional_param('c', 0, PARAM_INT);
// Ver playlist.php: identidad firmada en el token, para el camino de la app.
$userid = optional_param('u', 0, PARAM_INT);

/**
 * Termina la petición con un código y sin cuerpo.
 *
 * @param int $status
 */
function impronta_heartbeat_fail(int $status): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit;
}

$path = trim(preg_replace('#/+#', '/', str_replace('\\', '/', (string) $rawf)), '/');
if ($path === '' || strpos($path, '..') !== false) {
    impronta_heartbeat_fail(400);
}

if (empty($token) || empty($expires)) {
    impronta_heartbeat_fail(403);
}

if (token::authorize($path, $token, (int) $expires, (int) $courseid, (int) $userid) !== null) {
    impronta_heartbeat_fail(403);
}

$sessionid = reelo_api::recall_session($path, (int) $userid);
if ($sessionid === '') {
    // No hay sesión que renovar: o la playlist se pidió hace demasiado, o esta
    // instalación no tiene caché compartida entre peticiones. 409 y no error:
    // el reproductor debe seguir reproduciendo, solo que sin latir.
    impronta_heartbeat_fail(409);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$watched = 0;
if (is_array($payload) && isset($payload['watchedSeconds']) && is_numeric($payload['watchedSeconds'])) {
    $watched = max(0, (int) $payload['watchedSeconds']);
}

$respuesta = reelo_api::heartbeat($path, (int) $userid, $sessionid, $watched);
if ($respuesta === null) {
    // Perder un latido no puede parar la reproducción: el siguiente lo arregla,
    // y si de verdad hay un bloqueo lo corta el propio segmento con un 403.
    impronta_heartbeat_fail(502);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'evicted' => !empty($respuesta['evicted']),
    'blocked' => !empty($respuesta['blocked']),
    'heartbeatSeconds' => isset($respuesta['heartbeatSeconds']) ? (int) $respuesta['heartbeatSeconds'] : 120,
]);
