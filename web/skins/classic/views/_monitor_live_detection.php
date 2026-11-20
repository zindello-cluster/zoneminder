<?php
//
// zindello-cluster: the monitor page under live detection (cluster-docs
// docs/zoneminder-zones-for-live-detection.md, section 2, "The camera's settings"):
// - Analysis FPS sets live detection's rate for the camera (O553: "the monitor page's Analysis FPS
//   sets live detection's rate, and the Detection tab's Frames a second goes"); empty, 5 a second.
// - Alarm Frame Count sets how many samples in a row an object must be in an Active (or Exclusive)
//   zone before it raises or joins the alarm (O549, O550). Both stay editable whatever Motion
//   Detection says, and each says so beside it.
// - Analysis Image, Reference Image Blend, Alarm Reference Image Blend and Warmup Frames, which only
//   motion detection uses, are greyed out with a line saying live detection doesn't use them, and
//   kept as they are (O551, O557): read-only and out of reach rather than disabled, so a Save posts
//   them unchanged.
// Included by views/monitor.php at the end of the page, after the form.
//
?>
<script nonce="<?php echo $cspNonce ?>">
(function() {
  'use strict';
  function form() {
    return document.getElementById('contentForm') || document.querySelector('form[name="contentForm"]') ||
      (document.querySelector('[name="newMonitor[AnalysisFPSLimit]"]') || {}).form;
  }
  function note(el, text) {
    var li = el.closest('li') || el.parentNode;
    if (!li || li.querySelector('.liveDetectionNote')) return;
    var p = document.createElement('div');
    p.className = 'form-text text-muted liveDetectionNote';
    p.textContent = text;
    li.appendChild(p);
  }
  function apply() {
    var f = form();
    if (!f) return;
    ['newMonitor[AnalysisImage]', 'newMonitor[RefBlendPerc]', 'newMonitor[AlarmRefBlendPerc]', 'newMonitor[WarmupCount]'].forEach(function(name) {
      var el = f.elements[name];
      if (!el) return;
      el.classList.add('liveDetectionUnused');
      el.setAttribute('tabindex', '-1');
      el.setAttribute('aria-disabled', 'true');
      if (el.tagName == 'INPUT') el.readOnly = true;
      el.style.opacity = '0.5';
      el.style.pointerEvents = 'none';
      note(el, <?php echo json_encode(translate('Live detection doesn\'t use this; it\'s kept as it is for motion detection.')) ?>);
    });
    var fps = f.elements['newMonitor[AnalysisFPSLimit]'];
    if (fps) {
      fps.disabled = false;
      note(fps, <?php echo json_encode(translate('Live detection\'s rate for this camera: how many frames a second it looks at. Empty: 5.')) ?>);
    }
    var count = f.elements['newMonitor[AlarmFrameCount]'];
    if (count) {
      count.disabled = false;
      count.setAttribute('max', '16');
      note(count, <?php echo json_encode(translate('Live detection: how many samples in a row an object must be in an Active (or Exclusive) zone before it raises or joins the alarm, 1 to 16.')) ?>);
    }
  }
  if (document.readyState == 'loading') {
    document.addEventListener('DOMContentLoaded', apply);
  } else {
    apply();
  }
})();
</script>
