<?php
//
// zindello-cluster: alert rules, shared by the Event Server page (skins/classic/views/eventserver.php,
// includes/actions/eventserver.php) and the API (api/app/Controller/AlertRulesController.php)
// (external-security.zindello.com.au/docs/alert-rules.md; cluster-docs O431-O438).
//
// Who gets a push: per user, per camera, per type, in three tables in the `detector` database beside
// ZoneMinder's, on ZoneMinder's own connection:
//   alert_rules  (user_id, monitor_id, alerts, labels)  one row per user per camera once set;
//                (0, 0) is the default, for a user or camera with no row of its own
//   alert_users  (user_id, alerts)                      the switch for all of a user's alerts; no row: on
//   alert_phones (token_hash, username, ...)            the paired phones, kept by the event server
//
// Everyone may see and change their own; a user who may edit System settings, anyone's. Each caller
// checks that with alert_rules_may_manage() before anything else. Every query has bound parameters;
// the database's name is fixed here, never taken from a request.
//

require_once(__DIR__.'/database.php');
require_once(__DIR__.'/User.php');
require_once(__DIR__.'/Monitor.php');

if (!defined('ZM_ALERT_RULES_DB')) define('ZM_ALERT_RULES_DB', 'detector');

// The page's eight types, and the COCO names each stands for. "animal" is COCO's ten.
function alert_rules_types() {
  return array(
    'person' => array('person'),
    'car' => array('car'),
    'truck' => array('truck'),
    'bus' => array('bus'),
    'motorcycle' => array('motorcycle'),
    'bicycle' => array('bicycle'),
    'boat' => array('boat'),
    'animal' => array('bird', 'cat', 'dog', 'horse', 'sheep', 'cow', 'elephant', 'bear', 'zebra', 'giraffe'),
  );
}

// The 17 COCO names a rule may hold, in the order they're stored.
function alert_rules_labels() {
  $labels = array();
  foreach (alert_rules_types() as $names) $labels = array_merge($labels, $names);
  return $labels;
}

// What a user who has set nothing gets when there's no default row either: today's pushes.
function alert_rules_builtin_default() {
  return array('alerts' => true, 'labels' => array('person', 'car', 'truck', 'bus', 'motorcycle', 'boat'));
}

function alert_rules_table($name) {
  return '`'.ZM_ALERT_RULES_DB.'`.`'.$name.'`';
}

// The three tables are there (the chart's seed makes them). Without them nothing here is used.
function alert_rules_ready() {
  $n = dbFetchOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=? '.
    "AND table_name IN ('alert_rules','alert_users','alert_phones')", 'n', array(ZM_ALERT_RULES_DB));
  return intval($n) == 3;
}

// The signed-in user, when there is a real one: rules are per ZoneMinder user, so with sign-in off
// (ZoneMinder's stand-in user, Id 0) there is nobody to keep them for.
function alert_rules_caller() {
  global $user;
  if (!ZM_OPT_USE_AUTH or !$user or !($user instanceof ZM\User) or intval($user->Id()) <= 0) return null;
  return $user;
}

function alert_rules_is_admin() {
  return alert_rules_caller() && canEdit('System');
}

// Anyone their own; a user who may edit System settings, anyone's.
function alert_rules_may_manage($target_id) {
  $caller = alert_rules_caller();
  if (!$caller) return false;
  if (intval($target_id) == intval($caller->Id())) return true;
  return canEdit('System');
}

function alert_rules_user($id) {
  $id = intval($id);
  if ($id <= 0) return null;
  $u = ZM\User::find_one(array('Id' => $id));
  return ($u and $u->Id()) ? $u : null;
}

// Every ZoneMinder user, for the chooser: Id => Username.
function alert_rules_users() {
  $users = array();
  foreach (dbFetchAll('SELECT Id, Username FROM Users ORDER BY Username ASC') as $row) {
    $users[intval($row['Id'])] = $row['Username'];
  }
  return $users;
}

// The cameras $target may view, as ZoneMinder decides it (User::viewableMonitorIds): Id => Name,
// in the console's order.
function alert_rules_cameras($target) {
  $viewable = array_flip(array_map('intval', $target->viewableMonitorIds()));
  $cameras = array();
  foreach (dbFetchAll('SELECT Id, Name FROM Monitors WHERE Deleted=0 ORDER BY Sequence ASC, Name ASC') as $row) {
    if (isset($viewable[intval($row['Id'])])) $cameras[intval($row['Id'])] = $row['Name'];
  }
  return $cameras;
}

function alert_rules_camera_exists($mid) {
  return intval(dbFetchOne('SELECT COUNT(*) AS n FROM Monitors WHERE Id=? AND Deleted=0', 'n', array(intval($mid)))) > 0;
}

// Labels as stored: only the 17 names, each once, in their order.
function alert_rules_normalise($labels) {
  $has = array();
  foreach ((array)$labels as $l) if (is_string($l)) $has[$l] = true;
  $out = array();
  foreach (alert_rules_labels() as $l) if (isset($has[$l])) $out[] = $l;
  return $out;
}

function alert_rules_row($row) {
  $labels = json_decode($row['labels'], true);
  return array('alerts' => (bool)$row['alerts'], 'labels' => alert_rules_normalise(is_array($labels) ? $labels : array()));
}

function alert_rules_default() {
  $row = dbFetchOne('SELECT alerts, labels FROM '.alert_rules_table('alert_rules').' WHERE user_id=0 AND monitor_id=0', false, array());
  return $row ? alert_rules_row($row) : alert_rules_builtin_default();
}

// The user's own rows: monitor_id => array(alerts, labels).
function alert_rules_rows($uid) {
  $rows = array();
  foreach (dbFetchAll('SELECT monitor_id, alerts, labels FROM '.alert_rules_table('alert_rules').' WHERE user_id=? AND monitor_id<>0', false, array(intval($uid))) as $row) {
    $rows[intval($row['monitor_id'])] = alert_rules_row($row);
  }
  return $rows;
}

function alert_rules_user_alerts($uid) {
  $row = dbFetchOne('SELECT alerts FROM '.alert_rules_table('alert_users').' WHERE user_id=?', false, array(intval($uid)));
  return $row ? (bool)$row['alerts'] : true;
}

// Everything about $target's rules: the switch, the default, and each camera they may view with
// its switch and types, and whether it's their own row ('set') or the default.
function alert_rules_get($target) {
  $default = alert_rules_default();
  $rows = alert_rules_rows($target->Id());
  $cameras = array();
  foreach (alert_rules_cameras($target) as $mid => $name) {
    $r = isset($rows[$mid]) ? $rows[$mid] : $default;
    $cameras[$mid] = array('name' => $name, 'alerts' => $r['alerts'], 'labels' => $r['labels'], 'set' => isset($rows[$mid]));
  }
  return array(
    'user' => array('id' => intval($target->Id()), 'name' => $target->Username()),
    'alerts' => alert_rules_user_alerts($target->Id()),
    'default' => $default,
    'cameras' => $cameras,
  );
}

// A switch as sent: true/false, 1/0, or their words. null: not a switch.
function alert_rules_bool($v) {
  if (is_bool($v)) return $v;
  if (is_int($v) and ($v === 0 or $v === 1)) return $v === 1;
  if (is_string($v)) {
    $v = strtolower(trim($v));
    if (in_array($v, array('1', 'true', 'on', 'yes'), true)) return true;
    if (in_array($v, array('0', 'false', 'off', 'no'), true)) return false;
  }
  return null;
}

// Change $target's rules. $change has any of:
//   'reset'   => true: back to the default (their rows and their switch deleted), done first
//   'alerts'  => bool: the switch for all their alerts
//   'cameras' => array(<camera id> => array('alerts' => bool, 'labels' => array of COCO names))
// A camera's missing 'alerts' or 'labels' keeps what it has now. A camera is written only when it
// differs from the default or already has a row, so an untouched camera keeps following the
// default. Everything is checked before anything is written.
// Returns array(<HTTP status>, <message for people>): 200 when done.
function alert_rules_change($target, $change) {
  global $dbConn;
  if (!is_array($change)) return array(400, 'Nothing to change.');
  $uid = intval($target->Id());

  $reset = false;
  if (array_key_exists('reset', $change)) {
    $reset = alert_rules_bool($change['reset']);
    if ($reset === null) return array(400, 'Back to the default must be true or false.');
  }
  $alerts = null;
  if (array_key_exists('alerts', $change)) {
    $alerts = alert_rules_bool($change['alerts']);
    if ($alerts === null) return array(400, 'Alerts must be on or off.');
  }

  $writes = array();
  if (array_key_exists('cameras', $change)) {
    if (!is_array($change['cameras'])) return array(400, 'Cameras must be a list by camera.');
    $default = alert_rules_default();
    $rows = $reset ? array() : alert_rules_rows($uid);
    $viewable = alert_rules_cameras($target);
    $allowed = array_flip(alert_rules_labels());
    foreach ($change['cameras'] as $mid => $c) {
      if (!preg_match('/^[1-9][0-9]{0,9}$/', (string)$mid)) return array(400, 'A camera was named that isn\'t a camera.');
      $mid = intval($mid);
      if (!isset($viewable[$mid])) {
        if (!alert_rules_camera_exists($mid)) return array(404, 'There is no camera '.$mid.'.');
        return array(400, $target->Username().' may not view camera '.$mid.'.');
      }
      if (!is_array($c)) return array(400, 'Each camera needs its switch and types.');
      $now = isset($rows[$mid]) ? $rows[$mid] : $default;
      $on = $now['alerts'];
      if (array_key_exists('alerts', $c)) {
        $on = alert_rules_bool($c['alerts']);
        if ($on === null) return array(400, 'A camera\'s alerts must be on or off.');
      }
      $labels = $now['labels'];
      if (array_key_exists('labels', $c)) {
        if (!is_array($c['labels'])) return array(400, 'A camera\'s types must be a list.');
        foreach ($c['labels'] as $l) {
          if (!is_string($l) or !isset($allowed[$l])) {
            return array(400, 'Types must be from: '.implode(', ', alert_rules_labels()).'.');
          }
        }
        $labels = alert_rules_normalise($c['labels']);
      }
      $same = ($on == $default['alerts']) && ($labels == alert_rules_normalise($default['labels']));
      if ($same and !isset($rows[$mid])) continue;
      $writes[$mid] = array($on, $labels);
    }
  }

  $dbConn->beginTransaction();
  $ok = true;
  if ($reset) {
    $ok = $ok && dbQuery('DELETE FROM '.alert_rules_table('alert_rules').' WHERE user_id=? AND monitor_id<>0', array($uid)) !== null;
    $ok = $ok && dbQuery('DELETE FROM '.alert_rules_table('alert_users').' WHERE user_id=?', array($uid)) !== null;
  }
  if ($ok and $alerts !== null) {
    $ok = dbQuery('INSERT INTO '.alert_rules_table('alert_users').' (user_id, alerts) VALUES (?, ?) '.
      'ON DUPLICATE KEY UPDATE alerts=VALUES(alerts)', array($uid, $alerts ? 1 : 0)) !== null;
  }
  foreach ($writes as $mid => $w) {
    if (!$ok) break;
    $ok = dbQuery('INSERT INTO '.alert_rules_table('alert_rules').' (user_id, monitor_id, alerts, labels) VALUES (?, ?, ?, ?) '.
      'ON DUPLICATE KEY UPDATE alerts=VALUES(alerts), labels=VALUES(labels)',
      array($uid, $mid, $w[0] ? 1 : 0, json_encode($w[1]))) !== null;
  }
  if (!$ok) {
    $dbConn->rollBack();
    return array(500, 'The rules couldn\'t be saved. ZoneMinder\'s log has the reason.');
  }
  $dbConn->commit();
  return array(200, $reset ? 'Back to the default.' : 'Saved.');
}

// Phones. A phone belongs to the ZoneMinder user whose Username it signed in with, compared without
// regard to case (strtolower, as ASCII: this PHP has no mbstring); one with no name, or a name that
// is no ZoneMinder user's, belongs to nobody. The table is a handful of rows, so it's read whole and
// sorted out here, which keeps one comparison for showing and for removing.
function alert_rules_phone_columns() {
  return 'token_hash, username, platform, app_version, profile, first_seen, last_seen, last_push, remove';
}

function alert_rules_all_phones() {
  return dbFetchAll('SELECT '.alert_rules_phone_columns().' FROM '.alert_rules_table('alert_phones').
    ' ORDER BY first_seen ASC, token_hash ASC');
}

function alert_rules_phone_of($phone, $username) {
  return $phone['username'] !== null && strtolower($phone['username']) === strtolower($username);
}

function alert_rules_phones($username) {
  $phones = array();
  foreach (alert_rules_all_phones() as $row) {
    if (alert_rules_phone_of($row, $username)) $phones[] = $row;
  }
  return $phones;
}

function alert_rules_unowned_phones() {
  $names = array();
  foreach (dbFetchAll('SELECT Username FROM Users') as $row) $names[strtolower($row['Username'])] = true;
  $phones = array();
  foreach (alert_rules_all_phones() as $row) {
    if ($row['username'] === null or !isset($names[strtolower($row['username'])])) $phones[] = $row;
  }
  return $phones;
}

function alert_rules_phone($token_hash) {
  if (!is_string($token_hash) or !preg_match('/^[0-9a-f]{64}$/', strtolower($token_hash))) return null;
  return dbFetchOne('SELECT '.alert_rules_phone_columns().' FROM '.alert_rules_table('alert_phones').' WHERE token_hash=?',
    false, array(strtolower($token_hash))) ?: null;
}

// The caller may remove a phone that signed in as them; one who may edit System settings, any.
function alert_rules_may_remove_phone($phone) {
  $caller = alert_rules_caller();
  if (!$caller) return false;
  if (canEdit('System')) return true;
  return alert_rules_phone_of($phone, $caller->Username());
}

// What the page names a phone by, so its hash is never on the page; the action finds the phone by
// the same sum (SHA2(CONCAT('phone:', token_hash), 256)).
function alert_rules_phone_ref($phone) {
  return hash('sha256', 'phone:'.$phone['token_hash']);
}

// Marks it; the event server forgets the phone within about 30 seconds and deletes the row.
function alert_rules_remove_phone($phone) {
  return dbQuery('UPDATE '.alert_rules_table('alert_phones').' SET remove=1 WHERE token_hash=?', array($phone['token_hash'])) !== null;
}

// A stored UTC DATETIME as seconds since the epoch, or null.
function alert_rules_utc($s) {
  if ($s === null or $s === '') return null;
  $d = DateTime::createFromFormat('Y-m-d H:i:s', substr($s, 0, 19), new DateTimeZone('UTC'));
  return $d ? $d->getTimestamp() : null;
}
?>
