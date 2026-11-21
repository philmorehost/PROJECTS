# Retired VTU provider removal and configuration repair

LegitDataWay and Bilalsadasub have been removed from the active source in both editions. This is an explicit provider retirement, not an attribution of the reported attack to either company.

## Changes

- Removed purchase adapters, requery adapters and webhook handlers (34 files across the two editions), plus both obsolete root `api.php` callback-test harnesses.
- Removed installer seed entries and stale package-list entries. Removed obsolete provider-specific variable names from the standalone MTN diagnostic script; this is not retirement of MTN.
- Existing DB settings cannot route these providers to `localserver`: purchase and requery paths fail closed before charging/calling the provider. API plan fetching and marketplace/cart paths reject or hide these providers.
- Root web-server rules deny retired/stale integration URLs; the PHP bootstrap also rejects stale callback/direct-adapter requests. Deploy deletions rather than only copying new files.
- No Android/iOS provider-specific references were found; no mobile source rebuild is required solely for this removal.

## Important additional security observation

The removed webhooks did not verify callback signatures, and interpolated callback-supplied `request-id` into SQL before changing/refunding pending transactions. Their removal closes those specific endpoints only. This is not proof they caused the incident. The other findings in `DGV7_INCIDENT_SOURCE_AUDIT.md`, including shared step-up flag handling and unrelated SQL injection paths, still require remediation. The original audit inventory describes its base commit, not the post-removal file set.

## Open the database repair tool

After deploying the matching edition:

- **SAAS:** sign in as the **super administrator**, then open **Account Settings → Retired Provider Database Repair**, or `/bc-spadmin/RetiredProviderRepair.php`.
- **NON-SAAS:** sign in as the installation **owner/admin**, then open **Account Settings → Retired Provider Database Repair**, or `/bc-admin/RetiredProviderRepair.php`.

The tool operates across this installation's tenants. SAAS vendor administrators cannot use the super-admin tool. There is no anonymous or GET-based execution and no automatic cleanup on ordinary page loads.

### Before executing

1. Preserve an access-controlled full database backup, deployed files and incident logs **outside the public webroot**. Verify you can restore the backup. This repair is not a backup utility.
2. Pause purchases and queue workers while cleaning provider configuration. Revoke these providers' API credentials in their own dashboards. Coordinate pending transaction reconciliation separately.
3. Open the page and review the preview counts. It returns no API keys.
4. Request the email code, or use the enrolled authenticator. Enter your Security PIN and code. Type `REMOVE RETIRED PROVIDERS` and confirm that evidence/backups were preserved.
5. Execute. The preview is session/actor bound, expires after ten minutes and is consumed per execution attempt. If configuration/counts changed, refresh and authorize a new preview.
6. Select supported replacement providers and configure prices/plan mappings deliberately; no replacement or price is invented automatically. Resume workers only after testing normal purchases.

### What is removed from the database

These providers do **not** have dedicated stock database tables. They share tables with other providers. The repair removes **only matching configuration rows**, not entire tables:

- Their `sas_apis` credentials/configuration.
- Their tenant-and-API-scoped service status/routing rows.
- Their smart/agent/API pricing and card parameter mappings.
- Their global API marketplace listings.

Matching normalizes hostnames/URLs and recognized legacy slugs. Subdomains of the exact retired domains match; names merely containing the provider name or URLs whose actual host is another provider do not. Unknown custom aliases require separate manual review; the tool deliberately does not guess.

Deletion uses a fixed table allowlist and tenant+API predicates. It is transactional, removes parent credentials last, checks deleted counts, and rolls back on query failures or changed preview plans. Existing affected tables must use **InnoDB**. Missing optional mapping tables are skipped; incompatible existing schema blocks cleanup. A successful web execution requests an audit record containing row counts and API IDs, not credentials. Validate database engine/schema behavior on a staging clone before production use.

### What is preserved

Wallet balances; users/vendors; financial transactions and references; bank/withdrawal/payment histories; issued cards/receipts; provider payment events; security/audit logs and incident evidence. Pending historical transactions are **not** automatically marked successful, failed or refunded. The new requery guard will refuse retired-provider execution, so reconcile those pending items manually against provider records before making an authorized accounting adjustment.

Re-running after a successful cleanup is a no-op. Restoring an old backup can restore retired configuration, but runtime routing guards remain necessary and active.

## Deployment requirements

- Apply file deletions for `func/api-gateway/*-{retired-domain}.php`, corresponding `requery/` files, `webhook/{retired-domain}.php`, and root `api.php`. Do not upload only the new repair page.
- Deploy the updated `func/bc-connect.php`, new `func/bc-retired-*.php` helpers/view, purchase/requery guards, marketplace/cart/plan guards, installer and `.htaccess` from the same edition.
- Apache must honor the root rules. On Nginx/IIS, add equivalent deny/410 rules for retired paths and root callback test harness, and physically delete obsolete files. Check the actual deployed callback URLs return 410 or 404 and do not change wallets.
- Do not use the older general `cleanup_platform.php` for this incident-specific repair: it has broader pruning behavior, including old backups/logs, which may destroy evidence. Use the dedicated repair page described here.
- Existing security vulnerabilities are **not** all fixed by this change. Keep unrestricted money movement contained until the incident audit's financial/authentication defects are repaired and verified.

## Validation performed

- `tests/retired_provider_repair_test.php`: **66 checks** using the real planner/executor and SQL doubles: exact-host matching, unrelated-provider retention, tenant-scoped dependency cleanup, dry run, repeated no-op, rollback, stale preview, InnoDB refusal, removed handlers and helper parity.
- Modified/new PHP syntax checks: **41 files passed** at implementation validation.
- Existing suites: fintech gate **1,066**, policy **70**, gateway verdict **117**, mobile redesign **76** checks passed; six VTU service-handler gate tests passed.
- No live database was cleaned, no real MySQL concurrency was proved, and no production server was contacted or deployed to.
