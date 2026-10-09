// Isolated browser integration: serves the actual audited, read-only Admin bundle.
// No installed Xboard application, API, database, or shared file is modified.
import {createServer, request as proxyRequest} from 'node:http';
import {readFileSync, existsSync, mkdtempSync, mkdirSync, writeFileSync} from 'node:fs';
import {resolve, join} from 'node:path';
import {spawn, spawnSync} from 'node:child_process';
import nodeAssert from 'node:assert/strict';

const checks = {assertions: 0, states: 0};
function assert(...args) { checks.assertions++; return nodeAssert(...args); }
assert.equal = (...args) => { checks.assertions++; return nodeAssert.equal(...args); };
assert.deepEqual = (...args) => { checks.assertions++; return nodeAssert.deepEqual(...args); };
const mobileLayout = {};
const mobileControls = [];

const root = resolve(import.meta.dirname, '..');
const native = process.env.FLOWSCOPE_ADMIN_DIR || 'D:/Xboard-Independent/source/Xboard/public/assets/admin';
const browserPath = process.env.FLOWSCOPE_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
assert(existsSync(join(native, 'assets/index-CEIYH7i8.js')), 'Set FLOWSCOPE_ADMIN_DIR to the audited Admin directory.');
assert(existsSync(browserPath), 'Set FLOWSCOPE_BROWSER to a local Chromium binary.');
const artifacts = join(root, 'tests/.runtime/ui');
mkdirSync(artifacts, {recursive: true});
const profile = mkdtempSync(join(artifacts, 'browser-profile-'));
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const version = JSON.parse(readFileSync(join(root, 'FlowScope/config.json'), 'utf8')).version;
const config = {version, enabled: true, apiBase: '/api/v2/flowscope', statusUrl: '/api/v2/flowscope/status', timezone: 'Asia/Shanghai', owner: 'flowscope', compatibility: {supported: true, build: 'index-CEIYH7i8.js'}};
let statusEnabled = true, statusCode = 200, dashboardMode = 'normal', dashboardDelay = 0, installLifetime = 600;
let stored = {enabled: true, display_name: 'Saved physical server', quota_bytes: '1099511627777', traffic_mode: 'outbound', cycle_start_day: 31};
let collector = {id: 'isolated-collector', status: 'active', last_report_at: 1791345600, first_sample_at: 1791342000, interfaces: ['eth0']};
const apiRequests = [], errors = [], rejectedAssetPaths = [];
const assetFiles = {'script': 'flowscope.js', 'style': 'flowscope.css', 'flowscope.js': 'flowscope.js', 'flowscope.css': 'flowscope.css'};
const serverFixture = {id: 7, type: 'vless', name: 'Duplicate native name', rate: '1', show: 1, host: 'node.example.test', port: '443', server_port: '443', parent_id: 0, group_ids: [], route_ids: [], tags: [], excludes: [], ips: [], custom_outbounds: [], custom_routes: [], protocol_settings: {}, transfer_enable: 0, online: 1, online_user: 0, sort: 1, machine_id: 41};
const machineFixture = {id: 41, name: 'Duplicate physical server', notes: 'First machine', is_active: true, servers_count: 1, last_seen_at: null, load_status: null};
const sampleCycle = {cycle_start: '2026-10-01T00:00:00+08:00', cycle_end: '2026-10-31T23:59:59+08:00', first_sample_at: 1791342000, last_report_at: 1791345600, collector_status: 'active', traffic_source: 'nic'};
const traffic = [
  {...sampleCycle, machine_id: 41, name: 'Long physical server name '.repeat(24) + '<script>bad()</script>', used_bytes: '1400781713654', inbound_bytes: '1000', outbound_bytes: '1400781713654', quota_bytes: '1099511627776', percentage: 127.4, traffic_mode: 'outbound', cycle_start: '2026-09-30T00:00:00+08:00', cycle_end: '2026-10-30T23:59:59+08:00'},
  {...sampleCycle, machine_id: 42, name: 'Zero measured usage', used_bytes: '0', inbound_bytes: '0', outbound_bytes: '0', quota_bytes: '1073741824', percentage: 0, traffic_mode: 'both'},
  {...sampleCycle, machine_id: 43, name: 'Just over quota', used_bytes: '1073741825', inbound_bytes: '1073741825', outbound_bytes: '0', quota_bytes: '1073741824', percentage: 100, over_quota: true, traffic_mode: 'inbound'},
  {...sampleCycle, machine_id: 44, name: 'No collector yet', collector_status: 'waiting', first_sample_at: null, last_report_at: null, used_bytes: '0', inbound_bytes: '0', outbound_bytes: '0', quota_bytes: '1073741824', percentage: 0, traffic_mode: 'outbound'},
  {...sampleCycle, machine_id: 45, name: 'Collector overdue', collector_status: 'stale', last_report_at: '2026-10-06T12:00:00+08:00', used_bytes: '268435456', inbound_bytes: '1', outbound_bytes: '268435456', quota_bytes: '1073741824', percentage: 25, traffic_mode: 'outbound'}
];
function json(response, body, code = 200) { response.writeHead(code, {'Content-Type': 'application/json'}); response.end(JSON.stringify(body)); }
const originalHtml = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<script>window.settings={secure_path:'/test-admin',base_url:'/'};localStorage.setItem('XBOARD_ACCESS_TOKEN',JSON.stringify({value:'test-raw-token',time:Date.now(),expire:null}));localStorage.setItem('i18nextLng',new URLSearchParams(location.search).get('lang')||'en-US');</script>
<script src="/locales/en-US.js"></script><script src="/locales/zh-CN.js"></script><script src="/locales/ru-RU.js"></script>
<link rel="stylesheet" href="/assets/index-DiYa-_z_.css"><style id="foreign-style">/* smart-expiry:keep */[data-foreign]{outline-color:purple}</style>
<script type="module" src="/assets/index-CEIYH7i8.js"></script></head><body><div id="root"></div><div data-foreign="qr-code-extend"></div></body></html>`;
// Use the actual PHP HTML injector, not a handwritten approximation of its loader.
// These three narrow application dependencies are fixture values; the compatibility
// service and complete HTML transformation are the real plugin source, read-only.
const phpLiteral = value => "'" + value.replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'";
const phpFixture = `<?php
namespace Plugin\\Flowscope\\Middleware { final class EnsureEnabled { public static function isEnabled(): bool { return true; } } }
namespace {
  require ${phpLiteral(resolve(native, '../../../app/Services/Plugin/AbstractPlugin.php'))};
  require ${phpLiteral(join(root, 'FlowScope/Plugin.php'))};
  require ${phpLiteral(join(root, 'FlowScope/Services/AdminCompatibility.php'))};
  require ${phpLiteral(join(root, 'FlowScope/Services/AdminIntegration.php'))};
  function app($class) { return new \\Plugin\\Flowscope\\Services\\AdminCompatibility(${phpLiteral(resolve(native, '../../..'))}); }
  function config($key, $default=null) { return 'Asia/Shanghai'; }
  echo \\Plugin\\Flowscope\\Services\\AdminIntegration::inject(base64_decode('${Buffer.from(originalHtml).toString('base64')}'));
}`;
const injected = spawnSync(process.env.FLOWSCOPE_PHP || 'php', [], {input: phpFixture, encoding: 'utf8', windowsHide: true});
assert.equal(injected.status, 0, 'Actual PHP injector executes; set FLOWSCOPE_PHP or add PHP to PATH. ' + (injected.stderr || ''));
const html = injected.stdout;
assert.equal((html.match(/data-flowscope-loader/g) || []).length, 1, 'Actual PHP output contains one owned loader');
assert(html.includes(`src="/api/v2/flowscope/assets/script?v=${version}"`) && html.includes(`href="/api/v2/flowscope/assets/style?v=${version}"`), 'Actual PHP injector emits extensionless versioned JS/CSS URLs');
const http = createServer(async (request, response) => {
  const url = new URL(request.url, 'http://localhost');
  if (url.pathname.startsWith('/api/v2/flowscope/assets/')) {
    const key = url.pathname.slice('/api/v2/flowscope/assets/'.length), file = assetFiles[key];
    if (!file) { response.writeHead(404); response.end('Not Found'); return; }
    response.writeHead(200, {'Content-Type': file.endsWith('.css') ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8', 'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'no-store'});
    response.end(readFileSync(join(root, 'FlowScope/resources/assets', file))); return;
  }
  if (url.pathname.startsWith('/api/')) {
    let raw = ''; for await (const chunk of request) raw += chunk;
    apiRequests.push({path: url.pathname, method: request.method, authorization: request.headers.authorization, body: raw ? JSON.parse(raw) : null});
    if (url.pathname.endsWith('/flowscope/status')) return json(response, {...config, enabled: statusEnabled}, statusCode);
    if (url.pathname.endsWith('/flowscope/machine-addresses')) return json(response, {data: {machines: [{machine_id: 41, ipv4: '8.8.4.4', updated_at: Math.floor(Date.now()/1000)-43200, source: 'node', stale: false}, {machine_id: 42, ipv4: '2001:db8::1', updated_at: Math.floor(Date.now()/1000)}], nodes: [{node_id: 9, ipv4: '9.9.9.9', updated_at: Math.floor(Date.now()/1000), source: 'node', stale: false}]}});
    if (url.pathname.endsWith('/flowscope/dashboard')) {
      const result = dashboardMode === 'empty' ? [] : traffic.map(s => ({...s, ...(dashboardMode === 'waiting-history' && s.machine_id === 44 ? {used_bytes: '268435456', outbound_bytes: '268435456', percentage: 25} : {}), name: request.headers.authorization === 'new-admin-token' ? 'New account ' + s.name : s.name}));
      if (dashboardDelay) await sleep(dashboardDelay);
      if (dashboardMode === 'error') return json(response, {message: 'Temporary test failure'}, 500);
      return json(response, {data: {servers: result, timezone: 'Asia/Shanghai'}});
    }
    if (/\/flowscope\/machine\/(41|42)\/install-ticket$/.test(url.pathname)) return json(response, {data: {machine_id: Number(url.pathname.split('/').at(-2)), command: 'sudo bash -c "test-fixture-only-no-real-credential"', expires_at: Math.floor(Date.now()/1000)+installLifetime}});
    if (/\/flowscope\/machine\/(41|42)\/collector-reset$/.test(url.pathname)) { collector = null; return json(response, {data: {reset: true}}); }
    if (/\/flowscope\/machine\/(41|42)$/.test(url.pathname)) {
      if (request.method === 'POST') {
        const body = JSON.parse(raw); stored = {...stored, ...body};
        if (body.quota_value) stored.quota_bytes = '1649267441664';
      }
      return json(response, {data: {server: {machine_id: Number(url.pathname.split('/').at(-1)), name: machineFixture.name, is_active: true}, settings: stored, collector}});
    }
    if (url.pathname.endsWith('/flowscope/machines')) return json(response, {data: {machines: [machineFixture, {...machineFixture, id: 42}].map(m => ({machine_id: m.id, name: m.name, settings: stored}))}});
    if (url.pathname.endsWith('/stat/getStats')) return json(response, {data: {todayIncome: 0, currentMonthIncome: 0, dayIncomeGrowth: 0, monthIncomeGrowth: 0, ticketPendingTotal: 0, commissionPendingTotal: 0, currentMonthNewUsers: 0, userGrowth: 0, totalUsers: 1, activeUsers: 0, monthTraffic: {upload: 0, download: 0}, todayTraffic: {upload: 0, download: 0}}});
    if (url.pathname.endsWith('/stat/getOrder')) return json(response, {data: {summary: {start_date: '2026-10-01', end_date: '2026-10-31', paid_total: 0, commission_total: 0, paid_count: 0, commission_count: 0, commission_rate: 0, avg_paid_amount: 0}, list: []}});
    if (url.pathname.endsWith('/server/manage/getNodes')) return json(response, {data: [serverFixture, {...serverFixture, id: 9, type: 'trojan', machine_id: null}]});
    if (url.pathname.endsWith('/server/machine/fetch')) return json(response, {data: [machineFixture, {...machineFixture, id: 42, notes: 'Second same-name machine'}]});
    if (url.pathname.endsWith('/system/getQueueStats') || url.pathname.endsWith('/system/getSystemStatus')) return json(response, {data: null});
    return json(response, {data: [], total: 0});
  }
  let file;
  if (/^\/(?:assets|locales)\/[A-Za-z0-9_.-]+$/.test(url.pathname)) file = join(native, url.pathname.slice(1));
  if (file && existsSync(file)) {
    response.writeHead(200, {'Content-Type': file.endsWith('.css') ? 'text/css' : 'application/javascript'}); response.end(readFileSync(file)); return;
  }
  response.writeHead(200, {'Content-Type': 'text/html'}); response.end(html);
});
await new Promise(resolve => http.listen(0, '127.0.0.1', resolve));
const backendOrigin = `http://127.0.0.1:${http.address().port}`;
// Reproduce a web server's static-suffix location which rejects dynamic .js/.css
// API URLs before Laravel receives them. Native static files still pass through.
const proxy = createServer((request, response) => {
  const url = new URL(request.url, backendOrigin);
  if (/^\/api\/v2\/flowscope\/assets\/[^/]+\.(?:js|css)$/.test(url.pathname)) {
    rejectedAssetPaths.push(url.pathname);
    response.writeHead(404, {'Content-Type': 'text/plain'}); response.end('Static file not found'); return;
  }
  const upstream = proxyRequest(url, {method: request.method, headers: request.headers}, result => {
    response.writeHead(result.statusCode, result.headers); result.pipe(response);
  });
  upstream.on('error', error => { response.writeHead(502); response.end(error.message); });
  request.pipe(upstream);
});
await new Promise(resolve => proxy.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${proxy.address().port}`;
const browser = spawn(browserPath, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], {stdio: 'ignore', windowsHide: true});
let ws, cdp, acceptConfirmation = false;
const confirmations = [];
try {
  for (const legacy of ['flowscope.js', 'flowscope.css']) {
    const direct = await fetch(backendOrigin + '/api/v2/flowscope/assets/' + legacy);
    assert.equal(direct.status, 200, 'Legacy alias remains available directly from the backend: ' + legacy);
    const blocked = await fetch(origin + '/api/v2/flowscope/assets/' + legacy);
    assert.equal(blocked.status, 404, 'Static-suffix proxy reproduces the production 404: ' + legacy);
  }
  for (const [asset, mime] of [['script', 'application/javascript'], ['style', 'text/css']]) {
    const response = await fetch(origin + '/api/v2/flowscope/assets/' + asset + '?v=' + version);
    assert.equal(response.status, 200, 'Extensionless asset survives the static-suffix proxy: ' + asset);
    assert(response.headers.get('content-type').startsWith(mime), 'Extensionless asset retains the browser MIME type: ' + asset);
  }
  let port;
  for (let i = 0; i < 200; i++) { try { port = readFileSync(join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0]; break; } catch { await sleep(50); } }
  assert(port, 'Browser debugging port was created');
  const tabs = await fetch(`http://127.0.0.1:${port}/json/list`).then(r => r.json());
  ws = new WebSocket(tabs.find(t => t.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, {once: true}); ws.addEventListener('error', reject, {once: true}); });
  let id = 0; const pending = new Map();
  ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id) { const callback = pending.get(message.id); pending.delete(message.id); message.error ? callback.reject(message.error) : callback.resolve(message.result); }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails);
    if (message.method === 'Page.javascriptDialogOpening') {
      confirmations.push(message.params.message);
      cdp('Page.handleJavaScriptDialog', {accept: acceptConfirmation});
    }
  });
  cdp = (method, params = {}) => new Promise((resolve, reject) => { const next = ++id; pending.set(next, {resolve, reject}); ws.send(JSON.stringify({id: next, method, params})); });
  const evaluate = async expression => {
    const result = await cdp('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
  };
  const wait = async (expression, message, timeout = 15000) => {
    const until = Date.now() + timeout;
    while (Date.now() < until) { if (await evaluate(expression)) { checks.states++; return; } await sleep(50); }
    throw new Error(message + ': ' + (await evaluate('document.body.innerText')).slice(0, 1600));
  };
  const screenshot = async (name, fullPage = true) => { const result = await cdp('Page.captureScreenshot', {format: 'png', captureBeyondViewport: fullPage}); writeFileSync(join(artifacts, name + '.png'), Buffer.from(result.data, 'base64')); };
  const layoutMetrics = async () => evaluate(`(()=>{const rect=n=>{if(!n)return null;const r=n.getBoundingClientRect();return {left:r.left,right:r.right,width:r.width}};const main=document.querySelector('main#content');return {innerWidth,documentWidth:document.documentElement.scrollWidth,main:rect(main),nativeMarginLeft:main?getComputedStyle(main).marginLeft:null,nativeTransition:main?getComputedStyle(main).transitionProperty:null,nativeTransitionDuration:main?getComputedStyle(main).transitionDuration:null,card:rect(document.querySelector('.flowscope-card'))}})()`);
  const click = async selector => {
    const point = await evaluate(`(()=>{const n=document.querySelector(${JSON.stringify(selector)});n.scrollIntoView({block:'center'});const r=n.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}})()`);
    await cdp('Input.dispatchMouseEvent', {type: 'mousePressed', ...point, button: 'left', clickCount: 1});
    await cdp('Input.dispatchMouseEvent', {type: 'mouseReleased', ...point, button: 'left', clickCount: 1});
  };
  await cdp('Runtime.enable'); await cdp('Page.enable');
  await cdp('Emulation.setDeviceMetricsOverride', {width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false});
  dashboardDelay = 300;
  await cdp('Page.navigate', {url: origin + '/'});
  await wait('document.querySelector("#flowscope-period-traffic")?.textContent.includes("Loading traffic")', 'Loading state renders');
  await wait('document.querySelectorAll(".flowscope-server").length===5', 'Actual native dashboard mounts');
  assert.equal(await evaluate('window.__FLOWSCOPE__.version + ":" + window.__FLOWSCOPE_RUNTIME__.version'), version + ':' + version, 'Real PHP inline loader and browser runtime agree on version');
  assert.equal(await evaluate('document.querySelector("[data-flowscope-owner]").getAttribute("data-flowscope-owner")'), 'flowscope-' + version, 'UI ownership marker uses the version');
  assert.equal(await evaluate('document.querySelector(".flowscope-over .flowscope-percentage").textContent'), '127.4%');
  assert.equal(await evaluate('document.querySelector(".flowscope-over .flowscope-progress-fill").style.width'), '100%');
  assert.equal(await evaluate('document.querySelectorAll(".flowscope-over-badge").length'), 2, 'Exact backend over-quota flag survives percentage rounding');
  assert.equal(await evaluate('document.querySelector(\'[data-machine-id="44"] .flowscope-percentage\').textContent'), '—', 'No collector does not falsely imply measured zero usage');
  assert.equal(await evaluate('document.querySelector(\'[data-machine-id="44"] [role="progressbar"]\').hasAttribute("aria-valuenow")'), false, 'Waiting progress is indeterminate');
  assert.equal(await evaluate('document.querySelector(\'[data-machine-id="45"]\').textContent.includes("Report overdue")'), true, 'Stale reporting clearly labels retained last-known usage');
  assert.equal(await evaluate('document.querySelector(\'[data-machine-id="42"] .flowscope-percentage\').textContent'), '0.0%', 'Measured zero is distinct from missing collection');
  assert.equal(await evaluate('document.querySelector(".flowscope-coverage").textContent.includes("prorated") && document.querySelector(".flowscope-sample-details").textContent.includes("NIC RX:")'), true, 'NIC directions and interval-prorated incomplete coverage are explicit');
  assert.equal(await evaluate('document.querySelector(\'[data-machine-id="41"]\').textContent.includes("Asia/Shanghai") && !document.querySelector(\'[data-machine-id="41"]\').textContent.includes("1791345600")'), true, 'Epoch sample timestamps render readable dates with timezone');
  await wait('document.querySelectorAll("[data-flowscope-hidden]").length===12', 'Five native dashboard cards and seven sidebar links hidden');
  assert.equal(await evaluate('!!document.querySelector(".flowscope-server-name script")'), false, 'Untrusted physical-server name is text');
  assert.equal(await evaluate('document.querySelector(".flowscope-cycle").textContent.includes("2026-09-30 – 2026-10-30")'), true);
  assert.equal(apiRequests.filter(r => r.path.includes('/flowscope/')).every(r => r.authorization === 'test-raw-token'), true, 'Uses native raw authorization');
  assert.equal(apiRequests.filter(r => r.path.endsWith('/flowscope/dashboard')).length, 1, 'Own DOM mutations do not create a repeated observer/fetch loop');
  dashboardMode = 'waiting-history'; dashboardDelay = 0;
  await evaluate('window.__FLOWSCOPE_RUNTIME__.refresh()');
  await wait('document.querySelector(\'[data-machine-id="44"] .flowscope-percentage\')?.textContent==="25.0%"', 'Waiting after collector reset retains known historical usage');
  assert.equal(await evaluate('document.querySelector(\'[data-machine-id="44"] .flowscope-usage\').textContent.includes("256.0 MB")'), true, 'Collector reset does not hide preserved traffic history');
  assert.equal(await evaluate('document.querySelector(\'[data-machine-id="44"] .flowscope-sample-note\').textContent.includes("Recorded history")'), true, 'Retained history is distinct from complete-cycle coverage');
  dashboardMode = 'normal';
  await evaluate('window.__FLOWSCOPE_RUNTIME__.refresh()');
  await wait('document.querySelector(\'[data-machine-id="44"] .flowscope-percentage\')?.textContent==="—"', 'Truly unmeasured waiting state remains distinct from zero');
  await evaluate(`(()=>{const n=document.createElement('a');n.id='foreign-main-route';n.href='#/finance/order';n.textContent='Order Management';document.body.append(n);})()`);
  await evaluate('window.__FLOWSCOPE_RUNTIME__.refresh()');
  assert.equal(await evaluate('document.querySelector("#foreign-main-route").hasAttribute("data-flowscope-hidden")'), false, 'Same route link outside sidebar is preserved');
  await screenshot('dashboard-light-desktop');
  assert.equal(await evaluate('getComputedStyle(document.querySelector(".flowscope-server-list")).gridTemplateColumns.split(" ").length'), 4, 'Desktop period traffic follows native four-column statistics layout');
  await evaluate('document.documentElement.classList.add("dark")');
  assert.equal(await evaluate('getComputedStyle(document.querySelector(".flowscope-card")).backgroundColor===getComputedStyle(document.querySelector("div.rounded-xl.border.bg-card")).backgroundColor'), true, 'Card uses native dark palette');
  await screenshot('dashboard-dark-desktop');
  await cdp('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: true});
  mobileLayout.resizing = await layoutMetrics();
  // Native main#content transitions its md:ml-64 margin to zero on resize.
  // Capture the settled mobile layout, not a middle frame of that native transition.
  await sleep(350);
  mobileLayout.settled = await layoutMetrics();
  assert.equal(mobileLayout.settled.documentWidth <= mobileLayout.settled.innerWidth, true, 'Whole native document fits mobile viewport');
  assert.equal(mobileLayout.settled.card.left >= 0 && mobileLayout.settled.card.right <= mobileLayout.settled.innerWidth, true, 'FlowScope outer bounds fit mobile viewport');
  await screenshot('dashboard-dark-mobile');
  assert.equal(await evaluate('document.querySelector(".flowscope-card").scrollWidth <= document.querySelector(".flowscope-card").clientWidth'), true, 'Long name does not overflow mobile card');
  // Navigation while a request is pending must not leave the replacement widget blank.
  dashboardDelay = 900;
  await evaluate('window.__FLOWSCOPE_RUNTIME__.refresh()');
  await evaluate(`document.querySelector('aside nav a[href="#/server/manage"]').click()`);
  await wait('!document.querySelector("#flowscope-period-traffic")', 'Leaving dashboard removes old widget');
  await evaluate(`document.querySelector('aside nav a[href="#/"]').click()`);
  await wait('document.querySelectorAll(".flowscope-server").length===5', 'Returning during a pending request immediately loads replacement widget', 5000);
  // Account changes abort old requests and clear old account DOM before rendering new data.
  await evaluate('window.__FLOWSCOPE_RUNTIME__.refresh()');
  await sleep(50);
  dashboardDelay = 0;
  await evaluate(`localStorage.setItem('XBOARD_ACCESS_TOKEN',JSON.stringify({value:'new-admin-token',time:Date.now(),expire:null}));window.__FLOWSCOPE_RUNTIME__.refresh()`);
  assert.equal(await evaluate('document.querySelectorAll("[data-flowscope-owner],[data-flowscope-hidden]").length'), 0, 'Token change clears old account integration immediately');
  await wait('document.querySelector(".flowscope-server-name")?.textContent.startsWith("New account ")', 'New token fetches fresh account data');
  await sleep(1000);
  assert.equal(await evaluate('document.querySelector(".flowscope-server-name").textContent.startsWith("New account ")'), true, 'Old in-flight response cannot overwrite new account data');
  dashboardMode = 'error'; dashboardDelay = 0;
  await evaluate('window.__FLOWSCOPE_RUNTIME__.refresh()');
  await wait('document.querySelector("#flowscope-period-traffic").textContent.includes("Unable to load traffic")', 'Error state renders');
  dashboardMode = 'empty';
  await evaluate('document.querySelector("#flowscope-period-traffic button").click()');
  await wait('document.querySelector("#flowscope-period-traffic").textContent.includes("No traffic monitoring servers")', 'Retry and empty state render');
  assert.equal(await evaluate('document.querySelector(".flowscope-link").getAttribute("href")'), '#/server/machine', 'Empty state reuses real physical-server hash navigation');
  await cdp('Emulation.setDeviceMetricsOverride', {width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false});
  await cdp('Page.navigate', {url: origin + '/#/server/manage'});
  await wait(`document.querySelectorAll('button[aria-label="Actions"]').length>0`, 'Native server table loads');
  await wait('document.querySelector(\'.flowscope-machine-ip[data-node-id="9"]\')?.textContent.includes("9.9.9.9")', 'Unassigned legacy node displays node-scoped address without guessed machine');
  await click('button[aria-label="Actions"]');
  await wait('[...document.querySelectorAll("[role=menuitem]")].some(n=>n.textContent.trim()==="Edit")', 'Native action menu opens');
  await evaluate('[...document.querySelectorAll("[role=menuitem]")].find(n=>n.textContent.trim()==="Edit").click()');
  await wait('!!document.querySelector(\'[role="dialog"][data-state="open"] input[name="server_port"]\')', 'Native proxy-node edit still works');
  assert.equal(await evaluate('document.querySelectorAll(".flowscope-editor").length'), 0, 'Proxy-node editor no longer mounts FlowScope, even when bound to a machine');
  await cdp('Page.navigate', {url: origin + '/#/server/machine'});
  await wait('document.querySelectorAll(\'button[aria-label="Edit Server"]\').length===2', 'Real physical-server table loads same-name machines');
  await wait('document.querySelector(\'.flowscope-machine-ip[data-machine-id="41"]\')?.textContent.includes("8.8.4.4")', 'Machine IPv4 appears under native heartbeat metadata');
  assert.equal(await evaluate('document.querySelector(\'.flowscope-machine-ip[data-machine-id="42"]\').textContent'), 'IPv4: Waiting', 'IPv6 is never displayed as machine IPv4');
  assert.equal(await evaluate('document.querySelector(\'.flowscope-machine-ip[data-machine-id="41"]\').textContent'), 'IPv4: 8.8.4.4', '12-hour Node observation follows Node freshness rather than collector TTL');
  assert((await evaluate('document.querySelector(\'.flowscope-machine-ip[data-machine-id="41"]\').title')).includes('Xboard-Node'), 'Source and last successful detection time available');
  await screenshot('machine-ipv4-desktop');
  await click('button[aria-label="Edit Server"]');
  await wait('document.querySelector("input[name=flowscope_display_name]")', 'Native physical form fiber provides exact machine identity');
  assert(apiRequests.some(r => r.path === '/api/v2/flowscope/machine/41') && !apiRequests.some(r => r.path === '/api/v2/flowscope/machine/42'), 'Same-name physical servers resolve by exact native id');
  assert.equal(await evaluate('document.querySelector(".flowscope-editor").closest("form")'), null, 'FlowScope region is a sibling, never inside the native form');
  await evaluate('document.querySelector("input[name=flowscope_enabled]").click()');
  await evaluate('document.querySelector(".flowscope-editor-actions button").click()');
  await wait('document.querySelector(".flowscope-save-status")?.textContent.includes("settings saved")', 'Periodic traffic can be disabled independently');
  assert.equal(apiRequests.findLast(r=>r.path==='/api/v2/flowscope/machine/41'&&r.method==='POST').body.enabled, false, 'Traffic-off setting persisted');
  assert((await evaluate('document.querySelector(\'.flowscope-machine-ip[data-machine-id="41"]\').textContent')).includes('8.8.4.4'), 'IPv4 stays visible after periodic traffic is disabled');
  await evaluate('document.querySelector("input[name=flowscope_enabled]").click()');
  await evaluate('document.querySelector(".flowscope-editor-actions button").click()');
  await wait('document.querySelector(".flowscope-save-status")?.textContent.includes("settings saved")', 'Traffic switch restored for existing regression checks');
  assert.equal(await evaluate('document.querySelector(".flowscope-machine-id").textContent'), 'machine_id: 41', 'Collector hint uses exact machine id');
  assert.equal(apiRequests.some(r => /server\/machine\/getToken/.test(r.path)), false, 'Collector hints never fetch or expose native server tokens');
  const beforeNative = apiRequests.filter(r => /server\/(?:machine|manage)\/(save|update)/.test(r.path)).length;
  await evaluate('document.querySelector("input[name=flowscope_install_interfaces]").value="ens33,eth1";document.querySelector(".flowscope-generate-install").click()');
  await wait('document.querySelector(".flowscope-install-command").value.includes("test-fixture-only")', 'One-command installer renders a read-only command');
  const installRequest = apiRequests.findLast(r => r.path.endsWith('/install-ticket'));
  assert.equal(installRequest.path, '/api/v2/flowscope/machine/41/install-ticket', 'Installation targets exact physical server');
  assert.deepEqual(installRequest.body.interfaces, ['ens33','eth1'], 'NIC override reaches install API');
  assert.equal(installRequest.body.panel_url, origin, 'Installation uses current panel origin');
  assert.equal(await evaluate('document.querySelector(".flowscope-install-command").readOnly'), true, 'Command display is read-only');
  assert.equal(apiRequests.filter(r => /server\/(?:machine|manage)\/(save|update)/.test(r.path)).length, beforeNative, 'Generating a command cannot submit native form');
  await evaluate('document.querySelector("input[name=flowscope_install_interfaces]").value="eth0,eth0";document.querySelector(".flowscope-generate-install").click()');
  assert.equal(apiRequests.filter(r => r.path.endsWith('/install-ticket')).length, 1, 'Duplicate NIC is rejected without an API call');
  installLifetime = 2;
  await evaluate('document.querySelector("input[name=flowscope_install_interfaces]").value="";document.querySelector(".flowscope-generate-install").click()');
  await wait('document.querySelector(".flowscope-install-command").value.includes("test-fixture-only")', 'Regenerated install command is displayed');
  await wait('document.querySelector(".flowscope-install-command").value==="" && document.querySelector(".flowscope-copy-install").hidden', 'Expired command is erased from the DOM');
  assert.equal(await evaluate('document.querySelector(".flowscope-copy-install").getBoundingClientRect().height'), 0, 'Expired copy button is actually invisible, not only hidden-marked');
  installLifetime = 600;
  await evaluate('document.querySelector(".flowscope-generate-install").click()');
  await wait('document.querySelector(".flowscope-install-command").value.includes("test-fixture-only")', 'Fresh command replaces expired credential');
  await evaluate('document.querySelector(".flowscope-copy-install").click()');
  await wait('/Copied|copy manually/.test(document.querySelector(".flowscope-copy-install").nextElementSibling.textContent)', 'Copy command succeeds or offers explicit manual selection');
  assert.equal(await evaluate('Object.values(localStorage).some(v=>v.includes("test-fixture-only"))'), false, 'Install command is never persisted in localStorage');
  await evaluate('document.querySelector(".flowscope-generate-install").scrollIntoView({block:"start"})');
  await screenshot('oneclick-install-desktop', false);
  await evaluate('document.querySelector("input[name=flowscope_display_name]").focus()');
  await cdp('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13});
  await cdp('Input.dispatchKeyEvent', {type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13});
  await sleep(100);
  assert.equal(apiRequests.filter(r => /server\/(?:machine|manage)\/(save|update)/.test(r.path)).length, beforeNative, 'Enter in independent controls cannot submit native form');
  await evaluate(`const u=document.querySelector('select[name=flowscope_quota_unit]');const original=u.value;u.value=original==='GB'?'TB':'GB';u.dispatchEvent(new Event('change',{bubbles:true}));u.value=original;u.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('.flowscope-editor-actions button').click()`);
  await wait('document.querySelector(".flowscope-save-status")?.textContent.includes("settings saved")', 'Quota unit round trip saves successfully');
  assert.equal(apiRequests.findLast(r => r.path === '/api/v2/flowscope/machine/41' && r.method === 'POST').body.quota_bytes, '1099511627777', 'Unit round trip preserves original exact bytes beyond display precision');
  await evaluate(`document.querySelector('input[name=flowscope_display_name]').value='Independent saved name';document.querySelector('input[name=flowscope_quota_value]').value='1.5';document.querySelector('input[name=flowscope_quota_value]').dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('select[name=flowscope_quota_unit]').value='TB';document.querySelector('select[name=flowscope_quota_unit]').dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('.flowscope-editor-actions button').click();`);
  await wait('document.querySelector(".flowscope-save-status")?.textContent.includes("settings saved")', 'Independent FlowScope settings save');
  const saveRequest = apiRequests.findLast(r => r.path === '/api/v2/flowscope/machine/41' && r.method === 'POST');
  assert.deepEqual(saveRequest.body, {enabled: true, display_name: 'Independent saved name', traffic_mode: 'outbound', cycle_start_day: 31, quota_value: '1.5', quota_unit: 'TB'});
  assert.equal(apiRequests.filter(r => /server\/(?:machine|manage)\/(save|update)/.test(r.path)).length, beforeNative, 'FlowScope action does not submit native editor');
  await evaluate('document.querySelector(\'[role="dialog"][data-state="open"] form button[type="submit"]\').scrollIntoView({block:"center"})');
  assert.equal(await evaluate('(()=>{const r=document.querySelector(\'[role="dialog"][data-state="open"] form button[type="submit"]\').getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight})()'), true, 'Native physical-server Update remains reachable');
  await evaluate('document.querySelector(".flowscope-editor").scrollIntoView({block:"center"})');
  assert.equal(await evaluate('(()=>{const r=document.querySelector(".flowscope-editor button").getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight})()'), true, 'Desktop FlowScope save action is visible inside native dialog');
  await screenshot('native-editor-desktop', false);
  // Native ResponsiveDialog switches Radix Dialog to Vaul Drawer at this breakpoint.
  // Close/reopen through its real trigger, without changing native React state.
  await evaluate('[...document.querySelectorAll(\'[role="dialog"][data-state="open"] form button\')].find(n=>n.textContent.trim()==="Cancel").click()');
  await wait('!document.querySelector(".flowscope-editor")', 'Desktop editor closes before native responsive surface change');
  assert.equal(await evaluate('document.querySelectorAll(".flowscope-install-command").length'), 0, 'Closing the machine editor removes command data');
  await cdp('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: true});
  await sleep(650);
  await click('button[aria-label="Edit Server"]');
  await wait('document.querySelector("input[name=flowscope_display_name]") && document.querySelector(\'[role="dialog"][data-state="open"]\')?.classList.contains("bottom-0")', 'Mobile physical-server drawer remounts exact adapter');
  await sleep(650);
  assert.equal(await evaluate('getComputedStyle(document.querySelector(\'[role="dialog"][data-state="open"]\'),"::after").content'), 'none', 'Adapted Vaul drag filler cannot obscure scrolled editor content');
  await cdp('Emulation.setTouchEmulationEnabled', {enabled: true, maxTouchPoints: 1});
  await evaluate('document.querySelector(\'[role="dialog"][data-state="open"]\').scrollTop=0');
  await cdp('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{x: 20, y: 700}]});
  for (const y of [650, 600, 550, 500, 450, 400]) {
    await cdp('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [{x: 20, y}]});
    await sleep(25);
  }
  await cdp('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
  await sleep(200);
  mobileLayout.drawerTouchScroll = await evaluate('({scrollTop:document.querySelector(\'[role="dialog"][data-state="open"]\')?.scrollTop,touchAction:getComputedStyle(document.querySelector(\'[role="dialog"][data-state="open"]\')).touchAction})');
  assert.equal(mobileLayout.drawerTouchScroll.touchAction, 'pan-y', 'Adapted drawer permits native touch scrolling');
  assert(mobileLayout.drawerTouchScroll.scrollTop > 20, 'Real emulated touch swipe scrolls physical-server editor');
  await evaluate('document.querySelector(".flowscope-generate-install").click()');
  await wait('document.querySelector(".flowscope-install-command").value.includes("test-fixture-only")', 'Mobile installer generates a command');
  for (const selector of ['form input[name="name"]', 'form textarea[name="notes"]', 'form button[type="submit"]', 'input[name="flowscope_display_name"]', 'input[name="flowscope_quota_value"]', 'select[name="flowscope_traffic_mode"]', 'input[name="flowscope_cycle_start_day"]', '.flowscope-editor-actions button', '.flowscope-machine-id', 'input[name="flowscope_install_interfaces"]', '.flowscope-generate-install', '.flowscope-install-command', '.flowscope-copy-install']) {
    await evaluate(`document.querySelector('[role="dialog"][data-state="open"]').querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center'})`);
    const metric = await evaluate(`(()=>{const d=document.querySelector('[role="dialog"][data-state="open"]'),n=d.querySelector(${JSON.stringify(selector)}),r=n.getBoundingClientRect(),b=d.getBoundingClientRect(),h=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);return {top:r.top,bottom:r.bottom,dialogTop:b.top,dialogBottom:b.bottom,visible:r.top>=Math.max(0,b.top)-1&&r.bottom<=Math.min(innerHeight,b.bottom)+1,hit:h===n||n.contains(h),hitTag:h?.tagName}})()`);
    mobileControls.push({selector, ...metric});
    assert.equal(metric.visible && metric.hit, true, 'Mobile native/plugin field is scroll-reachable and unobscured: ' + selector);
  }
  await evaluate('document.querySelector(".flowscope-generate-install").scrollIntoView({block:"start"})');
  await screenshot('oneclick-install-mobile', false);
  await evaluate('document.querySelector(".flowscope-editor-actions button").scrollIntoView({block:"center"})');
  await sleep(150);
  assert.equal(await evaluate('(()=>{const r=document.querySelector(".flowscope-editor button").getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight})()'), true, 'Mobile FlowScope save action is visible inside native dialog');
  await screenshot('native-editor-mobile', false);
  assert.equal(await evaluate('document.querySelector(".flowscope-editor").scrollWidth <= document.querySelector(".flowscope-editor").clientWidth'), true, 'Editor fits mobile');
  // Duplicate script execution must retain one adapter, observer and editor section.
  await evaluate(`fetch('/api/v2/flowscope/assets/script?v=${version}').then(r=>r.text()).then(code=>(0,eval)(code))`);
  assert.equal(await evaluate('document.querySelectorAll(".flowscope-editor").length'), 1);
  // Ambiguous form providers are rejected without guessing a server from its name.
  await evaluate(`(()=>{const p=document.querySelector('[role="dialog"][data-state="open"] form input[name=name]');let f=p[Object.keys(p).find(k=>k.startsWith('__reactFiber$'))];while(f&&!f.memoizedProps?.getValues)f=f.return;window.__testOriginalProps=f.return.memoizedProps;window.__testFiber=f.return;f.return.memoizedProps={...f.return.memoizedProps,getValues:()=>({id:42,name:'Duplicate physical server',notes:'',is_active:true}),control:{}};})()`);
  await evaluate('window.__FLOWSCOPE_RUNTIME__.refresh()');
  await wait('document.querySelector(".flowscope-editor").textContent.includes("ambiguous")', 'Ambiguous identity fails closed');
  assert.equal(await evaluate('!!document.querySelector("input[name=flowscope_display_name]")'), false);
  await evaluate('window.__testFiber.memoizedProps=window.__testOriginalProps;window.__FLOWSCOPE_RUNTIME__.refresh()');
  await wait('document.querySelector("input[name=flowscope_display_name]")', 'Restored exact form identity works');
  const resetsBefore = apiRequests.filter(r => r.path.endsWith('/collector-reset')).length;
  acceptConfirmation = false;
  await evaluate('document.querySelector(".flowscope-reset-collector").click()');
  assert.equal(apiRequests.filter(r => r.path.endsWith('/collector-reset')).length, resetsBefore, 'Declining real browser confirmation does not reset binding');
  acceptConfirmation = true;
  await evaluate('document.querySelector(".flowscope-reset-collector").click()');
  await wait('document.querySelector(".flowscope-save-status")?.textContent.includes("history has been preserved")', 'Confirmed collector reset reloads machine settings');
  assert.equal(apiRequests.filter(r => r.path.endsWith('/machine/41/collector-reset')).length, resetsBefore + 1, 'Reset targets exact physical server');
  assert(confirmations.length === 2 && confirmations.every(m => m.includes('history will not be deleted')), 'Both real confirmations clearly preserve history');
  await evaluate('[...document.querySelectorAll(\'[role="dialog"][data-state="open"] form button\')].find(n=>n.textContent.trim()==="Cancel").click()');
  await wait('!document.querySelector(".flowscope-editor")', 'Closing machine editor removes its owned section');
  await sleep(650);
  await evaluate('document.querySelectorAll(\'button[aria-label="Edit Server"]\')[1].click()');
  await wait('document.querySelector(".flowscope-machine-id")?.textContent==="machine_id: 42"', 'Second same-name machine resolves its own identity');
  assert(apiRequests.some(r => r.path === '/api/v2/flowscope/machine/42'), 'Second row does not reuse first same-name machine id');
  await evaluate('[...document.querySelectorAll(\'[role="dialog"][data-state="open"] form button\')].find(n=>n.textContent.trim()==="Cancel").click()');
  await wait('!document.querySelector(".flowscope-editor")', 'Second editor closes cleanly');
  await sleep(650);
  const configuredMachineRequests = apiRequests.filter(r => /\/flowscope\/machine\//.test(r.path)).length;
  await evaluate('[...document.querySelectorAll("button")].find(n=>n.textContent.trim()==="Add Server").click()');
  await wait('document.querySelector(".flowscope-editor")?.textContent.includes("Save the new server")', 'Unsaved physical server prompts native save first');
  assert.equal(await evaluate('document.querySelectorAll("input[name=flowscope_display_name]").length'), 0, 'Unsaved machines cannot configure another server');
  assert.equal(apiRequests.filter(r => /\/flowscope\/machine\//.test(r.path)).length, configuredMachineRequests, 'Unsaved machine makes no identity-guessing API request');
  await evaluate('document.querySelector("[data-foreign]").setAttribute("data-flowscope-machine-dialog","foreign-owner")');
  statusEnabled = false;
  await evaluate('window.__FLOWSCOPE_RUNTIME__.refresh()');
  await wait('!window.__FLOWSCOPE_RUNTIME__', 'Disabled plugin destroys adapter');
  mobileLayout.disabledNative = await layoutMetrics();
  assert.equal(await evaluate(`document.querySelectorAll('[data-flowscope-owner],[data-flowscope-hidden],[data-flowscope-asset],[data-flowscope-machine-dialog="flowscope-${version}"]').length`), 0);
  assert.equal(await evaluate('document.querySelector("[data-foreign]").getAttribute("data-flowscope-machine-dialog")'), 'foreign-owner', 'Foreign marker value is not removed by dialog cleanup');
  assert.equal(await evaluate('document.querySelector("#foreign-style").textContent.includes("smart-expiry:keep") && !!document.querySelector("[data-foreign]")'), true, 'Foreign style and markers preserved');
  statusEnabled = true; dashboardMode = 'normal';
  await cdp('Page.navigate', {url: origin + '/'});
  await wait('document.querySelectorAll(".flowscope-server").length===5', 'Re-enable via refresh restores UI');
  assert.equal(await evaluate('document.querySelectorAll("#flowscope-period-traffic").length'), 1);
  statusCode = 401;
  await evaluate('window.__FLOWSCOPE_RUNTIME__.refresh()');
  await wait('!window.__FLOWSCOPE_RUNTIME__', '401 clears own integration');
  assert.equal(await evaluate('document.querySelectorAll("[data-flowscope-hidden]").length'), 0, '401 restores native cards');
  statusCode = 200;
  await cdp('Page.navigate', {url: origin + '/'});
  await wait('document.querySelectorAll(".flowscope-server").length===5', 'Refresh restores supported adapter');
  for (const lang of ['zh-CN', 'ru-RU']) {
    await cdp('Page.navigate', {url: origin + '/?lang=' + lang});
    await wait('document.querySelectorAll(".flowscope-server").length===5 && document.querySelectorAll("[data-flowscope-hidden]").length===12', lang + ' exact locale hides only native target components');
  }
  await evaluate(`window.__FLOWSCOPE_RUNTIME__.destroy();window.__FLOWSCOPE__.compatibility.supported=false;fetch('/api/v2/flowscope/assets/script?v=${version}').then(r=>r.text()).then(code=>(0,eval)(code))`);
  assert.equal(await evaluate('!!window.__FLOWSCOPE_RUNTIME__ || !!document.querySelector("[data-flowscope-owner]")'), false, 'Unsupported builds fail closed');
  console.log('PASS actual native Admin through static-suffix proxy and actual PHP loader: physical machine identity and same-name edits; node editor excluded; unsaved machine prompt; independent exact save and Enter isolation; NIC RX/TX, waiting/stale/coverage; confirmed collector reset preserving history; native/plugin mobile scroll reachability; loading/error/retry/empty; over-quota; light/dark; en/zh/ru locale; duplicate load; disable/401 restore; foreign ownership; reload re-enable; unsupported build.');
  writeFileSync(join(artifacts, 'browser-results.json'), JSON.stringify({result: 'PASS', version, checks: {...checks, total: checks.assertions + checks.states}, loaderPipeline: 'Actual AdminIntegration::inject PHP output with fixture application dependencies', proxy: {staticSuffixRule: true, rejectedAssetPaths}, mobileLayout, mobileControls, confirmations, nativeBuild: 'index-CEIYH7i8.js', requests: apiRequests, runtimeErrors: errors}, null, 2));
  if (errors.length) throw new Error('Native browser runtime errors: ' + JSON.stringify(errors));
} catch (error) {
  if (cdp) {
    try { const shot = await cdp('Page.captureScreenshot', {format: 'png'}); writeFileSync(join(artifacts, 'failure.png'), Buffer.from(shot.data, 'base64')); } catch {}
  }
  writeFileSync(join(artifacts, 'browser-results.json'), JSON.stringify({result: 'FAIL', version, checks: {...checks, total: checks.assertions + checks.states}, proxy: {staticSuffixRule: true, rejectedAssetPaths}, mobileLayout, mobileControls, message: error.message, requests: apiRequests, runtimeErrors: errors}, null, 2));
  throw error;
} finally {
  ws?.close(); browser.kill(); await new Promise(resolve => proxy.close(resolve)); await new Promise(resolve => http.close(resolve));
}
