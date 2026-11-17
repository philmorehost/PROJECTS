/**
 * Live Preview HTTP Server for BLOCKPAYNE (runs real PHP 8.4 + shared PGlite SQL on 0.0.0.0:8080).
 * Keeps the repository working tree completely clean (no .env or lockfiles written to host disk).
 */
import http from 'node:http';
import { readdir, readFile } from 'node:fs/promises';
import { join, relative, dirname, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

process.on('unhandledRejection', (err) => {
  console.error('[Preview UnhandledRejection]', err);
});

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');

const { PGlite } = require('@electric-sql/pglite');
const { PhpNode } = require('php-wasm/PhpNode.mjs');

const pgInstance = new PGlite();
await pgInstance.waitReady;
pgInstance.close = async () => {};
function SharedPGlite() {
  return pgInstance;
}

globalThis.phpSettings = { ...(globalThis.phpSettings ?? {}), PGlite: SharedPGlite };

const MIME = {
  '.css': 'text/css; charset=UTF-8',
  '.js': 'application/javascript; charset=UTF-8',
  '.svg': 'image/svg+xml; charset=UTF-8',
  '.json': 'application/json; charset=UTF-8',
  '.webmanifest': 'application/manifest+json; charset=UTF-8',
  '.png': 'image/png',
  '.ico': 'image/x-icon',
};

const SKIP = new Set(['node_modules', '.git', '.preview-data']);

async function walk(dir, out = []) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) await walk(full, out);
    else if (/\.(php|sql|css|js|svg|json|webmanifest)$/.test(entry.name) || entry.name === 'artisan') out.push(full);
  }
  return out;
}

const cachedFiles = [];
for (const file of (await walk(root)).sort()) {
  cachedFiles.push([relative(root, file), await readFile(file, 'utf8')]);
}

const persistentStorageFiles = new Map();

const runtime = new PhpNode({ version: '8.4', PGlite: SharedPGlite });
let currentOut = '';
runtime.addEventListener('output', (e) => { currentOut += e.detail[0] ?? ''; });
runtime.addEventListener('error', (e) => { console.error('[PHP]', String(e.detail?.[0] ?? '')); });

async function prepareFreshRuntime() {
  await runtime.refresh();
  const made = new Set();
  const ensureDir = async (dir) => {
    let cur = '';
    for (const part of dir.split('/').filter(Boolean)) {
      cur += '/' + part;
      if (made.has(cur)) continue;
      try { await runtime.mkdir(cur); } catch {}
      made.add(cur);
    }
  };

  for (const [rel, content] of cachedFiles) {
    const target = '/src/' + rel;
    await ensureDir(target.split('/').slice(0, -1).join('/'));
    await runtime.writeFile(target, content);
  }
  for (const d of ['/src/storage', '/src/storage/cache', '/src/storage/sessions', '/src/storage/logs', '/tmp']) {
    await ensureDir(d);
  }
  for (const [vfsPath, content] of persistentStorageFiles.entries()) {
    await ensureDir(vfsPath.split('/').slice(0, -1).join('/'));
    await runtime.writeFile(vfsPath, content);
  }
}

async function capturePersistentVfsFiles() {
  for (const p of ['/src/.env', '/src/storage/installed', '/src/storage/license.php', '/src/storage/license-cache.json', '/src/storage/cache/favicon.svg']) {
    try {
      const raw = await runtime.readFile(p);
      const str = typeof raw === 'string' ? raw : new TextDecoder().decode(raw);
      persistentStorageFiles.set(p, str);
    } catch {}
  }
}

// Initialize the installed gateway instance via the real 4-stage InstallController
await prepareFreshRuntime();
currentOut = '';
await runtime.run(`<?php
require_once '/src/app/bootstrap.php';
use App\\Support\\Env;
use App\\Support\\Database;
use App\\Services\\LicenseService;
use App\\Http\\Controllers\\InstallController;
use App\\Http\\Controllers\\GatewayController;

Env::setRuntime('DB_CONNECTION', 'pglite');
Env::setRuntime('DB_DATABASE', 'memory://');

LicenseService::setTransport(static fn(string $key, string $domain): array => [
    'code'  => 200,
    'body'  => '{"status":1,"message":"License is valid."}',
    'error' => '',
]);

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '102.89.33.10';
$_SESSION = [];

$inst = new InstallController();
$inst->submitStage(1, ['license_key' => 'BP-LIVE-KEY-2026']);
$inst->submitStage(2, ['db_driver' => 'pglite', 'db_database' => 'memory://']);
$inst->submitStage(3, [
    'site_title'          => 'BlockPayne',
    'brand_color'         => '#10b981',
    'gateway_fee_percent' => '1.00',
    'admin_name'          => 'Platform Admin',
    'admin_email'         => 'admin@blockpayne.com',
    'admin_password'      => 'AdminPass123!',
]);

$pdo = Database::connection();
$admin = $pdo->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch();
$gw = new GatewayController();
$zpub = 'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs';
$gw->addWallet($admin, ['coin_symbol' => 'BTC', 'wallet_label' => 'Ledger Main BTC', 'xpub_key' => $zpub]);
$gw->addWallet($admin, ['coin_symbol' => 'LTC', 'wallet_label' => 'Litecoin SegWit', 'xpub_key' => $zpub]);
$gw->addWallet($admin, ['coin_symbol' => 'USDT-TRC20', 'wallet_label' => 'Tron USDT Vault', 'xpub_key' => $zpub]);
$gw->createPaymentLink($admin, ['title' => 'Accept Crypto Payment', 'fiat_amount' => 25.00, 'fiat_currency' => 'USD']);
echo "READY_OK\\n";
`);
await capturePersistentVfsFiles();
console.log('[Preview Init]', currentOut.trim());

const sessionStore = new Map();

let queue = Promise.resolve();
function enqueue(fn) {
  const next = queue.then(fn, fn);
  queue = next.then(() => {}, () => {});
  return next;
}

function parseCookies(cookieHeader) {
  const out = {};
  if (!cookieHeader) return out;
  for (const pair of cookieHeader.split(';')) {
    const idx = pair.indexOf('=');
    if (idx > 0) {
      out[pair.slice(0, idx).trim()] = decodeURIComponent(pair.slice(idx + 1).trim());
    }
  }
  return out;
}

const PORT = Number(process.env.PORT || 8080);

const server = http.createServer((req, res) => {
  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', () => {
    const rawBody = Buffer.concat(chunks).toString('utf8');
    enqueue(() => handleRequest(req, res, rawBody)).catch((err) => {
      console.error('[Preview Request Error]', err);
      if (!res.headersSent) {
        res.writeHead(500, { 'Content-Type': 'text/plain; charset=UTF-8' });
      }
      res.end('Internal preview error');
    });
  });
});

async function handleRequest(req, res, rawBody) {
  const host = req.headers.host || `localhost:${PORT}`;
  const fullUrl = new URL(req.url || '/', `http://${host}`);
  const pathname = fullUrl.pathname;

  if (/^\/(app|bin|database|resources|storage|tests|node_modules)(\/|$)/i.test(pathname)
      || /^\/(\.env|\.git|artisan|package\.json|version\.json)$/i.test(pathname)) {
    res.writeHead(403, { 'Content-Type': 'text/plain; charset=UTF-8' });
    res.end('403 Forbidden');
    return;
  }

  if (pathname.startsWith('/public/') || pathname === '/manifest.webmanifest' || pathname === '/sw.js') {
    try {
      const diskPath = join(root, pathname);
      const data = await readFile(diskPath);
      const ext = extname(pathname);
      res.writeHead(200, {
        'Content-Type': MIME[ext] || 'application/octet-stream',
        'Cache-Control': 'no-cache',
      });
      res.end(data);
      return;
    } catch {
      res.writeHead(404);
      res.end('Not found');
      return;
    }
  }

  const isHttpsProxy = String(req.headers['x-forwarded-proto'] || '').toLowerCase() === 'https';
  const cookies = parseCookies(req.headers.cookie || '');
  let sid = cookies.bp_preview_sid || '';
  let setCookieHeader = null;
  if (!sid || !sessionStore.has(sid)) {
    sid = Math.random().toString(36).slice(2) + Date.now().toString(36);
    sessionStore.set(sid, {});
    setCookieHeader = isHttpsProxy
      ? `bp_preview_sid=${sid}; Path=/; HttpOnly; SameSite=None; Secure`
      : `bp_preview_sid=${sid}; Path=/; HttpOnly; SameSite=Lax`;
  }

  const queryObj = {};
  for (const [k, v] of fullUrl.searchParams.entries()) {
    queryObj[k] = v;
  }

  const ct = String(req.headers['content-type'] || '');
  const postObj = {};
  if (ct.includes('application/x-www-form-urlencoded') && rawBody) {
    for (const [k, v] of new URLSearchParams(rawBody).entries()) {
      postObj[k] = v;
    }
  }

  const serverVars = {
    REQUEST_METHOD: req.method || 'GET',
    REQUEST_URI: req.url || '/',
    SCRIPT_NAME: '/index.php',
    HTTP_HOST: host,
    HTTPS: isHttpsProxy ? 'on' : '',
    HTTP_X_FORWARDED_PROTO: String(req.headers['x-forwarded-proto'] || ''),
    REMOTE_ADDR: '102.89.33.10',
    HTTP_ACCEPT: String(req.headers.accept || 'text/html'),
    CONTENT_TYPE: ct,
    HTTP_X_CSRF_TOKEN: String(req.headers['x-csrf-token'] || ''),
    HTTP_X_PAYHUB_SIGNATURE: String(req.headers['x-payhub-signature'] || ''),
    HTTP_AUTHORIZATION: String(req.headers.authorization || ''),
    HTTP_X_API_KEY: String(req.headers['x-api-key'] || ''),
  };

  let entryScript = '/src/index.php';
  if (pathname === '/install' || pathname === '/install/index.php') {
    entryScript = '/src/install/index.php';
  } else if (pathname === '/api' || pathname.startsWith('/api/')) {
    entryScript = '/src/api/index.php';
  }

  await prepareFreshRuntime();

  const payloadJson = JSON.stringify({
    server: serverVars,
    get: queryObj,
    post: postObj,
    session: sessionStore.get(sid) || {},
    rawBody,
    entry: entryScript,
  });

  await runtime.writeFile('/tmp/req.json', payloadJson);
  currentOut = '';

  await runtime.run(`<?php
require_once '/src/app/bootstrap.php';
use App\\Support\\Env;
use App\\Services\\LicenseService;

$req = json_decode(file_get_contents('/tmp/req.json'), true);
Env::setRuntime('DB_CONNECTION', 'pglite');
Env::setRuntime('DB_DATABASE', 'memory://');

LicenseService::setTransport(static fn(string $key, string $domain): array => [
    'code'  => 200,
    'body'  => '{"status":1,"message":"License is valid."}',
    'error' => '',
]);

$_SERVER = $req['server'];
$_GET = $req['get'];
$_POST = $req['post'];
$_SESSION = is_array($req['session']) ? $req['session'] : [];
$GLOBALS['BP_EXTERNAL_SESSION'] = true;
$GLOBALS['BP_ALLOW_IFRAME'] = true;
$GLOBALS['BP_META_FILE'] = '/tmp/res_meta.json';
$GLOBALS['BP_RAW_BODY'] = $req['rawBody'];
$GLOBALS['BP_REDIRECT_TO'] = null;
http_response_code(200);

chdir('/src');
require $req['entry'];
bp_halt();
`);

  await capturePersistentVfsFiles();

  let body = currentOut;
  let meta = { status: 200, redirect: null, session: sessionStore.get(sid) || {} };
  try {
    const rawMeta = await runtime.readFile('/tmp/res_meta.json');
    const metaStr = typeof rawMeta === 'string' ? rawMeta : new TextDecoder().decode(rawMeta);
    meta = JSON.parse(metaStr);
  } catch {}

  sessionStore.set(sid, meta.session || {});

  if (meta.bin_b64) {
    const binBuf = Buffer.from(meta.bin_b64, 'base64');
    const dlHeaders = {
      'Content-Type': meta.mime || 'application/zip',
      'Content-Disposition': `attachment; filename="${meta.filename || 'download.zip'}"`,
      'Content-Length': String(binBuf.length),
      'Cache-Control': 'no-store',
    };
    if (setCookieHeader) dlHeaders['Set-Cookie'] = setCookieHeader;
    res.writeHead(200, dlHeaders);
    res.end(binBuf);
    return;
  }

  const outHeaders = {};
  let status = Number(meta.status || 200);
  if (meta.redirect) {
    status = status >= 300 && status < 400 ? status : 302;
    outHeaders['Location'] = meta.redirect;
  }

  const trimmed = body.trimStart();
  if (pathname === '/favicon.svg' || trimmed.startsWith('<svg')) {
    outHeaders['Content-Type'] = 'image/svg+xml; charset=UTF-8';
  } else if (pathname.startsWith('/api/webhooks/blockbee') && trimmed === '*ok*') {
    outHeaders['Content-Type'] = 'text/plain; charset=UTF-8';
  } else if (pathname.startsWith('/api/') && (trimmed.startsWith('{') || trimmed.startsWith('['))) {
    outHeaders['Content-Type'] = 'application/json; charset=UTF-8';
  } else {
    outHeaders['Content-Type'] = 'text/html; charset=UTF-8';
  }

  if (setCookieHeader) {
    outHeaders['Set-Cookie'] = setCookieHeader;
  }

  res.writeHead(status, outHeaders);
  res.end(body);
}

server.listen(PORT, '0.0.0.0', () => {
  console.log(`BLOCKPAYNE Live Production Preview listening on http://0.0.0.0:${PORT}`);
});
