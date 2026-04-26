(function () {
	'use strict';

	document.addEventListener('submit', function (event) {
		var submitter = event.submitter;

		if (!submitter || !submitter.classList.contains('button-link-delete')) {
			return;
		}

		if (!window.confirm('Clear all Schrack Sync logs?')) {
			event.preventDefault();
		}
	});
}());
