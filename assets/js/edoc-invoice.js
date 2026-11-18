/* Read-only invoice refreshes never replace the surrounding WooCommerce order form. */
(function () {
	'use strict';
	document.querySelectorAll('.schrack-edoc-invoice').forEach(function (panel) {
		var button = panel.querySelector('[data-invoice-refresh]');
		var content = panel.querySelector('[data-invoice-content]');
		var message = panel.querySelector('[data-invoice-message]');
		var pending = false;
		async function refresh() {
			if (pending || document.hidden) return;
			pending = true;
			button.disabled = true;
			message.textContent = 'Se verifică factura…';
			var controller = new AbortController();
			var timeout = setTimeout(function () { controller.abort(); }, 15000);
			try {
				var url = new URL(panel.dataset.url, window.location.href);
				url.search = new URLSearchParams({action: 'schrack_edoc_invoice', order_id: panel.dataset.order, _wpnonce: panel.dataset.nonce, admin: panel.dataset.admin});
				var response = await fetch(url.toString(), {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
				var result = await response.json();
				if (!response.ok || !result.success) throw new Error('refresh');
				// The authenticated endpoint returns a context-escaped, read-only HTML fragment.
				if (content.innerHTML !== result.data.html) content.innerHTML = result.data.html;
				button.hidden = !result.data.visible;
				message.textContent = '';
			} catch (error) {
				message.textContent = 'Factura nu poate fi actualizată momentan. Reîncercați folosind butonul.';
			} finally {
				clearTimeout(timeout);
				pending = false;
				button.disabled = false;
			}
		}
		button.addEventListener('click', refresh);
		// Event-driven reads avoid background polling and automatic retries after errors.
		document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
		refresh();
	});
}());
