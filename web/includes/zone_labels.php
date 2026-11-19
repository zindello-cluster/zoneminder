<?php
//
// zindello-cluster: the types live detection ignores in an area (cluster-docs
// docs/zoneminder-detection-exclusions.md; the owner, 19 November 2026: "map OUT this area where the
// decoative bee on the wall thinks it's a human", O523-O535; external-security.zindello.com.au
// docs/live-detection.md, "Areas it ignores"). An area is an ordinary ZoneMinder zone of type
// Inactive; the types it ignores are ticked in the zone page's Live detection section
// (views/_zone_detection.php) and saved by the page's own Save (actions/zone.php), into the
// `detector` database beside ZoneMinder's, on ZoneMinder's own connection:
//
//   detector_zone_labels (zone_id, labels)   one row per zone with types ticked; no row: nothing
//
// The chart's seed makes the table. The live sidecar reads the rows of Inactive zones only, and the
// seed removes a row whose zone has been deleted. Every query has bound parameters; the database's
// name is fixed here, never taken from a request.
//

require_once(__DIR__.'/database.php');
require_once(__DIR__.'/detection_labels.php');

if (!defined('ZM_ZONE_LABELS_DB')) define('ZM_ZONE_LABELS_DB', 'detector');

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

// A zone's ticked types, COCO names; none for a new zone or one with no row.
function zone_labels_of($zid) {
  $zid = intval($zid);
  if ($zid <= 0 or !zone_labels_ready()) return array();
  $row = dbFetchOne('SELECT labels FROM '.zone_labels_table().' WHERE zone_id=?', false, array($zid));
  if (!$row) return array();
  $labels = json_decode($row['labels'], true);
  return is_array($labels) ? array_values(array_filter($labels, 'is_string')) : array();
}

// The zone page's Save: the ticks posted for zone $zid of camera $mid. Only for a zone of that
// camera, only when the zone is Inactive (the ticks are only enabled then, so for any other type
// nothing is posted and the zone keeps its ticks, greyed out and ignored), and only when the
// section was on the page. Nothing ticked removes the row. Never touches ZoneMinder's Zones.
// Returns true if the ticks were saved.
function zone_labels_save($mid, $zid, $request) {
  $mid = intval($mid);
  $zid = intval($zid);
  if ($mid <= 0 or $zid <= 0) return false;
  $section = (isset($request['liveDetection']) and is_array($request['liveDetection'])) ? $request['liveDetection'] : null;
  if (!$section or empty($section['shown'])) return false;
  if (!zone_labels_ready()) return false;
  $zone = dbFetchOne('SELECT Id, Type FROM Zones WHERE MonitorId=? AND Id=?', false, array($mid, $zid));
  if (!$zone or $zone['Type'] != 'Inactive') return false;
  $labels = detection_labels_from_ticks(isset($section['labels']) ? $section['labels'] : array());
  if (count($labels)) {
    dbQuery('INSERT INTO '.zone_labels_table().' (zone_id, labels) VALUES (?, ?) '.
      'ON DUPLICATE KEY UPDATE labels=VALUES(labels)', array($zid, json_encode($labels)));
  } else {
    dbQuery('DELETE FROM '.zone_labels_table().' WHERE zone_id=?', array($zid));
  }
  return true;
}
