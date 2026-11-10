import { readdir, readFile } from 'node:fs/promises';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');

const { PhpNode } = require('php-wasm/PhpNode.mjs');

const SKIP = new Set(['node_modules', '.git', '.preview-data']);

async function walkPhp(dir, out = []) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) await walkPhp(full, out);
    else if (entry.name.endsWith('.php') || entry.name === 'artisan') out.push(full);
  }
  return out;
}

const files = (await walkPhp(root)).sort();
const php = new PhpNode({ version: '8.3' });
let output = '';
php.addEventListener('output', (e) => { output += e.detail[0] ?? ''; });
php.addEventListener('error', (e) => { output += String(e.detail?.[0] ?? ''); });
await php.refresh();

await php.mkdir('/lint');
for (let i = 0; i < files.length; i++) {
  const src = await readFile(files[i], 'utf8');
  await php.writeFile(`/lint/f_${i}.php`, src);
}

const mapJson = JSON.stringify(files.map((f, i) => [`/lint/f_${i}.php`, relative(root, f)]));
await php.run(`<?php
$list = json_decode(${JSON.stringify(mapJson)}, true);
$bad = 0;
foreach ($list as [$tmp, $name]) {
    $code = file_get_contents($tmp);
    if (str_starts_with($code, "#!")) {
        $code = substr($code, strpos($code, "\\n") + 1);
    }
    try {
        token_get_all($code, TOKEN_PARSE);
    } catch (ParseError $e) {
        $bad++;
        echo "PARSE FAIL {$name}: " . $e->getMessage() . " on line " . $e->getLine() . "\\n";
    }
}
echo "PHP Lint: " . (count($list) - $bad) . "/" . count($list) . " files parsed cleanly.\\n";
if ($bad > 0) { exit(1); }
`);

process.stdout.write(output);
if (/PARSE FAIL/.test(output)) {
  process.exit(1);
}

// Syntax check frontend JavaScript too
execFileSync(process.execPath, ['--check', join(root, 'public/assets/app.js')], { stdio: 'inherit' });
execFileSync(process.execPath, ['--check', join(root, 'sw.js')], { stdio: 'inherit' });
console.log('JS Lint: public/assets/app.js & sw.js passed node --check.');
