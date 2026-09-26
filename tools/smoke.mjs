// Smoke test of a DEPLOYED instance: signs in, calls every GET endpoint that needs no parameter plus each report,
// and fails on any 5xx / unexpected status. Read-only, safe against production.
//   SMOKE_PASSWORD=… node tools/smoke.mjs https://scm-ops-laravel.vercel.app [username=admin]
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE = (process.argv[2] || '').replace(/\/$/, '');
const USER = process.argv[3] || 'admin';
const PASS = process.env.SMOKE_PASSWORD;
if (!BASE || !PASS) { console.error('usage: SMOKE_PASSWORD=… node tools/smoke.mjs <base-url> [username]'); process.exit(2); }

const json = { 'content-type': 'application/json', accept: 'application/json' };
const login = await fetch(BASE + '/api/auth/login', { method: 'POST', headers: json, body: JSON.stringify({ username: USER, password: PASS }) });
if (!login.ok) { console.error('login failed: ' + login.status + ' ' + (await login.text()).slice(0, 200)); process.exit(1); }
const token = (await login.json()).accessToken;
const get = async (url) => {
  const t0 = Date.now();
  const r = await fetch(BASE + '/api' + url, { headers: { authorization: 'Bearer ' + token, accept: 'application/json' } });
  const text = await r.text();
  return { url, status: r.status, ms: Date.now() - t0, body: text };
};

const php = process.env.PHP_BIN || 'php';
const routes = JSON.parse(execFileSync(php, ['artisan', 'route:list', '--path=api', '--json'], { cwd: ROOT, encoding: 'utf8', maxBuffer: 1 << 26 }))
  .filter((r) => /GET/.test(r.method)).map((r) => '/' + r.uri.replace(/^api\/?/, ''))
  .filter((u) => u !== '/' && !u.includes('{') && !/^\/auth\//.test(u) && !/\/scan\//.test(u) && u !== '/search');
const reports = JSON.parse((await get('/reports')).body).map((r) => '/reports/' + r.name);
const urls = [...new Set([...routes, ...reports, '/search?q=PO&limit=5'])].sort();

const results = [];
for (let i = 0; i < urls.length; i += 6) results.push(...await Promise.all(urls.slice(i, i + 6).map(get)));
// Correct refusals, not failures: an administrator is not a driver; the attachment list needs entityType + entityId; a label needs its text.
const EXPECTED = { '/delivery/my-trips': USER === 'driver' ? 200 : 403, '/integrations/attachments': 400, '/barcodes/code128': 400, '/barcodes/qr': 400 };
// A provider-backed list answers 422 <CODE>_PENDING until that provider is configured — an honest state, not a failure.
const pending = (r) => r.status === 422 && /"code":"[A-Z_]+_PENDING"/.test(r.body);
// Non-API pages are checked by CONTENT: the SPA shell answers 200 for any path, so a stale or failed deploy would
// otherwise look healthy (that is how a week of failed Vercel builds went unnoticed).
const PAGES = [['/privacy', 'Privacy Policy — B2B ops'], ['/manifest.webmanifest', '"short_name"'], ['/', 'rel="manifest"']];
for (const [path, needle] of PAGES) {
  const t0 = Date.now();
  const r = await fetch(BASE + path);
  const body = await r.text();
  results.push({ url: `[page] ${path}`, status: r.ok && body.includes(needle) ? 200 : r.status === 200 ? 599 : r.status, ms: Date.now() - t0, body: body.includes(needle) ? '' : `missing "${needle}" — stale or failed deploy?` });
}
const bad = results.filter((r) => r.status !== (EXPECTED[r.url] ?? 200) && !pending(r));
for (const r of bad) console.log(`✗ ${r.status} GET ${r.url}  ${r.body.slice(0, 220).replace(/\s+/g, ' ')}`);
const ms = results.map((r) => r.ms).sort((a, b) => a - b);
console.log(`\n${results.length} endpoints · ${results.length - bad.length} OK · ${bad.length} failed · median ${ms[ms.length >> 1]} ms · slowest ${ms.at(-1)} ms (${results.find((r) => r.ms === ms.at(-1)).url})`);
process.exit(bad.length ? 1 : 0);
