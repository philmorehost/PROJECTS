// DOM regression using the actual PHP-rendered select, not reimplemented options.
import {JSDOM} from 'jsdom';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
const dir=path.dirname(fileURLToPath(import.meta.url));
const fixtures=JSON.parse(execFileSync(process.execPath,[path.join(dir,'run-test.mjs'),'tests/pricing_options_fixture.php'],{encoding:'utf8'}));
let count=0;
for(const html of Object.values(fixtures)){
 const dom=new JSDOM('<form>'+html+'</form>');
 const select=dom.window.document.querySelector('select');
 for(const value of ['smart','agent','api']){
  const option=select.querySelector(`[value=${value}]`);
  assert.equal(option.disabled,false);
  select.value=value;
  assert.equal(new dom.window.FormData(select.form).get('upgrade-type'),value);
  count+=2;
 }
 dom.window.close();
}
console.log(`${count} rendered pricing-select DOM checks passed.`);
