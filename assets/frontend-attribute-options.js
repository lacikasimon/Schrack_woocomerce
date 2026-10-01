/* Load unselected technical facet values only when their native details opens. */
(function () {
	'use strict';
	const pending = new WeakSet();
	async function load(group) {
		const slot = group.querySelector('[data-attribute-options-pending]');
		const root = group.closest('.schrack-product-filter');
		if (!slot || !root || pending.has(group)) return;
		const taxonomy = group.getAttribute('data-attribute-taxonomy');
		const category = group.getAttribute('data-attribute-category');
		const action = root.getAttribute('data-attribute-action');
		if (!action || !taxonomy) return;
		const restoreFocus = slot.contains(document.activeElement);
		pending.add(group);
		slot.setAttribute('aria-busy', 'true');
		slot.textContent = 'Se încarcă valorile…';
		const controller = new AbortController();
		const timer = window.setTimeout(() => controller.abort(), 20000);
		try {
			const body = new URLSearchParams({action, nonce: root.getAttribute('data-nonce') || '', taxonomy, category});
			const response = await fetch(root.getAttribute('data-ajax-url'), {method: 'POST', credentials: 'same-origin', body, signal: controller.signal});
			if (!response.ok) throw new Error('http');
			const result = await response.json();
			if (!result.success || typeof result.data?.html !== 'string') throw new Error('response');
			// A category/product refresh can replace this group while the read runs.
			if (!group.isConnected || group.getAttribute('data-attribute-category') !== category) return;
			const template = document.createElement('template');
			template.innerHTML = result.data.html;
			const incoming = Array.from(template.content.querySelectorAll('[data-attribute-taxonomy]')).find(node => node.getAttribute('data-attribute-taxonomy') === taxonomy && node.getAttribute('data-attribute-category') === category);
			if (!incoming || !incoming.querySelector('.schrack-attribute-filter__options')) throw new Error('markup');
			const fragment = document.createDocumentFragment();
			Array.from(incoming.children).forEach(node => { if (node.tagName !== 'SUMMARY') fragment.appendChild(node); });
			slot.replaceWith(fragment);
			group.querySelector('noscript')?.remove();
			if (restoreFocus) group.querySelector('summary')?.focus();
		} catch (_) {
			if (!group.isConnected) return;
			slot.textContent = 'Valorile nu au putut fi încărcate. ';
			const retry = document.createElement('button');
			retry.type = 'button';
			retry.textContent = 'Reîncearcă';
			retry.setAttribute('data-attribute-options-retry', '');
			slot.appendChild(retry);
			if (restoreFocus) retry.focus();
		} finally {
			window.clearTimeout(timer);
			pending.delete(group);
			slot.removeAttribute('aria-busy');
		}
	}
	document.addEventListener('toggle', event => {
		const group = event.target;
		if (group.matches?.('.schrack-attribute-filter') && group.open) load(group);
	}, true);
	document.addEventListener('click', event => {
		const retry = event.target.closest('[data-attribute-options-retry]');
		if (retry) load(retry.closest('.schrack-attribute-filter'));
	});
}());
