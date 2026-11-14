/* global wp */
(function () {
	'use strict';
	const panel = document.getElementById('cwc-adapter-setup');
	const button = document.getElementById('cwc-adapter-retry');
	const progress = document.getElementById('cwc-adapter-progress');
	if (!panel || !button || !progress) {
		return;
	}
	async function setup(automatic) {
		button.disabled = true;
		panel.setAttribute('aria-busy', 'true');
		progress.textContent = panel.dataset.running;
		try {
			const body = new URLSearchParams({
				action: panel.dataset.action,
				nonce: panel.dataset.nonce,
				automatic: automatic ? '1' : '0'
			});
			const response = await fetch(panel.dataset.url, {
				method: 'POST', credentials: 'same-origin', body: body
			});
			const result = await response.json();
			progress.textContent = result.data.message;
			if (window.wp && wp.a11y) {
				wp.a11y.speak(progress.textContent);
			}
			if (result.success && result.data.status === 'complete') {
				window.location.reload();
			}
		} catch (error) {
			// A request may have completed server-side. Refresh before retrying.
			progress.textContent = panel.dataset.failure;
		} finally {
			button.disabled = false;
			panel.removeAttribute('aria-busy');
		}
	}
	button.addEventListener('click', function () { setup(false); });
	if (panel.dataset.automatic === '1') {
		setup(true);
	}
}());
