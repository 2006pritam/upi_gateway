import { Client, TablesDB, Users, Query, ID, AppwriteException } from 'node-appwrite';
import crypto from 'node:crypto';

export const DB = 'emudra';
export { Query, ID };

let _tables = null;
let _users = null;
let _client = null;

/** Build server clients from the per-execution dynamic key (or a fixed APPWRITE_API_KEY variable). */
export function initClients(req) {
  const key = process.env.APPWRITE_API_KEY || req?.headers?.['x-appwrite-key'];
  _client = new Client()
    .setEndpoint(process.env.APPWRITE_FUNCTION_API_ENDPOINT || process.env.APPWRITE_ENDPOINT)
    .setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID || process.env.APPWRITE_PROJECT_ID)
    .setKey(key);
  _tables = new TablesDB(_client);
  _users = new Users(_client);
}
export const tables = () => _tables;
export const users = () => _users;
export const endpoint = () => process.env.APPWRITE_FUNCTION_API_ENDPOINT || process.env.APPWRITE_ENDPOINT;
export const projectId = () => process.env.APPWRITE_FUNCTION_PROJECT_ID || process.env.APPWRITE_PROJECT_ID;

export const isConflict = (e) => e instanceof AppwriteException && e.code === 409;
export const isNotFound = (e) => e instanceof AppwriteException && e.code === 404;

const jsonFields = new Set(['providerResponse', 'providerCheckout', 'riskFlags', 'redirectDomains', 'credentials', 'payload']);

function encode(data) {
  const out = {};
  for (const [k, v] of Object.entries(data)) {
    if (v === undefined) continue;
    out[k] = jsonFields.has(k) && v !== null && typeof v !== 'string' ? JSON.stringify(v) : v;
  }
  return out;
}

function decode(row) {
  if (!row) return row;
  const o = { id: row.$id, createdAt: row.$createdAt, updatedAt: row.$updatedAt };
  for (const [k, v] of Object.entries(row)) {
    if (k.startsWith('$')) continue;
    if (jsonFields.has(k) && typeof v === 'string') {
      try { o[k] = JSON.parse(v); } catch { o[k] = null; }
    } else o[k] = v;
  }
  return o;
}

export async function get(table, id) {
  try { return decode(await _tables.getRow({ databaseId: DB, tableId: table, rowId: id })); }
  catch (e) { if (isNotFound(e)) return null; throw e; }
}

export async function create(table, data, id = ID.unique()) {
  return decode(await _tables.createRow({ databaseId: DB, tableId: table, rowId: id, data: encode(data) }));
}

export async function update(table, id, data) {
  return decode(await _tables.updateRow({ databaseId: DB, tableId: table, rowId: id, data: encode(data) }));
}

export async function remove(table, id) {
  try { await _tables.deleteRow({ databaseId: DB, tableId: table, rowId: id }); } catch (e) { if (!isNotFound(e)) throw e; }
}

export async function list(table, queries = [], limit = 100) {
  const res = await _tables.listRows({ databaseId: DB, tableId: table, queries: [...queries, Query.limit(limit)] });
  return { rows: res.rows.map(decode), total: res.total };
}

export async function first(table, queries) {
  const { rows } = await list(table, queries, 1);
  return rows[0] || null;
}

/** Fetch every matching row (paged by cursor). Keep the filters tight: this reads the whole result set. */
export async function all(table, queries = [], max = 5000) {
  const out = [];
  let cursor = null;
  while (out.length < max) {
    const q = [...queries, Query.limit(100)];
    if (cursor) q.push(Query.cursorAfter(cursor));
    const res = await _tables.listRows({ databaseId: DB, tableId: table, queries: q });
    out.push(...res.rows.map(decode));
    if (res.rows.length < 100) break;
    cursor = res.rows[res.rows.length - 1].$id;
  }
  return out;
}

export async function count(table, queries = []) {
  const res = await _tables.listRows({ databaseId: DB, tableId: table, queries: [...queries, Query.limit(1)] });
  return res.total;
}

/**
 * First-writer-wins lock built on unique row IDs. Returns true if this caller created the claim.
 * Appwrite has no compare-and-set, so unique-ID inserts are the atomic primitive we rely on.
 */
export const claimId = (kind, ref) => crypto.createHash('sha1').update(`${kind}:${ref}`).digest('hex').slice(0, 32);

export async function claim(kind, ref, expiresAt = null, owner = null) {
  const id = claimId(kind, ref);
  try {
    await _tables.createRow({ databaseId: DB, tableId: 'claims', rowId: id, data: { kind, ref: String(ref).slice(0, 120), expiresAt, owner } });
    return { won: true, id };
  } catch (e) {
    if (isConflict(e)) return { won: false, id };
    throw e;
  }
}

export const releaseClaim = (id) => remove('claims', id);

export const iso = (d = new Date()) => new Date(d).toISOString();
export const now = () => new Date();
