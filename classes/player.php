<?php
/**
 * HTML del reproductor.
 *
 * Tres salidas distintas según quién pide:
 *   - navegador: <video> de Video.js + los extras por alumno (player::extras)
 *   - app de Moodle: un marcador con los datos ya firmados, que monta
 *     js/app-player.js dentro del webview
 *   - sin permiso: un aviso en lugar del reproductor
 *
 * @package   filter_impronta
 */

namespace filter_impronta;

defined('MOODLE_INTERNAL') || die();

/**
 * Construye el reproductor y lo que lo acompaña.
 */
class player {

    /** Versión de Video.js que se sirve desde el CDN, en los dos caminos. */
    const VIDEOJS = '8.16.1';

    /**
     * URL de un fichero estático del plugin, con la versión como cache-buster.
     *
     * Sin el parámetro, el webview de la app se queda con la copia vieja del
     * JavaScript después de actualizar el plugin y el fallo parece del
     * servidor.
     *
     * @param string $file ruta relativa dentro del plugin
     * @return string
     */
    public static function asset_url(string $file): string {
        global $CFG;
        return $CFG->wwwroot . '/filter/impronta/' . $file . '?v=' . (int) config::get('version', 0);
    }

    /**
     * Si la petición viene de la app de Moodle.
     *
     * @param bool $forceplayer
     * @return bool
     */
    public static function is_mobile_app(bool $forceplayer): bool {
        if ($forceplayer) {
            return false;
        }

        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return stripos($agent, 'MoodleMobile') !== false;
    }

    /**
     * Un mensaje corto en lugar del reproductor.
     *
     * @param string $identifier identificador de la cadena de idioma
     * @return string
     */
    public static function notice(string $identifier): string {
        return '<div class="alert alert-warning" role="alert">'
            . s(get_string($identifier, 'filter_impronta'))
            . '</div>';
    }

    /**
     * HTML del reproductor de un vídeo.
     *
     * @param string $filename ruta lógica Materia/Clase
     * @param array $options
     *   courseid      int   curso donde se incrusta el vídeo
     *   audio         bool  solo audio
     *   subtitles     array códigos de idioma, p. ej. ['es', 'en']
     *   forceplayer   bool  pintar el reproductor aunque sea la app (embed.php)
     *   token/expires       reutilizar un token ya validado en vez de emitir uno
     * @return string
     * @throws \coding_exception
     */
    public static function render(string $filename, array $options = []): string {
        global $USER;

        $defaults = [
            'courseid' => 0,
            'forceplayer' => false,
            'token' => null,
            'expires' => null,
            'userid' => null,
            'authorizationgroupid' => '',
            'playbackid' => '',
            'mode' => '',
            'playbackrates' => [0.5, 0.75, 1, 1.25, 1.5, 2],
            'audio' => false,
            'subtitles' => [],
        ];
        $options = array_merge($defaults, $options);
        $isaudio = !empty($options['audio']);
        $courseid = (int) $options['courseid'];
        $authorizationgroupid = (string) $options['authorizationgroupid'];
        $playbackid = (string) $options['playbackid'];
        $mode = (string) $options['mode'];

        // Identidad de esta reproducción. Se resuelve antes que nada porque la
        // comprobación de matrícula depende de ella: cuando el reproductor lo
        // pinta embed.php abierto desde la app, no hay cookie de sesión y el
        // único usuario conocido es el que viene firmado en el token.
        $tokenuserid = (int) ($options['userid'] ?? 0);
        if ($tokenuserid <= 0) {
            $tokenuserid = (isloggedin() && !isguestuser()) ? (int) $USER->id : 0;
        }

        if ($mode !== 'scorm' && $courseid <= 0 && config::require_course()) {
            return self::notice('nocoursecontext');
        }
        if ($mode !== 'scorm' && $courseid > 0 && !access::can_view_course($courseid, $tokenuserid)) {
            return self::notice('notenrolled');
        }

        // La caché de render va por vídeo; todo lo que es de cada alumno
        // (watermark, subject de analítica) se añade después, en self::extras.
        $cacheable = empty($options['token']) && empty($options['expires'])
            && empty($options['forceplayer']);
        static $rendercache = [];
        static $assetsprinted = false;

        $ismobileapp = self::is_mobile_app($options['forceplayer']);
        if ($ismobileapp) {
            $cacheable = false;
        }
        if ($mode === 'scorm') {
            $cacheable = false;
        }

        $cachekey = ($isaudio ? 'a:' : 'v:') . $courseid . ':' . $filename;

        $escapedid = preg_replace('/[^A-Za-z0-9\-_:.]/', '-', basename($filename));
        $escapedid = 'vjs_' . $escapedid;

        if (!empty($options['token']) && !empty($options['expires'])) {
            $token = $options['token'];
            $expires = (int) $options['expires'];
        } else {
            $expires = time() + config::token_ttl();
            // $ismobileapp se marca DENTRO de la firma: es lo que permite que
            // playlist.php exija sesión de navegador sin cortarle el vídeo a la
            // app, que nunca manda la cookie (comprobado el 2026-08-11: el
            // webview daba 403 y el escritorio 200 con el mismo token).
            $token = token::generate($filename, $expires, $courseid, request::ip(), $tokenuserid,
                $ismobileapp, $authorizationgroupid, $playbackid, $mode);
        }

        // Ya no hay modo cookie, y su desaparición es deliberada.
        //
        // Consistía en poner en el navegador del alumno las cookies firmadas de
        // CloudFront, que cubren "Materia/Clase/*". Es decir: la clase entera,
        // durante horas, en un sitio del que se pueden copiar. Servía para que la
        // firma no viajara horneada en cada segmento, pero ese problema lo
        // resuelve mejor la playlist que ahora monta el backend, donde no hay
        // ninguna firma que copiar — solo un token que caduca y que se comprueba
        // contra la lista de bloqueos en CADA segmento.
        //
        // Mantener las dos cosas a la vez habría sido lo peor de todo: el camino
        // nuevo comprobando bloqueos segmento a segmento mientras el navegador
        // guarda unas cookies que se saltan la comprobación entera.
        $extraparams = $isaudio ? ['a' => 1] : [];

        $playlisturl = token::endpoint_url('playlist.php', $filename, $token, $expires, $courseid,
            $tokenuserid, $extraparams, $authorizationgroupid, $playbackid, $mode);

        // El beacon de analitica se construye con el mismo token HMAC que la
        // playlist: el apikey del tenant NUNCA sale del servidor (lo usa solo
        // events.php, server-side). Ver self::extras(). Se le pasa tambien la URL
        // de playlist para la recuperacion ante 403 (fase 0.2 del plan de TTL
        // corto). Los embeds de solo audio no emiten extras (ni watermark, ni
        // analitica, ni recuperacion): a proposito, es un flujo marginal que no
        // merece el JS extra.
        $extrahtml = $isaudio ? '' : self::extras($escapedid, $filename, $token, $expires,
            $courseid, $playlisturl, $tokenuserid, $authorizationgroupid, $playbackid, $mode);

        if ($cacheable && isset($rendercache[$cachekey])) {
            return $rendercache[$cachekey] . $extrahtml;
        }

        // Solo la rama de la app: en el navegador el reproductor va inline y no
        // hay nada que abrir aparte.
        //
        // El `u` que mete token::endpoint_url es lo que evita el 403 al abrir
        // este enlace en el navegador del móvil: ahí no hay cookie de sesión de
        // Moodle (comprobado por ADB, fase 0.4 del plan de TTL corto).
        if ($ismobileapp) {
            $embedurl = token::endpoint_url('embed.php', $filename, $token, $expires, $courseid,
                $tokenuserid, $isaudio ? ['a' => 1] : [], $authorizationgroupid, $playbackid, $mode);
            return self::app_marker($filename, $playlisturl, $embedurl, $token, $expires,
                $courseid, $tokenuserid, $isaudio);
        }

        $assets = '';
        if (!$assetsprinted) {
            $assetsprinted = true;
            // El color del watermark se aplica por CSS y no como opción de
            // ImprontaWatermark.attach(): el UMD del paquete trae "color:#fff" en el
            // style inline de la marca, y una regla de hoja con !important lo
            // gana sin tener que recompilar y redistribuir el paquete. El bucle
            // anti-tampering del UMD reafirma display/visibility/opacity, pero no
            // toca color, así que no hay pelea entre los dos.
            $wmcolor = watermark::color();
            $vjs = self::VIDEOJS;
            $watermarkjs = self::asset_url('watermark.js');
            $fitjs = self::asset_url('js/watermark-fit.js');
            $extrasjs = self::asset_url('js/player-extras.js');
            $assets = <<<HTML
<link href="https://vjs.zencdn.net/{$vjs}/video-js.css" rel="stylesheet" />
<style>
.impronta-watermark { color: {$wmcolor} !important; }
.filter-impronta-player.video-js { background-color:#f3f4f6; }
.filter-impronta-player .vjs-poster {
  background-color:#f3f4f6;
  background-position:center;
  background-repeat:no-repeat;
  background-size:contain;
}
</style>
<script src="https://vjs.zencdn.net/{$vjs}/video.min.js"></script>
<script src="{$watermarkjs}"></script>
<script src="{$fitjs}"></script>
<script src="{$extrasjs}"></script>
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
        $trackshtml = self::tracks_html($filename, $options, $token, $expires, $courseid, $isaudio,
            $authorizationgroupid, $playbackid, $mode, $tokenuserid);

        $assetsmarkup = $assets === '' ? '' : $assets . "\n";
        $vjsclass = $isaudio ? '' : ' vjs-fluid filter-impronta-player';
        $vjsstyle = $isaudio ? ' style="width:100%;height:3em"' : '';
        $poster = $isaudio ? '' : self::poster_url($filename);
        $posterattr = $poster === '' ? '' : ' poster="' . s($poster) . '"';

        // Sonda del poster de reserva (ver poster_fallback_url): el thumb de
        // una clase pendiente del scan da 404 y la caja se queda gris. No se
        // escucha el error del <img> que video.js 8 mete dentro de .vjs-poster
        // porque puede fallar ANTES de que esto corra: se sondea, y si el img
        // ya esta roto lo delata con naturalWidth === 0. Va en el HTML que se
        // cachea por vídeo a propósito: poster y logo son idénticos para
        // cualquier alumno.
        $posterprobejs = '';
        if ($poster !== '') {
            $probejson = json_encode(
                ['poster' => $poster, 'fallback' => self::poster_fallback_url()],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
            );
            $posterprobejs = <<<JS

// Poster de reserva: si el thumb 404ea, el logo del sitio en su lugar.
(function() {
  var cfg = {$probejson};
  if (!cfg.fallback || cfg.poster === cfg.fallback) { return; }
  var intentos = 0;
  (function mira() {
    var root = document.getElementById('{$escapedid}');
    var caja = root ? root.closest('.video-js') : null;
    var img = caja ? caja.querySelector('.vjs-poster img') : null;
    if (img) {
      if (img.complete && img.naturalWidth === 0) { img.src = cfg.fallback; return; }
      img.addEventListener('error', function() { img.src = cfg.fallback; }, {once: true});
      return;
    }
    if (++intentos < 40) { setTimeout(mira, 250); }
  })();
})();
JS;
        }

        $html = <<<HTML
{$assetsmarkup}<video id="{$escapedid}" class="video-js vjs-default-skin{$vjsclass}"{$vjsstyle}{$posterattr}
       controls preload="none" disablepictureinpicture playsinline data-setup='{$setupattr}'>
  <source src="{$playlistsrc}" type="application/x-mpegURL">
{$trackshtml}</video>
<script>
document.addEventListener('DOMContentLoaded', function() {
  if (typeof videojs !== 'undefined') {
    videojs('{$escapedid}').ready(function() { this.playbackRate(1); });
  }
});
{$posterprobejs}</script>
HTML;

        if ($cacheable && $assets === '') {
            $rendercache[$cachekey] = $html;
        }

        return $html . $extrahtml;
    }

    /**
     * Marcador que se manda a la app de Moodle, con todo lo que el reproductor
     * in-app necesita ya firmado server-side.
     *
     * Aquí (petición del web service) el alumno sí está autenticado, mientras
     * que en el webview no habrá cookie: por eso el token va atado al usuario y
     * el watermark viaja resuelto, no como plantilla.
     *
     * Dentro va el botón de abrir en el navegador como respaldo: si
     * js/app-player.js no llega a ejecutarse (app antigua, JS bloqueado, fallo
     * al cargar video.js), el alumno ve exactamente lo que veía antes en vez de
     * un hueco vacío. El reproductor lo borra al montarse (textContent = '').
     *
     * Este es el ÚNICO sitio donde queda el botón: en el navegador se retiró
     * porque allí el reproductor va inline y no hay respaldo que ofrecer. Aquí
     * sigue siendo la única salida cuando el JS no arranca.
     *
     * @param string $filename
     * @param string $playlisturl
     * @param string $embedurl
     * @param string $token
     * @param int $expires
     * @param int $courseid
     * @param int $tokenuserid
     * @param bool $isaudio
     * @return string
     */
    private static function app_marker(string $filename, string $playlisturl, string $embedurl,
            string $token, int $expires, int $courseid, int $tokenuserid,
            bool $isaudio): string {
        global $USER;

        $buttontext = get_string('openvideo', 'filter_impronta');
        $infotext = get_string('openvideoinfo', 'filter_impronta');
        $embedhref = s($embedurl);

        // Esta rama solo corre en la petición del web service de la app
        // (embed.php pasa forceplayer, que desactiva is_mobile_app), y ahí el
        // alumno sí está autenticado: $USER es quien verá el vídeo.
        $data = [
            'playlist' => $playlisturl,
            'events' => $isaudio ? '' : token::endpoint_url('events.php', $filename, $token,
                $expires, $courseid, $tokenuserid),
            'subject' => impronta_api::subject($tokenuserid),
            'path' => $filename,
            'poster' => $isaudio ? '' : self::poster_url($filename),
            // El logo por si el thumb del poster da 404: js/app-player.js
            // cambia la src del <img> del poster si falla. Igual que el
            // poster, es una URL pública determinista.
            'posterfallback' => $isaudio ? '' : self::poster_fallback_url(),
            'watermark' => $tokenuserid > 0 ? watermark::label($USER) : '',
            'color' => watermark::color(),
            // El latido de la sesión. Los embeds de solo audio quedan fuera,
            // igual que la analítica: es un flujo marginal que no merece el JS.
            'session' => $isaudio ? '' : token::endpoint_url('heartbeat.php', $filename, $token,
                $expires, $courseid, $tokenuserid),
            'revoked' => get_string('accessrevoked', 'filter_impronta'),
            'evicted' => get_string('sessionevicted', 'filter_impronta'),
        ];
        $attrs = '';
        foreach ($data as $key => $value) {
            $attrs .= ' data-impronta-' . $key . '="' . s((string) $value) . '"';
        }

        return <<<HTML
<div class="filter_impronta-app-player"{$attrs} style="text-align:center; padding:1em;">
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

    /**
     * URL del poster: thumbnail del primer frame si hay mediabase configurada,
     * logo del sitio si no.
     *
     * La URL es determinista y sin firma: el thumb es una imagen generada en la
     * ingesta y cacheada por CloudFront (1 año) y el navegador (immutable). No
     * lleva token porque no cubre nada que proteger, y ahí está la diferencia
     * con la playlist: la query que autoriza la clase entera jamás sale del
     * servidor. Aquí no hay llamada ni caché: es una construcción de string.
     *
     * @param string $filename ruta lógica Materia/Clase
     * @return string vacío si no hay mediabase y el sitio tampoco tiene logo
     */
    private static function poster_url(string $filename): string {
        $mediabase = rtrim((string) config::get('mediabase', ''), '/');
        if ($mediabase !== '') {
            return $mediabase . '/thumbs/' . cloudfront::encode_key($filename) . '.jpg';
        }
        return self::poster_fallback_url();
    }

    /**
     * Poster de reserva: el logo del sitio.
     *
     * Sale por pantalla por dos caminos: sin mediabase configurada, poster_url
     * cae aquí directamente; y con mediabase pero clase sin thumb todavía —el
     * scan no la ha generado—, el JS del reproductor vigila el <img> del
     * poster y, al 404, cambia la src a esta URL. Es una construcción de
     * string más: sin llamada ni caché nueva.
     *
     * @return string vacío si el sitio no tiene logo
     */
    private static function poster_fallback_url(): string {
        global $OUTPUT;

        $logo = $OUTPUT->get_logo_url(600, 120);
        return $logo ? $logo->out(false) : '';
    }

    /**
     * Etiquetas <track> de subtítulos, servidas por playlist.php.
     *
     * @param string $filename
     * @param array $options
     * @param string $token
     * @param int $expires
     * @param int $courseid
     * @param bool $isaudio
     * @param string $authorizationgroupid
     * @param string $playbackid
     * @param string $mode
     * @param int $userid usuario firmado en el token
     * @return string
     */
    private static function tracks_html(string $filename, array $options, string $token, int $expires,
            int $courseid, bool $isaudio, string $authorizationgroupid = '',
            string $playbackid = '', string $mode = '', int $userid = 0): string {
        global $CFG;

        if ($isaudio || empty($options['subtitles']) || !is_array($options['subtitles'])) {
            return '';
        }

        $langnames = ['es' => 'Español', 'en' => 'English', 'pt' => 'Português', 'fr' => 'Français'];
        $trackshtml = '';

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
            if ($userid > 0) {
                $trackparams['u'] = $userid;
            }
            if ($mode === 'scorm') {
                $trackparams['g'] = $authorizationgroupid;
                $trackparams['p'] = $playbackid;
                $trackparams['m'] = $mode;
            }
            $trackquery = http_build_query($trackparams, '', '&', PHP_QUERY_RFC3986);
            $trackurl = $CFG->wwwroot . '/filter/impronta/playlist.php?' . $trackquery;
            $label = $langnames[$lang] ?? strtoupper($lang);
            $trackshtml .= '  <track kind="subtitles" srclang="' . s($lang) . '" label="' . s($label)
                . '" src="' . s($trackurl) . "\">\n";
        }

        return $trackshtml;
    }

    /**
     * Lo que se añade después del reproductor y depende del alumno: watermark,
     * analítica y recuperación ante 403. Nunca se cachea.
     *
     * El código vive en js/player-extras.js; aquí solo se emite la llamada con
     * los datos resueltos.
     *
     * @param string $escapedid id DOM del elemento <video>
     * @param string $filename ruta lógica del vídeo
     * @param string $token token HMAC emitido para este render
     * @param int $expires caducidad del token
     * @param int $courseid curso donde se incrustó (0 = ninguno)
     * @param string $playlisturl URL de playlist.php, para la recuperación ante 403
     * @param int $userid usuario firmado en el token (0 = usar el de la sesión)
     * @return string
     */
    private static function extras(string $escapedid, string $filename, string $token, int $expires,
            int $courseid, string $playlisturl, int $userid = 0, string $authorizationgroupid = '',
            string $playbackid = '', string $mode = ''): string {
        global $USER;

        // El usuario del watermark sale del token cuando no hay sesión (la app
        // abre el reproductor sin cookie); si no, del usuario de la sesión. Sin
        // esto el watermark quedaría vacío justo en el camino móvil, que es donde
        // más falta hace.
        $watermarkuser = null;
        if ($userid > 0) {
            $watermarkuser = \core_user::get_user($userid);
        } else if (isloggedin() && !isguestuser()) {
            $watermarkuser = $USER;
        }

        $cfg = [
            'targetId' => $escapedid,
            'watermarkLabel' => $watermarkuser ? watermark::label($watermarkuser) : '',
            'eventsUrl' => token::endpoint_url('events.php', $filename, $token, $expires, $courseid,
                $userid, [], $authorizationgroupid, $playbackid, $mode),
            'subject' => impronta_api::subject($userid),
            'videoPath' => $filename,
            'playlistUrl' => $playlisturl,
            'expiredText' => s(get_string('sessionexpired', 'filter_impronta')),
            'heartbeatSeconds' => 15,
            // El latido de la SESIÓN, que no es el de la analítica de arriba.
            // Aquel manda eventos de visionado cada 15 s; este mantiene viva la
            // sesión de reproducción, reporta cuánto vídeo se ha visto -el
            // denominador de la detección- y trae de vuelta si hay que parar.
            // El intervalo real lo dice Impronta en su respuesta.
            'sessionUrl' => token::endpoint_url('heartbeat.php', $filename, $token, $expires, $courseid,
                $userid, [], $authorizationgroupid, $playbackid, $mode),
            'revokedText' => s(get_string('accessrevoked', 'filter_impronta')),
            'evictedText' => s(get_string('sessionevicted', 'filter_impronta')),
        ];

        // JSON_HEX_TAG no es opcional: la etiqueta del watermark sale de campos
        // que edita el propio usuario (nombre, campos de perfil) y esto se
        // inyecta dentro de un <script>. Sin escapar "<" y ">", un perfil con
        // "</script>" cerraría el bloque e inyectaría HTML en la página. Lo que
        // se escapa son escapes de cadena JS: el watermark muestra el texto tal
        // cual.
        $cfgjson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        return "\n<script>ImprontaPlayerExtras({$cfgjson});</script>\n";
    }
}
