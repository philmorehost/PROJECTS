import { createHmac, timingSafeEqual } from 'node:crypto';

// Request signature (PHP → service):  hex(HMAC_SHA256(secret, `${ts}.${METHOD}.${path}.${body}`))
// Callback signature (service → PHP): hex(HMAC_SHA256(secret, `${ts}.${body}`))

export const signRequest = (secret, ts, method, path, body) =>
  createHmac('sha256', secret).update(`${ts}.${method.toUpperCase()}.${path}.${body}`).digest('hex');

export const signCallback = (secret, ts, body) => createHmac('sha256', secret).update(`${ts}.${body}`).digest('hex');

export function verifyRequest(secret, req, rawBody, { maxSkewSec = 300, nowSec = () => Math.floor(Date.now() / 1000) } = {}) {
  const ts = req.headers['x-mz-timestamp'];
  const sig = req.headers['x-mz-signature'];
  if (!ts || !sig || !/^\d+$/.test(ts) || !/^[0-9a-f]{64}$/.test(sig)) return false;
  if (Math.abs(nowSec() - Number(ts)) > maxSkewSec) return false;
  const expected = Buffer.from(signRequest(secret, ts, req.method, req.url, rawBody), 'hex');
  const given = Buffer.from(sig, 'hex');
  return expected.length === given.length && timingSafeEqual(expected, given);
}
