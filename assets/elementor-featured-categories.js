(function () {
	'use strict';

	function initNav(root) {
		if (!root || root.getAttribute('data-fcat-nav-ready') === 'yes') {
			return;
		}

		var hero = root.querySelector('[data-fcat-hero]');
		var nav = root.querySelector('[data-fcat-nav]');

		if (!hero || !nav || !('IntersectionObserver' in window)) {
			return;
		}

		root.setAttribute('data-fcat-nav-ready', 'yes');

		var sentinel = document.createElement('div');

		sentinel.setAttribute('aria-hidden', 'true');
		sentinel.style.position = 'absolute';
		sentinel.style.bottom = '0';
		sentinel.style.left = '0';
		sentinel.style.height = '1px';
		sentinel.style.width = '1px';
		hero.appendChild(sentinel);

		var observer = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
						// A hero below the viewport has not been scrolled past yet.
						nav.classList.toggle('is-fixed', !entry.isIntersecting && entry.boundingClientRect.bottom <= 0);
				});
			},
			{ threshold: 0 }
		);

		observer.observe(sentinel);
	}

	function initAll(context) {
		var scope = context && context.querySelectorAll ? context : document;

		Array.prototype.forEach.call(scope.querySelectorAll('[data-schrack-fcat]'), initNav);
	}

	function ready() {
		// The category artwork is already eager. Yield its initial paint before
		// inserting the sticky-nav sentinel and starting intersection observation.
		if (document.hidden || typeof window.requestAnimationFrame !== 'function') {
			initAll(document);
			return;
		}
		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () { initAll(document); });
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ready, { once: true });
	} else {
		ready();
	}

	if (window.elementorFrontend && window.elementorFrontend.hooks) {
		window.elementorFrontend.hooks.addAction('frontend/element_ready/schrack_featured_categories.default', function ($scope) {
			var element = $scope && $scope[0] ? $scope[0] : null;

			if (element) {
				initAll(element);
			}
		});
	}
})();
