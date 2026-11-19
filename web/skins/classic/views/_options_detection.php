<?php
//
// zindello-cluster: the Detection tab, live detection's settings per camera
// (external-security.zindello.com.au/docs/live-detection.md, "The Detection tab"; the owner,
// 12 November 2026: "What types is yolo looking for? Some config option on the UI for this would be
// good.", cluster-docs O365). One row per camera: Live on/off, what to look for (COCO's 80 types,
// the common ones first and the rest under More; the ten animals as one tick, "animal"), a threshold
// and a hold per type, whether boxes start shown, and which objects may raise an alarm (alarm_on;
// the owner, 13 November 2026: "Stationary Objects should not trigger an alert ... make that an
// option to enable/disable on a per-camera basis"). Saved straight to detector.detector_settings;
// the live sidecar reads it within 10 seconds. Seen by whoever may view System settings, edited by
// whoever may edit them, as every other tab.
//
// The threshold and the hold per type (detector_settings.thresholds; cluster-docs
// docs/zoneminder-zones-for-live-detection.md; the owner, 20 November 2026: "A 'threshold' and a
// 'minimum hold' gives us our 60/40 split we have right now doesn't it?", O562; "B is good.", O563)
// replace the camera's one Confidence (0.60) and the tracker's stayConfidence (0.40): the threshold
// starts a track and raises or joins an alarm, the hold keeps a track and holds an alarm already on
// (O568). A type without its own starts at 0.60 and 0.40 (O567). The rate is the camera's Analysis
// FPS and the samples in a row its Alarm Frame Count, both on its monitor page, so Frames a second
// is gone (O550, O553); min_confidence and per_second are no longer read or written here.
//

if (!canView('System')) {
  $view = 'error';
  return;
}
$canEdit = canEdit('System');

define('ZM_DETECTION_DB', 'detector');

// The list is shared with the zone page's Live detection section (includes/detection_labels.php).
require_once('includes/detection_labels.php');
$common = detection_labels_common();
$animals = detection_labels_animals();
$more = detection_labels_more();
$all = detection_labels_all();
// Which objects may raise a camera's alarm, as the live sidecar reads detector_settings.alarm_on.
$alarmChoices = array(
  'moving' => 'Moving objects only',
  'moving_and_people' => 'Moving objects, and people even when still',
  'all' => 'Everything',
);
$alarmDefault = 'moving_and_people';
// A detector table from before the column (the chart's seed adds it at ZoneMinder's next start):
// shown, not saved.
$hasAlarmOn = intval(dbFetchOne('SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema=? '.
  "AND table_name='detector_settings' AND column_name='alarm_on'", 'n', array(ZM_DETECTION_DB))) > 0;
// The same for the thresholds (the seed adds the column with this change).
$hasThresholds = intval(dbFetchOne('SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema=? '.
  "AND table_name='detector_settings' AND column_name='thresholds'", 'n', array(ZM_DETECTION_DB))) > 0;
define('ZM_DETECTION_THRESHOLD', 0.60);  // a type's threshold and hold until set (O567)
define('ZM_DETECTION_HOLD', 0.40);

// A posted value on the 0.05 steps, within [min, max]; $else if none or not a number.
function detection_value($v, $min, $max, $else) {
  if (!is_string($v) and !is_numeric($v)) return $else;
  $v = trim(strval($v));
  if ($v === '' or !is_numeric($v)) return $else;
  return round(max($min, min($max, round(floatval($v) * 20) / 20)), 2);
}

// A camera's stored thresholds: {name: {threshold, hold}}, each a number.
function detection_thresholds_of($row) {
  $t = ($row and isset($row['thresholds'])) ? json_decode(strval($row['thresholds']), true) : null;
  $out = array();
  foreach (is_array($t) ? $t : array() as $l => $v) {
    if (!is_string($l) or !is_array($v)) continue;
    $th = (isset($v['threshold']) and is_numeric($v['threshold'])) ? floatval($v['threshold']) : ZM_DETECTION_THRESHOLD;
    $ho = (isset($v['hold']) and is_numeric($v['hold'])) ? floatval($v['hold']) : ZM_DETECTION_HOLD;
    $out[$l] = array('threshold' => $th, 'hold' => min($ho, $th));
  }
  return $out;
}

$message = '';
if ($canEdit and isset($_POST['detectionSave']) and isset($_POST['camera']) and is_array($_POST['camera'])) {
  foreach ($_POST['camera'] as $mid => $row) {
    $mid = intval($mid);
    if (!$mid) continue;
    $labels = array();
    foreach ((isset($row['labels']) and is_array($row['labels'])) ? $row['labels'] : array() as $l) {
      if ($l == 'animal') {
        $labels = array_merge($labels, $animals);
      } else if (in_array($l, $all, true)) {
        $labels[] = $l;
      }
    }
    $labels = array_values(array_unique($labels));
    sort($labels);
    $live = !empty($row['live']) ? 1 : 0;
    $boxes = !empty($row['boxes']) ? 1 : 0;
    $alarmOn = (isset($row['alarm_on']) and is_string($row['alarm_on']) and isset($alarmChoices[$row['alarm_on']])) ? $row['alarm_on'] : $alarmDefault;
    $cols = array('monitor_id' => $mid, 'labels' => json_encode($labels), 'live' => $live, 'boxes_default' => $boxes);
    if ($hasAlarmOn) $cols['alarm_on'] = $alarmOn;
    if ($hasThresholds) {
      // Each type looked for: as posted (the animals by their one row), else as stored, else 0.60
      // and 0.40; the hold never above the threshold.
      $was = detection_thresholds_of(dbFetchOne('SELECT thresholds FROM `'.ZM_DETECTION_DB.'`.detector_settings WHERE monitor_id=?', false, array($mid)));
      $posted = (isset($row['thresholds']) and is_array($row['thresholds'])) ? $row['thresholds'] : array();
      $thresholds = array();
      foreach ($labels as $l) {
        $tick = (in_array($l, $animals, true) and isset($posted['animal'])) ? 'animal' : $l;
        $p = (isset($posted[$tick]) and is_array($posted[$tick])) ? $posted[$tick] : array();
        $th = detection_value(isset($p['threshold']) ? $p['threshold'] : null, 0.30, 0.95,
          isset($was[$l]) ? $was[$l]['threshold'] : ZM_DETECTION_THRESHOLD);
        $ho = detection_value(isset($p['hold']) ? $p['hold'] : null, 0.10, 0.95,
          isset($was[$l]) ? $was[$l]['hold'] : ZM_DETECTION_HOLD);
        $thresholds[$l] = array('threshold' => $th, 'hold' => min($ho, $th));
      }
      $cols['thresholds'] = json_encode((object)$thresholds);
    } else {
      // A table from before the thresholds: its retired columns have no defaults yet, so a new row
      // gets today's (never changed on an existing row).
      $cols['min_confidence'] = ZM_DETECTION_THRESHOLD;
      $cols['per_second'] = 5;
    }
    $update = array();
    foreach (array_keys($cols) as $c) {
      if (!in_array($c, array('monitor_id', 'min_confidence', 'per_second'), true)) $update[] = $c.'=VALUES('.$c.')';
    }
    dbQuery('INSERT INTO `'.ZM_DETECTION_DB.'`.detector_settings ('.implode(', ', array_keys($cols)).') '.
      'VALUES ('.implode(', ', array_fill(0, count($cols), '?')).') ON DUPLICATE KEY UPDATE '.implode(', ', $update),
      array_values($cols));
  }
  $message = translate('Saved. The detector picks it up within 10 seconds.');
}

$settings = array();
foreach (dbFetchAll('SELECT * FROM `'.ZM_DETECTION_DB.'`.detector_settings') as $row) {
  $row['labels'] = json_decode($row['labels'], true) ?: array();
  $settings[$row['monitor_id']] = $row;
}
$monitors = dbFetchAll('SELECT Id, Name, Enabled FROM Monitors WHERE Deleted=0 ORDER BY Sequence ASC, Name ASC');

function detection_label_shown($labels) {
  // The ten animals as one word when they're all there.
  global $animals;
  $has = array_fill_keys($labels, true);
  $out = array();
  $all_animals = true;
  foreach ($animals as $a) if (!isset($has[$a])) $all_animals = false;
  foreach ($labels as $l) {
    if (in_array($l, $animals, true)) {
      if ($all_animals) continue;
      $out[] = $l;
    } else {
      $out[] = $l;
    }
  }
  if ($all_animals) $out[] = 'animal';
  return $out;
}
?>
<form name="detectionForm" method="post" action="?view=options&amp;tab=detection">
  <input type="hidden" name="view" value="options"/>
  <input type="hidden" name="tab" value="detection"/>
<?php if ($message) echo '<span class="timedSuccessBox">'.$message.'</span>'; ?>
  <p class="form-text"><?php echo translate('What YOLO looks for on each camera, all the time, from the frames ZoneMinder has decoded. What it finds raises the alarms and is drawn as boxes over the picture, live and on recordings. A camera with Live off is still recorded; it just raises no alarms and has no boxes. Alarms on says whether something that hasn\'t moved, a parked car say, may raise an alarm.') ?></p>
  <p class="form-text"><?php echo translate('Each type has a threshold and a hold. An object is drawn, stored and can raise or join an alarm once it\'s at its threshold, and stays tracked, and keeps an alarm already on, down to its hold. A type just ticked starts at 0.60 and 0.40; set its own after saving. A zone can ask for a higher threshold on its own zone page. How often a camera is looked at is its Analysis FPS, and how many samples in a row an object must be in a zone before it alarms is its Alarm Frame Count: both on the camera\'s own page (Analysis and Buffers).') ?></p>
<?php if (!$hasThresholds) echo '<p class="form-text text-muted">'.translate('The thresholds and holds can be changed after ZoneMinder\'s next start; until then each type is at 0.60 and 0.40.').'</p>'; ?>
  <table id="detectionTable" class="table table-sm table-striped">
    <thead>
      <tr>
        <th><?php echo translate('Camera') ?></th>
        <th><?php echo translate('Live') ?></th>
        <th><?php echo translate('Looks for') ?></th>
        <th><?php echo translate('Threshold and hold') ?></th>
        <th><?php echo translate('Boxes shown') ?></th>
        <th><?php echo translate('Alarms on') ?></th>
      </tr>
    </thead>
    <tbody>
<?php
$disabled = $canEdit ? '' : ' disabled="disabled"';
foreach ($monitors as $m) {
  $mid = intval($m['Id']);
  $s = isset($settings[$mid]) ? $settings[$mid] : null;
  $labels = $s ? $s['labels'] : array();
  $ticked = array_fill_keys(detection_label_shown($labels), true);
  $name = 'camera['.$mid.']';
  $moreTicked = 0;
  foreach ($more as $l) if (isset($ticked[$l])) $moreTicked++;
  foreach ($animals as $l) if (isset($ticked[$l])) $moreTicked++;
?>
      <tr>
        <td><?php echo validHtmlStr($m['Name']) ?><?php if (!$m['Enabled']) echo ' <span class="text-muted">('.translate('off').')</span>'; ?>
<?php if (!$s) echo '<br/><span class="text-muted">'.translate('No settings yet: the row is made at ZoneMinder\'s next start.').'</span>'; ?></td>
        <td><input type="checkbox" name="<?php echo $name ?>[live]" value="1"<?php echo ($s and $s['live']) ? ' checked="checked"' : '' ?><?php echo $disabled ?>/></td>
        <td>
<?php
  foreach ($common as $l) {
    echo '<label class="mr-2 text-nowrap"><input type="checkbox" name="'.$name.'[labels][]" value="'.$l.'"'.(isset($ticked[$l]) ? ' checked="checked"' : '').$disabled.'/> '.validHtmlStr($l).'</label> ';
  }
?>
          <details<?php echo $moreTicked ? ' open' : '' ?>>
            <summary><?php echo translate('More') ?><?php echo $moreTicked ? ' ('.$moreTicked.')' : '' ?></summary>
<?php
  foreach (array_merge($animals, $more) as $l) {
    echo '<label class="mr-2 text-nowrap"><input type="checkbox" name="'.$name.'[labels][]" value="'.$l.'"'.(isset($ticked[$l]) ? ' checked="checked"' : '').$disabled.'/> '.validHtmlStr($l).'</label> ';
  }
?>
          </details>
        </td>
        <td>
<?php
  $values = detection_thresholds_of($s);
  $off = $hasThresholds ? $disabled : ' disabled="disabled"';
  if (!count($labels)) echo '<span class="text-muted">'.translate('Nothing looked for').'</span>';
  else echo '<table class="table-sm detectionThresholds"><tr><th></th><th>'.translate('Threshold').'</th><th>'.translate('Hold').'</th></tr>';
  foreach (detection_label_shown($labels) as $tick) {
    $l = $tick == 'animal' ? $animals[0] : $tick;
    $v = isset($values[$l]) ? $values[$l] : array('threshold' => ZM_DETECTION_THRESHOLD, 'hold' => ZM_DETECTION_HOLD);
    echo '<tr><td class="text-nowrap">'.validHtmlStr($tick).'</td>'.
      '<td><input type="number" name="'.$name.'[thresholds]['.validHtmlStr($tick).'][threshold]" min="0.30" max="0.95" step="0.05" value="'.number_format($v['threshold'], 2, '.', '').'" style="width: 5em"'.$off.'/></td>'.
      '<td><input type="number" name="'.$name.'[thresholds]['.validHtmlStr($tick).'][hold]" min="0.10" max="0.95" step="0.05" value="'.number_format($v['hold'], 2, '.', '').'" style="width: 5em"'.$off.'/></td></tr>';
  }
  if (count($labels)) echo '</table>';
?>
        </td>
        <td><input type="checkbox" name="<?php echo $name ?>[boxes]" value="1"<?php echo ($s and $s['boxes_default']) ? ' checked="checked"' : '' ?><?php echo $disabled ?>/></td>
        <td>
<?php
  $alarmOn = ($s and isset($s['alarm_on']) and isset($alarmChoices[$s['alarm_on']])) ? $s['alarm_on'] : $alarmDefault;
  echo '<select name="'.$name.'[alarm_on]"'.($hasAlarmOn ? $disabled : ' disabled="disabled"').'>';
  foreach ($alarmChoices as $value => $words) {
    echo '<option value="'.$value.'"'.($value == $alarmOn ? ' selected="selected"' : '').'>'.validHtmlStr(translate($words)).'</option>';
  }
  echo '</select>';
  if (!$hasAlarmOn) echo '<br/><span class="text-muted">'.translate('Can be changed after ZoneMinder\'s next start.').'</span>';
?>
        </td>
      </tr>
<?php
} // end foreach monitor
?>
    </tbody>
  </table>
<?php if ($canEdit) { ?>
  <button type="submit" name="detectionSave" value="1"><?php echo translate('Save') ?></button>
<?php } ?>
</form>
