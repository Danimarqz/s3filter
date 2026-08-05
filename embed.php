<?php
/**
 * Full-page player, opened in a new tab.
 *
 * Used by the "Abrir vídeo" button and by the Moodle mobile app, whose
 * webview cannot run the inline player. It is a standalone page rather than
 * an iframe on purpose: the token in the URL is single-purpose and the page
 * renders nothing but the player.
 *
 * Access control is the same as playlist.php — a token that names the
 * course, plus a live enrolment check — because this endpoint is reachable
 * directly and must not be weaker than the one it links to.
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

/**
 * Renders a standalone dark page with one or more messages and stops.
 */
function s3video_embed_fail(int $status, array $messages): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . s(get_string('pluginname', 'filter_s3video')) . '</title>'
        . '<style>body{font-family:sans-serif;padding:1.5em;background:#111;color:#fff}'
        . 'a{color:#4fc3f7}</style></head><body>';
    foreach ($messages as $message) {
        echo '<p>' . $message . '</p>';
    }
    echo '</body></html>';
    exit;
}

if (empty($rawf)) {
    s3video_embed_fail(400, [s(get_string('missingfilename', 'filter_s3video'))]);
}

$filename = trim(preg_replace('#/+#', '/', str_replace('\\', '/', $rawf)), '/');
if ($filename === '' || strpos($filename, '..') !== false) {
    s3video_embed_fail(400, [s(get_string('missingfilename', 'filter_s3video'))]);
}

if (empty($token) || empty($expires)) {
    s3video_embed_fail(403, [s(get_string('reopenthroughapp', 'filter_s3video'))]);
}

$ip = s3video_get_request_ip();
if (!s3video_validate_token($filename, $token, (int) $expires, (int) $courseid, $ip)) {
    s3video_embed_fail(403, [s(get_string('tokeninvalid', 'filter_s3video'))]);
}

if ($courseid > 0) {
    if (!s3video_user_can_access_course((int) $courseid)) {
        $messages = [s(get_string('notenrolled', 'filter_s3video'))];
        if (isloggedin() && !isguestuser()) {
            $messages[] = s(get_string('sessionconflict', 'filter_s3video', format_string(fullname($USER, true))));
            $logouturl = new moodle_url('/login/logout.php', ['sesskey' => sesskey()]);
            $messages[] = '<a href="' . s($logouturl->out(false)) . '">'
                . s(get_string('logoutandretry', 'filter_s3video')) . '</a>';
        }
        s3video_embed_fail(403, $messages);
    }
} else {
    if (s3video_require_course()) {
        s3video_embed_fail(403, [s(get_string('nocoursecontext', 'filter_s3video'))]);
    }
    if (!isloggedin() || isguestuser()) {
        s3video_embed_fail(403, [s(get_string('reopenthroughapp', 'filter_s3video'))]);
    }
}

$playeroptions = [
    'forceplayer' => true,
    'audio' => (bool) $audio,
    'courseid' => (int) $courseid,
    'token' => $token,
    'expires' => (int) $expires,
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo s(get_string('pluginname', 'filter_s3video')); ?></title>
  <style>
    html,body{
      margin:0;padding:0;background:#000;color:#fff;
      height:100%;width:100%;overflow:hidden
    }
  </style>
</head>
<body>
  <?php echo s3video_player($filename, $playeroptions); ?>
</body>
</html>