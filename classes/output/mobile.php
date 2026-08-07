<?php
// Soporte para la app de Moodle.

namespace filter_s3video\output;

use filter_s3video\access;
use filter_s3video\config;
use filter_s3video\player;
use filter_s3video\reelo_api;
use filter_s3video\request;
use filter_s3video\token;
use filter_s3video\watermark;

defined('MOODLE_INTERNAL') || die();

/**
 * Reproductor dentro de la app de Moodle.
 *
 * Reparto de trabajo, y por qué es este y no otro:
 *
 * Moodle NO aplica los filtros al contenido que manda a la app. Lo
 * comprobado el 2026-08-06 contra producción: la descripción de una etiqueta
 * con "[s3:Materia/Clase]" llega a la app con el tag literal, sin procesar,
 * porque el diseño de la app es aplicar los filtros en el cliente (para eso
 * existe CoreFilterDelegate). Así que el filtro PHP, que es quien sabe firmar
 * tokens, nunca se ejecuta en este camino.
 *
 * De ahí las dos piezas:
 *
 *   - mobile_init() devuelve el JavaScript que la app ejecuta: un arranque
 *     mínimo que deja los servicios de la app en window.s3videoApp y carga
 *     js/app-player.js, donde está el reproductor.
 *   - mobile_video() son los datos firmados de una clase. Va autenticado como
 *     el alumno, así que aquí sí se puede firmar el token con su id, resolver
 *     su watermark y construir la URL de la playlist.
 *
 * El apikey del tenant y el secreto de firma nunca bajan al dispositivo: lo
 * que viaja es una URL ya firmada con caducidad.
 */
class mobile {

    /**
     * Datos firmados para reproducir una clase concreta dentro de la app.
     *
     * La llama el JavaScript del reproductor vía tool_mobile_get_content, una
     * vez por vídeo que aparezca en la página.
     *
     * @param array $args con 'path' (Materia/Clase) y 'courseid'
     * @return array
     */
    public static function mobile_video(array $args): array {
        global $USER;

        $path = trim((string) ($args['path'] ?? ''));
        $courseid = (int) ($args['courseid'] ?? 0);

        // La ruta viene del cliente: se valida igual que en playlist.php, sin
        // dar por hecho que es la que emitimos nosotros.
        if ($path === '' || strpos($path, '..') !== false || substr_count($path, '/') !== 1) {
            return self::error_video('tokeninvalid');
        }

        // Control de acceso idéntico al del navegador. Aquí hay sesión de web
        // service, así que se comprueba contra el usuario autenticado.
        if ($courseid > 0) {
            if (!access::can_view_course($courseid)) {
                return self::error_video('notenrolled');
            }
        } else if (config::require_course()) {
            return self::error_video('nocoursecontext');
        }

        $userid = (isloggedin() && !isguestuser()) ? (int) $USER->id : 0;
        if ($userid <= 0) {
            return self::error_video('reopenthroughapp');
        }

        $expires = time() + config::token_ttl();
        $token = token::generate($path, $expires, $courseid, request::ip(), $userid);

        return [
            'templates' => [],
            'otherdata' => [
                'playlist' => token::endpoint_url('playlist.php', $path, $token, $expires, $courseid, $userid),
                'events' => token::endpoint_url('events.php', $path, $token, $expires, $courseid, $userid),
                // Respaldo: si el reproductor no llega a montarse dentro de la
                // app, el JS pinta un enlace a esto y el alumno lo ve en el
                // navegador, que es lo que tenía antes de todo esto.
                'embed' => token::endpoint_url('embed.php', $path, $token, $expires, $courseid, $userid),
                'openlabel' => get_string('openvideo', 'filter_s3video'),
                'subject' => reelo_api::subject($userid),
                'watermark' => watermark::label($USER),
                'color' => watermark::color(),
                'path' => $path,
                'error' => '',
            ],
        ];
    }

    /**
     * Respuesta de mobile_video cuando no se puede reproducir. Se devuelve el
     * motivo traducido en vez de lanzar una excepción: una excepción en esta
     * llamada dejaría a la app sin poder pintar el contenido.
     *
     * @param string $identifier cadena de idioma con el motivo
     * @return array
     */
    private static function error_video(string $identifier): array {
        return [
            'templates' => [],
            'otherdata' => [
                'playlist' => '',
                'events' => '',
                'subject' => '',
                'watermark' => '',
                'color' => '',
                'path' => '',
                'embed' => '',
                'openlabel' => '',
                'error' => get_string($identifier, 'filter_s3video'),
            ],
        ];
    }

    /**
     * Arranque del reproductor in-app: deja los servicios de la app en
     * window.s3videoApp y carga js/app-player.js.
     *
     * El JavaScript de verdad está en ese fichero y no aquí porque esto es un
     * fichero PHP: dentro de un heredoc no hay comprobación de sintaxis, ni
     * resaltado, ni forma de ejecutarlo suelto. Lo único que se queda es lo que
     * solo sabe el servidor — rutas y la lista de usuarios autorizados.
     *
     * @param array $args argumentos por defecto del web service
     * @return array
     */
    public static function mobile_init(array $args): array {
        global $CFG, $USER;

        // Interruptor de seguridad. El addon móvil se sirve a TODAS las apps
        // conectadas al sitio, así que un fallo aquí no afecta solo a quien
        // abre el vídeo: deja la app sin cargar ningún curso, para todo el
        // mundo. Pasó el 2026-08-06 en producción.
        //
        // Como la llamada del web service va autenticada, se puede limitar por
        // usuario: solo los ids de 'mobileusers' reciben el JavaScript del
        // reproductor; el resto recibe cadena vacía y la app se comporta como
        // si el plugin no tuviera addon. Vacío (por defecto) = nadie, que es
        // lo que debe pasar mientras esto no esté validado en un dispositivo.
        $permitidos = array_filter(array_map(
            'intval',
            explode(',', (string) config::get('mobileusers', ''))
        ));
        if (!$permitidos || !in_array((int) $USER->id, $permitidos, true)) {
            return ['javascript' => ''];
        }

        // video.js trae @videojs/http-streaming incorporado, asi que la misma
        // libreria resuelve el HLS por MSE en Android (el WebView de Chromium
        // no reproduce .m3u8 nativo) y deja un objeto player compatible con
        // el UMD del watermark. Una dependencia en vez de dos.
        $vjs = player::VIDEOJS;
        $cfg = json_encode([
            'wwwroot' => $CFG->wwwroot,
            'componente' => 'filter_s3video',
            'videojs' => "https://vjs.zencdn.net/{$vjs}/video.min.js",
            'videojscss' => "https://vjs.zencdn.net/{$vjs}/video-js.css",
            'watermarkjs' => player::asset_url('watermark.js'),
            'watermarkfitjs' => player::asset_url('js/watermark-fit.js'),
        ], JSON_UNESCAPED_SLASHES);
        $appjs = json_encode(player::asset_url('js/app-player.js'), JSON_UNESCAPED_SLASHES);

        $js = <<<JS
// .call(this) al final NO es cosmético: la app evalúa este código con un
// "this" propio que expone sus servicios (CoreSitePluginsProvider entre
// ellos), y una IIFE normal lo perdería. Como el reproductor vive en otro
// fichero, hay que dejárselo en algún sitio donde pueda recogerlo.
(function() {
  window.s3videoApp = Object.assign({servicios: this}, {$cfg});
  var s = document.createElement('script');
  s.src = {$appjs};
  s.async = false;
  document.head.appendChild(s);
}).call(this);
JS;

        return ['javascript' => $js];
    }
}
