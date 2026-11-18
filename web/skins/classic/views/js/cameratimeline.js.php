<?php
  // zindello-cluster: a camera's timeline (views/cameratimeline.php).
  global $mid;
  global $monitor;
  global $timelineSpan;
  global $timelineAt;
  global $timelineStream;
  global $timelineDays;
?>
var tlMonitorId = <?php echo intval($mid) ?>;
var tlSpan = '<?php echo validJsStr($timelineSpan) ?>';
var tlAt = <?php echo json_encode(floatval($timelineAt)) ?>;
var tlStream = '<?php echo validJsStr($timelineStream) ?>';
var tlDays = <?php echo intval($timelineDays) ?>;
var tlBoxesCookie = 'zmWatchShowBoxes' + tlMonitorId;
