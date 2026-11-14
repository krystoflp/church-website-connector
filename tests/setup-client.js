/* Portable administrator setup-client checks; no browser or network. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
let source = path.join(__dirname, '../assets/setup.js');
if (!fs.existsSync(source)) source = path.join(__dirname, '../church-website-connector/assets/setup.js');
const script = fs.readFileSync(source, 'utf8');
let checks = 0;
async function scenario(automatic, success, missing = false) {
    const requests = [], attrs = {}, listeners = {}, spoken = [];
    let reloads = 0;
    const panel = { dataset: { url: 'https://connector.example/wp-admin/admin-ajax.php', action: 'church_connector_setup_adapter', nonce: 'fixture-nonce', automatic, running: 'Installing', failure: 'Refresh before retrying' }, setAttribute: (k, v) => attrs[k] = v, removeAttribute: k => delete attrs[k] };
    const button = { disabled: false, addEventListener: (event, fn) => listeners[event] = fn };
    const progress = { textContent: '' };
    const context = { document: { getElementById: id => missing ? null : ({ 'cwc-adapter-setup': panel, 'cwc-adapter-retry': button, 'cwc-adapter-progress': progress }[id]) }, URLSearchParams, window: { wp: true, location: { reload: () => reloads++ } }, wp: { a11y: { speak: text => spoken.push(text) } }, fetch: async (url, options) => { requests.push({ url, options }); if (success === 'network') throw new Error('Interrupted'); return { json: async () => ({ success: true, data: { status: success ? 'complete' : 'failed', message: success ? 'Ready' : 'Installation failed' } }) }; } };
    vm.runInNewContext(script, context);
    await new Promise(resolve => setImmediate(resolve));
    if (missing) { assert.equal(requests.length, 0); checks++; return; }
    assert.equal(requests.length, automatic === '1' ? 1 : 0); checks++;
    if (automatic !== '1') { listeners.click(); await new Promise(resolve => setImmediate(resolve)); }
    assert.equal(requests.length, 1); checks++;
    const request = requests[0];
    assert.equal(request.url, panel.dataset.url); checks++;
    assert.equal(request.options.method, 'POST'); checks++;
    assert.equal(request.options.credentials, 'same-origin'); checks++;
    assert.equal(request.options.body.get('nonce'), 'fixture-nonce'); checks++;
    assert.equal(request.options.body.get('automatic'), automatic); checks++;
    assert.equal(request.options.body.get('action'), 'church_connector_setup_adapter'); checks++;
    assert.equal(button.disabled, false); checks++;
    assert.equal(attrs['aria-busy'], undefined); checks++;
    assert.equal(reloads, success === true ? 1 : 0); checks++;
    assert.equal(progress.textContent, success === 'network' ? panel.dataset.failure : (success ? 'Ready' : 'Installation failed')); checks++;
    assert.equal(requests.length, 1); checks++;
    if (success !== 'network') { assert.equal(spoken.length, 1); checks++; }
}
(async () => {
    await scenario('1', true);
    await scenario('0', true);
    await scenario('1', false);
    await scenario('1', 'network');
    await scenario('1', true, true);
    process.stdout.write(`Setup client checks passed (${checks} checks)\n`);
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
