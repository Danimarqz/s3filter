// Lo que se añade a un reproductor del navegador y depende del alumno:
// watermark, analítica y recuperación ante firma caducada.
//
// Va aparte del HTML del reproductor porque ese HTML se cachea por vídeo y
// esto NO se puede cachear: la etiqueta del watermark y el subject de la
// analítica son de cada alumno. \filter_s3video\player::extras imprime una
// llamada a ReeloPlayerExtras() por vídeo con esos datos ya resueltos.
//
// El beacon apunta a events.php de este mismo plugin, que valida el mismo
// token HMAC que protege la playlist y reenvía a Reelo server-side con el
// apikey del tenant. El apikey nunca llega al navegador: filtrarlo dejaría a
// cualquier alumno firmar CloudFront para el catálogo entero (POST
// /moodle/authorize confía en esa misma clave).
//
// cfg = {
//   targetId, watermarkLabel, eventsUrl, subject, videoPath, playlistUrl,
//   expiredText, heartbeatSeconds
// }
window.ReeloPlayerExtras = function(cfg) {
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

    player.on('dispose', function() {
      clearInterval(heartbeat);
      flush();
    });

    // --- Recuperacion ante 403 (firma caducada a mitad de clase) ---------
    // Los segmentos llevan la firma horneada en la playlist; si caduca a
    // mitad de leccion, el reproductor ve una rafaga de 403 y dispara error.
    // Un solo reintento por caducidad: tras cada error, guardar la posicion,
    // recargar la playlist con cache-buster (playlist.php vuelve a firmar con
    // Reelo si la firma anterior caduco), restaurar la posicion y reanudar.
    // recovered se resetea en el primer 'playing' posterior: una sesion larga
    // que sufra varias caducidades (una cada firma) se recupera cada vez. Si
    // la recarga no llega a reproducir, recovered sigue true y el siguiente
    // error muestra el mensaje explicito.
    var recovered = false;

    function showSessionExpired() {
      var el = document.getElementById(targetId);
      if (!el) { return; }
      var msg = document.createElement('div');
      msg.setAttribute('role', 'alert');
      msg.className = 'alert alert-warning';
      msg.style.cssText = 'margin:0.5em 0;padding:0.6em 1em;';
      msg.textContent = cfg.expiredText;
      el.parentNode.insertBefore(msg, el.nextSibling);
    }

    player.on('error', function() {
      if (recovered) {
        showSessionExpired();
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

    var wm = ReeloWatermark.attach(player, {
      label: cfg.watermarkLabel,
      tamperLimit: 3,
      onTamper: function(count, position) {
        push('tamper', position);
        flush();
      }
    });

    window.ReeloWatermarkFit.attach(player, wm);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', waitForEl);
  } else {
    waitForEl();
  }
};
