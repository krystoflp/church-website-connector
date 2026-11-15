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

(function () {
    'use strict';
    const form = document.getElementById('cwc-gp-install-form');
    const key = document.getElementById('cwc-generatepress-license-key');
    const progress = document.getElementById('cwc-gp-setup-progress');
    if (!form || !key || !progress) { return; }
    const button = form.querySelector('[type="submit"]');
    let running = false;
    function show(message) {
        progress.textContent = message;
        if (window.wp && wp.a11y) { wp.a11y.speak(message); }
    }
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (running) { return; }
        if (!/^[A-Za-z0-9_-]{1,256}$/.test(key.value.trim())) {
            show(form.dataset.invalid);
            key.focus();
            return;
        }
        const body = new URLSearchParams(new FormData(form));
        body.set('license_key', key.value.trim());
        key.value = '';
        key.disabled = true;
        button.disabled = true;
        running = true;
        form.setAttribute('aria-busy', 'true');
        show(form.dataset.running);
        try {
            const response = await fetch(form.dataset.url, {
                method: 'POST', credentials: 'same-origin', body: body
            });
            const result = await response.json();
            if (!result.data || typeof result.data.message !== 'string') { throw new Error('Invalid response'); }
            show(result.data.message);
            if (result.success && result.data.status === 'complete') { window.location.reload(); }
        } catch (error) {
            show(form.dataset.failure);
        } finally {
            key.disabled = false;
            button.disabled = false;
            running = false;
            form.removeAttribute('aria-busy');
        }
    });
}());
