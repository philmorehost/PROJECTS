# Authorized service-execution dispatch lease & crash recovery — held foundation

This increment is an INTERNAL library (`func/bc-service-dispatch-lease.php`) and
unapplied DDL (`VTU/migrations/20261118_service_dispatch_leases.sql`) for both
editions. It does NOT execute live provider calls, wire HTTP intake pages
(`IntlTransfer.php`, `Tuition.php`, `POSRequest.php`), or alter
`func/bc-service-attempt-journal.php`.

## Contract

- **Capability matrix gate (`sas_service_capability_matrix`)**: requires explicit
  tenant/service/provider/currency authorization plus verified provider
  idempotency (`idempotency_verified=1`) and status-query (`status_query_verified=1`)
  evidence before `bc_sdl_acquire_lease` can transition `held` -> `in_flight`.
- **Exclusive one-shot worker lease (`sas_service_dispatch_leases`)**: checks
  active vendor, active/unblocked user, matching canonical payload fingerprint,
  and absence of any existing lease row for `(vendor_id, request_id)`.
- **Crash-recovery reaper (`bc_sdl_reap_expired_lease`)**: expires timed-out
  `active` leases to `expired_unknown` and transitions the attempt to `unknown`
  (`lease_expired`). Attempts are NEVER reset back to `held` or retried without
  verified reconciliation via `bc_saj_observe`.
