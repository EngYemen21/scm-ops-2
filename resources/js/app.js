import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { VueQueryPlugin } from '@tanstack/vue-query';
import App from './App.vue';
import { queryClient } from './api/client';
import { router } from './router';
import { toast } from './stores/ui';
import { t } from './i18n';
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

app.mount('#app');
