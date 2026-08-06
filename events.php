<?php
/**
 * Server-side relay for player analytics events.
 *
 * The player beacon posts here instead of calling Reelo directly so the
 * tenant's API key never leaves the Moodle server. Previously the key was
 * inlined in a <script> every learner could read, which defeated the whole
 * access model: that same key mints CloudFront signatures for the entire
 * catalog via POST /moodle/authorize.
 *
 * Access control is the same as playlist.php: the request must carry a
 * valid HMAC token (t/e/c) bound to the video path, and the viewer must be
 * enrolled in the course. Events naming any other video are dropped rather
 * than forwarded.
 *
 * @package   filter_s3video
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/filter/s3video/lib.php');

$rawf = optional_param('f', null, PARAM_RAW_TRIMMED);
$token = optional_param('t', null, PARAM_ALPHANUMEXT);
$expires = optional_param('e', null, PARAM_INT);
$courseid = optional_param('c', 0, PARAM_INT);

/**
 * Ends the request with a bare status code (no body worth leaking).
 */
function s3video_events_fail(int $status): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit;
}

$path = trim(preg_replace('#/+#', '/', str_replace('\\', '/', (string) $rawf)), '/');
if ($path === '' || strpos($path, '..') !== false) {
    s3video_events_fail(400);
}

if (empty($token) || empty($expires)) {
    s3video_events_fail(403);
}

$ip = s3video_get_request_ip();
if (!s3video_validate_token($path, $token, (int) $expires, (int) $courseid, $ip)) {
    s3video_events_fail(403);
}

if ($courseid > 0) {
    if (!s3video_user_can_access_course((int) $courseid)) {
        s3video_events_fail(403);
    }
} else {
    if (s3video_require_course()) {
        s3video_events_fail(403);
    }
    if (!isloggedin() || isguestuser()) {
        s3video_events_fail(403);
    }
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    s3video_events_fail(400);
}

// El subject es determinista desde $USER->id y el secret del plugin
// (s3video_analytics_subject): recalcularlo aqui e ignorar el del payload,
// para que el cliente no pueda reportar analitica bajo un identificador
// ajeno. Este endpoint solo deja pasar a usuarios logueados no invitados,
// asi que el subject siempre es no vacio.
$subject = s3video_analytics_subject();
$events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : [];
if (empty($events)) {
    s3video_events_fail(400);
}

// El token es de un solo video: descartar cualquier evento que nombre otro
// (defensa en profundidad, el cliente nunca debería mandarlos).
$clean = [];
foreach ($events as $ev) {
    if (!is_array($ev)) {
        continue;
    }
    $vp = isset($ev['videoPath']) && is_string($ev['videoPath']) ? $ev['videoPath'] : '';
    $type = isset($ev['type']) && is_string($ev['type']) ? $ev['type'] : '';
    if ($vp !== $path || $type === '') {
        continue;
    }
    $clean[] = [
        'videoPath' => $vp,
        'type' => $type,
        'positionSeconds' => isset($ev['positionSeconds']) && is_numeric($ev['positionSeconds']) ? (int) $ev['positionSeconds'] : 0,
        'ts' => isset($ev['ts']) && is_numeric($ev['ts']) ? (int) $ev['ts'] : (int) (microtime(true) * 1000),
    ];
}

if (empty($clean)) {
    s3video_events_fail(400);
}

if (!s3video_post_events(['subject' => $subject, 'events' => $clean])) {
    s3video_events_fail(502);
}

http_response_code(204);
