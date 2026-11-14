import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const HERE = path.dirname(fileURLToPath(import.meta.url));
const CASES = path.join(HERE, 'stray-html-cases.json');
const HELPERS = path.join(HERE, '..', '..', 'LM', 'health.php');
const runtimeId = await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 21 } });
const php = new PHP(runtimeId);
useHostFilesystem(php);
const cases = JSON.parse(fs.readFileSync(CASES, 'utf8'));
// Lift lm_health_stray_html() and lm_health_looks_like_php() straight out of
// health.php: the test must exercise the function that ships, not a copy.
const health = fs.readFileSync(HELPERS, 'utf8');
const start = health.indexOf("if (!function_exists('lm_health_stray_html')) {");
const end = health.indexOf('/* ------------------------------------------------------------------ checks */');
if (start < 0 || end < 0) {
  console.error('Could not find the scanner in health.php — has it been renamed?');
  process.exit(1);
}
const scanner = health.slice(start, end);

const phpCode = `<?php
${scanner}
$cases = json_decode(file_get_contents(${JSON.stringify(CASES)}), true);
$fail = 0;
foreach ($cases as $c) {
    $got = lm_health_stray_html($c['src']);
    $want = $c['expect'];
    $ok = (count($got) === count($want));
    if ($ok) { foreach ($want as $line => $text) { if (!isset($got[(int) $line]) || $got[(int) $line] !== $text) { $ok = false; break; } } }
    if (!$ok) { $fail++; printf("FAIL  %s\\n      expected %s\\n      got      %s\\n", $c['name'], json_encode($want), json_encode($got)); }
    else { printf("ok    %s\\n", $c['name']); }
}
printf("\\n--- %d of %d case(s) failed\\n", $fail, count($cases));
`;
const res = await php.run({ code: phpCode, $_SERVER: { DOCUMENT_ROOT: '/' } });
console.log(res.text, res.errors);
process.exit(res.text.includes('0 of') ? 0 : 1);
