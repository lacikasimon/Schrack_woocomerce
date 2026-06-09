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

	function buildRegionalFooter() {
		var counties = [
			{ label: 'BH', color: '#84CDDD' },
			{ label: 'BN', color: '#2EBBD5' },
			{ label: 'CJ', color: '#188CB1' },
			{ label: 'MM', color: '#196194' },
			{ label: 'SJ', color: '#1E528F' },
			{ label: 'SM', color: '#2A416F' }
		];
		var links = [
			{ label: 'www.regionordvest.ro', href: 'https://regionordvest.ro/' },
			{ label: 'www.nord-vest.ro', href: 'https://www.nord-vest.ro/' }
		];
		var section = document.createElement('section');
		var slogan = document.createElement('p');
		var countyBand = document.createElement('div');
		var linkWrap = document.createElement('div');

		section.className = 'schrack-footer__regional';
		section.setAttribute('aria-label', 'Subsol obligatoriu Programul Regional Nord-Vest');

		slogan.textContent = 'Investim \u00een viitorul regiunii!';
		section.appendChild(slogan);

		countyBand.className = 'schrack-footer__county-band';
		countyBand.setAttribute('aria-label', 'Judetele Regiunii de Dezvoltare Nord-Vest');
		counties.forEach(function (county) {
			var item = document.createElement('span');

			item.textContent = county.label;
			item.style.backgroundColor = county.color;
			countyBand.appendChild(item);
		});
		section.appendChild(countyBand);

		linkWrap.className = 'schrack-footer__regional-links';
		links.forEach(function (link, index) {
			var item = document.createElement('span');
			var anchor = document.createElement('a');

			anchor.href = link.href;
			anchor.target = '_blank';
			anchor.rel = 'noopener noreferrer';
			anchor.textContent = link.label;
			item.appendChild(anchor);

			if (index < links.length - 1) {
				var separator = document.createElement('i');

				separator.setAttribute('aria-hidden', 'true');
				separator.textContent = '|';
				item.appendChild(separator);
			}

			linkWrap.appendChild(item);
		});
		section.appendChild(linkWrap);

		return section;
	}

	function initRegionalFooter(context) {
		var scope = context && context.querySelectorAll ? context : document;

		Array.prototype.forEach.call(scope.querySelectorAll('.schrack-footer'), function (footer) {
			if (footer.querySelector('.schrack-footer__regional')) {
				return;
			}

			footer.insertBefore(buildRegionalFooter(), footer.firstChild);
		});
	}

	function initAll(context) {
		var scope = context && context.querySelectorAll ? context : document;

		Array.prototype.forEach.call(scope.querySelectorAll('[data-schrack-header]'), initHeader);
		initRegionalFooter(scope);
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

		window.elementorFrontend.hooks.addAction('frontend/element_ready/schrack_footer.default', function ($scope) {
			var element = $scope && $scope[0] ? $scope[0] : null;

			if (element) {
				initAll(element);
			}
		});
	}
})();
