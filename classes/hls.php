<?php
/**
 * Manejo de playlists HLS.
 *
 * @package   filter_s3video
 */

namespace filter_s3video;

defined('MOODLE_INTERNAL') || die();

/**
 * Reconoce y reescribe los .m3u8 que sirve playlist.php.
 */
class hls {

    /**
     * Si una playlist es master (demuxed: variantes + media) en vez de una
     * playlist de segmentos.
     *
     * @param string $content
     * @return bool
     */
    public static function is_master(string $content): bool {
        return (stripos($content, '#EXT-X-STREAM-INF') !== false)
            || (stripos($content, '#EXT-X-MEDIA:') !== false);
    }

    /**
     * Valida que un nombre de rendition sea un basename ".m3u8" seguro.
     *
     * @param string $name
     * @return bool
     */
    public static function is_safe_rendition(string $name): bool {
        if ($name === '' || strpos($name, '..') !== false || strpos($name, '/') !== false
                || strpos($name, '\\') !== false) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9._-]+\.m3u8$/', $name);
    }

    /**
     * Reescribe un master playlist para que sus renditions apunten a
     * playlist.php en vez de a CloudFront.
     *
     * @param string $content master playlist original
     * @param string $path ruta lógica del vídeo
     * @param int $courseid curso donde está incrustado
     * @param string $ip IP de quien pide
     * @param bool $audio si la petición original traía la marca de audio
     * @param int $userid usuario firmado en el token de la petición original
     * @param bool $cookiemode si la petición original venía en modo cookie
     * @return string
     * @throws \coding_exception
     */
    public static function rewrite_master(string $content, string $path, int $courseid, string $ip,
            bool $audio, int $userid = 0, bool $cookiemode = false): string {
        global $CFG;

        // Los tokens de los renditions duran lo mismo que el del player (ver
        // config::token_ttl): si divergieran, la recuperación ante 403 fallaría
        // a mitad de clase y solo en las clases con varias calidades.
        $expires = time() + config::token_ttl();
        // El token nuevo se ata al MISMO usuario que el de la petición original:
        // si se emitiera sin él, la URL de la rendition llegaría sin `u` y no
        // pasaría su propia validación HMAC.
        $token = token::generate($path, $expires, $courseid, $ip, $userid);

        $buildproxyurl = function (string $rendition) use ($CFG, $path, $token, $expires, $courseid, $audio,
                $userid, $cookiemode): string {
            $params = ['f' => $path, 'r' => $rendition, 't' => $token, 'e' => $expires, 'c' => $courseid];
            if ($userid > 0) {
                $params['u'] = $userid;
            }
            if ($cookiemode) {
                // El modo tiene que propagarse: si la rendition volviera en modo
                // firma, sus segmentos llevarían la firma en la query y se
                // perdería el sentido de haber puesto las cookies.
                $params['modo'] = 'cookie';
            }
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
                if (stripos($trim, '#EXT-X-MEDIA:') === 0 && preg_match('/URI="([^"]*)"/', $trim, $m)
                        && $m[1] !== '') {
                    $renditionname = basename(str_replace('\\', '/', strtok($m[1], '?')));
                    if (self::is_safe_rendition($renditionname)) {
                        $line = preg_replace('/URI="[^"]*"/', 'URI="' . $buildproxyurl($renditionname) . '"',
                            $line, 1);
                    }
                }
                $rewritten[] = $line;
                continue;
            }

            $bare = strtok($trim, '?');
            if (preg_match('/\.m3u8$/i', $bare)) {
                $renditionname = basename(str_replace('\\', '/', $bare));
                if (self::is_safe_rendition($renditionname)) {
                    $rewritten[] = $buildproxyurl($renditionname);
                    continue;
                }
            }

            $rewritten[] = $line;
        }

        return implode("\n", $rewritten);
    }
}
