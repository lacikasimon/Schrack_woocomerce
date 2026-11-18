const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function harness(hidden = false) {
	const listeners = {}, events = {}, calls = [], pending = [], timers = new Map();
	let timerId = 0;
	const button = {disabled: false, hidden: false, addEventListener: (key, fn) => events[key] = fn};
	const content = {innerHTML: 'original invoice'};
	const message = {textContent: ''};
	const panel = {dataset: {url: '/ajax', order: '123', nonce: 'nonce', admin: '1'}, querySelector: selector => ({'[data-invoice-refresh]': button, '[data-invoice-content]': content, '[data-invoice-message]': message})[selector]};
	const document = {hidden, querySelectorAll: () => [panel], addEventListener: (key, fn) => listeners[key] = fn};
	const form = {value: 'unsaved order change', focused: true, scroll: 700};
	vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/edoc-invoice.js'), 'utf8'), {
		document, window: {location: {href: 'https://shop.example.invalid/order'}}, URL, URLSearchParams, AbortController,
		setTimeout: (fn, ms) => { timers.set(++timerId, {fn, ms}); return timerId; }, clearTimeout: id => timers.delete(id),
		fetch: (url, args) => { calls.push({url, args}); return new Promise((resolve, reject) => pending.push({resolve, reject})); }
	});
	const flush = async () => { for (let i = 0; i < 10; ++i) await Promise.resolve(); };
	const resolve = async (html = 'new invoice', visible = true) => { pending.shift().resolve({ok: true, json: async () => ({success: true, data: {html, visible}})}); await flush(); };
	return {document, form, button, content, message, listeners, events, calls, pending, timers, flush, resolve};
}
test('invoice refresh is read-only, sequential and leaves the order form intact', async () => {
	const h = harness();
	assert.equal(h.calls.length, 1); h.events.click(); assert.equal(h.calls.length, 1);
	assert.equal(new URL(h.calls[0].url).searchParams.get('action'), 'schrack_edoc_invoice');
	assert.equal(h.calls[0].args.cache, 'no-store'); assert.equal(h.calls[0].args.body, undefined);
	await h.resolve();
	assert.equal(h.content.innerHTML, 'new invoice'); assert.deepEqual(h.form, {value: 'unsaved order change', focused: true, scroll: 700});
	assert.equal(h.button.disabled, false); assert.equal(h.timers.size, 0);
});
test('hidden tabs do no work and resume with one read on return', async () => {
	const h = harness(true); assert.equal(h.calls.length, 0); h.events.click(); assert.equal(h.calls.length, 0);
	h.document.hidden = false; h.listeners.visibilitychange(); h.listeners.visibilitychange(); assert.equal(h.calls.length, 1);
	await h.resolve('', false); assert.equal(h.button.hidden, true); assert.equal(h.timers.size, 0);
});
test('failed refresh preserves the previous invoice and reports a manual retry', async () => {
	const h = harness(); h.pending.shift().reject(new Error('offline')); await h.flush();
	assert.equal(h.content.innerHTML, 'original invoice'); assert.match(h.message.textContent, /Reîncercați/);
	assert.equal(h.calls.length, 1); assert.equal(h.timers.size, 0);
	h.events.click(); assert.equal(h.calls.length, 2); await h.resolve(); assert.equal(h.message.textContent, '');
});
