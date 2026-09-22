// Build of the SAME client for the native apps (Capacitor): a static index.html with relative asset paths, bundled into
// mobile/www and packaged into the Android / iOS shells. The app talks to the server named in VITE_API_ORIGIN.
//   VITE_API_ORIGIN=https://scm-ops-laravel.vercel.app npx vite build --config vite.mobile.config.js
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  root: 'mobile',
  base: './',
  publicDir: 'public',
  plugins: [vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }), tailwindcss()],
  resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
  build: { outDir: 'www', emptyOutDir: true, target: ['es2019', 'chrome80', 'safari13'] },
  server: { fs: { allow: ['..'] } },
});
