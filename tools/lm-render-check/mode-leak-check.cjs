// Leak detector: uses php-parser to find the *HTML regions* of each file (the
// `inline` nodes), then flags lines inside those regions that are pure PHP
// syntax. Those regions are exactly what PHP prints verbatim, so a hit is a
// real leak. Blocks that legitimately print code (<script>, <style>, <pre>,
// <code>, <textarea>) are masked first.
const fs = require('fs');
const path = require('path');
const engine = require('php-parser');

const parser = new engine({ parser: { extractDoc: false }, ast: { withPositions: true } });

function walkDir(dir, out) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (['node_modules', '.git', 'storage', 'includes/src', 'vendor'].includes(e.name)) continue;
      walkDir(p, out);
    } else if (e.name.endsWith('.php')) {
      out.push(p);
    }
  }
  return out;
}

function inlineNodes(node, out) {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) { for (const n of node) inlineNodes(n, out); return out; }
  if (node.kind === 'inline' && typeof node.value === 'string') out.push(node);
  for (const key of Object.keys(node)) {
    if (key === 'loc' || key === 'leadingComments' || key === 'trailingComments') continue;
    const v = node[key];
    if (v && typeof v === 'object') inlineNodes(v, out);
  }
  return out;
}

const PHP_LINE = [
  /^\}\s*\}?\s*$/,
  /^\{\s*$/,
  /^\}\s*(else|elseif|catch|finally)\b/,
  /^if\s*\(\s*!?\s*function_exists\s*\(/,
  /^(?:public|private|protected)\s+(?:static\s+)?function\s+\w+/,
  /^function\s+\w+\s*\(/,
  /^(?:require|include)(?:_once)?\s*[('"]/,
  /^\*+\s*@(?:param|return|var|package|throws)/,
];

function maskHtml(html) {
  const blank = (m) => m.replace(/[^\n]/g, '');
  return html
    .replace(/<(script|style|pre|code|textarea)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, blank)
    .replace(/data-copy="[\s\S]*?"/gi, blank);
}

const dir = process.argv[2] || '.';
const files = walkDir(dir, []);
let bad = 0, hits = 0;

for (const f of files) {
  let ast;
  try { ast = parser.parseCode(fs.readFileSync(f, 'utf8'), f); } catch (e) { continue; }
  const html = inlineNodes(ast, []).map((n) => n.value).join('\n');
  if (!html) continue;
  const found = [];
  maskHtml(html).split('\n').forEach((line, i) => {
    const t = line.trim();
    if (t === '') return;
    if (PHP_LINE.some((re) => re.test(t))) found.push(t.slice(0, 100));
  });
  if (found.length) {
    bad++; hits += found.length;
    console.log(`\n${path.relative(dir, f)}  (${found.length} HTML-region line(s) that look like PHP)`);
    found.slice(0, 8).forEach((l) => console.log('   ' + l));
  }
}

console.log(`\n--- ${hits} leaked line(s) in ${bad} file(s), ${files.length} file(s) scanned`);
process.exit(hits ? 1 : 0);
