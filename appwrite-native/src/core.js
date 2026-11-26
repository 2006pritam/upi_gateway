import * as db from './db.js';
import { Query } from './db.js';
import {
  BRAND, RISK_FLAGS, ValidationError, fail, randomId, hmac, normalizeUtr, toPaise, money, addMinutes, istDayStart, istDate,
  assertPublicHost, telegramSend, httpJson,
} from './util.js';
import { PROVIDERS, verifiers, hosted } from './verifiers.js';

export const CFG = {
  orderExpiryMinutes: 15, checkIntervalSeconds: 3, maxUtrAttempts: 3, utrGraceMinutes: 60,
  uniqueAmount: { providers: ['fam_email', 'fampay_email'], maxOffsetPaise: 50, graceMinutes: 60 },
  fam: { sender: 'no-reply@famapp.com', domain: 'famapp.com', maxAgeHours: 72, lateGraceMinutes: 60, skewMinutes: 2, sigToleranceSeconds: 300 },
  failureLimit: 5, cooldownMinutes: 10, backoff: [30, 120, 600, 1800, 3600],
};

export const baseUrl = () => (process.env.APP_URL || '').replace(/\/$/, '');

// Work that must finish before the function returns (webhooks, alerts).
const background = [];
export const defer = (p) => { background.push(p.catch((e) => console.error('background task failed:', e?.message || e))); };
export const flush = async () => { while (background.length) await Promise.allSettled(background.splice(0)); };

const open = (o) => o.status === 'pending';

// ------------------------------------------------------------------ profiles & accounts
export const getProfile = (userId) => db.get('profiles', userId);
export const parseJson = (v, d = null) => { if (v == null) return d; if (typeof v !== 'string') return v; try { return JSON.parse(v); } catch { return d; } };

export async function getAccount(id) {
  if (!id) return null;
  const a = await db.get('accounts', id);
  if (a && typeof a.credentials === 'string') a.credentials = parseJson(a.credentials, {});
  return a;
}
export async function listAccounts(userId) {
  const rows = await db.all('accounts', [Query.equal('userId', userId), Query.orderAsc('$createdAt')]);
  for (const a of rows) a.credentials = parseJson(a.credentials, {});
  return rows;
}
export const isHosted = (a) => PROVIDERS[a?.provider]?.hosted === true;
const isHealthy = (a) => !a.unhealthyUntil || new Date(a.unhealthyUntil) < new Date();

export async function recordCheck(account, ok) {
  if (ok) {
    if ((account.failureCount || 0) > 0 || account.unhealthyUntil) await db.update('accounts', account.id, { failureCount: 0, unhealthyUntil: null });
  } else {
    const n = (account.failureCount || 0) + 1;
    await db.update('accounts', account.id, { failureCount: n, unhealthyUntil: n >= CFG.failureLimit ? db.iso(addMinutes(new Date(), CFG.cooldownMinutes)) : account.unhealthyUntil });
  }
}

export async function usedToday(account) {
  const day = db.iso(istDayStart());
  const nowIso = db.iso();
  const q = (extra) => db.all('orders', [Query.equal('accountId', account.id), Query.equal('environment', 'live'), ...extra]);
  const [paidRows, pendingRows, reviewRows] = await Promise.all([
    q([Query.equal('status', 'paid'), Query.greaterThanEqual('paidAt', day)]),
    q([Query.equal('status', 'pending'), Query.greaterThan('expiresAt', nowIso)]),
    q([Query.equal('status', 'review')]),
  ]);
  return [...paidRows, ...pendingRows, ...reviewRows].reduce((s, o) => s + Number(o.amount || 0), 0);
}
async function canAccept(account, amount) {
  if (account.dailyLimit == null) return true;
  return (await usedToday(account)) + amount <= Number(account.dailyLimit);
}

async function resolveAccount(profile, amount, accountId) {
  let accounts = (await listAccounts(profile.id)).filter((a) => a.isActive);
  const healthy = accounts.filter(isHealthy);
  if (healthy.length) accounts = healthy;
  if (accountId) {
    const owns = accounts.some((a) => a.id === accountId) || (await db.get('accounts', accountId))?.userId === profile.id;
    if (!owns) fail('payment_account_id', 'This UPI account does not exist.');
  }
  const requested = accountId ? accounts.find((a) => a.id === accountId) : null;
  if (requested && (await canAccept(requested, amount))) return requested;
  if (!accounts.length) fail('payment_account_id', 'Add an active UPI account in the dashboard before creating orders.');
  const available = [];
  for (const a of accounts) if (await canAccept(a, amount)) available.push(a);
  if (!available.length) fail('amount', 'All UPI accounts have reached their daily limit for this amount. Try a smaller amount or raise a limit.');
  if (profile.rotationMode === 'random') return available[Math.floor(Math.random() * available.length)];
  return [...available].sort((a, b) => (b.isDefault - a.isDefault) || (new Date(a.createdAt) - new Date(b.createdAt)))[0];
}

/** E-mail verified accounts only see an amount, so every open order needs its own price: p, p+0.01, ... */
async function allocatePayableAmount(account, price, expiresAt, ownerHint) {
  const cfg = CFG.uniqueAmount;
  if (!cfg.providers.includes(account.provider)) return null;
  const paise = Math.round(price * 100);
  const holdUntil = db.iso(addMinutes(expiresAt, cfg.graceMinutes));
  for (let off = 0; off <= cfg.maxOffsetPaise; off++) {
    const candidate = money((paise + off) / 100);
    const ref = `${account.id}:${candidate}`;
    const r = await db.claim('amt', ref, holdUntil, ownerHint);
    if (r.won) return candidate;
    const row = await db.get('claims', r.id);
    if (row && row.expiresAt && new Date(row.expiresAt) < new Date()) {
      await db.update('claims', r.id, { expiresAt: holdUntil, owner: ownerHint });
      return candidate;
    }
  }
  fail('amount', 'Too many open orders for this amount right now. Please try again in a few minutes.');
}
const releaseAmount = (order, account) => (order.payableAmount != null && account)
  ? db.releaseClaim(db.claimId('amt', `${account.id}:${money(order.payableAmount)}`)) : Promise.resolve();

// ------------------------------------------------------------------ orders
export const orderPayable = (o) => money(o.payableAmount ?? o.amount);
export const isPastExpiry = (o) => !!o.expiresAt && new Date(o.expiresAt) < new Date();

export function upiIntentUrl(order, account, scheme = 'upi://pay') {
  const p = new URLSearchParams({ pa: account.upiId || '', pn: account.payeeName || '', am: orderPayable(order), cu: 'INR', tr: order.orderNo, tn: order.note || order.orderNo });
  return `${scheme}?${p.toString().replace(/\+/g, '%20')}`;
}
export const androidIntentUrl = (order, account, pkg) => `intent://${upiIntentUrl(order, account).split('://')[1]}#Intent;scheme=upi;package=${pkg};end`;
export const paymentUrl = (o) => `${baseUrl()}/${o.environment === 'sandbox' ? 'sandbox/pay' : 'pay'}/${o.orderNo}`;

export function toApi(o, account) {
  return {
    order_id: o.orderNo, client_order_id: o.clientOrderId, amount: money(o.amount), payable_amount: orderPayable(o), currency: 'INR',
    status: o.status, environment: o.environment, test: o.environment === 'sandbox', utr: o.utr || null,
    submitted_utr: o.status === 'review' ? o.customerUtr : null, payment_url: paymentUrl(o),
    upi_intent: o.environment === 'sandbox' || !account || isHosted(account) ? null : upiIntentUrl(o, account),
    customer: { name: o.customerName || null, email: o.customerEmail || null, mobile: o.customerMobile || null },
    paid_at: o.paidAt || null, expires_at: o.expiresAt || null, created_at: o.createdAt,
  };
}

export async function getOrderByNo(orderNo) { return db.first('orders', [Query.equal('orderNo', orderNo)]); }
export async function getOrderFor(userId, key, environment) {
  const q = [Query.equal('userId', userId)];
  if (environment) q.push(Query.equal('environment', environment));
  return (await db.first('orders', [...q, Query.equal('orderNo', key)])) || (await db.first('orders', [...q, Query.equal('clientOrderId', key)]));
}

export async function createOrder(profile, data, environment = 'live') {
  if (!['live', 'sandbox'].includes(environment)) fail('environment', 'Invalid payment environment.');
  const amount = Number(data.amount);
  const account = environment === 'live' ? await resolveAccount(profile, amount, data.payment_account_id || null) : null;
  const orderNo = (environment === 'sandbox' ? 'TST' : 'ORD') + randomId(16);
  const expiresAt = addMinutes(new Date(), Number(data.expiry_minutes) || CFG.orderExpiryMinutes);
  const payableAmount = account ? await allocatePayableAmount(account, amount, expiresAt, orderNo) : null;
  const row = {
    userId: profile.id, orderNo, accountId: account?.id ?? null, clientOrderId: data.client_order_id || orderNo, environment, amount, payableAmount: payableAmount != null ? Number(payableAmount) : null,
    status: 'pending', customerName: data.customer_name ?? null, customerEmail: data.customer_email ?? null, customerMobile: data.customer_mobile ?? null,
    note: data.note ?? null, redirectUrl: data.redirect_url ?? null, linkId: data.link_id ?? null, createdIp: data.created_ip ?? null, apiKeyId: data.api_key_id ?? null,
    expiresAt: db.iso(expiresAt), utrAttempts: 0,
  };
  let order;
  try { order = await db.create('orders', row); }
  catch (e) {
    if (account) await releaseAmount({ payableAmount }, account);
    if (db.isConflict(e)) fail('client_order_id', 'This client_order_id is already used.');
    throw e;
  }
  return assess(order);
}

export async function assess(order) {
  if (order.environment !== 'live') return order;
  const flags = [];
  const since = (m) => db.iso(addMinutes(new Date(), -m));
  if (order.createdIp) {
    const n = await db.count('orders', [Query.equal('userId', order.userId), Query.equal('environment', 'live'), Query.equal('createdIp', order.createdIp), Query.greaterThanEqual('$createdAt', since(10))]);
    if (n >= 6) flags.push('ip_burst');
  }
  if (order.customerMobile) {
    const n = await db.count('orders', [Query.equal('userId', order.userId), Query.equal('environment', 'live'), Query.equal('customerMobile', order.customerMobile), Query.equal('status', ['pending', 'expired']), Query.greaterThanEqual('$createdAt', since(60))]);
    if (n >= 4) flags.push('mobile_unpaid');
  }
  if (order.status === 'paid' && order.paidAt && order.expiresAt && new Date(order.paidAt) > new Date(order.expiresAt)) flags.push('late_payment');
  if ((order.utrAttempts || 0) >= 2) flags.push('utr_retries');
  if (order.verifiedBy === 'utr_review') flags.push('manual_utr');
  const rank = { low: 1, medium: 2, high: 3 };
  const level = flags.map((f) => RISK_FLAGS[f][0]).sort((a, b) => rank[b] - rank[a])[0] || null;
  const next = { riskFlags: flags.length ? JSON.stringify(flags) : null, riskLevel: level };
  return { ...order, ...(await db.update('orders', order.id, next)), riskFlags: flags.length ? flags : null };
}

const refresh = (o) => db.get('orders', o.id);

export async function checkOrder(order, force = false) {
  if (order.status !== 'pending') return order;
  if (!order.expiresAt) {
    return db.update('orders', order.id, { expiresAt: db.iso(addMinutes(new Date(), CFG.orderExpiryMinutes)) });
  }
  if (order.environment === 'sandbox') return isPastExpiry(order) ? expireOrder(order) : order;

  const last = order.lastCheckedAt ? new Date(order.lastCheckedAt).getTime() : 0;
  if (force || Date.now() - last >= CFG.checkIntervalSeconds * 1000) {
    await db.update('orders', order.id, { lastCheckedAt: db.iso() });
    const account = await getAccount(order.accountId);
    if (account) {
      const ctx = { utrTaken: (o, u) => utrTaken(o, u), isOldestPendingForAmount };
      let result;
      try { result = await verifiers[account.provider](order, account, ctx); } catch (e) { result = { paid: false, raw: { error: e.message } }; }
      await recordCheck(account, !( !result.paid && result.raw?.error)).catch(() => {});
      if (result.paid) return markPaid(order, result.utr, result.txnId, result.raw, 'provider');
      if (result.raw && Object.keys(result.raw).length) order = await db.update('orders', order.id, { providerResponse: JSON.stringify(result.raw).slice(0, 3000) }).catch(() => order);
    }
  }
  if (isPastExpiry(order)) return expireOrder(order);
  return refresh(order);
}

async function isOldestPendingForAmount(order) {
  const rows = await db.all('orders', [Query.equal('accountId', order.accountId), Query.equal('status', 'pending'), Query.equal('payableAmount', Number(orderPayable(order))), Query.orderAsc('$createdAt')], 5);
  return !rows.length || rows[0].id === order.id;
}

export async function utrTaken(order, utr) {
  const r = await db.get('claims', db.claimId('utr', `${order.userId}:${utr}`));
  return !!(r && r.owner && r.owner !== order.id);
}
export async function ensureUtrUnused(order, utr) {
  const c = await db.claim('utr', `${order.userId}:${utr}`, null, order.id);
  if (c.won) return;
  const row = await db.get('claims', c.id);
  if (row?.owner !== order.id) fail('utr', 'This UTR is already used on another order.');
}

export async function markPaid(order, utr, txnId, raw, verifiedBy) {
  const fresh = await refresh(order);
  if (fresh.environment !== 'live') fail('order', 'Sandbox orders cannot be paid through live payment handlers.');
  if (fresh.status === 'paid' || fresh.status === 'review') return fresh;
  if (utr) await ensureUtrUnused(fresh, utr);
  const lock = await db.claim('paid', fresh.id, null, fresh.id);
  if (!lock.won) return refresh(fresh);
  let updated;
  try {
    updated = await db.update('orders', fresh.id, {
      status: 'paid', utr: utr ?? null, providerTxnId: txnId ?? null, providerResponse: raw && Object.keys(raw).length ? JSON.stringify(raw).slice(0, 8000) : null,
      verifiedBy, paidAt: db.iso(), reviewNote: null,
    });
  } catch (e) { await db.releaseClaim(lock.id); throw e; }
  const account = await getAccount(updated.accountId);
  await releaseAmount(updated, account);
  await dispatchEvent(updated, 'order.paid');
  return refresh(updated);
}

async function dispatchEvent(order, event) {
  const o = await assess(await refresh(order));
  defer(sendWebhook(o, event));
  if (['order.paid', 'order.under_review'].includes(event)) defer(merchantAlert(o, event));
}

export async function expireOrder(order) {
  const fresh = await refresh(order);
  if (fresh.status !== 'pending') return fresh;
  const updated = await db.update('orders', fresh.id, { status: 'expired' });
  defer(sendWebhook(updated, 'order.expired'));
  return updated;
}

export async function submitCustomerUtr(order, utrRaw) {
  const utr = normalizeUtr(utrRaw);
  const fresh = await refresh(order);
  const account = await getAccount(fresh.accountId);
  const accepts = fresh.environment === 'live' && ['manual', 'fam_email'].includes(account?.provider) && (fresh.utrAttempts || 0) < CFG.maxUtrAttempts
    && (fresh.status === 'pending' || (fresh.status === 'expired' && fresh.expiresAt && new Date(fresh.expiresAt) > addMinutes(new Date(), -CFG.utrGraceMinutes)));
  if (!accepts) fail('utr', 'UTR can no longer be submitted for this payment. Please contact the merchant.');
  await ensureUtrUnused(fresh, utr);
  const updated = await db.update('orders', fresh.id, { status: 'review', customerUtr: utr, utrSubmittedAt: db.iso(), utrAttempts: (fresh.utrAttempts || 0) + 1, reviewNote: null });
  await dispatchEvent(updated, 'order.under_review');
  return refresh(updated);
}

export async function approveCustomerUtr(order, bankUtrRaw) {
  const bankUtr = normalizeUtr(bankUtrRaw);
  const fresh = await refresh(order);
  if (fresh.status !== 'review') fail('bank_utr', 'This order is not waiting for review.');
  if (fresh.customerUtr !== bankUtr) fail('bank_utr', "UTR doesn't match what the customer submitted. Check your bank/UPI app again, or reject.");
  await ensureUtrUnused(fresh, fresh.customerUtr);
  const lock = await db.claim('paid', fresh.id, null, fresh.id);
  if (!lock.won) return refresh(fresh);
  let updated;
  try { updated = await db.update('orders', fresh.id, { status: 'paid', utr: fresh.customerUtr, verifiedBy: 'utr_review', paidAt: db.iso(), reviewNote: null }); }
  catch (e) { await db.releaseClaim(lock.id); throw e; }
  await releaseAmount(updated, await getAccount(updated.accountId));
  await dispatchEvent(updated, 'order.paid');
  return refresh(updated);
}

export async function rejectCustomerUtr(order, reason) {
  const fresh = await refresh(order);
  if (fresh.status !== 'review') fail('reason', 'This order is not waiting for review.');
  const canRetry = (fresh.utrAttempts || 0) < CFG.maxUtrAttempts;
  await db.releaseClaim(db.claimId('utr', `${fresh.userId}:${fresh.customerUtr}`));
  const updated = await db.update('orders', fresh.id, {
    status: canRetry ? 'pending' : 'expired', reviewNote: reason || `UTR ${fresh.customerUtr} was not found in the merchant's account.`,
    expiresAt: canRetry ? db.iso(addMinutes(new Date(), CFG.orderExpiryMinutes)) : fresh.expiresAt,
  });
  if (!canRetry) defer(sendWebhook(updated, 'order.expired'));
  return updated;
}

export async function refundedAmount(orderId) {
  const rows = await db.all('refunds', [Query.equal('orderId', orderId)]);
  return Math.round(rows.reduce((s, r) => s + Number(r.amount), 0) * 100) / 100;
}
export const refundable = async (o) => (o.status === 'paid' && o.environment === 'live') ? Math.max(0, Math.round((Number(o.amount) - await refundedAmount(o.id)) * 100) / 100) : 0;

export async function refundOrder(order, amount, reason) {
  const max = await refundable(await refresh(order));
  if (amount < 0.01 || amount > max + 0.0001) fail('amount', `Refund must be between ₹0.01 and ₹${money(max)}.`);
  return db.create('refunds', { userId: order.userId, orderId: order.id, amount: Math.round(amount * 100) / 100, reason });
}

export async function simulateSandbox(order, outcome) {
  if (!['success', 'failed', 'pending', 'expired', 'timeout', 'invalid_reference'].includes(outcome)) fail('outcome', 'Invalid sandbox outcome.');
  if (outcome === 'invalid_reference') fail('transaction_reference', 'The simulated transaction reference is invalid.');
  const fresh = await refresh(order);
  if (fresh.environment !== 'sandbox') throw new ValidationError('Not found', 404);
  if (fresh.status !== 'pending' || fresh.sandboxSimulatedAt) fail('outcome', 'This test order has already been simulated.');
  const lock = await db.claim('sim', fresh.id, null, fresh.id);
  if (!lock.won) fail('outcome', 'This test order has already been simulated.');
  const values = { sandboxSimulatedAt: db.iso(), providerResponse: JSON.stringify({ simulated: true, environment: 'sandbox', outcome }), verifiedBy: 'sandbox' };
  const event = { success: 'order.paid', failed: 'order.failed', expired: 'order.expired', timeout: 'order.timeout' }[outcome] || 'order.pending';
  if (outcome === 'success') Object.assign(values, { status: 'paid', utr: `TEST${randomId(16)}`, providerTxnId: `TEST_TXN_${randomId(12)}`, paidAt: db.iso() });
  else if (outcome === 'failed') values.status = 'failed';
  else if (outcome === 'expired') values.status = 'expired';
  const updated = await db.update('orders', fresh.id, values);
  defer(sendWebhook(updated, event));
  return updated;
}

export async function startCheckout(order, account) {
  const fresh = await refresh(order);
  if (fresh.status !== 'pending' || isPastExpiry(fresh)) return null;
  let checkout = parseJson(fresh.providerCheckout, null);
  if (!checkout || (checkout.expires_at || 0) <= Date.now() / 1000 + 30) {
    checkout = await hosted[account.provider](fresh, account, `${baseUrl()}/pay/${fresh.orderNo}/return`);
    if (!['redirect', 'form'].includes(checkout?.type) || !/^https:\/\//i.test(checkout?.url || '') || (checkout.type === 'form' && typeof checkout.fields !== 'object')) {
      throw new Error('The payment provider returned an invalid checkout URL. Please try again.');
    }
    await db.update('orders', fresh.id, { providerCheckout: JSON.stringify(checkout) });
  }
  return checkout;
}

// ------------------------------------------------------------------ webhooks
async function signingFor(order) {
  const profile = await getProfile(order.userId);
  const sandbox = order.environment === 'sandbox';
  let url = sandbox ? profile?.sandboxWebhookUrl : profile?.webhookUrl;
  let secret = null;
  if (order.apiKeyId) {
    const k = await db.get('apikeys', order.apiKeyId);
    if (k && !k.revokedAt) { secret = k.secret; url = k.webhookUrl || url; }
  }
  if (!secret) {
    const k = await db.first('apikeys', [Query.equal('userId', order.userId), Query.equal('environment', order.environment), Query.isNull('revokedAt')]);
    secret = k?.secret || null;
  }
  return { profile, url, secret, sandbox };
}

export async function sendWebhook(order, event, opts = {}) {
  const { force = false, endpointId = null, attempt = 1 } = opts;
  const { profile, url: primary, secret, sandbox } = await signingFor(order);
  if (!secret) return;
  let url = primary;
  if (endpointId == null) {
    if (!force && attempt === 1) {
      const eps = await db.all('endpoints', [Query.equal('userId', order.userId), Query.equal('environment', order.environment)]);
      for (const ep of eps) {
        if (primary && primary.replace(/\/$/, '') === ep.url.replace(/\/$/, '')) continue;
        defer(sendWebhook(order, event, { endpointId: ep.id }));
      }
    }
  } else {
    const ep = await db.get('endpoints', endpointId);
    url = ep && ep.userId === order.userId && ep.environment === order.environment ? ep.url : null;
  }
  if (!url) return;
  if (endpointId == null && sandbox && profile?.webhookUrl && profile.webhookUrl.replace(/\/$/, '') === url.replace(/\/$/, '')) return;
  if (!force) {
    const dup = await db.first('weblogs', [Query.equal('orderId', order.id), Query.equal('event', event), Query.equal('url', url), Query.equal('success', true)]);
    if (dup) return;
  }
  const account = order.accountId ? await getAccount(order.accountId) : null;
  const payload = { event, data: toApi(order, account) };
  const logBase = { userId: order.userId, orderId: order.id, orderNo: order.orderNo, event, url, payload: JSON.stringify(payload).slice(0, 6000), attempt, endpointId, final: false };
  try { await assertPublicHost(url); }
  catch { await db.create('weblogs', { ...logBase, success: false, errorLabel: 'blocked_url', final: true }); return; }
  const body = JSON.stringify(payload);
  let code = null; let label = null;
  try {
    const res = await fetch(url, { method: 'POST', redirect: 'manual', signal: AbortSignal.timeout(12000), headers: { 'content-type': 'application/json', 'x-signature': hmac(secret, body), 'x-event': event }, body });
    code = res.status;
    if (code >= 500) label = `http_${code}`;
  } catch (e) { label = /timeout|abort/i.test(String(e?.name) + String(e?.message)) ? 'timeout' : 'network_error'; }
  const success = code !== null && code >= 200 && code < 300;
  const retry = !success && attempt < 6;
  await db.create('weblogs', {
    ...logBase, responseCode: code, success, errorLabel: label, final: !retry,
    nextRetryAt: retry ? db.iso(new Date(Date.now() + CFG.backoff[attempt - 1] * 1000)) : null,
  });
}

/** Re-deliver webhooks whose retry time has come (called from the scheduled run and opportunistically). */
export async function processWebhookRetries(limit = 10) {
  const due = await db.all('weblogs', [Query.equal('success', false), Query.equal('final', false), Query.lessThanEqual('nextRetryAt', db.iso()), Query.limit(limit)], limit);
  for (const log of due) {
    const claim = await db.claim('retry', log.id, null, log.id);
    if (!claim.won) continue;
    await db.update('weblogs', log.id, { final: true });
    const order = await db.get('orders', log.orderId);
    if (!order) continue;
    await sendWebhook(order, log.event, { endpointId: log.endpointId, attempt: (log.attempt || 1) + 1, force: true });
  }
  return due.length;
}

async function merchantAlert(order, event) {
  if (order.environment !== 'live') return;
  const profile = await getProfile(order.userId);
  if (!profile?.telegramAlerts) return;
  const who = order.customerName || order.customerMobile || 'a customer';
  let text = null;
  if (event === 'order.paid') text = `✅ ₹${money(order.amount)} received from ${who}\nOrder ${order.clientOrderId}${order.utr ? `\nUTR ${order.utr}` : ''}`;
  if (event === 'order.under_review') text = `🕵️ Review needed: ${who} says they paid ₹${money(order.amount)} (UTR ${order.customerUtr}).\nOpen the dashboard to approve.`;
  if (text && ['medium', 'high'].includes(order.riskLevel)) text += `\n⚠ ${order.riskLevel[0].toUpperCase()}${order.riskLevel.slice(1)} risk: ${(order.riskFlags || []).map((f) => RISK_FLAGS[f]?.[1] || f).join('; ')}`;
  if (text) await telegramSend(profile, text);
}

// ------------------------------------------------------------------ subscriptions
export function advance(sub, from) {
  const d = new Date(from); d.setUTCHours(0, 0, 0, 0);
  if (sub.interval === 'weekly') d.setUTCDate(d.getUTCDate() + 7);
  else if (sub.interval === 'yearly') { const day = d.getUTCDate(); d.setUTCDate(1); d.setUTCFullYear(d.getUTCFullYear() + 1); d.setUTCDate(Math.min(day, new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate())); }
  else { const day = d.getUTCDate(); d.setUTCDate(1); d.setUTCMonth(d.getUTCMonth() + 1); d.setUTCDate(Math.min(day, new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate())); }
  return d;
}

export async function billSubscription(sub) {
  const profile = await getProfile(sub.userId);
  const due = new Date(sub.nextDueAt);
  const today = istDayStart();
  const advanceFrom = (from) => { let n = advance(sub, from); while (n <= today) n = advance(sub, n); return n; };
  let order;
  try {
    order = await createOrder(profile, {
      amount: sub.amount, client_order_id: `SUB${sub.id.slice(-8)}-${istDate(due).replace(/-/g, '')}`, payment_account_id: sub.accountId || null,
      customer_name: sub.customerName, customer_email: sub.customerEmail, customer_mobile: sub.customerMobile, note: String(sub.planName).slice(0, 50), expiry_minutes: 4320,
    });
  } catch (e) {
    if (e instanceof ValidationError) { await db.update('subscriptions', sub.id, { nextDueAt: db.iso(due < today ? advanceFrom(due) : due) }); return null; }
    throw e;
  }
  await db.update('subscriptions', sub.id, { lastOrderId: order.id, lastBilledAt: db.iso(), nextDueAt: db.iso(advanceFrom(due)) });
  const name = profile.businessName || profile.name;
  if (sub.customerEmail && process.env.SMTP_FROM) { /* e-mail delivery is optional; Telegram below still fires */ }
  if (profile.telegramAlerts) await telegramSend(profile, `🧾 Invoice sent to ${sub.customerName}: ₹${money(order.amount)} for ${sub.planName}\n${paymentUrl(order)}`);
  void name;
  return order;
}

// ------------------------------------------------------------------ reporting
export async function ordersBetween(userId, from, to, environment = 'live') {
  return db.all('orders', [Query.equal('userId', userId), Query.equal('environment', environment), Query.greaterThanEqual('$createdAt', db.iso(from)), Query.lessThanEqual('$createdAt', db.iso(to))]);
}

export async function summary(userId, from, to) {
  const orders = await ordersBetween(userId, from, to);
  const paid = orders.filter((o) => o.status === 'paid');
  const accounts = new Map((await listAccounts(userId)).map((a) => [a.id, a.label]));
  const refunds = (await db.all('refunds', [Query.equal('userId', userId), Query.greaterThanEqual('$createdAt', db.iso(from)), Query.lessThanEqual('$createdAt', db.iso(to))])).reduce((s, r) => s + Number(r.amount), 0);
  const group = (keyFn) => { const m = {}; for (const o of paid) { const k = keyFn(o); (m[k] ??= { count: 0, total: 0 }); m[k].count++; m[k].total = Math.round((m[k].total + Number(o.amount)) * 100) / 100; } return m; };
  const count = (s) => orders.filter((o) => o.status === s).length;
  return {
    orders: orders.length, paid: paid.length, paid_total: Math.round(paid.reduce((s, o) => s + Number(o.amount), 0) * 100) / 100, refunded: Math.round(refunds * 100) / 100,
    pending: count('pending'), expired: count('expired'), review: count('review'), conversion: orders.length ? Math.round((paid.length / orders.length) * 1000) / 10 : 0,
    by_account: group((o) => accounts.get(o.accountId) || 'Unknown'),
    by_day: Object.fromEntries(Object.entries(group((o) => istDate(o.paidAt || o.createdAt))).sort(([a], [b]) => a.localeCompare(b))),
    orders_list: orders,
  };
}

export const csvSafe = (v) => { const s = String(v ?? ''); return s && '=+-@\t\r'.includes(s[0]) ? `'${s}` : s; };

// ------------------------------------------------------------------ e-mail ingest (FamX)
const MONEY = '(?:₹|Rs\\.?)\\s?([\\d,]+(?:\\.\\d{1,2})?)';
const squish = (s) => String(s || '').replace(/\s+/g, ' ').trim();
const mny = (v) => { const n = Number(String(v).replace(/,/g, '')); return Number.isFinite(n) && n > 0 ? money(n) : null; };

export function parseFamEmail(subject, body) {
  const subj = squish(subject); const text = squish(body);
  const a = subj.match(new RegExp(`^You received ${MONEY} in your FamX account$`, 'i'));
  const b = text.match(new RegExp(`successfully received ${MONEY}`, 'i'));
  if (!a || !b) return null;
  const amount = mny(a[1]);
  if (!amount || amount !== mny(b[1])) return null;
  const u = text.match(/\bUTR\s*:?\s*([A-Za-z0-9]{6,30})\b/i);
  if (!u) return null;
  const t = text.match(/transaction id\s*:?\s*([A-Za-z0-9]{6,40})/i);
  const p = text.match(new RegExp(`successfully received ${MONEY} from (.+?)(?: at \\d{1,2}:\\d{2}| Transaction ID)`, 'i'));
  const pu = text.match(/Purpose\s*:\s*(.*?)\s*(?:\.\s*If this was not done|$)/i);
  const purpose = pu ? pu[1].trim().replace(/\.$/, '').trim().slice(0, 255) || null : null;
  const d = text.match(/(\d{1,2}:\d{2})\s?([AP]M)\s+IST,?\s+(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/i);
  let paidAt = null;
  if (d) { const t2 = Date.parse(`${d[3]} ${d[4]} ${d[5]} ${d[1]} ${d[2].toUpperCase()} GMT+0530`); if (!Number.isNaN(t2)) paidAt = new Date(t2); }
  return { amount, utr: u[1].toUpperCase(), txnId: t ? t[1].toUpperCase() : null, payer: p ? p[2].trim().slice(0, 120) : null, purpose, paidAt };
}

const authentic = (res) => {
  const dom = CFG.fam.domain.replace(/\./g, '\\.');
  return new RegExp(`dkim=pass\\b[^;]*?header\\.(?:i|d)=@?(?:[a-z0-9\\-]+\\.)*${dom}(?![a-z0-9.\\-])`, 'i').test(res)
    && new RegExp(`dmarc=pass\\b[^;]*?header\\.from=${dom}(?![a-z0-9.\\-])`, 'i').test(res);
};

export async function ingestEmail(account, mail) {
  const from = (String(mail.from).match(/<([^<>\s]+)>\s*$/)?.[1] || mail.from).toLowerCase().trim();
  if (from !== CFG.fam.sender) return { status: 'ignored', reason: 'sender_not_allowed' };
  if (!authentic(String(mail.auth_results || ''))) return { status: 'rejected', reason: 'sender_not_authenticated' };
  const parsed = parseFamEmail(mail.subject, mail.body);
  if (!parsed) return { status: 'ignored', reason: 'not_a_received_payment' };
  const receivedAt = new Date(mail.received_at);
  if (Number.isNaN(receivedAt.getTime())) fail('received_at', 'Invalid date.');
  if (receivedAt < addMinutes(new Date(), -CFG.fam.maxAgeHours * 60)) return { status: 'ignored', reason: 'too_old' };
  const paidAt = parsed.paidAt || receivedAt;

  const dup = (await db.first('emailpayments', [Query.equal('accountId', account.id), Query.equal('messageId', String(mail.message_id).slice(0, 200))]))
    || (await db.first('emailpayments', [Query.equal('userId', account.userId), Query.equal('utr', parsed.utr)]));
  if (dup) return { status: 'duplicate', payment_status: dup.status };

  let payment;
  try {
    payment = await db.create('emailpayments', { userId: account.userId, accountId: account.id, messageId: String(mail.message_id).slice(0, 200), utr: parsed.utr, txnId: parsed.txnId, amount: Number(parsed.amount), payerName: parsed.payer, purpose: parsed.purpose, paidAt: db.iso(paidAt), status: 'unmatched' });
  } catch (e) { if (db.isConflict(e)) return { status: 'duplicate' }; throw e; }

  const [order, outcome] = await findOrder(account, payment, paidAt);
  if (order && await settle(order, payment)) {
    const p = await db.update('emailpayments', payment.id, { orderId: order.id, status: 'matched' });
    return { status: 'matched', payment_status: p.status, order_id: order.orderNo };
  }
  const status = outcome === 'ambiguous' ? 'ambiguous' : 'unmatched';
  await db.update('emailpayments', payment.id, { status });
  return { status, payment_status: status };
}

async function findOrder(account, payment, paidAt) {
  const skewed = addMinutes(paidAt, CFG.fam.skewMinutes);
  const base = [Query.equal('accountId', account.id), Query.equal('environment', 'live'), Query.equal('payableAmount', Number(payment.amount))];
  const legacy = [Query.equal('accountId', account.id), Query.equal('environment', 'live'), Query.isNull('payableAmount'), Query.equal('amount', Number(payment.amount))];
  const reviewed = (await db.all('orders', [...base, Query.equal('status', 'review'), Query.equal('customerUtr', payment.utr)]))[0]
    || (await db.all('orders', [...legacy, Query.equal('status', 'review'), Query.equal('customerUtr', payment.utr)]))[0];
  if (reviewed && new Date(reviewed.createdAt) <= skewed) return [reviewed, 'matched'];
  const grace = db.iso(addMinutes(new Date(), -CFG.fam.lateGraceMinutes));
  const cands = [...(await db.all('orders', base)), ...(await db.all('orders', legacy))]
    .filter((o) => new Date(o.createdAt) <= skewed && (o.status === 'pending' || (o.status === 'expired' && o.expiresAt > grace)));
  const purpose = String(payment.purpose || '');
  const named = cands.filter((o) => purpose && (purpose.toLowerCase().includes(o.orderNo.toLowerCase()) || (o.note && o.note.trim().toLowerCase() === purpose.trim().toLowerCase())));
  if (named.length === 1) return [named[0], 'matched'];
  if (named.length > 1) return [null, 'ambiguous'];
  if (/ORD[A-Z0-9]{16}/i.test(purpose)) return [null, 'unmatched'];
  if (cands.length === 1) return [cands[0], 'matched'];
  return [null, cands.length ? 'ambiguous' : 'unmatched'];
}

async function settle(order, payment) {
  try {
    if (order.status === 'review') await approveCustomerUtr(order, payment.utr);
    else await markPaid(order, payment.utr, payment.txnId, { source: 'famapp_email', utr: payment.utr, txn_id: payment.txnId, payer: payment.payerName }, 'provider');
    return true;
  } catch (e) { if (e instanceof ValidationError) return false; throw e; }
}

export async function reconcileSubmittedUtr(order) {
  const o = await refresh(order);
  if (o.status !== 'review' || !o.customerUtr || !o.accountId) return o;
  const p = (await db.all('emailpayments', [Query.equal('accountId', o.accountId), Query.equal('utr', o.customerUtr), Query.equal('status', ['unmatched', 'ambiguous'])]))[0];
  if (!p || Number(p.amount) !== Number(orderPayable(o)) || new Date(o.createdAt) > addMinutes(p.paidAt, CFG.fam.skewMinutes)) return o;
  try { await approveCustomerUtr(o, p.utr); await db.update('emailpayments', p.id, { orderId: o.id, status: 'matched' }); } catch (e) { if (!(e instanceof ValidationError)) throw e; }
  return refresh(o);
}

export { httpJson };
