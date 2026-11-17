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
async function generatepressScenario(outcome, invalid = false) {
    const requests = [], attrs = {}, spoken = [], listeners = {};
    let reloads = 0, focused = false;
    const key = {value:'',disabled:false,focus:()=>focused=true};
    const button = {disabled:false};
    const progress = {textContent:''};
    const form = {dataset:{url:'https://connector.example/wp-admin/admin-ajax.php',running:'Installing',failure:'Check the result before retrying',invalid:'Enter a licence'},
        querySelector:()=>button,addEventListener:(name,fn)=>listeners[name]=fn,
        setAttribute:(name,value)=>attrs[name]=value,removeAttribute:name=>delete attrs[name]};
    class TestFormData {
        constructor() { this.data=[['action','church_connector_setup_generatepress'],['_wpnonce','fixture-nonce'],['license_key',key.value]]; }
        [Symbol.iterator]() { return this.data[Symbol.iterator](); }
    }
    const context={document:{getElementById:id=>({'cwc-gp-install-form':form,'cwc-generatepress-license-key':key,'cwc-gp-setup-progress':progress}[id])},
        URLSearchParams,FormData:TestFormData,window:{wp:true,location:{reload:()=>reloads++}},wp:{a11y:{speak:message=>spoken.push(message)}},
        fetch:async(url,options)=>{requests.push({url,options,cleared:key.value==='',disabled:key.disabled,busy:attrs['aria-busy']});
            if(outcome==='network')throw new Error('FAKE-LICENCE-SHOULD-NOT-APPEAR');
            return {json:async()=>({success:outcome!=='denied',data:{status:outcome==='complete'?'complete':'failed',message:outcome==='complete'?'Ready':'Licence invalid'}})};
        }};
    vm.runInNewContext(script,context);
    assert.equal(requests.length,0);checks++;
    key.value=invalid?'bad key!':'FAKE-LICENCE-SHOULD-NOT-APPEAR';
    assert.equal(requests.length,0);checks++;
    let prevented=0;const event={preventDefault:()=>prevented++};
    const first=listeners.submit(event);
    if(!invalid) await listeners.submit(event);
    await first;
    if(invalid){assert.equal(requests.length,0);checks++;assert.equal(progress.textContent,form.dataset.invalid);checks++;assert.equal(focused,true);checks++;return;}
    assert.equal(requests.length,1);checks++;
    const request=requests[0];
    assert.equal(request.url,form.dataset.url);checks++;
    assert.equal(request.options.method,'POST');checks++;
    assert.equal(request.options.credentials,'same-origin');checks++;
    assert.equal(request.options.body.get('license_key'),'FAKE-LICENCE-SHOULD-NOT-APPEAR');checks++;
    assert.equal(request.options.body.get('_wpnonce'),'fixture-nonce');checks++;
    assert.equal(request.options.body.get('action'),'church_connector_setup_generatepress');checks++;
    assert.equal(request.cleared,true);checks++;assert.equal(request.disabled,true);checks++;assert.equal(request.busy,'true');checks++;
    assert.equal(key.value,'');checks++;assert.equal(key.disabled,false);checks++;assert.equal(button.disabled,false);checks++;
    assert.equal(attrs['aria-busy'],undefined);checks++;
    assert.equal(reloads,outcome==='complete'?1:0);checks++;
    assert.equal(progress.textContent,outcome==='network'?form.dataset.failure:(outcome==='complete'?'Ready':'Licence invalid'));checks++;
    assert.equal(progress.textContent.includes('FAKE-LICENCE'),false);checks++;
    assert.equal(spoken.length,2);checks++;assert.equal(prevented,2);checks++;
}
async function rocketNoticeScenario() {
    const requests = [], listeners = {};
    const notice = {dataset:{url:'https://connector.example/wp-admin/admin-ajax.php',nonce:'fixture-dismiss'},addEventListener:(name,fn)=>listeners[name]=fn};
    vm.runInNewContext(script,{document:{getElementById:id=>id==='cwc-rocket-notice'?notice:null},URLSearchParams,
        fetch:async(url,options)=>{requests.push({url,options});throw new Error('offline');}});
    assert.equal(requests.length,0);checks++;
    listeners.click({target:{closest:()=>null}});assert.equal(requests.length,0);checks++;
    listeners.click({target:{closest:selector=>selector==='.notice-dismiss'}});
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(requests.length,1);checks++;
    assert.equal(requests[0].options.method,'POST');checks++;
    assert.equal(requests[0].options.credentials,'same-origin');checks++;
    assert.equal(requests[0].url,notice.dataset.url);checks++;
    assert.equal(requests[0].options.body.get('nonce'),'fixture-dismiss');checks++;
    assert.equal(requests[0].options.body.get('action'),'church_connector_dismiss_rocket_notice');checks++;
}
(async () => {
    await scenario('1', true);
    await scenario('0', true);
    await scenario('1', false);
    await scenario('1', 'network');
    await scenario('1', true, true);
    await rocketNoticeScenario();
    await clipboardScenario(false);
    await clipboardScenario(true);
    await generatepressScenario('complete');
    await generatepressScenario('failed');
    await generatepressScenario('denied');
    await generatepressScenario('network');
    await generatepressScenario('failed', true);
    process.stdout.write(`Setup client checks passed (${checks} checks)\n`);
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
