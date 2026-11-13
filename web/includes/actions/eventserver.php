<?php
//
// zindello-cluster: the Event Server page's actions: Save, Back to the default, and Remove a phone
// (external-security.zindello.com.au/docs/alert-rules.md, "The page"). The rules and the permission
// checks are includes/alert_rules.php, shared with the API. Only a POST does anything here:
// ZoneMinder's CSRF check (index.php) is on POSTs, and an action named in a link is a GET. What
// happened is left in $eventserver_message (done) or $eventserver_error (refused) for the view,
// which escapes them.
//

if ($_SERVER['REQUEST_METHOD'] != 'POST') return;

require_once('includes/alert_rules.php');

global $eventserver_error;
global $eventserver_message;

if (!alert_rules_caller()) {
  $eventserver_error = 'Alert rules are per user, so they need ZoneMinder\'s sign-in.';
  return;
}
if (!alert_rules_ready()) {
  $eventserver_error = 'Alert rules aren\'t set up on this server yet.';
  return;
}

if ($action == 'removePhone') {
  // The page names the phone by a reference made from its hash (alert_rules_phone_ref), so the
  // hash itself is never on the page.
  $ref = (isset($_POST['phone']) and is_string($_POST['phone'])) ? $_POST['phone'] : '';
  $phone = null;
  if (preg_match('/^[0-9a-f]{64}$/', $ref)) {
    $row = dbFetchOne('SELECT token_hash FROM '.alert_rules_table('alert_phones').' WHERE SHA2(CONCAT(\'phone:\', token_hash), 256)=?', false, array($ref));
    if ($row) $phone = alert_rules_phone($row['token_hash']);
  }
  if (!$phone) {
    $eventserver_error = 'That phone isn\'t paired any more.';
  } else if (!alert_rules_may_remove_phone($phone)) {
    $eventserver_error = 'You can only remove your own phones.';
  } else if (!alert_rules_remove_phone($phone)) {
    $eventserver_error = 'The phone couldn\'t be removed. ZoneMinder\'s log has the reason.';
  } else {
    $eventserver_message = 'Removed. The event server forgets the phone within about 30 seconds.';
  }
  return;
}

if ($action != 'save' and $action != 'reset') return;

$target_id = isset($_POST['uid']) ? intval($_POST['uid']) : 0;
if (!alert_rules_may_manage($target_id)) {
  $eventserver_error = 'You can only change your own alert rules.';
  return;
}
$target = alert_rules_user($target_id);
if (!$target) {
  $eventserver_error = 'There is no such user.';
  return;
}
if ($target->Id() != alert_rules_caller()->Id() and empty($_POST['changeOther'])) {
  $eventserver_error = 'Nothing was changed: tick "Change '.$target->Username().'\'s rules" first.';
  return;
}

if ($action == 'reset') {
  $change = array('reset' => true);
} else {
  // Each camera row on the form says it was there (shown), so a camera added since the page was
  // drawn isn't taken as switched off. The animal tick is COCO's ten.
  $types = alert_rules_types();
  $cameras = array();
  $posted = (isset($_POST['camera']) and is_array($_POST['camera'])) ? $_POST['camera'] : array();
  foreach ($posted as $mid => $row) {
    if (!is_array($row) or empty($row['shown'])) continue;
    $labels = array();
    foreach ((isset($row['types']) and is_array($row['types'])) ? $row['types'] : array() as $type) {
      if (is_string($type) and isset($types[$type])) $labels = array_merge($labels, $types[$type]);
    }
    $cameras[$mid] = array('alerts' => !empty($row['alerts']), 'labels' => $labels);
  }
  $change = array('alerts' => !empty($_POST['alerts']), 'cameras' => $cameras);
}

list($status, $message) = alert_rules_change($target, $change);
if ($status == 200) {
  $eventserver_message = $message;
} else {
  $eventserver_error = $message;
}
?>
