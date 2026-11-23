// Pure Bitcoin helpers (no libraries) — unit-tested.

export const DUST = 546; // sats
const IN_VB = 68; // P2WPKH input
const CHANGE_VB = 31; // P2WPKH output
const DEST_VB = 43; // worst case (P2TR / P2WSH) so fees are never short
const OVERHEAD_VB = 11;

export function estimateVsize(nIn, { change = true, destOutputs = 1 } = {}) {
  return Math.ceil(OVERHEAD_VB + IN_VB * nIn + DEST_VB * destOutputs + (change ? CHANGE_VB : 0));
}

/**
 * Choose UTXOs to pay `amount` sats at `feeRate` sat/vB.
 * Prefers confirmed, then largest coins. Change below dust is added to the fee.
 * @returns {{ inputs: Array, fee: number, change: number }}
 */
export function selectCoins(utxos, amount, feeRate) {
  if (!(amount >= DUST)) throw new Error(`Amount below dust limit (${DUST} sats)`);
  const sorted = [...utxos].sort(
    (a, b) => Number(!!b.status?.confirmed) - Number(!!a.status?.confirmed) || b.value - a.value,
  );
  const inputs = [];
  let total = 0;
  for (const u of sorted) {
    inputs.push(u);
    total += u.value;
    const feeWithChange = Math.ceil(feeRate * estimateVsize(inputs.length, { change: true }));
    if (total >= amount + feeWithChange) {
      const change = total - amount - feeWithChange;
      if (change >= DUST) return { inputs, fee: feeWithChange, change };
      return { inputs, fee: total - amount, change: 0 };
    }
    const feeNoChange = Math.ceil(feeRate * estimateVsize(inputs.length, { change: false }));
    if (total >= amount + feeNoChange) return { inputs, fee: total - amount, change: 0 };
  }
  throw new Error('hot wallet has insufficient BTC (including network fee)');
}

/** Amount left after paying to sweep all `utxos` into one output. */
export function sweepAmount(utxos, feeRate) {
  const total = utxos.reduce((s, u) => s + u.value, 0);
  const fee = Math.ceil(feeRate * (OVERHEAD_VB + IN_VB * utxos.length + CHANGE_VB));
  const out = total - fee;
  return out >= DUST * 2 ? { out, fee } : null;
}
