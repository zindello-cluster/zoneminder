// zindello-cluster: the Camera tab on a camera's own page (views/_monitor_camera.php,
// ajax/camerasettings.php; cluster-docs docs/zoneminder-camera-tab.md, O508-O511). Reads the
// camera when the tab is first shown (one login), marks what's changed, sends it all on Save (one
// login, read back), never retries a refusal; keeps ZoneMinder's live picture of the camera beside
// it, and a Snapshot of it to compare with.
(function() {
  'use strict';
  const tab = document.getElementById('cameraTab');
  if (!tab) return;
  const mid = tab.dataset.mid;
  const canEdit = tab.dataset.edit === '1';
  const name = tab.dataset.name;
  const el = (id) => document.getElementById(id);
  let read = null; // the fields as last read from the camera
  const changes = {}; // id -> value, not yet saved
  let busy = false;

  function text(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function banner(kind, html) {
    const b = el('camBanner');
    if (!kind) {
      b.style.display = 'none';
      return;
    }
    b.className = 'cam-banner cam-' + kind;
    b.innerHTML = html;
    b.style.display = '';
  }

  function time(t) {
    return new Date(t * 1000).toLocaleTimeString();
  }

  function modeChanged() {
    return Object.prototype.hasOwnProperty.call(changes, 'night_vision_mode');
  }

  function control(f) {
    const value = Object.prototype.hasOwnProperty.call(changes, f.id) ? changes[f.id] : f.value;
    const off = !canEdit || !f.editable || busy || (f.modeSpecific && modeChanged());
    const dis = off ? ' disabled="disabled"' : '';
    const mark = Object.prototype.hasOwnProperty.call(changes, f.id) ? ' cam-changed' : '';
    if (f.type === 'select') {
      let h = '<select class="form-control-sm' + mark + '" data-id="' + f.id + '"' + dis + '>';
      for (const k of Object.keys(f.options)) {
        h += '<option value="' + text(k) + '"' + (k === value ? ' selected="selected"' : '') + '>' + text(f.options[k]) + '</option>';
      }
      return h + '</select>';
    }
    if (f.type === 'level') {
      return '<input type="range" class="' + mark + '" data-id="' + f.id + '" min="' + f.min + '" max="' + f.max + '" value="' + text(value) + '"' + dis +
        '/> <span class="cam-level" id="camLevel_' + f.id + '">' + text(value) + '</span>';
    }
    // a switch: on or off
    return '<label class="cam-switch' + mark + '"><input type="checkbox" data-id="' + f.id + '"' + (value === 'on' ? ' checked="checked"' : '') + dis + '/> ' +
      text(f.label) + (f.text ? ' <span class="cam-muted">' + text(f.text) + '</span>' : '') + '</label>';
  }

  function render() {
    const box = el('camSettings');
    if (!read) {
      box.innerHTML = '';
      return;
    }
    let h = '';
    let group = null;
    for (const f of read.fields) {
      if (f.group !== group) {
        if (group === 'On-screen text') h += '</div>';
        group = f.group;
        h += '<h4>' + text(group) + '</h4>';
        if (group === 'On-screen text') h += '<div class="cam-switches">';
      }
      if (f.type === 'switch') {
        h += control(f);
      } else {
        h += '<div class="cam-row"><div class="cam-k">' + text(f.label) + '</div><div class="cam-v">' + control(f) + '</div></div>';
      }
    }
    if (group === 'On-screen text') h += '</div>';
    if (modeChanged()) h += '<p class="cam-muted">The settings of the new Lighting Mode can be changed once it\'s saved.</p>';
    if (!read.editable) {
      h += '<p class="cam-muted">Day/Night Switch is on Auto: the camera keeps separate day and night settings, shown here read only. ' +
        'Set it to Unified to change them here.</p>';
    }
    box.innerHTML = h;
    let s = '';
    for (const k of Object.keys(read.setup)) {
      s += '<div class="cam-row"><div class="cam-k">' + text(k) + '</div><div class="cam-v">' + text(read.setup[k]) + '</div></div>';
    }
    el('camSetup').innerHTML = s;
    const n = Object.keys(changes).length;
    if (canEdit) {
      el('camSave').disabled = busy || n === 0;
      el('camUndo').disabled = busy || n === 0;
    }
    el('camRead').disabled = busy;
  }

  function changed(ev) {
    const t = ev.target;
    const id = t.dataset.id;
    if (!id) return;
    const f = read.fields.find((x) => x.id === id);
    const v = t.type === 'checkbox' ? (t.checked ? 'on' : 'off') : t.value;
    if (t.type === 'range') el('camLevel_' + id).textContent = v;
    if (v === f.value) {
      delete changes[id];
    } else {
      changes[id] = v;
    }
    if (ev.type === 'change') {
      render();
      const n = Object.keys(changes).length;
      if (n) banner('pending', 'Not saved yet: ' + n + ' change' + (n > 1 ? 's' : '') + '. Save sends them to the camera, then reads them back.');
      else banner(null);
    }
  }

  function answer(data, savedWords) {
    if (data.refused) {
      read = null;
      banner('error', '<b>' + text(data.refused) + '</b> ' + time(data.at) + '. Read again tries once more.');
      el('camInfo').textContent = 'Not read';
    } else if (data.failed) {
      banner('error', text(data.failed) + ' ' + time(data.at) + '. Nothing was changed by this page.');
      if (!read) el('camInfo').textContent = 'Not read';
    } else if (data.result === 'Ok' && data.camera) {
      read = data.camera;
      for (const k of Object.keys(changes)) delete changes[k];
      const c = read.camera;
      el('camInfo').textContent = c.model + ' · firmware ' + c.firmware + ' · read from the camera at ' + time(data.at);
      if (savedWords !== undefined) {
        if (data.camera.changedWords) {
          let msg = 'Saved to the ' + text(name) + ' and read back at ' + time(data.at) + ': ' + text(data.camera.changedWords) + '.';
          if (data.camera.notTaken && data.camera.notTaken.length) {
            msg += ' <b>Not taken by the camera:</b> ' + text(data.camera.notTaken.join(', ')) + ' (shown as it has them).';
            banner('error', msg);
          } else {
            banner('ok', msg);
          }
        } else {
          banner('ok', 'Nothing to save: the camera already had these. Read back at ' + time(data.at) + '.');
        }
      } else {
        banner(null);
      }
    } else {
      banner('error', text(data.message || 'No answer from ZoneMinder'));
    }
    busy = false;
    render();
  }

  function readCamera() {
    if (busy) return;
    busy = true;
    banner('pending', 'Reading the camera…');
    render();
    $j.getJSON(thisUrl + '?view=request&request=camerasettings&action=read&mid=' + mid)
        .done((d) => answer(d))
        .fail(() => answer({message: 'ZoneMinder didn\'t answer'}));
  }

  function save() {
    if (busy || !Object.keys(changes).length) return;
    busy = true;
    banner('pending', 'Saving to the camera…');
    render();
    const body = {view: 'request', request: 'camerasettings', action: 'save', mid: mid};
    for (const k of Object.keys(changes)) body['changes[' + k + ']'] = changes[k];
    if (typeof csrfMagicName !== 'undefined') body[csrfMagicName] = csrfMagicToken;
    $j.post(thisUrl, body, null, 'json')
        .done((d) => answer(d, true))
        .fail(() => answer({message: 'ZoneMinder didn\'t answer'}));
  }

  function undo() {
    for (const k of Object.keys(changes)) delete changes[k];
    banner(null);
    render();
  }

  // ZoneMinder's own picture of the camera, a frame a second while the tab is shown.
  const live = el('camLive');
  let liveTimer = null;
  function nextFrame() {
    liveTimer = null;
    if (!tab.offsetParent) return; // the tab isn't shown
    live.src = tab.dataset.live + '&_=' + Date.now();
  }
  live.addEventListener('load', () => {
    el('camNowLabel').textContent = 'Live';
    if (!liveTimer) liveTimer = setTimeout(nextFrame, 1000);
  });
  live.addEventListener('error', () => {
    if (!liveTimer) liveTimer = setTimeout(nextFrame, 3000);
  });

  function snapshot() {
    if (!live.naturalWidth) return;
    const c = el('camBeforeCanvas');
    c.width = live.naturalWidth;
    c.height = live.naturalHeight;
    c.getContext('2d').drawImage(live, 0, 0);
    el('camBeforeLabel').textContent = 'Before · snapshot ' + new Date().toLocaleTimeString();
    el('camBefore').style.display = '';
  }

  let opened = false;
  function shown() {
    if (!liveTimer) nextFrame();
    if (opened) return;
    opened = true;
    readCamera();
  }

  el('camSettings').addEventListener('input', changed);
  el('camSettings').addEventListener('change', changed);
  el('camRead').addEventListener('click', readCamera);
  el('camSnapshot').addEventListener('click', snapshot);
  if (canEdit) {
    el('camSave').addEventListener('click', save);
    el('camUndo').addEventListener('click', undo);
  }
  $j('#camera-tab').on('shown.bs.tab', shown);
  if (tab.offsetParent) shown(); // opened straight on this tab (?tab=camera)
})();
