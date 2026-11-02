#!/usr/bin/env python3
"""HTTP smoke tests. Use a disposable site; creates and removes its own records.
Env: KB_BASE_URL, KB_ADMIN_EMAIL, KB_ADMIN_PASSWORD, KB_MEMBER_EMAIL,
KB_MEMBER_PASSWORD (a verified member). KB_INSTALL=1 additionally exercises the
installer using KB_DB_HOST/PORT/NAME/USER/PASSWORD and the explicit local fixture.
"""
import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = os.environ.get("KB_BASE_URL", "http://127.0.0.1:8080").rstrip("/")
RUN = uuid.uuid4().hex[:8]

class Client:
    def __init__(self):
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def get(self, path):
        try:
            r = self.op.open(BASE + path)
            return r.status, r.url, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.url, e.read().decode()
    def token(self, path):
        return re.search(r'name="_token" value="([^"]+)', self.get(path)[2]).group(1)
    def post(self, path, data, token=None, origin=None):
        data = {**data, "_token": token or self.token(path)}
        headers = {"Content-Type": "application/x-www-form-urlencoded"}
        if origin: headers["Origin"] = origin
        req = urllib.request.Request(BASE + path, urllib.parse.urlencode(data).encode(), headers)
        try:
            r = self.op.open(req)
            return r.status, r.url, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.url, e.read().decode()
    def api(self, path, data, token=None, origin=None):
        headers = {"Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": token or self.token("/dashboard")}
        if origin: headers["Origin"] = origin
        req = urllib.request.Request(BASE + path, json.dumps(data).encode(), headers)
        try:
            r = self.op.open(req)
            return r.status, json.load(r)
        except urllib.error.HTTPError as e:
            return e.code, json.load(e)

def ok(condition, label):
    if not condition: raise AssertionError(label)
    print("PASS", label)

if os.environ.get("KB_INSTALL") == "1":
    c = Client()
    ok(c.get("/")[1].endswith("/install"), "first access opens installer")
    steps = [
        {"step": "1", "site_url": BASE, "activation_key": "LOCAL-PREVIEW-ONLY", "accept": "1"},
        {"step": "2", "db_host": os.environ.get("KB_DB_HOST", "127.0.0.1"), "db_port": os.environ.get("KB_DB_PORT", "3306"), "db_name": os.environ["KB_DB_NAME"], "db_user": os.environ["KB_DB_USER"], "db_password": os.environ["KB_DB_PASSWORD"]},
        {"step": "3", "name": "Smoke Administrator", "email": os.environ["KB_ADMIN_EMAIL"], "password": os.environ["KB_ADMIN_PASSWORD"], "password_confirm": os.environ["KB_ADMIN_PASSWORD"]},
    ]
    for data in steps:
        code, url, html = c.post("/install", data)
        ok(code == 200 and "notice error" not in html, "installer step " + data["step"])
    ok("Your community is ready" in c.get("/install")[2], "installer completion instructions")

admin = Client()
ok(admin.post("/login", {"email": os.environ["KB_ADMIN_EMAIL"], "password": os.environ["KB_ADMIN_PASSWORD"]})[1].endswith("/admin"), "administrator login")
for path in ["/admin", "/admin/questions", "/admin/users", "/admin/results", "/admin/settings", "/admin/smtp", "/admin/notifications", "/admin/audit"]:
    code, _, html = admin.get(path)
    ok(code == 200 and "Temporarily unavailable" not in html, "admin page " + path)
ok(admin.post("/admin/settings", {"site_title": "KINGDOMBUILDERS", "site_tagline": "Grow in the Word.", "admin_email": os.environ["KB_ADMIN_EMAIL"], "registration_open": "1", "quiz_open": "1", "questions_per_attempt": "3", "seconds_per_question": "300", "pass_mark": "50", "max_tab_switches": "0", "timer_enabled": "1", "randomize_questions": "1", "randomize_answers": "1"})[0] == 200, "save quiz settings")
ok(b"K" in urllib.request.urlopen(BASE + "/favicon.svg").read(), "dynamic favicon")
question = {"action": "save", "id": "0", "category": "Smoke", "question_text": "Smoke question " + RUN, "option_a": "A", "option_b": "B", "option_c": "C", "option_d": "D", "correct_option": "0", "reference": "Test", "explanation": "", "is_active": "1"}
ok(admin.post("/admin/questions", question)[0] == 200, "create question")
html = admin.get("/admin/questions?q=" + RUN)[2]
qid = re.search(r'name="id" value="(\d+)"><input type="hidden" name="action" value="delete"', html).group(1)
question.update(id=qid, question_text="Edited smoke question " + RUN)
ok(admin.post("/admin/questions", question)[0] == 200, "edit question")
ok(admin.post("/admin/questions", {"action": "delete", "id": qid})[0] == 200, "delete question")
email = f"smoke-{RUN}@example.test"
password = "Smoke-Passphrase-2026!"
ok(admin.post("/admin/users", {"action": "save", "id": "0", "name": "Smoke Member", "email": email, "role": "member", "status": "active", "password": password})[0] == 200, "administrator creates member")
html = admin.get("/admin/users?q=" + RUN)[2]
uid = re.search(r'name="id" value="(\d+)"><button name="action"', html).group(1)
ok(admin.post("/admin/users", {"action": "suspend", "id": uid})[0] == 200, "suspend member")
ok(admin.post("/admin/users", {"action": "unsuspend", "id": uid})[0] == 200, "unsuspend member")
ok(admin.post("/admin/users", {"action": "delete", "id": uid})[0] == 200, "delete member")
reg = Client()
reg_email = f"register-{RUN}@example.test"
code, _, html = reg.post("/register", {"name": "Registered Member", "email": reg_email, "password": password, "password_confirm": password, "accept": "1"})
ok(code == 200 and "confirmation message has been queued" in html, "public registration queues confirmation")
ok(reg.post("/login", {"email": reg_email, "password": password})[1].endswith("/dashboard"), "registered member can sign in")
ok("confirm" in reg.get("/dashboard")[2].lower(), "unverified member is gated")
html = admin.get("/admin/users?q=" + RUN)[2]
rid = re.search(r'name="id" value="(\d+)"><button name="action"', html).group(1)
ok(admin.post("/admin/users", {"action": "delete", "id": rid})[0] == 200, "cleanup registered member")
if not os.environ.get("KB_MEMBER_ID"):
    print("Installer/admin checks passed. Set KB_MEMBER_ID to run member and support-session checks.")
    raise SystemExit(0)

member = Client()
ok(member.post("/login", {"email": os.environ["KB_MEMBER_EMAIL"], "password": os.environ["KB_MEMBER_PASSWORD"]})[1].endswith("/dashboard"), "member login")
ok(member.post("/quiz/start", {})[1].startswith(BASE + "/quiz?id="), "start/resume quiz")
# The start POST already follows the redirect; read the active attempt link from the dashboard.
html = member.get("/dashboard")[2]
match = re.search(r'/quiz\?id=([a-f0-9-]+)', html)
if match:
    # Finish the active attempt through the API before starting a clean one.
    p = "/api/quiz?id=" + match.group(1)
    state = member.api(p, {"action": "state"})[1]
    if state["status"] == "active": member.api(p, {"action": "finish"})
_, url, _ = member.post("/quiz/start", {})
attempt = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)["id"][0]
p = "/api/quiz?id=" + attempt
token = member.token("/dashboard")
code, state = member.api(p, {"action": "state"}, token)
ok(code == 200 and state["status"] == "active", "quiz state")
ok(all("correct_option" not in q and "explanation" not in q for q in state["questions"]), "active payload hides answer keys")
ok(member.api(p, {"action": "state"}, "wrong")[0] == 419, "CSRF rejection")
ok(member.api(p, {"action": "state"}, token, origin="https://evil.example")[0] == 403, "cross-origin rejection")
for q in state["questions"]:
    ok(member.api(p, {"action": "answer", "position": q["position"], "choice": 0}, token)[0] == 200, "save answer")
ok(member.api(p, {"action": "answer", "position": 0, "choice": 1}, token)[0] == 409, "forward-only enforcement")
ok(member.api(p, {"action": "finish"}, token)[1]["status"] == "submitted", "server-side scoring")
ok(member.get("/result?id=" + attempt)[0] == 200, "member result")
ok(admin.api(p, {"action": "state"}, admin.token("/admin"))[0] == 404, "attempt ownership")
ok(admin.get("/admin/results/export")[0] == 200, "CSV export")

# Impersonation uses the separately supplied verified member.
ok(admin.post("/admin/impersonate", {"id": os.environ["KB_MEMBER_ID"]})[1].endswith("/dashboard"), "audited support login")
ok("Support session" in admin.get("/dashboard")[2], "support session banner")
ok(admin.get("/admin")[0] == 403, "support session cannot administer")
ok(admin.post("/support/return", {})[1].endswith("/admin"), "return to administrator")
ok(admin.get("/admin")[0] == 200, "administrator access restored")
print("HTTP smoke tests passed.")
