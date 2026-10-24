/**
 * Parse every PHP file in the repository as PHP 7.4.
 *
 * The live host is cPanel + LiteSpeed running ea-php74 (see LM/.htaccess), so
 * PHP 8-only syntax is a parse error the php-wasm 8.x runtime cannot see.
 */
const fs = require('fs');
const path = require('path');
const Engine = require('php-parser');

const ROOT = path.resolve(__dirname, '..');
const parser = new Engine({ parser: { suppressErrors: false } });

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.name === 'node_modules' || e.name === '.git' || e.name === 'qa') continue;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, out);
    else if (e.isFile() && e.name.endsWith('.php')) out.push(full);
  }
  return out;
}

const files = walk(ROOT);
let errors = 0;
for (const file of files) {
  try {
    parser.parseCode(fs.readFileSync(file, 'utf8'), file, { version: '7.4' });
  } catch (e) {
    errors++;
    console.log('FAIL ' + path.relative(ROOT, file) + ' → ' + String(e.message).slice(0, 180));
  }
}
console.log('\nPHP 7.4 parse: ' + files.length + ' files, ' + errors + ' error(s)');
process.exit(errors === 0 ? 0 : 1);
