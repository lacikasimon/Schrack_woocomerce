// Run: node --test tests/frontend-lazy-images.js
// Exercise observer lifecycle and URL assignment without a browser or network.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname, '../assets/frontend-lazy-images.js'), 'utf8');

function image(attributes = {}) {
	return {
		nodeType: 1, isConnected: true, attributes: { src: '/placeholder.svg', 'data-schrack-image-src': '/supplier.jpg', ...attributes }, writes: [],
		getAttribute(key) { return this.attributes[key] ?? null; },
		setAttribute(key, value) { this.attributes[key] = value; this.writes.push(key); },
		removeAttribute(key) { delete this.attributes[key]; },
		matches() { return this.getAttribute('data-schrack-image-src') !== null; },
		querySelectorAll() { return []; }
	};
}

function setup(images, supportsObserver = true) {
	const state = { observed: new Set(), instances: 0 };
	const root = image();
	root.attributes = {};
	root.hasAttribute = function (key) { return this.getAttribute(key) !== null; };
	root.querySelectorAll = () => images.filter(img => img.matches());
	class IntersectionObserver {
		constructor(callback, options) { state.intersect = callback; state.options = options; state.instances++; }
		observe(img) { state.observed.add(img); }
		unobserve(img) { state.observed.delete(img); }
	}
	class MutationObserver {
		constructor(callback) { state.mutate = callback; }
		observe() {}
	}
	const context = {
		document: { documentElement: root, readyState: 'complete' },
		window: supportsObserver ? { IntersectionObserver } : {}, IntersectionObserver, MutationObserver
	};
	runInNewContext(source, context);
	state.rerun = () => runInNewContext(source, context);
	return state;
}

test('offscreen URLs stay deferred; intersecting images restore responsive attributes in order', () => {
	const visible = image({ 'data-schrack-image-srcset': '/small.jpg 300w, /large.jpg 600w', 'data-schrack-image-sizes': '50vw' });
	const below = image();
	const state = setup([visible, below]);
	assert.equal(visible.getAttribute('src'), '/placeholder.svg');
	assert.equal(below.getAttribute('src'), '/placeholder.svg');
	assert.equal(state.options.rootMargin, '100px 0px');
	state.intersect([{ target: below, isIntersecting: false }, { target: visible, isIntersecting: true }]);
	assert.equal(below.getAttribute('src'), '/placeholder.svg');
	assert.equal(visible.getAttribute('src'), '/supplier.jpg');
	assert.equal(visible.getAttribute('srcset'), '/small.jpg 300w, /large.jpg 600w');
	assert.deepEqual(visible.writes, ['loading', 'sizes', 'srcset', 'src']);
	assert.equal(visible.getAttribute('data-schrack-image-src'), null);
	assert.equal(state.observed.has(visible), false);
});

test('AJAX insertion, removal, stale callbacks and reinsertion keep observer state correct', () => {
	const state = setup([]);
	const added = image();
	state.mutate([{ addedNodes: [added], removedNodes: [] }]);
	assert.equal(state.observed.has(added), true);
	added.isConnected = false;
	state.mutate([{ addedNodes: [], removedNodes: [added] }]);
	assert.equal(state.observed.has(added), false);
	state.intersect([{ target: added, isIntersecting: true }]);
	assert.equal(added.getAttribute('src'), '/placeholder.svg');
	added.isConnected = true;
	state.mutate([{ addedNodes: [added], removedNodes: [added] }]);
	assert.equal(state.observed.has(added), true);
	state.intersect([{ target: added, isIntersecting: true }]);
	assert.equal(added.getAttribute('src'), '/supplier.jpg');
});

test('nested AJAX cards are observed and duplicate script execution adds no second observer', () => {
	const state = setup([]);
	const nested = image();
	const card = image();
	card.attributes = {};
	card.querySelectorAll = () => [nested];
	state.mutate([{ addedNodes: [card], removedNodes: [] }]);
	assert.equal(state.observed.has(nested), true);
	state.rerun();
	assert.equal(state.instances, 1);
});

test('browsers without IntersectionObserver still display every product image', () => {
	const initial = image();
	const state = setup([initial], false);
	assert.equal(initial.getAttribute('src'), '/supplier.jpg');
	const added = image();
	state.mutate([{ addedNodes: [added], removedNodes: [] }]);
	assert.equal(added.getAttribute('src'), '/supplier.jpg');
});
