<?php
//
// zindello-cluster: the Alerts list (cluster-docs docs/zoneminder-ui.md, section 1; O482-O498).
// Shared by the events list (ajax/events.php) and the API's events index
// (api/app/Controller/EventsController.php).
//
// An event is one of the signed-in user's alerts when the event server tagged it with a type that
// user has switched on for its camera now: their own row for the camera on the Event Server page,
// else the default; none at all while their switch for all alerts is off. The event server (our
// fork, as the user ZM_ALERTS_TAGGER) tags each event with every type that raised or joined live
// detection's alarm during it, whoever is told, so the list follows the rules as they are now,
// past events included (O489). Tags anyone else added don't count, and nor do the old detection
// hook's, from before ZM_ALERTS_SINCE (O490: not filled).
//
// Nothing is stored per user: the condition is worked out on each read. "Show: All Events" (the
// list's show=all, the API's show=all) leaves it off.
//
// With sign-in off, or without the alert rules' tables, there is nobody to have alerts: no
// condition, and the list is every event as upstream's.
//

require_once(__DIR__.'/alert_rules.php');

// The event server's own ZoneMinder user, whose tags count; the chart can set it in conf.d.
if (!defined('ZM_ALERTS_TAGGER')) define('ZM_ALERTS_TAGGER', 'notify');

// show=all asks for every event; anything else, or nothing, for the alerts.
function alerts_list_wanted($show) {
  return !(is_string($show) and strtolower(trim($show)) === 'all');
}

// The first moment the event server's tags are live detection's, as ZoneMinder writes its times
// (its own zone): ZM_ALERTS_SINCE if the chart sets it, else live detection's first alarm row,
// which is the same moment until retention deletes that row. null: no alarm row at all.
function alerts_list_since() {
  if (defined('ZM_ALERTS_SINCE') and ZM_ALERTS_SINCE !== '') {
    $t = strtotime(ZM_ALERTS_SINCE);
    return $t === false ? null : date('Y-m-d H:i:s', $t);
  }
  $db = ZM_ALERT_RULES_DB;
  $n = dbFetchOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=? AND table_name=?',
    'n', array($db, 'detector_alarms'));
  if (!intval($n)) return null;
  // The first per camera is the primary key's first row for it.
  $first = dbFetchOne('SELECT MIN(f) AS f FROM (SELECT MIN(raised_at) AS f FROM `'.$db.'`.detector_alarms GROUP BY monitor_id) AS m', 'f');
  if (!$first) return null;
  $t = new DateTime($first, new DateTimeZone('UTC'));
  $t->setTimezone(new DateTimeZone(date_default_timezone_get()));
  return $t->format('Y-m-d H:i:s');
}

// The SQL condition on an Events row (aliased $alias) for the signed-in user's alerts. null: no
// condition (sign-in off, or no rules tables). '0': nothing is an alert for them.
function alerts_list_condition($alias = 'E') {
  $caller = alert_rules_caller();
  if (!$caller or !alert_rules_ready()) return null;
  if (!alert_rules_user_alerts($caller->Id())) return '0';
  $since = alerts_list_since();
  if ($since === null) return '0';
  $tagger = dbFetchOne('SELECT Id FROM Users WHERE Username=?', 'Id', array(ZM_ALERTS_TAGGER));
  if (!$tagger) return '0';

  // Cameras with the same types share one test.
  $default = alert_rules_default();
  $rows = alert_rules_rows($caller->Id());
  $by_labels = array();
  foreach (array_keys(alert_rules_cameras($caller)) as $mid) {
    $r = isset($rows[$mid]) ? $rows[$mid] : $default;
    if (!$r['alerts'] or !count($r['labels'])) continue;
    $labels = $r['labels'];
    if (in_array('motorcycle', $labels, true)) $labels[] = 'motorbike';  // an older name, as for pushes
    $by_labels[implode(',', $labels)][] = intval($mid);
  }
  if (!count($by_labels)) return '0';

  $tests = array();
  foreach ($by_labels as $labels => $mids) {
    $tests[] = '('.$alias.'.MonitorId IN ('.implode(',', $mids).') AND EXISTS (SELECT 1 FROM Events_Tags AS AET'
      .' INNER JOIN Tags AS ATG ON ATG.Id = AET.TagId WHERE AET.EventId = '.$alias.'.Id AND AET.AssignedBy = '.intval($tagger)
      .' AND ATG.Name IN ('.implode(',', array_map('dbEscape', explode(',', $labels))).')))';
  }
  return '('.$alias.'.StartDateTime >= '.dbEscape($since).' AND ('.implode(' OR ', $tests).'))';
}
