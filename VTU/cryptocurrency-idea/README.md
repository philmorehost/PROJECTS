# Mzeevtu — Buy & Sell Crypto

Users buy crypto from your stock, or sell their crypto to you. Naira moves through their existing **Mzeevtu (DGV7.0) wallet balance**.

```
Browser widget ──► /crypto/api.php (PHP, your site) ──signed──► Node wallet service ──► Blockchain
                   ◄── /crypto/callback.php ◄──signed── (credits / refunds)
```

| Part | Folder | What it does |
|---|---|---|
| Wallet service | `service/` | Holds the keys, prices trades, sends crypto, watches deposits, sweeps funds |
| PHP bridge | `php-bridge/` | Debits/credits the user's Naira wallet, talks to the service |
| Widget | `widget/` | The Buy/Sell screen users see |

## How a trade works

**Buy.** The user gets a price (held for 2 min), enters their wallet address and confirms. PHP debits their Naira wallet. The service sends crypto from your **hot wallet**, and the order completes after network confirmations. If sending fails before it reaches the blockchain, the user is **refunded automatically**.

**Sell.** The user gets a price and receives a **unique one-time deposit address** with an exact amount and 60 min to send. Once the deposit is confirmed, the service tells PHP to credit their Naira wallet. The coins are then **swept** into your hot wallet.

## Setup

### 1. Wallet service (VPS with Node.js 22.5+)
cPanel shared hosting usually can't run this. Use a small VPS, ideally the same one as the site.

```bash
cd service
npm install
npm run new-wallet          # prints a seed phrase + your hot wallet address on each network
cp .env.example .env        # paste the seed phrase, set 3 long random secrets, RPC URL, CALLBACK_URL
npm test                    # 20 tests should pass
npm start
```
Keep it running with `pm2 start "npm start" --name mz-crypto`. Keep `HOST=127.0.0.1`, because only your PHP should reach it.

**Fund the hot wallets** (addresses printed at start-up; one seed phrase covers all networks):

| Network | Hot wallet looks like | Fund it with |
|---|---|---|
| BSC / Ethereum / Polygon | `0x…` | the coins you sell + BNB/ETH/POL for gas |
| TRON | `T…` | USDT-TRC20 + **TRX** (each USDT payout burns roughly 7–14 TRX for energy unless you stake or rent energy) |
| Bitcoin | `bc1q…` | BTC (the network fee comes out of it) |

### 2. PHP bridge (your DGV7.0 site)
1. Upload `php-bridge/` to `public_html/crypto/`.
2. Run `schema.sql` in phpMyAdmin.
3. Edit `config.php`. **This is the one part you must adapt:**
   - `wallet.table / id_col / balance_col` is where DGV7.0 stores the user's balance (check phpMyAdmin).
   - `session_user_key` is the `$_SESSION` key holding the logged-in user ID.
   - `history_sql` (optional) makes trades show in the user's transaction history.
   - Set `MZ_CRYPTO_API_SECRET` / `MZ_CRYPTO_CALLBACK_SECRET` to the same values as the service `.env`.
4. Hook your KYC check into `mz_user_can_trade()` in `lib.php`.

### 3. Widget
Paste `widget/crypto-widget.html` into the dashboard page for your BUY/SELL section. If the bridge isn't at `/crypto/api.php`, change `MZ_API` at the top of the script. The page must be UTF-8.

## Setting your rates
The default is the live CoinGecko price ± your spread (`BUY_SPREAD_BPS`, `SELL_SPREAD_BPS`, where 150 = 1.5%). To set **your own fixed rates** instead (common for USDT/NGN), sign an admin request:

```
POST /v1/bc-admin/rates   {"asset":"USDT_BSC","buyRate":"1680","sellRate":"1620"}
POST /v1/bc-admin/rates   {"asset":"USDT_BSC","clear":true}     ← back to market price
```

## Admin API (signed with ADMIN_SECRET)
| Endpoint | Use |
|---|---|
| `GET /v1/bc-admin/wallet` | Hot wallet address, balances, reserved amounts, order counts |
| `GET /v1/bc-admin/orders?status=needs_review` | Orders that need a human |
| `POST /v1/bc-admin/orders/:id/resolve` | Buy: `{"action":"refund"}` or `{"action":"complete"}`. Sell: `{"action":"credit","fiatAmount":"75000"}` or `{"action":"reject"}` |

Signature: `X-Mz-Timestamp: <unix>` and `X-Mz-Signature: hex(HMAC_SHA256(secret, "<ts>.<METHOD>.<path>.<body>"))`. `mz_service()` in `lib.php` shows how.

**Goes to review (never automatic):** sell deposits that arrive after the window, overpayments over 2%, deposits below the minimum, buy transactions that revert or aren't mined within 30 min. Refunding a buy is blocked if its transaction succeeded on-chain.

## Built-in safety
- Private keys exist only in the service. PHP and the browser never see them.
- Prices come from the service. The browser can't change what a user pays.
- Every buy is signed and **saved before broadcasting**, so a crash or retry can never send twice.
- Idempotent everywhere: `clientRef` on orders, `eventId` on callbacks, and a quote can only be used once.
- Hot wallet liquidity is checked (minus pending orders) before a user is charged.
- Callbacks retry with backoff until your site confirms. Wallet credits are applied exactly once.
- Exact BigInt maths, with rounding always in your favour by less than 1 kobo.

## Networks
Enabled with `ENABLED_ASSETS`: `USDT_TRON, TRX, BTC, USDT_BSC, USDC_BSC, BNB, ETH, USDT_ETH, USDC_ETH, USDT_POLYGON, POL`. **Verify the contract addresses in `src/assets.js` on the explorer before going live.**

| | Confirmed when | Network fees you pay |
|---|---|---|
| **USDT-TRC20 / TRX** | Block is solidified (irreversible, ~1 min) | Payouts burn TRX for energy. Each USDT sell sweep costs roughly 7–27 TRX (the deposit address is topped up, then its leftover TRX is returned) |
| **BTC** | 2 confirmations (~20 min) | Payout fee taken from the hot wallet at `BTC_FEE_LEVEL`. Sweeps cost one small tx each |
| EVM tokens | 15 (BSC) / 12 (ETH) / 64 (Polygon) blocks | Gas for payouts + a gas top-up per token sweep |

Fees are yours, so price them in. BTC fees can spike, so a bigger spread or fixed manual rates for BTC is wise. USDT-TRC20 costs more per sweep than BEP20.

**How a stuck payout is handled per network.** It is only auto-refunded when it provably can never be mined: on EVM, the nonce was reused; on TRON, the tx's expiry passed; on Bitcoin, one of its coins was spent by another tx. Otherwise it is rebroadcast and, after 30 min, sent to review.

## Before going live
- Test end to end with small amounts first (e.g. ₦2,000 of USDT on BSC).
- Keep only a working float in the hot wallet. Store the seed phrase offline too, and back up `data/*.db`.
- On TRON, the hot wallet needs TRX for every USDT payout. Watch it in `GET /v1/bc-admin/wallet`.
- Each one-time deposit address is used for one order only. Coins sent to it later are recoverable with the seed phrase, but won't be credited automatically.
