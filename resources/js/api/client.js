// API client for `/api`: JSON, bearer auth, automatic refresh on 401, idempotent POSTs, typed ApiError,
// and the vue-query helpers every page uses (useList / useGet / useAction / useInvalidate).
//
// Session rules (learned the hard way — keep them):
//  - the access token is persisted, so a page reload reuses it instead of rotating the refresh token;
//  - concurrent 401s share ONE refresh call;
//  - the user is only logged out when the server REJECTS the refresh token, never on a network hiccup;
//  - action POSTs without a payload still send `{}`.
import { computed, ref, toValue } from 'vue';
import { keepPreviousData, QueryClient, useQuery, useQueryClient } from '@tanstack/vue-query';
import { bi, getLang } from '../i18n';
import { toast } from '../stores/ui';

// ---------------------------------------------------------------- tokens
const RT_KEY = 'scm.refresh';
const AT_KEY = 'scm.access';
const ls = {
  get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
  set: (k, v) => { try { if (v) localStorage.setItem(k, v); else localStorage.removeItem(k); } catch { /* ignore */ } },
};
/** JWT exp (ms) without verifying — only to decide whether a stored token is worth reusing. */
const jwtExpMs = (tok) => { try { const p = JSON.parse(atob(tok.split('.')[1].replace(/-/g, '+').replace(/_/g, '/'))); return typeof p.exp === 'number' ? p.exp * 1000 : 0; } catch { return 0; } };

let accessToken = ls.get(AT_KEY);
if (accessToken && jwtExpMs(accessToken) - Date.now() < 30_000) accessToken = null; // expired / about to expire → boot refreshes

export const tokens = {
  get access() { return accessToken; },
  setAccess(tok) { accessToken = tok; ls.set(AT_KEY, tok); },
  get refresh() { return ls.get(RT_KEY); },
  setRefresh(tok) { ls.set(RT_KEY, tok); },
  clear() { accessToken = null; ls.set(AT_KEY, null); ls.set(RT_KEY, null); },
};
/** True when the refresh endpoint itself rejected the token (as opposed to a network / server hiccup). */
let refreshRejected = false;

const unauthorizedListeners = new Set();
/** Called when the session can no longer be refreshed (the auth store drops the user → login page). */
export function onUnauthorized(fn) { unauthorizedListeners.add(fn); return () => unauthorizedListeners.delete(fn); }

// ---------------------------------------------------------------- errors
function categoryOf(status) {
  if (status === 0) return 'NETWORK';
  if (status === 400) return 'VALIDATION';
  if (status === 401) return 'UNAUTHORIZED';
  if (status === 403) return 'FORBIDDEN';
  if (status === 404) return 'NOT_FOUND';
  if (status === 409) return 'CONFLICT';
  if (status === 422) return 'BUSINESS_RULE';
  return status < 500 ? 'VALIDATION' : 'SYSTEM';
}

/** { status, category, code, message (ar), messageEn, details, requestId } */
export class ApiError extends Error {
  constructor(init) {
    super(init.message || init.messageEn || `HTTP ${init.status}`);
    this.name = 'ApiError';
    this.status = init.status;
    this.category = init.category || categoryOf(init.status);
    this.code = init.code || `HTTP_${init.status}`;
    this.messageEn = init.messageEn || this.message;
    this.details = init.details;
    this.requestId = init.requestId;
  }
  /** Message in the current (or given) UI language. */
  localized(l = getLang()) { return l === 'en' ? this.messageEn || this.message : this.message || this.messageEn; }
  /** `details` `[{path,message}]` → `{ 'lines.0.qty': 'msg' }`. */
  fieldErrors() {
    const out = {};
    const d = this.details;
    if (Array.isArray(d)) { for (const it of d) if (it && typeof it === 'object' && it.path) out[String(it.path)] = String(it.message || this.localized()); }
    else if (d && typeof d === 'object') { for (const [k, v] of Object.entries(d)) if (typeof v === 'string') out[k] = v; }
    return out;
  }
}
export const isApiError = (e) => e instanceof ApiError;
export function errorMessage(e, l = getLang()) {
  if (isApiError(e)) return e.localized(l);
  if (e instanceof Error) return e.message;
  if (e && typeof e === 'object' && e.message) return String(e.message);
  return l === 'ar' ? 'خطأ غير متوقع' : 'Unexpected error';
}

// ---------------------------------------------------------------- request
export const API_BASE = '/api';

export function buildQuery(params) {
  if (!params) return '';
  const sp = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue;
    if (Array.isArray(v)) v.forEach((x) => sp.append(k, String(x)));
    else sp.set(k, String(v));
  }
  const s = sp.toString();
  return s ? `?${s}` : '';
}

/** opts: { params, body, headers, signal, auth (false = no bearer) } */
export async function request(method, path, opts = {}) {
  const url = API_BASE + (path.startsWith('/') ? path : `/${path}`) + buildQuery(opts.params);
  const headers = { Accept: 'application/json', ...(opts.headers || {}) };
  const isForm = typeof FormData !== 'undefined' && opts.body instanceof FormData;
  if (opts.body === undefined && (method === 'POST' || method === 'PUT' || method === 'PATCH')) opts = { ...opts, body: {} };
  if (opts.body !== undefined && !isForm) headers['Content-Type'] = 'application/json';
  if (opts.auth !== false && accessToken) headers.Authorization = `Bearer ${accessToken}`;
  headers['Accept-Language'] = getLang();
  let res;
  try {
    res = await fetch(url, { method, headers, body: opts.body === undefined ? undefined : isForm ? opts.body : JSON.stringify(opts.body), signal: opts.signal, credentials: 'same-origin' });
  } catch (e) {
    if (e?.name === 'AbortError') throw e;
    throw new ApiError({ status: 0, category: 'NETWORK', code: 'NETWORK', message: 'تعذر الاتصال بالخادم', messageEn: 'Cannot reach the server' });
  }
  if (res.status === 401 && opts.auth !== false && !opts._retried && !/^\/auth\/(login|refresh|logout)/.test(path)) {
    const ok = await refreshAccess();
    if (ok) return request(method, path, { ...opts, _retried: true });
    if (refreshRejected || !tokens.refresh) { tokens.clear(); unauthorizedListeners.forEach((l) => l()); }
  }
  const ct = res.headers.get('content-type') || '';
  const payload = res.status === 204 ? undefined : ct.includes('json') ? await res.json().catch(() => undefined) : await res.text().catch(() => undefined);
  if (!res.ok) {
    const p = payload && typeof payload === 'object' ? payload : {};
    throw new ApiError({ status: res.status, category: p.category, code: p.code, message: p.message || (typeof payload === 'string' && payload ? payload.slice(0, 200) : undefined), messageEn: p.messageEn, details: p.details, requestId: p.requestId || res.headers.get('x-request-id') || undefined });
  }
  return payload;
}

// ---------------------------------------------------------------- refresh
let refreshing = null;
/** Exchange the stored refresh token for new tokens (deduplicated across concurrent 401s). Resolves null on failure. */
export function refreshAccess() {
  if (refreshing) return refreshing;
  const rt = tokens.refresh;
  if (!rt) return Promise.resolve(null);
  refreshing = (async () => {
    try {
      refreshRejected = false;
      const r = await request('POST', '/auth/refresh', { body: { refreshToken: rt }, auth: false });
      tokens.setAccess(r.accessToken); tokens.setRefresh(r.refreshToken);
      return r;
    } catch (e) {
      refreshRejected = e instanceof ApiError && (e.status === 401 || e.status === 400);
      if (refreshRejected) tokens.clear();
      return null;
    } finally { refreshing = null; }
  })();
  return refreshing;
}

// ---------------------------------------------------------------- api facade
export const uuid = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16); }));

export const api = {
  get: (path, params, signal) => request('GET', path, { params, signal }),
  post: (path, body, params) => request('POST', path, { body, params }),
  put: (path, body) => request('PUT', path, { body }),
  patch: (path, body) => request('PATCH', path, { body }),
  del: (path, body) => request('DELETE', path, { body }),
  /** POST with an `Idempotency-Key` header: a double click / retry replays the first response instead of repeating the action. */
  postIdempotent: (path, body, key = uuid()) => request('POST', path, { body, headers: { 'Idempotency-Key': key } }),
  upload: (path, form) => request('POST', path, { body: form }),
};

// ---------------------------------------------------------------- vue-query
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 10_000, refetchOnWindowFocus: false, retry: (count, err) => !(isApiError(err) && err.status > 0 && err.status < 500) && count < 1 },
    mutations: { retry: 0 },
  },
});

/** `['api', ...segments, params]` so `invalidate(['inventory'])` hits every query under `/inventory/**`. */
export const queryKey = (path, params) => ['api', ...String(path).split('?')[0].split('/').filter(Boolean), ...(params ? [params] : [])];

/**
 * Paged list. `path` and `params` may be plain values, refs or getters — the query re-runs when they change.
 *   const list = useList('/sales/orders', () => ({ page: page.value, status: status.value }));
 *   list.data.value → { items, total, page, pageSize, pages }; list.isLoading.value; list.error.value
 * opts: { enabled, refetchInterval, staleTime, keepPrevious (default true), retry }
 */
export function useList(path, params, opts = {}) {
  return useQuery({
    queryKey: computed(() => queryKey(toValue(path), toValue(params))),
    queryFn: ({ signal }) => api.get(toValue(path), toValue(params), signal),
    enabled: computed(() => !!toValue(path) && (toValue(opts.enabled) ?? true)),
    refetchInterval: opts.refetchInterval,
    staleTime: opts.staleTime,
    placeholderData: opts.keepPrevious === false ? undefined : keepPreviousData,
    retry: opts.retry === false ? 0 : undefined,
  });
}

/** Single resource. A null / empty path disables the query: `useGet(() => (sel.value ? `/sales/orders/${sel.value}` : null))`. */
export function useGet(path, params, opts = {}) {
  return useQuery({
    queryKey: computed(() => queryKey(toValue(path) || '__disabled__', toValue(params))),
    queryFn: ({ signal }) => api.get(toValue(path), toValue(params), signal),
    enabled: computed(() => !!toValue(path) && (toValue(opts.enabled) ?? true)),
    refetchInterval: opts.refetchInterval,
    staleTime: opts.staleTime,
    retry: opts.retry === false ? 0 : undefined,
  });
}

/** `const invalidate = useInvalidate(); invalidate(['inventory', 'sales/orders'])` — omit for everything. */
export function useInvalidate() {
  const qc = useQueryClient();
  return (prefixes) => {
    if (!prefixes || prefixes === 'all') return qc.invalidateQueries({ queryKey: ['api'] });
    return Promise.all(prefixes.map((p) => qc.invalidateQueries({ queryKey: queryKey(p) })));
  };
}

/**
 * Mutation wrapper used by every action button:
 *   const act = useAction();
 *   const po = await act.run(() => api.postIdempotent(`/procurement/po/${n}/approve`), { success: t('اعتُمد', 'Approved'), invalidate: ['procurement'] });
 * Shows a toast on success/error, invalidates queries, exposes `pending` and the last `error` (refs) for
 * <ErrorBanner :error="act.error.value" />. `run` resolves undefined on failure (never throws) unless `throwOnError`.
 * options: { success: string | {ar,en} | (data) => string | false, invalidate: string[] | 'all' | false, errorToast: boolean }
 */
export function useAction(defaults = {}) {
  const invalidate = useInvalidate();
  const pending = ref(false);
  const error = ref(null);
  async function run(fn, opts = {}) {
    const o = { ...defaults, ...opts };
    pending.value = true; error.value = null;
    try {
      const data = await fn();
      if (o.success !== false) toast.say(typeof o.success === 'function' ? o.success(data) : o.success ?? { ar: 'تم ✓', en: 'Done ✓' });
      if (o.invalidate !== false) void invalidate(o.invalidate);
      return data;
    } catch (e) {
      const err = isApiError(e) ? e : new ApiError({ status: 0, category: 'SYSTEM', code: 'CLIENT', message: errorMessage(e, 'ar'), messageEn: errorMessage(e, 'en') });
      error.value = err;
      if (o.errorToast !== false) toast.say(err.category === 'FORBIDDEN' ? { ar: 'صلاحية غير كافية', en: 'Insufficient permission' } : err.localized(), 3200);
      if (o.throwOnError) throw err;
      return undefined;
    } finally { pending.value = false; }
  }
  return { run, pending, error, clearError: () => { error.value = null; } };
}

export { bi };
