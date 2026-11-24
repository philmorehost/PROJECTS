import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac, createHash } from 'node:crypto';
import { openDb } from '../src/db.js';
import { buildRegistry } from '../src/config.js';
import {
  createCbsBridge,
  verifyCbsSignerRequest,
  verifyCbsWatcherRequest,
  units1e8ToAssetBase,
  assetBaseToUnits1e8,
} from '../src/cbs-bridge.js';

function makeBridgeFixture(overrides = {}) {
  const db = openDb(':memory:');
  const registry = buildRegistry(['USDT_TRON', 'USDT_BSC', 'BTC']);
  const calls = { signed: 0, broadcast: 0 };
  const chain = {
    hotAddress: (c) => (c === 'tron' ? 'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb' : '0x1111111111111111111111111111111111111111'),
    normalizeAddress: (c, a) => {
      if (c === 'tron' && /^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(a)) return a;
      if (c === 'bsc' && /^0x[0-9a-fA-F]{40}$/.test(a)) return a.toLowerCase();
      throw new Error('invalid_address');
    },
    getBalance: async () => 500_000_000n,
    signFromHot: async () => {
      calls.signed++;
      return { raw: '0xsigned', hash: '0xhash_' + calls.signed, meta: {} };
    },
    broadcast: async () => {
      calls.broadcast++;
      return '0xtx_' + calls.broadcast;
    },
    getDeposit: async () => ({ seen: 25_000_000n, confirmed: 25_000_000n, txHash: '0xdep123', logIndex: 2 }),
    ...overrides,
  };
  const bridge = createCbsBridge({ db, chain, config: { registry } });
  return { db, bridge, calls };
}

test('CBS signer/watcher HMAC verification accepts valid signatures and rejects skew/tamper', () => {
  const secret = 'cbs-test-secret-with-at-least-32-chars';
  const now = 1700000000;
  const body = JSON.stringify({ order_id: 42 });
  const sigSigner = createHmac('sha256', secret).update(`${now}.POST./v1/cbs/signer.${body}`).digest('hex');
  assert.equal(verifyCbsSignerRequest(secret, {
    method: 'POST',
    url: '/v1/cbs/signer',
    headers: { 'x-cbs-timestamp': String(now), 'x-cbs-signature': sigSigner },
  }, body, { nowSec: () => now }), true);
  assert.equal(verifyCbsSignerRequest(secret, {
    method: 'POST',
    url: '/v1/cbs/signer',
    headers: { 'x-cbs-timestamp': String(now - 400), 'x-cbs-signature': sigSigner },
  }, body, { nowSec: () => now }), false);

  const sigWatcher = createHmac('sha256', secret).update(`${now}\n${body}`).digest('hex');
  assert.equal(verifyCbsWatcherRequest(secret, {
    method: 'POST',
    url: '/v1/cbs/watcher',
    headers: { 'x-cbs-timestamp': String(now), 'x-cbs-signature': sigWatcher },
  }, body, { nowSec: () => now }), true);
  assert.equal(verifyCbsWatcherRequest(secret, {
    method: 'POST',
    url: '/v1/cbs/watcher',
    headers: { 'x-cbs-timestamp': String(now), 'x-cbs-signature': sigWatcher },
  }, body + 'x', { nowSec: () => now }), false);
});

test('CBS precision conversion converts 1e8 units to/from chain decimals without rounding loss', () => {
  assert.equal(units1e8ToAssetBase('100000000', 6), 1_000_000n);
  assert.equal(units1e8ToAssetBase('100000000', 18), 1_000_000_000_000_000_000n);
  assert.throws(() => units1e8ToAssetBase('1', 6), /precision_loss/);
  assert.equal(assetBaseToUnits1e8(1_500_000n, 6), '150000000');
  assert.equal(assetBaseToUnits1e8(1_500_000_000_000_000_000n, 18), '150000000');
});

test('CBS signer broadcasts once, is idempotent on retry, and rejects payload conflicts', async () => {
  const { bridge, calls } = makeBridgeFixture();
  const sha = createHash('sha256').update('order-7').digest('hex');
  const req = {
    order_id: 7,
    request_id: 'req-7',
    asset: 'USDT',
    network: 'TRC20',
    destination_address: 'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb',
    crypto_units_1e8: '2500000000',
    payload_sha256: sha,
  };
  const r1 = await bridge.handleSigner(req);
  assert.equal(r1.outcome, 'broadcast');
  assert.equal(r1.tx_hash, '0xtx_1');
  assert.equal(calls.signed, 1);
  assert.equal(calls.broadcast, 1);

  const r2 = await bridge.handleSigner(req);
  assert.equal(r2.outcome, 'broadcast');
  assert.equal(r2.tx_hash, '0xtx_1');
  assert.equal(calls.signed, 1);
  assert.equal(calls.broadcast, 1);

  const rConflict = await bridge.handleSigner({
    ...req,
    payload_sha256: createHash('sha256').update('tampered').digest('hex'),
  });
  assert.equal(rConflict.outcome, 'rejected_before_broadcast');
  assert.equal(rConflict.error, 'payload_conflict');
});

test('CBS signer marks ambiguous broadcast failures as unknown and never double-broadcasts', async () => {
  const { bridge, calls } = makeBridgeFixture({
    broadcast: async () => {
      calls.broadcast++;
      throw new Error('rpc_timeout');
    },
  });
  const sha = createHash('sha256').update('order-8').digest('hex');
  const req = {
    order_id: 8,
    request_id: 'req-8',
    asset: 'USDT',
    network: 'TRC20',
    destination_address: 'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb',
    crypto_units_1e8: '1000000000',
    payload_sha256: sha,
  };
  const r1 = await bridge.handleSigner(req);
  assert.equal(r1.outcome, 'unknown');
  assert.equal(r1.error, 'rpc_timeout');

  const r2 = await bridge.handleSigner(req);
  assert.equal(r2.outcome, 'unknown');
  assert.equal(calls.broadcast, 1);
});

test('CBS signer rejects insufficient liquidity before broadcast and watcher returns normalized 1e8 deposit', async () => {
  const { bridge, calls } = makeBridgeFixture({
    getBalance: async () => 100n,
  });
  const sha = createHash('sha256').update('order-9').digest('hex');
  const r1 = await bridge.handleSigner({
    order_id: 9,
    request_id: 'req-9',
    asset: 'USDT',
    network: 'TRC20',
    destination_address: 'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb',
    crypto_units_1e8: '1000000000',
    payload_sha256: sha,
  });
  assert.equal(r1.outcome, 'rejected_before_broadcast');
  assert.equal(r1.error, 'insufficient_hot_liquidity');
  assert.equal(calls.signed, 0);

  const w = await bridge.handleWatcher({
    order_id: 10,
    request_id: 'req-10',
    asset: 'USDT',
    network: 'TRC20',
    deposit_address: 'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb',
    expected_units_1e8: '2500000000',
  });
  assert.equal(w.observed, true);
  assert.equal(w.observed_units_1e8, '2500000000');
  assert.equal(w.confirmations, 19);
  assert.equal(w.tx_hash, '0xdep123');
});
