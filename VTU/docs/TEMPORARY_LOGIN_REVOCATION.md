# Temporary login credential binding

SAAS temporary tokens for app OTP login, mobile browser handoff, user impersonation and vendor administrator impersonation now carry an authentication-time credential MAC. Lookup continues to require the persisted SHA256 token hash, expiry, purpose and unused state. One-use atomic consumption is preserved. Only a hash is stored; no schema migration.

The 64-lowercase-hex transport contract is unchanged: 16 random nonce bytes plus a truncated 16-byte HMAC-SHA256 keyed by the existing role/account/credential fingerprint. Password, security/transaction PIN, identity, tenant or Google linkage changes invalidate outstanding tokens. Inactive/blocked target accounts are refused. Fresh issuance reads authoritative account state. Old random unbound tokens fail closed, so users must restart pending login and handoff flows after deployment.

This is current-value binding, not a monotonic epoch: restoring all previous credential fields can restore validity of still-live persisted tokens. Very short concurrent credential-change races are not eliminated by transactional account locking. Issuer authorization and audit policies are unchanged. Legacy API keys are NOT rotated or disabled and remain separate unfinished scope.

NON-SAAS does not define these temporary-token utilities. Its Login/AppPortal consumers now refuse unavailable token support rather than fatalling; no substitute predictable impersonation mechanism is enabled. Implementing complete token issuance/consumption support there is separate scope.

Tests: login_binding_test.php pure/source checks; the SAAS disposable MySQL credential fixture additionally checks that persisted password reset rejects a pending login binding. No deployment/provider/device verification. Financial/crypto holds unchanged.
