/* OneTap 2.14.0: keep saved preferences immediate, initialize new visitors on use. */
(function () {
	'use strict';
	function init() {
		const sources = Array.from(document.querySelectorAll('script[data-schrack-onetap-src]'));
		if (!sources.length) return;
		const fonts = Array.from(document.querySelectorAll('[data-schrack-onetap-font-media]'));
		const styles = Array.from(document.querySelectorAll('link[data-schrack-onetap-style-src]'));
		const languageSource = document.querySelector('script[data-schrack-onetap-languages]');
		let languagesReady = !languageSource;
		const toggle = document.querySelector('.onetap-toggle');
		const panel = document.querySelector('nav.onetap-accessibility');
		const markupSource = panel && panel.getAttribute('data-schrack-onetap-markup');
		let markupReady = !markupSource;
		const container = document.querySelector('.onetap-container-toggle');
		let pending = null;
		let ready = false;
		let openRequested = false;
		let keyboardShortcut = null;
		let status;

		function announce(message) {
			if (!toggle) return;
			if (!status) {
				status = document.createElement('span');
				status.className = 'screen-reader-text';
				status.setAttribute('role', 'status');
				toggle.after(status);
			}
			status.className = 'screen-reader-text';
			status.textContent = message;
		}

		function script(source) {
			if (source.dataset.schrackOnetapReady) return Promise.resolve();
			return new Promise(function (resolve, reject) {
				const active = source.cloneNode(false);
				active.removeAttribute('id');
				active.removeAttribute('type');
				active.removeAttribute('defer');
				active.removeAttribute('data-schrack-onetap-src');
				active.async = false;
				active.src = source.dataset.schrackOnetapSrc;
				active.onload = function () {
					source.dataset.schrackOnetapReady = '1';
					resolve();
				};
				active.onerror = function () {
					active.remove();
					reject(new Error('OneTap could not load'));
				};
				source.after(active);
			});
		}

		function stylesheet(link) {
			if (link.dataset.schrackOnetapReady) return Promise.resolve();
			return new Promise(function (resolve, reject) {
				link.onload = function () {
					link.onload = link.onerror = null;
					link.dataset.schrackOnetapReady = '1';
					resolve();
				};
				link.onerror = function () {
					link.onload = link.onerror = null;
					link.removeAttribute('href');
					reject(new Error('OneTap stylesheet could not load'));
				};
				link.setAttribute('href', link.getAttribute('data-schrack-onetap-style-src'));
			});
		}

		function start(open) {
			openRequested = openRequested || open;
			if (ready || pending) return pending;
			// Restore the original font stylesheet media before saved settings or
			// native controls can enable Readable Font. New visitors avoid loading
			// these large fallback fonts for ordinary storefront content.
			fonts.forEach(function (font) {
				font.setAttribute('media', font.getAttribute('data-schrack-onetap-font-media'));
				font.removeAttribute('data-schrack-onetap-font-media');
			});
			fonts.length = 0;
			if (toggle) toggle.setAttribute('aria-busy', 'true');
			if (openRequested) announce('Se încarcă opțiunile de accesibilitate…');
			// The vendor's hotkeys library must finish before its main script.
			const translations = languagesReady ? Promise.resolve() : fetch(languageSource.getAttribute('data-schrack-onetap-languages'), {credentials: 'omit', cache: 'force-cache'}).then(function (response) {
				if (!response.ok) throw new Error('OneTap translations could not load');
				return response.json();
			}).then(function (languages) {
				if (!languages || typeof languages !== 'object' || Array.isArray(languages) || !window.onetapAjaxObject) throw new Error('Invalid OneTap translations');
				window.onetapAjaxObject.languages = languages;
				languagesReady = true;
			});
			const markup = markupReady ? Promise.resolve() : fetch(markupSource, {credentials: 'omit', cache: 'force-cache'}).then(function (response) {
				if (!response.ok) throw new Error('OneTap panel could not load');
				return response.json();
			}).then(function (result) {
				if (!result || typeof result.html !== 'string' || result.html.length > 262144
					|| !/^\s*<section class="onetap-container">/.test(result.html)
					|| /<(?:nav|script|style|iframe)\b|<form\b(?!>)|\b(?:nonce|on\w+)\s*=/i.test(result.html)
					|| (result.html.match(/<form>/g) || []).length > 1) throw new Error('Invalid OneTap panel');
				panel.innerHTML = result.html;
				markupReady = true;
			});
			const prerequisites = Promise.all([translations, Promise.all(styles.map(stylesheet)), markup]).then(function () {
				if (panel) panel.setAttribute('data-schrack-onetap-css-ready', '1');
			});
			pending = sources.reduce(function (previous, source) {
				return previous.then(function () { return script(source); });
			}, prerequisites).then(function () {
				return new Promise(function (resolve) { window.jQuery(resolve); });
			}).then(function () {
				ready = true;
				document.removeEventListener('click', onClick, true);
				document.removeEventListener('keydown', onKey, true);
				if (toggle) {
					toggle.removeAttribute('aria-busy');
					toggle.removeAttribute('title');
				}
				announce('');
				if (openRequested && toggle) toggle.click();
				if (keyboardShortcut) document.dispatchEvent(new KeyboardEvent('keydown', keyboardShortcut));
			}).catch(function () {
				pending = null;
				openRequested = false;
				keyboardShortcut = null;
				if (toggle) {
					toggle.removeAttribute('aria-busy');
					toggle.setAttribute('title', 'Încărcarea a eșuat. Apasă pentru a reîncerca.');
				}
				announce('Opțiunile de accesibilitate nu s-au încărcat. Apasă din nou pentru a reîncerca.');
				if (status) status.className = 'schrack-onetap-error';
			});
			return pending;
		}

		function onClick(event) {
			if (!event.target.closest('.onetap-toggle, a[href="#onetap-toolbar"], #onetap-toolbar')) return;
			event.preventDefault();
			event.stopImmediatePropagation();
			start(true);
		}

		function onKey(event) {
			// Match OneTap's public open shortcut. Tab starts loading without
			// intercepting focus navigation or requiring a pointer interaction.
			const shortcut = event.key === '.' && (event.ctrlKey || event.metaKey || event.altKey);
			const navigation = event.altKey && (event.key === 'F11' || (event.shiftKey && event.key.toLowerCase() === 'k'));
			if (shortcut || navigation) {
				event.preventDefault();
				event.stopImmediatePropagation();
			}
			if (navigation) keyboardShortcut = {key: event.key, altKey: true, shiftKey: event.shiftKey, bubbles: true, cancelable: true};
			if (shortcut || navigation || event.key === 'Tab') start(shortcut);
		}

		if (panel) panel.setAttribute('inert', '');
		document.addEventListener('click', onClick, true);
		document.addEventListener('keydown', onKey, true);
		let saved = true;
		try {
			// Do not parse, reset or overwrite any of the vendor's preferences.
			saved = !!localStorage.getItem('onetap-accessibility-free');
			for (const key of ['onetap_free_toolbar_hidden_until', 'onetap_pro_toolbar_hidden_until']) {
				saved = saved || !!localStorage.getItem(key) || !!sessionStorage.getItem(key);
			}
		} catch (_) { /* Storage unavailable: retain native startup. */ }
		if (saved || !toggle || !panel || !container) {
			start(false);
		} else {
			container.style.display = 'block';
		}
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
	else init();
}());
