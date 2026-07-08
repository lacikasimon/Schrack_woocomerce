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

		var navHeight = nav.offsetHeight || 64;
		var sentinel = document.createElement('div');

		sentinel.setAttribute('aria-hidden', 'true');
		sentinel.style.position = 'absolute';
		sentinel.style.top = '0';
		sentinel.style.left = '0';
		sentinel.style.height = '1px';
		sentinel.style.width = '1px';
		hero.appendChild(sentinel);

		var observer = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					nav.classList.toggle('is-fixed', !entry.isIntersecting);
				});
			},
			{
				rootMargin: '-' + navHeight + 'px 0px 0px 0px',
				threshold: 0
			}
		);

		observer.observe(sentinel);
	}

	function initAll(context) {
		var scope = context && context.querySelectorAll ? context : document;

		Array.prototype.forEach.call(scope.querySelectorAll('[data-schrack-fcat]'), initNav);
	}

	document.addEventListener('DOMContentLoaded', function () {
		initAll(document);
	});

	if (window.elementorFrontend && window.elementorFrontend.hooks) {
		window.elementorFrontend.hooks.addAction('frontend/element_ready/schrack_featured_categories.default', function ($scope) {
			var element = $scope && $scope[0] ? $scope[0] : null;

			if (element) {
				initAll(element);
			}
		});
	}
})();
