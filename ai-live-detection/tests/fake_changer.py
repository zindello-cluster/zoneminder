"""A fake of the camera changer's API, for the Camera tab's smoke test (smoke.sh): the two calls the
tab makes (cluster-docs docs/zoneminder-camera-changer-pod.md, section 11; O803, O808), answered as
the changer answers them for a VIGI C340. It writes every request it was asked, and what it holds,
to <state file> after each, for the test to read. A file /tmp/changer-refuse makes it answer as for
a camera that refused its login.

    python3 fake_changer.py <address> <port> <state file>
"""
import json
import os
import re
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

address, port, state_file = sys.argv[1], int(sys.argv[2]), sys.argv[3]
state = {"calls": [], "values": {"night_vision_mode": "md_night_vision", "inf_type": "auto", "luma": "50", "osd_label_1": "on"}}
LABELS = {"night_vision_mode": ("Lighting Mode", {"inf_night_vision": "Infrared Lighting", "md_night_vision": "Motion-triggered Color",
                                                  "wtl_night_vision": "White Light Illumination"}),
          "inf_type": ("Infrared Light", {"auto": "Auto", "on": "Always On", "off": "Always Off"}),
          "luma": ("Brightness", None), "osd_label_1": ("Channel Name", None)}


def view():
    v = state["values"]
    return {"camera": {"model": "VIGI C340", "firmware": "2.0.0 Build 231121 Rel.79722n", "mac": "98-25-4A-E7-00-78"},
            "profile": "common", "editable": True,
            "fields": [{"id": "night_vision_mode", "group": "Fill Light Settings", "label": "Lighting Mode", "type": "select",
                        "value": v["night_vision_mode"], "editable": True, "modeSpecific": False, "options": LABELS["night_vision_mode"][1]},
                       {"id": "inf_type", "group": "Fill Light Settings", "label": "Infrared Light", "type": "select",
                        "value": v["inf_type"], "editable": True, "modeSpecific": True, "options": LABELS["inf_type"][1]},
                       {"id": "luma", "group": "Picture", "label": "Brightness", "type": "level", "value": v["luma"],
                        "editable": True, "modeSpecific": False, "min": 0, "max": 100},
                       {"id": "osd_label_1", "group": "On-screen text", "label": "Channel Name", "type": "switch",
                        "value": v["osd_label_1"], "editable": True, "modeSpecific": False, "text": "VIGI C340 2.0"}],
            "setup": {"Main stream": "H264 · 2560×1440 · 15 fps · VBR to 3072 kb/s · H.264+ Off"}}


class Handler(BaseHTTPRequestHandler):
    def answer(self, code, body):
        data = json.dumps(body).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)
        with open(state_file, "w") as f:
            json.dump(state, f)

    def handle_any(self, method):
        n = int(self.headers.get("Content-Length") or 0)
        body = json.loads(self.rfile.read(n).decode() or "{}") if n else None
        state["calls"].append({"method": method, "path": self.path, "body": body})
        m = re.fullmatch(r"/cameras/([0-9]+)/settings", self.path)
        if not m:
            return self.answer(404, {"error": "not found"})
        if m.group(1) != "1":
            return self.answer(404, {"error": "not a camera camera setup sets"})
        if os.path.exists("/tmp/changer-refuse"):
            return self.answer(200, {"refused": "The Smoke camera refused its login (login refused, 9 tries left of 10). Not tried again.", "at": 1})
        if method == "GET":
            return self.answer(200, {"camera": view(), "at": 1})
        words = []
        for k, v in body["changes"].items():
            if k not in LABELS:
                return self.answer(200, {"failed": "The Smoke camera has no setting %s." % k, "at": 1})
            label, opts = LABELS[k]
            if opts is not None and v not in opts:
                return self.answer(200, {"failed": "The Smoke camera can't take %s for %s." % (v, label), "at": 1})
            if state["values"][k] != v:
                was = state["values"][k]
                words.append("%s %s → %s" % (label, opts.get(was, was) if opts else was, opts.get(v, v) if opts else v))
                state["values"][k] = v
        out = dict(view(), changedWords="; ".join(words), notTaken=[])
        return self.answer(200, {"camera": out, "at": 1})

    def do_GET(self):
        self.handle_any("GET")

    def do_POST(self):
        self.handle_any("POST")

    def log_message(self, *a):
        pass


HTTPServer((address, port), Handler).serve_forever()
