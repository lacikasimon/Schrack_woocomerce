(function () {
	'use strict';
	const root = document.getElementById('schrack-edoc-test-orders');
	if (!root) return;
	const prepare = document.getElementById('edoc-test-prepare');
	const run = document.getElementById('edoc-test-run');
	const message = document.getElementById('edoc-test-message');
	const progress = document.getElementById('edoc-test-progress');
	const months = document.getElementById('edoc-test-months');
	const results = document.getElementById('edoc-test-results');
	let state = {status: 'idle'}, busy = false, automatic = false, timer = null, retry = 4000;
	function later(callback, delay) { clearTimeout(timer); if (!document.hidden) timer = setTimeout(callback, delay); }
	async function request(command) {
		const data = new URLSearchParams({action: 'schrack_edoc_test_orders', nonce: schrackEDocTestOrders.nonce, command});
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 30000);
		try {
			let url = schrackEDocTestOrders.url;
			const args = {credentials: 'same-origin', cache: 'no-store', signal: controller.signal};
			if (command === 'status') { url += '?' + data.toString(); }
			else { data.set('batch', state.batch || ''); data.set('cursor', String(state.cursor || 0)); args.method = 'POST'; args.body = data; }
			const response = await fetch(url, args);
			const json = await response.json();
			if (!response.ok || !json.success) throw new Error(typeof json.data === 'string' ? json.data : 'Cererea nu a reușit.');
			return json.data;
		} finally { clearTimeout(timeout); }
	}
	function row(table, key, values, link) {
		let tr = table.querySelector('[data-key="' + key + '"]');
		if (!tr) {
			tr = document.createElement('tr'); tr.dataset.key = key;
			values.forEach(() => tr.appendChild(document.createElement('td'))); table.appendChild(tr);
		}
		values.forEach((value, index) => {
			const cell = tr.children[index];
			if (index === 0 && link) {
				let a = cell.querySelector('a');
				if (!a) { a = document.createElement('a'); cell.appendChild(a); }
				a.href = link; if (a.textContent !== String(value)) a.textContent = value;
			} else if (cell.textContent !== String(value)) cell.textContent = value;
		});
	}
	function render(data) {
		state = data;
		prepare.hidden = state.status !== 'idle';
		run.hidden = state.status === 'idle' || state.status === 'complete';
		run.textContent = state.status === 'ready' ? 'Creează comenzile de test' : 'Continuă generarea';
		run.setAttribute('aria-disabled', String(busy || automatic));
		progress.textContent = (state.status === 'complete' ? 'Lot creat integral. ' : '') + (state.cursor || 0) + '/' + (state.total || 0) + ' comenzi create · ' + (state.confirmed || 0) + ' confirmate în ERP.';
		for (const item of state.months || []) row(months, item.month, [item.month, item.planned, item.created, item.confirmed]);
		for (const item of state.orders || []) row(results, String(item.id), ['#' + item.id, item.date, item.name, item.total, item.confirmed ? 'Confirmată' : 'În așteptare'], item.url);
		if (state.error) message.textContent = state.error;
	}
	async function poll() {
		if (busy || document.hidden) return;
		busy = true;
		try {
			render(await request('status')); retry = 4000;
			if (state.status === 'complete' && state.edoc_enabled && state.confirmed < state.total) later(poll, retry);
		} catch (error) {
			message.textContent = 'Starea nu poate fi citită: ' + error.message;
			retry = Math.min(retry * 2, 60000); later(poll, retry);
		} finally { busy = false; run.setAttribute('aria-disabled', String(automatic)); }
	}
	async function mutate(command) {
		if (busy || document.hidden) return;
		clearTimeout(timer); busy = true; run.setAttribute('aria-disabled', 'true'); prepare.setAttribute('aria-disabled', 'true');
		message.textContent = command === 'prepare' ? 'Se pregătește lotul, fără a crea comenzi…' : 'Se creează următoarea comandă TEST…';
		try {
			render(await request(command)); retry = 4000;
			if (command === 'prepare') { automatic = false; message.textContent = 'Lot pregătit. Verificați distribuția lunară, apoi porniți generarea.'; }
			else if (state.status === 'complete') { automatic = false; message.textContent = 'Comenzile TEST au fost create. Se verifică transmiterea către ERP.'; later(poll, retry); }
			else if (automatic && !document.hidden) later(() => mutate('step'), 100);
		} catch (error) {
			automatic = false;
			message.textContent = 'Operațiunea nu a fost confirmată: ' + error.message + ' Se verifică starea. Generarea se reia numai din buton.';
			later(poll, 1500); // Read-only reconciliation. Never automatically replay a failed write.
		} finally { busy = false; run.setAttribute('aria-disabled', String(automatic)); prepare.setAttribute('aria-disabled', 'false'); }
	}
	prepare.addEventListener('click', () => { if (!busy) mutate('prepare'); });
	run.addEventListener('click', () => { if (!busy && !automatic) { automatic = true; mutate('step'); } });
	document.addEventListener('visibilitychange', () => {
		clearTimeout(timer);
		if (document.hidden) { if (automatic) message.textContent = 'Generare suspendată cât timp fila este ascunsă. Folosiți Continuă la revenire.'; automatic = false; }
		else later(poll, 0);
	});
	poll();
}());
