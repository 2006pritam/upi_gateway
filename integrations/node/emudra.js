// e-Mudra Node.js helper. No dependencies (Node 18+).
//
//   const { EMudra } = require('./emudra');
//   const api = new EMudra('__GATEWAY_URL__', process.env.EMUDRA_KEY, process.env.EMUDRA_SECRET);
//   const order = await api.createOrder(499, 'INV1001', { customer_name: 'Asha' });
//   res.redirect(order.payment_url);
//
// Webhook (use express.raw so the body is untouched):
//   app.post('/webhook', express.raw({ type: '*/*' }), (req, res) => {
//     const event = api.verifyWebhook(req.body, req.get('X-Signature'));
//     if (!event) return res.sendStatus(401);
//     if (event.event === 'order.paid') markPaid(event.data.client_order_id);
//     res.sendStatus(200);
//   });
const crypto = require('crypto');

class EMudra {
  constructor(baseUrl, key, secret) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.key = key;
    this.secret = secret;
  }

  async #request(method, path, body) {
    const res = await fetch(this.baseUrl + path, {
      method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Api-Key': this.key, 'X-Api-Secret': this.secret },
      body: body ? JSON.stringify(body) : undefined,
    });
    const json = await res.json().catch(() => null);
    if (!res.ok || !json || !json.success) throw new Error(`e-Mudra API error (${res.status}): ${json?.message ?? 'no response'}`);
    return json.data;
  }

  createOrder(amount, clientOrderId, extra = {}) {
    return this.#request('POST', '/api/v1/orders', { amount, client_order_id: clientOrderId, ...extra });
  }

  getOrder(id) {
    return this.#request('GET', '/api/v1/orders/' + encodeURIComponent(id));
  }

  /** Returns the parsed event or null if the signature is wrong. Still confirm with getOrder() before delivering goods. */
  verifyWebhook(rawBody, signature) {
    const expected = crypto.createHmac('sha256', this.secret).update(rawBody).digest('hex');
    const a = Buffer.from(expected);
    const b = Buffer.from(String(signature || ''));
    return a.length === b.length && crypto.timingSafeEqual(a, b) ? JSON.parse(rawBody.toString()) : null;
  }
}

module.exports = { EMudra };
