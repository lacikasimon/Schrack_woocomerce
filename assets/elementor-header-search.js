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

	function minChars(root) {
		var config = parseConfig(root);

		return parseInt(config.min_chars, 10) || 3;
	}

	function setLoading(root, loading) {
		root.classList.toggle('is-loading', loading);
		root.setAttribute('aria-busy', loading ? 'true' : 'false');
	}

	function closeResults(root) {
		var input = root.querySelector('[data-header-search-input]');
		var results = root.querySelector('[data-header-search-results]');

		setLoading(root, false);

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
		var minimum = minChars(root);
		var search = input ? input.value.trim() : '';
		var body;

		if (!input || !ajaxUrl || !action || !nonce) {
			setLoading(root, false);
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

		if (search.length < minimum) {
			setLoading(root, false);
			setResults(root, '<div class="schrack-header-search__panel"><div class="schrack-header-search__empty">Introdu cel putin ' + String(minimum) + ' caractere.</div></div>');
			return;
		}

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
				throw new Error('Invalid header search response');
			}

			if (input.value.trim() !== search) {
				return;
			}

			setResults(root, payload.data.html);
		}).catch(function () {
			if (input.value.trim() !== search) {
				return;
			}

			setResults(root, '<div class="schrack-header-search__panel"><div class="schrack-header-search__empty">Cautarea a esuat.</div></div>');
		}).finally(function () {
			if (input.value.trim() === search || input.value.trim().length < minimum) {
				setLoading(root, false);
			}
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

		input.addEventListener('input', function () {
			var search = input.value.trim();

			if (search.length >= minChars(root)) {
				setLoading(root, true);
				delayedRequest();
				return;
			}

			setLoading(root, false);
			delayedRequest();
		});
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

	document.addEventListener('input', function (event) {
		var target = event.target;
		var root;

		if (!target || !target.matches || !target.matches('[data-header-search-input]')) {
			return;
		}

		root = target.closest('.schrack-header-search');

		if (!root || root.getAttribute('data-header-search-ready') === 'yes') {
			return;
		}

		initSearch(root);

		if (target.value.trim().length >= minChars(root)) {
			setLoading(root, true);
			window.setTimeout(function () {
				requestResults(root);
			}, 0);
		}
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initAll(document);
		});
	} else {
		initAll(document);
	}

	window.addEventListener('elementor/frontend/init', function () {
		if (!window.elementorFrontend || !window.elementorFrontend.hooks) {
			return;
		}

		window.elementorFrontend.hooks.addAction('frontend/element_ready/schrack_header_search.default', function ($scope) {
			var root = $scope && $scope[0] ? $scope[0] : document;
			initAll(root);
		});

		window.elementorFrontend.hooks.addAction('frontend/element_ready/schrack_header.default', function ($scope) {
			var root = $scope && $scope[0] ? $scope[0] : document;
			initAll(root);
		});
	});
}());
