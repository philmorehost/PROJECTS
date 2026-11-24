# Operator Staging & Production Rollout Runbook

This runbook governs host deployment, schema migration, historical reconciliation, external sandbox acceptance, and staged hold-lift authorization for **DGV7.0 (SAAS / NON-SAAS)** and **PAYHUB**.

## 1. Pre-Deployment Backup & Preflight
1. Back up both `SAAS` / `NON-SAAS` and `PAYHUB` databases and webroots before pulling code.
2. Pull `main` via cPanel Git / UpdateSystem (`bc-update-manifest-guard.php` blocks staged updates whose files deviate from `VTU/migrations/MANIFEST.json`).
3. Verify offline manifest & Bybit retirement on the target host:
   ```bash
   php VTU/bin/verify-release-readiness.php
   ```
   Confirm `"ok": true`, `"manifest_errors": []`, `"bybit_retired": true`, and `"warnings": []`.

## 2. Manifest-Verified Database Migration
1. Preview the SHA-256 verified migration plan (dry-run default, makes zero DB changes):
   ```bash
   php VTU/bin/apply-migrations.php --dry-run
   ```
2. Apply pending `VTU/migrations/*.sql` and `PAYHUB/install/*.sql` migrations idempotently and run the live `ENGINE=InnoDB` check:
   ```bash
   php VTU/bin/apply-migrations.php --apply --edition=SAAS
   ```
   (Repeat `--edition=NON-SAAS` on a `NON-SAAS` host if it uses a separate database.)
3. Confirm live readiness passes with `"db_checked": true` across all 29 required InnoDB tables:
   ```bash
   php VTU/bin/verify-release-readiness.php --db-check
   ```

## 3. Historical Reconciliation & KMS Review *(Required Before Any Hold Lift)*
1. **PayHub webhook obligations (`LEGACY_SETTLEMENT_HOLD`):**
   - Inspect aged/unattempted events:
     ```bash
     php PAYHUB/bin/check-webhook-outbox.php
     ```
   - Perform dual-operator reconciliation (`preparer_ref !== approver_ref`) with authoritative evidence:
     ```bash
     PAYHUB_WEBHOOK_RECONCILE_ENABLED=1 php PAYHUB/bin/reconcile-webhook-outbox.php --help
     ```
2. **Pre-hold obligations:** Independently reconcile historical bank payouts, virtual cards, checkout intents, and gift-card escrows without synthetic credits or blind replays.
3. **Gift-card KMS & KYB review:** Complete key-management review for `sas_giftcard_orders` encrypted codes and manual compliance/liveness review for KYC/KYB.

## 4. Controlled Sandbox & Worker Wiring
All 8 operational gates default to `false` (`0`). Enable each only after live TLS/sandbox acceptance passes for that subsystem:

| Gate Env Flag | Companion Config / Endpoint Vars | CLI / Cron Runner |
| --- | --- | --- |
| `PAYHUB_ALERT_SINK_ENABLED=1` | `PAYHUB_ALERT_SINK_URL`, `PAYHUB_ALERT_SINK_HMAC_SECRET` | `php PAYHUB/bin/dispatch-webhook-alert.php` |
| `PAYHUB_WEBHOOK_RECONCILE_ENABLED=1` | Dual-operator CLI flags | `php PAYHUB/bin/reconcile-webhook-outbox.php` |
| `BC_PAYOUT_WORKER_ENABLED=1` | `BC_PAYOUT_PROVIDER_ENDPOINT`, `BC_PAYOUT_PROVIDER_HMAC_SECRET` | `php cron/process_payout_reservations.php --vendor=ID` |
| `BC_VIRTUALCARD_WORKER_ENABLED=1` | `BC_VIRTUALCARD_PROVIDER_ENDPOINT`, `BC_VIRTUALCARD_PROVIDER_HMAC_SECRET` | `php cron/process_virtualcard_orders.php --vendor=ID` |
| `BC_GIFTCARD_WORKER_ENABLED=1` | `BC_GIFTCARD_PROVIDER_ENDPOINT`, `BC_GIFTCARD_PROVIDER_HMAC_SECRET` | `php cron/process_giftcard_orders.php --vendor=ID` |
| `BC_SERVICE_DISPATCH_WORKER_ENABLED=1` | `BC_SERVICE_DISPATCH_ENDPOINT`, `BC_SERVICE_DISPATCH_HMAC_SECRET` | `php cron/process_service_dispatch.php --vendor=ID` |
| `BC_CHECKOUT_EXPIRE_ENABLED=1` | *(DB TTL reaper only)* | `php cron/expire_checkout_intents.php --vendor=ID` |
| `CRYPTO_BUYSELL_EXECUTION_ENABLED=1` | `CRYPTO_SIGNER_ENDPOINT`, `CRYPTO_SIGNER_HMAC_SECRET`, `CRYPTO_WATCHER_ENDPOINT`, `CRYPTO_WATCHER_HMAC_SECRET` | `php cron/process_crypto_buysell_signer.php` & `process_crypto_buysell_deposits.php` |

> **Crash Reapers:** `cron/process_service_dispatch.php --vendor=ID --reap-expired-only` moves expired active leases to `unknown` without retrying and may run safely independently of dispatch enablement.

## 5. Physical Device & Release Sign-Off
1. Test DG7, JIKABIZ, and MZEEVTU Android/iOS builds on physical devices for login, OTP/TOTP, security questions, and live camera/mic KYC capture (with host `ffprobe` limits verified).
2. Confirm `PAYHUB/logs/` is non-public (`.htaccess` enforced) and `PAYHUB_DEBUG_LOG_ENABLED` remains unset (`0`) in production unless actively troubleshooting.
