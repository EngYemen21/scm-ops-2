// Build of the SAME client for the native apps (Capacitor): a static index.html with relative asset paths, bundled into
// mobile/www and packaged into the Android / iOS shells. The app talks to the server named in VITE_API_ORIGIN, taken
// from the environment (CI sets it in the workflow) or from mobile/.env on a workstation.
//   VITE_API_ORIGIN=https://scm-ops-laravel.vercel.app npx vite build --config vite.mobile.config.js
import { fileURLToPath, URL } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
  // Without a server address every API call would go to the app's own bundle and fail on every screen — refuse to
  // build such an app instead of shipping it.
  const origin = process.env.VITE_API_ORIGIN || loadEnv(mode, 'mobile', 'VITE_').VITE_API_ORIGIN;
  if (!/^https:\/\/[^/]+/.test(origin || '')) {
    throw new Error('VITE_API_ORIGIN is missing or not https — set it in the environment or in mobile/.env (see docs/MOBILE.md)');
  }
  return {
    root: 'mobile',
    base: './',
    publicDir: 'public',
    define: { 'import.meta.env.VITE_API_ORIGIN': JSON.stringify(origin) },
    plugins: [vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }), tailwindcss()],
    resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
    build: { outDir: 'www', emptyOutDir: true, target: ['es2019', 'chrome80', 'safari13'] },
    server: { fs: { allow: ['..'] } },
  };
});
