# Business KYB submission and approval safety

Both editions' BusinessUpgrade submission and BusinessKYB review POST handlers now validate CSRF and rate-limit before uploading evidence or applying a decision. Their forms include the token.

Submission retains its validations and records a request only. Its final update now atomically requires a nonpending eligible personal account, active/unblocked in the same tenant. A second submit can no longer overwrite a request that became pending after the initial read; zero affected rows are failure, not success.

Business approval requires current approved KYC (including expiry and refresh mandate), an active/unblocked account, and a pending KYB request. Approval compares request reference/document plus KYC/status snapshot in its final update. Rejection compares reference/document. A changed request/security state between the decision read and write fails without promotion. Successful approval retains existing app-session revocation; account pricing level is unchanged.

Limits: these helpers are called by authenticated tenant-admin review routes; they do not independently authenticate the reviewer string. Snapshot comparison covers the decision-time row, not a version signed when an older review page was rendered. Durable reviewer identity/audit and self-review policy, stale-open-page approval protection, review/provider decisions and all alternate Business promotion routes remain separate audit scope. App revocation is still a subsequent operation rather than a new cross-table transaction; resolver account-type checks independently refuse Business app sessions. No historical approvals are changed.

Tests: fintech_gate_test.php exercises KYC refusal and the existing submission/approval/rejection flow with controlled fixtures. kyb_review_mysql_test.php executes scoped submissions, pending protection, KYC/expiry requirements, evidence snapshot predicates and replay in disposable MySQL for each edition (session revocation is observed with a test double). No schema migration, provider/device verification or deployment. Financial/crypto holds unchanged.

## Follow-up: rendered-review tickets

The tenant-admin review page now issues bounded (200 entries), ten-minute, random one-use session tickets for each rendered row, shared by its approve/reject forms. They bind the tenant-admin credentials and the full request/compliance snapshot. A missing, stale, cross-session/tenant/reviewer or replayed ticket is refused; matching nonempty email/username self-review is refused case-insensitively. Decision helpers compare the expected fingerprint after their fresh read, and their final SQL compares evidence fields to reject intervening replacements. Old open forms must reload. This supersedes the earlier stale-open-page limitation for this route only; independent beneficial-owner identity, all alternate callers and durable review audit remain unfinished.
