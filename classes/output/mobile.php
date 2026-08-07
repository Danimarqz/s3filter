<?php
// Reproductor in-app para la app de Moodle.

namespace filter_s3video\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Reproductor dentro de la app de Moodle.
 *
 * Reparto de trabajo, y por qué es este y no otro:
 *
 * Moodle NO aplica los filtros al contenido que manda a la app. Lo
 * comprobado el 2026-08-06 contra producción: la descripción de una etiqueta
 * con "[s3video:Materia/Clase]" llega a la app con el tag literal, sin
 * procesar, porque el diseño de la app es aplicar los filtros en el cliente
 * (para eso existe CoreFilterDelegate). Así que el filtro PHP, que es quien
 * sabe firmar tokens, nunca se ejecuta en este camino.
 *
 * De ahí las dos piezas:
 *
 *   - mobile_init() devuelve el JavaScript que la app ejecuta. Ese JS
 *     sustituye el tag por un hueco que solo lleva la RUTA de la clase, sin
 *     nada secreto, y luego pide los datos al servidor.
 *   - mobile_video() es lo que pide. Va autenticado como el alumno, así que
 *     aquí sí se puede firmar el token con su id, resolver su watermark y
 *     construir la URL de la playlist.
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
        global $CFG, $USER;

        require_once(__DIR__ . '/../../lib.php');

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
            if (!s3video_user_can_access_course($courseid)) {
                return self::error_video('notenrolled');
            }
        } else if (s3video_require_course()) {
            return self::error_video('nocoursecontext');
        }

        $userid = (isloggedin() && !isguestuser()) ? (int) $USER->id : 0;
        if ($userid <= 0) {
            return self::error_video('reopenthroughapp');
        }

        $expires = time() + max(60, (int) s3video_setting('tokenttl', 25200));
        $token = s3video_generate_token($path, $expires, $courseid, s3video_get_request_ip(), $userid);

        return [
            'templates' => [],
            'otherdata' => [
                'playlist' => s3video_endpoint_url('playlist.php', $path, $token, $expires, $courseid, $userid),
                'events' => s3video_endpoint_url('events.php', $path, $token, $expires, $courseid, $userid),
                // Respaldo: si el reproductor no llega a montarse dentro de la
                // app, el JS pinta un enlace a esto y el alumno lo ve en el
                // navegador, que es lo que tenía antes de todo esto.
                'embed' => s3video_endpoint_url('embed.php', $path, $token, $expires, $courseid, $userid),
                'openlabel' => get_string('openvideo', 'filter_s3video'),
                'subject' => s3video_analytics_subject($userid),
                'watermark' => s3video_build_watermark_label($USER),
                'color' => s3video_watermark_color(),
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
     * @param array $args argumentos por defecto del web service
     * @return array
     */
    public static function mobile_init(array $args): array {
        global $CFG, $USER;

        // Esta clase se autocarga, y el autoload NO trae lib.php: sin este
        // require, s3video_setting() de más abajo no existe. Es el mismo fallo
        // que tumbó el sitio desde settings.php el 2026-08-06. __DIR__ en vez
        // de una ruta con el nombre del plugin, para que la copia dev
        // (filter_s3videodev) siga funcionando sin tocar nada.
        require_once(__DIR__ . '/../../lib.php');

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
            explode(',', (string) s3video_setting('mobileusers', ''))
        ));
        if (!$permitidos || !in_array((int) $USER->id, $permitidos, true)) {
            return ['javascript' => ''];
        }

        $wwwroot = json_encode($CFG->wwwroot, JSON_UNESCAPED_SLASHES);
        // video.js trae @videojs/http-streaming incorporado, asi que la misma
        // libreria resuelve el HLS por MSE en Android (el WebView de Chromium
        // no reproduce .m3u8 nativo) y deja un objeto player compatible con
        // el UMD del watermark. Una dependencia en vez de dos.
        $videojs = json_encode('https://vjs.zencdn.net/8.16.1/video.min.js', JSON_UNESCAPED_SLASHES);
        $videojscss = json_encode('https://vjs.zencdn.net/8.16.1/video-js.css', JSON_UNESCAPED_SLASHES);

        $js = <<<JS
// .call(this) al final NO es cosmético: la app evalúa este código con un
// "this" propio que expone sus servicios (CoreFilterDelegate entre ellos), y
// una IIFE normal lo perdería.
(function() {
  var APP = this;
  var WWWROOT = {$wwwroot};
  var VIDEOJS_JS = {$videojs};
  var VIDEOJS_CSS = {$videojscss};
  var WATERMARK_JS = WWWROOT + '/filter/s3video/watermark.js';
  var COMPONENTE = 'filter_s3video';
  var CLASE = 'filter_s3video-app-player';
  var SELECTOR = '.' + CLASE;
  // Mismo patrón que classes/text_filter.php. Aquí hace falta porque a la app
  // le llega el tag SIN procesar: Moodle no aplica los filtros al contenido
  // que manda por web service, los deja para el cliente.
  var TAG = /\[(?:reelo|s3)(audio)?:([^\]]+)\]/g;

  // ponytail: telemetría temporal, quitar cuando el reproductor in-app esté
  // validado. El WebView de la app no es depurable (no expone socket de
  // DevTools) y su consola no llega al logcat, así que la única forma de saber
  // por dónde va esto es pedir una URL que no existe y leer el nombre en el
  // log de acceso de Apache:
  //   sudo tail -f /opt/bitnami/apache/logs/access_log | grep __s3diag
  function diag(paso) {
    try {
      fetch(WWWROOT + '/filter/s3video/__s3diag?paso=' + encodeURIComponent(paso))
        .catch(function() {});
    } catch (e) {}
  }

  function loadCss(href) {
    if (document.querySelector('link[href="' + href + '"]')) { return; }
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    document.head.appendChild(link);
  }

  function loadScript(src) {
    return new Promise(function(resolve, reject) {
      var existing = document.querySelector('script[src="' + src + '"]');
      if (existing) {
        if (existing.dataset.s3videoLoaded === '1') { resolve(); return; }
        existing.addEventListener('load', function() { resolve(); });
        existing.addEventListener('error', function() { reject(new Error('load ' + src)); });
        return;
      }
      var script = document.createElement('script');
      script.src = src;
      script.async = false;
      script.addEventListener('load', function() {
        script.dataset.s3videoLoaded = '1';
        resolve();
      });
      script.addEventListener('error', function() { reject(new Error('load ' + src)); });
      document.head.appendChild(script);
    });
  }

  // El color viaja por dato y se aplica con !important porque el UMD del
  // watermark trae color:#fff en el style inline de cada capa.
  function applyColor(color) {
    if (!color || document.getElementById('s3video-app-wm-color')) { return; }
    var style = document.createElement('style');
    style.id = 's3video-app-wm-color';
    style.textContent = '.reelo-watermark { color: ' + color + ' !important; }';
    document.head.appendChild(style);
  }

  function analytics(player, cfg) {
    if (!cfg.events || !cfg.subject) { return; }
    var queue = [];
    var timer = null;

    function push(type) {
      var pos = 0;
      try { pos = player.currentTime() || 0; } catch (e) { pos = 0; }
      queue.push({
        videoPath: cfg.path,
        type: type,
        positionSeconds: Math.round(pos),
        ts: Date.now()
      });
      if (type === 'complete' || queue.length >= 20) { flush(); }
    }

    function flush() {
      if (!queue.length) { return; }
      var batch = queue;
      queue = [];
      // events.php reenvía a Reelo server-side con el apikey del tenant: la
      // clave no baja nunca al dispositivo.
      fetch(cfg.events, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({subject: cfg.subject, events: batch}),
        keepalive: true
      }).catch(function() {});
    }

    player.on('play', function() { push('play'); });
    player.on('pause', function() { push('pause'); });
    player.on('seeked', function() { push('seek'); });
    player.on('ended', function() { push('complete'); });
    timer = setInterval(function() {
      if (!player.paused()) { push('heartbeat'); }
    }, 15000);
    player.on('dispose', function() {
      if (timer) { clearInterval(timer); }
      flush();
    });
    document.addEventListener('visibilitychange', function() {
      if (document.visibilityState === 'hidden') { flush(); }
    });
  }

  // Pide al servidor los datos firmados de una clase. Va autenticado como el
  // alumno, así que el token sale atado a su id y el watermark con su nombre;
  // ni el apikey del tenant ni el secreto de firma bajan al dispositivo.
  //
  // Dos caminos porque no está documentado cuál de los dos servicios expone la
  // app en este contexto. El diag dice cuál funcionó, y cuando lo sepamos se
  // deja solo ese.
  function pedirDatos(path, courseid) {
    var args = {path: path, courseid: parseInt(courseid, 10) || 0};

    if (APP && APP.CoreSitePluginsProvider && APP.CoreSitePluginsProvider.getContent) {
      diag('ws:siteplugins');
      return Promise.resolve(APP.CoreSitePluginsProvider.getContent(COMPONENTE, 'mobile_video', args))
        .then(function(r) { return normalizar(r); });
    }

    if (APP && APP.CoreSitesProvider && APP.CoreSitesProvider.getCurrentSite) {
      diag('ws:site-write');
      return Promise.resolve(APP.CoreSitesProvider.getCurrentSite()).then(function(site) {
        return site.write('tool_mobile_get_content', {
          component: COMPONENTE,
          method: 'mobile_video',
          args: [
            {name: 'path', value: String(path)},
            {name: 'courseid', value: String(args.courseid)}
          ]
        });
      }).then(function(r) { return normalizar(r); });
    }

    diag('ws:sin-servicio:' + Object.keys(APP || {}).slice(0, 15).join(','));
    return Promise.reject(new Error('sin servicio de web service'));
  }

  // otherdata llega como objeto o como lista de {name, value} según el camino.
  function normalizar(r) {
    var od = (r && r.otherdata) || r || {};
    if (Object.prototype.toString.call(od) !== '[object Array]') { return od; }
    var out = {};
    for (var i = 0; i < od.length; i++) { out[od[i].name] = od[i].value; }
    return out;
  }

  function montar(el) {
    // Camino normal: el servidor ya filtró y el marcador trae los datos
    // firmados. No hace falta preguntar nada.
    var directo = {
      playlist: el.getAttribute('data-s3video-playlist'),
      events: el.getAttribute('data-s3video-events'),
      subject: el.getAttribute('data-s3video-subject'),
      path: el.getAttribute('data-s3video-path'),
      watermark: el.getAttribute('data-s3video-watermark'),
      color: el.getAttribute('data-s3video-color'),
      embed: el.getAttribute('data-s3video-embed')
    };
    if (directo.playlist) {
      diag('montar:marcador');
      return build(el, directo);
    }

    // Respaldo: si el marcador llegara sin los datos (la app podría sanear los
    // data-*), se piden por web service con la ruta.
    var path = directo.path;
    var courseid = el.getAttribute('data-s3video-courseid');
    if (!path) { diag('montar:sin-datos'); return; }
    diag('montar:ws');

    return pedirDatos(path, courseid).then(function(cfg) {
      if (!cfg || !cfg.playlist) {
        diag('sin-playlist:' + ((cfg && cfg.error) || 'respuesta vacia'));
        el.textContent = (cfg && cfg.error) || '';
        return;
      }
      diag('datos-ok');
      return build(el, cfg);
    }).catch(function(e) {
      diag('montar-error:' + (e && e.message ? e.message : e));
    });
  }

  function build(el, cfg) {
    if (!cfg.playlist) { return; }

    // Respaldo visible desde ya: si video.js no llega a cargar (CSP de la
    // app, red), el alumno se queda con el enlace al navegador en vez de un
    // hueco vacío. Se sustituye por el reproductor solo cuando está listo.
    if (cfg.embed) {
      var a = document.createElement('a');
      a.href = cfg.embed;
      a.target = '_blank';
      a.textContent = cfg.openlabel || 'Abrir video';
      a.style.cssText = 'display:inline-block;background:#1976d2;color:#fff;'
        + 'padding:0.8em 1.2em;border-radius:6px;font-weight:600;text-decoration:none;';
      el.textContent = '';
      el.appendChild(a);
    }

    loadCss(VIDEOJS_CSS);
    applyColor(cfg.color);

    diag('build:cargando-videojs');
    return loadScript(VIDEOJS_JS).then(function() {
      diag('build:videojs-cargado');
      var video = document.createElement('video');
      video.className = 'video-js vjs-default-skin vjs-fluid';
      video.setAttribute('controls', '');
      video.setAttribute('playsinline', '');
      video.setAttribute('preload', 'auto');

      // Se vacía el marcador (con él, el botón de respaldo) solo cuando
      // video.js ya está cargado: si la carga falla, el alumno se queda con
      // el botón de abrir en el navegador, que es lo que tenía antes.
      el.textContent = '';
      el.appendChild(video);

      var player = videojs(video, {
        fluid: true,
        playsinline: true,
        sources: [{src: cfg.playlist, type: 'application/x-mpegURL'}]
      });

      diag('build:player-creado');

      // Los beacons anteriores solo cuentan que el reproductor se montó. Lo
      // que falla después pasa dentro de video.js, así que se reporta su
      // propio objeto de error: el código distingue red (2) de formato no
      // soportado (4), que son diagnósticos opuestos.
      player.on('error', function() {
        var e = null;
        try { e = player.error(); } catch (err) {}
        diag('error:' + (e ? e.code + ':' + (e.message || '').slice(0, 60) : 'desconocido'));
      });
      player.on('loadedmetadata', function() {
        diag('metadata-ok');
      });
      player.one('playing', function() {
        diag('reproduciendo');
      });

      if (!cfg.watermark) { return; }
      return loadScript(WATERMARK_JS).then(function() {
        if (typeof ReeloWatermark === 'undefined') { return; }
        var wm = ReeloWatermark.attach(player, {
          label: cfg.watermark,
          tamperLimit: 3,
          onTamper: function() {}
        });

        // El watermark flotante se coloca en píxeles absolutos calculados con
        // el tamaño del reproductor EN ESE MOMENTO, y solo se recalcula en su
        // temporizador aleatorio (cada 20-40 s). Al entrar en pantalla
        // completa o girar el móvil el reproductor cambia de tamaño, y la
        // posición vieja puede caer fuera: el watermark desaparece o se ve a
        // medias. Recolocarlo en cada cambio de geometría lo arregla.
        //
        // El retardo no es capricho: justo después de rotar o entrar en
        // fullscreen, getBoundingClientRect todavía devuelve el tamaño
        // anterior. Sin esperar un frame se recolocaría con los datos viejos.
        var recolocar = function() {
          setTimeout(function() {
            try { wm.reposition(); } catch (e) {}
          }, 120);
        };

        player.on('fullscreenchange', recolocar);
        window.addEventListener('resize', recolocar);
        window.addEventListener('orientationchange', recolocar);

        // La app es una SPA y monta y desmonta reproductores al navegar: sin
        // quitar los listeners se acumularían apuntando a reproductores ya
        // destruidos.
        player.on('dispose', function() {
          window.removeEventListener('resize', recolocar);
          window.removeEventListener('orientationchange', recolocar);
        });
      }).catch(function() {
        // Sin watermark no se reproduce: identificar al alumno es el motivo
        // de que exista este reproductor.
        try { player.pause(); } catch (e) {}
        try { player.dispose(); } catch (e) {}
        el.textContent = '';
      }).then(function() {
        analytics(player, cfg);
      });
    }).catch(function(e) {
      // Marcador intacto: queda el botón de abrir en el navegador. El fallo
      // más probable aquí es que la CSP de la app bloquee el script del CDN.
      diag('build-error:' + (e && e.message ? e.message : e));
    });
  }

  // NO se registra ningún handler en CoreFilterDelegate, y es la decisión de
  // diseño más importante de este fichero.
  //
  // En cuanto existe un handler para un filtro, la app deja de pedirle al
  // servidor el texto filtrado y se lo pide crudo, para filtrarlo ella (lo
  // decide external_settings::get_filter, ver lib/external/classes/util.php).
  // Eso tiene dos consecuencias malas: el PHP del filtro —que es el único que
  // sabe firmar tokens— deja de ejecutarse, y el contenido de TODO el sitio
  // pasa a depender de nuestro handler. El 2026-08-06 eso dejó la app sin
  // cargar ningún curso, para todos los usuarios.
  //
  // Sin registrar nada, el servidor sigue filtrando y manda el marcador ya
  // firmado (ver la rama $ismobileapp de s3video_player). Aquí solo hay que
  // encontrarlo y convertirlo en reproductor. Un observador del DOM no
  // participa en el renderizado del contenido, así que en el peor caso no
  // aparece el reproductor y queda el botón de abrir en el navegador — nunca
  // puede impedir que el curso cargue.
  function revisar() {
    try {
      // Solo los que aún no se han montado: el propio reproductor genera
      // muchísimas mutaciones al construirse, y sin este filtro el observador
      // se dispara en bucle sobre un nodo ya resuelto.
      var nodes = document.querySelectorAll(SELECTOR + ':not([data-s3video-ready])');
      if (!nodes.length) { return; }
      diag('encontrados:' + nodes.length);
      for (var i = 0; i < nodes.length; i++) {
        nodes[i].setAttribute('data-s3video-ready', '1');
        montar(nodes[i]);
      }
    } catch (e) {
      diag('revisar-error:' + (e && e.message ? e.message : e));
    }
  }

  try {
    // El origen del webview decide qué debe aceptar la lista blanca de CORS
    // (ver s3video_send_cors_headers). Varía según plataforma y versión de la
    // app, así que se reporta en vez de darlo por supuesto.
    diag('init:origen=' + (typeof location !== 'undefined' ? location.origin : '?'));
    revisar();

    // La app es una SPA: el contenido del curso aparece después, y al navegar
    // se sustituye entero. Por eso hace falta observar en vez de mirar una vez.
    // Con espera de 200 ms para no rehacer el trabajo en cada mutación: montar
    // el reproductor genera cientos seguidas.
    var pendiente = null;
    var observer = new MutationObserver(function(mutaciones) {
      for (var i = 0; i < mutaciones.length; i++) {
        if (mutaciones[i].addedNodes && mutaciones[i].addedNodes.length) {
          if (pendiente) { clearTimeout(pendiente); }
          pendiente = setTimeout(function() { pendiente = null; revisar(); }, 200);
          return;
        }
      }
    });
    observer.observe(document.body || document.documentElement, {
      childList: true,
      subtree: true
    });
    diag('observando');
  } catch (e) {
    diag('init-error:' + (e && e.message ? e.message : e));
  }
}).call(this);
JS;

        return ['javascript' => $js];
    }
}
