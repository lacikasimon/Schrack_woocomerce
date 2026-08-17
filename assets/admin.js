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

	function initB2BFilters(root) {
		var form = root.closest('form');
		var table = form ? form.querySelector('[data-b2b-table]') : null;
		var search = root.querySelector('[data-b2b-search]');
		var statusFilter = root.querySelector('[data-b2b-status-filter]');
		var clear = root.querySelector('[data-b2b-clear-filters]');
		var visibleCount = root.querySelector('[data-b2b-visible-count]');
		var rows = table ? toArray(table.querySelectorAll('[data-b2b-row]')) : [];
		var emptyRow = table ? table.querySelector('[data-b2b-empty-row]') : null;

		function refresh() {
			var query = search ? search.value.trim().toLowerCase() : '';
			var status = statusFilter ? statusFilter.value : '';
			var count = 0;

			rows.forEach(function (row) {
				var searchText = (row.getAttribute('data-b2b-search') || '').toLowerCase();
				var rowStatus = row.getAttribute('data-b2b-status') || '';
				var matchesSearch = '' === query || searchText.indexOf(query) !== -1;
				var matchesStatus = '' === status || rowStatus === status;
				var isVisible = matchesSearch && matchesStatus;

				row.hidden = !isVisible;

				if (isVisible) {
					count++;
				}
			});

			if (visibleCount) {
				visibleCount.textContent = String(count);
			}

			if (emptyRow) {
				emptyRow.hidden = count > 0;
			}
		}

		if (!table) {
			return;
		}

		if (search) {
			search.addEventListener('input', refresh);
		}

		if (statusFilter) {
			statusFilter.addEventListener('change', refresh);
		}

		if (clear) {
			clear.addEventListener('click', function () {
				if (search) {
					search.value = '';
				}

				if (statusFilter) {
					statusFilter.value = '';
				}

				refresh();

				if (search) {
					search.focus();
				}
			});
		}

		refresh();
	}

	function initExportColumnBuilder(root) {
		var form = root.closest('form');
		var builder = root.querySelector('[data-export-column-builder]');
		var available = root.querySelector('[data-export-column-available]');
		var selected = root.querySelector('[data-export-column-selected]');
		var search = root.querySelector('[data-export-column-search]');
		var addButton = root.querySelector('[data-export-column-add]');
		var count = root.querySelector('[data-export-column-count]');
		var extraMeta = root.querySelector('[name="export_extra_meta_keys"]');

		if (!form || !builder || !available || !selected) {
			return;
		}

		function selectedItems() {
			return toArray(selected.querySelectorAll('[data-export-column-item]'));
		}

		function selectedIds() {
			return selectedItems().map(function (item) {
				return item.getAttribute('data-column-id') || '';
			});
		}

		function findOption(columnId) {
			return toArray(available.querySelectorAll('option')).filter(function (option) {
				return option.value === columnId;
			})[0] || null;
		}

		function updateState() {
			var ids = selectedIds();
			var items = selectedItems();

			toArray(available.querySelectorAll('option')).forEach(function (option) {
				option.disabled = ids.indexOf(option.value) !== -1;
			});

			items.forEach(function (item, index) {
				var up = item.querySelector('[data-export-column-action="up"]');
				var down = item.querySelector('[data-export-column-action="down"]');

				if (up) {
					up.disabled = 0 === index;
				}

				if (down) {
					down.disabled = index === items.length - 1;
				}
			});

			if (count) {
				count.textContent = String(items.length);
			}
		}

		function createAction(action, text, label) {
			var button = document.createElement('button');

			button.type = 'button';
			button.className = 'button button-small';
			button.setAttribute('data-export-column-action', action);
			button.setAttribute('aria-label', label);
			button.textContent = text;

			return button;
		}

		function addColumn(columnId) {
			var option;
			var item;
			var labelWrap;
			var label;
			var code;
			var actions;
			var input;

			if (!columnId || selectedIds().indexOf(columnId) !== -1) {
				return;
			}

			option = findOption(columnId);
			label = option ? (option.getAttribute('data-label') || option.textContent || columnId) : columnId;
			item = document.createElement('li');
			item.setAttribute('data-export-column-item', '');
			item.setAttribute('data-column-id', columnId);

			labelWrap = document.createElement('span');
			labelWrap.className = 'schrack-export-columns__item-label';
			labelWrap.appendChild(document.createElement('strong')).textContent = label;
			code = document.createElement('code');
			code.textContent = columnId;
			labelWrap.appendChild(code);
			item.appendChild(labelWrap);

			actions = document.createElement('span');
			actions.className = 'schrack-export-columns__item-actions';
			actions.appendChild(createAction('up', '↑', root.getAttribute('data-label-up') || 'Up'));
			actions.appendChild(createAction('down', '↓', root.getAttribute('data-label-down') || 'Down'));
			actions.appendChild(createAction('remove', '×', root.getAttribute('data-label-remove') || 'Remove'));
			item.appendChild(actions);

			input = document.createElement('input');
			input.type = 'hidden';
			input.name = 'export_columns[]';
			input.value = columnId;
			item.appendChild(input);
			selected.appendChild(item);
		}

		function usePreset(columns) {
			selected.innerHTML = '';
			columns.forEach(addColumn);
			updateState();
		}

		function refreshMode() {
			var customMode = root.querySelector('[name="export_column_mode"][value="custom"]');

			builder.hidden = !customMode || !customMode.checked;
		}

		if (addButton) {
			addButton.addEventListener('click', function () {
				toArray(available.selectedOptions).forEach(function (option) {
					if (!option.disabled) {
						addColumn(option.value);
					}
				});
				available.selectedIndex = -1;
				updateState();
			});
		}

		available.addEventListener('dblclick', function () {
			toArray(available.selectedOptions).forEach(function (option) {
				if (!option.disabled) {
					addColumn(option.value);
				}
			});
			available.selectedIndex = -1;
			updateState();
		});

		selected.addEventListener('click', function (event) {
			var button = event.target.closest('[data-export-column-action]');
			var item = button ? button.closest('[data-export-column-item]') : null;
			var action = button ? button.getAttribute('data-export-column-action') : '';

			if (!item) {
				return;
			}

			if ('remove' === action) {
				item.remove();
			} else if ('up' === action && item.previousElementSibling) {
				selected.insertBefore(item, item.previousElementSibling);
			} else if ('down' === action && item.nextElementSibling) {
				selected.insertBefore(item.nextElementSibling, item);
			}

			updateState();
		});

		toArray(root.querySelectorAll('[data-export-column-preset]')).forEach(function (button) {
			button.addEventListener('click', function () {
				var columns = [];

				try {
					columns = JSON.parse(button.getAttribute('data-export-column-preset') || '[]');
				} catch (error) {
					columns = [];
				}

				if (Array.isArray(columns)) {
					usePreset(columns);
				}
			});
		});

		if (search) {
			search.addEventListener('input', function () {
				var query = search.value.trim().toLowerCase();

				toArray(available.querySelectorAll('option')).forEach(function (option) {
					option.hidden = '' !== query && (option.textContent || '').toLowerCase().indexOf(query) === -1;
				});
			});
		}

		root.addEventListener('change', function (event) {
			if (event.target.matches('[name="export_column_mode"]')) {
				refreshMode();
			}
		});

		form.addEventListener('submit', function (event) {
			var customMode = root.querySelector('[name="export_column_mode"][value="custom"]');

			if (customMode && customMode.checked && 0 === selectedItems().length && (!extraMeta || '' === extraMeta.value.trim())) {
				event.preventDefault();
				window.alert(root.getAttribute('data-empty-message') || 'Choose at least one column.');
			}
		});

		refreshMode();
		updateState();
	}

	function initMarkupBulkControls() {
		toArray(document.querySelectorAll('[data-markups-bulk]')).forEach(initMarkupBulk);
	}

	function initB2BFilterControls() {
		toArray(document.querySelectorAll('[data-b2b-filters]')).forEach(initB2BFilters);
	}

	function initExportColumnControls() {
		toArray(document.querySelectorAll('[data-export-columns]')).forEach(initExportColumnBuilder);
	}

	function initAdminControls() {
		initMarkupBulkControls();
		initB2BFilterControls();
		initExportColumnControls();
	}

	document.addEventListener('submit', function (event) {
		var submitter = event.submitter;

		if (!submitter || !submitter.classList.contains('button-link-delete')) {
			return;
		}

		if (!window.confirm('Clear all Product furnizor importer logs?')) {
			event.preventDefault();
		}
	});

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', initAdminControls);
	} else {
		initAdminControls();
	}
}());
