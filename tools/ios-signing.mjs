// One-time iOS App Store signing setup through the App Store Connect API (no Mac needed):
//   1. a private key + CSR (openssl), 2. an "Apple Distribution" certificate from the CSR, 3. a .p12 of key + certificate,
//   4. an IOS_APP_STORE provisioning profile for the bundle id tied to that certificate.
// Output goes to OUT_DIR (never the repository). Prints names and ids only — never key material.
//   ASC_KEY_ID=… ASC_ISSUER_ID=… ASC_KEY_FILE=… OUT_DIR=… BUNDLE_ID=sa.b2b.ops node tools/ios-signing.mjs
import { createPrivateKey, randomBytes, sign } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const { ASC_KEY_ID, ASC_ISSUER_ID, ASC_KEY_FILE, OUT_DIR, BUNDLE_ID = 'sa.b2b.ops' } = process.env;
if (!ASC_KEY_ID || !ASC_ISSUER_ID || !ASC_KEY_FILE || !OUT_DIR) { console.error('set ASC_KEY_ID, ASC_ISSUER_ID, ASC_KEY_FILE, OUT_DIR'); process.exit(2); }
mkdirSync(OUT_DIR, { recursive: true });
const out = (f) => join(OUT_DIR, f);

const b64url = (buf) => Buffer.from(buf).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');
function token() {
  const now = Math.floor(Date.now() / 1000);
  const head = b64url(JSON.stringify({ alg: 'ES256', kid: ASC_KEY_ID, typ: 'JWT' }));
  const body = b64url(JSON.stringify({ iss: ASC_ISSUER_ID, iat: now, exp: now + 1200, aud: 'appstoreconnect-v1' }));
  const sig = sign('sha256', Buffer.from(`${head}.${body}`), { key: createPrivateKey(readFileSync(ASC_KEY_FILE)), dsaEncoding: 'ieee-p1363' });
  return `${head}.${body}.${b64url(sig)}`;
}
async function api(method, path, body) {
  const res = await fetch('https://api.appstoreconnect.apple.com' + path, { method, headers: { Authorization: `Bearer ${token()}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  const json = res.status === 204 ? {} : await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`${method} ${path.split('?')[0]} → ${res.status} ${JSON.stringify(json.errors?.map((e) => `${e.code}: ${e.detail}`) || json).slice(0, 500)}`);
  return json;
}
const openssl = (...args) => execFileSync('openssl', args, { stdio: ['ignore', 'pipe', 'pipe'] });

// 1. key + CSR (reused when re-running, so a failed later step does not waste a certificate)
if (!existsSync(out('dist.key'))) openssl('genrsa', '-out', out('dist.key'), '2048');
openssl('req', '-new', '-key', out('dist.key'), '-out', out('dist.csr'), '-subj', '/CN=B2B ops Distribution/O=B2B ops/C=SA');
const csr = readFileSync(out('dist.csr'), 'utf8').replace(/-----[^-]+-----/g, '').replace(/\s+/g, '');

// 2. distribution certificate (Apple allows a small number per team — reuse ours if it already exists)
let certId = existsSync(out('cert.id')) ? readFileSync(out('cert.id'), 'utf8').trim() : null;
if (!certId) {
  const c = await api('POST', '/v1/certificates', { data: { type: 'certificates', attributes: { certificateType: 'DISTRIBUTION', csrContent: csr } } });
  certId = c.data.id;
  writeFileSync(out('cert.id'), certId);
  writeFileSync(out('dist.cer'), Buffer.from(c.data.attributes.certificateContent, 'base64'));
  console.log('certificate created', certId, c.data.attributes.name, 'expires', c.data.attributes.expirationDate);
} else console.log('certificate reused', certId);

// 3. .p12 (key + certificate) with a random password; legacy algorithms so the macOS keychain imports it
openssl('x509', '-inform', 'DER', '-in', out('dist.cer'), '-out', out('dist.pem'));
const p12pass = existsSync(out('p12.pass')) ? readFileSync(out('p12.pass'), 'utf8').trim() : randomBytes(18).toString('base64url');
writeFileSync(out('p12.pass'), p12pass);
openssl('pkcs12', '-export', '-legacy', '-inkey', out('dist.key'), '-in', out('dist.pem'), '-out', out('dist.p12'), '-name', 'Apple Distribution', '-passout', `pass:${p12pass}`);

// 4. App Store provisioning profile for the bundle id
const bundle = (await api('GET', `/v1/bundleIds?filter[identifier]=${encodeURIComponent(BUNDLE_ID)}`)).data.find((b) => b.attributes.identifier === BUNDLE_ID);
if (!bundle) throw new Error(`bundle id ${BUNDLE_ID} is not registered`);
const profileName = `B2B ops App Store ${new Date().toISOString().slice(0, 10)}`;
const p = await api('POST', '/v1/profiles', { data: { type: 'profiles', attributes: { name: profileName, profileType: 'IOS_APP_STORE' }, relationships: { bundleId: { data: { type: 'bundleIds', id: bundle.id } }, certificates: { data: [{ type: 'certificates', id: certId }] } } } });
writeFileSync(out('appstore.mobileprovision'), Buffer.from(p.data.attributes.profileContent, 'base64'));
writeFileSync(out('profile.name'), profileName);
console.log('profile created', p.data.id, profileName, p.data.attributes.profileState, 'expires', p.data.attributes.expirationDate);
console.log('files in', OUT_DIR, ': dist.p12, p12.pass, appstore.mobileprovision, profile.name');
