// Compare build.json against the tree, the way health.php does.
const fs=require('fs'),path=require('path'),crypto=require('crypto');
const root=process.argv[2];
const m=JSON.parse(fs.readFileSync(path.join(root,'build.json'),'utf8'));
let stale=[],missing=[];
for(const [rel,hash] of Object.entries(m.files)){
  const f=path.join(root,rel);
  if(!fs.existsSync(f)){missing.push(rel);continue;}
  const actual=crypto.createHash('sha1').update(fs.readFileSync(f)).digest('hex');
  if(actual!==hash)stale.push(rel);
}
console.log('manifest files:',Object.keys(m.files).length);
console.log('missing:',missing.length?missing:'none');
console.log('stale  :',stale.length?stale:'none');
// And the reverse: PHP files on disk that the manifest does not list.
const unlisted=[];
function walk(d){for(const e of fs.readdirSync(d,{withFileTypes:true})){
  if(['node_modules','.git','storage','uploads','docs','tests'].includes(e.name))continue;
  const p=path.join(d,e.name);
  if(e.isDirectory())walk(p);
  else if(e.name.endsWith('.php')){
    const rel=path.relative(root,p);
    if(fs.statSync(p).size>0 && !(rel in m.files))unlisted.push(rel);
  }}}
walk(root);
console.log('unlisted non-empty PHP:',unlisted.length?unlisted:'none');
