import http from 'node:http';
import { loadConfig } from './config.js';
import { openDb } from './db.js';
import { createChain } from './chain.js';
import { createPricing } from './pricing.js';
import { createOrderService, AppError } from './orders.js';
import { verifyRequest } from './auth.js';
import { createCallbackSender } from './callbacks.js';
import { createCbsBridge, verifyCbsSignerRequest, verifyCbsWatcherRequest } from './cbs-bridge.js';

const config = loadConfig();
const db = openDb(config.dbPath);
const chain = await createChain({ config });
const pricing = createPricing({ config, db });
const svc = createOrderService({ db, chain, pricing, config });
const cbsBridge = createCbsBridge({ db, chain, config });
const sendCallback = createCallbackSender({ url: config.callbackUrl, secret: config.callbackSecret });

// [method, pathRegex, auth, handler(match, body, query)]
const routes = [
  ['GET', /^\/health$/, null, () => ({ ok: true })],
  ['GET', /^\/v1\/assets$/, 'api', () => svc.listAssets()],
  ['POST', /^\/v1\/quotes$/, 'api', (m, b) => svc.createQuote(b)],
  ['GET', /^\/v1\/quotes\/([\w-]+)$/, 'api', (m) => svc.getQuote(m[1])],
  ['POST', /^\/v1\/orders\/buy$/, 'api', (m, b) => svc.createBuyOrder(b)],
  ['POST', /^\/v1\/orders\/sell$/, 'api', (m, b) => svc.createSellOrder(b)],
  ['GET', /^\/v1\/orders\/by-ref\/([\w\-:.]+)$/, 'api', (m) => svc.getOrderByRef(m[1])],
  ['GET', /^\/v1\/orders\/([\w-]+)$/, 'api', (m) => svc.getOrder(m[1])],
  ['POST', /^\/v1\/cbs\/signer$/, 'cbs_signer', (m, b) => cbsBridge.handleSigner(b)],
  ['POST', /^\/v1\/cbs\/watcher$/, 'cbs_watcher', (m, b) => cbsBridge.handleWatcher(b)],

  ['GET', /^\/v1\/admin\/wallet$/, 'admin', () => svc.walletStatus()],
  ['GET', /^\/v1\/admin\/orders$/, 'admin', (m, b, q) => svc.listOrders(Object.fromEntries(q))],
  ['POST', /^\/v1\/admin\/rates$/, 'admin', (m, b) => svc.setManualRate(b)],
  ['POST', /^\/v1\/admin\/orders\/([\w-]+)\/resolve$/, 'admin', (m, b) => svc.resolve(m[1], b)],
];

function reply(res, status, data) {
  const body = JSON.stringify(data);
  res.writeHead(status, { 'content-type': 'application/json', 'cache-control': 'no-store' });
  res.end(body);
}

const server = http.createServer(async (req, res) => {
  try {
    const chunks = [];
    let size = 0;
    for await (const c of req) {
      size += c.length;
      if (size > 64 * 1024) return reply(res, 413, { error: 'payload_too_large' });
      chunks.push(c);
    }
    const raw = Buffer.concat(chunks).toString('utf8');
    const url = new URL(req.url, 'http://local');

    for (const [method, re, auth, handler] of routes) {
      const m = url.pathname.match(re);
      if (!m || req.method !== method) continue;
      if (auth) {
        if (auth === 'cbs_signer') {
          if (!verifyCbsSignerRequest(config.cbsSignerSecret, req, raw)) return reply(res, 401, { error: 'unauthorized' });
        } else if (auth === 'cbs_watcher') {
          if (!verifyCbsWatcherRequest(config.cbsWatcherSecret, req, raw)) return reply(res, 401, { error: 'unauthorized' });
        } else {
          const secret = auth === 'admin' ? config.adminSecret : config.apiSecret;
          if (!verifyRequest(secret, req, raw)) return reply(res, 401, { error: 'unauthorized' });
        }
      }
      let body = {};
      if (raw) {
        try { body = JSON.parse(raw); } catch { return reply(res, 400, { error: 'bad_json' }); }
      }
      const data = await handler(m, body, url.searchParams);
      return reply(res, 200, data);
    }
    reply(res, 404, { error: 'not_found' });
  } catch (e) {
    if (e instanceof AppError) return reply(res, e.httpStatus, { error: e.code, message: e.message });
    console.error('unhandled', e);
    reply(res, 500, { error: 'internal', message: 'Something went wrong' });
  }
});

// background workers (each never overlaps itself)
function loop(name, ms, fn) {
  let busy = false;
  setInterval(async () => {
    if (busy) return;
    busy = true;
    try { await fn(); } catch (e) { console.error(`[${name}]`, e.message); } finally { busy = false; }
  }, ms);
}
loop('buys', 15_000, () => svc.tickBuys());
loop('sells', 20_000, () => svc.tickSells());
loop('sweeps', 60_000, () => svc.tickSweeps());
loop('callbacks', 10_000, () => svc.tickOutbox(sendCallback));

server.listen(config.port, config.host, () => {
  console.log(`FINTECHFLOW DGV7.0 crypto service on http://${config.host}:${config.port}`);
  for (const c of Object.values(config.registry.chains)) {
    console.log(`Hot wallet on ${c.name} (fund this): ${chain.hotAddress(c.key)}`);
  }
  console.log(`Assets: ${Object.keys(config.registry.enabledAssets).join(', ')}`);
});

for (const sig of ['SIGINT', 'SIGTERM']) {
  process.on(sig, () => {
    server.close(() => { db.close(); process.exit(0); });
    setTimeout(() => process.exit(0), 5000).unref();
  });
}
