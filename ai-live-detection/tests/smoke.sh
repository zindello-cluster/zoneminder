#!/bin/sh
# The built image, started against a throwaway MariaDB, with each page we change checked for our
# parts (AI-LIVE-DETECTION.md, "The image"). ZoneMinder loads its own schema at first start; the
# detector tables are made here as the chart's seed makes them, with one camera, one event and two
# frames with boxes; then, signed in as the fresh install's admin, each page is fetched and grepped.
#
#   ai-live-detection/tests/smoke.sh <image>
set -eu
image=$1
net=zm-smoke-$$
pass=throwaway

cleanup() { docker rm -f zm-smoke zm-smoke-db >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$net" >/dev/null
docker run -d --name zm-smoke-db --network "$net" -e MARIADB_ROOT_PASSWORD=$pass -e MARIADB_DATABASE=zm \
  -e MARIADB_USER=zmuser -e MARIADB_PASSWORD=$pass docker.io/library/mariadb:12.3.3 >/dev/null
# The image's own readiness check, over TCP: the entrypoint's temporary server doesn't pass it.
for i in $(seq 60); do
  docker exec zm-smoke-db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
  sleep 2
done
docker exec zm-smoke-db healthcheck.sh --connect --innodb_initialized
sql() { docker exec -i zm-smoke-db mariadb -uroot -p$pass "$@"; }
sql -e "SET GLOBAL log_bin_trust_function_creators=1; CREATE DATABASE detector; GRANT ALL ON detector.* TO 'zmuser'@'%';"

docker run -d --name zm-smoke --network "$net" --shm-size 256m -e TZ=Australia/Sydney \
  -e ZM_DB_HOST=zm-smoke-db -e ZM_DB_NAME=zm -e ZM_DB_USER=zmuser -e ZM_DB_PASS=$pass "$image" >/dev/null
for i in $(seq 120); do
  if docker exec zm-smoke curl -sf -o /dev/null http://127.0.0.1/zm/ 2>/dev/null; then break; fi
  sleep 2
done
docker exec zm-smoke curl -sf -o /dev/null http://127.0.0.1/zm/ || { docker logs zm-smoke | tail -50; echo "ZoneMinder didn't start" >&2; exit 1; }

# The detector tables as the chart's seed makes them (deploy/live/schema.sql there), and a camera,
# an event and two frames with boxes.
sql detector <<'EOF'
CREATE TABLE detector_settings (monitor_id INT UNSIGNED NOT NULL PRIMARY KEY, labels JSON NOT NULL, min_confidence DECIMAL(3,2) NOT NULL,
  per_second TINYINT UNSIGNED NOT NULL, live BOOLEAN NOT NULL, boxes_default BOOLEAN NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE detector_frames (monitor_id INT UNSIGNED NOT NULL, frame_time DATETIME(6) NOT NULL, frame_number INT UNSIGNED NOT NULL,
  boxes JSON NOT NULL, PRIMARY KEY (monitor_id, frame_time)) ENGINE=InnoDB;
CREATE TABLE detector_events (event_id BIGINT UNSIGNED NOT NULL, label VARCHAR(32) NOT NULL, monitor_id INT UNSIGNED NOT NULL,
  video_start DATETIME(6) NOT NULL, first_seen DATETIME(6) NOT NULL, last_seen DATETIME(6) NOT NULL, frames INT UNSIGNED NOT NULL,
  best_confidence DECIMAL(3,2) NOT NULL, PRIMARY KEY (event_id, label), KEY by_label (label, first_seen), KEY by_camera (monitor_id, first_seen)) ENGINE=InnoDB;
INSERT INTO detector_settings VALUES (1, '["person","car"]', 0.60, 5, 1, 1, NOW());
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
token=$(docker exec zm-smoke curl -sf -X POST -d 'user=admin&pass=admin' http://127.0.0.1/zm/api/host/login.json \
  | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p' || true)
auth=${token:+"&token=$token"}
page() { docker exec zm-smoke curl -sf "http://127.0.0.1/zm/index.php?$1$auth"; }
must() { # <what> <pattern> <page query>
  if page "$3" | grep -q -- "$2"; then echo "ok: $1"; return; fi
  echo "MISSING: $1 ($2 in ?$3)" >&2
  docker exec zm-smoke curl -s -w '\nHTTP %{http_code} %{redirect_url}\n' "http://127.0.0.1/zm/index.php?$3$auth" | tail -c 3000 >&2
  docker exec zm-smoke sh -c 'tail -n 30 /var/log/apache2/error.log /var/log/zm/*.log 2>/dev/null' >&2 || true
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
must "the events list's Object filter" 'id="objectFilter"' 'view=events'
must "the boxes request: the event's frames" '"frames":\[{"t":1794443200.2' 'view=request&request=detections&what=event&eid=1'
must "the boxes request: the event's start" '"video_start":1794443200' 'view=request&request=detections&what=event&eid=1'
must "the boxes request: live" '"interval":0.2' 'view=request&request=detections&what=live&mid=1&since=1794443200'
# The event's Id, as a number or a string, in the list's rows.
must "the Object filter finds the event" '"Id":"\?1"\?[,}]' 'view=request&request=events&task=query&object=person'
if page 'view=request&request=events&task=query&object=car' | grep -q '"Id":"\?1"\?[,}]'; then echo "the Object filter let a car through" >&2; exit 1; fi
echo "ok: the Object filter keeps the event out for an object it hasn't got"
echo "smoke test passed"
