// Render gate: runs every page under a real PHP (php-wasm, PHP 8.3) with a
// stubbed admin session and a null $pdo, then scans the rendered HTML for PHP
// source. A stray `?>` still parses, so syntax checks cannot see this class of
// bug — only rendering can.
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const ROOT = path.resolve(process.argv[2] || path.join(import.meta.dirname, '..', '..', 'LM'));

// Without a database, db.php prints its 503 "database is not answering" page and
// exits before the shell renders — so a leak in the console chrome would go
// unseen. Warn loudly instead of reporting a false all-clear.
if (process.env.LM_SKIP_DB_WARNING !== '1') {
  const db = path.join(ROOT, 'db.php');
  const connects = fs.existsSync(db) && /new\s+PDO\s*\(/.test(fs.readFileSync(db, 'utf8'));
  if (connects) {
    console.warn(
      'NOTE  ' + db + ' opens a real MySQL connection, so console pages stop at the\n' +
      '      503 "database is not answering" page and their shell is never rendered.\n' +
      '      For a meaningful run, point this at a copy whose db.php sets $pdo = null.\n'
    );
  }
}
const OUT = fs.mkdtempSync(path.join(os.tmpdir(), 'lmrender-'));
const SKIP_FILES = new Set(['db.php', 'cron.php', 'download.php', 'health.php', 'check-update.php', 'webhook.php', 'api.php', 'pull-worker.php', 'unsubscribe.php', 'order.php', 'request_license.php', 'success.php', 'success_manual.php', 'api-docs.php']);
const SKIP_DIRS = new Set(['includes', 'migrations', 'docs', 'assets', 'email_templates', 'storage', 'uploads', 'vendor', 'node_modules']);

function collect(dir, depth = 0) {
  const out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.name.startsWith('.') || SKIP_DIRS.has(e.name)) continue;
    const p = path.join(dir, e.name);
    if (e.isDirectory()) { if (depth < 2) out.push(...collect(p, depth + 1)); }
    else if (e.name.endsWith('.php') && !SKIP_FILES.has(e.name) && !/^api[-_.]/.test(e.name)) out.push(p);
  }
  return out;
}

// Lines that can only come from PHP source, never from a page's own markup.
const LEAK = [
  [/<\?php\b/, 'literal <?php in output'],
  [/\?>$/, 'literal ?> in output'],
  [/if \(!function_exists\('[A-Za-z_]+'\)\) \{/, 'function_exists() guard echoed'],
  [/^\s*\}\s*$/, 'stray closing brace'],
  [/^\s*\}\s*(?:else|elseif)\b.*\{\s*$/, 'stray brace branch'],
  [/^\s*(?:public|private|protected)\s+function\s+\w+/, 'method signature echoed'],
  [/^\s*(?:require|include)(?:_once)?\s*[(']/, 'require/include echoed'],
  [/^\s*\* @(?:param|return|package)/, 'docblock echoed'],
];

const STUB = `if (!function_exists('lm_session_start')) {
    // security.php guards this helper with function_exists(), so ours wins:
    // a real PHP session, contents under our control, no login round-trip.
    function lm_session_start($regenerate = false) {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_name('lm_session'); @session_start(); }
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = 'render-gate';
        $_SESSION['lm_admin'] = true;
        return true;
    }
}`;

// Page content that legitimately contains PHP source: <script>/<style> blocks,
// the operator manual's code samples, and the copy-to-clipboard payloads.
// Blank those spans (keeping newlines so line numbers stay true) before scanning.
function masked(html) {
  const blank = (m) => m.replace(/[^\n]/g, '');
  return html
    .replace(/<(script|style|pre|code|textarea)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, blank)
    .replace(/data-copy="[\s\S]*?"/gi, blank);
}

const runtimeId = await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 21 } });
const php = new PHP(runtimeId);
useHostFilesystem(php);
await php.run({ code: `<?php @mkdir(${JSON.stringify(ROOT)} . '/storage/sessions', 0777, true);` });

const files = collect(ROOT);
let leaked = 0, fatal = 0, rendered = 0, skipped = 0;

for (const f of files) {
  const rel = path.relative(ROOT, f).split(path.sep).join('/');
  const outFile = path.join(OUT, rel.replace(/\W+/g, '_') + '.json');
  const code = `<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
${STUB}
$_SERVER['DOCUMENT_ROOT'] = ${JSON.stringify(ROOT)};
$_SERVER['SCRIPT_NAME'] = '/${rel}';
$_SERVER['PHP_SELF'] = '/${rel}';
$_SERVER['REQUEST_URI'] = '/${rel}';
$_SERVER['SCRIPT_FILENAME'] = ${JSON.stringify(f)};
$_SERVER['REQUEST_METHOD'] = 'GET';
register_shutdown_function(function () {
    $body = '';
    while (ob_get_level() > 0) { $body = ob_get_clean() . $body; }
    $err = error_get_last();
    file_put_contents(${JSON.stringify(outFile)}, json_encode(array(
        'body' => $body,
        'fatal' => ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) ? $err['message'] : '',
    )));
});
ob_start();
chdir(${JSON.stringify(path.dirname(f))});
require ${JSON.stringify(f)};`;

  let headers = {};
  try {
    const res = await php.run({ code, $_SERVER: { DOCUMENT_ROOT: ROOT, HTTP_HOST: 'localhost', REQUEST_METHOD: 'GET', REMOTE_ADDR: '127.0.0.1', SERVER_PROTOCOL: 'HTTP/1.1', QUERY_STRING: '', SCRIPT_NAME: '/' + rel } });
    headers = res.headers || {};
  } catch (err) { /* fatal: the shutdown handler still wrote the body */ }

  if (!fs.existsSync(outFile)) { skipped++; console.log(`SKIP  ${rel}: no output captured`); continue; }
  const { body = '', fatal: fatalMsg = '' } = JSON.parse(fs.readFileSync(outFile, 'utf8'));

  if (!/<(?:html|body|div|main|form|table)\b/i.test(body)) {
    skipped++;
    if (process.env.RENDER_VERBOSE) console.log(`SKIP  ${rel}: nothing rendered (${body.trim().length} bytes${headers.location ? ', redirect ' + headers.location : ''})`);
    continue;
  }
  rendered++;

  const hits = [];
  masked(body).split('\n').forEach((line, i) => {
    for (const [re, why] of LEAK) {
      if (re.test(line)) { hits.push({ n: i + 1, s: line.trim().slice(0, 100), why }); break; }
    }
  });

  if (hits.length) {
    leaked++;
    console.log(`\nLEAK  ${rel}  — ${hits.length} line(s) of PHP source in the output`);
    hits.slice(0, 6).forEach((h) => console.log(`      ${rel}:${h.n}  ${h.s}   [${h.why}]`));
    if (hits.length > 6) console.log(`      ... ${hits.length - 6} more`);
  }

  if (fatalMsg) {
    fatal++;
    console.log(`FATAL ${rel}: ${fatalMsg.split('\n')[0].slice(0, 160)}`);
  }
}

fs.rmSync(OUT, { recursive: true, force: true });
console.log(`\n--- ${leaked} page(s) leaked PHP source, ${fatal} page(s) died, ${rendered} rendered, ${skipped} skipped, ${files.length} candidate(s)`);
process.exit(leaked || fatal ? 1 : 0);
