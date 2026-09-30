const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const code = fs.readFileSync(require('node:path').join(__dirname, '../assets/frontend-onetap.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function page({saved = null, blocked = false, missingButton = false, hidden = null, translations = false, languageFailure = false} = {}) {
	const listeners = {};
	const downloads = [];
	const messages = [];
	const replayed = [];
	const fetches = [];
	let languageFailureRemaining = languageFailure;
	const browserWindow = {jQuery: callback => callback(), onetapAjaxObject: {nonce: 'request-only', languages: {}}};
	const attrs = {};
	let opens = 0;
	const toggle = missingButton ? null : {
		setAttribute: (key, value) => { attrs[key] = value; },
		removeAttribute: key => { delete attrs[key]; },
		after: node => messages.push(node),
		click: () => { opens++; },
	};
	const panel = {setAttribute: (key, value) => { panel[key] = value; }};
	const container = {style: {display: 'none'}};
	const sources = ['hotkeys.js', 'script.min.js'].map(url => ({
		dataset: {schrackOnetapSrc: url},
		cloneNode() {
			return {nonce: 'csp-nonce', removeAttribute() {}, remove() { this.removed = true; }};
		},
		after: node => downloads.push(node),
	}));
	const fontAttrs = {'data-schrack-onetap-font-media': 'screen', media: 'not all'};
	const fonts = [{
		getAttribute: key => fontAttrs[key],
		setAttribute: (key, value) => { fontAttrs[key] = value; },
		removeAttribute: key => { delete fontAttrs[key]; },
	}];
	const document = {
		readyState: 'complete',
		querySelectorAll: selector => selector === '[data-schrack-onetap-font-media]' ? fonts : sources,
		querySelector: selector => ({'.onetap-toggle': toggle, 'nav.onetap-accessibility': panel, '.onetap-container-toggle': container, 'script[data-schrack-onetap-languages]': translations ? {getAttribute: () => '/uploads/languages-hash.json'} : null})[selector],
		addEventListener: (name, callback) => { listeners[name] = callback; },
		removeEventListener: name => { delete listeners[name]; },
		createElement: () => ({setAttribute() {}}),
		dispatchEvent: event => replayed.push(event),
	};
	const storage = {getItem(key) {
		if (blocked) throw new Error('storage denied');
		return key === 'onetap-accessibility-free' ? saved : hidden;
	}};
	vm.runInNewContext(code, {document, window: browserWindow, fetch: async (url, options) => {
		fetches.push({url, options});
		const ok = !languageFailureRemaining;
		languageFailureRemaining = false;
		return {ok, json: async () => ({en: {header: {title: 'Accessibility'}}, ro: {header: {title: 'Accesibilitate'}}})};
	}, localStorage: storage, sessionStorage: storage, KeyboardEvent: class { constructor(type, values) { Object.assign(this, {type}, values); } }});
	function event(name, values = {}) {
		const e = {target: {closest: () => true}, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; }, ...values};
		listeners[name]?.(e);
		return e;
	}
	async function complete() {
		await flush();
		downloads[0].onload();
		await flush();
		downloads[1].onload();
		await flush();
	}
	return {listeners, downloads, messages, replayed, toggle, panel, container, attrs, sources, fontAttrs, fetches, browserWindow, event, complete, opens: () => opens};
}

test('public translations download only on activation, before native scripts, with no cookies', async () => {
	const p = page({translations: true});
	await flush();
	assert.equal(p.fetches.length, 0);
	p.event('click');
	p.event('click');
	await flush();
	assert.equal(p.fetches.length, 1);
	assert.equal(p.fetches[0].options.credentials, 'omit');
	assert.equal(p.fetches[0].options.cache, 'force-cache');
	assert.equal(p.browserWindow.onetapAjaxObject.languages.ro.header.title, 'Accesibilitate');
	assert.equal(p.browserWindow.onetapAjaxObject.nonce, 'request-only');
	await p.complete();
	assert.equal(p.opens(), 1);
});

test('translation failures show an error and retry only on user demand', async () => {
	const p = page({translations: true, languageFailure: true});
	p.event('click');
	await flush();
	assert.equal(p.downloads.length, 0);
	assert.match(p.messages[0].textContent, /nu s-au încărcat/);
	assert.equal(p.fetches.length, 1);
	p.event('click');
	await p.complete();
	assert.equal(p.fetches.length, 2);
	assert.equal(p.downloads.length, 2);
});

test('saved preferences load translations immediately before restoring vendor behavior', async () => {
	const p = page({saved: '{}', translations: true});
	await p.complete();
	assert.equal(p.fetches.length, 1);
	assert.equal(p.opens(), 0);
	assert.equal(p.fontAttrs.media, 'screen');
});

test('new visitor gets a focusable toolbar button without downloading libraries', async () => {
	const p = page();
	await flush();
	assert.equal(p.downloads.length, 0);
	assert.equal(p.container.style.display, 'block');
	assert.equal(p.panel.inert, '');
	assert.equal(p.fontAttrs.media, 'not all');
});

test('first activation loads once, in dependency order, then opens the native toolbar', async () => {
	const p = page();
	assert.equal(p.event('click').prevented, true);
	p.event('click');
	await flush();
	assert.equal(p.downloads.length, 1);
	assert.equal(p.downloads[0].src, 'hotkeys.js');
	assert.equal(p.downloads[0].nonce, 'csp-nonce');
	assert.equal(p.attrs['aria-busy'], 'true');
	assert.equal(p.fontAttrs.media, 'screen');
	assert.equal(p.fontAttrs['data-schrack-onetap-font-media'], undefined);
	await p.complete();
	assert.equal(p.downloads[1].src, 'script.min.js');
	assert.equal(p.opens(), 1);
	assert.equal(p.attrs['aria-busy'], undefined);
	assert.equal(p.listeners.click, undefined);
});

test('existing accessibility preferences initialize immediately, without opening the panel', async () => {
	const p = page({saved: '{"biggerText":true}'});
	await p.complete();
	assert.equal(p.downloads.length, 2);
	assert.equal(p.opens(), 0);
	assert.equal(p.fontAttrs.media, 'screen');
});

test('hidden toolbar preferences remain hidden and delegate to the vendor', async () => {
	const p = page({hidden: 'session'});
	await flush();
	assert.equal(p.container.style.display, 'none');
	assert.equal(p.downloads.length, 1);
});

test('blocked storage and missing expected markup retain native startup', async () => {
	for (const options of [{blocked: true}, {missingButton: true}]) {
		const p = page(options);
		await flush();
		assert.equal(p.downloads.length, 1);
	}
});

test('Tab loads accessibility without cancelling keyboard focus movement', async () => {
	const p = page();
	assert.equal(p.event('keydown', {key: 'Tab'}).prevented, undefined);
	await p.complete();
	assert.equal(p.opens(), 0);
});

test('the vendor open shortcut works on the first keypress', async () => {
	const p = page();
	assert.equal(p.event('keydown', {key: '.', altKey: true}).prevented, true);
	await p.complete();
	assert.equal(p.opens(), 1);
});

test('typing and unrelated clicks do not intercept user input or load OneTap', async () => {
	const p = page();
	assert.equal(p.event('keydown', {key: 'a'}).prevented, undefined);
	assert.equal(p.event('click', {target: {closest: () => null}}).prevented, undefined);
	await flush();
	assert.equal(p.downloads.length, 0);
});

test('keyboard navigation shortcuts are replayed after the vendor is ready', async () => {
	for (const shortcut of [{key: 'F11', altKey: true}, {key: 'K', altKey: true, shiftKey: true}]) {
		const p = page();
		assert.equal(p.event('keydown', shortcut).prevented, true);
		await p.complete();
		assert.equal(p.replayed.length, 1);
		assert.equal(p.replayed[0].key, shortcut.key);
	}
});

test('network failure announces an error, retries only on demand and reuses successful dependencies', async () => {
	const p = page();
	p.event('click');
	await flush();
	p.downloads[0].onload();
	await flush();
	p.downloads[1].onerror();
	await flush();
	assert.match(p.messages[0].textContent, /nu s-au încărcat/);
	assert.equal(p.downloads.length, 2);
	p.event('click');
	await flush();
	assert.equal(p.downloads.length, 3);
	assert.equal(p.downloads[2].src, 'script.min.js');
	p.downloads[2].onload();
	await flush();
	assert.equal(p.opens(), 1);
	assert.equal(p.attrs.title, undefined);
});
