# KINGDOMBUILDERS — implementation and launch plan

## 1. Product definition

A church-friendly Bible learning and computer-based testing website, rebuilt with server-rendered HTML5, vanilla PHP and MySQL. The existing question bank and test controls are retained; anonymous, name-only participation and the shared administrator password are not.

**Primary roles**
- **Administrator:** manages members, questions, assessments, results, site identity, activation, SMTP and the notification queue.
- **Member:** registers, verifies their email, signs in, takes available quizzes, resumes an interrupted attempt and reviews their own results.

**Publication:** all work stays on Arena’s fixed session branch. Use `KINGDOMBUILDERS — PHP/MySQL rebuild` as the pull-request title; GitHub cannot be published under a different Arena session branch without disassociating the work.

## 2. Architecture and request flow

```text
Browser → TLS / edge rate limits / static asset cache
        → PHP-FPM front controller → MySQL primary (InnoDB)
                                  → private encrypted runtime state
                                  → transactional notification outbox
Cron → notification worker → authenticated SMTP
     → expired-attempt finalizer
     → cached domain authorization refresh
Optional at scale → Redis sessions/rate limits, read replica, object/CDN storage
```

- No JavaScript framework, remote font, client-side routing or runtime package dependency.
- Small, progressively enhanced JavaScript for the quiz; PHP performs authorization, validation and scoring.
- PDO prepared statements; UTF-8 (`utf8mb4`); server-side HTML escaping; no executable user uploads.
- Application code, schema, resources, private state, tests and documentation are separate. Only the front controller and versioned assets should be web-accessible.
- Root `index.php` starts setup when no valid installation exists. Setup becomes inaccessible after completion.
- MySQL is the source of truth for accounts, settings, questions, attempts and results. JSON is used only for the bundled question seed and data interchange.
- SMTP runs outside the web request. A failed mail server must not undo a submitted quiz or expose a stack trace.

## 3. Staged delivery and acceptance criteria

### Milestone A — foundation and four-step installer
**Deliverables**
1. Responsive visual system, accessible forms, reduced-motion support, system fonts and an administrator-defined title.
2. Dynamic SVG favicon using the first Unicode letter of the configured title; safe XML escaping and short, revalidatable caching.
3. Environment preflight: PHP version, required extensions, private-directory writability and database driver.
4. Installer:
   - **1 / Welcome:** requirements, readable software terms, canonical site URL and domain-bound activation key.
   - **2 / Database:** existing database credentials, controlled schema creation and bundled question import.
   - **3 / Administrator:** name, email and strong password; no default password.
   - **4 / Ready:** secure sign-in link, first-login checklist, worker cron instructions and backup guidance.
5. Opaque, authenticated encrypted runtime credentials; no publicly served activation file.
6. Atomic configuration creation, installation lock and repeat-install protection.

**Acceptance:** missing requirements block progress; steps cannot be skipped; invalid authorization fails closed; existing databases are not overwritten; a completed installation cannot create another administrator; secrets never appear in HTML, URLs or logs.

### Milestone B — identity, administration and email
**Deliverables**
- Member registration, email verification, login/logout, password reset and authenticated password change.
- Argon2id when available, bcrypt otherwise; opportunistic hash upgrades; session rotation and revocation.
- Administrator CRUD for members and administrators; suspend/unsuspend; explicit, audited “sign in as member” with a persistent return-to-admin control.
- CSRF checks on every mutation, role and ownership checks on the server, account-enumeration resistance and throttling.
- Site and quiz settings; editable SMTP connection with a test message; queue status and controlled retry.
- Transactional audit events and notifications for account, administrative, assessment and configuration changes. Per-answer clicks are stored but summarized at attempt completion rather than generating an email for every click.

**Acceptance:** suspended accounts lose access on their next request; reset tokens are single-use, hashed and expiring; an administrator cannot remove their own access or the last administrator; impersonation cannot grant administrative access; SMTP failures are visible and retryable.

### Milestone C — question bank and CBT
**Deliverables**
- Import the existing Matthew/Mark questions into a categorized, indexed bank.
- Add/edit/delete questions, four options, one correct answer, reference and explanation; search and pagination.
- Immutable per-attempt question/option snapshots so editing a question does not change an in-progress assessment.
- Configurable question count, pass mark, total time budget, randomization, backtracking, practice feedback, attempt limit and open/closed state.
- Server-owned question selection, answer ordering, deadline, saved answers, score and submission state.
- Resume after refresh; idempotent submission; transactional scoring; member history and administrator results/CSV export.
- Optional visibility-change monitoring with honest limitations: browser signals are advisory, not proof of cheating or a substitute for proctoring.

**Acceptance:** correct answers never appear in the active quiz payload; users cannot access another member’s attempt; refreshing does not reset the deadline; duplicate submissions do not duplicate results/emails; deleting a question does not corrupt historical attempts; time limits and forward-only rules are enforced server-side.

### Milestone D — performance and operational hardening
- Enable OPcache and TLS; serve versioned static assets with long-lived immutable caching.
- Add edge caching for public assets only. Never cache authenticated HTML, installer responses, quiz APIs or favicons containing private configuration at a shared cache.
- Index account lookup, question selection, member history, attempt expiry and queue claiming.
- Bound question selection, pagination, request bodies, SMTP timeouts, login attempts and worker batch sizes.
- Use a dedicated least-privilege runtime database account; separate migration privileges.
- Configure PHP-FPM capacity from measured memory/request latency; use persistent connections only after connection-budget testing.
- Add a shared Redis session/limiter adapter before running multiple web nodes if the deployment cannot provide shared session storage.
- Test backups and restoration, disk-full/database-unavailable behavior, queue lease recovery and worker concurrency.
- Run dependency/runtime patch review, penetration tests and an accessibility pass before launch.

**Capacity targets, not measured claims:** start load tests at 100 concurrent learners, then 500 and 1,000. Record hardware, PHP workers, DB size and question count. Proposed gate: p95 page latency < 500 ms and answer persistence < 250 ms at the agreed target, error rate < 0.1%, no lost/double-scored attempts. Do not advertise a traffic capacity until this test passes.

### Milestone E — production acceptance and handover
- Install on the actual domain with the real activation key and production SMTP credentials.
- Approve the distributor’s software terms; the API documentation does not supply an end-user agreement.
- Configure SPF, DKIM and DMARC; test delivery to the intended recipient domains.
- Verify cron execution, queue alerting, HTTPS renewal, backup restoration and administrator recovery procedures.
- Run cross-browser/mobile, keyboard/screen-reader, security and load tests.
- Train administrators and publish a changelog, operating runbook and incident/rollback procedure.

## 4. Security and privacy decisions

- Passwords are never stored or emailed in plaintext. Reset/verification tokens are random, stored as SHA-256 hashes and consumed atomically.
- Session cookies are HttpOnly, SameSite=Lax and Secure on HTTPS; strict session mode and ID rotation are enabled.
- State-changing requests require a session-bound CSRF token. Browser JSON endpoints are same-origin and do not enable CORS.
- Login/reset responses do not reveal whether an account exists. Rate-limit both account and source address; trust forwarded headers only from explicitly configured proxies.
- Store audit source addresses as keyed hashes, not raw IP addresses. Avoid passwords, tokens, SMTP credentials and message bodies in audit metadata.
- Encrypt domain-bound activation state using AES-256-GCM with an installation-specific key. Cache successful checks for six hours; permit at most 24 hours of continuity after the last successful check during an upstream outage. Refresh in the worker, not on every page request.
- Obfuscation/encoding is not encryption and PHP source obfuscation is not an access-control boundary. A server owner can modify local PHP. Stronger tamper resistance requires signed service capabilities or a hosted service, not an easily reversible encoded file.
- Keep terms visible and acceptance explicit even though public filenames and UI use neutral setup language.
- The old public participant/session JSON files and shared-password API must be removed from the deployed web root. Git history may still contain the old data: restrict repository access and consider a separately authorized history purge. Removing a working-tree file is not a claim that history was erased.
- Define retention before production: proposed 90 days for audit/rate-limit records, 30 days for sent notifications, and an owner-defined results retention policy. Backups need the same access controls and retention schedule.

## 5. Data model

| Table | Purpose / important constraints |
|---|---|
| users | Unique email, role, status, password hash, verification and session version |
| access_tokens | Hashed, expiring, single-use verification/reset tokens |
| settings | Validated site, quiz and SMTP configuration; encrypted SMTP secret |
| questions | Category, four options, correct option, reference, active flag |
| attempts | Owner, immutable settings snapshot, deadline, status, score; one active attempt per member |
| attempt_items | Snapshot, displayed option order, server-only correct option and saved answer |
| audit_events | Actor, effective administrator, action, subject and sanitized metadata |
| mail_queue | Durable outbox, idempotency key, lease, retry time and sanitized failure state |
| rate_limits | Atomic fixed-window counters keyed by purpose and hashed identifier |
| schema_migrations | Applied schema version and installation time |

## 6. Scaling roadmap and trade-offs

1. **Single production node:** TLS + PHP-FPM + MySQL, OPcache, durable local/shared sessions, cron workers. Appropriate starting point; capacity must be measured.
2. **Vertical tuning:** buffer pool sized to workload, slow-query review, FPM process limits, static CDN, email worker concurrency. Read only committed data from replicas; account writes, quiz starts, scoring and queue claims stay on the primary.
3. **Multiple web nodes:** shared Redis sessions and rate limits, shared encrypted-state key management, centralized logs/metrics, stateless deployments. Do not simply copy local-file sessions between nodes.
4. **Large question banks:** precomputed/randomized selection strategy and category quotas; current implementation fetches active IDs only, not full question bodies, and never uses `ORDER BY RAND()` over the bank.
5. **Very large events:** stagger starts, queue noncritical notifications, archive old attempts, partition high-volume audit data only when measurements justify it.

## 7. Work tracking

The implementation status, verification results and remaining production gates are recorded in `IMPLEMENTATION_STATUS.md`. The initial delivery is a working foundation, not a claim of production penetration testing or measured traffic capacity.
