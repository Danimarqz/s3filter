<?php
namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

/** Issue a new, short-lived playback lease to the authenticated owner of an old one. */
class renewal {
    public static function issue(string $url, int $currentuserid, bool $app): array {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            throw new \moodle_exception('tokeninvalid', 'filter_impronta');
        }
        parse_str($query, $params);
        $path = trim(preg_replace('#/+#', '/', str_replace('\\', '/', (string) ($params['f'] ?? ''))), '/');
        $courseid = (int) ($params['c'] ?? 0);
        $userid = (int) ($params['u'] ?? 0);
        $expires = (int) ($params['e'] ?? 0);
        $playbackid = (string) ($params['p'] ?? '');
        $mode = (string) ($params['m'] ?? '');
        $group = (string) ($params['g'] ?? '');
        $oldtoken = (string) ($params['t'] ?? '');
        if ($path === '' || strpos($path, '..') !== false || $userid !== $currentuserid
                || $mode === 'scorm' || !token::signed_context($path, $oldtoken, $expires,
                    $courseid, request::ip(), $userid, $app, $group, $playbackid, $mode)
                || ($courseid > 0 && !access::can_view_course($courseid, $userid))
                || ($courseid <= 0 && config::require_course())) {
            throw new \moodle_exception('tokeninvalid', 'filter_impronta');
        }
        $newexpires = time() + config::token_ttl();
        $newplaybackid = 'r' . bin2hex(random_bytes(8));
        $newtoken = token::generate($path, $newexpires, $courseid, request::ip(), $userid,
            $app, '', $newplaybackid);
        return [
            'playlistUrl' => token::endpoint_url('playlist.php', $path, $newtoken, $newexpires, $courseid, $userid,
                !empty($params['a']) ? ['a' => 1] : [], '', $newplaybackid),
            'eventsUrl' => token::endpoint_url('events.php', $path, $newtoken, $newexpires,
                $courseid, $userid, [], '', $newplaybackid),
            'sessionUrl' => token::endpoint_url('heartbeat.php', $path, $newtoken, $newexpires,
                $courseid, $userid, [], '', $newplaybackid),
            'expiresAt' => $newexpires,
        ];
    }
}
