import { toRateString } from './money.js';

/**
 * Rates are "fiat per 1 coin". Manual rates (set by admin) always win over market rates.
 * Buy price  = market × (1 + BUY_SPREAD)   — what the user pays you
 * Sell price = market × (1 − SELL_SPREAD)  — what you pay the user
 */
export function createPricing({ config, db, fetchImpl = globalThis.fetch, now = () => Date.now() }) {
  let cache = { at: 0, data: null };

  async function market() {
    if (cache.data && now() - cache.at < 60_000) return cache.data;
    const ids = [...new Set(Object.values(config.registry.enabledAssets).map((a) => a.coingeckoId))].join(',');
    const url = `https://api.coingecko.com/api/v3/simple/price?ids=${ids}&vs_currencies=${config.fiat.toLowerCase()}`;
    try {
      const headers = { accept: 'application/json' };
      if (config.coingeckoApiKey) headers['x-cg-demo-api-key'] = config.coingeckoApiKey;
      const res = await fetchImpl(url, { headers, signal: AbortSignal.timeout(8000) });
      if (!res.ok) throw new Error(`CoinGecko HTTP ${res.status}`);
      cache = { at: now(), data: await res.json() };
      return cache.data;
    } catch (err) {
      // Never trade on stale prices.
      if (cache.data && now() - cache.at < config.priceMaxAgeSec * 1000) return cache.data;
      throw new Error(`Price feed unavailable: ${err.message}`);
    }
  }

  async function getRates(assetKey) {
    const manual = db.prepare('SELECT buy_rate, sell_rate FROM manual_rates WHERE asset = ?').get(assetKey);
    if (manual) return { buy: manual.buy_rate, sell: manual.sell_rate, source: 'manual' };

    const asset = config.registry.assets[assetKey];
    const data = await market();
    const mid = data?.[asset.coingeckoId]?.[config.fiat.toLowerCase()];
    if (!mid) throw new Error(`No market price for ${assetKey}/${config.fiat}`);
    return {
      buy: toRateString(mid * (1 + config.buySpreadBps / 10_000)),
      sell: toRateString(mid * (1 - config.sellSpreadBps / 10_000)),
      mid: toRateString(mid),
      source: 'market',
    };
  }

  return { getRates };
}
