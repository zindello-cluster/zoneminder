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
   `views/js/watch.js`).
4. **The Detection tab in Options,** per camera: live on or off, what to look for, the confidence,
   the frames a second, boxes shown, which objects may raise an alarm (`views/options.php`, `views/_options_detection.php`, the tab
   list in `includes/functions.php`).
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
Remove, and bad input refused. A pull request into the
branch runs the same, without pushing.

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
