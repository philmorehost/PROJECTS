import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
for(const ed of ['SAAS','NON-SAAS']){
 class FormData{data=new Map();set(k,v){this.data.set(k,v);}}
 const context={FormData,URLSearchParams,document:{querySelector(selector){assert.equal(selector,'meta[name="feature-action-csrf"]');return {content:'fixture-csrf'};}}};
 vm.createContext(context);vm.runInContext(fs.readFileSync(`VTU/DGV7.0-${ed}/asset/bc-feature-action.js`,'utf8'),context);
 assert.equal(context.bcFeatureActionBody().data.get('_csrf_token'),'fixture-csrf');assert.equal(context.bcFeatureActionParams().get('_csrf_token'),'fixture-csrf');
 for(const file of ['SendFund.php','GiftCard.php']){
  const s=fs.readFileSync(`VTU/DGV7.0-${ed}/web/${file}`,'utf8');
  for(const form of s.matchAll(/<form\b[^>]*>/g))assert(s.slice(form.index+form[0].length).startsWith('<?php echo bc_csrf_field(); ?>'));
  assert(!s.includes('new FormData()'));assert(!s.includes('new URLSearchParams()'));
 }
}
console.log('Both editions: CSRF in all feature forms and legacy programmatic request bodies.');
