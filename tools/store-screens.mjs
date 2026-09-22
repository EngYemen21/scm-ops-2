// Store listing material: phone screenshots (1080×2340) of the signed-in app + the Play feature graphic (1024×500).
//   node tools/store-screens.mjs [base=http://127.0.0.1:8000] [session=public/_s_admin.json]
// Output: mobile/store/. Screenshots are taken from the running system with the demo data — replace them with real
// data before publishing if you prefer.
import puppeteer from 'puppeteer-core';
import sharp from 'sharp';
import { mkdirSync, readFileSync } from 'node:fs';

const BASE = process.argv[2] || 'http://127.0.0.1:8000';
const session = JSON.parse(readFileSync(process.argv[3] || 'public/_s_admin.json', 'utf8'));
const OUT = 'mobile/store';
mkdirSync(OUT, { recursive: true });
const SHOTS = [['01-home', '/dash'], ['02-fleet-map', '/fleet'], ['03-trip', '/trip/TRP-2026-0029'], ['04-inventory', '/inv'], ['05-more', '/more']];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--ignore-gpu-blocklist'] });
const page = await browser.newPage();
await page.setViewport({ width: 360, height: 780, deviceScaleFactor: 3, isMobile: true, hasTouch: true });
await page.goto(BASE + '/login', { waitUntil: 'networkidle2', timeout: 60000 });
await page.evaluate((s) => { localStorage.setItem('scm.access', s.accessToken); localStorage.setItem('scm.refresh', s.refreshToken); }, session);
for (const [name, path] of SHOTS) {
  await page.goto(BASE + path, { waitUntil: 'networkidle2', timeout: 60000 });
  await page.waitForSelector('.map-dot', { timeout: 20000 }).catch(() => {});
  await sleep(3500);
  await page.screenshot({ path: `${OUT}/${name}.png` });
  console.log(name, '1080×2340');
}
await browser.close();

// feature graphic: logo on the brand dark with the tagline
const logo = await sharp('public/logo-white.png').resize({ width: 520 }).png().toBuffer();
const tag = Buffer.from(`<svg width="1024" height="500"><text x="512" y="360" font-family="Almarai, Arial" font-size="34" font-weight="700" fill="#A8E4EF" text-anchor="middle">عمليات سلسلة الإمداد — مخزون · مبيعات · نقل · تتبع حي</text></svg>`);
await sharp({ create: { width: 1024, height: 500, channels: 4, background: '#1E2130' } }).composite([{ input: logo, left: 252, top: 130 }, { input: tag, left: 0, top: 0 }]).png().toFile(`${OUT}/feature-graphic.png`);
console.log('feature-graphic 1024×500');
