/* FlowScope 2.4.0. Read-only adapter for the audited Xboard Admin build.
 * No native React state, route, request, or shared asset is changed. */
(() => {
  'use strict';
  const config = window.__FLOWSCOPE__;
  if (window.__FLOWSCOPE_RUNTIME__) return;
  if (!config || config.enabled !== true || config.compatibility?.supported !== true ||
      config.compatibility.build !== 'index-CEIYH7i8.js') return;

  const OWNER = 'flowscope-' + config.version;
  const OWN = '[data-flowscope-owner="' + OWNER + '"]';
  const HIDDEN = 'data-flowscope-hidden';
  const apiBase = String(config.apiBase || '/api/v2/flowscope').replace(/\/$/, '');
  const hidden = new Set();
  const controllers = new Set();
  const listeners = [];
  const editors = new Map();
  const adaptedDialogs = new Set();
  let stopped = false, authorized = false, scheduled = false, statusBusy = false;
  let dashboard = null, dashboardBusy = false, dashboardUpdated = 0, dashboardAttempted = 0, lastToken = '';
  let dashboardRequest = 0;
  let generation = 0;
  let addresses = new Map(), nodeAddresses = new Map(), addressAttempted = 0, addressBusy = false;

  const navPaths = {
    '/config/notice': 'noticeManagement', '/config/payment': 'paymentConfig',
    '/config/knowledge': 'knowledgeManagement', '/finance/order': 'orderManagement',
    '/finance/coupon': 'couponManagement', '/finance/gift-card': 'giftCardManagement',
    '/user/ticket': 'ticketManagement'
  };
  const fallback = {
    'zh-CN': {title: '仪表盘', stats: ['今日收入', '月收入', '待处理工单', '待处理佣金'], overview: '收入概览',
      nav: ['公告管理', '支付配置', '知识库管理', '订单管理', '优惠券管理', '礼品卡管理', '工单管理']},
    'en-US': {title: 'Dashboard', stats: ["Today's Income", 'Monthly Income', 'Pending Tickets', 'Pending Commission'], overview: 'Revenue Overview',
      nav: ['Notice Management', 'Payment Configuration', 'Knowledge Management', 'Order Management', 'Coupon Management', 'Gift Card Management', 'Ticket Management']},
    'ru-RU': {title: 'Панель управления', stats: ['Доход за сегодня', 'Доход за месяц', 'Открытых тикетов', 'Комиссии на выплату'], overview: 'Обзор доходов',
      nav: ['Управление уведомлениями', 'Настройки оплаты', 'База знаний', 'Управление заказами', 'Купоны', 'Подарочные карты', 'Тикеты']}
  };
  const words = {
    'zh-CN': {period: '周期流量', subtitle: '物理服务器网卡流量 · 当前月度周期 · 按使用占比排序', loading: '正在加载流量…', error: '流量加载失败，请重试。', retry: '重试', empty: '尚未配置启用流量监控的服务器。', configure: '前往服务器管理', outbound: '出向（NIC TX）', inbound: '入向（NIC RX）', both: '双向（RX + TX）', over: '超额', cycle: '当前周期', timezone: '时区', settings: 'FlowScope / 周期流量', enable: '启用周期流量监控', name: '显示名称', nameHint: '留空使用物理服务器名称', quota: '流量额度', quotaHint: '1 GB = 1024³ 字节；1 TB = 1024⁴ 字节。', mode: '网卡统计方向', day: '周期起始日', dayHint: '每月 1–31 日；当月无此日期时使用月末。', save: '保存 FlowScope', saving: '正在保存…', saved: 'FlowScope 配置已保存。', editLoading: '正在加载 FlowScope 配置…', editError: '无法加载配置，请重试。', unavailable: '物理服务器身份缺失或不明确，FlowScope 配置不可用。', newMachine: '请先保存新服务器，再配置周期流量。', validation: '请输入有效额度和 1–31 的起始日。', independent: 'FlowScope 配置独立保存。', failed: '保存失败，请重试。', waiting: '等待采集', active: '正在采集', stale: '上报已过期', waitingHistory: '???????????????????????????????', waitingHint: '尚未收到可计量采样；首次上报建立基线，不代表本周期用量为零。', staleHint: '采集上报已过期；下方为最后已知用量，可能不完整。', coverage: '仅统计已采集的网卡增量，不回填安装前或采集中断期间的流量。', lastReport: '最后上报', firstSample: '首次采样', collector: '网卡采集器', installHint: '每台物理服务器需额外安装 FlowScope Collector；使用下方 machine_id，并从原生服务器管理获取服务器 Token。请勿把 Token 粘贴到此界面。', interfaces: '采集网卡', resetCollector: '重置采集器绑定', resetConfirm: '重置此物理服务器的采集器绑定？旧采集器将失效，需重新绑定。已采集历史不会删除。', resetDone: '采集器绑定已重置；历史流量已保留。'},
    'en-US': {period: 'Period Traffic', subtitle: 'Physical server NIC traffic · Current monthly cycle · Highest usage first', loading: 'Loading traffic…', error: 'Unable to load traffic. Please retry.', retry: 'Retry', empty: 'No traffic monitoring servers configured.', configure: 'Configure servers', outbound: 'Outbound (NIC TX)', inbound: 'Inbound (NIC RX)', both: 'Both (RX + TX)', over: 'Over quota', cycle: 'Current cycle', timezone: 'Timezone', settings: 'FlowScope / Traffic Cycle', enable: 'Enable periodic traffic monitoring', name: 'Display name', nameHint: 'Leave blank to use the physical server name', quota: 'Traffic quota', quotaHint: '1 GB = 1024³ bytes; 1 TB = 1024⁴ bytes.', mode: 'NIC traffic direction', day: 'Cycle start day', dayHint: 'Day 1–31 each month; shorter months use their last day.', save: 'Save FlowScope', saving: 'Saving…', saved: 'FlowScope settings saved.', editLoading: 'Loading FlowScope settings…', editError: 'Unable to load settings. Please retry.', unavailable: 'Physical server identity is unavailable or ambiguous. FlowScope settings are unavailable.', newMachine: 'Save the new server before configuring its traffic cycle.', validation: 'Enter a valid quota and a cycle day from 1 to 31.', independent: 'FlowScope settings are saved independently.', failed: 'Unable to save. Please retry.', waiting: 'Waiting for collector', active: 'Collecting', stale: 'Report overdue', waitingHistory: 'Waiting for new collector reports. Recorded history below is retained, not complete-cycle coverage.', waitingHint: 'No samples received yet. This does not establish zero usage for the cycle.', staleHint: 'Collector reports are overdue. Usage below is the last known value and may be incomplete.', coverage: 'Only sampled NIC deltas are counted. Traffic before the initial baseline and unsampled tails before reboot cannot be recovered. Cross-sample intervals are prorated by elapsed time.', lastReport: 'Last report', firstSample: 'First sample', collector: 'NIC Collector', installHint: 'Install an additional FlowScope Collector on each physical server. Use the machine_id below and obtain its server Token from native Server Management. Do not paste a Token into this UI.', interfaces: 'Interfaces', resetCollector: 'Reset collector binding', resetConfirm: 'Reset this physical server’s collector binding? The old collector will be invalidated and must bind again. Collected history will not be deleted.', resetDone: 'Collector binding reset. Traffic history has been preserved.'}
  };

  Object.assign(words['zh-CN'], {installHint: '点击生成一键命令，在对应 Linux 宿主机执行。命令以 root 权限运行你面板提供的安装器；无需手工填写机器 Token。', installGenerate: '生成一键安装命令', installGenerating: '正在生成…', installInterfaces: '指定网卡（可选）', installInterfaceHint: '留空自动识别唯一默认出口；多出口请填写，如 ens33,eth1。已有安装默认保留原网卡。', installSecurity: '命令 10 分钟内单次有效，新命令使旧命令失效。使用前请保密；安装会启动服务并设为开机自启，不删除采集状态。', installCopy: '复制命令', installCopied: '已复制；请在对应服务器执行。', installSelect: '已选中命令，请手动复制。', installExpired: '安装命令已过期，请重新生成。', installError: '生成失败：请确认面板使用 HTTPS、服务器已启用，再重试。', installInvalid: '网卡名无效或重复，请用逗号分隔。', waitingHistory: '等待新的采集上报；以下保留历史流量，不代表完整周期用量。'});
  Object.assign(words['en-US'], {installHint: 'Generate a command and run it on this Linux host. It executes the installer from your panel as root; no manual machine Token entry.', installGenerate: 'Generate install command', installGenerating: 'Generating…', installInterfaces: 'NIC override (optional)', installInterfaceHint: 'Blank: detect a unique default-route NIC. Multiple exits: specify ens33,eth1. Existing installs retain their NICs.', installSecurity: 'One use within 10 minutes; a new command revokes the previous one. Keep it private. Installs and starts an enabled service without deleting collector state.', installCopy: 'Copy command', installCopied: 'Copied. Run on the matching server.', installSelect: 'Command selected; copy manually.', installExpired: 'Command expired; generate another.', installError: 'Could not generate. Use panel HTTPS and enable this machine, then retry.', installInvalid: 'Invalid or duplicate NIC names; separate with commas.'});

  Object.assign(words['zh-CN'], {installHint: '生成简短管理命令，在对应 Linux SSH 终端运行。交互选项：1 对接面板 / 升级；2 卸载。以 root 权限执行面板提供的脚本。', installGenerate: '生成安装 / 卸载命令', installSecurity: '对接凭证 10 分钟内单次有效，命令请保密。升级保留采集数据；卸载需再次确认，将删除本机配置、数据库和未上报流量，不删除面板历史。'});
  Object.assign(words['en-US'], {installHint: 'Generate a short command and run in this Linux SSH terminal. Menu: 1 Connect / upgrade; 2 Uninstall. Executes panel-provided code as root.', installGenerate: 'Generate install / uninstall command', installSecurity: 'Connection credential: one use within 10 minutes. Keep private. Upgrades retain state; confirmed uninstall deletes local config, database and pending reports, not panel history.'});
  function language() {
    const value = localStorage.getItem('i18nextLng') || 'zh-CN';
    return value.startsWith('zh') ? 'zh-CN' : value.startsWith('ru') ? 'ru-RU' : 'en-US';
  }
  function t(key) { return (words[language()] || words['en-US'])[key] || key; }
  function nativeLabels() {
    const lang = language(), resource = window.XBOARD_TRANSLATIONS?.[lang];
    const base = fallback[lang] || fallback['en-US'];
    return {
      title: resource?.dashboard?.title || base.title,
      stats: ['todayIncome', 'monthlyIncome', 'pendingTickets', 'pendingCommission'].map((k, i) => resource?.dashboard?.stats?.[k] || base.stats[i]),
      overview: resource?.dashboard?.overview?.title || base.overview,
      nav: Object.fromEntries(Object.entries(navPaths).map(([path, key], i) => [path, resource?.nav?.[key] || base.nav[i]]))
    };
  }
  function token() {
    try {
      const entry = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || 'null');
      return entry && typeof entry.value === 'string' && entry.value &&
        (entry.expire == null || (typeof entry.expire === 'number' && entry.expire > Date.now())) ? entry.value : '';
    } catch { return ''; }
  }
  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = String(text);
    return node;
  }
  function owned(node) { node.setAttribute('data-flowscope-owner', OWNER); return node; }
  function listen(node, type, fn, options) {
    node.addEventListener(type, fn, options);
    listeners.push(() => node.removeEventListener(type, fn, options));
  }
  function button(label, fn) {
    const node = el('button', 'flowscope-button', label);
    node.type = 'button';
    node.addEventListener('click', fn);
    return node;
  }
  function markHidden(node) {
    if (!node || node.hasAttribute(HIDDEN)) return;
    node.setAttribute(HIDDEN, OWNER);
    hidden.add(node);
  }
  function restoreHidden() {
    for (const node of hidden) if (node.getAttribute(HIDDEN) === OWNER) node.removeAttribute(HIDDEN);
    hidden.clear();
  }
  function clearUI() {
    generation++;
    addresses.clear(); nodeAddresses.clear(); addressAttempted = 0; addressBusy = false;
    restoreHidden();
    for (const node of document.querySelectorAll(OWN)) node.remove();
    editors.clear();
    for (const dialog of adaptedDialogs) if (dialog.getAttribute('data-flowscope-machine-dialog') === OWNER) dialog.removeAttribute('data-flowscope-machine-dialog');
    adaptedDialogs.clear();
    dashboard = null;
    dashboardBusy = false;
    dashboardRequest++;
    dashboardUpdated = 0;
    dashboardAttempted = 0;
    for (const controller of controllers) controller.abort();
    controllers.clear();
  }
  function destroy() {
    if (stopped) return;
    stopped = true;
    authorized = false;
    observer.disconnect();
    clearInterval(lifecycleTimer);
    for (const remove of listeners.splice(0)) remove();
    clearUI();
    for (const asset of document.querySelectorAll('[data-flowscope-asset="1"]')) asset.remove();
    delete window.__FLOWSCOPE_RUNTIME__;
  }
  async function request(path, body) {
    const currentToken = token();
    if (!currentToken) { if (authorized) destroy(); throw new Error('Unauthenticated'); }
    const controller = new AbortController();
    controllers.add(controller);
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(path.startsWith(apiBase + '/') ? path : apiBase + path, {
        method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {Authorization: currentToken, Accept: 'application/json', 'Content-Type': 'application/json', 'Content-Language': language()},
        body: body === undefined ? undefined : JSON.stringify(body), signal: controller.signal
      });
      if (currentToken !== token()) throw new Error('Authentication changed');
      if ([401, 403, 404, 410].includes(response.status) && (path.includes('/status') || [401, 403, 410].includes(response.status))) destroy();
      const payload = await response.json().catch(() => null);
      if (currentToken !== token()) throw new Error('Authentication changed');
      if (!response.ok) {
        const error = new Error(payload?.message || 'Request failed');
        error.errors = payload?.errors;
        throw error;
      }
      if (path === config.statusUrl || path.endsWith('/status')) {
        if (!payload || typeof payload.enabled !== 'boolean') throw new Error('Invalid status response');
        return payload;
      }
      if (!payload || !Object.prototype.hasOwnProperty.call(payload, 'data')) throw new Error('Invalid response');
      return payload.data;
    } finally {
      clearTimeout(timeout);
      controllers.delete(controller);
    }
  }
  async function checkStatus() {
    if (stopped || statusBusy || !token()) return;
    const expectedToken = token();
    statusBusy = true;
    try {
      const data = await request(config.statusUrl || apiBase + '/status');
      if (stopped) return;
      if (data.enabled !== true || data.compatibility?.supported === false) { destroy(); return; }
      authorized = true;
      schedule();
    } catch { /* A transient failure leaves native UI visible until successful authorization. */ }
    finally {
      statusBusy = false;
      if (!stopped && token() && token() !== expectedToken) checkStatus();
    }
  }
  function syncToken() {
    const current = token();
    if (current === lastToken) return;
    const previous = lastToken;
    lastToken = current;
    if (authorized || previous) { authorized = false; clearUI(); }
    if (!current && previous) destroy();
    else if (current) checkStatus();
  }

  // React Fiber is inspected only for the existing native form provider and sidebar ancestry.
  // No fiber, native props, form values, handlers, or native requests are ever changed.
  function fiber(node) {
    const keys = Object.keys(node).filter(k => k.startsWith('__reactFiber$'));
    return keys.length === 1 ? node[keys[0]] : null;
  }
  function identity(dialog) {
    const form = editorHost(dialog);
    if (!form) return {error: 'unavailable'};
    const values = new Map();
    const owners = [];
    const seen = new Set();
    let item = fiber(form.querySelector('input[name="name"]')), count = 0;
    while (item && count++ < 100 && !seen.has(item)) {
      seen.add(item);
      const props = item.memoizedProps;
      if (typeof props?.getValues === 'function' && props.control && !values.has(props.getValues)) {
        try { values.set(props.getValues, props.getValues()); }
        catch { return {error: 'unavailable'}; }
      }
      // The audited machine edit component owns this exact native form, including
      // unsaved create state. Never derive a machine from node bindings or names.
      if (item.type?.name === 'n3t' && ['edit', 'add'].includes(props?.type || 'add')) owners.push(props);
      item = item.return;
    }
    if (values.size !== 1 || owners.length !== 1) return {error: 'unavailable'};
    const value = [...values.values()][0];
    if (!value || typeof value.name !== 'string' || typeof value.notes !== 'string' || typeof value.is_active !== 'boolean' || Object.hasOwn(value, 'type') || Object.hasOwn(value, 'server_port')) return {error: 'unavailable'};
    const owner = owners[0];
    if (value.id == null || value.id === '') return owner.type !== 'edit' ? {error: 'newMachine'} : {error: 'unavailable'};
    const id = String(value.id);
    if (!/^[1-9]\d*$/.test(id) || typeof value.id === 'number' && !Number.isSafeInteger(value.id) || owner.type !== 'edit' || String(owner.defaultValues?.id) !== id) return {error: 'unavailable'};
    return {id, key: 'machine:' + id};
  }
  function sidebarContext(anchor, path) {
    if (anchor.closest('aside nav')) return true;
    if (!anchor.closest('[role="menu"]')) return false;
    let item = fiber(anchor), count = 0;
    while (item && count++ < 100) {
      const sub = item.memoizedProps?.sub;
      if (Array.isArray(sub) && sub.some(n => n.href === path && n.title === 'nav:' + navPaths[path])) return true;
      item = item.return;
    }
    return false;
  }
  function hideSidebar(labels) {
    for (const anchor of document.querySelectorAll('aside nav a[href], [role="menu"] a[href]')) {
      const rawHref = anchor.getAttribute('href');
      const href = rawHref?.startsWith('#/') ? rawHref.slice(1) : null;
      if (!Object.hasOwn(navPaths, href) || !sidebarContext(anchor, href) || anchor.textContent.trim() !== labels.nav[href]) continue;
      const li = anchor.closest('li');
      markHidden(li && li.closest('nav') === anchor.closest('nav') ? li : anchor);
    }
  }
  function dashboardGrid(labels) {
    const titles = [...document.querySelectorAll('#root h1')].filter(n => n.textContent.trim() === labels.title && !n.closest('aside'));
    if (titles.length !== 1) return null;
    const header = titles[0].parentElement?.parentElement, layout = header?.parentElement;
    if (!header?.classList.contains('flex-none') || !layout?.classList.contains('flex-col')) return null;
    const bodies = [...layout.children].filter(n => n.classList.contains('flex-1') && n.classList.contains('overflow-hidden'));
    if (bodies.length !== 1) return null;
    const wraps = [...bodies[0].children].filter(n => n.classList.contains('space-y-6'));
    if (wraps.length !== 1) return null;
    const grids = [...wraps[0].children].filter(n => n.classList.contains('grid') && n.classList.contains('gap-6'));
    return grids.length === 1 ? grids[0] : null;
  }
  function hideDashboard(grid, labels) {
    const stats = [...grid.children].filter(n => n.classList.contains('grid') && n.classList.contains('gap-4') && n.classList.contains('lg:grid-cols-4'));
    if (stats.length === 1) {
      for (const title of labels.stats) {
        const matches = [...stats[0].querySelectorAll('h3')].filter(n => n.textContent.trim() === title);
        if (matches.length === 1) {
          const card = matches[0].closest('div.rounded-xl.border.bg-card');
          if (card?.parentElement === stats[0]) markHidden(card);
        }
      }
    }
    const overviews = [...grid.querySelectorAll('h3')].filter(n => n.textContent.trim() === labels.overview && !n.closest(OWN));
    if (overviews.length === 1) {
      const card = overviews[0].closest('div.rounded-xl.border.bg-card');
      if (card?.parentElement === grid) markHidden(card);
    }
  }
  function formatBytes(raw) {
    try {
      let value = BigInt(String(raw));
      if (value < 0n) value = 0n;
      const units = ['B', 'KB', 'GB', 'TB'];
      const sizes = [1n, 1024n, 1073741824n, 1099511627776n];
      if (value >= 1048576n && value < sizes[2]) return ((value * 10n / 1048576n).toString().replace(/(\d)(\d)$/, '$1.$2')) + ' MB';
      let index = 0;
      for (let i = sizes.length - 1; i >= 0; i--) if (value >= sizes[i]) { index = i; break; }
      if (index === 0) return value + ' B';
      const tenths = (value * 10n + sizes[index] / 2n) / sizes[index];
      return (tenths / 10n) + (tenths % 10n ? '.' + (tenths % 10n) : '') + ' ' + units[index];
    } catch { return '—'; }
  }
  // Dates and percentages are supplied by the backend. JS never calculates cycle boundaries.
  function cycleLabel(server) {
    const start = String(server.cycle_start || '').slice(0, 10), end = String(server.cycle_end || '').slice(0, 10);
    return /^\d{4}-\d{2}-\d{2}$/.test(start) && /^\d{4}-\d{2}-\d{2}$/.test(end) ? start + ' – ' + end : '—';
  }
  function sampleTime(value) {
    if (value == null || value === '') return '—';
    const numeric = typeof value === 'number' || /^\d{10,13}$/.test(String(value));
    const number = numeric ? Number(value) : null;
    const date = new Date(numeric ? number * (number < 1e12 ? 1000 : 1) : String(value));
    if (!Number.isFinite(date.getTime())) return '—';
    try { return new Intl.DateTimeFormat(language(), {timeZone: config.timezone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false}).format(date) + ' (' + (config.timezone || 'UTC') + ')'; }
    catch { return date.toISOString(); }
  }
  function stateMessage(container, message, retry) {
    const block = el('div', 'flowscope-state');
    block.setAttribute('role', retry ? 'alert' : 'status');
    block.append(el('p', '', message));
    if (retry) block.append(button(t('retry'), retry));
    container.replaceChildren(block);
  }
  function mountDashboard(grid) {
    if (dashboard?.isConnected && dashboard.parentElement === grid) return;
    dashboard?.remove();
    const section = owned(el('section', 'flowscope-card'));
    section.id = 'flowscope-period-traffic';
    section.setAttribute('aria-labelledby', 'flowscope-period-heading');
    const header = el('div', 'flowscope-card-header');
    const title = el('h3', 'flowscope-card-title', t('period'));
    title.id = 'flowscope-period-heading';
    header.append(title, el('p', 'flowscope-muted', t('subtitle')));
    const body = el('div', 'flowscope-card-body');
    section.append(header, body);
    const stats = [...grid.children].filter(n => n.classList.contains('grid') && n.classList.contains('lg:grid-cols-4'));
    if (stats.length === 1) grid.insertBefore(section, stats[0].nextSibling);
    else grid.append(section);
    dashboard = section;
    loadDashboard();
  }
  async function loadDashboard() {
    if (!authorized || stopped || !dashboard?.isConnected || dashboardBusy) return;
    dashboardBusy = true;
    const requestId = ++dashboardRequest;
    dashboardAttempted = Date.now();
    const target = dashboard, body = target.querySelector('.flowscope-card-body'), revision = generation;
    target.setAttribute('aria-busy', 'true');
    if (!dashboardUpdated) stateMessage(body, t('loading'));
    try {
      const data = await request('/dashboard');
      if (stopped || revision !== generation || target !== dashboard || !target.isConnected) return;
      if (!Array.isArray(data.servers)) throw new Error('Invalid response');
      if (!data.servers.length) {
        stateMessage(body, t('empty'));
        const source = [...document.querySelectorAll('aside nav a[href="#/server/machine"]')];
        // Use the existing, audited router href only if it is present in native navigation.
        if (source.length === 1) {
          const link = el('a', 'flowscope-link', t('configure'));
          link.href = source[0].getAttribute('href');
          body.firstElementChild.append(link);
        }
      } else {
        const list = el('div', 'flowscope-server-list');
        // Server returns the stable percentage-descending ordering.
        for (const server of data.servers) {
          if (!/^[1-9]\d*$/.test(String(server.machine_id)) || server.traffic_source !== 'nic') throw new Error('Invalid machine traffic response');
          const collectorStatus = ['active', 'stale'].includes(server.collector_status) ? server.collector_status : 'waiting';
          const waiting = collectorStatus === 'waiting';
          // Resetting a collector cursor does not remove previously recorded daily
          // traffic. Keep that history visible while awaiting a new collector.
          const recordedHistory = ['used_bytes', 'inbound_bytes', 'outbound_bytes'].some(key => /^\d+$/.test(String(server[key])) && BigInt(String(server[key])) > 0n);
          const unmeasured = waiting && !recordedHistory;
          const percentage = Math.max(0, Number(server.percentage) || 0);
          const overQuota = !unmeasured && (server.over_quota === true || percentage > 100);
          const item = el('article', 'flowscope-server' + (overQuota ? ' flowscope-over' : ''));
          item.setAttribute('data-machine-id', String(server.machine_id));
          const top = el('div', 'flowscope-server-heading');
          const name = el('h4', 'flowscope-server-name', server.name || server.display_name || '—');
          name.title = name.textContent;
          top.append(name);
          top.append(el('span', 'flowscope-collector-status flowscope-collector-' + collectorStatus, t(collectorStatus)));
          if (overQuota) top.append(el('span', 'flowscope-over-badge', t('over')));
          top.append(el('span', 'flowscope-percentage', unmeasured ? '—' : percentage.toFixed(1) + '%'));
          const meter = el('div', 'flowscope-progress');
          meter.setAttribute('role', 'progressbar');
          meter.setAttribute('aria-label', name.textContent);
          meter.setAttribute('aria-valuemin', '0');
          meter.setAttribute('aria-valuemax', '100');
          if (!unmeasured) meter.setAttribute('aria-valuenow', String(Math.min(100, percentage)));
          meter.setAttribute('aria-valuetext', unmeasured ? t('waiting') : percentage.toFixed(1) + '%');
          const fill = el('div', 'flowscope-progress-fill');
          fill.style.width = (unmeasured ? 0 : Math.min(100, percentage)) + '%';
          meter.append(fill);
          const details = el('div', 'flowscope-server-details');
          details.append(el('span', 'flowscope-usage', (unmeasured ? '—' : formatBytes(server.used_bytes)) + ' / ' + formatBytes(server.quota_bytes)), el('span', '', t(server.traffic_mode)), el('span', 'flowscope-cycle', t('cycle') + ': ' + cycleLabel(server)));
          item.append(top, meter, details);
          item.append(el('p', 'flowscope-sample-details', 'NIC RX: ' + (unmeasured ? '—' : formatBytes(server.inbound_bytes)) + ' · NIC TX: ' + (unmeasured ? '—' : formatBytes(server.outbound_bytes))));
          if (waiting || collectorStatus === 'stale') item.append(el('p', 'flowscope-sample-note', t(waiting ? recordedHistory ? 'waitingHistory' : 'waitingHint' : 'staleHint')));
          if (server.last_report_at) item.append(el('p', 'flowscope-sample-details', t('lastReport') + ': ' + sampleTime(server.last_report_at)));
          if (server.first_sample_at) item.append(el('p', 'flowscope-sample-details', t('firstSample') + ': ' + sampleTime(server.first_sample_at)));
          list.append(item);
        }
        const zone = el('p', 'flowscope-timezone', t('timezone') + ': ' + String(data.timezone || config.timezone || '—'));
        body.replaceChildren(list, el('p', 'flowscope-coverage', t('coverage')), zone);
      }
      dashboardUpdated = Date.now();
    } catch {
      if (!stopped && revision === generation && target === dashboard && target.isConnected) stateMessage(body, t('error'), loadDashboard);
    } finally {
      if (requestId === dashboardRequest) {
        dashboardBusy = false;
        // A route can replace the dashboard while its request is still pending.
        // Immediately populate the new section after that old request settles.
        if (!stopped && authorized && target !== dashboard && dashboard?.isConnected) loadDashboard();
      }
      target.removeAttribute('aria-busy');
    }
  }

  function editorHost(dialog) {
    if (location.hash.split('?')[0] !== '#/server/machine' || dialog.querySelector('input[name="server_port"]')) return null;
    const forms = [...dialog.querySelectorAll('form')].filter(n => !n.closest(OWN));
    if (forms.length !== 1) return null;
    const form = forms[0];
    return form.querySelectorAll('input[name="name"]').length === 1 && form.querySelectorAll('textarea[name="notes"]').length === 1 && form.querySelectorAll('button[role="switch"]').length === 1 && form.parentElement === dialog ? form : null;
  }
  function mountEditors() {
    for (const [dialog, state] of editors) if (!dialog.isConnected || !state.node.isConnected || !editorHost(dialog) || dialog.getAttribute('data-state') !== 'open') {
      state.node.remove(); editors.delete(dialog);
      if (dialog.getAttribute('data-flowscope-machine-dialog') === OWNER) dialog.removeAttribute('data-flowscope-machine-dialog');
      adaptedDialogs.delete(dialog);
    }
    for (const dialog of document.querySelectorAll('[role="dialog"][data-state="open"]')) {
      const host = editorHost(dialog);
      if (!host) continue;
      const resolved = identity(dialog), key = resolved.key || resolved.error;
      let state = editors.get(dialog);
      if (state?.key === key && state.node.isConnected) continue;
      state?.node.remove();
      const section = owned(el('section', 'flowscope-editor'));
      section.append(el('h3', 'flowscope-card-title', t('settings')));
      const body = el('div', 'flowscope-editor-body');
      section.append(body);
      // A sibling of the native form cannot submit it, including on Enter.
      host.after(section);
      if (!dialog.hasAttribute('data-flowscope-machine-dialog')) {
        dialog.setAttribute('data-flowscope-machine-dialog', OWNER);
        adaptedDialogs.add(dialog);
      }
      state = {node: section, body, key, identity: resolved};
      editors.set(dialog, state);
      if (resolved.error) stateMessage(body, t(resolved.error));
      else loadEditor(dialog, state);
    }
  }
  async function loadEditor(dialog, state) {
    stateMessage(state.body, t('editLoading'));
    try {
      const data = await request('/machine/' + state.identity.id);
      if (stopped || editors.get(dialog) !== state || !state.node.isConnected || identity(dialog).key !== state.identity.key) return;
      if (!data.settings || String(data.server?.machine_id) !== state.identity.id) throw new Error('Identity mismatch');
      renderEditor(dialog, state, data.settings, data.collector);
    } catch {
      if (!stopped && editors.get(dialog) === state && state.node.isConnected) stateMessage(state.body, t('editError'), () => loadEditor(dialog, state));
    }
  }
  function field(label, input, hint) {
    const wrapper = el('label', 'flowscope-field');
    wrapper.append(el('span', 'flowscope-label', label), input);
    if (hint) wrapper.append(el('span', 'flowscope-hint', hint));
    return wrapper;
  }
  function input(type, value, name) {
    const node = el('input', 'flowscope-input');
    node.type = type; node.value = value; node.name = 'flowscope_' + name;
    return node;
  }
  function select(options, value, name) {
    const node = el('select', 'flowscope-input');
    node.name = 'flowscope_' + name;
    for (const [key, label] of options) { const option = el('option', '', label); option.value = key; node.append(option); }
    node.value = value;
    return node;
  }
  function renderEditor(dialog, state, settings, collector) {
    const controls = el('div', 'flowscope-fields');
    const enabled = input('checkbox', '', 'enabled');
    enabled.checked = settings.enabled === true;
    enabled.className = 'flowscope-checkbox';
    const toggle = el('label', 'flowscope-toggle');
    toggle.append(enabled, el('span', '', t('enable')));
    const name = input('text', settings.display_name || '', 'display_name');
    name.maxLength = 120; name.placeholder = t('nameHint');
    // Keep an exact byte quota until the user changes either quota control.
    const quotaBytes = String(settings.quota_bytes || '0');
    let defaultUnit = 'GB', defaultValue = '';
    try {
      const bytes = BigInt(quotaBytes);
      const size = bytes >= 1099511627776n && bytes % 1099511627776n === 0n ? 1099511627776n : 1073741824n;
      defaultUnit = size === 1099511627776n ? 'TB' : 'GB';
      if (bytes > 0n) defaultValue = String(Number(bytes * 1000000n / size) / 1000000);
    } catch { /* malformed backend quota remains blank and is rejected by validation */ }
    const quota = input('text', defaultValue, 'quota_value');
    quota.inputMode = 'decimal'; quota.autocomplete = 'off';
    const unit = select([['GB', 'GB'], ['TB', 'TB']], defaultUnit, 'quota_unit');
    const initialQuotaValue = quota.value, initialQuotaUnit = unit.value;
    const quotaGroup = el('span', 'flowscope-quota-controls');
    quotaGroup.append(quota, unit);
    const mode = select([['outbound', t('outbound')], ['inbound', t('inbound')], ['both', t('both')]], settings.traffic_mode || 'outbound', 'traffic_mode');
    const day = input('number', String(settings.cycle_start_day || 1), 'cycle_start_day');
    day.min = '1'; day.max = '31'; day.step = '1';
    controls.append(field(t('name'), name), field(t('quota'), quotaGroup, t('quotaHint')), field(t('mode'), mode), field(t('day'), day, t('dayHint')));
    const status = el('p', 'flowscope-save-status');
    status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    const save = button(t('save'), async () => {
      if (save.disabled) return;
      if (lastToken !== token()) { syncToken(); return; }
      const current = identity(dialog);
      if (current.key !== state.identity.key) { schedule(); return; }
      const validDay = /^\d+$/.test(day.value) && Number(day.value) >= 1 && Number(day.value) <= 31;
      const quotaChanged = quota.value.trim() !== initialQuotaValue.trim() || unit.value !== initialQuotaUnit;
      if (!validDay || (quotaChanged || quotaBytes === '0') && (!/^\d+(?:\.\d{1,9})?$/.test(quota.value.trim()) || !/[1-9]/.test(quota.value))) {
        status.textContent = t('validation'); status.classList.add('flowscope-error'); return;
      }
      const payload = {enabled: enabled.checked, display_name: name.value.trim(), traffic_mode: mode.value, cycle_start_day: Number(day.value)};
      if (quotaChanged || quotaBytes === '0') { payload.quota_value = quota.value.trim(); payload.quota_unit = unit.value; }
      else payload.quota_bytes = quotaBytes;
      save.disabled = true; save.textContent = t('saving'); status.textContent = ''; status.classList.remove('flowscope-error');
      try {
        await request('/machine/' + current.id, payload);
        if (stopped || editors.get(dialog) !== state || !state.node.isConnected) return;
        status.textContent = t('saved');
        dashboardUpdated = 0;
        loadDashboard();
      } catch (error) {
        if (!stopped && state.node.isConnected) {
          const messages = error.errors && Object.values(error.errors).flat().filter(v => typeof v === 'string');
          status.textContent = messages?.length ? messages.join(' ') : t('failed'); status.classList.add('flowscope-error');
        }
      } finally { save.disabled = false; save.textContent = t('save'); }
    });
    const actions = el('div', 'flowscope-editor-actions');
    actions.append(save, el('span', 'flowscope-hint', t('independent')));
    const installation = el('aside', 'flowscope-collector-install');
    installation.append(el('h4', 'flowscope-label', t('collector')), el('p', 'flowscope-hint', t('installHint')), el('code', 'flowscope-machine-id', 'machine_id: ' + state.identity.id));
    const collectorStatus = ['active', 'stale'].includes(collector?.status) ? collector.status : 'waiting';
    installation.append(el('p', 'flowscope-hint', t(collectorStatus)));
    const nicOverride = input('text', '', 'install_interfaces');
    nicOverride.autocomplete = 'off'; nicOverride.placeholder = 'ens33,eth1'; nicOverride.maxLength = 2080;
    const command = el('textarea', 'flowscope-input flowscope-install-command');
    command.readOnly = true; command.rows = 5; command.hidden = true;
    command.setAttribute('aria-label', t('installGenerate')); command.spellcheck = false;
    const installStatus = el('p', 'flowscope-hint'); installStatus.setAttribute('role', 'status');
    const copy = button(t('installCopy'), async () => {
      if (!command.value || state.installExpires <= Date.now() || lastToken !== token()) return;
      try { await navigator.clipboard.writeText(command.value); installStatus.textContent = t('installCopied'); }
      catch { command.focus(); command.select(); installStatus.textContent = t('installSelect'); }
    });
    copy.hidden = true;
    const generate = button(t('installGenerate'), async () => {
      if (generate.disabled || lastToken !== token() || identity(dialog).key !== state.identity.key) return;
      const names = nicOverride.value.trim() ? nicOverride.value.split(',').map(v => v.trim()).filter(Boolean) : [];
      if (names.length > 32 || new Set(names).size !== names.length || names.some(v => !/^[A-Za-z0-9_.:@-]{1,64}$/.test(v) || v === 'lo')) { installStatus.textContent = t('installInvalid'); return; }
      command.value = ''; command.hidden = true; copy.hidden = true;
      generate.disabled = true; generate.textContent = t('installGenerating'); installStatus.textContent = '';
      try {
        const data = await request('/machine/' + state.identity.id + '/install-ticket', {panel_url: location.origin, interfaces: names});
        if (stopped || editors.get(dialog) !== state || !state.node.isConnected || identity(dialog).key !== state.identity.key) return;
        if (String(data.machine_id) !== state.identity.id || typeof data.command !== 'string' || !Number.isFinite(data.expires_at) || data.expires_at * 1000 <= Date.now()) throw new Error('Invalid command');
        command.value = data.command; command.hidden = false; copy.hidden = false;
        state.installExpires = data.expires_at * 1000;
        state.expireInstall = () => { command.value = ''; command.hidden = true; copy.hidden = true; installStatus.textContent = t('installExpired'); };
        installStatus.textContent = t('installSecurity');
      } catch { if (!stopped && editors.get(dialog) === state && state.node.isConnected) installStatus.textContent = t('installError'); }
      finally { generate.disabled = false; generate.textContent = t('installGenerate'); }
    });
    generate.classList.add('flowscope-generate-install'); copy.classList.add('flowscope-copy-install');
    installation.append(field(t('installInterfaces'), nicOverride, t('installInterfaceHint')), generate, command, copy, installStatus);
    if (collector?.last_report_at) installation.append(el('p', 'flowscope-hint', t('lastReport') + ': ' + sampleTime(collector.last_report_at)));
    if (collector?.first_sample_at) installation.append(el('p', 'flowscope-hint', t('firstSample') + ': ' + sampleTime(collector.first_sample_at)));
    if (Array.isArray(collector?.interfaces)) {
      const names = collector.interfaces.filter(v => typeof v === 'string').join(', ');
      if (names) installation.append(el('p', 'flowscope-hint', t('interfaces') + ': ' + names));
    }
    if (collector?.id) {
      const reset = button(t('resetCollector'), async () => {
        if (reset.disabled || lastToken !== token() || identity(dialog).key !== state.identity.key) return;
        if (!window.confirm(t('resetConfirm'))) return;
        if (lastToken !== token() || identity(dialog).key !== state.identity.key) { syncToken(); schedule(); return; }
        reset.disabled = true;
        try {
          await request('/machine/' + state.identity.id + '/collector-reset', {});
          if (stopped || editors.get(dialog) !== state || !state.node.isConnected) return;
          state.collectorReset = true;
          loadEditor(dialog, state);
        } catch {
          if (!stopped && state.node.isConnected) { status.textContent = t('failed'); status.classList.add('flowscope-error'); }
        } finally { reset.disabled = false; }
      });
      reset.classList.add('flowscope-reset-collector');
      installation.append(reset);
    }
    if (state.collectorReset) { status.textContent = t('resetDone'); state.collectorReset = false; }
    state.body.replaceChildren(toggle, controls, actions, status, installation);
    // An Enter key in our controls must not activate a native dialog submit action.
    state.body.onkeydown = event => { if (event.key === 'Enter' && event.target.tagName !== 'BUTTON') { event.preventDefault(); event.stopPropagation(); } };
  }
  function mountMachineAddresses() {
    const path = location.hash.split('?')[0];
    if (!['#/server/machine', '#/server/manage'].includes(path)) return;
    if (!addressBusy && Date.now() - addressAttempted >= 60000) {
      addressBusy = true; addressAttempted = Date.now();
      const revision = generation;
      request('/machine-addresses').then(data => {
        if (stopped || revision !== generation) return;
        addresses = new Map((Array.isArray(data.machines) ? data.machines : []).filter(v => /^[1-9]\d*$/.test(String(v.machine_id)) && validIPv4(v.ipv4)).map(v => [String(v.machine_id), v]));
        nodeAddresses = new Map((Array.isArray(data.nodes) ? data.nodes : []).filter(v => /^[1-9]\d*$/.test(String(v.node_id)) && validIPv4(v.ipv4)).map(v => [String(v.node_id), v]));
        schedule();
      }).catch(() => {}).finally(() => { if (revision === generation) addressBusy = false; });
    }
    const seen = new Set();
    for (const span of document.querySelectorAll('#root span')) {
      if (span.closest(OWN)) continue;
      let item = fiber(span), count = 0;
      while (item && count++ < 30) {
        const node = item.memoizedProps?.node;
        if (path === '#/server/manage' && node && !node.machine_id && /^[1-9]\d*$/.test(String(node.id)) &&
            span.textContent === node.name && span.classList.contains('cursor-default')) {
          const parent = span.parentElement;
          if (!seen.has(parent)) {
            seen.add(parent);
            let line = parent.querySelector(':scope > .flowscope-machine-ip');
            if (!line) { line = owned(el('small', 'flowscope-machine-ip')); span.after(line); }
            line.dataset.nodeId = String(node.id);
            renderAddress(line, nodeAddresses.get(String(node.id)));
          }
          break;
        }
        if (item.type?.name === 'c3t') {
          const id = String(item.memoizedProps?.machine?.id || '');
          const card = item.child?.stateNode;
          const content = card?.children?.[1], meta = content?.children?.[1];
          if (!/^[1-9]\d*$/.test(id) || !content || !meta || !meta.classList.contains('text-xs') || seen.has(content)) break;
          seen.add(content);
          let line = content.querySelector(':scope > .flowscope-machine-ip');
          if (!line) { line = owned(el('p', 'flowscope-machine-ip')); meta.after(line); }
          line.dataset.machineId = id;
          renderAddress(line, addresses.get(id));
          break;
        }
        item = item.return;
      }
    }
  }
  function renderAddress(line, record) {
    const fresh = record && (typeof record.stale === 'boolean' ? !record.stale : Date.now()/1000 - Number(record.updated_at) < 7200);
    const text = 'IPv4: ' + (record ? record.ipv4 + (fresh ? '' : (language() === 'zh-CN' ? '（最后已知）' : ' (last known)')) : (language() === 'zh-CN' ? '待获取' : 'Waiting'));
    if (line.textContent !== text) line.textContent = text;
    const source = record?.source === 'node' ? 'Xboard-Node' : 'FlowScope Collector';
    line.title = record ? source + ' · ' + new Date(Number(record.updated_at) * 1000).toLocaleString() : '';
  }
  function validIPv4(value) {
    return typeof value === 'string' && /^(?:\d{1,3}\.){3}\d{1,3}$/.test(value) && value.split('.').every(v => Number(v) <= 255 && String(Number(v)) === v);
  }
  function reconcile() {
    scheduled = false;
    if (stopped || !authorized) return;
    if (!token()) { destroy(); return; }
    const labels = nativeLabels();
    // Reversible markers are retained across native mutations and removed only by cleanup.
    for (const node of hidden) if (!node.isConnected) hidden.delete(node);
    hideSidebar(labels);
    const grid = dashboardGrid(labels);
    if (grid) { hideDashboard(grid, labels); mountDashboard(grid); }
    else if (dashboard) { dashboard.remove(); dashboard = null; dashboardUpdated = 0; }
    mountEditors();
    mountMachineAddresses();
  }
  function schedule() {
    if (stopped || scheduled) return;
    scheduled = true;
    queueMicrotask(reconcile);
  }
  const observer = new MutationObserver(records => {
    // Ignore all changes confined to our UI, and our own visibility markers. This avoids
    // the repeated observer/render loop which otherwise happens in an injected adapter.
    if (records.some(record => {
      const target = record.target.nodeType === 1 ? record.target : record.target.parentElement;
      if (target?.closest(OWN)) return false;
      if (record.type === 'attributes' && record.attributeName === HIDDEN) return false;
      if (record.type === 'childList' && [...record.addedNodes, ...record.removedNodes].every(n => n.nodeType === 1 && n.matches(OWN))) return false;
      return true;
    })) schedule();
  });
  observer.observe(document.documentElement, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['data-state', 'href', 'class']});
  listen(window, 'popstate', schedule);
  listen(window, 'hashchange', schedule);
  listen(window, 'storage', event => { if (event.key === 'XBOARD_ACCESS_TOKEN') syncToken(); });
  let ticks = 0;
  const lifecycleTimer = setInterval(() => {
    if (stopped) return;
    const current = token();
    if (authorized && !current) { destroy(); return; }
    if (current !== lastToken) syncToken();
    if (++ticks % 15 === 0) checkStatus();
    if (authorized && dashboard?.isConnected && Date.now() - dashboardAttempted >= 60000) loadDashboard();
    if (authorized && ['#/server/machine', '#/server/manage'].includes(location.hash.split('?')[0]) && Date.now() - addressAttempted >= 60000) schedule();
    // React may reuse the same dialog DOM while switching machine identity.
    for (const state of editors.values()) if (state.installExpires && state.installExpires <= Date.now()) { state.expireInstall?.(); state.installExpires = 0; }
    if (authorized && editors.size) schedule();
  }, 1000);
  window.__FLOWSCOPE_RUNTIME__ = Object.freeze({version: config.version, destroy, refresh: () => { syncToken(); checkStatus(); schedule(); loadDashboard(); }});
  lastToken = token();
  checkStatus();
})();
