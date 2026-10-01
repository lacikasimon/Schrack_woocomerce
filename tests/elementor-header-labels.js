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

function boot(initial = [], frames = false, {scope = 'home', mobile = true} = {}) {
	let callback;
	const body = element('', initial);
	body.classList = {contains(name) { return name === scope; }};
	const document = {
		readyState: 'complete', body, queries: 0,
		querySelectorAll(selector) { this.queries++; return body.querySelectorAll(selector); },
		addEventListener() {}
	};
	class Observer {
		constructor(fn) { callback = fn; }
		observe(node, options) { assert.equal(node, body); assert.deepEqual({...options}, {childList: true, subtree: true}); }
	}
	const queued = [];
	const window = {MutationObserver: Observer, matchMedia() { return {matches: mobile}; }};
	if (frames) window.requestAnimationFrame = fn => queued.push(fn);
	vm.runInNewContext(source, {document, window, MutationObserver: Observer});
	return {document, frame() { const batch = queued.splice(0); batch.forEach(fn => fn()); }, insert(...nodes) { callback([{addedNodes: nodes, removedNodes: []}]); }, mutation(records) { callback(records); }};
}

test('initial header setup yields a paint; native and later inserted labels remain correct', () => {
	const button = element('cookieadmin_re_consent');
	const page = boot([button], true);
	assert.equal(button.writes, 0); page.frame(); assert.equal(button.writes, 0);
	page.frame(); assert.equal(button.getAttribute('aria-label'), 'Modifica preferintele cookie');
	const inserted = element('cookieadmin_close_pref'); page.insert(inserted);
	assert.equal(inserted.getAttribute('aria-label'), 'Inchide preferintele cookie');
});

test('archives and desktop retain immediate startup; mobile single products yield', () => {
	for (const options of [{scope: 'category'}, {scope: 'home', mobile: false}]) {
		const button = element('cookieadmin_re_consent'); boot([button], true, options);
		assert.equal(button.getAttribute('aria-label'), 'Modifica preferintele cookie');
	}
	const button = element('cookieadmin_re_consent');
	const page = boot([button], true, {scope: 'single-product'});
	assert.equal(button.writes, 0); page.frame(); page.frame();
	assert.equal(button.getAttribute('aria-label'), 'Modifica preferintele cookie');
});

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
