/**
 * Dev harness that runs the page smoke tests one scenario per PHP process.
 *
 * The pages under test include header.php, which declares functions: rendering
 * two pages in a single process would redeclare them, so every scenario gets its
 * own fresh PHP runtime.
 *
 *   npm install --no-save @php-wasm/node @php-wasm/universal   # once
 *   node tests/run_smoke.mjs                                  # all scenarios
 *   node tests/run_smoke.mjs 5                                # first 5 only
 *
 * Two files are swapped inside the wasm filesystem only (the repository is never
 * modified):
 *   - includes/db_connect.php  -> binds the fake in-memory database
 *   - includes/send_email.php  -> the recording mail stub
 * so no MySQL server and no SMTP account are needed.
 */
import { PHP, loadPHPRuntime } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/node';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

async function boot() {
  const loader = await getPHPLoaderModule('8.3');
  const runtime = await loadPHPRuntime(loader, { processId: 1 });
  const php = new PHP(runtime);

  php.mkdir('/project');
  (function copy(dir, base = '') {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const rel = path.join(base, entry.name);
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        php.mkdir('/project/' + rel);
        copy(full, rel);
      } else if (entry.name.endsWith('.php')) {
        php.writeFile('/project/' + rel, fs.readFileSync(full));
      }
    }
  })(ROOT);

  // Both files are intentionally not part of a deployable checkout.
  php.writeFile('/project/includes/db_connect.php', "<?php\n$mysqli = isset($GLOBALS['SMOKE_DB']) ? $GLOBALS['SMOKE_DB'] : null;\n");
  php.writeFile('/project/includes/send_email.php', "<?php\nrequire_once __DIR__ . '/../tests/send_email_stub.php';\n");
  php.chdir('/project');
  return php;
}

const limit = parseInt(process.argv[2] || '0', 10);
let failed = 0;
let run = 0;

for (let i = 0; ; i++) {
  if (limit > 0 && i >= limit) break;
  const php = await boot();
  const proc = await php.cli(['php', '/project/tests/run_page_smoke.php'], {
    env: { SMOKE_SCENARIO: String(i), SMOKE_DUMP: '/tmp/eflex-smoke-' + i + '.html' },
  });
  const text = await proc.stdoutText;
  const errors = await proc.stderrText;

  if (text.includes('unknown scenario')) {
    if (i === 0) {
      process.stdout.write(text);
    }
    break;
  }
  run++;

  const verdict = (text.match(/VERDICT:[\s\S]*?(?=\n\S|$)/) || [text.trim()])[0];
  if (!verdict.includes('VERDICT: OK')) {
    failed++;
    process.stdout.write(verdict.trimEnd() + '\n');
    if (errors) {
      process.stdout.write('   stderr: ' + errors.split('\n').slice(0, 4).join(' | ') + '\n');
    }
  } else {
    process.stdout.write(verdict.trimEnd() + '\n');
  }
}

if (run === 0) {
  process.stdout.write('No scenarios were run (is @php-wasm installed?).\n');
  process.exit(1);
}
process.stdout.write('\n' + (failed === 0
  ? `All ${run} page scenarios passed.`
  : `${failed} of ${run} page scenario(s) failed.`) + '\n');
process.exit(failed === 0 ? 0 : 1);
