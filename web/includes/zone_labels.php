<?php
//
// zindello-cluster: live detection's types per zone (cluster-docs docs/zoneminder-zones-for-live-detection.md,
// O544-O570, after docs/zoneminder-detection-exclusions.md, O523-O535; external-security.zindello.com.au
// docs/live-detection.md). Each zone's types are ticked in the zone page's Live detection section
// (views/_zone_detection.php) and saved by the page's own Save (actions/zone.php), into the
// `detector` database beside ZoneMinder's, on ZoneMinder's own connection:
//
//   detector_zone_labels (zone_id, labels, thresholds)
//
// What the ticks mean depends on the zone's type (O554): on an Active, Inclusive, Exclusive or
// Preclusive zone "only these types" (the types that raise, join or hold an alarm there, or that set
// a Preclusive zone off); on an Inactive zone "ignore these" (a box of a ticked type wholly inside it
// is stored but never drawn and never alarms, O564). A Privacy zone has none.
//
// The rows (the live sidecar reads them the same way):
//   no row     an Active, Inclusive, Exclusive or Preclusive zone: every type (the default, O555);
//              an Inactive zone: nothing (as built)
//   labels []  nothing ticked: a zone that alarms raises nothing; an Inactive zone ignores nothing
//              (the page refuses to save an Inactive zone so, O548)
//   thresholds {"person": 0.75}: the zone's own threshold per type, used where it's higher than the
//              camera's, for raising and joining an alarm there and for the samples in a row
//              (O563, O568); Active, Inclusive and Exclusive zones only. NULL: the camera's
//
// A new zone of any type starts with every type ticked (O555, O560). The chart's seed makes the
// table and its `thresholds` column, ticks every existing zone that alarms for every type once, and
// removes a row whose zone has been deleted. Every query has bound parameters; the database's name
// is fixed here, never taken from a request.
//

require_once(__DIR__.'/database.php');
require_once(__DIR__.'/detection_labels.php');

if (!defined('ZM_ZONE_LABELS_DB')) define('ZM_ZONE_LABELS_DB', 'detector');
define('ZM_ZONE_THRESHOLD_MIN', 0.30);
define('ZM_ZONE_THRESHOLD_MAX', 0.95);

function zone_labels_table() {
  return '`'.ZM_ZONE_LABELS_DB.'`.`detector_zone_labels`';
}

// The table is there (the chart's seed makes it at ZoneMinder's start). Without it the section is
// shown greyed out and nothing is saved.
function zone_labels_ready() {
  $n = dbFetchOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=? '.
    "AND table_name='detector_zone_labels'", 'n', array(ZM_ZONE_LABELS_DB));
  return intval($n) == 1;
}

// The table has its `thresholds` column (added by the seed with this change). Without it, a zone's
// own thresholds are shown greyed out and not saved; the ticks still are.
function zone_labels_has_thresholds() {
  $n = dbFetchOne('SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema=? '.
    "AND table_name='detector_zone_labels' AND column_name='thresholds'", 'n', array(ZM_ZONE_LABELS_DB));
  return intval($n) == 1;
}

// The types whose ticks mean something on a zone of this type, and those a zone can set its own
// threshold for.
function zone_labels_type_has_ticks($type) {
  return in_array($type, array('Active', 'Inclusive', 'Exclusive', 'Preclusive', 'Inactive'), true);
}
function zone_labels_type_has_thresholds($type) {
  return in_array($type, array('Active', 'Inclusive', 'Exclusive'), true);
}

// A zone's row, or null: {labels: [COCO names], thresholds: {name: 0.75}}.
function zone_labels_row($zid) {
  $zid = intval($zid);
  if ($zid <= 0 or !zone_labels_ready()) return null;
  $cols = zone_labels_has_thresholds() ? 'labels, thresholds' : 'labels';
  $row = dbFetchOne('SELECT '.$cols.' FROM '.zone_labels_table().' WHERE zone_id=?', false, array($zid));
  if (!$row) return null;
  $labels = json_decode($row['labels'], true);
  $thresholds = isset($row['thresholds']) ? json_decode($row['thresholds'], true) : null;
  $out = array();
  foreach (is_array($thresholds) ? $thresholds : array() as $l => $v) {
    if (is_string($l) and is_numeric($v)) $out[$l] = floatval($v);
  }
  return array(
    'labels' => is_array($labels) ? array_values(array_filter($labels, 'is_string')) : array(),
    'thresholds' => $out,
  );
}

// The types shown ticked for zone $zid of type $type: a new zone, every type; a zone without a row,
// every type unless it's Inactive (nothing); otherwise its row's.
function zone_labels_shown($zid, $type) {
  $all = detection_labels_from_ticks(detection_labels_all());
  if (intval($zid) <= 0) return $all;
  $row = zone_labels_row($zid);
  if ($row) return $row['labels'];
  return $type == 'Inactive' ? array() : $all;
}

// A posted threshold as stored: two places, on the 0.05 steps, within 0.30-0.95; null if none was
// given or it isn't a number.
function zone_labels_threshold($v) {
  if (!is_string($v) and !is_numeric($v)) return null;
  $v = trim(strval($v));
  if ($v === '' or !is_numeric($v)) return null;
  $v = round(floatval($v) * 20) / 20;
  return round(max(ZM_ZONE_THRESHOLD_MIN, min(ZM_ZONE_THRESHOLD_MAX, $v)), 2);
}

// The section as posted, if it was on the page; null otherwise.
function zone_labels_section($request) {
  $section = (isset($request['liveDetection']) and is_array($request['liveDetection'])) ? $request['liveDetection'] : null;
  return ($section and !empty($section['shown'])) ? $section : null;
}

// Before ZoneMinder saves anything: why this Save must be refused, or null. An Inactive zone with no
// type ticked (O548: "An inactive zone should not be able to be saved with nothing ticked").
function zone_labels_refusal($request) {
  $section = zone_labels_section($request);
  if (!$section or !zone_labels_ready()) return null;
  $type = (isset($request['newZone']['Type']) and is_string($request['newZone']['Type'])) ? $request['newZone']['Type'] : '';
  if ($type != 'Inactive') return null;
  if (count(detection_labels_from_ticks(isset($section['labels']) ? $section['labels'] : array()))) return null;
  return 'Not saved: an Inactive zone needs at least one type ticked under Live detection. It ignores only the types ticked there.';
}

// The zone page's Save: the ticks (and, for a zone that alarms, its own thresholds) posted for zone
// $zid of camera $mid, once ZoneMinder has saved the zone. Only for a zone of that camera, only for
// a type that has ticks, and only when the section was on the page. Nothing ticked is stored as [],
// so a zone that alarms then raises nothing (no row would read as every type). A zone's thresholds
// are kept only for its ticked types; for a Preclusive or Inactive zone they're left as they are
// (not used, not posted). Never touches ZoneMinder's Zones. Returns true if the ticks were saved.
function zone_labels_save($mid, $zid, $request) {
  $mid = intval($mid);
  $zid = intval($zid);
  if ($mid <= 0 or $zid <= 0) return false;
  $section = zone_labels_section($request);
  if (!$section or !zone_labels_ready()) return false;
  $zone = dbFetchOne('SELECT Id, Type FROM Zones WHERE MonitorId=? AND Id=?', false, array($mid, $zid));
  if (!$zone or !zone_labels_type_has_ticks($zone['Type'])) return false;
  $labels = detection_labels_from_ticks(isset($section['labels']) ? $section['labels'] : array());
  if ($zone['Type'] == 'Inactive' and !count($labels)) return false;  // refused before the save
  if (zone_labels_type_has_thresholds($zone['Type']) and zone_labels_has_thresholds()) {
    $posted = (isset($section['thresholds']) and is_array($section['thresholds'])) ? $section['thresholds'] : array();
    $thresholds = array();
    foreach ($posted as $tick => $v) {
      $v = zone_labels_threshold($v);
      if ($v === null or !is_string($tick)) continue;
      foreach (detection_labels_from_ticks(array($tick)) as $l) {
        if (in_array($l, $labels, true)) $thresholds[$l] = $v;
      }
    }
    ksort($thresholds);
    dbQuery('INSERT INTO '.zone_labels_table().' (zone_id, labels, thresholds) VALUES (?, ?, ?) '.
      'ON DUPLICATE KEY UPDATE labels=VALUES(labels), thresholds=VALUES(thresholds)',
      array($zid, json_encode($labels), count($thresholds) ? json_encode($thresholds) : null));
  } else {
    dbQuery('INSERT INTO '.zone_labels_table().' (zone_id, labels) VALUES (?, ?) '.
      'ON DUPLICATE KEY UPDATE labels=VALUES(labels)', array($zid, json_encode($labels)));
  }
  return true;
}
