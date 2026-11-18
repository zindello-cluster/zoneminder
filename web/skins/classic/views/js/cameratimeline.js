/*
 * zindello-cluster: a camera's timeline (views/cameratimeline.php; cluster-docs
 * docs/zoneminder-ui.md, section 3; the owner, 18 November 2026, O482, O484, O493, O494).
 *
 * The bar shows a window of the camera's recording: 30 days (everything kept, 30 days ago to
 * now), 7 days, 24 hours or 1 hour. The position is a line with a handle: drag it, or click the
 * bar, and the recording plays from there. Changing span keeps the position centred, and at an
 * edge the part past now or before the oldest is blank ("Stay centred"). Dragging the bar's lanes
 * sideways moves the window ("Drag the bar"). Its right end, now, is live.
 *
 * Recordings play one section (ZoneMinder event) after another, each its MP4, seeked to the
 * position; where an alarmed event overlaps the section before it, the later-started one plays;
 * a gap in the recording is skipped. The boxes are the drawing layer's (js/detections.js), from
 * each section's stored frames, exactly as on the event page; live is ZoneMinder's MJPEG stream,
 * each frame with its own boxes, a second behind, as on the camera page.
 *
 * TL holds the arithmetic with no page in it, so it can be tested on its own
 * (ai-live-detection/tests/timeline.test.js).
 */
var TL = (function() {
  'use strict';
  var SPANS = {'30d': 30 * 86400, '7d': 7 * 86400, '24h': 86400, '1h': 3600};
  // The window for a span around a position: 30 days is everything kept; the others are centred
  // on the position, whatever lies past now or before the oldest (blank).
  function windowFor(span, pos, now, days) {
    if (span == '30d') return [now - days * 86400, now];
    var half = SPANS[span] / 2;
    return [pos - half, pos + half];
  }
  // The window moved by a drag of dx pixels on a bar `width` pixels wide; 30 days doesn't move.
  function pan(win, span, dx, width, now, days) {
    if (span == '30d' || !width) return win;
    var len = win[1] - win[0];
    var shift = -dx * len / width;
    var lo = now - days * 86400 - len / 2, hi = now + len / 2;
    var start = Math.max(lo, Math.min(hi - len, win[0] + shift));
    return [start, start + len];
  }
  function toX(t, win, width) {
    return (t - win[0]) / (win[1] - win[0]) * width;
  }
  function toTime(x, win, width) {
    return win[0] + x / width * (win[1] - win[0]);
  }
  // The section playing at t: the latest-started one that covers it (an alarmed event's first
  // seconds overlap the section before it); null in a gap.
  function sectionAt(sections, t) {
    var best = null;
    for (var i = 0; i < sections.length; i++) {
      var s = sections[i];
      if (s.start <= t && t < s.end && (!best || s.start > best.start)) best = s;
    }
    return best;
  }
  // What plays after `section` ends: whatever covers its end, else the next to start.
  function sectionAfter(sections, section) {
    var t = section.end;
    var at = sectionAt(sections, t);
    if (at && at.id != section.id) return at;
    var next = null;
    for (var i = 0; i < sections.length; i++) {
      var s = sections[i];
      if (s.id != section.id && s.start >= t - 0.001 && (!next || s.start < next.start)) next = s;
    }
    return next;
  }
  // The first section starting after t (past a gap); null if none.
  function sectionFrom(sections, t) {
    var next = null;
    for (var i = 0; i < sections.length; i++) {
      var s = sections[i];
      if (s.start > t && (!next || s.start < next.start)) next = s;
    }
    return next;
  }
  // Tick spacing: the first step that leaves at least `minPx` between labels.
  var STEPS = [60, 300, 600, 1800, 3600, 2 * 3600, 3 * 3600, 6 * 3600, 12 * 3600, 86400, 2 * 86400, 3 * 86400, 7 * 86400];
  function tickStep(win, width, minPx) {
    var perPx = (win[1] - win[0]) / width;
    for (var i = 0; i < STEPS.length; i++) if (STEPS[i] / perPx >= minPx) return STEPS[i];
    return STEPS[STEPS.length - 1];
  }
  return {SPANS: SPANS, windowFor: windowFor, pan: pan, toX: toX, toTime: toTime, sectionAt: sectionAt,
    sectionAfter: sectionAfter, sectionFrom: sectionFrom, tickStep: tickStep};
})();
if (typeof module !== 'undefined' && module.exports) module.exports = TL;

if (typeof window !== 'undefined' && typeof $j !== 'undefined') {
  (function() {
    'use strict';
    var COL = {rec: '#aab4be', alarm: '#f0a500', alert: '#e74c3c', live: '#27ae60', pos: '#1e272e',
      lane: '#ffffff', sep: '#e4e7eb', axis: '#57606f', text: '#1e272e'};
    var LANE = 22, GAP = 4, TOP = 24, AXIS = 26;
    var st = {
      span: tlSpan, pos: 0, live: false, playing: false, win: null, now: Date.now() / 1000,
      bar: null, barTimer: null, sections: [], sectionsRange: null, section: null, loading: null,
      overlayVideo: null, overlayLive: null, boxes: null, frames: {}, liveAbort: null, livePoll: null,
      liveNewest: 0, liveTimers: [], liveShown: null, drag: null, raf: null
    };
    var video, img, canvas;

    function now() {
      return Date.now() / 1000;
    }
    function fmt(t, f) {
      return DateTime.fromSeconds(t).toFormat(f);
    }
    function message(text) {
      $j('#tlMessage').text(text || '').toggle(!!text);
    }
    function readout() {
      $j('#tlReadout').text(st.live ? 'Live · ' + fmt(now(), 'ccc d LLL HH:mm:ss') : fmt(st.pos, 'ccc d LLL yyyy HH:mm:ss'));
      $j('#tlBadge').text(st.live ? 'LIVE' : 'Recording').toggleClass('live', st.live);
      $j('#tlPlayText').text(st.playing ? 'Pause' : 'Play');
      $j('#tlPlay .material-icons').text(st.playing ? 'pause' : 'play_arrow');
      $j('#tlLiveButton').toggleClass('btn-success', st.live).toggleClass('btn-normal', !st.live);
      $j('#tlSpans button').each(function() {
        $j(this).toggleClass('btn-primary active', $j(this).data('span') == st.span).toggleClass('btn-normal', $j(this).data('span') != st.span);
      });
    }

    // ---- The bar
    function width() {
      return canvas.clientWidth;
    }
    function fetchBar() {
      clearTimeout(st.barTimer);
      st.barTimer = setTimeout(function() {
        var win = st.win.slice();
        var cols = Math.max(1, Math.min(4000, Math.round(width())));
        $j.getJSON(thisUrl + '?view=request&request=cameratimeline&what=bar&mid=' + tlMonitorId +
            '&from=' + win[0] + '&to=' + win[1] + '&cols=' + cols)
            .done(function(data) {
              if (data.result != 'Ok') return;
              st.bar = data;
              st.now = data.now;
              draw();
            });
      }, 150);
    }
    function setWindow(win) {
      st.win = win;
      draw();
      fetchBar();
    }
    function draw() {
      if (st.raf) return;
      st.raf = requestAnimationFrame(function() {
        st.raf = null;
        drawNow();
      });
    }
    function drawNow() {
      var W = width(), H = canvas.clientHeight, ratio = window.devicePixelRatio || 1;
      if (!W) return;
      if (canvas.width != Math.round(W * ratio) || canvas.height != Math.round(H * ratio)) {
        canvas.width = Math.round(W * ratio);
        canvas.height = Math.round(H * ratio);
      }
      var g = canvas.getContext('2d');
      g.setTransform(ratio, 0, 0, ratio, 0, 0);
      g.clearRect(0, 0, W, H);
      var win = st.win, X = function(t) { return TL.toX(t, win, W); };
      var lane = function(i) { return TOP + i * (LANE + GAP); };
      g.fillStyle = COL.lane;
      g.fillRect(0, TOP - 2, W, 3 * LANE + 2 * GAP + 4);
      var b = st.bar;
      if (b) {
        // The bar's columns, placed by time, so a drag moves them before the new ones arrive.
        var cw = (b.to - b.from) / b.cols;
        var amax = Math.max(1, quantile(b.alarms, 0.95)), emax = Math.max(1, quantile(b.alerts, 0.95));
        for (var c = 0; c < b.cols; c++) {
          var x0 = X(b.from + c * cw), x1 = X(b.from + (c + 1) * cw);
          if (x1 < 0 || x0 > W) continue;
          var w = Math.max(1, x1 - x0);
          if (b.rec.charAt(c) == '1') {
            g.fillStyle = COL.rec;
            g.fillRect(x0, lane(0), w, LANE);
          }
          if (!b.alarmList && b.alarms[c]) {
            var h = Math.max(3, Math.min(1, b.alarms[c] / amax) * LANE);
            g.fillStyle = COL.alarm;
            g.fillRect(x0, lane(1) + LANE - h, w, h);
          }
          if (!b.alertList && b.alerts[c]) {
            var h2 = Math.max(4, Math.min(1, b.alerts[c] / emax) * LANE);
            g.fillStyle = COL.alert;
            g.fillRect(x0, lane(2) + LANE - h2, w, h2);
          }
        }
        g.font = '11px sans-serif';
        g.textBaseline = 'middle';
        if (b.alarmList) blocks(g, b.alarmList, 1, COL.alarm, '#3d2c00', X, lane);
        if (b.alertList) blocks(g, b.alertList, 2, COL.alert, '#ffffff', X, lane);
      }
      g.fillStyle = COL.sep;
      for (var i = 1; i < 3; i++) g.fillRect(0, lane(i) - GAP / 2 - 0.5, W, 1);
      // The axis.
      var ay = lane(3) + 2;
      g.fillStyle = COL.axis;
      g.fillRect(0, ay, W, 1);
      g.font = '11px sans-serif';
      g.textAlign = 'center';
      g.textBaseline = 'alphabetic';
      var step = TL.tickStep(win, W, 90);
      var off = DateTime.fromSeconds(win[0]).offset * 60;
      for (var t = Math.ceil((win[0] + off) / step) * step - off; t <= win[1]; t += step) {
        var x = X(t);
        g.fillRect(Math.round(x), ay, 1, 7);
        var midnight = ((t + off) % 86400) === 0;
        var label = (step >= 86400 || midnight) ? fmt(t, 'ccc d LLL') : fmt(t, 'HH:mm');
        g.fillText(label, Math.min(W - 30, Math.max(30, x)), ay + 20);
      }
      // A part chosen for export (views/js/cameratimeline.js's export, when there is one).
      if (typeof tlDrawExtra == 'function') tlDrawExtra(g, X, TOP, lane(3), ay, W);
      // Now: the live end.
      var nx = X(st.now), lx = null;
      if (nx >= -2 && nx <= W + 2) {
        nx = Math.min(W - 2, Math.max(1, nx));
        g.fillStyle = COL.live;
        g.fillRect(nx - 1, TOP - 4, 3, 3 * LANE + 2 * GAP + 8);
        lx = Math.min(W - 40, nx + 4);
        g.fillRect(lx, 2, 38, 16);
        g.fillStyle = '#ffffff';
        g.font = 'bold 10px sans-serif';
        g.textAlign = 'center';
        g.fillText('LIVE', lx + 19, 14);
      }
      // The position: a solid line with a handle.
      var pos = st.live ? st.now : st.pos;
      var px = X(pos);
      if (px >= -1 && px <= W + 1 && !st.live) {
        g.fillStyle = COL.pos;
        g.fillRect(px - 1, TOP - 6, 2, 3 * LANE + 2 * GAP + 12);
        g.beginPath();
        g.arc(px, TOP - 8, 7, 0, 2 * Math.PI);
        g.fill();
        var text = fmt(pos, st.span == '1h' ? 'HH:mm:ss' : 'ccc d LLL HH:mm');
        g.font = '11px sans-serif';
        var tw = g.measureText(text).width + 12;
        var bx = Math.min(W - tw, Math.max(0, px + 12));
        // Clear of the LIVE tag: the other side of the handle.
        if (lx !== null && bx < lx + 40 && bx + tw > lx) bx = Math.max(0, px - 12 - tw);
        g.fillRect(bx, 0, tw, 16);
        g.fillStyle = '#ffffff';
        g.textAlign = 'left';
        g.fillText(text, bx + 6, 12);
      }
    }
    function blocks(g, list, i, fill, ink, X, lane) {
      for (var k = 0; k < list.length; k++) {
        var a = list[k], x = X(a.start), w = Math.max(2, X(Math.min(a.end, st.now)) - x);
        g.fillStyle = fill;
        g.fillRect(x, lane(i), w, LANE);
        var text = (a.labels || []).join(', ');
        if (text && w > g.measureText(text).width + 8) {
          g.fillStyle = ink;
          g.textAlign = 'left';
          g.fillText(text, x + 4, lane(i) + LANE / 2);
        }
      }
    }
    function quantile(values, q) {
      var v = values.filter(function(n) { return n > 0; }).sort(function(a, b) { return a - b; });
      return v.length ? v[Math.floor((v.length - 1) * q)] : 0;
    }

    // ---- Recordings
    function loadSections(t) {
      var r = st.sectionsRange;
      if (r && t >= r[0] + 600 && t <= r[1] - 600) return $j.Deferred().resolve().promise();
      var from = t - 2 * 3600, to = t + 2 * 3600;
      return $j.getJSON(thisUrl + '?view=request&request=cameratimeline&what=sections&mid=' + tlMonitorId +
          '&from=' + from + '&to=' + to).then(function(data) {
        if (data.result != 'Ok') return;
        st.sections = data.sections;
        st.sectionsRange = [from, to];
        st.now = data.now;
      });
    }
    function framesFor(section) {
      if (st.frames[section.id]) return st.frames[section.id];
      st.frames[section.id] = $j.getJSON(thisUrl + '?view=request&request=detections&what=event&eid=' + section.id)
          .then(function(data) {
            if (data.result != 'Ok') return null;
            if (st.boxes === null) {
              st.boxes = !!data.boxes_default;
              showBoxes();
            }
            return data;
          });
      return st.frames[section.id];
    }
    function playSection(section, offset) {
      st.section = section;
      var ready = framesFor(section);
      video.style.display = '';
      img.style.display = 'none';
      if (video.getAttribute('data-eid') != String(section.id)) {
        video.setAttribute('data-eid', section.id);
        video.src = thisUrl + '?view=view_video&eid=' + section.id;
        st.overlayVideo.setFrames([]);
      }
      var seek = function() {
        try { video.currentTime = Math.max(0, offset); } catch (e) { /* not seekable yet */ }
        if (st.playing) video.play().catch(function() {});
      };
      if (video.readyState >= 1) seek(); else $j(video).one('loadedmetadata', seek);
      ready.then(function(data) {
        if (!data || st.section !== section) return;
        st.overlayVideo.interval = data.interval;
        st.overlayVideo.setFrames(data.frames);
        section.videoStart = data.video_start;
        drawBoxes();
      });
    }
    // Play or show the recording at t: its section, or past a gap the next one.
    function goTo(t) {
      stopLive();
      st.live = false;
      st.pos = t;
      readout();
      draw();
      var want = t;
      loadSections(t).then(function() {
        if (st.pos !== want || st.live) return;
        var s = TL.sectionAt(st.sections, t);
        if (!s) {
          var next = TL.sectionFrom(st.sections, t);
          if (!next) {
            if (t > now() - 120) { goLive(); return; }
            message('No recording here.');
            video.pause();
            return;
          }
          message('');
          st.pos = next.start;
          s = next;
        } else {
          message('');
        }
        playSection(s, st.pos - s.start);
      });
    }
    function onVideoTime() {
      if (st.live || !st.section) return;
      var s = st.section;
      st.pos = s.start + video.currentTime;
      drawBoxes();
      readout();
      draw();
    }
    function drawBoxes() {
      var s = st.section;
      if (!s || st.live) return;
      var start = s.videoStart || s.start;
      st.overlayVideo.drawAt(start + video.currentTime);
    }
    function onEnded() {
      if (st.live || !st.section) return;
      var s = st.section;
      loadSections(s.end).then(function() {
        var next = TL.sectionAfter(st.sections, s);
        if (!next) {
          if (s.end > now() - 120) { goLive(); return; }
          st.playing = false;
          readout();
          return;
        }
        st.pos = Math.max(next.start, s.end);
        playSection(next, st.pos - next.start);
      });
    }
    function onVideoError() {
      if (st.live || !st.section) return;
      var s = st.section;
      // The section being recorded now may not play yet: go live; else carry on past it.
      if (s.end > now() - 15) { goLive(); return; }
      message('This part of the recording couldn\'t be played; carrying on after it.');
      onEnded();
    }

    // ---- Live
    function goLive() {
      st.live = true;
      st.playing = true;
      st.section = null;
      video.pause();
      video.style.display = 'none';
      img.style.display = '';
      message('');
      st.now = now();
      if (st.span != '30d') st.win = TL.windowFor(st.span, st.now, st.now, tlDays);
      readout();
      setWindow(st.win);
      startLive();
    }
    function startLive() {
      stopLive();
      st.liveAbort = new AbortController();
      st.liveNewest = 0;
      st.overlayLive.setFrames([]);
      pollLive();
      st.livePoll = setInterval(pollLive, 500);
      var url = tlStream + '&connkey=' + (100000 + Math.floor(Math.random() * 900000));
      ZMDetections.readStream(url, function(blob, stamp) {
        if (!st.live) return;
        if (st.boxes === false) {
          showLive(blob, stamp);
        } else {
          st.liveTimers.push(setTimeout(function() {
            st.liveTimers.shift();
            if (st.live) showLive(blob, stamp);
          }, 1000));
        }
      }, st.liveAbort.signal).then(null, function() {}).then(function() {
        if (st.live && st.playing) message('Live stopped; press Live to try again.');
      });
    }
    function showLive(blob, stamp) {
      var url = URL.createObjectURL(blob);
      var old = st.liveShown;
      img.onload = function() {
        if (old) URL.revokeObjectURL(old);
        if (stamp) st.overlayLive.drawAt(stamp);
      };
      img.src = url;
      st.liveShown = url;
      st.now = now();
      readout();
    }
    function pollLive() {
      $j.getJSON(thisUrl + '?view=request&request=detections&what=live&mid=' + tlMonitorId + '&since=' + st.liveNewest)
          .done(function(data) {
            if (data.result != 'Ok') return;
            if (st.boxes === null) {
              st.boxes = !!data.boxes_default;
              showBoxes();
            }
            st.overlayLive.interval = data.interval;
            st.overlayLive.addFrames(data.frames, 30);
            st.liveNewest = Math.max(st.liveNewest, st.overlayLive.newest());
          });
    }
    function stopLive() {
      if (st.liveAbort) st.liveAbort.abort();
      st.liveAbort = null;
      clearInterval(st.livePoll);
      st.livePoll = null;
      while (st.liveTimers.length) clearTimeout(st.liveTimers.shift());
    }

    // ---- Controls
    function showBoxes() {
      var on = st.boxes !== false;
      st.overlayVideo.show(on);
      st.overlayLive.show(on);
      $j('#tlBoxes').toggleClass('btn-primary', on).toggleClass('btn-normal', !on);
    }
    function playPause() {
      if (st.live) {
        // Live paused holds the picture; Play carries on live.
        st.playing = !st.playing;
        if (st.playing) startLive(); else stopLive();
        readout();
        return;
      }
      st.playing = !st.playing;
      if (st.playing) {
        if (!st.section) goTo(st.pos); else video.play().catch(function() {});
      } else {
        video.pause();
      }
      readout();
    }
    function setSpan(span) {
      st.span = span;
      var pos = st.live ? now() : st.pos;
      readout();
      setWindow(TL.windowFor(span, pos, now(), tlDays));
    }

    // Drag the position (its handle), drag the lanes to move the window, or click to go there.
    function pointer(e) {
      var r = canvas.getBoundingClientRect();
      return {x: e.clientX - r.left, y: e.clientY - r.top};
    }
    function onDown(e) {
      var p = pointer(e), W = width();
      var px = TL.toX(st.live ? st.now : st.pos, st.win, W);
      var axisTop = TOP + 3 * (LANE + GAP);
      if (typeof tlAxisDown == 'function' && p.y >= axisTop && tlAxisDown(e, p, st, W)) return;
      st.drag = {x: p.x, startX: p.x, win: st.win.slice(), what: (!st.live && Math.abs(p.x - px) <= 10) ? 'position' : 'pan', moved: false};
      canvas.setPointerCapture(e.pointerId);
      e.preventDefault();
    }
    function onMove(e) {
      if (typeof tlAxisMove == 'function' && tlAxisMove(e, pointer(e), st, width())) return;
      var d = st.drag;
      if (!d) return;
      var p = pointer(e);
      if (Math.abs(p.x - d.startX) > 3) d.moved = true;
      if (d.what == 'position') {
        st.pos = Math.min(now(), TL.toTime(p.x, st.win, width()));
        if (st.playing) video.pause();
        readout();
      } else {
        st.win = TL.pan(d.win, st.span, p.x - d.startX, width(), now(), tlDays);
      }
      draw();
    }
    function onUp(e) {
      if (typeof tlAxisUp == 'function' && tlAxisUp(e, pointer(e), st, width())) return;
      var d = st.drag;
      st.drag = null;
      if (!d) return;
      var p = pointer(e);
      if (d.what == 'position' || !d.moved) {
        var t = Math.min(now(), TL.toTime(p.x, st.win, width()));
        if (t > now() - 2) goLive(); else goTo(t);
      } else {
        fetchBar();
      }
    }

    $j(document).ready(function() {
      video = document.getElementById('tlVideo');
      img = document.getElementById('tlLive');
      canvas = document.getElementById('tlBar');
      st.overlayVideo = ZMDetections.overlay(video, {visible: true});
      st.overlayLive = ZMDetections.overlay(img, {visible: true});
      var cookie = getCookie(tlBoxesCookie);
      st.boxes = cookie ? (cookie == '1') : null;
      if (st.boxes !== null) showBoxes();
      $j('#tlBoxes').on('click', function() {
        st.boxes = st.boxes === false;
        setCookie(tlBoxesCookie, st.boxes ? '1' : '0');
        showBoxes();
        drawBoxes();
      });
      $j('#tlPlay').on('click', playPause);
      $j('#tlLiveButton').on('click', goLive);
      $j('#tlSpans button').on('click', function() { setSpan($j(this).data('span')); });
      if ('requestVideoFrameCallback' in video) {
        var frame = function() { onVideoTime(); video.requestVideoFrameCallback(frame); };
        video.requestVideoFrameCallback(frame);
      }
      video.addEventListener('timeupdate', onVideoTime);
      video.addEventListener('seeked', onVideoTime);
      video.addEventListener('ended', onEnded);
      video.addEventListener('error', onVideoError);
      canvas.addEventListener('pointerdown', onDown);
      canvas.addEventListener('pointermove', onMove);
      canvas.addEventListener('pointerup', onUp);
      canvas.addEventListener('pointercancel', function() { st.drag = null; });
      window.addEventListener('resize', function() { draw(); fetchBar(); });
      // The bar's newest columns, and now, every half minute.
      setInterval(function() { st.now = now(); fetchBar(); }, 30000);
      st.now = now();
      if (tlAt > 0 && tlAt < st.now - 2) {
        st.pos = tlAt;
        st.win = TL.windowFor(st.span, st.pos, st.now, tlDays);
        st.playing = true;
        readout();
        setWindow(st.win);
        goTo(tlAt);
      } else {
        st.pos = st.now;
        st.win = TL.windowFor(st.span, st.now, st.now, tlDays);
        goLive();
      }
    });
  })();
}
