<?php
/**
 * Serves an HLS playlist (.m3u8) or a WebVTT subtitle track, rewritten so
 * the browser can fetch the media straight from CloudFront.
 *
 * ACCESS CONTROL — both layers apply to every request here, including
 * renditions and subtitles:
 *
 *   1. The signed `c` (course) parameter must survive HMAC validation and
 *      the viewer must be currently enrolled in that course.
 *   2. The CloudFront signature is requested from Reelo with this site's
 *      API key, and Reelo scopes it to this class alone.
 *
 * @package   filter_s3video
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/filter/s3video/lib.php');

$rawf = optional_param('f', null, PARAM_RAW_TRIMMED);
$token = optional_param('t', null, PARAM_ALPHANUMEXT);
$expires = optional_param('e', null, PARAM_INT);
$courseid = optional_param('c', 0, PARAM_INT);
$audio = optional_param('a', 0, PARAM_INT);
$rawr = optional_param('r', null, PARAM_RAW_TRIMMED);
$rawvtt = optional_param('vtt', null, PARAM_ALPHANUMEXT);

/**
 * Ends the request with a plain-text error.
 */
function s3video_playlist_fail(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo '# ' . $message;
    exit;
}

if (empty($rawf)) {
    s3video_playlist_fail(400, 'Falta el parámetro f.');
}

// `r` (rendition): .m3u8 basename, no path separators.
$rendition = null;
if ($rawr !== null && $rawr !== '') {
    $candidate = basename(str_replace('\\', '/', $rawr));
    if ($candidate !== $rawr || strpos($rawr, '..') !== false || !s3video_is_safe_rendition_name($candidate)) {
        s3video_playlist_fail(400, 'Parámetro r inválido.');
    }
    $rendition = $candidate;
}

// `vtt` (subtitle language): 2-5 alphanumeric characters.
$vttlang = null;
if ($rawvtt !== null && $rawvtt !== '') {
    $candidatevtt = strtolower($rawvtt);
    if (!preg_match('/^[a-z0-9\-]{2,5}$/', $candidatevtt)) {
        s3video_playlist_fail(400, 'Idioma de subtítulos inválido.');
    }
    $vttlang = $candidatevtt;
}

// Normalise the logical path.
$path = trim(preg_replace('#/+#', '/', str_replace('\\', '/', $rawf)), '/');
if ($path === '' || strpos($path, '..') !== false) {
    s3video_playlist_fail(400, 'Ruta inválida.');
}

/* --------------------------------------------------------------------- */
/* Layer 2: signed course + live enrolment                                */
/* --------------------------------------------------------------------- */

if (empty($token) || empty($expires)) {
    s3video_playlist_fail(403, get_string('reopenthroughapp', 'filter_s3video'));
}

$ip = s3video_get_request_ip();
if (!s3video_validate_token($path, $token, (int) $expires, (int) $courseid, $ip)) {
    s3video_playlist_fail(403, get_string('tokeninvalid', 'filter_s3video'));
}

if ($courseid > 0) {
    if (!s3video_user_can_access_course((int) $courseid)) {
        s3video_playlist_fail(403, get_string('notenrolled', 'filter_s3video'));
    }
} else {
    if (s3video_require_course()) {
        s3video_playlist_fail(403, get_string('nocoursecontext', 'filter_s3video'));
    }
    if (!isloggedin() || isguestuser()) {
        s3video_playlist_fail(403, get_string('reopenthroughapp', 'filter_s3video'));
    }
}

/* --------------------------------------------------------------------- */
/* Layer 1: CloudFront signature from Reelo                               */
/* --------------------------------------------------------------------- */

// No existe un prefijo "audio/": los renditions de video y audio viven
// juntos bajo Materia/Clase/, y la policy de "Materia/Clase/*" ya cubre el
// audio. Firmar "audio/Materia/Clase" cambiaba el ambito a base/audio/
// Materia/* (toda una 'materia' inexistente) y fallaba con 502.
$signpath = $path;

$reason = null;
$signature = s3video_fetch_signature($signpath, $reason);
if ($signature === null) {
    $message = $reason === 'denied'
        ? get_string('servicedenied', 'filter_s3video')
        : get_string('servicedown', 'filter_s3video');
    s3video_playlist_fail($reason === 'denied' ? 403 : 503, $message);
}

$basename = basename($path);
// El master no siempre se llama como la clase: el backend devuelve el
// nombre real (videos.master) en la respuesta de /moodle/authorize y la
// firma lo cachea. Fallback a la convencion "{clase}.m3u8" para firmas
// antiguas.
$mastername = !empty($signature['master']) ? $signature['master'] : $basename . '.m3u8';
$masterkey = "{$signpath}/{$mastername}";
$key = $rendition !== null ? "{$signpath}/{$rendition}" : $masterkey;

/* --------------------------------------------------------------------- */
/* Subtitles: {path}/subs/{lang}.vtt, served without rewriting            */
/* --------------------------------------------------------------------- */

if ($vttlang !== null) {
    $vtturl = s3video_sign_url(
        $signature['baseurl'] . '/' . s3video_encode_key("{$signpath}/subs/{$vttlang}.vtt"),
        $signature
    );
    $vttcontent = s3video_fetch_remote($vtturl);
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
/* --------------------------------------------------------------------- */

$m3u8url = s3video_sign_url($signature['baseurl'] . '/' . s3video_encode_key($key), $signature);
$m3u8content = s3video_fetch_remote($m3u8url);
if ($m3u8content === false) {
    s3video_playlist_fail(502, 'No se pudo obtener la playlist.');
}

if ($rendition === null && s3video_is_master_playlist($m3u8content)) {
    // Master playlist: point the renditions back at this script.
    $output = s3video_rewrite_master_playlist($m3u8content, $path, (int) $courseid, $ip, (bool) $audio);
} else {
    // Media playlist: turn every segment into a signed CloudFront URL.
    $basedir = trim(dirname($key), '/');

    $resolvesegment = function (string $seg) use ($basedir, $signature): string {
        if (strncasecmp($seg, 'http://', 7) === 0 || strncasecmp($seg, 'https://', 8) === 0) {
            return s3video_sign_url(strtok($seg, '?'), $signature);
        }
        $segkey = ($basedir === '' ? $seg : $basedir . '/' . ltrim($seg, '/'));
        return s3video_sign_url($signature['baseurl'] . '/' . s3video_encode_key($segkey), $signature);
    };

    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $m3u8content));
    $rewritten = [];

    foreach ($lines as $line) {
        $trim = trim($line);

        if ($trim === '' || $trim[0] === '#') {
            if (stripos($trim, '#EXT-X-MAP:') === 0 && preg_match('/URI="([^"]*)"/', $trim, $m) && $m[1] !== '') {
                $line = preg_replace('/URI="[^"]*"/', 'URI="' . $resolvesegment($m[1]) . '"', $line, 1);
            }
            $rewritten[] = $line;
            continue;
        }

        $rewritten[] = $resolvesegment($trim);
    }

    $output = implode("\n", $rewritten);
}

header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
header('Cache-Control: private, max-age=60');
echo $output;