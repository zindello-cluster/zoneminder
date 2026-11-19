<?php
//
// zindello-cluster: the types live detection can look for, as the Detection tab's Looks for list
// offers them (views/_options_detection.php), shared with the zone page's Live detection section
// (views/_zone_detection.php): COCO's 80 names, the common ones first, the ten animals as one tick
// ("animal"), and the rest under More.
//

function detection_labels_common() {
  return array('person', 'car', 'truck', 'bus', 'motorcycle', 'bicycle', 'boat', 'animal');
}

function detection_labels_animals() {
  return array('bird', 'cat', 'dog', 'horse', 'sheep', 'cow', 'elephant', 'bear', 'zebra', 'giraffe');
}

function detection_labels_more() {
  return array('airplane', 'train', 'traffic light', 'fire hydrant', 'stop sign', 'parking meter', 'bench',
    'backpack', 'umbrella', 'handbag', 'tie', 'suitcase', 'frisbee', 'skis', 'snowboard', 'sports ball', 'kite',
    'baseball bat', 'baseball glove', 'skateboard', 'surfboard', 'tennis racket', 'bottle', 'wine glass', 'cup',
    'fork', 'knife', 'spoon', 'bowl', 'banana', 'apple', 'sandwich', 'orange', 'broccoli', 'carrot', 'hot dog',
    'pizza', 'donut', 'cake', 'chair', 'couch', 'potted plant', 'bed', 'dining table', 'toilet', 'tv', 'laptop',
    'mouse', 'remote', 'keyboard', 'cell phone', 'microwave', 'oven', 'toaster', 'sink', 'refrigerator', 'book',
    'clock', 'vase', 'scissors', 'teddy bear', 'hair drier', 'toothbrush');
}

// Every tick the list offers: the common ones ("animal" among them), the animals and the rest.
function detection_labels_all() {
  return array_merge(detection_labels_common(), detection_labels_animals(), detection_labels_more());
}

// Ticks posted from the list → the COCO names stored: "animal" as its ten, anything the list
// doesn't offer left out, each once, sorted.
function detection_labels_from_ticks($ticks) {
  $all = detection_labels_all();
  $labels = array();
  foreach (is_array($ticks) ? $ticks : array() as $l) {
    if (!is_string($l)) continue;
    if ($l == 'animal') {
      $labels = array_merge($labels, detection_labels_animals());
    } else if (in_array($l, $all, true)) {
      $labels[] = $l;
    }
  }
  $labels = array_values(array_unique($labels));
  sort($labels);
  return $labels;
}

// Stored COCO names → the ticks shown: the ten animals as "animal" when they're all there.
function detection_labels_ticked($labels) {
  $animals = detection_labels_animals();
  $has = array_fill_keys($labels, true);
  $all_animals = true;
  foreach ($animals as $a) if (!isset($has[$a])) $all_animals = false;
  $out = array();
  foreach ($labels as $l) {
    if ($all_animals and in_array($l, $animals, true)) continue;
    $out[] = $l;
  }
  if ($all_animals) $out[] = 'animal';
  return $out;
}
