/*
 * zindello-cluster: live detection's boxes, drawn over a recording or the live picture
 * (external-security.zindello.com.au/docs/live-detection.md, "The viewer").
 *
 * ZMDetections.overlay(target, options) lays a canvas over `target` (an <img> or <video>) inside
 * its parent, so it follows zoom and pan with it. Give it frames ({t: seconds since the epoch,
 * boxes: [{label, confidence, box: [x1, y1, x2, y2] as fractions}]}) and ask it to draw at a time:
 * it draws the newest frame at or before that time if it's within 1.5 sampling intervals,
 * otherwise nothing. Every draw is a fresh lookup, so play, pause, seek and slow motion all work
 * the same way. Each label has its own solid colour, with "person 87%" on the box.
 *
 * ZMDetections.readStream(url, onFrame, signal) reads ZoneMinder's MJPEG stream (nph-zms,
 * mode=jpeg) with fetch(), splits it into frames and gives each one with its X-Timestamp header:
 * the frame's capture stamp, the very value the live sidecar stores (cluster-docs T150).
 */
var ZMDetections = (function() {
  'use strict';

  var COLOURS = {
    person: '#ff3b30', car: '#34c759', truck: '#0a84ff', bus: '#ff9f0a', motorcycle: '#bf5af2',
    bicycle: '#64d2ff', boat: '#ffd60a',
    bird: '#ff375f', cat: '#ff375f', dog: '#ff375f', horse: '#ff375f', sheep: '#ff375f', cow: '#ff375f',
    elephant: '#ff375f', bear: '#ff375f', zebra: '#ff375f', giraffe: '#ff375f'
  };
  var ANIMALS = {bird: 1, cat: 1, dog: 1, horse: 1, sheep: 1, cow: 1, elephant: 1, bear: 1, zebra: 1, giraffe: 1};

  function shown(label) {
    return ANIMALS[label] ? 'animal' : label;
  }

  function overlay(target, options) {
    options = options || {};
    var canvas = document.createElement('canvas');
    canvas.className = 'detections';
    var parent = target.parentNode;
    if (getComputedStyle(parent).position == 'static') parent.style.position = 'relative';
    parent.appendChild(canvas);

    var o = {
      canvas: canvas,
      frames: [],          // sorted by t
      interval: options.interval || 0.2,
      visible: options.visible !== false,
      lastDrawn: null
    };

    o.setFrames = function(frames) {
      o.frames = frames.slice().sort(function(a, b) { return a.t - b.t; });
    };

    // New frames, in time order, after the ones held; old ones dropped past `keep` seconds.
    o.addFrames = function(frames, keep) {
      for (var i = 0; i < frames.length; i++) {
        var f = frames[i];
        if (!o.frames.length || f.t > o.frames[o.frames.length - 1].t) o.frames.push(f);
      }
      if (keep && o.frames.length) {
        var cutoff = o.frames[o.frames.length - 1].t - keep;
        var n = 0;
        while (n < o.frames.length && o.frames[n].t < cutoff) n++;
        if (n) o.frames.splice(0, n);
      }
    };

    o.newest = function() {
      return o.frames.length ? o.frames[o.frames.length - 1].t : 0;
    };

    // The newest frame at or before t, if within 1.5 intervals; else null.
    o.frameAt = function(t) {
      var lo = 0, hi = o.frames.length - 1, best = -1;
      while (lo <= hi) {
        var mid = (lo + hi) >> 1;
        if (o.frames[mid].t <= t) { best = mid; lo = mid + 1; } else { hi = mid - 1; }
      }
      if (best < 0) return null;
      var f = o.frames[best];
      return (t - f.t <= 1.5 * o.interval) ? f : null;
    };

    o.fit = function() {
      var w = target.clientWidth, h = target.clientHeight;
      if (!w || !h) return false;
      canvas.style.left = target.offsetLeft + 'px';
      canvas.style.top = target.offsetTop + 'px';
      canvas.style.width = w + 'px';
      canvas.style.height = h + 'px';
      var ratio = window.devicePixelRatio || 1;
      var pw = Math.round(w * ratio), ph = Math.round(h * ratio);
      if (canvas.width != pw || canvas.height != ph) { canvas.width = pw; canvas.height = ph; }
      return true;
    };

    o.clear = function() {
      canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
      o.lastDrawn = null;
    };

    o.draw = function(frame) {
      if (!o.visible || !o.fit()) return;
      var ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      if (!frame) { o.lastDrawn = null; return; }
      var W = canvas.width, H = canvas.height;
      var line = Math.max(2, Math.round(W / 480));
      var font = Math.max(12, Math.round(W / 60));
      ctx.lineWidth = line;
      ctx.font = 'bold ' + font + 'px sans-serif';
      ctx.textBaseline = 'top';
      for (var i = 0; i < frame.boxes.length; i++) {
        var b = frame.boxes[i];
        var x = b.box[0] * W, y = b.box[1] * H, w = (b.box[2] - b.box[0]) * W, h = (b.box[3] - b.box[1]) * H;
        var colour = COLOURS[b.label] || '#ffffff';
        ctx.strokeStyle = colour;
        ctx.strokeRect(x, y, w, h);
        var text = shown(b.label) + ' ' + Math.round(b.confidence * 100) + '%';
        var tw = ctx.measureText(text).width + 8;
        var ty = y - font - 6 >= 0 ? y - font - 6 : y;
        ctx.fillStyle = colour;
        ctx.fillRect(x, ty, tw, font + 6);
        ctx.fillStyle = '#000000';
        ctx.fillText(text, x + 4, ty + 3);
      }
      o.lastDrawn = frame;
    };

    o.drawAt = function(t) {
      o.draw(o.frameAt(t));
    };

    o.show = function(on) {
      o.visible = !!on;
      canvas.style.display = o.visible ? 'block' : 'none';
      if (!o.visible) o.clear();
    };

    o.remove = function() {
      if (canvas.parentNode) canvas.parentNode.removeChild(canvas);
    };

    o.show(o.visible);
    return o;
  }

  // ZoneMinder's MJPEG stream: multipart/x-mixed-replace, each part with Content-Type,
  // Content-Length and X-Timestamp headers (src/zm_monitorstream.cpp). onFrame(blob, stamp) for
  // each; resolves when the stream ends, rejects on a network error (an abort resolves).
  function readStream(url, onFrame, signal) {
    return fetch(url, {signal: signal, credentials: 'same-origin'}).then(function(response) {
      if (!response.ok || !response.body) throw new Error('stream: HTTP ' + response.status);
      var reader = response.body.getReader();
      var buf = new Uint8Array(0);
      var decoder = new TextDecoder('latin1');

      function append(chunk) {
        var n = new Uint8Array(buf.length + chunk.length);
        n.set(buf, 0);
        n.set(chunk, buf.length);
        buf = n;
      }

      function indexOf(seq, from) {
        outer: for (var i = from; i <= buf.length - seq.length; i++) {
          for (var j = 0; j < seq.length; j++) if (buf[i + j] != seq[j]) continue outer;
          return i;
        }
        return -1;
      }
      var CRLFCRLF = [13, 10, 13, 10];

      function pump() {
        return reader.read().then(function(r) {
          if (r.done) return;
          append(r.value);
          // Each part: headers, a blank line, then Content-Length bytes.
          for (;;) {
            var end = indexOf(CRLFCRLF, 0);
            if (end < 0) break;
            var head = decoder.decode(buf.subarray(0, end));
            var m = /Content-Length:\s*(\d+)/i.exec(head);
            if (!m) { buf = buf.subarray(end + 4); continue; }  // a boundary line on its own
            var length = parseInt(m[1], 10);
            if (buf.length < end + 4 + length) break;
            var stampMatch = /X-Timestamp:\s*([\d.]+)/i.exec(head);
            var typeMatch = /Content-Type:\s*([^\r\n]+)/i.exec(head);
            var body = buf.slice(end + 4, end + 4 + length);
            buf = buf.subarray(end + 4 + length);
            onFrame(new Blob([body], {type: typeMatch ? typeMatch[1].trim() : 'image/jpeg'}),
                    stampMatch ? parseFloat(stampMatch[1]) : null);
          }
          return pump();
        });
      }
      return pump();
    }).catch(function(e) {
      if (e && e.name == 'AbortError') return;
      throw e;
    });
  }

  return {overlay: overlay, readStream: readStream, shown: shown, COLOURS: COLOURS};
})();
