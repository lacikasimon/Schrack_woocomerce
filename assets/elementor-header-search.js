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

	function closeResults(root) {
		var input = root.querySelector('[data-header-search-input]');
		var results = root.querySelector('[data-header-search-results]');

		if (results) {
			results.hidden = true;
			results.innerHTML = '';
		}

		if (input) {
			input.setAttribute('aria-expanded', 'false');
		}
	}

	function setResults(root, html) {
		var input = root.querySelector('[data-header-search-input]');
		var results = root.querySelector('[data-header-search-results]');

		if (!results || !input) {
			return;
		}

		results.innerHTML = html;
		results.hidden = false;
		input.setAttribute('aria-expanded', 'true');
	}

	function requestResults(root) {
		var input = root.querySelector('[data-header-search-input]');
		var ajaxUrl = root.getAttribute('data-ajax-url');
		var action = root.getAttribute('data-action');
		var nonce = root.getAttribute('data-nonce');
		var config = parseConfig(root);
		var minChars = parseInt(config.min_chars, 10) || 2;
		var search = input ? input.value.trim() : '';
		var body;

		if (!input || !ajaxUrl || !action || !nonce) {
			return;
		}

		if (!search) {
			closeResults(root);
			return;
		}

		body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', nonce);
		body.set('search', search);
		body.set('config', JSON.stringify(config));

		if (search.length < minChars) {
			setResults(root, '<div class="schrack-header-search__panel"><div class="schrack-header-search__empty">Introdu cel putin ' + String(minChars) + ' caractere.</div></div>');
			return;
		}

		root.classList.add('is-loading');

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
				throw new Error('Invalid header search response');
			}

			setResults(root, payload.data.html);
		}).catch(function () {
			setResults(root, '<div class="schrack-header-search__panel"><div class="schrack-header-search__empty">Cautarea a esuat.</div></div>');
		}).finally(function () {
			root.classList.remove('is-loading');
		});
	}

	function initSearch(root) {
		var input = root.querySelector('[data-header-search-input]');
		var delayedRequest = debounce(function () {
			requestResults(root);
		}, 220);

		if (root.getAttribute('data-header-search-ready') === 'yes' || !input) {
			return;
		}

		root.setAttribute('data-header-search-ready', 'yes');

		input.addEventListener('input', delayedRequest);
		input.addEventListener('focus', function () {
			if (input.value.trim()) {
				requestResults(root);
			}
		});

		root.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeResults(root);
				input.blur();
			}
		});

		document.addEventListener('click', function (event) {
			if (!root.contains(event.target)) {
				closeResults(root);
			}
		});
	}

	function initAll(context) {
		Array.prototype.forEach.call((context || document).querySelectorAll('.schrack-header-search'), initSearch);
	}

	document.addEventListener('DOMContentLoaded', function () {
		initAll(document);
	});

	window.addEventListener('elementor/frontend/init', function () {
		if (!window.elementorFrontend || !window.elementorFrontend.hooks) {
			return;
		}

		window.elementorFrontend.hooks.addAction('frontend/element_ready/schrack_header_search.default', function ($scope) {
			var root = $scope && $scope[0] ? $scope[0] : document;
			initAll(root);
		});
	});
}());
