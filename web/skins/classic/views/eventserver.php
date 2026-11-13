<?php
//
// zindello-cluster: the Event Server page, alert rules per user, per camera, per type
// (external-security.zindello.com.au/docs/alert-rules.md, "The page"; the owner, 14 November 2026:
// "Alert pushes based on per user, per camera, per detection type.", cluster-docs O431-O438).
//
// For the signed-in user: the switch for all their alerts, a row for each camera they may view (its
// switch and a tick for each type), Save and Back to the default, and their paired phones with
// Remove. A user who may edit System settings also chooses whose rules to show (another's only
// change with the "Change <name>'s rules" tick, checked again in includes/actions/eventserver.php)
// and sees the phones that belong to nobody. The rules and the permission checks are
// includes/alert_rules.php, shared with the API.
//

require_once('includes/alert_rules.php');

global $eventserver_message;
global $eventserver_error;
global $dateTimeFormatter;

$caller = alert_rules_caller();
$ready = $caller ? alert_rules_ready() : false;
$isAdmin = $caller ? alert_rules_is_admin() : false;

// Whose rules: your own, or, for a user who may edit System settings, whoever is chosen.
$target = $caller;
$refused = '';
if ($caller and isset($_REQUEST['uid']) and $_REQUEST['uid'] !== '' and intval($_REQUEST['uid']) != $caller->Id()) {
  if (!alert_rules_may_manage(intval($_REQUEST['uid']))) {
    $refused = 'You can only see your own alert rules.';
    $target = null;
  } else {
    $target = alert_rules_user(intval($_REQUEST['uid']));
    if (!$target) {
      $refused = 'There is no such user.';
    }
  }
}
$other = ($target and $caller and $target->Id() != $caller->Id());

function eventserver_time($s, $none) {
  global $dateTimeFormatter;
  $t = alert_rules_utc($s);
  return $t === null ? $none : $dateTimeFormatter->format($t);
}

function eventserver_platform($p) {
  $names = array('ios' => 'iPhone or iPad', 'android' => 'Android');
  return isset($names[strtolower($p)]) ? $names[strtolower($p)] : $p;
}

// The paired phones table; $owner: show who each phone signed in as (the phones of nobody).
function eventserver_phones($phones, $uid, $owner) {
  if (!count($phones)) {
    echo '<p class="text-muted">'.validHtmlStr($owner ? 'None.' : 'No phone has paired yet. A phone shows here once its app has signed in to this server.').'</p>';
    return;
  }
?>
  <table class="table table-sm table-striped eventServerPhones">
    <thead>
      <tr>
        <th>Phone</th>
<?php if ($owner) { ?>
        <th>Signed in as</th>
<?php } ?>
        <th>App version</th>
        <th>Name in the app</th>
        <th>Paired</th>
        <th>Last connected</th>
        <th>Last alert</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($phones as $p) { ?>
      <tr>
        <td><?php echo validHtmlStr(eventserver_platform($p['platform'])) ?></td>
<?php if ($owner) { ?>
        <td><?php echo $p['username'] === null ? 'Not recorded' : validHtmlStr($p['username']).' <span class="text-muted">(not a ZoneMinder user)</span>' ?></td>
<?php } ?>
        <td><?php echo validHtmlStr($p['app_version']) ?></td>
        <td><?php echo $p['profile'] === null ? 'Not recorded' : validHtmlStr($p['profile']) ?></td>
        <td><?php echo validHtmlStr(eventserver_time($p['first_seen'], 'Not recorded')) ?></td>
        <td><?php echo validHtmlStr(eventserver_time($p['last_seen'], 'Not recorded')) ?></td>
        <td><?php echo validHtmlStr(eventserver_time($p['last_push'], 'None yet')) ?></td>
        <td>
<?php if ($p['remove']) { ?>
          <span class="text-muted">Being removed</span>
<?php } else { ?>
          <form method="post" action="?view=eventserver&amp;uid=<?php echo intval($uid) ?>" class="m-0">
            <input type="hidden" name="view" value="eventserver"/>
            <input type="hidden" name="uid" value="<?php echo intval($uid) ?>"/>
            <input type="hidden" name="phone" value="<?php echo alert_rules_phone_ref($p) ?>"/>
            <button type="submit" name="action" value="removePhone" class="btn btn-sm btn-normal">Remove</button>
          </form>
<?php } ?>
        </td>
      </tr>
<?php } ?>
    </tbody>
  </table>
<?php
}

xhtmlHeaders(__FILE__, 'Event Server');
getBodyTopHTML();
echo getNavBarHTML();
?>
<div id="page" class="container-fluid">
  <h2>Event Server</h2>
  <p class="form-text">Which alerts reach your phones: per camera, and for which things found in its pictures. The phone app's own switches still apply as well.</p>
<?php
if ($eventserver_message) echo '<div class="alert alert-success" role="status">'.validHtmlStr($eventserver_message).'</div>'.PHP_EOL;
if ($eventserver_error) echo '<div class="alert alert-danger" role="alert">'.validHtmlStr($eventserver_error).'</div>'.PHP_EOL;

if (!$caller) {
  echo '<div class="alert alert-warning">Alert rules are per user, so they need ZoneMinder\'s sign-in, which is off on this server.</div>';
} else if (!$ready) {
  echo '<div class="alert alert-warning">Alert rules aren\'t set up on this server yet, so every paired phone gets every alert.</div>';
} else if (!$target) {
  echo '<div class="alert alert-danger" role="alert">'.validHtmlStr($refused).'</div>';
} else {
  $rules = alert_rules_get($target);
  $types = alert_rules_types();
  $name = validHtmlStr($target->Username());
  $uid = intval($target->Id());

  if ($isAdmin) {
?>
  <form method="get" action="" class="form-inline mb-3" id="eventServerUserForm">
    <input type="hidden" name="view" value="eventserver"/>
    <label for="eventServerUser" class="mr-2">Whose rules</label>
    <select name="uid" id="eventServerUser" class="form-control form-control-sm mr-2">
<?php
    foreach (alert_rules_users() as $id => $username) {
      echo '<option value="'.intval($id).'"'.($id == $uid ? ' selected="selected"' : '').'>'.validHtmlStr($username).
        ($id == $caller->Id() ? ' (you)' : '').'</option>'.PHP_EOL;
    }
?>
    </select>
    <button type="submit" class="btn btn-sm btn-normal">Show</button>
  </form>
<?php
  }
  if ($other) {
    echo '<div class="alert alert-warning" role="alert"><strong>These are '.$name.'\'s rules, not yours.</strong> '.
      'The cameras listed are the ones '.$name.' may view. Save and Back to the default change '.$name.'\'s rules only with '.
      '"Change '.$name.'\'s rules" ticked.</div>'.PHP_EOL;
  }
  $defaultTypes = array();
  foreach ($types as $type => $labels) {
    if (count(array_intersect($labels, $rules['default']['labels']))) $defaultTypes[] = $type;
  }
?>
  <form method="post" action="?view=eventserver&amp;uid=<?php echo $uid ?>" id="eventServerForm">
    <input type="hidden" name="view" value="eventserver"/>
    <input type="hidden" name="uid" value="<?php echo $uid ?>"/>
    <div class="form-check mb-3">
      <input type="checkbox" class="form-check-input" name="alerts" value="1" id="eventServerAlerts"<?php echo $rules['alerts'] ? ' checked="checked"' : '' ?>/>
      <label class="form-check-label" for="eventServerAlerts"><strong><?php echo $other ? 'Send alerts to '.$name.'\'s phones' : 'Send alerts to my phones' ?></strong> (off: none at all, whatever the cameras below say)</label>
    </div>
    <p class="form-text">A camera that hasn't been set follows the default: <?php
      echo $rules['default']['alerts'] ? 'alerts on, for '.validHtmlStr(count($defaultTypes) ? implode(', ', $defaultTypes) : 'nothing') : 'alerts off' ?>.
      An animal is any of a bird, cat, dog, horse, sheep, cow, elephant, bear, zebra or giraffe.</p>
    <table id="eventServerTable" class="table table-sm table-striped">
      <thead>
        <tr>
          <th>Camera</th>
          <th>Alerts</th>
          <th>For</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
<?php
  if (!count($rules['cameras'])) {
    echo '<tr><td colspan="4" class="text-muted">'.($other ? $name.' may' : 'You may').' not view any camera.</td></tr>'.PHP_EOL;
  }
  foreach ($rules['cameras'] as $mid => $c) {
    $field = 'camera['.intval($mid).']';
    $has = array_flip($c['labels']);
?>
        <tr>
          <td><?php echo validHtmlStr($c['name']) ?><input type="hidden" name="<?php echo $field ?>[shown]" value="1"/></td>
          <td><input type="checkbox" name="<?php echo $field ?>[alerts]" value="1" aria-label="Alerts from <?php echo validHtmlStr($c['name']) ?>"<?php echo $c['alerts'] ? ' checked="checked"' : '' ?>/></td>
          <td>
<?php
    foreach ($types as $type => $labels) {
      $ticked = false;
      foreach ($labels as $l) if (isset($has[$l])) $ticked = true;
      echo '            <label class="mr-2 text-nowrap"><input type="checkbox" name="'.$field.'[types][]" value="'.$type.'"'.
        ($ticked ? ' checked="checked"' : '').'/> '.$type.'</label>'.PHP_EOL;
    }
?>
          </td>
          <td class="text-muted"><?php echo $c['set'] ? '' : 'The default' ?></td>
        </tr>
<?php
  } // end foreach camera
?>
      </tbody>
    </table>
<?php if ($other) { ?>
    <div class="form-check mb-2">
      <input type="checkbox" class="form-check-input" name="changeOther" value="1" id="eventServerChangeOther"/>
      <label class="form-check-label" for="eventServerChangeOther">Change <?php echo $name ?>'s rules</label>
    </div>
<?php } ?>
    <button type="submit" name="action" value="save" class="btn btn-normal">Save</button>
    <button type="submit" name="action" value="reset" class="btn btn-normal">Back to the default</button>
  </form>

  <h3 class="mt-4"><?php echo $other ? $name.'\'s phones' : 'My phones' ?></h3>
  <p class="form-text">Phones paired with this server through the phone app. Remove stops alerts to it; if the app is still signed in, it pairs again the next time it's opened.</p>
<?php
  eventserver_phones(alert_rules_phones($target->Username()), $uid, false);

  if ($isAdmin) {
?>
  <h3 class="mt-4">Phones that belong to nobody</h3>
  <p class="form-text">Paired before alert rules and not opened since, or signed in as someone who is no longer a ZoneMinder user. They get the default until their app signs in again; one whose user has gone gets no alerts.</p>
<?php
    eventserver_phones(alert_rules_unowned_phones(), $uid, true);
  }
} // end if ready and a user
?>
</div>
<?php xhtmlFooter() ?>
