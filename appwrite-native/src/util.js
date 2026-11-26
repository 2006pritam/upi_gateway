import crypto from 'node:crypto';
import dns from 'node:dns/promises';
import net from 'node:net';

export const BRAND = 'eMudra';
export const IST_OFFSET_MIN = 330;

export const randomId = (n) => {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  let o = '';
  for (const b of crypto.randomBytes(n)) o += chars[b % chars.length];
  return o;
};
export const randomToken = (n) => crypto.randomBytes(n).toString('base64url').slice(0, n);
export const hmac = (secret, body) => crypto.createHmac('sha256', secret).update(body).digest('hex');
export const safeEqual = (a, b) => {
  const x = Buffer.from(String(a)); const y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
};

export const toPaise = (v) => Math.round(Number(v || 0) * 100);
export const money = (v) => (Math.round(Number(v) * 100) / 100).toFixed(2);
export const normalizeUtr = (u) => String(u || '').replace(/\s+/g, '').toUpperCase();

export class ValidationError extends Error {
  constructor(errors, status = 422) {
    super(typeof errors === 'string' ? errors : Object.values(errors)[0]);
    this.errors = typeof errors === 'string' ? { _: errors } : errors;
    this.status = status;
  }
}
export const fail = (field, message) => { throw new ValidationError({ [field]: message }); };

export const addMinutes = (d, m) => new Date(new Date(d).getTime() + m * 60000);
export const addDays = (d, n) => new Date(new Date(d).getTime() + n * 86400000);

/** Start of the current day in India (the merchants' business day), as a Date. */
export function istDayStart(d = new Date()) {
  const shifted = new Date(new Date(d).getTime() + IST_OFFSET_MIN * 60000);
  shifted.setUTCHours(0, 0, 0, 0);
  return new Date(shifted.getTime() - IST_OFFSET_MIN * 60000);
}
export const istDate = (d) => new Date(new Date(d).getTime() + IST_OFFSET_MIN * 60000).toISOString().slice(0, 10);

export const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// ---- SSRF-safe outbound URL check for merchant webhooks ----
function isPrivateIp(ip) {
  if (net.isIPv4(ip)) {
    const [a, b] = ip.split('.').map(Number);
    return a === 10 || a === 127 || a === 0 || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127) || a >= 224;
  }
  const l = ip.toLowerCase();
  return l === '::1' || l === '::' || l.startsWith('fc') || l.startsWith('fd') || l.startsWith('fe80') || l.startsWith('::ffff:');
}
export function validateWebhookUrl(url, field = 'url') {
  let u;
  try { u = new URL(url); } catch { fail(field, 'Enter a valid URL.'); }
  if (u.protocol !== 'https:') fail(field, 'Webhook URLs must use https://.');
  if (u.port && u.port !== '443') fail(field, 'Webhook URLs must use port 443.');
  if (u.username || u.password) fail(field, 'Webhook URLs cannot contain credentials.');
  const h = u.hostname.toLowerCase();
  if (h === 'localhost' || h.endsWith('.local') || h.endsWith('.internal') || net.isIP(h.replace(/^\[|\]$/g, ''))) fail(field, 'Webhook URLs must use a public domain name.');
  return u;
}
export async function assertPublicHost(url) {
  const u = validateWebhookUrl(url);
  const addrs = await dns.lookup(u.hostname, { all: true });
  if (!addrs.length || addrs.some((a) => isPrivateIp(a.address))) fail('url', 'That host resolves to a private address.');
  return u;
}

export async function httpJson(url, { method = 'GET', headers = {}, body, form, timeout = 15000 } = {}) {
  const init = { method, headers: { accept: 'application/json', ...headers }, signal: AbortSignal.timeout(timeout), redirect: 'manual' };
  if (form) { init.body = new URLSearchParams(form).toString(); init.headers['content-type'] = 'application/x-www-form-urlencoded'; }
  else if (body !== undefined) { init.body = typeof body === 'string' ? body : JSON.stringify(body); init.headers['content-type'] ??= 'application/json'; }
  const res = await fetch(url, init);
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* non-JSON */ }
  return { status: res.status, ok: res.ok, json, text };
}

// ---- Paytm checksum (AES-128-CBC over sha256(payload|salt)+salt) ----
const PAYTM_IV = Buffer.from('@@@@&&&&####$$$$');
const SALT_CHARS = '9876543210ZYXWVUTSRQPONMLKJIHGFEDCBAabcdefghijklmnopqrstuvwxyz!@#$&_';
export function paytmChecksum(payload, key) {
  let salt = '';
  for (let i = 0; i < 4; i++) salt += SALT_CHARS[crypto.randomInt(SALT_CHARS.length)];
  const plain = crypto.createHash('sha256').update(`${payload}|${salt}`).digest('hex') + salt;
  const c = crypto.createCipheriv('aes-128-cbc', Buffer.from(key.replace(/&amp;/g, '&')), PAYTM_IV);
  return Buffer.concat([c.update(plain, 'utf8'), c.final()]).toString('base64');
}

// ---- Telegram ----
export const TELEGRAM_TOKEN = /^\d{6,12}:[A-Za-z0-9_-]{30,50}$/;
export const TELEGRAM_CHAT = /^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/;
export async function telegramSend(profile, text) {
  if (!profile?.telegramBotToken || !profile?.telegramChatId) return false;
  try {
    const r = await httpJson(`https://api.telegram.org/bot${profile.telegramBotToken}/sendMessage`, {
      method: 'POST', form: { chat_id: profile.telegramChatId, text: String(text).slice(0, 1000), disable_web_page_preview: 'true' }, timeout: 8000,
    });
    return r.ok;
  } catch { return false; }
}

export const RISK_FLAGS = {
  ip_burst: ['high', 'Many orders from one device in a few minutes'],
  mobile_unpaid: ['medium', 'Several unpaid orders for this mobile number'],
  late_payment: ['low', 'Paid after the order expired'],
  utr_retries: ['medium', 'Customer needed several UTR attempts'],
  manual_utr: ['low', 'Confirmed by hand from a customer-typed UTR'],
};
