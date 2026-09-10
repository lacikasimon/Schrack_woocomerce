(function () {
	'use strict';

	function init(root) {
		var endpoint = root.getAttribute('data-ajax-url');
		var nonce = root.getAttribute('data-status-nonce');
		var timer = null;
		var request = null;
		var sequence = 0;
		var failures = 0;
		var stopped = false;
		var authBlocked = false;
		var mutating = false;
		var fragments = {};
		var state = {
			active: root.getAttribute('data-transfer-active') === '1',
			locked: !!root.querySelector('[data-transfer-lock][disabled]'),
			product_locked: !!root.querySelector('[data-transfer-product-lock][disabled]')
		};
		var refreshButton = root.querySelector('[data-transfer-refresh]');
		var connection = root.querySelector('[data-transfer-connection]');
		var notice = root.querySelector('[data-transfer-notice]');

		function message(element, text, type) {
			element.hidden = !text;
			element.className = 'notice notice-' + type + ' inline';
			element.replaceChildren();
			if (text) {
				var paragraph = document.createElement('p');
				paragraph.textContent = text;
				element.appendChild(paragraph);
			}
		}

		function updateLocks() {
			root.querySelectorAll('[data-transfer-lock]').forEach(function (fieldset) {
				fieldset.disabled = mutating || state.locked;
			});
			root.querySelectorAll('[data-transfer-fragment] button[type="submit"]').forEach(function (button) {
				button.disabled = mutating || (button.hasAttribute('data-transfer-product-lock') && state.product_locked);
			});
			refreshButton.disabled = !!request;
			root.setAttribute('aria-busy', mutating ? 'true' : 'false');
		}

		function apply(data) {
			if (!data || !data.fragments || !['export', 'import', 'category'].every(function (key) {
				return typeof data.fragments[key] === 'string';
			}) || typeof data.active !== 'boolean' || typeof data.locked !== 'boolean' || typeof data.product_locked !== 'boolean' ||
				typeof data.completed_export_id !== 'string' || typeof data.nonce !== 'string' || !data.action_nonces) {
				throw new Error('Răspunsul serverului nu conține starea operațiunilor.');
			}
			state = data;
			nonce = data.nonce;
			var scrollX = window.scrollX;
			var scrollY = window.scrollY;
			['export', 'import', 'category'].forEach(function (key) {
				var target = root.querySelector('[data-transfer-fragment="' + key + '"]');
				if (!target || fragments[key] === data.fragments[key]) {
					return;
				}
				// Status forms may change as a job finishes. Restore keyboard focus locally.
				var focused = target.contains(document.activeElement);
				var focusedForm = focused ? document.activeElement.closest('form') : null;
				var action = focusedForm ? focusedForm.querySelector('[name="action"]').value : '';
				var focusedLink = focused && document.activeElement.tagName === 'A' ? document.activeElement.href : '';
				target.innerHTML = data.fragments[key];
				fragments[key] = data.fragments[key];
				if (focused) {
					var actionField = Array.from(target.querySelectorAll('[name="action"]')).find(function (field) { return field.value === action; });
					var focusTarget = actionField ? actionField.form.querySelector('button[type="submit"]') : target.querySelector('h2, h3');
					if (focusedLink) {
						focusTarget = Array.from(target.querySelectorAll('a')).find(function (link) { return link.href === focusedLink; }) || focusTarget;
					}
					if (focusTarget) {
						if (!actionField) { focusTarget.tabIndex = -1; }
						focusTarget.focus({ preventScroll: true });
					}
				}
			});
			// These controls stay mounted, preserving file inputs, modes, and column order.
			var completed = root.querySelector('[data-transfer-completed-export]');
			completed.hidden = !data.completed_export_id;
			completed.querySelector('[name="export_id"]').value = data.completed_export_id;
			root.querySelector('[data-transfer-export-running]').hidden = !data.export_active;
			root.querySelectorAll('form').forEach(function (form) {
				var actionField = form.querySelector('[name="action"]');
				var nonceField = form.querySelector('[name="_wpnonce"]');
				if (actionField && nonceField && typeof data.action_nonces[actionField.value] === 'string') {
					nonceField.value = data.action_nonces[actionField.value];
				}
			});
			updateLocks();
			window.scrollTo(scrollX, scrollY);
		}

		function schedule(delay) {
			window.clearTimeout(timer);
			if (!stopped && !document.hidden && !authBlocked && (state.active || failures)) {
				timer = window.setTimeout(refresh, delay);
			}
		}

		async function send(body, mutation) {
			window.clearTimeout(timer);
			if (request) {
				if (mutating) { return; }
				request.abort();
			}
			var current = ++sequence;
			var controller = new AbortController();
			request = controller;
			mutating = mutation;
			updateLocks();
			var timeout = window.setTimeout(function () { controller.abort(); }, mutation ? 120000 : 30000);
			try {
				var response = await window.fetch(endpoint, {
					method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store', signal: controller.signal
				});
				if (current !== sequence || stopped) { return; }
				if (response.status === 401 || response.status === 403) {
					authBlocked = true;
					throw new Error('Sesiunea a expirat sau nu mai ai permisiune. Autentifică-te din nou, apoi apasă „Actualizează starea”.');
				}
				if (!response.ok) { throw new Error('Serverul nu a răspuns corect.'); }
				var result = await response.json();
				if (current !== sequence || stopped) { return; }
				apply(result.data);
				failures = 0;
				message(connection, '', 'error');
				if (mutation) {
					var feedback = result.data.notice;
					message(notice, feedback && feedback.message ? feedback.message : (result.success ? 'Operațiune procesată.' : 'Operațiunea nu a fost acceptată.'), result.success ? 'success' : 'error');
				}
			} catch (error) {
				if (current !== sequence || stopped) { return; }
				failures++;
				var text = authBlocked ? error.message : (mutation
					? 'Răspunsul operațiunii nu a putut fi confirmat. Verificăm starea; cererea nu va fi retrimisă automat.'
					: 'Starea nu a putut fi actualizată. Datele afișate sunt cele primite anterior; reîncercăm automat.');
				message(connection, text, 'error');
			} finally {
				window.clearTimeout(timeout);
				if (current === sequence) {
					request = null;
					mutating = false;
					updateLocks();
					schedule(failures ? Math.min(30000, 5000 * Math.pow(2, failures - 1)) : 5000);
				}
			}
		}

		function refresh() {
			if (request || stopped || document.hidden) { return; }
			var body = new FormData();
			body.set('action', 'schrack_wc_sync_transfer_status');
			body.set('nonce', nonce);
			return send(body, false);
		}

		root.addEventListener('submit', function (event) {
			var form = event.target.closest('form[data-transfer-action]');
			if (!form || event.defaultPrevented) { return; }
			event.preventDefault();
			if (mutating || (event.submitter && event.submitter.matches(':disabled'))) { return; }
			message(notice, '', 'info');
			// Capture the upload and options before temporarily disabling controls.
			send(new FormData(form), true);
		});
		refreshButton.addEventListener('click', function () {
			authBlocked = false;
			refresh();
		});
		document.addEventListener('visibilitychange', function () {
			window.clearTimeout(timer);
			if (!document.hidden && !authBlocked) { refresh(); }
		});
		window.addEventListener('pagehide', function () {
			stopped = true;
			sequence++;
			window.clearTimeout(timer);
			if (request) { request.abort(); }
		});
		window.addEventListener('pageshow', function (event) {
			if (event.persisted) {
				stopped = false;
				request = null;
				mutating = false;
				refresh();
			}
		});
		refresh();
	}

	function boot() {
		document.querySelectorAll('[data-product-transfer]').forEach(init);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
