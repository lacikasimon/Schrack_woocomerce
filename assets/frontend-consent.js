(function () {
	'use strict';
	if (window.schrackConsentBridge) return;
	window.schrackConsentBridge = true;

	window.dataLayer = window.dataLayer || [];
	function consentCommand() { window.dataLayer.push(arguments); }
	const denied = {
		analytics_storage: 'denied', ad_storage: 'denied',
		ad_user_data: 'denied', ad_personalization: 'denied'
	};
	// Runs before Site Kit's config commands and before any Google network request.
	consentCommand('consent', 'default', denied);

	function readChoice() {
		const cookie = document.cookie.split(';').map(part => part.trim()).find(part => part.startsWith('cookieadmin_consent='));
		if (!cookie) return {};
		try {
			const value = JSON.parse(decodeURIComponent(cookie.slice('cookieadmin_consent='.length)));
			return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
		} catch (_) { return {}; }
	}

	let lastChoice = '';
	function sync() {
		const choice = readChoice();
		const yes = value => value === true || value === 'true';
		const all = !yes(choice.reject) && yes(choice.accept);
		const analytics = !yes(choice.reject) && (all || yes(choice.analytics));
		const marketing = !yes(choice.reject) && (all || yes(choice.marketing));
		const state = {
			analytics_storage: analytics ? 'granted' : 'denied',
			ad_storage: marketing ? 'granted' : 'denied',
			ad_user_data: marketing ? 'granted' : 'denied',
			ad_personalization: marketing ? 'granted' : 'denied'
		};
		const key = JSON.stringify(state);
		if (key !== lastChoice) {
			consentCommand('consent', 'update', state);
			lastChoice = key;
		}
		if (!analytics && !marketing) return;
		document.querySelectorAll('script[data-schrack-consent-src]').forEach(function (placeholder) {
			const script = document.createElement('script');
			// Preserve CSP nonce and any integration metadata, but never the inert type.
			Array.from(placeholder.attributes).forEach(function (attribute) {
				if (!['type', 'src', 'data-schrack-consent-src', 'defer'].includes(attribute.name)) {
					script.setAttribute(attribute.name, attribute.value);
				}
			});
			if (placeholder.nonce) script.nonce = placeholder.nonce;
			script.async = true;
			script.src = placeholder.getAttribute('data-schrack-consent-src');
			placeholder.replaceWith(script);
		});
	}

	function start() {
		// CookieAdmin 1.2.2 exposes this shared save function. Pro also calls it
		// after saving its consent log, so we react to a completed save, not a click.
		if (typeof window.cookieadmin_save_consent_cookie !== 'function') return;
		const save = window.cookieadmin_save_consent_cookie;
		window.cookieadmin_save_consent_cookie = function () {
			const result = save.apply(this, arguments);
			sync();
			return result;
		};
		sync();
		// Reconcile saved preferences after returning from another tab or bfcache.
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) sync();
		});
		window.addEventListener('pageshow', sync);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
	else start();
}());
