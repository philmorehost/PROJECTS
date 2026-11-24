import { createHmac, timingSafeEqual } from 'node:crypto';
import { ASSETS } from './assets.js';

const NETWORK_CHAIN_MAP = {
  TRC20: 'tron',
  TRON: 'tron',
  BEP20: 'bsc',
  BSC: 'bsc',
  ERC20: 'ethereum',
  ETH: 'ethereum',
  ETHEREUM: 'ethereum',
  POLYGON: 'polygon',
  MATIC: 'polygon',
  BTC: 'bitcoin',
  BITCOIN: 'bitcoin',
};

export function verifyCbsSignerRequest(secret, req, rawBody, { maxSkewSec = 300, nowSec = () => Math.floor(Date.now() / 1000) } = {}) {
  const ts = req.headers['x-cbs-timestamp'];
  const sig = req.headers['x-cbs-signature'];
  if (!secret || !ts || !sig || !/^\d+$/.test(String(ts)) || !/^[0-9a-f]{64}$/i.test(String(sig))) return false;
  if (Math.abs(nowSec() - Number(ts)) > maxSkewSec) return false;
  const path = new URL(req.url || '/', 'http://local').pathname;
  const canonical = `${ts}.${String(req.method || 'POST').toUpperCase()}.${path}.${rawBody}`;
  const expected = Buffer.from(createHmac('sha256', secret).update(canonical).digest('hex'), 'hex');
  const given = Buffer.from(String(sig).toLowerCase(), 'hex');
  return expected.length === given.length && timingSafeEqual(expected, given);
}

export function verifyCbsWatcherRequest(secret, req, rawBody, { maxSkewSec = 300, nowSec = () => Math.floor(Date.now() / 1000) } = {}) {
  const ts = req.headers['x-cbs-timestamp'];
  const sig = req.headers['x-cbs-signature'];
  if (!secret || !ts || !sig || !/^\d+$/.test(String(ts)) || !/^[0-9a-f]{64}$/i.test(String(sig))) return false;
  if (Math.abs(nowSec() - Number(ts)) > maxSkewSec) return false;
  const canonical = `${ts}\n${rawBody}`;
  const expected = Buffer.from(createHmac('sha256', secret).update(canonical).digest('hex'), 'hex');
  const given = Buffer.from(String(sig).toLowerCase(), 'hex');
  return expected.length === given.length && timingSafeEqual(expected, given);
}

export function resolveCbsAsset(registry, assetSymbol, network) {
  const sym = String(assetSymbol || '').trim().toUpperCase();
  const net = String(network || '').trim().toUpperCase();
  const chainKey = NETWORK_CHAIN_MAP[net];
  if (!sym || !chainKey) return null;
  const enabled = registry?.enabledAssets || ASSETS;
  const match = Object.values(enabled).find((a) => a.symbol === sym && a.chain === chainKey);
  return match || null;
}

export function units1e8ToAssetBase(units1e8, decimals) {
  const s = String(units1e8 ?? '').trim();
  if (!/^\d+$/.test(s)) throw new Error('invalid_units');
  const v = BigInt(s);
  if (v <= 0n) throw new Error('invalid_units');
  if (decimals >= 8) {
    return v * (10n ** BigInt(decimals - 8));
  }
  const factor = 10n ** BigInt(8 - decimals);
  if (v % factor !== 0n) throw new Error('precision_loss');
  return v / factor;
}

export function assetBaseToUnits1e8(baseAmount, decimals) {
  const v = BigInt(baseAmount);
  if (v < 0n) throw new Error('invalid_units');
  if (decimals >= 8) {
    return (v / (10n ** BigInt(decimals - 8))).toString();
  }
  return (v * (10n ** BigInt(8 - decimals))).toString();
}

export function createCbsBridge({ db, chain, config }) {
  const nowSec = () => Math.floor(Date.now() / 1000);

  function saveDispatch(orderId, requestId, payloadSha256, { outcome, provider_ref = '', tx_hash = '', error = '' }) {
    const t = nowSec();
    db.prepare(`
      INSERT INTO cbs_signer_dispatches(order_id, request_id, payload_sha256, outcome, provider_ref, tx_hash, error, created_at, updated_at)
      VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(order_id) DO UPDATE SET
        outcome = excluded.outcome,
        provider_ref = excluded.provider_ref,
        tx_hash = excluded.tx_hash,
        error = excluded.error,
        updated_at = excluded.updated_at
    `).run(orderId, requestId, payloadSha256, outcome, provider_ref, tx_hash, error, t, t);
    return { outcome, provider_ref, tx_hash, error };
  }

  async function handleSigner(body) {
    const orderId = Number(body?.order_id || 0);
    const requestId = String(body?.request_id || '');
    const payloadSha = String(body?.payload_sha256 || '');
    if (!Number.isInteger(orderId) || orderId <= 0 || !requestId || !/^[0-9a-f]{64}$/i.test(payloadSha)) {
      return { outcome: 'rejected_before_broadcast', provider_ref: '', tx_hash: '', error: 'invalid_request' };
    }

    const existing = db.prepare('SELECT * FROM cbs_signer_dispatches WHERE order_id = ?').get(orderId);
    if (existing) {
      if (existing.payload_sha256.toLowerCase() !== payloadSha.toLowerCase()) {
        return { outcome: 'rejected_before_broadcast', provider_ref: '', tx_hash: '', error: 'payload_conflict' };
      }
      return {
        outcome: existing.outcome,
        provider_ref: existing.provider_ref,
        tx_hash: existing.tx_hash,
        error: existing.error,
      };
    }

    const asset = resolveCbsAsset(config.registry, body?.asset, body?.network);
    if (!asset) {
      return saveDispatch(orderId, requestId, payloadSha, {
        outcome: 'rejected_before_broadcast',
        error: 'unsupported_asset_network',
      });
    }

    let destination;
    let baseAmount;
    try {
      destination = chain.normalizeAddress(asset.chain, String(body?.destination_address || ''));
      baseAmount = units1e8ToAssetBase(body?.crypto_units_1e8, asset.decimals);
    } catch (e) {
      return saveDispatch(orderId, requestId, payloadSha, {
        outcome: 'rejected_before_broadcast',
        error: e?.message || 'invalid_parameters',
      });
    }

    try {
      const hotAddr = chain.hotAddress(asset.chain);
      const balance = await chain.getBalance(asset, hotAddr);
      if (BigInt(balance) < baseAmount) {
        return saveDispatch(orderId, requestId, payloadSha, {
          outcome: 'rejected_before_broadcast',
          error: 'insufficient_hot_liquidity',
        });
      }
    } catch (e) {
      return saveDispatch(orderId, requestId, payloadSha, {
        outcome: 'unknown',
        error: e?.message || 'liquidity_check_failed',
      });
    }

    let signed;
    try {
      signed = await chain.signFromHot(asset, destination, baseAmount);
    } catch (e) {
      return saveDispatch(orderId, requestId, payloadSha, {
        outcome: 'rejected_before_broadcast',
        error: e?.message || 'sign_failed',
      });
    }

    // Record broadcast intent BEFORE attempting network broadcast so crashes/timeouts stay 'unknown'
    saveDispatch(orderId, requestId, payloadSha, {
      outcome: 'unknown',
      provider_ref: `cbs-${orderId}`,
      tx_hash: String(signed?.hash || ''),
      error: 'broadcast_pending',
    });

    try {
      const txHash = await chain.broadcast(asset.chain, signed.raw);
      return saveDispatch(orderId, requestId, payloadSha, {
        outcome: 'broadcast',
        provider_ref: `cbs-${orderId}`,
        tx_hash: String(txHash || signed?.hash || ''),
        error: '',
      });
    } catch (e) {
      return saveDispatch(orderId, requestId, payloadSha, {
        outcome: 'unknown',
        provider_ref: `cbs-${orderId}`,
        tx_hash: String(signed?.hash || ''),
        error: e?.message || 'broadcast_unknown',
      });
    }
  }

  async function handleWatcher(body) {
    const asset = resolveCbsAsset(config.registry, body?.asset, body?.network);
    if (!asset) {
      return { observed: false, error: 'unsupported_asset_network' };
    }
    let depositAddr;
    try {
      depositAddr = chain.normalizeAddress(asset.chain, String(body?.deposit_address || ''));
    } catch {
      return { observed: false, error: 'invalid_deposit_address' };
    }

    const dep = await chain.getDeposit(asset, depositAddr);
    const seen = BigInt(dep?.seen ?? 0n);
    const confirmed = BigInt(dep?.confirmed ?? 0n);
    if (seen <= 0n && confirmed <= 0n) {
      return { observed: false };
    }
    const reqConfs = Number(config.registry.chains[asset.chain]?.confirmations ?? 1);
    const observedUnits = assetBaseToUnits1e8(confirmed > 0n ? confirmed : seen, asset.decimals);
    return {
      observed: true,
      network: String(body?.network || ''),
      asset: String(body?.asset || asset.symbol),
      tx_hash: String(dep?.txHash || `dep-${orderIdFor(body)}-${depositAddr.slice(0, 10)}`),
      log_index: Number(dep?.logIndex ?? 0),
      observed_units_1e8: observedUnits,
      confirmations: confirmed > 0n ? reqConfs : 0,
      required_confirmations: reqConfs,
      reorg_detected: Boolean(dep?.reorgDetected),
    };
  }

  return { handleSigner, handleWatcher };
}

function orderIdFor(body) {
  const id = Number(body?.order_id || 0);
  return Number.isInteger(id) && id > 0 ? id : 0;
}
