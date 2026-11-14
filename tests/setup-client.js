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
    const summary = { textContent: '' }, notice = { className: 'notice notice-warning' };
    const observations = [];
    const context = { document: { querySelectorAll: selector => selector === '[data-cwc-adapter-message]' ? [summary] : [notice], getElementById: id => missing ? null : ({ 'cwc-adapter-setup': panel, 'cwc-adapter-retry': button, 'cwc-adapter-progress': progress }[id]) }, URLSearchParams, window: { wp: true, location: { reload: () => reloads++ } }, wp: { a11y: { speak: text => spoken.push(text) } }, fetch: async (url, options) => { observations.push({progress:progress.textContent, summary:summary.textContent, notice:notice.className, busy:attrs['aria-busy'], disabled:button.disabled}); requests.push({ url, options }); if (success === 'network') throw new Error('Interrupted'); return { json: async () => ({ success: true, data: { status: success ? 'complete' : 'failed', message: success ? 'Ready' : 'Installation failed' } }) }; } };
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
    assert.equal(spoken.length, 2); checks++;
    assert.equal(summary.textContent, progress.textContent); checks++;
    assert.equal(notice.className, success === true ? 'notice notice-success' : 'notice notice-warning'); checks++;
    assert.deepEqual(observations, [{progress:'Installing',summary:'Installing',notice:'notice notice-info',busy:'true',disabled:true}]); checks++;
}
async function clipboardScenario(fails) {
    const listeners = {}, copied = [], spoken = [];
    let focused = false, selected = false;
    const button = {dataset:{success:'Copied',failure:'Copy with keyboard'},addEventListener:(name,fn)=>listeners[name]=fn};
    const report = {value:'{"connector":"1.7.3"}',focus:()=>focused=true,select:()=>selected=true};
    const status = {textContent:''};
    vm.runInNewContext(script,{document:{getElementById:id=>({'cwc-copy-diagnostics':button,'cwc-diagnostic-report':report,'cwc-copy-status':status}[id])},navigator:{clipboard:{writeText:async text=>{if(fails)throw new Error('Clipboard unavailable');copied.push(text);}}},window:{wp:true},wp:{a11y:{speak:text=>spoken.push(text)}}});
    await listeners.click();
    assert.equal(status.textContent,fails?'Copy with keyboard':'Copied');checks++;
    assert.deepEqual(copied,fails?[]:[report.value]);checks++;
    assert.equal(focused,fails);checks++;assert.equal(selected,fails);checks++;
    assert.deepEqual(spoken,[status.textContent]);checks++;
}
(async () => {
    await scenario('1', true);
    await scenario('0', true);
    await scenario('1', false);
    await scenario('1', 'network');
    await scenario('1', true, true);
    await clipboardScenario(false);
    await clipboardScenario(true);
    process.stdout.write(`Setup client checks passed (${checks} checks)\n`);
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
