(function () {
	'use strict';
	const root = document.getElementById('schrack-cache-warmer');
	if (!root) return;
	const form = document.getElementById('schrack-cache-form');
	const message = document.getElementById('schrack-cache-message');
	const status = document.getElementById('schrack-cache-status');
	const results = document.getElementById('schrack-cache-results');
	const select = document.getElementById('schrack-profile-url');
	const profile = document.getElementById('schrack-profile-result');
	let timer = null, busy = false, running = false, retry = 4000;
	const labels = {idle: 'În așteptare', running: 'În curs', complete: 'Terminat', stopped: 'Oprit', error: 'Oprit din cauza erorilor; verifică rezultatele'};
	function later(delay) {
		clearTimeout(timer);
		if (!document.hidden) timer = setTimeout(poll, delay);
	}
	async function request(command, data) {
		const body = new URLSearchParams(data || {});
		body.set('action', 'schrack_cache_warmer');
		body.set('nonce', schrackCacheWarmer.nonce);
		body.set('command', command);
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 35000);
		try {
			const response = await fetch(schrackCacheWarmer.ajax, {method: 'POST', body, credentials: 'same-origin', signal: controller.signal});
			const json = await response.json();
			if (!response.ok || !json.success) throw new Error(typeof json.data === 'string' ? json.data : 'Cererea nu a reușit.');
			return json.data;
		} finally { clearTimeout(timeout); }
	}
	function render(data, saved) {
		if (data.measurement) {
			profile.textContent = JSON.stringify(data.measurement, null, 2);
			return;
		}
		const state = data.state || {};
		running = state.status === 'running';
		status.textContent = (labels[state.status] || state.status) + ' — ' + (state.catalog ? (state.processed || 0) + ' pagini · produse în stoc parcurse: ' + (state.products_processed || 0) + ' · HIT confirmat: ' + (state.confirmed || 0) + ' · neconfirmat: ' + (state.unconfirmed || 0) + ' · ' + (state.phase === 'products' ? 'catalogul în stoc' : 'pagini prioritare') : (state.cursor || 0) + '/' + (state.urls || []).length) +
			(data.next ? ' · Următoarea cerere: ' + new Date(data.next * 1000).toLocaleTimeString('ro-RO') : '') +
			(data.cron_disabled ? ' · WP-Cron la vizite este dezactivat; este necesar cron-ul găzduirii.' : '');
		results.replaceChildren();
		for (const item of state.results || []) {
			const row = document.createElement('tr');
			for (const value of [item.url, item.http || 'Eroare', item.cache + (item.cache === 'MISS' ? (item.verified === true ? ' → HIT' : item.verified === false ? ' · neconfirmat' : ' · se verifică') : ''), item.ms + ' ms' + (item.verify_ms !== undefined ? ' / verificare ' + item.verify_ms + ' ms' : '')]) {
				const cell = document.createElement('td'); cell.textContent = value; row.appendChild(cell);
			}
			results.appendChild(row);
		}
		// Only an explicit save updates the selector. Polling never overwrites edits.
		if (saved) {
			const value = select.value;
			select.replaceChildren();
			for (const url of data.config.urls) {
				const option = document.createElement('option'); option.value = url; option.textContent = url; select.appendChild(option);
			}
			if (data.config.urls.includes(value)) select.value = value;
		}
	}
	async function poll() {
		if (busy || document.hidden) return;
		busy = true;
		try {
			render(await request('status'));
			retry = 4000;
			if (running) later(retry);
		} catch (error) {
			status.textContent = 'Starea nu poate fi citită. Reîncercare automată: ' + error.message;
			retry = Math.min(retry * 2, 60000); later(retry);
		} finally { busy = false; }
	}
	async function command(name) {
		if (busy) { message.textContent = 'Se citește starea. Reîncearcă peste o clipă.'; return; }
		clearTimeout(timer); busy = true;
		const buttons = root.querySelectorAll('button');
		buttons.forEach(button => { button.disabled = true; });
		message.textContent = name.startsWith('profile') ? 'Măsurare în curs…' : 'Se procesează…';
		try {
			const data = name === 'save' ? {urls: form.elements.urls.value, enabled: form.elements.enabled.checked ? '1' : '0', discover: form.elements.discover.checked ? '1' : '0'} : {url: select.value};
			render(await request(name, data), name === 'save');
			if (name === 'stop') form.elements.enabled.checked = false;
			message.textContent = 'Operațiune finalizată.';
		} catch (error) {
			message.textContent = 'Operațiunea nu a fost confirmată: ' + error.message + ' Verifică starea înainte de a reîncerca.';
		} finally {
			busy = false; buttons.forEach(button => { button.disabled = false; });
			// Read-only reconciliation after any uncertain write. Never retry the write.
			later(1000);
		}
	}
	form.addEventListener('submit', event => { event.preventDefault(); command('save'); });
	root.querySelectorAll('[data-command]').forEach(button => button.addEventListener('click', () => command(button.dataset.command)));
	document.addEventListener('visibilitychange', () => { clearTimeout(timer); if (!document.hidden) later(0); });
	poll();
})();
