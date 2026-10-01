/* CookieAdmin 1.2.2: render existing notice early, keep native controls and logs. */
(function () {
	'use strict';
	const banner = document.querySelector('.cookieadmin_law_container.cookieadmin_box');
	if (!banner) return;
	try {
		const cookie = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith('cookieadmin_consent='));
		// Match the vendor's truthy parsed cookie, including legacy consent objects.
		if (cookie && JSON.parse(decodeURIComponent(cookie.slice('cookieadmin_consent='.length)))) return;
	} catch (_) {
		// Malformed stored JSON is a new choice, as in the native module.
		// If reading cookies itself is blocked, retain native startup.
		try { void document.cookie; } catch (_) { return; }
	}
	if (document.readyState !== 'loading') return;
	let queued = null;
	function capture(event) {
		const button = event.target.closest('#cookieadmin_customize_button, #cookieadmin_reject_button, #cookieadmin_accept_button');
		if (!button || !banner.contains(button)) return;
		event.preventDefault();
		event.stopImmediatePropagation();
		if (queued) return;
		queued = button;
		button.setAttribute('aria-busy', 'true');
	}
	document.addEventListener('click', capture, true);
	banner.style.display = 'block';
	document.addEventListener('DOMContentLoaded', function () {
		// The native deferred script registers its DOMContentLoaded handler later.
		// Run after all handlers; a user action is replayed exactly once.
		window.setTimeout(function () {
			document.removeEventListener('click', capture, true);
			if (!queued) return;
			const button = queued;
			queued = null;
			button.removeAttribute('aria-busy');
			if (!button.isConnected) return;
			if (typeof window.cookieadmin_set_consent === 'function') button.click();
			else {
				const status = document.createElement('span');
				status.setAttribute('role', 'status');
				status.textContent = 'Opțiunile cookie nu sunt disponibile momentan.';
				banner.appendChild(status);
			}
		}, 0);
	}, {once: true});
}());
