import { signCallback } from './auth.js';

/** Delivers events to the PHP site. The site must reply HTTP 200 with {"ok":true}. */
export function createCallbackSender({ url, secret, fetchImpl = globalThis.fetch }) {
  return async function send(payload) {
    const body = JSON.stringify(payload);
    const ts = String(Math.floor(Date.now() / 1000));
    const res = await fetchImpl(url, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-mz-timestamp': ts, 'x-mz-signature': signCallback(secret, ts, body) },
      body,
      signal: AbortSignal.timeout(15_000),
    });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* not json */ }
    if (!res.ok || !json || json.ok !== true) throw new Error(`callback HTTP ${res.status}: ${text.slice(0, 200)}`);
  };
}
