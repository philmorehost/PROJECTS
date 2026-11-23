import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source = fs.readFileSync(new URL('../../DGV7.0-SAAS/asset/bc-user-action-otp.js', import.meta.url),'utf8');
async function run(reply, throws=false) {
    let click, requests=0, focused=false;
    const fields={_csrf_token:{value:'csrf'},sec33_user_action:{value:'api'},sec33_status:{value:'1'},'account-user-id':{value:'42'},'account-username':{value:'customer'},sec_pin:{value:'never-send-pin'},sec_factor:{value:'654321',focus(){focused=true;}}};
    const button={disabled:false,addEventListener(_,fn){click=fn;}};
    const status={textContent:''};
    const form={action:'https://example.test/bc-admin/Users.php',elements:{namedItem(name){return fields[name];}},querySelector(s){return s.includes('sec33_send_code')?button:status;}};
    let resolve;
    class FormData { values=new Map();set(k,v){this.values.set(k,v);} }
    const context={document:{querySelectorAll(){return [form];}},FormData,SyntaxError,Error,fetch:async (url,options)=>{
        requests++;assert.equal(url,form.action);assert.equal(options.method,'POST');assert.equal(options.credentials,'same-origin');
        assert.equal(options.headers['X-Requested-With'],'XMLHttpRequest');assert.equal(options.body.values.get('_csrf_token'),'csrf');
        assert.equal(options.body.values.get('sec33_send_code'),'1');assert(!options.body.values.has('sec_pin'));assert(!options.body.values.has('sec_factor'));
        await new Promise(r=>{resolve=r;});
        return {ok:true,json:async()=>{if(throws)throw new SyntaxError('not JSON');return reply;}};
    }};
    vm.runInNewContext(source,context);
    let prevented=0;const event={preventDefault(){prevented++;}};
    const pending=click(event);assert.equal(button.disabled,true);await click(event);assert.equal(requests,1);
    resolve();await pending;
    assert.equal(prevented,2);assert.equal(button.disabled,false);assert.equal(fields.sec_factor.value,'654321');
    return {status:status.textContent,focused};
}
assert.deepEqual(await run({status:'success'}),{status:'OTP SENT',focused:true});
assert.deepEqual(await run({status:'error',message:'Please wait before retrying.'}),{status:'Please wait before retrying.',focused:false});
assert.match((await run(null,true)).status,/Session or verification unavailable/);
assert.equal(source,fs.readFileSync(new URL('../../DGV7.0-NON-SAAS/asset/bc-user-action-otp.js', import.meta.url),'utf8'));
console.log('Inline user-action OTP behavior passed: no reload, duplicate dispatch, PIN/code leakage or input loss.');
