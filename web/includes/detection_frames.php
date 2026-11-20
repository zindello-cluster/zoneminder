<?php
//
// zindello-cluster: what every reader of live detection's stored frames shares (ajax/detections.php,
// ajax/cameratimeline.php's boxed export; cluster-docs docs/zoneminder-zones-for-live-detection.md,
// O553, O564, O570):
//
// - The sampling interval: the camera's own Analysis FPS on its monitor page (Monitors.AnalysisFPSLimit)
//   is live detection's rate (O553: "the monitor page's Analysis FPS sets live detection's rate, and
//   the Detection tab's Frames a second goes"). Empty or 0 is taken as 5 a second, the rate the live
//   sidecar falls back to.
// - The boxes that are drawn: a box live detection stored marked `ignored` (it lay wholly inside an
//   Inactive zone that lists its type: stored for training later, never drawn, never alarming, O564)
//   is left out, and a frame with nothing left is left out with it, as if it had no row.
//

require_once(__DIR__.'/database.php');

define('ZM_DETECTION_FALLBACK_FPS', 5);

// Seconds between live detection's samples of camera $mid.
function detection_interval($mid) {
  $fps = floatval(dbFetchOne('SELECT AnalysisFPSLimit FROM Monitors WHERE Id=?', 'AnalysisFPSLimit', array(intval($mid))));
  return 1.0 / ($fps > 0 ? $fps : ZM_DETECTION_FALLBACK_FPS);
}

// A stored row's boxes (its JSON) as the list to draw: the boxes marked `ignored` left out. Objects
// (json_decode without assoc), so they go back out exactly as stored.
function detection_boxes_shown($json) {
  $boxes = json_decode($json);
  if (!is_array($boxes)) return array();
  $out = array();
  foreach ($boxes as $b) {
    if (is_object($b) and !isset($b->ignored)) $out[] = $b;
  }
  return $out;
}

// Rows (us: microseconds since the epoch, boxes: JSON) as [{t, boxes}], oldest first, each with
// only the boxes drawn; a row with none left out.
function detection_frames_shown($rows) {
  $frames = array();
  foreach ($rows as $row) {
    $boxes = detection_boxes_shown($row['boxes']);
    if (!count($boxes)) continue;
    $frames[] = array('t' => intval($row['us']) / 1000000.0, 'boxes' => $boxes);
  }
  return $frames;
}
