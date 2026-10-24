#!/usr/bin/env python3
"""Static regression checks for DGV7.0 Apache/LiteSpeed .htaccess files.

The security hardening commit introduced a <DirectoryMatch> container into both root
.htaccess files. That container is only legal in server/vhost configuration, so the
web server rejected the whole per-directory config and returned HTTP 500 before PHP ran.

Run with:  python3 VTU/tests/htaccess_context_test.py
Exit code 0 means all checks passed.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

VTU_ROOT = Path(__file__).resolve().parents[1]
EDITIONS = ("DGV7.0-SAAS", "DGV7.0-NON-SAAS")

# These are the containers/directives that previously caused, or can cause, a 500 when
# placed in a per-directory .htaccess file. Comments are stripped before matching.
FORBIDDEN = re.compile(
    r"(?:<\s*/?\s*(?:Directory|DirectoryMatch|VirtualHost|Location|LocationMatch)\b"
    r"|^\s*AllowOverride\b"
    r"|^\s*php_(?:value|flag|admin_value|admin_flag)\b)",
    re.IGNORECASE,
)
TAG = re.compile(r"^\s*<\s*(/?)\s*([A-Za-z][A-Za-z0-9]*)\b")
QUERY_COND = re.compile(
    r"^\s*RewriteCond\s+%\{QUERY_STRING\}\s+(.+?)\s+\[NC(?:,OR)?\]\s*$"
)
DENY_RULE = re.compile(r"^\s*RewriteRule\s+(\S+)\s+-\s+\[F(?:,[^\]]*)?\]")

errors: list[str] = []
checks = 0


def check(condition: bool, message: str) -> None:
    global checks
    checks += 1
    if not condition:
        errors.append(message)


def active_lines(text: str) -> list[tuple[int, str]]:
    return [(n, line) for n, line in enumerate(text.splitlines(), 1)
            if line.strip() and not line.lstrip().startswith("#")]


def check_containers(path: Path, lines: list[tuple[int, str]]) -> None:
    stack: list[tuple[str, int]] = []
    for line_no, line in lines:
        match = TAG.match(line)
        if not match:
            continue
        closing = bool(match.group(1))
        name = match.group(2).lower()
        if closing:
            if not stack or stack[-1][0] != name:
                check(False, f"{path}:{line_no}: mismatched closing </{match.group(2)}>")
            else:
                stack.pop()
        else:
            stack.append((name, line_no))
    for name, line_no in stack:
        check(False, f"{path}:{line_no}: unclosed <{name}> container")


htaccess_files: list[Path] = []
for edition in EDITIONS:
    edition_root = VTU_ROOT / edition
    check(edition_root.is_dir(), f"missing edition directory: {edition_root}")
    root_file = edition_root / ".htaccess"
    check(root_file.is_file(), f"missing root .htaccess: {root_file}")
    for path in sorted(edition_root.rglob(".htaccess")):
        htaccess_files.append(path)
        text = path.read_text(encoding="utf-8-sig")
        lines = active_lines(text)
        for line_no, line in lines:
            check(not FORBIDDEN.search(line),
                  f"{path}:{line_no}: server/vhost-only directive in .htaccess: {line.strip()}")
        check_containers(path, lines)

check(len(htaccess_files) > 0, "no .htaccess files found")

saas_root = VTU_ROOT / "DGV7.0-SAAS" / ".htaccess"
non_saas_root = VTU_ROOT / "DGV7.0-NON-SAAS" / ".htaccess"
saas_text = saas_root.read_text(encoding="utf-8-sig")
non_saas_text = non_saas_root.read_text(encoding="utf-8-sig")
check(saas_root.read_bytes() == non_saas_root.read_bytes(),
      "SAAS and NON-SAAS root .htaccess files have diverged")

for path, text in ((saas_root, saas_text), (non_saas_root, non_saas_text)):
    lines = active_lines(text)
    active_text = "\n".join(line for _, line in lines)
    check("RewriteEngine On" in active_text, f"{path}: RewriteEngine On is missing")
    check("RedirectMatch" not in active_text, f"{path}: RedirectMatch is unreliable in .htaccess context")
    check("<IfModule mod_authz_core.c>" in active_text and "<IfModule !mod_authz_core.c>" in active_text,
          f"{path}: Apache 2.4/2.2 authorization fallback is missing")
    check("RewriteRule ^(?:backups?|logs?|tmp|temp|debug|cache)(?:/|$)" in text,
          f"{path}: private-directory RewriteRule is missing")

    query_patterns = [m.group(1) for _, line in lines if (m := QUERY_COND.match(line))]
    check(len(query_patterns) >= 4, f"{path}: expected at least four query-string guards")
    for pattern in query_patterns:
        try:
            re.compile(pattern, re.IGNORECASE)
        except re.error as exc:
            check(False, f"{path}: invalid query-string regex {pattern!r}: {exc}")

    combined = re.compile("|".join(f"(?:{p})" for p in query_patterns), re.IGNORECASE) if query_patterns else None
    benign = "page=select-plan&action=update&sort=created&q=evaluation"
    if combined:
        matches = [p for p in query_patterns if re.search(p, benign, re.IGNORECASE)]
        check(not matches, f"{path}: legitimate query string falsely blocked by {matches}")
        for attack in (
            "id=1%27%20OR%201=1",
            "id=1%20union%20select%20password",
            "schema=information_schema.tables",
            "debug=eval(base64_decode('x'))",
            "file=../../etc/passwd",
        ):
            check(bool(combined.search(attack)), f"{path}: attack pattern not blocked: {attack}")

    deny_patterns = [m.group(1) for _, line in lines
                     if (m := DENY_RULE.match(line)) and m.group(1) != "^"]
    try:
        compiled_denies = [(p, re.compile(p, re.IGNORECASE)) for p in deny_patterns]
    except re.error as exc:
        compiled_denies = []
        check(False, f"{path}: invalid deny-rule regex: {exc}")

    path_cases = {
        ".git/config": True,
        "logs/app.log": True,
        "backup/2026.zip": True,
        "cache/manifest.json": True,
        "uploads/kyc/shell.php": True,
        "asset/shell.php": True,
        "index.php": False,
        "web/Login.php": False,
        "web/api/ai-chat.php": False,
        "Downloads/app.apk": False,
    }
    for candidate, should_block in path_cases.items():
        blockers = [p for p, regex in compiled_denies if regex.search(candidate)]
        check(bool(blockers) == should_block,
              f"{path}: path {candidate!r} blocked={bool(blockers)}, expected {should_block}; rules={blockers}")

if errors:
    print(f"FAIL — {checks} checks, {len(errors)} problem(s):")
    for error in errors:
        print(f"  - {error}")
    sys.exit(1)

print(f"PASS — {checks} checks across {len(htaccess_files)} .htaccess files in both editions.")
