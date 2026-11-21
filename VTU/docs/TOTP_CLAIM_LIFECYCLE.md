# Fresh authenticator codes falsely reported as reused

## Cause reproduced offline

`bc_stepup_actor_second_factor()` validates a fresh TOTP and atomically advances
`totp_last_step` through the prepared `bc_q()` helper. It then checked
`mysqli_affected_rows($connection_server)` after that helper had closed its
statement. The connection counter at that point is not a reliable statement
result and may be -1 even though the update changed one row. The gate returned
"already been used" after consuming the code, before performing the user action.
This explains how repeated fresh codes can each be rejected by this path.

## Correction

`bc_q()` has an optional, by-reference third argument holding the write's affected
row count, captured from `mysqli_stmt_affected_rows()` BEFORE statement close.
The existing escaped fallback captures its connection count immediately after
its write. Existing callers retain the same return contract.

The TOTP gate now checks the captured count. Exactly one claimed row succeeds;
zero (or an unexpected row count) still refuses a stale/replayed claim. Prepare,
execute and invalid row-count failures stay fail-closed but report inability to
verify instead of falsely asserting replay. The table whitelist and atomic
`totp_last_step < candidate_step` condition remain unchanged.

This change is identical across SAAS and NON-SAAS. It does not reset TOTP state,
allow reused codes, bypass PINs/CSRF, or allow email to bypass enrolled TOTP.
No financial/account-policy holds were changed.

## Validation and deployment

```
node VTU/tests/harness/run-test.mjs tests/totp_claim_test.php
node VTU/tests/harness/run-test.mjs tests/user_action_gate_test.php
node VTU/tests/harness/run-test.mjs tests/security33_test.php
node VTU/tests/harness/run-test.mjs tests/security_audit_stepup_probe.php
node VTU/tests/harness/run-test.mjs tests/security_audit_stepup_nonsaas_probe.php
```

The isolated lifecycle test uses the actual TOTP verification and prepared helper
with mysqli test doubles. Closing a statement deliberately invalidates the
connection counter; the fresh code must still succeed. All three actor tables,
stale-row replay (concurrent-claim equivalent), refreshed-row replay, prepare/
execute failures, retry after failure, and no email fallback are covered.
Run this test without the native mysqli extension (`php -n` or the WASM harness).
It is not a live MySQL/production verification.

Deploy `func/bc-stepup.php` in the installed edition and reload/invalidate PHP
OPcache as appropriate. Previously rejected attempts may already have advanced
the replay counter, so wait for the NEXT authenticator code after deployment,
then submit the PIN and that fresh code once. No database cleanup or TOTP reset
is required. No live deployment, live API enablement or device test was performed.
