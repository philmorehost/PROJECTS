/**
 * Boot a PHP-wasm runtime with a copy of LM/ in its filesystem.
 *
 * Two quirks of the embed SAPI shape this file:
 *   1. php-wasm has no MySQL driver, so db.php becomes a stub.
 *   2. `exit` (which lm_json() and every guard call) leaves the runtime unable
 *      to produce output again, so a probe that may exit gets a fresh runtime.
 *
 * A probe can claim any identity via SCRIPT_FILENAME without overwriting the
 * real file: the code always runs from /lm/.qa/probe-N.php.
 */
const { PhpNode } = require('php-wasm/PhpNode.js');
const fs = require('fs');
const path = require('path');

const LM = path.resolve(__dirname, '..', '..', 'LM');
const SKIP = new Set(['storage', 'uploads', 'docs', '.git', '.qa']);

async function copyTree(php, src, dest) {
  for (const e of fs.readdirSync(src, { withFileTypes: true })) {
    if (SKIP.has(e.name)) continue;
    const full = path.join(src, e.name);
    if (e.isDirectory()) {
      try { await php.mkdir(dest + '/' + e.name); } catch (err) { /* exists */ }
      await copyTree(php, full, dest + '/' + e.name);
    } else if (e.isFile()) {
      await php.writeFile(dest + '/' + e.name, fs.readFileSync(full));
    }
  }
}

async function makeRuntime(options) {
  const php = new PhpNode();
  await php.binary;

  const box = { php, out: '' };
  php.onoutput = e => { box.out += (e.detail || []).join(''); };

  for (const d of ['lm', 'lm/storage', 'lm/storage/logs', 'lm/.qa']) {
    try { await php.mkdir('/' + d); } catch (e) {}
  }

  if (!options.empty) await copyTree(php, LM, '/lm');

  // A stub, but one that lets a probe inject a fake PDO to exercise code
  // paths that touch the database (php-wasm has no MySQL driver).
  await php.writeFile('/lm/db.php', '<?php $pdo = isset($GLOBALS["qa_pdo"]) ? $GLOBALS["qa_pdo"] : null;');
  await php.writeFile('/lm/settings.json', JSON.stringify({
    site_name: 'Philmore Licence Manager',
    site_url: 'https://manager.pmhserver.name.ng',
    admin_email: 'ops@philmore.test',
  }));
  await php.writeFile('/lm/storage/app_secret.key', 'ab'.repeat(32));

  return box;
}

async function boot(options = {}) {
  let box = await makeRuntime(options);
  let dirty = false;
  let counter = 0;

  /** Run PHP code while pretending to be $script. */
  async function run(code, script = '/lm/probe.php', opts = {}) {
    if (dirty || opts.fresh) {
      box = await makeRuntime(options);
      dirty = false;
    }

    box.out = '';
    const probe = '/lm/.qa/probe-' + (++counter) + '.php';
    const body = code.replace(/^<\?php\s*/, '');
    const cwd = opts.chdir || path.posix.dirname(script);

    await box.php.writeFile(probe, `<?php
        $_SERVER['SCRIPT_FILENAME'] = ${JSON.stringify(script)};
        $_SERVER['SCRIPT_NAME'] = ${JSON.stringify('/' + script.replace(/^\/lm\//, ''))};
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
        error_reporting(E_ALL);
        ini_set('display_errors', ${opts.debug ? "'1'" : "'0'"});
        chdir(${JSON.stringify(cwd)});
        ${body}
    `);

    await box.php.run(`<?php require ${JSON.stringify(probe)};`);
    const out = box.out;

    // A probe that can end the request also ends the runtime's usefulness.
    if (/(^|[^a-z_])(exit|die)\s*\(|lm_json\s*\(|http_response_code\s*\(\s*4/i.test(body)) {
      dirty = true;
    } else if (out === '' && /echo|print|json_encode/.test(body)) {
      dirty = true;
    }

    return out;
  }

  /** Write a file into the runtime that is live right now (statics persist). */
  async function write(file, data) {
    await box.php.writeFile(file, data);
  }

  return { run, write, LM, php: () => box.php };
}

module.exports = { boot, LM };
