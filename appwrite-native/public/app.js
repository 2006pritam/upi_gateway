const $app = document.getElementById('app');
const E = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const inr = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dt = (s) => s ? new Date(s).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
const pill = (s) => `<span class="pill pill-${E(s)}">${E(s)}</span>`;
const H = { 'content-type': 'application/json', 'x-requested-with': 'emudra' };
let me = null, env = (() => { try { return localStorage.getItem('em_env') || 'live'; } catch { return 'live'; } })();

async function call(url, body) {
  const r = await fetch(url, { method: 'POST', headers: H, body: JSON.stringify(body || {}), credentials: 'same-origin' });
  let j; try { j = await r.json(); } catch { j = { ok: false, error: 'Server error (' + r.status + ')' }; }
  if (r.status === 401 && url.startsWith('/rpc/')) { me = null; location.hash = '#/login'; }
  if (!j.ok) { const e = new Error(j.error || 'Request failed'); e.errors = j.errors || {}; throw e; }
  return j.data;
}
const rpc = (n, b) => call('/rpc/' + n, b);
function toast(m) { const t = document.createElement('div'); t.className = 'toast'; t.textContent = m; document.body.appendChild(t); setTimeout(() => t.remove(), 2600); }
function formData(f) { const o = {}; new FormData(f).forEach((v, k) => { o[k] = v; }); f.querySelectorAll('input[type=checkbox]').forEach((c) => { o[c.name] = c.checked; }); return o; }
function showErr(f, e) {
  f.querySelectorAll('.field-err,.alert-error').forEach((x) => x.remove());
  let shown = false;
  for (const [k, v] of Object.entries(e.errors || {})) { const el = f.querySelector(`[name="${k}"]`); if (el) { el.insertAdjacentHTML('afterend', `<div class="field-err">${E(Array.isArray(v) ? v[0] : v)}</div>`); shown = true; } }
  if (!shown) f.insertAdjacentHTML('afterbegin', `<div class="alert alert-error">${E(e.message)}</div>`);
}
function onSubmit(sel, fn) {
  const f = $app.querySelector(sel); if (!f) return;
  f.addEventListener('submit', async (ev) => { ev.preventDefault(); const b = f.querySelector('button[type=submit],button:not([type])'); if (b) b.disabled = true; try { await fn(formData(f), f); } catch (e) { showErr(f, e); } finally { if (b) b.disabled = false; } });
}
function on(sel, ev, fn) { $app.querySelectorAll(sel).forEach((el) => el.addEventListener(ev, fn)); }
function modal(html) { const m = document.createElement('div'); m.className = 'modal'; m.innerHTML = `<div class="card">${html}</div>`; m.addEventListener('click', (e) => { if (e.target === m) m.remove(); }); document.body.appendChild(m); return m; }
const copy = (t) => { navigator.clipboard?.writeText(t); toast('Copied'); };
const field = (n, l, o = {}) => `<label class="label">${l}</label><input class="input" name="${n}" type="${o.type || 'text'}" ${o.req ? 'required' : ''} ${o.ph ? `placeholder="${E(o.ph)}"` : ''} ${o.v != null ? `value="${E(o.v)}"` : ''} ${o.step ? `step="${o.step}"` : ''} ${o.auto ? `autocomplete="${o.auto}"` : ''}>`;

// ------------------------------------------------------------------ auth
function authView(mode) {
  const reg = mode === 'register';
  $app.innerHTML = `<div class="auth"><div class="brand"><img src="/assets/emudra-mark.svg" alt="">eMudra</div><div class="card"><h1>${reg ? 'Create your account' : 'Welcome back'}</h1><p class="muted">${reg ? 'Start accepting UPI payments in minutes.' : 'Sign in to your payment dashboard.'}</p>
  <form id="f">${reg ? field('name', 'Your name', { req: 1, auto: 'name' }) + field('business_name', 'Business name', { req: 1 }) : ''}${field('email', 'Email', { type: 'email', req: 1, auto: 'email' })}${field('password', 'Password', { type: 'password', req: 1, auto: reg ? 'new-password' : 'current-password' })}${reg ? field('password_confirmation', 'Confirm password', { type: 'password', req: 1, auto: 'new-password' }) : ''}
  <button class="btn btn-block" style="margin-top:16px" type="submit">${reg ? 'Create account' : 'Sign in'}</button></form>
  <p class="center small muted" style="margin-top:14px">${reg ? 'Already have an account? <a href="#/login">Sign in</a>' : 'New here? <a href="#/register">Create an account</a>'}</p></div></div>`;
  onSubmit('#f', async (d) => { await call('/auth/' + (reg ? 'register' : 'login'), d); await boot(); location.hash = '#/dashboard'; });
}

// ------------------------------------------------------------------ shell
const NAV = [['dashboard', 'Dashboard'], ['orders', 'Orders'], ['orders/new', 'New payment'], ['links', 'Payment links'], ['subs', 'Subscriptions'], ['accounts', 'UPI accounts'], ['earnings', 'Earnings'], ['reports', 'Reports'], ['webhooks', 'Webhook log'], ['settings', 'Settings & API keys'], ['docs', 'API docs']];
function shell(route, inner) {
  const nav = [...NAV, ...(me.user.role === 'admin' ? [['admin', 'Admin']] : [])];
  $app.innerHTML = `<div class="shell"><aside class="side" id="side"><div class="brand"><img src="/assets/emudra-mark.svg" alt="">eMudra</div>${nav.map(([r, l]) => `<a class="nav ${route === r || (r !== 'orders' && route.startsWith(r + '/') && r !== 'orders/new') ? 'on' : ''}" href="#/${r}">${l}</a>`).join('')}<hr style="border:0;border-top:1px solid var(--line);margin:12px 0"><div class="small muted" style="padding:0 10px">${E(me.user.display_name)}<br>${E(me.user.email)}</div><a class="nav" href="#/password">Change password</a><a class="nav" href="#" id="out">Sign out</a></aside>
  <main class="main"><div class="top"><div class="row"><button class="btn btn-ghost menu-btn" id="mb">☰</button></div><div class="env"><button data-env="live" class="${env === 'live' ? 'on' : ''}">Live</button><button data-env="sandbox" class="${env === 'sandbox' ? 'on' : ''}">Sandbox</button></div></div><div id="view">${inner}</div></main></div>`;
  document.getElementById('mb').onclick = () => document.getElementById('side').classList.toggle('open');
  document.getElementById('out').onclick = async (e) => { e.preventDefault(); await call('/auth/logout').catch(() => {}); me = null; location.hash = '#/login'; };
  on('[data-env]', 'click', (e) => { env = e.target.dataset.env; try { localStorage.setItem('em_env', env); } catch {} route$(); });
}
const table = (heads, rows, empty = 'Nothing here yet.') => rows.length ? `<div class="tablewrap"><table><thead><tr>${heads.map((h) => `<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : `<p class="muted">${empty}</p>`;
const pager = (p, pages, key) => pages > 1 ? `<div class="row" style="margin-top:10px"><button class="btn btn-ghost btn-sm" data-pg="${p - 1}" ${p <= 1 ? 'disabled' : ''}>Prev</button><span class="muted small">Page ${p} / ${pages}</span><button class="btn btn-ghost btn-sm" data-pg="${p + 1}" ${p >= pages ? 'disabled' : ''}>Next</button></div>` : '';

// ------------------------------------------------------------------ pages
const pages = {};
pages.dashboard = async () => {
  const d = await rpc('dashboard', { environment: env }); const s = d.stats; const max = Math.max(1, ...d.chart.map((c) => c.amount));
  const done = d.checklist.every((c) => c[1]);
  return `<h1>Dashboard</h1><p class="muted">${env === 'sandbox' ? 'Sandbox — test payments only.' : 'Your live payments at a glance.'}</p>
  <div class="grid g4" style="margin:14px 0"><div class="card"><div class="stat">${inr(s.today_amount)}</div><div class="stat-l">Today · ${s.today_count} payments</div></div><div class="card"><div class="stat">${inr(s.total_amount)}</div><div class="stat-l">Total · ${s.paid_count} paid</div></div><div class="card"><div class="stat">${s.pending_count}${s.review_count ? ` <small class="muted">+${s.review_count} review</small>` : ''}</div><div class="stat-l">Pending orders</div></div><div class="card"><div class="stat">${s.success_rate == null ? '—' : s.success_rate + '%'}</div><div class="stat-l">Success rate</div></div></div>
  <div class="card"><h2>Last 7 days · ${inr(d.week_total)}</h2><div class="bars" style="margin-bottom:22px">${d.chart.map((c) => `<div style="height:${Math.max(3, (c.amount / max) * 100)}%" title="${inr(c.amount)}"><span>${E(c.label)}</span></div>`).join('')}</div></div>
  ${done ? '' : `<div class="card"><h2>Get started</h2>${d.checklist.map(([l, ok, h]) => `<div>${ok ? '✅' : '⬜'} ${h && !ok ? `<a href="${h}">${E(l)}</a>` : E(l)}</div>`).join('')}</div>`}
  <div class="card"><h2>Recent orders</h2>${ordersTable(d.recent)}</div>`;
};
const ordersTable = (o) => table(['Order', 'Amount', 'Status', 'Account', 'Created'], o.map((x) => [`<a href="#/orders/${E(x.order_no)}">${E(x.order_no)}</a>`, inr(x.amount), pill(x.status), E(x.account_label || '—'), dt(x.created_at)]), 'No orders yet.');

pages.orders = async (arg, q) => {
  const page = Number(q.get('page') || 1), status = q.get('status') || '', s = q.get('q') || '';
  const d = await rpc('orders.list', { environment: env, page, status, q: s });
  return `<div class="top"><h1>Orders</h1><a class="btn" href="#/orders/new">+ New payment</a></div>
  <div class="tabs"><button class="${!status ? 'on' : ''}" data-st="">All</button>${Object.entries(d.counts).map(([k, v]) => `<button class="${status === k ? 'on' : ''}" data-st="${k}">${k} (${v})</button>`).join('')}</div>
  <form id="sf" class="row" style="margin-bottom:12px"><input class="input" name="q" value="${E(s)}" placeholder="Search order no, your order id or UTR" style="max-width:380px"><button class="btn btn-ghost" type="submit">Search</button></form>
  <div class="card">${ordersTable(d.orders)}${pager(d.page, d.pages)}</div>`;
};
pages.orders.after = (arg, q) => {
  on('[data-st]', 'click', (e) => { location.hash = `#/orders?status=${e.target.dataset.st}`; });
  on('[data-pg]', 'click', (e) => { q.set('page', e.target.dataset.pg); location.hash = '#/orders?' + q; });
  onSubmit('#sf', (d) => { location.hash = '#/orders?q=' + encodeURIComponent(d.q); });
};

pages['orders/new'] = async () => {
  const a = await rpc('accounts.list'); const act = a.accounts.filter((x) => x.is_active);
  return `<h1>New payment</h1><div class="card" style="max-width:520px"><form id="f">${field('amount', 'Amount (₹)', { type: 'number', req: 1, step: '0.01' })}${field('customer_name', 'Customer name (optional)')}${field('customer_mobile', 'Customer mobile (optional)')}${field('note', 'Note (optional)')}
  <label class="label">Pay to</label><select name="payment_account_id"><option value="">Automatic (${E(a.rotation)})</option>${act.map((x) => `<option value="${x.id}">${E(x.label)} — ${E(x.provider_label)}</option>`).join('')}</select><button class="btn" style="margin-top:16px" type="submit">Create payment</button></form></div>`;
};
pages['orders/new'].after = () => onSubmit('#f', async (d) => {
  const r = await rpc('orders.create', d); const m = modal(`<h2>Payment created</h2><p>Share this link with your customer:</p><p><code>${E(r.payment_url)}</code></p><div class="row"><button class="btn" id="c">Copy link</button><a class="btn btn-ghost" target="_blank" href="${E(r.payment_url)}">Open</a><a class="btn btn-ghost" href="#/orders/${E(r.order_no)}" id="v">View order</a></div>`);
  m.querySelector('#c').onclick = () => copy(r.payment_url); m.querySelector('#v').onclick = () => m.remove();
});

pages['orders/view'] = async (no) => {
  const d = await rpc('orders.get', { order_no: no }); const o = d.order;
  const act = !d.read_only ? `<div class="row" style="margin:10px 0">${['pending', 'review', 'expired'].includes(o.status) ? '<button class="btn btn-ghost btn-sm" id="re">Re-check</button>' : ''}${['pending', 'review', 'expired'].includes(o.status) && d.accepts_utr ? '<button class="btn btn-ghost btn-sm" id="mp">Mark as paid</button>' : ''}${o.status === 'review' ? '<button class="btn btn-sm" id="ap">Approve UTR</button><button class="btn btn-danger btn-sm" id="rj">Reject UTR</button>' : ''}${d.refundable > 0 ? '<button class="btn btn-ghost btn-sm" id="rf">Refund</button>' : ''}</div>` : '';
  return `<div class="top"><h1>Order ${E(o.order_no)} ${pill(o.status)}</h1><a href="#/orders">← Orders</a></div>
  <div class="card"><div class="grid g2"><div><div class="stat-l">Amount</div><div class="stat">${inr(o.amount)}</div>${o.adjusted ? `<div class="small muted">Customer pays ${inr(o.payable_amount)}</div>` : ''}</div><div>
  ${[['Your order id', o.client_order_id], ['Account', o.account_label], ['Customer', [o.customer_name, o.customer_mobile, o.customer_email].filter(Boolean).join(' · ')], ['Note', o.note], ['UTR', o.utr], ['Customer UTR', o.customer_utr], ['Verified by', o.verified_by], ['Created', dt(o.created_at)], ['Paid', o.paid_at ? dt(o.paid_at) : null], ['Expires', dt(o.expires_at)]].filter((x) => x[1]).map(([k, v]) => `<div class="small"><span class="muted">${k}:</span> ${E(v)}</div>`).join('')}</div></div>
  ${o.review_note ? `<div class="notice">${E(o.review_note)}</div>` : ''}${o.risk_flags.length ? `<div class="notice">Risk (${E(o.risk_level)}): ${o.risk_flags.map((f) => E(f.label)).join(', ')}</div>` : ''}${act}
  <div class="small">Payment link: <a href="${E(o.payment_url)}" target="_blank">${E(o.payment_url)}</a></div></div>
  ${d.refunds.length ? `<div class="card"><h2>Refunds</h2>${table(['Amount', 'Reason', 'When'], d.refunds.map((r) => [inr(r.amount), E(r.reason), dt(r.created_at)]))}</div>` : ''}
  <div class="card"><h2>Webhook deliveries</h2>${logsTable(d.logs, !d.read_only)}</div>
  ${d.provider_response ? `<div class="card"><h2>Provider response</h2><pre>${E(JSON.stringify(d.provider_response, null, 2))}</pre></div>` : ''}`;
};
const logsTable = (logs, resend, withOrder) => table([...(withOrder ? ['Order'] : []), 'Event', 'URL', 'Result', 'Attempt', 'When', ''], logs.map((l) => [...(withOrder ? [`<a href="#/orders/${E(l.order_no)}">${E(l.order_no)}</a>`] : []), E(l.event), `<span class="small">${E(l.url)}</span>`, l.success ? pill('paid').replace('paid', l.response_code || 'ok') : `<span class="pill pill-failed">${E(l.response_code || l.error_label || 'failed')}</span>`, l.attempt, dt(l.created_at), resend && l.id ? `<button class="btn btn-ghost btn-sm" data-rs="${l.id}">Resend</button>` : '']), 'No webhook deliveries yet.');
pages['orders/view'].after = (no) => {
  const act = (sel, fn) => { const el = $app.querySelector(sel); if (el) el.onclick = async () => { try { await fn(); } catch (e) { toast(e.message); } }; };
  const reload = () => route$();
  act('#re', async () => { const r = await rpc('orders.recheck', { order_no: no }); toast('Status: ' + r.status); reload(); });
  act('#mp', async () => { const u = prompt('Enter the bank UTR (12 digits):'); if (u) { await rpc('orders.markPaid', { order_no: no, utr: u }); reload(); } });
  act('#ap', async () => { const u = prompt('Type the UTR you see in your bank / UPI app:', ''); await rpc('orders.approveUtr', { order_no: no, bank_utr: u }); reload(); });
  act('#rj', async () => { const r = prompt('Reason for rejecting:'); if (r) { await rpc('orders.rejectUtr', { order_no: no, reason: r }); reload(); } });
  act('#rf', async () => { const a = prompt('Refund amount (₹):'); if (!a) return; const r = prompt('Reason:'); if (r) { await rpc('orders.refund', { order_no: no, amount: a, reason: r }); reload(); } });
  on('[data-rs]', 'click', async (e) => { try { await rpc('webhooks.resend', { id: e.target.dataset.rs }); toast('Resent'); reload(); } catch (x) { toast(x.message); } });
};

pages.links = async () => {
  const d = await rpc('links.list');
  return `<div class="top"><h1>Payment links</h1><button class="btn" id="nl">+ New link</button></div><p class="muted">Reusable pages you can share anywhere — WhatsApp, Instagram, email — no website needed.</p>
  <div class="card">${table(['Title', 'Amount', 'Link', 'Status', ''], d.links.map((l) => [E(l.title), l.amount ? inr(l.amount) : 'Any amount', `<a href="${E(l.url)}" target="_blank" class="small">${E(l.url)}</a>`, pill(l.is_active ? 'active' : 'paused'), `<button class="btn btn-ghost btn-sm" data-cp="${E(l.url)}">Copy</button> <button class="btn btn-ghost btn-sm" data-tg="${l.id}">${l.is_active ? 'Pause' : 'Enable'}</button> <button class="btn btn-ghost btn-sm" data-dl="${l.id}">Delete</button>`]), 'No links yet.')}</div>`;
};
pages.links.after = () => {
  on('[data-cp]', 'click', (e) => copy(e.target.dataset.cp));
  on('[data-tg]', 'click', async (e) => { await rpc('links.toggle', { id: e.target.dataset.tg }); route$(); });
  on('[data-dl]', 'click', async (e) => { if (confirm('Delete this link?')) { await rpc('links.delete', { id: e.target.dataset.dl }); route$(); } });
  const nl = document.getElementById('nl'); if (nl) nl.onclick = () => { const m = modal(`<h2>New payment link</h2><form id="lf">${field('title', 'Title', { req: 1 })}${field('description', 'Description')}${field('amount', 'Fixed amount (₹) — leave empty to let customers choose', { type: 'number', step: '0.01' })}${field('note', 'Note')}<button class="btn" style="margin-top:14px" type="submit">Create</button></form>`); const f = m.querySelector('#lf'); f.addEventListener('submit', async (ev) => { ev.preventDefault(); try { const d = formData(f); if (!d.amount) delete d.amount; await rpc('links.create', d); m.remove(); route$(); } catch (e) { showErr(f, e); } }); };
};

pages.subs = async () => {
  const [d, a] = await Promise.all([rpc('subs.list'), rpc('accounts.list')]);
  pages.subs.accounts = a.accounts;
  return `<div class="top"><h1>Subscriptions</h1><button class="btn" id="ns">+ New subscription</button></div><p class="muted">Recurring billing: an order is created when each payment falls due and the customer is notified by webhook.</p>
  <div class="card">${table(['Plan', 'Customer', 'Amount', 'Interval', 'Next due', 'Status', ''], d.subscriptions.map((s) => [E(s.plan_name), E(s.customer_name), inr(s.amount), E(s.interval), E(s.next_due_at || '—'), pill(s.status), `${s.status === 'active' ? `<button class="btn btn-ghost btn-sm" data-sb="${s.id}">Bill now</button> <button class="btn btn-ghost btn-sm" data-ss="paused:${s.id}">Pause</button>` : ''}${s.status === 'paused' ? `<button class="btn btn-ghost btn-sm" data-ss="active:${s.id}">Resume</button>` : ''}${s.status !== 'cancelled' ? ` <button class="btn btn-ghost btn-sm" data-ss="cancelled:${s.id}">Cancel</button>` : ''}`]), 'No subscriptions yet.')}</div>`;
};
pages.subs.after = () => {
  on('[data-sb]', 'click', async (e) => { try { const r = await rpc('subs.bill', { id: e.target.dataset.sb }); toast(r.order_no ? 'Order ' + r.order_no + ' created' : 'Billed'); route$(); } catch (x) { toast(x.message); } });
  on('[data-ss]', 'click', async (e) => { const [st, id] = e.target.dataset.ss.split(':'); await rpc('subs.update', { id, status: st }); route$(); });
  const ns = document.getElementById('ns'); if (ns) ns.onclick = () => {
    const m = modal(`<h2>New subscription</h2><form id="sf">${field('plan_name', 'Plan name', { req: 1 })}${field('amount', 'Amount (₹)', { type: 'number', step: '0.01', req: 1 })}<label class="label">Interval</label><select name="interval"><option value="monthly">Monthly</option><option value="weekly">Weekly</option><option value="yearly">Yearly</option></select>${field('next_due_at', 'First due date', { type: 'date', req: 1 })}${field('customer_name', 'Customer name', { req: 1 })}${field('customer_email', 'Customer email')}${field('customer_mobile', 'Customer mobile')}<button class="btn" style="margin-top:14px" type="submit">Create</button></form>`);
    const f = m.querySelector('#sf'); f.addEventListener('submit', async (ev) => { ev.preventDefault(); try { await rpc('subs.create', formData(f)); m.remove(); route$(); } catch (e) { showErr(f, e); } });
  };
};

const PF = { manual: '', fam_email: '', paytm_business: [['mid', 'Paytm MID']], paytm_pg: [['mid', 'Paytm MID'], ['merchant_key', 'Paytm Merchant Key'], ['website_name', 'Website name (optional)']], phonepe_pg: [['client_id', 'Client ID'], ['client_secret', 'Client Secret'], ['client_version', 'Client Version']], fampay_email: [['imap_username', 'Gmail address'], ['imap_password', 'Google App Password'], ['imap_sender', 'Sender to watch (optional)']] };
pages.accounts = async () => {
  const d = await rpc('accounts.list'); pages.accounts.data = d;
  return `<div class="top"><h1>UPI accounts</h1><button class="btn" id="na">+ Add account</button></div>
  <div class="card"><div class="row"><b>Rotation:</b><select id="rot" style="max-width:240px"><option value="random" ${d.rotation === 'random' ? 'selected' : ''}>Random among healthy accounts</option><option value="default" ${d.rotation === 'default' ? 'selected' : ''}>Always use default account</option></select></div></div>
  <div class="card">${table(['Label', 'Type', 'UPI ID', 'Today', 'Status', ''], d.accounts.map((a) => [`${E(a.label)}${a.is_default ? ' <span class="pill pill-active">default</span>' : ''}`, E(a.provider_label), E(a.upi_id || '—'), a.daily_limit ? `${inr(a.used_today)} / ${inr(a.daily_limit)}` : 'No limit', a.healthy ? pill(a.is_active ? 'active' : 'paused') : '<span class="pill pill-failed">unhealthy</span>', `${a.provider === 'fam_email' ? `<button class="btn btn-ghost btn-sm" data-es="${a.id}">Email setup</button> ` : ''}<button class="btn btn-ghost btn-sm" data-ac="toggle:${a.id}">${a.is_active ? 'Disable' : 'Enable'}</button> ${a.is_default ? '' : `<button class="btn btn-ghost btn-sm" data-ac="default:${a.id}">Make default</button> `}<button class="btn btn-ghost btn-sm" data-ac="del:${a.id}">Delete</button>`]), 'No accounts yet — add one to start accepting payments.')}</div>`;
};
pages.accounts.after = () => {
  const d = pages.accounts.data;
  document.getElementById('rot').onchange = async (e) => { await rpc('settings.rotation', { rotation_mode: e.target.value }); toast('Saved'); };
  on('[data-ac]', 'click', async (e) => { const [k, id] = e.target.dataset.ac.split(':'); const a = d.accounts.find((x) => x.id === id);
    try { if (k === 'del') { if (!confirm('Delete this account?')) return; await rpc('accounts.delete', { id }); } else if (k === 'toggle') await rpc('accounts.update', { id, action: 'toggle' }); else await rpc('accounts.update', { id, action: 'default' }); route$(); } catch (x) { toast(x.message); } });
  on('[data-es]', 'click', async (e) => { const s = await rpc('accounts.emailSetup', { id: e.target.dataset.es }); modal(`<h2>Email forwarding setup</h2><p class="small muted">Forward payment-confirmation emails from your payment app to your ingest webhook (e.g. via Cloudflare Email Worker / Apps Script) and sign each request with the secret.</p><p class="small">Ingest URL</p><code>${E(s.ingest_url)}</code><p class="small">Signing secret (HMAC-SHA256 of <code>timestamp.body</code> in <code>X-Signature</code>, timestamp in <code>X-Timestamp</code>)</p><code>${E(s.ingest_secret)}</code><p class="small muted">Expected sender: ${E(s.sender)}</p>`); });
  const na = document.getElementById('na'); if (na) na.onclick = () => {
    const m = modal(`<h2>Add UPI account</h2><form id="af"><label class="label">Type</label><select name="provider" id="pv">${d.providers.map((p) => `<option value="${p.id}">${E(p.label)}</option>`).join('')}</select>${field('label', 'Label (e.g. Shop Paytm)', { req: 1 })}${field('payee_name', 'Payee name shown to customer', { req: 1 })}<div id="ux">${field('upi_id', 'UPI ID', { ph: 'name@paytm' })}</div>${field('daily_limit', 'Daily limit ₹ (optional)', { type: 'number' })}<div id="ex"></div><button class="btn" style="margin-top:14px" type="submit">Add account</button></form>`);
    const f = m.querySelector('#af'), pv = f.querySelector('#pv');
    const draw = () => { const p = pv.value, hosted = d.providers.find((x) => x.id === p).hosted; f.querySelector('#ux').style.display = hosted ? 'none' : ''; f.querySelector('#ex').innerHTML = (hosted ? `<label class="label">Environment</label><select name="environment"><option value="sandbox">Sandbox (testing)</option><option value="production">Production</option></select>` : '') + (PF[p] || []).map(([k, l]) => field(k, l, { type: /secret|key|password/i.test(k) ? 'password' : 'text' })).join('') + (p === 'fampay_email' ? `<label class="label">Mail server</label><select name="imap_host">${d.imap_hosts.map((h) => `<option>${E(h)}</option>`).join('')}</select>` : ''); };
    pv.onchange = draw; draw();
    f.addEventListener('submit', async (ev) => { ev.preventDefault(); try { const r = await rpc('accounts.create', formData(f)); m.remove(); if (r.email_setup) { const s = await rpc('accounts.emailSetup', { id: r.id }); modal(`<h2>Email forwarding setup</h2><p class="small">Ingest URL</p><code>${E(s.ingest_url)}</code><p class="small">Signing secret (shown once here, also available later via “Email setup”)</p><code>${E(s.ingest_secret)}</code>`); } route$(); } catch (e) { showErr(f, e); } });
  };
};

pages.earnings = async (a, q) => {
  const days = Number(q.get('days') || 30), account = q.get('account') || '';
  const d = await rpc('earnings', { days, account: account || null });
  return `<h1>Earnings</h1><div class="row" style="margin:10px 0"><select id="dy" style="max-width:150px">${[7, 30, 90].map((x) => `<option ${x === d.days ? 'selected' : ''} value="${x}">Last ${x} days</option>`).join('')}</select><select id="ac" style="max-width:220px"><option value="">All accounts</option>${d.accounts.map((x) => `<option value="${x.id}" ${x.id === d.account ? 'selected' : ''}>${E(x.label)}</option>`).join('')}</select></div>
  <div class="grid g4"><div class="card"><div class="stat">${inr(d.total)}</div><div class="stat-l">Balance after ${d.days} days</div></div><div class="card"><div class="stat">${inr(d.opening)}</div><div class="stat-l">Opening balance</div></div></div>
  <div class="card">${table(['Date', 'Payments', 'Received', 'Refunded', 'Running total'], d.rows.map((r) => [E(r.date), r.count, inr(r.received), inr(r.refunded), inr(r.running)]))}</div>`;
};
pages.earnings.after = () => { const go = () => { location.hash = `#/earnings?days=${document.getElementById('dy').value}&account=${document.getElementById('ac').value}`; }; document.getElementById('dy').onchange = go; document.getElementById('ac').onchange = go; };

pages.reports = async (a, q) => {
  const d = await rpc('reports', { from: q.get('from') || '', to: q.get('to') || '' });
  return `<h1>Reports</h1><form id="rf" class="row" style="margin:10px 0"><input class="input" type="date" name="from" value="${d.from}" style="max-width:170px"><input class="input" type="date" name="to" value="${d.to}" style="max-width:170px"><button class="btn" type="submit">Apply</button><a class="btn btn-ghost" href="/rpc/reports.csv?from=${d.from}&to=${d.to}" download>Download CSV</a></form>
  <div class="grid g4"><div class="card"><div class="stat">${inr(d.paid_total)}</div><div class="stat-l">Collected · ${d.paid} paid</div></div><div class="card"><div class="stat">${d.orders}</div><div class="stat-l">Orders created</div></div><div class="card"><div class="stat">${d.conversion}%</div><div class="stat-l">Conversion</div></div><div class="card"><div class="stat">${inr(d.refunded)}</div><div class="stat-l">Refunded</div></div></div>
  <div class="grid g2"><div class="card"><h2>By account</h2>${table(['Account', 'Payments', 'Total'], Object.entries(d.by_account).map(([k, v]) => [E(k), v.count, inr(v.total)]))}</div><div class="card"><h2>By day</h2>${table(['Day', 'Payments', 'Total'], Object.entries(d.by_day).map(([k, v]) => [E(k), v.count, inr(v.total)]))}</div></div>`;
};
pages.reports.after = () => onSubmit('#rf', (d) => { location.hash = `#/reports?from=${d.from}&to=${d.to}`; });

pages.webhooks = async (a, q) => {
  const page = Number(q.get('page') || 1); const d = await rpc('webhooks.list', { page });
  pages.webhooks.q = q; return `<h1>Webhook log</h1><div class="card">${logsTable(d.logs, true, true)}${pager(d.page, d.pages)}</div>`;
};
pages.webhooks.after = (a, q) => { on('[data-pg]', 'click', (e) => { q.set('page', e.target.dataset.pg); location.hash = '#/webhooks?' + q; }); on('[data-rs]', 'click', async (e) => { try { await rpc('webhooks.resend', { id: e.target.dataset.rs }); toast('Resent'); route$(); } catch (x) { toast(x.message); } }); };

pages.settings = async () => {
  const s = await rpc('settings.get'); pages.settings.s = s;
  const keys = s.api_keys.filter((k) => !k.revoked_at);
  return `<h1>Settings & API keys</h1>
  <div class="card"><div class="top"><h2>API keys</h2><button class="btn btn-sm" id="nk">+ New key</button></div><p class="muted small">Each key has its own secret. The secret is shown only once when you create it. Sandbox keys (test_key_…) only create test orders.</p>
  ${table(['Name', 'Key', 'Env', 'Webhook', 'Last used', ''], keys.map((k) => [E(k.name), `<code>${E(k.key)}</code> <button class="btn btn-ghost btn-sm" data-cp="${E(k.key)}">Copy</button>`, pill(k.environment === 'sandbox' ? 'sandbox' : 'live'), E(k.webhook_url || 'account default'), k.last_used_at ? dt(k.last_used_at) : 'never', `<button class="btn btn-ghost btn-sm" data-kh="${k.id}">Webhook</button> <button class="btn btn-ghost btn-sm" data-kr="${k.id}">Revoke</button>`]), 'No API keys yet.')}</div>
  <div class="grid g2"><div class="card"><h2>Webhooks</h2><form id="wf"><p class="small muted">Live events are POSTed here, signed with your API secret (X-Signature).</p>${field('webhook_url', 'Live webhook URL', { v: s.webhook_url || '', ph: 'https://yoursite.com/emudra-webhook' })}<button class="btn btn-sm" style="margin:10px 0" type="submit">Save</button></form>
  <form id="sw">${field('sandbox_webhook_url', 'Sandbox webhook URL', { v: s.sandbox_webhook_url || '' })}<button class="btn btn-sm" style="margin-top:10px" type="submit">Save</button></form>
  <h3 style="margin-top:16px">Extra endpoints (${s.endpoints.length}/${s.max_extra_webhooks})</h3>${s.endpoints.map((e) => `<div class="row small"><code>${E(e.url)}</code> ${pill(e.environment === 'sandbox' ? 'sandbox' : 'live')} <button class="btn btn-ghost btn-sm" data-er="${e.id}">Remove</button></div>`).join('')}<form id="ef" class="row" style="margin-top:8px"><input class="input" name="url" placeholder="https://…" style="flex:1;min-width:180px"><select name="environment" style="width:110px"><option value="live">Live</option><option value="sandbox">Sandbox</option></select><button class="btn btn-ghost btn-sm" type="submit">Add</button></form></div>
  <div class="card"><h2>Profile</h2><form id="pf">${field('name', 'Name', { v: s.profile.name, req: 1 })}${field('business_name', 'Business name', { v: s.profile.business_name, req: 1 })}${field('phone', 'Phone', { v: s.profile.phone || '' })}<button class="btn btn-sm" style="margin-top:10px" type="submit">Save</button></form>
  <h3 style="margin-top:16px">Allowed redirect domains</h3><form id="rd"><textarea name="domains" rows="3" placeholder="shop.example.com (one per line)">${E(s.redirect_domains.join('\n'))}</textarea><p class="small muted">Customers are only redirected back to your site if its domain is listed here.</p><button class="btn btn-sm" type="submit">Save</button></form></div></div>
  <div class="card"><h2>Telegram alerts</h2><form id="tf"><div class="grid g2"><div>${field('telegram_bot_token', 'Bot token (from @BotFather)', { type: 'password', ph: s.telegram.has_token ? '•••••• saved — leave empty to keep' : '' })}</div><div>${field('telegram_chat_id', 'Chat ID', { v: s.telegram.chat_id || '' })}</div></div>
  <label class="row" style="margin:10px 0"><input type="checkbox" name="telegram_alerts" ${s.telegram.alerts ? 'checked' : ''}> Alert me on new payments, reviews and failures</label><label class="row"><input type="checkbox" name="daily_summary" ${s.telegram.daily_summary ? 'checked' : ''}> Send a daily summary each morning</label><div class="row" style="margin-top:12px"><button class="btn btn-sm" type="submit">Save</button><button class="btn btn-ghost btn-sm" type="button" id="tt">Send test</button></div></form></div>`;
};
pages.settings.after = () => {
  const s = pages.settings.s;
  on('[data-cp]', 'click', (e) => copy(e.target.dataset.cp));
  on('[data-kr]', 'click', async (e) => { if (confirm('Revoke this key? Apps using it will stop working.')) { await rpc('apikeys.revoke', { id: e.target.dataset.kr }); route$(); } });
  on('[data-kh]', 'click', (e) => { const k = s.api_keys.find((x) => x.id === e.target.dataset.kh); const m = modal(`<h2>Webhook for ${E(k.name)}</h2><form id="kf">${field('webhook_url', 'Webhook URL (empty = account default)', { v: k.webhook_url || '' })}<button class="btn" style="margin-top:12px" type="submit">Save</button></form>`); const f = m.querySelector('#kf'); f.addEventListener('submit', async (ev) => { ev.preventDefault(); try { await rpc('apikeys.update', { id: k.id, ...formData(f) }); m.remove(); route$(); } catch (x) { showErr(f, x); } }); });
  on('[data-er]', 'click', async (e) => { await rpc('settings.endpointRemove', { id: e.target.dataset.er }); route$(); });
  document.getElementById('nk').onclick = () => { const m = modal(`<h2>New API key</h2><form id="kf">${field('name', 'Name (e.g. Website, Mobile app)', { req: 1 })}<label class="label">Environment</label><select name="environment"><option value="live">Live</option><option value="sandbox">Sandbox</option></select>${field('webhook_url', 'Webhook URL (optional)')}<button class="btn" style="margin-top:12px" type="submit">Generate</button></form>`); const f = m.querySelector('#kf'); f.addEventListener('submit', async (ev) => { ev.preventDefault(); try { const k = await rpc('apikeys.create', formData(f)); m.querySelector('.card').innerHTML = `<h2>Save your secret now</h2><p class="notice">This secret is shown only once.</p><p class="small">Key</p><code>${E(k.key)}</code><p class="small">Secret</p><code>${E(k.secret)}</code><div class="row" style="margin-top:14px"><button class="btn" id="cs">Copy secret</button><button class="btn btn-ghost" id="dn">Done</button></div>`; m.querySelector('#cs').onclick = () => copy(k.secret); m.querySelector('#dn').onclick = () => { m.remove(); route$(); }; } catch (x) { showErr(f, x); } }); };
  onSubmit('#wf', async (d) => { await rpc('settings.webhook', d); toast('Saved'); });
  onSubmit('#sw', async (d) => { await rpc('settings.sandboxWebhook', d); toast('Saved'); });
  onSubmit('#ef', async (d) => { await rpc('settings.endpointAdd', d); route$(); });
  onSubmit('#pf', async (d) => { await rpc('settings.profile', d); toast('Saved'); });
  onSubmit('#rd', async (d) => { await rpc('settings.redirectDomains', { redirect_domains: d.domains }); toast('Saved'); });
  onSubmit('#tf', async (d) => { await rpc('settings.alerts', d); toast('Saved'); });
  document.getElementById('tt').onclick = async () => { try { await rpc('settings.alertsTest'); toast('Test sent'); } catch (e) { toast(e.message); } };
};

pages.password = async () => `<h1>Change password</h1><div class="card" style="max-width:420px"><form id="f">${field('current_password', 'Current password', { type: 'password', req: 1 })}${field('password', 'New password', { type: 'password', req: 1 })}${field('password_confirmation', 'Confirm new password', { type: 'password', req: 1 })}<button class="btn" style="margin-top:14px" type="submit">Update password</button></form></div>`;
pages.password.after = () => onSubmit('#f', async (d, f) => { await rpc('password.change', d); f.reset(); toast('Password updated'); });

pages.admin = async (a, q) => {
  if (me.user.role !== 'admin') return '<h1>Forbidden</h1>';
  const [m, o] = await Promise.all([rpc('admin.merchants'), rpc('admin.orders', { page: Number(q.get('page') || 1), status: q.get('status') || '', q: q.get('q') || '' })]);
  return `<h1>Admin</h1><div class="card"><h2>Merchants</h2>${table(['Business', 'Email', 'Role', 'Orders', 'Status', ''], m.merchants.map((x) => [E(x.business_name || x.name), E(x.email), E(x.role), x.orders ?? '', pill(x.is_active ? 'active' : 'cancelled'), `<button class="btn btn-ghost btn-sm" data-mt="${x.id}">${x.is_active ? 'Disable' : 'Enable'}</button>`]))}</div>
  <div class="card"><h2>All live orders</h2><form id="dbg" class="row" style="margin-bottom:10px"><input class="input" name="order_no" placeholder="Order no to debug" style="max-width:260px"><button class="btn btn-ghost btn-sm" type="submit">Debug</button></form>${table(['Order', 'Merchant', 'Amount', 'Status', 'Created'], o.orders.map((x) => [E(x.order_no), E(x.merchant || ''), inr(x.amount), pill(x.status), dt(x.created_at)]))}${pager(o.page, o.pages)}</div>`;
};
pages.admin.after = (a, q) => {
  on('[data-mt]', 'click', async (e) => { try { await rpc('admin.toggle', { id: e.target.dataset.mt }); route$(); } catch (x) { toast(x.message); } });
  on('[data-pg]', 'click', (e) => { q.set('page', e.target.dataset.pg); location.hash = '#/admin?' + q; });
  onSubmit('#dbg', async (d) => { const r = await rpc('admin.debug', d); modal(`<h2>${E(r.order.order_no)} · ${pill(r.order.status)}</h2><p class="small">Merchant: ${E(r.merchant)}</p><pre>${E(JSON.stringify({ order: r.order, provider_response: r.provider_response, logs: r.logs, emails: r.emails }, null, 2))}</pre>`); });
};

pages.docs = async () => {
  const base = me.base_url || location.origin;
  return `<h1>API documentation</h1>
  <div class="card"><h2>Authentication</h2><p>Send your <code>X-API-Key</code> (and the secret as <code>X-API-Secret</code>) on every request. Use <code>test_key_…</code> keys for the sandbox.</p></div>
  <div class="card"><h2>Create an order</h2><pre>curl -X POST ${E(base)}/api/v1/orders \\
  -H "X-API-Key: key_xxx" -H "X-API-Secret: sec_xxx" \\
  -H "Content-Type: application/json" \\
  -d '{"amount": 499.00, "client_order_id": "INV-1001",
       "customer_name": "Asha", "customer_mobile": "9999999999",
       "redirect_url": "https://shop.example.com/thanks"}'</pre><p class="small muted">Response includes <code>order_id</code>, <code>payment_url</code>, <code>payable_amount</code> and <code>status</code>. Redirect the customer to <code>payment_url</code>. Re-sending the same <code>client_order_id</code> returns the same order (idempotent).</p></div>
  <div class="card"><h2>Get an order</h2><pre>GET ${E(base)}/api/v1/orders/{order_id}</pre></div>
  <div class="card"><h2>Webhooks</h2><p>We POST JSON to your webhook URL with headers <code>X-Event</code> (<code>order.paid</code>, <code>order.expired</code>, <code>order.refunded</code>…) and <code>X-Signature</code> = HMAC-SHA256 of the raw body using your API secret. Reply with HTTP 2xx; failures are retried with backoff.</p><pre>// Node
const sig = crypto.createHmac('sha256', SECRET).update(rawBody).digest('hex');
if (sig !== req.headers['x-signature']) return res.sendStatus(401);</pre></div>
  <div class="card"><h2>Plugins</h2><p><a href="/integrations/woocommerce">WooCommerce plugin</a> · <a href="/integrations/php">PHP client</a> · <a href="/integrations/node">Node client</a></p></div>`;
};

// ------------------------------------------------------------------ router
async function boot() { try { me = await rpc('me'); } catch { me = null; } }
async function route$() {
  const raw = location.hash.replace(/^#\/?/, '') || 'dashboard'; const [path, qs] = raw.split('?'); const q = new URLSearchParams(qs || '');
  if (!me) { if (path === 'register') return authView('register'); return authView('login'); }
  if (path === 'login' || path === 'register') { location.hash = '#/dashboard'; return; }
  let key = path, arg = null;
  if (path.startsWith('orders/') && path !== 'orders/new') { key = 'orders/view'; arg = path.slice(7); }
  const page = pages[key] || pages.dashboard;
  const nav = key === 'orders/view' ? 'orders' : key;
  shell(nav, '<p class="muted">Loading…</p>');
  try { document.getElementById('view').innerHTML = await page(arg, q); page.after?.(arg, q); } catch (e) { document.getElementById('view').innerHTML = `<div class="alert alert-error">${E(e.message)}</div>`; }
}
addEventListener('hashchange', route$);
await boot(); route$();
