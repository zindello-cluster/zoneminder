<?php
//
// zindello-cluster: a VIGI camera's own settings, through its web API (cluster-docs
// docs/zoneminder-camera-tab.md; the owner, 18 November 2026: "I want to be able to tune it from a
// single UI.", O508-O511). The same calls as the camera's own pages and as the chart's camera setup
// (external-security.zindello.com.au deploy/camsetup/camsetup.py): POST https://<camera>/ before a
// session, POST https://<camera>/stok=<session>/ds after. TP-Link publishes no document of them.
//
// One login per action, never retried: a VIGI allows 10 refused logins, then locks itself, for a
// while or until it's power-cycled. One action at a time per camera (a lock file). Never logs or
// returns a password or a session.
//

define('VIGI_OK', 0);
define('VIGI_SUPER_OK', -40409);   // a login that worked
define('VIGI_UNAUTH', -40401);     // no session: data.code says why
define('VIGI_LOCKED', -40404);     // locked after refused logins: data.sec_left
define('VIGI_FACTORY', -40405);    // factory-default: no password yet
define('VIGI_NORMAL', -40407);     // needs a login
define('VIGI_LOCKED_FOREVER', -40408);

// Which cameras are VIGIs: camera setup's own settings (no secrets), mounted read only by the chart.
if (!defined('ZM_VIGI_CAMERAS_FILE')) define('ZM_VIGI_CAMERAS_FILE', '/opt/camsetup/config.json');

class VigiError extends Exception {}
class VigiRefused extends VigiError {}   // a refused login, or a locked camera: never retried

// The camera of a monitor, by its name in camera setup's settings: array('address' => ...) or null.
function vigi_camera_of($monitor) {
  static $cameras = null;
  if ($cameras === null) {
    $cameras = array();
    $text = @file_get_contents(ZM_VIGI_CAMERAS_FILE);
    $cfg = $text === false ? null : json_decode($text, true);
    if (is_array($cfg) and isset($cfg['cameras']) and is_array($cfg['cameras'])) {
      foreach ($cfg['cameras'] as $c) {
        if (isset($c['name']) and isset($c['address'])) $cameras[$c['name']] = $c;
      }
    }
  }
  return isset($cameras[$monitor->Name()]) ? $cameras[$monitor->Name()] : null;
}

function vigi_md5auth($password) {
  return strtoupper(md5('TPCQ75NF2Y:'.$password));
}

// RSA PKCS#1 v1.5 with the camera's key (a base64 SubjectPublicKeyInfo), base64, as its jsencrypt.
function vigi_rsa($spki_b64, $message) {
  $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(rawurldecode($spki_b64), 64, "\n")."-----END PUBLIC KEY-----\n";
  $key = openssl_pkey_get_public($pem);
  if ($key === false or !openssl_public_encrypt($message, $out, $key, OPENSSL_PKCS1_PADDING)) {
    throw new VigiError("the camera's key isn't one we can use");
  }
  return base64_encode($out);
}

class VigiCamera {
  private $address;
  private $timeout;
  private $stok = null;
  private $lock = null;

  function __construct($address, $timeout = 10) {
    if (!filter_var($address, FILTER_VALIDATE_IP) and !preg_match('/^[A-Za-z0-9.-]+$/', $address)) {
      throw new VigiError('not an address');
    }
    $this->address = $address;
    $this->timeout = $timeout;
  }

  function __destruct() {
    $this->logout();
    $this->unlock();
  }

  // One action at a time per camera: waits up to the timeout for another to finish.
  private function lock() {
    $this->lock = fopen(sys_get_temp_dir().'/zm-vigi-'.preg_replace('/[^A-Za-z0-9.-]/', '_', $this->address).'.lock', 'c');
    $until = time() + $this->timeout;
    while (!flock($this->lock, LOCK_EX | LOCK_NB)) {
      if (time() >= $until) throw new VigiError('another change to this camera is still running');
      usleep(200000);
    }
  }

  private function unlock() {
    if ($this->lock) {
      flock($this->lock, LOCK_UN);
      fclose($this->lock);
      $this->lock = null;
    }
  }

  private function request($method, $path, $body = null) {
    $ch = curl_init('https://'.$this->address.$path);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => $this->timeout,
      CURLOPT_TIMEOUT => $this->timeout,
      // The camera's own certificate is self-signed; it's on a VLAN only it and ZoneMinder are on.
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_CUSTOMREQUEST => $method,
    ));
    if ($body !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=UTF-8'));
    }
    $text = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($text === false) throw new VigiError("didn't answer");
    return array($status, $text);
  }

  private function post($path, $obj) {
    list(, $text) = $this->request('POST', $path, json_encode($obj, JSON_UNESCAPED_SLASHES));
    $r = json_decode($text, true);
    if (!is_array($r)) throw new VigiError('gave an answer that isn\'t JSON');
    return $r;
  }

  // (code, data) without logging in: VIGI_FACTORY, VIGI_NORMAL, VIGI_LOCKED or VIGI_LOCKED_FOREVER,
  // and the login's one-time key and nonce. Never a login attempt.
  function state() {
    $r = $this->post('/', array('method' => 'do', 'user_management' => array('get_encrypt_info' => null)));
    $data = (isset($r['data']) and is_array($r['data'])) ? $r['data'] : array();
    $code = isset($data['code']) ? $data['code'] : (isset($r['error_code']) ? $r['error_code'] : null);
    if (isset($r['error_code']) and $r['error_code'] == VIGI_OK) $code = VIGI_NORMAL;
    return array($code, $data);
  }

  // One login. Refused or locked: VigiRefused, and nothing tries again.
  function login($username, $password) {
    $this->lock();
    list($code, $data) = $this->state();
    if ($code == VIGI_LOCKED) {
      throw new VigiRefused('is locked after refused logins'.(isset($data['sec_left']) ? ' for '.intval($data['sec_left']).' s more' : ''));
    }
    if ($code == VIGI_LOCKED_FOREVER) throw new VigiRefused('is locked until it\'s power-cycled');
    if ($code == VIGI_FACTORY) throw new VigiError('is factory-default: camera setup sets its first password as ZoneMinder starts');
    if ($code != VIGI_NORMAL) throw new VigiError('gave an answer we don\'t know ('.$code.')');
    if (isset($data['encrypt_type']) and is_array($data['encrypt_type']) and in_array('2', $data['encrypt_type'], true)
        and !empty($data['key']) and isset($data['nonce'])) {
      $secret = vigi_rsa($data['key'], vigi_md5auth($password).':'.$data['nonce']);
      $kind = '2';
    } else {
      $secret = vigi_md5auth($password);
      $kind = '1';
    }
    $r = $this->post('/', array('method' => 'do', 'login' => array(
      'username' => $username, 'password' => $secret, 'passwdType' => 'md5', 'encrypt_type' => $kind)));
    $code = isset($r['error_code']) ? $r['error_code'] : null;
    if (($code == VIGI_OK or $code == VIGI_SUPER_OK) and isset($r['stok'])) {
      $this->stok = rawurldecode($r['stok']);
      return;
    }
    $d = (isset($r['data']) and is_array($r['data'])) ? $r['data'] : array();
    $inner = isset($d['code']) ? $d['code'] : $code;
    if ($inner == VIGI_LOCKED or $inner == VIGI_LOCKED_FOREVER) throw new VigiRefused('is locked after refused logins');
    throw new VigiRefused('refused its login'.(isset($d['time']) ? ' (it says '.intval($d['time']).' tries are left of '.intval($d['max_time']).')' : ''));
  }

  private function ds($obj) {
    if ($this->stok === null) throw new VigiError('not logged in');
    return $this->post('/stok='.rawurlencode($this->stok).'/ds', $obj);
  }

  function get($section, $names) {
    $r = $this->ds(array('method' => 'get', $section => array('name' => $names)));
    if (!isset($r['error_code']) or $r['error_code'] != VIGI_OK or !isset($r[$section])) {
      throw new VigiError("couldn't read $section (".(isset($r['error_code']) ? $r['error_code'] : '?').')');
    }
    return $r[$section];
  }

  function getTable($section, $table) {
    $r = $this->ds(array('method' => 'get', $section => array('table' => $table)));
    if (!isset($r['error_code']) or $r['error_code'] != VIGI_OK or !isset($r[$section][$table])) {
      throw new VigiError("couldn't read $section (".(isset($r['error_code']) ? $r['error_code'] : '?').')');
    }
    return $r[$section][$table];
  }

  function set($payload) {
    $r = $this->ds(array_merge(array('method' => 'set'), $payload));
    if (!isset($r['error_code']) or $r['error_code'] != VIGI_OK) {
      throw new VigiError('refused '.implode(', ', array_keys($payload)).' ('.(isset($r['error_code']) ? $r['error_code'] : '?').')');
    }
  }

  function logout() {
    if ($this->stok !== null) {
      try {
        $this->ds(array('method' => 'do', 'system' => array('logout' => 'null')));
      } catch (Exception $e) {
      }
      $this->stok = null;
    }
    $this->unlock();
  }
}

// The settings the Camera tab shows and changes, by the VIGI's own names (its image and OSD pages).
// Each: group, label, where (section, name, key; `profile` is the camera's day/night profile,
// `common` while Day/Night Switch is Unified), how it's shown (select with options, a level, or a
// switch), and in which lighting modes it applies (the image page writes the light's own keys for the
// infrared light, the white light, or both).
function vigi_fields() {
  $onoff = array('on' => 'On', 'off' => 'Off');
  $light = array('auto' => 'Auto', 'on' => 'Always On', 'off' => 'Always Off');
  return array(
    'night_vision_mode' => array('group' => 'Fill Light Settings', 'label' => 'Lighting Mode', 'at' => array('image', 'switch', 'night_vision_mode'),
      'type' => 'select', 'options' => array('inf_night_vision' => 'Infrared Lighting', 'md_night_vision' => 'Motion-triggered Color', 'wtl_night_vision' => 'White Light Illumination')),
    'inf_type' => array('group' => 'Fill Light Settings', 'label' => 'Infrared Light', 'at' => array('image', 'profile', 'inf_type'),
      'type' => 'select', 'options' => $light, 'modes' => array('inf_night_vision', 'md_night_vision'), 'also' => array('md_night_vision' => 'wtl_type')),
    'wtl_type' => array('group' => 'Fill Light Settings', 'label' => 'White Light', 'at' => array('image', 'profile', 'wtl_type'),
      'type' => 'select', 'options' => $light, 'modes' => array('wtl_night_vision')),
    'smartwtl' => array('group' => 'Fill Light Settings', 'label' => 'White Light Intensity', 'at' => array('image', 'profile', 'smartwtl'),
      'type' => 'select', 'options' => array('auto_wtl' => 'Smart White Light-Standard', 'auto_wtl_ae' => 'Smart White Light-Soft', 'manual' => 'Manual'),
      'modes' => array('md_night_vision', 'wtl_night_vision')),
    'smartwtl_digital_level' => array('group' => 'Fill Light Settings', 'label' => 'Intensity Level', 'at' => array('image', 'profile', 'smartwtl_digital_level'),
      'type' => 'level', 'min' => 1, 'max' => 100, 'modes' => array('md_night_vision', 'wtl_night_vision')),
    'switch_mode' => array('group' => 'Day/Night', 'label' => 'Day/Night Switch', 'at' => array('image', 'switch', 'switch_mode'),
      'type' => 'select', 'options' => array('common' => 'Unified', 'auto_switch' => 'Auto')),
    'inf_sensitivity' => array('group' => 'Day/Night', 'label' => 'Sensitivity', 'at' => array('image', 'profile', 'inf_sensitivity'),
      'type' => 'select', 'options' => array_combine(array('0', '1', '2', '3', '4', '5', '6', '7'), array('0', '1', '2', '3', '4', '5', '6', '7')),
      'modes' => array('inf_night_vision', 'md_night_vision'), 'also' => array('md_night_vision' => 'wtl_sensitivity')),
    'wtl_sensitivity' => array('group' => 'Day/Night', 'label' => 'Sensitivity', 'at' => array('image', 'profile', 'wtl_sensitivity'),
      'type' => 'select', 'options' => array_combine(array('0', '1', '2', '3', '4', '5', '6', '7'), array('0', '1', '2', '3', '4', '5', '6', '7')),
      'modes' => array('wtl_night_vision')),
    'inf_delay' => array('group' => 'Day/Night', 'label' => 'Delayed Switch (seconds)', 'at' => array('image', 'profile', 'inf_delay'),
      'type' => 'level', 'min' => 1, 'max' => 60, 'modes' => array('inf_night_vision', 'md_night_vision'), 'also' => array('md_night_vision' => 'wtl_delay')),
    'wtl_delay' => array('group' => 'Day/Night', 'label' => 'Delayed Switch (seconds)', 'at' => array('image', 'profile', 'wtl_delay'),
      'type' => 'level', 'min' => 1, 'max' => 60, 'modes' => array('wtl_night_vision')),
    'luma' => array('group' => 'Picture', 'label' => 'Brightness', 'at' => array('image', 'profile', 'luma'), 'type' => 'level', 'min' => 0, 'max' => 100),
    'contrast' => array('group' => 'Picture', 'label' => 'Contrast', 'at' => array('image', 'profile', 'contrast'), 'type' => 'level', 'min' => 0, 'max' => 100),
    'saturation' => array('group' => 'Picture', 'label' => 'Saturation', 'at' => array('image', 'profile', 'saturation'), 'type' => 'level', 'min' => 0, 'max' => 100),
    'sharpness' => array('group' => 'Picture', 'label' => 'Sharpness', 'at' => array('image', 'profile', 'sharpness'), 'type' => 'level', 'min' => 0, 'max' => 100),
    'wide_dynamic' => array('group' => 'Picture', 'label' => 'WDR', 'at' => array('image', 'profile', 'wide_dynamic'), 'type' => 'select', 'options' => $onoff),
    'high_light_compensation' => array('group' => 'Picture', 'label' => 'HLC', 'at' => array('image', 'profile', 'high_light_compensation'), 'type' => 'select', 'options' => $onoff),
    'dehaze' => array('group' => 'Picture', 'label' => 'Defog Mode', 'at' => array('image', 'profile', 'dehaze'), 'type' => 'select', 'options' => $onoff),
    'wb_type' => array('group' => 'Picture', 'label' => 'White Balance', 'at' => array('image', 'profile', 'wb_type'), 'type' => 'select',
      'options' => array('auto' => 'Auto', 'day_light' => 'Daylight', 'nature' => 'Natural Light', 'incandescent' => 'Incandescent', 'warm' => 'Warm light')),
    'auto_exp_antiflicker' => array('group' => 'Picture', 'label' => 'Anti-flicker', 'at' => array('image', 'profile', 'auto_exp_antiflicker'), 'type' => 'select', 'options' => $onoff),
    'flip_type' => array('group' => 'Picture', 'label' => 'Mirror', 'at' => array('image', 'switch', 'flip_type'), 'type' => 'select',
      'options' => array('off' => 'Off', 'left_and_right' => 'Left-Right', 'up_and_down' => 'Up-Down', 'center' => 'Center')),
    'osd_date' => array('group' => 'On-screen text', 'label' => 'Date', 'at' => array('OSD', 'date', 'enabled'), 'type' => 'switch'),
    'osd_week' => array('group' => 'On-screen text', 'label' => 'Week', 'at' => array('OSD', 'week', 'enabled'), 'type' => 'switch'),
    'osd_label_1' => array('group' => 'On-screen text', 'label' => 'Channel Name', 'at' => array('OSD', 'label_info_1', 'enabled'), 'type' => 'switch'),
    'osd_label_2' => array('group' => 'On-screen text', 'label' => 'Custom', 'at' => array('OSD', 'label_info_2', 'enabled'), 'type' => 'switch'),
    'osd_label_3' => array('group' => 'On-screen text', 'label' => 'Custom', 'at' => array('OSD', 'label_info_3', 'enabled'), 'type' => 'switch'),
  );
}

function vigi_value($raw, $at, $profile) {
  $name = $at[1] == 'profile' ? $profile : $at[1];
  if (!isset($raw[$at[0]][$name]) or !array_key_exists($at[2], $raw[$at[0]][$name])) return null;
  return rawurldecode((string)$raw[$at[0]][$name][$at[2]]);
}

// Everything the tab shows, in one login: the editable settings (with their options), and camera
// setup's, read only.
function vigi_read($camera, $username, $password) {
  $cam = new VigiCamera($camera['address']);
  $cam->login($username, $password);
  try {
    $raw = array('image' => $cam->get('image', array('switch', 'common')));
    $osd = $cam->get('OSD', array('date', 'week'));
    foreach ($cam->getTable('OSD', 'label_info') as $row) {
      foreach ($row as $k => $v) $osd[$k] = $v;
    }
    $raw['OSD'] = $osd;
    $info = $cam->get('device_info', array('basic_info'));
    $video = $cam->get('video', array('main', 'minor'));
    $system = $cam->get('system', array('basic', 'dst_manual', 'ntp'));
    $protocol = $cam->get('protocol', array('wan', 'static'));
  } finally {
    $cam->logout();
  }
  return vigi_view($raw, $info, $video, $system, $protocol);
}

function vigi_view($raw, $info, $video, $system, $protocol) {
  $profile = isset($raw['image']['switch']['switch_mode']) ? $raw['image']['switch']['switch_mode'] : 'common';
  $mode = vigi_value($raw, array('image', 'switch', 'night_vision_mode'), $profile);
  // Only the Unified profile is edited here; Auto keeps separate day and night settings.
  $editable = ($profile == 'common');
  $fields = array();
  foreach (vigi_fields() as $id => $f) {
    $v = vigi_value($raw, $f['at'], 'common');
    if ($v === null) continue;  // a setting this camera hasn't got
    if (isset($f['modes']) and !in_array($mode, $f['modes'], true)) continue;
    $out = array('id' => $id, 'group' => $f['group'], 'label' => $f['label'], 'type' => $f['type'], 'value' => $v,
      'editable' => $editable or $f['at'][1] != 'profile', 'modeSpecific' => isset($f['modes']));
    if ($f['type'] == 'select') {
      $out['options'] = $f['options'];
      if (!isset($f['options'][$v])) {
        $out['options'][$v] = $v;   // a value the tab doesn't offer (a schedule, say): shown, not changeable
        $out['editable'] = false;
      }
    } else if ($f['type'] == 'level') {
      $out['min'] = $f['min'];
      $out['max'] = $f['max'];
    } else if ($f['type'] == 'switch') {
      if (substr($id, 0, 10) == 'osd_label_' and isset($raw['OSD'][$f['at'][1]]['text'])) {
        $out['text'] = rawurldecode($raw['OSD'][$f['at'][1]]['text']);
      }
    }
    $fields[] = $out;
  }
  $b = isset($info['basic_info']) ? $info['basic_info'] : array();
  $m = isset($video['main']) ? $video['main'] : array();
  $s = isset($video['minor']) ? $video['minor'] : array();
  $fr = function ($v) { return intval($v) > 65536 ? (intval($v) - 65536).' fps' : $v; };
  $u = function ($x) { return rawurldecode((string)$x); };
  return array(
    'camera' => array('model' => $u(@$b['device_model']), 'firmware' => $u(@$b['sw_version']), 'mac' => $u(@$b['mac'])),
    'profile' => $profile,
    'editable' => $editable,
    'fields' => $fields,
    'setup' => array(
      'Main stream' => sprintf('%s · %s · %s · %s to %s kb/s · H.264+ %s', $u(@$m['encode_type']), str_replace('*', '×', $u(@$m['resolution'])),
        $fr(@$m['frame_rate']), strtoupper($u(@$m['bitrate_type'])), $u(@$m['bitrate']), (@$m['smart_codec'] == 'on') ? 'On' : 'Off'),
      'Substream' => sprintf('%s · %s · %s · %s kb/s', $u(@$s['encode_type']), str_replace('*', '×', $u(@$s['resolution'])), $fr(@$s['frame_rate']), $u(@$s['bitrate'])),
      'Time' => sprintf('%s (%s) · DST %s · NTP %s', $u(@$system['basic']['timezone']), $u(@$system['basic']['zone_id']),
        (@$system['dst_manual']['enabled'] == 'on') ? 'on' : 'off', $u(@$system['ntp']['server'])),
      'Network' => sprintf('%s · %s', $u(@$protocol['static']['ipaddr']), (@$protocol['wan']['wan_type'] == 'static') ? 'static' : 'from DHCP'),
    ),
  );
}

// The calls for a Save: only what differs from what the camera has, in the groups the camera's own
// pages send them in (image.switch; the profile's image settings; each on-screen text with its own
// place and words), each value checked against the field's own choices.
function vigi_payloads($raw, $changes) {
  $fields = vigi_fields();
  $profile = isset($raw['image']['switch']['switch_mode']) ? $raw['image']['switch']['switch_mode'] : 'common';
  $mode = isset($changes['night_vision_mode']) ? $changes['night_vision_mode'] : vigi_value($raw, array('image', 'switch', 'night_vision_mode'), $profile);
  $switch = array();
  $image = array();
  $osd = array();
  $what = array();
  foreach ($changes as $id => $value) {
    if (!isset($fields[$id])) throw new VigiError("no setting $id");
    $f = $fields[$id];
    $value = (string)$value;
    if ($f['type'] == 'select' and !isset($f['options'][$value])) throw new VigiError("$value isn't a choice for ".$f['label']);
    if ($f['type'] == 'level' and (!ctype_digit($value) or intval($value) < $f['min'] or intval($value) > $f['max'])) {
      throw new VigiError($f['label'].' is '.$f['min'].' to '.$f['max']);
    }
    if ($f['type'] == 'switch' and $value != 'on' and $value != 'off') throw new VigiError($f['label'].' is on or off');
    if ($f['at'][1] == 'profile' and $profile != 'common') throw new VigiError($f['label'].' can be changed here only with Day/Night Switch on Unified');
    $now = vigi_value($raw, $f['at'], 'common');
    if ($now === null) throw new VigiError('the camera has no '.$f['label']);
    if ($now === $value) continue;
    $what[] = array($f['label'], $now, $value, $id);
    if ($f['at'][0] == 'OSD') {
      $sec = $f['at'][1];
      $row = array();
      foreach ($raw['OSD'][$sec] as $k => $v) $row[$k] = is_string($v) ? rawurldecode($v) : $v;
      $row['enabled'] = $value;
      $osd[$sec] = $row;
    } else if ($f['at'][1] == 'switch') {
      $switch[$f['at'][2]] = $value;
    } else {
      $image[$f['at'][2]] = $value;
      if (isset($f['also'][$mode])) $image[$f['also'][$mode]] = $value;
    }
  }
  $calls = array();
  if ($switch) $calls[] = array('image' => array('switch' => $switch));
  if ($image) $calls[] = array('image' => array('common' => $image));
  if ($osd) $calls[] = array('OSD' => $osd);
  return array($calls, $what);
}

// Save: in one login, read what the camera has, send what differs, and read it back.
function vigi_save($camera, $username, $password, $changes) {
  $cam = new VigiCamera($camera['address']);
  $cam->login($username, $password);
  try {
    $read = function () use ($cam) {
      $raw = array('image' => $cam->get('image', array('switch', 'common')));
      $osd = $cam->get('OSD', array('date', 'week'));
      foreach ($cam->getTable('OSD', 'label_info') as $row) {
        foreach ($row as $k => $v) $osd[$k] = $v;
      }
      $raw['OSD'] = $osd;
      return $raw;
    };
    $raw = $read();
    list($calls, $what) = vigi_payloads($raw, $changes);
    foreach ($calls as $call) $cam->set($call);
    $after = $calls ? $read() : $raw;
    $notTaken = array();
    foreach ($what as $w) {
      $f = vigi_fields()[$w[3]];
      if (vigi_value($after, $f['at'], 'common') !== $w[2]) $notTaken[] = $f['label'];
    }
    $info = $cam->get('device_info', array('basic_info'));
    $video = $cam->get('video', array('main', 'minor'));
    $system = $cam->get('system', array('basic', 'dst_manual', 'ntp'));
    $protocol = $cam->get('protocol', array('wan', 'static'));
  } finally {
    $cam->logout();
  }
  $view = vigi_view($after, $info, $video, $system, $protocol);
  $view['changed'] = $what;
  $view['notTaken'] = $notTaken;
  return $view;
}

// A change as words, for ZoneMinder's Log and the page: "Lighting Mode Motion-triggered Color →
// Infrared Lighting".
function vigi_describe($what) {
  $fields = vigi_fields();
  $out = array();
  foreach ($what as $w) {
    $f = $fields[$w[3]];
    $show = function ($v) use ($f) {
      if ($f['type'] == 'select' and isset($f['options'][$v])) return $f['options'][$v];
      if ($f['type'] == 'switch') return $v == 'on' ? 'on' : 'off';
      return $v;
    };
    $out[] = $f['label'].' '.$show($w[1]).' → '.$show($w[2]);
  }
  return implode('; ', $out);
}
