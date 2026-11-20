<?php
//
// zindello-cluster: the zone page's Live detection section (cluster-docs
// docs/zoneminder-zones-for-live-detection.md, O544-O570, after docs/zoneminder-detection-exclusions.md,
// O523-O535): the types live detection acts on in this zone, ticked from the Detection tab's Looks
// for list and saved by the zone page's own Save (actions/zone.php; includes/zone_labels.php).
//
// - The ticks are on every zone type but Privacy (O554): on a zone that alarms, and on a Preclusive
//   zone, "only these types"; on an Inactive zone, "ignore these". A new zone of any type starts
//   with every type ticked (O555, O560); an existing zone without a row shows every type, or
//   nothing if it's Inactive, as the live sidecar reads it. An Inactive zone can't be saved with
//   nothing ticked (O548).
// - Beside each tick, the zone's own threshold for that type, blank for the camera's (O563, O568):
//   Active, Inclusive and Exclusive zones only.
// - ZoneMinder's motion settings (Preset, Alarm Colour, Alarm Check Method and the pixel settings)
//   are greyed out for every type and no longer required, kept in the row as they are (O551, O557,
//   O558); Extend Alarm Frames stays, for a Preclusive zone (js/zone_detection.js).
//
// Included by views/zone.php inside its form, where Monitors: Edit for this camera has already been
// checked.
//

require_once('includes/zone_labels.php');

$zoneLabelsReady = zone_labels_ready();
$zoneThresholdsReady = $zoneLabelsReady && zone_labels_has_thresholds();
$zoneType = $zone['Type'];
$zoneRow = zone_labels_row($zone['Id']);
$zoneTicked = array_fill_keys(detection_labels_ticked(zone_labels_shown($zone['Id'], $zoneType)), true);
$zoneOwn = $zoneRow ? $zoneRow['thresholds'] : array();
$zoneMoreTicked = 0;
foreach (array_merge(detection_labels_animals(), detection_labels_more()) as $l) {
  if (isset($zoneTicked[$l])) $zoneMoreTicked++;
}

// The camera's own threshold per type (the Detection tab), shown where the zone sets none.
$zoneCameraThresholds = array();
$zoneHasCameraThresholds = intval(dbFetchOne('SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema=? '.
  "AND table_name='detector_settings' AND column_name='thresholds'", 'n', array(ZM_ZONE_LABELS_DB))) > 0;
if ($zoneHasCameraThresholds) {
  $t = json_decode(strval(dbFetchOne('SELECT thresholds FROM `'.ZM_ZONE_LABELS_DB.'`.detector_settings WHERE monitor_id=?',
    'thresholds', array($monitor->Id()))), true);
  foreach (is_array($t) ? $t : array() as $l => $v) {
    if (is_array($v) and isset($v['threshold']) and is_numeric($v['threshold'])) $zoneCameraThresholds[$l] = floatval($v['threshold']);
  }
}
// One tick's value: "animal" is ten names, shown when all ten agree.
$zoneValueOf = function($map, $tick) {
  $names = detection_labels_from_ticks(array($tick));
  $vals = array();
  foreach ($names as $n) $vals[] = isset($map[$n]) ? number_format($map[$n], 2, '.', '') : null;
  $vals = array_unique($vals);
  return (count($vals) == 1) ? reset($vals) : null;
};

$zoneTick = function($l) use ($zoneTicked, $zoneLabelsReady, $zoneThresholdsReady, $zoneType, $zoneOwn, $zoneCameraThresholds, $zoneValueOf) {
  $off = $zoneLabelsReady ? '' : ' zoneDetectionOff';
  $own = $zoneValueOf($zoneOwn, $l);
  $camera = $zoneValueOf($zoneCameraThresholds, $l);
  $camera = $camera === null ? '0.60' : $camera;
  $thresholdOn = $zoneThresholdsReady && zone_labels_type_has_thresholds($zoneType);
  return '<span class="mr-3 text-nowrap d-inline-block">'.
    '<label class="mb-0"><input type="checkbox" class="zoneDetectionLabel'.$off.'" '.
    'name="liveDetection[labels][]" value="'.validHtmlStr($l).'"'.(isset($zoneTicked[$l]) ? ' checked="checked"' : '').
    (($zoneLabelsReady and $zoneType != 'Privacy') ? '' : ' disabled="disabled"').'/> '.validHtmlStr($l).'</label> '.
    '<input type="number" class="zoneDetectionThreshold'.($zoneThresholdsReady ? '' : ' zoneDetectionOff').'" '.
    'name="liveDetection[thresholds]['.validHtmlStr($l).']" min="0.30" max="0.95" step="0.05" '.
    'value="'.($own === null ? '' : $own).'" placeholder="'.$camera.'" '.
    'title="'.validHtmlStr(sprintf(translate('The threshold for %s in this zone. Blank: the camera\'s, %s.'), $l, $camera)).'" '.
    'style="width: 4.5em"'.($thresholdOn ? '' : ' disabled="disabled"').'/></span> ';
};

// What the ticks mean, per type; js/zone_detection.js shows the one for the type chosen.
$zoneMeanings = array(
  'Active' => translate('Only these types raise, join or hold an alarm here, once a box reaches into this zone by any part and has been in it for the camera\'s Alarm Frame Count of samples in a row. Every type is still stored and drawn, here or not.'),
  'Inclusive' => translate('Only these types join an alarm here, and only while a box is alarming in an Active zone; they never raise one. Every type is still stored and drawn.'),
  'Exclusive' => translate('Only these types raise, join or hold an alarm here, and only while no box is in an Active zone. Every type is still stored and drawn.'),
  'Preclusive' => translate('While a box of one of these types is in this zone, at the camera\'s threshold, nothing alarms anywhere on this camera, then for Extend Alarm Frames more samples.'),
  'Inactive' => translate('Live detection ignores these types here: a box entirely inside this zone is stored, but never drawn and never alarms. A person walking past, whose box sticks out of the zone, is still detected. At least one type must be ticked.'),
  'Privacy' => translate('A Privacy zone is blacked out before live detection sees the picture, so there\'s nothing to tick.'),
);
?>
            <div id="zoneDetection" class="mt-2 p-2 border border-primary text-left">
              <input type="hidden" name="liveDetection[shown]" value="1"/>
              <h3 class="h6 mb-1"><?php echo translate('Live detection') ?></h3>
<?php foreach ($zoneMeanings as $type => $words) { ?>
              <p class="mb-1 zoneDetectionMeaning" data-zone-type="<?php echo $type ?>"<?php echo $type == $zoneType ? '' : ' style="display: none"' ?>><?php echo $words ?></p>
<?php } ?>
              <p class="mb-1 text-muted zoneDetectionThresholdHelp"><?php echo translate('The number beside a type is its threshold in this zone, for raising and joining an alarm here and for the samples in a row: blank is the camera\'s (the Detection tab), and only a higher one changes anything. Holding an alarm already on is always the camera\'s hold.') ?></p>
              <div id="zoneDetectionLabels">
<?php foreach (detection_labels_common() as $l) echo $zoneTick($l); ?>
                <details<?php echo $zoneMoreTicked ? ' open' : '' ?>>
                  <summary><?php echo translate('More') ?><?php echo $zoneMoreTicked ? ' ('.$zoneMoreTicked.')' : '' ?></summary>
<?php foreach (array_merge(detection_labels_animals(), detection_labels_more()) as $l) echo $zoneTick($l); ?>
                </details>
              </div>
              <p class="mb-0 text-danger" id="zoneDetectionNothing" style="display: none"></p>
<?php if (!$zoneLabelsReady) { ?>
              <p class="mb-0 text-muted"><?php echo translate('Can be set after ZoneMinder\'s next start.') ?></p>
<?php } else if (!$zoneThresholdsReady) { ?>
              <p class="mb-0 text-muted"><?php echo translate('A zone\'s own thresholds can be set after ZoneMinder\'s next start.') ?></p>
<?php } ?>
              <p class="mb-0 text-muted"><?php echo translate('Live detection doesn\'t use ZoneMinder\'s motion settings above (Preset, Alarm Colour, Alarm Check Method, the pixel thresholds, the areas, the filter, the blobs and Overload Frame Ignore Count): they\'re greyed out and kept as they are. Extend Alarm Frames still counts for a Preclusive zone, in live detection\'s samples.') ?></p>
              <p class="mb-0 text-muted"><?php echo translate('Saved with the zone by Save.') ?></p>
            </div>
<?php echo output_script_if_exists(array('js/zone_detection.js')); ?>
