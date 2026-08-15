<?php
/**
 * Token HMAC que comparten embed.php, playlist.php y events.php.
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
     * @param bool $esapp el token se emite para el reproductor DENTRO de la app,
     *     donde no va a haber cookie de sesión. Ver el bloque de abajo.
     * @return string
     * @throws \coding_exception
     */
    public static function generate(string $filename, int $expires, int $courseid, string $ip,
            int $userid = 0, bool $esapp = false, string $authorizationgroupid = '',
            string $playbackid = '', string $mode = ''): string {
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

        // La marca de "esto es la app" va DENTRO de la firma, y ahí está todo el
        // asunto: no se detecta en la petición, se acredita.
        //
        // Detectarla es imposible de hacer bien. El UA del webview es
        // "Mozilla/5.0 (Linux; Android 16; ...; wv)" y NO dice MoodleMobile
        // -comprobado en el log de Apache el 2026-08-11-, así que is_mobile_app()
        // da false justo en la petición de la playlist; y cualquier cabecera que
        // se mire la falsifica un curl -H.
        //
        // Emitir sí se puede: el render del reproductor in-app solo ocurre en la
        // petición del web service de la app, que va autenticada con su token
        // (ver player::app_marker). Ahí el servidor SABE que es la app, y firma
        // ese hecho. Sin el secretkey no se falsifica.
        //
        // Límite conocido: quien ya tenga sesión de alumno puede pedir la página
        // con UA de MoodleMobile y cosechar tokens marcados, que luego valen sin
        // cookie durante su TTL. Sacarlos del webview de la app -que no es
        // depurable- es bastante más difícil. Lo que estrecha ese resto es el TTL
        // corto, no esta marca. Ver docs/plan-ttl-corto.md.
        //
        // Al final y solo si está: un token sin marca produce el payload de
        // siempre, así que los ya emitidos siguen validando.
        if ($esapp) {
            $payload .= '|app';
        }

        // SCORM grants are deliberately an additional signed variant.  The
        // group and playback id are opaque values, but signing them prevents a
        // learner from swapping either one in playlist/heartbeat/events URLs.
        if ($mode === 'scorm') {
            $payload .= '|scorm|' . $authorizationgroupid . '|' . $playbackid;
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
     * @param bool $esapp comprobar contra la variante marcada como app
     * @return bool
     */
    public static function validate(string $filename, string $token, int $expires, int $courseid,
            string $ip, int $userid = 0, bool $esapp = false, string $authorizationgroupid = '',
            string $playbackid = '', string $mode = ''): bool {
        if (time() > $expires) {
            return false;
        }
        $expected = self::generate($filename, $expires, $courseid, $ip, $userid, $esapp,
            $authorizationgroupid, $playbackid, $mode);
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
            int $courseid, int $userid, array $extra = [], string $authorizationgroupid = '',
            string $playbackid = '', string $mode = ''): string {
        global $CFG;

        $params = ['f' => $filename, 't' => $token, 'e' => $expires, 'c' => $courseid];
        if ($userid > 0) {
            $params['u'] = $userid;
        }
        if ($mode === 'scorm') {
            $params['g'] = $authorizationgroupid;
            $params['p'] = $playbackid;
            $params['m'] = $mode;
        }

        return $CFG->wwwroot . '/filter/impronta/' . $script . '?'
            . http_build_query(array_merge($params, $extra), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Autorización común de embed.php, playlist.php y events.php:
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
     * @param bool|null $esapp parámetro de SALIDA: true si el token venía marcado
     *     como emitido para el reproductor in-app. Quien lo necesita es
     *     playlist.php, para saber si puede exigir sesión de navegador o si esta
     *     es la petición que nunca va a traer cookie.
     * @return string|null null si puede seguir; si no, el identificador de la
     *     cadena de idioma con el motivo
     */
    public static function authorize(string $path, ?string $token, int $expires, int $courseid,
            int $userid, ?bool &$esapp = null, string $authorizationgroupid = '',
            string $playbackid = '', string $mode = ''): ?string {
        $ip = request::ip();
        $esapp = false;

        if ($token === null || $token === '') {
            return 'tokeninvalid';
        }

        // Se prueban las dos variantes porque la firma es opaca: no se puede leer
        // la marca, solo recalcular con ella y ver cuál casa. Sin marca primero,
        // que es lo que traen los tokens emitidos antes de esto.
        if ($mode === 'scorm') {
            if ($authorizationgroupid === '' || $playbackid === ''
                    || !self::validate($path, $token, $expires, $courseid, $ip, $userid, false,
                        $authorizationgroupid, $playbackid, 'scorm')) {
                return 'tokeninvalid';
            }
            $esapp = false;
        } else if (self::validate($path, $token, $expires, $courseid, $ip, $userid, false)) {
            $esapp = false;
        } else if (self::validate($path, $token, $expires, $courseid, $ip, $userid, true)) {
            $esapp = true;
        } else {
            return 'tokeninvalid';
        }

        if ($mode === 'scorm') {
            // SCORM is launched from a Rise package, so it has no Moodle
            // course context to validate. The current non-guest web session is
            // mandatory and must be the exact user signed into the launch
            // token; a copied token must not work in another account's session.
            global $USER;
            if (!isloggedin() || isguestuser() || $userid <= 0 || (int) $USER->id !== $userid) {
                return 'scormsession';
            }
            return null;
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
