(function () {
	'use strict';
	const link = document.getElementById('schrack-woo-full-css');
	if (!link || link.hasAttribute('data-schrack-woo-listening')) return;
	link.setAttribute('data-schrack-woo-listening', '1');
	const deferredHref = link.getAttribute('data-schrack-woo-deferred-href');
	let activated = false;
	function fetchFull() {
		if (deferredHref && !link.hasAttribute('href')) link.setAttribute('href', deferredHref);
	}
	function activate() {
		if (activated) return;
		activated = true;
		fetchFull();
		link.setAttribute('media', link.getAttribute('data-schrack-woo-media') || 'all');
		document.removeEventListener('pointerdown', activate, true);
		document.removeEventListener('keydown', activate, true);
	}
	function ready() {
		// Two frames allow the critical rules to paint before applying the full CSS.
		// Hidden tabs and older browsers still get the complete native stylesheet.
		if (document.hidden || !window.requestAnimationFrame) { activate(); return; }
		window.requestAnimationFrame(function () { window.requestAnimationFrame(activate); });
	}
	document.addEventListener('pointerdown', activate, {capture: true, passive: true});
	document.addEventListener('keydown', activate, {capture: true, passive: true});
	window.addEventListener('beforeprint', activate);
	link.addEventListener('error', activate, {once: true});
	if (deferredHref) {
		// Initial matching rules already cover the home page. Keep the extra
		// native rules inactive until input/printing so loading them does not
		// repeat whole-document style calculation during the first render.
		function background() {
			if (activated) return;
			if (document.hidden) { activate(); return; }
			if (typeof window.requestIdleCallback === 'function') {
				window.requestIdleCallback(fetchFull, {timeout: 1000});
			} else {
				window.setTimeout(fetchFull, 0);
			}
		}
		if (document.hidden) { activate(); }
		else if (document.readyState === 'complete') { background(); }
		else { window.addEventListener('load', background, {once: true}); }
		return;
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ready, {once: true});
	} else {
		ready();
	}
}());
