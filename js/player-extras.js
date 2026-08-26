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
    var queue = [];

    function push(type, pos) {
      queue.push({videoPath: cfg.videoPath, type: type, positionSeconds: Math.round(pos), ts: Date.now()});
    }

    function flush() {
      if (queue.length === 0 || !cfg.eventsUrl) { return; }
      var batch = queue;
      queue = [];
      try {
        fetch(cfg.eventsUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({subject: cfg.subject, events: batch}),
          keepalive: true
        }).catch(function() {});
      } catch (e) {}
    }

    player.on('play', function() { push('play', player.currentTime() || 0); });
    player.on('pause', function() { push('pause', player.currentTime() || 0); });
    player.on('seeked', function() { push('seek', player.currentTime() || 0); });
    player.on('ended', function() { push('complete', player.currentTime() || 0); flush(); });

    var heartbeat = setInterval(function() {
      if (!player.paused()) { push('heartbeat', player.currentTime() || 0); flush(); }
    }, (cfg.heartbeatSeconds || 15) * 1000);

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
      if (latidoTimer) { clearInterval(latidoTimer); latidoTimer = null; }
      try { player.pause(); } catch (e) {}
      aviso(texto);
    }

    function latir() {
      if (!cfg.sessionUrl || sesionTerminada) { return; }
      var enviados = Math.round(vistos);
      fetch(cfg.sessionUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({watchedSeconds: enviados})
      }).then(function(res) {
        if (!res.ok) { return null; }
        // Los segundos solo se descuentan si el latido llegó. Perderlos
        // inflaría la proporción de segmentos servidos por minuto visto y
        // acercaría una alerta a un alumno que no ha hecho nada raro.
        vistos -= enviados;
        return res.json();
      }).then(function(r) {
        if (!r) { return; }
        if (r.blocked) { parar(cfg.revokedText); }
        else if (r.evicted) { parar(cfg.evictedText); }
      }).catch(function() {});
    }

    if (cfg.sessionUrl) {
      // El intervalo arranca en el PRIMER PLAY, no al montar el reproductor.
      // Antes latía todo lo embebido en la página: una página de curso lleva una
      // docena de vídeos, así que a los 120 s salían doce peticiones juntas y las
      // de los que nadie había abierto contestaban 409 —que heartbeat.php
      // devuelve a propósito cuando no hay sesión que renovar, ver su línea 83—,
      // llenando la consola de rojo por algo que funcionaba bien.
      //
      // Con preload="none" arregla además una carrera: la sesión no existe hasta
      // que se pide la playlist, y eso tampoco pasa hasta que se pulsa play.
      //
      // 120 s de partida; el servidor manda el suyo en cada respuesta, pero hace
      // falta uno para el primero.
      player.one('play', function() {
        latidoTimer = setInterval(latir, 120000);
      });
    }

    player.on('dispose', function() {
      clearInterval(heartbeat);
      if (latidoTimer) { clearInterval(latidoTimer); }
      flush();
    });

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
    var recovered = false;

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

    player.on('error', function() {
      // Ya se sabe que no hay nada que recuperar: el mensaje esta puesto.
      if (sesionTerminada) { return; }

      // Pregunta al servidor por el motivo real. Si es un bloqueo, latir()
      // para el reproductor y pone el mensaje que toca; el reintento de abajo
      // no llega a servir de nada porque la playlist nueva daria 403 igual.
      latir();

      if (recovered) {
        aviso(cfg.expiredText);
        return;
      }
      recovered = true;
      var pos = player.currentTime() || 0;
      var sep = cfg.playlistUrl.indexOf('?') === -1 ? '?' : '&';
      player.src({src: cfg.playlistUrl + sep + 'cb=' + Date.now(), type: 'application/x-mpegURL'});
      player.one('playing', function() { recovered = false; });
      if (pos > 0) {
        player.one('loadedmetadata', function() {
          player.currentTime(pos);
          player.play();
        });
      } else {
        player.play();
      }
    });

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
        flush();
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
