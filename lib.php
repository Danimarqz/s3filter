<?php
/**
 * S3 Video filter for Moodle — shared library.
 *
 * Embeds HLS video hosted on a Reelo tenant's private CloudFront distribution
 * into Moodle pages, behind two independent layers of access control:
 *
 *   Layer 1 (Reelo). This site authenticates to the Reelo backend with its
 *     tenant API key and receives a CloudFront signature scoped to one
 *     Materia/Clase prefix and expiring on its own. The CloudFront private
 *     key never leaves Reelo's AWS account, so a compromised Moodle server
 *     cannot mint URLs, and disabling the tenant in Reelo's console stops
 *     playback here within one cache TTL.
 *
 *   Layer 2 (Moodle). Before any of that, this plugin checks the viewer is
 *     enrolled in the course the video was embedded in. Reelo cannot make
 *     that check - Moodle owns the enrolments - and the course identity is
 *     taken from the filter's render context and then HMAC-signed into the
 *     token, so it is not something the browser can change.
 *
 * Neither layer is sufficient alone. Layer 1 proves the request comes from
 * this site; layer 2 proves this learner may see this video.
 *
 * The player also burns a per-user watermark (configurable fields, e.g.
 * "name - profile_field_dni") and reports playback + tamper analytics to
 * Reelo's POST /events.
 *
 * @package   filter_s3video
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

/**
 * Backend base URL. Deliberately a constant, not editable from the Moodle
 * settings: the plugin is tied to this Reelo deployment.
 */
define('S3VIDEO_API_URL', 'https://videos.dmarquez.com');

/* --------------------------------------------------------------------- */
/* Settings (Moodle admin configuration)                                  */
/* --------------------------------------------------------------------- */

/**
 * Reads a plugin setting from Moodle admin settings (filter_s3video/...).
 *
 * @param string $name
 * @param mixed $default
 * @return mixed
 */
function s3video_setting(string $name, $default = null) {
    $value = get_config('filter_s3video', $name);
    return ($value === false) ? $default : $value;
}

/**
 * Reads a required plugin setting, throwing when missing.
 *
 * @param string $name
 * @return string
 * @throws coding_exception
 */
function s3video_require_setting(string $name): string {
    $value = s3video_setting($name);
    if ($value === null || $value === '') {
        throw new coding_exception('Missing required Reelo configuration: filter_s3video/' . $name);
    }
    return (string) $value;
}

/* --------------------------------------------------------------------- */
/* Request helpers                                                        */
/* --------------------------------------------------------------------- */

/**
 * Whether internal tokens should be bound to the requester's IP.
 *
 * @return bool
 */
function s3video_token_bind_ip(): bool {
    $raw = s3video_setting('bindip', '0');
    return filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
}

/**
 * Best-effort request IP, tolerating the usual reverse proxies.
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

/* --------------------------------------------------------------------- */
/* Watermark label                                                        */
/* --------------------------------------------------------------------- */

/**
 * Builds the watermark label from the configured template.
 *
 * The template (setting `watermarktemplate`, default "name - dni") can use
 * any of these tokens, replaced with the current user's data; everything
 * else is kept literally:
 *
 *   - "name"               -> fullname($USER)
 *   - "dni"                -> $USER->idnumber
 *   - "profile_field_XXX"  -> custom profile field XXX
 *
 * Examples:
 *   "name - dni"                    -> "Juan Pérez - 12345678A"
 *   "name - profile_field_nif"      -> "Juan Pérez - B98765432"
 *   "profile_field_centro"          -> "IES Ejemplo"
 *
 * @param \stdClass $user
 * @return string
 */
function s3video_build_watermark_label(\stdClass $user): string {
    global $CFG;

    $template = trim((string) s3video_setting('watermarktemplate', 'name - dni'));
    if ($template === '') {
        return '';
    }

    // Load custom profile fields if the template references any.
    if (stripos($template, 'profile_field_') !== false) {
        require_once($CFG->dirroot . '/user/profile/lib.php');
        if (function_exists('profile_load_data')) {
            profile_load_data($user);
        }
    }

    $fullname = format_string(fullname($user));
    $dni = (string) ($user->idnumber ?? '');

    // Resolve the exact tokens referenced by the template.
    $tokens = [];
    if (preg_match_all('/\b(name|dni|profile_field_[A-Za-z0-9_]+)\b/', $template, $matches)) {
        foreach (array_unique($matches[1]) as $token) {
            if ($token === 'name') {
                $tokens[$token] = $fullname;
            } elseif ($token === 'dni') {
                $tokens[$token] = $dni;
            } else {
                $tokens[$token] = (string) (isset($user->{$token}) ? $user->{$token} : '');
            }
        }
    }

    if (empty($tokens)) {
        return trim($template);
    }

    $result = preg_replace_callback(
        '/\b(name|dni|profile_field_[A-Za-z0-9_]+)\b/',
        function ($m) use ($tokens) {
            return $tokens[$m[0]] ?? $m[0];
        },
        $template
    );

    // Collapse repeated whitespace around empty tokens ("name -  " -> "name").
    $result = trim(preg_replace('/\s+/', ' ', (string) $result));
    return $result;
}

/* --------------------------------------------------------------------- */
/* Layer 2: the course the video was embedded in                          */
/* --------------------------------------------------------------------- */

/**
 * Resolves the course id a filter context belongs to.
 *
 * @param \context|null $context
 * @return int course id, or 0 when the context is not inside a course
 */
function s3video_courseid_from_context($context): int {
    if (!$context instanceof \context) {
        return 0;
    }
    $coursecontext = $context->get_course_context(false);
    if (!$coursecontext) {
        return 0;
    }
    // SITEID is the front page, which would defeat the check.
    if ((int) $coursecontext->instanceid === (int) SITEID) {
        return 0;
    }
    return (int) $coursecontext->instanceid;
}

/**
 * Whether the current user may watch content belonging to a course.
 *
 * Active enrolment is the rule for learners. Managers/teachers who can view
 * a course without being enrolled are allowed through the capability check,
 * so a teacher can preview their own material.
 *
 * @param int $courseid
 * @return bool
 */
function s3video_user_can_access_course(int $courseid): bool {
    if ($courseid <= 0) {
        return false;
    }
    if (!isloggedin() || isguestuser()) {
        return false;
    }

    $context = context_course::instance($courseid, IGNORE_MISSING);
    if (!$context) {
        return false;
    }

    if (is_enrolled($context, null, '', true)) {
        return true;
    }

    return has_capability('moodle/course:view', $context);
}

/**
 * Whether a video with no course context may still be played.
 *
 * @return bool
 */
function s3video_require_course(): bool {
    $raw = s3video_setting('requirecourse', '1');
    return filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
}

/**
 * Builds the internal token shared by embed.php and playlist.php.
 *
 * The course id is part of the signed payload: the course is decided by the
 * filter from the render context, and the browser cannot substitute a
 * different one without invalidating the HMAC.
 *
 * @param string $filename logical Materia/Clase path
 * @param int $expires unix timestamp
 * @param int $courseid course the video was embedded in (0 = none)
 * @param string $ip
 * @return string
 * @throws coding_exception
 */
function s3video_generate_token(string $filename, int $expires, int $courseid, string $ip): string {
    $secret = s3video_require_setting('secretkey');
    $payload = "{$filename}|{$expires}|{$courseid}";

    if (s3video_token_bind_ip()) {
        $payload .= "|{$ip}";
    }

    return hash_hmac('sha256', $payload, $secret);
}

/**
 * Validates a token produced by s3video_generate_token().
 *
 * @param string $filename
 * @param string $token
 * @param int $expires
 * @param int $courseid
 * @param string $ip
 * @return bool
 */
function s3video_validate_token(string $filename, string $token, int $expires, int $courseid, string $ip): bool {
    if (time() > $expires) {
        return false;
    }
    $expected = s3video_generate_token($filename, $expires, $courseid, $ip);
    return hash_equals($expected, $token);
}

/* --------------------------------------------------------------------- */
/* Layer 1: CloudFront signatures issued by Reelo                          */
/* --------------------------------------------------------------------- */

/**
 * Obtains a CloudFront signature for a class prefix from the Reelo backend.
 *
 * Returns an array with:
 *   baseurl   - media root for this tenant, no trailing slash
 *   query     - "Policy=...&Signature=...&Key-Pair-Id=...", ready to append
 *   expiresat - unix timestamp the signature stops working at
 *
 * One signature covers the master playlist, both renditions, every segment
 * and the subtitle tracks of that class, because Reelo signs a custom policy
 * whose resource ends in "*". Cached until shortly before expiry.
 *
 * @param string $path logical Materia/Clase path
 * @param string|null $reason out-param: 'denied', 'unavailable' or null
 * @return array|null null on failure
 */
function s3video_fetch_signature(string $path, ?string &$reason = null): ?array {
    $reason = null;

    $cache = cache::make_from_params(cache_store::MODE_APPLICATION, 'filter_s3video', 'signatures');
    $cachekey = sha1($path);

    $cached = $cache->get($cachekey);
    if (is_array($cached) && isset($cached['expiresat']) && $cached['expiresat'] > time() + 300) {
        return $cached;
    }

    $apiurl = rtrim(S3VIDEO_API_URL, '/');
    $apikey = s3video_require_setting('apikey');

    $payload = ['videoPath' => $path];
    $subject = s3video_analytics_subject();
    if ($subject !== '') {
        $payload['subject'] = $subject;
    }

    $curl = new \curl();
    $curl->setHeader([
        'Authorization: Bearer ' . $apikey,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    $response = $curl->post($apiurl . '/moodle/authorize', json_encode($payload), [
        'CURLOPT_TIMEOUT' => 10,
        'CURLOPT_CONNECTTIMEOUT' => 5,
        'CURLOPT_FOLLOWLOCATION' => 0,
    ]);

    $info = $curl->get_info();
    $httpcode = (int) ($info['http_code'] ?? 0);

    if ($curl->get_errno()) {
        debugging('filter_s3video: Reelo API unreachable: ' . $curl->error, DEBUG_NORMAL);
        $reason = 'unavailable';
        return null;
    }
    if ($httpcode === 401 || $httpcode === 403 || $httpcode === 409) {
        // Bad/revoked API key, or a disabled tenant. Not transient.
        debugging('filter_s3video: Reelo API rejected this site (HTTP ' . $httpcode . ')', DEBUG_NORMAL);
        $reason = 'denied';
        return null;
    }
    if ($httpcode < 200 || $httpcode >= 300) {
        debugging('filter_s3video: Reelo API returned HTTP ' . $httpcode, DEBUG_NORMAL);
        $reason = 'unavailable';
        return null;
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)
            || empty($decoded['baseUrl'])
            || empty($decoded['policy'])
            || empty($decoded['signature'])
            || empty($decoded['keyPairId'])) {
        debugging('filter_s3video: malformed response from the Reelo API', DEBUG_NORMAL);
        $reason = 'unavailable';
        return null;
    }

    $query = 'Policy=' . $decoded['policy']
        . '&Signature=' . $decoded['signature']
        . '&Key-Pair-Id=' . $decoded['keyPairId'];

    $ttl = isset($decoded['ttlSeconds']) ? (int) $decoded['ttlSeconds'] : 3600;
    $result = [
        'baseurl' => rtrim((string) $decoded['baseUrl'], '/'),
        'query' => $query,
        'expiresat' => time() + max(600, $ttl),
    ];

    $cache->set($cachekey, $result);
    return $result;
}

/**
 * Appends a signature's query string to a CloudFront URL.
 *
 * @param string $url
 * @param array $signature as returned by s3video_fetch_signature()
 * @return string
 */
function s3video_sign_url(string $url, array $signature): string {
    $separator = strpos($url, '?') === false ? '?' : '&';
    return $url . $separator . $signature['query'];
}

/**
 * Percent-encodes a key path segment by segment, leaving the slashes.
 *
 * @param string $path
 * @return string
 */
function s3video_encode_key(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * Relays a batch of analytics events to Reelo's POST /events with this
 * site's tenant API key. Called only from events.php, server-side: the API
 * key must never appear in a page the learner's browser can read.
 *
 * @param array $payload {subject, events:[{videoPath,type,positionSeconds,ts}]}
 * @return bool true when Reelo accepted the batch (2xx)
 */
function s3video_post_events(array $payload): bool {
    $apikey = (string) s3video_setting('apikey', '');
    if ($apikey === '') {
        error_log('filter_s3video: no apikey configured; dropping analytics batch');
        return false;
    }

    $curl = new \curl();
    $curl->setHeader([
        'Authorization: Bearer ' . $apikey,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    $curl->post(rtrim(S3VIDEO_API_URL, '/') . '/events', json_encode($payload), [
        'CURLOPT_TIMEOUT' => 5,
        'CURLOPT_CONNECTTIMEOUT' => 3,
        'CURLOPT_FOLLOWLOCATION' => 0,
    ]);

    $info = $curl->get_info();
    $httpcode = (int) ($info['http_code'] ?? 0);
    return $httpcode >= 200 && $httpcode < 300;
}

/**
 * Downloads a URL that already carries a valid signature, with retries.
 *
 * @param string $url
 * @param int $retries
 * @return string|false
 */
function s3video_fetch_remote(string $url, int $retries = 3) {
    $attempt = 0;
    while ($attempt < $retries) {
        $attempt++;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Reelo-Moodle-HLS-Proxy',
            CURLOPT_FAILONERROR => true,
        ]);

        $body = curl_exec($ch);
        $err = curl_errno($ch);
        $errmsg = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err === 0 && $http < 400 && stripos((string) $body, '<Error>') === false) {
            return $body;
        }

        $safeurl = strtok($url, '?');
        error_log("filter_s3video: fetch {$attempt}/{$retries} failed for [{$safeurl}]. HTTP {$http}. curl [{$err}] {$errmsg}");

        if ($attempt < $retries) {
            sleep(1);
        }
    }

    return false;
}

/* --------------------------------------------------------------------- */
/* HLS playlist handling                                                  */
/* --------------------------------------------------------------------- */

/**
 * Whether a playlist is a master (demuxed: variants + media) rather than a
 * media playlist made of segments.
 *
 * @param string $content
 * @return bool
 */
function s3video_is_master_playlist(string $content): bool {
    return (stripos($content, '#EXT-X-STREAM-INF') !== false) || (stripos($content, '#EXT-X-MEDIA:') !== false);
}

/**
 * Validates that a rendition name is a safe ".m3u8" basename.
 *
 * @param string $name
 * @return bool
 */
function s3video_is_safe_rendition_name(string $name): bool {
    if ($name === '' || strpos($name, '..') !== false || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        return false;
    }
    return (bool) preg_match('/^[A-Za-z0-9._-]+\.m3u8$/', $name);
}

/**
 * Rewrites a demuxed master playlist so its rendition URIs point back at
 * playlist.php instead of at CloudFront.
 *
 * @param string $content original master playlist
 * @param string $path logical video path
 * @param int $courseid course the video is embedded in
 * @param string $ip requester IP
 * @param bool $audio whether the original request carried the audio flag
 * @return string
 * @throws coding_exception
 */
function s3video_rewrite_master_playlist(string $content, string $path, int $courseid, string $ip, bool $audio): string {
    global $CFG;

    $tokenttl = max(60, (int) s3video_setting('tokenttl', 1800));
    $expires = time() + $tokenttl;
    $token = s3video_generate_token($path, $expires, $courseid, $ip);

    $buildproxyurl = function (string $rendition) use ($CFG, $path, $token, $expires, $courseid, $audio): string {
        $params = ['f' => $path, 'r' => $rendition, 't' => $token, 'e' => $expires, 'c' => $courseid];
        if ($audio) {
            $params['a'] = 1;
        }
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return $CFG->wwwroot . '/filter/s3video/playlist.php?' . $query;
    };

    $normalized = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", $normalized);
    $rewritten = [];

    foreach ($lines as $line) {
        $trim = trim($line);

        if ($trim === '') {
            $rewritten[] = $line;
            continue;
        }

        if ($trim[0] === '#') {
            if (stripos($trim, '#EXT-X-MEDIA:') === 0 && preg_match('/URI="([^"]*)"/', $trim, $m) && $m[1] !== '') {
                $renditionname = basename(str_replace('\\', '/', strtok($m[1], '?')));
                if (s3video_is_safe_rendition_name($renditionname)) {
                    $line = preg_replace('/URI="[^"]*"/', 'URI="' . $buildproxyurl($renditionname) . '"', $line, 1);
                }
            }
            $rewritten[] = $line;
            continue;
        }

        $bare = strtok($trim, '?');
        if (preg_match('/\.m3u8$/i', $bare)) {
            $renditionname = basename(str_replace('\\', '/', $bare));
            if (s3video_is_safe_rendition_name($renditionname)) {
                $rewritten[] = $buildproxyurl($renditionname);
                continue;
            }
        }

        $rewritten[] = $line;
    }

    return implode("\n", $rewritten);
}

/* --------------------------------------------------------------------- */
/* Player                                                                 */
/* --------------------------------------------------------------------- */

/**
 * Whether the request comes from the Moodle mobile app.
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
 * Renders a short message in place of the player.
 *
 * @param string $identifier language string identifier
 * @return string
 */
function s3video_notice(string $identifier): string {
    return '<div class="alert alert-warning" role="alert">'
        . s(get_string($identifier, 'filter_s3video'))
        . '</div>';
}

/**
 * Builds the player HTML for one video.
 *
 * @param string $filename logical Materia/Clase path
 * @param array $options
 *   courseid      int   course the video is embedded in
 *   audio         bool  render as audio-only
 *   subtitles     array language codes, e.g. ['es', 'en']
 *   forceplayer   bool  render inline even for the mobile app (embed.php)
 *   token/expires       reuse an already validated token instead of minting one
 * @return string
 * @throws coding_exception
 */
function s3video_player(string $filename, array $options = []): string {
    global $CFG;

    $defaults = [
        'courseid' => 0,
        'forceplayer' => false,
        'token' => null,
        'expires' => null,
        'playbackrates' => [0.5, 0.75, 1, 1.25, 1.5, 2],
        'audio' => false,
        'subtitles' => [],
    ];
    $options = array_merge($defaults, $options);
    $isaudio = !empty($options['audio']);
    $courseid = (int) $options['courseid'];

    if ($courseid <= 0 && s3video_require_course()) {
        return s3video_notice('nocoursecontext');
    }
    if ($courseid > 0 && !s3video_user_can_access_course($courseid)) {
        return s3video_notice('notenrolled');
    }

    // Render cache is keyed on the video; anything per-user (watermark,
    // analytics subject) is appended afterwards via s3video_build_video_extras.
    $cacheable = empty($options['token']) && empty($options['expires']) && empty($options['forceplayer']);
    static $rendercache = [];
    static $assetsprinted = false;

    $ismobileapp = s3video_is_mobile_app($options['forceplayer']);
    if ($ismobileapp) {
        $cacheable = false;
    }

    $cachekey = ($isaudio ? 'a:' : 'v:') . $courseid . ':' . $filename;

    $escapedid = preg_replace('/[^A-Za-z0-9\-_:.]/', '-', basename($filename));
    $escapedid = 'vjs_' . $escapedid;

    $tokenttl = max(60, (int) s3video_setting('tokenttl', 1800));
    if (!empty($options['token']) && !empty($options['expires'])) {
        $token = $options['token'];
        $expires = (int) $options['expires'];
    } else {
        $expires = time() + $tokenttl;
        $token = s3video_generate_token($filename, $expires, $courseid, s3video_get_request_ip());
    }

    // El beacon de analitica se construye con el mismo token HMAC que la
    // playlist: el apikey del tenant NUNCA sale del servidor (lo usa solo
    // events.php, server-side). Ver s3video_build_video_extras().
    $extrahtml = $isaudio ? '' : s3video_build_video_extras($escapedid, $filename, $token, $expires, $courseid);

    if ($cacheable && isset($rendercache[$cachekey])) {
        return $rendercache[$cachekey] . $extrahtml;
    }

    $playlistparams = ['f' => $filename, 't' => $token, 'e' => $expires, 'c' => $courseid];
    if ($isaudio) {
        $playlistparams['a'] = 1;
    }
    $playlistquery = http_build_query($playlistparams, '', '&', PHP_QUERY_RFC3986);
    $playlisturl = $CFG->wwwroot . '/filter/s3video/playlist.php?' . $playlistquery;

    $embedparams = ['f' => $filename, 't' => $token, 'e' => $expires, 'c' => $courseid];
    if ($isaudio) {
        $embedparams['a'] = 1;
    }
    $embedquery = http_build_query($embedparams, '', '&', PHP_QUERY_RFC3986);
    $embedurl = $CFG->wwwroot . '/filter/s3video/embed.php?' . $embedquery;

    $buttontext = get_string('openvideo', 'filter_s3video');

    if ($ismobileapp) {
        $infotext = get_string('openvideoinfo', 'filter_s3video');
        $embedhref = s($embedurl);

        return <<<HTML
<div style="text-align:center; padding:1em;">
  <a href="{$embedhref}" target="_blank"
     style="display:inline-block; background:#1976d2; color:#fff;
            padding:0.8em 1.2em; border-radius:6px;
            font-weight:600; text-decoration:none;">
    {$buttontext}
  </a>
  <p style="font-size:0.9em;color:#666;margin-top:0.5em;">{$infotext}</p>
</div>
HTML;
    }

    $assets = '';
    if (!$assetsprinted) {
        $assetsprinted = true;
        $assets = <<<HTML
<link href="https://vjs.zencdn.net/8.16.1/video-js.css" rel="stylesheet" />
<script src="https://vjs.zencdn.net/8.16.1/video.min.js"></script>
<script src="{$CFG->wwwroot}/filter/s3video/watermark.js"></script>
HTML;
    }

    $setupconfig = $isaudio ? ['audioOnlyMode' => true] : ['fluid' => true];

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

    $setupattr = s(json_encode($setupconfig, JSON_UNESCAPED_SLASHES));
    $playlistsrc = s($playlisturl);

    $trackshtml = '';
    if (!$isaudio && !empty($options['subtitles']) && is_array($options['subtitles'])) {
        $langnames = ['es' => 'Español', 'en' => 'English', 'pt' => 'Português', 'fr' => 'Français'];
        foreach ($options['subtitles'] as $lang) {
            $lang = strtolower(trim((string) $lang));
            if (!preg_match('/^[a-z0-9\-]{2,5}$/', $lang)) {
                continue;
            }
            $trackparams = [
                'f' => $filename,
                'vtt' => $lang,
                't' => $token,
                'e' => $expires,
                'c' => $courseid,
            ];
            $trackquery = http_build_query($trackparams, '', '&', PHP_QUERY_RFC3986);
            $trackurl = $CFG->wwwroot . '/filter/s3video/playlist.php?' . $trackquery;
            $label = $langnames[$lang] ?? strtoupper($lang);
            $trackshtml .= '  <track kind="subtitles" srclang="' . s($lang) . '" label="' . s($label)
                . '" src="' . s($trackurl) . "\">\n";
        }
    }

    $assetsmarkup = $assets === '' ? '' : $assets . "\n";
    $vjsclass = $isaudio ? '' : ' vjs-fluid';
    $vjsstyle = $isaudio ? ' style="width:100%;height:3em"' : '';
    $embedhref = s($embedurl);

    $html = <<<HTML
{$assetsmarkup}<video id="{$escapedid}" class="video-js vjs-default-skin{$vjsclass}"{$vjsstyle}
       controls preload="auto" disablepictureinpicture playsinline data-setup='{$setupattr}'>
  <source src="{$playlistsrc}" type="application/x-mpegURL">
{$trackshtml}</video>
<script>
document.addEventListener('DOMContentLoaded', function() {
  if (typeof videojs !== 'undefined') {
    videojs('{$escapedid}').ready(function() { this.playbackRate(1); });
  }
});
</script>
<div style="text-align:center; padding:1em;">
  <a href="{$embedhref}" target="_blank"
     style="display:inline-block; background:#1976d2; color:#fff;
            padding:0.8em 1.2em; border-radius:6px;
            font-weight:600; text-decoration:none;">
    {$buttontext}
  </a>
</div>
HTML;

    if ($cacheable && $assets === '') {
        $rendercache[$cachekey] = $html;
    }

    return $html . $extrahtml;
}

/* --------------------------------------------------------------------- */
/* Watermark + analytics (per-user, appended after the cached player)      */
/* --------------------------------------------------------------------- */

/**
 * Pseudonymous, stable identifier for the current learner.
 *
 * Reelo receives this instead of the Moodle user id/name/DNI: enough to
 * count distinct viewers and follow one learner's progress, useless to
 * anyone who does not hold this site's secret.
 *
 * @return string empty for guests and anonymous requests
 */
function s3video_analytics_subject(): string {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return '';
    }
    $secret = (string) s3video_setting('secretkey', '');
    if ($secret === '') {
        return '';
    }
    return substr(hash('sha256', ((int) $USER->id) . '|' . $secret), 0, 24);
}

/**
 * Per-user markup appended after the (cacheable) player HTML: watermark and
 * analytics beacon sharing one script scope, so onTamper reports to the same
 * queue. Never cached.
 *
 * The beacon posts to the plugin's own events.php, which validates the same
 * HMAC token that protects the playlist and relays server-side to Reelo
 * with the tenant's API key. The API key never reaches the browser: leaking
 * it would let any student mint CloudFront signatures for the whole catalog
 * (POST /moodle/authorize trusts that same key).
 *
 * @param string $escapedid DOM id of the video element
 * @param string $filename logical video path
 * @param string $token HMAC token minted for this render
 * @param int $expires unix timestamp the token expires at
 * @param int $courseid course the video was embedded in (0 = none)
 * @return string
 */
function s3video_build_video_extras(string $escapedid, string $filename, string $token, int $expires, int $courseid): string {
    global $CFG, $USER;

    $eventurl = $CFG->wwwroot . '/filter/s3video/events.php?' . http_build_query([
        'f' => $filename,
        't' => $token,
        'e' => $expires,
        'c' => $courseid,
    ], '', '&', PHP_QUERY_RFC3986);
    $subject = s3video_analytics_subject();

    $watermarklabel = '';
    if (isloggedin() && !isguestuser()) {
        $watermarklabel = s3video_build_watermark_label($USER);
    }

    $idjson = json_encode($escapedid, JSON_UNESCAPED_SLASHES);
    $urljson = json_encode($eventurl, JSON_UNESCAPED_SLASHES);
    $subjectjson = json_encode($subject, JSON_UNESCAPED_SLASHES);
    $pathjson = json_encode($filename, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $labeljson = json_encode($watermarklabel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return <<<HTML
<script>
(function() {
  var targetId = {$idjson};
  var watermarkLabel = {$labeljson};

  function waitForEl() {
    var el = document.getElementById(targetId);
    if (!el) { setTimeout(waitForEl, 150); return; }
    waitForPlayer();
  }

  function waitForPlayer() {
    if (typeof videojs === 'undefined') { setTimeout(waitForPlayer, 150); return; }
    var player = videojs.getPlayer ? videojs.getPlayer(targetId) : videojs(targetId);
    if (!player || !player.ready) { setTimeout(waitForPlayer, 150); return; }
    player.ready(function() { init(player); });
  }

  function init(player) {
    var cfg = {
      url: {$urljson},
      subject: {$subjectjson},
      videoPath: {$pathjson},
      heartbeatSeconds: 15
    };
    var queue = [];

    function push(type, pos) {
      queue.push({videoPath: cfg.videoPath, type: type, positionSeconds: Math.round(pos), ts: Date.now()});
    }
    function flush() {
      if (queue.length === 0 || !cfg.url) return;
      var batch = queue; queue = [];
      try {
        fetch(cfg.url, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({subject: cfg.subject, events: batch}),
          keepalive: true
        }).catch(function() {});
      } catch (e) {}
    }

    player.on('play', function() { push('play', player.currentTime() || 0); });
    player.on('pause', function() { push('pause', player.currentTime() || 0); });
    player.on('seeked', function() { push('seek', player.currentTime() || 0); });
    player.on('ended', function() { push('complete', player.currentTime() || 0); flush(); });

    var heartbeat = setInterval(function() {
      if (!player.paused()) { push('heartbeat', player.currentTime() || 0); flush(); }
    }, cfg.heartbeatSeconds * 1000);

    player.on('dispose', function() {
      clearInterval(heartbeat);
      flush();
    });

    if (watermarkLabel) {
      ReeloWatermark.attach(player, {
        label: watermarkLabel,
        tamperLimit: 3,
        onTamper: function(count, position) {
          push('tamper', position);
          flush();
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', waitForEl);
  } else {
    waitForEl();
  }
})();
</script>
HTML;
}