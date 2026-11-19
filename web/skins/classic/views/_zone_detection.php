<?php
//
// zindello-cluster: the zone page's Live detection section (cluster-docs
// docs/zoneminder-detection-exclusions.md, section 4; O527-O535): the types live detection ignores
// in this zone, ticked from the Detection tab's Looks for list and saved by the zone page's own Save
// (actions/zone.php; includes/zone_labels.php). The ticks are enabled only for an Inactive zone, by
// applyZoneType (views/js/zone.js), as ZoneMinder's own settings are for the types that use them;
// a zone of another type keeps its ticks, greyed out and ignored. Included by views/zone.php inside
// its form, where Monitors: Edit for this camera has already been checked.
//

require_once('includes/zone_labels.php');

$zoneLabelsReady = zone_labels_ready();
$zoneTicked = array_fill_keys(detection_labels_ticked(zone_labels_of($zone['Id'])), true);
$zoneMoreTicked = 0;
foreach (array_merge(detection_labels_animals(), detection_labels_more()) as $l) {
  if (isset($zoneTicked[$l])) $zoneMoreTicked++;
}
// Greyed out until applyZoneType enables them for an Inactive zone; for good without the table.
$zoneTick = function($l) use ($zoneTicked, $zoneLabelsReady) {
  return '<label class="mr-2 text-nowrap"><input type="checkbox" class="zoneDetectionLabel'.($zoneLabelsReady ? '' : ' zoneDetectionOff').'" '.
    'name="liveDetection[labels][]" value="'.validHtmlStr($l).'"'.(isset($zoneTicked[$l]) ? ' checked="checked"' : '').
    ' disabled="disabled"/> '.validHtmlStr($l).'</label> ';
};
?>
            <div id="zoneDetection" class="mt-2 p-2 border border-primary text-left">
              <input type="hidden" name="liveDetection[shown]" value="1"/>
              <h3 class="h6 mb-1"><?php echo translate('Live detection') ?></h3>
              <p class="mb-1"><?php echo translate('Live detection ignores these types here: a box entirely inside this zone is dropped, as if never seen. A person walking past, whose box sticks out of the zone, is still detected.') ?></p>
              <p class="mb-1"><?php echo translate('Ignores here (only for an Inactive zone; nothing until a type is ticked):') ?></p>
              <div id="zoneDetectionLabels">
<?php foreach (detection_labels_common() as $l) echo $zoneTick($l); ?>
                <details<?php echo $zoneMoreTicked ? ' open' : '' ?>>
                  <summary><?php echo translate('More') ?><?php echo $zoneMoreTicked ? ' ('.$zoneMoreTicked.')' : '' ?></summary>
<?php foreach (array_merge(detection_labels_animals(), detection_labels_more()) as $l) echo $zoneTick($l); ?>
                </details>
              </div>
<?php if (!$zoneLabelsReady) { ?>
              <p class="mb-0 text-muted"><?php echo translate('Can be set after ZoneMinder\'s next start.') ?></p>
<?php } else if (!count($zoneTicked)) { ?>
              <p class="mb-0 text-muted" id="zoneDetectionNothing"><?php echo translate('Nothing is ignored here until a type is ticked.') ?></p>
<?php } ?>
              <p class="mb-0 text-muted"><?php echo translate('Saved with the zone by Save.') ?></p>
            </div>
