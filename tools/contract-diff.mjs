// Contract check: compares the JSON SHAPE (field names, nesting, value types) of every GET endpoint between the
// reference API and this API. Both must be running and hold the same demo data.
//   node tools/contract-diff.mjs [refBase=http://127.0.0.1:3000/api] [newBase=http://127.0.0.1:8000/api]
// A field the reference returns but this API does not ("missing") or returns with another type ("type") is a contract
// break the client can trip over. Extra fields here are reported as a count only (harmless).
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REF = process.argv[2] || 'http://127.0.0.1:3000/api';
const NEW = process.argv[3] || 'http://127.0.0.1:8000/api';
const USER = process.env.CONTRACT_USER || 'admin';
const PASS = process.env.SEED_PASSWORD;
if (!PASS) { console.error('set SEED_PASSWORD (the demo password both systems were seeded with)'); process.exit(2); }

async function login(base) {
  const r = await fetch(base + '/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ username: USER, password: PASS }) });
  if (!r.ok) throw new Error(`login failed on ${base}: ${r.status}`);
  return (await r.json()).accessToken;
}
async function get(base, token, url) {
  const r = await fetch(base + url, { headers: { authorization: 'Bearer ' + token, accept: 'application/json' } });
  const text = await r.text();
  let body; try { body = JSON.parse(text); } catch { body = text.slice(0, 80); }
  return { status: r.status, body };
}

// ---- shape: value -> type signature; arrays merge the shapes of their elements; null is a wildcard
const typeOf = (v) => (v === null || v === undefined ? 'null' : Array.isArray(v) ? 'array' : typeof v);
function shape(v, depth = 0) {
  const t = typeOf(v);
  if (t === 'array') return { t, of: v.slice(0, 25).map((x) => shape(x, depth + 1)).reduce(merge, null) };
  if (t === 'object') return depth > 6 ? { t } : { t, keys: Object.fromEntries(Object.entries(v).map(([k, x]) => [k, shape(x, depth + 1)])) };
  return { t };
}
function merge(a, b) {
  if (!a) return b; if (!b) return a;
  if (a.t === 'null') return b; if (b.t === 'null') return a;
  if (a.t !== b.t) return a;
  if (a.t === 'object') { const keys = { ...a.keys }; for (const [k, s] of Object.entries(b.keys || {})) keys[k] = merge(keys[k], s); return { t: 'object', keys }; }
  if (a.t === 'array') return { t: 'array', of: merge(a.of, b.of) };
  return a;
}
// decimals: the reference prints "58", this API "58.00" — both strings. Numbers vs numeric strings ARE reported.
function diff(ref, neu, at, out) {
  if (!ref || ref.t === 'null' || !neu || neu.t === 'null') { if (ref && ref.t !== 'null' && !neu) out.missing.push(at); return; }
  if (ref.t !== neu.t) { out.type.push(`${at}: ${ref.t} → ${neu.t}`); return; }
  if (ref.t === 'object' && ref.keys && neu.keys) {
    out.fields += Object.keys(ref.keys).length;
    for (const k of Object.keys(ref.keys)) { if (!(k in neu.keys)) out.missing.push(`${at}.${k}`); else diff(ref.keys[k], neu.keys[k], `${at}.${k}`, out); }
    out.extra += Object.keys(neu.keys).filter((k) => !(k in ref.keys)).length;
  }
  if (ref.t === 'array') diff(ref.of, neu.of, at + '[]', out);
}

// ---- routes from Laravel
const php = process.env.PHP_BIN || 'php';
const routes = JSON.parse(execFileSync(php, ['artisan', 'route:list', '--path=api', '--json'], { cwd: ROOT, encoding: 'utf8', maxBuffer: 1 << 26 }))
  .filter((r) => /GET/.test(r.method)).map((r) => '/' + r.uri.replace(/^api\/?/, '')).filter((u) => u !== '/');
const statics = routes.filter((u) => !u.includes('{'));
const SKIP = [/^\/auth\//, /\/scan\//];
// Routes whose parameter is not the id of their own parent list: where to take a sample row from.
const SAMPLE_FROM = {
  '/inventory/products/{sku}/stock': '/products', '/inventory/trace/{referenceNumber}': '/inbound/grns',
  '/delivery/trips/{id}': '/transport/trips', '/fulfillment/trips/{trip}/loading': '/transport/trips',
  '/integrations/pods/{podId}/attachments': '/delivery/pods', '/reports/{name}': '/reports',
};
// Routes that need a query instead of a path parameter.
const FIXED = { '/search': '/search?q=PO&limit=12' };
const [rt, nt] = await Promise.all([login(REF), login(NEW)]);
const sampleCache = new Map();
async function sampleOf(listUrl) { // identifier of the first row of a list on the reference
  if (sampleCache.has(listUrl)) return sampleCache.get(listUrl);
  const r = await get(REF, rt, listUrl + (listUrl.includes('?') ? '&' : '?') + 'pageSize=5');
  const rows = Array.isArray(r.body) ? r.body : r.body?.items || [];
  const it = rows[0];
  const v = it ? (it.number ?? it.code ?? it.sku ?? it.key ?? it.name ?? it.id) : null;
  sampleCache.set(listUrl, v);
  return v;
}

const results = []; const skipped = [];
for (const route of routes.sort()) {
  if (SKIP.some((re) => re.test(route))) { skipped.push(route + ' (needs input)'); continue; }
  let url = route;
  const params = [...route.matchAll(/\{(\w+)\??\}/g)];
  if (params.length > 1) { skipped.push(route + ' (two parameters)'); continue; }
  if (FIXED[route]) url = FIXED[route];
  else if (params.length === 1) {
    const prefix = route.slice(0, route.indexOf('{')).replace(/\/$/, '');
    const list = SAMPLE_FROM[route] || (statics.includes(prefix) ? prefix : null);
    const v = list ? await sampleOf(list) : null;
    if (!v) { skipped.push(route + ' (no sample row)'); continue; }
    url = route.replace(/\{\w+\??\}/, encodeURIComponent(v));
  }
  const q = url.includes('?') ? '&' : '?';
  const [a, b] = await Promise.all([get(REF, rt, url + q + 'pageSize=5'), get(NEW, nt, url + q + 'pageSize=5')]);
  const out = { missing: [], type: [], extra: 0, fields: 0 };
  if (a.status !== b.status) out.type.push(`HTTP ${a.status} → ${b.status}`);
  else if (a.status < 400) diff(shape(a.body), shape(b.body), '$', out);
  results.push({ url, status: `${a.status}/${b.status}`, ...out });
}

const bad = results.filter((r) => r.missing.length || r.type.length);
for (const r of bad) {
  console.log(`\n✗ GET ${r.url}  [${r.status}]`);
  r.missing.slice(0, 12).forEach((m) => console.log('    missing  ' + m));
  if (r.missing.length > 12) console.log(`    … +${r.missing.length - 12} more missing`);
  r.type.slice(0, 12).forEach((m) => console.log('    type     ' + m));
}
// self-test: the comparison must notice a removed field and a changed type, or this whole report means nothing
{
  const t = { missing: [], type: [], extra: 0, fields: 0 };
  diff(shape({ a: 1, b: [{ c: 'x', d: 2 }] }), shape({ a: '1', b: [{ c: 'x' }] }), '$', t);
  if (t.missing.join() !== '$.b[].d' || t.type.length !== 1) { console.error('self-test failed', t); process.exit(2); }
}
console.log(`\n${results.reduce((n, r) => n + r.fields, 0)} reference fields checked`);
console.log(`${results.length} endpoints compared · ${results.length - bad.length} identical in shape · ${bad.length} with differences · ${skipped.length} skipped`);
if (process.env.VERBOSE) skipped.forEach((s) => console.log('  skipped ' + s));
process.exit(bad.length ? 1 : 0);
