import { test } from 'node:test';
import assert from 'node:assert/strict';
import { selectCoins, sweepAmount, estimateVsize, DUST } from '../src/chains/btc-utils.js';

const u = (value, confirmed = true, id = Math.random().toString(16).slice(2)) => ({ txid: id, vout: 0, value, status: { confirmed } });

test('picks confirmed, largest coins first and returns change', () => {
  const r = selectCoins([u(10_000), u(500_000, false), u(300_000)], 100_000, 10);
  assert.equal(r.inputs.length, 1);
  assert.equal(r.inputs[0].value, 300_000, 'confirmed preferred over a bigger unconfirmed coin');
  assert.equal(r.fee, Math.ceil(10 * estimateVsize(1)));
  assert.equal(r.inputs[0].value, 100_000 + r.fee + r.change);
});

test('adds inputs when needed; dust change goes to fee', () => {
  const r = selectCoins([u(60_000), u(50_000)], 100_000, 20);
  assert.equal(r.inputs.length, 2);
  assert.equal(110_000, 100_000 + r.fee + r.change);
  const tight = selectCoins([u(101_500)], 100_000, 10); // leftover < fee-with-change + dust
  assert.equal(tight.change, 0);
  assert.equal(tight.fee, 1_500);
});

test('throws when funds cannot cover amount + fee, or amount is dust', () => {
  assert.throws(() => selectCoins([u(100_000)], 100_000, 5), /insufficient BTC/);
  assert.throws(() => selectCoins([u(100_000)], DUST - 1, 5), /dust/);
});

test('sweep amount subtracts fee and skips dust', () => {
  const s = sweepAmount([u(50_000), u(30_000)], 10);
  assert.equal(s.out + s.fee, 80_000);
  assert.equal(sweepAmount([u(1_500)], 10), null);
});
