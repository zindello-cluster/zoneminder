#!/bin/sh
# The built image, started against a throwaway MariaDB, with each page we change checked for our
# parts (AI-LIVE-DETECTION.md, "The image"). ZoneMinder loads its own schema at first start; the
# detector tables are made here as the chart's seed makes them, with one camera, one event and two
# frames with boxes; then, signed in as the fresh install's admin, each page is fetched and grepped.
#
#
# Then the Event Server page and the alert rules API, with ZoneMinder's sign-in on: an admin, and a
# user who may view only one of the two cameras.
#
#   ai-live-detection/tests/smoke.sh <image>
#
# SMOKE_NAME (default zm-smoke) names the two containers (<name>, <name>-db) and the network.
set -eu
image=$1
name=${SMOKE_NAME:-zm-smoke}
net=$name-$$
pass=throwaway

cleanup() { docker rm -f "$name" "$name-db" >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$net" >/dev/null
docker run -d --name "$name-db" --network "$net" -e MARIADB_ROOT_PASSWORD=$pass -e MARIADB_DATABASE=zm \
  -e MARIADB_USER=zmuser -e MARIADB_PASSWORD=$pass docker.io/library/mariadb:12.3.3 >/dev/null
# The image's own readiness check, over TCP: the entrypoint's temporary server doesn't pass it.
for i in $(seq 60); do
  docker exec "$name-db" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
  sleep 2
done
docker exec "$name-db" healthcheck.sh --connect --innodb_initialized
sql() { docker exec -i "$name-db" mariadb -uroot -p$pass "$@"; }
sql -e "SET GLOBAL log_bin_trust_function_creators=1; CREATE DATABASE detector; GRANT ALL ON detector.* TO 'zmuser'@'%';"

docker run -d --name "$name" --network "$net" --shm-size 256m -e TZ=Australia/Sydney \
  -e ZM_DB_HOST="$name-db" -e ZM_DB_NAME=zm -e ZM_DB_USER=zmuser -e ZM_DB_PASS=$pass "$image" >/dev/null
for i in $(seq 120); do
  if docker exec "$name" curl -sf -o /dev/null http://127.0.0.1/zm/ 2>/dev/null; then break; fi
  sleep 2
done
docker exec "$name" curl -sf -o /dev/null http://127.0.0.1/zm/ || { docker logs "$name" | tail -50; echo "ZoneMinder didn't start" >&2; exit 1; }

# The detector tables as the chart's seed makes them (deploy/live/schema.sql there), and a camera,
# an event and two frames with boxes.
sql detector <<'EOF'
CREATE TABLE detector_settings (monitor_id INT UNSIGNED NOT NULL PRIMARY KEY, labels JSON NOT NULL, min_confidence DECIMAL(3,2) NOT NULL,
  per_second TINYINT UNSIGNED NOT NULL, live BOOLEAN NOT NULL, boxes_default BOOLEAN NOT NULL,
  alarm_on ENUM('moving','moving_and_people','all') NOT NULL DEFAULT 'moving_and_people',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE detector_frames (monitor_id INT UNSIGNED NOT NULL, frame_time DATETIME(6) NOT NULL, frame_number INT UNSIGNED NOT NULL,
  boxes JSON NOT NULL, PRIMARY KEY (monitor_id, frame_time)) ENGINE=InnoDB;
CREATE TABLE detector_events (event_id BIGINT UNSIGNED NOT NULL, label VARCHAR(32) NOT NULL, monitor_id INT UNSIGNED NOT NULL,
  video_start DATETIME(6) NOT NULL, first_seen DATETIME(6) NOT NULL, last_seen DATETIME(6) NOT NULL, frames INT UNSIGNED NOT NULL,
  best_confidence DECIMAL(3,2) NOT NULL, PRIMARY KEY (event_id, label), KEY by_label (label, first_seen), KEY by_camera (monitor_id, first_seen)) ENGINE=InnoDB;
INSERT INTO detector_settings (monitor_id, labels, min_confidence, per_second, live, boxes_default) VALUES (1, '["person","car"]', 0.60, 5, 1, 1);
INSERT INTO detector_frames VALUES (1, '2026-11-12 00:26:40.200000', 10, '[{"label":"person","confidence":0.87,"box":[0.1,0.1,0.3,0.6],"track":1}]'),
  (1, '2026-11-12 00:26:40.400000', 13, '[{"label":"person","confidence":0.9,"box":[0.12,0.1,0.32,0.6],"track":1}]');
INSERT INTO detector_events VALUES (1, 'person', 1, '2026-11-12 00:26:40.000000', '2026-11-12 00:26:40.200000', '2026-11-12 00:26:40.400000', 2, 0.90);
EOF
sql zm <<'EOF'
INSERT INTO Monitors (Id, Name, Type, Enabled, Width, Height, Colours, Path, Capturing, Analysing, Recording, Decoding)
  VALUES (1, 'Smoke camera', 'Ffmpeg', 1, 640, 480, 4, 'rtsp://127.0.0.1/none', 'Always', 'None', 'Always', 'Always');
INSERT INTO Events (Id, MonitorId, StorageId, StateId, Name, StartDateTime, EndDateTime, Width, Height, Length, Frames, AlarmFrames, DefaultVideo, Videoed)
  VALUES (1, 1, 1, 1, 'Event 1', '2026-11-12 11:26:40', '2026-11-12 11:27:40', 640, 480, 60, 900, 0, '1-video.h264.mp4', 1);
-- A fresh install sends every page to the privacy notice until it's answered (index.php).
UPDATE Config SET Value = '0' WHERE Name = 'ZM_SHOW_PRIVACY';
EOF

# Signed in as the fresh install's admin/admin, by token (the API's login), when sign-in is on.
token=$(docker exec "$name" curl -sf -X POST -d 'user=admin&pass=admin' http://127.0.0.1/zm/api/host/login.json \
  | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p' || true)
auth=${token:+"&token=$token"}
page() { docker exec "$name" curl -sf "http://127.0.0.1/zm/index.php?$1$auth"; }
must() { # <what> <pattern> <page query>
  if page "$3" | grep -q -- "$2"; then echo "ok: $1"; return; fi
  echo "MISSING: $1 ($2 in ?$3)" >&2
  docker exec "$name" curl -s -w '\nHTTP %{http_code} %{redirect_url}\n' "http://127.0.0.1/zm/index.php?$3$auth" | tail -c 3000 >&2
  docker exec "$name" sh -c 'tail -n 30 /var/log/apache2/error.log /var/log/zm/*.log 2>/dev/null' >&2 || true
  exit 1
}
must "the event page's Boxes button" 'id="toggleBoxesButton"' 'view=event&eid=1'
# cache_bust() serves it as cache/skins_classic_js_detections-<css>-<mtime>.js, or by its own path.
must "the event page loads the drawing layer" 'js_detections-\|js/detections\.js' 'view=event&eid=1'
must "the camera page's Live button" 'id="liveButton"' 'view=watch&mid=1'
must "the camera page's Boxes button" 'id="liveBoxesButton"' 'view=watch&mid=1'
must "the Detection tab" 'id="detectionTable"' 'view=options&tab=detection'
must "the Detection tab's camera row" 'Smoke camera' 'view=options&tab=detection'
must "the Detection tab in the Options menu" 'tab=detection' 'view=options&tab=system'
must "the Detection tab's Alarms on choice" 'name="camera\[1\]\[alarm_on\]"' 'view=options&tab=detection'
must "the Detection tab's Alarms on default" 'value="moving_and_people" selected="selected">Moving objects, and people even when still' 'view=options&tab=detection'
must "the events list's Object filter" 'id="objectFilter"' 'view=events'
must "the boxes request: the event's frames" '"frames":\[{"t":1794443200.2' 'view=request&request=detections&what=event&eid=1'
must "the boxes request: the event's start" '"video_start":1794443200' 'view=request&request=detections&what=event&eid=1'
must "the boxes request: live" '"interval":0.2' 'view=request&request=detections&what=live&mid=1&since=1794443200'
# The event's Id, as a number or a string, in the list's rows.
must "the Object filter finds the event" '"Id":"\?1"\?[,}]' 'view=request&request=events&task=query&object=person'
if page 'view=request&request=events&task=query&object=car' | grep -q '"Id":"\?1"\?[,}]'; then echo "the Object filter let a car through" >&2; exit 1; fi
echo "ok: the Object filter keeps the event out for an object it hasn't got"
# A detector table from before alarm_on (the chart's seed adds it at ZoneMinder's next start): the
# tab still shows, the choice greyed out.
sql detector -e "ALTER TABLE detector_settings DROP COLUMN alarm_on"
must "the Detection tab on a table without alarm_on" 'Smoke camera' 'view=options&tab=detection'
must "the Alarms on choice greyed out until the column is there" "Can be changed after ZoneMinder's next start" 'view=options&tab=detection'

# ---- The Event Server page and the alert rules API (external-security.zindello.com.au,
# docs/alert-rules.md), with sign-in on. Each check says ok or fails the test.
fail() {
  echo "FAILED: $1" >&2
  docker exec "$name" sh -c 'tail -n 30 /var/log/apache2/error.log /var/log/zm/*.log 2>/dev/null' >&2 || true
  exit 1
}
ok() { echo "ok: $1"; }
has() { # <what> <pattern> <text>
  if printf '%s' "$3" | grep -q -- "$2"; then ok "$1"; else printf '%s\n' "$3" | tail -c 3000 >&2; fail "$1 ($2 not found)"; fi
}
hasnt() { # <what> <pattern> <text>
  if printf '%s' "$3" | grep -q -- "$2"; then printf '%s\n' "$3" | grep -- "$2" | head -5 >&2; fail "$1 ($2 found)"; else ok "$1"; fi
}
is() { # <what> <expected> <actual>
  if [ "$2" = "$3" ]; then ok "$1"; else fail "$1: expected [$2], got [$3]"; fi
}
q() { sql -N -B zm -e "$1"; }
# Signed in through the login form, by cookie, as a person would be.
login() { # <user> <password>
  docker exec "$name" curl -s -o /dev/null -c "/tmp/$1.jar" -b "/tmp/$1.jar" \
    --data-urlencode "username=$1" --data-urlencode "password=$2" -d 'action=login&view=login' http://127.0.0.1/zm/index.php
}
upage() { # <user> <query>
  docker exec "$name" curl -s -c "/tmp/$1.jar" -b "/tmp/$1.jar" "http://127.0.0.1/zm/index.php?$2"
}
# A form post, with the CSRF token ZoneMinder put on the page.
upost() { # <user> <query> <form data>
  csrf=$(upage "$1" 'view=eventserver' | sed -n 's/.*csrfMagicToken = "\([^"]*\)".*/\1/p' | head -1)
  test -n "$csrf" || fail "no CSRF token on the Event Server page for $1"
  docker exec "$name" curl -s -c "/tmp/$1.jar" -b "/tmp/$1.jar" --data-urlencode "__csrf_magic=$csrf" -d "$3" \
    "http://127.0.0.1/zm/index.php?$2"
}
# The API, by token: sets $code and $body.
token() { # <user> <password>
  docker exec "$name" curl -s -X POST --data-urlencode "user=$1" --data-urlencode "pass=$2" http://127.0.0.1/zm/api/host/login.json \
    | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p'
}
api() { # <token> <method> <path> [curl arguments]
  t=$1; m=$2; p=$3; shift 3
  code=$(docker exec "$name" curl -s -o /tmp/api.out -w '%{http_code}' -X "$m" "$@" "http://127.0.0.1/zm/api/$p?token=$t")
  body=$(docker exec "$name" cat /tmp/api.out)
}
apiis() { # <what> <expected status> <token> <method> <path> [curl arguments]
  w=$1; e=$2; shift 2
  api "$@"
  if [ "$code" = "$e" ]; then ok "$w"; else echo "$body" | tail -c 2000 >&2; fail "$w: expected HTTP $e, got $code"; fi
}
json='-H Content-Type:application/json -d'
rules() { q "SELECT CONCAT(monitor_id, ':', alerts, ':', labels) FROM detector.alert_rules WHERE user_id=$1 ORDER BY monitor_id" | tr '\n' ' ' | sed 's/ $//'; }
switch() { q "SELECT alerts FROM detector.alert_users WHERE user_id=$1"; }
removing() { q "SELECT remove FROM detector.alert_phones WHERE token_hash='$1'"; }
animals='"bird","cat","dog","horse","sheep","cow","elephant","bear","zebra","giraffe"'

# A second camera; a second user who may view only the first; sign-in on, with a secret for tokens;
# times shown in Sydney's.
vpass=viewer-throwaway
vhash=$(docker exec "$name" php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$vpass")
secret=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
sql zm <<EOF
INSERT INTO Monitors (Id, Name, Type, Enabled, Width, Height, Colours, Path, Capturing, Analysing, Recording, Decoding)
  VALUES (2, 'Back yard', 'Ffmpeg', 1, 640, 480, 4, 'rtsp://127.0.0.1/none', 'Always', 'None', 'Always', 'Always');
INSERT INTO Users (Username, Password, Enabled, Stream, Events, Control, Monitors, \`Groups\`, Devices, Snapshots, System, APIEnabled)
  VALUES ('viewer', '$vhash', 1, 'View', 'View', 'None', 'View', 'None', 'None', 'None', 'None', 1);
INSERT INTO Monitors_Permissions (MonitorId, UserId, Permission) SELECT 2, Id, 'None' FROM Users WHERE Username='viewer';
UPDATE Config SET Value='1' WHERE Name='ZM_OPT_USE_AUTH';
UPDATE Config SET Value='$secret' WHERE Name='ZM_AUTH_HASH_SECRET';
UPDATE Config SET Value='Australia/Sydney' WHERE Name='ZM_TIMEZONE';
EOF
admin=$(q "SELECT Id FROM Users WHERE Username='admin'")
viewer=$(q "SELECT Id FROM Users WHERE Username='viewer'")
login admin admin
login viewer "$vpass"
atok=$(token admin admin)
vtok=$(token viewer "$vpass")
test -n "$atok" && test -n "$vtok" || fail "no API token for admin or viewer"
ok "signed in as admin and as viewer, by the form and by token"

has "the Event Server menu item (admin)" 'id="getEventServerHTML"' "$(upage admin 'view=console')"
has "the Event Server menu item (viewer)" 'href="?view=eventserver"' "$(upage viewer 'view=console')"

# Without the tables: the page says so, the API answers 503.
has "the page without the tables" "Alert rules aren't set up" "$(upage viewer 'view=eventserver')"
apiis "the API without the tables" 503 "$vtok" GET alert_rules.json

# The tables, exactly as docs/alert-rules.md has them.
sql detector <<'EOF'
-- One row per user per camera, once that user has set that camera.
-- user_id 0 with monitor_id 0 is the default, for a user or a camera with no row.
CREATE TABLE IF NOT EXISTS alert_rules (
  user_id     INT UNSIGNED NOT NULL,  -- ZoneMinder's Users.Id; 0: the default
  monitor_id  INT UNSIGNED NOT NULL,  -- ZoneMinder's Monitors.Id; 0: the default
  alerts      BOOLEAN NOT NULL,       -- this camera's switch
  labels      JSON NOT NULL,          -- the types that push, COCO names: ["person","car"]
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, monitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The switch for all of a user's alerts. No row: on.
CREATE TABLE IF NOT EXISTS alert_users (
  user_id     INT UNSIGNED NOT NULL PRIMARY KEY,
  alerts      BOOLEAN NOT NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The paired phones, kept by the event server for the page to show. The token itself stays in
-- the event server's own file; this holds its SHA-256, which can't be pushed to.
CREATE TABLE IF NOT EXISTS alert_phones (
  token_hash   CHAR(64) NOT NULL PRIMARY KEY,
  username     VARCHAR(64) NULL,      -- who signed in on it; NULL: paired before the rules, and
                                      -- not signed in since
  platform     VARCHAR(16) NOT NULL,  -- ios, android
  app_version  VARCHAR(32) NOT NULL,
  profile      VARCHAR(64) NULL,      -- the app's name for this server, e.g. "Home"
  first_seen   DATETIME NULL,         -- the app first registered, UTC; NULL: before the rules
  last_seen    DATETIME NULL,         -- the app last registered, UTC; NULL: not since the rules
  last_push    DATETIME NULL,         -- the last alert sent to it, UTC
  remove       BOOLEAN NOT NULL DEFAULT FALSE  -- set by Remove; the event server forgets the phone
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF

# No default row: everything on, today's six types.
apiis "viewer reads their own rules" 200 "$vtok" GET alert_rules.json
has "with no default row, the built-in default" '"default":{"alerts":true,"labels":\["person","car","truck","bus","motorcycle","boat"\]}' "$body"
has "the API names the user" "\"user\":{\"id\":$viewer,\"name\":\"viewer\"}" "$body"
has "the API lists the camera viewer may view" '"1":{"name":"Smoke camera","alerts":true' "$body"
hasnt "the API leaves out the camera viewer may not view" 'Back yard' "$body"

# The default row, as the chart's seed writes it.
sql detector -e "INSERT INTO alert_rules (user_id, monitor_id, alerts, labels) VALUES (0, 0, 1, '[\"person\",\"car\"]')"
page=$(upage viewer 'view=eventserver')
has "the page for viewer" 'id="eventServerTable"' "$page"
has "the page lists the camera viewer may view" 'Smoke camera' "$page"
hasnt "the page leaves out the camera viewer may not view" 'Back yard' "$page"
hasnt "no choice of user for viewer" 'id="eventServerUser"' "$page"
has "an unset camera shows the default's types (car ticked)" 'name="camera\[1\]\[types\]\[\]" value="car" checked' "$page"
hasnt "an unset camera shows the default's types (truck not ticked)" 'name="camera\[1\]\[types\]\[\]" value="truck" checked' "$page"
page=$(upage admin 'view=eventserver')
has "the page for admin lists both cameras" 'Back yard' "$page"
has "admin chooses whose rules" 'id="eventServerUser"' "$page"

# Viewer sets their own through the page: person, and animal as COCO's ten.
page=$(upost viewer "view=eventserver&uid=$viewer" "view=eventserver&uid=$viewer&action=save&alerts=1&camera[1][shown]=1&camera[1][alerts]=1&camera[1][types][]=person&camera[1][types][]=animal")
has "the page says saved" 'Saved.' "$page"
is "the page's save: viewer's camera row" "1:1:[\"person\",$animals]" "$(rules "$viewer")"
is "the page's save: viewer's switch" 1 "$(switch "$viewer")"
has "the page shows animal ticked" 'name="camera\[1\]\[types\]\[\]" value="animal" checked' "$(upage viewer 'view=eventserver')"
# All alerts off through the page.
upost viewer "view=eventserver&uid=$viewer" "view=eventserver&uid=$viewer&action=save&camera[1][shown]=1&camera[1][alerts]=1&camera[1][types][]=person" >/dev/null
is "the page's save: viewer's switch off" 0 "$(switch "$viewer")"
is "the page's save: viewer's camera row again" '1:1:["person"]' "$(rules "$viewer")"

# Admin's own: a camera left at the default gets no row; the changed one does.
upost admin "view=eventserver&uid=$admin" "view=eventserver&uid=$admin&action=save&alerts=1&camera[1][shown]=1&camera[1][alerts]=1&camera[1][types][]=person&camera[1][types][]=car&camera[2][shown]=1&camera[2][types][]=person" >/dev/null
is "a camera left at the default keeps following it; the changed one is saved" '2:0:["person"]' "$(rules "$admin")"

# Viewer through the API, JSON and form fields.
apiis "viewer sets their own by JSON" 200 "$vtok" PUT "alert_rules/$viewer.json" $json '{"alerts": true, "cameras": {"1": {"alerts": false, "labels": ["car", "dog"]}}}'
has "PUT answers with the rules" '"cameras":{"1":{"name":"Smoke camera","alerts":false,"labels":\["car","dog"\],"set":true}}' "$body"
is "the API's save: viewer's camera row" '1:0:["car","dog"]' "$(rules "$viewer")"
is "the API's save: viewer's switch" 1 "$(switch "$viewer")"
apiis "viewer sets their own by form fields" 200 "$vtok" PUT "alert_rules/$viewer.json" -d 'alerts=0&cameras[1][labels][]=bus'
is "the form fields' save: the camera keeps its switch, takes the types" '1:0:["bus"]' "$(rules "$viewer")"
is "the form fields' save: viewer's switch" 0 "$(switch "$viewer")"

# Nobody else's: viewer can't read or change admin's, by the API or the page.
apiis "viewer can't read admin's rules" 403 "$vtok" GET "alert_rules/$admin.json"
apiis "viewer can't change admin's rules" 403 "$vtok" PUT "alert_rules/$admin.json" $json '{"alerts": false}'
page=$(upost viewer "view=eventserver&uid=$admin" "view=eventserver&uid=$admin&changeOther=1&action=save&camera[1][shown]=1&camera[1][alerts]=1")
has "the page refuses viewer's post for admin" 'You can only change your own alert rules.' "$page"
page=$(upost viewer "view=eventserver&uid=$admin" "view=eventserver&uid=$admin&changeOther=1&action=reset")
is "admin's rows untouched by viewer" '2:0:["person"]' "$(rules "$admin")"
is "admin's switch untouched by viewer" 1 "$(switch "$admin")"
has "the page won't show viewer admin's rules" 'You can only see your own alert rules.' "$(upage viewer "view=eventserver&uid=$admin")"

# Bad input.
apiis "a type that isn't one of the 17 is refused" 400 "$vtok" PUT "alert_rules/$viewer.json" $json '{"cameras": {"1": {"labels": ["unicorn"]}}}'
apiis "'animal' is a tick on the page, not a type in the API" 400 "$vtok" PUT "alert_rules/$viewer.json" $json '{"cameras": {"1": {"labels": ["animal"]}}}'
apiis "a camera viewer may not view is refused" 400 "$vtok" PUT "alert_rules/$viewer.json" $json '{"cameras": {"2": {"alerts": true}}}'
apiis "admin can't give viewer a camera viewer may not view" 400 "$atok" PUT "alert_rules/$viewer.json" $json '{"cameras": {"2": {"alerts": true}}}'
apiis "an unknown camera is 404" 404 "$vtok" PUT "alert_rules/$viewer.json" $json '{"cameras": {"99": {"alerts": true}}}'
apiis "an unknown user is 404" 404 "$atok" GET alert_rules/9999.json
apiis "a switch that isn't one is refused" 400 "$vtok" PUT "alert_rules/$viewer.json" $json '{"alerts": "maybe"}'
page=$(upost viewer "view=eventserver&uid=$viewer" "view=eventserver&uid=$viewer&action=save&alerts=1&camera[2][shown]=1&camera[2][alerts]=1")
has "the page refuses a camera viewer may not view" 'may not view camera 2' "$page"
is "nothing written for the refused input" '1:0:["bus"]' "$(rules "$viewer")"
is "nothing written for the refused input (switch)" 0 "$(switch "$viewer")"

# Admin changes viewer's: the page only with the tick; the API.
page=$(upage admin "view=eventserver&uid=$viewer")
has "admin sees the warning on viewer's rules" "These are viewer's rules, not yours." "$page"
has "admin sees the camera viewer may view" 'Smoke camera' "$page"
hasnt "admin doesn't see a camera viewer may not view, on viewer's rules" 'Back yard' "$page"
has "the tick to change viewer's rules" "Change viewer's rules" "$page"
page=$(upost admin "view=eventserver&uid=$viewer" "view=eventserver&uid=$viewer&action=save&alerts=1&camera[1][shown]=1&camera[1][alerts]=1&camera[1][types][]=truck")
has "without the tick, nothing changes" 'Nothing was changed' "$page"
is "without the tick, viewer's rows are as they were" '1:0:["bus"]' "$(rules "$viewer")"
upost admin "view=eventserver&uid=$viewer" "view=eventserver&uid=$viewer&changeOther=1&action=save&alerts=1&camera[1][shown]=1&camera[1][alerts]=1&camera[1][types][]=truck" >/dev/null
is "with the tick, admin's change to viewer's camera" '1:1:["truck"]' "$(rules "$viewer")"
is "with the tick, admin's change to viewer's switch" 1 "$(switch "$viewer")"
apiis "admin changes viewer's through the API" 200 "$atok" PUT "alert_rules/$viewer.json" $json '{"cameras": {"1": {"labels": ["boat"]}}}'
is "the API's change by admin" '1:1:["boat"]' "$(rules "$viewer")"
apiis "admin reads viewer's" 200 "$atok" GET "alert_rules/$viewer.json"
has "admin reads viewer's: viewer's cameras only" '"cameras":{"1":' "$body"
hasnt "admin reads viewer's: not a camera viewer may not view" 'Back yard' "$body"

# Back to the default: the page (with the tick for another's), and reset in the API.
upost admin "view=eventserver&uid=$viewer" "view=eventserver&uid=$viewer&action=reset" >/dev/null
is "admin's Back to the default without the tick does nothing" '1:1:["boat"]' "$(rules "$viewer")"
page=$(upost viewer "view=eventserver&uid=$viewer" "view=eventserver&uid=$viewer&action=reset")
has "the page says back to the default" 'Back to the default.' "$page"
is "Back to the default deletes the rows" '' "$(rules "$viewer")"
is "Back to the default deletes the switch" '' "$(switch "$viewer")"
apiis "viewer sets one again" 200 "$vtok" PUT "alert_rules/$viewer.json" $json '{"alerts": false, "cameras": {"1": {"labels": ["person"]}}}'
# Only a POST with the page's CSRF token changes anything: not a link, and not a form from elsewhere.
upage viewer "view=eventserver&action=reset&uid=$viewer" >/dev/null
is "a link with an action in it changes nothing" '1:1:["person"]' "$(rules "$viewer")"
docker exec "$name" curl -s -o /dev/null -c /tmp/viewer.jar -b /tmp/viewer.jar -d "view=eventserver&uid=$viewer&action=reset" \
  "http://127.0.0.1/zm/index.php?view=eventserver"
is "a post without the page's CSRF token changes nothing" '1:1:["person"]' "$(rules "$viewer")"
apiis "admin's reset of viewer's" 200 "$atok" PUT "alert_rules/$viewer.json" $json '{"reset": true}'
is "reset deletes the rows" '' "$(rules "$viewer")"
is "reset deletes the switch" '' "$(switch "$viewer")"
has "reset answers with the default again" '"1":{"name":"Smoke camera","alerts":true,"labels":\["person","car"\],"set":false}' "$body"
is "the default row is left alone" '0:1:["person","car"]' "$(rules 0)"

# Phones: viewer's two (one signed in as "Viewer"), admin's, one with no user, one whose user has gone.
p1=1111111111111111111111111111111111111111111111111111111111111111
p2=2222222222222222222222222222222222222222222222222222222222222222
pa=3333333333333333333333333333333333333333333333333333333333333333
pn=4444444444444444444444444444444444444444444444444444444444444444
pg=5555555555555555555555555555555555555555555555555555555555555555
sql detector <<EOF
INSERT INTO alert_phones (token_hash, username, platform, app_version, profile, first_seen, last_seen, last_push) VALUES
  ('$p1', 'Viewer', 'ios', '9.9.1-viewer-one', 'Home', NULL, NULL, NULL),
  ('$p2', 'viewer', 'android', '9.9.1-viewer-two', 'Cottage', '2026-11-14 01:02:03', '2026-11-14 02:00:00', '2026-11-14 03:00:00'),
  ('$pa', 'admin', 'ios', '9.9.1-admin', 'Home', '2026-11-14 01:00:00', '2026-11-14 01:00:00', NULL),
  ('$pn', NULL, 'android', '9.9.1-nobody', NULL, NULL, NULL, NULL),
  ('$pg', 'gone', 'ios', '9.9.1-gone', 'Old', '2026-11-01 00:00:00', '2026-11-01 00:00:00', NULL);
EOF
page=$(upage viewer 'view=eventserver')
has "viewer's phone shows (signed in with another case)" '9.9.1-viewer-one' "$page"
has "viewer's other phone shows" '9.9.1-viewer-two' "$page"
hasnt "admin's phone doesn't show to viewer" '9.9.1-admin' "$page"
hasnt "a phone of nobody's doesn't show to viewer" '9.9.1-nobody\|9.9.1-gone' "$page"
has "a phone paired before the rules: not recorded" 'Not recorded' "$page"
has "a phone with no alert yet: none yet" 'None yet' "$page"
has "a time in ZoneMinder's time zone (01:02:03 UTC is 12:02:03 in Sydney)" '12:02:03' "$page"
hasnt "no token hash on the page" "$p1\|$p2\|1111111111111111\|2222222222222222" "$page"
hasnt "no section of nobody's phones for viewer" 'Phones that belong to nobody' "$page"
apiis "viewer's phones in the API" 200 "$vtok" GET alert_rules.json
has "the API's phone" "{\"id\":\"$p2\",\"platform\":\"android\",\"app_version\":\"9.9.1-viewer-two\",\"name\":\"Cottage\",\"paired\":\"2026-11-14T01:02:03Z\",\"last_connected\":\"2026-11-14T02:00:00Z\",\"last_alert\":\"2026-11-14T03:00:00Z\",\"removing\":false}" "$body"
has "the API's phone paired before the rules" "{\"id\":\"$p1\",\"platform\":\"ios\",\"app_version\":\"9.9.1-viewer-one\",\"name\":\"Home\",\"paired\":null" "$body"
hasnt "the API shows viewer no one else's phones" "$pa\|$pn\|$pg" "$body"

ref() { docker exec "$name" php -r 'echo hash("sha256", "phone:".$argv[1]);' "$1"; }
page=$(upost viewer "view=eventserver&uid=$viewer" "view=eventserver&uid=$viewer&action=removePhone&phone=$(ref "$pa")")
has "the page refuses viewer removing admin's phone" 'You can only remove your own phones.' "$page"
is "admin's phone not marked" 0 "$(removing "$pa")"
apiis "viewer can't remove admin's phone through the API" 403 "$vtok" DELETE "alert_rules/phone/$pa.json"
is "admin's phone still not marked" 0 "$(removing "$pa")"
page=$(upost viewer "view=eventserver&uid=$viewer" "view=eventserver&uid=$viewer&action=removePhone&phone=$(ref "$p1")")
has "the page's Remove" 'Removed.' "$page"
is "the page's Remove marks the phone" 1 "$(removing "$p1")"
has "a phone being removed says so" 'Being removed' "$(upage viewer 'view=eventserver')"
apiis "viewer removes their own phone through the API" 200 "$vtok" DELETE "alert_rules/phone/$p2.json"
is "the API's Remove marks the phone" 1 "$(removing "$p2")"
apiis "an unknown phone is 404" 404 "$vtok" DELETE "alert_rules/phone/6666666666666666666666666666666666666666666666666666666666666666.json"
page=$(upage admin 'view=eventserver')
has "admin sees the phones of nobody" 'Phones that belong to nobody' "$page"
has "admin sees a phone with no user" '9.9.1-nobody' "$page"
has "admin sees a phone whose user has gone" '9.9.1-gone' "$page"
hasnt "no token hash on admin's page" "$pa\|$pn\|$pg" "$page"
apiis "admin's API lists the phones of nobody" 200 "$atok" GET alert_rules.json
has "the phones of nobody in the API" "\"unowned_phones\":\[{\"id\":\"$pn\"" "$body"
apiis "admin removes a phone of nobody's through the API" 200 "$atok" DELETE "alert_rules/phone/$pn.json"
is "the phone of nobody's is marked" 1 "$(removing "$pn")"
upost admin "view=eventserver&uid=$admin" "view=eventserver&uid=$admin&action=removePhone&phone=$(ref "$pg")" >/dev/null
is "admin removes a phone whose user has gone through the page" 1 "$(removing "$pg")"
is "admin's own phone untouched" 0 "$(removing "$pa")"

# And signed out: nothing.
code=$(docker exec "$name" curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/zm/api/alert_rules.json)
is "the API signed out" 401 "$code"
has "the page signed out goes to the login" 'view=login' "$(docker exec "$name" curl -s -o /dev/null -w '%{redirect_url}' 'http://127.0.0.1/zm/index.php?view=eventserver')"

# ---- The Alerts list (cluster-docs docs/zoneminder-ui.md, section 1; O487-O498): each signed-in
# user's alerts from the event server's tags against their rules now; show=all for every event.
# Then zmNinjaNG 2.8.0's own calls (app/src/api/events.ts at zmNinjaNg-2.8.0), replayed as the app
# makes them: each answers as before, same keys, only fewer events (O492).
command -v jq >/dev/null || fail "jq is needed for the API checks"
q "INSERT INTO Users (Username, Password, Enabled, Stream, Events, Control, Monitors, \`Groups\`, Devices, Snapshots, System, APIEnabled)
   VALUES ('notify', '', 1, 'View', 'View', 'None', 'View', 'None', 'None', 'None', 'None', 1)"
notify=$(q "SELECT Id FROM Users WHERE Username='notify'")
# Events on 18 November 2026, Sydney time; live detection's first alarm row at 17:38:48.
sql zm <<EOF2
INSERT INTO Events (Id, MonitorId, StorageId, StateId, Name, Cause, StartDateTime, EndDateTime, Width, Height, Length, Frames, AlarmFrames, DefaultVideo, Videoed) VALUES
 (101, 1, 1, 1, 'Event 101', 'Detected',   '2026-11-18 18:00:00', '2026-11-18 18:01:00', 640, 480, 60, 900, 90, '', 0),
 (102, 1, 1, 1, 'Event 102', 'Continuous', '2026-11-18 18:05:00', '2026-11-18 18:06:00', 640, 480, 60, 900, 10, '', 0),
 (103, 1, 1, 1, 'Event 103', 'Continuous', '2026-11-18 18:10:00', '2026-11-18 18:11:00', 640, 480, 60, 900, 0, '', 0),
 (104, 1, 1, 1, 'Event 104', 'Continuous', '2026-11-18 18:15:00', '2026-11-18 18:16:00', 640, 480, 60, 900, 0, '', 0),
 (105, 1, 1, 1, 'Event 105', 'Continuous', '2026-11-17 12:00:00', '2026-11-17 12:01:00', 640, 480, 60, 900, 30, '', 0),
 (106, 2, 1, 1, 'Event 106', 'Detected',   '2026-11-18 18:20:00', '2026-11-18 18:21:00', 640, 480, 60, 900, 90, '', 0),
 (107, 1, 1, 1, 'Event 107', 'Continuous', '2026-11-18 18:25:00', '2026-11-18 18:26:00', 640, 480, 60, 900, 40, '', 0),
 (108, 1, 1, 1, 'Event 108', 'Continuous', '2026-11-18 10:00:00', '2026-11-18 10:01:00', 640, 480, 60, 900, 40, '', 0);
INSERT INTO Tags (Name, CreatedBy) VALUES ('person', $notify), ('car', $notify), ('motorbike', $notify);
-- The event server's tags (as notify): 101 person, 102 car, 105 person (before the change), 106
-- person (camera 2), 107 motorbike, 108 person (that morning, before the change); 104 person by
-- hand (admin).
INSERT INTO Events_Tags (TagId, EventId, AssignedBy) SELECT Id, 101, $notify FROM Tags WHERE Name='person';
INSERT INTO Events_Tags (TagId, EventId, AssignedBy) SELECT Id, 102, $notify FROM Tags WHERE Name='car';
INSERT INTO Events_Tags (TagId, EventId, AssignedBy) SELECT Id, 105, $notify FROM Tags WHERE Name='person';
INSERT INTO Events_Tags (TagId, EventId, AssignedBy) SELECT Id, 106, $notify FROM Tags WHERE Name='person';
INSERT INTO Events_Tags (TagId, EventId, AssignedBy) SELECT Id, 107, $notify FROM Tags WHERE Name='motorbike';
INSERT INTO Events_Tags (TagId, EventId, AssignedBy) SELECT Id, 108, $notify FROM Tags WHERE Name='person';
INSERT INTO Events_Tags (TagId, EventId, AssignedBy) SELECT Id, 104, $admin FROM Tags WHERE Name='person';
EOF2
sql detector <<'EOF2'
CREATE TABLE IF NOT EXISTS detector_alarms (monitor_id INT UNSIGNED NOT NULL, raised_at DATETIME(6) NOT NULL, label VARCHAR(32) NOT NULL,
  first_seen DATETIME(6) NOT NULL, last_seen DATETIME(6) NOT NULL, best_confidence DECIMAL(3,2) NOT NULL, raised BOOLEAN NOT NULL,
  picture VARCHAR(128) NULL, PRIMARY KEY (monitor_id, raised_at, label), KEY by_last_seen (monitor_id, last_seen)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO detector_alarms VALUES (1, '2026-11-18 06:38:48.513541', 'person', '2026-11-18 06:38:48.513541', '2026-11-18 06:38:50', 0.8, 1, NULL),
  (2, '2026-11-18 07:10:00', 'person', '2026-11-18 07:10:00', '2026-11-18 07:10:05', 0.8, 1, NULL);
-- The rules: the default a person; viewer's own row for camera 1, person and motorcycle; admin none.
DELETE FROM alert_rules WHERE user_id <> 0;
DELETE FROM alert_users;
REPLACE INTO alert_rules (user_id, monitor_id, alerts, labels) VALUES (0, 0, 1, '["person"]');
EOF2
q "INSERT INTO detector.alert_rules (user_id, monitor_id, alerts, labels) VALUES ($viewer, 1, 1, '[\"person\",\"motorcycle\"]')"
person=$(q "SELECT Id FROM Tags WHERE Name='person'")

listids() { printf '%s' "$1" | jq -r '[.rows[].Id|tostring]|sort_by(tonumber)|join(",")'; }
# The API exactly as the app calls it: its path, then its query string and the token.
napi() { # <token> <path?query>
  case "$2" in *\?*) u="$2&token=$1" ;; *) u="$2?token=$1" ;; esac
  code=$(docker exec "$name" curl -s -o /tmp/api.out -w '%{http_code}' "http://127.0.0.1/zm/api/$u")
  body=$(docker exec "$name" cat /tmp/api.out)
}
apiids() { printf '%s' "$body" | jq -r '[.events[].Event.Id|tostring]|sort_by(tonumber)|join(",")'; }
shape='(.events|type=="array") and (.pagination|has("page") and has("current") and has("count") and has("prevPage") and has("nextPage") and has("limit") and has("pageCount"))'
# <what> <path?query> <the user's alerts> <every event>: both answer 200 with the app's keys, the
# alerts and every event as expected, and an event has the same fields either way.
replay() {
  napi "$vtok" "$2"
  is "$1: 200" 200 "$code"
  printf '%s' "$body" | jq -e "$shape" >/dev/null || { printf '%s\n' "$body" | tail -c 1500 >&2; fail "$1: the app's keys"; }
  is "$1: viewer's alerts" "$3" "$(apiids)"
  keys=$(printf '%s' "$body" | jq -c '[.events[0].Event // {} | keys[]]')
  case "$2" in *\?*) all="$2&show=all" ;; *) all="$2?show=all" ;; esac
  napi "$vtok" "$all"
  is "$1, show=all: 200" 200 "$code"
  printf '%s' "$body" | jq -e "$shape" >/dev/null || fail "$1, show=all: the app's keys"
  is "$1, show=all: every event" "$4" "$(apiids)"
  if [ "$keys" != '[]' ]; then is "$1: an event's fields as with show=all" "$(printf '%s' "$body" | jq -c '[.events[0].Event | keys[]]')" "$keys"; fi
}

# The web list, as each user.
is "the list: viewer's alerts (own row: person, motorcycle; motorbike counts; not a hand-added tag; not before the change)" \
  "101,107" "$(listids "$(upage viewer 'view=request&request=events&task=query')")"
is "the list: admin's alerts (the default, a person; both cameras)" "101,106" "$(listids "$(upage admin 'view=request&request=events&task=query')")"
is "the list, All Events: viewer's" "1,101,102,103,104,105,107,108" "$(listids "$(upage viewer 'view=request&request=events&task=query&show=all')")"
is "the list, All Events: admin's" "1,101,102,103,104,105,106,107,108" "$(listids "$(upage admin 'view=request&request=events&task=query&show=all')")"
page=$(upage viewer 'view=events')
has "the events page's Show choice" 'id="showFilterTerm"' "$page"
has "Alerts chosen by default" 'id="showAlerts" data-show="alerts" class="btn btn-primary active"' "$page"
has "All Events offered" '>All Events</button>' "$page"
has "All Events chosen from the address" 'id="showAll" data-show="all" class="btn btn-primary active"' "$(upage viewer 'view=events&show=all')"

# zmNinjaNG 2.8.0's calls (encodeURIComponent'd segments, as getEvents, getMonitorEventsSince and
# getAdjacentEvent build them).
d0='2026-11-18%2000%3A00%3A00'; d1='2026-11-18%2023%3A59%3A59'
replay "the app's Events list (no filters)" 'events/index.json?page=1&limit=100&sort=StartDateTime&direction=desc' \
  "101,107" "1,101,102,103,104,105,107,108"
replay "the app's Events list (camera, dates)" "events/index/MonitorId%3A1/StartDateTime%20%3E%3D%3A$d0/EndDateTime%20%3C%3D%3A$d1.json?page=1&limit=100&sort=StartDateTime&direction=desc" \
  "101,107" "101,102,103,104,107,108"
replay "the app's Timeline (cause Continuous, per camera)" "events/index/MonitorId%3A1/StartDateTime%20%3E%3D%3A$d0/EndDateTime%20%3C%3D%3A$d1/Cause%20REGEXP%3AContinuous.json?page=1&limit=100&sort=StartDateTime&direction=desc" \
  "107" "102,103,104,107,108"
replay "the app's tag filter" "events/index/Tags.Id%3A$person.json?page=1&limit=100&sort=StartDateTime&direction=desc" \
  "101" "101,104,105,108"
replay "the app's \"Only detected objects\"" "events/index/Notes%20REGEXP%3Adetected%3A.json?page=1&limit=100&sort=StartDateTime&direction=desc" \
  "" ""
replay "the app's new-events badge" "events/index/MonitorId%3A1/StartDateTime%20%3E%3A2026-11-18%2018%3A00%3A00.json?limit=1&sort=StartDateTime&direction=desc" \
  "107" "107"
napi "$vtok" "events/index/MonitorId%3A1/StartDateTime%20%3E%3A2026-11-18%2018%3A00%3A00.json?limit=1&sort=StartDateTime&direction=desc"
is "the badge counts viewer's alerts since" 1 "$(printf '%s' "$body" | jq -r '.pagination.count')"
napi "$vtok" "events/index/MonitorId%3A1/StartDateTime%20%3E%3A2026-11-18%2018%3A00%3A00.json?limit=1&sort=StartDateTime&direction=desc&show=all"
is "the badge, show=all, counts every event since" 4 "$(printf '%s' "$body" | jq -r '.pagination.count')"
replay "the app's next event" "events/index/StartDateTime%20%3E%3A2026-11-18%2018%3A00%3A00.json?page=1&limit=1&sort=StartDateTime&direction=asc" \
  "107" "102"
replay "the app's previous event" "events/index/StartDateTime%20%3C%3A2026-11-18%2018%3A25%3A00.json?page=1&limit=1&sort=StartDateTime&direction=desc" \
  "101" "104"
# Opening an event by id, as a push does: one that isn't an alert opens as before.
napi "$vtok" 'events/103.json'
is "the app opens an event that isn't an alert by id (a push)" 200 "$code"
is "the event by id" 103 "$(printf '%s' "$body" | jq -r '.event.Event.Id')"
# show=all as a named segment, and in the query string with no named segments (the app's bare
# /events/index.json without paging).
napi "$vtok" 'events/index/show%3Aall.json?page=1&limit=100&sort=StartDateTime&direction=desc'
is "show:all as a named segment: 200" 200 "$code"
is "show:all as a named segment: every event" "1,101,102,103,104,105,107,108" "$(apiids)"
napi "$vtok" 'events/index.json?show=all'
is "show=all, no named segments, no paging: 200" 200 "$code"
is "show=all, no named segments, no paging: every event" "1,101,102,103,104,105,107,108" "$(apiids)"
napi "$vtok" 'events/index.json'
is "no named segments, no paging: viewer's alerts" "101,107" "$(apiids)"
# consoleEvents is left alone: every event (O492).
napi "$vtok" 'events/consoleEvents/1000%20day.json'
is "consoleEvents: 200" 200 "$code"
is "consoleEvents counts every event, not only alerts" "$(q "SELECT COUNT(*) FROM Events WHERE MonitorId=1 AND StartDateTime >= NOW() - INTERVAL 1000 DAY")" \
  "$(printf '%s' "$body" | jq -r '.results["1"]')"

# The rules now: a change shows at once, past events included; all alerts off lists nothing.
q "UPDATE detector.alert_rules SET labels='[\"car\"]' WHERE user_id=$viewer AND monitor_id=1"
is "the rules now: viewer switches to cars" "102" "$(listids "$(upage viewer 'view=request&request=events&task=query')")"
napi "$vtok" 'events/index.json?page=1&limit=100&sort=StartDateTime&direction=desc'
is "the rules now, through the API" "102" "$(apiids)"
q "INSERT INTO detector.alert_users (user_id, alerts) VALUES ($viewer, 0)"
is "all alerts off: nothing listed" "" "$(listids "$(upage viewer 'view=request&request=events&task=query')")"
is "all alerts off: All Events still lists every event" "1,101,102,103,104,105,107,108" "$(listids "$(upage viewer 'view=request&request=events&task=query&show=all')")"
q "DELETE FROM detector.alert_users WHERE user_id=$viewer"
# The chart's moment (conf.d), in place of live detection's first alarm row.
docker exec "$name" sh -c 'echo "ZM_ALERTS_SINCE=2026-11-17 00:00:00" > /etc/zm/conf.d/zz-alerts-test.conf'
is "ZM_ALERTS_SINCE from conf.d: admin's alerts from 17 November" "101,105,106,108" "$(listids "$(upage admin 'view=request&request=events&task=query')")"
docker exec "$name" rm -f /etc/zm/conf.d/zz-alerts-test.conf

# The console's event counts dropped (O491); Montage Review asks for every event.
page=$(upage admin 'view=console')
has "the console still shows" 'id="consoleTableBody"' "$page"
hasnt "the console's event counts are gone" 'data-field="TotalEvents"\|data-field="HourEvents"\|data-field="MonthEvents"' "$page"
is "Montage Review asks the API for every event" 2 "$(docker exec "$name" grep -c "show=all" /usr/share/zoneminder/www/skins/classic/views/js/montagereview.js)"

# ---- A camera's timeline (cluster-docs docs/zoneminder-ui.md, section 3): its page, its links,
# and its data request; its arithmetic on its own (tests/timeline.test.js).
node ai-live-detection/tests/timeline.test.js || fail "the timeline's arithmetic"
sec() { docker exec "$name" php -r 'date_default_timezone_set("Australia/Sydney"); echo strtotime($argv[1]);' "$1"; }
t0=$(sec '2026-11-18 18:00:00')
t1=$((t0 + 3600))
# A raise of live detection's alarm at 18:15:30 (UTC 07:15:30), a person and a car.
sql detector -e "INSERT INTO detector_alarms VALUES (1, '2026-11-18 07:15:30', 'person', '2026-11-18 07:15:30', '2026-11-18 07:15:40', 0.9, 1, NULL),
  (1, '2026-11-18 07:15:30', 'car', '2026-11-18 07:15:32', '2026-11-18 07:15:38', 0.8, 0, NULL)"
page=$(upage admin 'view=cameratimeline&mid=1')
has "the timeline page" 'id="tlBar"' "$page"
has "the timeline's spans" 'data-span="30d"' "$page"
has "the timeline's script" 'cameratimeline' "$page"
has "the timeline's Live" 'id="tlLiveButton"' "$page"
has "the camera page's Timeline button" 'id="timelineButton"' "$(upage admin 'view=watch&mid=1')"
has "the event page's link to the timeline" "view=cameratimeline&amp;mid=1&amp;span=1h&amp;t=$t0" "$(upage admin 'view=event&eid=101')"
is "the console's Timeline button (its script)" 1 "$(docker exec "$name" grep -c 'view=cameratimeline' /usr/share/zoneminder/www/skins/classic/views/js/console.js)"
bar=$(upage admin "view=request&request=cameratimeline&what=bar&mid=1&from=$t0&to=$t1&cols=60")
is "the bar: recording by the minute, 18:00 to 18:06 (101, a gap, 102)" "1100011" "$(printf '%s' "$bar" | jq -r '.rec[0:7]')"
is "the bar: one alarm, at 18:15" "1:1" "$(printf '%s' "$bar" | jq -r '"\(.alarms|add):\(.alarms[15])"')"
is "the bar: admin's one alert, at 18:00 (101)" "1:1" "$(printf '%s' "$bar" | jq -r '"\(.alerts|add):\(.alerts[0])"')"
is "the bar at an hour: the alarm's types" '["car","person"]' "$(printf '%s' "$bar" | jq -c '.alarmList[0].labels')"
is "the bar at an hour: the alert's event" 101 "$(printf '%s' "$bar" | jq -r '.alertList[0].id')"
is "the bar at an hour: the alarm from its first sighting" "$((t0 + 930))" "$(printf '%s' "$bar" | jq -r '.alarmList[0].start|floor')"
bar=$(upage admin "view=request&request=cameratimeline&what=bar&mid=1&from=$((t0 - 30 * 86400))&to=$t1&cols=720")
is "the bar at 30 days: no detail" "null" "$(printf '%s' "$bar" | jq -r '.alarmList')"
is "the bar at 30 days: 720 columns" 720 "$(printf '%s' "$bar" | jq -r '.rec|length')"
sections=$(upage admin "view=request&request=cameratimeline&what=sections&mid=1&from=$t0&to=$t1")
is "the sections in the hour" "101,102,103,104,107" "$(printf '%s' "$sections" | jq -r '[.sections[].id]|join(",")')"
is "a section's start, to the second" "$t0" "$(printf '%s' "$sections" | jq -r '.sections[0].start|floor')"
is "a section's end, through its last second" "$((t0 + 61))" "$(printf '%s' "$sections" | jq -r '.sections[0].end|floor')"
has "sections for more than four hours are refused" 'up to four hours' "$(upage admin "view=request&request=cameratimeline&what=sections&mid=1&from=$t0&to=$((t0 + 5 * 3600))")"
has "a camera viewer may not view: not found" 'Not found' "$(upage viewer "view=request&request=cameratimeline&what=bar&mid=2&from=$t0&to=$t1&cols=60")"
has "its page refused to viewer" 'view=error\|Error\|Not\|error' "$(upage viewer 'view=cameratimeline&mid=2')"

# ---- Export from the timeline (cluster-docs docs/zoneminder-ui.md, section 4; O486, O495, O497):
# one MP4 joined across the sections, cut to the part, up to an hour; only whoever asked fetches it.
q "UPDATE Events SET DefaultVideo=CONCAT(Id, '-video.mp4'), Videoed=1 WHERE Id IN (101, 102)"
for e in 101 102; do
  docker exec "$name" sh -c "d=/var/cache/zoneminder/events/1/2026-11-18/$e && mkdir -p \$d && ffmpeg -loglevel error -y -f lavfi -i testsrc2=size=320x240:rate=15 -t 61 -c:v libx264 -preset ultrafast -g 15 -pix_fmt yuv420p -movflags +faststart \$d/$e-video.mp4 && chown -R www-data \$d" \
    || fail "test recordings"
done
# 18:00:30 to 18:05:30: the last 31 s of 101 (it ends 18:01:00, through that second), a gap, the first 30 s of 102.
job=$(upage admin "view=request&request=cameratimeline&what=export&mid=1&from=$((t0 + 30))&to=$((t0 + 330))" | jq -r '.job // empty')
test -n "$job" || fail "the export didn't start"
ok "the export starts"
for i in $(seq 30); do
  state=$(upage admin "view=request&request=cameratimeline&what=exportstatus&mid=1&from=0&to=1&job=$job" | jq -r '.state')
  [ "$state" = making ] || break
  sleep 1
done
is "the export is made" ready "$state"
has "only whoever asked may fetch it" 'No such export' "$(upage viewer "view=request&request=cameratimeline&what=exportstatus&mid=1&from=0&to=1&job=$job")"
docker exec "$name" curl -s -D /tmp/export.head -o /tmp/export.mp4 -c /tmp/admin.jar -b /tmp/admin.jar \
  "http://127.0.0.1/zm/index.php?view=request&request=cameratimeline&what=exportfile&mid=1&from=0&to=1&job=$job"
has "a download, named for the camera and the part" 'attachment; filename="Smoke camera 2026-11-18 18.00.30 to 18.05.30.mp4"' "$(docker exec "$name" cat /tmp/export.head)"
length=$(docker exec "$name" ffprobe -v error -show_entries format=duration -of csv=p=0 /tmp/export.mp4)
is "one MP4 of the part: 61 s, give or take a keyframe ($length)" yes "$(echo "$length" | awk '{print ($1 >= 59 && $1 <= 64) ? "yes" : "no"}')"
is "fetched, it's gone" 0 "$(docker exec "$name" sh -c 'ls /var/tmp/zm/timeline 2>/dev/null | wc -l' | tr -d ' ')"
has "over an hour is refused" 'up to an hour' "$(upage admin "view=request&request=cameratimeline&what=export&mid=1&from=$t0&to=$((t0 + 3700))")"
has "the timeline's Export" 'id="tlExport"' "$(upage admin 'view=cameratimeline&mid=1')"

# ---- With boxes (cluster-docs docs/zoneminder-boxed-export.md; O486, O496, O501-O504): the page
# writes the parts and live detection's boxes for the boxed exporter's Job, and waits its turn.
sql detector -e "INSERT INTO detector_frames VALUES (1, '2026-11-18 07:00:40.000000', 1, '[{\"label\":\"person\",\"confidence\":0.87,\"box\":[0.25,0.25,0.75,0.75],\"track\":1}]'),
  (1, '2026-11-18 07:00:40.200000', 2, '[{\"label\":\"person\",\"confidence\":0.88,\"box\":[0.26,0.25,0.76,0.75],\"track\":1}]')"
job=$(upage admin "view=request&request=cameratimeline&what=export&mid=1&from=$((t0 + 30))&to=$((t0 + 330))&boxes=1" | jq -r '.job // empty')
test -n "$job" || fail "the boxed export didn't start"
dir=/var/tmp/zm/timeline/$job
is "a boxed export waits for the exporter" waiting "$(upage admin "view=request&request=cameratimeline&what=exportstatus&mid=1&from=0&to=1&job=$job" | jq -r '.state')"
req=$(docker exec "$name" cat "$dir/request.json")
is "its request: the camera's own size" "640x480" "$(printf '%s' "$req" | jq -r '"\(.width)x\(.height)"')"
is "its request: the parts, 101's last 31 s and 102's first 30 s" "101:30:61 102:0:30" \
  "$(printf '%s' "$req" | jq -r '[.parts[] | "\(.file|capture("/(?<e>[0-9]+)-video").e):\(.in|round):\(.out|round)"] | join(" ")')"
is "its request: each part from its section's first frame" "$t0" "$(printf '%s' "$req" | jq -r '.parts[0].start|floor')"
is "its boxes: live detection's frames in the part" 2 "$(docker exec "$name" cat "$dir/boxes.json" | jq '.frames|length')"
is "nothing made in ZoneMinder's pod" no "$(docker exec "$name" sh -c "test -e $dir/out.part -o -e $dir/out.mp4 && echo yes || echo no")"
# The exporter starts its Job; the Job leaves the file.
docker exec "$name" sh -c "touch $dir/started"
is "once started, it's being made" making "$(upage admin "view=request&request=cameratimeline&what=exportstatus&mid=1&from=0&to=1&job=$job" | jq -r '.state')"
docker exec "$name" sh -c "cp /var/cache/zoneminder/events/1/2026-11-18/101/101-video.mp4 $dir/out.mp4"
is "the Job's file is ready" ready "$(upage admin "view=request&request=cameratimeline&what=exportstatus&mid=1&from=0&to=1&job=$job" | jq -r '.state')"
has "the timeline's With boxes choice" 'name="tlExportBoxes" value="1"' "$(upage admin 'view=cameratimeline&mid=1')"

echo "smoke test passed"
