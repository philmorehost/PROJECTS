# Order automation — automatic cancellation of unpaid orders

This document explains the **automatic cancel-pending-orders** feature and the
**restock** fix that goes with it.

---

## 1. What was wrong

### "Each cancel returns double to stock"

Stock is taken out of `products` / `product_variants` when an order is placed and
has to be put back **once** when the order is cancelled. The old cancel code in
`my_orders.php`, `admin/order_detail.php` and `admin/delete_order.php` did this:

```php
SELECT status, stock_restored FROM orders WHERE id = ?   -- no lock
if (!stock_restored) {
    UPDATE products SET stock = stock + ? ...            -- adds the stock
}
UPDATE orders SET status = 'Cancelled', stock_restored = 1 ...
```

Two requests for the same order — a customer double-tapping **Cancel**, the admin
clicking twice, or the new automatic sweep running while the customer cancels —
both read `stock_restored = 0`, both add the quantities, and both mark the order
cancelled. The stock comes back **twice** (or more) and the shop ends up with
phantom inventory.

The same "add without checking" pattern also existed at checkout: stock was
deducted without checking it was still available, so two shoppers could both buy
the last unit and stock went negative — which is then "returned" as extra stock on
cancellation.

### Too many pending orders

There was no way for the shop to clean up orders that customers never paid. They
stayed in *Pending* / *Awaiting Payment* forever, keeping their stock reserved.

---

## 2. How it works now

### One place for cancellation — `includes/order_stock.php`

Every cancellation path (customer, staff, automatic sweep, delete) now calls
`eflex_cancel_order()`, which:

1. opens a transaction and **locks the order row** with `SELECT … FOR UPDATE`,
2. checks the status and the `stock_restored` flag *under that lock*,
3. puts the quantities back from `order_items` (variant stock to
   `product_variants`, plain stock to `products`),
4. flips `stock_restored` 0 → 1 with `WHERE stock_restored = 0` and commits.

A second, concurrent attempt therefore sees the flag set (or gets 0 affected
rows) and does nothing. **Restocking is exactly-once.**

The same helper powers the other direction: moving a **cancelled** order back to
another status (`eflex_reopen_order()`) reserves the stock again, so re-opening an
order cannot be used to create free inventory. It refuses when there is not
enough stock left.

Checkout now uses `eflex_deduct_checkout_item()`, which will not let stock go
below zero:

```php
UPDATE product_variants SET stock = stock - ?
 WHERE id = ? AND stock >= ?      -- nothing changed => out of stock
```

so overselling (and the over-restocking that followed it) is impossible.

### Who can cancel what

| Where | Statuses that can be cancelled |
| --- | --- |
| Customer (`my_orders.php`) | `Pending`, `Awaiting Payment`, `Failed` |
| Automatic sweep / bulk cancel | `Pending`, `Awaiting Payment`, `Failed` |
| Staff (`admin/order_detail.php`) | any status (refunds are allowed) |

`Processing` is deliberately **not** cancelled automatically: it means the
customer uploaded a payment proof and a human still has to approve it.

---

## 3. Automatic cancellation after 30 hours

Orders still in `Pending`, `Awaiting Payment` or `Failed` **30 hours** after they
were placed are cancelled automatically and their stock is returned.

### Admin settings

**Admin → Site Settings → Order Automation**

| Setting | Default | Meaning |
| --- | --- | --- |
| `auto_cancel_pending_enabled` | on | master switch |
| `auto_cancel_pending_after` | `30` | how long an unpaid order may sit |
| `auto_cancel_pending_unit` | `hours` | `hours` or `days` (2 days = 48 hours) |
| `auto_cancel_notify_customer` | on | email the customer when their order is cancelled |
| `auto_cancel_last_run` | — | timestamp of the last automatic sweep |

The window can be written in hours **or** days; both forms are validated
(1–8760 hours, 1–365 days), and an invalid value is saved as the 30-hour default
with a warning.

The same page has a **"Cancel overdue orders now"** button, a **cron command**
(with a schedule of `*/10 * * * *` — every 10 minutes), an optional **URL cron**
(`cron/auto_cancel_orders.php?key=…`) and a button to **regenerate the cron key**
(stored in `auto_cancel_cron_key`).

### When does it run?

1. **Cron (recommended)** — `cron/auto_cancel_orders.php`, every 5–15 minutes:

   ```
   /usr/local/bin/php /home/USER/public_html/cron/auto_cancel_orders.php
   ```

   The exact path is shown in Site Settings. Over HTTP it needs
   `?key=YOUR_CRON_KEY` (or an admin session). Only one sweep runs at a time
   (MySQL `GET_LOCK`), and it is idempotent, so it is safe to run often.

2. **Lazily, on page loads** — admin dashboard, Manage Orders, Site Settings and
   My Orders call `eflex_run_auto_cancel_if_due()`, which runs at most once every
   15 minutes (`EFLEX_AUTO_CANCEL_THROTTLE`) and handles up to 50 orders per
   sweep. This means the rule also works on shared hosting where no cron job is
   configured.

Admin → Manage Orders shows the numbers (unpaid / past the window / last run) and
has a **bulk cancel** form: either *only orders older than the window* or *every
unpaid order*. The dashboard warns when orders are already overdue.

### Emails

* The customer gets **one email per cancellation batch** listing their cancelled
  order numbers (only when `auto_cancel_notify_customer` is on).
* The shop gets one summary email at `admin_notification_email` (falls back to
  `from_email`).

Notifications are sent **after** the transaction, so a mail failure can never
leave stock half-restored.

### Customer-facing note

`my_orders.php` shows the saved window ("Unpaid orders … are cancelled
automatically after 30 hours"), so a cancelled order is never a surprise.

---

## 4. Database changes

`includes/db_setup.php` adds these automatically on the next page load:

| Change | Why |
| --- | --- |
| `orders.cancelled_at` | when the order was cancelled |
| `orders.cancel_reason` | `Automatically cancelled: unpaid after 30 hours`, `Cancelled by the customer`, `Cancelled by the administrator` … |
| index `orders(status, created_at)` | makes the "older than X hours" query cheap |

Both columns are optional for the code: `eflex_orders_has_column()` detects them,
so the feature keeps working on a database where the migration has not run yet.

The order detail page shows *"Stock for this order has already been returned on …
— do not restock it again manually"* when the flag is set.

---

## 5. Things worth knowing after deploying

* **Existing inflated stock is not corrected automatically** — the old double
  restock left no trace, so the database cannot tell which counts were doubled.
  Do a physical stock count for the products that appear in recently cancelled
  orders and correct them once in *Products → Edit*.
* **Deleting an order** (`admin/delete_order.php`) still returns the stock, but
  only if it was not returned before; the flag guarantees it happens once.
* A cancelled order can be re-opened from the order detail page; the stock is
  reserved again, and the action is refused with a clear message if the item is
  sold out.

---

## 6. Tests

No MySQL server is needed — `tests/fake_db.php` is an in-memory stand-in for
`mysqli`.

```bash
cd EFLEX
php tests/run_cancel_tests.php     # 71 behavioural checks (restock, races, window, emails)
php tests/syntax_check.php         # parses every PHP file

# page-level smoke tests (renders the real admin/customer pages):
npm install --no-save @php-wasm/node @php-wasm/universal
node tests/run_smoke.mjs
```

`run_cancel_tests.php` covers, among others:

* cancelling twice (double click) returns the stock only once,
* variant stock goes back to the variant, not the parent product,
* paid/`Processing` orders are never swept,
* an already-cancelled order is not restocked again,
* re-opening reserves the stock again (and is refused when sold out),
* the 30-hour default, hour/day units, the on/off switch,
* one email per customer + shop summary, `notify_customer = 0` suppression,
* checkout cannot oversell,
* a legacy schema without `cancelled_at` / `cancel_reason` still works.

The page smoke tests (`tests/run_smoke.mjs`) render the real pages against the
fake database and assert what they did to stock:

| Scenario | Expected |
| --- | --- |
| Manage Orders page view | overdue order swept, fresh order untouched, stock +2 once |
| Site Settings page view | nothing cancelled |
| Order detail page view | nothing changed |
| Bulk cancel (scope = all) | both unpaid orders cancelled, product +2, variant +2 |
| Customer cancels | order cancelled, variant restocked once, flag set |
| Staff cancels via dropdown | order cancelled, `cancel_reason` recorded, variant restocked once |
| Staff re-opens a cancelled order | status changed, stock reserved again (10 → 7) |
| Save "2 days" | window stored as 2 / days |
| Save "0 days" | rejected, stored as 30 hours |
| "Cancel overdue orders now" | overdue cancelled, stock +2 once |
| "Generate new cron key" | 40-hex key stored |
| Cron with the key | overdue cancelled, stock +2 once, last run recorded |
