import { DB, tables } from './db.js';

const s = (key, size = 255, o = {}) => ({ t: 'string', key, size, ...o });
const i = (key) => ({ t: 'int', key });
const f = (key) => ({ t: 'float', key });
const b = (key, def = false) => ({ t: 'bool', key, def });
const d = (key) => ({ t: 'datetime', key });

export const SCHEMA = {
  profiles: {
    cols: [s('email'), s('name'), s('role', 20), s('businessName'), s('phone', 40), b('isActive', true), s('webhookUrl', 500), s('sandboxWebhookUrl', 500),
      s('rotationMode', 20), s('redirectDomains', 2000), s('telegramBotToken', 255, { encrypt: true }), s('telegramChatId', 64), b('telegramAlerts'), b('dailySummary'), d('lastSummaryAt')],
    idx: [['email', 'key', ['email']], ['role', 'key', ['role']]],
  },
  accounts: {
    cols: [s('userId', 36), s('provider', 30), s('label', 100), s('upiId', 100), s('payeeName', 100), s('credentials', 4000, { encrypt: true }), b('isActive', true), b('isDefault'), f('dailyLimit'), i('failureCount'), d('unhealthyUntil')],
    idx: [['userId', 'key', ['userId']]],
  },
  apikeys: {
    cols: [s('userId', 36), s('name', 100), s('key', 64), s('secret', 200, { encrypt: true }), s('environment', 10), s('webhookUrl', 500), d('lastUsedAt'), s('lastUsedIp', 64), d('revokedAt')],
    idx: [['key', 'unique', ['key']], ['userId', 'key', ['userId']]],
  },
  orders: {
    cols: [s('userId', 36), s('orderNo', 32), s('accountId', 36), s('clientOrderId', 64), s('environment', 10), f('amount'), f('payableAmount'), s('status', 12),
      s('customerName', 100), s('customerEmail', 255), s('customerMobile', 20), s('note', 100), s('redirectUrl', 500), s('linkId', 36), s('createdIp', 64), s('apiKeyId', 36),
      d('expiresAt'), d('paidAt'), s('utr', 40), s('customerUtr', 40), d('utrSubmittedAt'), i('utrAttempts'), s('reviewNote', 500), s('providerTxnId', 100),
      s('providerResponse', 8000), s('providerCheckout', 4000), s('verifiedBy', 20), d('lastCheckedAt'), d('sandboxSimulatedAt'), s('riskFlags', 500), s('riskLevel', 10)],
    idx: [['orderNo', 'unique', ['orderNo']], ['clientUnique', 'unique', ['userId', 'environment', 'clientOrderId']], ['userId', 'key', ['userId']],
      ['status', 'key', ['status']], ['accountId', 'key', ['accountId']], ['expiresAt', 'key', ['expiresAt']]],
  },
  refunds: { cols: [s('userId', 36), s('orderId', 36), f('amount'), s('reason', 255)], idx: [['orderId', 'key', ['orderId']], ['userId', 'key', ['userId']]] },
  links: { cols: [s('userId', 36), s('title', 120), s('description', 500), f('amount'), s('note', 50), s('accountId', 36), b('isActive', true)], idx: [['userId', 'key', ['userId']]] },
  subscriptions: {
    cols: [s('userId', 36), s('planName', 100), f('amount'), s('interval', 10), s('customerName', 100), s('customerEmail', 255), s('customerMobile', 20), d('nextDueAt'), s('status', 10),
      s('accountId', 36), s('lastOrderId', 36), d('lastBilledAt')],
    idx: [['userId', 'key', ['userId']], ['nextDueAt', 'key', ['nextDueAt']]],
  },
  endpoints: { cols: [s('userId', 36), s('url', 500), s('environment', 10)], idx: [['userId', 'key', ['userId']]] },
  weblogs: {
    cols: [s('userId', 36), s('orderId', 36), s('orderNo', 32), s('event', 30), s('url', 500), s('payload', 6000), i('responseCode'), b('success'), i('attempt'), s('errorLabel', 30), d('nextRetryAt'), s('endpointId', 36), b('final')],
    idx: [['orderId', 'key', ['orderId']], ['userId', 'key', ['userId']], ['retry', 'key', ['success', 'final']]],
  },
  emailpayments: {
    cols: [s('userId', 36), s('accountId', 36), s('messageId', 200), s('utr', 40), s('txnId', 60), f('amount'), s('payerName', 120), s('purpose', 255), d('paidAt'), s('status', 12), s('orderId', 36)],
    idx: [['msg', 'unique', ['accountId', 'messageId']], ['userUtr', 'unique', ['userId', 'utr']], ['accountId', 'key', ['accountId']]],
  },
  claims: { cols: [s('kind', 20), s('ref', 120), d('expiresAt'), s('owner', 36)], idx: [] },
  audit: { cols: [s('adminId', 36), s('action', 40), s('targetId', 36), s('meta', 500)], idx: [] },
};

async function ensureColumn(table, c) {
  const base = { databaseId: DB, tableId: table, key: c.key, required: false };
  const T = tables();
  if (c.t === 'string') return T.createStringColumn({ ...base, size: c.size, encrypt: !!c.encrypt });
  if (c.t === 'int') return T.createIntegerColumn({ ...base, xdefault: 0 });
  if (c.t === 'float') return T.createFloatColumn(base);
  if (c.t === 'bool') return T.createBooleanColumn({ ...base, xdefault: c.def });
  if (c.t === 'datetime') return T.createDatetimeColumn(base);
}

/** Idempotent: safe to call repeatedly until it reports { ready: true }. */
export async function ensureSchema() {
  const T = tables();
  const log = [];
  try { await T.get({ databaseId: DB }); } catch { await T.create({ databaseId: DB, name: 'e-Mudra' }); log.push('database created'); }

  let ready = true;
  for (const [table, def] of Object.entries(SCHEMA)) {
    try { await T.getTable({ databaseId: DB, tableId: table }); }
    catch { await T.createTable({ databaseId: DB, tableId: table, name: table, permissions: [], rowSecurity: false }); log.push(`table ${table}`); }

    const have = new Map((await T.listColumns({ databaseId: DB, tableId: table })).columns.map((c) => [c.key, c.status]));
    for (const c of def.cols) {
      if (!have.has(c.key)) { await ensureColumn(table, c); log.push(`column ${table}.${c.key}`); ready = false; }
      else if (have.get(c.key) !== 'available') ready = false;
    }
    if (!ready) continue;
    const haveIdx = new Set((await T.listIndexes({ databaseId: DB, tableId: table })).indexes.map((x) => x.key));
    for (const [key, type, cols] of def.idx) {
      if (!haveIdx.has(key)) { await T.createIndex({ databaseId: DB, tableId: table, key, type, columns: cols }); log.push(`index ${table}.${key}`); ready = false; }
    }
  }
  return { ready, log };
}
