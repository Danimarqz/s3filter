// One renewal in flight per player. Only Moodle's authenticated endpoints issue URLs.
window.ImprontaPlaybackRenew = function(cfg, request) {
  var pending = null;
  return function() {
    if (pending) { return pending; }
    pending = Promise.resolve().then(function() { return request(cfg.playlistUrl); }).then(function(fresh) {
      if (!fresh || !fresh.playlistUrl || !fresh.eventsUrl || !fresh.sessionUrl) {
        throw new Error('invalid playback renewal');
      }
      cfg.playlistUrl = fresh.playlistUrl;
      cfg.eventsUrl = fresh.eventsUrl;
      cfg.sessionUrl = fresh.sessionUrl;
      return fresh;
    }).then(function(value) { pending = null; return value; }, function(error) {
      pending = null;
      throw error;
    });
    return pending;
  };
};
