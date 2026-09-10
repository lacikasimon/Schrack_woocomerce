(function () {
	'use strict';

	function normalize(value) {
		return value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
	}

	function categoryNodes(list) {
		return Array.prototype.filter.call(list.children, function (node) {
			return node.tagName === 'LI';
		});
	}

	function filterNode(node, query, parentMatches) {
		var label = node.querySelector('label');
		var matches = parentMatches || normalize(label ? label.textContent : '').indexOf(query) !== -1;
		var visible = matches;

		Array.prototype.forEach.call(node.children, function (child) {
			if (child.tagName === 'UL') {
				categoryNodes(child).forEach(function (category) {
					if (filterNode(category, query, matches)) {
						visible = true;
					}
				});
			}
		});

		node.hidden = !visible;
		return visible;
	}

	function initCategorySearch() {
		var box = document.getElementById('taxonomy-product_cat');
		var lists = box ? box.querySelectorAll('#product_catchecklist, #product_catchecklist-pop') : [];

		if (!lists.length || document.getElementById('schrack-product-category-search')) {
			return;
		}

		var text = window.schrackProductAdmin;
		var field = document.createElement('p');
		var label = document.createElement('label');
		var search = document.createElement('input');
		var filters = [];

		field.className = 'schrack-product-category-search';
		label.htmlFor = 'schrack-product-category-search';
		label.textContent = text.categorySearch;
		search.id = label.htmlFor;
		search.type = 'search';
		search.placeholder = text.categoryPlaceholder;
		search.autocomplete = 'off';
		search.setAttribute('aria-controls', Array.prototype.map.call(lists, function (list) {
			return list.id;
		}).join(' '));
		field.appendChild(label);
		field.appendChild(search);
		box.insertBefore(field, box.firstChild);

		Array.prototype.forEach.call(lists, function (list) {
			var empty = document.createElement('p');
			empty.className = 'schrack-product-category-empty';
			empty.textContent = text.noCategories;
			empty.hidden = true;
			empty.setAttribute('role', 'status');
			list.parentNode.insertBefore(empty, list.nextSibling);

			function filter() {
				var query = normalize(search.value);
				var visible = false;

				categoryNodes(list).forEach(function (node) {
					if (filterNode(node, query, false)) {
						visible = true;
					}
				});

				empty.hidden = !query || visible;
			}

			filters.push(filter);
			// WordPress inserts newly created categories into the existing checklist.
			new MutationObserver(filter).observe(list, { childList: true, subtree: true });
		});

		function refresh() {
			filters.forEach(function (filter) { filter(); });
		}

		search.addEventListener('input', refresh);
		search.addEventListener('search', refresh);
		search.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' || event.key === 'Escape') {
				event.preventDefault();
				if (event.key === 'Escape') {
					search.value = '';
					refresh();
				}
			}
		});
	}

	function initParentCategorySearch() {
		var $ = window.jQuery;
		var text = window.schrackProductAdmin;
		var bindings = [];

		if (!$ || !$.fn.selectWoo) {
			return;
		}

		function refresh() {
			// WordPress replaces the parent select after adding a category in the editor.
			bindings = bindings.filter(function (binding) {
				if (document.documentElement.contains(binding.select)) {
					return true;
				}
				binding.instance.destroy();
				return false;
			});

			$('#newproduct_cat_parent, #addtag select#parent, #edittag select#parent').each(function () {
				var select = this;
				var $select = $(select);
				var $parent = $select.parent();

				if ($select.data('select2')) {
					return;
				}

				$parent.addClass('schrack-parent-category-field');
				$select.selectWoo({
					width: '100%',
					minimumResultsForSearch: 0,
					dropdownParent: $parent,
					language: { noResults: function () { return text.noCategories; } },
					matcher: function (params, data) {
						return normalize(data.text || '').indexOf(normalize(params.term || '')) !== -1 ? data : null;
					}
				});
				$select.next('.select2-container').find('.select2-selection').attr('aria-label', text.parentCategorySearch);
				$select.on('select2:open.schrackParentCategory', function () {
					$parent.find('.select2-search__field').attr({
						'aria-label': text.parentCategorySearch,
						placeholder: text.categoryPlaceholder
					});
				});
				bindings.push({ select: select, instance: $select.data('select2') });
			});
		}

		refresh();
		$('#product_cat-adder, #addtag, #edittag').each(function () {
			new MutationObserver(refresh).observe(this, { childList: true, subtree: true });
		});
	}

	function init() {
		initCategorySearch();
		initParentCategorySearch();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
