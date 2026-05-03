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

	function normalizeFilterText(value) {
		return String(value || '').toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
	}

	function setLoading(root, loading) {
		var results = root.querySelector('.schrack-product-filter__results');

		root.classList.toggle('is-loading', loading);

		if (results) {
			results.setAttribute('aria-busy', loading ? 'true' : 'false');
		}
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
			results.innerHTML = '<div class="schrack-product-filter__empty"><strong>Filter failed.</strong><span>Please refresh the page and try again.</span></div>';
		}).finally(function () {
			setLoading(root, false);
		});
	}

	function filterCategoryOptions(root) {
		var input = root.querySelector('[data-category-search]');
		var select = root.querySelector('[data-category-select]');
		var needle;

		if (!input || !select) {
			return;
		}

		needle = normalizeFilterText(input.value.trim());

		Array.prototype.forEach.call(select.options, function (option) {
			var label = normalizeFilterText(option.getAttribute('data-filter-label') || option.textContent || '');
			var matches = !needle || option.value === '' || label.indexOf(needle) !== -1;

			option.hidden = !matches;
		});
	}

	function initFilter(root) {
		var form = root.querySelector('.schrack-product-filter__form');
		var categorySearch = root.querySelector('[data-category-search]');
		var delayedRequest = debounce(function () {
			requestProducts(root, 1);
		}, 450);

		if (root.getAttribute('data-filter-ready') === 'yes') {
			return;
		}

		if (!form) {
			return;
		}

		root.setAttribute('data-filter-ready', 'yes');

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			requestProducts(root, 1);
		});

		form.addEventListener('change', function (event) {
			if (event.target && event.target.matches('select')) {
				requestProducts(root, 1);
			}
		});

		form.addEventListener('input', function (event) {
			if (!event.target || !event.target.matches('input[type="search"], input[type="number"]')) {
				return;
			}

			if (event.target === categorySearch) {
				filterCategoryOptions(root);
			}

			delayedRequest();
		});

		root.addEventListener('click', function (event) {
			var pageButton = event.target.closest('[data-page]');
			var resetButton = event.target.closest('[data-filter-reset]');

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
				filterCategoryOptions(root);
				requestProducts(root, 1);
			}
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
