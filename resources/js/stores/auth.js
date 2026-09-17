// Session store: current user, permissions, login / logout, boot from stored tokens.
import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { api, onUnauthorized, refreshAccess, tokens } from '../api/client';

export const navPath = (key) => `/${key}`;

export const useAuth = defineStore('auth', () => {
  /** { id, username, nameAr, nameEn, initials, roles[], permissions[], nav[], warehouses[], driverId, mustChangePassword } */
  const user = ref(null);
  /** false until boot() finished (the router waits for it). */
  const ready = ref(false);
  let booting = null;

  const isSuper = computed(() => !!user.value?.roles.includes('super'));
  const homePath = computed(() => navPath(user.value?.nav?.[0] || 'dash'));

  /** `can('po.approve')` or `can(['a', 'b'])` (any of). The server enforces the same permissions on every request. */
  function can(permission) {
    if (!user.value) return false;
    if (isSuper.value) return true;
    const perms = user.value.permissions || [];
    return Array.isArray(permission) ? permission.some((p) => perms.includes(p)) : perms.includes(permission);
  }
  function hasRole(role) {
    if (!user.value) return false;
    return Array.isArray(role) ? role.some((r) => user.value.roles.includes(r)) : user.value.roles.includes(role);
  }

  /** Restores the session once: reuse a still-valid access token, otherwise refresh (retrying transient failures). */
  function boot() {
    if (booting) return booting;
    booting = (async () => {
      let u = null;
      if (tokens.access) { try { u = await api.get('/auth/me'); } catch { u = null; } }
      if (!u && tokens.refresh) {
        for (let attempt = 0; attempt < 3 && !u && tokens.refresh; attempt++) {
          if (attempt) await new Promise((res) => setTimeout(res, 1500 * attempt));
          const r = await refreshAccess();
          if (r) u = r.user;
        }
      }
      if (u) user.value = u; else tokens.clear();
      ready.value = true;
    })();
    return booting;
  }
  onUnauthorized(() => { user.value = null; });

  async function login(username, password) {
    const r = await api.post('/auth/login', { username, password });
    tokens.setAccess(r.accessToken); tokens.setRefresh(r.refreshToken);
    user.value = r.user;
    return r.user;
  }
  async function logout() {
    const rt = tokens.refresh;
    try { if (rt) await api.post('/auth/logout', { refreshToken: rt }); } catch { /* ignore */ }
    tokens.clear();
    user.value = null;
  }
  async function refreshMe() {
    try { user.value = await api.get('/auth/me'); return user.value; } catch { return null; }
  }

  return { user, ready, isSuper, homePath, can, hasRole, boot, login, logout, refreshMe };
});
