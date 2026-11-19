# AI live detection: our fork of ZoneMinder

This is [ZoneMinder/zoneminder](https://github.com/ZoneMinder/zoneminder) (GPL-2.0), forked for
live detection's web pages on security.zindello.com.au, until upstream has them (cluster-docs:
`spec/layout.md`, O366, O374–O377; the design is `external-security.zindello.com.au`'s
[`docs/live-detection.md`](https://github.com/zindello-cluster/external-security.zindello.com.au/blob/main/docs/live-detection.md)).
It's a feature that could go upstream, so it's kept as small commits that a pull request could
carry.

## Our commits

Every change is marked `zindello-cluster` in a comment where it sits.

1. **The boxes request and the drawing layer:** `web/ajax/detections.php`,
   `web/skins/classic/js/detections.js`, `web/skins/classic/css/base/detections.css`. The request
   reads live detection's `detector` database, behind the event's and the camera's own view checks.
2. **Boxes over recorded events,** exact by video time: a Boxes button on the event page
   (`views/event.php`, `views/js/event.js`).
3. **Live with boxes on the camera page:** a Live button and a Boxes toggle (`views/watch.php`,
   `views/js/watch.js`). On the live pictures only (Live here and the timeline's live end, not
   recordings), a tracked object missing from the frame drawn keeps its last box, grey and fading,
   for 0 to 5 of the camera's samples by how fast it was moving (`fade` in `js/detections.js`,
   `fadingFor` and `holdOf`; its test `ai-live-detection/tests/detections.test.js`). The owner, 19
   November 2026: "if an object disappears maybe draw the box grey and fading for up to 5 frames
   before dropping it out completely", and "For a car travelling through the frame, we might only
   hold onto it for 1 frame (or not at all) and for relatively stationary objects hold for up to 5
   frames". The rule and the measurements behind it are external-security.zindello.com.au's
   `docs/live-detection.md`, "Live: matched by frame stamp"; the dash's cameras page and the
   wall's boxed stream follow it too.
4. **The Detection tab in Options,** per camera: live on or off, what to look for, a threshold and
   a hold per type (item 14), boxes shown, which objects may raise an alarm (`views/options.php`,
   `views/_options_detection.php`, the tab list in `includes/functions.php`).
5. **The Object filter on the events list** (`views/events.php`, `views/js/events.js`,
   `ajax/events.php`).
6. **The Event Server page and its menu item,** alert rules per user, per camera, per type
   (`external-security.zindello.com.au`'s `docs/alert-rules.md`): each user's switch for all their
   alerts, each camera they may view with its switch and types, Save and Back to the default, their
   paired phones with Remove; a user who may edit System settings chooses whose (another's change
   only with a tick) and sees the phones that belong to nobody (`views/eventserver.php`,
   `includes/actions/eventserver.php`, the menu item after Log in `includes/functions.php`). The
   rules and their permission checks are one file, `web/includes/alert_rules.php`, shared with the
   API; they read and write the `detector` database's `alert_rules`, `alert_users` and
   `alert_phones`.
7. **The alert rules API,** behind ZoneMinder's own sign-in: `GET`/`PUT /api/alert_rules/<user
   id>.json` (`GET /api/alert_rules.json`, the caller's) and `DELETE
   /api/alert_rules/phone/<token_hash>.json` (`web/api/app/Controller/AlertRulesController.php`, two
   routes in `web/api/app/Config/routes.php`).

8. **Events are each user's alerts** (cluster-docs `docs/zoneminder-ui.md`, section 1; O487–O498):
   the events list (`ajax/events.php`, `views/events.php`, `views/js/events.js`) and the API's
   events index (`web/api/app/Controller/EventsController.php`) list the signed-in user's alerts:
   events the event server tagged (as the user `ZM_ALERTS_TAGGER`, `notify` unless set) with a type
   that user has switched on for that camera now, from `ZM_ALERTS_SINCE` (else live detection's
   first alarm row). Show: Alerts / All Events on the list; `show=all` in the API, taken out of the
   request before ZoneMinder builds its filter. The test is `web/includes/alerts_list.php`.
   `consoleEvents` and `search` are unchanged. The console's event count columns are dropped
   (`views/console.php`), and Montage Review asks the API for every event (`views/js/montagereview.js`).

9. **A camera's timeline** (cluster-docs `docs/zoneminder-ui.md`, section 3; O482, O484, O493,
   O494): `?view=cameratimeline&mid=N`, the recording under a bar of the camera's 30 days with
   spans of 30 days, 7 days, 24 hours and 1 hour, a position dragged anywhere and played from,
   the span changed around it, the bar dragged sideways, live detection's alarms and the user's
   alerts marked, and live at the right end (`views/cameratimeline.php`,
   `views/js/cameratimeline.js` and `.js.php`, `css/base/views/cameratimeline.css`; its data,
   `ajax/cameratimeline.php`). Reached from the console (`views/js/console.js`), the camera page
   and the event page.

10. **Export from the timeline** (cluster-docs `docs/zoneminder-ui.md`, section 4; O485, O486,
    O495, O497): drag along the time scale to choose a part, up to an hour, or export the hour on
    the bar; one MP4 joined across the sections and cut to the part, nothing encoded again, made in
    the background in ZoneMinder's export folder and downloaded by the browser; only whoever asked
    may fetch it, and it goes once fetched or after an hour (`ajax/cameratimeline.php`,
    `what=export`, `exportstatus`, `exportfile`). With boxes waits for the owner's agreement to its
    Job.

11. **Export with boxes** (cluster-docs `docs/zoneminder-boxed-export.md`; O486, O496,
    O501-O504): the dialog's With boxes choice. The page doesn't make the file: it writes the
    parts and live detection's boxes over them into the job's folder (`request.json`,
    `boxes.json`, then `boxed`), and the chart's boxed exporter, a pod of its own, starts a Job for
    it, one at a time, on the M4000, which leaves `out.mp4` there. The status says it's waiting
    until the exporter has started it (`ajax/cameratimeline.php`).

12. **The Camera tab** (cluster-docs `docs/zoneminder-camera-tab.md`; O508-O511): on the page of
    each camera the chart's camera setup sets (its settings file, `/opt/camsetup/config.json`), to
    whoever may view System settings, after MQTT: the VIGI's own Fill Light Settings, Day/Night,
    Picture and on-screen text, read from the camera when the tab opens and changed all at once on
    Save by whoever may edit System settings, then read back and logged in ZoneMinder's Log;
    camera setup's own settings shown read only; ZoneMinder's live picture of the camera beside
    them, with a Snapshot to compare with. Through the camera's web API with the monitor's own
    login, one login per action, never retried after a refusal (`includes/vigi.php`,
    `ajax/camerasettings.php`, `views/_monitor_camera.php`, `js/camerasettings.js`,
    `css/base/camerasettings.css`; the tab in `views/monitor.php`). Its smoke test talks to a fake
    VIGI (`tests/fake_vigi.py`).

13. **Areas live detection ignores** (cluster-docs `docs/zoneminder-detection-exclusions.md`;
    O523-O535; the owner, 19 November 2026: "map OUT this area where the decoative bee on the wall
    thinks it's a human"): an area is an ordinary ZoneMinder zone of type Inactive, drawn on
    ZoneMinder's own zone page; a **Live detection** section on that page ticks the types live
    detection ignores there, from the Detection tab's Looks for list, saved by the page's own Save
    into the `detector` database's `detector_zone_labels`. The ticks are enabled only for an
    Inactive zone, by ZoneMinder's own `applyZoneType`; a zone of another type keeps its ticks,
    greyed out and ignored. The ticks are never written to ZoneMinder's `Zones`. ZoneMinder's own
    Save reloads the camera's capture as it always has: for an Inactive zone that's every Save,
    ticks alone included, since the page sends the greyed-out motion settings' empty boxes, which
    ZoneMinder counts as changes to their empty (NULL) columns. Whoever may edit the camera, as for
    any zone. Three of upstream's files carry a hunk each: `views/zone.php` (the
    section, `views/_zone_detection.php`), `views/js/zone.js` (`applyZoneType`) and
    `includes/actions/zone.php` (saving the ticks, `includes/zone_labels.php`, after ZoneMinder
    has saved the zone, against its id, a new zone's too). The Looks for list is one file,
    `includes/detection_labels.php`, shared with the Detection tab. The API is unchanged: an area
    is an Inactive zone in a camera's zones list. The live sidecar (external-security.zindello.com.au,
    `docs/live-detection.md`, "Areas it ignores") drops a box lying entirely inside an area that
    lists its type. Item 14 takes the ticks to every zone type.
14. **ZoneMinder's zones in live detection** (cluster-docs
    `docs/zoneminder-zones-for-live-detection.md`; O544-O570; the owner, 20 November 2026: "If
    this is going to be a REPLACEMENT for zoneminders detection it needs to hand ALL THE CURRENT
    FEATURES of the dropped detection and then ADD our additional features."):
    - **The zone page:** the Live detection ticks on every zone type but Privacy: "only these
      types" on Active, Inclusive, Exclusive and Preclusive zones, "ignore these" on Inactive ones,
      the page saying which. A new zone starts with every type ticked; a zone with no row reads as
      every type, or nothing if it's Inactive, as the live sidecar reads it; a zone that alarms
      with nothing ticked is kept as `[]`. An Inactive zone with nothing ticked is refused, in the
      page and again before ZoneMinder's own Save writes anything. Beside each tick, the zone's
      own threshold for that type (blank: the camera's, shown as the placeholder), on Active,
      Inclusive and Exclusive zones, in `detector_zone_labels.thresholds`. ZoneMinder's motion
      settings (Preset, Alarm Colour, Alarm Check Method and the pixel settings) are greyed out
      for every type and no longer required: read-only and out of reach, not disabled, so
      ZoneMinder's own enabling still decides what's posted and a Save keeps them as they were.
      Extend Alarm Frames stays for a Preclusive zone. `js/zone_detection.js` (new), called from
      `views/js/zone.js` (`applyZoneType`, `validateForm`); `includes/actions/zone.php` (the
      refusal); `includes/zone_labels.php`, `views/_zone_detection.php`.
    - **The monitor page:** Analysis FPS is live detection's rate and Alarm Frame Count its samples
      in a row, each saying so; Analysis Image, the reference blends and Warmup Frames greyed out
      (`views/_monitor_live_detection.php`, new, one line in `views/monitor.php`).
    - **The Detection tab:** a threshold and a hold per type in place of Confidence, 0.60 and 0.40
      until set; no Frames a second (the camera's Analysis FPS). `min_confidence` and `per_second`
      are no longer read or written.
    - **The boxes:** a box stored marked `ignored` (an Inactive zone's) is never sent to be drawn
      or exported, nor a frame left with none, and the interval is the camera's Analysis FPS
      (`includes/detection_frames.php`, new, used by `ajax/detections.php` and
      `ajax/cameratimeline.php`). The object that raised the alarm (`alarm: true`) is drawn on
      that frame bold, striped black and yellow, its tag in its type's colour (`drawBox`,
      `stripeTile` in `js/detections.js`; tested in `tests/detections.test.js`).
    - **Not here:** ZoneMinder applying Alarm Frame Count a second time to the trigger
      (`src/zm_monitor.cpp`): this image compiles nothing, so that stays as upstream has it.

`git log $(cat ai-live-detection/UPSTREAM_RELEASE)..ai-live-detection -- web` lists them.

## The branches

- **`ai-live-detection`,** the default: an upstream release, our commits, and our build.
  `ai-live-detection/UPSTREAM_RELEASE` names the release.
- **`master`:** upstream's, only ever synced from upstream. Nothing of ours is merged to it.

Upstream's own workflows are deleted on `ai-live-detection` and switched off in the fork's
settings; ours are `.github/workflows/ai-live-detection-*`.

## The image

A merge to `ai-live-detection` builds `ghcr.io/zindello-cluster/zoneminder:<release>-es`: the
ZoneMinder team's image of that release (the `FROM` in `ai-live-detection/Dockerfile`, by digest)
with the web files our commits touch laid over `/usr/share/zoneminder/www` (the API's under
`/usr/share/zoneminder/www/api`, as `web/api/` is in the repo). Nothing is compiled.
Before building, CI checks that the base image's copies of those files are upstream's at that
release, so a base image that changed them fails the build rather than being overwritten
(`tests/check-files.sh`), and lints them; after building, it starts the image against a throwaway
MariaDB and checks each page we change for our parts (`tests/smoke.sh`), then, with sign-in on, an
admin and a user who may view one of two cameras, the Event Server page and the alert rules API:
each sees and sets only their own, the admin another's only with the tick, Back to the default,
Remove, and bad input refused. Then the Alerts list: each user's own alerts in the list and the API,
All Events and `show=all`, the rules now, a hand-added tag and the old hook's not counting, and
zmNinjaNG 2.8.0's own calls replayed (its list, timeline, tag filter, badges, next and previous,
opening an event by id as a push does): each answers 200 with the app's keys, only fewer events. Then
the zone page's Live detection section: its ticks saved with a new zone and an existing one, on
every type, every type ticked on a new zone, an Inactive zone with nothing ticked refused, a zone's
own thresholds, ZoneMinder's motion settings not required, ticks alone leaving ZoneMinder's `Zones`
row as it was, refused to a user who can't edit the camera, and the app's zones call answering as
before; the Detection tab's thresholds and holds; the monitor page's lines; the boxes and the boxed
export without the Inactive zones' boxes, at the camera's Analysis FPS. A pull request into
the branch runs the same, without pushing.

The digest is in the run's summary. `external-security.zindello.com.au` pins it; a pull request
there moves it, and the owner merges and syncs it. The image is private on GHCR.

## A new upstream release

1. Each morning the `upstream` workflow opens an issue when upstream has a release newer than
   `UPSTREAM_RELEASE`.
2. Read its release notes. If upstream now has what our commits do, stop here and retire the fork
   (below).
3. Sync `master` (the **Sync fork** button, or `gh repo sync zindello-cluster/zoneminder -b master`).
4. `ai-live-detection/sync.sh <release>` merges it into a new branch, `upstream-<release>`, keeps
   upstream's workflows out, moves the `FROM` to the team's image of the release by digest, and
   records the release. A conflict in our files stops it, naming them.
5. Push it and open a pull request into `ai-live-detection` with the release notes, risks and
   rollback. CI checks, builds and smoke-tests it; the owner merges it.
6. The merge builds the image; then the chart's pull request with the new digest.

## Retiring the fork

When upstream has the change: put `external-security.zindello.com.au`'s `image:` back on
`ghcr.io/zoneminder/zoneminder`, archive this repo, and take its row out of the layout.
