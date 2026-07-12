(function () {
	'use strict';

	var archiveSelector = '.elementor-widget-woocommerce-products, ul.products, .schrack-product-filter';
	var categoryListSelector = '.sidebar .product-categories, .sidebar .wc-block-product-categories-list--depth-0';
	var storageKey = 'schrackShopView';
	var activeView = readStoredView();
	var viewToggles = [];
	var customFilters = [];

	function normalize(text) {
		return (text || '').replace(/\s+/g, ' ').trim();
	}

	function searchKey(text) {
		var value = normalize(text).toLowerCase();

		return typeof value.normalize === 'function'
			? value.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
			: value;
	}

	function isShopArchive() {
		return document.body.classList.contains('schrack-shop-archive-page') || Boolean(document.querySelector(archiveSelector));
	}

	function readStoredView() {
		try {
			return window.localStorage.getItem(storageKey) === 'list' ? 'list' : 'grid';
		} catch (error) {
			return 'grid';
		}
	}

	function storeView(view) {
		try {
			window.localStorage.setItem(storageKey, view);
		} catch (error) {
			// Storage can be disabled without affecting the view switcher.
		}
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

		return searchKey(link ? link.textContent : item.textContent);
	}

	function installCategorySearch() {
		document.querySelectorAll(categoryListSelector).forEach(function (list) {
			var items;
			var itemIndexes;
			var records;
			var tools;
			var input;
			var timeoutId;
			var frameId;

			if (list.dataset.schrackCategorySearch === 'yes') {
				return;
			}

			items = Array.prototype.slice.call(list.querySelectorAll('li'));

			if (items.length < 12) {
				return;
			}

			list.dataset.schrackCategorySearch = 'yes';
			itemIndexes = new Map();
			items.forEach(function (item, index) {
				itemIndexes.set(item, index);
			});
			records = items.map(function (item) {
				var parentItem = item.parentElement ? item.parentElement.closest('li') : null;

				return {
					element: item,
					key: directCategoryName(item),
					parentIndex: parentItem && itemIndexes.has(parentItem) ? itemIndexes.get(parentItem) : -1
				};
			});

			tools = document.createElement('div');
			tools.className = 'schrack-shop-category-tools';

			input = document.createElement('input');
			input.type = 'search';
			input.placeholder = 'Caută categorii';
			input.setAttribute('aria-label', 'Caută categorii');

			tools.appendChild(input);
			list.parentNode.insertBefore(tools, list);

			function applyFilter() {
				var query = searchKey(input.value);
				var visible = new Uint8Array(records.length);
				var index;
				var parentIndex;

				if (query) {
					for (index = 0; index < records.length; index++) {
						if (records[index].key.indexOf(query) === -1) {
							continue;
						}

						parentIndex = index;

						while (parentIndex >= 0 && !visible[parentIndex]) {
							visible[parentIndex] = 1;
							parentIndex = records[parentIndex].parentIndex;
						}
					}
				} else {
					visible.fill(1);
				}

				records.forEach(function (record, recordIndex) {
					var shouldHide = visible[recordIndex] === 0;

					if (record.element.hidden !== shouldHide) {
						record.element.hidden = shouldHide;
					}
				});
			}

			input.addEventListener('input', function () {
				window.clearTimeout(timeoutId);

				if (frameId) {
					window.cancelAnimationFrame(frameId);
				}

				timeoutId = window.setTimeout(function () {
					frameId = window.requestAnimationFrame(function () {
						frameId = null;
						applyFilter();
					});
				}, 60);
			});
		});
	}

	function createViewToggle() {
		var toggle = document.createElement('div');
		var gridButton = document.createElement('button');
		var listButton = document.createElement('button');

		toggle.className = 'schrack-shop-view-toggle';
		toggle.setAttribute('role', 'group');
		toggle.setAttribute('aria-label', 'Mod de afișare');

		gridButton.type = 'button';
		gridButton.textContent = '▦';
		gridButton.setAttribute('aria-label', 'Afișare în grilă');
		gridButton.addEventListener('click', function () {
			applyView('grid', true);
		});

		listButton.type = 'button';
		listButton.textContent = '☰';
		listButton.setAttribute('aria-label', 'Afișare ca listă');
		listButton.addEventListener('click', function () {
			applyView('list', true);
		});

		toggle.appendChild(gridButton);
		toggle.appendChild(listButton);

		viewToggles.push({
			grid: gridButton,
			list: listButton
		});

		return toggle;
	}

	function applyView(view, persist) {
		activeView = view === 'list' ? 'list' : 'grid';
		document.body.classList.toggle('schrack-shop-list-view', activeView === 'list');

		customFilters.forEach(function (root) {
			root.classList.toggle('is-list-view', activeView === 'list');
		});

		viewToggles.forEach(function (toggle) {
			toggle.grid.setAttribute('aria-pressed', activeView === 'grid' ? 'true' : 'false');
			toggle.list.setAttribute('aria-pressed', activeView === 'list' ? 'true' : 'false');
		});

		if (persist) {
			storeView(activeView);
		}
	}

	function installNativeToolbar() {
		document.querySelectorAll('.elementor-widget-woocommerce-products .woocommerce, .woocommerce').forEach(function (root) {
			var products = root.querySelector('ul.products');
			var resultCount = root.querySelector('.woocommerce-result-count');
			var ordering = root.querySelector('.woocommerce-ordering');
			var productsParent;
			var toolbar;
			var controls;

			if (!products || root.closest('.schrack-product-filter') || products.closest('.woocommerce') !== root || products.dataset.schrackShopToolbar === 'yes') {
				return;
			}

			if (!resultCount && !ordering) {
				return;
			}

			productsParent = products.parentNode;

			if (!productsParent) {
				return;
			}

			root.dataset.schrackShopToolbar = 'yes';
			products.dataset.schrackShopToolbar = 'yes';
			toolbar = document.createElement('div');
			toolbar.className = 'schrack-shop-toolbar';
			controls = document.createElement('div');
			controls.className = 'schrack-shop-toolbar__controls';

			productsParent.insertBefore(toolbar, products);

			if (resultCount) {
				toolbar.appendChild(resultCount);
			}

			if (ordering) {
				controls.appendChild(ordering);
			}

			controls.appendChild(createViewToggle());
			toolbar.appendChild(controls);
		});
	}

	function installCustomFilterToolbar() {
		document.querySelectorAll('.schrack-product-filter').forEach(function (root) {
			var content = root.querySelector('.schrack-product-filter__content');
			var results = root.querySelector('.schrack-product-filter__results');
			var summary;
			var toolbar;
			var summaryCopy;
			var controls;
			var observer;

			if (!content || !results || root.dataset.schrackShopToolbar === 'yes') {
				return;
			}

			root.dataset.schrackShopToolbar = 'yes';
			customFilters.push(root);

			toolbar = content.querySelector('[data-shop-filter-toolbar]');

			if (!toolbar) {
				toolbar = document.createElement('div');
				toolbar.className = 'schrack-shop-toolbar schrack-shop-filter-toolbar';
				toolbar.setAttribute('data-shop-filter-toolbar', '');
				content.insertBefore(toolbar, results);
			}

			summaryCopy = toolbar.querySelector('[data-filter-toolbar-summary]');

			if (!summaryCopy) {
				summaryCopy = document.createElement('span');
				summaryCopy.className = 'schrack-shop-filter-toolbar__summary';
				summaryCopy.setAttribute('data-filter-toolbar-summary', '');
				toolbar.prepend(summaryCopy);
			}

			controls = toolbar.querySelector('.schrack-shop-toolbar__controls');

			if (!controls) {
				controls = document.createElement('div');
				controls.className = 'schrack-shop-toolbar__controls';
				toolbar.appendChild(controls);
			}

			if (!controls.querySelector('.schrack-shop-view-toggle')) {
				controls.appendChild(createViewToggle());
			}

			function syncSummary() {
				var match;
				var text;
				var strong;

				summary = results.querySelector('.schrack-product-filter__summary');
				text = summary ? normalize(summary.textContent) : '';
				match = text.match(/^Se afișează\s+(.+?)\s+din\s+(.+)$/i);
				summaryCopy.textContent = '';

				if (!match) {
					summaryCopy.textContent = text;
					return;
				}

				summaryCopy.appendChild(document.createTextNode('Se afișează '));
				strong = document.createElement('strong');
				strong.textContent = match[1];
				summaryCopy.appendChild(strong);
				summaryCopy.appendChild(document.createTextNode(' din ' + match[2]));
			}

			syncSummary();

			if (typeof window.MutationObserver === 'function') {
				observer = new window.MutationObserver(syncSummary);
				observer.observe(results, { childList: true, subtree: true });
			}
		});
	}

	function installCatalogAnchor() {
		var catalog = document.querySelector('.two_columns_25_75, .schrack-product-filter, .elementor-widget-woocommerce-products');

		if (!catalog) {
			return;
		}

		if (!document.getElementById('schrack-shop-catalog')) {
			catalog.id = 'schrack-shop-catalog';
		}

		if (window.location.hash === '#schrack-shop-catalog') {
			window.requestAnimationFrame(function () {
				catalog.scrollIntoView({
					behavior: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
					block: 'start'
				});
			});
		}

		document.querySelectorAll('[data-shop-category-jump]').forEach(function (link) {
			link.addEventListener('click', function (event) {
				var searchInput = document.querySelector('.schrack-shop-category-tools input, [data-category-search]');
				var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

				event.preventDefault();
				catalog.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });

				if (searchInput) {
					window.setTimeout(function () {
						try {
							searchInput.focus({ preventScroll: true });
						} catch (error) {
							searchInput.focus();
						}
					}, reduceMotion ? 0 : 260);
				}
			});
		});
	}

	function init() {
		if (!isShopArchive()) {
			return;
		}

		document.body.classList.add('schrack-shop-archive-page');
		document.body.classList.toggle('schrack-shop-has-intro', Boolean(document.querySelector('[data-shop-redesign]')));
		translateVisibleText();
		installCategorySearch();
		installNativeToolbar();
		installCustomFilterToolbar();
		applyView(activeView, false);
		installCatalogAnchor();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
