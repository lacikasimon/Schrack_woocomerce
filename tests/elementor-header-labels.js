/* Execute the shipped observer against connected, nested and removed DOM fixtures. */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/elementor-header.js'), 'utf8');

function element(className = '', children = [], label = '') {
	const attributes = new Map(label ? [['aria-label', label]] : []);
	return {
		nodeType: 1, isConnected: true, children, queries: 0, writes: 0,
		matches(selector) { return selector === '.' + className; },
		getAttribute(name) { return attributes.get(name) ?? null; },
		setAttribute(name, value) { this.writes++; attributes.set(name, value); },
		querySelectorAll(selector) {
			this.queries++;
			return children.flatMap(child => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]);
		}
	};
}

function boot(initial = []) {
	let callback;
	const body = element('', initial);
	const document = {
		readyState: 'complete', body, queries: 0,
		querySelectorAll(selector) { this.queries++; return body.querySelectorAll(selector); },
		addEventListener() {}
	};
	class Observer {
		constructor(fn) { callback = fn; }
		observe(node, options) { assert.equal(node, body); assert.deepEqual({...options}, {childList: true, subtree: true}); }
	}
	vm.runInNewContext(source, {document, window: {MutationObserver: Observer}, MutationObserver: Observer});
	return {document, insert(...nodes) { callback([{addedNodes: nodes, removedNodes: []}]); }, mutation(records) { callback(records); }};
}

test('existing native buttons get initial labels; explicit custom labels remain', () => {
	const close = element('cookieadmin_close_pref');
	const custom = element('cookieadmin_re_consent', [], 'Custom label');
	boot([close, custom]);
	assert.equal(close.getAttribute('aria-label'), 'Inchide preferintele cookie');
	assert.equal(custom.getAttribute('aria-label'), 'Custom label');
	assert.equal(custom.writes, 0);
});

test('direct and nested native button insertions are labeled without a document rescan', () => {
	const page = boot();
	const queries = page.document.queries;
	const direct = element('cookieadmin_re_consent');
	const nested = element('cookieadmin_close_pref');
	page.insert(direct, element('', [element('', [nested])]));
	assert.equal(direct.getAttribute('aria-label'), 'Modifica preferintele cookie');
	assert.equal(nested.getAttribute('aria-label'), 'Inchide preferintele cookie');
	assert.equal(page.document.queries, queries);
	page.insert(direct);
	assert.equal(direct.writes, 1);
});

test('removed, disconnected and text nodes do not cause scans; reinsertion works', () => {
	const page = boot();
	const detached = element('cookieadmin_close_pref'); detached.isConnected = false;
	page.mutation([{addedNodes: [detached, {nodeType: 3}], removedNodes: [element('cookieadmin_re_consent')]}]);
	assert.equal(detached.queries, 0); assert.equal(detached.writes, 0);
	detached.isConnected = true; page.insert(detached);
	assert.equal(detached.getAttribute('aria-label'), 'Inchide preferintele cookie');
});

test('repeated unrelated AJAX updates never scan the complete catalog again', () => {
	const page = boot([element('', Array.from({length: 1000}, () => element()))]);
	const queries = page.document.queries;
	for (let i = 0; i < 100; i++) page.insert(element('', [element()]));
	assert.equal(page.document.queries, queries);
});
