import { randomUUID } from 'node:crypto';
import { parseUnits, formatUnits, fiatFromCrypto, cryptoFromFiat, roundBase, toRateString, FIAT_DP } from './money.js';

export class AppError extends Error {
  constructor(code, message, httpStatus = 400) {
    super(message);
    this.code = code;
    this.httpStatus = httpStatus;
  }
}

const MIN = 60_000;

/*
 BUY  (user buys crypto from you, paying from their site wallet)
   queued → signing → broadcast_pending → broadcast → completed
                     ↘ failed (refund event)          ↘ needs_review
 SELL (user sends crypto to a one-time deposit address, you credit their site wallet)
   awaiting_deposit → confirming → deposit_confirmed → completed (after site acknowledges credit)
                    ↘ expired      ↘ needs_review
*/

export function createOrderService({ db, chain, pricing, config, now = () => Date.now(), log = console }) {
  const reg = config.registry;
  const minFiat = parseUnits(config.minFiat, FIAT_DP);
  const maxFiat = parseUnits(config.maxFiat, FIAT_DP);

  // ── helpers ──────────────────────────────────────────────────────────
  const tx = (fn) => {
    db.exec('BEGIN IMMEDIATE');
    try {
      const r = fn();
      db.exec('COMMIT');
      return r;
    } catch (e) {
      db.exec('ROLLBACK');
      throw e;
    }
  };

  function asset(key) {
    const a = reg.enabledAssets[key];
    if (!a) throw new AppError('unknown_asset', `Asset ${key} is not available`);
    return a;
  }
  const anyAsset = (key) => reg.assets[key];
  const chainOf = (a) => reg.chains[a.chain];

  const getOrder = (id) => db.prepare('SELECT * FROM orders WHERE id = ?').get(id);
  const getByRef = (ref) => db.prepare('SELECT * FROM orders WHERE client_ref = ?').get(ref);

  function updateOrder(id, fields, expectStatus) {
    const f = { ...fields, updated_at: now() };
    const keys = Object.keys(f);
    let sql = `UPDATE orders SET ${keys.map((k) => `${k} = ?`).join(', ')} WHERE id = ?`;
    const params = keys.map((k) => (f[k] === undefined ? null : typeof f[k] === 'bigint' ? f[k].toString() : f[k]));
    params.push(id);
    if (expectStatus) {
      const list = [].concat(expectStatus);
      sql += ` AND status IN (${list.map(() => '?').join(',')})`;
      params.push(...list);
    }
    return Number(db.prepare(sql).run(...params).changes);
  }

  function view(o) {
    if (!o) return null;
    const a = anyAsset(o.asset);
    const c = reg.chains[a.chain] ?? {};
    return {
      id: o.id,
      clientRef: o.client_ref,
      side: o.side,
      asset: o.asset,
      symbol: a.symbol,
      network: c.name,
      addressType: c.family,
      status: o.status,
      cryptoAmount: formatUnits(o.crypto_base, a.decimals),
      receivedAmount: o.received_base ? formatUnits(o.received_base, a.decimals) : null,
      fiatAmount: formatUnits(o.fiat_minor, FIAT_DP),
      currency: config.fiat,
      rate: o.rate,
      destination: o.destination,
      depositAddress: o.deposit_address,
      txHash: o.tx_hash,
      explorerUrl: o.tx_hash && c.explorerTx ? `${c.explorerTx}${o.tx_hash}` : null,
      expiresAt: o.expires_at,
      refunded: !!o.refunded,
      note: o.note ?? null,
      createdAt: o.created_at,
      updatedAt: o.updated_at,
    };
  }

  function enqueue(type, order) {
    const o = getOrder(order.id);
    const payload = { eventId: `${type}:${o.id}`, type, order: view(o), userRef: o.user_ref, sentAt: now() };
    db.prepare(
      'INSERT OR IGNORE INTO outbox (event_id, type, order_id, payload, next_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
    ).run(payload.eventId, type, o.id, JSON.stringify(payload), now(), now());
  }

  function checkLimits(fiat) {
    if (fiat < minFiat) throw new AppError('below_minimum', `Minimum is ${config.fiat} ${config.minFiat}`);
    if (fiat > maxFiat) throw new AppError('above_maximum', `Maximum is ${config.fiat} ${config.maxFiat}`);
  }

  function claimQuote(quoteId, side) {
    const q = db.prepare('SELECT * FROM quotes WHERE id = ?').get(quoteId);
    if (!q) throw new AppError('quote_not_found', 'Quote not found', 404);
    if (q.side !== side) throw new AppError('quote_side', `This is a ${q.side} quote`);
    if (q.used) throw new AppError('quote_used', 'Quote already used', 409);
    if (q.expires_at <= now()) throw new AppError('quote_expired', 'Quote expired — please get a new price', 410);
    const ch = db.prepare('UPDATE quotes SET used = 1 WHERE id = ? AND used = 0 AND expires_at > ?').run(quoteId, now());
    if (Number(ch.changes) !== 1) throw new AppError('quote_used', 'Quote already used', 409);
    return q;
  }

  function requireStr(v, name, max = 64) {
    if (typeof v !== 'string' || !v.trim() || v.length > max || !/^[\w\-:.]+$/.test(v)) {
      throw new AppError('bad_request', `${name} is required (letters, numbers, - _ : .)`);
    }
    return v.trim();
  }

  function existingFor(clientRef, side, quoteId) {
    const o = getByRef(clientRef);
    if (!o) return null;
    if (o.side !== side || o.quote_id !== quoteId) throw new AppError('ref_conflict', 'clientRef already used for another order', 409);
    return view(o);
  }

  // ── quotes ───────────────────────────────────────────────────────────
  async function createQuote({ side, asset: assetKey, cryptoAmount, fiatAmount } = {}) {
    if (side !== 'buy' && side !== 'sell') throw new AppError('bad_request', 'side must be buy or sell');
    const a = asset(assetKey);
    const rates = await pricing.getRates(a.key).catch((e) => {
      throw new AppError('price_unavailable', e.message, 503);
    });
    const rate = side === 'buy' ? rates.buy : rates.sell;

    let cryptoBase;
    try {
      if (cryptoAmount != null && cryptoAmount !== '') {
        cryptoBase = roundBase(parseUnits(cryptoAmount, a.decimals, { truncate: true }), a.decimals, a.displayDecimals);
      } else if (fiatAmount != null && fiatAmount !== '') {
        const fm = parseUnits(fiatAmount, FIAT_DP, { truncate: true });
        // buy: user pays ≤ fiatAmount → round crypto down. sell: user receives ≈ fiatAmount → round crypto up.
        cryptoBase = roundBase(cryptoFromFiat(fm, a.decimals, rate, side === 'sell'), a.decimals, a.displayDecimals, side === 'sell');
      } else {
        throw new AppError('bad_request', 'Provide cryptoAmount or fiatAmount');
      }
    } catch (e) {
      if (e instanceof AppError) throw e;
      throw new AppError('bad_amount', e.message);
    }
    if (cryptoBase <= 0n) throw new AppError('bad_amount', 'Amount too small');

    // buy: round fiat up (in your favour by < 1 kobo); sell: round down
    const fiatMinor = fiatFromCrypto(cryptoBase, a.decimals, rate, side === 'buy');
    checkLimits(fiatMinor);

    const q = {
      id: randomUUID(),
      side,
      asset: a.key,
      crypto_base: cryptoBase.toString(),
      fiat_minor: fiatMinor.toString(),
      rate,
      created_at: now(),
      expires_at: now() + config.quoteTtlSec * 1000,
    };
    db.prepare(
      'INSERT INTO quotes (id, side, asset, crypto_base, fiat_minor, rate, created_at, expires_at) VALUES (?,?,?,?,?,?,?,?)',
    ).run(q.id, q.side, q.asset, q.crypto_base, q.fiat_minor, q.rate, q.created_at, q.expires_at);
    return quoteView(q);
  }

  function quoteView(q) {
    const a = anyAsset(q.asset);
    return {
      quoteId: q.id,
      side: q.side,
      asset: q.asset,
      symbol: a.symbol,
      network: reg.chains[a.chain]?.name,
      addressType: reg.chains[a.chain]?.family,
      cryptoAmount: formatUnits(q.crypto_base, a.decimals),
      fiatAmount: formatUnits(q.fiat_minor, FIAT_DP),
      currency: config.fiat,
      rate: q.rate,
      expiresAt: q.expires_at,
      used: !!q.used,
    };
  }

  function getQuote(id) {
    const q = db.prepare('SELECT * FROM quotes WHERE id = ?').get(id);
    if (!q) throw new AppError('quote_not_found', 'Quote not found', 404);
    return quoteView(q);
  }

  // ── BUY: you send crypto from the hot wallet ─────────────────────────
  async function reservedFor(assetKey) {
    const rows = db
      .prepare("SELECT crypto_base FROM orders WHERE side='buy' AND asset = ? AND status IN ('queued','signing')")
      .all(assetKey);
    return rows.reduce((s, r) => s + BigInt(r.crypto_base), 0n);
  }

  async function createBuyOrder({ quoteId, clientRef, userRef, destination } = {}) {
    clientRef = requireStr(clientRef, 'clientRef');
    userRef = requireStr(String(userRef ?? ''), 'userRef');
    quoteId = requireStr(quoteId, 'quoteId');
    const existing = existingFor(clientRef, 'buy', quoteId);
    if (existing) return existing;

    const qPeek = db.prepare('SELECT * FROM quotes WHERE id = ?').get(quoteId);
    if (!qPeek) throw new AppError('quote_not_found', 'Quote not found', 404);
    const a = asset(qPeek.asset);
    const netName = reg.chains[a.chain].name;

    let dest;
    try {
      dest = chain.normalizeAddress(a.chain, String(destination ?? '').trim());
    } catch {
      throw new AppError('bad_address', `Invalid ${netName} wallet address`);
    }
    if (dest.toLowerCase() === chain.hotAddress(a.chain).toLowerCase()) throw new AppError('bad_address', 'Invalid wallet address');

    // liquidity check before we lock anything
    const bal = await chain.getBalance(a, chain.hotAddress(a.chain));
    const available = bal - (await reservedFor(a.key));
    if (available < BigInt(qPeek.crypto_base)) {
      throw new AppError('insufficient_liquidity', `Not enough ${a.symbol} available right now — try a smaller amount`, 409);
    }

    const order = tx(() => {
      const again = existingFor(clientRef, 'buy', quoteId);
      if (again) return again;
      const q = claimQuote(quoteId, 'buy');
      const id = randomUUID();
      db.prepare(
        `INSERT INTO orders (id, side, asset, status, client_ref, user_ref, quote_id, crypto_base, fiat_minor, rate, destination, created_at, updated_at)
         VALUES (?, 'buy', ?, 'queued', ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      ).run(id, q.asset, clientRef, userRef, q.id, q.crypto_base, q.fiat_minor, q.rate, dest, now(), now());
      return view(getOrder(id));
    });

    if (order.status === 'queued') {
      // send immediately; the worker also retries queued orders after restarts
      setImmediate(() => processBuy(order.id).catch((e) => log.error('processBuy', order.id, e.message)));
    }
    return order;
  }

  async function processBuy(id) {
    if (updateOrder(id, { status: 'signing' }, 'queued') !== 1) return;
    const o = getOrder(id);
    const a = anyAsset(o.asset);

    let signed;
    try {
      signed = await chain.signFromHot(a, o.destination, BigInt(o.crypto_base));
    } catch (e) {
      // Nothing was broadcast — safe to refund automatically.
      tx(() => {
        updateOrder(id, { status: 'failed', error: `sign: ${e.shortMessage || e.message}` }, 'signing');
        enqueue('buy.refund', o);
      });
      log.error('buy sign failed', id, e.message);
      return;
    }
    // Persist the signed tx BEFORE broadcasting so a crash can never cause a double send.
    if (updateOrder(id, { status: 'broadcast_pending', signed_tx: signed.raw, tx_hash: signed.hash, tx_meta: JSON.stringify(signed.meta ?? {}) }, 'signing') !== 1) {
      // order was refunded by the watchdog while we were signing — never broadcast it
      chain.release?.(a.chain, signed.meta);
      log.warn('order changed during signing; not broadcasting', id);
      return;
    }
    try {
      await chain.broadcast(a.chain, signed.raw);
      updateOrder(id, { status: 'broadcast', broadcast_at: now() }, 'broadcast_pending');
    } catch (e) {
      updateOrder(id, { error: `broadcast: ${e.shortMessage || e.message}` });
      log.warn('broadcast failed (will retry/verify)', id, e.message);
    }
  }

  async function checkBuy(o) {
    const a = anyAsset(o.asset);
    const c = chainOf(a);
    const t = now();

    if (o.status === 'queued') return processBuy(o.id);
    if (o.status === 'signing') {
      // crashed mid-signing — the tx was never stored, so it was never broadcast
      if (!o.signed_tx && t - o.updated_at > 2 * MIN) {
        tx(() => {
          updateOrder(o.id, { status: 'failed', error: 'interrupted before broadcast' }, 'signing');
          enqueue('buy.refund', o);
        });
      }
      return;
    }

    const st = await chain.getTxStatus(a, { hash: o.tx_hash, raw: o.signed_tx, meta: JSON.parse(o.tx_meta || '{}') });
    switch (st.state) {
      case 'confirmed':
        tx(() => {
          if (updateOrder(o.id, { status: 'completed', error: null }, ['broadcast', 'broadcast_pending']) === 1) enqueue('buy.completed', o);
        });
        return;
      case 'pending':
        if (o.status === 'broadcast_pending') updateOrder(o.id, { status: 'broadcast', broadcast_at: o.broadcast_at ?? t }, 'broadcast_pending');
        return;
      case 'reverted':
        updateOrder(o.id, { status: 'needs_review', error: 'transaction failed on-chain' });
        return;
      case 'dropped':
        // provably never mined (nonce reused / expired / inputs spent elsewhere) → safe refund
        tx(() => {
          if (updateOrder(o.id, { status: 'failed', error: 'transaction dropped' }, ['broadcast', 'broadcast_pending']) === 1) enqueue('buy.refund', o);
        });
        return;
      default: // not_found: not mined yet
        if (t - o.created_at > config.buyStuckMinutes * MIN) {
          updateOrder(o.id, { status: 'needs_review', error: 'not mined in time (check fees)' });
          return;
        }
        try {
          await chain.broadcast(a.chain, o.signed_tx); // same signed tx → same hash, can never double-pay
          if (o.status === 'broadcast_pending') updateOrder(o.id, { status: 'broadcast', broadcast_at: t }, 'broadcast_pending');
        } catch (e) {
          log.warn('rebroadcast', o.id, e.message);
        }
    }
  }

  // ── SELL: user sends crypto to a one-time deposit address ────────────
  function nextDepositIndex() {
    db.prepare("INSERT OR IGNORE INTO counters (name, value) VALUES ('deposit_index', 0)").run();
    db.prepare("UPDATE counters SET value = value + 1 WHERE name = 'deposit_index'").run();
    return Number(db.prepare("SELECT value FROM counters WHERE name = 'deposit_index'").get().value);
  }

  async function createSellOrder({ quoteId, clientRef, userRef } = {}) {
    clientRef = requireStr(clientRef, 'clientRef');
    userRef = requireStr(String(userRef ?? ''), 'userRef');
    quoteId = requireStr(quoteId, 'quoteId');
    return tx(() => {
      const existing = existingFor(clientRef, 'sell', quoteId);
      if (existing) return existing;
      const q = claimQuote(quoteId, 'sell');
      asset(q.asset);
      const index = nextDepositIndex();
      const address = chain.depositAddress(reg.enabledAssets[q.asset].chain, index);
      const id = randomUUID();
      db.prepare(
        `INSERT INTO orders (id, side, asset, status, client_ref, user_ref, quote_id, crypto_base, fiat_minor, rate,
           deposit_index, deposit_address, expires_at, created_at, updated_at)
         VALUES (?, 'sell', ?, 'awaiting_deposit', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      ).run(id, q.asset, clientRef, userRef, q.id, q.crypto_base, q.fiat_minor, q.rate, index, address,
        now() + config.sellWindowMin * MIN, now(), now());
      return view(getOrder(id));
    });
  }

  async function checkSell(o) {
    const a = anyAsset(o.asset);
    const c = chainOf(a);
    const t = now();
    const { seen: balLatest, confirmed: balConf } = await chain.getDeposit(a, o.deposit_address);

    const firstSeen = o.first_seen_at ?? (balLatest > 0n ? t : null);
    const late = firstSeen != null && firstSeen > o.expires_at;

    if (balConf === 0n || balLatest !== balConf) {
      // nothing yet, or a deposit is still confirming
      if (balLatest > 0n) {
        if (o.status === 'expired' || late) {
          updateOrder(o.id, { status: 'needs_review', first_seen_at: firstSeen, received_base: balLatest, note: 'Deposit arrived after the quote expired' });
        } else {
          updateOrder(o.id, { status: 'confirming', first_seen_at: firstSeen, received_base: balLatest, last_checked_at: t });
        }
      } else if (o.status === 'awaiting_deposit' && t > o.expires_at) {
        updateOrder(o.id, { status: 'expired', last_checked_at: t }, 'awaiting_deposit');
      } else {
        updateOrder(o.id, { last_checked_at: t });
      }
      return;
    }

    if (o.status === 'expired' || late) {
      updateOrder(o.id, { status: 'needs_review', first_seen_at: firstSeen, received_base: balConf, note: 'Deposit arrived after the quote expired' });
      return;
    }

    const expected = BigInt(o.crypto_base);
    const maxOk = (expected * BigInt(10_000 + config.sellOverpayBps)) / 10_000n;
    const fiat = fiatFromCrypto(balConf, a.decimals, o.rate, false);

    if (balConf > maxOk) {
      updateOrder(o.id, { status: 'needs_review', first_seen_at: firstSeen, received_base: balConf,
        note: `Overpaid: received ${formatUnits(balConf, a.decimals)}, expected ${formatUnits(expected, a.decimals)}` });
      return;
    }
    if (fiat < minFiat) {
      updateOrder(o.id, { status: 'needs_review', first_seen_at: firstSeen, received_base: balConf,
        note: `Received ${formatUnits(balConf, a.decimals)} — below minimum` });
      return;
    }
    // Credit what was actually received (handles small underpayments) at the locked rate.
    tx(() => {
      const n = updateOrder(o.id, {
        status: 'deposit_confirmed', received_base: balConf, fiat_minor: fiat, first_seen_at: firstSeen,
        sweep_status: 'pending', last_checked_at: t,
        note: balConf < expected ? `Underpaid: credited for ${formatUnits(balConf, a.decimals)}` : null,
      }, ['awaiting_deposit', 'confirming']);
      if (n === 1) enqueue('sell.credit', o);
    });
  }

  // ── workers ──────────────────────────────────────────────────────────
  async function each(rows, fn, label) {
    for (const r of rows) {
      try {
        await fn(r);
      } catch (e) {
        log.error(label, r.id, e.message);
      }
    }
  }

  async function tickBuys() {
    const rows = db.prepare("SELECT * FROM orders WHERE side='buy' AND status IN ('queued','signing','broadcast_pending','broadcast')").all();
    await each(rows, checkBuy, 'checkBuy');
  }

  async function tickSells() {
    const t = now();
    const active = db.prepare("SELECT * FROM orders WHERE side='sell' AND status IN ('awaiting_deposit','confirming')").all();
    // keep watching expired orders for 7 days (every 10 min) so late deposits are never lost
    const expired = db
      .prepare("SELECT * FROM orders WHERE side='sell' AND status='expired' AND expires_at > ? AND (last_checked_at IS NULL OR last_checked_at < ?)")
      .all(t - 7 * 24 * 60 * MIN, t - 10 * MIN);
    await each([...active, ...expired], checkSell, 'checkSell');
  }

  async function tickSweeps() {
    const rows = db
      .prepare("SELECT * FROM orders WHERE side='sell' AND sweep_status IN ('pending','gas_sent','token_sent') AND (sweep_updated_at IS NULL OR sweep_updated_at < ?)")
      .all(now() - 30_000);
    await each(rows, async (o) => {
      const a = anyAsset(o.asset);
      const state = { status: o.sweep_status, tx: o.sweep_tx, at: o.sweep_updated_at };
      const res = await chain.sweepStep(a, o.deposit_index, state);
      if (res.status !== state.status || res.tx !== state.tx) {
        updateOrder(o.id, { sweep_status: res.status, sweep_tx: res.tx ?? null, sweep_updated_at: now() });
      }
    }, 'sweep');
  }

  async function tickOutbox(send) {
    const rows = db.prepare('SELECT * FROM outbox WHERE delivered_at IS NULL AND next_at <= ? ORDER BY id LIMIT 25').all(now());
    for (const r of rows) {
      try {
        await send(JSON.parse(r.payload));
        tx(() => {
          db.prepare('UPDATE outbox SET delivered_at = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?').run(now(), r.id);
          if (r.type === 'sell.credit') updateOrder(r.order_id, { status: 'completed' }, 'deposit_confirmed');
          if (r.type === 'buy.refund') updateOrder(r.order_id, { refunded: 1 });
        });
      } catch (e) {
        const delay = Math.min(2 ** r.attempts * 10_000, 60 * MIN);
        db.prepare('UPDATE outbox SET attempts = attempts + 1, next_at = ?, last_error = ? WHERE id = ?').run(now() + delay, String(e.message).slice(0, 500), r.id);
      }
    }
  }

  // ── public / admin ───────────────────────────────────────────────────
  async function listAssets() {
    const out = [];
    for (const a of Object.values(reg.enabledAssets)) {
      let rates = null;
      try {
        const r = await pricing.getRates(a.key);
        rates = { buy: r.buy, sell: r.sell };
      } catch { /* price unavailable */ }
      out.push({ asset: a.key, symbol: a.symbol, name: a.name, network: reg.chains[a.chain].name, addressType: reg.chains[a.chain].family, displayDecimals: a.displayDecimals, rates });
    }
    return { currency: config.fiat, minFiat: config.minFiat, maxFiat: config.maxFiat, assets: out };
  }

  async function walletStatus() {
    const balances = [];
    const hotWallets = {};
    const seen = new Set();
    for (const a of Object.values(reg.enabledAssets)) {
      for (const x of [a, reg.nativeFor(a.chain)]) {
        if (seen.has(x.key)) continue;
        seen.add(x.key);
        try {
          const hot = chain.hotAddress(x.chain);
          hotWallets[reg.chains[x.chain].name] = hot;
          const b = await chain.getBalance(x, hot);
          balances.push({ asset: x.key, symbol: x.symbol, network: reg.chains[x.chain].name, address: hot, balance: formatUnits(b, x.decimals), reserved: formatUnits(await reservedFor(x.key), x.decimals) });
        } catch (e) {
          balances.push({ asset: x.key, error: e.message });
        }
      }
    }
    const counts = db.prepare('SELECT side, status, COUNT(*) AS n FROM orders GROUP BY side, status').all();
    const outboxPending = db.prepare('SELECT COUNT(*) AS n FROM outbox WHERE delivered_at IS NULL').get().n;
    return { hotWallets, balances, orders: counts, callbacksPending: outboxPending };
  }

  function listOrders({ status, side, limit = 50 } = {}) {
    const where = [];
    const params = [];
    if (status) { where.push('status = ?'); params.push(status); }
    if (side) { where.push('side = ?'); params.push(side); }
    params.push(Math.min(Number(limit) || 50, 500));
    const rows = db.prepare(`SELECT * FROM orders ${where.length ? 'WHERE ' + where.join(' AND ') : ''} ORDER BY created_at DESC LIMIT ?`).all(...params);
    return rows.map((o) => ({ ...view(o), error: o.error, userRef: o.user_ref, sweepStatus: o.sweep_status }));
  }

  function setManualRate({ asset: key, buyRate, sellRate, clear } = {}) {
    asset(key);
    if (clear) {
      db.prepare('DELETE FROM manual_rates WHERE asset = ?').run(key);
      return { asset: key, source: 'market' };
    }
    const b = toRateString(String(buyRate));
    const s = toRateString(String(sellRate));
    if (parseUnits(s, 8) >= parseUnits(b, 8)) throw new AppError('bad_rate', 'sellRate must be lower than buyRate');
    db.prepare('INSERT INTO manual_rates (asset, buy_rate, sell_rate, updated_at) VALUES (?,?,?,?) ON CONFLICT(asset) DO UPDATE SET buy_rate=excluded.buy_rate, sell_rate=excluded.sell_rate, updated_at=excluded.updated_at')
      .run(key, b, s, now());
    return { asset: key, buyRate: b, sellRate: s, source: 'manual' };
  }

  async function resolve(id, { action, fiatAmount, note, force } = {}) {
    const o = getOrder(id);
    if (!o) throw new AppError('not_found', 'Order not found', 404);
    if (o.status !== 'needs_review') throw new AppError('bad_state', `Order is ${o.status}, not needs_review`, 409);
    const a = anyAsset(o.asset);

    if (o.side === 'buy') {
      if (action === 'refund') {
        if (o.tx_hash) {
          const st = await chain.getTxStatus(a, { hash: o.tx_hash, raw: o.signed_tx, meta: JSON.parse(o.tx_meta || '{}') });
          if (st.state === 'confirmed' || st.state === 'pending') {
            throw new AppError('already_sent', 'This transaction is on-chain — do not refund', 409);
          }
          if (st.state === 'not_found' && !force) {
            throw new AppError('may_still_mine', 'Tx could still be mined. Wait until it is dropped/expired, or pass force:true', 409);
          }
        }
        return tx(() => {
          updateOrder(o.id, { status: 'failed', note: note ?? 'refunded by admin' }, 'needs_review');
          enqueue('buy.refund', o);
          return view(getOrder(o.id));
        });
      }
      if (action === 'complete') {
        return tx(() => {
          updateOrder(o.id, { status: 'completed', note: note ?? 'completed by admin' }, 'needs_review');
          enqueue('buy.completed', o);
          return view(getOrder(o.id));
        });
      }
    } else {
      if (action === 'credit') {
        const fiat = fiatAmount
          ? parseUnits(fiatAmount, FIAT_DP)
          : fiatFromCrypto(BigInt(o.received_base ?? 0), a.decimals, o.rate, false);
        if (fiat <= 0n) throw new AppError('bad_amount', 'Nothing to credit');
        return tx(() => {
          updateOrder(o.id, { status: 'deposit_confirmed', fiat_minor: fiat, sweep_status: 'pending', note: note ?? 'credited by admin' }, 'needs_review');
          enqueue('sell.credit', o);
          return view(getOrder(o.id));
        });
      }
      if (action === 'reject') {
        updateOrder(o.id, { status: 'rejected', note: note ?? 'rejected by admin' }, 'needs_review');
        return view(getOrder(o.id));
      }
    }
    throw new AppError('bad_action', o.side === 'buy' ? 'action must be refund or complete' : 'action must be credit or reject');
  }

  return {
    createQuote, getQuote, createBuyOrder, createSellOrder, processBuy,
    getOrder: (id) => { const o = view(getOrder(id)); if (!o) throw new AppError('not_found', 'Order not found', 404); return o; },
    getOrderByRef: (ref) => { const o = view(getByRef(ref)); if (!o) throw new AppError('not_found', 'Order not found', 404); return o; },
    tickBuys, tickSells, tickSweeps, tickOutbox,
    listAssets, walletStatus, listOrders, setManualRate, resolve,
  };
}

