import QRCode from 'qrcode';
import { esc, money, BRAND } from './util.js';
import * as core from './core.js';

const head = (title) => `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${esc(title)}</title><link rel="icon" href="/assets/favicon.ico"><link rel="stylesheet" href="/assets/app.css"></head>`;
const shell = (title, inner, script = '') => `${head(title)}<body class="pay-body"><main class="pay-wrap">${inner}<p class="pay-foot">Secured by ${BRAND} · UPI payments</p></main>${script ? `<script src="${script}" defer></script>` : ''}</body></html>`;

export const notFoundPage = () => shell('Not found', '<div class="card pay-card"><h1>Page not found</h1><p class="muted">This payment page does not exist or is no longer available.</p></div>');
export const errorPage = (msg) => shell('Error', `<div class="card pay-card"><h1>Something went wrong</h1><p class="muted">${esc(msg)}</p></div>`);

export async function qrSvg(text) { return QRCode.toString(text, { type: 'svg', margin: 1, width: 320, errorCorrectionLevel: 'M' }); }

const statusPill = (s) => `<span class="pill pill-${esc(s)}">${esc(s)}</span>`;

export async function checkoutPage(order, account, merchant, { error = null } = {}) {
  const hosted = core.isHosted(account);
  const pay = money(core.orderPayable(order));
  const name = merchant?.businessName || merchant?.name || BRAND;
  const header = `<div class="pay-head"><div class="pay-merchant">${esc(name)}</div><div class="pay-amount">₹${pay}</div>${order.note ? `<div class="muted">${esc(order.note)}</div>` : ''}<div class="muted small">Order ${esc(order.orderNo)}</div></div>`;
  const adjusted = core.orderPayable(order) !== money(order.amount)
    ? `<div class="notice">Please pay exactly <b>₹${pay}</b>. The few extra paise let us match your payment automatically.</div>` : '';
  const err = error ? `<div class="alert alert-error">${esc(error)}</div>` : '';
  const data = `data-order="${esc(order.orderNo)}" data-status="${esc(order.status)}" data-expires="${esc(order.expiresAt || '')}"`;

  if (order.status === 'paid') {
    return shell('Payment received', `<div class="card pay-card" ${data}>${header}<div class="big-ok">✓</div><h2>Payment received</h2><p class="muted">Thank you! Your payment was confirmed${order.utr ? ` (UTR ${esc(order.utr)})` : ''}.</p><div class="row"><a class="btn" href="/pay/${esc(order.orderNo)}/receipt">View receipt</a></div></div>`, '/assets/pay.js');
  }
  if (order.status === 'review') {
    return shell('Payment under review', `<div class="card pay-card" ${data}>${header}<h2>Waiting for confirmation</h2><p class="muted">You submitted UTR <b>${esc(order.customerUtr)}</b>. The merchant will confirm it shortly. You can keep this page open.</p></div>`, '/assets/pay.js');
  }
  const accepts = ['manual', 'fam_email'].includes(account?.provider) && (order.utrAttempts || 0) < core.CFG.maxUtrAttempts;
  const utrForm = accepts ? `<form method="post" action="/pay/${esc(order.orderNo)}/utr" class="utr-form"><label class="label">Already paid? Enter the 12-digit UTR from your UPI app</label><div class="row"><input class="input" name="utr" inputmode="numeric" maxlength="14" placeholder="123456789012" required><button class="btn">Submit</button></div></form>` : '';
  if (order.status === 'expired') {
    return shell('Payment expired', `<div class="card pay-card" ${data}>${header}<h2>This payment link has expired</h2><p class="muted">Please ask the merchant for a new link. If you already paid, enter your UTR below.</p>${err}${order.reviewNote ? `<div class="alert alert-error">${esc(order.reviewNote)}</div>` : ''}${core.CFG && utrForm && (order.expiresAt && new Date(order.expiresAt) > new Date(Date.now() - core.CFG.utrGraceMinutes * 60000)) ? utrForm : ''}</div>`, '/assets/pay.js');
  }
  if (order.status === 'failed') return shell('Payment failed', `<div class="card pay-card">${header}<h2>Payment failed</h2></div>`);

  if (hosted) {
    return shell('Pay securely', `<div class="card pay-card" ${data}>${header}${err}<form method="post" action="/pay/${esc(order.orderNo)}/start"><button class="btn btn-lg btn-block">Pay ₹${pay} securely</button></form><p class="muted small center">You will be taken to ${esc(account.provider === 'phonepe_pg' ? 'PhonePe' : 'Paytm')} to complete the payment.</p><div class="timer muted small center" id="timer"></div></div>`, '/assets/pay.js');
  }

  const intent = core.upiIntentUrl(order, account);
  const apps = [['Google Pay', 'com.google.android.apps.nbu.paisa.user'], ['PhonePe', 'com.phonepe.app'], ['Paytm', 'net.one97.paytm'], ['BHIM', 'in.org.npci.upiapp']];
  const buttons = apps.map(([n, p]) => `<a class="btn btn-ghost app-btn" data-android="${esc(core.androidIntentUrl(order, account, p))}" data-generic="${esc(intent)}" href="${esc(intent)}">${n}</a>`).join('');
  return shell('Pay with UPI', `<div class="card pay-card" ${data} data-intent="${esc(intent)}">${header}${adjusted}${err}
    <div class="qr"><img src="/pay/${esc(order.orderNo)}/qr.svg" alt="UPI QR code" width="240" height="240"></div>
    <p class="muted small center">Scan with any UPI app, or open your app below</p>
    <div class="apps">${buttons}</div>
    <div class="upi-id"><span class="muted small">UPI ID</span><code>${esc(account.upiId)}</code><button type="button" class="btn btn-ghost btn-sm" data-copy="${esc(account.upiId)}">Copy</button></div>
    <div class="status-line"><span class="spinner"></span> Waiting for payment… <span id="timer" class="muted"></span></div>${utrForm}</div>`, '/assets/pay.js');
}

export function receiptPage(order, account, merchant) {
  const rows = [['Merchant', merchant?.businessName || merchant?.name], ['Order', order.orderNo], ['Reference', order.clientOrderId], ['Amount', `₹${money(order.amount)}`], ['Paid on', order.paidAt ? new Date(order.paidAt).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' }) : ''], ['UTR', order.utr], ['Paid to', account?.upiId || account?.label], ['Status', 'Paid']];
  return shell('Receipt', `<div class="card pay-card"><div class="big-ok">✓</div><h1>Payment receipt</h1><table class="kv">${rows.filter((r) => r[1]).map(([k, v]) => `<tr><td class="muted">${esc(k)}</td><td>${esc(v)}</td></tr>`).join('')}</table><div class="row"><button class="btn" onclick="window.print()">Print</button></div></div>`);
}

export function linkPage(link, merchant, error) {
  const fixed = link.amount != null;
  return shell(link.title, `<div class="card pay-card"><div class="pay-merchant">${esc(merchant?.businessName || merchant?.name)}</div><h1>${esc(link.title)}</h1>${link.description ? `<p class="muted">${esc(link.description)}</p>` : ''}${fixed ? `<div class="pay-amount">₹${money(link.amount)}</div>` : ''}${error ? `<div class="alert alert-error">${esc(error)}</div>` : ''}
  <form method="post" action="/p/${esc(link.id)}">${fixed ? '' : '<label class="label">Amount (₹)</label><input class="input" name="amount" type="number" min="1" max="100000" step="0.01" required>'}
  <label class="label">Your name (optional)</label><input class="input" name="customer_name" maxlength="100">
  <label class="label">Mobile (optional)</label><input class="input" name="customer_mobile" maxlength="20" inputmode="tel">
  <button class="btn btn-lg btn-block mt">Continue to pay</button></form></div>`);
}

export function sandboxPage(order, merchant, { error = null, done = null } = {}) {
  const sim = order.status !== 'pending' || order.sandboxSimulatedAt;
  const buttons = [['success', 'Simulate success'], ['failed', 'Simulate failure'], ['pending', 'Leave pending'], ['expired', 'Simulate expiry'], ['timeout', 'Simulate timeout']]
    .map(([k, l]) => `<form method="post" action="/sandbox/pay/${esc(order.orderNo)}/simulate"><input type="hidden" name="outcome" value="${k}"><button class="btn ${k === 'success' ? '' : 'btn-ghost'} btn-block">${l}</button></form>`).join('');
  return shell('Sandbox payment', `<div class="card pay-card"><div class="notice">TEST MODE — no real money moves.</div><div class="pay-head"><div class="pay-merchant">${esc(merchant?.businessName || merchant?.name)}</div><div class="pay-amount">₹${money(order.amount)}</div><div class="muted small">Order ${esc(order.orderNo)} ${statusPill(order.status)}</div></div>${error ? `<div class="alert alert-error">${esc(error)}</div>` : ''}${done ? `<div class="alert alert-ok">${esc(done)}</div>` : ''}${sim ? '<p class="muted center">This test order has been simulated.</p>' : `<div class="stack">${buttons}</div>`}</div>`);
}

export function payRedirectPage(checkout) {
  const fields = Object.entries(checkout.fields || {}).map(([k, v]) => `<input type="hidden" name="${esc(k)}" value="${esc(v)}">`).join('');
  return shell('Redirecting…', `<div class="card pay-card"><h2>Redirecting to the payment page…</h2><form id="f" method="post" action="${esc(checkout.url)}">${fields}<noscript><button class="btn">Continue</button></noscript></form></div>`, '/assets/redirect.js');
}
