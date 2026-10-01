const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const code = require('node:fs').readFileSync(require('node:path').join(__dirname, '../assets/frontend-consent-banner.js'),'utf8');
function page({cookie='', blocked=false, state='loading', native=true}={}) {
 const listeners={},timers=[],attrs={},messages=[]; let calls=0;
 const button={isConnected:true,setAttribute:(k,v)=>{attrs[k]=v;},removeAttribute:k=>{delete attrs[k];},click:()=>{calls++;}};
 const banner={style:{display:'none'},contains:e=>e===button,appendChild:e=>messages.push(e)};
 const document={readyState:state,get cookie(){if(blocked)throw Error('blocked');return cookie;},querySelector:()=>banner,addEventListener:(n,f)=>{listeners[n]=f;},removeEventListener:n=>{delete listeners[n];},createElement:()=>({setAttribute(){}})};
 const window={setTimeout:f=>timers.push(f),cookieadmin_set_consent:native?()=>{}:undefined};
 vm.runInNewContext(code,{document,window});
 const click=()=>{const e={target:{closest:()=>button},preventDefault(){this.prevented=true;},stopImmediatePropagation(){this.stopped=true;}};listeners.click?.(e);return e;};
 return{banner,attrs,button,messages,click,listeners,timers,calls:()=>calls,complete(){listeners.DOMContentLoaded?.();timers.splice(0).forEach(f=>f());}};
}
test('new visitor sees existing notice before DOMContentLoaded with no consent write',()=>{const p=page();assert.equal(p.banner.style.display,'block');assert.equal(p.calls(),0);p.complete();assert.equal(p.calls(),0);assert.equal(p.listeners.click,undefined);});
test('an early native choice is queued once and replayed after all native handlers',()=>{const p=page();assert.equal(p.click().prevented,true);p.click();assert.equal(p.calls(),0);assert.equal(p.attrs['aria-busy'],'true');p.listeners.DOMContentLoaded();assert.equal(p.calls(),0);p.timers.shift()();assert.equal(p.calls(),1);assert.equal(p.attrs['aria-busy'],undefined);});
test('saved choices, including legacy truthy values, stay hidden on cached HTML',()=>{for(const v of [{reject:'true'},{accept:'true'},{analytics:'true'},{consent:'legacy'},{},[],true,'legacy']){const p=page({cookie:'cookieadmin_consent='+encodeURIComponent(JSON.stringify(v))});assert.equal(p.banner.style.display,'none');assert.equal(p.listeners.click,undefined);}});
test('malformed and false cookies behave like native new-choice startup',()=>{for(const v of ['%oops','not-json','false','null','0','""']){const p=page({cookie:'cookieadmin_consent='+v});assert.equal(p.banner.style.display,'block');}});
test('blocked cookie access and already-ready document keep native startup',()=>{for(const o of [{blocked:true},{state:'complete'}]){const p=page(o);assert.equal(p.banner.style.display,'none');assert.equal(p.listeners.click,undefined);}});
test('navigation or failed vendor scripts never replay consent writes',()=>{const gone=page();gone.click();gone.button.isConnected=false;gone.complete();assert.equal(gone.calls(),0);const failed=page({native:false});failed.click();failed.complete();assert.equal(failed.calls(),0);assert.match(failed.messages[0].textContent,/nu sunt disponibile/);});
