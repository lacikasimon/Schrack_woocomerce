/** Browser event/request regressions, without a live store or third-party dependencies. */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function element() {
	const handlers = {}, attrs = {}, classes = new Set();
	return {
		handlers, attrs, value: '', innerHTML: '', hidden: false,
		classList: { toggle(key, enabled) { enabled ? classes.add(key) : classes.delete(key); }, contains(key) { return classes.has(key); } },
		addEventListener(name, fn) { (handlers[name] ||= []).push(fn); },
		fire(name, data = {}) { const event = { target: this, preventDefault() { this.prevented = true; }, ...data }; for (const fn of handlers[name] || []) fn(event); return event; },
		getAttribute(key) { return attrs[key] ?? null; },
		setAttribute(key, value) { attrs[key] = value; },
		hasAttribute(key) { return key in attrs; },
		querySelector() { return null; }, querySelectorAll() { return []; },
		contains(target) { return target === this; }, matches() { return false; }, closest() { return null; },
		blur() {}, remove() { this.removed = true; }
	};
}

function runtime(source, document, extra = {}) {
	const timers = new Map(), calls = [];
	let id = 0, now = 1000;
	const window = {
		AbortController, addEventListener() {}, location: new URL('https://shop.test/shop/?search=old&tracking=keep'),
		history: { state: { keep: true }, replaceState(state, unused, url) { this.url = new URL(url); } },
		setTimeout(fn) { timers.set(++id, fn); return id; }, clearTimeout(id) { timers.delete(id); },
		fetch(url, options) { return new Promise((resolve, reject) => calls.push({ url, options, resolve, reject })); }
	};
	vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/' + source, 'utf8'), { document, window, URL, URLSearchParams, Date: { now: () => now }, ...extra });
	return {
		calls, timers, window,
		advance(ms) { now += ms; },
		runTimers() { const pending = Array.from(timers.values()); timers.clear(); pending.forEach(fn => fn()); },
		async flush() { await new Promise(resolve => setImmediate(resolve)); },
		success(index, html = 'results', extra = {}) { calls[index].resolve({ ok: true, json: async () => ({ success: true, data: { html, ...extra } }) }); }
	};
}

function headerFixture({ controllers = true } = {}) {
	const root = element(), input = element(), results = element(), document = element();
	document.readyState = 'complete';
	Object.assign(root.attrs, { 'data-config': '{"min_chars":3}', 'data-action': 'header_search', 'data-nonce': 'test-only', 'data-ajax-url': '/ajax' });
	root.querySelector = selector => selector === '[data-header-search-input]' ? input : results;
	root.contains = target => [root, input, results].includes(target);
	document.querySelectorAll = () => [root];
	const h = runtime('elementor-header-search.js', document);
	if (!controllers) h.window.AbortController = undefined;
	return { ...h, root, input, results, document, type(text) { input.value = text; input.fire('input'); } };
}

test('header cancels obsolete requests and renders only the newest query, including without AbortController', async () => {
	for (const controllers of [true, false]) {
		const f = headerFixture({ controllers });
		f.type('corp'); f.runTimers();
		f.input.fire('focus'); assert.equal(f.calls.length, 1);
		f.type('cablu');
		if (controllers) assert.equal(f.calls[0].options.signal.aborted, true);
		f.success(0, 'obsolete'); await f.flush(); assert.notEqual(f.results.innerHTML, 'obsolete');
		f.runTimers(); f.success(1, 'current'); await f.flush();
		assert.equal(f.results.innerHTML, 'current'); assert.equal(f.root.attrs['aria-busy'], 'false');
	}
});

test('header results cache expires and never retries failed requests automatically', async () => {
	const f = headerFixture(); f.type('corp'); f.runTimers(); f.success(0, 'cached'); await f.flush();
	f.input.fire('focus'); assert.equal(f.calls.length, 1); assert.equal(f.results.innerHTML, 'cached');
	f.advance(30001); f.input.fire('focus'); assert.equal(f.calls.length, 2);
	f.calls[1].reject(new Error('offline')); await f.flush();
	assert.match(f.results.innerHTML, /Cautarea a esuat/); assert.equal(f.timers.size, 0);
	f.input.fire('focus'); assert.equal(f.calls.length, 3);
});

test('closing, shortening or hiding the header cancels pending work and cannot reopen stale suggestions', async () => {
	for (const close of [f => f.root.fire('keydown', { key: 'Escape' }), f => f.document.fire('click', { target: {} }), f => { f.document.hidden = true; f.document.fire('visibilitychange'); }, f => f.root.fire('submit')]) {
		const f = headerFixture(); f.type('corp'); f.runTimers(); close(f);
		assert.equal(f.calls[0].options.signal.aborted, true);
		f.success(0, 'late'); await f.flush(); assert.equal(f.results.hidden, true);
	}
	const f = headerFixture(); f.type('corp'); f.type('co'); f.runTimers();
	assert.equal(f.calls.length, 0); assert.match(f.results.innerHTML, /3 caractere/);
});

function catalogFixture({ late = true } = {}) {
	const root = element(), form = element(), input = element(), results = element(), facets = element(), document = element();
	const hero = element(), heroInput = element(), header = element(), headerInput = element();
	document.readyState = late ? 'complete' : 'loading';
	Object.assign(root.attrs, { 'data-config': '{"min_search_chars":2}', 'data-action': 'filter_products', 'data-nonce': 'test-only', 'data-ajax-url': '/ajax' });
	facets.attrs['data-facets-category'] = '10';
	const fields = { category: '10', min_price: '50', max_price: '250', stock_filter_present: '1', in_stock_only: 'yes', 'attr[pa_ip][]': '1' };
	root.querySelector = selector => ({ '.schrack-product-filter__form': form, '.schrack-product-filter__results': results, 'input[name="search"]': input, '[data-attribute-facets]': facets }[selector] || null);
	form.querySelector = selector => selector === 'input[name="search"]' ? input : null;
	input.matches = selector => selector.includes('search') && !selector.includes('attribute');
	const external = [hero, header];
	for (const [el, field, className] of [[hero, heroInput, '.schrack-shop-hero__search'], [header, headerInput, '.schrack-header-search__form']]) {
		el.action = 'https://shop.test/shop/';
		el.matches = selector => selector.includes(className);
		el.querySelector = () => field;
	}
	document.querySelector = selector => selector === '.schrack-product-filter' ? root : null;
	document.querySelectorAll = selector => selector === '.schrack-product-filter' ? [root] : external;
	let error = null;
	document.createElement = () => element();
	results.querySelector = selector => selector === '[data-filter-error]' && error && !error.removed ? error : null;
	results.prepend = el => { error = el; };
	results.innerHTML = 'original products';
	const h = runtime('elementor-products.js', document, { FormData: class { constructor() { return Object.entries({ ...fields, search: input.value })[Symbol.iterator](); } } });
	return {
		...h, root, form, input, results, hero, heroInput, header, headerInput, document, fields,
		get error() { return error; },
		submit(text, target = hero) { (target === hero ? heroInput : headerInput).value = text; return document.fire('submit', { target }); }
	};
}

test('late-loaded catalog search initializes once and hero/header submit through AJAX while retaining filters and input nodes', async () => {
	const f = catalogFixture(); assert.equal(f.root.attrs['data-filter-ready'], 'yes');
	const inputNode = f.input; assert.equal(f.submit('corp').prevented, true);
	const body = new URLSearchParams(f.calls[0].options.body);
	assert.equal(body.get('search'), 'corp'); assert.equal(body.get('category'), '10'); assert.equal(body.get('min_price'), '50');
	assert.equal(body.get('attr[pa_ip][]'), '1'); assert.equal(body.get('facets_category'), '10'); assert.equal(body.get('paged'), '1');
	assert.equal(f.headerInput.value, 'corp'); assert.equal(f.input, inputNode);
	f.success(0, 'matching products', { facets_html: null }); await f.flush();
	assert.equal(f.results.innerHTML, 'matching products');
	const url = f.window.history.url; assert.equal(url.searchParams.get('search'), 'corp'); assert.equal(url.searchParams.get('tracking'), 'keep');
	assert.equal(url.searchParams.get('nonce'), null); assert.equal(url.searchParams.get('config'), null);
	f.submit('cablu', f.header); assert.equal(f.calls.length, 2); assert.equal(f.heroInput.value, 'cablu');
	assert.equal(f.form.handlers.submit.length, 1);
});

test('catalog typing aborts requests immediately, cached results avoid duplicate reads and short terms issue no request', async () => {
	const f = catalogFixture(); f.submit('corp'); f.input.value = 'cablu'; f.form.fire('input', { target: f.input });
	assert.equal(f.calls[0].options.signal.aborted, true);
	f.success(0, 'old'); await f.flush(); assert.equal(f.results.innerHTML, 'original products');
	f.runTimers(); f.success(1, 'cables'); await f.flush();
	f.submit('cablu'); assert.equal(f.calls.length, 2); assert.equal(f.results.innerHTML, 'cables');
	f.submit('c'); assert.equal(f.calls.length, 2); assert.match(f.results.innerHTML, /Continuă căutarea/);
});

test('catalog failure retains products, filters and URL, and manual retry recovers without navigation', async () => {
	const f = catalogFixture(); f.submit('corp'); f.calls[0].reject(new Error('offline')); await f.flush();
	assert.equal(f.results.innerHTML, 'original products'); assert.match(f.error.innerHTML, /Încearcă din nou/);
	assert.equal(f.window.history.url, undefined); assert.equal(f.fields.category, '10'); assert.equal(f.timers.size, 0);
	f.root.fire('click', { target: { closest: selector => selector === '[data-filter-retry]' ? f.error : null } });
	assert.equal(f.calls.length, 2); f.success(1, 'recovered'); await f.flush(); assert.equal(f.results.innerHTML, 'recovered'); assert.equal(f.error.removed, true);
});

test('search targeting a different category/shop and pages without a catalog keep intentional navigation', () => {
	const f = catalogFixture(); f.header.action = 'https://shop.test/categorie-produs/lighting/';
	assert.equal(f.submit('corp', f.header).prevented, undefined); assert.equal(f.calls.length, 0);
	f.document.querySelector = () => null; assert.equal(f.submit('corp').prevented, undefined); assert.equal(f.calls.length, 0);
});
