(function () {
	'use strict';
	const root = document.getElementById('schrack-performance-tools');
	if (!root) return;
	const state = document.getElementById('schrack-performance-state');
	const message = document.getElementById('schrack-performance-message');
	const seo = document.getElementById('schrack-seo-audit');
	let busy = false, timer = null, retry = 15000;
	const buttons = root.querySelectorAll('button[data-operation]');
	function later(delay) { clearTimeout(timer); if (!document.hidden) timer = setTimeout(() => run('status'), delay); }
	async function run(operation) {
		if (busy || (operation === 'status' && document.hidden)) return;
		clearTimeout(timer); busy = true;
		buttons.forEach(button => { button.disabled = true; });
		const read = operation === 'status' || operation === 'seo_audit';
		if (!read) message.textContent = 'Se procesează…';
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 30000);
		try {
			const body = new URLSearchParams({action: 'schrack_performance_tools', nonce: schrackPerformanceTools.nonce, operation});
			const response = await fetch(schrackPerformanceTools.ajax, {method: 'POST', body, credentials: 'same-origin', signal: controller.signal});
			const result = await response.json();
			if (!response.ok || !result.success) throw new Error(typeof result.data === 'string' ? result.data : 'Cererea a eșuat.');
			retry = 15000;
			if (result.data.seo_audit) seo.textContent = JSON.stringify(result.data.seo_audit, null, 2);
			else {
				state.textContent = JSON.stringify(result.data, null, 2);
				if ([result.data.search_index, result.data.log_archive].some(job => job && job.status === 'running')) later(retry);
			}
			if (!read) message.textContent = 'Operațiune confirmată. Progresul continuă în fundal.';
		} catch (error) {
			message.textContent = read ? 'Starea nu poate fi citită: ' + error.message : 'Operațiunea nu a fost confirmată: ' + error.message + ' Verifică starea înainte de a reîncerca.';
			retry = Math.min(retry * 2, 60000); later(retry);
		} finally {
			clearTimeout(timeout); busy = false; buttons.forEach(button => { button.disabled = false; });
			if (operation !== 'status') later(1000); // Reconcile; never repeat a mutation automatically.
		}
	}
	buttons.forEach(button => button.addEventListener('click', () => run(button.dataset.operation)));
	document.addEventListener('visibilitychange', () => { clearTimeout(timer); if (!document.hidden) later(0); });
	run('status');
})();
