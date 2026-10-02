(function () {
	'use strict';

	var states = new WeakMap();
	var cacheTtl = 30000;

	function stateFor(root) {
		var state = states.get(root);
		if (!state) {
			state = { config: parseConfig(root), cache: new Map(), controller: null, key: '', sequence: 0 };
			states.set(root, state);
		}
		return state;
	}

	function parseConfig(root) {
		try {
			return JSON.parse(root.getAttribute('data-config') || '{}');
		} catch (error) {
			return {};
		}
	}

	function debounce(callback, wait) {
		var timeoutId;
		var delayed = function () {
			var args = arguments;
			delayed.cancel();
			timeoutId = window.setTimeout(function () {
				timeoutId = null;
				callback.apply(null, args);
			}, wait);
		};
		delayed.cancel = function () { window.clearTimeout(timeoutId); timeoutId = null; };
		return delayed;
	}

	function minChars(root) {
		var config = stateFor(root).config;

		return parseInt(config.min_chars, 10) || 3;
	}

	function setLoading(root, loading) {
		root.classList.toggle('is-loading', loading);
		root.setAttribute('aria-busy', loading ? 'true' : 'false');
	}

	function closeResults(root) {
		var input = root.querySelector('[data-header-search-input]');
		var results = root.querySelector('[data-header-search-results]');

		cancelRequest(root);
		if (stateFor(root).delayed) { stateFor(root).delayed.cancel(); }

		if (results) {
			results.hidden = true;
			results.innerHTML = '';
		}

		if (input) {
			input.setAttribute('aria-expanded', 'false');
		}
	}

	function cancelRequest(root) {
		var state = stateFor(root);
		state.sequence++;
		if (state.controller) { state.controller.abort(); }
		state.controller = null;
		state.key = '';
		setLoading(root, false);
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
		var state = stateFor(root);
		var config = state.config;
		var minimum = minChars(root);
		var search = input ? input.value.trim() : '';
		var body;
		var cached;
		var requestId;
		var options;

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
			cancelRequest(root);
			setResults(root, '<div class="schrack-header-search__panel"><div class="schrack-header-search__empty">Introdu cel putin ' + String(minimum) + ' caractere.</div></div>');
			return;
		}

		cached = state.cache.get(search);
		if (cached && Date.now() - cached.time < cacheTtl) {
			cancelRequest(root);
			setResults(root, cached.html);
			return;
		}
		if (state.key === search) { return; }
		cancelRequest(root);
		requestId = state.sequence;
		state.key = search;
		state.controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;

		setLoading(root, true);

		options = {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		};
		if (state.controller) { options.signal = state.controller.signal; }
		window.fetch(ajaxUrl, options).then(function (response) {
			if (!response.ok) { throw new Error('Header search request failed'); }
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data || typeof payload.data.html !== 'string') {
				throw new Error('Invalid header search response');
			}

			if (requestId !== state.sequence || input.value.trim() !== search) {
				return;
			}

			state.cache.delete(search);
			state.cache.set(search, { time: Date.now(), html: payload.data.html });
			if (state.cache.size > 12) { state.cache.delete(state.cache.keys().next().value); }
			setResults(root, payload.data.html);
		}).catch(function (error) {
			if ((error && error.name === 'AbortError') || requestId !== state.sequence || input.value.trim() !== search) {
				return;
			}

			setResults(root, '<div class="schrack-header-search__panel"><div class="schrack-header-search__empty">Cautarea a esuat.</div></div>');
		}).finally(function () {
			if (requestId === state.sequence) {
				state.controller = null;
				state.key = '';
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
		stateFor(root).delayed = delayedRequest;

		input.addEventListener('input', function (event) {
			var search = input.value.trim();
			var results = root.querySelector('[data-header-search-results]');
			cancelRequest(root);
			delayedRequest.cancel();
			if (results) { results.hidden = true; }
			input.setAttribute('aria-expanded', 'false');
			if (event.isComposing) { return; }

			if (search.length >= minChars(root)) {
				setLoading(root, true);
				delayedRequest();
				return;
			}

			setLoading(root, false);
			delayedRequest();
		});
		input.addEventListener('focus', function () {
			delayedRequest.cancel();
			if (input.value.trim()) {
				requestResults(root);
			}
		});

		root.addEventListener('submit', function () { closeResults(root); });
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) { closeResults(root); }
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
