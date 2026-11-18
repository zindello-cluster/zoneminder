<?php
//
// zindello-cluster: the Camera tab's request (views/_monitor_camera.php; cluster-docs
// docs/zoneminder-camera-tab.md, O508-O511).
//
// ?request=camerasettings&action=read&mid=N
//     the camera's settings, read from it now in one login (includes/vigi.php).
// POST request=camerasettings&action=save&mid=N&changes[<id>]=<value>...
//     sends what differs to the camera, reads it back, in one login; logged in ZoneMinder's Log.
//
// Behind ZoneMinder's sign-in, the camera's own view check, and System: View to read, Edit to save.
// The camera's login is the one ZoneMinder records it with (the monitor's own). A refusal is never
// retried here or in the page: a VIGI locks itself after 10.
//
ini_set('display_errors', '');

require_once('includes/Monitor.php');
require_once('includes/vigi.php');

$mid = isset($_REQUEST['mid']) ? intval($_REQUEST['mid']) : 0;
$Monitor = $mid ? ZM\Monitor::find_one(array('Id' => $mid)) : null;
if (!$Monitor or !$Monitor->canView() or !canView('System')) {
  ajaxError('Not found');
}
$camera = vigi_camera_of($Monitor);
if (!$camera) {
  ajaxError('Not a camera camera setup sets');
}
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'read';
$who = isset($user) && $user ? $user->Username() : '?';

try {
  if ($action == 'read') {
    ajaxResponse(array('camera' => vigi_read($camera, $Monitor->User(), $Monitor->Pass()), 'at' => time()));
  } else if ($action == 'save') {
    if (!canEdit('System') or $_SERVER['REQUEST_METHOD'] != 'POST') {
      ajaxError('Saving needs System: Edit');
    }
    $changes = (isset($_POST['changes']) and is_array($_POST['changes'])) ? $_POST['changes'] : array();
    $view = vigi_save($camera, $Monitor->User(), $Monitor->Pass(), $changes);
    if ($view['changed']) {
      ZM\Info('Camera settings: '.$who.', '.$Monitor->Name().': '.vigi_describe($view['changed']).
        ($view['notTaken'] ? ' (not taken: '.implode(', ', $view['notTaken']).')' : ' (read back)'));
    }
    $view['changedWords'] = vigi_describe($view['changed']);
    unset($view['changed']);
    ajaxResponse(array('camera' => $view, 'at' => time()));
  } else {
    ajaxError('No such action');
  }
} catch (VigiRefused $e) {
  ZM\Warning('Camera settings: '.$who.', '.$Monitor->Name().': the camera '.$e->getMessage().'; not tried again');
  ajaxResponse(array('refused' => 'The '.$Monitor->Name().' '.$e->getMessage().'. Not tried again: it\'s the login ZoneMinder records it with.', 'at' => time()));
} catch (VigiError $e) {
  ZM\Warning('Camera settings: '.$who.', '.$Monitor->Name().': the camera '.$e->getMessage());
  ajaxResponse(array('failed' => 'The '.$Monitor->Name().' '.$e->getMessage().'.', 'at' => time()));
}
