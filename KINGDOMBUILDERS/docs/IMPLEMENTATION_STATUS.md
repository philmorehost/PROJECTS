# Implementation status

Updated: 2026-11-02. This is a working foundation, not a claim of production penetration testing or measured traffic capacity.

## Implemented

| Area | Status | Notes |
|---|---|---|
| Vanilla PHP/MySQL architecture | Done | Front controller, PDO prepared statements, InnoDB schema, no runtime framework |
| HTML5 design | Done | Responsive navy/teal/gold/ivory theme, mobile navigation, progressive-enhancement quiz JS |
| Root installer | Done | Four stages, requirement grid, encoded agreement, activation, DB/schema/seed, admin creation, completion checklist, post-install lock |
| Dynamic favicon | Done | First Unicode character of site title, SVG, ETag revalidation |
| Activation | Done | Provider API contract, strict `status===1`, TLS verification, 6h cache, ≤24h outage grace, encrypted private state, explicit local fixture |
| Accounts | Done | Register, verify (POST confirm), login/logout, reset, profile/password change, session revocation |
| Administration | Done | Dashboard, question CRUD, user CRUD, suspend/unsuspend, audited impersonation with admin-return, last-admin/self guards, results/CSV, settings, SMTP, notifications, audit |
| Quiz/CBT | Done | Immutable snapshots, server scoring, deadline, forward-only/backtracking, shuffle, saved answers/skips, focus policy, ownership checks, idempotent finish |
| Email | Done | Transactional outbox, admin SMTP settings, encrypted secret, test message, leased worker, retries/backoff, sanitized errors, retention pruning |
| Security | Done | CSRF + origin, security headers/CSP, HttpOnly/SameSite cookies, hashed single-use tokens, Argon2id/bcrypt, keyed throttling, AES-256-GCM private state, no public legacy JSON/API |
| Operations | Done | CLI worker/check, Apache rules, Nginx/cron examples, deployment and backup docs |
| Tests/CI | Done | 17 core integration checks, HTTP smoke (installer/admin/member/support/CSRF), real SMTP socket fixture, PHP 8.2/8.3/8.4 + MySQL 8.4 workflow |

## Verification performed locally

- PHP 8.4 syntax lint across project PHP files.
- Core integration suite: **17 passed / 0 failed** (hashing, encryption/tamper, canonical URL, favicon source, quiz payload secrecy, single active attempt, forward-only/ownership, snapshot immutability, scoring/idempotency, deadline, focus finalization, token single-use/revocation, suspension, mail idempotency, nested rollback, rate limiting, activation fixture/encrypted state).
- HTTP smoke against PHP built-in server + MariaDB: installer stages, admin pages/CRUD, suspend/unsuspend/delete, member quiz flow, answer-key secrecy, CSRF 419, cross-origin 403, forward-only 409, ownership 404, CSV export, impersonation/admin-return.
- Password reset: GET does not consume token; POST changes password; token is single-use; old password rejected.
- SMTP fixture: real local SMTP transaction, Unicode encoded headers, recipient envelope and dot-stuffing.
- Worker CLI and `bin/check.php` executed successfully.
- Browser screenshots at 1440px and 390px; mobile horizontal overflow corrected.

## Remaining production gates

1. **Load/performance:** capacity targets are unmeasured. Run representative load tests and tune FPM/MySQL before launch.
2. **Security review:** independent review/penetration test, especially proxy trust, session storage, installer exposure and activation outage behavior.
3. **Provider activation:** local checks use the explicit fixture. Validate a real key/domain against the provider before public launch.
4. **SMTP deliverability:** configure real SPF/DKIM/DMARC-aligned sender and confirm inbox placement.
5. **Multi-node:** current sessions/rate limits/private files are single-node. Add shared session/rate-limit storage and secret management before horizontal scaling.
6. **History hygiene:** legacy public files are removed from the working tree, but Git history may still contain them. Restrict access and arrange an authorized history purge if required.
7. **Monitoring/alerting:** wire worker exit codes, queue age, 5xx rate, DB latency and activation grace expiry into the host’s monitoring.
8. **Retention policy:** confirm legal/organizational retention for results and backups; defaults prune audit at 90 days and sent mail at 30 days.

## Known trade-offs

- PHP encoding/obfuscation is not tamper-proof; a server owner can inspect local code. Stronger enforcement requires signed service capabilities or hosted logic.
- Mail delivery is at-least-once under ambiguous network failure; remote duplicates are possible even though queue rows are idempotent.
- Random question selection uses bounded pivot sampling, not `ORDER BY RAND()` over large banks.
- Focus-change monitoring is advisory and server-enforced by policy; it is not a proctoring guarantee.
