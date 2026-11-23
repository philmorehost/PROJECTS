# Current KYC enforcement policy

Both editions' isKYCEnforced wrapper now performs a local prepared lookup of the vendor force toggle and platform force_kyc option on every call. There is no session/TTL or same-request authorization cache, and cached getSuperAdminOption output is not consulted. One combined statement reads the tenant flag and platform aggregate; either enabled value requires enforcement. An absent platform option retains the default off semantics. Invalid policy values, a missing vendor or unavailable policy storage fail closed rather than implying disabled KYC.

Effective-check lookup retains its historical duplicate-collapse/fallback behavior, but if both queries cannot read check storage it now throws rather than returning an empty enabled list. App confinement also refuses invalid policy context instead of treating it as unrestricted. These errors may currently produce a generic server error; they do not grant access or mutate approvals.

This is a policy-freshness patch, not a full Business/KYB/review-provider audit. Existing approval status, optional expiry, and enabled-check semantics are unchanged. No changes to document acceptance, AI/provider approvals, account tiers, minimum funding, money movement, withdrawals or crypto holds. Complete end-to-end browser/device tests remain separate.

Validation: kyc_fresh_policy_test.php checks actual policy reader against controlled row changes, malformed policy and cached session inputs. compliance_mysql_test.php runs the real prepared policy reads and persisted expiry/confinement checks against disposable MySQL for both editions, including same-process toggle changes and missing check storage. No schema migration or deployment.
