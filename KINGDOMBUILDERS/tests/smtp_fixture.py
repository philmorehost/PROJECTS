#!/usr/bin/env python3
"""Real SMTP socket tests against a disposable PHP/MySQL test configuration.
Requires: pip install aiosmtpd; KB_CONFIG_DIR and KB_LOCAL_PREVIEW=1; PHP_BIN.
The PHP helper refuses databases not ending in _test. No external mail is sent.

Servers exercised:
  1. unauthenticated capture — transaction, Unicode headers, dot-stuffing;
  2. authenticated capture (aiosmtpd) — AUTH PLAIN read from a real EHLO reply,
     the regression that made every provider look unsupported;
  3. LOGIN-only server — providers that offer no PLAIN mechanism;
  4. refusing server — wrong credentials report stage/reply code, never the secret;
  5. XOAUTH2-only server — an honest explanation instead of a generic failure.
"""
import asyncio
import base64
import json
import os
import quopri
import socket
import socketserver
import subprocess
import threading
import uuid
from pathlib import Path

from aiosmtpd.controller import Controller
from aiosmtpd.smtp import AuthResult, LoginPassword

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN", "php")
USER = "user@example.test"
PASSWORD = "app-password-never-print"


class Capture:
    def __init__(self):
        self.messages = []

    async def handle_DATA(self, server, session, envelope):
        self.messages.append((envelope.mail_from, list(envelope.rcpt_tos), envelope.content))
        return "250 Message accepted for local test"


def authenticator(server, session, envelope, mechanism, auth_data):
    if not isinstance(auth_data, LoginPassword):
        return AuthResult(success=False, handled=False)
    return AuthResult(success=auth_data.login == USER.encode() and auth_data.password == PASSWORD.encode())


class SilentServer(socketserver.ThreadingTCPServer):
    """Accepts the TCP connection and never sends a greeting."""

    allow_reuse_address = True
    daemon_threads = True


class SilentHandler(socketserver.BaseRequestHandler):
    def handle(self):
        self.request.settimeout(20)
        try:
            while self.request.recv(1024):
                pass
        except OSError:
            pass


class NotSmtpServer(SilentServer):
    """Answers with something that is not SMTP, like a POP3 or HTTPS port."""

    def __init__(self, address):
        self.banner = b"+OK POP3 server ready\r\n"
        super().__init__(address, NotSmtpHandler)


class NotSmtpHandler(socketserver.BaseRequestHandler):
    def handle(self):
        self.server.banner  # documented above; sent immediately
        self.request.sendall(getattr(self.server, "banner", b"+OK ready\r\n"))
        self.request.settimeout(20)
        try:
            while self.request.recv(1024):
                pass
        except OSError:
            pass


class ScriptedServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def __init__(self, address, ehlo_lines, accept_login):
        self.ehlo_lines = ehlo_lines
        self.accept_login = accept_login
        self.messages = []
        super().__init__(address, ScriptedHandler)


class ScriptedHandler(socketserver.StreamRequestHandler):
    """Small deterministic SMTP server for the paths aiosmtpd cannot express."""

    def reply(self, text: bytes) -> None:
        self.wfile.write(text)

    def handle(self):
        self.reply(b"220 script.local ESMTP test\r\n")
        authenticated = False
        in_data = False
        payload = []
        while True:
            raw = self.rfile.readline()
            if not raw:
                return
            if in_data:
                if raw.strip() == b".":
                    in_data = False
                    self.server.messages.append(b"".join(payload))
                    payload = []
                    self.reply(b"250 2.0.0 Message accepted\r\n")
                else:
                    payload.append(raw)
                continue
            command = raw.decode("utf-8", "replace").strip()
            upper = command.upper()
            if upper.startswith("EHLO"):
                self.reply(self.server.ehlo_lines)
            elif upper.startswith("AUTH LOGIN") and self.server.accept_login:
                self.reply(b"334 VXNlcm5hbWU6\r\n")
                login = base64.b64decode(self.rfile.readline().strip())
                self.reply(b"334 UGFzc3dvcmQ6\r\n")
                secret = base64.b64decode(self.rfile.readline().strip())
                if login == USER.encode() and secret == PASSWORD.encode():
                    authenticated = True
                    self.reply(b"235 2.7.0 Authentication successful\r\n")
                else:
                    self.reply(b"535 5.7.8 Authentication credentials invalid\r\n")
            elif upper.startswith("AUTH"):
                self.reply(b"504 5.5.4 Unrecognized authentication type\r\n")
            elif upper.startswith("MAIL FROM") or upper.startswith("RCPT TO"):
                if authenticated:
                    self.reply(b"250 OK\r\n")
                else:
                    self.reply(b"530 5.7.0 Authentication required\r\n")
            elif upper == "DATA":
                if authenticated:
                    in_data = True
                    self.reply(b"354 End data with <CR><LF>.<CR><LF>\r\n")
                else:
                    self.reply(b"530 5.7.0 Authentication required\r\n")
            elif upper == "QUIT":
                self.reply(b"221 Bye\r\n")
                return
            else:
                self.reply(b"250 OK\r\n")


def html_part(raw: bytes) -> str:
    """Decode the quoted-printable text/html part from the raw DATA payload."""
    marker = b"Content-Type: text/html; charset=UTF-8"
    start = raw.index(marker)
    body = raw[start:].split(b"\r\n\r\n", 1)[1]
    end = body.find(b"\r\n--")
    if end != -1:
        body = body[:end]
    return quopri.decodestring(body).decode("utf-8", "replace")


def free_port() -> int:
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        return probe.getsockname()[1]


def run_php(**overrides):
    env = {**os.environ, "KB_TEST_RUN": uuid.uuid4().hex, **overrides}
    result = subprocess.run(
        [PHP, str(ROOT / "tests/smtp_send.php")],
        env=env,
        capture_output=True,
        text=True,
        timeout=40,
    )
    if result.returncode:
        raise RuntimeError(result.stderr or result.stdout)
    return json.loads(result.stdout.strip().splitlines()[-1])


def serve(server):
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return thread


def stop(server, thread):
    server.shutdown()
    server.server_close()
    thread.join(timeout=5)


def check(condition, label, detail=None):
    if not condition:
        raise AssertionError(f"{label}: {detail}" if detail else label)
    print("PASS", label)


async def main():
    # 1. Plain server: full transaction, Unicode header, dot-stuffing.
    capture = Capture()
    controller = Controller(capture, hostname="127.0.0.1", port=free_port())
    controller.start()
    try:
        data = run_php(KB_TEST_SMTP_PORT=str(controller.port))
    finally:
        controller.stop()
    stats = data["process"]
    check(stats["sent"] == 1 and stats["failed"] == 0, "unauthenticated delivery", stats)
    check(data["failure"] and data["failure"]["stage"] == "connection", "refused port reports a connection failure", data["failure"])
    check(data["failure"]["retryable"] is True, "connection failures are retryable", data["failure"])
    check(PASSWORD not in data["failure"]["message"], "password never appears in an error message")
    check(len(capture.messages) == 1, "exactly one message arrived", capture.messages)
    sender, recipients, content = capture.messages[0]
    check(sender == "notifications@example.test", "envelope sender", sender)
    check(recipients == ["recipient@example.test"], "envelope recipient", recipients)
    check(b"Subject: =?UTF-8?B?" in content, "Unicode subject is encoded", content[:200])
    check(b"\r\n.Second line" in content, "a leading dot reaches the server unaltered", content[-160:])
    check("Ẹ̀kọ́".encode() in content, "Unicode body survives", content[-120:])
    check(b"Content-Type: multipart/alternative" in content, "message is multipart/alternative", content[:400])
    check(b"Auto-Submitted: auto-generated" in content, "message is marked automated", content[:400])
    designed = html_part(content)
    check("<!doctype html>" in designed.lower(), "HTML part is a full document", designed[:120])
    check('role="presentation"' in designed, "HTML layout is table-based", designed[:200])
    check("KINGDOMBUILDERS" in designed, "HTML part carries the site brand", designed[:400])
    check("prefers-color-scheme:dark" in designed, "HTML part supports dark mode")
    check("Unicode: Ẹ̀kọ́" in designed, "HTML part carries the message body")
    check(".kb-h1{" in designed and ".kb-btn-a{" in designed, "HTML styles survive the transfer encoding")

    # 2. Authenticated server advertising AUTH LOGIN PLAIN: PLAIN is used.
    authed = Capture()
    controller = Controller(
        authed,
        hostname="127.0.0.1",
        port=free_port(),
        authenticator=authenticator,
        auth_require_tls=False,
    )
    controller.start()
    try:
        data = run_php(KB_TEST_SMTP_PORT=str(controller.port), KB_TEST_SMTP_USER=USER, KB_TEST_SMTP_PASS=PASSWORD)
    finally:
        controller.stop()
    stats = data["process"]
    check(stats["sent"] == 1 and stats["failed"] == 0, "AUTH PLAIN delivery succeeds", stats)
    check(len(authed.messages) == 1, "authenticated message arrived", authed.messages)

    # 3. A server that offers LOGIN only must still sign in.
    login_server = ScriptedServer(
        ("127.0.0.1", free_port()),
        b"250-script.local\r\n250-SIZE 10240000\r\n250-AUTH LOGIN\r\n250 HELP\r\n",
        accept_login=True,
    )
    thread = serve(login_server)
    try:
        data = run_php(KB_TEST_SMTP_PORT=str(login_server.server_address[1]), KB_TEST_SMTP_USER=USER, KB_TEST_SMTP_PASS=PASSWORD)
    finally:
        stop(login_server, thread)
    stats = data["process"]
    check(stats["sent"] == 1 and stats["failed"] == 0, "AUTH LOGIN delivery succeeds", stats)
    check(len(login_server.messages) == 1, "LOGIN message arrived", login_server.messages)

    # 4. Refused credentials: stage, reply code and no secret in the text.
    secret = "wrong-password-never-print"
    rejected = ScriptedServer(
        ("127.0.0.1", free_port()),
        b"250-script.local\r\n250-AUTH LOGIN PLAIN\r\n250 HELP\r\n",
        accept_login=True,
    )
    thread = serve(rejected)
    try:
        data = run_php(
            KB_TEST_SMTP_PORT=str(rejected.server_address[1]),
            KB_TEST_SMTP_USER=USER,
            KB_TEST_SMTP_PASS=secret,
            KB_TEST_MODE="send-test",
        )
    finally:
        stop(rejected, thread)
    failure = data["failure"]
    check(failure is not None, "refused credentials produce an error", data)
    check(failure["stage"] == "auth", "failure is attributed to authentication", failure)
    check(failure["retryable"] is False, "credential failures are not retried", failure)
    check(failure["code"] == 535, "SMTP reply code is preserved", failure)
    check("SMTP 535" in failure["message"], "reply code reaches the administrator", failure)
    check(secret not in failure["message"], "the refused password is never echoed", failure)

    # 5. Only an unsupported mechanism: explain what the provider offers.
    oauth = ScriptedServer(
        ("127.0.0.1", free_port()),
        b"250-script.local\r\n250-AUTH XOAUTH2\r\n250 HELP\r\n",
        accept_login=False,
    )
    thread = serve(oauth)
    try:
        data = run_php(
            KB_TEST_SMTP_PORT=str(oauth.server_address[1]),
            KB_TEST_SMTP_USER=USER,
            KB_TEST_SMTP_PASS=PASSWORD,
            KB_TEST_MODE="send-test",
        )
    finally:
        stop(oauth, thread)
    failure = data["failure"]
    check(failure and failure["stage"] == "auth", "unsupported mechanisms fail at authentication", failure)
    check("XOAUTH2" in failure["message"] and "PLAIN or LOGIN" in failure["message"], "the refusal names both sides", failure)
    check(PASSWORD not in failure["message"], "unsupported-mechanism error leaks nothing", failure)

    # 6. A silent server is the production symptom that logged stage=timeout.
    silent = SilentServer(("127.0.0.1", free_port()), SilentHandler)
    thread = serve(silent)
    try:
        data = run_php(
            KB_TEST_SMTP_PORT=str(silent.server_address[1]),
            KB_TEST_SMTP_USER=USER,
            KB_TEST_SMTP_PASS=PASSWORD,
            KB_TEST_MODE="send-test",
        )
    finally:
        stop(silent, thread)
    failure = data["failure"]
    check(failure is not None, "a silent server produces an error", data)
    check(failure["stage"] == "greeting", "timeouts keep the failing stage instead of 'timeout'", failure)
    check("did not answer" in failure["message"], "timeout names the step", failure)
    check("No reply on 127.0.0.1" in failure["message"], "timeout names the host and port", failure)
    check("Diagnose connection" in failure["message"], "timeout points at the diagnosis tool", failure)
    check(secret not in failure["message"] and PASSWORD not in failure["message"], "timeout leaks nothing", failure)

    # 7. Diagnosis reports a working server, a silent one and a non-SMTP port.
    good = ScriptedServer(
        ("127.0.0.1", free_port()),
        b"250-script.local\r\n250-AUTH LOGIN PLAIN\r\n250 HELP\r\n",
        accept_login=True,
    )
    thread = serve(good)
    try:
        data = run_php(KB_TEST_SMTP_PORT=str(good.server_address[1]), KB_TEST_MODE="diagnose")
    finally:
        stop(good, thread)
    diagnosis = data["diagnosis"]
    check(diagnosis["resolved"] is True, "diagnosis reports host resolution", diagnosis)
    first = diagnosis["probes"][0]
    check(first["ok"] is True and first["configured"] is True, "diagnosis marks the saved port ready", first)
    check(diagnosis["recommendation"]["code"] == "ok", "healthy diagnosis recommends nothing", diagnosis["recommendation"])
    check(len(diagnosis["probes"]) == 1, "a healthy probe stops the search", diagnosis["probes"])

    silent = SilentServer(("127.0.0.1", free_port()), SilentHandler)
    thread = serve(silent)
    try:
        data = run_php(KB_TEST_SMTP_PORT=str(silent.server_address[1]), KB_TEST_MODE="diagnose")
    finally:
        stop(silent, thread)
    diagnosis = data["diagnosis"]
    first = diagnosis["probes"][0]
    check(first["ok"] is False and first["stage"] == "greeting", "diagnosis identifies silence", first)
    check(len(diagnosis["probes"]) > 1, "diagnosis tries alternative ports", [p["port"] for p in diagnosis["probes"]])
    check(diagnosis["recommendation"]["code"] in {"silent", "blocked", "unknown"}, "silence is summarised", diagnosis["recommendation"])

    other = NotSmtpServer(("127.0.0.1", free_port()))
    thread = serve(other)
    try:
        data = run_php(KB_TEST_SMTP_PORT=str(other.server_address[1]), KB_TEST_MODE="diagnose")
    finally:
        stop(other, thread)
    first = data["diagnosis"]["probes"][0]
    check(first["ok"] is False, "a non-SMTP port is not ready", first)
    check("not SMTP" in first["detail"] and "POP3" in first["detail"], "the non-SMTP banner is quoted", first)

    print("SMTP socket tests passed.")


if __name__ == "__main__":
    asyncio.run(main())
