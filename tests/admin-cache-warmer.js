const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
function harness() {
	const timers = new Map(), pending = [], calls = [], listeners = {};
	let id = 0;
	const element = () => ({textContent: '', value: '', checked: false, disabled: false, children: [], events: {}, addEventListener(k,f) { this.events[k]=f; }, appendChild(e) { this.children.push(e); }, replaceChildren() { this.children=[]; }});
	const elements = {};
	for (const name of ['schrack-cache-warmer','schrack-cache-form','schrack-cache-message','schrack-cache-status','schrack-cache-results','schrack-profile-url','schrack-profile-result']) elements[name] = element();
	const buttons = ['start','stop','profile'].map(command=>Object.assign(element(),{dataset:{command}}));
	elements['schrack-cache-warmer'].querySelectorAll=()=>buttons;
	elements['schrack-cache-form'].elements={urls:{value:'unsaved input'},enabled:{checked:false}};
	const document = {hidden:false,getElementById:n=>elements[n],createElement:element,addEventListener:(k,f)=>listeners[k]=f};
	const context = {document,URLSearchParams,AbortController,schrackCacheWarmer:{ajax:'/ajax',nonce:'nonce'},setTimeout:(f,ms)=>{timers.set(++id,{f,ms});return id;},clearTimeout:n=>timers.delete(n),fetch:(url,args)=>{calls.push(Object.fromEntries(args.body));return new Promise((resolve,reject)=>pending.push({resolve,reject}));}};
	vm.runInNewContext(fs.readFileSync(require.resolve('../assets/admin-cache-warmer.js'),'utf8'),context);
	const flush = async()=>{for(let i=0;i<8;i++) await Promise.resolve();};
	const resolve = async(state='running')=>{pending.shift().resolve({ok:true,json:async()=>({success:true,data:{state:{status:state,urls:['/'],cursor:0,results:[]},config:{urls:['/']}}})});await flush();};
	const fire = async()=>{const entry=[...timers].find(([,t])=>t.ms!==35000);if(entry){timers.delete(entry[0]);entry[1].f();await flush();}};
	return {timers,pending,calls,listeners,elements,buttons,document,flush,resolve,fire};
}
test('polling is sequential, preserves input and stops on completion',async()=>{
	const h=harness(); assert.equal(h.calls.length,1); await h.fire(); assert.equal(h.calls.length,1);
	await h.resolve(); assert.equal(h.elements['schrack-cache-form'].elements.urls.value,'unsaved input');
	await h.fire(); assert.equal(h.calls.length,2); await h.resolve('complete'); assert.equal(h.timers.size,0);
});
test('hidden tabs pause and resume with a read only request',async()=>{
	const h=harness(); await h.resolve(); h.document.hidden=true; h.listeners.visibilitychange(); await h.fire(); assert.equal(h.calls.length,1);
	h.document.hidden=false;h.listeners.visibilitychange();await h.fire();assert.equal(h.calls[1].command,'status');
});
test('uncertain mutation is reconciled without automatic replay',async()=>{
	const h=harness();await h.resolve('idle');h.buttons[0].events.click();await h.flush();assert.equal(h.calls[1].command,'start');
	h.pending.shift().reject(new Error('timeout'));await h.flush();await h.fire();assert.equal(h.calls[2].command,'status');
	assert.equal(h.calls.filter(c=>c.command==='start').length,1);assert.match(h.elements['schrack-cache-message'].textContent,/nu a fost confirmată/);
});
test('status failures back off with visible feedback',async()=>{
	const h=harness();h.pending.shift().reject(new Error('offline'));await h.flush();assert.ok([...h.timers.values()].some(t=>t.ms===8000));
	await h.fire();h.pending.shift().reject(new Error('offline'));await h.flush();assert.ok([...h.timers.values()].some(t=>t.ms===16000));assert.match(h.elements['schrack-cache-status'].textContent,/offline/);
});
