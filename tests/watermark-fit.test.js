const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(__dirname + '/../js/watermark-fit.js', 'utf8');

function harness(caja, video) {
  const timers = [];
  const listeners = {};
  const repositions = [];
  // Nodo del watermark tal y como lo deja el UMD: font-size en el atributo
  // style, que setProperty actualiza.
  const marca = {
    style: {
      fontSize: '11px',
      setProperty(prop, value) {
        if (prop === 'font-size') this.fontSize = value;
      },
    },
  };
  const player = {
    handlers: {},
    caja,
    on(type, handler) { (this.handlers[type] ||= []).push(handler); },
    emit(type) { for (const handler of this.handlers[type] || []) handler(); },
    el() {
      return {
        getBoundingClientRect: () => player.caja,
        querySelector: () => marca,
      };
    },
    videoWidth() { return video.width; },
    videoHeight() { return video.height; },
  };
  const context = {
    window: {
      addEventListener(type, handler) { listeners[type] = handler; },
      removeEventListener(type) { delete listeners[type]; },
    },
    setTimeout(handler) { timers.push(handler); },
  };
  vm.createContext(context);
  vm.runInContext(source, context);
  const fit = context.window.ImprontaWatermarkFit.attach(
    player,
    {reposition: (right, bottom) => repositions.push([right, bottom])}
  );
  return {
    player, marca, fit, repositions, listeners,
    correrTimers() { while (timers.length) timers.shift()(); },
  };
}

test('el texto mide 24px del fotograma, igual que FFmpeg', () => {
  const {marca, repositions} = harness(
    {width: 1920, height: 1080},
    {width: 1920, height: 1080}
  );
  assert.equal(marca.style.fontSize, '24px');
  assert.deepEqual(repositions, [[24, 24]]);
});

test('el texto crece con la caja del reproductor', () => {
  const h = harness({width: 960, height: 540}, {width: 1920, height: 1080});
  assert.equal(marca(h), '12px');

  h.player.caja = {width: 3840, height: 2160};
  h.listeners.resize();
  h.correrTimers();
  assert.equal(marca(h), '48px');
});

test('nunca baja del 11px de partida: ilegible no identifica a nadie', () => {
  const h = harness({width: 320, height: 180}, {width: 1920, height: 1080});
  assert.equal(marca(h), '11px');
});

test('con barras negras el tamaño va por la imagen, no por la caja', () => {
  // Vídeo 16:9 en una caja cuadrada: la imagen ocupa 1000x562, el resto es
  // negro. El tamaño sale de la imagen y la marca se mete dentro de ella.
  const h = harness({width: 1000, height: 1000}, {width: 1920, height: 1080});
  assert.equal(marca(h), '13px');
  assert.deepEqual(h.repositions, [[24, 242.75]]);
});

function marca(h) {
  return h.marca.style.fontSize;
}
