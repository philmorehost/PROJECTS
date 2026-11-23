import fs from 'node:fs';
import {JSDOM} from 'jsdom';
import assert from 'node:assert/strict';
const tick=()=>new Promise(r=>setTimeout(r,0));
for(const edition of ['SAAS','NON-SAAS']) for(const mode of ['photo','video','denied','unsupported','oversized','cancel']) {
 const moving=mode==='video'||mode==='oversized';
 const dom=new JSDOM(`<form><div data-live-capture="${moving?'video':'photo'}"><input type="file" hidden><video hidden></video><img hidden><button type="button" data-camera-start>Start</button><button type="button" data-camera-shot hidden>Take</button><button type="button" data-camera-reset hidden>Retake</button><p role="status"></p></div></form>`,{url:'https://tenant.test',runScripts:'outside-only'});
 const w=dom.window,d=w.document;let stopped=0,recording;
 Object.defineProperty(w,'isSecureContext',{value:mode!=='unsupported'});
 Object.defineProperty(w.navigator,'mediaDevices',{value:{getUserMedia:async opts=>{if(mode==='denied')throw new Error('Permission denied');assert.equal(opts.audio,moving);assert.equal(opts.video.width.max,960);return {getTracks:()=>[{stop:()=>stopped++}]};}}});
 w.HTMLMediaElement.prototype.play=async()=>{};
 Object.defineProperty(w.HTMLVideoElement.prototype,'videoWidth',{get:()=>1280});Object.defineProperty(w.HTMLVideoElement.prototype,'videoHeight',{get:()=>720});
 w.HTMLCanvasElement.prototype.getContext=()=>({drawImage:()=>{}});w.HTMLCanvasElement.prototype.toBlob=function(cb,type,quality){assert.equal(this.width,960);assert.equal(quality,.78);cb(new w.Blob(['jpeg'],{type}));};
 w.DataTransfer=class{constructor(){this.items={add:f=>this.files=[f]};this.files=[];}};
 Object.defineProperty(d.querySelector('input'),'files',{writable:true,value:[]});
 w.URL.createObjectURL=()=> 'blob:preview';w.URL.revokeObjectURL=()=>{};
 w.MediaRecorder=class {static isTypeSupported(){return true;} constructor(s,opts){assert.equal(opts.videoBitsPerSecond,500000);this.mimeType=opts.mimeType;this.state='inactive';recording=this;}start(){this.state='recording';}stop(){this.state='inactive';const blob=new w.Blob([mode==='oversized'?'x'.repeat(3*1024*1024+1):'video'],{type:'video/webm'});this.ondataavailable({data:blob});this.onstop();}};
 w.eval(fs.readFileSync(`VTU/DGV7.0-${edition}/asset/bc-live-capture.js`,'utf8'));
 d.querySelector('[data-camera-start]').click();await tick();await tick();
 if(['denied','unsupported'].includes(mode)){assert.equal(d.querySelector('input').files.length,0);assert.match(d.querySelector('[role=status]').textContent,/Gallery uploads are not accepted/);}
 else if(mode==='cancel'){d.querySelector('[data-camera-reset]').click();assert.ok(stopped);assert.equal(d.querySelector('input').files.length,0);}
 else {
  const e=new w.Event('submit',{cancelable:true});d.querySelector('form').dispatchEvent(e);assert.equal(e.defaultPrevented,true);
  if(moving)recording.stop();else d.querySelector('[data-camera-shot]').click();await tick();
  if(mode==='oversized'){assert.equal(d.querySelector('input').files.length,0);assert.match(d.querySelector('[role=status]').textContent,/too large/);}
  else {assert.equal(d.querySelector('input').files.length,1);assert.match(d.querySelector('[role=status]').textContent,/Captured/);assert.ok(stopped);}
 }
 w.dispatchEvent(new w.Event('pagehide'));w.close();console.log(`PASS ${edition} live capture ${mode}`);
}
