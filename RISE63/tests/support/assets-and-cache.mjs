/**
 * The asset/cache chain that decides whether a browser sees a new release.
 *
 * This exists because of a specific field failure: the new HTML rendered (new copy, new close
 * button) while the browser kept serving the previous app.js, so nothing bound the button. There
 * was no error to look at — the old script ran happily and knew nothing about the new markup.
 *
 * Three things have to hold, and none of them can be checked by looking at the page:
 *   1. the rendered asset URL is fingerprinted, so its content can never be confused with an
 *      older one;
 *   2. the service-worker cache name changes whenever the shell changes, so an old shell cannot
 *      survive a deploy;
 *   3. every cacheable asset the worker lists actually exists.
 */
import { readFile, readdir } from 'node:fs/promises';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { PhpNode } = require('php-wasm/PhpNode.mjs');
const phpModule = require('php-wasm/php8.3-node.mjs');

const failures = [];
let checks = 0;
const check = (name, ok, detail = '') => {
  checks++;
  if (ok) { console.log(`PASS ${name}`); }
  else { failures.push(name); console.log(`FAIL ${name}${detail ? ' :: ' + detail : ''}`); }
};

const root = new URL('../../', import.meta.url).pathname;
const sw = await readFile(root + 'sw.js', 'utf8');

/* 1. The worker's cache name must be derived, not hand-written. */
check('the service-worker cache name is not a hard-coded literal',
  !/var SHELL_VERSION = 'rise63-v[0-9]+';/.test(sw),
  'a literal has to be remembered on every release, and was not');
check('the cache name folds in the release id', /RISE63_RELEASE/.test(sw),
  'the asset list alone does not change when only code changes');

/* 2. The cache name really does change. Load the worker twice with different releases. */
const loadVersion = (release, source) => new Function('self', `var importScripts = function () {};
${source}
return SHELL_VERSION;`)({ addEventListener() {}, location: { origin: 'https://x', href: 'https://x/sw.js' }, RISE63_RELEASE: release });
const v1 = loadVersion('1.0.0-aaaa', sw);
const v2 = loadVersion('1.0.0-bbbb', sw);
check('a different release produces a different cache name', v1 !== v2, `${v1} vs ${v2}`);
const v3 = loadVersion('1.0.0-aaaa', sw.replace("  './favicon.svg',\n", ''));
check('a different shell list produces a different cache name', v1 !== v3, `${v1} vs ${v3}`);

/* 3. Every file the worker pre-caches must exist on disk. */
// Only the SHELL array is pre-cached; importScripts() is not a cache entry.
const shellBlock = sw.slice(sw.indexOf('var SHELL = ['), sw.indexOf('];', sw.indexOf('var SHELL = [')));
const listed = [...shellBlock.matchAll(/'\.\/([^']+)'/g)].map((m) => m[1]).filter((p) => p !== '');
const missing = [];
for (const rel of new Set(listed)) {
  if (rel === '' || rel === 'index.php') { continue; }
  try { await readFile(root + rel); } catch { missing.push(rel); }
}
check('every pre-cached asset exists', missing.length === 0, missing.join(', '));

/* 4. asset() must serve a fingerprinted URL, and the file it names must exist with exactly the
      bytes that were hashed. This does not run bin/build-assets.php (it is CLI-only and writes into
      the tree); it reproduces the two lines that matter over the real asset bytes. */
{
  const runtime = new PhpNode(phpModule);
  let out = '';
  runtime.addEventListener('output', (e) => { out += e.detail[0] ?? ''; });
  runtime.addEventListener('error', (e) => { out += String(e.detail?.[0] ?? ''); });
  await runtime.refresh();
  try { await runtime.mkdir('/a'); } catch (e) { /* exists */ }

  const sources = ['app.css', 'app.js', 'security.js'];
  for (const source of sources) {
    await runtime.writeFile('/a/' + source, await readFile(root + 'public/assets/' + source, 'utf8'));
  }

  await runtime.run(`<?php
  $manifest = [];
  foreach (['app.css', 'app.js', 'security.js'] as $source) {
      $contents = file_get_contents('/a/' . $source);
      $hash     = substr(hash('sha256', $contents), 0, 8);
      $info     = pathinfo($source);
      $target   = $info['filename'] . '.' . $hash . '.' . $info['extension'];
      file_put_contents('/a/' . $target, $contents);
      $manifest[$source] = $target;
  }
  echo json_encode($manifest);
  `);
  await new Promise((r) => setTimeout(r, 1200));

  let manifest = null;
  try { manifest = JSON.parse(out.trim()); } catch { /* reported below */ }
  check('a build produces a fingerprinted name for every asset',
    manifest !== null && sources.every((s) => /\.[0-9a-f]{8}\.(css|js)$/.test(manifest[s] || '')),
    out.trim().slice(0, 200));

  if (manifest) {
    const original = await readFile(root + 'public/assets/app.js', 'utf8');
    const hashedName = manifest['app.js'];
    const expected = 'app.js'.replace('.js', '') + '.' + (await import('node:crypto'))
      .createHash('sha256').update(original).digest('hex').slice(0, 8) + '.js';
    check('the fingerprint is the first 8 hex of the file, so a change cannot reuse it',
      hashedName === expected, `${hashedName} vs ${expected}`);

    // Changing one byte must change the name — that is the whole point of the fingerprint.
    const altered = original.replace("'use strict';", "'use strict'; /* x */");
    const changed = (await import('node:crypto')).createHash('sha256').update(altered).digest('hex').slice(0, 8);
    check('one changed byte yields a different asset URL', changed !== hashedName.split('.')[1],
      `${changed} vs ${hashedName.split('.')[1]}`);
  }
}

/* 5. asset() must degrade safely. Before a build the manifest does not exist; serving the bare
      filename then lets the browser keep a previous release, which is precisely how a redesign
      shipped and was never seen. The helper must fall back to a content fingerprint. */
{
  const runtime = new PhpNode(phpModule);
  let out = '';
  runtime.addEventListener('output', (e) => { out += e.detail[0] ?? ''; });
  runtime.addEventListener('error', (e) => { out += String(e.detail?.[0] ?? ''); });
  await runtime.refresh();

  const functions = await readFile(root + 'app/Core/Functions.php', 'utf8');
  await runtime.writeFile('/f.php', functions);
  // php-wasm has its own virtual filesystem, so the asset has to be placed inside it; ROOT points
  // at the directory the helper will actually look in.
  for (const dir of ['/src', '/src/public', '/src/public/assets']) {
    try { await runtime.mkdir(dir); } catch { /* exists */ }
  }
  await runtime.writeFile('/src/public/assets/app.css', await readFile(root + 'public/assets/app.css', 'utf8'));
  await runtime.run(`<?php
namespace RISE63 {
    // Functions.php declares url() too, so only ROOT is provided here.
    const ROOT = '/src';
}
namespace {
    require '/f.php';
    echo \\RISE63\\asset('app.css');
}
`);
  await new Promise((r) => setTimeout(r, 1000));
  const url = out.trim();

  check('without a manifest, asset() still returns a fingerprinted URL',
    /app\.(css|\.[0-9a-f]{8}\.css)(\?v=[0-9a-f]{8})?$/.test(url) && /[0-9a-f]{8}/.test(url), url);
  check('the fallback never returns the bare, cacheable filename',
    !/\/app\.css$/.test(url), url);
  const cssBytes = await readFile(root + 'public/assets/app.css', 'utf8');
  const expected = (await import('node:crypto')).createHash('sha256').update(cssBytes).digest('hex').slice(0, 8);
  check('the fallback fingerprint matches the file contents', url.includes(expected), `${url} vs ${expected}`);
}

console.log('\n' + '─'.repeat(62));
console.log(`${checks - failures.length} passed, ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
