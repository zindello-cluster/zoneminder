<?php
//
// zindello-cluster: the camera changer's API, for the Camera tab (cluster-docs
// docs/zoneminder-camera-changer-pod.md, section 11; the owner, 26 November 2026: "Fold it into the
// camera work that's going on now." and "B do it now.", O803, O808).
//
// ZoneMinder never signs in to a camera itself: the Camera tab asks the camera changer, the only
// thing that signs in to a camera, one sign-in at a time per camera. Machine to machine: only
// ZoneMinder's pod may reach the changer's API (a Cilium rule of the platform's), with no password,
// for now (O772). The changer's address is the chart's, ZM_CAMERA_CHANGER_URL in /etc/zm/conf.d.
// Never sends or receives a camera's password.
//

// Which cameras camera setup sets: its own settings (no secrets), mounted read only by the chart.
if (!defined('ZM_VIGI_CAMERAS_FILE')) define('ZM_VIGI_CAMERAS_FILE', '/opt/camsetup/config.json');

// The camera of a monitor camera setup sets, by its name in camera setup's settings, or null.
function camera_changer_camera_of($monitor) {
  static $cameras = null;
  if ($cameras === null) {
    $cameras = array();
    $text = @file_get_contents(ZM_VIGI_CAMERAS_FILE);
    $cfg = $text === false ? null : json_decode($text, true);
    if (is_array($cfg) and isset($cfg['cameras']) and is_array($cfg['cameras'])) {
      foreach ($cfg['cameras'] as $c) {
        if (isset($c['name'])) $cameras[$c['name']] = $c;
      }
    }
  }
  return isset($cameras[$monitor->Name()]) ? $cameras[$monitor->Name()] : null;
}

class CameraChangerError extends Exception {}

// One call to the changer's API: its answer, decoded. Never retried.
function camera_changer_call($method, $path, $body = null) {
  if (!defined('ZM_CAMERA_CHANGER_URL') or !ZM_CAMERA_CHANGER_URL) {
    throw new CameraChangerError("doesn't know where the camera changer is (ZM_CAMERA_CHANGER_URL)");
  }
  $ch = curl_init(rtrim(ZM_CAMERA_CHANGER_URL, '/').$path);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    // A read or a save signs in to the camera once, and the camera may wait for another's sign-in.
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CUSTOMREQUEST => $method,
  ));
  if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  }
  $text = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($text === false) throw new CameraChangerError("couldn't reach the camera changer");
  $r = json_decode($text, true);
  if ($status == 404) throw new CameraChangerError("isn't one the camera changer sets up yet");
  if ($status != 200 or !is_array($r)) throw new CameraChangerError('got an answer from the camera changer it doesn\'t know ('.$status.')');
  return $r;
}
