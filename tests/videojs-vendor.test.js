const test = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');

const plugin = join(__dirname, '..');
const read = (path) => readFileSync(join(plugin, path), 'utf8');

test('Video.js se sirve desde el plugin en web y app', () => {
  const php = read('classes/player.php') + read('classes/output/mobile.php');

  assert.doesNotMatch(php, /vjs\.zencdn\.net/);
  assert.equal((php.match(/vendor\/video\.js\/video\.min\.js/g) || []).length, 2);
  assert.equal((php.match(/vendor\/video\.js\/video-js\.min\.css/g) || []).length, 2);
  assert.equal((php.match(/vendor\/video\.js\/vtt\/vtt\.min\.js/g) || []).length, 2);
  assert.match(read('js/app-player.js'), /'vtt\.js': CFG\.vttjs/);
  assert.match(read('vendor/video.js/video.min.js'), /Video\.js 8\.16\.1/);
  assert.match(read('vendor/video.js/LICENSE'), /Apache License, Version 2\.0/);
  assert.match(read('vendor/video.js/vtt/LICENSE'), /Apache License, Version 2\.0/);
});
