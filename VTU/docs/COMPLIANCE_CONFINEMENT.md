# KYC confinement consistency

Both first-party app authentication entry points (shared credential bridge and dedicated app-account authenticator) now run the same KYC route guard. When existing forced-KYC policy requires confinement, only exact /web/api/kyc.php, /web/api/profile.php and /web/api/logout.php script paths are permitted. A basename elsewhere does not grant an exception. Other endpoint-specific permissions still apply.

An approval is current only when kyc_status is 2, no refresh mandate is set, and any recorded ID expiry is valid and not in the past. Blank/null expiry retains historical optional-expiry semantics. Expired/malformed expiry and refresh-required approvals no longer bypass app confinement merely because status remains 2. The helper is read-only; it never auto-approves or mutates existing submissions. Web whole-console confinement uses the same current-approval predicate.

While forced KYC confinement is active, minimum-funding redirection is suppressed so the verification page can remain reachable. This does not remove minimum-funding rules after compliance completion or enable transfers/funding/withdrawals for confined accounts. Security setup remains earlier in the authenticated bootstrap and app session resolver; password/PIN and authenticator requirements are not bypassed.

Scope and limitations: this patch is not a complete KYC/KYB audit. Existing policy caches, provider/AI review decisions, Business integration policy, suspension/security-question interactions and all alternate routes still need review. Existing force-toggle and enabled-check semantics are preserved. No complete browser/device/provider verification is claimed.

Tests: compliance_policy_test.php checks approval/route primitives and source wiring; fintech_gate_test.php exercises both actual authenticators with restricted/allowed paths using controlled policy fixtures; compliance_mysql_test.php checks real persisted expiry/refresh/review state with a fixed enabled force toggle for both editions. No schema migrations, deployment, live cleanup or financial/crypto activation.
