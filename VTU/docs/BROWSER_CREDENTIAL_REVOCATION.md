# Browser credential binding

Both editions bind user/vendor PHP sessions and SAAS platform-admin sessions explicitly at authenticated entry points. Live authoritative account rows are checked on browser configuration/enrollment entry. Missing stamps are rejected, never silently established on the first authenticated request.

Binding covers role, account and tenant identifiers, username/email, password, security PIN, transaction PIN and Google identity. Changing a covered value requires fresh sign-in. Balance changes do not. Login email-OTP pending state carries the authentication-time fingerprint; legacy VerifyOTP states lacking such a snapshot must restart sign-in. Credential-changing enrollment completion consequently requires signing in again.

## Rollout

Deployment is separate from merge. All existing browser sessions without bindings will require sign-in after deployment. Existing unbound app bearer tokens already require sign-in under the separate app credential-binding change. No schema migration or live cleanup is required.

The NON-SAAS predictable daily impersonation link has been removed in favor of the existing random, expiring, one-use login-token consumer. No new token minting permission is introduced.

The old biometric authentication endpoint only looked up a credential ID; it did not verify a WebAuthn assertion signature. It now returns 503/WEBAUTHN_VERIFICATION_REQUIRED and instructs password sign-in. Biometric enrollment/storage is not removed. Restoring biometric sign-in requires full server-side assertion verification and device/browser testing, not merely adding a session stamp.

## Limits and validation

This is a current-value fingerprint, not a monotonic per-account revocation epoch. Restoring identical old credential values can restore a still-existing binding. Legacy API-key authentication, all alternate temporary-access-link policies, Google token verification, full enrollment/KYC sequencing and device/provider checks are not declared complete. API-key code must not mint browser bindings merely because it writes session variables. Fail-closed browser checks also apply when an existing session accompanies a web API request; there is no blanket API-path exemption.

Pure/source regression: VTU/tests/browser_binding_test.php. Disposable native MySQL credential tests exercise browser binding against persisted credential changes for both editions through VTU/tests/app_credential_mysql_test.php. Financial/crypto restrictions remain unchanged.
