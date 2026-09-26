// Minimal App Store Connect API client for the release chores that do not need a Mac.
//   ASC_KEY_ID=… ASC_ISSUER_ID=… ASC_KEY_FILE=path/AuthKey_XXXX.p8 node tools/asc.mjs <command> [args]
// Commands:
//   whoami                         list apps and bundle ids the key can see
//   bundle <identifier> <name>     register an iOS bundle id if it does not exist yet
//   builds <bundleId>              latest builds of the app (processing state)
//   listing <bundleId>             push mobile/store/listing.json (names, texts, urls, categories) + the iPhone / iPad
//                                  screenshots in mobile/store/iphone-6.9 and ipad-13 to the version being prepared
// The key never leaves this machine except as a short-lived signed token (20 min).
import { createHash, createPrivateKey, sign } from 'node:crypto';
import { readdirSync, readFileSync, statSync } from 'node:fs';

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
} else if (cmd === 'listing') {
  const [bundleId] = args;
  const L = JSON.parse(readFileSync('mobile/store/listing.json', 'utf8'));
  const app = (await api('GET', `/v1/apps?filter[bundleId]=${encodeURIComponent(bundleId)}`)).data[0];
  if (!app) throw new Error(`no app record for ${bundleId}`);

  // app-level info: name / subtitle / privacy URL per locale, categories
  const info = (await api('GET', `/v1/apps/${app.id}/appInfos`)).data.find((i) => !['READY_FOR_SALE', 'REPLACED_WITH_NEW_INFO'].includes(i.attributes.appStoreState || i.attributes.state)) || (await api('GET', `/v1/apps/${app.id}/appInfos`)).data[0];
  const infoLocs = (await api('GET', `/v1/appInfos/${info.id}/appInfoLocalizations`)).data;
  for (const [locale, t] of Object.entries(L.locales)) {
    const attrs = { name: t.name, subtitle: t.subtitle, privacyPolicyUrl: L.privacyPolicyUrl };
    const have = infoLocs.find((x) => x.attributes.locale === locale);
    if (have) await api('PATCH', `/v1/appInfoLocalizations/${have.id}`, { data: { type: 'appInfoLocalizations', id: have.id, attributes: attrs } });
    else await api('POST', '/v1/appInfoLocalizations', { data: { type: 'appInfoLocalizations', attributes: { locale, ...attrs }, relationships: { appInfo: { data: { type: 'appInfos', id: info.id } } } } });
    console.log('app info', locale, 'ok');
  }
  await api('PATCH', `/v1/appInfos/${info.id}`, { data: { type: 'appInfos', id: info.id, relationships: {
    primaryCategory: { data: { type: 'appCategories', id: L.primaryCategory } }, secondaryCategory: { data: { type: 'appCategories', id: L.secondaryCategory } } } } });
  console.log('categories', L.primaryCategory, '/', L.secondaryCategory);

  // the version being prepared: texts per locale
  const version = (await api('GET', `/v1/apps/${app.id}/appStoreVersions?filter[platform]=IOS&filter[appStoreState]=PREPARE_FOR_SUBMISSION,DEVELOPER_REJECTED,REJECTED,METADATA_REJECTED`)).data[0];
  if (!version) throw new Error('no iOS version in "Prepare for Submission"');
  await api('PATCH', `/v1/appStoreVersions/${version.id}`, { data: { type: 'appStoreVersions', id: version.id, attributes: { copyright: L.copyright } } });
  const verLocs = (await api('GET', `/v1/appStoreVersions/${version.id}/appStoreVersionLocalizations`)).data;
  const locIds = {};
  for (const [locale, t] of Object.entries(L.locales)) {
    const attrs = { description: t.description, keywords: t.keywords, promotionalText: t.promotionalText, supportUrl: L.supportUrl };
    const have = verLocs.find((x) => x.attributes.locale === locale);
    const r = have
      ? await api('PATCH', `/v1/appStoreVersionLocalizations/${have.id}`, { data: { type: 'appStoreVersionLocalizations', id: have.id, attributes: attrs } })
      : await api('POST', '/v1/appStoreVersionLocalizations', { data: { type: 'appStoreVersionLocalizations', attributes: { locale, ...attrs }, relationships: { appStoreVersion: { data: { type: 'appStoreVersions', id: version.id } } } } });
    locIds[locale] = r.data.id;
    console.log('version', version.attributes.versionString, locale, 'texts ok');
  }

  // screenshots (same images for every locale); a set is replaced only when its files changed
  const SETS = { 'iphone-6.9': 'APP_IPHONE_67', 'ipad-13': 'APP_IPAD_PRO_3GEN_129' };
  for (const [locale, locId] of Object.entries(locIds)) {
    const sets = (await api('GET', `/v1/appStoreVersionLocalizations/${locId}/appScreenshotSets?include=appScreenshots`)).data;
    for (const [dir, displayType] of Object.entries(SETS)) {
      const files = readdirSync(`mobile/store/${dir}`).filter((f) => f.endsWith('.png')).sort();
      let set = sets.find((s) => s.attributes.screenshotDisplayType === displayType);
      if (set) {
        const existing = (await api('GET', `/v1/appScreenshotSets/${set.id}/appScreenshots`)).data;
        const sums = existing.map((s) => s.attributes.sourceFileChecksum).sort().join();
        const local = files.map((f) => createHash('md5').update(readFileSync(`mobile/store/${dir}/${f}`)).digest('hex')).sort().join();
        if (sums === local) { console.log('screens', locale, dir, 'unchanged'); continue; }
        for (const s of existing) await api('DELETE', `/v1/appScreenshots/${s.id}`);
      } else {
        set = (await api('POST', '/v1/appScreenshotSets', { data: { type: 'appScreenshotSets', attributes: { screenshotDisplayType: displayType }, relationships: { appStoreVersionLocalization: { data: { type: 'appStoreVersionLocalizations', id: locId } } } } })).data;
      }
      for (const f of files) {
        const path = `mobile/store/${dir}/${f}`;
        const bytes = readFileSync(path);
        const shot = (await api('POST', '/v1/appScreenshots', { data: { type: 'appScreenshots', attributes: { fileName: f, fileSize: statSync(path).size }, relationships: { appScreenshotSet: { data: { type: 'appScreenshotSets', id: set.id } } } } })).data;
        for (const op of shot.attributes.uploadOperations) {
          const headers = Object.fromEntries(op.requestHeaders.map((h) => [h.name, h.value]));
          const res = await fetch(op.url, { method: op.method, headers, body: bytes.subarray(op.offset, op.offset + op.length) });
          if (!res.ok) throw new Error(`upload ${f} part → ${res.status}`);
        }
        await api('PATCH', `/v1/appScreenshots/${shot.id}`, { data: { type: 'appScreenshots', id: shot.id, attributes: { uploaded: true, sourceFileChecksum: createHash('md5').update(bytes).digest('hex') } } });
      }
      console.log('screens', locale, dir, files.length, 'uploaded');
    }
  }
} else {
  console.error('usage: node tools/asc.mjs whoami | bundle <identifier> <name> | builds <bundleId> | listing <bundleId>'); process.exit(2);
}
