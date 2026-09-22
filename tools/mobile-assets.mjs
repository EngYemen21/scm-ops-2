// Source images for the app icon and splash screen (mobile/assets/), composed from the brand logo:
//   node tools/mobile-assets.mjs && npm run mobile:assets
// icon.png 1024×1024 (logo on the brand dark), icon-foreground / icon-background for Android adaptive icons, splash 2732×2732.
import sharp from 'sharp';
import { mkdirSync } from 'node:fs';

const DARK = '#1E2130';
const out = 'mobile/assets';
mkdirSync(out, { recursive: true });

async function logoResized(width) {
  return sharp('public/logo-white.png').resize({ width, withoutEnlargement: false }).png().toBuffer();
}
async function compose(size, logoWidth, file, background = DARK) {
  const logo = await logoResized(logoWidth);
  const meta = await sharp(logo).metadata();
  await sharp({ create: { width: size, height: size, channels: 4, background } })
    .composite([{ input: logo, left: Math.round((size - meta.width) / 2), top: Math.round((size - meta.height) / 2) }])
    .png().toFile(`${out}/${file}`);
  console.log(file, size, 'logo', meta.width, 'x', meta.height);
}
await compose(1024, 800, 'icon.png');
await compose(1024, 800, 'icon-only.png');
await compose(1024, 640, 'icon-foreground.png', { r: 0, g: 0, b: 0, alpha: 0 }); // adaptive: transparent foreground, safe zone 66%
await sharp({ create: { width: 1024, height: 1024, channels: 4, background: DARK } }).png().toFile(`${out}/icon-background.png`);
await compose(2732, 900, 'splash.png');
await compose(2732, 900, 'splash-dark.png');
