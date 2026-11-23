# Retired provider: first runtime-removal boundary

This change implements the user's explicit decision to discontinue Bybit. It supersedes previous Bybit purchase/configuration requirements.

Both editions remove the provider from the purchasable-module catalogue and encrypted configuration UI/allowlists. The old CryptoBybit.php URL returns410 without bootstrap, and CryptoHub no longer links to it. The old config entry point no longer reads environment or saved keys, and the old transport implementation is removed: all operations throw bybit_retired before any HTTP. Klasha credential configuration and Klasha/Platnova/Flutterwave module rules remain separate. No fallback sends an uncertain order to a different provider.

Compatibility function names and pure historical-data parsers remain while dependent custody/evidence/polling code is dismantled. This is not yet literal removal of all mentions/files. Historical transactions, audit evidence, saved encrypted credentials and entitlements are not deleted or refunded by this source change; live data cleanup/refund requires a separate authorized plan. Old workers must still be removed from deployment configuration even though the transport now refuses requests.

Tests of the retired HTTP implementation no longer describe a supported feature. CI replaces those outbound-adapter contracts with explicit refusal checks, while retaining historical parser and independent custody/accounting regressions. Paid-module concurrency tests now use the supported Platnova module; Klasha encryption and transactional checks remain. Remaining historical test files are not a certificate of a supported Bybit integration.

No A2P/P2A production trading readiness is claimed by this retirement patch. Full exact-reference homepage, dependency cleanup, provider capabilities, tenant-safe durable order/ledger integration, secure custody/signing and authorized real-provider/device verification remain outstanding. No schema migration, live trade, seed/key generation, network activation or deployment is performed. Financial and crypto holds remain intact.

## Native regression follow-up

The first native crypto run exposed a previously active-provider customer-binding fixture that still loaded retired credentials. The integration runner now exercises historical-record refusal instead: proposal/review/renew/address checkpoints reject the retired provider, rejected transitions leave no state/audit changes, revocation remains available with audit-failure rollback and stale-revision checks, and ledger/route counts remain unchanged. The former customer-binding/bound-address success fixture files remain historical only; they no longer describe supported provider capabilities. All unrelated ledger, polling, reconciliation and wallet MySQL cases remain in the runner. These are automated regression tests against disposable databases, not simulated product features or proof of live-provider readiness.

The follow-up also found that the old polling MySQL scenario chain relied on active-provider credentials. That chain is now historical, replaced by native retirement contracts for create/prepare/fetch/commit refusal and historical stream stop, audit rollback, and revision fencing (including stop without decryption keys). Pure polling protocol tests remain enabled. Active-provider polling/worker/delivery scenario coverage is **not** claimed as executed by the retirement MySQL runner; reusing those components for a new provider requires fresh integration coverage. The earlier statement above that all polling MySQL cases remain is superseded by this finding.

## Further reader/UI removal and cancelled staging

The user explicitly cancelled Bybit staging. The current completion checklist no longer requires it; old staging/drill files remain historical, not a release task to resume. This cancellation does not authorize replacement trading or bypass custody/security/provider verification.

Both editions now remove the implementation bodies of all five authenticated Bybit evidence readers (identity, parent, master address, subaccount address and deposit page), replacing them with direct `bybit_retired` tombstones. The readers no longer call credential loaders or transport. Offline historical-data normalizers and signature/query parsers remain where still referenced. The custody UI removes provider-read forms, new polling, fetch and new worker-grant controls, but retains historical evidence and local stop/revoke tools. CryptoHub no longer describes the integration as read-only discovery. Existing review-only records are not replacement-provider configuration or activation grants.

This remains dependency-safe retirement, not a claim that every Bybit symbol, historical test or schema label is deleted. No live database or deployment configuration is changed; operators must remove any externally configured old jobs under the approved rollout plan. No ledger changes, history deletion or automatic refunds.

## Request construction and stale scheduler retirement

Both editions now remove the old provider URL/endpoint catalogue, request-signature generation and API authorization-header construction from `bc-bybit.php`. Compatibility request/signature functions refuse with `bybit_retired`; offline parsing is retained only for historical records and dependent safety checks.

The old polling worker and polling-alert-delivery CLI launchers now unconditionally exit2 without application bootstrap, database access, environment enable flags or transport execution. HTTP requests receive410. Old enable flags cannot revive them. This stops stale scheduler entries from taking leases, creating attempts, loading secrets or making external alert calls for the cancelled integration. Operators should still remove those external scheduler entries at rollout. Historical evidence, leases, grants, delivery receipts, ledger records and stop/revoke maintenance functions are not deleted or rewritten. These launchers are not reused for replacement-provider services.

Regression tests cover direct request/signature refusal and launcher source boundaries. Native CI also executes each retired launcher with old enable flags set, requiring exit2, empty stdout and an explicit no-work message. WASM does not execute subprocess cases. No live scheduler/deployment changes are performed by this patch.
