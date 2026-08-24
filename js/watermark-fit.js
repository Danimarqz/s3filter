// Encaja el watermark fijo dentro de la IMAGEN del vídeo.
//
// El paquete @impronta/watermark lo coloca contra la caja del reproductor, que
// en pantalla completa es toda la pantalla. La imagen no: un vídeo 16:9 en un
// móvil 20:9 deja barras negras, y en vertical esas barras son casi toda la
// pantalla. La marca puede caer ahí y no verse — el WebView de la app de Moodle
// solo pinta el HTML sobre el rectángulo de imagen (medido por ADB el
// 2026-08-07: en horizontal la capa fija sale cortada justo en el borde de la
// imagen, y en vertical no se ve).
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
      // 24px desde el borde del fotograma, igual que FFmpeg. bx/by eliminan
      // las barras negras del WebView sin tocar la caja de Video.js. El UMD es
      // el único que escribe los estilos y puede reafirmarlos ante tampering.
      wm.reposition(g.bx + 24, g.by + 24);
    }

    // El retardo no es capricho: justo después de rotar o entrar en pantalla
    // completa, getBoundingClientRect todavía devuelve el tamaño anterior.
    // Sin esperar un frame se recolocaría con los datos viejos.
    function recolocar() {
      setTimeout(function() {
        try { colocar(true); } catch (e) {}
      }, 120);
    }

    player.on('fullscreenchange', recolocar);
    // Los metadatos traen el tamaño real del vídeo; hasta entonces el cálculo
    // va con el 16:9 supuesto.
    player.on('loadedmetadata', recolocar);
    global.addEventListener('resize', recolocar);
    global.addEventListener('orientationchange', recolocar);

    colocar(false);

    function destroy() {
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
