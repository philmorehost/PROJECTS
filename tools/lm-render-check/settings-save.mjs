/**
 * Settings save round-trip.
 *
 * Renders admin/settings.php twice: a GET to read the current values, then a real
 * POST of the form, then a GET again to prove the POST stuck. This is the seam
 * the retention work depends on — the rule lives in settings, so if the form does
 * not save it, every release is kept forever and nothing looks broken.
 *
 * Note the two harness requirements, both learned the hard way:
 *   - app_ui.php must be required and the session started here. settings.php also
 *     requires it, but a session started after some other output is not the same
 *     session, and the page's guards then exit with an empty body.
 *   - $_SESSION['lm_password_change_required'] must be unset, or lm_admin_gate()
 *     redirects to profile.php and the page never renders.
 *
 *   node settings-save.mjs            # uses a temp copy of ../../LM
 */
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const SRC = path.resolve(process.argv[2] || '../../LM');
const ROOT = path.join(os.tmpdir(), 'lm-settings-' + process.pid);

let failures = 0;
function check(name, ok, detail = '') {
  if (ok) {
    console.log('ok    ' + name);
  } else {
    failures++;
    console.log('FAIL  ' + name + (detail ? '\n      ' + detail : ''));
  }
}

fs.rmSync(ROOT, { recursive: true, force: true });
fs.cpSync(SRC, ROOT, { recursive: true });
// No database in the sandbox: settings.php only needs the connection to exist.
fs.writeFileSync(path.join(ROOT, 'db.php'), '<?php\n$pdo = null;\n');

const settingsPath = path.join(ROOT, 'settings.json');
const before = JSON.parse(fs.readFileSync(settingsPath, 'utf8'));

const runtimeId = await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 91 } });
const php = new PHP(runtimeId);
useHostFilesystem(php);

const server = {
  DOCUMENT_ROOT: ROOT,
  HTTP_HOST: 'localhost',
  REQUEST_METHOD: 'GET',
  SCRIPT_NAME: '/admin/settings.php',
  PHP_SELF: '/admin/settings.php',
};

/** Run settings.php with the given request, in a session the page accepts. */
async function hit(post = null) {
  // A JS object literal is not PHP: hand the values over as JSON and decode.
  const postCode = post
    ? `$_POST = json_decode(${JSON.stringify(JSON.stringify(post))}, true);\n` +
      `$_SERVER['REQUEST_METHOD'] = 'POST';\n` +
      `$_POST['csrf_token'] = lm_csrf_token();\n`
    : '';
  const res = await php.run({
    code: `<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
require ${JSON.stringify(path.join(ROOT, 'includes/app_ui.php'))};
lm_session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'harness';
$_SESSION['admin_role'] = 'admin';
unset($_SESSION['lm_password_change_required']);
${postCode}
chdir(${JSON.stringify(path.join(ROOT, 'admin'))});
ob_start();
require ${JSON.stringify(path.join(ROOT, 'admin/settings.php'))};
$html = ob_get_clean();
echo "<!--HARNESS-->" . $html;
`,
    $_SERVER: server,
  });
  const text = res.text || '';
  const at = text.indexOf('<!--HARNESS-->');
  return { html: at === -1 ? text : text.slice(at + 14), errors: res.errors || '' };
}

// ------------------------------------------------------------------ read first
const first = await hit();
check('settings.php renders', first.html.length > 5000, `got ${first.html.length} bytes, errors: ${first.errors.slice(0, 200)}`);
check('no PHP diagnostics on GET', first.errors.trim() === '', first.errors.slice(0, 300));

const keepField = (html) => {
  const m = html.match(/id="release_keep"[^>]*value="([^"]*)"/);
  return m ? m[1] : null;
};

check('the keep field is on the page', keepField(first.html) !== null);
check('the days field is on the page', /id="release_keep_days"/.test(first.html));
check('the auto-prune checkbox is on the page', /name="release_auto_prune"/.test(first.html));
const beforeValue = keepField(first.html);

// ------------------------------------------------------------------- post it
const posted = await hit({
  release_keep: '3',
  release_keep_days: '7',
  release_auto_prune: '1',
  action: 'save_storage',
});
check('no PHP diagnostics on POST', posted.errors.trim() === '', posted.errors.slice(0, 300));
check('the save is confirmed on the page', /saved|updated|Saved|Updated/.test(posted.html));

// ------------------------------------------------------------- and read again
const after = await hit();
const afterValue = keepField(after.html);
check('the saved keep count is shown after reload', afterValue === '3', `expected "3", saw ${JSON.stringify(afterValue)} (was ${JSON.stringify(beforeValue)})`);
check('the days value is shown after reload', /id="release_keep_days"[^>]*value="7"/.test(after.html));
check('the checkbox is still checked', /name="release_auto_prune"[^>]*checked|checked[^>]*name="release_auto_prune"/.test(after.html));

// --------------------------------------------------------------- the file itself
const stored = JSON.parse(fs.readFileSync(settingsPath, 'utf8'));
check('release_keep is in settings.json', stored.release_keep === 3, JSON.stringify(stored.release_keep));
check('release_keep_days is in settings.json', stored.release_keep_days === 7, JSON.stringify(stored.release_keep_days));
check('release_auto_prune is in settings.json', stored.release_auto_prune === true, JSON.stringify(stored.release_auto_prune));

const lost = Object.keys(before).filter((k) => k !== 'release_keep' && !(k in stored));
check('every other setting survived', lost.length === 0, 'lost: ' + lost.join(', '));
check('the cron secret is byte-identical', stored.cron_secret === before.cron_secret);
check('csrf_secret survived', stored.csrf_secret === before.csrf_secret);

// ------------------------------------------------------------- the clamp rules
const clamped = await hit({ release_keep: '999', release_keep_days: '-5', action: 'save_storage' });
check('out-of-range values do not produce a diagnostic', clamped.errors.trim() === '', clamped.errors.slice(0, 200));
const clampStored = JSON.parse(fs.readFileSync(settingsPath, 'utf8'));
check('keep is clamped to 50', clampStored.release_keep === 50, JSON.stringify(clampStored.release_keep));
check('days is clamped to 0', clampStored.release_keep_days === 0, JSON.stringify(clampStored.release_keep_days));
check('an unchecked box is saved as false', clampStored.release_auto_prune === false, JSON.stringify(clampStored.release_auto_prune));

fs.rmSync(ROOT, { recursive: true, force: true });

console.log(`\n--- ${failures} of 17 check(s) failed`);
process.exit(failures ? 1 : 0);
