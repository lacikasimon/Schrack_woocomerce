// Run: node --test tests/frontend-consent.js
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname, '../assets/frontend-consent.js'), 'utf8');

function setup(choice) {
	const listeners = {};
	const state = { requests: [], saves: 0, pending: true };
	const placeholder = {
		attributes: [{ name: 'id', value: 'google_gtagjs-js' }, { name: 'type', value: 'text/plain' }],
		nonce: 'test-nonce',
		getAttribute: () => 'https://www.googletagmanager.com/gtag/js?id=GT-TEST',
		replaceWith: script => { state.pending = false; state.requests.push(script); }
	};
	const document = {
		cookie: choice === undefined ? '' : 'cookieadmin_consent=' + JSON.stringify(choice),
		readyState: 'loading', hidden: false,
		addEventListener: (type, callback) => { listeners[type] = callback; },
		querySelectorAll: () => state.pending ? [placeholder] : [],
		createElement: () => ({ setAttribute(name, value) { this[name] = value; } })
	};
	const window = {
		addEventListener: (type, callback) => { listeners[type] = callback; },
		cookieadmin_save_consent_cookie: value => { state.saves++; document.cookie = 'cookieadmin_consent=' + JSON.stringify(value); return 'saved'; }
	};
	runInNewContext(source, { window, document });
	state.start = () => listeners.DOMContentLoaded();
	state.save = value => window.cookieadmin_save_consent_cookie(value);
	state.last = () => window.dataLayer.at(-1)[2];
	state.document = document;
	state.listeners = listeners;
	state.window = window;
	return state;
}

test('first visit, reject, malformed cookie and functional-only consent make no Google request', () => {
	for (const choice of [undefined, { reject: 'true' }, { functional: 'true' }, [], 'false', { analytics: 'false' }]) {
		const state = setup(choice);
		state.start();
		assert.equal(state.requests.length, 0);
		assert.equal(state.last().analytics_storage, 'denied');
		state.document.cookie = 'cookieadmin_consent=%INVALID';
		state.listeners.pageshow();
		assert.equal(state.requests.length, 0);
	}
});

test('analytics approval loads once, preserves nonce, and leaves ad consent denied', () => {
	const state = setup();
	state.start();
	assert.equal(state.save({ analytics: 'true' }), 'saved');
	assert.equal(state.requests.length, 1);
	assert.equal(state.requests[0].nonce, 'test-nonce');
	assert.equal(state.requests[0].type, undefined);
	assert.equal(state.last().analytics_storage, 'granted');
	assert.equal(state.last().ad_storage, 'denied');
	state.save({ analytics: 'true' });
	state.listeners.pageshow();
	assert.equal(state.requests.length, 1);
	assert.equal(state.saves, 2);
});

test('cached page honours returning consent; withdrawal immediately updates Google without reloading', () => {
	const state = setup({ accept: 'true' });
	assert.equal(state.requests.length, 0, 'No network before initialization/configuration completes');
	state.start();
	assert.equal(state.requests.length, 1);
	assert.equal(state.last().ad_personalization, 'granted');
	state.save({ reject: 'true' });
	assert.equal(state.last().analytics_storage, 'denied');
	assert.equal(state.last().ad_user_data, 'denied');
	assert.equal(state.requests.length, 1);
});

test('marketing-only, explicit false and reject precedence preserve separate consent categories', () => {
	const state = setup({ marketing: 'true', analytics: 'false' });
	state.start();
	assert.equal(state.last().analytics_storage, 'denied');
	assert.equal(state.last().ad_storage, 'granted');
	state.save({ reject: 'true', accept: 'true', analytics: 'true' });
	assert.equal(state.last().analytics_storage, 'denied');
	assert.equal(state.last().ad_storage, 'denied');
});

test('missing consent API fails closed and visibility reconciles changes made in another tab', () => {
	const missing = setup({ accept: true });
	delete missing.window.cookieadmin_save_consent_cookie;
	missing.start();
	assert.equal(missing.requests.length, 0);
	const state = setup();
	state.start();
	state.document.cookie = 'cookieadmin_consent=' + encodeURIComponent(JSON.stringify({ analytics: true }));
	state.document.hidden = true;
	state.listeners.visibilitychange();
	assert.equal(state.requests.length, 0);
	state.document.hidden = false;
	state.listeners.visibilitychange();
	assert.equal(state.requests.length, 1);
});
