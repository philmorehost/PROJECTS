/**
 * Syntax checker for every PHP file in the project.
 *
 * This sandbox has no PHP binary, so the same PHP engine used by php-wasm parses every file
 * with token_get_all($code, TOKEN_PARSE). That is a real grammar check, not a regex guess.
 *
 *   npm i php-wasm        (once, in RISE63/)
 *   node tests/support/phpwasm-lint.mjs
 */
import { readdir, readFile, writeFile, unlink } from 'node:fs/promises';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');

const { PhpNode } = require('php-wasm/PhpNode.mjs');
const phpModule = require('php-wasm/php8.3-node.mjs');

const SKIP = new Set(['node_modules', '.git', 'storage', 'docs']);

async function walk(dir, out = []) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) await walk(full, out);
    else if (entry.name.endsWith('.php')) out.push(full);
  }
  return out;
}

const files = (await walk(root)).sort();
const payload = {};
for (const file of files) {
  payload[relative(root, file)] = await readFile(file, 'utf8');
}

let output = '';
let errors = '';
const runtime = new PhpNode(phpModule);
runtime.addEventListener('output', (event) => { output += event.detail[0] ?? ''; });
runtime.addEventListener('error', (event) => { errors += String(event.detail?.[0] ?? event.detail ?? ''); });
await runtime.refresh();

await runtime.writeFile('/sources.json', JSON.stringify(payload));

const script = `<?php
$files = json_decode(file_get_contents('/sources.json'), true);
$bad = [];
foreach ($files as $name => $code) {
    try { token_get_all($code, TOKEN_PARSE); }
    catch (\\Throwable $e) { $bad[] = $name . ' :: ' . $e->getMessage(); }
}
if ($bad) { echo "PARSE-ERRORS\\n" . implode("\\n", $bad); exit(1); }
echo "LINT-OK " . count($files);
exit(0);
`;
const code = await runtime.run(script);
await new Promise((resolve) => setTimeout(resolve, 400));

const cleaned = output.trim();
if (cleaned.startsWith('LINT-OK')) {
  console.log(`PHP lint: ${files.length} file(s) parsed cleanly with token_get_all(TOKEN_PARSE) [PHP ${runtime.phpVersion ?? '8.x'}].`);
  process.exit(0);
}
console.error(cleaned || errors || `unexpected lint exit ${code}`);
process.exit(1);
