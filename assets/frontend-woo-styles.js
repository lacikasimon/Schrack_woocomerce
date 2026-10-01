(function () {
	'use strict';
	const link = document.getElementById('schrack-woo-full-css');
	if (!link || link.hasAttribute('data-schrack-woo-listening')) return;
	link.setAttribute('data-schrack-woo-listening', '1');
	let activated = false;
	function activate() {
		if (activated) return;
		activated = true;
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
	link.addEventListener('error', activate, {once: true});
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ready, {once: true});
	} else {
		ready();
	}
}());
