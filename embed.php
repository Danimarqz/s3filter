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
 * @package   filter_impronta
 */

require_once(__DIR__ . '/../../config.php');

use filter_impronta\player;
use filter_impronta\token;

$rawf = optional_param('f', null, PARAM_RAW_TRIMMED);
$token = optional_param('t', null, PARAM_ALPHANUMEXT);
$expires = optional_param('e', null, PARAM_INT);
$courseid = optional_param('c', 0, PARAM_INT);
$audio = optional_param('a', 0, PARAM_INT);
// Identidad firmada en el token: permite reproducir sin cookie de sesión, que
// es el caso del navegador que abre la app de Moodle. Ver token::generate.
$userid = optional_param('u', 0, PARAM_INT);
$authorizationgroupid = optional_param('g', '', PARAM_ALPHANUMEXT);
$playbackid = optional_param('p', '', PARAM_ALPHANUMEXT);
$mode = optional_param('m', '', PARAM_ALPHA);

/**
 * Renders a standalone dark page with one or more messages and stops.
 */
function impronta_embed_fail(int $status, array $messages): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . s(get_string('pluginname', 'filter_impronta')) . '</title>'
        . '<style>body{font-family:sans-serif;padding:1.5em;background:#111;color:#fff}'
        . 'a{color:#4fc3f7}</style></head><body>';
    foreach ($messages as $message) {
        echo '<p>' . $message . '</p>';
    }
    echo '</body></html>';
    exit;
}

if (empty($rawf)) {
    impronta_embed_fail(400, [s(get_string('missingfilename', 'filter_impronta'))]);
}

$filename = trim(preg_replace('#/+#', '/', str_replace('\\', '/', $rawf)), '/');
if ($filename === '' || strpos($filename, '..') !== false) {
    impronta_embed_fail(400, [s(get_string('missingfilename', 'filter_impronta'))]);
}

if (empty($token) || empty($expires)) {
    impronta_embed_fail(403, [s(get_string('reopenthroughapp', 'filter_impronta'))]);
}

$unused = false;
$denied = token::authorize($filename, $token, (int) $expires, (int) $courseid, (int) $userid, $unused,
    $authorizationgroupid, $playbackid, $mode);
if ($denied !== null) {
    $messages = [s(get_string($denied, 'filter_impronta'))];

    // Caso típico desde el móvil: el enlace se abrió en un navegador donde hay
    // otra sesión de Moodle distinta a la del alumno que lo generó. Decirlo y
    // ofrecer cerrar sesión ahorra el "no funciona" sin más.
    if ($denied === 'notenrolled' && isloggedin() && !isguestuser()) {
        $messages[] = s(get_string('sessionconflict', 'filter_impronta', format_string(fullname($USER, true))));
        $logouturl = new moodle_url('/login/logout.php', ['sesskey' => sesskey()]);
        $messages[] = '<a href="' . s($logouturl->out(false)) . '">'
            . s(get_string('logoutandretry', 'filter_impronta')) . '</a>';
    }

    impronta_embed_fail(403, $messages);
}

$playeroptions = [
    'forceplayer' => true,
    'audio' => (bool) $audio,
    'courseid' => (int) $courseid,
    'token' => $token,
    'expires' => (int) $expires,
    'userid' => (int) $userid,
    'authorizationgroupid' => $authorizationgroupid,
    'playbackid' => $playbackid,
    'mode' => $mode,
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo s(get_string('pluginname', 'filter_impronta')); ?></title>
  <style>
    html,body{
      margin:0;padding:0;background:#000;color:#fff;
      height:100%;width:100%;overflow:hidden
    }
  </style>
</head>
<body>
  <?php echo player::render($filename, $playeroptions); ?>
</body>
</html>
