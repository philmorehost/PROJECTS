/**
 * Routes every blockchain call to the adapter for that network family.
 * Adapters are loaded only for enabled networks, so you only need the
 * libraries for the coins you actually turn on.
 *
 * One seed phrase drives everything. Index 0 is the hot wallet on each network:
 *   EVM  (BSC/Ethereum/Polygon)  m/44'/60'/0'/0/i   0x…
 *   TRON                         m/44'/195'/0'/0/i  T…
 *   Bitcoin (native SegWit)      m/84'/0'/0'/0/i    bc1q…
 *
 * Adapter interface:
 *   hotAddress() · addressAt(i) · normalizeAddress(a)
 *   getBalance(asset, addr) → bigint
 *   getDeposit(asset, addr) → { seen, confirmed }  (bigints; confirmed = meets the network's confirmations)
 *   signFromHot(asset, to, amount) → { raw, hash, meta }   (signs only; nothing is sent)
 *   broadcast(raw) · release(meta)?
 *   getTxStatus(asset, { hash, raw, meta }) → { state: confirmed | pending | reverted | dropped | not_found }
 *       dropped   = can provably never be mined (safe to refund)
 *       not_found = not mined yet (rebroadcast and wait)
 *   sweepStep(asset, index, state) → { status, tx }
 */
export async function createChain({ config }) {
  const adapters = {};
  for (const [key, c] of Object.entries(config.registry.chains)) {
    const args = { config, chain: c, opts: config.chainOptions ?? {} };
    if (c.family === 'evm') adapters[key] = (await import('./chains/evm.js')).createEvmAdapter(args);
    else if (c.family === 'tron') adapters[key] = (await import('./chains/tron.js')).createTronAdapter(args);
    else if (c.family === 'btc') adapters[key] = (await import('./chains/btc.js')).createBtcAdapter(args);
    else throw new Error(`Unknown chain family ${c.family}`);
  }
  const A = (k) => {
    const a = adapters[k];
    if (!a) throw new Error(`Chain ${k} not enabled`);
    return a;
  };

  return {
    hotAddress: (chainKey) => A(chainKey).hotAddress(),
    depositAddress: (chainKey, index) => {
      if (!Number.isInteger(index) || index < 1) throw new Error('deposit index must be ≥ 1');
      return A(chainKey).addressAt(index);
    },
    normalizeAddress: (chainKey, a) => A(chainKey).normalizeAddress(a),
    getBalance: (asset, addr) => A(asset.chain).getBalance(asset, addr),
    getDeposit: (asset, addr) => A(asset.chain).getDeposit(asset, addr),
    signFromHot: (asset, to, amount) => A(asset.chain).signFromHot(asset, to, amount),
    broadcast: (chainKey, raw) => A(chainKey).broadcast(raw),
    release: (chainKey, meta) => A(chainKey).release?.(meta),
    getTxStatus: (asset, tx) => A(asset.chain).getTxStatus(asset, tx),
    sweepStep: (asset, index, state) => A(asset.chain).sweepStep(asset, index, state),
  };
}
