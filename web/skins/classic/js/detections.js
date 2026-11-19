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
 * With options.fade (the live pictures: the camera page's Live and the timeline's live end, not
 * recordings), a tracked object missing from the frame drawn but in a frame before it no more of
 * the camera's samples earlier than its hold keeps its last box, grey (FADED) and fading, outline
 * and tag alike, at 1 - n / (FADE + 1) for n samples gone; seen again, it's in the frame and drawn
 * in its colour at once. The owner, 19 November 2026: "if an object disappears maybe draw the box
 * grey and fading for up to 5 frames before dropping it out completely"; then, "For a car
 * travelling through the frame, we might only hold onto it for 1 frame (or not at all) and for
 * relatively stationary objects hold for up to 5 frames". Its hold is FADE (5) samples, less one
 * for each SPEED_STEP (0.05) of its own size it was moving a sample (holdOf). Samples are counted
 * by time at the camera's rate: the time between two frames over the sampling interval, rounded.
 * The same rule as the dash's cameras page and the wall's boxed stream.
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
  var FADE = 5;            // samples a missing tracked object's box stays at most, grey and fading
  var SPEED_STEP = 0.05;   // one sample less for each this much of its size it moved a sample
  var FADED = '#8e8e93';   // the grey it's drawn in

  function shown(label) {
    return ANIMALS[label] ? 'animal' : label;
  }

  // How many samples a tracked object missing after frames[j] is held, from its speed: how far its
  // box's centre moved from its earliest box in the frames up to FADE samples before frames[j] to
  // its box in frames[j], in its size there (the box's longer side), per sample. FADE less one for
  // each SPEED_STEP of that, rounded, from FADE (still) to 0 (0.25 a sample or faster). Seen in no
  // frame before frames[j] in that time, its speed isn't known: FADE.
  function holdOf(frames, j, track, interval) {
    function at(f) {
      for (var k = 0; k < f.boxes.length; k++) if (f.boxes[k].track === track) return f.boxes[k];
      return null;
    }
    var last = at(frames[j]), first = null, n0 = 0;
    for (var k = j - 1; k >= 0; k--) {
      var n = Math.round((frames[j].t - frames[k].t) / interval);
      if (n > FADE) break;
      var b = at(frames[k]);
      if (b) { first = b; n0 = n; }
    }
    if (!first) return FADE;
    var size = Math.max(last.box[2] - last.box[0], last.box[3] - last.box[1], 1e-6);
    var dx = (last.box[0] + last.box[2]) / 2 - (first.box[0] + first.box[2]) / 2;
    var dy = (last.box[1] + last.box[3]) / 2 - (first.box[1] + first.box[3]) / 2;
    var speed = Math.sqrt(dx * dx + dy * dy) / size / Math.max(1, n0);
    return Math.max(0, Math.min(FADE, Math.round(FADE - speed / SPEED_STEP)));
  }

  // The tracked objects in frames[0..i) but not in frames[i], each its last box, while that frame
  // is no more samples before frames[i] than its hold (holdOf): [{label, confidence, box, track,
  // fade}], fade its opacity. Kept on the frame: the frames before it are all in once it is (they
  // come in time order).
  function fadingFor(frames, i, interval) {
    var f = frames[i];
    if (f.fading) return f.fading;
    var seen = {}, out = [], j, k, b;
    for (k = 0; k < f.boxes.length; k++) if (f.boxes[k].track != null) seen[f.boxes[k].track] = 1;
    for (j = i - 1; j >= 0; j--) {
      var p = frames[j];
      var n = Math.max(1, Math.round((f.t - p.t) / interval));
      if (n > FADE) break;
      for (k = 0; k < p.boxes.length; k++) {
        b = p.boxes[k];
        if (b.track == null || seen[b.track]) continue;
        seen[b.track] = 1;
        if (n > holdOf(frames, j, b.track, interval)) continue;
        out.push({label: b.label, confidence: b.confidence, box: b.box, track: b.track, fade: 1 - n / (FADE + 1)});
      }
    }
    f.fading = out;
    return out;
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
      fade: !!options.fade,
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

    // The newest frame at or before t, if within 1.5 intervals; else null. With fade, the frame
    // carries its fading boxes (fadingFor).
    o.frameAt = function(t) {
      var lo = 0, hi = o.frames.length - 1, best = -1;
      while (lo <= hi) {
        var mid = (lo + hi) >> 1;
        if (o.frames[mid].t <= t) { best = mid; lo = mid + 1; } else { hi = mid - 1; }
      }
      if (best < 0) return null;
      var f = o.frames[best];
      if (t - f.t > 1.5 * o.interval) return null;
      if (o.fade) fadingFor(o.frames, best, o.interval);
      return f;
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
      // The fading boxes first, under the others.
      var fading = (o.fade && frame.fading) || [];
      var boxes = fading.concat(frame.boxes);
      for (var i = 0; i < boxes.length; i++) {
        var b = boxes[i];
        var x = b.box[0] * W, y = b.box[1] * H, w = (b.box[2] - b.box[0]) * W, h = (b.box[3] - b.box[1]) * H;
        var colour = i < fading.length ? FADED : (COLOURS[b.label] || '#ffffff');
        ctx.globalAlpha = i < fading.length ? b.fade : 1;
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
      ctx.globalAlpha = 1;
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

  return {overlay: overlay, readStream: readStream, shown: shown, fadingFor: fadingFor, holdOf: holdOf,
          COLOURS: COLOURS, FADE: FADE, SPEED_STEP: SPEED_STEP, FADED: FADED};
})();

if (typeof module !== 'undefined' && module.exports) module.exports = ZMDetections;
