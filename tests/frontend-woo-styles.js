const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/frontend-woo-styles.js'), 'utf8');

function boot({readyState = 'loading', hidden = false, frames = true, missing = false, deferred = false, idle = true} = {}) {
	function target() {
		const listeners = new Map();
		return {
			addEventListener(name, fn, options = {}) { listeners.set(name, {fn, options}); },
			removeEventListener(name, fn) { if (listeners.get(name)?.fn === fn) listeners.delete(name); },
			fire(name) { const entry = listeners.get(name); if (!entry) return; if (entry.options.once) listeners.delete(name); entry.fn(); },
			listeners
		};
	}
	const document = Object.assign(target(), {readyState, hidden});
	const attributes = new Map([['media', 'not all'], ['data-schrack-woo-media', 'screen'], ['href', '/native-woocommerce.css']]);
	if (deferred) { attributes.delete('href'); attributes.set('data-schrack-woo-deferred-href', '/native-woocommerce.css'); }
	const link = Object.assign(target(), {
		writes: 0, hasAttribute(name) { return attributes.has(name); },
		getAttribute(name) { return attributes.get(name) ?? null; },
		setAttribute(name, value) { if (name === 'media') this.writes++; attributes.set(name, value); }
	});
	document.getElementById = () => missing ? null : link;
	const queued = [];
	const idleTasks = [];
	const window = target();
	window.setTimeout = fn => idleTasks.push(fn);
	if (idle) window.requestIdleCallback = fn => idleTasks.push(fn);
	if (frames) window.requestAnimationFrame = fn => queued.push(fn);
	const context = {document, window};
	vm.runInNewContext(source, context);
	return {document, window, link, queued, idleTasks, idle() { idleTasks.shift()?.(); }, frame() { queued.shift()?.(); }, duplicate() { vm.runInNewContext(source, context); }};
}

test('complete native CSS activates after DOM ready and two paint opportunities', () => {
	const page = boot();
	assert.equal(page.link.getAttribute('media'), 'not all');
	page.document.fire('DOMContentLoaded');
	assert.equal(page.queued.length, 1);
	page.frame(); assert.equal(page.link.getAttribute('media'), 'not all');
	page.frame(); assert.equal(page.link.getAttribute('media'), 'screen');
	assert.equal(page.link.getAttribute('href'), '/native-woocommerce.css');
	assert.equal(page.link.writes, 1);
});

test('early keyboard or pointer input restores native media immediately, once, without cancelling input', () => {
	for (const event of ['pointerdown', 'keydown']) {
		const page = boot();
		assert.equal(page.document.listeners.get(event).options.passive, true);
		page.document.fire(event);
		assert.equal(page.link.getAttribute('media'), 'screen');
		assert.equal(page.document.listeners.has('pointerdown'), false);
		assert.equal(page.document.listeners.has('keydown'), false);
		page.document.fire('DOMContentLoaded'); page.frame(); page.frame();
		assert.equal(page.link.writes, 1);
	}
});

test('hidden tabs and browsers without animation frames retain prompt native styling', () => {
	for (const options of [{hidden: true}, {frames: false}]) {
		const page = boot(options); page.document.fire('DOMContentLoaded');
		assert.equal(page.link.getAttribute('media'), 'screen');
		assert.equal(page.queued.length, 0);
	}
});

test('CSS failure promotes the native fallback; duplicate scripts add no second initialization', () => {
	const page = boot({readyState: 'complete'});
	page.duplicate(); assert.equal(page.queued.length, 1);
	page.link.fire('error'); assert.equal(page.link.getAttribute('media'), 'screen');
	page.frame(); page.frame(); assert.equal(page.link.writes, 1);
	assert.equal(boot({missing: true}).queued.length, 0);
});

test('deferred full CSS does not fetch at DOM ready or reapply styles after background load', () => {
	const page = boot({deferred: true});
	page.document.fire('DOMContentLoaded'); page.frame(); page.frame();
	assert.equal(page.link.getAttribute('href'), null);
	page.window.fire('load'); assert.equal(page.link.getAttribute('href'), null);
	page.idle(); assert.equal(page.link.getAttribute('href'), '/native-woocommerce.css');
	assert.equal(page.link.getAttribute('media'), 'not all');
	page.link.fire('load'); assert.equal(page.link.getAttribute('media'), 'not all');
	page.document.fire('pointerdown'); assert.equal(page.link.getAttribute('media'), 'screen');
});

test('early pointer or keyboard input fetches and activates deferred CSS immediately', () => {
	for (const event of ['pointerdown', 'keydown']) {
		const page = boot({deferred: true}); page.document.fire(event);
		assert.equal(page.link.getAttribute('href'), '/native-woocommerce.css');
		assert.equal(page.link.getAttribute('media'), 'screen');
		page.window.fire('load'); assert.equal(page.idleTasks.length, 0);
		page.link.fire('load'); assert.equal(page.link.writes, 1);
	}
});

test('deferred CSS supports late startup, hidden tabs and browsers without idle callbacks', () => {
	for (const options of [{readyState: 'complete'}, {idle: false}]) {
		const page = boot({deferred: true, ...options}); page.window.fire('load'); page.idle(); page.window.fire('beforeprint');
		assert.equal(page.link.getAttribute('media'), 'screen');
	}
	const hidden = boot({deferred: true, hidden: true});
	assert.equal(hidden.link.getAttribute('href'), '/native-woocommerce.css');
	assert.equal(hidden.link.getAttribute('media'), 'screen');
});

test('deferred background startup is idempotent and preserves native URL on error', () => {
	const page = boot({deferred: true}); page.duplicate(); page.window.fire('load');
	assert.equal(page.idleTasks.length, 1); page.idle(); page.link.fire('error');
	assert.equal(page.link.getAttribute('href'), '/native-woocommerce.css');
	assert.equal(page.link.getAttribute('media'), 'screen');
	assert.equal(page.link.writes, 1);
});
