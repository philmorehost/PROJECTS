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
        if data["step"] == "3":
            ok("Your community is ready" in html, "installer completion instructions")
    ok(c.get("/install")[0] == 404, "installer locks after completion")

public = Client()
code, _, html = public.get("/")
ok(code == 200 and "Build your knowledge" in html and "Active questions" in html, "modern landing page renders")

admin = Client()
ok(admin.post("/login", {"email": os.environ["KB_ADMIN_EMAIL"], "password": os.environ["KB_ADMIN_PASSWORD"]})[1].endswith("/admin"), "administrator login")
ok("pass rate" in admin.get("/admin")[2], "admin dashboard insights render")
for path in ["/admin", "/admin/questions", "/admin/users", "/admin/results", "/admin/settings", "/admin/smtp", "/admin/cron", "/admin/notifications", "/admin/audit"]:
    code, _, html = admin.get(path)
    ok(code == 200 and "Temporarily unavailable" not in html, "admin page " + path)
smtp_form = {"smtp_enabled": "1", "smtp_host": "mail.example.test", "smtp_port": "587", "smtp_security": "tls", "smtp_username": "postmaster@example.test", "smtp_password": "Very-Secret-App-Password-1", "from_name": "KINGDOMBUILDERS", "from_email": "notifications@example.test", "php_cli_path": "", "action": "save"}
ok(admin.post("/admin/smtp", smtp_form)[0] == 200, "save SMTP settings")
html = admin.get("/admin/smtp")[2]
ok("mail.example.test" in html and "Delivery enabled" in html, "SMTP settings persist after reload")
ok("Very-Secret-App-Password-1" not in html, "SMTP password never rendered")
ok("cron-command" in html and "Copy command" in html and "bin/worker.php" in html, "cron command is copyable")
fail_form = dict(smtp_form, smtp_host="127.0.0.1", smtp_port="1", action="test")
code, _, html = admin.post("/admin/smtp", fail_form)
ok(code == 200 and "Test email failed" in html and "Could not connect" in html, "SMTP test reports a safe diagnosis")
ok("Very-Secret-App-Password-1" not in html, "failed SMTP test does not leak the password")
ok("mail.example.test" in admin.get("/admin/smtp")[2], "unsaved test values do not replace saved SMTP settings")
html = admin.get("/admin/notifications")[2]
ok("cron-command" in html, "notifications page exposes the cron command")
ok("Deliver queued messages now" in html, "notifications page can deliver without cron")
ok("scheduled task" in admin.get("/admin")[2].lower(), "dashboard warns when the worker has never run")
site_settings = {"site_title": "KINGDOMBUILDERS", "site_tagline": "Grow in the Word.", "admin_email": os.environ["KB_ADMIN_EMAIL"], "registration_open": "1", "quiz_open": "1", "questions_per_attempt": "3", "seconds_per_question": "300", "pass_mark": "50", "max_tab_switches": "0", "timer_enabled": "1", "randomize_questions": "1", "randomize_answers": "1"}
ok(admin.post("/admin/settings", site_settings)[0] == 200, "save quiz settings")
html = admin.get("/admin/settings")[2]
ok("cron-command" in html and "Copy command" in html, "settings page offers the copyable cron command")
html = admin.get("/admin/cron")[2]
ok("Copy command" in html and "How to add this cron job" in html and "KB_CONFIG_DIR" in html, "dedicated cron page gives copyable command and instructions")
ok('"build"' in public.get("/health")[2], "health reports the deployed build")
ok(admin.post("/admin/settings", dict(site_settings, large_text="1"))[0] == 200, "enable extra-large text")
ok('class="text-large"' in public.get("/")[2], "extra-large text applies site-wide")
ok(admin.post("/admin/settings", site_settings)[0] == 200, "restore standard text")
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
ok("Best score" in member.get("/dashboard")[2], "member dashboard stats render")
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
