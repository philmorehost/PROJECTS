# Retired provider: first runtime-removal boundary

This change implements the user's explicit decision to discontinue Bybit. It supersedes previous Bybit purchase/configuration requirements.

Both editions remove the provider from the purchasable-module catalogue and encrypted configuration UI/allowlists. The old CryptoBybit.php URL returns410 without bootstrap, and CryptoHub no longer links to it. The old config entry point no longer reads environment or saved keys, and the old transport implementation is removed: all operations throw bybit_retired before any HTTP. Klasha credential configuration and Klasha/Platnova/Flutterwave module rules remain separate. No fallback sends an uncertain order to a different provider.

Compatibility function names and pure historical-data parsers remain while dependent custody/evidence/polling code is dismantled. This is not yet literal removal of all mentions/files. Historical transactions, audit evidence, saved encrypted credentials and entitlements are not deleted or refunded by this source change; live data cleanup/refund requires a separate authorized plan. Old workers must still be removed from deployment configuration even though the transport now refuses requests.

Tests of the retired HTTP implementation no longer describe a supported feature. CI replaces those outbound-adapter contracts with explicit refusal checks, while retaining historical parser and independent custody/accounting regressions. Paid-module concurrency tests now use the supported Platnova module; Klasha encryption and transactional checks remain. Remaining historical test files are not a certificate of a supported Bybit integration.

No A2P/P2A production trading readiness is claimed by this retirement patch. Full exact-reference homepage, dependency cleanup, provider capabilities, tenant-safe durable order/ledger integration, secure custody/signing and authorized real-provider/device verification remain outstanding. No schema migration, live trade, seed/key generation, network activation or deployment is performed. Financial and crypto holds remain intact.
