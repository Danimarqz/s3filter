// Reproductor in-app para la app de Moodle.
//
// Lo carga el bootstrap que devuelve \filter_impronta\output\mobile::mobile_init,
// que además deja en window.improntaApp las rutas que solo sabe el servidor.
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
// firmado (ver la rama de app de \filter_impronta\player::render). Aquí solo
// hay que encontrarlo y convertirlo en reproductor. Un observador del DOM no
// participa en el renderizado del contenido, así que en el peor caso no
// aparece el reproductor y queda el botón de abrir en el navegador — nunca
// puede impedir que el curso cargue.
(function() {
  'use strict';

  var CFG = window.improntaApp || {};
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
      fetch(WWWROOT + '/filter/impronta/__s3diag?paso=' + encodeURIComponent(paso))
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
        if (existing.dataset.improntaLoaded === '1') { resolve(); return; }
        existing.addEventListener('load', function() { resolve(); });
        existing.addEventListener('error', function() { reject(new Error('load ' + src)); });
        return;
      }
      var script = document.createElement('script');
      script.src = src;
      script.async = false;
      script.addEventListener('load', function() {
        script.dataset.improntaLoaded = '1';
        resolve();
      });
      script.addEventListener('error', function() { reject(new Error('load ' + src)); });
      document.head.appendChild(script);
    });
  }

  // El color viaja por dato y se aplica con !important porque el UMD del
  // watermark trae color:#fff en el style inline de cada capa.
  function applyColor(color) {
    if (!color || document.getElementById('impronta-app-wm-color')) { return; }
    var style = document.createElement('style');
    style.id = 'impronta-app-wm-color';
    style.textContent = '.impronta-watermark { color: ' + color + ' !important; }';
    document.head.appendChild(style);
  }

  // Safe area para dispositivos con esquinas redondeadas / notch (iPhone X+,
  // Android modernos). Sin esto, la barra de control de Video.js queda cortada
  // por las esquinas de la pantalla: play/pause, barra de progreso y botón de
  // pantalla completa no se ven o no se pueden pulsar.
  function injectSafeAreaCSS() {
    if (document.getElementById('impronta-safe-area')) { return; }
    var style = document.createElement('style');
    style.id = 'impronta-safe-area';
    style.textContent = [
      '/* Safe area para esquinas redondeadas / notch */',
      '.video-js .vjs-control-bar {',
      '  padding-bottom: env(safe-area-inset-bottom, 0);',
      '  padding-left: env(safe-area-inset-left, 0);',
      '  padding-right: env(safe-area-inset-right, 0);',
      '}',
      '/* El botón grande de play también respeta el safe area en vertical */',
      '.video-js .vjs-big-play-button {',
      '  top: calc(50% + env(safe-area-inset-top, 0) / 2);',
      '  left: calc(50% + (env(safe-area-inset-right, 0) - env(safe-area-inset-left, 0)) / 2);',
      '}',
      '.filter_impronta-app-player .video-js, .filter_impronta-app-player .vjs-poster {',
      '  background-color: #f3f4f6;',
      '}',
      '.filter_impronta-app-player .vjs-poster {',
      '  background-position: center;',
      '  background-repeat: no-repeat;',
      '  background-size: contain;',
      '}'
    ].join('\n');
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
      // events.php reenvía a Impronta server-side con el apikey del tenant: la
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
    }, 30000);
    player.on('dispose', function() {
      if (timer) { clearInterval(timer); }
      flush();
    });
    document.addEventListener('visibilitychange', function() {
      if (document.visibilityState === 'hidden') { flush(); }
    });
  }

  // Latido de la sesión de reproducción. Ver heartbeat.php: mantiene la sesión
  // viva, reporta cuánto vídeo se ha visto -el denominador de la detección de
  // descarga masiva- y devuelve si hay que parar.
  //
  // El identificador de sesión no está aquí: lo guardó playlist.php en el
  // servidor. Este JS no puede latir por una sesión ajena porque no conoce
  // ninguna.
  function sesion(player, cfg, el) {
    if (!cfg.session) { return; }

    var vistos = 0;
    var ultimo = 0;
    var terminada = false;
    var iniciada = false;
    var latidoEnVuelo = false;
    var flushPendiente = false;
    var destruida = false;
    var timer = null;
    var intervalo = 30;

    function limpiarTimer() {
      if (timer) { clearTimeout(timer); timer = null; }
    }

    function programar(segundos) {
      limpiarTimer();
      if (terminada || !iniciada || player.paused()) { return; }
      timer = setTimeout(function() {
        timer = null;
        latir(false);
      }, segundos * 1000);
    }

    // De los avances pequeños de currentTime y no del reloj: una pausa no
    // cuenta, un rebobinado no resta, y ver a 2x cuenta el doble.
    player.on('timeupdate', function() {
      if (!iniciada) { return; }
      var t = 0;
      try { t = player.currentTime() || 0; } catch (e) { return; }
      var delta = t - ultimo;
      if (delta > 0 && delta < 2) { vistos += delta; }
      ultimo = t;
    });

    function parar(texto) {
      terminada = true;
      limpiarTimer();
      try { player.pause(); } catch (e) {}
      diag('sesion:parada');
      if (!texto) { return; }
      var msg = document.createElement('p');
      msg.setAttribute('role', 'alert');
      msg.style.cssText = 'margin:0.6em 0;padding:0.6em 1em;background:#fff3cd;color:#664d03;border-radius:6px;';
      msg.textContent = texto;
      el.appendChild(msg);
    }

    function latir(forzar) {
      if ((terminada && !(forzar && destruida && flushPendiente)) || !iniciada || latidoEnVuelo || (!forzar && player.paused())) { return; }
      var enviados = Math.round(vistos);
      flushPendiente = false;
      latidoEnVuelo = true;
      fetch(cfg.session, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({watchedSeconds: enviados}),
        keepalive: true
      }).then(function(res) {
        if (!res.ok) { throw new Error('heartbeat ' + res.status); }
        // Solo se descuentan si el latido llegó: perderlos inflaría la
        // proporción de segmentos por minuto visto y acercaría una alerta a un
        // alumno que no ha hecho nada raro.
        vistos -= enviados;
        return res.json();
      }).then(function(r) {
        var recibido = Number(r && r.heartbeatSeconds);
        intervalo = recibido > 0 ? recibido : 120;
        if (r.blocked) { parar(cfg.revoked); }
        else if (r.evicted) { parar(cfg.evicted); }
      }).catch(function() {
        // Los segundos quedan pendientes para el siguiente latido.
        intervalo = 120;
      }).then(function() {
        latidoEnVuelo = false;
        if (flushPendiente) {
          latir(true);
        } else if (!destruida && !terminada && iniciada && !player.paused()) {
          programar(intervalo);
        }
      });
    }

    function flush() {
      if (terminada || !iniciada) { return; }
      flushPendiente = true;
      if (!latidoEnVuelo) { latir(true); }
    }

    // En el primer play y no al montar, por lo mismo que en player-extras.js: sin
    // sesión que renovar heartbeat.php contesta 409, y latir por vídeos que nadie
    // ha abierto son peticiones que no pueden salir bien. Aquí importa menos que
    // en el navegador porque la app suele traer un vídeo por vista, pero el
    // marcador se monta por elemento y nada impide que haya varios.
    // El primer latido espera 30 s desde el primer play; los siguientes usan
    // heartbeatSeconds del servidor y 120 s si falla o no lo devuelve.
    player.on('play', function() {
      if (terminada) { return; }
      iniciada = true;
      if (!timer && !latidoEnVuelo) { programar(intervalo); }
    });
    player.on('pause', function() {
      limpiarTimer();
      flush();
    });
    player.on('ended', function() {
      limpiarTimer();
      flush();
    });
    player.on('error', function() { flush(); });

    function alOcultarse() {
      if (document.visibilityState === 'hidden') { flush(); }
    }
    document.addEventListener('visibilitychange', alOcultarse);

    player.on('dispose', function() {
      limpiarTimer();
      destruida = true;
      flush();
      terminada = true;
      document.removeEventListener('visibilitychange', alOcultarse);
    });
  }

  // El servidor ya filtró y el marcador trae los datos firmados: no hay nada
  // que preguntarle. Si faltan, no se monta nada y el alumno se queda con el
  // enlace al navegador que trae el propio marcador.
  function montar(el) {
    var cfg = {
      playlist: el.getAttribute('data-impronta-playlist'),
      poster: el.getAttribute('data-impronta-poster'),
      events: el.getAttribute('data-impronta-events'),
      subject: el.getAttribute('data-impronta-subject'),
      path: el.getAttribute('data-impronta-path'),
      watermark: el.getAttribute('data-impronta-watermark'),
      color: el.getAttribute('data-impronta-color'),
      session: el.getAttribute('data-impronta-session'),
      revoked: el.getAttribute('data-impronta-revoked'),
      evicted: el.getAttribute('data-impronta-evicted')
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
    injectSafeAreaCSS();

    diag('build:cargando-videojs');
    return loadScript(CFG.videojs).then(function() {
      diag('build:videojs-cargado');
      var video = document.createElement('video');
      video.className = 'video-js vjs-default-skin vjs-fluid';
      video.setAttribute('controls', '');
      video.setAttribute('playsinline', '');
      video.setAttribute('preload', 'none');

      // Se vacía el marcador (con él, el botón de respaldo) solo cuando
      // video.js ya está cargado: si la carga falla, el alumno se queda con
      // el botón de abrir en el navegador, que es lo que tenía antes.
      el.textContent = '';
      el.appendChild(video);

      var player = videojs(video, {
        fluid: true,
        playsinline: true,
        poster: cfg.poster || undefined,
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
        if (typeof ImprontaWatermark === 'undefined') {
          // throw y NO return, que es lo que había. Con el return mudo el vídeo
          // se reproducía sin watermark y sin que nada lo dijera — así llegó a
          // producción y así se descubrió: mirando una pantalla. El catch de
          // abajo ya tiene la política correcta: sin watermark no se reproduce.
          //
          // El nombre lo fija rollup.config.mjs del paquete del watermark
          // (output.name). Estuvo desalineado -el bundle exponía
          // ReeloWatermark- y ese desajuste es lo que esta rama no delataba.
          throw new Error('watermark: falta el global ImprontaWatermark');
        }
        var wm = ImprontaWatermark.attach(player, {
          label: cfg.watermark,
          tamperLimit: 3,
          onTamper: function() {}
        });
        window.ImprontaWatermarkFit.attach(player, wm, diag);
      }).catch(function() {
        // Sin watermark no se reproduce: identificar al alumno es el motivo
        // de que exista este reproductor.
        try { player.pause(); } catch (e) {}
        try { player.dispose(); } catch (e) {}
        el.textContent = '';
      }).then(function() {
        analytics(player, cfg);
        sesion(player, cfg, el);
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
      var nodes = document.querySelectorAll(SELECTOR + ':not([data-impronta-ready])');
      if (!nodes.length) { return; }
      diag('encontrados:' + nodes.length);
      for (var i = 0; i < nodes.length; i++) {
        nodes[i].setAttribute('data-impronta-ready', '1');
        montar(nodes[i]);
      }
    } catch (e) {
      diag('revisar-error:' + (e && e.message ? e.message : e));
    }
  }

  try {
    // El origen del webview decide qué debe aceptar la lista blanca de CORS
    // (ver \filter_impronta\request::send_cors_headers). Varía según plataforma
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
