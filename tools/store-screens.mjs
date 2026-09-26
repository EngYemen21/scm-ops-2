// Store listing screenshots at the exact sizes the stores require, plus the Play feature graphic.
//   node tools/store-screens.mjs [base=http://127.0.0.1:8000] [session=public/_s_admin.json]
// Output: mobile/store/<device>/NN-name.png. Uses the demo data; screens that show live vehicle positions (fleet map)
// are left out on purpose — real plates and locations do not belong in a public listing.
import puppeteer from 'puppeteer-core';
import sharp from 'sharp';
import { mkdirSync, readFileSync } from 'node:fs';

const BASE = process.argv[2] || 'http://127.0.0.1:8000';
const session = JSON.parse(readFileSync(process.argv[3] || 'public/_s_admin.json', 'utf8'));
const OUT = 'mobile/store';
const SHOTS = [['01-home', '/dash'], ['02-inventory', '/inv'], ['03-trip', '/trip/TRP-2026-0029'], ['04-receiving', '/receiving'], ['05-products', '/products']];
// viewport × scale = the store's pixel size
const DEVICES = {
  'iphone-6.9': { width: 440, height: 956, deviceScaleFactor: 3, isMobile: true, hasTouch: true }, // 1320 × 2868 (App Store 6.9")
  'ipad-13': { width: 1032, height: 1376, deviceScaleFactor: 2, isMobile: true, hasTouch: true }, // 2064 × 2752 (App Store 13")
  'android-phone': { width: 360, height: 780, deviceScaleFactor: 3, isMobile: true, hasTouch: true }, // 1080 × 2340 (Play)
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--ignore-gpu-blocklist'] });
for (const [device, viewport] of Object.entries(DEVICES)) {
  mkdirSync(`${OUT}/${device}`, { recursive: true });
  const page = await browser.newPage();
  await page.setViewport(viewport);
  await page.goto(BASE + '/login', { waitUntil: 'networkidle2', timeout: 60000 });
  await page.evaluate((s) => { localStorage.setItem('scm.access', s.accessToken); localStorage.setItem('scm.refresh', s.refreshToken); }, session);
  for (const [name, path] of SHOTS) {
    await page.goto(BASE + path, { waitUntil: 'networkidle2', timeout: 60000 });
    await page.waitForSelector('.map-dot', { timeout: 15000 }).catch(() => {});
    await sleep(3500);
    const file = `${OUT}/${device}/${name}.png`;
    await page.screenshot({ path: file });
    const m = await sharp(file).metadata();
    console.log(device, name, `${m.width}×${m.height}`);
  }
  await page.close();
}
await browser.close();

// Play feature graphic: logo on the brand dark with the tagline
const logo = await sharp('public/logo-white.png').resize({ width: 520 }).png().toBuffer();
const tag = Buffer.from(`<svg width="1024" height="500"><text x="512" y="360" font-family="Almarai, Arial" font-size="34" font-weight="700" fill="#A8E4EF" text-anchor="middle">عمليات سلسلة الإمداد — مخزون · مبيعات · نقل · تتبع حي</text></svg>`);
await sharp({ create: { width: 1024, height: 500, channels: 4, background: '#1E2130' } }).composite([{ input: logo, left: 252, top: 130 }, { input: tag, left: 0, top: 0 }]).png().toFile(`${OUT}/feature-graphic.png`);
console.log('feature-graphic 1024×500');
