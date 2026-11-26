<?php
//
// zindello-cluster: the Camera tab's request (views/_monitor_camera.php; cluster-docs
// docs/zoneminder-camera-tab.md, O508-O511), through the camera changer's API
// (docs/zoneminder-camera-changer-pod.md, section 11; O803, O808).
//
// ?request=camerasettings&action=read&mid=N
//     the camera's settings, which the changer reads from it now in one sign-in.
// POST request=camerasettings&action=save&mid=N&changes[<id>]=<value>...
//     the changer sends what differs to the camera and reads it back, in one sign-in; logged in
//     ZoneMinder's Log with the user's name.
//
// Behind ZoneMinder's sign-in, the camera's own view check, and System: View to read, Edit to save:
// ZoneMinder decides who may, before it asks the changer. ZoneMinder never signs in to a camera and
// never holds its password here: the changer does, one sign-in at a time per camera. A refusal is
// never retried here or in the page: a VIGI locks itself after 10.
//
ini_set('display_errors', '');

require_once('includes/Monitor.php');
require_once('includes/camerachanger.php');

$mid = isset($_REQUEST['mid']) ? intval($_REQUEST['mid']) : 0;
$Monitor = $mid ? ZM\Monitor::find_one(array('Id' => $mid)) : null;
if (!$Monitor or !$Monitor->canView() or !canView('System')) {
  ajaxError('Not found');
}
if (!camera_changer_camera_of($Monitor)) {
  ajaxError('Not a camera camera setup sets');
}
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'read';
$who = isset($user) && $user ? $user->Username() : '?';

try {
  if ($action == 'read') {
    $r = camera_changer_call('GET', '/cameras/'.$Monitor->Id().'/settings');
  } else if ($action == 'save') {
    if (!canEdit('System') or $_SERVER['REQUEST_METHOD'] != 'POST') {
      ajaxError('Saving needs System: Edit');
    }
    $changes = array();
    if (isset($_POST['changes']) and is_array($_POST['changes'])) {
      foreach ($_POST['changes'] as $k => $v) {
        if (is_string($k) and (is_string($v) or is_int($v))) $changes[$k] = (string)$v;
      }
    }
    $r = camera_changer_call('POST', '/cameras/'.$Monitor->Id().'/settings', array('changes' => (object)$changes, 'user' => $who));
    if (isset($r['camera']['changedWords']) and $r['camera']['changedWords'] !== '') {
      ZM\Info('Camera settings: '.$who.', '.$Monitor->Name().': '.$r['camera']['changedWords'].
        (!empty($r['camera']['notTaken']) ? ' (not taken: '.implode(', ', $r['camera']['notTaken']).')' : ' (read back)'));
    }
  } else {
    ajaxError('No such action');
  }
} catch (CameraChangerError $e) {
  ZM\Warning('Camera settings: '.$who.', '.$Monitor->Name().': the camera '.$e->getMessage());
  ajaxResponse(array('failed' => 'The '.$Monitor->Name().' '.$e->getMessage().'.', 'at' => time()));
}

if (isset($r['refused'])) {
  ZM\Warning('Camera settings: '.$who.', '.$Monitor->Name().': '.$r['refused']);
  ajaxResponse(array('refused' => (string)$r['refused'], 'at' => isset($r['at']) ? intval($r['at']) : time()));
} else if (isset($r['failed'])) {
  ZM\Warning('Camera settings: '.$who.', '.$Monitor->Name().': '.$r['failed']);
  ajaxResponse(array('failed' => (string)$r['failed'], 'at' => isset($r['at']) ? intval($r['at']) : time()));
} else if (isset($r['camera']) and is_array($r['camera'])) {
  ajaxResponse(array('camera' => $r['camera'], 'at' => isset($r['at']) ? intval($r['at']) : time()));
}
ajaxResponse(array('failed' => 'The '.$Monitor->Name().' got an answer from the camera changer it doesn\'t know.', 'at' => time()));
