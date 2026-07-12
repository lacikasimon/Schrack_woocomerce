(function () {
	'use strict';

	var configCache = new WeakMap();
	var requestStates = new WeakMap();
	var categoryExplorerIndexes = new WeakMap();
	var attributeOptionIndexes = new WeakMap();
	var scheduledLocalFilters = new WeakMap();
	var productCacheTtl = 30000;
	var categoryCacheTtl = 60000;

	function parseConfig(root) {
		var config;

		if (configCache.has(root)) {
			return configCache.get(root);
		}

		try {
			config = JSON.parse(root.getAttribute('data-config') || '{}');
		} catch (error) {
			config = {};
		}

		configCache.set(root, config);

		return config;
	}

	function debounce(callback, wait) {
		var timeoutId;
		var debounced = function () {
			var args = arguments;

			debounced.cancel();
			timeoutId = window.setTimeout(function () {
				timeoutId = null;
				callback.apply(null, args);
			}, wait);
		};

		debounced.cancel = function () {
			if (timeoutId) {
				window.clearTimeout(timeoutId);
				timeoutId = null;
			}
		};

		return debounced;
	}

	function normalizeSearchText(text) {
		var value = (text || '').replace(/\s+/g, ' ').trim().toLowerCase();

		return typeof value.normalize === 'function'
			? value.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
			: value;
	}

	function requestState(root) {
		var state;

		if (requestStates.has(root)) {
			return requestStates.get(root);
		}

		state = {
			categoryCache: new Map(),
			categoryController: null,
			categoryKey: '',
			categorySequence: 0,
			config: parseConfig(root),
			productCache: new Map(),
			productController: null,
			productKey: '',
			productSequence: 0
		};
		state.configJson = JSON.stringify(state.config);
		requestStates.set(root, state);

		return state;
	}

	function cacheGet(cache, key, ttl) {
		var entry = cache.get(key);

		if (!entry) {
			return null;
		}

		if (Date.now() - entry.time > ttl) {
			cache.delete(key);
			return null;
		}

		cache.delete(key);
		cache.set(key, entry);

		return entry.value;
	}

	function cacheSet(cache, key, value, limit) {
		var oldestKey;

		cache.delete(key);
		cache.set(key, { time: Date.now(), value: value });

		if (cache.size > limit) {
			oldestKey = cache.keys().next().value;
			cache.delete(oldestKey);
		}
	}

	function newController() {
		return typeof window.AbortController === 'function' ? new window.AbortController() : null;
	}

	function cancelController(controller) {
		if (controller && typeof controller.abort === 'function') {
			controller.abort();
		}
	}

	function isAbortError(error) {
		return Boolean(error && error.name === 'AbortError');
	}

	function scheduleLocalFilter(input, callback) {
		var currentFrame = scheduledLocalFilters.get(input);

		if (currentFrame) {
			window.cancelAnimationFrame(currentFrame);
		}

		scheduledLocalFilters.set(input, window.requestAnimationFrame(function () {
			scheduledLocalFilters.delete(input);
			callback(input);
		}));
	}

	function setLoading(root, loading) {
		var results = root.querySelector('.schrack-product-filter__results');

		root.classList.toggle('is-loading', loading);

		if (results) {
			results.setAttribute('aria-busy', loading ? 'true' : 'false');
		}
	}

	function priceInputs(root) {
		return {
			min: root.querySelector('input[name="min_price"]'),
			max: root.querySelector('input[name="max_price"]')
		};
	}

	function syncPricePresetState(root) {
		var inputs = priceInputs(root);
		var minValue = inputs.min ? inputs.min.value : '';
		var maxValue = inputs.max ? inputs.max.value : '';

		Array.prototype.forEach.call(root.querySelectorAll('[data-price-min]'), function (button) {
			button.classList.toggle(
				'is-active',
				button.getAttribute('data-price-min') === minValue && button.getAttribute('data-price-max') === maxValue
			);
		});
	}

	function setPriceRange(root, button) {
		var inputs = priceInputs(root);
		var isActive = button.classList.contains('is-active');

		if (!inputs.min || !inputs.max) {
			return;
		}

		inputs.min.value = isActive ? '' : button.getAttribute('data-price-min') || '';
		inputs.max.value = isActive ? '' : button.getAttribute('data-price-max') || '';
		syncPricePresetState(root);
		updateActiveFilterCount(root);
		requestProducts(root, 1);
	}

	function updateActiveFilterCount(root) {
		var badge = root.querySelector('[data-active-filter-count]');
		var form = root.querySelector('.schrack-product-filter__form');

		if (!badge || !form) {
			return;
		}

		var count = 0;
		var search = form.querySelector('input[name="search"]');
		var categoryId = form.querySelector('[data-category-id]');
		var categorySearch = form.querySelector('input[name="category_search"]');
		var minPrice = form.querySelector('input[name="min_price"]');
		var maxPrice = form.querySelector('input[name="max_price"]');
		var manufacturer = form.querySelector('select[name="manufacturer"]');
		var productLine = form.querySelector('select[name="product_line"]');
		var specialOffer = form.querySelector('input[name="special_offer_only"]');
		var attrGroups = new Set();

		if (search && search.value.trim() !== '') {
			count++;
		}

		if ((categoryId && parseInt(categoryId.value, 10) > 0) || (categorySearch && categorySearch.value.trim() !== '')) {
			count++;
		}

		if ((minPrice && minPrice.value.trim() !== '') || (maxPrice && maxPrice.value.trim() !== '')) {
			count++;
		}

		if (manufacturer && manufacturer.value !== '') {
			count++;
		}

		if (productLine && productLine.value !== '') {
			count++;
		}

		if (specialOffer && specialOffer.checked) {
			count++;
		}

		Array.prototype.forEach.call(form.querySelectorAll('input[name^="attr["]:checked'), function (input) {
			attrGroups.add(input.name);
		});

		count += attrGroups.size;

		badge.textContent = String(count);
		badge.hidden = count === 0;
	}

	function restoreCategoryExplorerDefault(grid) {
		var expanded = grid.getAttribute('data-expanded') === 'yes';

		categoryExplorerCards(grid).forEach(function (record) {
			record.element.hidden = !expanded && record.overflow;
		});
	}

	function categoryExplorerCards(grid) {
		var records;

		if (categoryExplorerIndexes.has(grid)) {
			return categoryExplorerIndexes.get(grid);
		}

		records = Array.prototype.map.call(grid.querySelectorAll('.schrack-category-explorer__card'), function (card) {
			var nameEl = card.querySelector('.schrack-category-explorer__name');

			return {
				element: card,
				key: normalizeSearchText(nameEl ? nameEl.textContent : ''),
				overflow: card.hasAttribute('data-overflow')
			};
		});
		categoryExplorerIndexes.set(grid, records);

		return records;
	}

	function filterCategoryExplorer(input) {
		var explorerSection = input.closest('.schrack-category-explorer');
		var grid = explorerSection ? explorerSection.querySelector('[data-category-explorer-grid]') : null;
		var toggle = explorerSection ? explorerSection.querySelector('[data-category-explorer-expand]') : null;

		if (!grid) {
			return;
		}

		var term = normalizeSearchText(input.value);

		if (term === '') {
			restoreCategoryExplorerDefault(grid);

			if (toggle && grid.getAttribute('data-expanded') !== 'yes') {
				toggle.hidden = false;
			}

			return;
		}

		if (toggle) {
			toggle.hidden = true;
		}

		categoryExplorerCards(grid).forEach(function (record) {
			record.element.hidden = record.key.indexOf(term) === -1;
		});
	}

	function appendResults(results, html) {
		var wrapper = document.createElement('div');
		var currentGrid = results.querySelector('.schrack-product-filter__grid');
		var currentSummary = results.querySelector('.schrack-product-filter__summary');
		var currentPagination = results.querySelector('.schrack-product-filter__pagination');
		var incomingGrid;
		var incomingSummary;
		var incomingPagination;
		var fragment;

		wrapper.innerHTML = html;
		incomingGrid = wrapper.querySelector('.schrack-product-filter__grid');
		incomingSummary = wrapper.querySelector('.schrack-product-filter__summary');
		incomingPagination = wrapper.querySelector('.schrack-product-filter__pagination');

		if (!currentGrid || !incomingGrid) {
			results.innerHTML = html;
			return;
		}

		fragment = document.createDocumentFragment();

		while (incomingGrid.firstElementChild) {
			fragment.appendChild(incomingGrid.firstElementChild);
		}

		currentGrid.appendChild(fragment);

		if (currentSummary && incomingSummary) {
			currentSummary.replaceWith(incomingSummary);
		}

		if (currentPagination && incomingPagination) {
			currentPagination.replaceWith(incomingPagination);
		} else if (currentPagination) {
			currentPagination.remove();
		} else if (incomingPagination) {
			results.appendChild(incomingPagination);
		}
	}

	function refreshAttributeFacets(root, html) {
		var current = root.querySelector('[data-attribute-facets]');
		var wrapper;
		var incoming;

		if (!current || typeof html !== 'string') {
			return;
		}

		wrapper = document.createElement('div');
		wrapper.innerHTML = html;
		incoming = wrapper.querySelector('[data-attribute-facets]');

		if (incoming) {
			current.replaceWith(incoming);
		}
	}

	function filterAttributeOptions(input) {
		var group = input.closest('.schrack-attribute-filter');
		var term = normalizeSearchText(input.value);
		var options;

		if (!group) {
			return;
		}

		if (attributeOptionIndexes.has(group)) {
			options = attributeOptionIndexes.get(group);
		} else {
			options = Array.prototype.map.call(group.querySelectorAll('[data-attribute-option]'), function (option) {
				return {
					element: option,
					key: normalizeSearchText(option.textContent)
				};
			});
			attributeOptionIndexes.set(group, options);
		}

		options.forEach(function (record) {
			record.element.hidden = term !== '' && record.key.indexOf(term) === -1;
		});
	}

	function clearAttributeSelections(root) {
		Array.prototype.forEach.call(root.querySelectorAll('[data-attribute-facets] input[type="checkbox"]:checked'), function (input) {
			input.checked = false;
		});
	}

	function cancelProductRequest(root) {
		var state = requestState(root);

		state.productSequence++;
		cancelController(state.productController);
		state.productController = null;
		state.productKey = '';
		setLoading(root, false);
	}

	function applyProductPayload(root, results, data, append) {
		if (append) {
			appendResults(results, data.html);
			return;
		}

		results.innerHTML = data.html;
		refreshAttributeFacets(root, data.facets_html);
		updateActiveFilterCount(root);
	}

	function showMinimumSearchMessage(root, minimum) {
		var results = root.querySelector('.schrack-product-filter__results');

		cancelProductRequest(root);

		if (results) {
			results.innerHTML = '<div class="schrack-product-filter__empty"><strong>Continuă căutarea.</strong><span>Introdu cel puțin ' + String(minimum) + ' caractere.</span></div>';
		}
	}

	function productRequestBody(root, form, page) {
		var state = requestState(root);
		var body = new URLSearchParams(new FormData(form));

		body.set('action', root.getAttribute('data-action') || '');
		body.set('nonce', root.getAttribute('data-nonce') || '');
		body.set('config', state.configJson);
		body.set('paged', String(page || 1));

		return body.toString();
	}

	function renderCachedProducts(root, page) {
		var form = root.querySelector('.schrack-product-filter__form');
		var results = root.querySelector('.schrack-product-filter__results');
		var state = requestState(root);
		var cacheKey;
		var cached;

		if (!form || !results) {
			return false;
		}

		cacheKey = productRequestBody(root, form, page);
		cached = cacheGet(state.productCache, cacheKey, productCacheTtl);

		if (cached === null) {
			return false;
		}

		cancelProductRequest(root);
		applyProductPayload(root, results, cached, false);

		return true;
	}

	function requestProducts(root, page, options) {
		var form = root.querySelector('.schrack-product-filter__form');
		var results = root.querySelector('.schrack-product-filter__results');
		var ajaxUrl = root.getAttribute('data-ajax-url');
		var action = root.getAttribute('data-action');
		var nonce = root.getAttribute('data-nonce');
		var state = requestState(root);
		var append = options && options.append;
		var bodyString;
		var cacheKey;
		var cached;
		var controller;
		var fetchOptions;
		var requestId;

		if (!form || !results || !ajaxUrl || !action || !nonce) {
			return;
		}

		bodyString = productRequestBody(root, form, page);
		cacheKey = bodyString;
		cached = cacheGet(state.productCache, cacheKey, productCacheTtl);

		if (cached) {
			cancelProductRequest(root);
			applyProductPayload(root, results, cached, append);
			return;
		}

		if (state.productController && state.productKey === cacheKey) {
			return;
		}

		cancelController(state.productController);
		state.productSequence++;
		requestId = state.productSequence;
		controller = newController();
		state.productController = controller;
		state.productKey = cacheKey;

		setLoading(root, true);

		fetchOptions = {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: bodyString
		};

		if (controller) {
			fetchOptions.signal = controller.signal;
		}

		window.fetch(ajaxUrl, fetchOptions).then(function (response) {
			if (!response.ok) {
				throw new Error('Filter request failed');
			}

			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data || typeof payload.data.html !== 'string') {
				throw new Error('Invalid filter response');
			}

			if (requestId !== state.productSequence) {
				return;
			}

			cacheSet(state.productCache, cacheKey, payload.data, 12);
			applyProductPayload(root, results, payload.data, append);
		}).catch(function (error) {
			if (isAbortError(error) || requestId !== state.productSequence) {
				return;
			}

			results.innerHTML = '<div class="schrack-product-filter__empty"><strong>Filtrarea a esuat.</strong><span>Reincarca pagina si incearca din nou.</span></div>';
		}).finally(function () {
			if (requestId === state.productSequence) {
				state.productController = null;
				state.productKey = '';
				setLoading(root, false);
			}
		});
	}

	function categoryPicker(root) {
		return {
			input: root.querySelector('[data-category-search]'),
			hidden: root.querySelector('input[type="hidden"][data-category-id]'),
			results: root.querySelector('[data-category-results]'),
			selected: root.querySelector('[data-category-selected]'),
			selectedLabel: root.querySelector('[data-category-selected-label]'),
			wrap: root.querySelector('[data-category-picker]')
		};
	}

	function cancelCategoryRequest(root) {
		var state = requestState(root);

		state.categorySequence++;
		cancelController(state.categoryController);
		state.categoryController = null;
		state.categoryKey = '';
	}

	function closeCategoryResults(root) {
		var picker = categoryPicker(root);

		cancelCategoryRequest(root);

		if (picker.results) {
			picker.results.hidden = true;
		}

		if (picker.input) {
			picker.input.setAttribute('aria-expanded', 'false');
		}
	}

	function setCategoryResults(root, html) {
		var picker = categoryPicker(root);

		if (!picker.results || !picker.input) {
			return;
		}

		picker.results.innerHTML = html;
		picker.results.hidden = false;
		picker.input.setAttribute('aria-expanded', 'true');
	}

	function syncSelectedCategory(root) {
		var picker = categoryPicker(root);
		var id = picker.hidden ? picker.hidden.value : '';
		var label = picker.input ? picker.input.value : '';

		if (!picker.selected || !picker.selectedLabel) {
			return;
		}

		if (id && label) {
			picker.selectedLabel.textContent = label;
			picker.selected.hidden = false;
		} else {
			picker.selectedLabel.textContent = '';
			picker.selected.hidden = true;
		}
	}

	function setSelectedCategory(root, id, label) {
		var picker = categoryPicker(root);
		var selectedId = String(id || '');

		if (picker.hidden) {
			picker.hidden.value = id || '';
		}

		if (picker.input) {
			picker.input.value = label || '';
		}

		Array.prototype.forEach.call(root.querySelectorAll('.schrack-product-filter__category-shortcut[data-category-option]'), function (button) {
			var selected = button.getAttribute('data-category-id') === selectedId;
			var check = button.querySelector('.schrack-product-filter__category-check');

			button.classList.toggle('is-selected', selected);

			if (check) {
				check.textContent = selected ? '✓' : '';
			}
		});

		syncSelectedCategory(root);
		closeCategoryResults(root);
	}

	function resetSelectedCategory(root) {
		var picker = categoryPicker(root);
		var defaultId = picker.wrap ? picker.wrap.getAttribute('data-default-category-id') || '' : '';
		var defaultLabel = picker.wrap ? picker.wrap.getAttribute('data-default-category-label') || '' : '';

		setSelectedCategory(root, defaultId, defaultLabel);
	}

	function categorySearchTerm(root) {
		var picker = categoryPicker(root);
		var value = picker.input ? picker.input.value.trim() : '';
		var selectedLabel = picker.selectedLabel ? picker.selectedLabel.textContent.trim() : '';

		if (picker.hidden && picker.hidden.value && selectedLabel && value === selectedLabel) {
			return '';
		}

		return value;
	}

	function requestCategories(root, search) {
		var ajaxUrl = root.getAttribute('data-ajax-url');
		var action = root.getAttribute('data-category-action');
		var nonce = root.getAttribute('data-nonce');
		var picker = categoryPicker(root);
		var state = requestState(root);
		var body;
		var bodyString;
		var cacheKey;
		var cached;
		var controller;
		var fetchOptions;
		var requestId;

		if (!ajaxUrl || !action || !nonce || !picker.results) {
			return;
		}

		body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', nonce);
		body.set('search', search || '');
		body.set('selected', picker.hidden ? picker.hidden.value : '');
		body.set('limit', String(state.config.category_results_limit || 30));
		bodyString = body.toString();
		cacheKey = bodyString;
		cached = cacheGet(state.categoryCache, cacheKey, categoryCacheTtl);

		if (cached !== null) {
			cancelCategoryRequest(root);
			setCategoryResults(root, cached);
			return;
		}

		if (state.categoryController && state.categoryKey === cacheKey) {
			return;
		}

		cancelController(state.categoryController);
		state.categorySequence++;
		requestId = state.categorySequence;
		controller = newController();
		state.categoryController = controller;
		state.categoryKey = cacheKey;

		picker.results.innerHTML = '<div class="schrack-category-picker__empty">Se cauta...</div>';
		picker.results.hidden = false;
		if (picker.input) {
			picker.input.setAttribute('aria-expanded', 'true');
		}

		fetchOptions = {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: bodyString
		};

		if (controller) {
			fetchOptions.signal = controller.signal;
		}

		window.fetch(ajaxUrl, fetchOptions).then(function (response) {
			if (!response.ok) {
				throw new Error('Category request failed');
			}

			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data || typeof payload.data.html !== 'string') {
				throw new Error('Invalid category response');
			}

			if (requestId !== state.categorySequence) {
				return;
			}

			cacheSet(state.categoryCache, cacheKey, payload.data.html, 20);
			setCategoryResults(root, payload.data.html);
		}).catch(function (error) {
			if (isAbortError(error) || requestId !== state.categorySequence) {
				return;
			}

			setCategoryResults(root, '<div class="schrack-category-picker__empty">Cautarea categoriilor a esuat.</div>');
		}).finally(function () {
			if (requestId === state.categorySequence) {
				state.categoryController = null;
				state.categoryKey = '';
			}
		});
	}

	function initFilter(root) {
		var form;
		var categorySearch;
		var config;
		var minSearchChars;
		var delayedRequest;
		var delayedCategoryRequest;

		if (root.getAttribute('data-filter-ready') === 'yes') {
			return;
		}

		form = root.querySelector('.schrack-product-filter__form');
		categorySearch = root.querySelector('[data-category-search]');

		if (!form) {
			return;
		}

		config = requestState(root).config;
		minSearchChars = parseInt(config.min_search_chars, 10) || 2;
		delayedRequest = debounce(function () {
			requestProducts(root, 1);
		}, 350);
		delayedCategoryRequest = debounce(function () {
			requestCategories(root, categorySearchTerm(root));
		}, 180);

		root.setAttribute('data-filter-ready', 'yes');
		syncSelectedCategory(root);
		syncPricePresetState(root);
		updateActiveFilterCount(root);

		form.addEventListener('submit', function (event) {
			var searchInput = form.querySelector('input[name="search"]');
			var searchValue = searchInput ? searchInput.value.trim() : '';

			event.preventDefault();
			delayedRequest.cancel();

			if (searchValue && searchValue.length < minSearchChars) {
				showMinimumSearchMessage(root, minSearchChars);
				return;
			}

			requestProducts(root, 1);
		});

		root.addEventListener('change', function (event) {
			if (event.target && event.target.matches('select, input[type="checkbox"]')) {
				delayedRequest.cancel();
				updateActiveFilterCount(root);
				requestProducts(root, 1);
			}
		});

		form.addEventListener('input', function (event) {
			if (!event.target || !event.target.matches('input[type="search"], input[type="number"]')) {
				return;
			}

			if (event.isComposing) {
				return;
			}

			if (event.target.matches('[data-attribute-filter-search]')) {
				scheduleLocalFilter(event.target, filterAttributeOptions);
				return;
			}

			if (event.target === categorySearch) {
				setSelectedCategory(root, '', event.target.value);
				updateActiveFilterCount(root);
				delayedCategoryRequest();
				return;
			}

			syncPricePresetState(root);
			updateActiveFilterCount(root);

			if (event.target.matches('input[name="search"]')) {
				var searchValue = event.target.value.trim();

				if (searchValue && searchValue.length < minSearchChars) {
					delayedRequest.cancel();
					showMinimumSearchMessage(root, minSearchChars);
					return;
				}
			}

			if (renderCachedProducts(root, 1)) {
				delayedRequest.cancel();
				return;
			}

			delayedRequest();
		});

		root.addEventListener('input', function (event) {
			if (event.target && event.target.matches('[data-category-explorer-search]')) {
				scheduleLocalFilter(event.target, filterCategoryExplorer);
			}
		});

		if (categorySearch) {
			categorySearch.addEventListener('focus', function () {
				requestCategories(root, categorySearchTerm(root));
			});
		}

		root.addEventListener('click', function (event) {
			var pageButton = event.target.closest('[data-page]');
			var resetButton = event.target.closest('[data-filter-reset]');
			var categoryOption = event.target.closest('[data-category-option]');
			var categoryClear = event.target.closest('[data-category-clear]');
			var pricePreset = event.target.closest('[data-price-min]');
			var categoryExplorerExpand = event.target.closest('[data-category-explorer-expand]');

			if (categoryExplorerExpand) {
				event.preventDefault();
				var explorerSection = categoryExplorerExpand.closest('.schrack-category-explorer');
				var grid = explorerSection ? explorerSection.querySelector('[data-category-explorer-grid]') : null;

				if (grid) {
					grid.setAttribute('data-expanded', 'yes');
					restoreCategoryExplorerDefault(grid);
				}

				categoryExplorerExpand.hidden = true;
				return;
			}

			if (pricePreset) {
				event.preventDefault();
				delayedRequest.cancel();
				setPriceRange(root, pricePreset);
				return;
			}

			if (categoryOption) {
				var categoryId = categoryOption.getAttribute('data-category-id') || '';
				var picker = categoryPicker(root);
				var isShortcut = categoryOption.classList.contains('schrack-product-filter__category-shortcut');
				var isSelectedShortcut = isShortcut && picker.hidden && picker.hidden.value === categoryId;

				event.preventDefault();
				delayedRequest.cancel();
				delayedCategoryRequest.cancel();
				clearAttributeSelections(root);
				setSelectedCategory(
					root,
					isSelectedShortcut ? '' : categoryId,
					isShortcut ? '' : categoryOption.getAttribute('data-category-label')
				);
				updateActiveFilterCount(root);
				requestProducts(root, 1);
				return;
			}

			if (categoryClear) {
				event.preventDefault();
				delayedRequest.cancel();
				delayedCategoryRequest.cancel();
				clearAttributeSelections(root);
				setSelectedCategory(root, '', '');
				updateActiveFilterCount(root);
				requestProducts(root, 1);
				return;
			}

			if (pageButton) {
				event.preventDefault();
				delayedRequest.cancel();

				if (!pageButton.disabled) {
					requestProducts(
						root,
						parseInt(pageButton.getAttribute('data-page'), 10) || 1,
						{ append: pageButton.getAttribute('data-load-more') === 'yes' }
					);
				}
			}

			if (resetButton) {
				var orderby = root.querySelector('select[name="orderby"]');

				event.preventDefault();
				delayedRequest.cancel();
				delayedCategoryRequest.cancel();
				form.reset();
				Array.prototype.forEach.call(form.querySelectorAll('input[type="search"], input[type="number"]'), function (input) {
					input.value = '';
				});
				Array.prototype.forEach.call(form.querySelectorAll('input[type="checkbox"]'), function (input) {
					input.checked = false;
				});
				var stockOnly = form.querySelector('input[name="in_stock_only"]');

				if (stockOnly) {
					stockOnly.checked = true;
				}
				Array.prototype.forEach.call(form.querySelectorAll('select[name="manufacturer"], select[name="product_line"]'), function (select) {
					select.value = '';
				});
				clearAttributeSelections(root);

				if (orderby) {
					orderby.value = config.default_orderby || 'menu_order';
				}

				resetSelectedCategory(root);
				syncPricePresetState(root);
				updateActiveFilterCount(root);
				requestProducts(root, 1);
			}
		});

		root.addEventListener('focusout', function () {
			window.setTimeout(function () {
				if (!root.contains(document.activeElement)) {
					closeCategoryResults(root);
				}
			}, 120);
		});
	}

	function initAll(context) {
		Array.prototype.forEach.call((context || document).querySelectorAll('.schrack-product-filter'), initFilter);
	}

	document.addEventListener('DOMContentLoaded', function () {
		initAll(document);
	});

	window.addEventListener('elementor/frontend/init', function () {
		if (!window.elementorFrontend || !window.elementorFrontend.hooks) {
			return;
		}

		window.elementorFrontend.hooks.addAction('frontend/element_ready/schrack_product_filter.default', function ($scope) {
			var root = $scope && $scope[0] ? $scope[0] : document;
			initAll(root);
		});
	});
}());
