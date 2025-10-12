<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/filter/s3video/lib.php');
require_once($CFG->dirroot . '/lib/aws-sdk/src/CloudFront/UrlSigner.php');

use Aws\CloudFront\UrlSigner;

/**
 * Devuelve una playlist HLS (.m3u8) firmada desde CloudFront
 * para una ruta de vídeo relativa pasada como parámetro `f`.
 *
 * Ejemplo:
 *   [s3:CLASES UPP/CLASE UPP 10]
 *   → f=CLASES%20UPP/CLASE%20UPP%2010
 *   → s3://bucket/CLASES UPP/CLASE UPP 10/CLASE UPP 10.m3u8
 */

//  parámetros 
$rawf    = optional_param('f', null, PARAM_RAW_TRIMMED);
$token   = optional_param('t', null, PARAM_ALPHANUMEXT);
$expires = optional_param('e', null, PARAM_INT);

//  validación inicial 
if (empty($rawf)) {
    http_response_code(400);
    echo "Error: falta parámetro f.";
    exit;
}

//  normalizar ruta 
$path = urldecode($rawf);
$path = str_replace('\\', '/', $path);
$path = preg_replace('#/+#', '/', $path);
$path = trim($path, '/');

if (strpos($path, '..') !== false) {
    http_response_code(400);
    echo "Error: ruta inválida.";
    exit;
}

//  construir clave final S3 
$basename = basename($path);
$key = "{$path}/{$basename}.m3u8";

//  control de acceso 
$ip = s3video_get_request_ip();
$authorized = false;
$tokenprovided = false;

if ($token && $expires) {
    $tokenprovided = true;
    $authorized = s3video_validate_token($path, $token, (int)$expires, $ip);
}
if (!$authorized && isloggedin() && !isguestuser()) {
    $authorized = true;
}
if (!$authorized) {
    http_response_code(403);
    echo "# Error: acceso no autorizado.";
    exit;
}

//  configuración CloudFront 
$cloudfrontdomain = s3video_require_env('S3VIDEO_CLOUDFRONT_DOMAIN');
$keypairid        = s3video_require_env('S3VIDEO_CLOUDFRONT_KEYPAIR_ID');
$privatekey       = s3video_require_env('S3VIDEO_CLOUDFRONT_PRIVATE_KEY');

$playlistttl = max(60, (int)s3video_env('S3VIDEO_PLAYLIST_TTL', 600));
$expiresat   = time() + $playlistttl;

$signer = new UrlSigner($keypairid, $privatekey);

//  helper para codificar por segmentos 
$encode = function(string $p): string {
    return implode('/', array_map('rawurlencode', explode('/', $p)));
};

//  URL CloudFront del .m3u8 
$m3u8url = "https://{$cloudfrontdomain}/" . $encode($key);

//  descargar playlist original firmada 
$m3u8content = s3video_fetch_remote_signed($signer, $m3u8url, $expiresat);
if ($m3u8content === false) {
    http_response_code(502);
    echo "# Error: no se pudo obtener la playlist.";
    exit;
}

//  reescribir segmentos relativos 
$basedir = trim(dirname($key), '/');
$lines = preg_split("/\r\n|\n|\r/", $m3u8content);
$rewritten = [];

foreach ($lines as $line) {
    $trim = trim($line);

    if ($trim === '' || $trim[0] === '#') {
        $rewritten[] = $line;
        continue;
    }

    if (preg_match('~^https?://~i', $trim)) {
        // Segmento absoluto → refirmar
        $segmenturl = strtok($trim, '?');
        $rewritten[] = $signer->getSignedUrl($segmenturl, $expiresat);
    } else {
        // Segmento relativo → unir con base
        $segkey = ($basedir === '' ? $trim : $basedir . '/' . ltrim($trim, '/'));
        $absurl = "https://{$cloudfrontdomain}/" . $encode($segkey);
        $rewritten[] = $signer->getSignedUrl($absurl, $expiresat);
    }
}

//  salida final 
header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
header('Cache-Control: private, max-age=60');
echo implode("\n", $rewritten);

/**
 * Descarga una URL firmada y devuelve el cuerpo o false.
 */
function s3video_fetch_remote_signed(UrlSigner $signer, string $url, int $expiresat) {
    $signed = $signer->getSignedUrl($url, $expiresat);
    $ch = curl_init($signed);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Moodle-HLS-Proxy'
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch);
    curl_close($ch);

    if ($err !== 0 || $http >= 400 || stripos($body, '<Error>') !== false) {
        return false;
    }
    return $body;
}
