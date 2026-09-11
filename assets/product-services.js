(function ($) {
	'use strict';

	// Keep the selected variation, quantity, focus and page position after adding a bundle.
	document.addEventListener('submit', async function (event) {
		const form = event.target;
		if (!(form instanceof HTMLFormElement) || !form.matches('form.cart') || event.defaultPrevented) return;
		const notice = form.parentElement.querySelector('[data-required-services]');
		if (!notice) return;
		event.preventDefault();
		if (form.dataset.requiredServicesBusy === 'true') return;
		const button = event.submitter || form.querySelector('.single_add_to_cart_button');
		const result = notice.querySelector('[data-required-services-result]');
		const payload = new FormData(form);
		const variation = form.querySelector('[name="variation_id"]');
		const productId = variation ? Number(variation.value) : Number(payload.get('add-to-cart') || button?.value || payload.get('product_id'));
		if (!productId || button?.classList.contains('disabled')) {
			result.textContent = 'Selectează opțiunile produsului înainte de a-l adăuga în coș.';
			return;
		}
		payload.set('product_id', String(productId));
		payload.set('quantity', payload.get('quantity') || '1');
		payload.delete('add-to-cart');
		payload.set('security', notice.dataset.nonce);
		form.dataset.requiredServicesBusy = 'true';
		form.setAttribute('aria-busy', 'true');
		const wasDisabled = button?.disabled;
		const hadFocus = document.activeElement === button;
		if (button) button.disabled = true;
		result.classList.remove('is-error');
		result.textContent = 'Se adaugă produsul și serviciile obligatorii…';
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 30000);
		try {
			const response = await fetch(notice.dataset.addUrl, { method: 'POST', body: payload, credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
			const data = await response.json();
			if (data.error) {
				const error = new Error(data.message || 'Produsul nu a fost adăugat. Verifică disponibilitatea serviciilor obligatorii.');
				error.rejected = true;
				throw error;
			}
			if (!response.ok || !data.fragments) throw new Error('network');
			Object.entries(data.fragments).forEach(([selector, html]) => $(selector).replaceWith(html));
			$(document.body).trigger('added_to_cart', [data.fragments, data.cart_hash, $(button)]);
			result.textContent = 'Produsul și serviciile obligatorii au fost adăugate în coș. ';
			const link = document.createElement('a');
			link.href = notice.dataset.cartUrl;
			link.textContent = 'Vezi coșul';
			result.append(link);
		} catch (error) {
			result.classList.add('is-error');
			result.textContent = error.rejected
				? error.message
				: 'Nu am putut confirma adăugarea. Verifică mai întâi coșul înainte de a încerca din nou.';
		} finally {
			clearTimeout(timeout);
			delete form.dataset.requiredServicesBusy;
			form.removeAttribute('aria-busy');
			if (button) button.disabled = wasDisabled;
			if (hadFocus && button?.isConnected && document.activeElement === document.body) button.focus({ preventScroll: true });
		}
	});
})(jQuery);
