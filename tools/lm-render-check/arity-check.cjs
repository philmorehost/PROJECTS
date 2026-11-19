// Arity check: every call to a project helper must pass at least as many
// arguments as the function is declared with required parameters.
//
// This is the check that would have caught cron.php: a `?>` inside an echoed
// string ends the expression, so `lm_log(a . b . ' ?> ' . json_encode([...]))`
// parses as lm_log(<one argument>) — the rest of the line becomes HTML. PHP
// does not complain at parse time (a call with too few arguments is a runtime
// ArgumentCountError), so the site ran for three weeks logging 3,000 fatals.
//
// Only project functions are checked: built-ins have optional arguments and
// variadics that make a static count pointless.
const fs = require('fs');
const path = require('path');
const PhpParser = require('php-parser');

const parser = new PhpParser({ parser: { extractDoc: false }, ast: { withPositions: true } });

function walkFiles(dir, out) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (['node_modules', '.git', 'storage', 'vendor', 'includes/src', 'tests'].includes(e.name)) continue;
      walkFiles(p, out);
    } else if (e.name.endsWith('.php')) {
      out.push(p);
    }
  }
  return out;
}

function eachNode(node, fn) {
  if (!node || typeof node !== 'object') return;
  if (Array.isArray(node)) { for (const n of node) eachNode(n, fn); return; }
  fn(node);
  for (const k of Object.keys(node)) {
    if (k === 'loc' || k === 'leadingComments' || k === 'trailingComments') continue;
    const v = node[k];
    if (v && typeof v === 'object') eachNode(v, fn);
  }
}

const dir = process.argv[2] || '.';
const files = walkFiles(dir, []);

// Pass 1: required parameter count for every declared function.
const required = new Map();
const asts = [];
for (const f of files) {
  let ast;
  try { ast = parser.parseCode(fs.readFileSync(f, 'utf8'), f); } catch (e) { continue; }
  asts.push({ file: f, ast });
  eachNode(ast, (n) => {
    if ((n.kind === 'function' || n.kind === 'method') && n.name && Array.isArray(n.arguments)) {
      const name = typeof n.name === 'string' ? n.name : (n.name && n.name.name);
      if (!name) return;
      const req = n.arguments.filter((a) => !a.value && !a.variadic && !a.byref === false).filter((a) => !a.value).length;
      // php-parser marks a defaulted parameter by giving it a `value`.
      const requiredCount = n.arguments.filter((a) => a.value === undefined || a.value === null).length;
      if (!required.has(name)) required.set(name, requiredCount);
    }
  });
}

// Pass 2: call sites.
let problems = 0;
for (const { file, ast } of asts) {
  eachNode(ast, (n) => {
    if (n.kind !== 'call' || !n.what || n.what.kind !== 'name') return;
    const name = n.what.name;
    if (!required.has(name)) return;
    const needs = required.get(name);
    const given = Array.isArray(n.arguments) ? n.arguments.length : 0;
    if (given < needs) {
      problems++;
      const line = n.loc && n.loc.start ? n.loc.start.line : '?';
      console.log(`${path.relative(dir, file)}:${line}  ${name}() needs ${needs} argument(s), call passes ${given}`);
    }
  });
}

console.log(`\n--- ${problems} call(s) with too few arguments, ${files.length} file(s) scanned`);
process.exit(problems ? 1 : 0);
