/* Shipped navigation: first paint, scroll direction and late/Elementor startup. */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/elementor-featured-categories.js'), 'utf8');

function boot({readyState = 'loading', hidden = false, frames = true, intersection = true} = {}) {
	const listeners = {}, queued = [], observers = [];
	let hook;
	const hero = {children: [], appendChild(node) { this.children.push(node); }};
	const nav = {fixed: false, classList: {toggle(name, value) { assert.equal(name, 'is-fixed'); nav.fixed = value; }}};
	const attrs = {};
	const root = {
		getAttribute(name) { return attrs[name] ?? null; },
		setAttribute(name, value) { attrs[name] = value; },
		querySelector(selector) { return selector === '[data-fcat-hero]' ? hero : nav; }
	};
	const document = {
		readyState, hidden,
		addEventListener(name, fn) { listeners[name] = fn; },
		querySelectorAll() { return [root]; },
		createElement() { return {style: {}, setAttribute() {}}; }
	};
	class Observer {
		constructor(fn) { this.fn = fn; observers.push(this); }
		observe(node) { this.node = node; }
	}
	const window = {elementorFrontend: {hooks: {addAction(name, fn) { hook = fn; }}}};
	if (frames) window.requestAnimationFrame = fn => queued.push(fn);
	if (intersection) window.IntersectionObserver = Observer;
	const context = {document, window, IntersectionObserver: Observer};
	vm.runInNewContext(source, context);
	return {
		hero, nav, observers,
		ready() { listeners.DOMContentLoaded?.(); },
		frame() { const batch = queued.splice(0); batch.forEach(fn => fn()); },
		entry(isIntersecting, bottom) { observers[0].fn([{isIntersecting, boundingClientRect: {bottom}}]); },
		elementor() { hook([{querySelectorAll() { return [root]; }}]); },
		duplicate() { vm.runInNewContext(source, context); }
	};
}

test('initial artwork gets a paint before the sticky sentinel is inserted', () => {
	const page = boot(); page.ready();
	assert.equal(page.hero.children.length, 0);
	page.frame(); assert.equal(page.hero.children.length, 0);
	page.frame(); assert.equal(page.hero.children.length, 1);
	assert.equal(page.observers.length, 1);
});

test('only a hero passed above the viewport fixes navigation; scrolling back restores it', () => {
	const page = boot({hidden: true}); page.ready();
	page.entry(false, 1200); assert.equal(page.nav.fixed, false);
	page.entry(true, 400); assert.equal(page.nav.fixed, false);
	page.entry(false, -10); assert.equal(page.nav.fixed, true);
	page.entry(true, 30); assert.equal(page.nav.fixed, false);
});

test('late scripts, hidden pages and older browsers all initialize navigation', () => {
	for (const options of [{readyState: 'complete', hidden: true}, {hidden: true}, {frames: false}]) {
		const page = boot(options); page.ready();
		assert.equal(page.observers.length, 1);
	}
});

test('Elementor and repeated script startup share one observer and sentinel', () => {
	const page = boot(); page.elementor(); page.ready(); page.frame(); page.frame();
	page.duplicate(); page.ready(); page.frame(); page.frame(); page.elementor();
	assert.equal(page.hero.children.length, 1);
	assert.equal(page.observers.length, 1);
});

test('without intersection observation the original navigation remains usable', () => {
	const page = boot({intersection: false, hidden: true}); page.ready();
	assert.equal(page.nav.fixed, false);
	assert.equal(page.hero.children.length, 0);
	assert.equal(page.observers.length, 0);
});
