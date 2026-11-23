import { test } from 'node:test';
import assert from 'node:assert/strict';
import { openDb } from '../src/db.js';
import { createOrderService } from '../src/orders.js';
import { createPricing } from '../src/pricing.js';
import { buildRegistry } from '../src/config.js';
import { fiatFromCrypto, cryptoFromFiat, parseUnits, formatUnits } from '../src/money.js';
import { signRequest, verifyRequest } from '../src/auth.js';

const USDT = 10n ** 18n;
const HOT = '0x000000000000000000000000000000000000h0t0'.replace('h0t0', 'aaaa');

function fakeChain() {
  const seen = new Map();
  const confirmed = new Map();
  const status = new Map(); // hash -> state
  const st = { nonce: 0, signFail: false, broadcastFail: false, sent: [], broadcasts: 0 };
  const k = (a, addr) => `${a.key}:${addr.toLowerCase()}`;
  const HOTS = { bsc: HOT, tron: 'T' + 'H'.repeat(33), bitcoin: 'bc1qhotwallet0000000000000000000000000000' };
  const VALID = { bsc: /^0x[0-9a-fA-F]{40}$/, tron: /^T[1-9A-HJ-NP-Za-km-z]{33}$/, bitcoin: /^(bc1[0-9a-z]{25,87}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/ };
  return {
    st, status,
    set(a, addr, s, c = s) { seen.set(k(a, addr), s); confirmed.set(k(a, addr), c); },
    hotAddress: (chainKey) => HOTS[chainKey],
    depositAddress: (chainKey, i) => chainKey === 'tron' ? 'T' + String(i).padStart(33, 'D') : chainKey === 'bitcoin' ? 'bc1qdeposit' + String(i).padStart(30, '0') : '0x' + i.toString(16).padStart(40, '0'),
    normalizeAddress: (chainKey, a) => { if (!VALID[chainKey].test(a)) throw new Error('bad'); return a; },
    async getBalance(a, addr) { return seen.get(k(a, addr)) ?? 0n; },
    async getDeposit(a, addr) { return { seen: seen.get(k(a, addr)) ?? 0n, confirmed: confirmed.get(k(a, addr)) ?? 0n }; },
    async getTxStatus(a, { hash }) { return { state: status.get(hash) ?? 'not_found' }; },
    async signFromHot(a, to, amount) {
      if (st.signFail) throw new Error('insufficient gas');
      const nonce = st.nonce++;
      const hash = '0xhash' + nonce;
      st.sent.push({ to, amount, hash, asset: a.key });
      return { raw: '0xraw' + nonce, hash, meta: { nonce } };
    },
    async broadcast() { st.broadcasts++; if (st.broadcastFail) throw new Error('rpc down'); },
    async sweepStep() { return { status: 'done', tx: '0xsweep' }; },
  };
}

function setup({ rates = { buy: '1650.5', sell: '1600' } } = {}) {
  const db = openDb(':memory:');
  let t = 1_700_000_000_000;
  const config = {
    fiat: 'NGN', minFiat: '1000', maxFiat: '2000000', quoteTtlSec: 120, sellWindowMin: 60,
    sellOverpayBps: 200, buyStuckMinutes: 30, buySpreadBps: 150, sellSpreadBps: 150, priceMaxAgeSec: 300,
    registry: buildRegistry(['USDT_BSC', 'BNB', 'USDT_TRON', 'TRX', 'BTC']),
  };
  const pricing = { getRates: async () => rates };
  const chain = fakeChain();
  const log = { error() {}, warn() {}, log() {} };
  const svc = createOrderService({ db, chain, pricing, config, now: () => t, log });
  const a = config.registry.assets.USDT_BSC;
  return { db, svc, chain, a, advance: (ms) => (t += ms), now: () => t, config };
}

const tick = () => new Promise((r) => setImmediate(r));
const DEST = '0x' + 'b'.repeat(40);

test('money maths is exact', () => {
  assert.equal(fiatFromCrypto(100n * USDT, 18, '1650.5'), 16505000n); // ₦165,050.00
  const c = cryptoFromFiat(1_000_000n, 18, '1655.25'); // ₦10,000
  assert.equal(formatUnits(c, 18).slice(0, 8), '6.041383');
  assert.throws(() => parseUnits('1.2.3', 2));
  assert.throws(() => parseUnits('-5', 2));
});

test('buy quote by fiat rounds in your favour and respects limits', async () => {
  const { svc } = setup({ rates: { buy: '1655.25', sell: '1600' } });
  const q = await svc.createQuote({ side: 'buy', asset: 'USDT_BSC', fiatAmount: '10000' });
  assert.equal(q.cryptoAmount, '6.04');
  assert.equal(q.fiatAmount, '9997.71');
  await assert.rejects(svc.createQuote({ side: 'buy', asset: 'USDT_BSC', fiatAmount: '50' }), /Minimum/);
  await assert.rejects(svc.createQuote({ side: 'buy', asset: 'DOGE', fiatAmount: '5000' }), /not available/);
});

test('buy: sends from hot wallet, idempotent, completes after confirmations', async () => {
  const { svc, chain, a, db } = setup();
  chain.set(a, HOT, 1000n * USDT);
  const q = await svc.createQuote({ side: 'buy', asset: 'USDT_BSC', cryptoAmount: '100' });
  assert.equal(q.fiatAmount, '165050');

  const o1 = await svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'MZB1', userRef: '42', destination: DEST });
  const o2 = await svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'MZB1', userRef: '42', destination: DEST });
  assert.equal(o1.id, o2.id, 'same clientRef returns same order');
  await tick(); await tick();
  assert.equal(chain.st.sent.length, 1, 'crypto sent exactly once');
  assert.equal(svc.getOrder(o1.id).status, 'broadcast');

  // quote cannot be reused for another order
  await assert.rejects(svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'MZB2', userRef: '42', destination: DEST }), /already used/);

  chain.status.set('0xhash0', 'pending');
  await svc.tickBuys();
  assert.equal(svc.getOrder(o1.id).status, 'broadcast', 'waits for confirmations');
  chain.status.set('0xhash0', 'confirmed');
  await svc.tickBuys();
  assert.equal(svc.getOrder(o1.id).status, 'completed');
  const ev = db.prepare('SELECT type FROM outbox').all().map((r) => r.type);
  assert.deepEqual(ev, ['buy.completed']);
});

test('buy: refuses when hot wallet lacks liquidity (including reserved orders)', async () => {
  const { svc, chain, a } = setup();
  chain.set(a, HOT, 150n * USDT);
  chain.st.broadcastFail = false;
  const q1 = await svc.createQuote({ side: 'buy', asset: 'USDT_BSC', cryptoAmount: '100' });
  const q2 = await svc.createQuote({ side: 'buy', asset: 'USDT_BSC', cryptoAmount: '100' });
  // hold first order in queued state by not ticking
  const realSign = chain.signFromHot;
  chain.signFromHot = () => new Promise(() => {}); // hang
  await svc.createBuyOrder({ quoteId: q1.quoteId, clientRef: 'A1', userRef: '1', destination: DEST });
  await tick();
  await assert.rejects(svc.createBuyOrder({ quoteId: q2.quoteId, clientRef: 'A2', userRef: '1', destination: DEST }), /Not enough USDT/);
  chain.signFromHot = realSign;
});

test('buy: signing failure refunds; dropped tx refunds; nothing double-refunded', async () => {
  const { svc, chain, a, db } = setup();
  chain.set(a, HOT, 1000n * USDT);
  chain.st.signFail = true;
  const q = await svc.createQuote({ side: 'buy', asset: 'USDT_BSC', cryptoAmount: '10' });
  const o = await svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'F1', userRef: '7', destination: DEST });
  await tick(); await tick();
  assert.equal(svc.getOrder(o.id).status, 'failed');

  chain.st.signFail = false;
  const q2 = await svc.createQuote({ side: 'buy', asset: 'USDT_BSC', cryptoAmount: '10' });
  const o2 = await svc.createBuyOrder({ quoteId: q2.quoteId, clientRef: 'F2', userRef: '7', destination: DEST });
  await tick(); await tick();
  chain.status.set('0xhash0', 'dropped'); // nonce reused / expired / inputs spent → never mines
  await svc.tickBuys();
  await svc.tickBuys();
  assert.equal(svc.getOrder(o2.id).status, 'failed');
  const refunds = db.prepare("SELECT order_id FROM outbox WHERE type='buy.refund'").all();
  assert.equal(refunds.length, 2);
});

test('buy: a signature that arrives after the watchdog refund is never broadcast', async () => {
  const { svc, chain, a, advance } = setup();
  chain.set(a, HOT, 1000n * USDT);
  let release;
  const realSign = chain.signFromHot.bind(chain);
  chain.signFromHot = (...args) => new Promise((r) => { release = () => r(realSign(...args)); });
  let broadcasts = 0;
  chain.broadcast = async () => { broadcasts++; };

  const q = await svc.createQuote({ side: 'buy', asset: 'USDT_BSC', cryptoAmount: '10' });
  const o = await svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'W1', userRef: '1', destination: DEST });
  await tick();
  advance(3 * 60_000);
  await svc.tickBuys();
  assert.equal(svc.getOrder(o.id).status, 'failed');
  release();
  await tick(); await tick();
  assert.equal(broadcasts, 0);
  assert.equal(svc.getOrder(o.id).status, 'failed');
});

test('buy: rejects bad addresses', async () => {
  const { svc, chain, a } = setup();
  chain.set(a, HOT, 1000n * USDT);
  const q = await svc.createQuote({ side: 'buy', asset: 'USDT_BSC', cryptoAmount: '10' });
  await assert.rejects(svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'X', userRef: '1', destination: 'TXyz' }), /Invalid BNB Smart Chain \(BEP20\) wallet/);
  await assert.rejects(svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'X', userRef: '1', destination: HOT }), /Invalid wallet/);
});

test('sell: unique deposit address → confirming → credited → completed after callback', async () => {
  const { svc, chain, a, db } = setup();
  const q = await svc.createQuote({ side: 'sell', asset: 'USDT_BSC', cryptoAmount: '50' });
  assert.equal(q.fiatAmount, '80000');
  const o = await svc.createSellOrder({ quoteId: q.quoteId, clientRef: 'S1', userRef: '42' });
  const o2q = await svc.createQuote({ side: 'sell', asset: 'USDT_BSC', cryptoAmount: '50' });
  const o2 = await svc.createSellOrder({ quoteId: o2q.quoteId, clientRef: 'S2', userRef: '43' });
  assert.notEqual(o.depositAddress, o2.depositAddress, 'every sell gets its own address');
  assert.equal(o.status, 'awaiting_deposit');

  chain.set(a, o.depositAddress, 50n * USDT, 0n); // seen, not yet confirmed
  await svc.tickSells();
  assert.equal(svc.getOrder(o.id).status, 'confirming');

  chain.set(a, o.depositAddress, 50n * USDT, 50n * USDT);
  await svc.tickSells();
  const done = svc.getOrder(o.id);
  assert.equal(done.status, 'deposit_confirmed');
  assert.equal(done.fiatAmount, '80000');

  const delivered = [];
  await svc.tickOutbox(async (p) => delivered.push(p));
  assert.equal(delivered[0].type, 'sell.credit');
  assert.equal(delivered[0].userRef, '42');
  assert.equal(svc.getOrder(o.id).status, 'completed');
  await svc.tickSweeps();
  assert.equal(db.prepare('SELECT sweep_status FROM orders WHERE id=?').get(o.id).sweep_status, 'done');
});

test('sell: underpayment credits actual amount; overpayment and late deposits go to review', async () => {
  const { svc, chain, a, advance } = setup();
  const mk = async (ref) => svc.createSellOrder({ quoteId: (await svc.createQuote({ side: 'sell', asset: 'USDT_BSC', cryptoAmount: '50' })).quoteId, clientRef: ref, userRef: '1' });

  const under = await mk('U1');
  chain.set(a, under.depositAddress, 49n * USDT);
  const over = await mk('O1');
  chain.set(a, over.depositAddress, 60n * USDT);
  await svc.tickSells();
  assert.equal(svc.getOrder(under.id).status, 'deposit_confirmed');
  assert.equal(svc.getOrder(under.id).fiatAmount, '78400');
  assert.equal(svc.getOrder(over.id).status, 'needs_review');

  const late = await mk('L1');
  advance(61 * 60_000);
  await svc.tickSells();
  assert.equal(svc.getOrder(late.id).status, 'expired');
  advance(11 * 60_000);
  chain.set(a, late.depositAddress, 50n * USDT);
  await svc.tickSells();
  assert.equal(svc.getOrder(late.id).status, 'needs_review');

  const credited = await svc.resolve(late.id, { action: 'credit', fiatAmount: '75000' });
  assert.equal(credited.status, 'deposit_confirmed');
  assert.equal(credited.fiatAmount, '75000');
});

test('callback failures retry with backoff', async () => {
  const { svc, chain, a, db, advance } = setup();
  const q = await svc.createQuote({ side: 'sell', asset: 'USDT_BSC', cryptoAmount: '50' });
  const o = await svc.createSellOrder({ quoteId: q.quoteId, clientRef: 'R1', userRef: '1' });
  chain.set(a, o.depositAddress, 50n * USDT);
  await svc.tickSells();
  await svc.tickOutbox(async () => { throw new Error('site down'); });
  assert.equal(svc.getOrder(o.id).status, 'deposit_confirmed');
  let calls = 0;
  await svc.tickOutbox(async () => calls++);
  assert.equal(calls, 0, 'waits for backoff');
  advance(11_000);
  await svc.tickOutbox(async () => calls++);
  assert.equal(calls, 1);
  assert.equal(svc.getOrder(o.id).status, 'completed');
  assert.equal(db.prepare('SELECT attempts FROM outbox').get().attempts, 2);
});

test('manual rates override market and must keep a spread', async () => {
  const db = openDb(':memory:');
  const config = { fiat: 'NGN', buySpreadBps: 100, sellSpreadBps: 100, priceMaxAgeSec: 300, registry: buildRegistry(['USDT_BSC']) };
  const pricing = createPricing({ config, db, fetchImpl: async () => ({ ok: true, json: async () => ({ tether: { ngn: 1600 } }) }) });
  assert.deepEqual(await pricing.getRates('USDT_BSC'), { buy: '1616', sell: '1584', mid: '1600', source: 'market' });
  const svc = createOrderService({ db, chain: fakeChain(), pricing, config: { ...config, minFiat: '1', maxFiat: '9999999' } });
  svc.setManualRate({ asset: 'USDT_BSC', buyRate: '1700', sellRate: '1620' });
  assert.equal((await pricing.getRates('USDT_BSC')).source, 'manual');
  assert.throws(() => svc.setManualRate({ asset: 'USDT_BSC', buyRate: '1600', sellRate: '1700' }), /lower/);
});

test('TRON: USDT-TRC20 buy validates T-addresses and pays out on TRON', async () => {
  const { svc, chain, config } = setup();
  const usdt = config.registry.assets.USDT_TRON;
  chain.set(usdt, chain.hotAddress('tron'), 500n * 10n ** 6n);
  const q = await svc.createQuote({ side: 'buy', asset: 'USDT_TRON', cryptoAmount: '25' });
  assert.equal(q.addressType, 'tron');
  assert.equal(q.network, 'TRON (TRC20)');
  await assert.rejects(svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'T0', userRef: '1', destination: DEST }), /Invalid TRON \(TRC20\) wallet address/);
  const to = 'TQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE';
  const o = await svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'T1', userRef: '1', destination: to });
  await tick(); await tick();
  assert.equal(chain.st.sent[0].asset, 'USDT_TRON');
  assert.equal(chain.st.sent[0].amount, 25n * 10n ** 6n, 'USDT-TRC20 has 6 decimals');
  chain.status.set('0xhash0', 'confirmed');
  await svc.tickBuys();
  const done = svc.getOrder(o.id);
  assert.equal(done.status, 'completed');
  assert.equal(done.explorerUrl, 'https://tronscan.org/#/transaction/0xhash0');
});

test('BTC: sell gets a bc1 deposit address, credits after confirmations', async () => {
  const { svc, chain, config } = setup({ rates: { buy: '160000000', sell: '150000000' } });
  const btc = config.registry.assets.BTC;
  const q = await svc.createQuote({ side: 'sell', asset: 'BTC', fiatAmount: '300000' });
  assert.equal(q.cryptoAmount, '0.002');      // 300,000 / 150,000,000, rounded up to 6 dp
  assert.equal(q.fiatAmount, '300000');
  const o = await svc.createSellOrder({ quoteId: q.quoteId, clientRef: 'B1', userRef: '9' });
  assert.match(o.depositAddress, /^bc1q/);
  chain.set(btc, o.depositAddress, 200_000n, 0n);
  await svc.tickSells();
  assert.equal(svc.getOrder(o.id).status, 'confirming');
  chain.set(btc, o.depositAddress, 200_000n, 200_000n);
  await svc.tickSells();
  assert.equal(svc.getOrder(o.id).status, 'deposit_confirmed');
  assert.equal(svc.getOrder(o.id).fiatAmount, '300000');
});

test('BTC: buy rejects EVM/TRON addresses, accepts bc1', async () => {
  const { svc, chain, config } = setup({ rates: { buy: '160000000', sell: '150000000' } });
  chain.set(config.registry.assets.BTC, chain.hotAddress('bitcoin'), 10n ** 8n);
  const q = await svc.createQuote({ side: 'buy', asset: 'BTC', fiatAmount: '200000' });
  await assert.rejects(svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'BB', userRef: '1', destination: DEST }), /Invalid Bitcoin/);
  const o = await svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'BB', userRef: '1', destination: 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq' });
  assert.equal(o.addressType, 'btc');
});

test('not-yet-mined tx is rebroadcast, then sent to review if stuck', async () => {
  const { svc, chain, a, advance } = setup();
  chain.set(a, HOT, 1000n * USDT);
  const q = await svc.createQuote({ side: 'buy', asset: 'USDT_BSC', cryptoAmount: '10' });
  const o = await svc.createBuyOrder({ quoteId: q.quoteId, clientRef: 'NF', userRef: '1', destination: DEST });
  await tick(); await tick();
  const before = chain.st.broadcasts;
  await svc.tickBuys();
  assert.equal(chain.st.broadcasts, before + 1, 'rebroadcast same signed tx');
  advance(31 * 60_000);
  await svc.tickBuys();
  assert.equal(svc.getOrder(o.id).status, 'needs_review');
  await assert.rejects(svc.resolve(o.id, { action: 'refund' }), /could still be mined/);
  chain.status.set('0xhash0', 'confirmed');
  await assert.rejects(svc.resolve(o.id, { action: 'refund', force: true }), /do not refund/);
});

test('request signatures', () => {
  const secret = 'x'.repeat(40);
  const ts = String(Math.floor(Date.now() / 1000));
  const body = '{"a":1}';
  const req = { method: 'POST', url: '/v1/quotes', headers: { 'x-mz-timestamp': ts, 'x-mz-signature': signRequest(secret, ts, 'POST', '/v1/quotes', body) } };
  assert.equal(verifyRequest(secret, req, body), true);
  assert.equal(verifyRequest(secret, req, '{"a":2}'), false);
  assert.equal(verifyRequest('y'.repeat(40), req, body), false);
  const old = { ...req, headers: { ...req.headers, 'x-mz-timestamp': String(Number(ts) - 1000) } };
  assert.equal(verifyRequest(secret, old, body), false);
});
