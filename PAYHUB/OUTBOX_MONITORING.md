# Outbox check integration runbook

## Implemented, not installed live

Run locally as a restricted operator or integrate the read-only plugin with an existing monitoring agent:

```sh
php PAYHUB/bin/check-webhook-outbox.php --max-pages=5 --page-size=100 --age-seconds=900
```

The process prints one JSON report. It never sends a webhook, consumes a token/claim, resets evidence, credits funds or activates a provider. It loads configuration only, not the application bootstrap/runtime migrations. Protect configuration and output; use a database principal with only required SELECT privileges where deployment supports it. No scheduler, outbound notification transport or credentials are introduced here. The optional callable in the helper is an internal test seam, not a CLI option.

## Monitoring-agent contract

| Exit | Meaning / operator response |
| --- | --- |
| 0 | No configured signal observed within a complete traversal. **Not** proof of correct payment settlement, recipient processing, transport readiness or global system health. |
| 1 | Warning: aged unattempted event or aged existing log evidence. Investigate, do not automatically recover/replay. |
| 2 | Critical: any unknown claim, any unrecognized claim state, or an aged attempting event. Preserve evidence and investigate; never reset/reclaim. |
| 3 | Unknown: page cap reached, unavailable query/config/schema, malformed observation, or invalid options. Never translate unknown to healthy. Inspect `reasons`: an incomplete scan can already contain critical findings. |

Age uses **outbox creation time**, not time since the claim started. Successfully delivered events do not alert solely for age. Existing logs without a shared claim are evidence, not success/failure classification. Unknown claims are critical even when fresh; attempting is alerted only when the event is aged. These are initial conservative policies, not payment SLAs or authorization decisions.

Configure the monitoring agent to capture stdout and exit status, notify its existing approved local/supervised alert channel, alert on missing/stale runs, and treat runner timeout, signal, missing PHP or missing JSON as UNKNOWN. Enforce an external execution deadline and database/query/resource budgets suitable for the host. Row bounds do not bound SQL/connection wall time. Never invoke recovery from an alert hook. A process success with invalid JSON is UNKNOWN, not an empty queue. The CLI's `--help` is informational and not a check run.

No cron configuration is installed by this change. Review cadence, retention, escalation owner and data permissions before scheduling. Avoid shell pipelines that mask the plugin exit code. Use a monitoring runner capable of independent per-status routing; code 3 must still notify even if a code-2 finding is present in `reasons`.

## Scan limits and scale

Each run starts at transaction ID zero. Defaults inspect up to 500 events (5 pages x 100); maximum is 10,000 (50 x 200), with a one-row lookahead per page. The reported `counts`/`aged_counts` are aggregate **observations**, not a consistent transactional snapshot. `complete` means the traversal reached its observed end, not that concurrent writes cannot change it. Events inserted behind a cursor are seen on a later fresh scan. The scan covers only the outbox; legacy payments/logs and orphan claims without outbox rows need separate reconciliation.

Historical completed events still consume scan capacity. If the cap is reached, do not raise it indefinitely or hide the alert by starting from a later cursor: the check deliberately returns UNKNOWN. Review an indexed operational-summary design/capacity plan that preserves immutable history. This initial bounded scan is suitable only where a full traversal fits the configured budget. The underlying `monitor-webhook-outbox.php` command remains a page-by-page diagnostic, not a global health check.

## Investigation without replay

1. Confirm revision/migration chain and monitoring query permissions using restricted tooling; unavailable schema is not zero backlog.
2. Inspect the privacy-safe reasons/counts. Correlate specific events only in authorized local operator tooling; do not paste keys, payloads or customer evidence into alert tickets.
3. For unknown/attempting/existing evidence, preserve claims and logs. Confirm downstream processing using authoritative merchant evidence, not an HTTP status alone.
4. For unattempted events, the alert is **not** a recovery eligibility check. A separately authorized recovery command must revalidate committed body/config/payment state and transport policy; this runbook does not enable it.
5. Changed endpoints/keys/payment data, missing evidence or uncertain delivery require a separately reviewed audited reconciliation process. Never rewrite the immutable event, reset claims, regenerate historical bodies or synthesize wallet credits.

## Verification boundary

Pure policy fixtures cover severity, bounded traversal, cap precedence, invalid/malformed observations and failure propagation. Native MySQL fixtures exercise actual monitor queries and aggregation with disposable temporary tables and unchanged claim rows; CLI invalid inputs are checked in subprocesses before any configuration load. Neither establishes live provider/DNS/TLS acceptance or an operational alert notification having arrived.
