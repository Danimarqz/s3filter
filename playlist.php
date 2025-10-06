<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/filter/s3video/lib.php');
require_once($CFG->dirroot . '/lib/aws-sdk/src/CloudFront/UrlSigner.php');

use Aws\CloudFront\UrlSigner;

$filename = required_param('f', PARAM_ALPHANUMEXT);
$token = optional_param('t', null, PARAM_ALPHANUMEXT);
$expires = optional_param('e', null, PARAM_INT);

$ip = s3video_get_request_ip();
$authorized = false;

if ($token && $expires) {
    $tokenprovided = true;
    $authorized = s3video_validate_token($filename, $token, (int) $expires, $ip);
}

if (!$authorized) {
    if (isloggedin() && !isguestuser()) {
        $authorized = true;
    }
}

if (!$authorized) {
    http_response_code(403);
    if ($tokenprovided ?? false) {
        echo "#EXTM3U\n# Error: " . get_string('tokeninvalid', 'filter_s3video');
        if (isloggedin() && !isguestuser()) {
            global $USER;
            $username = format_string(fullname($USER, true));
            echo "\n# " . get_string('sessionconflict', 'filter_s3video', $username);
        } else {
            echo "\n# " . get_string('reopenthroughapp', 'filter_s3video');
        }
    } else {
        echo "#EXTM3U\n# Error: Acceso no autorizado.";
    }
    exit;
}

$cloudfrontdomain = s3video_require_env('S3VIDEO_CLOUDFRONT_DOMAIN');
$keypairid = s3video_require_env('S3VIDEO_CLOUDFRONT_KEYPAIR_ID');
$privatekey = s3video_require_env('S3VIDEO_CLOUDFRONT_PRIVATE_KEY');

$playlistttl = (int) s3video_env('S3VIDEO_PLAYLIST_TTL', 600);
$playlistttl = max(60, $playlistttl);
$expiresat = time() + $playlistttl;

$signer = new UrlSigner($keypairid, $privatekey);
$m3u8url = "https://{$cloudfrontdomain}/{$filename}/{$filename}.m3u8";

try {
    $signedm3u8 = $signer->getSignedUrl($m3u8url, $expiresat);
    $m3u8content = s3video_fetch_remote($signedm3u8);
    if ($m3u8content === false || stripos($m3u8content, '<Error>') !== false) {
        throw new Exception('No se pudo obtener la playlist original.');
    }
} catch (Exception $e) {
    http_response_code(502);
    echo "#EXTM3U\n# Error: " . $e->getMessage();
    exit;
}

$lines = preg_split("/\r\n|\n|\r/", $m3u8content);
$rewritten = [];

foreach ($lines as $line) {
    $trim = trim($line);
    if ($trim === '' || $trim[0] === '#') {
        $rewritten[] = $line;
        continue;
    }

    $segmenturl = preg_match('~^https?://~i', $trim)
        ? strtok($trim, '?')
        : "https://{$cloudfrontdomain}/{$filename}/" . ltrim($trim, '/');

    $rewritten[] = $signer->getSignedUrl($segmenturl, $expiresat);
}

header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
header('Cache-Control: private, max-age=60');
echo implode("\n", $rewritten);

function s3video_fetch_remote(string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Moodle-HLS-Proxy'
    ]);
    $body = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch);
    curl_close($ch);

    if ($error !== 0 || $httpcode >= 400) {
        return false;
    }

    return $body;
}
