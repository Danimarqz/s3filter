<?php
/**
 * Token HMAC que comparten embed.php, playlist.php, events.php y cookies.php.
 *
 * @package   filter_impronta
 */

namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

/**
 * Emisión, validación y autorización de los tokens del plugin.
 */
class token {

    /**
     * Emite el token interno.
     *
     * El id del curso va dentro de la carga firmada: el curso lo decide el
     * filtro desde el contexto de render, y el navegador no puede sustituirlo
     * sin invalidar el HMAC.
     *
     * @param string $filename ruta lógica Materia/Clase
     * @param int $expires timestamp unix
     * @param int $courseid curso donde se incrustó el vídeo (0 = ninguno)
     * @param string $ip
     * @param int $userid usuario al que se ata el token (0 = ninguno)
     * @return string
     * @throws \coding_exception
     */
    public static function generate(string $filename, int $expires, int $courseid, string $ip,
            int $userid = 0): string {
        $secret = config::required('secretkey');
        $payload = "{$filename}|{$expires}|{$courseid}";

        if (config::bind_ip()) {
            $payload .= "|{$ip}";
        }

        // El usuario se firma dentro del token para que el reproductor funcione
        // SIN sesión de navegador: en la app de Moodle el webview no lleva la
        // cookie de sesión (se autentica con token de web service), asi que
        // isloggedin() es false en playlist.php aunque el alumno esté logueado en
        // la app. El HMAC prueba que fue este Moodle quien emitió el token para
        // ese alumno, esa clase y ese curso, y con esa identidad se puede
        // recomprobar la matrícula en cada petición sin depender de la cookie.
        //
        // Al final y solo si hay usuario: un token con $userid = 0 produce el
        // mismo payload de siempre, asi que los tokens ya emitidos (viven horas)
        // siguen validando tras desplegar esto.
        if ($userid > 0) {
            $payload .= "|u{$userid}";
        }

        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Valida un token emitido por generate().
     *
     * @param string $filename
     * @param string $token
     * @param int $expires
     * @param int $courseid
     * @param string $ip
     * @param int $userid
     * @return bool
     */
    public static function validate(string $filename, string $token, int $expires, int $courseid,
            string $ip, int $userid = 0): bool {
        if (time() > $expires) {
            return false;
        }
        $expected = self::generate($filename, $expires, $courseid, $ip, $userid);
        return hash_equals($expected, $token);
    }

    /**
     * URL de uno de los endpoints del plugin con los parámetros firmados.
     *
     * Existe para que los cuatro sitios que construyen estas URLs (playlist,
     * embed, beacon de analítica y datos del reproductor in-app) no puedan
     * divergir: si a una se le olvidara el `u`, esa ruta dejaría de funcionar sin
     * cookie de sesión y solo se notaría desde el móvil.
     *
     * @param string $script embed.php | playlist.php | events.php
     * @param string $filename Materia/Clase
     * @param string $token token HMAC
     * @param int $expires caducidad del token
     * @param int $courseid curso (0 = ninguno)
     * @param int $userid usuario firmado en el token (0 = ninguno)
     * @param array $extra parámetros adicionales (a=1 para audio, etc.)
     * @return string
     */
    public static function endpoint_url(string $script, string $filename, string $token, int $expires,
            int $courseid, int $userid, array $extra = []): string {
        global $CFG;

        $params = ['f' => $filename, 't' => $token, 'e' => $expires, 'c' => $courseid];
        if ($userid > 0) {
            $params['u'] = $userid;
        }

        return $CFG->wwwroot . '/filter/impronta/' . $script . '?'
            . http_build_query(array_merge($params, $extra), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Autorización común de embed.php, playlist.php, events.php y cookies.php:
     * valida el token HMAC y decide si quien pide puede reproducir esta clase.
     *
     * Los endpoints hacían lo mismo con varias copias del mismo bloque, que es
     * justo donde una diverge sin que nadie se entere. Aquí también vive la regla
     * nueva: la identidad puede venir de la sesión de navegador O del $userid
     * firmado dentro del token, que es lo que permite reproducir desde el webview
     * de la app de Moodle, donde no hay cookie de sesión.
     *
     * @param string $path Materia/Clase
     * @param string|null $token token HMAC recibido
     * @param int $expires caducidad que venía en la URL
     * @param int $courseid curso (0 = sin contexto de curso)
     * @param int $userid usuario firmado en el token (0 = ninguno)
     * @return string|null null si puede seguir; si no, el identificador de la
     *     cadena de idioma con el motivo
     */
    public static function authorize(string $path, ?string $token, int $expires, int $courseid,
            int $userid): ?string {
        $ip = request::ip();

        if ($token === null || $token === ''
                || !self::validate($path, $token, $expires, $courseid, $ip, $userid)) {
            return 'tokeninvalid';
        }

        if ($courseid > 0) {
            return access::can_view_course($courseid, $userid) ? null : 'notenrolled';
        }

        if (config::require_course()) {
            return 'nocoursecontext';
        }

        // Sin contexto de curso sigue haciendo falta saber quién reproduce: o hay
        // sesión de navegador, o el token trae el usuario firmado.
        if ($userid <= 0 && (!isloggedin() || isguestuser())) {
            return 'reopenthroughapp';
        }

        return null;
    }
}
