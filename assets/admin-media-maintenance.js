(function () {
	'use strict';
	const root = document.getElementById('schrack-media-maintenance');
	if (!root) return;
	const message = document.getElementById('schrack-media-message');
	const stateElement = document.getElementById('schrack-media-state');
	const report = document.getElementById('schrack-media-report');
	const previous = document.getElementById('schrack-media-previous');
	const next = document.getElementById('schrack-media-next');
	const buttons = Array.from(root.querySelectorAll('[data-media-operation]'));
	let busy = false, timer = null, retry = 5000, state = {}, before = 0, nextCursor = 0, history = [], rendered = '';
	function later(delay) {
		clearTimeout(timer);
		if (!document.hidden) timer = setTimeout(() => run('status'), delay);
	}
	function controls() {
		buttons.forEach(button => {
			const operation = button.dataset.mediaOperation;
			button.disabled = busy || (operation === 'scan' && ['running', 'paused'].includes(state.status)) ||
				(operation === 'repair' && (state.status !== 'complete' || state.mode !== 'scan')) ||
				(operation === 'pause' && (state.status !== 'running' || state.pause_requested)) ||
				(operation === 'resume' && !['paused', 'error'].includes(state.status));
		});
		previous.disabled = busy || history.length === 0;
		next.disabled = busy || !nextCursor;
	}
	function cell(tr, text) {
		const td = document.createElement('td'); td.textContent = text; tr.appendChild(td); return td;
	}
	function render(data) {
		const scroll = [window.scrollX, window.scrollY];
		state = data.state || {}; nextCursor = data.next || 0;
		const labels = {running: 'În curs', paused: 'În pauză', complete: 'Încheiat', error: 'Eroare'};
		stateElement.textContent = state.id ? `${labels[state.status] || state.status} — ${state.mode === 'repair' ? 'reparare' : 'verificare'}\n` +
			`Verificate: ${state.scanned} · cu probleme: ${state.issues}\nCopii cu aceeași sursă: ${state.source_copies} · copii identice: ${state.identical_copies}\nReparate: ${state.repaired} · omise la reparare: ${state.skipped}` +
			(state.pause_requested ? '\nPauză cerută: se încheie imaginea curentă.' : '') + (state.message ? '\n' + state.message : '') : 'Nicio verificare pornită.';
		const signature = JSON.stringify(data.rows || []);
		if (signature === rendered) { window.scrollTo(...scroll); return; }
		rendered = signature;
		const focused = report.contains(document.activeElement) ? document.activeElement.dataset.mediaId : null;
		const rows = (data.rows || []).map(row => {
			const tr = document.createElement('tr');
			const title = cell(tr, '');
			if (row.edit_url) {
				const link = document.createElement('a'); link.textContent = `${row.title || 'Imagine'} (#${row.id})`;
				link.href = row.edit_url; link.dataset.mediaId = String(row.id); title.appendChild(link);
			} else title.textContent = `${row.title || 'Imagine'} (#${row.id})`;
			cell(tr, `${row.file}\n${row.dimensions.length ? row.dimensions.join(' × ') + ' px' : 'Dimensiuni necunoscute'} · ${(row.bytes / 1048576).toFixed(2)} MB\n${row.issues.join('; ') || 'Fără probleme detectate'}`).style.whiteSpace = 'pre-wrap';
			cell(tr, `Sursă comună: ${row.source_hash_matches.join(', ') || '—'}${row.source_hash_more ? ' (și altele)' : ''}\nFișier identic: ${row.file_hash_matches.join(', ') || '—'}${row.file_hash_more ? ' (și altele)' : ''}`).style.whiteSpace = 'pre-wrap';
			const usage = row.references.known.map(ref => `${ref.type} #${ref.id}: ${ref.title} (${ref.context})`).join('\n');
			cell(tr, (usage || 'Nicio utilizare cunoscută.') + '\nAlte utilizări pot exista.' + (row.references.limited ? '\nLista utilizărilor este limitată.' : '')).style.whiteSpace = 'pre-wrap';
			cell(tr, ({pending: 'De reparat', repaired: 'Reparat', skipped: 'Omis', not_needed: 'Nu este necesar', unavailable: 'Original indisponibil / invalid'})[row.repair] + (row.repair_message ? ': ' + row.repair_message : ''));
			return tr;
		});
		report.replaceChildren(...rows);
		if (focused) {
			const link = Array.from(report.querySelectorAll('a')).find(a => a.dataset.mediaId === focused);
			if (link) link.focus({preventScroll: true});
		}
		window.scrollTo(...scroll);
	}
	async function run(operation) {
		if (busy || (operation === 'status' && document.hidden)) return;
		const active = document.activeElement;
		const focusedControl = buttons.includes(active) || active === previous || active === next ? active : null;
		clearTimeout(timer); busy = true; controls();
		const read = operation === 'status';
		if (!read) message.textContent = 'Se trimite operațiunea…';
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 30000);
		try {
			const body = new URLSearchParams({action: 'schrack_media_maintenance', nonce: schrackMediaMaintenance.nonce, operation, job_id: state.id || '', before: String(before)});
			const response = await fetch(schrackMediaMaintenance.ajax, {method: 'POST', body, credentials: 'same-origin', signal: controller.signal});
			const result = await response.json();
			if (!response.ok || !result.success) throw new Error(result.data && result.data.message || 'Cererea a eșuat.');
			if (operation === 'scan') { before = 0; history = []; }
			render(result.data); retry = 5000;
			message.textContent = read ? (state.message || '') : 'Operațiune confirmată. Originalele sunt păstrate.';
			if (state.status === 'running') later(retry);
		} catch (error) {
			message.textContent = (read ? 'Starea nu poate fi citită: ' : 'Operațiunea nu a fost confirmată: ') + error.message +
				(read ? '' : ' Verifică starea înainte de a reîncerca.');
			retry = Math.min(retry * 2, 60000); later(retry); // Reads only; mutations are never retried.
		} finally {
			clearTimeout(timeout); busy = false; controls();
			if (focusedControl && focusedControl.isConnected && !focusedControl.disabled && document.activeElement === document.body) focusedControl.focus({preventScroll: true});
			if (!read) later(1000);
		}
	}
	buttons.forEach(button => button.addEventListener('click', () => run(button.dataset.mediaOperation)));
	next.addEventListener('click', () => { if (busy || !nextCursor) return; history.push(before); before = nextCursor; run('status'); });
	previous.addEventListener('click', () => { if (busy || !history.length) return; before = history.pop(); run('status'); });
	document.addEventListener('visibilitychange', () => {
		clearTimeout(timer);
		if (!document.hidden && !busy) later(0);
	});
	run('status');
})();
