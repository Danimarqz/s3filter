const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(__dirname + '/../js/app-player.js', 'utf8');

function harness(firstResponse) {
  let now = 0;
  let nextTimer = 1;
  let player;
  const timers = new Map();
  const requests = [];
  const documentListeners = {};
  const windowListeners = {};
  const element = {
    attrs: {
      'data-impronta-playlist': '/playlist.m3u8',
      'data-impronta-poster': '/poster.jpg',
      'data-impronta-session': '/session',
      'data-impronta-watermark': 'student',
      'data-impronta-events': '/events',
      'data-impronta-subject': 'student',
      'data-impronta-path': 'lesson.m3u8'
    },
    getAttribute(name) { return this.attrs[name] || null; },
    setAttribute(name, value) { this.attrs[name] = value; },
    appendChild() {},
    textContent: ''
  };
  const document = {
    visibilityState: 'visible',
    head: {appendChild(node) { if (node.onload) node.onload(); }},
    body: {observe() {}},
    getElementById() { return null; },
    querySelector() { return null; },
    querySelectorAll() { return element.attrs['data-impronta-ready'] ? [] : [element]; },
    createElement(tag) {
      if (tag === 'script') {
        return {
          dataset: {},
          addEventListener(type, handler) { this['on' + type] = handler; }
        };
      }
      return {setAttribute() {}, appendChild() {}, style: {}, textContent: ''};
    },
    addEventListener(type, handler) { documentListeners[type] = handler; },
    removeEventListener(type, handler) {
      if (documentListeners[type] === handler) delete documentListeners[type];
    }
  };
  let videojsOptions;
  const context = {
    window: {
      improntaApp: {wwwroot: '', componente: 'impronta', videojscss: '/video.css', videojs: '/video.js'},
      addEventListener(type, handler) { windowListeners[type] = handler; },
      removeEventListener(type, handler) {
        if (windowListeners[type] === handler) delete windowListeners[type];
      }
    },
    document,
    MutationObserver: function() { this.observe = function() {}; },
    setTimeout(handler, delay) {
      const id = nextTimer++;
      timers.set(id, {handler, delay, due: now + delay, repeat: false});
      return id;
    },
    clearTimeout(id) { timers.delete(id); },
    setInterval(handler, delay) {
      const id = nextTimer++;
      timers.set(id, {handler, delay, due: now + delay, repeat: true});
      return id;
    },
    clearInterval(id) { timers.delete(id); },
    fetch(url, options) {
      requests.push({url, options});
      if (url === '/session' && requests.filter((r) => r.url === '/session').length === 1) {
        return firstResponse;
      }
      return Promise.resolve({ok: true, json: () => Promise.resolve({heartbeatSeconds: 60})});
    },
    videojs(video, options) { videojsOptions = options; return player; },
    ImprontaWatermark: {attach() { return {}; }},
    ImprontaWatermarkFit: {attach() {}},
    console
  };
  context.window.window = context.window;
  context.window.ImprontaWatermarkFit = context.ImprontaWatermarkFit;
  player = {
    handlers: {},
    playing: false,
    position: 0,
    on(type, handler) { (this.handlers[type] ||= []).push(handler); },
    one(type, handler) { this.on(type, handler); },
    emit(type) {
      if (type === 'play') this.playing = true;
      if (type === 'pause' || type === 'ended') this.playing = false;
      for (const handler of this.handlers[type] || []) handler();
    },
    paused() { return !this.playing; },
    currentTime() { return this.position; },
    el() { return {querySelector() { return null; }}; },
    pause() { this.playing = false; },
    dispose() { this.emit('dispose'); }
  };
  vm.runInNewContext(source, context, {filename: 'app-player.js'});

  async function advance(milliseconds) {
    const target = now + milliseconds;
    while (true) {
      const due = [...timers.entries()].find(([, timer]) => timer.due <= target);
      if (!due) break;
      now = due[1].due;
      if (due[1].repeat) due[1].due += due[1].delay;
      else timers.delete(due[0]);
      due[1].handler();
      await settle();
    }
    now = target;
    await settle();
  }
  async function settle() {
    for (let i = 0; i < 20; i += 1) await Promise.resolve();
  }
  return {player, requests, windowListeners, videojsOptions: () => videojsOptions, advance, settle};
}

test('flushes seconds added while a heartbeat is in flight', async () => {
  let resolveFirst;
  const first = new Promise((resolve) => { resolveFirst = resolve; });
  const h = harness(first);

  await h.settle();
  assert.equal(h.videojsOptions().poster, '/poster.jpg');
  h.player.emit('play');
  await h.advance(30000);
  for (let position = 1; position <= 8; position += 1) {
    h.player.position = position;
    h.player.emit('timeupdate');
  }
  h.player.emit('pause');
  assert.equal(h.requests.filter((request) => request.url === '/session').length, 1);

  resolveFirst({ok: true, json: () => Promise.resolve({heartbeatSeconds: 60})});
  await h.settle();
  const sessions = h.requests.filter((request) => request.url === '/session');
  assert.equal(sessions.length, 2);
  assert.deepEqual(JSON.parse(sessions[1].options.body), {watchedSeconds: 8});
});

test('batches analytics for three minutes and flushes terminal events', async () => {
  const h = harness(Promise.resolve({ok: true, json: () => Promise.resolve({heartbeatSeconds: 60})}));
  await h.settle();
  h.player.emit('play');
  await h.advance(179999);
  assert.equal(h.requests.filter((request) => request.url === '/events').length, 0);
  await h.advance(1);

  let events = h.requests.filter((request) => request.url === '/events');
  assert.equal(events.length, 1);
  assert.equal(JSON.parse(events[0].options.body).flushReason, 'interval');
  assert.equal(JSON.parse(events[0].options.body).events.filter((event) => event.type === 'heartbeat').length, 6);

  h.player.emit('pause');
  h.player.emit('play');
  h.windowListeners.pagehide();
  events = h.requests.filter((request) => request.url === '/events');
  assert.deepEqual(events.slice(1).map((request) => JSON.parse(request.options.body).flushReason), ['pause', 'pagehide']);
});
