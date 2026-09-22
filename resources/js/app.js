import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { VueQueryPlugin } from '@tanstack/vue-query';
import App from './App.vue';
import { queryClient } from './api/client';
import { router } from './router';
import { toast } from './stores/ui';
import { t } from './i18n';
import { setupNative } from './composables/native';
import '../css/app.css';

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.use(VueQueryPlugin, { queryClient });

// A failure in one handler must never look like "the button did nothing".
app.config.errorHandler = (err, _instance, info) => {
  console.error('[ui-error]', info, err);
  toast.say(t('حدث خطأ غير متوقع في الواجهة', 'Unexpected interface error'), 3200);
};

// After a deploy the hashed chunks of the previous build are gone: a tab that still runs the old build fails to lazy-load
// a page. Reload once so the browser picks up the new build instead of showing a blank screen (guarded against loops).
const reloadOnce = (why) => {
  const key = 'scm.reloaded-for-stale-build';
  if (sessionStorage.getItem(key) === location.pathname) { console.error('[boot] stale build, already reloaded once', why); return; }
  sessionStorage.setItem(key, location.pathname);
  location.reload();
};
window.addEventListener('vite:preloadError', (e) => { e.preventDefault(); reloadOnce('preload'); });
router.onError((err) => {
  if (/(Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module)/i.test(String(err))) reloadOnce(err);
  else console.error('[router]', err);
});

app.mount('#app');
document.documentElement.dataset.mounted = '1';
void setupNative(router);
