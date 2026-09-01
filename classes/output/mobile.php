<?php
// Soporte para la app de Moodle.

namespace filter_impronta\output;

use filter_impronta\config;
use filter_impronta\player;

defined('MOODLE_INTERNAL') || die();

/**
 * Reproductor dentro de la app de Moodle.
 *
 * Lo único que hace esta clase es entregar el arranque del reproductor. Los
 * datos de cada clase NO se piden desde la app: el filtro PHP corre
 * server-side y manda el marcador con la playlist firmada, el token y el
 * watermark ya resueltos (ver \filter_impronta\player::render). El apikey del
 * tenant y el secreto de firma nunca bajan al dispositivo.
 */
class mobile {

    /**
     * Arranque del reproductor in-app: deja las rutas en window.improntaApp y
     * carga js/app-player.js.
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
        // usuario: si 'mobileusers' trae ids, solo esos reciben el JavaScript
        // del reproductor y el resto recibe cadena vacía (la app se comporta
        // como si el plugin no tuviera addon). Vacío = todo el mundo, que es
        // el valor normal; la lista solo sirve para volver a acotarlo mientras
        // se prueba algo.
        $permitidos = array_filter(array_map(
            'intval',
            explode(',', (string) config::get('mobileusers', ''))
        ));
        if ($permitidos && !in_array((int) $USER->id, $permitidos, true)) {
            return ['javascript' => ''];
        }

        // video.js trae @videojs/http-streaming incorporado, asi que la misma
        // libreria resuelve el HLS por MSE en Android (el WebView de Chromium
        // no reproduce .m3u8 nativo) y deja un objeto player compatible con
        // el UMD del watermark. Una dependencia en vez de dos.
        $cfg = json_encode([
            'wwwroot' => $CFG->wwwroot,
            'componente' => 'filter_impronta',
            'videojs' => player::asset_url('vendor/video.js/video.min.js'),
            'videojscss' => player::asset_url('vendor/video.js/video-js.min.css'),
            'watermarkjs' => player::asset_url('watermark.js'),
            'watermarkfitjs' => player::asset_url('js/watermark-fit.js'),
        ], JSON_UNESCAPED_SLASHES);
        $appjs = json_encode(player::asset_url('js/app-player.js'), JSON_UNESCAPED_SLASHES);

        $js = <<<JS
(function() {
  window.improntaApp = {$cfg};
  var s = document.createElement('script');
  s.src = {$appjs};
  s.async = false;
  document.head.appendChild(s);
})();
JS;

        return ['javascript' => $js];
    }
}
