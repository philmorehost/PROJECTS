// Exact decimal maths with BigInt — never use floating point for balances.

export const RATE_DP = 8; // rates stored with up to 8 decimal places
export const FIAT_DP = 2; // kobo / pence

export function parseUnits(value, decimals, { truncate = false } = {}) {
  const s = String(value ?? '').trim();
  if (!/^\d+(\.\d+)?$/.test(s)) throw new Error('Invalid amount');
  let [whole, frac = ''] = s.split('.');
  if (frac.length > decimals) {
    if (!truncate) throw new Error(`Too many decimal places (max ${decimals})`);
    frac = frac.slice(0, decimals);
  }
  return BigInt(whole) * 10n ** BigInt(decimals) + BigInt(frac.padEnd(decimals, '0') || '0');
}

export function formatUnits(value, decimals) {
  let v = BigInt(value);
  const neg = v < 0n;
  if (neg) v = -v;
  const base = 10n ** BigInt(decimals);
  const whole = v / base;
  const frac = decimals ? (v % base).toString().padStart(decimals, '0').replace(/0+$/, '') : '';
  return (neg ? '-' : '') + whole.toString() + (frac ? '.' + frac : '');
}

export function formatFixed(value, decimals, dp) {
  const s = formatUnits(value, decimals);
  const [w, f = ''] = s.split('.');
  return dp ? `${w}.${f.padEnd(dp, '0').slice(0, dp)}` : w;
}

/** Normalise a rate (number or string) to a canonical string with ≤ 8 dp. */
export function toRateString(rate) {
  if (typeof rate === 'number') {
    if (!Number.isFinite(rate) || rate <= 0) throw new Error('Invalid rate');
    rate = rate.toFixed(RATE_DP);
  }
  const scaled = parseUnits(rate, RATE_DP, { truncate: true });
  if (scaled <= 0n) throw new Error('Invalid rate');
  return formatUnits(scaled, RATE_DP);
}

function div(n, d, roundUp) {
  const q = n / d;
  return roundUp && n % d !== 0n ? q + 1n : q;
}

/** fiat minor units (kobo) for an amount of crypto (base units) at `rate` fiat per coin. */
export function fiatFromCrypto(cryptoBase, decimals, rate, roundUp = false) {
  const r = parseUnits(rate, RATE_DP, { truncate: true });
  return div(BigInt(cryptoBase) * r * 10n ** BigInt(FIAT_DP), 10n ** BigInt(decimals + RATE_DP), roundUp);
}

/** crypto base units for an amount of fiat minor units. */
export function cryptoFromFiat(fiatMinor, decimals, rate, roundUp = false) {
  const r = parseUnits(rate, RATE_DP, { truncate: true });
  return div(BigInt(fiatMinor) * 10n ** BigInt(decimals + RATE_DP), r * 10n ** BigInt(FIAT_DP), roundUp);
}

/** Round base units to `keepDp` decimal places (e.g. USDT with 18 decimals → 2 dp). */
export function roundBase(base, decimals, keepDp, roundUp = false) {
  if (keepDp >= decimals) return BigInt(base);
  const step = 10n ** BigInt(decimals - keepDp);
  return div(BigInt(base), step, roundUp) * step;
}
