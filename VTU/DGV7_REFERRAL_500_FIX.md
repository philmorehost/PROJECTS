# Refer and Earn — HTTP 500 fix (all three apps)

**Symptom reported:** the Refer and Earn screen fails to load. The app shows

> The server returned an error (HTTP 500). Please try again shortly.

That message comes from `endpointError()` in each app's `util/Extensions.kt` when the server
answers with a non-2xx status — so this was **never a client or connection problem**. The request
reached the server and the endpoint crashed.

---

## Root cause

`web/api/referral.php` queries `sas_users.referral_id` and `sas_users.referral_bonus_awarded`.

Those columns are declared in the `CREATE TABLE sas_users` statement in `func/bc-tables.php`, but:

1. **`CREATE TABLE IF NOT EXISTS` never alters an existing table.** An install created before the
   Refer and Earn feature therefore has no such columns, and nothing ever adds them.
2. **`/web/api/*` never loads `bc-tables.php`.** API endpoints include only
   `func/bc-connect.php`, which pulls in `bc-func.php`, `bc-gateway.php`, the queue helpers, etc. —
   but **not** `bc-tables.php`. So the column-migration block in `bc-tables.php` (which runs for
   every web/admin/cron surface) never ran for app traffic.
3. The endpoint then fetched results like this:

   ```php
   $totals = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
   ```

   When the query fails, `mysqli_stmt_get_result()` returns `false`. Under PHP 8,
   `mysqli_fetch_assoc()` is declared as `mysqli_fetch_assoc(mysqli_result $result)`, so passing
   `false` throws an **uncaught `TypeError`** — a fatal error, i.e. **HTTP 500**.

Reproduced locally on PHP 8.2 with a schema that lacks the columns:

```
ORIGINAL referral.php
  FATAL TypeError: mysqli_stmt_bind_param(): Argument #1 ($st) must be of type mysqli_stmt,
        bool given
  => uncaught fatal => HTTP 500 in the app

PATCHED referral.php
  status=success total=0 qualified=0 bonus=100 coins=0
```

The same failure mode applies if `sas_points_log` or `sas_loyalty_bonus_settings` is missing, or if
`get_user_vtu_details()` / `isServiceEnabled()` hit a missing table — all of which are called by this
endpoint. Every one of those is now guarded.

---

## Changes

### 1. `func/bc-tables.php` — both editions

Added the two columns to the existing `$cols` migration list, so an upgraded install gets them the
next time any web page / admin page / cron job loads:

```php
"referral_id"            => "VARCHAR(225)",
"referral_bonus_awarded" => "TINYINT(1) DEFAULT 0"
```

### 2. `web/api/referral.php` — both editions

* **New helper** `bc_referral_fetch_row($stmt)` — runs the statement and returns the first row, or
  `null` when the statement, execution or result is `false`. No caller can ever hand `false` to
  `mysqli_fetch_assoc()` again.
* **Self-heal** — the endpoint inspects `SHOW COLUMNS FROM sas_users` and issues the two
  `ALTER TABLE ... ADD COLUMN` statements when they are missing. This is what makes the screen work
  immediately after uploading, without waiting for someone to browse the website first.
  `CREATE TABLE IF NOT EXISTS` could never do this.
* **Defensive fetches** — every query goes through the helper, so a missing column/table degrades to
  zeros instead of a fatal.
* **Guarded helpers** — `get_user_vtu_details()` and `isServiceEnabled()` are wrapped in
  `try { } catch (Throwable) { }` because they query `sas_points_log`, `sas_conversions` and
  `sas_services` directly and raise the same `TypeError` when those are absent.

Both editions now contain byte-identical `referral.php` (modulo CRLF/LF).

### 3. Tests

| File | Purpose |
| --- | --- |
| `tests/app_referral_api_test.php` | existing contract test — still **37/37** |
| `tests/app_referral_resilience_test.php` | **new**: 15 checks proving the endpoint survives a pre-migration schema and still returns real data on a healthy one |

The new test fails against the old endpoint (6 failures) and passes against the fixed one (15/15),
so it genuinely guards the regression.

---

## Deployment

Upload these four files (two per edition you run):

```
DGV7.0-SAAS/func/bc-tables.php
DGV7.0-SAAS/web/api/referral.php
DGV7.0-NON-SAAS/func/bc-tables.php          (if you run the NON-SAAS edition)
DGV7.0-NON-SAAS/web/api/referral.php
```

No app rebuild is required — the bug was entirely server-side. The Refer and Earn screen will load
as soon as `referral.php` is replaced, because the endpoint adds the missing columns itself.

Verify after uploading:

```powershell
# Replace <key> with a real api_key for the vendor
curl.exe -s -X POST -H "Content-Type: application/json" ^
  -d "{\"api_key\":\"<key>\"}" https://app.mzeevtu.com/web/api/referral.php
```

Expected: `{"status":"success","data":{...}}` — no `{"status":"error",...}` and no HTML/500.

To confirm the columns landed:

```sql
SHOW COLUMNS FROM sas_users LIKE 'referral%';
-- referral_id            varchar(225)  NULL
-- referral_bonus_awarded tinyint(1)   NULL  (default 0)
```

---

## Why the apps needed no change

All three Android apps and all three iOS apps carry the same referral implementation
(`ReferralFragment.kt` / `ReferralView.swift`), calling `POST web/api/referral.php` with
`{"api_key": ...}` and rendering `data.referral_code / referral_link / referral_bonus /
total_referrals / qualified_referrals / pending_referrals / coins_from_referral
s / coins_balance / referred_users`. I verified byte-identical sources across DG7, JIKABIZ and
MZEEVTU (after package normalisation), the same nav wiring, the same layout ids, and the same
`endpointError()` handling — so there was no "out of date" copy to update.

The only reason MZEEVTU appeared stale is that the **published** MZEEVTU build is versionCode 10
(1.0.9) while the current source is 13 (1.0.12); that build cannot be published until Play's
upload-key reset is approved. The referral screen in source is identical to DG7's, which works.

---

## Related defect worth knowing

`web/api/register.php` writes `referral_id` in its `INSERT INTO sas_users (...)`. On an install that
lacks the column, **registration through the app also fails**. The `bc-tables.php` fix above repairs
that the first time any web page loads; if you want it repaired from the API alone, the same
self-heal block can be added to `register.php`.
