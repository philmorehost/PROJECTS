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

Layout follows the reference fintech homepage (platnova.com), redrawn with original assets: sticky
navbar → two-line display hero (second line outlined) with proof row and a CSS phone mockup →
scrolling category band → alternating feature split rows (send money, feature grid, bills, cards) →
service grid with the Personal/Business switch → dark analytics band → accounts, savings, tuition
rows → multi-column footer. Nothing is copied: copy, icons, mockups and the colour system are drawn
in the file; `platnova.com` was used for layout and visual language only.

Conventions it obeys:

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

**The feature rows are descriptive, not a promise of live rails.** Send-money corridors, multi-currency
accounts, savings and tuition describe the intended product. Until the Phase 3 adapters exist the copy
must not imply those rails are funded. Every section that names a service carries its card in the
gated service grid, so the copy and the gate stay in one file.

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

### 6. Landing rebuilt onto the reference homepage layout

Template 6 was restructured to follow the reference fintech homepage, keeping the original copy and
assets. Section order is now the reference order: navbar → display hero → scrolling band → send money
→ feature grid → bills → gated services → cards → analytics → accounts → savings → tuition → footer.

What the rebuild changed and what it deliberately did not:

- **Original assets only.** The phone mockup, card mockups, analytics panel and every icon are drawn
  in the file. No `/_next/image` URL, no reference logo, no reference copy.
- **Gating unchanged.** The service grid is still fed by `bc_fintech_marketing_partition()`, and the
  panel ids are now `pc-services-personal` / `pc-services-business`; the switch only toggles
  `hidden`. Tests assert both panels render server-side and that Personal is visible without JS.
- **Accessibility.** Mockups are `aria-hidden`; the band is decorative; the tabs implement the
  tablist pattern; `prefers-reduced-motion` disables the marquee and transitions; every in-page
  anchor resolves to an `id` in the same file (asserted by the suite and by a CI grep).
- **Brand colour** is still validated before use. The hero "Download app" link honours
  `bc_fintech_tenant_android_app_url()` and falls back to the register page.

The suite grew from **835 to 857 checks**; the CI workflow gained matching landing greps and the
anchor check.

### 7. Signed-out journey moved onto the Plate shell

The unauthenticated and account-state pages still loaded the heavyweight `assets-2` template with a
photographic background, so a visitor who had just seen the new landing landed on a different
product. They now share one shell, `func/bc-plate-auth.php`, carrying the same design tokens as
template 6 (Inter, `--brand` / `--ink` / `--muted` / `--radius`, 16px radius, soft brand wash
instead of the photo).

Helpers: `bc_plate_auth_head($title)`, `bc_plate_auth_top($opts)`, `bc_plate_auth_end($opts)`, plus
`bc_plate_auth_icon()` and `bc_plate_auth_colour()`. `$opts` covers the card modifier, icon and
icon tone, title, subtitle, alerts, footer links and footer note.

Migrated pages, in both editions: **Login, Register, PasswordRecovery, VerifyOTP, SecurityPIN,
LockoutResolution, Inactive, Suspended, Error.**

- **Every PHP logic line is preserved.** Only the `<head>` and the wrapper markup were replaced;
  forms keep their field names, handlers and scripts. `Login.php` keeps the Google sign-in block,
  the biometric button, the SweetAlert session-message block and the network-status widget;
  `PasswordRecovery.php` keeps its two-step code flow; `LockoutResolution.php` keeps the
  per-table login redirect.
- **The password show/hide toggle** no longer depends on Bootstrap icon classes: it swaps an
  `aria-label` and the button text, so it works with the shell's inline SVG.
- **Error.php is now dependency-free.** It requires only the shell, so a broken URL cannot be
  blocked by a database problem. Previously it was an unstyled standalone page.
- The shell is **presentation-only** — no money movement, no gateway credential reads, no
  entitlement decisions. The suite asserts this and CI greps for it.
- `Login`, `Register` and `SecurityPIN` legitimately differ between editions (the standalone
  edition has no login-OTP, CSRF or current-PIN hardening); both variants were migrated and the
  differences preserved. The other six pages are byte-identical and parity-checked.

The suite grew from **857 to 923 checks**; CI gained the shell syntax check, the parity list and
the presentation-only greps.

### 8. Root `.htaccess` parity restored

`VTU/tests/htaccess_context_test.py` was failing with **"SAAS and NON-SAAS root .htaccess files
have diverged"**. The SAAS root file carried an extra rule that the standalone edition lacked:

```apache
RewriteRule (?:^|/)(?:login|forgot|app-info|airtime|data|card|bank|betting|cable|electric|education|register|email-msg|merchant|ufitpay|upayvessel-webhook|sender_id_debug)\.(?:txt|json|log)$ - [F,NC,L]
```

Those files are the debug artefacts that historically held credentials, OTPs, API keys and PII;
the writers are gone, and the rule makes a stale copy on a not-yet-redeployed server unfetchable
too. NON-SAAS now carries the identical rule, so both editions' root files are byte-identical
(`md5 23a1b582…`) and the test reports **224 checks across 16 `.htaccess` files, PASS**.

Nothing was removed: the directory-listing block, the deny on config/log/backup files, the
security headers, the upload-PHP execution block and the query-string guards are all intact. The
test also still rejects server/vhost-only directives, which is what caused the earlier HTTP 500.

CI now runs the context test as its own step and `cmp`s the two root files, and both `.htaccess`
paths plus the test script were added to the workflow triggers, so the divergence cannot return
silently.

### 9. Fresh installations returned 403 — installer guard removed

After the parity fix, a **new installation of either edition returned "Forbidden"**. The cause was a
guard in both root `.htaccess` files:

```apache
RewriteCond %{REQUEST_URI} (?:^|/)install/
RewriteCond %{DOCUMENT_ROOT}/install/install.php !-f
RewriteRule ^ - [F,L]
```

Two independent defects, either of which makes the guard fire on every request:

1. **`install/install.php` exists in neither edition.** SAAS ships `install/index.php`; NON-SAAS
   ships `install.php` at the root. So `!-f` was always true.
2. **`%{DOCUMENT_ROOT}` is the Apache document root, not the application directory.** Even with a
   correct file name, the check would fail whenever the app is deployed in a subdirectory
   (`public_html/vtu/` and similar), which is the common case.

With the condition always true, `RewriteRule ^ - [F,L]` returned **403 for every `/install/`
request**, so the wizard could never be opened. The comment above it ("re-enable it temporarily
only when a fresh installation is deliberately being performed") is the trap: there was no way to
re-enable it short of editing the file on the server.

**The guard is removed.** Re-installation over a live install is still prevented — by the
installers themselves, which are app-relative and work from any subdirectory:

| Edition | Self-guard |
| --- | --- |
| SAAS `install/index.php` | redirects to `/` when `func/db-json.php` exists **and** a `sas_super_admin` row exists |
| NON-SAAS `install.php` | redirects to `/bc-admin/Dashboard.php` when `installed.lock` exists |

Nothing else was loosened: the directory-listing block, the deny on config/log/backup files, the
security headers, the upload-PHP execution block, the private-directory rules and the
query-string guards are all unchanged, and the two root files remain byte-identical.

`htaccess_context_test.py` gained three assertions so this cannot return: no active line may key a
decision off `%{DOCUMENT_ROOT}`, the nonexistent `install/install.php` may not be referenced, and
no `RewriteCond` may gate the installer path. Verified by restoring the old file — the test fails
with exactly those messages — then restoring the fix (**PASS, 224 checks**).

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

## 10. Site-wide fintech UI — installers

The installation wizards are the first screen a customer sees, so both were rebuilt onto a
shared shell in `func/bc-installer-shell.php` (byte-identical across editions, parity-enforced
in CI). It carries the same design tokens as the landing page and the auth shell
(`--brand: #0f766e`, `--ink`, `--muted`, `--line`, `--surface`, `--wash`, 16px radius), a
soft brand wash instead of the old flat grey, and a caption stepper.

- `bc_installer_head($title)` — meta, fonts, icons, the shared stylesheet.
- `bc_installer_stepper($step)` — 4 captioned dots; `data-step` / `data-after` hooks drive
  the client stepper.
- `bc_installer_foot($note, $scripts)` — footer, shared `goStep`/`toggleVis` script, then the
  wizard's own inline JS.
- `install/index.php` (SAAS) and `install.php` (standalone) keep all of their own PHP logic
  and field names verbatim; only the markup moved. The standalone wizard's JS was moved out
  of the markup into a `__INSTALLER_SCRIPTS__` heredoc so the page can hand it to the shell
  without printing it twice.
- The SAAS `install/install.css` was removed — it is no longer referenced by anything.
- CI now asserts the shell is byte-identical and that both installers use `bc_installer_head`
  / `bc_installer_foot` and load neither `assets-2`, the Bootstrap CDN nor `install.css`.

## 11. Bank details must always save, and always say so

`update-bank-details` in `bc-admin/AccountSettings.php` (both editions) and
`bc-spadmin/AccountSettings.php` (SAAS) failed silently for a legitimate ₦0 charge:

- PHP's `empty("0")` is `true`, so `!empty($amount_charged)` rejected a `0` charge as "empty".
- The amount branch was inverted (`if ($amount_charged > 0) { /* error */ }`), so it fell
  through having written nothing **and** set no message. The admin saw an unsaved form with
  no explanation.

The handler now validates flatly (`$amount_ok = $amount_charged !== '' && is_numeric(...) &&
(float)$amount_charged >= 0`), writes `$bank_save_message`, and **always** sets a message —
there is no silent fall-through. `bc-spadmin` also gained the missing `exit` after its
redirect. The five bank fields in all three forms are escaped with
`htmlspecialchars(..., ENT_QUOTES)`.

Regression coverage lives in `VTU/tests/fintech_gate_test.php`: the real handler block is
evaluated against a fake `sas_admin_payments` / `sas_super_admin_payments`, asserting a `0`
charge saves, an existing row updates instead of duplicating, and invalid input always yields
a message.

## 12. Installer head must be called from inside the PHP tags

Both wizards printed their own opening lines as literal text. `bc_installer_head()` was written in
**inline-HTML position** in `DGV7.0-SAAS/install/index.php` and `DGV7.0-NON-SAAS/install.php`, so
PHP never executed it: the page had no `<head>`, no `<title>`, no stylesheet, and no JS. It
looked like a "loading issue" with a missing stylesheet — it was a call outside the tags.

The call is now `?>` + `<?php bc_installer_head("…"); ?>`, and the gate test renders each
installer from source with `token_get_all()` (inline HTML becomes `echo`, the tags are dropped) so
a call left outside the tags reproduces the literal text instead of being silently evaluated. The
previous helper eval'd from the first `bc_installer_head(` and therefore could not see it.

## 13. Add-vendor must not lose the "use existing domain" value

`bc-spadmin/VendorReg.php` read the existing domain **only** from the hidden `app_base_url`
field, which the page's script fills by copying `existing_url` on `input`. Any suppressed script,
autofill, or paste that never fired `oninput` left that field empty, so the vendor was saved with
a domain the admin had in fact typed. The handler now reads `existing_url` directly and falls back
to it, and normalises it with the shipped host cleaner (so `www.` and a scheme are stripped).

The second fault was a silent one: when the `sas_pending_vendors` INSERT failed, the handler
discarded `mysqli_error()` and set a bare *"Could not save your registration. Please try
again."* — no cause, nothing in the log. It now logs the real error and reports a duplicate
email/domain distinctly, so the admin can delete the pending row and retry.

Schema: the pending-vendor migration added eight columns under a single "app_base_url is missing"
condition. A table that already had `app_base_url` but was missing a later column never received
it, and the add-vendor INSERT — which names every column — then failed with an unknown-column
error while the duplicate pre-checks still passed. Each column is now added on its own, with
`AFTER order_playstore` applied only once `order_playstore` exists. `BC_TABLES_VERSION` moved to
`2026.11.19-1` in both editions so installations already carrying the old marker re-run it.

Nothing here depends on cPanel API details: cPanel is best-effort at approval time
(`bc-spadmin/VendorRegistrations.php`), and WHMP is required only on the **register** path. A
settings state of "bank settings saved, TLD prices saved, cPanel API details not saved" must not
block add-vendor.

Coverage: `VTU/tests/fintech_gate_test.php` now evaluates the real `create-profile` handler
against a faithful fake `sas_pending_vendors` (unique keys enforced, `mysqli_error` populated),
asserting the existing domain is read and normalised, a duplicate is named, nothing is written on
a collision, and the failure branch is wired to the real error. 1025 checks.

## 14. Back-office sign-in pages join the signed-out shell

The last legacy-template surface was the admin sign-in journey: `bc-admin/Login.php`,
`bc-admin/PasswordRecovery.php`, `bc-admin/VerifyOTP.php`, `bc-admin/Error.php` (both editions)
and the `bc-spadmin/` equivalents still loaded `assets-2` and a photographic background while
every other signed-out page already used `func/bc-plate-auth.php`.

- All 11 pages now render through `bc_plate_auth_head/top/end`. The tenant identity is bridged
  with one line — `$GLOBALS['get_all_site_details'] = $get_all_super_admin_site_details` — so the
  shell's brand bar, title and brand colour resolve from the same site row the old template used.
- Presentation only: every form field and button name, the two-step login OTP branch, the
  blocked-account SweetAlert flow (including the unblock-request fetch), the OTP countdown and
  the password visibility toggle survive verbatim; only the chrome and control classes changed.
- `Error.php` is a pure shell render; `VerifyOTP.php` and `PasswordRecovery.php` stay
  byte-identical across editions.

Coverage: `VTU/tests/plate_admin_test.php` asserts each page uses the auth shell, loads no
`assets-2`, keeps its credential field, and that the two shared pages stay byte-identical
(688 checks). CI already lints every `bc-admin`/`bc-spadmin` page.

## 15. Legacy chrome partials deleted — after restoring what they injected

The retired partials (`func/bc-header.php`, `bc-footer.php`, `bc-admin-header.php`,
`bc-admin-footer.php`, both `*-header-link.php`, `bc-spadmin-header.php`,
`bc-spadmin-footer.php` — 13 files) are deleted. Deleting them safely first required
restoring the page-level features they injected on every page, which the shell
migrations had silently dropped:

- **Console** (`bc_app_widgets()` in `bc-plate-app.php`, called from `bc_app_foot()`):
  the AI assistant (original gates: signed-in + `ai_suite`, skipped on the VTU
  purchase pages), the Refer-and-Earn modal (trigger now in the topbar), and the
  support chat widget (self-gating, as before).
- **Back office** (`bc_adm_widgets()` in `bc-plate-admin.php`): the admin AI
  assistant (`ai_status` = 1), the forced Security PIN setup modal (same
  `quick_set_pin` AJAX contract with `AccountSettings.php`; shown via the shell's
  `.show` modal class instead of the retired Bootstrap bundle), and the
  temporary-vendor-host banner in the topbar.

`VTU/tests/plate_migration_test.php` now asserts the partials are absent in both
editions and that the shells carry the widgets (896 checks); the gate test's nav
assertions were retargeted from the deleted headers to the shells (still 1049).
One leftover: the SAAS-root `Dashboard.php` still references the old header through
an already-broken `../func/` path; it has been unreachable since before this work
and is untouched.

## 16. Nav regrouping, Card.php discarded, root .htaccess disabled, dashboards redesigned

Four changes, both editions throughout:

- **Navigation (`func/bc-plate-app.php`).** Gift Card and Crypto moved from Financial
  Services into **Wallet & Transfers**; Share Fund History moved from Wallet & Transfers
  into the history group; the group is renamed **History & Records → History & Tools**
  (`history-records` → `history-tools`). The pinned group lists in
  `plate_app_test.php`, `fintech_gate_test.php` and the CI workflow moved with it, and
  `plate_app_test.php` now asserts the placement of each moved item.
- **`/web/Card.php` discarded.** The page bought recharge cards through an external API
  that no longer works, and the VTU console (Print Hub / `DataBundleCard.php`) already
  generates recharge cards itself. Deleted in both editions, together with its sidebar
  item, the Pay Bills `match` entry, the media-KYC page-list entry in `bc-config.php`,
  and its captured bodies in `plate_bodies.php`. The admin floating-icon shortcut that
  pointed at it now opens `DataBundleCard.php`. `web/api/card.php` and `web/func/card.php`
  stay: they are the API surface, not the discarded page.
- **Root `.htaccess` disabled.** Renamed to `.htaccess.disabled` in both editions (kept
  byte-identical; rename back to re-enable). Its query-string WAF rules returned 403 for
  legitimate application requests. The per-directory guards for private upload, log and
  backup directories remain active, and `htaccess_context_test.py` now asserts the root
  file stays disabled while still validating the disabled copy's contents. CI asserts the
  same. Also deleted: the stray legacy `DGV7.0-SAAS/Dashboard.php` at the edition root —
  a dead duplicate of `web/Dashboard.php` whose `../func/` includes resolved outside the
  edition, so it could only ever 500.
- **Dashboards redesigned (Platnova).** `web/Dashboard.php` (both editions, edition gates
  preserved), `bc-admin/Dashboard.php` (both editions) and `bc-spadmin/Dashboard.php`
  (SAAS) were rebuilt on the shells' design tokens (`--brand`, `--ink`, `--muted`,
  `--line`, `--radius`, `--shadow`): brand-gradient balance/overview hero, compact
  service tiles that now include the new services (Crypto Hub, Virtual Dollar Card, Gift
  Cards, Bulk SMS, AI Suite, card printing via the VTU console), and white line-bordered
  cards throughout. Every query, POST handler, cache key, DOM id and JS hook is
  unchanged — only presentation moved. `web/Dashboard.php` remains exempt from the
  body-verbatim migration test, and its Phase 2 accounts partial keeps the tenant brand
  in its upgrade banner.

Test counts after this change: gate **1051**, policy **70**, plate_app **91**,
plate_admin **692**, plate_migration **880** (down from 896 — the two deleted Card.php
pages no longer contribute per-page checks), htaccess **158**.

## 17. Root .htaccess re-enabled; VirtualCard.php redesigned (stylesheet restored)

- **Root `.htaccess` is active again** in both editions (byte-identical, 224 context
  checks). The §16 disable was conditional on files actually being blocked from update;
  no such blocking exists — every file in this programme has updated cleanly — so the
  condition is not met and the file is restored exactly as it was, with its tests and CI
  parity checks back in force. The per-directory guards never went away in any case.
- **`web/VirtualCard.php` redesigned (both editions).** The page "had no stylesheet"
  for two compounding reasons: its CSS (`assets/css/fintech-cards.css`) was never
  linked after the shell migration, and a residual pagetitle `</div>` left in the body
  closed `.pk-app__wrap` early, so the shell's compat layer never applied to the page
  either. The redesign links the stylesheet, removes the stray close (the New Card
  button now sits in a proper page-head row), and adds a Platnova page style on the
  shell tokens: brand-gradient card face at real card proportions, gold chip, status
  dots, glass reveal button, styled empty state and quick actions. All queries, modal
  ids, form fields and the `virtual-card-ajax.php` contracts are unchanged. The
  body-verbatim pin for this page is retired in `plate_migration_test.php` (same
  treatment as `Dashboard.php`), replaced by a guard asserting the stylesheet link
  stays in place.

Test counts after this change: gate **1051**, policy **70**, plate_app **91**,
plate_admin **692**, plate_migration **876**, htaccess **224**.

## 18. Back-office bug round: invisible buttons, dead tabs, null warnings, NON-SAAS lockout

- **NON-SAAS "Website Not Found" on every page.** `bc-admin/Error.php` is only reached
  when `func/bc-admin-config.php` finds no database connection — and the repository
  shipped a committed `func/db-json.php` written by one installation's wizard,
  carrying that account's database credentials in plaintext. On any other account
  the connect fails, `$connection_server` stays null and every page redirects to
  the error page. Fixed properly: the committed credential file (and the committed
  NON-SAAS cache files) are deleted, `.gitignore` now excludes `func/db-json.php`
  in both editions (plus `func/levelup.php`, `installed.lock`, `func/cache/` for
  NON-SAAS), `func/db-dtl.php` tolerates a missing credential file, NON-SAAS
  `func/bc-connect.php` routes an uninstalled site to `/install.php` (SAAS parity),
  the NON-SAAS installer reopens when the lock exists but credentials are missing
  (no more Dashboard↔installer loop), and the no-connection redirect carries
  `?reason=db` so `bc-admin/Error.php` says "Service Temporarily Unavailable"
  with repair guidance instead of "Website Not Found". **Action for the site
  owner:** the credentials that were committed remain in git history — rotate
  that database password. After deploying this change, if the installer appears,
  re-enter the same database details; existing data and the vendor record are
  preserved (the wizard skips seeding when vendor 1 already exists).
- **Invisible button/badge text (SAAS back office).** Pages written against
  Bootstrap 5 referenced `--primary-color`, `--bs-primary` and `--bs-primary-rgb`,
  which no shell defined — themed elements lost their background and kept white
  text. `bc_app_css()` now derives `--bs-primary-rgb` from the tenant brand and
  defines `--primary-color`, `--bs-primary`, `--danger`, `--info` and per-colour
  RGB triplets as legacy aliases of the Platnova tokens. Separately, the compat
  layer's `.bg-opacity-*` were white tints (meant for dark heroes); they now
  follow Bootstrap semantics via `--bs-bg-opacity`, so the common
  `bg-<colour> bg-opacity-10 text-<colour>` badge renders tinted again.
  `.bg-info`/`.text-info`, the missing `btn-outline-*` variants and border-opacity
  handling were added under the same contract.
- **`bc-spadmin/VendorReg.php` "missing stylesheet":** in its public
  (no-session) branch the page emitted a bare `<body>` outside
  `.pk-app__wrap`, so the whole compat layer missed it. It now opens/closes the
  shell wrap in that branch (shell mode unchanged).
- **`bc-admin/AccountSettings.php` dead tabs:** the shells never loaded the
  Bootstrap bundle and their compat JS wired only modals/dismiss. Both shells
  now wire `[data-bs-toggle="pill"|"tab"]` (active link + pane switching), the
  CSS hides inactive `.tab-pane`s, and a minimal `bootstrap.Modal`
  `getOrCreateInstance` shim serves pages that open dialogs through the JS API.
- **Null-offset warnings (~30 pages):** nearly every cited line prints
  `$get_all_super_admin_site_details["site_title"]` into `bc_adm_head()`, and
  both SAAS configs left that global `null` until a row was seeded. Both SAAS
  configs (and the NON-SAAS missing-vendor path) now always default it to
  `array('site_title' => 'Admin Panel', ...)`. `bc-admin/SelfSubmitPayment.php`
  likewise defaults its two optional settings rows, ending the warnings and the
  "Maximum Amount: N" output on an unconfigured install.
- **`bc-spadmin/ShareFund.php`:** removed a stray literal `l>` fragment left in
  the page tail during migration (the only one in either back office; its divs
  were already balanced).

New regression suite `VTU/tests/backoffice_regression_test.php` (36 checks)
renders a real spadmin head and pins the aliases, badge tint contract, tab
wiring, config defaults and the NON-SAAS credential/install chain; it runs in
CI alongside the existing suites.

Test counts after this change: gate **1051**, policy **70**, plate_app **91**,
plate_admin **692**, plate_migration **876**, backoffice_regression **36**,
htaccess **224**.

## 19. Matrix hubs, menu restructure, and the modern fintech redesign pass

- **Sidebar → Hub pages (all three shells).** The sidebars now list main menu
  items only. Every item with sub-pages links to a new Hub page
  (`web/Hub.php`, `bc-admin/Hub.php`, `bc-spadmin/Hub.php`) that renders its
  sub-pages as an **embossed dark matrix** — subtle dark-slate cards with an
  inset top highlight and deep outer shadow (`.pk-matrix__card`), a gradient
  hero (`.pk-hub__hero`), brand icon tiles and hover lift. `bc_app_css()` gained
  a "fintech design layer v2" (deeper card elevation, gradient primary buttons,
  pill badges) so **every** shell page — console, admin and spadmin — picks up
  the modern look without body changes.
- **Console menu changes:** "Financial Services" → **Verify & Print**;
  "Business & API" → **Tools & API**; **PrintHub stands alone** as a top-level
  item, and all PrintHub print-card links were removed from **History & Tools**
  (Number Filter moved into it instead) — the print services live only in
  `web/PrintHub.php`, itself redesigned onto the same matrix (service gates and
  NIN/BVN vendor checks preserved). **Admin menu:** the duplicate
  "Help / Guide" entry is gone (Platform Guide remains), and the admin shell
  now hides menu entries whose page does not exist in the running edition —
  which discards Fund / Self Transactions / Self Submit Payment / Self Payment
  Orders / Renew Subscription / Subscription History from the standalone
  (NON-SAAS) menu, where those pages do not exist, while SAAS keeps them.
- **`bc-admin/USSDChannel.php` restored in NON-SAAS** (with
  `ajax-ussd-activate.php`), ported from SAAS with null-safe vendor reads;
  the menu's missing-page gate makes it appear automatically.
- **`bc-admin/KnowledgeBase.php` accordion fixed:** the shells now style
  (`.accordion*` in the compat layer) and wire Bootstrap collapse toggles,
  including `data-bs-parent` exclusivity — the retired bundle no longer needs
  to be present for the guide to expand.
- **NON-SAAS `bc-admin/SendMail.php` now uses the SAAS email sender** — the file
  is byte-identical across editions (Quill composer, campaign cancel/progress),
  backed by porting `bc_cancel_mail_campaign()` and
  `bc_get_active_mail_campaigns()` into NON-SAAS `func/bc-mail-queue.php`.
- **Page redesign layers** (`<style data-pkx="page-redesign">`, logic and markup
  contracts untouched): bc-admin — CryptoInvoices, AIMarketing, Users,
  BatchTransactions, PaymentOrders, Withdrawals, FundTransferRequests, ShareFund,
  BlogPostEdit, StatusMessage, EmailTemplates, AppUpdateBroadcast, IDBlockingSystem,
  TransactionLimits, SenderIDRequests, CoinConversions, KYCManagement,
  ServiceControl, DataBundleCard (all `?type=` variants), GiftCard, VirtualCard
  (plus the spadmin copies of Users/PaymentOrders/ShareFund/StatusMessage/
  EmailTemplates/KYCManagement/ServiceControl); web — SendFund, CryptoHub,
  NINCard, Pricing, TransactionCalculator (inserted after the shell head, so the
  migration body contract still holds).

Test counts after this change: gate **1053**, policy **70**, plate_app **149**,
plate_admin **708**, plate_migration **882**, backoffice_regression **69**,
htaccess **224**.

## 20. License lock fixes, matrix fine-tuning, BatchTransactions redesign

- **"Invalid for this domain" on the first try, fine on reload — fixed.** The
  activation handler forced a re-check by assigning
  `$GLOBALS['bc_integrity_checked'] = false`, but `bc_verify_integrity()`
  memoised on `isset(...)`, which is true for `false` — so the stale verdict
  from earlier in the same request was returned, the handler reported the key
  invalid and `bc_clear_activation()` deleted the file it had just written.
  The memo now requires a truthy flag and the handler `unset()`s it.
- **The key is no longer re-asked after every update.** Validation success
  (remote or cache) backfills `sas_super_admin_options` (`license_key`,
  `license_domain`, `license_status`, `license_last_check`) via
  `bc_license_db_backfill()`, so when a redeploy wipes `func/bc-activation.php`
  and `func/cache/`, the database copy — the source of truth — silently
  re-validates and rewrites the file. The activation handler also changed
  order: it persists the key **only after it validates**, so a mistyped attempt
  can't overwrite the saved working key. Domains are www-normalised before
  being sent to the license server.
- **No more hard lockout of a previously-valid install.** If the license server
  freshly rejects a key whose database record says it previously validated,
  `bc_license_grace_active()` starts a 7-day grace window (stamped in
  `license_invalid_since`) instead of locking the back office; explicit
  suspensions still lock immediately.
- **`bc_store_license_option()` fatal fixed for partial updates:**
  `bc-spadmin-config.php` now loads `func/bc-levelup.php` defensively when the
  license helpers are missing, instead of assuming `bc-connect.php`'s include
  chain completed on a half-updated deployment.
- **Matrix fine-tuning:** the admin shell's `<main class="pk-app__wrap">` is a
  flex item of `.pk-app__main`, and its `margin: 0 auto` shrink-wrapped it to
  the widest card — the reason the admin/spadmin matrix rendered as a single
  column. `.pk-app__main > .pk-app__wrap` now takes full width, and
  `.pk-matrix` is explicitly **3 columns on desktop, 2 under 1080px, 1 under
  640px**. Hub cards now use an icon that fits each page (`bc_adm_item_icon()`:
  explicit key → per-page map → label keywords, with per-type print icons)
  instead of repeating the parent section's glyph.
- **`bc-admin/BatchTransactions.php` redesigned** (both editions): dark
  gradient hero with page/per-page/filter stat tiles, a dedicated search card
  with clear-filter action, the shared batch table in a styled frame, and a
  modern Prev/Page/Next pager. All queries, the requery handler, search and
  pagination logic are byte-preserved (only the SAAS escaping difference
  remains between editions).

Test counts after this change: gate **1053**, policy **70**, plate_app **149**,
plate_admin **708**, plate_migration **882**, backoffice_regression **84**,
htaccess **224**.

## 21. Update-system edition mix-up and the missing NON-SAAS updater menu entry

- **SAAS sites were applying NON-SAAS update packages.** Every update client
  (`UpdateSystem.php`, `ajax_download_update.php`, `cron_update.php` in both
  editions) asked `check-update.php` with only `license_key` + `domain` — nothing
  identified which edition was asking, so a license record mapped to the
  standalone package on the manager sent that zip to SAAS installs, and
  `execute_update.php` happily overwrote SAAS files with NON-SAAS ones. All six
  clients now send `edition` (`SAAS` / `NON-SAAS`) and `product` (`DGV7`) with
  the request. **Manager-side:** the license records should be mapped by that
  field (a SAAS license must never resolve to the standalone package).
- **Belt-and-braces guard:** `bc_assert_update_edition()` now runs in
  `execute_update.php` and `cron_update.php` (both editions) immediately after
  the manifest is read and **before** any file is deleted or copied. It refuses
  the package when the manifest declares the other edition, and also when the
  staged files carry the other edition's marker (`func/bc-integrity.php` is
  NON-SAAS-only; `func/bc-levelup.php`/`bc-spadmin-config.php` are SAAS-only),
  with a message telling the admin no files were changed.
- **NON-SAAS `bc-admin/UpdateSystem.php` unreachable:** it existed but was in
  no menu, so it never appeared in the sidebar or the System Function matrix.
  "System Update" is now a System Function child (shown in NON-SAAS via the
  edition existence gate; SAAS `bc-admin` has no such page and keeps using the
  spadmin updater, which also gained a "System Update" leaf).

Test counts after this change: gate **1053**, policy **70**, plate_app **149**,
plate_admin **708**, plate_migration **882**, backoffice_regression **99**,
htaccess **224**.

## 22. Update edition mix-up (server side), matrix label contrast, CryptoHub redesign

The §21 client fix was only half the story: the DGV7.0 clients sent `edition`,
but `LM/check-update.php` never read it — it honoured only `expected_tier` /
`project_family`, so the tier still came solely from the license record.

- **LM `check-update.php`** now normalises the client's `edition`
  (`lm_check_edition_tier()`) and feeds it into the enforced `expected_tier`,
  so a license whose tier names the other edition is rejected with a clear
  message instead of being served the wrong zip. For legacy licenses with a
  NULL `tier_id`, `license_type` (the pre-tier edition record) still outranks
  a conflicting client claim; the declared edition decides when `license_type`
  carries none. Tier ids are resolved by `tier_code` (no longer hardcoded
  1/2) and the license record is healed. Responses now also carry `edition`.
- **LM package building** (`includes/github.php`): manifests declare
  `edition` as well as `tier`; the changelog's "files changed" is filtered to
  the project's packaged folder selection (a SAAS release no longer lists
  `VTU/DGV7.0-NON-SAAS/...` paths); and `lm_github_edition_violation()`
  refuses to publish a package whose selection contains the other edition's
  folder or marker files (`func/bc-integrity.php` vs `func/bc-levelup.php`).
  Manual uploads restamp the bundled manifest to the target tier
  (`ajax_upload_update.php`, `patch_zip_manifests.php`).
- **Clients**: all six update clients also send `expected_tier` (enforced by
  the already-deployed manager), `ajax_download_update.php` refuses a token
  issued for the other tier, and the execute/cron package guard now reads the
  `tier`/`project` manifest keys LM has always written.
- **Matrix labels invisible**: `.pk-app__wrap a { color: var(--brand) }`
  (specificity 0,1,1) beat `.pk-matrix__card`'s light text (0,1,0), so hub
  card labels took the site's dark brand colour on the dark card. Card, label,
  sub-label and go-arrow colours are now pinned in the shell (both editions).
- **CryptoHub redesign**: coin art is sized (40px overlapping chips; 26px
  wallet tiles), hero actions are glass pills, wallet/invoice/activity cards
  sit on the console shell, and the **Withdraw (`withdrawModal`) and Self
  Help (`selfHelpModal`) modals the page's JS posts to are restored** — they
  were lost when the legacy chrome was retired, so Send and Self Help have
  been throwing since. Handlers, queries and the JS block are unchanged.

New test `VTU/tests/lm_edition_test.php` (30 checks; runs in CI against the
real LM tree, mirrored locally by the harness). Counts: gate **1053**, policy
**70**, plate_app **149**, plate_admin **708**, plate_migration **908**,
backoffice_regression **110**, lm_edition **30**, htaccess **224**.

## 23. Pay Bills automation restored + redesign; admin Dashboard redesign

- **Root cause of the dead Pay Bills pages:** every automation on Data,
  Airtime, Bulk Data/Airtime, Cable, Electric, Betting and Exam — the
  phone-prefix network auto-detection (`tickDataCarrier`/`tickAirtimeCarrier`),
  plan filtering by `product-category`, carrier `.selected` states, proceed
  gating, bulk number filtering and identifier-limit checks — lives in
  `jsfile/bc-custom-all.js`, which the retired `bc-footer.php` used to load.
  The console shell (`bc-plate-app.php`) never picked it up (the admin shell
  did), so the pages rendered but nothing on them worked. `bc_app_foot()` now
  loads it after SweetAlert, and the carrier-tile sizing/selected styles that
  lived in the retired `assets-2` stylesheet are in the shell CSS.
- **Redesign:** all eight pages (both editions) sit in the shell bill card
  with a dark gradient head band (icon, title, step pills), carrier tiles and
  the `pk-verify` detail panel for the verify-then-confirm flows. Every form
  id/name, inline handler, plan query, PIN block and POST action is unchanged;
  a regression check resolves every inline handler name against the library.
- **bc-admin/Dashboard.php** (identical in both editions) rebuilt in the
  fintech v2 (`ad2-*`) design: glowing dark hero with glass metrics, alert
  cards, subscription/growth/quick-links, auto-funding bank cards and the
  addon list. All queries (including the `admin_stats_v2` dashboard cache),
  the vendor sync script, `buy-addon`/`pay-bill` handlers and addon modals
  are byte-preserved; the diff is presentation-only.

Test counts after this change: gate **1053**, policy **70**, plate_app **149**,
plate_admin **708**, plate_migration **980**, backoffice_regression **139**,
lm_edition **30**, htaccess **224**.

## 24. Landing redesign, license-save fix, back-office bug round (2026-11-19)

- **Landing page (template 1, both editions)** rebuilt on the client's
  freelancer design: hero with wallet/pockets mock, feature cards, three
  ways to get paid (bank transfer / invoice / QR), pockets, personas,
  currencies, spend, free pricing, FAQ and final CTA. The brand name is
  automated — every mention renders the saved site title (vendor site
  details; on the SAAS platform domain, the super-admin site title) — and
  sections follow bc-admin Service Control state: the VTU card/chips/tiles
  (airtime/data/cable/electric/betting/exam), the card sections
  (virtual_card), bank-transfer receiving (bank_transfer), the exchange
  steps/tiles (currency_conversion) and the FAQ service list all hide when
  their service is off. Templates 2/3/6 are untouched; vendors who picked
  them keep them.
- **Live currency conversion** is a real bc-admin feature now: Service
  Control gains a `currency_conversion` service toggle (vendor + SAAS
  global) and a **Currency Conversion API** card that stores the vendor's
  ExchangeRate-API key (`fx_api_key` in `sas_settings`).
  `getLiveExchangeRate()` prefers that keyed v6 endpoint over the shared
  free one. The landing converter renders **only** when the toggle is ON
  and a key is configured, and fetches `web/api/exchange-rate.php` live.
- **License "not saving" + updater tier-mismatch 403 (SAAS) were one bug.**
  The save handler ignored `bc_write_activation()`'s result; when the
  activation file could not be written (func/ ownership after an update),
  the stale file's key — which outranks the database in
  `bc_verify_integrity()` — was stamped back over the just-saved key by
  `bc_license_db_backfill()`. The old key (assigned to NON-SAAS on the
  License Manager) kept being sent, and the session-cached
  `getSuperAdminOption()` kept showing it, so the page looked unsaved. The
  handler now checks both writes, drops the stale activation file so the
  saved database key governs, busts the session option cache, and reports
  write failures honestly. UpdateSystem's tier-mismatch error now explains
  both fixes (re-save the key, or re-pin the license tier in License
  Manager → Fix License Tiers). LM enforcement itself is unchanged.
- **bc-admin Dashboard addon dump:** the addon modals were printed after
  `bc_adm_foot()`, i.e. outside `.pk-app__wrap` where the shell's
  `.modal{display:none}` compat rule applies, so the whole catalogue
  (APK/iOS/Play Store/PrintHub copy, fees, Order Now) rendered as raw page
  text. The modals and page script now sit before `bc_adm_foot()` (inside
  the wrap); the stray trailing `</html>` is gone. Dashboard.php stays
  byte-identical across editions.
- **IdentityAPI currency labels:** the naira sign was double-encoded
  (`â‚¦`, 16×, plus 5 em-dashes) across fee labels, input prefixes and
  confirm dialogs; restored to `₦`/`—`. Four fee lookups indexed
  `mysqli_fetch_assoc()` output without checking the query — now guarded
  like the existing identity-fee lookup.
- **BatchTransactions** printed a literal closing-tag fragment under its
  hero: a doubled `?>` on its own line after the query block (both
  editions). Removed.
- **Users Block action rendered blank:** `bi-ban` only exists in Bootstrap
  Icons 1.11+, but every shell loaded 1.10.5. All icon-font loaders
  (plate-app, plate-admin, installer shell, spadmin config; both editions)
  now load 1.11.3, which also fixes every other 1.11-only glyph in use.
- **web/SendFund.php redesigned** (both editions): fintech hero with the
  wallet balance, Verify→Confirm step pills, and a search-as-you-type bank
  picker — the bank list ships with the page (`func/banks.json`), filters
  instantly (prefix matches first), supports keyboard navigation, and
  fills the same hidden `bank-code` field the form posts. It replaces the
  TomSelect dropdown whose library the shell never loaded — constructing
  it threw, which also killed the account-name auto-resolution wired after
  it; that flow (resolve_account, caching, debounce) is restored verbatim.
  All POST actions (`verify-bank`, `process-transfer`, `initiate-transfer`,
  `reset-bank`, `send_otp`, `delete_beneficiary`), field names and the
  beneficiary limit flow are unchanged. Two latent bugs fixed on the way:
  the result modal read an undefined `$res` (the flashed `transfer_result`
  was never captured or cleared — now captured once into `$sf_result`), and
  `fillBeneficiary` scrolled to a `.card-header` the redesign replaces
  (now `.sf-hero`, null-guarded).

Test counts after this change: gate **1053**, policy **70**, plate_app **149**,
plate_admin **708**, plate_migration **1020**, backoffice_regression **184**,
lm_edition **30**, htaccess **224**.

## §25 — Dashboard redesign, provider-dynamic services, Flutterwave-only cards, free support & client landing (2026-11-20)

Implements the MZEE SITE UPGRADE client brief + Blueprint BP-DGV7-2026-01 items
that remained after §24, in both editions.

- **Dashboard (`web/Dashboard.php`)** redesigned modern-fintech style:
  removed the "Meet Your AI Assistant!" banner, the "Autonomous AI" eligibility
  card, the Referral card (now the dedicated `web/Referral.php`) and the
  Upgrade Account card (Business upgrade lives in the menu). Wallet-card
  actions (Fund/Submit/Share/Crypto) now render explicit icon + text labels in
  white — the labels previously inherited the brand link colour on the brand
  hero and were unreadable. Total Deposit / Total Spent became gradient stat
  cards. "All Services" is now text links matching the sidebar menu exactly
  (`Hub.php?g=pay-bills|wallet-transfers|verify-print`, `PrintHub.php`,
  `?g=tools-api`, `?g=history-tools`, `?g=settings-help`). New feature row:
  Send Money Abroad, Pay Tuition Worldwide, Travel eSIM; the Crypto Hub /
  Virtual Dollar Card / Gift Cards cards keep the requested copy.
- **Shell footer bug:** `.pk-app__foot` printed after the offset content
  column and slid under the fixed 268px sidebar, leaving only the tail of the
  "Platform Guide" link visible at the sidebar's bottom (the stray menu text in
  the client screenshot). The footer now offsets itself by `--side-w`
  (reset on mobile). Also: nav gains Refer & Earn + the four new services,
  "AI Assistant Terminal/AI Suite" become **Support**, the topbar Refer button
  opens the Referral page, and the retired VTU-purchase AI widget
  (`ai-assistant.js`) is no longer injected.
- **Two tiers everywhere:** `accountLevel()` now displays Personal /
  Personal (Agent legacy) / Business (discount tables keyed by level are
  untouched, so legacy Agent discounts are retained inside Personal).
  `web/Pricing.php` (SAAS) shows two columns — Personal (the viewer's own
  legacy table: Agent rates for ex-Agent users) and Business (API table);
  NON-SAAS titles became Personal (T1) / Personal (T2) / Business (T3).
  API docs say "Business account", never "API Vendor".
- **Provider layer (`func/bc-fintech-services.php`):** catalogue of what
  Klasha / Flutterwave / Platnova / legacy-Plisio offer (per the client PDF),
  the vendor's active provider (`fintech_active_provider`, switched in
  bc-admin → Service Control), and generic provider service requests
  (`sas_service_requests`). Checked per the brief: **neither Klasha nor
  Flutterwave offers a BTC swap**, so BTC pairs exist only on the legacy rail.
  `web/CryptoHub.php` follows the switch: in provider mode only that
  provider's assets render and every trade action fails closed through the
  registry seam; the Plisio rail is untouched while it stays active (default).
  New pages: `IntlTransfer.php` (with the embossed 3-step China payout visual),
  `Tuition.php` (Platnova, 160+ universities, student-ID validation),
  `ESIM.php` (1GLOBAL, LemFi-style QR/SM-DP+ one-tap flow), `POSRequest.php`
  (Flutterwave POS device requests) — all record requests and render exactly
  the active provider's services.
- **Referral (`web/Referral.php`, under Tools & API):** LemFi-style $10/$10
  program with visual 3-step diagram and progress bars. Volume counts ONLY
  completed Crypto + International Transfer transactions toward the referee's
  $100 threshold (`bc_referral_record_volume()`, hooked into completed crypto
  swaps; idempotent ledger payout of both $10 rewards, FX-converted, left
  `pending_rate`/`pending_payout` rather than guessing when it cannot pay).
  Creator/influencer application form → `sas_creator_applications` (T&Cs apply).
- **Virtual Card is Flutterwave ONLY:** new `func/bc-virtualcard-flutterwave.php`
  adapter via the provider registry (fail-closed until endpoints are
  configured); web page, ajax, `web/api/virtual-card.php` and
  `bc-admin/VirtualCard.php` all route through it. Removed: BSICards gateway +
  bridge, all Chimoney card functions (its FX-rate lookup for gift cards is
  kept), the Chimoney card webhook, and the `bsicards` SAAS marketplace type.
  Legacy cards are listed read-only and refuse funding with an explanation.
- **AI → Chat Support:** `web/AI-Assistant.php` is now the free Support page
  (AI first, human handoff, users never pay). Threads carry `SUP-######`
  ticket numbers (shown to the user, in the widget, and in bc-admin →
  Support Chat, which opens them as tickets). The chat widget badge shows a
  live unread response **count**. The AI can inspect the customer's own recent
  transactions (service/status/date) via a context callback registered by
  `web/ajax-support-chat.php` — the chat engine itself stays money-blind
  (gate-tested), never states balances, and still escalates to humans.
  NON-SAAS AISettings states owner-funded AI (direct API) and points at
  AIMarketing + Chat Settings; SAAS admin AI (marketplace) unchanged.
- **Landing (`index-bc-style-template-1.php`)** rebuilt to the client's
  freelancer template: sticky navbar with automated site name/logo, hero with
  bento widgets (virtual/physical card toggle, currency pills, invoice-paid
  mini, zero-fee card, instant VTU top-ups, income separation), floating
  subnav, Receive (bank transfer/invoice/QR), Manage pockets, the embossed
  3-step **Payout to China** workflow (Wallet provisioning → Instant funding →
  China settlement), red Spend section, six-feature Currencies grid (with the
  gated live FX converter kept), new-services strip (Send Abroad, Tuition,
  eSIM, POS, Crypto, Gift Cards, $10/$10 Referral, free support), FAQ, CTA and
  footer. `--brand-red` is the bc-admin-selected primary colour; all branding,
  logo and contact email are automated; sections stay gated by
  `isServiceEnabled()`. Template 6 untouched.

Test counts after this change: gate **1053**, policy **70**, plate_app **154**,
plate_admin **708**, plate_migration **1090**, backoffice_regression **184**,
lm_edition **30**, htaccess **224**, plus new **upgrade25 193**. Contracts
updated deliberately: plate_app/plate_migration/fintech_gate now pin the
retired AI widget, the Support nav, the two-tier Pricing titles and the §25
new pages instead of the pre-§25 bodies.

## §26 — Live provider endpoints from the official docs (2026-11-20)

The site owner supplied the four official API references (Klasha
https://developers.klasha.com/ , Flutterwave
https://developer.flutterwave.com/api , Platnova https://docs.platnova.com/ ,
1GLOBAL https://docs.1global.com/api/ ). Every endpoint, auth rule and cipher
below was transcribed from those docs (retrieved 2026-11-20, including the
machine-readable llms.txt indexes, per-page Markdown, Platnova's embedded
OpenAPI and Klasha's linked Postman collection). Nothing was invented: what
the current docs do not publish stays fail-closed, as before.

### Provider registry (`func/bc-fintech-providers.php`, both editions)

- **Klasha** — base `https://gate.klasapps.com/pay` (the docs' live `env_url`;
  sandbox `https://dev.kcookery.com`). Wired: login
  (`/auth/account/v2/login`), Transaction Status verify
  (`/nucleus/tnx/collection/status`, `gateRef`), Swap quote/confirm, Klasha
  Wire (beneficiary, quote, initiate, fetch), CNY payout (quotation v2, bank
  codes, initiate under `/wallet/merchant/{businessId}/...`), stablecoin
  wallets/fees/validate/v2 payout, and virtual-account creation. Webhook
  payload fields are mapped from `misc/webhook` (`data.tnxRef`,
  `data.sourceAmount`, ...); `event_id` stays unmapped because the documented
  payloads carry none.
- **Flutterwave** — base `https://api.flutterwave.com`. Wired: transaction
  verify, bill categories (`/v3/top-bill-categories`), bill validate
  (GET `/v3/bill-items/{item_code}/validate`), bill purchase
  (`/v3/billers/{biller_code}/items/{item_code}/payment`), hosted checkout
  (`POST /v3/payments`), payment plans, virtual account numbers, transfers,
  transfer rates and bulk transfers.
- **Platnova (new provider)** — base `https://api.platnova.com`, `X-API-Key`
  header only. Wired: payments send/verify, bank list + account-name verify,
  card creation (`/v1/cards/create`), and the partner eSIM API (plans,
  orders, order status, install link). Credentials: gateway row `platnova`
  in `sas_payment_gateways`, API key in the secret field.
- **1GLOBAL (new provider, slug `oneglobal`)** — Connect API at
  `https://services.truphone.com/connect-api/v2`: create order, poll order.
  The Truphone management API at docs.1global.com has no eSIM ordering; the
  Connect recipes supply the order flow and the completed-order shape
  (`output.matching_id/iccid`, `_metadata.links._lpa/_qrcode/_smdp`).
  Credentials: gateway row `oneglobal`, OAuth Bearer token as the secret.

Endpoint paths may carry `{placeholder}` segments; the request seam fills
them from same-named payload keys and refuses (`endpoint_parameter_missing`)
rather than ever calling a literal-brace URL. The cURL branch of the HTTP
seam now converts the seam's `name => value` header map into proper
`Name: value` lines (previously `array_values()` dropped the names, which
would have broken every real call) and sends GET payloads as query strings.

### Still fail-closed — deliberately

- **Flutterwave virtual cards, POS requests, regional toggles:** the current
  official API index publishes none of them (the Virtual Cards API no longer
  appears in the docs). Registry paths stay empty; the Flutterwave-only card
  adapter keeps refusing with `endpoint_not_configured`. Fill the three
  registry paths if Flutterwave documents them and it goes live unchanged.
- **Klasha webhooks:** payload shapes are documented, a signature scheme is
  not. The signature header/algorithm stay empty, so `users-klasha.php`
  keeps refusing every delivery; collections are confirmed through the
  Transaction Status API instead.
- **Platnova / 1GLOBAL webhooks and the 1GLOBAL product catalogue:** signing
  details / catalogue path not yet transcribed; no webhook credit path
  exists for these providers.
- **Platnova customer & virtual-account creation:** flows are documented but
  the raw paths were not transcribed verbatim, so no wrapper invents them.

### Klasha adapter (`func/bc-fintech-klasha.php`)

- Auth per docs: `x-auth-token` = merchant **public** key; `Authorization:
  Bearer` = token from `POST /auth/account/v2/login` (or a pasted JWT).
  Credential mapping on the existing gateway row: public_key → x-auth-token,
  encrypt_key → encryption key, secret_key → account password **or** Bearer
  token. Vendor options `klasha_login_email` / `klasha_business_id` (set in
  Service Control) supply the login username and the CNY `{businessId}`.
- The brief's "3DES" question is settled — the docs publish **two** schemes,
  both now implemented and known-answer tested: 3DES-CBC (`des-ede3-cbc`,
  official PHP snippet) for the Payments API, and AES-256-CBC in the OpenSSL
  salted format (EVP_BytesToKey/MD5, "Salted__") for the wallet/Wire/Swap/
  CNY/virtual-account APIs, sent as `{"message": "<ciphertext>"}`. Unknown
  schemes and missing OpenSSL refuse (`encryption_unavailable`) — never a
  plaintext fallback for an endpoint that requires encryption.

### Admin & pages

- `bc-admin/ServiceControl.php`: the Active Fintech Provider card now also
  saves Platnova / 1GLOBAL credentials and the Klasha login helpers (blank
  key fields keep the stored key). Klasha's PaymentGateway field is labelled
  "ENCRYPTION KEY" with the mapping documented inline.
- `web/ESIM.php`: when Platnova partner eSIM is configured, plans render
  live from `/v1/partner/esim/plans` with real kobo pricing; orders record
  against the shown rail. Display-only — no wallet is debited from the page
  (purchases debit the merchant's Platnova wallet and deliver by webhook, so
  order execution stays with the recorded-request workflow until priced
  checkout is enabled).
- `web/IntlTransfer.php`: after recording a request, the notice shows an
  indicative **live quote** — Klasha Swap FX quote or Flutterwave transfer
  rates, per the active provider. Quotes move no money.

### Tests & CI

- New `VTU/tests/provider_live_test.php` (**71** checks locally; the
  OpenSSL-dependent known-answer vectors and encrypted-call assertions run
  in CI's PHP): registry values, placeholder URLs, AES/3DES KATs generated
  with an independent implementation, Klasha login + encrypted CNY flow,
  Platnova X-API-Key/idempotency headers, 1GLOBAL order extraction, the
  preserved fail-closed set, and cross-edition parity of all §26 files.
- `fintech_gate_test.php` §19 now pins the documented registry (gate count
  **1053 → 1065**); the refusal-order contracts are unchanged but clear the
  shipped values through the override seam to keep exercising them. The old
  "klasha ships no cipher" guard is reversed in the test and in CI: both
  documented ciphers must now be present. CI also lints/runs the new test
  and adapters, parity-compares them, and covers them with the
  no-direct-balance-write rule.
- `provider_smoke.php` reports all four providers; the remaining "not
  configured" items are exactly the deliberate gaps above (plus credentials
  until an admin saves them).

Test counts after this change: gate **1065**, policy **70**, plate_app
**154**, plate_admin **708**, plate_migration **1090**,
backoffice_regression **184**, lm_edition **30**, htaccess **224**,
upgrade25 **193**, provider_live **71** (local no-OpenSSL branch).

## §27 — Dashboard "All Services" grid redesign (2026-11-20)

`web/Dashboard.php` (both editions): the All Services card's vertical
list-group is replaced by a responsive tile grid — one tile per hub
(coloured icon, name, one-line hint), reusing the page's existing
`.svc-tile` styles, hardened with a minimum height, focus ring and a
compact small-screen variant. Breakpoints (Bootstrap `row-cols`):
2-up on phones, 3-up from `sm`, 4-up from `lg`; tiles stretch so rows
stay even. The seven hub links are unchanged (still pinned by
upgrade25, which now also asserts the grid markup, 193 → 195).
