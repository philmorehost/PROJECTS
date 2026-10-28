/**
 * Generate LM/build.json — the release manifest that health.php checks a
 * deployed folder against.
 *
 * A mixed folder (new admin/ next to an old includes/) is the number one cause
 * of an admin who is signed in getting "403 Forbidden" from the console, and
 * nothing in the UI could see it. With a manifest, health.php can list exactly
 * which files are not from this release.
 *
 * Only files that actually run are listed: PHP, server rules, assets, email
 * templates and migrations. Documentation, settings and anything ignored by
 * git are left out so day-to-day edits never look like a partial upload.
 *
 * Usage: node qa/buildjson.cjs [--check]
 *   --check  verify the manifest on disk still matches, exit 1 if not
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const LM = path.resolve(__dirname, '..', 'LM');
const MANIFEST = path.join(LM, 'build.json');

const SKIP_DIRS = new Set(['docs', 'storage', 'uploads', 'node_modules', '.git']);
const SKIP_FILES = new Set(['settings.json', 'db.local.php', 'build.json']);
const INCLUDE_EXT = new Set(['.php', '.css', '.js', '.sql', '.html', '.htaccess']);

function wanted(name) {
  if (SKIP_FILES.has(name)) return false;
  const ext = name === '.htaccess' ? '.htaccess' : path.extname(name).toLowerCase();
  return INCLUDE_EXT.has(ext) || name === '.htaccess';
}

function walk(dir, rel = '', out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.isDirectory()) {
      if (SKIP_DIRS.has(e.name)) continue;
      walk(path.join(dir, e.name), rel ? rel + '/' + e.name : e.name, out);
    } else if (e.isFile() && wanted(e.name)) {
      // A zero-byte file carries no release signal and is never required.
      if (fs.statSync(path.join(dir, e.name)).size === 0) continue;
      out.push(rel ? rel + '/' + e.name : e.name);
    }
  }
  return out.sort();
}

const files = walk(LM);
const sha1 = p => crypto.createHash('sha1').update(fs.readFileSync(p)).digest('hex');
const version = (fs.readFileSync(path.join(LM, 'includes', 'lm_config.php'), 'utf8')
  .match(/define\('LM_VERSION',\s*'([^']+)'\)/) || [])[1] || '0.0.0';

const entries = {};
for (const f of files) entries[f] = sha1(path.join(LM, f));

const check = process.argv.includes('--check');
if (check) {
  const current = JSON.parse(fs.readFileSync(MANIFEST, 'utf8'));
  const diffs = [];
  for (const f of new Set([...Object.keys(current.files), ...Object.keys(entries)])) {
    if (!current.files[f]) diffs.push('added   ' + f);
    else if (!entries[f]) diffs.push('removed ' + f);
    else if (current.files[f] !== entries[f]) diffs.push('changed ' + f);
  }
  if (current.version !== version) diffs.push('version ' + current.version + ' → ' + version);
  console.log(diffs.length ? diffs.join('\n') : 'build.json matches the tree');
  process.exit(diffs.length ? 1 : 0);
}

const manifest = {
  name: 'Philmore Licence Manager',
  version,
  generated: new Date().toISOString().slice(0, 10),
  files: entries,
};
fs.writeFileSync(MANIFEST, JSON.stringify(manifest, null, 2) + '\n');
console.log('build.json: ' + version + ' · ' + Object.keys(entries).length + ' files · '
  + (fs.statSync(MANIFEST).size / 1024).toFixed(1) + ' KB');
