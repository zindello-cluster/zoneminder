<?php
//
// zindello-cluster: a camera's timeline (cluster-docs docs/zoneminder-ui.md, section 3; the owner,
// 18 November 2026, O482, O484, O493, O494): the recording under a bar of the camera's 30 days,
// with spans of 30 days, 7 days, 24 hours and 1 hour; a position dragged anywhere, playing and
// pausing from it; changing span keeps it centred; the bar dragged sideways moves the window;
// live detection's alarms and the signed-in user's alerts marked; live at the right end.
//
//   ?view=cameratimeline&mid=N[&t=<seconds since the epoch>][&span=30d|7d|24h|1h]
//
// The data is ajax/cameratimeline.php; the page is views/js/cameratimeline.js; the boxes are
// js/detections.js, as on the event and camera pages.
//

require_once('includes/Monitor.php');

$mid = isset($_REQUEST['mid']) ? validInt($_REQUEST['mid']) : 0;
$monitor = $mid ? ZM\Monitor::find_one(array('Id' => $mid)) : null;
if (!$monitor or !$monitor->canView() or !canView('Events') or !canView('Stream')) {
  $view = 'error';
  return;
}
$timelineSpan = (isset($_REQUEST['span']) and in_array($_REQUEST['span'], array('30d', '7d', '24h', '1h'), true)) ? $_REQUEST['span'] : '24h';
$timelineAt = isset($_REQUEST['t']) ? floatval($_REQUEST['t']) : 0;
$timelineStream = $monitor->getStreamSrc(array('mode' => 'jpeg', 'scale' => 100, 'maxfps' => 15), '&');
$timelineDays = 30;

xhtmlHeaders(__FILE__, $monitor->Name().' · '.translate('Timeline'));
getBodyTopHTML();
echo getNavBarHTML();
?>
<div id="page" class="container-fluid">
  <div id="tlHeader">
    <h2><?php echo validHtmlStr($monitor->Name()) ?></h2>
    <span class="tlCrumb">· <?php echo translate('Timeline') ?></span>
    <span class="tlRight">
      <a id="tlCameraLink" class="btn btn-normal" href="?view=watch&amp;mid=<?php echo $mid ?>"><?php echo translate('Camera') ?></a>
      <button type="button" id="tlBoxes" class="btn btn-normal" title="<?php echo translate('Boxes') ?>"><span class="material-icons md-18">crop_free</span> <?php echo translate('Boxes') ?></button>
    </span>
  </div>
  <div id="tlPlayer">
    <div id="tlFrame">
      <video id="tlVideo" playsinline webkit-playsinline muted preload="auto"></video>
      <img id="tlLive" alt="" style="display:none"/>
    </div>
    <div id="tlBadge"></div>
    <div id="tlMessage"></div>
  </div>
  <div id="tlControls">
    <button type="button" id="tlPlay" class="btn btn-primary"><span class="material-icons md-18">play_arrow</span> <span id="tlPlayText"><?php echo translate('Play') ?></span></button>
    <span id="tlReadout"></span>
    <span id="tlSpans" class="btn-group" role="group">
      <button type="button" class="btn btn-normal" data-span="30d"><?php echo translate('30 days') ?></button>
      <button type="button" class="btn btn-normal" data-span="7d"><?php echo translate('7 days') ?></button>
      <button type="button" class="btn btn-normal" data-span="24h"><?php echo translate('24 hours') ?></button>
      <button type="button" class="btn btn-normal" data-span="1h"><?php echo translate('1 hour') ?></button>
    </span>
    <button type="button" id="tlLiveButton" class="btn btn-normal"><span class="material-icons md-18">sensors</span> <?php echo translate('Live') ?></button>
  </div>
  <div id="tlBarArea">
    <div id="tlLanes"><div><?php echo translate('Recording') ?></div><div><?php echo translate('Alarms') ?></div><div><?php echo translate('Alerts') ?></div></div>
    <canvas id="tlBar"></canvas>
  </div>
</div>
<?php
  echo output_link_if_exists(array('css/base/detections.css'));
  echo output_script_if_exists(array('js/detections.js'));
  xhtmlFooter();
?>
