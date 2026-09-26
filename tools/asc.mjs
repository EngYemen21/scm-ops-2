// Minimal App Store Connect API client for the release chores that do not need a Mac.
//   ASC_KEY_ID=… ASC_ISSUER_ID=… ASC_KEY_FILE=path/AuthKey_XXXX.p8 node tools/asc.mjs <command> [args]
// Commands:
//   whoami                         list apps and bundle ids the key can see
//   bundle <identifier> <name>     register an iOS bundle id if it does not exist yet
//   builds <bundleId>              latest builds of the app (processing state)
// The key never leaves this machine except as a short-lived signed token (20 min).
import { createPrivateKey, sign } from 'node:crypto';
import { readFileSync } from 'node:fs';

const { ASC_KEY_ID, ASC_ISSUER_ID, ASC_KEY_FILE } = process.env;
if (!ASC_KEY_ID || !ASC_ISSUER_ID || !ASC_KEY_FILE) { console.error('set ASC_KEY_ID, ASC_ISSUER_ID and ASC_KEY_FILE'); process.exit(2); }

const b64url = (buf) => Buffer.from(buf).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');
function token() {
  const now = Math.floor(Date.now() / 1000);
  const head = b64url(JSON.stringify({ alg: 'ES256', kid: ASC_KEY_ID, typ: 'JWT' }));
  const body = b64url(JSON.stringify({ iss: ASC_ISSUER_ID, iat: now, exp: now + 1200, aud: 'appstoreconnect-v1' }));
  const key = createPrivateKey(readFileSync(ASC_KEY_FILE));
  const sig = sign('sha256', Buffer.from(`${head}.${body}`), { key, dsaEncoding: 'ieee-p1363' });
  return `${head}.${body}.${b64url(sig)}`;
}
async function api(method, path, body) {
  const res = await fetch('https://api.appstoreconnect.apple.com' + path, {
    method, headers: { Authorization: `Bearer ${token()}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined,
  });
  const json = res.status === 204 ? {} : await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`${method} ${path} → ${res.status} ${JSON.stringify(json.errors?.map((e) => `${e.code}: ${e.detail}`) || json).slice(0, 400)}`);
  return json;
}

const [cmd, ...args] = process.argv.slice(2);
if (cmd === 'whoami') {
  const apps = await api('GET', '/v1/apps?fields[apps]=name,bundleId,sku&limit=50');
  console.log('apps:', apps.data.map((a) => `${a.attributes.name} (${a.attributes.bundleId})`).join(' · ') || 'none');
  const ids = await api('GET', '/v1/bundleIds?fields[bundleIds]=identifier,name,platform&limit=200');
  console.log('bundle ids:', ids.data.map((b) => `${b.attributes.identifier} [${b.attributes.platform}]`).join(' · ') || 'none');
} else if (cmd === 'bundle') {
  const [identifier, name] = args;
  const found = await api('GET', `/v1/bundleIds?filter[identifier]=${encodeURIComponent(identifier)}`);
  const exact = found.data.find((b) => b.attributes.identifier === identifier);
  if (exact) console.log('exists', identifier, exact.id);
  else {
    const r = await api('POST', '/v1/bundleIds', { data: { type: 'bundleIds', attributes: { identifier, name, platform: 'IOS' } } });
    console.log('registered', identifier, r.data.id);
  }
} else if (cmd === 'builds') {
  const [bundleId] = args;
  const apps = await api('GET', `/v1/apps?filter[bundleId]=${encodeURIComponent(bundleId)}`);
  if (!apps.data.length) { console.log('no app record for', bundleId); process.exit(0); }
  const b = await api('GET', `/v1/builds?filter[app]=${apps.data[0].id}&sort=-uploadedDate&limit=5&fields[builds]=version,processingState,uploadedDate`);
  console.log(b.data.map((x) => `build ${x.attributes.version} · ${x.attributes.processingState} · ${x.attributes.uploadedDate}`).join('\n') || 'no builds yet');
} else {
  console.error('usage: node tools/asc.mjs whoami | bundle <identifier> <name> | builds <bundleId>'); process.exit(2);
}
