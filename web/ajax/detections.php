<?php
//
// zindello-cluster: live detection's boxes, for the event and watch pages
// (external-security.zindello.com.au/docs/live-detection.md, "The viewer").
//
// ?request=detections&what=event&eid=N    every stored frame of the event, and its exact start
// ?request=detections&what=live&mid=N&since=T   the camera's frames after T (seconds since the epoch)
//
// Behind ZoneMinder's own sign-in (index.php refuses any request without a user) and the same
// permission checks as the video it goes with: the event's canView, the camera's canView. The rows
// come from the `detector` database beside ZoneMinder's, written by the live sidecar; their times
// are UTC DATETIME(6), turned into seconds since the epoch by arithmetic, so no session time zone
// touches them. A frame with no row had no boxes.
//
ini_set('display_errors', '');

require_once('includes/Event.php');
require_once('includes/Monitor.php');

define('ZM_DETECTIONS_DB', 'detector');
define('ZM_DETECTIONS_MAX_FRAMES', 20000);

function detections_settings($monitor_id) {
  $row = dbFetchOne('SELECT per_second, boxes_default, live FROM `'.ZM_DETECTIONS_DB.'`.detector_settings WHERE monitor_id=?', false, array($monitor_id));
  return array(
    'interval'      => ($row and $row['per_second'] > 0) ? 1.0 / intval($row['per_second']) : 0.2,
    'boxes_default' => $row ? (bool)$row['boxes_default'] : true,
    'live'          => $row ? (bool)$row['live'] : false,
  );
}

// Rows as [{t: seconds since the epoch (float), boxes: [...]}], oldest first.
function detections_frames($sql, $params) {
  $frames = array();
  foreach (dbFetchAll($sql, false, $params) as $row) {
    $frames[] = array('t' => intval($row['us']) / 1000000.0, 'boxes' => json_decode($row['boxes']));
  }
  return $frames;
}

// A ZoneMinder local-time DATETIME (whole seconds, or with a fraction once the seed has widened
// Events.StartDateTime) as seconds since the epoch, to the microsecond, and as a UTC DATETIME(6).
function detections_local_time($s) {
  $tz = new DateTimeZone(date_default_timezone_get());
  $d = DateTime::createFromFormat(strpos($s, '.') === false ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u', $s, $tz);
  if (!$d) return null;
  $utc = clone $d;
  $utc->setTimezone(new DateTimeZone('UTC'));
  return array('epoch' => floatval($d->format('U.u')), 'utc' => $utc->format('Y-m-d H:i:s.u'));
}

$what = isset($_REQUEST['what']) ? $_REQUEST['what'] : '';

if ($what == 'event') {
  $eid = validInt($_REQUEST['eid']);
  $Event = new ZM\Event($eid);
  if (!$Event->Id() or !$Event->canView()) {
    ajaxError('Not found');
    return;
  }
  $start = detections_local_time($Event->StartDateTime());
  if (!$start) {
    ajaxError('The event has no start time');
    return;
  }
  $end = $Event->EndDateTime() ? detections_local_time($Event->EndDateTime()) : null;
  if ($end) {
    // Whole seconds in Events: take the second through.
    $end_utc = $end['utc'];
    $end_utc = (new DateTime($end_utc, new DateTimeZone('UTC')))->modify('+1 second')->format('Y-m-d H:i:s.u');
  } else {
    $end_utc = gmdate('Y-m-d H:i:s').'.999999';
  }
  $settings = detections_settings($Event->MonitorId());
  $frames = detections_frames(
    'SELECT TIMESTAMPDIFF(MICROSECOND, \'1970-01-01 00:00:00\', frame_time) AS us, boxes FROM `'.ZM_DETECTIONS_DB.'`.detector_frames '.
    'WHERE monitor_id=? AND frame_time >= ? AND frame_time < ? ORDER BY frame_time LIMIT '.ZM_DETECTIONS_MAX_FRAMES,
    array($Event->MonitorId(), $start['utc'], $end_utc));
  ajaxResponse(array(
    'video_start'   => $start['epoch'],
    'exact'         => strpos($Event->StartDateTime(), '.') !== false,
    'interval'      => $settings['interval'],
    'boxes_default' => $settings['boxes_default'],
    'frames'        => $frames,
  ));
  return;
}

if ($what == 'live') {
  $mid = validInt($_REQUEST['mid']);
  $Monitor = ZM\Monitor::find_one(array('Id' => $mid));
  if (!$Monitor or !$Monitor->canView()) {
    ajaxError('Not found');
    return;
  }
  $since = isset($_REQUEST['since']) ? floatval($_REQUEST['since']) : 0;
  if ($since <= 0) $since = microtime(true) - 5;  // the last few seconds, then only what's new
  $since_utc = gmdate('Y-m-d H:i:s', intval(floor($since))).sprintf('.%06d', intval(round(($since - floor($since)) * 1000000)));
  $settings = detections_settings($mid);
  $frames = detections_frames(
    'SELECT TIMESTAMPDIFF(MICROSECOND, \'1970-01-01 00:00:00\', frame_time) AS us, boxes FROM `'.ZM_DETECTIONS_DB.'`.detector_frames '.
    'WHERE monitor_id=? AND frame_time > ? ORDER BY frame_time LIMIT 200',
    array($mid, $since_utc));
  ajaxResponse(array(
    'interval'      => $settings['interval'],
    'boxes_default' => $settings['boxes_default'],
    'live'          => $settings['live'],
    'frames'        => $frames,
  ));
  return;
}

ajaxError('what: event or live');
