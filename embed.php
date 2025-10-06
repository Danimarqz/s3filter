<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/filter/s3video/lib.php');

$tokenprovided = false;
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
        $token = null;
        $expires = null;
    }
}

if (!$authorized) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');

    $messages = [];
    if ($tokenprovided) {
        $messages[] = get_string('tokeninvalid', 'filter_s3video');
    }

    if (isloggedin() && !isguestuser()) {
        global $USER;
        $username = format_string(fullname($USER, true));
        $messages[] = get_string('sessionconflict', 'filter_s3video', $username);
        $logouturl = new moodle_url('/login/logout.php', ['sesskey' => sesskey()]);
        $messages[] = '<p><a href="' . s($logouturl->out(false)) . '">' .
            get_string('logoutandretry', 'filter_s3video') . '</a></p>';
    } else {
        $messages[] = get_string('reopenthroughapp', 'filter_s3video');
    }

    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>' .
        get_string('pluginname', 'filter_s3video') . '</title><style>body{font-family:sans-serif;padding:1.5em;background:#111;color:#fff;}a{color:#4fc3f7;}</style></head><body>';
    foreach ($messages as $message) {
        echo '<p>' . $message . '</p>';
    }
    echo '</body></html>';
    exit;
}

$playeroptions = [
    'forceplayer' => true,
];

if ($token && $expires) {
    $playeroptions['token'] = $token;
    $playeroptions['expires'] = (int) $expires;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Video</title>
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
