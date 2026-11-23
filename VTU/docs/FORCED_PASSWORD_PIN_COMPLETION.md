# Forced password and PIN completion

Both editions retain this sequence: authenticated restricted session → password AND PIN replacement → fresh sign-in → required authenticator enrollment → normal compliance/access gates. TOTP enrollment already refuses an account whose forced-password flag is set. This change does not relax any KYC/KYB gate or declare that broader sequencing audit complete.

The forced-reset write now compares the authentication-time password and every available authoritative tenant/email/Google/PIN/status/block field, using null-safe comparisons, before atomically updating password, hashed security PIN, user legacy transaction PIN, and clearing the mandatory flag. A concurrent security-state change rejects the entire write; retry requires a fresh authoritative row. Inactive/blocked actors are refused. No schema changes.

The dedicated forced-reset handler is the only password/PIN completion path permitted to clear this flag. Ordinary user/vendor AccountSettings password handlers no longer clear it, including their legacy fallback writes. The existing enrollment guard still keeps those pages inaccessible while forced setup is pending; the handler change is defense in depth.

After a successful forced reset, all session data (including other role sessions and enrollment grants) is cleared, the session ID is regenerated, and the browser is redirected to the appropriate Login page with a completion notice. The old session is never restamped against its newly changed credentials. Other browser/app credentials are invalidated by their existing current-value credential checks. Independent Business developer API keys are unchanged.

Tests: enrollment_password_test.php validates inputs, atomic SQL and source integration; enrollment_password_mysql_test.php runs disposable native MySQL races for both account tables and editions, including PIN/null/identity/status changes, no partial updates and replay rejection. Enrollment, authenticator and fintech regressions remain separate. No provider/mail/device production verification or deployment; financial/crypto holds unchanged.
