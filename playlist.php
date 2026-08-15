<?php
/**
 * Sirve la playlist HLS de una clase, o una pista de subtítulos.
 *
 * CONTROL DE ACCESO — las dos capas aplican a todas las peticiones:
 *
 *   1. El parámetro `c` (curso) firmado tiene que sobrevivir a la validación
 *      HMAC, y el espectador tiene que estar matriculado AHORA en ese curso.
 *      Esto lo comprueba este sitio, que es el único que lo sabe.
 *   2. Impronta comprueba lo suyo con el apikey de este sitio.
 *
 * LA PLAYLIST YA NO LLEVA FIRMAS DENTRO, y ese es el cambio de fondo.
 *
 * Antes este fichero pedía una firma de CloudFront y la pegaba a cada segmento
 * del .m3u8. El problema es que una firma emitida NO SE PUEDE REVOCAR y esa
 * cubría "Materia/Clase/*": mientras durase, quien la sacara del navegador se
 * bajaba la clase entera, y bloquear a un alumno no surtía efecto hasta que
 * caducara. Ahora la playlist la monta el backend y cada una de sus líneas
 * apunta a una Lambda que comprueba el bloqueo ANTES de servir el segmento, así
 * que revocar el acceso muerde en el siguiente, unos diez segundos.
 *
 * Por el mismo motivo desaparecieron de aquí el modo cookie -las cookies de
 * CloudFront tienen exactamente el mismo alcance que aquella firma- y la
 * reescritura de master y renditions, que el contenido muxeado no tiene.
 *
 * Los subtítulos siguen viniendo del CDN con una firma, pero esa no baja nunca
 * al navegador: los descarga este PHP y los sirve él.
 *
 * @package   filter_impronta
 */

require_once(__DIR__ . '/../../config.php');

use filter_impronta\cloudfront;
use filter_impronta\impronta_api;
use filter_impronta\request;
use filter_impronta\token;

// Antes de nada, incluso antes de validar: el reproductor de la app pide esto
// por XHR desde otro origen y sin CORS el navegador descarta la respuesta sin
// mirarla, sea 200 o 403.
request::send_cors_headers();

$rawf = optional_param('f', null, PARAM_RAW_TRIMMED);
$token = optional_param('t', null, PARAM_ALPHANUMEXT);
$expires = optional_param('e', null, PARAM_INT);
$courseid = optional_param('c', 0, PARAM_INT);
// Usuario firmado dentro del token (camino de la app, sin cookie de sesión).
// Manipularlo invalida el HMAC, asi que no hace falta confiar en el valor.
$userid = optional_param('u', 0, PARAM_INT);
$authorizationgroupid = optional_param('g', '', PARAM_ALPHANUMEXT);
$playbackid = optional_param('p', '', PARAM_ALPHANUMEXT);
$mode = optional_param('m', '', PARAM_ALPHA);
$rawvtt = optional_param('vtt', null, PARAM_ALPHANUMEXT);

/**
 * Ends the request with a plain-text error.
 */
function impronta_playlist_fail(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo '# ' . $message;
    exit;
}

if (empty($rawf)) {
    impronta_playlist_fail(400, 'Falta el parámetro f.');
}

// `vtt` (subtitle language): 2-5 alphanumeric characters.
$vttlang = null;
if ($rawvtt !== null && $rawvtt !== '') {
    $candidatevtt = strtolower($rawvtt);
    if (!preg_match('/^[a-z0-9\-]{2,5}$/', $candidatevtt)) {
        impronta_playlist_fail(400, 'Idioma de subtítulos inválido.');
    }
    $vttlang = $candidatevtt;
}

// Normalise the logical path.
$path = trim(preg_replace('#/+#', '/', str_replace('\\', '/', $rawf)), '/');
if ($path === '' || strpos($path, '..') !== false) {
    impronta_playlist_fail(400, 'Ruta inválida.');
}

/* --------------------------------------------------------------------- */
/* Layer 2: signed course + live enrolment                                */
/* --------------------------------------------------------------------- */

if (empty($token) || empty($expires)) {
    header('X-Impronta-Deny: notoken');
    impronta_playlist_fail(403, get_string('reopenthroughapp', 'filter_impronta'));
}

$esapp = false;
$denied = token::authorize($path, $token, (int) $expires, (int) $courseid, (int) $userid, $esapp,
    $authorizationgroupid, $playbackid, $mode);
if ($denied !== null) {
    header('X-Impronta-Deny: token');
    impronta_playlist_fail(403, get_string($denied, 'filter_impronta'));
}

/* --------------------------------------------------------------------- */
/* Sesión de navegador, salvo que el token diga que es la app.            */
/*                                                                       */
/* Sin esto, el `u` firmado bastaba como identidad y esta URL valía       */
/* COPIADA en cualquier navegador, sin sesión, durante todo el TTL del    */
/* token —7 horas por defecto—. Y el `u` se firma SIEMPRE, también en     */
/* escritorio (ver classes/player.php:102), así que no era un caso raro:  */
/* era toda URL de playlist que el sitio hubiera emitido.                 */
/*                                                                       */
/* La excepción no se detecta, viene acreditada: $esapp sale de la propia */
/* firma (classes/token.php), y la app es la que nunca va a traer cookie  */
/* —medido el 2026-08-11: mismo token, 403 en el webview y 200 en el      */
/* escritorio—.                                                          */
/*                                                                       */
/* isloggedin() y no require_login(): esto lo pide un reproductor de      */
/* vídeo, y una redirección al login le entrega HTML donde espera un      */
/* .m3u8. El 403 se lee; la redirección desconcierta.                    */
/* --------------------------------------------------------------------- */

if ($mode !== 'scorm' && !$esapp && (!isloggedin() || isguestuser())) {
    header('X-Impronta-Deny: nosession');
    impronta_playlist_fail(403, get_string('reopenthroughapp', 'filter_impronta'));
}

/* --------------------------------------------------------------------- */
/* Subtítulos: {path}/subs/{lang}.vtt                                     */
/*                                                                        */
/* Siguen viniendo del CDN con una firma de las de antes. No es una        */
/* contradicción con lo de arriba: esa firma la usa ESTE PHP para          */
/* descargar el fichero y servirlo él, y no baja nunca al navegador. Su    */
/* alcance da igual mientras no salga de aquí.                             */
/* --------------------------------------------------------------------- */

if ($vttlang !== null) {
    $reason = null;
    $signature = impronta_api::signature($path, $reason, (int) $userid);
    if ($signature === null) {
        $message = $reason === 'denied'
            ? get_string('servicedenied', 'filter_impronta')
            : get_string('servicedown', 'filter_impronta');
        impronta_playlist_fail($reason === 'denied' ? 403 : 503, $message);
    }

    $vtturl = cloudfront::sign_url(
        $signature['baseurl'] . '/' . cloudfront::encode_key("{$path}/subs/{$vttlang}.vtt"),
        $signature
    );
    $vttcontent = cloudfront::fetch($vtturl);
    if ($vttcontent === false) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Subtítulo no encontrado.';
        exit;
    }
    header('Content-Type: text/vtt; charset=utf-8');
    header('Cache-Control: private, max-age=60');
    echo $vttcontent;
    exit;
}

/* --------------------------------------------------------------------- */
/* Playlist                                                               */
/*                                                                        */
/* Se pide montada al backend y se sirve tal cual. Aquí no se reescribe   */
/* ni se firma nada: cada línea ya apunta al endpoint que comprueba el     */
/* bloqueo y firma el segmento en el momento de servirlo.                  */
/* --------------------------------------------------------------------- */

$reason = null;
$sesion = impronta_api::playlist($path, $reason, (int) $userid, $authorizationgroupid);
if ($sesion === null) {
    $message = $reason === 'denied'
        ? get_string('servicedenied', 'filter_impronta')
        : get_string('servicedown', 'filter_impronta');
    // "backend" y no "denied": este 403 no lo decide este sitio, lo decide
    // Impronta -y su 401 por el apikey se traduce aquí a 403, que es lo que
    // hizo pasar por "apikey mala" un fallo que no tenía nada que ver.
    header('X-Impronta-Deny: backend');
    impronta_playlist_fail($reason === 'denied' ? 403 : 503, $message);
}

header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
// no-store y no los 60 s de antes: esta playlist lleva un token que identifica
// a ESTE alumno y a ESTA sesión. Un proxy que la cachee se la estaría sirviendo
// al siguiente que pase.
header('Cache-Control: no-store');
// El identificador de sesión se guarda aquí, no se le manda al navegador.
//
// Quien pide esta URL es el reproductor de vídeo, no nuestro JavaScript, así
// que una cabecera de respuesta no la vería nadie. Guardándolo en la caché del
// sitio, heartbeat.php lo recupera por su cuenta y el cliente no llega a
// conocerlo — que además es lo correcto: así no puede latir por una sesión
// ajena.
if (!empty($sesion['sessionId'])) {
    impronta_api::remember_session($path, (int) $userid, (string) $sesion['sessionId'], $playbackid);
}
echo $sesion['playlist'];
