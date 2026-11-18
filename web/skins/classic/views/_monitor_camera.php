<?php
//
// zindello-cluster: the Camera tab on a camera's own page (views/monitor.php): the VIGI's own
// lighting, day/night, picture and on-screen text, read from the camera when the tab is opened and
// changed all at once on Save, then read back (cluster-docs docs/zoneminder-camera-tab.md; the
// owner, 18 November 2026: "I want to be able to tune it from a single UI.", and "1. B 2. A 3. B
// 4. A 5. A 6. A 7. Keep it — Snapshot is a good idea. Enabling/disabling the on screen text is a
// cool idea.", O508-O511). Beside them, ZoneMinder's own live view of the camera (no extra
// connection to it) and a Snapshot of it to compare with. The settings come and go through
// ajax/camerasettings.php; nothing here is part of the monitor's own form (no field has a name).
// Seen by whoever may view System settings, changed by whoever may edit them.
//
$camEdit = canEdit('System');
$camLive = $monitor->getStreamSrc(array('mode' => 'single', 'scale' => 50), '&');
?>
<li class="camera-tab" id="cameraTab" data-mid="<?php echo $monitor->Id() ?>" data-edit="<?php echo $camEdit ? '1' : '0' ?>"
    data-live="<?php echo htmlspecialchars($camLive) ?>" data-name="<?php echo validHtmlStr($monitor->Name()) ?>">
  <link rel="stylesheet" href="<?php echo cache_bust('skins/classic/css/base/camerasettings.css') ?>"/>
  <div class="cam-bar">
    <b>Camera</b>
    <span id="camInfo" class="cam-muted">Opening the tab reads the camera.</span>
    <span class="cam-fill"></span>
    <button type="button" id="camRead" class="btn btn-normal">Read again</button>
<?php if ($camEdit) { ?>
    <button type="button" id="camUndo" class="btn btn-normal" disabled="disabled">Undo changes</button>
    <button type="button" id="camSave" class="btn btn-primary" disabled="disabled">Save</button>
<?php } ?>
  </div>
  <div id="camBanner" class="cam-banner" style="display:none"></div>
  <div class="cam-cols">
    <div class="cam-col cam-settings" id="camSettings"></div>
    <div class="cam-col">
      <div class="cam-bar">
        <span class="cam-muted">Live, from ZoneMinder (its own stream: no extra connection to the camera)</span>
        <span class="cam-fill"></span>
        <button type="button" id="camSnapshot" class="btn btn-normal">Snapshot</button>
      </div>
      <div class="cam-pictures">
        <div class="cam-picture" id="camBefore" style="display:none">
          <div class="cam-muted" id="camBeforeLabel">Before</div>
          <canvas id="camBeforeCanvas"></canvas>
        </div>
        <div class="cam-picture">
          <div class="cam-muted" id="camNowLabel">Live</div>
          <img id="camLive" alt="<?php echo validHtmlStr($monitor->Name()) ?>"/>
        </div>
      </div>
      <h4>Set by camera setup, from ZoneMinder's values <span class="cam-tag">read only</span></h4>
      <div id="camSetup"></div>
    </div>
  </div>
  <script src="<?php echo cache_bust('skins/classic/js/camerasettings.js') ?>"></script>
</li>
