(function () {
	'use strict';

	function toArray(list) {
		return Array.prototype.slice.call(list);
	}

	function closestCategoryNode(element) {
		return element ? element.closest('[data-bulk-category-node]') : null;
	}

	function categoryCheckboxes(root) {
		return toArray(root.querySelectorAll('[data-bulk-category-checkbox]'));
	}

	function selectedCategoryCheckboxes(root) {
		return categoryCheckboxes(root).filter(function (checkbox) {
			return checkbox.checked;
		});
	}

	function directCategoryCheckbox(node) {
		var label = node ? node.querySelector('.schrack-bulk-tree__label') : null;

		return label ? label.querySelector('[data-bulk-category-checkbox]') : null;
	}

	function descendantCategoryCheckboxes(node) {
		var childList = toArray(node ? node.children : []).filter(function (child) {
			return child.classList && child.classList.contains('schrack-bulk-tree__list');
		})[0];

		return childList ? toArray(childList.querySelectorAll('[data-bulk-category-checkbox]')) : [];
	}

	function updateBulkSelectionState(root) {
		var selectedCount = root.querySelector('[data-bulk-selected-count]');

		toArray(root.querySelectorAll('[data-bulk-category-node]')).reverse().forEach(function (node) {
			var checkbox = directCategoryCheckbox(node);
			var childCheckboxes = descendantCategoryCheckboxes(node);
			var checkedChildren = childCheckboxes.filter(function (childCheckbox) {
				return childCheckbox.checked;
			});
			var indeterminateChildren = childCheckboxes.filter(function (childCheckbox) {
				return childCheckbox.indeterminate;
			});

			if (!checkbox) {
				return;
			}

			if (0 === childCheckboxes.length) {
				checkbox.indeterminate = false;
				return;
			}

			checkbox.indeterminate = (checkedChildren.length > 0 && checkedChildren.length < childCheckboxes.length) ||
				(checkedChildren.length === childCheckboxes.length && !checkbox.checked) ||
				indeterminateChildren.length > 0;
		});

		if (selectedCount) {
			selectedCount.textContent = String(selectedCategoryCheckboxes(root).length);
		}
	}

	function setBulkResult(root, message, isError) {
		var result = root.querySelector('[data-bulk-result]');

		if (!result) {
			return;
		}

		result.textContent = message;
		result.classList.toggle('is-error', !!isError);
	}

	function rowIsEmpty(row) {
		var markup = row.querySelector('[data-markup-field]');
		var minMargin = row.querySelector('[data-min-margin-field]');
		var rounding = row.querySelector('[data-rounding-field]');

		return (!markup || '' === markup.value.trim()) &&
			(!minMargin || '' === minMargin.value.trim()) &&
			(!rounding || 'none' === rounding.value);
	}

	function applyBulkToRows(root) {
		var form = root.closest('form');
		var markup = root.querySelector('[data-bulk-markup]');
		var rounding = root.querySelector('[data-bulk-rounding]');
		var mode = root.querySelector('[data-bulk-mode]:checked');
		var selected = selectedCategoryCheckboxes(root);
		var markupValue = markup ? markup.value.trim() : '';
		var roundingValue = rounding ? rounding.value : '';
		var updated = 0;
		var skipped = 0;

		if (!form) {
			return;
		}

		if (0 === selected.length) {
			setBulkResult(root, 'Select at least one category.', true);
			return;
		}

		if ('' === markupValue && '' === roundingValue) {
			setBulkResult(root, 'Set a bulk markup or rounding value first.', true);
			return;
		}

		selected.forEach(function (checkbox) {
			var termId = checkbox.value;
			var row = form.querySelector('[data-markup-row][data-term-id="' + termId + '"]');
			var rowMarkup = row ? row.querySelector('[data-markup-field]') : null;
			var rowRounding = row ? row.querySelector('[data-rounding-field]') : null;

			if (!row) {
				return;
			}

			if (mode && 'empty' === mode.value && !rowIsEmpty(row)) {
				skipped++;
				return;
			}

			if ('' !== markupValue && rowMarkup) {
				rowMarkup.value = markupValue;
			}

			if ('' !== roundingValue && rowRounding) {
				rowRounding.value = roundingValue;
			}

			row.classList.add('is-bulk-updated');
			updated++;
		});

		if (updated > 0) {
			setBulkResult(root, updated + ' categories updated in the table.' + (skipped > 0 ? ' ' + skipped + ' already configured categories skipped.' : ''), false);
			return;
		}

		setBulkResult(root, 'No rows were updated.' + (skipped > 0 ? ' Selected categories were already configured.' : ''), true);
	}

	function refreshBulkTreeFilter(root) {
		var search = root.querySelector('[data-bulk-category-search]');
		var query = search ? search.value.trim().toLowerCase() : '';
		var nodes = toArray(root.querySelectorAll('[data-bulk-category-node]'));
		var visible = [];

		if ('' === query) {
			nodes.forEach(function (node) {
				node.hidden = false;
				node.classList.remove('is-search-match');
			});
			return;
		}

		nodes.forEach(function (node) {
			var searchText = (node.getAttribute('data-bulk-category-search-text') || '').toLowerCase();
			var matches = searchText.indexOf(query) !== -1;
			var parent = node;

			node.classList.toggle('is-search-match', matches);

			if (!matches) {
				return;
			}

			while (parent) {
				if (visible.indexOf(parent) === -1) {
					visible.push(parent);
				}
				parent = closestCategoryNode(parent.parentElement);
			}

			toArray(node.querySelectorAll('[data-bulk-category-node]')).forEach(function (childNode) {
				if (visible.indexOf(childNode) === -1) {
					visible.push(childNode);
				}
			});
		});

		nodes.forEach(function (node) {
			node.hidden = visible.indexOf(node) === -1;
		});
	}

	function setVisibleBulkCategories(root, checked) {
		categoryCheckboxes(root).forEach(function (checkbox) {
			var node = closestCategoryNode(checkbox);

			if (node && !node.hidden) {
				checkbox.checked = checked;
				checkbox.indeterminate = false;
			}
		});
		updateBulkSelectionState(root);
	}

	function initMarkupBulk(root) {
		var search = root.querySelector('[data-bulk-category-search]');
		var selectVisible = root.querySelector('[data-bulk-select-visible]');
		var selectAll = root.querySelector('[data-bulk-select-all]');
		var clear = root.querySelector('[data-bulk-clear]');
		var apply = root.querySelector('[data-bulk-apply]');

		if (search) {
			search.addEventListener('input', function () {
				refreshBulkTreeFilter(root);
			});
		}

		if (selectVisible) {
			selectVisible.addEventListener('click', function () {
				setVisibleBulkCategories(root, true);
			});
		}

		if (selectAll) {
			selectAll.addEventListener('click', function () {
				categoryCheckboxes(root).forEach(function (checkbox) {
					checkbox.checked = true;
					checkbox.indeterminate = false;
				});
				updateBulkSelectionState(root);
			});
		}

		if (clear) {
			clear.addEventListener('click', function () {
				categoryCheckboxes(root).forEach(function (checkbox) {
					checkbox.checked = false;
					checkbox.indeterminate = false;
				});
				updateBulkSelectionState(root);
				setBulkResult(root, '', false);
			});
		}

		if (apply) {
			apply.addEventListener('click', function () {
				applyBulkToRows(root);
			});
		}

		root.addEventListener('change', function (event) {
			var checkbox = event.target.closest('[data-bulk-category-checkbox]');
			var node;

			if (!checkbox) {
				return;
			}

			node = closestCategoryNode(checkbox);
			if (node) {
				descendantCategoryCheckboxes(node).forEach(function (childCheckbox) {
					childCheckbox.checked = checkbox.checked;
					childCheckbox.indeterminate = false;
				});
			}

			updateBulkSelectionState(root);
		});

		updateBulkSelectionState(root);
	}

	function initMarkupBulkControls() {
		toArray(document.querySelectorAll('[data-markups-bulk]')).forEach(initMarkupBulk);
	}

	document.addEventListener('submit', function (event) {
		var submitter = event.submitter;

		if (!submitter || !submitter.classList.contains('button-link-delete')) {
			return;
		}

		if (!window.confirm('Clear all Schrack Sync logs?')) {
			event.preventDefault();
		}
	});

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', initMarkupBulkControls);
	} else {
		initMarkupBulkControls();
	}
}());
