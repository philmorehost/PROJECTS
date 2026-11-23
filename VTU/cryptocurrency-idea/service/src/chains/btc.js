import * as bitcoin from 'bitcoinjs-lib';
import * as ecc from '@bitcoinerlab/secp256k1';
import { ethers } from 'ethers';
import { selectCoins, sweepAmount } from './btc-utils.js';

bitcoin.initEccLib(ecc); // lets us validate/send to Taproot (bc1p…) addresses

/**
 * Bitcoin (native SegWit, bc1q…), via any Esplora API (mempool.space by default).
 * - Coins are UTXOs: each send picks inputs and returns change to the hot wallet.
 * - A tx is "dropped" (safe to refund) only when one of its inputs was spent by a different tx.
 * - Deposits need no gas top-up: sweeping is one transaction.
 */
export function createBtcAdapter({ config, chain: c, opts }) {
  const network = bitcoin.networks.bitcoin;
  const base = c.rpcUrl;
  const hd = ethers.HDNodeWallet.fromPhrase(config.mnemonic, undefined, "m/84'/0'/0'/0");
  const keys = new Map();
  function keyAt(i) {
    if (!keys.has(i)) {
      const node = hd.deriveChild(i);
      const pubkey = Buffer.from(ethers.getBytes(node.publicKey)); // compressed, 33 bytes
      const sk = new ethers.SigningKey(node.privateKey);
      const pay = bitcoin.payments.p2wpkh({ pubkey, network });
      keys.set(i, {
        address: pay.address,
        output: pay.output,
        signer: {
          publicKey: pubkey,
          sign: (hash) => { // low-S ECDSA, 64-byte r||s
            const sig = sk.sign(hash);
            return Buffer.concat([Buffer.from(ethers.getBytes(sig.r)), Buffer.from(ethers.getBytes(sig.s))]);
          },
        },
      });
    }
    return keys.get(i);
  }
  const hot = () => keyAt(0);

  async function get(path, text = false) {
    const r = await fetch(base + path, { signal: AbortSignal.timeout(15_000) });
    if (r.status === 404) return null;
    if (!r.ok) throw new Error(`BTC API ${path} HTTP ${r.status}`);
    return text ? r.text() : r.json();
  }
  const utxos = async (addr) => (await get(`/address/${addr}/utxo`)) ?? [];
  const tip = async () => Number(await get('/blocks/tip/height', true));
  async function feeRate() {
    const f = await get('/v1/fees/recommended');
    const rate = Number(f?.[opts.btcFeeLevel] ?? f?.halfHourFee);
    if (!rate) throw new Error('BTC fee estimate unavailable');
    return Math.max(1, rate);
  }

  // inputs used by signed-but-unconfirmed txs, so two sends never pick the same coin
  const reserved = new Map();
  const reserve = (ins) => ins.forEach((i) => reserved.set(`${i.txid}:${i.vout}`, Date.now() + 2 * 3600_000));
  const isReserved = (u) => (reserved.get(`${u.txid}:${u.vout}`) ?? 0) > Date.now();

  let lock = Promise.resolve();
  const withLock = (fn) => {
    const run = lock.then(fn, fn);
    lock = run.catch(() => {});
    return run;
  };

  function build(inputs, from, outputs) {
    const psbt = new bitcoin.Psbt({ network });
    for (const u of inputs) psbt.addInput({ hash: u.txid, index: u.vout, witnessUtxo: { script: from.output, value: u.value } });
    for (const o of outputs) psbt.addOutput({ address: o.address, value: o.value });
    inputs.forEach((_, i) => psbt.signInput(i, from.signer));
    psbt.finalizeAllInputs();
    const tx = psbt.extractTransaction();
    return { raw: tx.toHex(), hash: tx.getId() };
  }

  async function send(raw) {
    const r = await fetch(`${base}/tx`, { method: 'POST', body: raw, signal: AbortSignal.timeout(20_000) });
    const text = await r.text();
    if (r.ok) return;
    if (/already in block chain|txn-already-known|txn-already-in-mempool/i.test(text)) return;
    throw new Error(`BTC broadcast: ${text.slice(0, 200)}`);
  }

  const self = {
    hotAddress: () => hot().address,
    addressAt: (i) => keyAt(i).address,
    normalizeAddress(a) {
      a = String(a).trim();
      bitcoin.address.toOutputScript(a, network); // throws on invalid / testnet addresses
      return /^bc1/i.test(a) ? a.toLowerCase() : a;
    },
    async getBalance(asset, address) {
      return BigInt((await utxos(address)).reduce((s, u) => s + u.value, 0));
    },
    async getDeposit(asset, address) {
      const [list, height] = await Promise.all([utxos(address), tip()]);
      let seen = 0;
      let confirmed = 0;
      for (const u of list) {
        seen += u.value;
        if (u.status?.confirmed && height - u.status.block_height + 1 >= c.confirmations) confirmed += u.value;
      }
      return { seen: BigInt(seen), confirmed: BigInt(confirmed) };
    },

    signFromHot(asset, to, amount) {
      return withLock(async () => {
        const h = hot();
        const available = (await utxos(h.address)).filter((u) => !isReserved(u));
        const sel = selectCoins(available, Number(amount), await feeRate());
        const outputs = [{ address: to, value: Number(amount) }];
        if (sel.change > 0) outputs.push({ address: h.address, value: sel.change });
        const { raw, hash } = build(sel.inputs, h, outputs);
        reserve(sel.inputs);
        return { raw, hash, meta: { inputs: sel.inputs.map((u) => ({ txid: u.txid, vout: u.vout })), fee: sel.fee } };
      });
    },

    release(meta) {
      for (const i of meta?.inputs ?? []) reserved.delete(`${i.txid}:${i.vout}`);
    },

    broadcast: send,

    async getTxStatus(asset, { hash, meta }) {
      const st = await get(`/tx/${hash}/status`);
      if (st) {
        if (!st.confirmed) return { state: 'pending' };
        return { state: (await tip()) - st.block_height + 1 >= c.confirmations ? 'confirmed' : 'pending' };
      }
      for (const i of meta?.inputs ?? []) {
        const os = await get(`/tx/${i.txid}/outspend/${i.vout}`);
        if (os?.spent && os.txid !== hash) return { state: 'dropped' };
      }
      return { state: 'not_found' };
    },

    async sweepStep(asset, index, state) {
      const d = keyAt(index);
      const list = (await utxos(d.address)).filter((u) => u.status?.confirmed);
      if (!list.length) return { status: 'done', tx: state.tx ?? null };
      const s = sweepAmount(list, await feeRate());
      if (!s) return { status: 'done', tx: state.tx ?? null }; // dust
      const { raw, hash } = build(list, d, [{ address: hot().address, value: s.out }]);
      await send(raw);
      return { status: 'done', tx: hash };
    },
  };
  return self;
}
