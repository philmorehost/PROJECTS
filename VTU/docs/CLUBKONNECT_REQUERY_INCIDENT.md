# ClubKonnect direct-data / requery incident — 2026-11-18

## Confirmed source defects repaired
- Admin transaction links pointed at an intentionally forbidden mutating GET. Links now open a non-mutating confirmation page; reconciliation uses POST, session CSRF, admin PIN and the existing second-factor verifier. GET `requery`/`mark_success` remains 405.
- Legacy root automated-cron-requery.php bypassed the canonical worker's authentication and overlap controls. It now delegates to that worker. Unauthenticated old HTTP schedules must be replaced, not exempted.
- NON-SAAS lacked the canonical worker. Added it restricted to standalone vendor 1, and brought shared reconciliation behavior in line with SAAS (explicit cron action, bounded late reversal support and logging). No schema migration added.
- Already-refunded transactions cannot be automatically re-debited through admin requery. Such discrepancies require separate financial review.
- Reconciliation transaction lookup and refund claim now constrain vendor AND username, including API callers and reconstructed worker context.
- ClubKonnect direct-data requery now encodes credentials, supports mixed-case keys, and uses exact documented codes/states. Only code 200 with a compatible completion state confirms delivery. 201/299, holds, query-auth errors, HTTP/transport failures, unknown/conflicting replies and 521 remain pending. Refund eligibility requires explicit terminal cancellation/refund evidence; mismatched OrderID remains pending. No new purchase/retry is sent.
- Direct-data purchase no longer treats 201/299 as success in either edition; on-hold codes stay pending.
- Invalid wallet input and service-security holds now produce support diagnostic codes rather than a generic charge refusal. No balance, replay, refund-source, hold or security bypass.

## Operator verification after separately authorized rollout
1. Schedule `* * * * * php /absolute/install/path/cron/process_requery_queue.php` under the install owner. CLI is recommended; no user session, PIN or TOTP is needed by the worker. Both canonical and legacy HTTP routes require the existing platform cron secret. Never publish it or put it in screenshots. Existing unauthenticated HTTP schedules will receive 403 intentionally.
2. Confirm writable logs directory and `logs/requery_queue_report.log`: run_started, provider_reply and run completion. Verify configured cron actually executes; an admin GET error alone says nothing about cron health.
3. Test pending references against provider account history without making new purchases. Success must correspond to the same provider OrderID. Unknown delivery stays pending; legacy refunds without a matching durable debit remain subject to financial holds/manual reconciliation.
4. For the direct-data charge refusal, capture the new `WALLET_*` code and matching `[WALLET_MUTATION_REFUSED]` server entry. Compare the user's account-level direct-data plan (`val_1` provider product code and positive `val_2` price), service hold, and transactional wallet tables. Never disable the guard to test. Charging occurs BEFORE the supplier request, so supplier status fixes do not prove that refusal is resolved.

## Validation and limits
Offline suites: 398 requery checks, 246 supplier purchase adapter checks, 532 runtime checks, 30 wallet-double checks; shared purchase PIN/balance gates pass. Native CI is required before merge. No live credentials, paid purchases, production database mutations, deployment or production timing verification.

Provider references consulted:
- https://www.clubkonnect.com/APIParaGetDataBundleV1
- https://www.clubkonnect.com/APIParaGetDisputeResolutionV1
The latter explicitly documents 201 as network-unresponsive, 299 as unspecified error, and 521 as ambiguous already-completed/cancelled/refunded; no automated cancel API was added.

## Automated-launch follow-up
Manual authenticated reconciliation has been confirmed working by the operator, and the direct-data purchase issue is resolved. The earlier `Forbidden`/CGI header remains a scheduling/runtime symptom, not evidence of a provider failure. This repository cannot inspect or change the production crontab.

Both editions now ship `cron/run_requery_queue.sh`. It selects a verified CLI PHP 8.2+ executable with mysqli/curl, rejects CGI output, establishes the install working directory, creates the log directory before redirecting output, and propagates worker exit codes. It requires no user PIN/TOTP, no HTTP URL and no cron secret. The existing worker still owns overlap locking, tenant scope and financial safeguards; HTTP access is NOT made anonymous. Setup screens now publish this launcher instead of bare PHP or an unauthenticated URL.

For the reported install, after separately authorized rollout, first run in Terminal:

```sh
/bin/sh /home/v8data/public_html/cron/run_requery_queue.sh --check
```

`"ready":true` verifies only CLI extensions and log write access. It explicitly does not connect to the database or query providers. If PHP cannot be located, pin a trusted absolute binary using `BC_REQUERY_PHP=/absolute/path/to/php` before the command. Do not use php-cgi.

Replace the old requery cron entry (do not add a duplicate). Set minute/hour/day/month/weekday to `*`, with command:

```sh
/bin/sh /home/v8data/public_html/cron/run_requery_queue.sh
```

For a raw crontab entry, prefix the command with `* * * * *`. No extra `>>` redirection is necessary; the launcher writes `logs/requery_queue.log`. Keep cron failure-email enabled so runtime selection or directory failures are visible before logging can begin. Confirm fresh `run_started`, `vendor_queue_loaded`, `provider_reply`, `transaction_processed` and `run_completed` records in `logs/requery_queue_report.log`. Pending outcomes can be legitimate and must not be force-completed/refunded. A successful `--check` is not proof the hosting scheduler runs, the database is reachable, or the provider returns final status.

Validation: six offline shell-launcher contracts cover CGI refusal, explicit path validation, paths with spaces, log redirection, bad arguments and nonzero worker exit propagation. Native CI also exercises each real worker's `--check` without application config/DB/provider calls. Production unattended operation still requires the above operator verification; no deployment or schedule installation is claimed.
