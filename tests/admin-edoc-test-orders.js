const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
function harness() {
	const timers = new Map(), pending = [], calls = [], listeners = {}; let id = 0;
	const element = tag => ({tag, textContent: '', hidden: false, dataset: {}, children: [], events: {}, attrs: {},
		appendChild(child) {this.children.push(child);}, setAttribute(k,v) {this.attrs[k] = v;}, addEventListener(k,f) {this.events[k] = f;},
		querySelector(selector) {return selector === 'a' ? this.children.find(e=>e.tag === 'a') : this.children.find(e=>e.dataset.key === selector.match(/data-key="([^"]+)"/)[1]);}
	});
	const elements = {};
	for (const name of ['schrack-edoc-test-orders','edoc-test-prepare','edoc-test-run','edoc-test-message','edoc-test-progress','edoc-test-months','edoc-test-results']) elements[name] = element('div');
	const document = {hidden: false, getElementById: name => elements[name], createElement: element, addEventListener: (k,f) => listeners[k] = f};
	const context = {document, URLSearchParams, AbortController, schrackEDocTestOrders: {url: '/ajax', nonce: 'nonce'},
		setTimeout: (f,ms) => {timers.set(++id,{f,ms});return id;}, clearTimeout: id => timers.delete(id),
		fetch: (url,args) => {calls.push(args.body ? Object.fromEntries(args.body) : Object.fromEntries(new URLSearchParams(url.split('?')[1])));return new Promise((resolve,reject)=>pending.push({resolve,reject}));}
	};
	vm.runInNewContext(fs.readFileSync(require.resolve('../assets/admin-edoc-test-orders.js'),'utf8'), context);
	const flush = async()=>{for(let i=0;i<12;i++) await Promise.resolve();};
	const state = (cursor=0,status='ready')=>({batch:'batch',cursor,total:2,status,confirmed:0,edoc_enabled:true,months:[{month:'2026-06',planned:2,created:cursor,confirmed:0}],orders:cursor?[{id:1,date:'2026-06-03',name:'TEST Alex',total:'12 RON',url:'/order/1',confirmed:false}]:[]});
	const resolve = async data=>{pending.shift().resolve({ok:true,json:async()=>({success:true,data})});await flush();};
	const fire = async()=>{const next=[...timers].find(([,timer])=>timer.ms!==30000);if(next){timers.delete(next[0]);next[1].f();await flush();}};
	return {timers,pending,calls,listeners,elements,document,flush,state,resolve,fire};
}
test('creation advances sequentially and final ERP polling is read-only and bounded',async()=>{
	const h=harness();await h.resolve(h.state(0,'idle'));h.elements['edoc-test-prepare'].events.click();await h.resolve(h.state());
	assert.equal(h.calls[1].command,'prepare');h.elements['edoc-test-run'].events.click();h.elements['edoc-test-run'].events.click();
	assert.equal(h.calls.length,3);assert.equal(h.calls[2].cursor,'0');await h.resolve(h.state(1,'running'));await h.fire();
	assert.equal(h.calls[3].command,'step');assert.equal(h.calls[3].cursor,'1');await h.resolve(h.state(2,'complete'));await h.fire();
	assert.equal(h.calls[4].command,'status');await h.resolve({...h.state(2,'complete'),confirmed:2});assert.equal(h.timers.size,0);
});
test('an uncertain write is never repeated; explicit resume uses reconciled cursor',async()=>{
	const h=harness();await h.resolve(h.state());h.elements['edoc-test-run'].events.click();h.pending.shift().reject(Error('timeout'));await h.flush();
	await h.fire();assert.equal(h.calls[2].command,'status');await h.resolve(h.state(1,'running'));assert.equal(h.timers.size,0);
	assert.match(h.elements['edoc-test-message'].textContent,/numai din buton/);h.elements['edoc-test-run'].events.click();assert.equal(h.calls[3].cursor,'1');
});
test('hidden tabs stop automatic writes and visibility only reconciles the state',async()=>{
	const h=harness();await h.resolve(h.state());h.elements['edoc-test-run'].events.click();h.document.hidden=true;h.listeners.visibilitychange();await h.resolve(h.state(1,'running'));
	assert.equal(h.timers.size,0);h.document.hidden=false;h.listeners.visibilitychange();await h.fire();assert.equal(h.calls[2].command,'status');
	await h.resolve(h.state(1,'running'));assert.equal(h.timers.size,0);
});
test('read failures back off, while result links preserve their DOM identity',async()=>{
	const h=harness();h.pending.shift().reject(Error('offline'));await h.flush();assert.ok([...h.timers.values()].some(t=>t.ms===8000));
	await h.fire();h.pending.shift().reject(Error('offline'));await h.flush();assert.ok([...h.timers.values()].some(t=>t.ms===16000));
	await h.fire();await h.resolve(h.state(1,'running'));const first=h.elements['edoc-test-results'].children[0].children[0].children[0];
	h.listeners.visibilitychange();await h.fire();await h.resolve({...h.state(1,'running'),confirmed:1});assert.equal(h.elements['edoc-test-results'].children[0].children[0].children[0],first);
});
