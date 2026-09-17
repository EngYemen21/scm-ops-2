// Notifications of the caller's roles for the "notifications" tab of the activity page.
// Same query key, normalised shape and 404 / 403 tolerance as the topbar bell (layout/NotificationBell.vue keeps its
// query private), so both read one cache entry:  { items: Notification[], unavailable: boolean }
//   Notification = { id, textAr, textEn, entityType, entityId, entityNumber, read, at, color }
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api, isApiError, queryKey } from '@/api/client';

function normalize(payload) {
  const arr = Array.isArray(payload) ? payload : Array.isArray(payload?.items) ? payload.items : [];
  return arr.map((n) => ({ id: String(n.id), textAr: n.textAr || n.text || '', textEn: n.textEn || n.text || n.textAr || '', entityType: n.entityType, entityId: n.entityId, entityNumber: n.entityNumber, read: !!n.read, at: n.at || n.createdAt || '', color: n.color }));
}

/** `const notif = useNotifications(); notif.items.value, notif.unread.value, notif.unavailable.value, notif.loading.value` */
export function useNotifications() {
  const query = useQuery({
    queryKey: queryKey('/notifications'),
    queryFn: async () => {
      try { return { items: normalize(await api.get('/notifications', { limit: 30 })), unavailable: false }; }
      catch (e) { if (isApiError(e) && (e.status === 404 || e.status === 403)) return { items: [], unavailable: true }; throw e; }
    },
    refetchInterval: 60_000, staleTime: 30_000, retry: 0,
  });
  const items = computed(() => query.data.value?.items || []);
  return {
    items,
    unread: computed(() => items.value.filter((n) => !n.read).length),
    unavailable: computed(() => !!query.data.value?.unavailable),
    loading: query.isLoading,
    error: query.error,
  };
}
