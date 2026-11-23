import { TronWeb } from 'tronweb';
import { ethers } from 'ethers';

const SUN = 1_000_000n; // 1 TRX

/**
 * TRON: TRX and TRC20 tokens (USDT-TRC20).
 * - No nonces: each tx has an expiration. Once it passes without the tx in a block,
 *   the tx can never be mined → safe to refund.
 * - "Confirmed" = solidified block (irreversible, ~1 min), read from the walletsolidity API.
 * - TRC20 transfers burn TRX for energy. Keep TRX in the hot wallet (or stake/rent energy).
 */
export function createTronAdapter({ config, chain: c, opts }) {
  const host = c.rpcUrl;
  const headers = opts.tronApiKey ? { 'TRON-PRO-API-KEY': opts.tronApiKey } : {};
  const tw = new TronWeb({ fullHost: host, headers });
  const hd = ethers.HDNodeWallet.fromPhrase(config.mnemonic, undefined, "m/44'/195'/0'/0");
  const keys = new Map();
  const keyAt = (i) => {
    if (!keys.has(i)) {
      const pk = hd.deriveChild(i).privateKey.slice(2);
      keys.set(i, { pk, address: TronWeb.address.fromPrivateKey(pk) });
    }
    return keys.get(i);
  };
  const hot = () => keyAt(0).address;

  async function post(path, body) {
    const r = await fetch(`${host}${path}`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', ...headers },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(15_000),
    });
    if (!r.ok) throw new Error(`TRON ${path} HTTP ${r.status}`);
    return r.json();
  }
  const api = (solid) => (solid ? '/walletsolidity' : '/wallet');

  async function trxBalance(address, solid = false) {
    const a = await post(`${api(solid)}/getaccount`, { address, visible: true });
    return BigInt(a?.balance ?? 0);
  }
  async function tokenBalance(asset, address, solid = false) {
    const param = TronWeb.address.toHex(address).replace(/^41/i, '').padStart(64, '0');
    const r = await post(`${api(solid)}/triggerconstantcontract`, {
      owner_address: hot(), contract_address: asset.contract, function_selector: 'balanceOf(address)', parameter: param, visible: true,
    });
    const hex = r?.constant_result?.[0];
    if (hex === undefined) throw new Error(`TRON balanceOf failed: ${JSON.stringify(r?.result ?? r).slice(0, 200)}`);
    return hex ? BigInt('0x' + hex) : 0n;
  }
  const balance = (asset, address, solid) => (asset.native ? trxBalance(address, solid) : tokenBalance(asset, address, solid));

  async function buildAndSign(asset, from, fromPk, to, amount) {
    let tx;
    if (asset.native) {
      tx = await tw.transactionBuilder.sendTrx(to, Number(amount), from);
    } else {
      const r = await tw.transactionBuilder.triggerSmartContract(
        asset.contract, 'transfer(address,uint256)',
        { feeLimit: opts.tronFeeLimitSun, callValue: 0 },
        [{ type: 'address', value: to }, { type: 'uint256', value: amount.toString() }],
        from,
      );
      if (!r?.result?.result) throw new Error('TRON: could not build transfer');
      tx = r.transaction;
    }
    tx = await tw.transactionBuilder.extendExpiration(tx, 600); // valid for ~10 more minutes
    const signed = await tw.trx.sign(tx, fromPk);
    return { raw: JSON.stringify(signed), hash: signed.txID, meta: { expiration: signed.raw_data.expiration } };
  }

  async function send(raw) {
    const r = await tw.trx.sendRawTransaction(JSON.parse(raw));
    if (r?.result || r?.code === 'DUP_TRANSACTION_ERROR') return;
    const msg = r?.message ? Buffer.from(r.message, 'hex').toString() : '';
    throw new Error(`TRON broadcast ${r?.code ?? 'failed'} ${msg}`.trim());
  }

  const failed = (i) => i.result === 'FAILED' || (i.receipt?.result && i.receipt.result !== 'SUCCESS');

  const self = {
    hotAddress: hot,
    addressAt: (i) => keyAt(i).address,
    normalizeAddress(a) {
      if (typeof a !== 'string' || !a.startsWith('T') || !tw.isAddress(a)) throw new Error('invalid TRON address');
      return a;
    },
    getBalance: (asset, address) => balance(asset, address, false),

    async getDeposit(asset, address) {
      const [seen, confirmed] = await Promise.all([balance(asset, address, false), balance(asset, address, true)]);
      return { seen, confirmed };
    },

    async signFromHot(asset, to, amount) {
      const trx = await trxBalance(hot());
      const needTrx = asset.native ? amount + SUN : BigInt(opts.tronMinHotTrx);
      if (trx < needTrx) throw new Error('hot wallet has insufficient TRX for fees');
      if (!asset.native && (await tokenBalance(asset, hot())) < amount) throw new Error(`hot wallet has insufficient ${asset.symbol}`);
      return buildAndSign(asset, hot(), keyAt(0).pk, to, amount);
    },

    broadcast: send,

    async getTxStatus(asset, { hash, meta }) {
      const solid = await post('/walletsolidity/gettransactioninfobyid', { value: hash });
      if (solid?.id) return { state: failed(solid) ? 'reverted' : 'confirmed' };
      const pend = await post('/wallet/gettransactioninfobyid', { value: hash });
      if (pend?.id) return { state: failed(pend) ? 'reverted' : 'pending' };
      if (meta?.expiration && Date.now() > meta.expiration + 120_000) return { state: 'dropped' };
      return { state: 'not_found' };
    },

    /**
     * pending → (top up TRX for energy) gas_sent → token_sent → (return leftover TRX) done
     */
    async sweepStep(asset, index, state) {
      const d = keyAt(index);
      const recent = Date.now() - (state.at ?? 0) < 10 * 60_000;
      const sweepTrxBack = async () => {
        const t = await trxBalance(d.address);
        if (t <= SUN + SUN / 10n) return { status: 'done', tx: state.tx ?? null };
        const s = await buildAndSign(config.registry.nativeFor('tron'), d.address, d.pk, hot(), t - SUN);
        await send(s.raw);
        return { status: 'done', tx: s.hash };
      };

      if (asset.native) return sweepTrxBack();

      const tok = await tokenBalance(asset, d.address);
      if (tok === 0n) return state.status === 'pending' ? { status: 'done', tx: null } : sweepTrxBack();
      if (state.status === 'token_sent' && recent) return state; // wait for token transfer

      const trx = await trxBalance(d.address);
      const need = BigInt(opts.tronSweepTrx);
      if (trx < need) {
        if (state.status === 'gas_sent' && recent) return state;
        const s = await self.signFromHot(config.registry.nativeFor('tron'), d.address, need - trx);
        await send(s.raw);
        return { status: 'gas_sent', tx: s.hash };
      }
      const s = await buildAndSign(asset, d.address, d.pk, hot(), tok);
      await send(s.raw);
      return { status: 'token_sent', tx: s.hash };
    },
  };
  return self;
}
