<?php
defined('MOODLE_INTERNAL') || die();

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
        $envpath = __DIR__ . '/.env';

        if (is_readable($envpath)) {
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
        }
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $value = getenv($key);
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
    global $CFG;

    $defaults = [
        'durationseconds' => 600,
        'forceplayer' => false,
        'token' => null,
        'expires' => null,
    ];
    $options = array_merge($defaults, $options);

    $is_mobile_app = s3video_is_mobile_app($options['forceplayer']);

    $playlistparams = ['f' => $filename];
    if (!empty($options['token']) && !empty($options['expires'])) {
        $playlistparams['t'] = $options['token'];
        $playlistparams['e'] = (int) $options['expires'];
    }
    $playlist_url = $CFG->wwwroot . '/filter/s3video/playlist.php?' . http_build_query($playlistparams);

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
        $iframe_url = $CFG->wwwroot . '/filter/s3video/embed.php?' . http_build_query($iframeparams);

        $buttontext = get_string('openvideo', 'filter_s3video');
        $infotext = get_string('openvideoinfo', 'filter_s3video');

        return <<<HTML
    <div style="text-align:center; padding:1em;">
    <a href="{$iframe_url}" target="_blank"
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
    }

    $escapedid = preg_replace('/[^A-Za-z0-9\-_:.]/', '-', $filename);
    $escapedid = 'vjs_' . $escapedid;

    $html = <<<HTML
<link href="https://vjs.zencdn.net/8.16.1/video-js.css" rel="stylesheet" />
<video id="{$escapedid}" class="video-js vjs-default-skin vjs-fluid"
       controls preload="auto" data-setup='{"fluid": true}'>
  <source src="{$playlist_url}" type="application/x-mpegURL">
</video>
<script src="https://vjs.zencdn.net/8.16.1/video.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  if (typeof videojs !== 'undefined') videojs('{$escapedid}');
});
</script>
HTML;

    return $html;
}
