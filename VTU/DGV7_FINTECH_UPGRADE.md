# DGV7 fintech upgrade — implementation baseline

Blueprint: *Project Kickoff Blueprint — DGV7.0 Scripts Fintech Platform Upgrade* (18 November 2026). This is an implementation baseline, not a claim that any of the new financial services is operational.

## Phase 1 foundation in this change

- `DGV7.0-SAAS/func/bc-fintech-policy.php` and `DGV7.0-NON-SAAS/func/bc-fintech-policy.php` provide identical, side-effect-free account and new-service policy decisions.
- Legacy `account_level` 1 and 2 map to personal, 3 to business, without changing that level or its current pricing. Once a properly approved and persisted `account_type` exists, it takes precedence. Unknown values fail closed.
- The policy requires explicit platform, vendor, module entitlement, provider-readiness, and account-tier controls for **new** services. Personal developer API access is always denied in the decision primitive.
- `tests/fintech_policy_test.php` tests the mappings, independent controls, and fail-closed behavior across both copies.
- `bc_fintech_mobile_app_allowed($user)` allows Android/iOS sign-in and first-party token issuance for **personal accounts only**. Business accounts are web-only, including accounts grandfathered from legacy level 3 and users who upgrade from personal to business; access must be rechecked when tokens are issued and refreshed.
- `bc_fintech_android_app_url_valid($androidAppUrl)` validates a website setting: unset/empty is valid and disables the redirect; otherwise only HTTP(S) URLs (including localhost staging URLs) or same-origin absolute APK paths are accepted. Protocol-relative URLs, unsafe schemes, URL credentials, control characters/whitespace, backslashes, dot-segment (`..`/`%2e%2e`) traversal and malformed values are rejected. Use it in bc-admin before saving.
- `bc_fintech_web_redirect_target($user, $androidAppUrl)` returns the destination a personal website login must follow, or `null` when none applies. Business and unknown accounts always get `null`; a blank setting also gets `null`, so personal users keep normal website access. A non-empty but invalid setting also gets `null`: callers must detect that state with `bc_fintech_android_app_url_valid()` and fail closed with a configuration error, never fall through to the dashboard or emit an unsafe `Location` header.
- Tested in `tests/fintech_policy_test.php` across both copies: legacy/persisted tiers, business grandfathering and upgrades, unknown users, blank/whitespace/null settings, Play Store/localhost/same-origin URLs, and rejection of unsafe or malformed destinations.

**Wiring status:** Phase 1B wires the policy into app login, app token issue/refresh, profile, website login and page access (see the Phase 1B section below). It is still **not deployed**, and personal developer-API lockdown remains unimplemented.

## Phase 1B in this change (implemented, not yet active in production)

**Schema — additive and lazy**
- `func/bc-fintech-schema.php` adds `sas_users.account_type` (NULLable — NULL keeps the legacy `account_level` mapping), `account_type_source/at`, and KYB state (`kyb_status`, `kyb_reference`, `kyb_submitted_at/reviewed_at/reviewed_by`). New columns are NULL/default so existing INSERTs keep working.
- `sas_site_details.android_app_url` is a new, separate setting. It never inherits `apk_download_url` or `app_redirect_mode`.
- `sas_service_control` gains `personal_enabled`, `business_enabled`, `provider_ready`. The existing `status` column keeps its current meaning, so no live VTU service changes behaviour.
- `sas_app_tokens` stores only a SHA-256 of each first-party app session token.
- Everything runs lazily from `bc-connect.php` with per-column `SHOW COLUMNS` guards. **`SYSTEM_VERSION` was deliberately not touched**: changing that marker re-runs the large legacy migration block (the OTP outage). No `DROP`/`TRUNCATE`, no row rewrites, no backfill of `account_type`.

**First-party app tokens**
- `func/bc-app-token.php`: 32-byte opaque token, hash-at-rest, 30-day expiry, `last_used_at`, per-device revoke and revoke-all. Issued on app login, returned as `data.app_token` with `data.app_token_expires_at`.
- `api_key` is **not** rotated or invalidated; existing app builds and merchant integrations keep working during migration.
- Every lookup re-reads the account and re-applies the policy, so an upgrade to Business kills live sessions immediately. `web/api/logout.php` revokes exactly the presented token.

**Enforcement (`func/bc-fintech-gate.php`)**
- App login (`web/api/login.php`, SAAS `login-otp.php`) refuses **before** any credential, balance or token is returned: HTTP 403 `Business accounts are accessible via web only`.
- `profile.php` re-applies the policy when an app token is presented; merchant requests without one are unaffected.
- Website login applies `bc_fintech_web_login_flow()` only **after** the session exists and OTP (if enabled) has passed.
- Ordinary pages are gated by `bc_fintech_web_access_blocked()` in `bc-config.php`, active only when a valid `android_app_url` is saved. Logout, login, lockout, API and AJAX routes stay reachable; super-admin impersonation keeps the website.
- A saved URL that fails validation shows a configuration error instead of the dashboard — never treated as "no redirect configured".

**Known limits of this phase**
- The **other** ~60 app endpoints still authenticate with `api_key`; they do not yet require an app token, so a Business account holding its old key could still call them until they are migrated. `profile.php` + login/token issue/revoke are done.
- Personal developer-API lockdown is still **not** enforced, and must not be until those endpoints accept app tokens.
- No `account_type` backfill has run; until then policy resolves entirely from `account_level`.
- Not deployed. No staging database, provider sandbox or live traffic has exercised any of it.

**Phase 1C checklist (next)**
1. ~~Dual-auth across every app endpoint.~~ **Done** (see the Phase 1C section).
2. Publish `app_token` in the DG7 / JIKABIZ / MZEEVTU Android and iOS clients, send it as `Authorization: Bearer`, call `web/api/logout.php` on sign-out, and handle the Business 403 with the web-only message.
3. Add a reviewed, auditable `account_type` backfill (level 1/2 → personal, level 3 → business) with rollback; keep `account_level` and every price untouched.
4. Add bc-admin/service UI for `personal_enabled` / `business_enabled` / `provider_ready` and a KYB review screen; do not enable any new fintech service without provider confirmation.
5. Staging: run the lazy migration against a restored production snapshot, verify duplicate-row `sas_site_details`, confirm logout/impersonation paths, then load-test the redirect gate.

## Phase 1C step 1 in this change — dual-auth bridge (staged, no hard lockdown)

**Why staged, in this order.** Live Android builds authenticate every `web/api/*` endpoint with
`sas_users.api_key`. Locking `api_key` before the app ships a bearer token would sign every
personal user out of airtime, data, bills and profile at once. The endpoints therefore learn both
credentials first, and the lockdown stays a separate, later switch.

- `bc_fintech_api_credential()` (in `func/bc-fintech-gate.php`) is now the credential resolver for
  **114 call sites across 57 endpoint files per edition**. Each endpoint keeps its existing
  `WHERE api_key='...'` lookup untouched:
  - valid `Authorization: Bearer <app_token>` → substitutes the token owner's `api_key`, so the
    query resolves to that account; the posted key is ignored and cannot be spoofed;
  - invalid/expired/revoked token → **HTTP 401 `APP_SESSION_INVALID`**, never a silent fallback;
  - no token → the supplied `api_key` passes through, keeping current app builds and merchant
    integrations working;
  - a Business account is refused here too, reusing the Phase 1B decision and message.
- Endpoints that call the resolver before `resolveVendorID()` pass `0`; the vendor then comes from
  the session row (`bc_app_session_user_any_vendor()`), never from the request.
- `tests/fintech_gate_test.php` asserts every endpoint resolves through the bridge and that **no
  endpoint is left on the raw `api_key` path**.

**Still outstanding, in this order**
1. Ship the DG7 / JIKABIZ / MZEEVTU Android + iOS builds that store `app_token` and send
   `Authorization: Bearer`. Until then the token path is unused in production.
2. ~~Run the reviewed `account_type` backfill.~~ Done in Step 2 below — the operator still has to
   press the button in each tenant's Service Control page.
3. Only then make `bc_fintech_api_credential()` refuse `api_key` for personal accounts, and hide
   `APIDocs.php` / key regeneration from them. **On hold** until (1) is confirmed deployed.

**Known limits**
- `security-quest.php`, `set-pin.php`, `biometric.php` and `databundle-card*.php` read the key from
  query/POST fields; they resolve through the same helper but need the token as a header, which
  the app update will send.
- Merchant `api_key` traffic is intentionally unaffected at this stage.

## Phase 1C step 2 in this change — backfill, service matrix, KYB

Step 2 turns the account tiers into something an operator can actually run. It is deliberately
**not** the hard `api_key` lockdown (step 3, on hold until the Bearer-token apps are deployed).

### 1. `account_type` backfill (idempotent)

`bc_fintech_backfill_account_types($vendor_id, $dry_run)` in `func/bc-fintech-accounts.php`
(both editions, byte-identical):

| Rule | Behaviour |
| --- | --- |
| Levels `1`, `2` | → `account_type = 'personal'` |
| Level `3` | → `account_type = 'business'` |
| Levels `0`, `4`, malformed | Left `NULL`, counted as `unknown_level` — a guessed tier would grant or remove access nobody approved |
| Rows that already have a tier | Never touched, so a manual correction survives a re-run |
| `account_level` | **Read only.** It still drives prices, parameter tables and the legacy API |
| Provenance | `account_type_source = 'backfill_2026_11'`, `account_type_at = NOW()` |

The console runs a **dry run first** and only writes when the operator ticks the confirmation box,
so a stray submit or a prefetch cannot reclassify accounts. `bc_fintech_backfill_dry_run()`
returns per-bucket counts (`personal`, `business`, `already_set`, `unknown_level`, `invalid_tier`).

### 2. Per-tier service matrix in both consoles

`bc_fintech_matrix_services()` defines the tier-aware services:
`developer_api`, `airtime`, `esim`, `tuition`, `bills`, `invoices`, `pos`, `transfers`,
`cross_border`, `stablecoin`, `fx`, `virtual_accounts`, `referrals`.

Per row the matrix exposes four switches on `sas_service_control`: `status`, `personal_enabled`,
`business_enabled`, `provider_ready`. A tier may use a service only when **all** of them are on:

```
usable(tier) = status && provider_ready && <tier>_enabled && !locked_off(service, tier)
```

- **`developer_api` is permanently off for Personal.** This is enforced in
  `bc_fintech_matrix_set()` (an enable attempt is refused with a message) **and** in
  `bc_fintech_tier_effective()`, so the UI and the runtime cannot disagree even if a row is edited
  in SQL. The switch renders disabled with a `locked` badge.
- New fintech services ship with `provider_ready = 0`, so turning a service on in the UI cannot
  expose an uncontracted provider.
- **Vendor console** (`bc-admin/ServiceControl.php`): scoped to the logged-in vendor.
- **Platform console** (`bc-spadmin/ServiceControl.php`): `$matrix_selectable = true` — a tenant
  selector validated against `sas_vendors` before any write, session-pinned. The platform console
  has no vendor context of its own and does not invent one.

Controller and view are separate files (`bc-service-matrix.php` / `-render.php`, and the same split
for the backfill card) so the page can include logic in its PHP preamble without emitting output
before the post/redirect/get redirect.

### 3. Personal → Business upgrade (KYB) flow

| Piece | Path | What it does |
| --- | --- | --- |
| User form | `web/BusinessUpgrade.php` | Business name, registration number, category, document upload. Linked from the user navigation and from the dashboard area. |
| Submit primitive | `bc_fintech_kyb_submit()` | Validates and records `kyb_status = 1`. **Never** sets `account_type`. |
| Review queue | `bc-admin/BusinessKYB.php` | `bc_fintech_kyb_pending()` — tenant-scoped, oldest first; approve / reject with a reason; recently decided list. Linked from the admin navigation. |
| Approve | `bc_fintech_kyb_approve()` | The **only** code path that sets `account_type = 'business'` (source `kyb_approved`), guarded on `kyb_status = 1`, records the reviewer, and calls `bc_app_token_revoke_all(..., 'account_type')` in the same operation. |
| Reject | `bc_fintech_kyb_reject()` | Requires pending status, keeps the account personal, stores `kyb_note` (≤ 2000 chars) for the applicant. |

`kyb_status`: **0** not started, **1** under review, **2** approved, **3** rejected.
Categories: `sole_trader`, `limited_co`, `partnership`, `ngo`, `fintech`, `other`.

Guards worth knowing:

- A second submit while a request is under review is refused **inside the primitive**, so the
  evidence a reviewer is looking at cannot be overwritten by a replayed POST.
- New columns: `kyb_business_name`, `kyb_registration_no`, `kyb_category`, `kyb_document`,
  `kyb_note` (plus the existing `kyb_status`, `kyb_reference`, `kyb_submitted_at`,
  `kyb_reviewed_at`, `kyb_reviewed_by`) — additive, handled by `bc_fintech_ensure_schema()`.
- **Documents are private.** Stored in `/uploads/kyb/` with a deny-all `.htaccess`, named
  `kyb_{user_id}_{16 hex}.{ext}`, accepted only as PDF/JPG/PNG/WebP ≤ 8 MB with `getimagesize()`
  confirming images and `is_uploaded_file()` confirming the upload. They are served **only** by
  `bc-admin/BusinessKYB.php?doc=1&uid=…`, which re-checks the document belongs to that vendor,
  resolves the path with `realpath()` and requires it to sit inside the upload root, releases the
  session lock before streaming, and sends `nosniff` + `no-store`.
- **No fee is charged on submit.** The existing `sas_user_upgrade_price` / `handle_upgrade.php`
  path still bills `account_level` changes and is deliberately untouched; a Business upgrade fee,
  if configured later, belongs after approval and never before it.
- `account_level` is not changed by the upgrade, so the account keeps its existing pricing.

### Test coverage added

`tests/fintech_gate_test.php` grew from 193 to **402 checks**: dry-run vs. write counts, unknown
levels left `NULL`, explicit tiers never overwritten, `account_level` untouched, re-run idempotency,
the `developer_api` lock at both layers, matrix writes and refusals, tenant isolation of the queue
and of document access, the full submit→approve/reject lifecycle, session revocation on approval,
and structural assertions that every new page routes through the shared primitives.

## Phase 2 in this change — Platnova-style web redesign and human + AI support chat

Phase 2 is the design-and-support slice of the delivery sequence. It ships presentation, navigation
gating and a support channel; it wires **no new provider** and moves no money.

### 1. Landing page template 6 ("Plate") — `index-bc-style-template-6.php`

A tenant-selectable landing page alongside templates 1–3. Selection runs through **bc-admin →
Account Settings**, whose list now offers `bc-style-template-6.css` (labelled `— PLATE`).

Templates 4 and 5 were **removed from that list**. They have no `index-bc-style-template-N.php`, and
`index.php` falls back to template 1 when the file is missing — so offering them presented a choice
that silently did nothing. Re-add them only when a page exists.

Layout: brand bar → hero with the Personal/Business switch → three-step cross-border flow → service
grid → trust strip → footer. Conventions it obeys:

- The SEO hooks `seo_start_ob_auto_alt()`, `seo_render_head_tags()` and `seo_render_custom_footer()`
  are called in the same order as the older templates.
- The logo resolves from `uploaded-image/{host with . → -}_logo.png`, as the other templates do.
- The brand colour is validated against `^#[0-9a-fA-F]{6}$` and falls back to `#0f766e`, so a bad
  tenant value cannot inject CSS.
- Icons are inline SVG (`bc_plate_icon()`). The landing page loads no icon font, so a `bi bi-*`
  class would render as an empty box.
- **Both panels are rendered server-side and the switch only toggles visibility.** With JS disabled
  the Personal panel stays visible and the Business content is still in the markup. The switch
  implements the tablist keyboard pattern (arrow keys move between tabs).

**The three-step flow is illustrative, not a promise of live rails.** "Create USD wallet → fund it →
send to China" describes the intended cross-border path. Until the Phase 3 adapters exist the copy
must not imply a funded USD balance is available.

### 2. Marketing gating — `bc_fintech_marketing_partition()`

Every card on the landing page and the dashboard comes from
`bc_fintech_marketing_partition($vendor_id, $tier)` in `func/bc-fintech-accounts.php`, which returns
live and soon buckets. A card is **live** only when the tenant row has `status = 1` **and**
`provider_ready = 1` **and** the tier switch (`personal_enabled` / `business_enabled`) is on — the
same three-way rule as the service matrix, so a card can never advertise something the account gate
would refuse.

Unavailable cards are **shown as "Coming soon" with a dashed outline, never hidden.** Hiding them
would make the product look smaller than it is and would leave a tenant with no signal that the
service exists. This is what keeps Klasha, Flutterwave Global, eSIM and Global Tuition cleanly gated
until the Phase 3 adapters land: they are advertised, visibly not purchasable, and unreachable.

Tier placement: eSIM is personal-only; tuition, invoices, POS and `developer_api` are business-only;
wallet, cross-border and FX appear for both. `developer_api` is never advertised to personal — the
partition helper refuses it even if a caller passes a catalogue that claims otherwise, and the gate
tests assert it.

### 3. Dashboard accounts block — `func/bc-dashboard-accounts.php`

Included by `web/Dashboard.php` above the existing balance hero. It renders:

- **Multi-currency wallet cards** from `bc_wallet_cards()`. Only **NGN is custodied**; USD, GBP and
  EUR read "Not open yet" unless a real balance exists. A custodied currency with a zero balance also
  reads "Not open yet" rather than `0.00`, because a zero that looks like a balance invites a
  "where is my money" ticket.
- **Rate strip** from `bc_fx_rate_snapshot()`. With no feed wired it reports *"Live rates are
  temporarily unavailable."* A zero rate is treated as unavailable too: a zero is indistinguishable
  from a real quotation of zero and would be acted upon.
- **Tier pill** from `bc_fintech_tier_badge()`: `tier-personal`, `tier-business` or `tier-unknown`.
  Unknown is labelled honestly rather than defaulted to personal.
- **KYB status** translated from `kyb_status` `[0 Not started, 1 Under review, 2 Approved, 3 Rejected]`.
- **Upgrade callout** to `web/BusinessUpgrade.php`, shown only when `tier === 'personal'` and
  `kyb_status ∉ {1, 2}` — not while a review is open, and not once approved.

The block loads its own dependencies and **returns early if any helper is missing**. Dashboard.php
includes only `bc-config.php`, so a partial deployed ahead of its helper file must render nothing
rather than fatal the busiest page in the product.

Navigation in `func/bc-header.php` is tier-aware via `$bc_nav_tier_service()`: **Developer API** and
**Business Upgrade** are gated by the same matrix. The matrix is read at most once per request, and
the header tolerates a page that includes it before the session user is loaded.

### 4. Human + AI support chat

Four shared files per edition, byte-identical across editions (CI `cmp`s them):

| File | Role |
| --- | --- |
| `func/bc-support-chat-schema.php` | `bc_chat_ensure_schema()` creates `sas_chat_threads`, `sas_chat_messages`, `sas_chat_events`; `bc_chat_settings()` / `bc_chat_setting_save()` over `sas_settings` |
| `func/bc-support-chat.php` | Engine: thread lifecycle, messages, escalation, agent queue, AI reply |
| `func/bc-support-chat-widget.php` | Customer widget, included from `func/bc-footer.php` |
| `web/ajax-support-chat.php` | JSON endpoint the widget polls |

**Schema.** `sas_chat_threads(id, vendor_id, user_id, username, subject, status, assigned_admin,
last_message_at, last_message_by, unread_for_agent, unread_for_user, ai_enabled, created_at,
closed_at)`; `sas_chat_messages(id, thread_id, vendor_id, author_type, author_name, body,
created_at)`; `sas_chat_events(...)` for the audit trail. `status` is
`open | pending_agent | with_agent | closed`.

The schema file deliberately does **not** include the fintech schema. "We cannot take payments" and
"we cannot answer the customer asking why" are different failures and must not become one.

**State machine.** `open` with `ai_enabled = 1` → the assistant answers. "Talk to a person" calls
`bc_chat_escalate()`, which moves the thread to `pending_agent` and clears `ai_enabled`. An agent
claiming it calls `bc_chat_claim_thread()` → `with_agent`, records the agent and posts the handoff
line. Closing ends the thread; the customer's next message opens a **new** one rather than resuming
a transcript they just finished with. **The assistant is silent whenever `ai_enabled != 1` or
`status != open`** — including after a tenant switches AI on mid-conversation, so it can never talk
over an agent.

**Escalation is not a dead end.** If the provider call fails, the customer still gets a message
saying a human will follow up, and the thread is escalated. A dead AI must not look like a dead chat.

**Key handling.** The key lives in `sas_settings` and is **write-only through the UI**:
`bc-admin/ChatSettings.php` renders a blank password field and shows only the last four characters as
a hint that a key exists. Leaving the field blank **keeps** the stored key, so editing the welcome
text cannot silently wipe the credential; clearing it requires an explicit checkbox. A key shorter
than 16 characters saves with a warning. The page warns when the assistant is on with no key — the
failure that otherwise looks like "the assistant ignores everyone". The endpoint never returns the
key, and the widget contains no key, model or prompt.

**AI providers.** Settings expose gemini and deepseek with **model optional** (the provider default
is used when blank). `bc_chat_ai_call()` prefers the platform `AIEngine` when the build ships it and
falls back to a direct HTTP call; both failing is what triggers escalation.

The prompt hard-forbids stating balances, promising refunds or requesting credentials, and instructs
the assistant to reply `ESCALATE` when the question needs account truth. It is a first-line helper,
not a source of account data.

**Agent console.** `bc-admin/SupportChat.php` gives waiting/in-progress lists, the transcript, and
claim / reply / close / pause-assistant. Actions are POST with a redirect, so a refresh cannot replay
a reply. Tenant scope comes from the admin's own id, and another tenant's thread id resolves to
"not found" rather than to their conversation. Flash messages use `bc_chat_flash`, not the purchase
flash.

**Endpoint security.** `web/ajax-support-chat.php` is POST-only, 401s without a session, binds the
user to `$get_logged_user_details` (**`user_id` is never read from the request body** — CI greps for
this), caps bodies at 4000 characters, sends `Cache-Control: no-store`, and 404s unknown threads so
ids cannot be probed across tenants. The public thread/message payloads are allow-lists, so a column
added later cannot leak by default.

### Test coverage added

`tests/fintech_gate_test.php` grew from 402 to **576 checks** across four new sections: marketing
partition gating (per tier and per `provider_ready`), wallet and rate presentation (including that a
zero rate is never quoted), the full chat lifecycle (open → escalate → claim → close → reopen,
the 4000-character cap at the boundary, tenant isolation on read and write, and the assistant staying
silent on an escalated thread), and structural wiring — including byte parity for the nine shared
Phase 2 files and a guard that the chat engine contains no money-moving calls.

### 5. Sidebar regrouped into service groups

The signed-in sidebar was ~23 flat top-level entries. It is now **six groups plus Dashboard**, in the
order a customer actually uses them:

| Group | Contents |
| --- | --- |
| **Dashboard** | single entry |
| **Pay Bills** | data, airtime, bulk data, bulk airtime, cable TV, electric token, betting, card printing, exam pins |
| **Wallet & Transfers** | fund with ATM card, fund via bank transfer, wallet to bank, share fund, share fund history, convert coins, virtual card |
| **Financial Services** | NIN slip + history, BVN verification, number filter, gift card, crypto |
| **Business & API** | AI Suite, AI Assistant Terminal, bulk SMS, submit SMS ID, account pricing, business upgrade, developer API |
| **History & Records** | transactions (nested: all, batch, points, calculator, number credit checker), Print Hub (nested: vending dashboard + the six print types), payment orders, submit payment |
| **Settings & Help** | account settings, help / guide |

Every existing `isServiceEnabled(...)` gate and the `$bc_nav_tier_service('developer_api')` /
Business-upgrade tier rules are preserved verbatim, so regrouping changed no entitlement. No service
link was dropped or added — asserted by the test suite against a fixed list of 40 pages, and the
`data-bs-target` of every collapse resolves to an `id` in the same block. The two nested groups use
`data-bs-parent` pointing at their own parent group so the accordion behaviour is correct at both
levels. The flat `Services` heading is gone.

`bc-header.php` and `bc-admin-header.php` legitimately differ between editions and are not parity
checked; the regrouped block itself is identical, and the only remaining differences are the
pre-existing ones (the standalone edition has no `ai_suite` / `vtu_coins` gates and keeps its own AI
widget condition).

## Phase 1C gateway change — Plisio retired

Plisio.net is defunct. It is retired **without deleting history**:

| Surface | Behaviour now |
| --- | --- |
| `func/bc-gateway-policy.php` | New and identical in both editions: `bc_gateway_is_retired()`, `bc_gateway_allowed_for_new_traffic()`, `bc_retired_gateway_message()`. |
| `web/api/crypto.php` | `create_invoice`, `swap`, `withdraw`, `get_rate` and other new-traffic actions return `GATEWAY_RETIRED`. `get_wallets` and `history` still work so users can see and reconcile existing funds. |
| `users-plisio.php` | Returns **410 Gone**, logs the attempt, and exits before any legacy fulfilment code. |
| `bc-admin/ServiceControl.php`, `bc-spadmin/ServiceControl.php` | Plisio removed from the active gateway list; the label is kept commented for support. |
| `bc-admin/PaymentGateway.php` | Removed from the gateway screen and webhook map; the SAAS activation purchase is refused **before** `chargeVendor`, so nobody pays a fee for a dead provider. |
| `bc-tables.php` | Fresh installs seed `plisio` as disabled. An **existing** row is never flipped, so a deliberate later decision or rollback is not overwritten. |
| Preserved | `sas_vendors.plisio_activated`, `plisio_activation_fee`, `plisio_tx_id`, gateway keys and every transaction row. |

**Replacement is not implemented.** Klasha and Flutterwave stablecoin adapters are Phase 3. No
deposit, payout or conversion is routed to them yet, and the crypto hub must not be advertised as
working until a provider agreement, sandbox entitlement and verified webhook signature handling
exist.

## Phase 3 in this change — provider adapters (Klasha & Flutterwave v3)

**Status: implemented, not live.** No provider call has been made to a real host and no
provider is contracted. Every specification-dependent value ships EMPTY and every adapter
refuses while it is empty.

### The documentation blocker

The build sandbox cannot reach the Klasha or Flutterwave documentation hosts. No endpoint
path, payload field name, error code or crypto scheme in this change was invented. The
registry (`bc_fintech_provider_registry()` in `func/bc-fintech-providers.php`) holds:

| Value | Klasha | Flutterwave v3 |
| --- | --- | --- |
| `base_url` | **EMPTY** | **EMPTY** |
| endpoint paths | **EMPTY** (7 keys) | **EMPTY** (11 new keys) |
| webhook `fields` map | **EMPTY** (9 keys) | carried over from the live handler |
| `signature` | **EMPTY** header + algorithm | `verif-hash` / `plain_compare` (verified in production) |
| `crypto.scheme` | **EMPTY** | n/a |
| `assets` | `USDC`, `USDT` (no RLUSD) | `USDC`, `USDT` (no RLUSD) |

An empty value is a refusal, never a guess. `bc_fintech_provider_configured()` returns a
stable reason code — `credentials_not_configured`, `base_url_not_configured`,
`endpoint_not_configured`, `field_mapping_not_configured`,
`signature_scheme_not_configured` — and `bc_fintech_provider_request()` returns **before**
the HTTP seam, so an unconfigured adapter cannot call a guessed URL. The refusal message for
an endpoint is exactly `endpoint not configured`.

The one exception, deliberately documented: `bc_flw_verify_transaction()` keeps the
`https://api.flutterwave.com/v3/transactions/{id}/verify` URL that the **live**
`users-flutterwave.php` handler already calls. That is a carried-over production value, not a
guess, and a registry `base_url` overrides it the moment the Phase 3 spec is supplied.

### Decision 1 — durable webhook idempotency

`sas_webhook_events (provider, event_id, vendor_id, status, created_at, completed_at)` with
`UNIQUE KEY uniq_provider_event (provider, event_id)`, created by `bc-fintech-schema.php`.

`bc_fintech_webhook_claim()` is **insert-then-check**: it attempts the INSERT and treats a
duplicate-key error (1062) as `already_processed`. A retried delivery is therefore skipped,
never re-applied, and the endpoint still answers 200 so the provider stops retrying.
`bc_fintech_webhook_release()` deletes a claim **only when nothing was credited**, so a
genuine retry can proceed; nothing after a credit ever releases it.

Pipeline (`bc_fintech_webhook_handle()`), identical for both providers:

```
signature -> claim -> provider API verification -> match -> ledger credit -> complete
```

A failure before the credit releases the claim and answers 200; a failure after it cannot.
Every delivery that is received and classified is acknowledged with 200 — including a forged
signature (which will never become valid, so retrying is pointless) — with the machine-readable
reason in the JSON body and the detail in logs/<provider>_webhook.log. Only a request that
cannot be processed at all (malformed JSON, no event id, or a server-side state such as a failed
claim) gets a non-200 status.

### Decision 2 — one atomic ledger primitive

`bc_fintech_ledger_post()` in `func/bc-fintech-ledger.php` is the **only** writer for provider
money movement. Adapters never run their own `UPDATE` (CI greps for it).

- **NGN** → `sas_users.balance` (`DECIMAL(65,30)`), and mirrored into `sas_transactions` so the
  credit appears in the customer's history, which is what `chargeUser()` does for every other
  NGN credit.
- **USD / GHS / ZAR / USDC / USDT** → `sas_user_crypto_wallets`, whose `balance` and
  `ledger_balance` both move. No third balance store exists.

Each post is one MySQL transaction: lock the balance row `FOR UPDATE`, apply the delta with
`sprintf('%.18F', $amount)` (exponent notation is rejected by MySQL), read the fresh balance,
write the `sas_fintech_ledger` audit row, commit. Any failure rolls back, so a refused post
leaves **no** balance change and **no** audit row. Non-numeric, non-finite, zero and negative
amounts are rejected — the direction argument carries the sign.

The NGN path mirrors `bc_atomic_debit_user()` (`func/bc-security.php`) rather than calling it,
because the audit row must commit in the same transaction as the balance change; a helper that
commits internally could leave a committed debit with no audit row.

### Decision 3 — `provider_ready` stays 0

Unchanged. `bc_fintech_tier_effective()` still requires `status` + `provider_ready` + the tier
switch, and `sas_service_control` keeps `UNIQUE (vendor_id, service_name)`. No adapter is
reachable while `provider_ready = 0`.

### Provider credential storage

No new table. `bc_fintech_provider_credentials()` reads the tables that already hold provider
keys, in the order each provider declares:

- `sas_bank_transfer_gateways (vendor_id, gateway_name, public_key, secret_key, encrypt_key,
  transfer_fee, status, PRIMARY KEY (vendor_id, gateway_name))` — **Klasha**, editable in
  `bc-admin/PaymentGateway.php` (the withdrawal/payout credentials screen now offers Klasha).
- `sas_payment_gateways (...)` — **Flutterwave**, whose `encrypt_key` is already the
  `verif-hash` secret.

Both screens already implement the write-only rule: a blank field keeps the stored value.

### Klasha

`func/bc-fintech-klasha.php`. Intended products are exposed as endpoint-keyed wrappers that
all refuse while their path is empty: China wire (`beneficiary`, `payout`), multi-currency
`wallet`, `fx`, `quote`, USDC/USDT `collection`, and transaction `verify`.

**The "3DES payload encryption" in the brief is UNVERIFIED and is NOT implemented.**
`bc_klasha_payload_encrypt()` / `_decrypt()` are an explicit seam that defaults to TLS-only
JSON and logs a warning; a configured-but-unimplemented scheme also falls back rather than
guessing a cipher. Authenticated encryption done wrong is a security defect, not a gap. CI
fails the build if a cipher appears in the adapter.

Klasha webhooks currently refuse with `signature_scheme_not_configured` — the honest state
until the signature scheme is confirmed.

### Flutterwave v3

`func/bc-fintech-flutterwave.php` **extends** the existing handler rather than duplicating it.
The verification approach is the one already proven in `users-flutterwave.php` and is the
reference for the new adapters: `hash_equals` on the `verif-hash`, then confirm the transaction
with the v3 verify endpoint, then require the verified `tx_ref` to equal the posted reference
before any credit.

New endpoint-keyed wrappers, all config-driven and all refusing while empty:
`/v3/bill-categories`, bill validation and payment, payment links (`payments`), payment plans
and invoices (`payment_plans`), USD/GBP/EUR virtual accounts, single and bulk transfers,
regional payment toggles, and the Android POS request workflow. Payload field names are **not**
invented — payloads are forwarded unchanged.

`users-flutterwave.php` now delegates to the shared pipeline: same verification, but the credit
goes through `bc_fintech_ledger_post()` and the delivery is claimed in `sas_webhook_events`
first.

**Receipts are re-attached at the delivery layer.** The ledger primitive deliberately does not
send mail or apply referral/loyalty logic — those are presentation concerns, and `chargeUser()`
mixing them into the balance write is part of why a second writer was dangerous. Because the
ledger is presentation-free, the receipt the old path produced through `chargeUser()` is
re-attached by `bc_fintech_webhook_notify()` in `func/bc-fintech-webhook.php`, which runs
immediately after a successful credit:

- `user-funding` to the wallet owner and `user-transactions` to the vendor — the same two
  templates, the same recipients, and the same `gross @ net` amount that `chargeUser()` reported;
- the template lookup is **vendor-explicit** (`bc_fintech_email_template()`), because a webhook
  cannot use `getUserEmailTemplate()`'s host-based vendor resolution — the delivery arrives on
  whatever host the provider was configured with, and the vendor is recovered from the checkout row;
- the sender is `sendVendorEmail()` in production and can be overridden with
  `$GLOBALS['bc_fintech_email_sender']` (tests, or a future queue);
- a mail failure is logged and swallowed. The money has already moved and the delivery is already
  claimed, so a mail problem can never fail the webhook, release the claim, or cause a second
  credit. This is asserted by the test suite.

Referral/loyalty logic is still **not** in the ledger. It is awarded on the debit (purchase) path
inside `chargeUser()`, not on wallet funding, so no provider credit loses it.

**Legacy duplicate retired.** `web/api/flutterwave_webhook.php` was a second Flutterwave credit
path: it compared the verif-hash only when the merchant had configured one, never confirmed the
transaction with Flutterwave's API, had no `sas_webhook_events` claim (a plain SELECT on
`sas_transactions`), and credited through `chargeOtherUser()` with its own `UPDATE`. A merchant
who configured that URL from `web/APIDocs.php` while the admin gateway screen pointed at
`users-flutterwave.php` therefore had two credit paths for one provider.

It is now a **URL alias**: the file resolves the vendor (host, then the checkout row) and
delegates to `bc_flw_handle_webhook()`, so the delivery goes through the same
signature → claim → provider verify → reference match → ledger pipeline as the canonical
endpoint. Nothing is credited in the file itself.

The one behaviour carried across is the fee. That endpoint always deducted the gateway's
**FEE (%)** before crediting, while `users-flutterwave.php` credits net of Flutterwave's app fee
only. `bc_flw_handle_webhook()` takes an optional `$merchant_fee_percent` so the legacy URL keeps
crediting exactly what it always credited; the canonical path passes `null`. **This divergence is
deliberate and still open** — the admin screen labels the value "FEE (%)" and the other provider
webhooks (`users-monnify.php`, `users-paystack.php`, `users-beewave.php`) and
`verify-funding.php` all apply it, so the canonical Flutterwave path is the outlier. Unifying the
fee is a product decision, not a security one, and is not made silently here.

`web/APIDocs.php` and `web/api/MOBILE_APP_SPEC.md` now publish `/users-flutterwave.php`, matching
every other provider and the admin webhook map.

### Per-provider asset gating

`bc_fintech_provider_asset_enabled()` — the catalogue lists what a provider *could* support and
is not consent. **RLUSD is never in a catalogue**: it is enabled only by an explicit
merchant-profile entry (`sas_settings` `provider_assets_<provider>`, a JSON list, written by
`bc_fintech_provider_profile_assets_save()`). A profile entry for an asset the provider does not
list does not enable it. Merchant profiles are per tenant, so one tenant's opt-in never leaks to
another.

### Test coverage added

`tests/fintech_gate_test.php` grew from 576 to **815 checks** across seven new sections:
provider registry/configuration and refusal reasons, the HTTP seam (asserting an unconfigured
adapter makes **no** call), per-provider asset gating including RLUSD, the atomic ledger
(NGN and stablecoin credits/debits, overdraft rollback, negative/NaN/INF rejection, inactive
account, history mirror), webhook behaviour — idempotency (same event twice → one credit),
signature rejection (bad hash → no claim, no ledger row, no balance change, no provider call),
claim release on an unconfirmed transaction, reference matching, unknown-reference refusal, and
a fully configured Klasha delivery — then the receipt notification (both recipients, `gross @ net`,
a throwing mailer leaving the credit and claim intact, an unresolved template as a no-op) and
the legacy-URL re-route (the historical FEE (%) still applied, the delivery still claimed, and
structural assertions that the legacy file credits nothing itself and that the docs point at the
canonical URL).

`tests/provider_smoke.php` is a standalone CLI that reports `not configured` per provider and
does nothing else — no HTTP, no credential read — until keys and the specification are supplied.
Run it with `php VTU/tests/provider_smoke.php [edition] [--vendor=N]`.

### Before any of this can go live

1. Supply the signed-off Klasha and Flutterwave v3 specifications (base URLs, endpoint paths,
   field maps, signature schemes, settlement assets) and put them in
   `bc_fintech_provider_registry()`.
2. Confirm the Klasha payload crypto scheme from the provider, then implement the seam.
3. Obtain sandbox entitlements and confirm which assets each merchant profile may settle.
4. Save credentials in `bc-admin/PaymentGateway.php`, then set `provider_ready = 1` for the
   specific tenant/service.
5. Replay webhooks in staging, including a duplicated delivery and a forged signature.
6. ~~Retire or re-route `web/api/flutterwave_webhook.php`.~~ **Done** — it is now a URL alias
   for the shared pipeline (see "Legacy duplicate retired" above). Keep the retirement of Plisio
   unchanged until the new adapters have run in sandbox.

## Existing implementation facts that affect rollout

1. Both editions primarily use `sas_users.account_level` for legacy user tiers and the three pricing tables. Existing account-level and prices must remain intact during migration and upgrades. `sas_user_upgrade_price.account_type` is a different field: it stores legacy level identifiers, not the new account tier.
2. Both editions currently use `sas_service_control` keyed by actual `vendor_id`. The standalone edition also calls `resolveVendorID()` and uses the site owner's vendor ID; forcing `vendor_id = 0` without adapting those paths would lose existing controls. Migrate or map deliberately after inspecting a standalone staging database.
3. The SAAS `isServiceEnabled()` currently lets a vendor-local setting override the global default. New marketplace modules need a distinct global **ceiling** and entitlement check; do not silently change current VTU service availability.
4. Existing first-party app endpoints such as `web/api/profile.php` authenticate using `sas_users.api_key`, which is also a merchant/developer credential. The same is true of purchase endpoints such as `web/api/airtime.php`, while `web/APIDocs.php` handles developer key regeneration. An `X-App-Source` header is client-controlled and is not authentication. Simply blocking all personal API-key requests or trusting that header would break the mobile apps or allow the restriction to be bypassed. Separate revocable, expiring first-party app sessions/tokens from developer credentials, migrate apps safely, then enforce developer permissions server-side across every applicable route.
5. Public pages and admin/API bootstraps run different migrations. The previous OTP update showed why changing a global schema marker can re-run large unrelated migrations. Use additive, versioned, idempotent, independently tested migrations and back up/restore-test staging databases. Do not overwrite balances, API keys, legacy levels, or tenant IDs.

## Required account access and redirect rollout

- Add a tenant-scoped `android_app_url` site setting with a bc-admin control in **both** editions, validated with `bc_fintech_android_app_url_valid()`. Empty means personal web login remains available; a valid configured URL routes the personal user to the Android app/download screen. Business users remain on the web. Enforce the routing only after all required authentication, including login OTP, and never let the redirect run before the session is established (otherwise it becomes an unauthenticated open redirect). Do not issue mobile tokens to business accounts: respond HTTP 403 with `Business accounts are accessible via web only`. Recheck account type when issuing and refreshing revocable app tokens, including after an upgrade to Business.
- Existing `sas_site_details.apk_download_url` and `app_redirect_mode` already power a separate anonymous landing-page prompt/force feature. Do not silently interpret an existing APK URL as consent to the new mandatory post-login policy. Use the explicitly configured new setting or a deliberate, reviewed migration, and reconcile or disable anonymous forced redirects where they could prevent Business users from reaching web login. Preserve the Business-only web path and prevent redirect loops.
- Apply the personal-only restriction to both Android and iOS for DG7, JIKABIZ and MZEEVTU. The requested web redirect is specifically the configured Android link, including when a user uses an iOS browser.
- Route enforcement must use authenticated server-side account data, not client-supplied account type or the spoofable app-source header. Prevent bypass through existing web routes, refresh tokens and alternate login flows. Explicitly define logout, recovery and upgrade paths so mandatory redirects do not trap users. Do not deploy these gates until account migration and first-party token separation are ready.

## Delivery sequence

1. **Account and authorization foundation:** inventory all registration, login, developer, merchant and app routes in both editions; design separate first-party authentication; additive account-type/KYB schema and controlled backfill; per-account service matrix and SAAS marketplace entitlements; Business upgrade and approval with a server-validated payment ledger; enforce access consistently across web and mobile. Migrate and test existing users, agent pricing and API-earner keys before enabling.
2. **Design and experience:** create an original fintech design inspired by the requested references, not copied assets or representations of unimplemented services. Apply responsive landing, customer dashboard, support flows and agreed mobile variants while retaining working purchase flows and accessibility.
3. **Provider adapters:** Klasha and Flutterwave replace the retired Plisio for stablecoin collection, payout and FX; confirm the contracted product, environment-specific official API specification, merchant entitlements, settlement regions/assets, webhook signatures and idempotency for each. Start in sandbox; do not assume that a listed endpoint or asset is available. Keep credentials in deployment secret storage, never source code or chat. Reconcile idempotently, use exact decimal/integer money units and auditable ledger entries, and never credit on an unverified webhook or request alone.
4. **Tuition, eSIM, referrals and support:** only offer supported institutions/products after a verified provider catalog exists. The referral reward must be based on final, eligible, non-reversed transfers, with unique awards and fraud controls. Human chat needs tenant-scoped authorization and auditable escalation. Treat KYB documents and eSIM details as sensitive.
5. **Staging and release:** separately stage both editions, replay webhooks, test refund/reversal and multi-tenant isolation, migrate a representative snapshot and verify backups. Complete security and compliance review, mobile signing and release, then explicitly approve production rollout. Never label sandbox integrations as live.

## Current prerequisites and decisions

- **Confirmed scope:** redesign all DG7, JIKABIZ and MZEEVTU Android and iOS apps.
- **Confirmed market target:** all blueprint markets and corridors, subject to provider entitlement, settlement support, and compliance approval in each jurisdiction.
- Supply an accessible, isolated staging setup and sandbox documentation/entitlements through the normal secure deployment process. Do not paste secrets in chat. Live rollout requires the providers' confirmed product access, compliance review, signing configuration and explicit release approval.
- Confirm operating jurisdictions, allowed assets and custody/ledger ownership before designing or launching cross-border, stablecoin and virtual-account features. A blueprint endpoint list is not evidence of provider support or regulatory approval.
