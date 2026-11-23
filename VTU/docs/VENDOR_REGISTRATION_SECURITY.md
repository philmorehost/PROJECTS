# Vendor registration controls and verification

## Operator use

SAAS: **bc-spadmin → Manage Vendors → Vendor Signup Security**, or `bc-spadmin/VendorRegistrationSettings.php`. The same link is on Global Service Control and Pending Registrations. Vendor signup has its own ON/OFF setting, separate from customer registration. Missing setting preserves ON for compatibility, but mailbox verification is mandatory for every new application. OFF returns503 for the public page/POST and refuses code sends/completions. Existing vendors and login are not disabled. Save requires fresh active owner identity, POST/CSRF, demo refusal, rate limiting, PIN and configured action verification (existing platform-owner authenticator enrollment rule applies). Old codes are invalidated on every policy save.

## Mailbox / abuse defenses

Public VendorReg requires strict scalar/length validation, a12–72-byte password (raw password hashed; not HTML/SQL-transformed), valid phone/package/payment input, CSRF and a honeypot. Email addresses are bounded ASCII, syntactically validated and normalized without silently stripping hostile input; domain literals, malformed domains, common temporary-mail domains and configured blocked domains/subdomains are refused. An MX-capable domain is required; null MX/DNS lookup failure refuses intake. This deliberately does not certify a mailbox or guarantee a complete disposable-mail database.

Ownership requires a random six-digit email code, hashed at rest, browser-bound, address-bound and policy-epoch-bound. Codes expire after10 minutes, allow at most5 guesses, and are single-use through an InnoDB row lock/transaction. Resend removes the previous browser challenge. SMTP must use configured platform credentials, not tenant-selected credentials; delivery failure leaves no usable new code. Persistent rate limits are serialized by MySQL advisory locks:5 sends/IP/15 minutes,3/address/15 minutes,100/platform/15 minutes and20 verification attempts/IP/15 minutes. Raw IP comes only from REMOTE_ADDR; untrusted forwarded headers are not used. These application limits supplement, not replace, a hosting/WAF rate limit.

The old `web/WebsiteOrderRequest.php` direct active-vendor insertion is removed: SAAS redirects to verified intake; NON-SAAS returns410 because the single-owner edition must not create extra public vendors.

## KYC / KYB

Existing platform `force_kyc` and enabled `sas_super_admin_kyc_verifications` are read fresh, not from option caches. Vendor KYB can be required on the new security page; an existing `force_kyb=1` is also honored. KYB applications require registered business name/registration number. Mailbox verification and payment NEVER satisfy KYC/KYB.

In Pending Registrations, use **KYC / KYB review** only after completing all configured checks through the approved secure verification process. Record completed KYC/KYB case references, confirm evidence review and supply PIN/action verification. Do not place raw ID numbers or documents in case-reference fields. This is an explicit manual review checkpoint, NOT a newly integrated automated identity provider or public document-upload service. Review is bound to the applicant identity and required-check snapshot; identity or policy changes invalidate it. Approval refuses missing mailbox proof and required unreviewed compliance, even if paid. Completed proof/reviewer/case references and business details are copied to the vendor's onboarding record before the old pending row can be removed. A completed required KYC review initializes vendor KYC status as verified; no user-controlled approval flag is accepted.

Legacy pending rows are NOT silently treated as mailbox verified. They remain blocked and require controlled support remediation/resubmission; do not delete payment/audit evidence merely to clear a duplicate. Existing active vendor KYC enforcement is unchanged. Closing signup does not cancel paid applications already in the manual review queue.

## Deployment and testing boundaries

Additive schema version `2026.11.18-vendor-registration-1` adds hashed challenges, pending mailbox/business/review fields and vendor onboarding proof fields. No existing vendor/history is removed by migration. Settings require transactional InnoDB options storage. Before rollout confirm platform SMTP delivery, production DNS/MX resolution and the operator's secure KYC/KYB process. Email/domain validity does not prove lawful identity; legitimate mailboxes can still be abused.

Tests: pure boundary contracts plus disposable MySQL code expiry/guess limits/address+browser binding/resend/replay, concurrent single consumption, policy toggles/epoch revocation, real SQL entitlement policy reads, mail-failure refusal and persistent send limits. Mail/factor/demo/security-DDL are explicit doubles in storage tests; no real email, verification provider, deployment or live financial activation is performed.
