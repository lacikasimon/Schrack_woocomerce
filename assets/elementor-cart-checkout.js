(function () {
	'use strict';

	var rootSelector = '.schrack-cart-checkout';
	var toggleSelector = '.woocommerce-form-coupon-toggle';
	var formSelector = 'form.checkout_coupon';

	function normalizeText(text) {
		return (text || '').replace(/\s+/g, ' ').trim();
	}

	function translateTextNodes(container, search, replacement) {
		var walker;
		var node;

		if (!document.createTreeWalker || !window.NodeFilter) {
			container.childNodes.forEach(function (child) {
				if (child.nodeType === window.Node.TEXT_NODE && normalizeText(child.textContent).indexOf(search) !== -1) {
					child.textContent = child.textContent.replace(search, replacement);
				}
			});
			return;
		}

		walker = document.createTreeWalker(container, window.NodeFilter.SHOW_TEXT);

		while ((node = walker.nextNode())) {
			if (normalizeText(node.textContent).indexOf(search) !== -1) {
				node.textContent = node.textContent.replace(search, replacement);
			}
		}
	}

	function translateCouponToggle(root) {
		root.querySelectorAll(toggleSelector).forEach(function (toggle) {
			var link = toggle.querySelector('a.showcoupon, a');

			translateTextNodes(toggle, 'Have a coupon?', 'Ai un cupon?');

			if (link && normalizeText(link.textContent).indexOf('Click here to enter your code') !== -1) {
				link.textContent = 'Introdu codul cuponului';
			}
		});

		root.querySelectorAll(formSelector + ' p').forEach(function (paragraph) {
			if (normalizeText(paragraph.textContent).indexOf('If you have a coupon code') !== -1) {
				paragraph.textContent = 'Daca ai un cod de cupon, introdu-l mai jos.';
			}
		});
	}

	function couponFormFor(toggle) {
		var root = toggle.closest(rootSelector);
		var current = toggle.nextElementSibling;

		while (current) {
			if (current.matches && current.matches(formSelector)) {
				return current;
			}

			current = current.nextElementSibling;
		}

		return root ? root.querySelector(formSelector) : null;
	}

	function setCouponFormOpen(form, isOpen) {
		form.hidden = !isOpen;
		form.style.display = isOpen ? 'block' : 'none';
		form.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
		form.classList.toggle('is-open', isOpen);
	}

	function bindCouponToggles(root) {
		root.querySelectorAll(toggleSelector).forEach(function (toggle) {
			var link = toggle.querySelector('a.showcoupon, a');
			var form = couponFormFor(toggle);

			if (!link || !form || link.dataset.schrackCouponBound === 'yes') {
				return;
			}

			if (!form.classList.contains('is-open')) {
				setCouponFormOpen(form, false);
			}

			link.dataset.schrackCouponBound = 'yes';
			link.setAttribute('role', 'button');
			link.setAttribute('aria-expanded', form.classList.contains('is-open') ? 'true' : 'false');

			link.addEventListener('click', function (event) {
				var currentForm;
				var input;
				var nextOpen;

				event.preventDefault();
				event.stopImmediatePropagation();

				currentForm = couponFormFor(toggle) || form;
				nextOpen = !currentForm.classList.contains('is-open');

				setCouponFormOpen(currentForm, nextOpen);
				link.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');

				if (nextOpen) {
					input = currentForm.querySelector('input[name="coupon_code"], input.input-text');

					if (input) {
						input.focus();
					}
				}
			}, true);
		});
	}

	function enhance(root) {
		translateCouponToggle(root);
		bindCouponToggles(root);
	}

	function init() {
		document.querySelectorAll(rootSelector).forEach(enhance);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	document.addEventListener('updated_checkout', init);
	document.addEventListener('wc_fragments_refreshed', init);

	if (window.jQuery) {
		window.jQuery(document.body).on('updated_checkout wc_fragments_refreshed', init);
	}
}());
