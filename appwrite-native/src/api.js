// Dashboard JSON API ("/rpc/<name>"), authenticated with an Appwrite Auth session cookie.
import { Client, Account, ID } from 'node-appwrite';
import * as db from './db.js';
import { Query } from './db.js';
import * as core from './core.js';
import { PROVIDERS, IMAP_HOSTS } from './verifiers.js';
import {
  ValidationError, fail, randomToken, hmac, istDayStart, istDate, addDays, money, validateWebhookUrl, telegramSend, TELEGRAM_TOKEN, TELEGRAM_CHAT, RISK_FLAGS, BRAND, normalizeUtr,
} from './util.js';

export const COOKIE = 'emudra_session';
const MAX_KEYS = 10;

const newClient = () => new Client().setEndpoint(db.endpoint()).setProject(db.projectId());

export function cookieOf(req, name = COOKIE) {
  for (const part of String(req.headers.cookie || '').split(';')) {
    const [k, ...v] = part.trim().split('=');
    if (k === name) return decodeURIComponent(v.join('='));
  }
  return null;
}
export const sessionCookie = (secret, maxAge = 60 * 60 * 24 * 14) => `${COOKIE}=${encodeURIComponent(secret)}; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=${maxAge}`;

export async function currentUser(req) {
  const secret = cookieOf(req);
  if (!secret) return null;
  try {
    const au = await new Account(newClient().setSession(secret)).get();
    let profile = await core.getProfile(au.$id);
    if (!profile) profile = await createProfile(au, {});
    return { ...profile, sessionSecret: secret };
  } catch { return null; }
}

async function createProfile(au, extra) {
  const first = (await db.count('profiles')) === 0;
  const profile = await db.create('profiles', {
    email: au.email, name: au.name || au.email.split('@')[0], role: first ? 'admin' : 'merchant', businessName: extra.business_name || au.name || null, phone: extra.phone || null,
    isActive: true, rotationMode: 'random', telegramAlerts: false, dailySummary: false,
  }, au.$id);
  if (first) await db.users().updateLabels({ userId: au.$id, labels: ['admin'] }).catch(() => {});
  return profile;
}

// ---------------------------------------------------------------- auth endpoints
export async function register(body) {
  const errors = {};
  const name = String(body.name || '').trim(); const business = String(body.business_name || '').trim();
  const email = String(body.email || '').trim().toLowerCase(); const password = String(body.password || '');
  if (!name || name.length > 100) errors.name = 'Enter your name.';
  if (!business || business.length > 100) errors.business_name = 'Enter your business name.';
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) errors.email = 'Enter a valid email.';
  if (password.length < 8) errors.password = 'Password must be at least 8 characters.';
  if (body.password_confirmation !== undefined && body.password_confirmation !== password) errors.password_confirmation = 'Passwords do not match.';
  if (Object.keys(errors).length) throw new ValidationError(errors);
  let au;
  try { au = await db.users().create({ userId: ID.unique(), email, password, name }); }
  catch (e) {
    if (e.code === 409) fail('email', 'An account with this email already exists.');
    if (e.code === 400) fail('password', e.message);
    throw e;
  }
  await createProfile(au, { business_name: business, phone: body.phone });
  return login({ email, password });
}

export async function login(body) {
  const email = String(body.email || '').trim().toLowerCase();
  let session;
  try { session = await new Account(newClient()).createEmailPasswordSession({ email, password: String(body.password || '') }); }
  catch (e) { if (e.code === 401 || e.code === 400) fail('email', 'These credentials do not match our records.'); if (e.code === 429) throw new ValidationError('Too many attempts. Try again later.', 429); throw e; }
  const profile = await core.getProfile(session.userId);
  if (profile && profile.isActive === false) { await new Account(newClient().setSession(session.secret)).deleteSession({ sessionId: 'current' }).catch(() => {}); fail('email', 'This account has been disabled.'); }
  return { secret: session.secret, expire: session.expire };
}

export async function logout(req) {
  const secret = cookieOf(req);
  if (secret) await new Account(newClient().setSession(secret)).deleteSession({ sessionId: 'current' }).catch(() => {});
}

// ---------------------------------------------------------------- helpers
const str = (v, max, field, { required = false, label } = {}) => {
  const s = v == null ? '' : String(v).trim();
  if (required && !s) fail(field, `${label || field} is required.`);
  if (s.length > max) fail(field, `${label || field} is too long.`);
  return s || null;
};
const num = (v, field, { min = -Infinity, max = Infinity, required = false } = {}) => {
  if (v === '' || v == null) { if (required) fail(field, `${field} is required.`); return null; }
  const n = Number(v);
  if (!Number.isFinite(n) || n < min || n > max) fail(field, `${field} must be between ${min} and ${max}.`);
  return n;
};
const publicOrder = async (o, accounts) => {
  const account = accounts ? accounts.get(o.accountId) : await core.getAccount(o.accountId);
  return {
    id: o.id, order_no: o.orderNo, client_order_id: o.clientOrderId, status: o.status, environment: o.environment, amount: Number(o.amount), payable_amount: Number(core.orderPayable(o)),
    adjusted: o.payableAmount != null && Number(o.payableAmount) !== Number(o.amount), customer_name: o.customerName, customer_email: o.customerEmail, customer_mobile: o.customerMobile,
    note: o.note, utr: o.utr, customer_utr: o.customerUtr, review_note: o.reviewNote, utr_attempts: o.utrAttempts || 0, verified_by: o.verifiedBy, risk_level: o.riskLevel,
    risk_flags: (o.riskFlags || []).map((f) => ({ flag: f, label: RISK_FLAGS[f]?.[1] || f })), created_at: o.createdAt, paid_at: o.paidAt, expires_at: o.expiresAt,
    account_label: account?.label || null, provider: account?.provider || null, payment_url: core.paymentUrl(o),
  };
};
const ownedOrder = async (u, orderNo, { any = false } = {}) => {
  const o = await core.getOrderByNo(String(orderNo || ''));
  if (!o || (o.userId !== u.id && !(any && u.role === 'admin'))) throw new ValidationError('Not found', 404);
  return o;
};
const parseDate = (s, fallback) => { const d = s ? new Date(`${s}T00:00:00+05:30`) : null; return d && !Number.isNaN(d.getTime()) ? d : fallback; };

async function audit(u, action, targetId, meta) { await db.create('audit', { adminId: u.id, action, targetId, meta: JSON.stringify(meta || {}).slice(0, 500) }).catch(() => {}); }

// ---------------------------------------------------------------- handlers
export const handlers = {
  async me(u) {
    return { user: { id: u.id, email: u.email, name: u.name, business_name: u.businessName, phone: u.phone, role: u.role, display_name: u.businessName || u.name }, brand: BRAND, base_url: core.baseUrl() };
  },

  async dashboard(u, b) {
    const env = b.environment === 'sandbox' ? 'sandbox' : 'live';
    const rows = await db.all('orders', [Query.equal('userId', u.id), Query.equal('environment', env), Query.orderDesc('$createdAt')], 5000);
    const day = istDayStart(); const since = addDays(day, -6);
    const paid = rows.filter((o) => o.status === 'paid');
    const sum = (a) => a.reduce((s, o) => s + Number(o.amount), 0);
    const todayPaid = paid.filter((o) => new Date(o.paidAt) >= day);
    const closed = rows.filter((o) => ['paid', 'expired', 'failed'].includes(o.status)).length;
    const chart = [...Array(7)].map((_, i) => {
      const d = addDays(since, i); const key = istDate(d);
      const m = paid.filter((o) => istDate(o.paidAt) === key);
      return { label: new Date(d.getTime() + 330 * 60000).toLocaleDateString('en-GB', { weekday: 'short', timeZone: 'UTC' }), date: key, amount: sum(m), count: m.length };
    });
    const accountsCount = await db.count('accounts', [Query.equal('userId', u.id)]);
    const keys = await db.all('apikeys', [Query.equal('userId', u.id), Query.isNull('revokedAt')]);
    const accounts = new Map((await core.listAccounts(u.id)).map((a) => [a.id, a]));
    return {
      environment: env,
      stats: {
        today_amount: sum(todayPaid), today_count: todayPaid.length, total_amount: sum(paid), paid_count: paid.length,
        pending_count: rows.filter((o) => o.status === 'pending').length, review_count: rows.filter((o) => o.status === 'review').length,
        success_rate: closed ? Math.round((paid.length / closed) * 100) : null,
      },
      chart, week_total: chart.reduce((s, c) => s + c.amount, 0),
      checklist: env === 'sandbox' ? [
        ['Generate a Sandbox API key', keys.some((k) => k.environment === 'sandbox'), '#/settings'], ['Set up Sandbox webhook URL', !!u.sandboxWebhookUrl, '#/settings'], ['Create your first TEST payment', rows.length > 0, '#/docs'],
      ] : [
        ['Create your merchant account', true, null], ['Add a UPI account', accountsCount > 0, '#/accounts'], ['Create an API key', keys.some((k) => k.environment === 'live'), '#/settings'],
        ['Set up your webhook URL', !!u.webhookUrl || keys.some((k) => k.webhookUrl), '#/settings'], ['Receive your first payment', paid.length > 0, '#/orders/new'],
      ],
      recent: await Promise.all(rows.slice(0, 6).map((o) => publicOrder(o, accounts))),
    };
  },

  // -------- accounts
  async 'accounts.list'(u) {
    const accounts = await core.listAccounts(u.id);
    const out = [];
    for (const a of accounts) {
      const used = a.dailyLimit != null ? await core.usedToday(a) : null;
      out.push({
        id: a.id, provider: a.provider, provider_label: PROVIDERS[a.provider]?.label || a.provider, label: a.label, upi_id: a.upiId, payee_name: a.payeeName, is_active: a.isActive, is_default: a.isDefault,
        daily_limit: a.dailyLimit, used_today: used, hosted: core.isHosted(a), environment: core.isHosted(a) ? a.credentials?.environment : null,
        healthy: !a.unhealthyUntil || new Date(a.unhealthyUntil) < new Date(), failure_count: a.failureCount || 0, orders: await db.count('orders', [Query.equal('accountId', a.id)]),
      });
    }
    return { accounts: out, rotation: u.rotationMode || 'random', providers: Object.entries(PROVIDERS).map(([id, p]) => ({ id, label: p.label, hosted: p.hosted })), imap_hosts: IMAP_HOSTS };
  },

  async 'accounts.create'(u, b) {
    const provider = b.provider;
    if (!PROVIDERS[provider]) fail('provider', 'Choose an account type.');
    const hosted = PROVIDERS[provider].hosted;
    const label = str(b.label, 60, 'label', { required: true, label: 'Label' });
    const payee = str(b.payee_name, 60, 'payee_name', { required: true, label: 'Payee name' });
    const upi = str(b.upi_id, 100, 'upi_id');
    if (!hosted && !upi) fail('upi_id', 'UPI ID is required for this account type.');
    if (upi && !/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z]{2,64}$/.test(upi)) fail('upi_id', 'Enter a valid UPI ID, e.g. yourname@paytm or yourname@fam.');
    const limit = num(b.daily_limit, 'daily_limit', { min: 1, max: 10000000 });
    const env = b.environment;
    if (hosted && !['sandbox', 'production'].includes(env)) fail('environment', 'Choose sandbox or production.');
    const need = (k, msg, re) => { const v = str(b[k], 200, k, { required: true, label: msg }); if (re && !re.test(v)) fail(k, `${msg} is invalid.`); return v; };
    let credentials = {};
    if (provider === 'paytm_business') credentials = { mid: need('mid', 'Paytm MID', /^[A-Za-z0-9]+$/) };
    if (provider === 'paytm_pg') credentials = { mid: need('mid', 'Paytm MID', /^[A-Za-z0-9]+$/), merchant_key: need('merchant_key', 'Paytm Merchant Key'), website_name: str(b.website_name, 40, 'website_name') || (env === 'production' ? 'DEFAULT' : 'WEBSTAGING'), environment: env };
    if (provider === 'phonepe_pg') credentials = { client_id: need('client_id', 'PhonePe Client ID'), client_secret: need('client_secret', 'PhonePe Client Secret'), client_version: need('client_version', 'PhonePe Client Version'), environment: env };
    if (provider === 'fampay_email') {
      const host = str(b.imap_host, 80, 'imap_host') || 'imap.gmail.com';
      if (!IMAP_HOSTS.includes(host)) fail('imap_host', 'That mail server is not supported.');
      credentials = { imap_username: need('imap_username', 'Gmail / IMAP email address'), imap_password: need('imap_password', 'Google App Password').replace(/\s+/g, ''), imap_host: host, imap_port: '993', imap_sender: str(b.imap_sender, 150, 'imap_sender') || 'no-reply@famapp.in' };
    }
    if (provider === 'fam_email') credentials = { ingest_secret: randomToken(48) };
    const first = (await db.count('accounts', [Query.equal('userId', u.id)])) === 0;
    const a = await db.create('accounts', { userId: u.id, provider, label, upiId: hosted ? null : upi, payeeName: payee, credentials: JSON.stringify(credentials), isActive: true, isDefault: first, dailyLimit: limit, failureCount: 0 });
    return { id: a.id, provider, email_setup: provider === 'fam_email' };
  },

  async 'accounts.emailSetup'(u, b) {
    const a = await core.getAccount(b.id);
    if (!a || a.userId !== u.id || a.provider !== 'fam_email') throw new ValidationError('Not found', 404);
    return { id: a.id, label: a.label, ingest_url: `${core.baseUrl()}/api/email-ingest/${a.id}`, ingest_secret: a.credentials.ingest_secret, sender: core.CFG.fam.sender };
  },

  async 'accounts.update'(u, b) {
    const a = await core.getAccount(b.id);
    if (!a || a.userId !== u.id) throw new ValidationError('Not found', 404);
    if (b.action === 'limit') await db.update('accounts', a.id, { dailyLimit: num(b.daily_limit, 'daily_limit', { min: 1, max: 10000000 }) });
    else if (b.action === 'toggle') await db.update('accounts', a.id, { isActive: !a.isActive });
    else if (b.action === 'default') {
      for (const x of await core.listAccounts(u.id)) if (x.isDefault && x.id !== a.id) await db.update('accounts', x.id, { isDefault: false });
      await db.update('accounts', a.id, { isDefault: true });
    } else fail('action', 'Unknown action.');
    return {};
  },

  async 'accounts.delete'(u, b) {
    const a = await core.getAccount(b.id);
    if (!a || a.userId !== u.id) throw new ValidationError('Not found', 404);
    if ((await db.count('orders', [Query.equal('accountId', a.id)])) > 0) fail('account', 'This account has orders. Disable it instead of deleting.');
    await db.remove('accounts', a.id);
    return {};
  },

  // -------- orders
  async 'orders.list'(u, b) {
    const env = b.environment === 'sandbox' ? 'sandbox' : 'live';
    const base = [Query.equal('userId', u.id), Query.equal('environment', env)];
    const q = [...base];
    if (b.status) q.push(Query.equal('status', String(b.status)));
    let rows; let total;
    if (b.q) {
      const s = String(b.q).trim();
      const found = new Map();
      for (const f of ['orderNo', 'clientOrderId', 'utr']) for (const o of await db.all('orders', [...q, Query.equal(f, s)], 20)) found.set(o.id, o);
      rows = [...found.values()]; total = rows.length;
    } else {
      const page = Math.max(1, Number(b.page) || 1);
      const res = await db.list('orders', [...q, Query.orderDesc('$createdAt'), Query.offset((page - 1) * 20)], 20);
      rows = res.rows; total = res.total;
    }
    const counts = {};
    for (const s of ['pending', 'paid', 'review', 'expired', 'failed']) counts[s] = await db.count('orders', [...base, Query.equal('status', s)]);
    const accounts = new Map((await core.listAccounts(u.id)).map((a) => [a.id, a]));
    return { orders: await Promise.all(rows.map((o) => publicOrder(o, accounts))), counts, total, page: Number(b.page) || 1, pages: Math.max(1, Math.ceil(total / 20)), environment: env };
  },

  async 'orders.create'(u, b) {
    const data = {
      amount: num(b.amount, 'amount', { min: 1, max: 100000, required: true }), payment_account_id: b.payment_account_id || null, customer_name: str(b.customer_name, 100, 'customer_name'),
      customer_mobile: str(b.customer_mobile, 20, 'customer_mobile'), note: str(b.note, 50, 'note'),
    };
    const o = await core.createOrder(u, data);
    return { order_no: o.orderNo, payment_url: core.paymentUrl(o) };
  },

  async 'orders.get'(u, b) {
    const o = await ownedOrder(u, b.order_no, { any: true });
    const account = await core.getAccount(o.accountId);
    const logs = await db.all('weblogs', [Query.equal('orderId', o.id), Query.orderDesc('$createdAt')], 50);
    const refunds = await db.all('refunds', [Query.equal('orderId', o.id), Query.orderDesc('$createdAt')]);
    const owner = u.id === o.userId;
    return {
      order: await publicOrder(o, new Map(account ? [[account.id, account]] : [])), refunds: refunds.map((r) => ({ amount: Number(r.amount), reason: r.reason, created_at: r.createdAt })),
      refundable: await core.refundable(o), logs: logs.map((l) => ({ id: l.id, event: l.event, url: l.url, response_code: l.responseCode, success: l.success, attempt: l.attempt, error_label: l.errorLabel, created_at: l.createdAt })),
      read_only: !owner || o.environment === 'sandbox', accepts_utr: !!account && ['manual', 'fam_email'].includes(account.provider), provider_response: o.providerResponse ? core.parseJson(o.providerResponse) : null,
    };
  },

  async 'orders.recheck'(u, b) { const o = await core.checkOrder(await ownedOrder(u, b.order_no)); return { status: o.status }; },

  async 'orders.markPaid'(u, b) {
    const o = await ownedOrder(u, b.order_no);
    const utr = String(b.utr || '').trim();
    if (!/^[A-Za-z0-9]{6,40}$/.test(utr)) fail('utr', 'Enter the UTR / reference number from the payment.');
    if (o.status === 'paid') fail('utr', 'Order is already paid.');
    if (o.status === 'review') fail('utr', 'The customer submitted a UTR. Approve or reject it instead.');
    await core.markPaid(o, normalizeUtr(utr), null, {}, 'manual');
    return {};
  },
  async 'orders.approveUtr'(u, b) {
    const o = await ownedOrder(u, b.order_no);
    if (!String(b.bank_utr || '').trim()) fail('bank_utr', 'Type the UTR you see in your bank/UPI app to confirm.');
    await core.approveCustomerUtr(o, b.bank_utr); return {};
  },
  async 'orders.rejectUtr'(u, b) { const o = await core.rejectCustomerUtr(await ownedOrder(u, b.order_no), str(b.reason, 150, 'reason')); return { status: o.status }; },
  async 'orders.refund'(u, b) {
    const o = await ownedOrder(u, b.order_no);
    const amount = num(b.amount, 'amount', { min: 0.01, required: true });
    await core.refundOrder(o, amount, str(b.reason, 200, 'reason', { required: true, label: 'Reason' })); return {};
  },

  // -------- webhooks log
  async 'webhooks.list'(u, b) {
    const page = Math.max(1, Number(b.page) || 1);
    const res = await db.list('weblogs', [Query.equal('userId', u.id), Query.orderDesc('$createdAt'), Query.offset((page - 1) * 25)], 25);
    return { logs: res.rows.map((l) => ({ id: l.id, order_no: l.orderNo, event: l.event, url: l.url, response_code: l.responseCode, success: l.success, attempt: l.attempt, error_label: l.errorLabel, created_at: l.createdAt })), total: res.total, page, pages: Math.max(1, Math.ceil(res.total / 25)) };
  },
  async 'webhooks.resend'(u, b) {
    const log = await db.get('weblogs', b.id);
    if (!log || log.userId !== u.id) throw new ValidationError('Not found', 404);
    const order = await db.get('orders', log.orderId);
    await core.sendWebhook(order, log.event, { force: true, endpointId: log.endpointId, attempt: 1 });
    return {};
  },

  // -------- payment links
  async 'links.list'(u) {
    const links = await db.all('links', [Query.equal('userId', u.id), Query.orderDesc('$createdAt')]);
    return { links: links.map((l) => ({ id: l.id, title: l.title, description: l.description, amount: l.amount, note: l.note, is_active: l.isActive, url: `${core.baseUrl()}/p/${l.id}`, created_at: l.createdAt })) };
  },
  async 'links.create'(u, b) {
    const l = await db.create('links', { userId: u.id, title: str(b.title, 120, 'title', { required: true, label: 'Title' }), description: str(b.description, 500, 'description'), amount: num(b.amount, 'amount', { min: 1, max: 100000 }), note: str(b.note, 50, 'note'), accountId: b.payment_account_id || null, isActive: true });
    return { id: l.id, url: `${core.baseUrl()}/p/${l.id}` };
  },
  async 'links.toggle'(u, b) { const l = await db.get('links', b.id); if (!l || l.userId !== u.id) throw new ValidationError('Not found', 404); await db.update('links', l.id, { isActive: !l.isActive }); return {}; },
  async 'links.delete'(u, b) { const l = await db.get('links', b.id); if (!l || l.userId !== u.id) throw new ValidationError('Not found', 404); await db.remove('links', l.id); return {}; },

  // -------- subscriptions
  async 'subs.list'(u) {
    const subs = await db.all('subscriptions', [Query.equal('userId', u.id), Query.orderDesc('$createdAt')]);
    return { subscriptions: subs.map((s) => ({ id: s.id, plan_name: s.planName, amount: s.amount, interval: s.interval, customer_name: s.customerName, customer_email: s.customerEmail, customer_mobile: s.customerMobile, next_due_at: s.nextDueAt ? istDate(s.nextDueAt) : null, status: s.status, last_billed_at: s.lastBilledAt })), intervals: { weekly: 'Every week', monthly: 'Every month', yearly: 'Every year' } };
  },
  async 'subs.create'(u, b) {
    if (!['weekly', 'monthly', 'yearly'].includes(b.interval)) fail('interval', 'Choose an interval.');
    const due = parseDate(b.next_due_at, null);
    if (!due) fail('next_due_at', 'Choose the first due date.');
    const s = await db.create('subscriptions', {
      userId: u.id, planName: str(b.plan_name, 100, 'plan_name', { required: true, label: 'Plan name' }), amount: num(b.amount, 'amount', { min: 1, max: 100000, required: true }), interval: b.interval,
      customerName: str(b.customer_name, 100, 'customer_name', { required: true, label: 'Customer name' }), customerEmail: str(b.customer_email, 255, 'customer_email'), customerMobile: str(b.customer_mobile, 20, 'customer_mobile'),
      nextDueAt: db.iso(due), status: 'active', accountId: b.payment_account_id || null,
    });
    return { id: s.id };
  },
  async 'subs.update'(u, b) {
    const s = await db.get('subscriptions', b.id); if (!s || s.userId !== u.id) throw new ValidationError('Not found', 404);
    if (!['active', 'paused', 'cancelled'].includes(b.status)) fail('status', 'Invalid status.');
    await db.update('subscriptions', s.id, { status: b.status }); return {};
  },
  async 'subs.bill'(u, b) {
    const s = await db.get('subscriptions', b.id); if (!s || s.userId !== u.id) throw new ValidationError('Not found', 404);
    const o = await core.billSubscription(s); return { order_no: o?.orderNo || null };
  },

  // -------- earnings & reports
  async earnings(u, b) {
    const days = [7, 30, 90].includes(Number(b.days)) ? Number(b.days) : 30;
    const accounts = await core.listAccounts(u.id);
    const accountId = b.account || null;
    if (accountId && !accounts.some((a) => a.id === accountId)) throw new ValidationError('Not found', 404);
    const start = addDays(istDayStart(), -(days - 1));
    const q = [Query.equal('userId', u.id), Query.equal('environment', 'live'), Query.equal('status', 'paid')];
    if (accountId) q.push(Query.equal('accountId', accountId));
    const paid = await db.all('orders', q, 5000);
    const orderIds = new Map(paid.map((o) => [o.id, o]));
    let refunds = await db.all('refunds', [Query.equal('userId', u.id)], 5000);
    if (accountId) { const all = await db.all('orders', [Query.equal('userId', u.id), Query.equal('accountId', accountId)], 5000); const ids = new Set(all.map((o) => o.id)); refunds = refunds.filter((r) => ids.has(r.orderId)); }
    void orderIds;
    const opening = Math.round((paid.filter((o) => new Date(o.paidAt) < start).reduce((s, o) => s + Number(o.amount), 0) - refunds.filter((r) => new Date(r.createdAt) < start).reduce((s, r) => s + Number(r.amount), 0)) * 100) / 100;
    let running = opening; const rows = [];
    for (let i = 0; i < days; i++) {
      const d = addDays(start, i); const k = istDate(d);
      const inn = paid.filter((o) => new Date(o.paidAt) >= start && istDate(o.paidAt) === k);
      const out = refunds.filter((r) => new Date(r.createdAt) >= start && istDate(r.createdAt) === k);
      const received = Math.round(inn.reduce((s, o) => s + Number(o.amount), 0) * 100) / 100; const refunded = Math.round(out.reduce((s, r) => s + Number(r.amount), 0) * 100) / 100;
      running = Math.round((running + received - refunded) * 100) / 100;
      rows.push({ date: k, count: inn.length, received, refunded, running });
    }
    return { rows: rows.reverse(), opening, total: running, days, accounts: accounts.map((a) => ({ id: a.id, label: a.label })), account: accountId };
  },

  async reports(u, b) {
    const to = parseDate(b.to, new Date()); const from = parseDate(b.from, addDays(to, -29));
    const s = await core.summary(u.id, from, new Date(to.getTime() + 86400000 - 1));
    delete s.orders_list;
    return { ...s, from: istDate(from), to: istDate(to) };
  },

  // -------- settings
  async 'settings.get'(u) {
    const keys = await db.all('apikeys', [Query.equal('userId', u.id), Query.orderDesc('$createdAt')]);
    const eps = await db.all('endpoints', [Query.equal('userId', u.id)]);
    return {
      profile: { name: u.name, business_name: u.businessName, phone: u.phone, email: u.email }, webhook_url: u.webhookUrl, sandbox_webhook_url: u.sandboxWebhookUrl, rotation_mode: u.rotationMode || 'random',
      redirect_domains: core.parseJson(u.redirectDomains, []) || [], telegram: { configured: !!(u.telegramBotToken && u.telegramChatId), chat_id: u.telegramChatId, alerts: !!u.telegramAlerts, daily_summary: !!u.dailySummary, has_token: !!u.telegramBotToken },
      api_keys: keys.map((k) => ({ id: k.id, name: k.name, key: k.key, environment: k.environment, webhook_url: k.webhookUrl, last_used_at: k.lastUsedAt, revoked_at: k.revokedAt, created_at: k.createdAt })),
      endpoints: eps.map((e) => ({ id: e.id, url: e.url, environment: e.environment })), max_extra_webhooks: 4,
    };
  },
  async 'settings.profile'(u, b) {
    const name = str(b.name, 100, 'name', { required: true, label: 'Name' }); const business = str(b.business_name, 100, 'business_name', { required: true, label: 'Business name' });
    await db.update('profiles', u.id, { name, businessName: business, phone: str(b.phone, 20, 'phone') });
    await db.users().updateName({ userId: u.id, name }).catch(() => {});
    return {};
  },
  async 'settings.alerts'(u, b) {
    const chat = str(b.telegram_chat_id, 64, 'telegram_chat_id'); const token = str(b.telegram_bot_token, 255, 'telegram_bot_token');
    if (chat && !TELEGRAM_CHAT.test(chat)) fail('telegram_chat_id', 'Chat ID must be a number (or @channelname).');
    if (token && !TELEGRAM_TOKEN.test(token)) fail('telegram_bot_token', 'That does not look like a bot token (from @BotFather).');
    const newToken = token || u.telegramBotToken;
    await db.update('profiles', u.id, { telegramChatId: chat, ...(token ? { telegramBotToken: token } : {}), telegramAlerts: !!b.telegram_alerts && !!(newToken && chat), dailySummary: !!b.daily_summary });
    return {};
  },
  async 'settings.alertsTest'(u) {
    const ok = await telegramSend(u, `✅ ${BRAND}: Telegram alerts are working.`);
    if (!ok) fail('telegram', 'Could not send. Check the bot token and chat ID, and that you pressed Start on your bot.');
    return {};
  },
  async 'settings.webhook'(u, b) {
    const url = str(b.webhook_url, 500, 'webhook_url'); if (url) validateWebhookUrl(url, 'webhook_url');
    await db.update('profiles', u.id, { webhookUrl: url }); return {};
  },
  async 'settings.sandboxWebhook'(u, b) {
    const url = str(b.sandbox_webhook_url, 500, 'sandbox_webhook_url');
    if (url) { validateWebhookUrl(url, 'sandbox_webhook_url'); if (u.webhookUrl && u.webhookUrl.replace(/\/$/, '') === url.replace(/\/$/, '')) fail('sandbox_webhook_url', 'Use a separate endpoint for Sandbox webhooks.'); }
    await db.update('profiles', u.id, { sandboxWebhookUrl: url }); return {};
  },
  async 'settings.endpointAdd'(u, b) {
    const env = b.environment === 'sandbox' ? 'sandbox' : 'live'; const url = str(b.url, 500, 'url', { required: true, label: 'URL' }); validateWebhookUrl(url);
    const norm = (x) => (x ? x.toLowerCase().replace(/\/$/, '') : null);
    const existing = await db.all('endpoints', [Query.equal('userId', u.id), Query.equal('environment', env)]);
    if (existing.length >= 4) fail('url', `You can add up to 4 extra ${env} webhook endpoints.`);
    const primary = env === 'sandbox' ? u.sandboxWebhookUrl : u.webhookUrl;
    if ([...existing.map((e) => e.url), primary].filter(Boolean).map(norm).includes(norm(url))) fail('url', `That URL is already a ${env} webhook endpoint.`);
    if (env === 'sandbox') {
      const liveEps = await db.all('endpoints', [Query.equal('userId', u.id), Query.equal('environment', 'live')]);
      if ([...liveEps.map((e) => e.url), u.webhookUrl].filter(Boolean).map(norm).includes(norm(url))) fail('url', 'Use a separate endpoint for Sandbox webhooks.');
    }
    await db.create('endpoints', { userId: u.id, url, environment: env }); return {};
  },
  async 'settings.endpointRemove'(u, b) { const e = await db.get('endpoints', b.id); if (!e || e.userId !== u.id) throw new ValidationError('Not found', 404); await db.remove('endpoints', e.id); return {}; },
  async 'settings.rotation'(u, b) { if (!['random', 'default'].includes(b.rotation_mode)) fail('rotation_mode', 'Invalid mode.'); await db.update('profiles', u.id, { rotationMode: b.rotation_mode }); return {}; },
  async 'settings.redirectDomains'(u, b) {
    const domains = String(b.redirect_domains || '').split(/[\n,]+/).map((d) => d.trim().toLowerCase().replace(/^https?:\/\//, '')).filter((d) => d && /^[a-z0-9.-]+$/.test(d));
    await db.update('profiles', u.id, { redirectDomains: domains.length ? JSON.stringify(domains) : null }); return { domains };
  },

  async 'apikeys.create'(u, b) {
    const name = str(b.name, 60, 'name', { required: true, label: 'Name' }); const env = b.environment === 'sandbox' ? 'sandbox' : 'live';
    if ((await db.count('apikeys', [Query.equal('userId', u.id), Query.isNull('revokedAt')])) >= MAX_KEYS) fail('name', `You can have at most ${MAX_KEYS} active keys. Revoke one you no longer use.`);
    const hook = str(b.webhook_url, 500, 'webhook_url'); if (hook) validateWebhookUrl(hook, 'webhook_url');
    const key = (env === 'sandbox' ? 'test_key_' : 'key_') + randomToken(24); const secret = (env === 'sandbox' ? 'test_sec_' : 'sec_') + randomToken(40);
    const k = await db.create('apikeys', { userId: u.id, name, key, secret, environment: env, webhookUrl: hook });
    return { id: k.id, name, key, secret, environment: env };
  },
  async 'apikeys.update'(u, b) {
    const k = await db.get('apikeys', b.id); if (!k || k.userId !== u.id || k.revokedAt) throw new ValidationError('Not found', 404);
    const hook = str(b.webhook_url, 500, 'webhook_url'); if (hook) validateWebhookUrl(hook, 'webhook_url');
    await db.update('apikeys', k.id, { webhookUrl: hook }); return {};
  },
  async 'apikeys.revoke'(u, b) { const k = await db.get('apikeys', b.id); if (!k || k.userId !== u.id) throw new ValidationError('Not found', 404); await db.update('apikeys', k.id, { revokedAt: db.iso() }); return {}; },

  async 'password.change'(u, b) {
    const next = String(b.password || '');
    if (next.length < 8) fail('password', 'Password must be at least 8 characters.');
    if (b.password_confirmation !== undefined && b.password_confirmation !== next) fail('password_confirmation', 'Passwords do not match.');
    try { await new Account(newClient().setSession(u.sessionSecret)).updatePassword({ password: next, oldPassword: String(b.current_password || '') }); }
    catch (e) { if (e.code === 401 || e.code === 400) fail('current_password', 'The current password is incorrect.'); throw e; }
    return {};
  },

  // -------- admin
  async 'admin.merchants'(u) {
    adminOnly(u);
    const rows = await db.all('profiles', [Query.orderDesc('$createdAt')], 500);
    const out = [];
    for (const p of rows) out.push({ id: p.id, name: p.name, business_name: p.businessName, email: p.email, role: p.role, is_active: p.isActive, created_at: p.createdAt, orders: await db.count('orders', [Query.equal('userId', p.id), Query.equal('environment', 'live')]) });
    return { merchants: out };
  },
  async 'admin.toggle'(u, b) {
    adminOnly(u);
    const p = await db.get('profiles', b.id); if (!p) throw new ValidationError('Not found', 404);
    if (p.id === u.id) fail('merchant', 'You cannot disable your own account.');
    await db.update('profiles', p.id, { isActive: !p.isActive });
    await db.users().updateStatus({ userId: p.id, status: !p.isActive ? true : false }).catch(() => {});
    await audit(u, p.isActive ? 'merchant.disable' : 'merchant.enable', p.id, { email: p.email });
    return {};
  },
  async 'admin.orders'(u, b) {
    adminOnly(u);
    const page = Math.max(1, Number(b.page) || 1); const q = [Query.equal('environment', 'live')];
    if (b.status) q.push(Query.equal('status', String(b.status)));
    if (b.q) q.push(Query.equal('orderNo', String(b.q).trim()));
    const res = await db.list('orders', [...q, Query.orderDesc('$createdAt'), Query.offset((page - 1) * 25)], 25);
    const profiles = new Map();
    const orders = [];
    for (const o of res.rows) { if (!profiles.has(o.userId)) profiles.set(o.userId, await core.getProfile(o.userId)); const p = await publicOrder(o, null); p.merchant = profiles.get(o.userId)?.businessName || profiles.get(o.userId)?.email; orders.push(p); }
    return { orders, total: res.total, page, pages: Math.max(1, Math.ceil(res.total / 25)) };
  },
  async 'admin.debug'(u, b) {
    adminOnly(u);
    const o = await core.getOrderByNo(String(b.order_no || '').trim()); if (!o) throw new ValidationError('Order not found', 404);
    const account = await core.getAccount(o.accountId);
    const logs = await db.all('weblogs', [Query.equal('orderId', o.id), Query.orderAsc('$createdAt')]);
    const emails = await db.all('emailpayments', [Query.equal('orderId', o.id)]);
    const merchant = await core.getProfile(o.userId);
    return {
      order: await publicOrder(o, new Map(account ? [[account.id, account]] : [])), merchant: merchant?.email, provider_response: core.parseJson(o.providerResponse), last_checked_at: o.lastCheckedAt,
      logs: logs.map((l) => ({ event: l.event, url: l.url, response_code: l.responseCode, success: l.success, attempt: l.attempt, error_label: l.errorLabel, created_at: l.createdAt })),
      emails: emails.map((e) => ({ utr: e.utr, amount: e.amount, status: e.status, payer: e.payerName, paid_at: e.paidAt })),
    };
  },
};

function adminOnly(u) { if (u.role !== 'admin') throw new ValidationError('Forbidden', 403); }

export async function reportCsv(u, query) {
  const to = parseDate(query.to, new Date()); const from = parseDate(query.from, addDays(to, -29));
  const s = await core.summary(u.id, from, new Date(to.getTime() + 86400000 - 1));
  const accounts = new Map((await core.listAccounts(u.id)).map((a) => [a.id, a.label]));
  const rows = [['Order', 'Client order', 'Status', 'Amount', 'Account', 'UTR', 'Customer', 'Mobile', 'Created', 'Paid']];
  for (const o of s.orders_list.sort((a, b) => a.createdAt.localeCompare(b.createdAt))) rows.push([o.orderNo, o.clientOrderId, o.status, money(o.amount), accounts.get(o.accountId) || '', o.utr || '', o.customerName || '', o.customerMobile || '', o.createdAt, o.paidAt || '']);
  return rows.map((r) => r.map((c) => `"${core.csvSafe(c).replace(/"/g, '""')}"`).join(',')).join('\n');
}
export { hmac };
