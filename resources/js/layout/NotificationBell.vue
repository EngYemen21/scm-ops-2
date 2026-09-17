<script setup>
// Notification bell + panel. GET /notifications (scoped to the caller's roles), POST /notifications/:id/read.
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api, isApiError, queryKey } from '../api/client';
import { dir, fmtAgo, lang, t } from '../i18n';
import { entityPath } from '../router/routes';
import Icon from './Icon.vue';

const router = useRouter();
const qc = useQueryClient();
const open = ref(false);
const KEY = queryKey('/notifications');

function normalize(payload) {
  const arr = Array.isArray(payload) ? payload : Array.isArray(payload?.items) ? payload.items : [];
  return arr.map((n) => ({ id: String(n.id), textAr: n.textAr || n.text || '', textEn: n.textEn || n.text || n.textAr || '', entityType: n.entityType, entityId: n.entityId, entityNumber: n.entityNumber, read: !!n.read, at: n.at || n.createdAt || '', color: n.color }));
}
const query = useQuery({
  queryKey: KEY,
  queryFn: async () => {
    try { return { items: normalize(await api.get('/notifications', { limit: 30 })), unavailable: false }; }
    catch (e) { if (isApiError(e) && (e.status === 404 || e.status === 403)) return { items: [], unavailable: true }; throw e; }
  },
  refetchInterval: 60_000, staleTime: 30_000, retry: 0,
});
const items = computed(() => query.data.value?.items || []);
const unavailable = computed(() => !!query.data.value?.unavailable);
const unread = computed(() => items.value.filter((n) => !n.read).length);

async function markRead(ids) {
  qc.setQueryData(KEY, (d) => (d ? { ...d, items: d.items.map((n) => (ids.includes(n.id) ? { ...n, read: true } : n)) } : d));
  await Promise.all(ids.map((id) => api.post(`/notifications/${encodeURIComponent(id)}/read`).catch(() => undefined)));
  void qc.invalidateQueries({ queryKey: KEY });
}
function go(n) {
  if (!n.read) void markRead([n.id]);
  open.value = false;
  const p = entityPath(n.entityType, n.entityNumber || n.entityId);
  if (p) router.push(p);
}
const dotColor = (n) => n.color || (/فشل|تالف|حجر|fail|damag|quarantin|breakdown|عطل/i.test(n.textAr + (n.textEn || '')) ? '#b23b3b' : /اعتماد|approval|pending|بانتظار/i.test(n.textAr + (n.textEn || '')) ? '#b26a16' : '#1BC4DB');
</script>

<template>
  <button type="button" class="tb-btn" aria-label="notifications" :title="t('الإشعارات', 'Notifications')" @click="open = !open">
    <Icon name="bell" />
    <span v-if="unread > 0" class="absolute -top-1 -end-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-bad px-1 font-num text-[8.5px] font-bold text-white">{{ unread > 99 ? '99+' : unread }}</span>
  </button>
  <template v-if="open">
    <div class="fixed inset-0 z-[60]" @click="open = false" />
    <div class="notif-panel top-[52px] end-[18px]" :dir="dir">
      <div class="flex items-center px-4 pt-3 pb-2">
        <div class="flex-1 text-[12px] font-extrabold">{{ t('الإشعارات', 'Notifications') }}<span v-if="unread > 0" class="badge ms-1.5">{{ unread }}</span></div>
        <button v-if="unread > 0" type="button" class="btn ghost sm" @click="markRead(items.filter((n) => !n.read).map((n) => n.id))">{{ t('تعليم الكل كمقروء', 'Mark all read') }}</button>
      </div>
      <div class="overflow-y-auto">
        <div v-if="unavailable" class="empty">{{ t('الإشعارات غير متاحة بعد — قريبًا', 'Notifications are not available yet — soon') }}</div>
        <div v-else-if="items.length === 0" class="empty">{{ t('لا إشعارات جديدة ✓', 'No new notifications ✓') }}</div>
        <div v-for="n in items" v-else :key="n.id" class="notif-row" :class="{ unread: !n.read }" @click="go(n)">
          <span class="mt-[5px] h-2 w-2 flex-none rounded-full" :style="{ background: dotColor(n) }" />
          <div class="min-w-0 flex-1">
            <div class="text-[10.5px] leading-[1.6]" :class="n.read ? 'font-bold' : 'font-extrabold'">{{ lang === 'ar' ? n.textAr : n.textEn || n.textAr }}</div>
            <div class="cell-sub" style="direction: inherit"><span v-if="n.entityNumber" class="num">{{ n.entityNumber }} · </span>{{ fmtAgo(n.at, lang) }}</div>
          </div>
        </div>
      </div>
    </div>
  </template>
</template>
