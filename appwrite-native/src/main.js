import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as db from './db.js';
import { Query } from './db.js';
import * as core from './core.js';
import * as api from './api.js';
import * as pages from './pages.js';
import { ensureSchema } from './schema.js';
import { ValidationError, fail, hmac, safeEqual, money, istDayStart, istDate, addDays, telegramSend, BRAND, randomId } from './util.js';

const PUBLIC = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'public');
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon', '.json': 'application/json', '.php': 'text/plain; charset=utf-8', '.txt': 'text/plain; charset=utf-8' };
const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; form-action 'self' https://securestage.paytmpayments.com https://secure.paytmpayments.com https://api-preprod.phonepe.com https://api.phonepe.com https://mercury-t2.phonepe.com https://mercury.phonepe.com; frame-ancestors 'none'; base-uri 'self'; object-src 'none'";
const SEC = { 'x-content-type-options': 'nosniff', 'x-frame-options': 'DENY', 'referrer-policy': 'strict-origin-when-cross-origin', 'strict-transport-security': 'max-age=31536000; includeSubDomains', 'permissions-policy': 'camera=(), microphone=(), geolocation=()' };

// ---- tiny in-memory rate limiter (per warm container; best effort) ----
const buckets = new Map();
function limit(key, max, windowMs = 60000) {
  const t = Date.now(); const b = buckets.get(key);
  if (!b || b.reset < t) { buckets.set(key, { n: 1, reset: t + windowMs }); return; }
  if (++b.n > max) throw new ValidationError('Too many requests. Please slow down.', 429);
  if (buckets.size > 5000) for (const [k, v] of buckets) if (v.reset < t) buckets.delete(k);
}

const clientIp = (req) => String(req.headers['x-appwrite-client-ip'] || req.headers['x-forwarded-for'] || '').split(',')[0].trim() || 'unknown';
const formBody = (req) => Object.fromEntries(new URLSearchParams(String(req.bodyRaw || '')));
const jsonBody = (req) => { try { return req.bodyJson ?? JSON.parse(req.bodyRaw || '{}'); } catch { throw new ValidationError('Invalid JSON body.', 400); } };

function html(res, body, status = 200, extra = {}) { return res.send(body, status, { 'content-type': 'text/html; charset=utf-8', 'content-security-policy': CSP, 'cache-control': 'no-store', ...SEC, ...extra }); }
function json(res, body, status = 200, extra = {}) { return res.send(JSON.stringify(body), status, { 'content-type': 'application/json', 'cache-control': 'no-store', ...SEC, ...extra }); }
const redirect = (res, url, status = 303, extra = {}) => res.send('', status, { location: url, ...SEC, ...extra });

function staticFile(res, rel) {
  const file = path.normalize(path.join(PUBLIC, rel));
  if (!file.startsWith(PUBLIC + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return null;
  const ext = path.extname(file);
  const immutable = /\.(css|js|png|ico|svg)$/.test(ext);
  return res.binary(fs.readFileSync(file), 200, { 'content-type': MIME[ext] || 'application/octet-stream', 'cache-control': immutable ? 'public, max-age=300' : 'no-cache', ...SEC, ...(ext === '.html' ? { 'content-security-policy': CSP } : {}) });
}

// ================================================================ public merchant API (/api/v1)
async function apiKeyAuth(req) {
  const key = String(req.headers['x-api-key'] || ''); const secret = String(req.headers['x-api-secret'] || '');
  const k = key ? await db.first('apikeys', [Query.equal('key', key), Query.isNull('revokedAt')]) : null;
  if (!k || !secret || !safeEqual(k.secret || '', secret)) throw new ValidationError('Invalid API key or secret.', 401);
  const merchant = await core.getProfile(k.userId);
  if (!merchant || merchant.isActive === false) throw new ValidationError('Merchant account is disabled.', 403);
  limit(`key:${k.id}`, 120); limit(`ip:${clientIp(req)}`, 600);
  if (!k.lastUsedAt || Date.now() - new Date(k.lastUsedAt).getTime() > 60000) db.update('apikeys', k.id, { lastUsedAt: db.iso(), lastUsedIp: clientIp(req) }).catch(() => {});
  return { key: k, merchant, environment: k.environment };
}

function redirectAllowed(merchant, url) {
  const allowed = core.parseJson(merchant.redirectDomains, []) || [];
  let host; try { host = new URL(url).hostname.toLowerCase(); } catch { return false; }
  return allowed.some((d) => host === d || host.endsWith(`.${d}`));
}

async function publicApi(ctx, p) {
  const { req, res } = ctx;
  const auth = await apiKeyAuth(req);
  const { merchant, environment, key } = auth;
  const m = req.method;

  if (p === '/api/v1/accounts' && m === 'GET') {
    const accounts = await core.listAccounts(merchant.id);
    return json(res, { success: true, data: accounts.filter((a) => a.isActive).map((a) => ({ id: a.id, label: a.label, provider: a.provider, upi_id: a.upiId, is_default: a.isDefault })) });
  }

  if (p === '/api/v1/orders' && m === 'POST') {
    const b = jsonBody(req); const errors = {};
    const amount = Number(b.amount);
    if (b.amount == null || !Number.isFinite(amount) || amount < 1 || amount > 100000 || !/^\d+(\.\d{1,2})?$/.test(String(b.amount))) errors.amount = 'amount must be between 1 and 100000 with at most 2 decimals.';
    if (!b.client_order_id || !/^[A-Za-z0-9_-]{1,64}$/.test(String(b.client_order_id))) errors.client_order_id = 'client_order_id is required (letters, numbers, - and _, max 64).';
    if (environment === 'sandbox' && b.payment_account_id) errors.payment_account_id = 'payment_account_id is not allowed for sandbox keys.';
    if (b.customer_email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(String(b.customer_email))) errors.customer_email = 'customer_email is invalid.';
    for (const [f, max] of [['customer_name', 100], ['customer_mobile', 20], ['note', 50]]) if (b[f] != null && String(b[f]).length > max) errors[f] = `${f} is too long.`;
    if (b.redirect_url) {
      if (!/^https:\/\//i.test(String(b.redirect_url)) || String(b.redirect_url).length > 500) errors.redirect_url = 'redirect_url must be an https URL.';
      else if (!(core.parseJson(merchant.redirectDomains, []) || []).length) errors.redirect_url = 'Redirect URLs are not enabled for your account. Add allowed domains in Settings.';
      else if (!redirectAllowed(merchant, b.redirect_url)) errors.redirect_url = 'The redirect URL domain is not in your allowed domains list.';
    }
    if (Object.keys(errors).length) throw new ValidationError(errors);
    const order = await core.createOrder(merchant, { ...b, amount, created_ip: clientIp(req), api_key_id: key.id }, environment);
    return json(res, { success: true, data: core.toApi(order, await core.getAccount(order.accountId)) }, 201);
  }

  const mt = p.match(/^\/api\/v1\/orders\/([^/]+)(\/simulate)?$/);
  if (mt) {
    const order = await core.getOrderFor(merchant.id, decodeURIComponent(mt[1]), environment);
    if (!order) return json(res, { message: 'Not found.' }, 404);
    if (mt[2] && m === 'POST') {
      if (environment !== 'sandbox') return json(res, { success: false, message: 'Simulation is only available with sandbox (test) API keys.' }, 403);
      const outcome = jsonBody(req).outcome || 'success';
      if (!['success', 'failed', 'pending', 'expired', 'timeout'].includes(outcome)) fail('outcome', 'Invalid outcome.');
      const o = await core.simulateSandbox(order, outcome);
      return json(res, { success: true, data: core.toApi(o, null) });
    }
    if (!mt[2] && m === 'GET') {
      const o = await core.checkOrder(order);
      return json(res, { success: true, data: core.toApi(o, await core.getAccount(o.accountId)) });
    }
  }
  return json(res, { message: 'Not found.' }, 404);
}

// ================================================================ e-mail ingest
async function emailIngest(ctx, accountId) {
  const { req, res } = ctx;
  limit(`ingest:${accountId}`, 60);
  const account = await core.getAccount(accountId);
  const secret = String(account?.credentials?.ingest_secret || '');
  const ts = String(req.headers['x-timestamp'] || ''); const sig = String(req.headers['x-signature'] || '').toLowerCase();
  const raw = String(req.bodyRaw || '');
  const key = secret || 'no-secret';
  const fresh = /^\d+$/.test(ts) && Math.abs(Date.now() / 1000 - Number(ts)) <= core.CFG.fam.sigToleranceSeconds;
  const ok = account && account.provider === 'fam_email' && account.isActive && secret && fresh
    && (safeEqual(hmac(key, `${ts}.${raw}`), sig) || safeEqual(hmac(key, `${ts}.${Buffer.from(raw, 'utf8').toString('latin1')}`), sig));
  if (!ok) { console.error('email-ingest rejected', accountId); return json(res, { message: 'Unauthorized.' }, 401); }
  const b = jsonBody(req); const errors = {};
  for (const [f, max] of [['message_id', 255], ['from', 255], ['subject', 300], ['body', 20000]]) if (!b[f] || String(b[f]).length > max) errors[f] = `${f} is required (max ${max}).`;
  if (!b.received_at) errors.received_at = 'received_at is required.';
  if (Object.keys(errors).length) throw new ValidationError(errors);
  return json(res, await core.ingestEmail(account, b));
}

// ================================================================ checkout pages
const loadLive = async (orderNo) => {
  const order = await core.getOrderByNo(orderNo);
  if (!order || order.environment !== 'live') return null;
  return order;
};
async function redirectAfter(order) {
  if (!order.redirectUrl) return null;
  const m = await core.getProfile(order.userId);
  if (!m || !redirectAllowed(m, order.redirectUrl) || !/^https:\/\//i.test(order.redirectUrl)) return null;
  const sep = order.redirectUrl.includes('?') ? '&' : '?';
  return `${order.redirectUrl}${sep}${new URLSearchParams({ order_id: order.orderNo, client_order_id: order.clientOrderId, status: order.status })}`;
}

async function checkoutRoutes(ctx, p) {
  const { req, res } = ctx; const m = req.method;
  const mt = p.match(/^\/pay\/([A-Za-z0-9]+)(?:\/(status|start|return|utr|receipt|qr\.svg))?$/);
  if (!mt) return null;
  limit(`pay:${clientIp(req)}`, 120);
  let order = await loadLive(mt[1]);
  if (!order) return html(res, pages.notFoundPage(), 404);
  const sub = mt[2] || '';
  const merchant = await core.getProfile(order.userId);
  const account = await core.getAccount(order.accountId);

  if (sub === '' && m === 'GET') {
    if (order.status === 'pending' && (!order.expiresAt || core.isPastExpiry(order))) order = await core.checkOrder(order);
    const q = new URLSearchParams(req.queryString || '');
    return html(res, await pages.checkoutPage(order, account, merchant, { error: q.get('e') }));
  }
  if (sub === 'status' && m === 'GET') {
    order = await core.checkOrder(order);
    const to = ['paid', 'expired'].includes(order.status) ? await redirectAfter(order) : null;
    return json(res, { status: order.status, redirect: to });
  }
  if (sub === 'start' && m === 'POST') {
    limit(`start:${clientIp(req)}`, 20);
    if (!core.isHosted(account)) return html(res, pages.notFoundPage(), 404);
    try {
      const checkout = await core.startCheckout(order, account);
      if (!checkout) return redirect(res, `/pay/${order.orderNo}`);
      return checkout.type === 'redirect' ? redirect(res, checkout.url) : html(res, pages.payRedirectPage(checkout));
    } catch (e) { return redirect(res, `/pay/${order.orderNo}?e=${encodeURIComponent(e.message)}`); }
  }
  if (sub === 'return') {
    order = await core.checkOrder(order, true);
    if (order.status === 'paid') { const to = await redirectAfter(order); if (to) return redirect(res, to); }
    return redirect(res, `/pay/${order.orderNo}`);
  }
  if (sub === 'utr' && m === 'POST') {
    limit(`utr:${clientIp(req)}`, 10);
    const utr = String(formBody(req).utr || '');
    try {
      if (!/^\s*\d{12}\s*$/.test(utr)) fail('utr', 'UTR must be the 12-digit number shown in your UPI app after payment.');
      const o = await core.submitCustomerUtr(order, utr);
      await core.reconcileSubmittedUtr(o);
    } catch (e) { if (e instanceof ValidationError) return redirect(res, `/pay/${order.orderNo}?e=${encodeURIComponent(e.message)}`); throw e; }
    return redirect(res, `/pay/${order.orderNo}`);
  }
  if (sub === 'receipt' && m === 'GET') {
    if (order.status !== 'paid') return html(res, pages.notFoundPage(), 404);
    return html(res, pages.receiptPage(order, account, merchant));
  }
  if (sub === 'qr.svg' && m === 'GET') {
    if (order.status !== 'pending' || !account || core.isHosted(account)) return html(res, pages.notFoundPage(), 404);
    return res.send(await pages.qrSvg(core.upiIntentUrl(order, account)), 200, { 'content-type': 'image/svg+xml', 'cache-control': 'private, max-age=600', ...SEC });
  }
  return html(res, pages.notFoundPage(), 404);
}

async function linkRoutes(ctx, p) {
  const { req, res } = ctx;
  const mt = p.match(/^\/p\/([A-Za-z0-9_]+)$/); if (!mt) return null;
  limit(`link:${clientIp(req)}`, 60);
  const link = await db.get('links', mt[1]);
  const merchant = link ? await core.getProfile(link.userId) : null;
  if (!link || !link.isActive || !merchant || merchant.isActive === false) return html(res, pages.notFoundPage(), 404);
  if (req.method === 'GET') return html(res, pages.linkPage(link, merchant));
  limit(`linkpay:${clientIp(req)}`, 10);
  const f = formBody(req);
  try {
    const amount = link.amount != null ? Number(link.amount) : Number(f.amount);
    if (!Number.isFinite(amount) || amount < 1 || amount > 100000) fail('amount', 'Enter an amount between ₹1 and ₹100000.');
    const order = await core.createOrder(merchant, {
      amount, client_order_id: `LNK${randomId(14)}`, payment_account_id: link.accountId || null, customer_name: (f.customer_name || '').slice(0, 100) || null,
      customer_mobile: (f.customer_mobile || '').slice(0, 20) || null, note: link.note || link.title.slice(0, 50), link_id: link.id, created_ip: clientIp(req),
    });
    return redirect(res, `/pay/${order.orderNo}`);
  } catch (e) { if (e instanceof ValidationError) return html(res, pages.linkPage(link, merchant, e.message), 422); throw e; }
}

async function sandboxRoutes(ctx, p) {
  const { req, res } = ctx;
  const mt = p.match(/^\/sandbox\/pay\/([A-Za-z0-9]+)(\/simulate)?$/); if (!mt) return null;
  limit(`sbx:${clientIp(req)}`, 60);
  const order = await core.getOrderByNo(mt[1]);
  if (!order || order.environment !== 'sandbox') return html(res, pages.notFoundPage(), 404);
  const merchant = await core.getProfile(order.userId);
  if (!mt[2]) return html(res, pages.sandboxPage(await core.checkOrder(order), merchant));
  try { const o = await core.simulateSandbox(order, formBody(req).outcome); return html(res, pages.sandboxPage(o, merchant, { done: `Simulated: ${o.status}` })); }
  catch (e) { if (e instanceof ValidationError) return html(res, pages.sandboxPage(order, merchant, { error: e.message }), 422); throw e; }
}

// ================================================================ dashboard RPC
async function rpc(ctx, p) {
  const { req, res } = ctx;
  const name = p.slice('/rpc/'.length);
  if (req.method === 'POST' && !String(req.headers['content-type'] || '').includes('application/json')) throw new ValidationError('Unsupported content type.', 415);
  const u = await api.currentUser(req);
  if (!u) return json(res, { ok: false, error: 'Not signed in.' }, 401);
  if (u.isActive === false) return json(res, { ok: false, error: 'This account has been disabled.' }, 403);
  limit(`rpc:${u.id}`, 240);
  if (name === 'reports.csv') {
    const csv = await api.reportCsv(u, Object.fromEntries(new URLSearchParams(req.queryString || '')));
    return res.send(csv, 200, { 'content-type': 'text/csv; charset=utf-8', 'content-disposition': 'attachment; filename="orders.csv"', ...SEC });
  }
  const handler = api.handlers[name];
  if (!handler) return json(res, { ok: false, error: 'Unknown action.' }, 404);
  const body = req.method === 'POST' ? jsonBody(req) : Object.fromEntries(new URLSearchParams(req.queryString || ''));
  return json(res, { ok: true, data: await handler(u, body) });
}

// ================================================================ scheduled work
async function runScheduled() {
  const out = {};
  out.webhookRetries = await core.processWebhookRetries(25);

  const expired = await db.all('orders', [Query.equal('status', 'pending'), Query.lessThan('expiresAt', db.iso())], 200);
  for (const o of expired) await core.checkOrder(o, true).catch((e) => console.error('expire failed', o.orderNo, e.message));
  out.expired = expired.length;

  const open = await db.all('orders', [Query.equal('status', 'pending'), Query.equal('environment', 'live'), Query.greaterThan('expiresAt', db.iso())], 100);
  for (const o of open) await core.checkOrder(o, true).catch(() => {});
  out.checked = open.length;

  const due = await db.all('subscriptions', [Query.equal('status', 'active'), Query.lessThanEqual('nextDueAt', db.iso(new Date(istDayStart().getTime() + 86400000 - 1)))], 100);
  let billed = 0;
  for (const s of due) { if ((await core.billSubscription(s).catch(() => null))) billed++; }
  out.billed = billed;

  // Daily summary for yesterday, once per day, from 08:00 IST.
  const istHour = new Date(Date.now() + 330 * 60000).getUTCHours();
  if (istHour >= 8) {
    const profiles = await db.all('profiles', [Query.equal('dailySummary', true), Query.equal('isActive', true)], 500);
    const day = istDayStart();
    let sent = 0;
    for (const p of profiles) {
      if (p.lastSummaryAt && new Date(p.lastSummaryAt) >= day) continue;
      const s = await core.summary(p.id, addDays(day, -1), new Date(day.getTime() - 1));
      const text = `📊 ${BRAND} summary for ${istDate(addDays(day, -1))}\nOrders: ${s.orders} · Paid: ${s.paid} (₹${money(s.paid_total)})\nPending: ${s.pending} · Review: ${s.review} · Expired: ${s.expired}\nConversion: ${s.conversion}%${s.refunded ? `\nRefunded: ₹${money(s.refunded)}` : ''}`;
      if (await telegramSend(p, text)) sent++;
      await db.update('profiles', p.id, { lastSummaryAt: db.iso() });
    }
    out.summaries = sent;
  }
  return out;
}

async function setup(ctx) {
  const token = process.env.SETUP_TOKEN;
  if (!token || !safeEqual(String(ctx.req.headers['x-setup-token'] || ''), token)) throw new ValidationError('Forbidden', 403);
  const started = Date.now(); let last;
  do { last = await ensureSchema(); if (last.ready) break; await new Promise((r) => setTimeout(r, 4000)); } while (Date.now() - started < 80000);
  return json(ctx.res, last);
}

// ================================================================ entrypoint
export default async function handler(context) {
  const { req, res } = context;
  const ctx = { req, res };
  try {
    db.initClients(req);
    const p = (req.path || '/').replace(/\/+$/, '') || '/';
    const m = req.method;

    if (String(req.headers['x-appwrite-trigger'] || '') === 'schedule') return json(res, { ok: true, ...(await runScheduled()) });
    if (p === '/__setup') return await setup(ctx);
    if (p === '/__tick') { const t = process.env.SETUP_TOKEN; if (t && safeEqual(String(req.headers['x-setup-token'] || ''), t)) return json(res, await runScheduled()); }
    if (p === '/up') return json(res, { ok: true });

    let out = null;
    if (p === '/' || p === '/app' || p === '/docs' || p === '/login' || p === '/register') out = p === '/docs' ? redirect(res, '/#/docs', 302) : p === '/login' ? redirect(res, '/#/login', 302) : p === '/register' ? redirect(res, '/#/register', 302) : staticFile(res, 'index.html');
    else if (p.startsWith('/assets/')) out = staticFile(res, p.slice(8));
    else if (p.startsWith('/integrations/')) {
      const f = { woocommerce: 'emudra-gateway.php', php: 'EMudra.php', node: 'emudra.js' }[p.split('/')[2]];
      out = f ? staticFile(res, `integrations/${f}`) : null;
    }
    else if (p.startsWith('/api/v1/')) out = await publicApi(ctx, p);
    else if (p.startsWith('/api/email-ingest/') && m === 'POST') out = await emailIngest(ctx, p.split('/')[3]);
    else if (p.startsWith('/auth/') && m === 'POST') {
      limit(`auth:${clientIp(req)}`, 20);
      if (!String(req.headers['x-requested-with'] || '')) throw new ValidationError('Bad request.', 400);
      if (p === '/auth/logout') { await api.logout(req); out = json(res, { ok: true }, 200, { 'set-cookie': api.sessionCookie('', 0) }); }
      else if (p === '/auth/login' || p === '/auth/register') {
        const s = await (p === '/auth/login' ? api.login : api.register)(jsonBody(req));
        out = json(res, { ok: true }, 200, { 'set-cookie': api.sessionCookie(s.secret) });
      }
    }
    else if (p.startsWith('/rpc/')) {
      if (!String(req.headers['x-requested-with'] || '')) throw new ValidationError('Bad request.', 400);
      out = await rpc(ctx, p);
    }
    else if (p.startsWith('/pay/')) out = await checkoutRoutes(ctx, p);
    else if (p.startsWith('/p/')) out = await linkRoutes(ctx, p);
    else if (p.startsWith('/sandbox/pay/')) out = await sandboxRoutes(ctx, p);

    await core.flush();
    if (out) { core.processWebhookRetries(3).then(() => core.flush()).catch(() => {}); return out; }
    return req.headers.accept?.includes('text/html') ? html(res, pages.notFoundPage(), 404) : json(res, { message: 'Not found.' }, 404);
  } catch (e) {
    await core.flush().catch(() => {});
    const wantsJson = (context.req.path || '').match(/^\/(api|rpc|auth)\//);
    if (e instanceof ValidationError) {
      if (wantsJson) return json(res, { ok: false, success: false, error: e.message, message: e.message, errors: e.errors }, e.status);
      return html(res, pages.errorPage(e.message), e.status);
    }
    console.error(e?.stack || e);
    if (wantsJson) return json(res, { ok: false, success: false, error: 'Server error.', message: 'Server error.' }, 500);
    return html(res, pages.errorPage('Something went wrong. Please try again.'), 500);
  }
}
