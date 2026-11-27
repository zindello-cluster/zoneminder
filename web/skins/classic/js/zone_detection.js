/*
 * zindello-cluster: the zone page's Live detection section (views/_zone_detection.php; cluster-docs
 * docs/zoneminder-zones-for-live-detection.md, O544-O570). Called by views/js/zone.js:
 *
 * ZMZoneDetection.applyZoneType(form), at the end of ZoneMinder's own applyZoneType (on the page's
 * start and on every change of Type):
 * - the ticks enabled for every type but Privacy (O554); the zone's own thresholds for Active,
 *   Inclusive and Exclusive only (O563, O568); what the ticks mean for the type shown;
 * - ZoneMinder's motion settings greyed out for every type (O551, O557, O558): Preset, Alarm
 *   Colour, Alarm Check Method, the pixel thresholds, the filter, the areas, the blobs and Overload
 *   Frame Ignore Count. Read-only and out of reach rather than disabled, so ZoneMinder's own
 *   enabling and disabling still decides what's posted, and a Save keeps them as they were.
 *   Extend Alarm Frame Count is left to ZoneMinder (on for a Preclusive zone, which live detection
 *   still counts).
 *
 * "Tick all · Untick all" (cluster-docs docs/zoneminder-zone-tick-all.md, O947): ticks or unticks every
 * box that can be ticked, More's included, and leaves the thresholds as they are; then the same as a
 * tick by hand: the red line for nothing ticked, and More's count, which follows every tick. Greyed
 * out where the ticks are (a Privacy zone; before the table exists). On an Inactive zone, Untick all
 * leaves nothing ticked, so Save is refused until a type is ticked, as below.
 *
 * ZMZoneDetection.errors(form), from ZoneMinder's own validateForm: an Inactive zone with nothing
 * ticked is refused (O548). ZMZoneDetection.motionUnused tells validateForm not to insist on the
 * motion settings.
 */
var ZMZoneDetection = (function() {
  'use strict';

  var MOTION = ['presetSelector', 'newAlarmRgbR', 'newAlarmRgbG', 'newAlarmRgbB', 'newZone[CheckMethod]',
    'newZone[MinPixelThreshold]', 'newZone[MaxPixelThreshold]', 'newZone[FilterX]', 'newZone[FilterY]',
    'newZone[MinAlarmPixels]', 'newZone[MaxAlarmPixels]', 'newZone[MinFilterPixels]', 'newZone[MaxFilterPixels]',
    'newZone[MinBlobPixels]', 'newZone[MaxBlobPixels]', 'newZone[MinBlobs]', 'newZone[MaxBlobs]',
    'newZone[OverloadFrames]'];
  var TICKS = {Active: true, Inclusive: true, Exclusive: true, Preclusive: true, Inactive: true, Privacy: false};
  var THRESHOLDS = {Active: true, Inclusive: true, Exclusive: true};
  var NOTHING = {
    Active: 'Nothing ticked: this zone raises no alarms.',
    Inclusive: 'Nothing ticked: this zone joins no alarms.',
    Exclusive: 'Nothing ticked: this zone raises no alarms.',
    Preclusive: 'Nothing ticked: this zone stops no alarms.',
    Inactive: 'Tick at least one type: an Inactive zone can\'t be saved with nothing ticked.'
  };

  function greyMotion(form) {
    MOTION.forEach(function(name) {
      var el = form.elements[name];
      if (!el) return;
      el.classList.add('zoneMotionUnused');
      el.setAttribute('tabindex', '-1');
      el.setAttribute('aria-disabled', 'true');
      el.setAttribute('title', 'Live detection doesn\'t use this.');
      if (el.tagName == 'INPUT') el.readOnly = true;
      el.style.opacity = '0.5';
      el.style.pointerEvents = 'none';
    });
  }

  function type(form) {
    return form.elements['newZone[Type]'].value;
  }

  function ticked() {
    return document.querySelectorAll('.zoneDetectionLabel:checked').length;
  }

  // Every box given, ticked (on) or not, but those that can't be changed (disabled); thresholds
  // aren't among them. Returns how many changed.
  function tickAll(boxes, on) {
    var changed = 0;
    Array.prototype.forEach.call(boxes, function(el) {
      if (el.disabled || el.checked == on) return;
      el.checked = on;
      changed++;
    });
    return changed;
  }

  // More's count, as the page draws it: " (63)", or nothing when none is ticked.
  function countText(n) {
    return n ? ' (' + n + ')' : '';
  }

  function showMoreCount() {
    var el = document.getElementById('zoneDetectionMoreCount');
    if (!el) return;
    el.textContent = countText(document.querySelectorAll('#zoneDetectionMore .zoneDetectionLabel:checked').length);
  }

  function changed(form) {
    showNothing(form);
    showMoreCount();
  }

  // The links: usable while the ticks are, greyed out and out of reach otherwise.
  function showTickAll(on) {
    document.querySelectorAll('.zoneDetectionTickAll').forEach(function(el) {
      el.setAttribute('aria-disabled', on ? 'false' : 'true');
      el.setAttribute('tabindex', on ? '0' : '-1');
      el.style.opacity = on ? '' : '0.5';
      el.style.pointerEvents = on ? '' : 'none';
    });
  }

  function watchTickAll(form) {
    document.querySelectorAll('.zoneDetectionTickAll').forEach(function(el) {
      if (el.dataset.watched) return;
      el.dataset.watched = '1';
      el.addEventListener('click', function(e) {
        e.preventDefault();
        if (el.getAttribute('aria-disabled') == 'true') return;
        tickAll(document.querySelectorAll('.zoneDetectionLabel'), el.getAttribute('data-tick') == '1');
        changed(form);
      });
    });
  }

  function showNothing(form) {
    var el = document.getElementById('zoneDetectionNothing');
    if (!el) return;
    var t = type(form);
    var off = document.querySelector('.zoneDetectionLabel.zoneDetectionOff');
    if (!off && TICKS[t] && !ticked()) {
      el.textContent = NOTHING[t];
      el.style.display = '';
    } else {
      el.style.display = 'none';
    }
  }

  function applyZoneType(form) {
    var t = type(form);
    greyMotion(form);
    document.querySelectorAll('.zoneDetectionLabel').forEach(function(el) {
      el.disabled = !TICKS[t] || el.classList.contains('zoneDetectionOff');
      if (!el.dataset.watched) {
        el.dataset.watched = '1';
        el.addEventListener('change', function() { changed(form); });
      }
    });
    document.querySelectorAll('.zoneDetectionThreshold').forEach(function(el) {
      el.disabled = !THRESHOLDS[t] || el.classList.contains('zoneDetectionOff');
    });
    document.querySelectorAll('.zoneDetectionMeaning').forEach(function(el) {
      el.style.display = el.getAttribute('data-zone-type') == t ? '' : 'none';
    });
    document.querySelectorAll('.zoneDetectionThresholdHelp').forEach(function(el) {
      el.style.display = THRESHOLDS[t] ? '' : 'none';
    });
    showTickAll(TICKS[t] && !document.querySelector('.zoneDetectionLabel.zoneDetectionOff'));
    watchTickAll(form);
    showNothing(form);
  }

  function errors(form) {
    var out = [];
    if (type(form) == 'Inactive' && !document.querySelector('.zoneDetectionLabel.zoneDetectionOff') &&
        document.querySelector('.zoneDetectionLabel') && !ticked()) {
      out.push('An Inactive zone needs at least one type ticked under Live detection: it ignores only the types ticked there.');
    }
    return out;
  }

  return {applyZoneType: applyZoneType, errors: errors, motionUnused: true, MOTION: MOTION,
    tickAll: tickAll, countText: countText};
})();

if (typeof module !== 'undefined' && module.exports) module.exports = ZMZoneDetection;
