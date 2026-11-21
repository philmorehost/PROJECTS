# Manual collection health and recovery policy

The protected audited conflict report now includes a separately bounded health section: at most twenty streams, with up to one hundred recent lease audit events per stream. Both stream and attempt-history truncation are explicit. This reuses the existing stepped-up POST/CSRF/platform PIN/TOTP boundary, no-store escaped response and exact-report-hash access audit. No new schema, public endpoint, background process or credentials are introduced; the installer remains at 24 production tables.

The report selects only stream IDs/revisions, pinned custody revision, terminal state, page count, expiry and lease expiry. It does not decrypt query windows/records/cursors, expose lease tokens/hashes, or contact the provider. Lease event counts describe only retained recent local audit events, NOT lifetime failures, provider request totals or successful reads. A `failed` release can follow validation/storage failure, not just provider failure. A claim without release may mean an in-flight call, crash, unknown outcome or missing release audit. Health is advisory and evaluated at the report's generated time, not a guarantee about the instant a browser renders it.

## Deterministic priority

1. **Stopped:** preserve evidence; no restart/reset action exists. A lease may still be present from an old in-flight attempt; changed stream revision already fences its commit.
2. **Window exhausted:** review coverage before any separately authorized new window. An empty provider cursor does not prove complete history, finality or eventual-consistency convergence.
3. **Expired:** stop, then review a replacement collection window under current approvals. Do not renew the old stream or rebind it to changed credentials/custody.
4. **Page limit:** stop and investigate a possible coverage gap; never skip a cursor to manufacture completion.
5. **Lease held:** wait, or explicitly stop if necessary. No parallel manual retry. An authorized stop remains revisioned and fences in-flight commits.
6. **Recorded failures in the recent audit slice:** review evidence and failures before another stepped-up manual attempt. A later success does not erase earlier failure history; this is a conservative attention flag, not a diagnosis or automatic backoff decision.
7. **Lease expired with unknown outcome:** refresh the committed stream revision before another manual attempt. Never replay a stale checkpoint/token because an HTTP response was lost.
8. **Ready for manual attempt:** fresh step-up and all current custody/config/vendor/policy/catalogue/scale guards are still mandatory. This is NOT a positive provider-health or credential-readiness assertion.

Terminal and expiry/limit states take priority over a lease/failure indication, but raw lease-held/failure flags remain visible. `automatic_retry_authorized`, `replacement_window_authorized`, `history_complete` and `settlement_enabled` are always false. Health never mutates state, releases a lease, resets a cursor, resolves a conflict, creates a window or changes holds.

## Replacement-window policy: explicit review required

Manual create remains available through its existing guarded workflow; the health report itself grants no permission and computes no replacement window. Before an operator deliberately creates one, separately verify current approvals/configuration, the original encrypted query/evidence and last committed revision, reason for interruption, explicit overlap with previously collected coverage, retained identity-conflict history, provider eventual-consistency limits and a documented unresolved-gap/reconciliation plan. Do not infer a start timestamp from the most recent returned record, use exhausted as a completeness watermark, or treat cross-stream first_seen as first-ever on chain. There is no automatic window rollover, backfill or conflict override.

Unattended operation remains disabled pending a separately reviewed isolated worker authorization, bounded retry/backoff and unknown-response policy, metrics/alert transport and ownership, overlapping-window lifecycle, completeness/reconciliation policy and operational/security readiness. This increment is operator-visible monitoring, NOT an alerting service or scheduler implementation.

## Validation and limitations

Eleven pure contracts cover health precedence, exact lease/stream-expiry boundaries, failure attention, terminal handling, denied authority flags and mirrored source parity. Native report tests assert stream/event bounds, explicit truncation types and non-authorizing health output under the existing exact-report-hash audit; existing audit-failure nondisclosure, scope, lease/overlap/concurrency and financial isolation cases still apply. Existing supporting locks/transaction requirements remain unchanged. Large-history query/index/retention performance must be reviewed before deployment; bounded returned rows do not guarantee bounded scanned rows.

No production deployment, schema migration, real provider call, browser/device verification, deposit import, credit, route activation or financial-containment change is performed or authorized here.
