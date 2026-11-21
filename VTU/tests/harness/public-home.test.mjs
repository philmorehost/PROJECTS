import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
for (const ed of ['DGV7.0-SAAS', 'DGV7.0-NON-SAAS']) {
 const source = fs.readFileSync(new URL(`../../${ed}/jsfile/bc-public-home.js`, import.meta.url), 'utf8');
 for (const enabled of [false, true]) {
  const dom = new JSDOM(`<input id="mz-send" value="100" data-fx-enabled="${enabled ? 1 : 0}"><select id="mz-from"><option>USD</option><option>NGN</option></select><select id="mz-to"><option>NGN</option><option>USD</option></select><output id="mz-get">—</output><p id="mz-rate-status"></p><p id="mz-rate-line"></p><button id="mz-swap"></button>`, { url:'https://example.test/', runScripts:'outside-only' });
  const w=dom.window,d=w.document,pending=[];
  w.fetch=(url,opts)=>new Promise((resolve,reject)=>pending.push({url,opts,resolve,reject}));
  let timer;
  w.setTimeout=fn=>{timer=fn;return 1};w.clearTimeout=()=>{timer=null};
  w.eval(source);
  const amount=d.getElementById('mz-send'),output=d.getElementById('mz-get');
  if (!enabled) {
   assert.equal(pending.length,0);assert.equal(amount.disabled,true);assert.equal(output.textContent,'—');
   assert.ok(d.getElementById('mz-rate-status').textContent.includes('not enabled'));
  } else {
   assert.equal(pending.length,1);
   assert.equal(pending[0].url,'/web/api/exchange-rate.php?from=USD&to=NGN');
   pending[0].resolve({ok:true,json:async()=>({rate:1500})});await tick();
   assert.equal(output.textContent,'150,000');
   amount.value='200';amount.dispatchEvent(new w.Event('input'));timer();
   amount.value='300';amount.dispatchEvent(new w.Event('input'));
   pending[1].resolve({ok:true,json:async()=>({rate:1})});await tick();
   assert.equal(output.textContent,'—','stale quotes do not render during debounce');
   timer();pending[2].resolve({ok:true,json:async()=>({rate:0})});await tick();
   assert.equal(output.textContent,'—');assert.ok(d.getElementById('mz-rate-status').textContent.includes('unavailable'));
   d.getElementById('mz-swap').click();timer();
   assert.equal(pending[3].url,'/web/api/exchange-rate.php?from=NGN&to=USD');
   amount.value='';amount.dispatchEvent(new w.Event('input'));timer();
   assert.equal(pending.length,4,'empty amount does not query');
   assert.equal(output.textContent,'—');
  }
  w.close();
 }
 console.log(`PASS ${ed}: public FX gating, live response, stale/invalid data, swap and empty input`);
}
