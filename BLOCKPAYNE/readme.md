# BLOCKPAYNE — Non-Custodial Crypto Payment Gateway

A fast, simple, mobile-first **Blockonomics-enhanced** non-custodial cryptocurrency payment gateway built in self-contained **PHP 8.2+ & MySQL 8.0+**. No Composer runtime or build step is required on cPanel, LiteSpeed, Apache, or Nginx hosting.

## Highlights

- **100% Live Non-Custodial (`xPub` & Direct Wallet):** Derives unique buyer deposit addresses per invoice in pure PHP (`secp256k1` BIP32/BIP44/BIP49/BIP84) for **BTC** (`zpub`/`ypub`/`xpub` -> `bc1q...`/`3...`/`1...`), **LTC** (`ltc1q...`/`L...`), **BCH** (`bitcoincash:q...`), **ETH** (`0x...` EIP-55), **USDT-TRC20** (`T...`), and **USDT-ERC20** (`0x...`). Also supports optional live **BlockBee / CryptAPI** forwarding (`api.blockbee.io`).
- **Live Multi-Source Market Rates & Node Polling:** Automatic exchange-rate failover across **CoinGecko**, **Binance**, and **Coinbase** + direct 0-conf mempool & confirmation polling across **Mempool.space**, **Blockstream**, **Litecoinspace**, **Blockchair**, **TronGrid**, and **Ethereum JSON-RPC**.
- **Prefund Credit Model (1% Fee):** Buyer payments route directly to the merchant's wallet. Operational fees are deducted from the merchant's USD prefund credit balance, topped up via live **Payhub** (Card/Bank NGN + signed `X-Payhub-Signature` webhook), **Live Crypto Deposit**, or **Admin Credit**.
- **Multi-Language Merchant API & 10 Official Platform Plugins (`/api-docs`):**
  - Interactive developer reference in **8 programming languages** (**PHP**, **Node.js / TypeScript**, **Python**, **cURL**, **Go**, **C# / .NET**, **Ruby**, and **Java 17+**) + **all PHP frameworks** (**Universal PHP SDK**, **Laravel**, **Symfony**, **CodeIgniter 4**, **Vanilla PHP**).
  - **1-Click `.zip` Downloadable Plugins & Bridges** (`/integrations/download/{slug}`) for **WHMCS**, **WordPress / WooCommerce**, **FOSSBilling**, **PrestaShop**, **Joomla (VirtueMart & HikaShop/J2Store)**, **Shopify**, **Wix (Velo)**, **Invoice Ninja v5**, **Telegram Shop Bot (PHP cPanel & Python)**, and **PHP SDK**.
  - Supports `"currency": "AUTO"` (buyer multi-coin switcher on `/pay/{id}`), custom `metadata` JSON round-tripping, and zero-cURL HMAC-signed HTML form redirects (`POST /pay/hosted`).
- **Dynamic 4-Stage Web Installer:** Automatically intercepts `/` when `storage/installed` is absent -> verifies PHP 8.2+ & License Manager (`https://manager.pmhserver.name.ng/api.php`) -> connects MySQL & writes `.env` (`0600`) -> creates Super Admin & generates dynamic **First-Letter SVG Logo/Favicon** -> locks `storage/installed`.
- **cPHulk / Imunify360-Grade Security Engine:**
  - **250-Country ISO Firewall** with instant live search (`Whitelisted`, `Not Specified`, `Blacklisted`).
  - **Account & IP Brute-Force Protection** with configurable window, failure thresholds, Privilege Exception toggle (`admin`/`administrator`), and `1_day` / `1_week` / `1_month` / `1_year` IP blocks.
  - **Smart 5-Session Whitelisting:** Clean logins across 5 distinct sessions automatically whitelist the IP and display the **King Crown Icon with Green Marking** in Security Monitor logs.

---

## Live Production Deployment (cPanel / Apache / LiteSpeed)

1. Upload the `BLOCKPAYNE/` directory contents to your domain or subdomain document root.
2. Create an empty MySQL 8.0+ (or MariaDB 10.6+) database and user in cPanel.
3. Ensure `storage/` and root `.env` path are writable by PHP, then visit `https://yourdomain.com/` to complete the **4-Stage Setup Wizard**.
4. Add the 1-minute Cron job in cPanel:
   ```cron
   * * * * * php /home/cpaneluser/public_html/artisan schedule:run >> /dev/null 2>&1
   ```
5. In **Admin -> Gateway Setup**, configure your live **Payhub** keys (`payhub_public_key`, `payhub_secret_key`), optional **BlockBee API Key**, and **Admin Live Crypto Treasury Addresses** (`BTC`, `LTC`, `BCH`, `USDT-TRC20`, `ETH`).

---

## Automated Tests

```bash
cd BLOCKPAYNE
npm install
npm test   # Runs PHP 8.4 syntax lint, JS lint, BIP84/Keccak unit suite & full SQL e2e suite
```

---

## Merchant REST API v1 (`/api-docs`)

Authenticate with `Authorization: Bearer bp_live_...` (or `X-API-Key: bp_live_...`):

- `POST /api/v1/invoices` — Create a non-custodial crypto invoice (`amount`, `fiat_currency`, `currency`, `order_id`, `title`, `buyer_email`, `callback_url`, `redirect_url`).
- `GET /api/v1/invoices/{invoice_id}` — Poll live invoice status, confirmations, `tx_hash`, and `explorer_url`.
- `GET /api/v1/invoices` — List/filter merchant invoices (`status`, `order_id`, `limit`).
- `GET /api/v1/rates` — Live USD & fiat crypto conversion rates.
- `GET /api/v1/balance` — Merchant USD prefund credit balance & active non-custodial wallets.
- **Signed Webhooks:** `X-BlockPayne-Event` (`invoice.detected` | `invoice.confirmed`) and `X-BlockPayne-Signature: HMAC-SHA256(raw_json_body, webhook_secret)`.
