/* global wp */
(function () {
	'use strict';
	const panel = document.getElementById('cwc-adapter-setup');
	const button = document.getElementById('cwc-adapter-retry');
	const progress = document.getElementById('cwc-adapter-progress');
	if (!panel || !button || !progress) {
		return;
	}
    function show(message, level) {
        progress.textContent = message;
        document.querySelectorAll('[data-cwc-adapter-message]').forEach(function (node) { node.textContent = message; });
        document.querySelectorAll('[data-cwc-adapter-notice]').forEach(function (node) { node.className = 'notice notice-' + level; });
        if (window.wp && wp.a11y) { wp.a11y.speak(message); }
    }
	async function setup(automatic) {
		button.disabled = true;
		panel.setAttribute('aria-busy', 'true');
		show(automatic && panel.dataset.runningAutomatic ? panel.dataset.runningAutomatic : panel.dataset.running, 'info');
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
			if (!result.data || typeof result.data.message !== 'string') { throw new Error('Invalid response'); }
			show(result.data.message, result.success && result.data.status === 'complete' ? 'success' : 'warning');
			if (result.success && result.data.status === 'complete') {
				window.location.reload();
			}
		} catch (error) {
			// A request may have completed server-side. Refresh before retrying.
			show(panel.dataset.failure, 'warning');
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

(function () {
    'use strict';
    const button = document.getElementById('cwc-copy-diagnostics');
    const report = document.getElementById('cwc-diagnostic-report');
    const status = document.getElementById('cwc-copy-status');
    if (!button || !report || !status) { return; }
    button.addEventListener('click', async function () {
        try {
            await navigator.clipboard.writeText(report.value);
            status.textContent = button.dataset.success;
        } catch (error) {
            report.focus();
            report.select();
            status.textContent = button.dataset.failure;
        }
        if (window.wp && wp.a11y) { wp.a11y.speak(status.textContent); }
    });
}());
