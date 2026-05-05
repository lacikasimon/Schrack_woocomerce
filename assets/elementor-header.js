(function () {
	'use strict';

	function setMenu(root, open) {
		var toggle = root.querySelector('[data-header-menu-toggle]');
		var panel = root.querySelector('[data-header-menu-panel]');
		var backdrop = root.querySelector('.schrack-header__backdrop');

		if (!toggle || !panel || !backdrop) {
			return;
		}

		if (open) {
			panel.hidden = false;
			backdrop.hidden = false;
			root.classList.add('is-menu-open');
			toggle.setAttribute('aria-expanded', 'true');
			document.documentElement.classList.add('schrack-header-menu-open');
			window.setTimeout(function () {
				var firstLink = panel.querySelector('a, button');

				if (firstLink) {
					firstLink.focus();
				}
			}, 40);
			return;
		}

		root.classList.remove('is-menu-open');
		toggle.setAttribute('aria-expanded', 'false');
		window.setTimeout(function () {
			if (!root.classList.contains('is-menu-open')) {
				panel.hidden = true;
				backdrop.hidden = true;
			}
		}, 180);

		if (!document.querySelector('.schrack-header.is-menu-open')) {
			document.documentElement.classList.remove('schrack-header-menu-open');
		}
	}

	function initHeader(root) {
		if (!root || root.getAttribute('data-header-ready') === 'yes') {
			return;
		}

		root.setAttribute('data-header-ready', 'yes');

		root.addEventListener('click', function (event) {
			var toggle = event.target.closest('[data-header-menu-toggle]');
			var close = event.target.closest('[data-header-menu-close]');
			var panelLink = event.target.closest('[data-header-menu-panel] a');

			if (toggle && root.contains(toggle)) {
				setMenu(root, toggle.getAttribute('aria-expanded') !== 'true');
				return;
			}

			if ((close && root.contains(close)) || (panelLink && root.contains(panelLink))) {
				setMenu(root, false);
			}
		});
	}

	function initAll(context) {
		var scope = context && context.querySelectorAll ? context : document;

		Array.prototype.forEach.call(scope.querySelectorAll('[data-schrack-header]'), initHeader);
	}

	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') {
			return;
		}

		Array.prototype.forEach.call(document.querySelectorAll('.schrack-header.is-menu-open'), function (root) {
			setMenu(root, false);
		});
	});

	document.addEventListener('DOMContentLoaded', function () {
		initAll(document);
	});

	if (window.elementorFrontend && window.elementorFrontend.hooks) {
		window.elementorFrontend.hooks.addAction('frontend/element_ready/schrack_header.default', function ($scope) {
			var element = $scope && $scope[0] ? $scope[0] : null;

			if (element) {
				initAll(element);
			}
		});
	}
})();
