<?php
//
// zindello-cluster: the Detection tab, live detection's settings per camera
// (external-security.zindello.com.au/docs/live-detection.md, "The Detection tab"; the owner,
// 12 November 2026: "What types is yolo looking for? Some config option on the UI for this would be
// good.", cluster-docs O365). One row per camera: Live on/off, what to look for (COCO's 80 types,
// the common ones first and the rest under More; the ten animals as one tick, "animal"), the
// confidence, the frames a second, and whether boxes start shown. Saved straight to
// detector.detector_settings; the live sidecar reads it within 10 seconds. Seen by whoever may view
// System settings, edited by whoever may edit them, as every other tab.
//

if (!canView('System')) {
  $view = 'error';
  return;
}
$canEdit = canEdit('System');

define('ZM_DETECTION_DB', 'detector');

$common = array('person', 'car', 'truck', 'bus', 'motorcycle', 'bicycle', 'boat', 'animal');
$animals = array('bird', 'cat', 'dog', 'horse', 'sheep', 'cow', 'elephant', 'bear', 'zebra', 'giraffe');
$more = array('airplane', 'train', 'traffic light', 'fire hydrant', 'stop sign', 'parking meter', 'bench',
  'backpack', 'umbrella', 'handbag', 'tie', 'suitcase', 'frisbee', 'skis', 'snowboard', 'sports ball', 'kite',
  'baseball bat', 'baseball glove', 'skateboard', 'surfboard', 'tennis racket', 'bottle', 'wine glass', 'cup',
  'fork', 'knife', 'spoon', 'bowl', 'banana', 'apple', 'sandwich', 'orange', 'broccoli', 'carrot', 'hot dog',
  'pizza', 'donut', 'cake', 'chair', 'couch', 'potted plant', 'bed', 'dining table', 'toilet', 'tv', 'laptop',
  'mouse', 'remote', 'keyboard', 'cell phone', 'microwave', 'oven', 'toaster', 'sink', 'refrigerator', 'book',
  'clock', 'vase', 'scissors', 'teddy bear', 'hair drier', 'toothbrush');
$all = array_merge($common, $animals, $more);

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
    $confidence = isset($row['confidence']) ? floatval($row['confidence']) : 0.6;
    $confidence = max(0.30, min(0.95, round($confidence, 2)));
    $per_second = isset($row['per_second']) ? intval($row['per_second']) : 5;
    $per_second = max(1, min(10, $per_second));
    $live = !empty($row['live']) ? 1 : 0;
    $boxes = !empty($row['boxes']) ? 1 : 0;
    dbQuery('INSERT INTO `'.ZM_DETECTION_DB.'`.detector_settings (monitor_id, labels, min_confidence, per_second, live, boxes_default) '.
      'VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE labels=VALUES(labels), min_confidence=VALUES(min_confidence), '.
      'per_second=VALUES(per_second), live=VALUES(live), boxes_default=VALUES(boxes_default)',
      array($mid, json_encode($labels), $confidence, $per_second, $live, $boxes));
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
  <p class="form-text"><?php echo translate('What YOLO looks for on each camera, all the time, from the frames ZoneMinder has decoded. What it finds raises the alarms and is drawn as boxes over the picture, live and on recordings. A camera with Live off is still recorded; it just raises no alarms and has no boxes.') ?></p>
  <table id="detectionTable" class="table table-sm table-striped">
    <thead>
      <tr>
        <th><?php echo translate('Camera') ?></th>
        <th><?php echo translate('Live') ?></th>
        <th><?php echo translate('Looks for') ?></th>
        <th><?php echo translate('Confidence') ?></th>
        <th><?php echo translate('Frames a second') ?></th>
        <th><?php echo translate('Boxes shown') ?></th>
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
        <td><input type="number" name="<?php echo $name ?>[confidence]" min="0.30" max="0.95" step="0.05" value="<?php echo $s ? number_format(floatval($s['min_confidence']), 2, '.', '') : '0.60' ?>" style="width: 5em"<?php echo $disabled ?>/></td>
        <td><input type="number" name="<?php echo $name ?>[per_second]" min="1" max="10" step="1" value="<?php echo $s ? intval($s['per_second']) : 5 ?>" style="width: 4em"<?php echo $disabled ?>/></td>
        <td><input type="checkbox" name="<?php echo $name ?>[boxes]" value="1"<?php echo ($s and $s['boxes_default']) ? ' checked="checked"' : '' ?><?php echo $disabled ?>/></td>
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
