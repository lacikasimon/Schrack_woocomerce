/* Authenticated, non-overlapping updates; job commands are never retried automatically. */
(function () {
	'use strict';
	const config = window.schrackAttributeMerge;
	const root = document.getElementById('schrack-attribute-merge');
	if (!config || !root) return;
	const connection = document.getElementById('schrack-merge-connection');
	let timer, busy = false, expired = false, failures = 0, pendingCommand = null;
	let refreshOnly = false, commandNotice = '';

	function disableCommands(disabled) {
		root.querySelectorAll('[data-merge-operation] button').forEach(button => { button.disabled = disabled; });
	}
	function schedule(delay) {
		window.clearTimeout(timer);
		if (!expired && !document.hidden && (config.state === 'running' || refreshOnly)) {
			timer = window.setTimeout(() => request('', config.job, refreshOnly), delay);
		}
	}
	function render(state) {
		if (state.html) {
			const parsed = new DOMParser().parseFromString(state.html, 'text/html');
			const focused = document.activeElement;
			const focusedOperation = focused?.closest('[data-merge-operation]')?.dataset.mergeOperation;
			const focusedLink = focused?.closest('a')?.getAttribute('href');
			const scrollX = window.scrollX, scrollY = window.scrollY;
			['notices', 'controls', 'details'].forEach(name => {
				const selector = '[data-merge-region="' + name + '"]';
				const current = root.querySelector(selector), replacement = parsed.querySelector(selector);
				if (current && replacement) current.replaceChildren(...replacement.childNodes);
			});
			if (focused && !focused.isConnected) {
				const controls = Array.from(root.querySelectorAll('[data-merge-operation]'));
				const links = Array.from(root.querySelectorAll('a'));
				const target = controls.find(form => form.dataset.mergeOperation === focusedOperation)?.querySelector('button')
					|| links.find(link => focusedLink && link.getAttribute('href') === focusedLink)
					|| document.getElementById('schrack-merge-phase');
				target?.focus({preventScroll: true});
			}
			window.scrollTo(scrollX, scrollY);
		}
		['phase', 'groups', 'products', 'conflicts', 'progress'].forEach(key => {
			const element = document.getElementById('schrack-merge-' + key);
			if (element) element.textContent = String(state[key] ?? '');
		});
		document.getElementById('schrack-merge-title').textContent = state.state === 'running' ? 'Procesare în curs' : 'Analiză și unificare';
		document.getElementById('schrack-merge-products-label').textContent = state.state === 'complete' ? 'Produse actualizate' : 'Produse de actualizat';
		config.job = state.id; config.state = state.state; config.viewKey = state.view_key;
		const stale = state.state === 'running' && state.updated_at && Date.now() / 1000 - state.updated_at > 180;
		connection.textContent = commandNotice || (stale
			? 'Nu s-a confirmat progres nou în ultimele 3 minute. Dacă situația persistă, folosește „Reia procesarea”.'
			: '');
	}
	async function request(operation = '', job = config.job, readOnly = false) {
		if (busy || expired || (!operation && document.hidden)) return;
		busy = true;
		if (operation) disableCommands(true);
		window.clearTimeout(timer);
		const controller = new AbortController();
		const timeout = window.setTimeout(() => controller.abort(), 25000);
		try {
			const response = await fetch(config.url, {
				method: 'POST', credentials: 'same-origin', signal: controller.signal,
				body: new URLSearchParams({action: 'schrack_attribute_merge_tick', nonce: config.nonce, job,
					operation, view_key: config.viewKey || '', advance: readOnly ? '0' : '1'})
			});
			if (response.status === 401 || response.status === 403) {
				expired = true;
				connection.textContent = 'Sesiunea a expirat. Redeschide pagina pentru a continua.';
				return;
			}
			const result = await response.json();
			if (!response.ok || !result.success || !result.data) {
				throw new Error(result.data?.message || 'Răspuns indisponibil de la server.');
			}
			render(result.data);
			failures = 0; refreshOnly = false;
		} catch (error) {
			failures++;
			if (operation) {
				commandNotice = 'Acțiunea nu a fost confirmată: ' + error.message + ' Se verifică starea salvată. Acțiunea nu este repetată automat.';
				refreshOnly = true;
			}
			connection.textContent = commandNotice || 'Conexiunea întârzie. Progresul salvat se păstrează; verificarea se va relua automat.';
		} finally {
			window.clearTimeout(timeout);
			busy = false;
			if (pendingCommand && !expired) {
				const command = pendingCommand; pendingCommand = null;
				request(command.operation, command.job);
			} else {
				disableCommands(expired);
				schedule(failures ? Math.min(30000, 2500 * Math.pow(2, failures)) : 2500);
			}
		}
	}
	root.addEventListener('submit', event => {
		const form = event.target.closest('[data-merge-operation]');
		if (!form) return;
		event.preventDefault();
		if (expired || pendingCommand) return;
		const command = {operation: form.dataset.mergeOperation, job: form.elements.namedItem('job').value};
		commandNotice = ''; disableCommands(true);
		connection.textContent = 'Se trimite acțiunea…';
		if (busy) pendingCommand = command;
		else request(command.operation, command.job);
	});
	document.addEventListener('visibilitychange', () => {
		window.clearTimeout(timer);
		if (!document.hidden) schedule(0);
	});
	schedule(1000);
})();
