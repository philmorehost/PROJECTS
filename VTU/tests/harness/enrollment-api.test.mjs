import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
const runner=fileURLToPath(new URL('./run-test.mjs',import.meta.url));
for(const [fixture,code] of [['password','PASSWORD_CHANGE_REQUIRED'],['totp','AUTHENTICATOR_SETUP_REQUIRED']]){
 const output=execFileSync(process.execPath,[runner,`tests/enrollment_api_${fixture}_test.php`],{encoding:'utf8'});
 const body=JSON.parse(output);
 assert.equal(body.status,'error');assert.equal(body.code,code);assert.equal(body.setup_url,'/web/SecuritySetup.php');
 assert.ok(!output.includes('UNREACHABLE'),'guard exits before the simulated side effect');
}
console.log('Both API guard fixtures return clean JSON and exit before side effects.');
