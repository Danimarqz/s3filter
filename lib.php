<?php
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/locallib.php');

/**
 * Cached loader for values stored in .env or environment variables.
 *
 * @param string $key
 * @param mixed $default
 * @return mixed|null
 */
function s3video_env(string $key, $default = null) {
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        $paths = [];
        $custompath = getenv('S3VIDEO_ENV_PATH');

        if ($custompath) {
            $paths[] = $custompath;
        }

        global $CFG;
        if (!empty($CFG->dataroot)) {
            $paths[] = rtrim($CFG->dataroot, '/\\') . '/cloudfront/.env';
        }

        foreach ($paths as $envpath) {
            if (!is_string($envpath) || $envpath === '') {
                continue;
            }
            if (!is_readable($envpath)) {
                continue;
            }

            $lines = file($envpath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || $trimmed[0] === '#') {
                    continue;
                }
                [$envkey, $envvalue] = array_map('trim', array_pad(explode('=', $line, 2), 2, ''));
                $envvalue = trim($envvalue, '"\'');
                if ($envkey !== '') {
                    $cache[$envkey] = $envvalue;
                }
            }
            break;
        }
    }

    if (array_key_exists($key, $cache)) {
        $cached = $cache[$key];
        return $cached === false ? $default : $cached;
    }

    $value = getenv($key);
    $cache[$key] = $value;
    return $value === false ? $default : $value;
}

/**
 * Helper to retrieve an environment variable or throw when missing.
 *
 * @param string $key
 * @return string
 * @throws coding_exception
 */
function s3video_require_env(string $key): string {
    $value = s3video_env($key);
    if ($value === null || $value === '') {
        throw new coding_exception('Missing required S3 video configuration: ' . $key);
    }
    return $value;
}

/**
 * Determines whether tokens should be bound to the requester IP.
 *
 * @return bool
 */
function s3video_token_bind_ip(): bool {
    $raw = s3video_env('S3VIDEO_BIND_IP', '0');
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
}

/**
 * Attempts to detect the request IP in a proxy friendly way.
 *
 * @return string
 */
function s3video_get_request_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }
        $value = trim(explode(',', $candidate)[0]);
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    return '0.0.0.0';
}

/**
 * Checks whether the user has an active manual enrolment in any course.
 *
 * @param int $userid
 * @return bool
 */
function s3video_user_has_manual_enrolment(int $userid): bool {
    global $DB;

    static $cache = [];

    if ($userid <= 0) {
        return false;
    }

    if (array_key_exists($userid, $cache)) {
        return $cache[$userid];
    }

    $now = time();
    $params = [
        'userid'      => $userid,
        'manual'      => 'manual',
        'userstatus'  => ENROL_USER_ACTIVE,
        'enrolstatus' => ENROL_INSTANCE_ENABLED,
        'now'         => $now,
    ];

    $sql = "SELECT 1
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                WHERE ue.userid = :userid
                AND e.enrol = :manual
                AND ue.status = :userstatus
                AND e.status = :enrolstatus
                AND (ue.timeend = 0 OR ue.timeend > :now)
                AND (ue.timestart = 0 OR ue.timestart <= :now)
                LIMIT 1";

    $cache[$userid] = $DB->record_exists_sql($sql, $params);
    return $cache[$userid];
}


/**
 * Checks if the current user agent belongs to Moodle App.
 *
 * @param bool $forceplayer
 * @return bool
 */
function s3video_is_mobile_app(bool $forceplayer): bool {
    if ($forceplayer) {
        return false;
    }

    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return stripos($agent, 'MoodleMobile') !== false;
}

/**
 * Builds the signed token shared by embed.php and playlist.php.
 *
 * @param string $filename
 * @param int $expires
 * @param string $ip
 * @return string
 * @throws coding_exception
 */
function s3video_generate_token(string $filename, int $expires, string $ip): string {
    $secret = s3video_require_env('S3VIDEO_SECRET_KEY');
    $payload = "{$filename}|{$expires}";

    if (s3video_token_bind_ip()) {
        $payload .= "|{$ip}";
    }

    return hash_hmac('sha256', $payload, $secret);
}

/**
 * Validates the shared token.
 *
 * @param string $filename
 * @param string $token
 * @param int $expires
 * @param string $ip
 * @return bool
 * @throws coding_exception
 */
function s3video_validate_token(string $filename, string $token, int $expires, string $ip): bool {
    if (time() > $expires) {
        return false;
    }
    $secret = s3video_require_env('S3VIDEO_SECRET_KEY');
    $payload = "{$filename}|{$expires}";

    if (s3video_token_bind_ip()) {
        $payload .= "|{$ip}";
    }

    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $token);
}

/**
 * Generates the HTML player.
 *
 * @param string $filename
 * @param array $options
 * @return string
 * @throws coding_exception
 */
function s3video_player(string $filename, array $options = []): string {
    global $CFG, $PAGE;

    $defaults = [
        'durationseconds' => 600,
        'forceplayer' => false,
        'token' => null,
        'expires' => null,
        'playbackrates' => [0.5, 0.75, 1, 1.25, 1.5, 2],
    ];
    $options = array_merge($defaults, $options);

    $cacheable = empty($options['token']) && empty($options['expires']) && empty($options['forceplayer']);
    static $rendercache = [];

    $is_mobile_app = s3video_is_mobile_app($options['forceplayer']);
    if ($is_mobile_app) {
        $cacheable = false;
    }

    if ($cacheable && isset($rendercache[$filename])) {
        return $rendercache[$filename];
    }

    $playlistparams = ['f' => $filename];
    if (!empty($options['token']) && !empty($options['expires'])) {
        $playlistparams['t'] = $options['token'];
        $playlistparams['e'] = (int) $options['expires'];
    }
    $playlisturl = new moodle_url('/filter/s3video/playlist.php', $playlistparams);

    if ($is_mobile_app) {
        $tokenttl = (int) s3video_env('S3VIDEO_TOKEN_TTL', 300);
        $tokenttl = max(60, $tokenttl);
        $expires = time() + $tokenttl;
        $ip = s3video_get_request_ip();
        $token = s3video_generate_token($filename, $expires, $ip);

        $iframeparams = [
            'f' => $filename,
            't' => $token,
            'e' => $expires,
        ];
        $iframeurl = new moodle_url('/filter/s3video/embed.php', $iframeparams);

        $buttontext = get_string('openvideo', 'filter_s3video');
        $infotext = get_string('openvideoinfo', 'filter_s3video');

        $iframehref = s($iframeurl->out(false));

        $html = <<<HTML
    <div style="text-align:center; padding:1em;">
    <a href="{$iframehref}" target="_blank"
        style="display:inline-block; background:#1976d2; color:#fff;
                padding:0.8em 1.2em; border-radius:6px;
                font-weight:600; text-decoration:none;">
        {$buttontext}
    </a>
    <p style="font-size:0.9em;color:#666;margin-top:0.5em;">
        {$infotext}
    </p>
    </div>
    HTML;

        return $html;
    }

    static $assetsregistered = false;
    $assets = '';
    if (!$assetsregistered) {
        $assetsregistered = true;
        if (isset($PAGE) && isset($PAGE->requires)) {
            $PAGE->requires->css(new moodle_url('https://vjs.zencdn.net/8.16.1/video-js.css'));
            $PAGE->requires->js(new moodle_url('https://vjs.zencdn.net/8.16.1/video.min.js'));
        } else {
            $assets = <<<HTML
<link href="https://vjs.zencdn.net/8.16.1/video-js.css" rel="stylesheet" />
<script src="https://vjs.zencdn.net/8.16.1/video.min.js"></script>
HTML;
        }
    }

    $escapedid = preg_replace('/[^A-Za-z0-9\-_:.]/', '-', basename($filename));
    $escapedid = 'vjs_' . $escapedid;
    $setupconfig = ['fluid' => true];

    if (!empty($options['playbackrates']) && is_array($options['playbackrates'])) {
        $normalized = [];
        foreach ($options['playbackrates'] as $rate) {
            $rate = (float) $rate;
            if ($rate > 0) {
                $normalized[] = $rate;
            }
        }
        if (!empty($normalized)) {
            $normalized = array_values(array_unique($normalized));
            sort($normalized, SORT_NUMERIC);
            $setupconfig['playbackRates'] = $normalized;
        }
    }

    $setupjson = json_encode($setupconfig, JSON_UNESCAPED_SLASHES);
    $setupattr = s($setupjson);
    $playlistsrc = s($playlisturl->out(false));

    $assetsmarkup = $assets === '' ? '' : $assets . "\n";

    $html = <<<HTML
{$assetsmarkup}<video id="{$escapedid}" class="video-js vjs-default-skin vjs-fluid"
       controls preload="auto" data-setup='{$setupattr}'>
  <source src="{$playlistsrc}" type="application/x-mpegURL">
</video>
<script>
document.addEventListener('DOMContentLoaded', function() {
  if (typeof videojs !== 'undefined') {
    const player = videojs('{$escapedid}');
    player.ready(function() {
      player.playbackRate(1); // velocidad inicial
    });
  }
});
</script>
HTML;

    if ($cacheable && $assets === '') {
        $rendercache[$filename] = $html;
    }

    return $html;
}
