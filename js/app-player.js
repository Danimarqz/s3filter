// Reproductor in-app para la app de Moodle.
//
// Lo carga el bootstrap que devuelve \filter_s3video\output\mobile::mobile_init,
// que además deja en window.s3videoApp las rutas que solo sabe el servidor.
// Aquí no hay nada secreto ni se le pregunta nada a la app: el marcador que
// pinta el filtro ya trae la playlist firmada, el token y el watermark
// resuelto.
//
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
// firmado (ver la rama de app de \filter_s3video\player::render). Aquí solo
// hay que encontrarlo y convertirlo en reproductor. Un observador del DOM no
// participa en el renderizado del contenido, así que en el peor caso no
// aparece el reproductor y queda el botón de abrir en el navegador — nunca
// puede impedir que el curso cargue.
(function() {
  'use strict';

  var CFG = window.s3videoApp || {};
  var WWWROOT = CFG.wwwroot;
  var COMPONENTE = CFG.componente;
  var SELECTOR = '.' + COMPONENTE + '-app-player';

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

  // El servidor ya filtró y el marcador trae los datos firmados: no hay nada
  // que preguntarle. Si faltan, no se monta nada y el alumno se queda con el
  // enlace al navegador que trae el propio marcador.
  function montar(el) {
    var cfg = {
      playlist: el.getAttribute('data-s3video-playlist'),
      events: el.getAttribute('data-s3video-events'),
      subject: el.getAttribute('data-s3video-subject'),
      path: el.getAttribute('data-s3video-path'),
      watermark: el.getAttribute('data-s3video-watermark'),
      color: el.getAttribute('data-s3video-color')
    };
    if (!cfg.playlist) { diag('montar:sin-datos'); return; }
    diag('montar:marcador');
    return build(el, cfg);
  }

  function build(el, cfg) {
    // El marcador que pintó el servidor (enlace al navegador + explicación) se
    // queda visible hasta que video.js está cargado: si la carga falla, el
    // alumno ve exactamente lo que veía antes de que existiera esto.
    loadCss(CFG.videojscss);
    applyColor(cfg.color);

    diag('build:cargando-videojs');
    return loadScript(CFG.videojs).then(function() {
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
      return loadScript(CFG.watermarkjs).then(function() {
        return loadScript(CFG.watermarkfitjs);
      }).then(function() {
        if (typeof ReeloWatermark === 'undefined') { return; }
        var wm = ReeloWatermark.attach(player, {
          label: cfg.watermark,
          tamperLimit: 3,
          onTamper: function() {}
        });
        window.ReeloWatermarkFit.attach(player, wm, diag);
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
    // (ver \filter_s3video\request::send_cors_headers). Varía según plataforma
    // y versión de la app, así que se reporta en vez de darlo por supuesto.
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
})();
