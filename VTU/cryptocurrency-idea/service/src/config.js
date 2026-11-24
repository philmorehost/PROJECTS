import { CHAINS, ASSETS, nativeAssetFor } from './assets.js';

const num = (v, d) => (v === undefined || v === '' ? d : Number(v));

function secret(env, name) {
  const v = env[name];
  if (!v || v.length < 32 || v.startsWith('change-me')) {
    throw new Error(`${name} must be set to a random string of at least 32 characters`);
  }
  return v;
}

export function buildRegistry(enabledKeys, rpcFor = () => 'http://unused', confFor = () => undefined) {
  const enabledAssets = {};
  const chains = {};
  for (const key of enabledKeys) {
    const a = ASSETS[key];
    if (!a) throw new Error(`Unknown asset "${key}". Options: ${Object.keys(ASSETS).join(', ')}`);
    enabledAssets[key] = a;
    if (!chains[a.chain]) {
      const c = CHAINS[a.chain];
      chains[a.chain] = { ...c, rpcUrl: rpcFor(a.chain), confirmations: confFor(a.chain) ?? c.confirmations };
    }
  }
  return { chains, assets: ASSETS, enabledAssets, nativeFor: nativeAssetFor };
}

export function loadConfig(env = process.env) {
  const enabled = (env.ENABLED_ASSETS || 'USDT_BSC,BNB').split(',').map((s) => s.trim()).filter(Boolean);
  const registry = buildRegistry(
    enabled,
    (chain) => {
      const rpc = env[`RPC_${chain.toUpperCase()}`] || CHAINS[chain].defaultRpc;
      if (!rpc) throw new Error(`RPC_${chain.toUpperCase()} is required for enabled assets on ${chain}`);
      return rpc.replace(/\/$/, '');
    },
    (chain) => (env[`CONFIRMATIONS_${chain.toUpperCase()}`] ? Number(env[`CONFIRMATIONS_${chain.toUpperCase()}`]) : undefined),
  );

  if (!env.MNEMONIC || env.MNEMONIC.split(/\s+/).length < 12) throw new Error('MNEMONIC must be a 12/24-word seed phrase');
  if (!env.CALLBACK_URL) throw new Error('CALLBACK_URL is required');

  const cfg = {
    host: env.HOST || '127.0.0.1',
    port: num(env.PORT, 8787),
    dbPath: env.DB_PATH || './data/fintechflow-crypto-prototype.db',
    mnemonic: env.MNEMONIC.trim(),
    apiSecret: secret(env, 'API_SECRET'),
    adminSecret: secret(env, 'ADMIN_SECRET'),
    callbackSecret: secret(env, 'CALLBACK_SECRET'),
    cbsSignerSecret: env.CBS_SIGNER_SECRET ? secret(env, 'CBS_SIGNER_SECRET') : secret(env, 'API_SECRET'),
    cbsWatcherSecret: env.CBS_WATCHER_SECRET ? secret(env, 'CBS_WATCHER_SECRET') : secret(env, 'CALLBACK_SECRET'),
    callbackUrl: env.CALLBACK_URL,
    fiat: (env.FIAT_CURRENCY || 'NGN').toUpperCase(),
    buySpreadBps: num(env.BUY_SPREAD_BPS, 150),
    sellSpreadBps: num(env.SELL_SPREAD_BPS, 150),
    coingeckoApiKey: env.COINGECKO_API_KEY || '',
    priceMaxAgeSec: num(env.PRICE_MAX_AGE_SEC, 300),
    minFiat: env.MIN_FIAT || '1000',
    maxFiat: env.MAX_FIAT || '2000000',
    quoteTtlSec: num(env.QUOTE_TTL_SEC, 120),
    sellWindowMin: num(env.SELL_WINDOW_MIN, 60),
    sellOverpayBps: num(env.SELL_OVERPAY_BPS, 200),
    buyStuckMinutes: num(env.BUY_STUCK_MINUTES, 30),
    chainOptions: {
      tronApiKey: env.TRON_API_KEY || '',
      tronFeeLimitSun: num(env.TRON_FEE_LIMIT_TRX, 100) * 1_000_000,
      tronMinHotTrx: num(env.TRON_MIN_HOT_TRX, 30) * 1_000_000,
      tronSweepTrx: num(env.TRON_SWEEP_TRX, 35) * 1_000_000,
      btcFeeLevel: env.BTC_FEE_LEVEL || 'halfHourFee',
    },
    registry,
  };
  if (new Set([cfg.apiSecret, cfg.adminSecret, cfg.callbackSecret]).size !== 3) {
    throw new Error('API_SECRET, ADMIN_SECRET and CALLBACK_SECRET must all be different');
  }
  return cfg;
}
