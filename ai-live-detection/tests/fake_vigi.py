"""A fake VIGI camera's web API, for the Camera tab's smoke test (smoke.sh): the calls camera setup
and the tab make, answered as the spare camera (a VIGI C340, firmware 2.0.0 Build 231121) answered
them on 18 November 2026, with the md5 login (encrypt_type 1). It writes what it holds and how many
logins it was asked for to <state file> after every request, for the test to read.

    python3 fake_vigi.py <address> <port> <certificate> <key> <password> <state file>
"""
import copy
import hashlib
import json
import ssl
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

address, port, cert, key, password, state_file = sys.argv[1:7]
EXPECT = hashlib.md5(("TPCQ75NF2Y:" + password).encode()).hexdigest().upper()
TOKEN = "fake-session"

state = {
    "logins": 0, "refused": 0,
    "image": {
        "switch": {"night_vision_mode": "md_night_vision", "switch_mode": "common", "flip_type": "off", "wtl_intensity_level": "3"},
        "common": {"inf_type": "auto", "wtl_type": "auto", "smartwtl": "auto_wtl", "smartwtl_digital_level": "50",
                   "inf_sensitivity": "4", "wtl_sensitivity": "4", "inf_delay": "5", "wtl_delay": "5", "luma": "50",
                   "contrast": "50", "saturation": "50", "sharpness": "50", "wide_dynamic": "off",
                   "high_light_compensation": "off", "dehaze": "off", "wb_type": "auto", "auto_exp_antiflicker": "off"},
    },
    "OSD": {
        "date": {"x_coor": "312", "y_coor": "278", "enabled": "on"},
        "week": {"x_coor": "5312", "y_coor": "278", "enabled": "on"},
        "label_info_1": {"x_coor": 312, "y_coor": 9222, "align_type": 0, "enabled": "on", "text": "VIGI%20C340%202.0"},
        "label_info_2": {"x_coor": 312, "y_coor": 8667, "align_type": 0, "enabled": "off", "text": "custom%201"},
        "label_info_3": {"x_coor": 312, "y_coor": 833, "align_type": 0, "enabled": "off", "text": "custom%202"},
    },
    "device_info": {"basic_info": {"device_model": "VIGI%20C340", "sw_version": "2.0.0%20Build%20231121%20Rel.79722n", "mac": "98-25-4A-E7-00-78"}},
    "video": {"main": {"encode_type": "H264", "resolution": "2560*1440", "frame_rate": "65551", "bitrate_type": "vbr", "bitrate": "3072", "smart_codec": "off"},
              "minor": {"encode_type": "H264", "resolution": "640*480", "frame_rate": "65541", "bitrate_type": "vbr", "bitrate": "256"}},
    "system": {"basic": {"timezone": "UTC%2b10%3a00", "zone_id": "Australia%2fCanberra", "timing_mode": "ntp"},
               "dst_manual": {"enabled": "on"}, "ntp": {"server": "192.168.203.1"}},
    "protocol": {"wan": {"wan_type": "static"}, "static": {"ipaddr": "192.168.203.199"}},
    "sets": [],
}


def save():
    with open(state_file, "w") as f:
        json.dump(state, f)


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def answer(self, obj):
        body = json.dumps(obj).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        save()

    def do_POST(self):
        req = json.loads(self.rfile.read(int(self.headers.get("Content-Length", "0"))) or b"{}")
        if self.path == "/":
            if "user_management" in req:
                return self.answer({"error_code": -40401, "data": {"code": -40407, "encrypt_type": ["1"], "nonce": "n0nce"}})
            if "login" in req:
                state["logins"] += 1
                if req["login"].get("password") == EXPECT and req["login"].get("username") == "admin":
                    return self.answer({"error_code": 0, "stok": TOKEN, "user_group": "root"})
                state["refused"] += 1
                return self.answer({"error_code": -40401, "data": {"code": -40401, "time": 10 - state["refused"], "max_time": 10}})
            return self.answer({"error_code": -40106})
        if self.path != "/stok=" + TOKEN + "/ds":
            return self.answer({"error_code": -40401, "data": {"code": -40401}})
        method = req.pop("method", None)
        if method == "do":
            return self.answer({"error_code": 0})
        if method == "get":
            out = {"error_code": 0}
            for section, ask in req.items():
                if "table" in ask:
                    rows = [{k: v} for k, v in state[section].items() if k.startswith(ask["table"] + "_")]
                    out[section] = {ask["table"]: rows}
                else:
                    names = ask["name"] if isinstance(ask["name"], list) else [ask["name"]]
                    out[section] = {n: copy.deepcopy(state[section][n]) for n in names if n in state.get(section, {})}
            return self.answer(out)
        if method == "set":
            state["sets"].append(req)
            for section, names in req.items():
                for n, values in names.items():
                    state[section].setdefault(n, {}).update(values)
            return self.answer({"error_code": 0})
        return self.answer({"error_code": -40106})


server = HTTPServer((address, int(port)), Handler)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(cert, key)
server.socket = ctx.wrap_socket(server.socket, server_side=True)
save()
server.serve_forever()
