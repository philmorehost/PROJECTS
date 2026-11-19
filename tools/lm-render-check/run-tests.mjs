// Runs the repo's PHP test files under a real PHP 8.3 (wasm).
//   node run-tests.mjs ../../LM/tests
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import fs from 'node:fs';
import path from 'node:path';

const dir = path.resolve(process.argv[2] || path.join(import.meta.dirname, '..', '..', 'LM', 'tests'));
const files = fs.readdirSync(dir).filter((f) => f.endsWith('_test.php')).sort();

const runtimeId = await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 31 } });
const php = new PHP(runtimeId);
useHostFilesystem(php);

let failed = 0;
for (const f of files) {
  const full = path.join(dir, f);
  let res;
  try {
    res = await php.run({ scriptPath: full, relativeUri: '/' + f, $_SERVER: {
      DOCUMENT_ROOT: dir, HTTP_HOST: 'localhost', REQUEST_METHOD: 'GET', SCRIPT_NAME: '/' + f,
    } });
  } catch (err) {
    failed++;
    console.log(`\n=== ${f}: CRASHED\n${String(err.message).slice(0, 1200)}`);
    continue;
  }
  const text = res.text || '';
  const ok = /0 of \d+ check\(s\) failed/.test(text) && res.exitCode === 0;
  console.log(`\n=== ${f} — ${ok ? 'PASS' : 'FAIL'}`);
  console.log(text.trimEnd());
  if (!ok) failed++;
}
console.log(`\n=== ${failed} of ${files.length} test file(s) failed`);
process.exit(failed ? 1 : 0);
