(function () {
	'use strict';

	var rootSelector = '.schrack-cart-checkout';
	var toggleSelector = '.woocommerce-form-coupon-toggle';
	var formSelector = 'form.checkout_coupon';
	var checkoutRefreshTimer = null;
	var textMap = {
		'Apply coupon': 'Aplica cuponul',
		'APPLY COUPON': 'APLICA CUPONUL',
		'Billing details': 'Detalii pentru facturare',
		'Cart totals': 'Total cos',
		'Click here to enter your code': 'Introdu codul cuponului',
		'Coupon code': 'Cod cupon',
		'Have a coupon?': 'Ai un cupon?',
		'Place order': 'Trimite comanda',
		'Price': 'Pret',
		'Product': 'Produs',
		'Quantity': 'Cantitate',
		'Remove item': 'Elimina produsul',
		'Shipping': 'Livrare',
		'Ship to a different address?': 'Livrezi la alta adresa?',
		'Subtotal': 'Subtotal',
		'Total': 'Total',
		'Update cart': 'Actualizeaza cosul',
		'UPDATE CART': 'ACTUALIZEAZA COSUL',
		'Your order': 'Comanda ta'
	};
	var phraseMap = [
		[ 'Apartment, suite, unit, etc. (optional)', 'Apartament, scara, etaj etc. (optional)' ],
		[ 'Click here to enter your code', 'Introdu codul cuponului' ],
		[ 'Coupon code', 'Cod cupon' ],
		[ 'Have a coupon?', 'Ai un cupon?' ],
		[ 'If you have a coupon code, please apply it below.', 'Daca ai un cod de cupon, introdu-l mai jos.' ],
		[ 'Ship to a different address?', 'Livrezi la alta adresa?' ]
	];

	function normalizeText(text) {
		return (text || '').replace(/\s+/g, ' ').trim();
	}

	function translatedValue(value) {
		var normalized = normalizeText(value);
		var output = value || '';

		if (textMap[normalized]) {
			return textMap[normalized];
		}

		phraseMap.forEach(function (entry) {
			output = output.split(entry[0]).join(entry[1]);
		});

		output = output.replace(/Shipping to ([^.]+)\./g, 'Livrare la $1.');
		output = output.replace(/Shipping to ([^.]+)/g, 'Livrare la $1');

		return output;
	}

	function translateTextNodes(container) {
		var walker;
		var node;
		var translated;

		if (!document.createTreeWalker || !window.NodeFilter) {
			container.childNodes.forEach(function (child) {
				if (child.nodeType === window.Node.TEXT_NODE) {
					translated = translatedValue(child.textContent);

					if (translated !== child.textContent) {
						child.textContent = translated;
					}
				}
			});
			return;
		}

		walker = document.createTreeWalker(container, window.NodeFilter.SHOW_TEXT);

		while ((node = walker.nextNode())) {
			translated = translatedValue(node.textContent);

			if (translated !== node.textContent) {
				node.textContent = translated;
			}
		}
	}

	function translateAttributes(root) {
		root.querySelectorAll('input, button, textarea, select, a, [aria-label], [title], [placeholder], [data-title]').forEach(function (element) {
			[ 'value', 'placeholder', 'aria-label', 'title', 'data-title' ].forEach(function (attribute) {
				var value = element.getAttribute(attribute);
				var translated;

				if (!value) {
					return;
				}

				translated = translatedValue(value);

				if (translated !== value) {
					element.setAttribute(attribute, translated);

					if ('value' === attribute && 'value' in element) {
						element.value = translated;
					}
				}
			});
		});
	}

	function translateStaticText(root) {
		translateTextNodes(root);
		translateAttributes(root);
	}

	function translateCouponToggle(root) {
		root.querySelectorAll(toggleSelector).forEach(function (toggle) {
			var link = toggle.querySelector('a.showcoupon, a');

			translateStaticText(toggle);

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

	function removeDuplicateCartMarkup(root) {
		var cartContainer = root.querySelector('.schrack-cart-checkout__woocommerce-cart .woocommerce');
		var forms;

		if (!cartContainer) {
			return;
		}

		forms = Array.prototype.slice.call(cartContainer.querySelectorAll(':scope > form.woocommerce-cart-form'));

		forms.slice(1).forEach(function (form) {
			form.remove();
		});

		cartContainer.querySelectorAll(':scope > .cart-collaterals').forEach(function (collaterals) {
			collaterals.remove();
		});
	}

	function cartQuantity(root) {
		var form = root.querySelector('.schrack-cart-checkout__woocommerce-cart form.woocommerce-cart-form');
		var total = 0;

		if (!form) {
			return 0;
		}

		form.querySelectorAll('input.qty').forEach(function (input) {
			var value = parseFloat(input.value || '0');

			if (!Number.isNaN(value) && value > 0) {
				total += value;
			}
		});

		return Math.max(0, Math.round(total));
	}

	function updateCartCount(root) {
		var badge = root.querySelector('[data-schrack-cart-count]');
		var count;

		if (!badge) {
			return;
		}

		count = cartQuantity(root);
		badge.textContent = count + (count === 1 ? ' produs' : ' produse');
	}

	function enableCartUpdate(input) {
		var form = input.closest('form.woocommerce-cart-form');
		var button;

		if (!form) {
			return;
		}

		button = form.querySelector('button[name="update_cart"], input[name="update_cart"]');

		if (button) {
			button.disabled = false;
			button.removeAttribute('disabled');
			button.setAttribute('aria-disabled', 'false');
		}
	}

	function quantityNumber(input, fallback) {
		var value = parseFloat(input.value);

		return Number.isNaN(value) ? fallback : value;
	}

	function quantityAttribute(input, attribute, fallback) {
		var value = parseFloat(input.getAttribute(attribute));

		return Number.isNaN(value) ? fallback : value;
	}

	function bindQuantityControls(root) {
		root.querySelectorAll('.schrack-cart-checkout__woocommerce-cart .quantity').forEach(function (quantity) {
			var input = quantity.querySelector('input.qty');
			var minus = quantity.querySelector('.minus');
			var plus = quantity.querySelector('.plus');

			if (!input) {
				return;
			}

			if (input.dataset.schrackQtyBound !== 'yes') {
				input.dataset.schrackQtyBound = 'yes';
				input.addEventListener('input', function () {
					enableCartUpdate(input);
					updateCartCount(root);
				});
				input.addEventListener('change', function () {
					enableCartUpdate(input);
					updateCartCount(root);
				});
			}

			[ minus, plus ].forEach(function (button) {
				if (!button || button.dataset.schrackQtyBound === 'yes') {
					return;
				}

				button.dataset.schrackQtyBound = 'yes';
				button.addEventListener('click', function () {
					var step = quantityAttribute(input, 'step', 1);
					var min = quantityAttribute(input, 'min', 0);
					var max = input.max === '' ? Infinity : quantityAttribute(input, 'max', Infinity);
					var current = quantityNumber(input, min);
					var next = current + (button.classList.contains('minus') ? -step : step);

					next = Math.max(min, Math.min(max, next));
					input.value = String(next);
					input.dispatchEvent(new Event('input', { bubbles: true }));
					input.dispatchEvent(new Event('change', { bubbles: true }));
				});
			});
		});
	}

	function requestCheckoutRefresh(root) {
		if (!window.jQuery || !root.querySelector('form.checkout')) {
			return;
		}

		window.clearTimeout(checkoutRefreshTimer);
		checkoutRefreshTimer = window.setTimeout(function () {
			window.jQuery(document.body).trigger('update_checkout');
		}, 80);
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
		removeDuplicateCartMarkup(root);
		translateStaticText(root);
		translateCouponToggle(root);
		bindCouponToggles(root);
		bindQuantityControls(root);
		updateCartCount(root);
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
		window.jQuery(document.body).on('updated_wc_div updated_cart_totals applied_coupon removed_coupon', function () {
			init();
			document.querySelectorAll(rootSelector).forEach(requestCheckoutRefresh);
		});
	}
}());
