/**
 * Render a PHP page of the License Manager with php-wasm.
 *
 * Usage: node render.cjs <page.php> [--no-ui] [--out FILE] [--session]
 *   --no-ui    delete includes/ui.php before rendering (partial deploy)
 *   --session  seed an admin + customer session before rendering
 */
const { PhpNode } = require('php-wasm/PhpNode.js');
const fs = require('fs');
const path = require('path');

const LM = path.join(__dirname, '..', 'LM');
const args = process.argv.slice(2);
const page = args[0] || 'index.php';
const noUi = args.includes('--no-ui');
const withSession = args.includes('--session');
const outIdx = args.indexOf('--out');
const outFile = outIdx >= 0 ? args[outIdx + 1] : '/tmp/render-' + page.replace(/\//g, '-').replace(/\.php$/, '.html');

const SKIP_COPY = new Set(['storage', 'uploads', 'docs', '.git']);

/** Mirror the LM tree into the wasm filesystem, minus runtime data. */
async function copyTree(php, src, dest) {
  for (const e of fs.readdirSync(src, { withFileTypes: true })) {
    if (SKIP_COPY.has(e.name)) continue;
    const full = path.join(src, e.name);
    if (e.isDirectory()) {
      try { await php.mkdir(dest + '/' + e.name); } catch (e) { /* already present */ }
      await copyTree(php, full, dest + '/' + e.name);
    } else if (e.isFile()) {
      await php.writeFile(dest + '/' + e.name, fs.readFileSync(full));
    }
  }
}

(async () => {
  const php = new PhpNode();
  await php.binary;
  let buf = '';
  php.onoutput = e => { buf += (e.detail || []).join(''); };

  for (const d of ['lm', 'lm/includes', 'lm/storage', 'lm/storage/logs', 'lm/assets', 'lm/admin', 'lm/user']) {
    try { await php.mkdir('/' + d); } catch (e) {}
  }

  await copyTree(php, LM, '/lm');

  if (noUi) await php.run("<?php @unlink('/lm/includes/ui.php');");

  // Simulate a partial upload: --stale <relpath> overwrites a file with an old
  // copy, --absent <relpath> deletes one entirely.
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--stale' && args[i + 1]) {
      await php.writeFile('/lm/' + args[i + 1], '<?php // previous release\n');
    }
    if (args[i] === '--absent' && args[i + 1]) {
      await php.run("<?php @unlink('/lm/" + args[i + 1] + "');");
    }
  }

  // php-wasm has no MySQL driver: a stub keeps GET pages working.
  await php.writeFile('/lm/db.php', '<?php $pdo = null;');
  await php.writeFile('/lm/.htaccess', fs.readFileSync(path.join(LM, '.htaccess')));
  await php.writeFile('/lm/settings.json', JSON.stringify({
    site_name: 'Philmore Licence Manager',
    site_url: 'https://manager.pmhserver.name.ng',
    admin_email: 'ops@philmore.test',
    updates_storage_dir: 'storage/updates',
  }));
  await php.writeFile('/lm/storage/app_secret.key', 'ab'.repeat(32));

  const target = '/lm/' + page.replace(/^\/+/, '');
  const sessionSeed = withSession
    ? "if (session_start()) { $_SESSION['admin_logged_in'] = true; $_SESSION['admin_username'] = 'admin'; $_SESSION['admin_id'] = 1; }"
    : '';

  await php.writeFile('/lm/render-case.php', `<?php
    @session_name('lm_session');
    @session_id('qa-render-session-0001');
    ${sessionSeed || '@session_start();'}
    $_SERVER['SCRIPT_FILENAME'] = ${JSON.stringify(target)};
    $_SERVER['SCRIPT_NAME'] = ${JSON.stringify('/' + page.replace(/^\/+/, ''))};
    $_SERVER['REQUEST_URI'] = ${JSON.stringify('/' + page.replace(/^\/+/, ''))};
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
    chdir(${JSON.stringify(path.posix.dirname(target))});
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    require ${JSON.stringify(target)};
  `);

  await php.run("<?php require '/lm/render-case.php';");
  fs.writeFileSync(outFile, buf);

  const diagnostics = (buf.match(/(Fatal error|Parse error|Warning|Notice)[^<\n]{0,160}/g) || []).slice(0, 5);
  console.log('rendered ' + page + (noUi ? ' [no ui.php]' : '') + (withSession ? ' [session]' : '')
    + ' → ' + outFile + ' (' + buf.length + ' bytes)');
  console.log(diagnostics.length ? 'DIAGNOSTICS:\n' + diagnostics.join('\n') : 'no PHP diagnostics');
})().catch(e => { console.error('FAILED', e); process.exit(1); });
