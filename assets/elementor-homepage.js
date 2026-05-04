(function () {
	'use strict';

	function initHomepage(root) {
		var search;
		var empty;
		var nodes;

		if (!root || root.getAttribute('data-home-ready') === 'yes') {
			return;
		}

		root.setAttribute('data-home-ready', 'yes');
		search = root.querySelector('[data-home-category-search]');
		empty = root.querySelector('[data-home-category-empty]');
		nodes = Array.prototype.slice.call(root.querySelectorAll('[data-home-category-node]'));

		Array.prototype.forEach.call(root.querySelectorAll('.schrack-home__service-visual img'), function (image) {
			image.addEventListener('error', function () {
				if (image.parentElement) {
					image.parentElement.classList.add('is-image-missing');
				}

				image.remove();
			});
		});

		root.addEventListener('click', function (event) {
			var toggle = event.target.closest('[data-home-category-toggle]');
			var node;
			var children;
			var expanded;

			if (!toggle || !root.contains(toggle)) {
				return;
			}

			node = toggle.closest('[data-home-category-node]');
			children = node ? node.querySelector(':scope > .schrack-home__tree-children') : null;

			if (!node || !children) {
				return;
			}

			expanded = toggle.getAttribute('aria-expanded') === 'true';
			toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
			node.classList.toggle('is-expanded', !expanded);
			children.hidden = expanded;
		});

		if (!search) {
			return;
		}

		search.addEventListener('input', function () {
			filterTree(root, nodes, empty, search.value);
		});
	}

	function filterTree(root, nodes, empty, value) {
		var query = (value || '').trim().toLowerCase();
		var visible = [];

		if (!query) {
			nodes.forEach(function (node) {
				node.hidden = false;
				node.classList.remove('is-search-match');
			});

			if (empty) {
				empty.hidden = true;
			}

			root.classList.remove('has-category-search');
			return;
		}

		root.classList.add('has-category-search');

		nodes.forEach(function (node) {
			var text = (node.getAttribute('data-search-text') || '').toLowerCase();
			var matched = text.indexOf(query) !== -1;
			var parent;

			node.classList.toggle('is-search-match', matched);

			if (!matched) {
				return;
			}

			if (visible.indexOf(node) === -1) {
				visible.push(node);
			}

			parent = node.parentElement ? node.parentElement.closest('[data-home-category-node]') : null;

			while (parent) {
				if (visible.indexOf(parent) === -1) {
					visible.push(parent);
				}

				parent = parent.parentElement ? parent.parentElement.closest('[data-home-category-node]') : null;
			}
		});

		nodes.forEach(function (node) {
			var shouldShow = visible.indexOf(node) !== -1;
			var toggle;
			var children;

			node.hidden = !shouldShow;

			if (!shouldShow) {
				return;
			}

			toggle = node.querySelector(':scope > .schrack-home__tree-row [data-home-category-toggle]');
			children = node.querySelector(':scope > .schrack-home__tree-children');

			if (toggle && children) {
				toggle.setAttribute('aria-expanded', 'true');
				node.classList.add('is-expanded');
				children.hidden = false;
			}
		});

		if (empty) {
			empty.hidden = visible.length > 0;
		}
	}

	function initAll(context) {
		var scope = context && context.querySelectorAll ? context : document;

		Array.prototype.forEach.call(scope.querySelectorAll('[data-schrack-home]'), initHomepage);
	}

	document.addEventListener('DOMContentLoaded', function () {
		initAll(document);
	});

	if (window.elementorFrontend && window.elementorFrontend.hooks) {
		window.elementorFrontend.hooks.addAction('frontend/element_ready/schrack_homepage.default', function ($scope) {
			var element = $scope && $scope[0] ? $scope[0] : null;

			if (element) {
				initAll(element);
			}
		});
	}
})();
