const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../assets/frontend-attribute-options.js', 'utf8');
function fixture() {
	const handlers = {}, calls = [];
	let replaced = false, retry = null, focus = null;
	const summary = {tagName:'SUMMARY', focus(){focus='summary';}};
	const options = {tagName:'DIV'};
	const slot = {setAttribute(){},removeAttribute(){},contains(el){return el && el===retry;},appendChild(el){retry=el;},replaceWith(){replaced=true;}};
	const root = {getAttribute(name){return {'data-attribute-action':'facet_read','data-nonce':'nonce','data-ajax-url':'/wp-admin/admin-ajax.php'}[name];}};
	const group = {open:true,isConnected:true,category:'10',getAttribute(name){return name==='data-attribute-category'?this.category:'pa_ip';},matches(){return true;},closest(){return root;},querySelector(selector){return selector==='summary'?summary:selector==='[data-attribute-options-pending]'&&!replaced?slot:null;}};
	const incoming = {children:[summary,options],getAttribute(name){return name==='data-attribute-category'?'10':'pa_ip';},querySelector(){return options;}};
	const document = {activeElement:null,addEventListener(name,fn){handlers[name]=fn;},createDocumentFragment(){return {appendChild(){}};},createElement(name){return name==='template'?{content:{querySelectorAll(){return [incoming];}}}:{setAttribute(){},focus(){focus='retry';},closest(){return group;}};}};
	vm.runInNewContext(source,{document,window:{setTimeout(){return 1;},clearTimeout(){}},URLSearchParams,AbortController,fetch(url,config){return new Promise((resolve,reject)=>calls.push({url,config,resolve,reject}));}});
	return {handlers,calls,group,document,get replaced(){return replaced;},get retry(){return retry;},get focus(){return focus;},open(){handlers.toggle({target:group});},success(index=0){calls[index].resolve({ok:true,json:async()=>({success:true,data:{html:'safe server fixture'}})});},async flush(){await new Promise(resolve=>setImmediate(resolve));}};
}
test('closed first-response controls make no network request; opening deduplicates reads',async()=>{
	const f=fixture();assert.equal(f.calls.length,0);f.group.open=false;f.open();assert.equal(f.calls.length,0);
	f.group.open=true;f.open();f.open();assert.equal(f.calls.length,1);
	const body=f.calls[0].config.body;assert.equal(body.get('taxonomy'),'pa_ip');assert.equal(body.get('category'),'10');assert.equal(body.get('nonce'),'nonce');
	f.success();await f.flush();assert.equal(f.replaced,true);f.open();assert.equal(f.calls.length,1);
});
test('a replaced or changed category never receives stale values',async()=>{
	for (const mutation of [f=>f.group.isConnected=false,f=>f.group.category='11']) {
		const f=fixture();f.open();mutation(f);f.success();await f.flush();assert.equal(f.replaced,false);
	}
});
test('read failures offer manual retry, with no automatic repeat or page reload',async()=>{
	const f=fixture();f.open();f.calls[0].reject(new Error('network'));await f.flush();assert.equal(f.calls.length,1);assert.ok(f.retry);
	f.document.activeElement=f.retry;f.handlers.click({target:{closest(){return f.retry;}}});assert.equal(f.calls.length,2);
	f.success(1);await f.flush();assert.equal(f.replaced,true);assert.equal(f.focus,'summary');
});
test('malformed server payload does not replace controls',async()=>{
	const f=fixture();f.open();f.calls[0].resolve({ok:true,json:async()=>({success:false})});await f.flush();assert.equal(f.replaced,false);assert.ok(f.retry);
});
