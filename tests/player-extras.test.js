const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(__dirname + '/../js/player-extras.js', 'utf8');

function harness(sessionFetch) {
  let now = 0;
  let nextId = 1;
  const timers = new Map();
  const requests = [];
  const listeners = {};
  const element = {parentNode: {insertBefore() {}}};
  const player = {
    handlers: {},
    playing: false,
    position: 0,
    on(type, handler) {
      (this.handlers[type] ||= []).push(handler);
    },
    one(type, handler) {
      const once = () => {
        this.handlers[type] = (this.handlers[type] || []).filter((item) => item !== once);
        handler();
      };
      this.on(type, once);
    },
    emit(type) {
      if (type === 'play') this.playing = true;
      if (type === 'pause' || type === 'ended') this.playing = false;
      for (const handler of this.handlers[type] || []) handler();
    },
    ready(handler) { handler(); },
    paused() { return !this.playing; },
    currentTime() { return this.position; },
    pause() { this.playing = false; },
    src() {},
  };
  const context = {
    window: {},
    document: {
      readyState: 'complete',
      visibilityState: 'visible',
      getElementById() { return element; },
      addEventListener(type, handler) { listeners[type] = handler; },
      removeEventListener(type, handler) {
        if (listeners[type] === handler) delete listeners[type];
      },
      createElement() { return {setAttribute() {}, style: {}, textContent: ''}; },
    },
    videojs: {getPlayer() { return player; }},
    fetch(url, options) {
      requests.push({url, options});
      if (url === '/session' && sessionFetch) return sessionFetch(requests.length, options);
      return Promise.resolve({ok: true, json: () => Promise.resolve({heartbeatSeconds: 75})});
    },
    setTimeout(handler, delay) {
      const id = nextId++;
      timers.set(id, {handler, delay, due: now + delay, repeat: false});
      return id;
    },
    clearTimeout(id) { timers.delete(id); },
    setInterval(handler, delay) {
      const id = nextId++;
      timers.set(id, {handler, delay, due: now + delay, repeat: true});
      return id;
    },
    clearInterval(id) { timers.delete(id); },
    Date: {now: () => now},
    console,
  };
  context.window = context;
  vm.runInNewContext(source, context, {filename: 'player-extras.js'});
  context.ImprontaPlayerExtras({
    targetId: 'video',
    eventsUrl: '/events',
    sessionUrl: '/session',
    subject: 'student',
    videoPath: 'lesson.m3u8',
    heartbeatSeconds: 15,
  });

  async function advance(milliseconds) {
    const target = now + milliseconds;
    while (true) {
      let next = null;
      for (const [id, timer] of timers) {
        if (timer.due <= target && (!next || timer.due < next.timer.due)) next = {id, timer};
      }
      if (!next) break;
      now = next.timer.due;
      if (next.timer.repeat) next.timer.due += next.timer.delay;
      else timers.delete(next.id);
      next.timer.handler();
      await settle();
    }
    now = target;
    await settle();
  }

  async function settle() {
    for (let i = 0; i < 5; i += 1) await Promise.resolve();
  }

  return {player, requests, listeners, document: context.document, advance, settle};
}

function watch(player, seconds) {
  for (let position = 1; position <= seconds; position += 1) {
    player.position = position;
    player.emit('timeupdate');
  }
}

test('sends the first session heartbeat 30 seconds after play', async () => {
  const h = harness();
  assert.equal(h.requests.filter((request) => request.url === '/session').length, 0);
  h.player.emit('play');
  await h.advance(29999);
  assert.equal(h.requests.filter((request) => request.url === '/session').length, 0);
  await h.advance(1);
  const sessionRequests = h.requests.filter((request) => request.url === '/session');
  assert.equal(sessionRequests.length, 1);
  assert.equal(sessionRequests[0].options.keepalive, true);
});

test('uses the server heartbeat interval and falls back to 120 seconds', async () => {
  const h = harness(() => Promise.resolve({ok: true, json: () => Promise.resolve({heartbeatSeconds: 75})}));
  h.player.emit('play');
  await h.advance(30000);
  await h.advance(74999);
  assert.equal(h.requests.filter((request) => request.url === '/session').length, 1);
  await h.advance(1);
  assert.equal(h.requests.filter((request) => request.url === '/session').length, 2);

  const fallback = harness(() => Promise.resolve({ok: true, json: () => Promise.resolve({})}));
  fallback.player.emit('play');
  await fallback.advance(30000);
  await fallback.advance(119999);
  assert.equal(fallback.requests.filter((request) => request.url === '/session').length, 1);
  await fallback.advance(1);
  assert.equal(fallback.requests.filter((request) => request.url === '/session').length, 2);
});

test('flushes watched seconds on pause and retains them after a failed request', async () => {
  let calls = 0;
  const h = harness(() => {
    calls += 1;
    if (calls === 1) return Promise.reject(new Error('offline'));
    return Promise.resolve({ok: true, json: () => Promise.resolve({heartbeatSeconds: 60})});
  });
  h.player.emit('play');
  watch(h.player, 10);
  h.player.emit('pause');
  await h.settle();
  let sessionRequests = h.requests.filter((request) => request.url === '/session');
  assert.equal(sessionRequests.length, 1);
  assert.deepEqual(JSON.parse(sessionRequests[0].options.body), {watchedSeconds: 10});

  h.player.emit('play');
  await h.advance(120000);
  sessionRequests = h.requests.filter((request) => request.url === '/session');
  assert.equal(sessionRequests.length, 3);
  assert.deepEqual(JSON.parse(sessionRequests[1].options.body), {watchedSeconds: 10});
});

test('does not overlap a heartbeat and flushes seconds added while it is pending', async () => {
  let resolveFirst;
  const h = harness(() => new Promise((resolve) => { resolveFirst = resolve; }));
  h.player.emit('play');
  await h.advance(30000);
  watch(h.player, 8);
  h.player.emit('pause');
  await h.settle();
  assert.equal(h.requests.filter((request) => request.url === '/session').length, 1);
  resolveFirst({ok: true, json: () => Promise.resolve({heartbeatSeconds: 60})});
  await h.advance(0);
  const sessionRequests = h.requests.filter((request) => request.url === '/session');
  assert.equal(sessionRequests.length, 2);
  assert.deepEqual(JSON.parse(sessionRequests[1].options.body), {watchedSeconds: 8});
});

test('flushes seconds added before dispose after the in-flight heartbeat completes', async () => {
  const resolvers = [];
  const h = harness(() => new Promise((resolve) => { resolvers.push(resolve); }));
  h.player.emit('play');
  await h.advance(30000);
  watch(h.player, 6);
  h.player.emit('dispose');
  await h.settle();
  assert.equal(h.requests.filter((request) => request.url === '/session').length, 1);

  resolvers.shift()({ok: true, json: () => Promise.resolve({heartbeatSeconds: 60})});
  await h.settle();
  let sessionRequests = h.requests.filter((request) => request.url === '/session');
  assert.equal(sessionRequests.length, 2);
  assert.deepEqual(JSON.parse(sessionRequests[1].options.body), {watchedSeconds: 6});

  resolvers.shift()({ok: true, json: () => Promise.resolve({heartbeatSeconds: 60})});
  await h.settle();
  h.player.emit('pause');
  await h.advance(120000);
  sessionRequests = h.requests.filter((request) => request.url === '/session');
  assert.equal(sessionRequests.length, 2);
});

test('flushes on hidden and removes the visibility listener on dispose', async () => {
  const h = harness();
  h.player.emit('play');
  watch(h.player, 5);
  h.document.visibilityState = 'hidden';
  h.listeners.visibilitychange();
  await h.settle();
  assert.equal(h.requests.filter((request) => request.url === '/session').length, 1);
  h.player.emit('dispose');
  assert.equal(h.listeners.visibilitychange, undefined);
});
