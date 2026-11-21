import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
const today = () => new Date().toISOString().slice(0,10);
const feed = (extra={}) => ({date:today(),usd:{usd:1,gbp:0.8,ngn:1600,eur:0.9,cny:7,kes:130,ghs:15,btc:0.00001,jpy:0,...extra}});
for (const ed of ['DGV7.0-SAAS', 'DGV7.0-NON-SAAS']) {
 const source=fs.readFileSync(new URL(`../../${ed}/jsfile/bc-public-home.js`, import.meta.url),'utf8');
 const dom=new JSDOM(`<input id="mz-send" value="1000"><select id="mz-from"><option value="">Loading</option></select><select id="mz-to"><option value="">Loading</option></select><output id="mz-get">—</output><p id="mz-rate-status"></p><p id="mz-rate-line"></p><button id="mz-swap"></button><button id="mz-refresh"></button><span id="mz-rate-badge"></span>`,{url:'https://example.test/',runScripts:'outside-only'});
 const w=dom.window,d=w.document,pending=[];
 w.AbortController=AbortController;
 w.fetch=(url,opts)=>new Promise((resolve,reject)=>pending.push({url,opts,resolve,reject}));
 const resolve=(i,data)=>pending[i].resolve({ok:true,json:async()=>data});
 const change=(id,value,event='change')=>{const el=d.getElementById(id);el.value=value;el.dispatchEvent(new w.Event(event));};
 const output=()=>d.getElementById('mz-get').textContent;
 const status=()=>d.getElementById('mz-rate-status').textContent;
 w.eval(source);
 assert.equal(pending.length,1);assert.ok(d.getElementById('mz-from').disabled);assert.equal(output(),'—');
 resolve(0,feed());await tick();
 assert.equal(d.getElementById('mz-from').value,'GBP');assert.equal(d.getElementById('mz-to').value,'NGN');
 assert.equal(output(),'2,000,000');assert.ok(status().includes(today()));
 assert.ok(d.querySelector('#mz-to option[value=CNY]'),'currency list comes from feed');
 assert.equal(d.querySelector('#mz-to option[value=BTC]'),null,'crypto is not presented as fiat');
 assert.equal(d.querySelector('#mz-to option[value=JPY]'),null,'zero rates are not selectable');
 change('mz-send','100','input');assert.equal(output(),'200,000');assert.equal(pending.length,1,'typing is local, not a financial/network request');
 d.getElementById('mz-swap').click();assert.equal(d.getElementById('mz-from').value,'NGN');assert.equal(output(),'0.05');
 change('mz-to','NGN');assert.equal(output(),'100','same currency is 1:1');
 change('mz-send','','input');assert.equal(output(),'—');assert.ok(status().includes('valid amount'));
 change('mz-send','-1','input');assert.equal(output(),'—');
 change('mz-send','0','input');assert.equal(output(),'0');
 change('mz-send','1e308','input');change('mz-from','USD');change('mz-to','NGN');assert.equal(output(),'—');assert.ok(status().includes('too large'));
 // A refresh clears old results immediately; invalid primary payload tries the mirror.
 change('mz-send','100','input');d.getElementById('mz-refresh').click();assert.equal(output(),'—');
 resolve(1,{date:'invalid',usd:{usd:1,ngn:1}});await tick();assert.equal(pending.length,3);
 resolve(2,feed({ngn:1500}));await tick();assert.equal(output(),'150,000');
 assert.ok(pending[2].url.startsWith('https://latest.currency-api.pages.dev/'));
 // Outage after a success must never leave an old result looking current.
 d.getElementById('mz-refresh').click();pending[3].reject(new Error('offline'));await tick();pending[4].reject(new Error('offline'));await tick();
 assert.equal(output(),'—');assert.ok(status().includes('unavailable'));assert.ok(d.getElementById('mz-from').disabled);
 assert.equal(d.getElementById('mz-refresh').disabled,false,'retry still usable');
 d.getElementById('mz-refresh').click();
 const older=feed();older.date=new Date(Date.now()-5*86400000).toISOString().slice(0,10);resolve(5,older);await tick();
 assert.ok(status().includes('Older reference data'),'old feed is never labeled current');
 for(const request of pending){
  assert.equal(request.opts.credentials,'omit');assert.equal(request.opts.referrerPolicy,'no-referrer');
  assert.equal(request.opts.body,undefined);assert.ok(!request.url.includes('example.test')&&!request.url.includes('/web/'));
 }
 w.close();
 console.log(`PASS ${ed}: public source, dynamic currencies, conversion, swap, validation, fallback/retry, stale labels and transaction isolation`);
}
