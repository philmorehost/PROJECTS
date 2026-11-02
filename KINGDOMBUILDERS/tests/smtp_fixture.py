#!/usr/bin/env python3
"""Real SMTP socket test against a disposable PHP/MySQL test configuration.
Requires: pip install aiosmtpd; KB_CONFIG_DIR and KB_LOCAL_PREVIEW=1; PHP_BIN.
The PHP helper refuses databases not ending in _test. No external mail is sent.
"""
import asyncio
import json
import os
import socket
from pathlib import Path
import subprocess
import uuid
from aiosmtpd.controller import Controller

ROOT = Path(__file__).resolve().parents[1]
messages = []

class Capture:
    async def handle_DATA(self, server, session, envelope):
        messages.append((envelope.mail_from, list(envelope.rcpt_tos), envelope.content))
        return "250 Message accepted for local test"

async def main():
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
    controller = Controller(Capture(), hostname="127.0.0.1", port=port)
    controller.start()
    try:
        env = {**os.environ, "KB_TEST_SMTP_PORT": str(port), "KB_TEST_RUN": uuid.uuid4().hex}
        result = subprocess.run([os.environ.get("PHP_BIN", "php"), str(ROOT / "tests/smtp_send.php")], env=env, capture_output=True, text=True, timeout=30)
        if result.returncode:
            raise RuntimeError(result.stderr or result.stdout)
        result_data = json.loads(result.stdout.strip().splitlines()[-1])
        stats = result_data["process"]
        assert stats["sent"] == 1 and stats["failed"] == 0, stats
        failure = result_data["failure"]
        assert failure and failure["stage"] == "connection", failure
        assert failure["retryable"] is True, failure
        assert "app-password-never-print" not in failure["message"], failure
        assert len(messages) == 1, messages
        sender, recipients, content = messages[0]
        assert sender == "notifications@example.test"
        assert recipients == ["recipient@example.test"]
        assert b"Subject: =?UTF-8?B?" in content
        assert b"..Second line" in content  # aiosmtpd exposes the raw DATA payload
        assert "Ẹ̀kọ́".encode() in content
        print("PASS real SMTP transaction, Unicode headers, recipient envelope and SMTP dot-stuffing")
    finally:
        controller.stop()

if __name__ == "__main__":
    asyncio.run(main())
