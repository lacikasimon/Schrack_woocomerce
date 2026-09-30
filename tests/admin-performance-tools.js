const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
function harness() {
 const timers=new Map(), pending=[], calls=[], listeners={};let sequence=0;
 const elements={};const element=()=>({textContent:'',disabled:false,dataset:{},events:{},addEventListener(k,fn){this.events[k]=fn;}});
 for(const id of ['schrack-performance-tools','schrack-performance-state','schrack-performance-message','schrack-seo-audit'])elements[id]=element();
 const buttons=['apply','archive','restore','seo_audit'].map(operation=>Object.assign(element(),{dataset:{operation}}));elements['schrack-performance-tools'].querySelectorAll=()=>buttons;
 const document={hidden:false,getElementById:id=>elements[id],addEventListener:(k,fn)=>listeners[k]=fn};
 const context={document,URLSearchParams,AbortController,schrackPerformanceTools:{ajax:'/ajax',nonce:'fixture'},setTimeout:(fn,ms)=>{timers.set(++sequence,{fn,ms});return sequence;},clearTimeout:id=>timers.delete(id),fetch:(url,args)=>{calls.push(Object.fromEntries(args.body));return new Promise((resolve,reject)=>pending.push({resolve,reject}));}};
 vm.runInNewContext(fs.readFileSync(require.resolve('../assets/admin-performance-tools.js'),'utf8'),context);
 const flush=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
 const resolve=async(data={search_index:{status:'running'},log_archive:{status:'complete'}})=>{pending.shift().resolve({ok:true,json:async()=>({success:true,data})});await flush();};
 const fire=async()=>{const next=[...timers].find(([,t])=>t.fn.toString().includes("run('status')"));if(next){timers.delete(next[0]);next[1].fn();await flush();}};
 return {document,timers,pending,calls,listeners,buttons,elements,resolve,fire,flush};
}
test('status reads never overlap and stop when all jobs finish',async()=>{const h=harness();await h.fire();assert.equal(h.calls.length,1);await h.resolve();await h.fire();assert.equal(h.calls.length,2);await h.resolve({search_index:{status:'complete'},log_archive:{status:'complete'}});assert.equal(h.timers.size,0);});
test('hidden tabs pause polling and resume with a read only operation',async()=>{const h=harness();await h.resolve();h.document.hidden=true;h.listeners.visibilitychange();await h.fire();assert.equal(h.calls.length,1);h.document.hidden=false;h.listeners.visibilitychange();await h.fire();assert.equal(h.calls[1].operation,'status');});
test('uncertain archive operation is reconciled without replay',async()=>{const h=harness();await h.resolve({});h.buttons[1].events.click();await h.flush();assert.equal(h.calls[1].operation,'archive');h.pending.shift().reject(new Error('offline'));await h.flush();await h.fire();assert.equal(h.calls[2].operation,'status');assert.equal(h.calls.filter(c=>c.operation==='archive').length,1);assert.match(h.elements['schrack-performance-message'].textContent,/nu a fost confirmată/);});
test('failed status reads visibly back off',async()=>{const h=harness();h.pending.shift().reject(new Error('offline'));await h.flush();assert.ok([...h.timers.values()].some(t=>t.ms===30000));await h.fire();h.pending.shift().reject(new Error('offline'));await h.flush();assert.ok([...h.timers.values()].some(t=>t.ms===60000));assert.match(h.elements['schrack-performance-message'].textContent,/offline/);});
test('SEO audit returns to background status polling without changing inputs',async()=>{const h=harness();await h.resolve();h.buttons[3].events.click();await h.flush();await h.resolve({seo_audit:{post_metadata:[]}});assert.match(h.elements['schrack-seo-audit'].textContent,/post_metadata/);await h.fire();assert.equal(h.calls[2].operation,'status');});
