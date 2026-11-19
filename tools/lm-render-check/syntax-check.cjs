const fs=require('fs'),path=require('path'),engine=require('php-parser');
const parser=new engine({parser:{extractDoc:false,suppressErrors:false},ast:{withPositions:true}});
function walk(d,o){for(const e of fs.readdirSync(d,{withFileTypes:true})){const p=path.join(d,e.name);
if(e.isDirectory()){if(!['node_modules','.git','storage','includes/src'].includes(e.name))walk(p,o);}
else if(e.name.endsWith('.php'))o.push(p);}return o;}
const dir=process.argv[2]||'.';
const files=walk(dir,[]);let bad=0;
for(const f of files){try{parser.parseCode(fs.readFileSync(f,'utf8'),f);}catch(err){bad++;console.log('FAIL',path.relative(dir,f),String(err.message).split('\n')[0]);}}
console.log(`--- ${bad} syntax error(s) in ${files.length} file(s)`);
process.exit(bad?1:0);
