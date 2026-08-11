// Encaja las dos capas del watermark dentro de la IMAGEN del vídeo.
//
// El paquete @impronta/watermark las coloca contra la caja del reproductor, que
// en pantalla completa es toda la pantalla. La imagen no: un vídeo 16:9 en un
// móvil 20:9 deja barras negras, y en vertical esas barras son casi toda la
// pantalla. Las capas caen ahí y no se ven — el WebView de la app de Moodle
// solo pinta el HTML sobre el rectángulo de imagen (medido por ADB el
// 2026-08-07: en horizontal la capa fija sale cortada justo en el borde de la
// imagen, y en vertical no se ve ninguna de las dos).
//
// Aunque se vieran, un watermark sobre negro no identifica a nadie: se
// recorta y listo. Así que la regla es la misma en los dos reproductores —
// dentro de la imagen siempre.
//
// La caja del reproductor no se puede tocar: en pantalla completa la hoja de
// estilos del navegador la fuerza a 100% con !important y gana a cualquier
// regla nuestra.
(function(global) {
  'use strict';

  /**
   * @param {object} player instancia de Video.js
   * @param {object} wm lo que devuelve ImprontaWatermark.attach()
   * @param {function=} avisar telemetría opcional, recibe una cadena
   * @return {{destroy: function}}
   */
  function encajar(player, wm, avisar) {
    var el = player.el();

    function medir() {
      var caja = el.getBoundingClientRect();
      if (!caja.width || !caja.height) { return null; }
      // Sin metadatos todavía, 16:9: es lo que mide todo el catálogo.
      var vw = player.videoWidth() || 16;
      var vh = player.videoHeight() || 9;
      var escala = Math.min(caja.width / vw, caja.height / vh);
      return {
        ancho: caja.width,
        alto: caja.height,
        // Barras negras a cada lado (bx) y arriba y abajo (by).
        bx: Math.max(0, (caja.width - vw * escala) / 2),
        by: Math.max(0, (caja.height - vh * escala) / 2)
      };
    }

    function colocar(reportar) {
      var g = medir();
      if (!g) { return; }

      if (reportar && avisar) {
        avisar('caja:' + Math.round(g.ancho) + 'x' + Math.round(g.alto)
          + '+' + Math.round(g.bx) + '+' + Math.round(g.by));
      }

      // Se comparan las cadenas antes de escribir porque el observador de
      // abajo reacciona a los cambios de style: escribir siempre sería un
      // bucle infinito.
      var fija = el.querySelector('.impronta-watermark-fixed');
      if (fija) {
        var derecha = (g.bx + 6) + 'px';
        var abajo = (g.by + 6) + 'px';
        if (fija.style.right !== derecha) { fija.style.right = derecha; }
        if (fija.style.bottom !== abajo) { fija.style.bottom = abajo; }
      }

      var flota = el.querySelector('.impronta-watermark-float');
      if (flota) {
        var topex = Math.max(g.bx, g.ancho - g.bx - flota.offsetWidth);
        var topey = Math.max(g.by, g.alto - g.by - flota.offsetHeight);
        var x = Math.min(Math.max(parseFloat(flota.style.left) || 0, g.bx), topex) + 'px';
        var y = Math.min(Math.max(parseFloat(flota.style.top) || 0, g.by), topey) + 'px';
        if (flota.style.left !== x) { flota.style.left = x; }
        if (flota.style.top !== y) { flota.style.top = y; }
      }
    }

    // El retardo no es capricho: justo después de rotar o entrar en pantalla
    // completa, getBoundingClientRect todavía devuelve el tamaño anterior.
    // Sin esperar un frame se recolocaría con los datos viejos.
    function recolocar() {
      setTimeout(function() {
        try { wm.reposition(); } catch (e) {}
        colocar(true);
      }, 120);
    }

    player.on('fullscreenchange', recolocar);
    // Los metadatos traen el tamaño real del vídeo; hasta entonces el cálculo
    // va con el 16:9 supuesto.
    player.on('loadedmetadata', recolocar);
    global.addEventListener('resize', recolocar);
    global.addEventListener('orientationchange', recolocar);

    // El paquete recoloca la capa flotante por su cuenta cada 20-40 s, y
    // vuelve a usar la caja del reproductor: sin vigilar su style se volvería
    // a ir a la barra negra al primer salto.
    var vigilante = null;
    var flotante = el.querySelector('.impronta-watermark-float');
    if (flotante && global.MutationObserver) {
      vigilante = new global.MutationObserver(function() { colocar(false); });
      vigilante.observe(flotante, {attributes: true, attributeFilter: ['style']});
    }

    colocar(false);

    function destroy() {
      if (vigilante) { vigilante.disconnect(); vigilante = null; }
      global.removeEventListener('resize', recolocar);
      global.removeEventListener('orientationchange', recolocar);
    }

    // La app es una SPA y monta y desmonta reproductores al navegar: sin
    // quitar los listeners se acumularían apuntando a reproductores muertos.
    player.on('dispose', destroy);

    return {destroy: destroy};
  }

  global.ImprontaWatermarkFit = {attach: encajar};
})(window);
