(function () {
	'use strict';

	function parseConfig(root) {
		try {
			return JSON.parse(root.getAttribute('data-config') || '{}');
		} catch (error) {
			return {};
		}
	}

	function debounce(callback, wait) {
		var timeoutId;

		return function () {
			var args = arguments;

			window.clearTimeout(timeoutId);
			timeoutId = window.setTimeout(function () {
				callback.apply(null, args);
			}, wait);
		};
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
		var attrGroups = {};

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
			attrGroups[input.name] = true;
		});

		count += Object.keys(attrGroups).length;

		badge.textContent = String(count);
		badge.hidden = count === 0;
	}

	function appendResults(results, html) {
		var wrapper = document.createElement('div');
		var currentGrid = results.querySelector('.schrack-product-filter__grid');
		var currentSummary = results.querySelector('.schrack-product-filter__summary');
		var currentPagination = results.querySelector('.schrack-product-filter__pagination');
		var incomingGrid;
		var incomingSummary;
		var incomingPagination;

		wrapper.innerHTML = html;
		incomingGrid = wrapper.querySelector('.schrack-product-filter__grid');
		incomingSummary = wrapper.querySelector('.schrack-product-filter__summary');
		incomingPagination = wrapper.querySelector('.schrack-product-filter__pagination');

		if (!currentGrid || !incomingGrid) {
			results.innerHTML = html;
			return;
		}

		Array.prototype.forEach.call(incomingGrid.children, function (card) {
			currentGrid.appendChild(card);
		});

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

	function requestProducts(root, page, options) {
		var form = root.querySelector('.schrack-product-filter__form');
		var results = root.querySelector('.schrack-product-filter__results');
		var ajaxUrl = root.getAttribute('data-ajax-url');
		var action = root.getAttribute('data-action');
		var nonce = root.getAttribute('data-nonce');
		var config = parseConfig(root);
		var append = options && options.append;
		var body;

		if (!form || !results || !ajaxUrl || !action || !nonce) {
			return;
		}

		body = new URLSearchParams(new FormData(form));
		body.set('action', action);
		body.set('nonce', nonce);
		body.set('config', JSON.stringify(config));
		body.set('paged', String(page || 1));

		setLoading(root, true);

		window.fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data || typeof payload.data.html !== 'string') {
				throw new Error('Invalid filter response');
			}

			if (append) {
				appendResults(results, payload.data.html);
			} else {
				results.innerHTML = payload.data.html;
			}
		}).catch(function () {
			results.innerHTML = '<div class="schrack-product-filter__empty"><strong>Filtrarea a esuat.</strong><span>Reincarca pagina si incearca din nou.</span></div>';
		}).finally(function () {
			setLoading(root, false);
		});
	}

	function categoryPicker(root) {
		return {
			input: root.querySelector('[data-category-search]'),
			hidden: root.querySelector('[data-category-id]'),
			results: root.querySelector('[data-category-results]'),
			selected: root.querySelector('[data-category-selected]'),
			selectedLabel: root.querySelector('[data-category-selected-label]'),
			wrap: root.querySelector('[data-category-picker]')
		};
	}

	function closeCategoryResults(root) {
		var picker = categoryPicker(root);

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

		if (picker.hidden) {
			picker.hidden.value = id || '';
		}

		if (picker.input) {
			picker.input.value = label || '';
		}

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
		var config = parseConfig(root);
		var body;

		if (!ajaxUrl || !action || !nonce || !picker.results) {
			return;
		}

		body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', nonce);
		body.set('search', search || '');
		body.set('selected', picker.hidden ? picker.hidden.value : '');
		body.set('limit', String(config.category_results_limit || 30));

		picker.results.innerHTML = '<div class="schrack-category-picker__empty">Se cauta...</div>';
		picker.results.hidden = false;

		window.fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data || typeof payload.data.html !== 'string') {
				throw new Error('Invalid category response');
			}

			setCategoryResults(root, payload.data.html);
		}).catch(function () {
			setCategoryResults(root, '<div class="schrack-category-picker__empty">Cautarea categoriilor a esuat.</div>');
		});
	}

	function initFilter(root) {
		var form = root.querySelector('.schrack-product-filter__form');
		var categorySearch = root.querySelector('[data-category-search]');
		var delayedRequest = debounce(function () {
			requestProducts(root, 1);
		}, 450);
		var delayedCategoryRequest = debounce(function () {
			requestCategories(root, categorySearchTerm(root));
		}, 250);

		if (root.getAttribute('data-filter-ready') === 'yes') {
			return;
		}

		if (!form) {
			return;
		}

		root.setAttribute('data-filter-ready', 'yes');
		syncSelectedCategory(root);
		syncPricePresetState(root);
		updateActiveFilterCount(root);

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			requestProducts(root, 1);
		});

		form.addEventListener('change', function (event) {
			if (event.target && event.target.matches('select, input[type="checkbox"]')) {
				updateActiveFilterCount(root);
				requestProducts(root, 1);
			}
		});

		form.addEventListener('input', function (event) {
			if (!event.target || !event.target.matches('input[type="search"], input[type="number"]')) {
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
			delayedRequest();
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
					Array.prototype.forEach.call(grid.querySelectorAll('[hidden]'), function (card) {
						card.hidden = false;
					});
				}

				categoryExplorerExpand.remove();
				return;
			}

			if (pricePreset) {
				event.preventDefault();
				setPriceRange(root, pricePreset);
				return;
			}

			if (categoryOption) {
				event.preventDefault();
				setSelectedCategory(root, categoryOption.getAttribute('data-category-id'), categoryOption.getAttribute('data-category-label'));
				updateActiveFilterCount(root);
				requestProducts(root, 1);
				return;
			}

			if (categoryClear) {
				event.preventDefault();
				setSelectedCategory(root, '', '');
				updateActiveFilterCount(root);
				requestProducts(root, 1);
				return;
			}

			if (pageButton) {
				event.preventDefault();

				if (!pageButton.disabled) {
					requestProducts(
						root,
						parseInt(pageButton.getAttribute('data-page'), 10) || 1,
						{ append: pageButton.getAttribute('data-load-more') === 'yes' }
					);
				}
			}

			if (resetButton) {
				event.preventDefault();
				form.reset();
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
