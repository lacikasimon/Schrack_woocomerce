(function () {
	'use strict';

	function setOpen(root, open) {
		var toggle = root.querySelector('[data-support-toggle]');
		var panel = root.querySelector('[data-support-panel]');

		if (!toggle || !panel) {
			return;
		}

		if (open) {
			panel.hidden = false;
			root.classList.add('is-open', 'is-opening');
			toggle.setAttribute('aria-expanded', 'true');
			toggle.setAttribute('aria-label', 'Inchide suport client');
			window.setTimeout(function () {
				root.classList.remove('is-opening');
			}, 220);
			return;
		}

		root.classList.remove('is-open', 'is-opening');
		toggle.setAttribute('aria-expanded', 'false');
		toggle.setAttribute('aria-label', 'Deschide WhatsApp și suport client');
		panel.hidden = true;
	}

	function initSupport(root) {
		if (!root || root.getAttribute('data-support-ready') === 'yes') {
			return;
		}

		root.setAttribute('data-support-ready', 'yes');

		root.addEventListener('click', function (event) {
			var toggle = event.target.closest('[data-support-toggle]');
			var close = event.target.closest('[data-support-close]');

			if (toggle && root.contains(toggle)) {
				setOpen(root, toggle.getAttribute('aria-expanded') !== 'true');
				return;
			}

			if (close && root.contains(close)) {
				setOpen(root, false);
			}
		});
	}

	function initAll(context) {
		var scope = context && context.querySelectorAll ? context : document;

		Array.prototype.forEach.call(scope.querySelectorAll('[data-schrack-support]'), initSupport);
	}

	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') {
			return;
		}

		Array.prototype.forEach.call(document.querySelectorAll('[data-schrack-support].is-open'), function (root) {
			setOpen(root, false);
		});
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initAll(document);
		});
	} else {
		initAll(document);
	}

	if (window.elementorFrontend && window.elementorFrontend.hooks) {
		window.elementorFrontend.hooks.addAction('frontend/element_ready/schrack_support.default', function ($scope) {
			var element = $scope && $scope[0] ? $scope[0] : null;

			if (element) {
				initAll(element);
			}
		});
	}
})();
