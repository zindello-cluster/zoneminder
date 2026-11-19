<?php
//
// zindello-cluster: the data of a camera's timeline (cluster-docs docs/zoneminder-ui.md, section 3;
// views/cameratimeline.php).
//
// ?request=cameratimeline&what=bar&mid=N&from=T&to=T&cols=C
//     the bar over [from, to) (seconds since the epoch) in C columns: for each, whether there's
//     recording, how many alarms started in it, and how many of the signed-in user's alerts
//     (includes/alerts_list.php). At two hours or less, also the alarms and alerts themselves.
// ?request=cameratimeline&what=sections&mid=N&from=T&to=T
//     the recordings (events) over [from, to), at most four hours: id, start and end, which the
//     player plays one after another.
//
// Behind ZoneMinder's sign-in and the camera's own view check, and Events: View. ZoneMinder writes
// an event's times in its own zone (whole seconds for the end; to the microsecond for the start
// once the chart's seed has widened the column); they are read as wall-clock microseconds and
// turned into seconds since the epoch here, with the zone's own changes (daylight saving), so no
// session time zone touches them. Live detection's rows are UTC.
//
ini_set('display_errors', '');

require_once('includes/Monitor.php');
require_once('includes/Event.php');
require_once('includes/alerts_list.php');

define('ZM_TIMELINE_DB', ZM_ALERT_RULES_DB);
define('ZM_TIMELINE_MAX_COLS', 4000);
define('ZM_TIMELINE_DETAIL', 2 * 3600);     // detail at two hours or less
define('ZM_TIMELINE_SECTIONS', 4 * 3600);   // sections for at most four hours at a time

// Wall-clock seconds in ZoneMinder's zone (as TIMESTAMPDIFF from 1970-01-01 gives them) to
// seconds since the epoch: the zone's offset at that moment, from its transitions.
class TimelineClock {
  private $transitions = array();
  function __construct($from, $to) {
    $tz = new DateTimeZone(date_default_timezone_get());
    foreach ($tz->getTransitions(intval($from) - 2 * 86400, intval($to) + 2 * 86400) as $t) {
      $this->transitions[] = array(intval($t['ts']), intval($t['offset']));
    }
  }
  function epoch($wall) {
    $offset = $this->transitions ? $this->transitions[0][1] : 0;
    foreach ($this->transitions as $t) {
      if ($t[0] <= $wall - $t[1]) $offset = $t[1]; else break;
    }
    return $wall - $offset;
  }
}

function timeline_local($epoch) {
  return date('Y-m-d H:i:s', intval(floor($epoch)));
}
function timeline_utc($epoch) {
  return gmdate('Y-m-d H:i:s', intval(floor($epoch)));
}

$what = isset($_REQUEST['what']) ? $_REQUEST['what'] : '';
$mid = isset($_REQUEST['mid']) ? validInt($_REQUEST['mid']) : 0;
$from = isset($_REQUEST['from']) ? floatval($_REQUEST['from']) : 0;
$to = isset($_REQUEST['to']) ? floatval($_REQUEST['to']) : 0;
$Monitor = $mid ? ZM\Monitor::find_one(array('Id' => $mid)) : null;
if (!$Monitor or !$Monitor->canView() or !canView('Events')) {
  ajaxError('Not found');
  return;
}
if (!($to > $from) or ($to - $from) > 40 * 86400) {
  ajaxError('from and to: a window of up to 40 days');
  return;
}
$now = microtime(true);
$clock = new TimelineClock($from, max($to, $now));
// The longest a section can be: none is longer than its camera's section length (and a minute).
$longest = max(600, intval($Monitor->SectionLength())) + 60;
$detector = intval(dbFetchOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=? AND table_name=?',
  'n', array(ZM_TIMELINE_DB, 'detector_alarms'))) > 0;

// The recordings over the window: [id, start, end, has video], oldest first. An event still being
// recorded ends now.
function timeline_sections($mid, $from, $to, $longest, $clock, $now) {
  $rows = dbFetchAll('SELECT Id, TIMESTAMPDIFF(MICROSECOND, \'1970-01-01 00:00:00\', StartDateTime) AS s, '.
    'TIMESTAMPDIFF(SECOND, \'1970-01-01 00:00:00\', EndDateTime) AS e, DefaultVideo FROM Events '.
    'WHERE MonitorId=? AND StartDateTime >= ? AND StartDateTime < ? ORDER BY StartDateTime',
    false, array($mid, timeline_local($from - $longest), timeline_local($to + 1)));
  $out = array();
  foreach ($rows as $r) {
    $s = $clock->epoch(intval($r['s']) / 1000000.0);
    $e = ($r['e'] === null) ? $now : $clock->epoch(intval($r['e']) + 1);  // whole seconds: through the second
    if ($e <= $from or $s >= $to) continue;
    $out[] = array(intval($r['Id']), $s, $e, $r['DefaultVideo'] != '');
  }
  return $out;
}

if ($what == 'sections') {
  if (($to - $from) > ZM_TIMELINE_SECTIONS) {
    ajaxError('sections: a window of up to four hours');
    return;
  }
  $sections = array();
  foreach (timeline_sections($mid, $from, $to, $longest, $clock, $now) as $s) {
    $sections[] = array('id' => $s[0], 'start' => $s[1], 'end' => $s[2], 'video' => $s[3]);
  }
  ajaxResponse(array('sections' => $sections, 'now' => $now));
  return;
}

// ---- Export (the owner, 18 November 2026: "Format: Joined MP4 / ... / Where: Browser Download /
// Long spans: Limit exports to 1h", "Anyone who views", O486, O495, O497): the part [from, to),
// at most an hour, as one MP4 joined across the sections, nothing encoded again. It's made in the
// background (an hour of a large camera is about 2 GB, longer than a page may wait through
// Cloudflare), in ZoneMinder's export folder, and fetched once ready; the files go when fetched,
// or after an hour.
//   what=export&mid&from&to  starts it: {job}
//   what=exportstatus&job    {state: making|ready|failed, size, name}
//   what=exportfile&job      the file, as a download
define('ZM_TIMELINE_EXPORT_MAX', 3600);
function timeline_export_dir() {
  return ZM_DIR_EXPORTS.'/timeline';
}
function timeline_export_job_dir($job) {
  global $user;
  if (!is_string($job) or !preg_match('/^[0-9a-f]{32}$/', $job)) return null;
  $dir = timeline_export_dir().'/'.$job;
  if (!is_dir($dir)) return null;
  $owner = @file_get_contents($dir.'/owner');
  if ($owner === false or intval($owner) !== intval($user ? $user->Id() : 0)) return null;  // only whoever asked
  return $dir;
}
// The parts of each section that play over [from, to), as the player plays them: the
// later-started section where two overlap; gaps skipped.
function timeline_export_parts($sections, $from, $to) {
  $parts = array();
  $t = $from;
  while ($t < $to) {
    $cur = null;
    foreach ($sections as $s) {
      if ($s[1] <= $t and $t < $s[2] and (!$cur or $s[1] > $cur[1])) $cur = $s;
    }
    if (!$cur) {
      $next = null;
      foreach ($sections as $s) if ($s[1] > $t and (!$next or $s[1] < $next[1])) $next = $s;
      if (!$next or $next[1] >= $to) break;
      $t = $next[1];
      continue;
    }
    $end = min($cur[2], $to);
    foreach ($sections as $s) if ($s[1] > $t and $s[1] < $end) $end = $s[1];  // a later one starts: it plays from there
    $parts[] = array('section' => $cur, 'in' => $t - $cur[1], 'out' => $end - $cur[1]);
    $t = $end;
  }
  return $parts;
}

if ($what == 'export') {
  if (($to - $from) > ZM_TIMELINE_EXPORT_MAX + 1) {
    ajaxError('An export is up to an hour.');
    return;
  }
  $to = min($to, $now);
  if ($to <= $from) {
    ajaxError('Nothing recorded there yet.');
    return;
  }
  $boxed = !empty($_REQUEST['boxes']);
  $parts = timeline_export_parts(timeline_sections($mid, $from, $to, $longest, $clock, $now), $from, $to);
  $list = '';
  $request = array();
  foreach ($parts as $p) {
    $Event = new ZM\Event($p['section'][0]);
    $file = $Event->DefaultVideo() ? $Event->Path().'/'.$Event->DefaultVideo() : '';
    if (!$file or !is_readable($file)) continue;
    $list .= "file '".str_replace("'", "'\\''", $file)."'\n".sprintf("inpoint %.3f\noutpoint %.3f\n", $p['in'], $p['out']);
    $request[] = array('file' => $file, 'start' => $p['section'][1], 'in' => $p['in'], 'out' => $p['out']);
  }
  if ($list === '') {
    ajaxError('There is no recording in that part.');
    return;
  }
  // Clear out what's older than an hour, then a folder of this job's own.
  $root = timeline_export_dir();
  if (!is_dir($root) and !@mkdir($root, 0700, true)) {
    ajaxError('The export folder can\'t be made.');
    return;
  }
  foreach (glob($root.'/*', GLOB_ONLYDIR) as $old) {
    if (filemtime($old) < time() - 3600) exec('rm -rf '.escapeshellarg($old));
  }
  $job = bin2hex(random_bytes(16));
  $dir = $root.'/'.$job;
  mkdir($dir, 0700);
  file_put_contents($dir.'/owner', strval($user ? $user->Id() : 0));
  file_put_contents($dir.'/parts.txt', $list);
  $name = preg_replace('/[^A-Za-z0-9 ._-]/', '', $Monitor->Name()).' '.date('Y-m-d H.i.s', intval($from)).' to '.date('H.i.s', intval($to)).'.mp4';
  file_put_contents($dir.'/name', $name);
  if ($boxed) {
    // With boxes (cluster-docs docs/zoneminder-boxed-export.md; O486, O496, O501-O504): not made
    // here. The parts and live detection's boxes over them go in the folder, and the boxed exporter,
    // a pod of its own, starts a Job for it (one at a time) on the M4000, which leaves the file.
    // The interval is the camera's Analysis FPS, and the boxes are those drawn: a box stored marked
    // `ignored` (an Inactive zone's) and a frame left with none are left out (includes/
    // detection_frames.php; cluster-docs O553, O564).
    require_once('includes/detection_frames.php');
    $interval = detection_interval($mid);
    $frames = array();
    if ($detector) {
      $frames = detection_frames_shown(dbFetchAll('SELECT TIMESTAMPDIFF(MICROSECOND, \'1970-01-01 00:00:00\', frame_time) AS us, boxes FROM `'.ZM_TIMELINE_DB.'`.detector_frames '.
          'WHERE monitor_id=? AND frame_time >= ? AND frame_time < ? ORDER BY frame_time', false,
          array($mid, timeline_utc($from - 2), timeline_utc($to + 1))));
    }
    file_put_contents($dir.'/request.json', json_encode(array('width' => intval($Monitor->Width()), 'height' => intval($Monitor->Height()),
      'interval' => $interval, 'parts' => $request)));
    file_put_contents($dir.'/boxes.json', json_encode(array('frames' => $frames)));
    file_put_contents($dir.'/boxed', '');  // last: the exporter takes the folder once this is there
    ajaxResponse(array('job' => $job, 'name' => $name, 'parts' => count($request), 'boxed' => true));
    return;
  }
  $cmd = ZM_PATH_FFMPEG.' -nostdin -loglevel error -f concat -safe 0 -i '.escapeshellarg($dir.'/parts.txt')
    .' -c copy -movflags +faststart -f mp4 '.escapeshellarg($dir.'/out.part');
  exec('nohup sh -c '.escapeshellarg('('.$cmd.' && mv '.escapeshellarg($dir.'/out.part').' '.escapeshellarg($dir.'/out.mp4')
    .') > '.escapeshellarg($dir.'/log').' 2>&1 || touch '.escapeshellarg($dir.'/failed')).' > /dev/null 2>&1 &');
  ajaxResponse(array('job' => $job, 'name' => $name, 'parts' => count($parts)));
  return;
}

if ($what == 'exportstatus' or $what == 'exportfile') {
  $dir = timeline_export_job_dir(isset($_REQUEST['job']) ? $_REQUEST['job'] : '');
  if (!$dir) {
    ajaxError('No such export.');
    return;
  }
  $name = @file_get_contents($dir.'/name');
  if ($what == 'exportstatus') {
    $state = file_exists($dir.'/out.mp4') ? 'ready' : (file_exists($dir.'/failed') ? 'failed' : 'making');
    // A boxed export not yet started by the exporter waits its turn (one at a time).
    if ($state == 'making' and file_exists($dir.'/boxed') and !file_exists($dir.'/started')) $state = 'waiting';
    ajaxResponse(array('state' => $state, 'name' => $name,
      'size' => file_exists($dir.'/out.mp4') ? filesize($dir.'/out.mp4') : (file_exists($dir.'/out.part') ? filesize($dir.'/out.part') : 0)));
    return;
  }
  $file = $dir.'/out.mp4';
  if (!file_exists($file)) {
    ajaxError('Not ready.');
    return;
  }
  while (ob_get_level()) ob_end_clean();
  header('Content-Type: video/mp4');
  header('Content-Length: '.filesize($file));
  header('Content-Disposition: attachment; filename="'.str_replace('"', '', $name).'"');
  header('Cache-Control: no-store');
  readfile($file);
  exec('rm -rf '.escapeshellarg($dir));
  exit;
}

if ($what != 'bar') {
  ajaxError('what: bar, sections, export, exportstatus or exportfile');
  return;
}

$cols = isset($_REQUEST['cols']) ? validInt($_REQUEST['cols']) : 0;
if ($cols < 1 or $cols > ZM_TIMELINE_MAX_COLS) {
  ajaxError('cols: 1 to '.ZM_TIMELINE_MAX_COLS);
  return;
}
$width = ($to - $from) / $cols;
$col = function($t) use ($from, $width, $cols) {
  return max(0, min($cols - 1, intval(floor(($t - $from) / $width))));
};
$rec = array_fill(0, $cols, 0);
$alarms = array_fill(0, $cols, 0);
$alerts = array_fill(0, $cols, 0);
$detail = ($to - $from) <= ZM_TIMELINE_DETAIL;
$alarm_list = array();
$alert_list = array();

// Recording: every column a section covers.
foreach (timeline_sections($mid, $from, $to, $longest, $clock, $now) as $s) {
  $a = max($s[1], $from);
  $b = min($s[2], $to);
  if ($b <= $a) continue;
  for ($c = $col($a), $last = $col($b - 0.000001); $c <= $last; $c++) $rec[$c] = 1;
}

// Alarms: live detection's (detector_alarms, one per raise); before them, ZoneMinder's alarmed
// events, which the bar counts the same way.
$since = alerts_list_since();
if ($detector) {
  $rows = dbFetchAll('SELECT TIMESTAMPDIFF(MICROSECOND, \'1970-01-01 00:00:00\', raised_at) AS r, '.
    'TIMESTAMPDIFF(MICROSECOND, \'1970-01-01 00:00:00\', MIN(first_seen)) AS f, '.
    'TIMESTAMPDIFF(MICROSECOND, \'1970-01-01 00:00:00\', MAX(last_seen)) AS l, '.
    'GROUP_CONCAT(DISTINCT label ORDER BY label) AS labels FROM `'.ZM_TIMELINE_DB.'`.detector_alarms '.
    'WHERE monitor_id=? AND raised_at >= ? AND raised_at < ? GROUP BY raised_at ORDER BY raised_at',
    false, array($mid, timeline_utc($from - 1), timeline_utc($to + 1)));
  foreach ($rows as $r) {
    $t = intval($r['r']) / 1000000.0;
    if ($t < $from or $t >= $to) continue;
    $alarms[$col($t)]++;
    if ($detail) {
      $alarm_list[] = array('start' => intval($r['f']) / 1000000.0, 'end' => intval($r['l']) / 1000000.0,
        'labels' => explode(',', $r['labels']));
    }
  }
}
$before = ($since === null) ? timeline_local($to + 1) : min($since, timeline_local($to + 1));
foreach (dbFetchAll('SELECT TIMESTAMPDIFF(MICROSECOND, \'1970-01-01 00:00:00\', StartDateTime) AS s, '.
    'TIMESTAMPDIFF(SECOND, \'1970-01-01 00:00:00\', EndDateTime) AS e FROM Events '.
    'WHERE MonitorId=? AND StartDateTime >= ? AND StartDateTime < ? AND AlarmFrames > 0 ORDER BY StartDateTime',
    false, array($mid, timeline_local($from), $before)) as $r) {
  $t = $clock->epoch(intval($r['s']) / 1000000.0);
  if ($t < $from or $t >= $to) continue;
  $alarms[$col($t)]++;
  if ($detail) {
    $alarm_list[] = array('start' => $t, 'end' => ($r['e'] === null) ? $now : $clock->epoch(intval($r['e']) + 1), 'labels' => array());
  }
}

// The signed-in user's alerts: their events, by when each started.
$condition = alerts_list_condition('E');
if ($condition !== null and $condition !== '0') {
  $rows = dbFetchAll('SELECT E.Id, TIMESTAMPDIFF(MICROSECOND, \'1970-01-01 00:00:00\', E.StartDateTime) AS s, '.
    'TIMESTAMPDIFF(SECOND, \'1970-01-01 00:00:00\', E.EndDateTime) AS e, '.
    '(SELECT GROUP_CONCAT(DISTINCT T.Name ORDER BY T.Name) FROM Events_Tags AS ET INNER JOIN Tags AS T ON T.Id = ET.TagId '.
    'WHERE ET.EventId = E.Id) AS labels FROM Events AS E '.
    'WHERE E.MonitorId=? AND E.StartDateTime >= ? AND E.StartDateTime < ? AND '.$condition.' ORDER BY E.StartDateTime',
    false, array($mid, timeline_local($from - $longest), timeline_local($to + 1)));
  foreach ($rows as $r) {
    $s = $clock->epoch(intval($r['s']) / 1000000.0);
    $e = ($r['e'] === null) ? $now : $clock->epoch(intval($r['e']) + 1);
    if ($e <= $from or $s >= $to) continue;
    $alerts[$col(max($s, $from))]++;
    if ($detail) {
      $alert_list[] = array('id' => intval($r['Id']), 'start' => $s, 'end' => $e,
        'labels' => $r['labels'] ? explode(',', $r['labels']) : array());
    }
  }
}

$data = array('from' => $from, 'to' => $to, 'cols' => $cols, 'now' => $now,
  'rec' => implode('', $rec), 'alarms' => $alarms, 'alerts' => $alerts);
if ($detail) {
  $data['alarmList'] = $alarm_list;
  $data['alertList'] = $alert_list;
}
ajaxResponse($data);
