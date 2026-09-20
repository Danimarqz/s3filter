// Lo que se añade a un reproductor del navegador y depende del alumno:
// watermark, analítica y recuperación ante firma caducada.
//
// Va aparte del HTML del reproductor porque ese HTML se cachea por vídeo y
// esto NO se puede cachear: la etiqueta del watermark y el subject de la
// analítica son de cada alumno. \filter_impronta\player::extras imprime una
// llamada a ImprontaPlayerExtras() por vídeo con esos datos ya resueltos.
//
// El beacon apunta a events.php de este mismo plugin, que valida el mismo
// token HMAC que protege la playlist y reenvía a Impronta server-side con el
// apikey del tenant. El apikey nunca llega al navegador: filtrarlo dejaría a
// cualquier alumno firmar CloudFront para el catálogo entero (POST
// /moodle/authorize confía en esa misma clave).
//
// cfg = {
//   targetId, watermarkLabel, eventsUrl, subject, videoPath, playlistUrl,
//   expiredText, heartbeatSeconds, sessionUrl, revokedText, evictedText
// }
window.ImprontaPlayerExtras = function(cfg) {
  'use strict';

  var targetId = cfg.targetId;

  function waitForEl() {
    var el = document.getElementById(targetId);
    if (!el) { setTimeout(waitForEl, 150); return; }
    waitForPlayer();
  }

  function waitForPlayer() {
    if (typeof videojs === 'undefined') { setTimeout(waitForPlayer, 150); return; }
    var player = videojs.getPlayer ? videojs.getPlayer(targetId) : videojs(targetId);
    if (!player || !player.ready) { setTimeout(waitForPlayer, 150); return; }
    player.ready(function() { init(player); });
  }

  function init(player) {
    var positionKey = 'impronta:position:' + cfg.subject + ':' + cfg.videoPath;
    try {
      var saved = Number(localStorage.getItem(positionKey));
      if (saved > 0) { player.one('loadedmetadata', function() { player.currentTime(saved); }); }
    } catch (e) {}
    player.on('timeupdate', function() {
      try { if (player.currentTime() > 0) { localStorage.setItem(positionKey, String(player.currentTime())); } } catch (e) {}
    });
    player.on('ended', function() { try { localStorage.removeItem(positionKey); } catch (e) {} });
    var renew = cfg.renewUrl && window.ImprontaPlaybackRenew &&
      window.ImprontaPlaybackRenew(cfg, function(url) {
        var form = new FormData();
        form.append('url', url);
        form.append('sesskey', cfg.sesskey);
        return fetch(cfg.renewUrl, {method: 'POST', body: form, credentials: 'same-origin'})
          .then(function(res) { if (!res.ok) { throw new Error('renew ' + res.status); } return res.json(); });
      });
    var queue = [];
    var analyticsHeartbeats = 0;
    var analyticsHeartbeatSeconds = cfg.heartbeatSeconds || 15;
    var analyticsHeartbeatsPerFlush = Math.max(1, Math.ceil(180 / analyticsHeartbeatSeconds));
    var flushReasons = {
      interval: true, pause: true, complete: true,
      pagehide: true, dispose: true, tamper: true
    };

    function push(type, pos) {
      queue.push({videoPath: cfg.videoPath, type: type, positionSeconds: Math.round(pos), ts: Date.now()});
    }

    function flush(reason) {
      if (queue.length === 0 || !cfg.eventsUrl) { return; }
      if (!flushReasons[reason]) { reason = 'interval'; }
      var batch = queue;
      queue = [];
      analyticsHeartbeats = 0;
      try {
        fetch(cfg.eventsUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({subject: cfg.subject, flushReason: reason, events: batch}),
          keepalive: true
        }).catch(function() {});
      } catch (e) {}
    }

    player.on('play', function() { push('play', player.currentTime() || 0); });
    player.on('pause', function() { push('pause', player.currentTime() || 0); flush('pause'); });
    player.on('seeked', function() { push('seek', player.currentTime() || 0); });
    player.on('ended', function() { push('complete', player.currentTime() || 0); flush('complete'); });

    var heartbeat = setInterval(function() {
      if (!player.paused()) {
        push('heartbeat', player.currentTime() || 0);
        analyticsHeartbeats += 1;
        // Conserva la resolución de 15 s, pero agrupa 3 min en cada POST.
        if (analyticsHeartbeats >= analyticsHeartbeatsPerFlush) { flush('interval'); }
      }
    }, analyticsHeartbeatSeconds * 1000);

    // --- Latido de la sesión de reproducción ------------------------------
    // Distinto del beacon de arriba, que es analítica. Este mantiene viva la
    // sesión, reporta cuánto vídeo se ha visto -el denominador de la detección
    // de descarga masiva- y trae de vuelta si hay que parar.
    //
    // El identificador de sesión NO está aquí: lo guardó playlist.php en el
    // servidor y heartbeat.php lo recupera. Este JS no puede latir por una
    // sesión ajena porque no conoce ninguna.
    var vistos = 0;
    var ultimoTiempo = player.currentTime() || 0;
    var sesionTerminada = false;
    var latidoTimer = null;
    var sesionIniciada = false;
    var sesionReproduciendo = false;
    var latidoEnVuelo = false;
    var lotePendiente = null;
    var flushPendiente = false;
    var disposePendiente = false;
    var primerLatidoPendiente = true;
    var siguienteLatido = 120000;

    // Los segundos se acumulan de los avances pequeños de currentTime y no del
    // reloj: así una pausa no cuenta, un rebobinado no resta, y ver a 2x cuenta
    // el doble, que es lo que Impronta compara contra los segmentos servidos.
    player.on('timeupdate', function() {
      var t = player.currentTime() || 0;
      var delta = t - ultimoTiempo;
      // timeupdate salta unas cuatro veces por segundo: más de dos segundos es
      // un salto en la línea de tiempo, no vídeo visto.
      if (delta > 0 && delta < 2) { vistos += delta; }
      ultimoTiempo = t;
    });

    function parar(texto) {
      sesionTerminada = true;
      if (latidoTimer) { clearTimeout(latidoTimer); latidoTimer = null; }
      try { player.pause(); } catch (e) {}
      aviso(texto);
    }

    function programarLatido(despues) {
      if (!sesionReproduciendo || sesionTerminada || latidoTimer) { return; }
      latidoTimer = setTimeout(function() {
        latidoTimer = null;
        primerLatidoPendiente = false;
        latir(true);
      }, despues);
    }

    // Identificador único del lote, con el mismo fallback que la webapp.
    function nuevoBatchId() {
      try {
        if (typeof crypto !== 'undefined' && crypto && typeof crypto.randomUUID === 'function') {
          return crypto.randomUUID();
        }
      } catch (e) {}
      return 'b' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10) + Math.random().toString(36).slice(2, 10);
    }

    // Lote congelado: al latir se fija {enviados, batchId} y, si el servidor no
    // confirma, el siguiente intento reenvía EXACTAMENTE el mismo lote hasta que
    // confirme. Los segundos que sigan llegando mientras hay un lote pendiente
    // no se mezclan: abren el siguiente. Así un reintento tras un ACK perdido
    // se deduplica en el backend y no se pierde ni se duplica nada.
    function latir(forzado, permitirDispose) {
      if (!cfg.sessionUrl || (sesionTerminada && !permitirDispose) || !sesionIniciada) { return; }
      if (latidoEnVuelo) { flushPendiente = true; return; }
      if (!lotePendiente) {
        var iniciales = Math.round(vistos);
        if (!forzado && iniciales <= 0) { return; }
        lotePendiente = {enviados: iniciales, batchId: nuevoBatchId()};
      }
      var lote = lotePendiente;
      latidoEnVuelo = true;
      Promise.resolve().then(function() {
        return fetch(cfg.sessionUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({watchedSeconds: lote.enviados, batchId: lote.batchId}),
          keepalive: true
        });
      }).then(function(res) {
        if (!res || !res.ok) { throw new Error('heartbeat failed'); }
        return typeof res.json === 'function' ? res.json() : {};
      }).then(function(r) {
        r = r || {};
        // Los segundos solo se descuentan si el latido llegó. Perderlos
        // inflaría la proporción de segmentos servidos por minuto visto y
        // acercaría una alerta a un alumno que no ha hecho nada raro.
        vistos = Math.max(0, vistos - lote.enviados);
        lotePendiente = null;
        latidoEnVuelo = false;
        if (r.blocked) { parar(cfg.revokedText); }
        else if (r.evicted) { parar(cfg.evictedText); }
        siguienteLatido = Number(r.heartbeatSeconds) > 0 ? Number(r.heartbeatSeconds) * 1000 : 120000;
        if (flushPendiente) {
          flushPendiente = false;
          var permitirDispose = disposePendiente;
          disposePendiente = false;
          if (Math.round(vistos) > 0) { latir(false, permitirDispose); }
          else { programarLatido(siguienteLatido); }
        } else {
          programarLatido(siguienteLatido);
        }
      }).catch(function() {
        latidoEnVuelo = false;
        // El lote sigue pendiente: el siguiente intento reenvía el mismo.
        if (flushPendiente) {
          flushPendiente = false;
          var permitirDispose = disposePendiente;
          disposePendiente = false;
          if (lotePendiente || Math.round(vistos) > 0) { latir(false, permitirDispose); }
          else { programarLatido(120000); }
        } else {
          programarLatido(120000);
        }
      });
    }

    player.on('play', function() {
      if (!cfg.sessionUrl || sesionTerminada) { return; }
      sesionIniciada = true;
      sesionReproduciendo = true;
      programarLatido(primerLatidoPendiente ? 30000 : siguienteLatido);
    });
    player.on('pause', function() {
      sesionReproduciendo = false;
      if (latidoTimer) { clearTimeout(latidoTimer); latidoTimer = null; }
      latir(false);
    });
    player.on('ended', function() {
      sesionReproduciendo = false;
      if (latidoTimer) { clearTimeout(latidoTimer); latidoTimer = null; }
      latir(false);
    });
    function flushSesion() {
      latir(false);
    }

    player.on('dispose', function() {
      clearInterval(heartbeat);
      clearInterval(relojAtasco);
      sesionReproduciendo = false;
      if (latidoTimer) { clearTimeout(latidoTimer); latidoTimer = null; }
      var habiaLatidoEnVuelo = latidoEnVuelo;
      disposePendiente = true;
      flushSesion();
      sesionTerminada = true;
      if (!habiaLatidoEnVuelo) { disposePendiente = false; }
      flush('dispose');
      document.removeEventListener('visibilitychange', onVisibilityChange);
      window.removeEventListener('pagehide', onPageHide);
    });

    function onVisibilityChange() {
      if (document.visibilityState === 'hidden') {
        flushSesion();
      } else {
        refreshIfExpired();
      }
    }
    document.addEventListener('visibilitychange', onVisibilityChange);

    function onPageHide() {
      flush('pagehide');
      flushSesion();
    }
    window.addEventListener('pagehide', onPageHide);

    // --- Recuperacion ante 403 ------------------------------------------
    // Un 403 en un segmento ya no significa solo "la firma caduco": ahora
    // puede ser tambien que se le haya revocado el acceso al alumno, y los
    // dos casos piden lo contrario -uno recargar, el otro parar y explicar-.
    // Distinguirlos leyendo el mensaje de error de video.js no es fiable, asi
    // que se hace un latido: la respuesta ya sabe si esta bloqueado.
    //
    // Un solo reintento por caducidad: tras cada error, guardar la posicion,
    // recargar la playlist con cache-buster (playlist.php pide una playlist
    // nueva a Impronta), restaurar la posicion y reanudar. recovered se resetea
    // en el primer 'playing' posterior. Si la recarga no llega a reproducir,
    // recovered sigue true y el siguiente error muestra el mensaje explicito.
    function aviso(texto) {
      var el = document.getElementById(targetId);
      if (!el || !texto) { return; }
      var msg = document.createElement('div');
      msg.setAttribute('role', 'alert');
      msg.className = 'alert alert-warning';
      msg.style.cssText = 'margin:0.5em 0;padding:0.6em 1em;';
      msg.textContent = texto;
      el.parentNode.insertBefore(msg, el.nextSibling);
    }

    var recovered = false;
    var renewing = false;

    // Recarga la playlist con cache-buster: playlist.php pide una playlist
    // nueva a Impronta, con nueva sesion de reproduccion y nuevas firmas de
    // segmento. La posicion se restaura y el latido (que no conoce el
    // sessionId: lo recupera heartbeat.php) pasa a renovar la nueva sesion
    // sin que este JS tenga que saber nada.
    function recargarPlaylist() {
      if (!renew) { aviso(cfg.expiredText); return; }
      if (renewing) { return; }
      renewing = true;
      recovered = true;
      var pos = player.currentTime() || 0;
      renew().then(function() {
        renewing = false;
        player.src({src: cfg.playlistUrl, type: 'application/x-mpegURL'});
        player.one('playing', function() { recovered = false; });
        if (pos > 0) {
          player.one('loadedmetadata', function() {
            player.currentTime(pos);
            player.play();
          });
        } else {
          player.play();
        }
      }).catch(function() { renewing = false; aviso(cfg.expiredText); });
    }

    function refreshIfExpired() {
      var match = /[?&]e=(\d+)/.exec(cfg.playlistUrl || '');
      if (match && Number(match[1]) * 1000 < Date.now() + 60000 && !recovered) {
        recargarPlaylist();
      }
    }
    player.on('play', refreshIfExpired);
    window.addEventListener('pageshow', refreshIfExpired);

    player.on('error', function() {
      // Ya se sabe que no hay nada que recuperar: el mensaje esta puesto.
      if (sesionTerminada) { return; }
      if (renewing) { return; }

      // Pregunta al servidor por el motivo real. Si es un bloqueo, latir()
      // para el reproductor y pone el mensaje que toca; el reintento de abajo
      // no llega a servir de nada porque la playlist nueva daria 403 igual.
      latir(true);

      if (recovered) {
        aviso(cfg.expiredText);
        return;
      }
      recargarPlaylist();
    });

    // --- Video colgado ----------------------------------------------------
    // http-streaming reintenta los segmentos por debajo y no siempre llega a
    // montar un error visible: el reproductor queda en waiting para siempre
    // con la posicion congelada y los latidos de analitica saliendo a su ritmo
    // (así se vio el 2026-08-31: 25 minutos de reintentos contra un segmento
    // 403 sin que video.js montara nunca su error). Un reproductor en play que
    // lleva 25 s sin avanzar un segundo y sin buscar esta atascado: se recarga
    // la playlist igual que en la recuperación ante 403.
    var ultimoProgreso = 0;
    try { ultimoProgreso = player.currentTime() || 0; } catch (e) {}
    var atascado = 0;
    var relojAtasco = setInterval(function() {
      // En dispose el player ya no responde: cualquier método puede lanzar.
      try {
        if (sesionTerminada || player.paused() || player.seeking()) { atascado = 0; return; }
        var t = player.currentTime() || 0;
        if (t !== ultimoProgreso) { atascado = 0; ultimoProgreso = t; return; }
        atascado += 5;
        if (atascado >= 25) {
          atascado = 0;
          if (recovered) { aviso(cfg.expiredText); return; }
          latir(true);
          recargarPlaylist();
        }
      } catch (e) { atascado = 0; }
    }, 5000);

    if (!cfg.watermarkLabel) { return; }

    // El nombre lo fija output.name en el rollup.config.mjs del paquete del
    // watermark. Estuvo en ReeloWatermark mientras aquí se pedía
    // ImprontaWatermark, así que esta línea lanzaba un TypeError -"cannot read
    // properties of undefined"- y el escritorio se quedaba tan sin watermark como
    // la app, solo con el error enterrado en la consola.
    if (typeof ImprontaWatermark === 'undefined') {
      throw new Error('watermark: falta el global ImprontaWatermark');
    }

    var wm = ImprontaWatermark.attach(player, {
      label: cfg.watermarkLabel,
      tamperLimit: 3,
      onTamper: function(count, position) {
        push('tamper', position);
        flush('tamper');
      }
    });

    window.ImprontaWatermarkFit.attach(player, wm);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', waitForEl);
  } else {
    waitForEl();
  }
};
