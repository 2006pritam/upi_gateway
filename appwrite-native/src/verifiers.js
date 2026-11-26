import { httpJson, toPaise, paytmChecksum, money } from './util.js';
import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';

export const PROVIDERS = {
  paytm_business: { label: 'Paytm for Business (auto verify)', hosted: false },
  fampay_email: { label: 'FamPay (email auto verify)', hosted: false },
  phonepe_pg: { label: 'PhonePe PG (official)', hosted: true },
  paytm_pg: { label: 'Paytm PG (official)', hosted: true },
  fam_email: { label: 'FamPay / FamX (auto verify by e-mail)', hosted: false },
  manual: { label: 'Any UPI ID (manual verify)', hosted: false },
};
export const IMAP_HOSTS = ['imap.gmail.com', 'imap.googlemail.com', 'outlook.office365.com', 'imap-mail.outlook.com', 'imap.mail.yahoo.com'];

const PHONEPE = {
  sandbox: { token: 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token', base: 'https://api-preprod.phonepe.com/apis/pg-sandbox' },
  production: { token: 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token', base: 'https://api.phonepe.com/apis/pg' },
};
const PAYTM_PG = { sandbox: 'https://securestage.paytmpayments.com', production: 'https://secure.paytmpayments.com' };

const pending = (raw = {}) => ({ paid: false, raw });
const paid = (utr, txnId, raw) => ({ paid: true, utr, txnId, raw });
const payable = (order) => order.payableAmount ?? order.amount;

// PhonePe OAuth tokens are cached for the life of the warm container.
const tokenCache = new Map();
async function phonepeToken(account, urls, refresh) {
  const c = account.credentials;
  const key = `${account.id}:${c.client_id}`;
  const hit = tokenCache.get(key);
  if (!refresh && hit && hit.exp > Date.now()) return hit.token;
  const r = await httpJson(urls.token, { method: 'POST', form: { client_id: c.client_id, client_version: c.client_version, client_secret: c.client_secret, grant_type: 'client_credentials' } });
  if (!r.ok || !r.json?.access_token) throw new Error('PhonePe login failed. Check Client ID, Client Secret, Client Version and environment.');
  tokenCache.set(key, { token: r.json.access_token, exp: Math.max(Date.now() + 60000, (Number(r.json.expires_at || 0) * 1000) - 60000) });
  return r.json.access_token;
}
async function phonepeCall(account, fn) {
  const urls = PHONEPE[account.credentials.environment === 'production' ? 'production' : 'sandbox'];
  let res;
  for (const refresh of [false, true]) {
    const token = await phonepeToken(account, urls, refresh);
    res = await fn(urls.base, { authorization: `O-Bearer ${token}` });
    if (res.status !== 401) return res;
  }
  return res;
}

async function paytmSigned(account, url, body) {
  const bodyJson = JSON.stringify(body);
  const signature = paytmChecksum(bodyJson, String(account.credentials.merchant_key));
  const payload = `{"body":${bodyJson},"head":{"signature":${JSON.stringify(signature)}}}`;
  return httpJson(url, { method: 'POST', body: payload });
}
const paytmBase = (a) => PAYTM_PG[a.credentials.environment === 'production' ? 'production' : 'sandbox'];

export const verifiers = {
  async manual() { return pending(); },
  async fam_email() { return pending(); },

  async paytm_business(order, account) {
    const mid = account.credentials?.mid;
    if (!mid) return pending({ error: 'Paytm MID not configured' });
    let data;
    try {
      const u = 'https://securegw.paytm.in/merchant-status/getTxnStatus?JsonData=' + encodeURIComponent(JSON.stringify({ MID: mid, ORDERID: order.orderNo }));
      data = (await httpJson(u, { timeout: 10000 })).json || {};
    } catch (e) { return pending({ error: e.message }); }
    if (data.STATUS !== 'TXN_SUCCESS') return pending(data);
    if (data.ORDERID !== order.orderNo) return pending({ error: 'Order ID mismatch' });
    if (toPaise(data.TXNAMOUNT) !== toPaise(order.amount)) return pending({ error: 'Amount mismatch' });
    return paid(data.BANKTXNID ?? null, data.TXNID ?? null, data);
  },

  async phonepe_pg(order, account) {
    let r;
    try { r = await phonepeCall(account, (base, h) => httpJson(`${base}/checkout/v2/order/${order.orderNo}/status?details=false`, { headers: h })); }
    catch (e) { return pending({ error: e.message }); }
    const data = r.json || {};
    if (data.state !== 'COMPLETED') return pending(data);
    if (data.merchantOrderId !== order.orderNo) return pending({ error: 'Order ID mismatch' });
    if (Number(data.amount || 0) !== toPaise(order.amount)) return pending({ error: 'Amount mismatch' });
    const p = (data.paymentDetails || []).find((x) => x.state === 'COMPLETED') || {};
    return paid(p.rail?.utr ?? null, p.transactionId ?? data.orderId ?? null, data);
  },

  async paytm_pg(order, account) {
    let r;
    try { r = await paytmSigned(account, `${paytmBase(account)}/v3/order/status`, { mid: account.credentials.mid, orderId: order.orderNo }); }
    catch (e) { return pending({ error: e.message }); }
    const body = r.json?.body || {};
    if (body.resultInfo?.resultStatus !== 'TXN_SUCCESS') return pending(body);
    if (body.orderId !== order.orderNo) return pending({ error: 'Order ID mismatch' });
    if (toPaise(body.txnAmount) !== toPaise(order.amount)) return pending({ error: 'Amount mismatch' });
    return paid(body.bankTxnId ?? null, body.txnId ?? null, body);
  },

  async fampay_email(order, account, ctx) {
    const c = account.credentials || {};
    if (!c.imap_username || !c.imap_password) return pending({ error: 'FamPay IMAP credentials not configured' });
    const host = (c.imap_host || 'imap.gmail.com').toLowerCase();
    if (!IMAP_HOSTS.includes(host) || Number(c.imap_port || 993) !== 993) return pending({ error: 'IMAP host not allowed' });
    const sender = String(c.imap_sender || 'no-reply@famapp.in').toLowerCase().trim();
    const since = new Date(new Date(order.createdAt).getTime() - 86400000);
    const client = new ImapFlow({ host, port: 993, secure: true, auth: { user: c.imap_username, pass: c.imap_password }, logger: false, socketTimeout: 12000 });
    const msgs = []; const diag = {};
    try {
      await client.connect();
      const lock = await client.getMailboxLock('INBOX');
      try {
        const uids = (await client.search({ from: sender, since })) || [];
        if (!uids.length) {
          // diagnostics: which senders did the inbox receive recently?
          const any = ((await client.search({ since })) || []).slice(-12);
          const seen = new Set();
          for (const uid of any) { const m = await client.fetchOne(uid, { envelope: true }, { uid: true }); const a = m?.envelope?.from?.[0]?.address; if (a) seen.add(a.toLowerCase()); }
          diag.senders = [...seen]; diag.inboxRecent = any.length;
        }
        for (const uid of uids.slice(-15)) {
          const m = await client.fetchOne(uid, { source: true, envelope: true }, { uid: true });
          if (m?.source) msgs.push(m.source);
        }
      } finally { lock.release(); }
      await client.logout();
    } catch (e) { try { client.close(); } catch { /* ignore */ } return pending({ error: `IMAP check failed: ${String(e.responseText || e.message || e).slice(0, 160)}` }); }

    const expected = toPaise(payable(order));
    const skips = [];
    for (const source of msgs.reverse()) {
      let mail; try { mail = await simpleParser(source); } catch { skips.push('unparsable'); continue; }
      const from = (mail.from?.value?.[0]?.address || '').toLowerCase();
      if (!(from === sender || (sender.startsWith('@') && from.endsWith(sender)))) { skips.push('sender ' + from); continue; }
      const auth = String(mail.headers.get('authentication-results') || '');
      const dom = from.split('@')[1].replace(/\./g, '\\.');
      if (!(new RegExp(`\\bdkim=pass\\b[^;]*?\\bheader\\.(?:d|i)=@?(?:[a-z0-9\\-]+\\.)*${dom}(?![a-z0-9.\\-])`, 'i').test(auth) && /\bspf=pass\b/i.test(auth))) { skips.push('dkim/spf not pass: ' + auth.replace(/\s+/g, ' ').slice(0, 160)); continue; }
      const text = `${mail.subject || ''}\n${mail.text || ''}`.replace(/\s+/g, ' ').trim();
      if (!/\b(?:received|credited|paid\s+you|sent\s+you|added\s+to\s+your|money\s+received|payment\s+received)\b/i.test(text)) { skips.push('not a credit mail: ' + text.slice(0, 80)); continue; }
      if (/\b(?:you\s+paid|you\s+sent|debited\s+from|transferred\s+to)\b/i.test(text)) continue;
      const am = text.match(/(?:₹|Rs\.?|INR)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i);
      if (!am || toPaise(am[1].replace(/,/g, '')) !== expected) { skips.push('amount ' + (am ? am[1] : 'none') + ' != ' + expected / 100); continue; }
      const date = mail.date ? new Date(mail.date) : null;
      if (date && (date < new Date(new Date(order.createdAt).getTime() - 120000) || date > new Date(new Date(order.expiresAt).getTime() + 3600000))) continue;
      const um = text.match(/\b(?:UTR|UPI\s*Ref(?:erence)?(?:\s*(?:No|Number|ID))?|Ref(?:erence)?\s*(?:No|Number|ID)|Bank\s*Ref(?:erence)?|Transaction\s*ID|Txn\s*ID)\s*[:#-]?\s*([0-9]{12})\b/i) || text.match(/\b([0-9]{12})\b/);
      const utr = um ? um[1] : null;
      if (utr && (await ctx.utrTaken(order, utr))) continue;
      const byOrder = text.toLowerCase().includes(order.orderNo.toLowerCase());
      const byUtr = utr && order.customerUtr && order.customerUtr.toUpperCase() === utr.toUpperCase();
      if (!byOrder && !byUtr && !(await ctx.isOldestPendingForAmount(order))) continue;
      return paid(utr, String(mail.messageId || '').slice(0, 120) || null, { source: 'fampay_email', sender: from, subject: mail.subject, matched_by: byOrder ? 'order_no' : byUtr ? 'customer_utr' : 'amount_window' });
    }
    return pending({ info: `emails from ${sender}: ${msgs.length}`, skipped: skips.slice(0, 5), ...diag });
  },
};

/** Hosted providers send the customer off to the provider's page. */
export const hosted = {
  async phonepe_pg(order, account, returnUrl) {
    const secondsLeft = Math.floor((new Date(order.expiresAt).getTime() - Date.now()) / 1000);
    const r = await phonepeCall(account, (base, h) => httpJson(`${base}/checkout/v2/pay`, {
      method: 'POST', headers: h,
      body: {
        merchantOrderId: order.orderNo, amount: toPaise(order.amount), expireAfter: Math.max(300, Math.min(3600, secondsLeft)),
        paymentFlow: { type: 'PG_CHECKOUT', message: order.note || `Payment for ${order.clientOrderId}`, merchantUrls: { redirectUrl: returnUrl } },
        metaInfo: { udf1: order.clientOrderId },
      },
    }));
    const url = r.json?.redirectUrl;
    if (!r.ok || !url) throw new Error('PhonePe could not start the payment. Please try again.');
    return { type: 'redirect', url, expires_at: Math.floor((r.json?.expireAt ?? Date.now() + 900000) / 1000) };
  },
  async paytm_pg(order, account, returnUrl) {
    const c = account.credentials; const base = paytmBase(account);
    const userInfo = { custId: `CUST${order.userId.slice(0, 8)}X${order.orderNo}` };
    if (order.customerMobile) userInfo.mobile = order.customerMobile;
    if (order.customerEmail) userInfo.email = order.customerEmail;
    const r = await paytmSigned(account, `${base}/theia/api/v1/initiateTransaction?mid=${encodeURIComponent(c.mid)}&orderId=${order.orderNo}`, {
      requestType: 'Payment', mid: c.mid, websiteName: c.website_name || 'DEFAULT', orderId: order.orderNo, callbackUrl: returnUrl,
      txnAmount: { value: money(order.amount), currency: 'INR' }, userInfo,
    });
    const token = r.json?.body?.txnToken;
    if (r.json?.body?.resultInfo?.resultStatus !== 'S' || !token) throw new Error('Paytm could not start the payment. Please try again.');
    return { type: 'form', url: `${base}/theia/api/v1/showPaymentPage?mid=${encodeURIComponent(c.mid)}&orderId=${order.orderNo}`, fields: { mid: c.mid, orderId: order.orderNo, txnToken: token }, expires_at: Math.floor(Date.now() / 1000) + 14 * 60 };
  },
};
