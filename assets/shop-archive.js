(function () {
	'use strict';

	var archiveSelector = '.elementor-widget-woocommerce-products, ul.products';
	var categoryListSelector = '.sidebar .product-categories, .sidebar .wc-block-product-categories-list--depth-0';

	function normalize(text) {
		return (text || '').replace(/\s+/g, ' ').trim();
	}

	function isShopArchive() {
		return document.body.classList.contains('schrack-shop-archive-page') || Boolean(document.querySelector(archiveSelector));
	}

	function replaceExactText(selector, from, to) {
		document.querySelectorAll(selector).forEach(function (element) {
			if (normalize(element.textContent) === from) {
				element.textContent = to;
			}
		});
	}

	function translateVisibleText() {
		replaceExactText('.title h1, .title_outer h1, h1', 'Shop', 'Catalog produse');
		replaceExactText('.sidebar h4, .sidebar .widgettitle', 'Category', 'Categorii');
		replaceExactText('.elementor-widget-text-editor', 'Electronics & Appliances', 'Catalog produse');
		replaceExactText('.out-of-stock-button-inner', 'Out of stock', 'Stoc epuizat');

		document.querySelectorAll('.breadcrumb, .breadcrumbs').forEach(function (element) {
			element.childNodes.forEach(function (node) {
				if (node.nodeType === window.Node.TEXT_NODE && node.textContent.indexOf('Shop') !== -1) {
					node.textContent = node.textContent.replace('Shop', 'Catalog');
				}
			});
		});
	}

	function directCategoryName(item) {
		var link = item.querySelector(':scope > a');

		return normalize(link ? link.textContent : item.textContent).toLowerCase();
	}

	function hasVisibleChild(item) {
		return Boolean(item.querySelector('ul li:not([hidden])'));
	}

	function installCategorySearch() {
		document.querySelectorAll(categoryListSelector).forEach(function (list) {
			var items;
			var tools;
			var input;
			var timeoutId;

			if (list.dataset.schrackCategorySearch === 'yes') {
				return;
			}

			items = Array.prototype.slice.call(list.querySelectorAll('li'));

			if (items.length < 12) {
				return;
			}

			list.dataset.schrackCategorySearch = 'yes';

			tools = document.createElement('div');
			tools.className = 'schrack-shop-category-tools';

			input = document.createElement('input');
			input.type = 'search';
			input.placeholder = 'Cauta categorii';
			input.setAttribute('aria-label', 'Cauta categorii');

			tools.appendChild(input);
			list.parentNode.insertBefore(tools, list);

			function applyFilter() {
				var query = normalize(input.value).toLowerCase();

				items.forEach(function (item) {
					item.hidden = false;
				});

				if (!query) {
					return;
				}

				items.forEach(function (item) {
					item.hidden = directCategoryName(item).indexOf(query) === -1;
				});

				items.slice().reverse().forEach(function (item) {
					if (hasVisibleChild(item)) {
						item.hidden = false;
					}
				});
			}

			input.addEventListener('input', function () {
				window.clearTimeout(timeoutId);
				timeoutId = window.setTimeout(applyFilter, 80);
			});
		});
	}

	function init() {
		if (!isShopArchive()) {
			return;
		}

		document.body.classList.add('schrack-shop-archive-page');
		translateVisibleText();
		installCategorySearch();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
