// UI sweep of the client on a LOCAL server: every sidebar page, every tab, one record of every entity page, every
// "+ …" form (opened and closed — nothing is ever submitted) and the first row of every table, as one role at one
// screen size. It reports what a person would have to stumble on:
//   JS exceptions · failed API calls · error banners · render crashes · placeholders that never go away · raw
//   `undefined` / `NaN` / `[object Object]` / `Invalid Date` in visible text · a page that scrolls sideways ·
//   a drawer that does not close.
//
//   npm i --no-save puppeteer-core            (once; uses the Chrome already installed — nothing is downloaded)
//   php artisan serve --port=8000             (the server under test; never production)
//   SWEEP_PASSWORD=<SEED_PASSWORD> node tools/ui-sweep.cjs
//
// Environment: BASE (http://127.0.0.1:8000) · SWEEP_USER (admin) · W / H (1440 × 900; W < 768 = the phone layout) ·
//   NAV=finance,procurement (only these pages) · ONLY=entities · FORMS=0 / ROWS=0 (pages and tabs only) ·
//   CHROME=<path to chrome> · OUT=<folder for screenshots + report.json>.
// A full run before a release: admin at 1440 with forms, then W=1280 / 1600 / 1920 and W=390 with FORMS=0 ROWS=0,
// then SWEEP_USER=sales|wm|inv|proc|disp|worker|driver|gm|finance with FORMS=0 ROWS=0. Exit code 1 on any finding.
const fs = require('fs');
const path = require('path');

let puppeteer;
try { puppeteer = require('puppeteer-core'); } catch { console.error('puppeteer-core is not installed — run: npm i --no-save puppeteer-core'); process.exit(2); }

const BASE = (process.env.BASE || 'http://127.0.0.1:8000').replace(/\/$/, '');
if (!/^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(BASE)) { console.error('local servers only — this sweep opens forms and clicks rows'); process.exit(2); }
const USER = process.env.SWEEP_USER || 'admin';
const PASS = process.env.SWEEP_PASSWORD;
const W = +(process.env.W || 1440); const H = +(process.env.H || 900);
const MOBILE = W < 768;
const OUT = process.env.OUT || path.join(require('os').tmpdir(), `ui-sweep-${USER}-${W}`);
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);
const FORMS = process.env.FORMS !== '0' && !MOBILE;
const ROWS = process.env.ROWS !== '0' && !MOBILE;
const NAV = (process.env.NAV || 'dash,tower,sales,consol,products,whs,inv,ledger,batches,receiving,procurement,finance,picking,dispatch,trips,fleet,ttower,returns,counts,reports,activity,settings,wreceive,driver,itower').split(',');
const CHROME = process.env.CHROME || [
  'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium-browser', '/usr/bin/chromium',
].find((p) => fs.existsSync(p));
if (!CHROME) { console.error('Chrome not found — set CHROME=<path>'); process.exit(2); }
fs.mkdirSync(OUT, { recursive: true });

const findings = [];
const visited = [];
let ctx = '';
const add = (kind, detail) => { findings.push({ where: ctx, kind, detail: String(detail).replace(/\s+/g, ' ').slice(0, 420) }); };
// Correct refusals and honest "provider not configured" answers are not failures.
const expectedStatus = (url, status, body) => (status === 422 && /"code":"[A-Z_]+_PENDING"/.test(body))
  || (status === 403 && /"code":"NOT_A_DRIVER"/.test(body));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  if (!PASS) { console.error('SWEEP_PASSWORD missing (the SEED_PASSWORD of the local .env)'); process.exit(2); }
  const login = await fetch(BASE + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ username: USER, password: PASS }) });
  if (!login.ok) { console.error('login failed ' + login.status + ' ' + (await login.text()).slice(0, 200)); process.exit(2); }
  const session = await login.json();
  const api = async (u) => { const r = await fetch(BASE + '/api' + u, { headers: { authorization: 'Bearer ' + session.accessToken, accept: 'application/json' } }); return r.ok ? r.json() : null; };

  // software WebGL so the maps render in a headless browser
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: [`--window-size=${W},${H}`, '--lang=ar', '--no-first-run', '--enable-unsafe-swiftshader', '--use-angle=swiftshader'] });
  const page = await browser.newPage();
  await page.setViewport({ width: W, height: H, isMobile: MOBILE, hasTouch: MOBILE, deviceScaleFactor: 1 });

  let pending = 0; let lastActivity = Date.now();
  page.on('request', (r) => { if (r.url().includes('/api/')) { pending++; lastActivity = Date.now(); } });
  const done = (r) => { if (r.url().includes('/api/')) { pending = Math.max(0, pending - 1); lastActivity = Date.now(); } };
  page.on('requestfinished', done);
  page.on('requestfailed', (r) => { done(r); const f = r.failure()?.errorText || ''; if (!/ERR_ABORTED/.test(f) && r.url().startsWith(BASE)) add('REQUEST_FAILED', `${r.method()} ${r.url().replace(BASE, '')} ${f}`); });
  page.on('response', async (r) => {
    const u = r.url(); if (!u.includes('/api/') || r.status() < 400) return;
    let body = ''; try { body = (await r.text()).slice(0, 260); } catch { /* gone */ }
    if (!expectedStatus(u, r.status(), body)) add('API_' + r.status(), `${r.request().method()} ${u.replace(BASE, '')} ${body}`);
  });
  page.on('pageerror', (e) => add('JS_EXCEPTION', e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) add('CONSOLE_ERROR', m.text()); });

  const idle = async (max = 20000) => {
    const t0 = Date.now();
    await sleep(250);
    while (Date.now() - t0 < max) { if (pending === 0 && Date.now() - lastActivity > 450) return true; await sleep(100); }
    add('SLOW_OR_STUCK_REQUESTS', `still ${pending} API request(s) pending after ${max} ms`); pending = 0; return false;
  };
  const overlays = () => page.evaluate(() => [...document.querySelectorAll('.drawer, .modal')].filter((el) => el.getBoundingClientRect().width > 0).length);

  const audit = async () => {
    const r = await page.evaluate(() => {
      const vis = (el) => { const b = el.getBoundingClientRect(); const s = getComputedStyle(el); return b.width > 0 && b.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
      const text = document.body.innerText || '';
      const bad = [];
      const re = /(^|[\s:(/·—-])(undefined|NaN|\[object Object\]|Invalid Date|null)(?=$|[\s).,/·—%-])/gm;
      let m; while ((m = re.exec(text)) && bad.length < 6) bad.push(text.slice(Math.max(0, m.index - 45), m.index + 45));
      const main = document.querySelector('main') || document.body;
      return {
        bad,
        banners: [...document.querySelectorAll('.banner[role="alert"]')].filter(vis).map((b) => b.innerText.slice(0, 200)),
        // the map's own cover is excluded: software WebGL in a long headless session may never finish drawing a map
        skel: [...document.querySelectorAll('.skel:not(.map-cover)')].filter(vis).length,
        textLen: (main.innerText || '').trim().length,
        drawers: [...document.querySelectorAll('.drawer, .modal')].filter(vis).length,
        overflowX: document.documentElement.scrollWidth - window.innerWidth,
        building: /هذه الصفحة قيد البناء|This page is being built/.test(text),
        crash: /حدث خطأ أثناء عرض هذه الصفحة|Something went wrong while rendering this page/.test(text),
        url: location.pathname + location.search,
      };
    });
    for (const b of r.bad) add('RAW_VALUE_IN_TEXT', b);
    for (const b of r.banners) add('ERROR_BANNER', b);
    if (r.crash) add('RENDER_CRASH', 'error boundary shown');
    if (r.building) add('PAGE_NOT_BUILT', 'ComingSoon placeholder');
    if (r.skel) add('STUCK_SKELETON', `${r.skel} loading placeholder(s) still visible after the page went idle`);
    if (r.textLen < 15) add('EMPTY_PAGE', `main text length ${r.textLen}`);
    if (r.overflowX > 2) add('PAGE_SCROLLS_SIDEWAYS', `document is ${r.overflowX}px wider than the viewport`);
    return r;
  };

  const closeOverlays = async () => {
    for (let i = 0; i < 4 && (await overlays()); i++) { await page.keyboard.press('Escape'); await sleep(350); }
    if (!(await overlays())) return true;
    await page.evaluate(() => document.querySelector('.x-btn[aria-label="close"]')?.click()); await sleep(350);
    if (await overlays()) { add('OVERLAY_DOES_NOT_CLOSE', 'drawer / modal still open after Escape ×4 and ✕'); return false; }
    add('ESCAPE_DID_NOT_CLOSE', 'closed only with the ✕ button');
    return true;
  };

  const shot = async (name) => { try { await page.screenshot({ path: path.join(OUT, name.replace(/[^\w.-]+/g, '_') + '.png'), fullPage: true }); } catch { /* ignore */ } };

  /** Opens every "+ …" button that opens a drawer or a modal, checks the form renders, closes it. Never submits. */
  const formsPass = async (label) => {
    const labels = await page.evaluate(() => [...document.querySelectorAll('button')].filter((b) => !b.closest('.drawer, .modal, form') && !b.disabled && b.getBoundingClientRect().width > 0 && /^\+/.test((b.innerText || '').trim())).map((b) => b.innerText.trim()));
    for (const text of [...new Set(labels)]) {
      const base = ctx; ctx = `${label} » form "${text}"`;
      const clicked = await page.evaluate((t) => { const b = [...document.querySelectorAll('button')].find((x) => !x.closest('.drawer, .modal, form') && (x.innerText || '').trim() === t && x.getBoundingClientRect().width > 0); if (b) { b.click(); return true; } return false; }, text);
      if (clicked) {
        await sleep(450); await idle(12000);
        const r = await audit();
        visited.push(ctx + (r.drawers ? '' : '  (inline action — no drawer)'));
        if (!(await closeOverlays())) { await page.goto(BASE + r.url, { waitUntil: 'domcontentloaded' }); await idle(); }
      }
      ctx = base;
    }
  };

  /** Clicks the first clickable row of every table in view; audits the drawer or the page it opens. */
  const rowsPass = async (label, url) => {
    const n = await page.evaluate(() => [...document.querySelectorAll('.gt')].filter((g) => g.querySelector('.gt-row.click') && !g.closest('.drawer, .modal')).length);
    for (let i = 0; i < Math.min(n, 3); i++) {
      const base = ctx; ctx = `${label} » first row of table ${i + 1}`;
      const ok = await page.evaluate((idx) => { const g = [...document.querySelectorAll('.gt')].filter((x) => x.querySelector('.gt-row.click') && !x.closest('.drawer, .modal'))[idx]; const row = g?.querySelector('.gt-row.click'); if (!row) return false; (row.querySelector('.gt-cell.gt-title') || row).click(); return true; }, i);
      if (ok) {
        await sleep(450); await idle(12000);
        const r = await audit(); visited.push(ctx + ' → ' + r.url);
        await closeOverlays();
        if (r.url !== url) { await page.goto(BASE + url, { waitUntil: 'domcontentloaded' }); await idle(); }
      }
      ctx = base;
    }
  };

  const settle = async () => {
    // the route's lazy chunk may still be loading: wait until the page has rendered something before judging it
    await page.waitForFunction(() => { const m = document.querySelector('main'); return !!m && m.innerText.trim().length > 14; }, { timeout: 15000 }).catch(() => {});
    await idle();
  };

  const visit = async (url, label) => {
    ctx = label;
    await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
    await settle();
    const r = await audit(); visited.push(label + '  ' + r.url);
    await shot(label);
    if (FORMS) await formsPass(label);
    if (ROWS) await rowsPass(label, r.url);
    const tabBar = () => [...document.querySelectorAll('[role="tablist"]')].find((b) => !b.closest('.drawer, .modal') && b.getBoundingClientRect().width > 0);
    const tabCount = await page.evaluate(`(${tabBar})()?.querySelectorAll('[role="tab"]:not([disabled])').length || 0`);
    for (let i = 1; i < tabCount; i++) {
      const tabText = await page.evaluate(`(() => { const tb = (${tabBar})()?.querySelectorAll('[role="tab"]:not([disabled])')[${i}]; if (!tb) return null; tb.click(); return (tb.innerText || '').trim().replace(/\\s+/g, ' '); })()`);
      if (tabText == null) break;
      ctx = `${label} › ${tabText}`;
      await sleep(300); await idle();
      const tr = await audit(); visited.push(ctx + '  ' + tr.url);
      await shot(`${label}--tab${i}`);
      if (FORMS) await formsPass(ctx);
      if (ROWS) await rowsPass(ctx, tr.url);
    }
  };

  // the session goes into localStorage once; the app rotates its own tokens afterwards
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.evaluate((s) => { localStorage.setItem('scm.access', s.accessToken); localStorage.setItem('scm.refresh', s.refreshToken); }, session);

  const want = (k) => !ONLY.length || ONLY.includes(k);
  if (!ONLY.length || ONLY.some((k) => NAV.includes(k))) for (const k of NAV) if (want(k)) await visit('/' + k, k);

  if (want('entities')) {
    const first = async (u, f = 'number') => { const d = await api(u); const it = (d?.items || d || [])[0]; return it ? it[f] : null; };
    const ents = [
      ['po', await first('/procurement/po?pageSize=1')], ['shipments', await first('/inbound/shipments?pageSize=1')], ['grn', await first('/inbound/grns?pageSize=1')],
      ['so', await first('/sales/orders?pageSize=1')], ['fo', await first('/fulfillment/orders?pageSize=1')], ['trip', await first('/transport/trips?pageSize=1')],
      ['rtn', await first('/returns?pageSize=1')], ['trf', await first('/inventory/transfers?pageSize=1')], ['exc', await first('/exceptions?pageSize=1')],
      ['product', await first('/products?pageSize=1', 'sku')], ['jv', await first('/finance/journals?pageSize=1')],
    ];
    for (const [k, num] of ents) {
      if (!num) { visited.push(`entity ${k}  (no record this role can list — not opened)`); continue; }
      await visit(`/${k}/${encodeURIComponent(num)}`, `entity ${k} ${num}`);
    }
  }

  await browser.close();
  const byKind = {}; for (const f of findings) byKind[f.kind] = (byKind[f.kind] || 0) + 1;
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify({ user: USER, viewport: `${W}x${H}`, visited, findings }, null, 1));
  console.log(`user ${USER} · viewport ${W}x${H} · views visited ${visited.length} · findings ${findings.length} · report ${path.join(OUT, 'report.json')}`);
  if (findings.length) console.log(JSON.stringify(byKind));
  for (const f of findings) console.log(`✗ [${f.kind}] ${f.where} — ${f.detail}`);
  process.exit(findings.length ? 1 : 0);
})().catch((e) => { console.error('SWEEP CRASHED', e); process.exit(2); });
