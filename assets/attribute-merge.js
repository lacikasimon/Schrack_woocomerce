/* Authenticated polling also drives short batches when cron is delayed. */
(function () {
	'use strict';
	const config = window.schrackAttributeMerge;
	if (!config || config.state !== 'running') return;
	const connection = document.getElementById('schrack-merge-connection');
	let stopped = false;
	async function tick() {
		if (stopped) return;
		const controller = new AbortController();
		const timeout = window.setTimeout(() => controller.abort(), 25000);
		try {
			const response = await fetch(config.url, {
				method: 'POST', credentials: 'same-origin', signal: controller.signal,
				body: new URLSearchParams({action: 'schrack_attribute_merge_tick', nonce: config.nonce, job: config.job})
			});
			if (response.status === 401 || response.status === 403) {
				stopped = true;
				connection.textContent = 'Sesiunea a expirat. Reîncarcă pagina pentru a continua.';
				return;
			}
			if (!response.ok) throw new Error('Request failed');
			const result = await response.json();
			if (!result.success || !result.data) throw new Error('Invalid response');
			const state = result.data;
			if (state.id !== config.job || state.state !== 'running') {
				stopped = true; window.location.reload(); return;
			}
			['phase', 'groups', 'products', 'conflicts'].forEach(key => {
				const element = document.getElementById('schrack-merge-' + key);
				if (element) element.textContent = String(state[key]);
			});
			connection.textContent = 'Progres salvat. Înregistrări verificate: ' + state.scanned + '.';
		} catch (error) {
			connection.textContent = 'Conexiunea întârzie. Progresul salvat se păstrează; se încearcă din nou.';
		} finally {
			window.clearTimeout(timeout);
			if (!stopped) window.setTimeout(tick, 2500);
		}
	}
	window.setTimeout(tick, 1000);
})();
