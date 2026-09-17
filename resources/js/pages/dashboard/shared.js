// Helpers shared by the dashboard-domain pages (dash / tower / exc / activity / reports / settings).
// Port of the reference `_shared.tsx`: label maps + pure helpers live here, the visual bits are the sibling
// components (SevChip, StateChip, SlaBadge, ExceptionActions, ExceptionActionModal, ListRow, Ago, CardTitle …).
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { bi } from '@/i18n';
import { entityPath } from '@/router/routes';
import { ROLE_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';

// ---------------------------------------------------------------- severity / kind / role
/** Severity pills: حرج/Critical white-on-red · عالٍ/High amber · متوسط/Medium neutral. */
export const SEVERITY = {
  c: { ar: 'حرج', en: 'Critical', fg: '#fff', bg: '#b23b3b' },
  w: { ar: 'عالٍ', en: 'High', fg: '#b26a16', bg: '#fbf0dd' },
  i: { ar: 'متوسط', en: 'Medium', fg: '#55506a', bg: '#F1EFF6' },
};
/** Select options of the three severities. */
export const SEVERITY_OPTIONS = [{ v: 'c', l: SEVERITY.c }, { v: 'w', l: SEVERITY.w }, { v: 'i', l: SEVERITY.i }];

/** Exception kind labels (API `kind` values). */
export const KIND_LABELS = {
  damage: { ar: 'تلف', en: 'Damage' }, damaged: { ar: 'تالف', en: 'Damaged' }, rejected: { ar: 'مرفوض', en: 'Rejected' }, reject: { ar: 'رفض', en: 'Reject' },
  shortage: { ar: 'نقص', en: 'Shortage' }, missing: { ar: 'مفقود', en: 'Missing' }, wrongloc: { ar: 'موقع خاطئ', en: 'Wrong location' }, wrong: { ar: 'خطأ', en: 'Wrong' },
  wrongveh: { ar: 'مركبة خاطئة', en: 'Wrong vehicle' }, capacity: { ar: 'سعة', en: 'Capacity' }, cap: { ar: 'حمولة', en: 'Capacity' }, faildel: { ar: 'فشل تسليم', en: 'Failed delivery' },
  failed: { ar: 'فشل', en: 'Failed' }, partial: { ar: 'تسليم جزئي', en: 'Partial delivery' }, temp: { ar: 'حرارة', en: 'Temperature' }, transfer: { ar: 'تحويل', en: 'Transfer' },
  latedep: { ar: 'تأخر انطلاق', en: 'Late departure' }, latedel: { ar: 'تأخر تسليم', en: 'Late delivery' }, breakdown: { ar: 'عطل', en: 'Breakdown' }, noshow: { ar: 'لم يحضر', en: 'No show' },
  other: { ar: 'أخرى', en: 'Other' },
};
/** Reactive when called from a template / computed (reads the current language). */
export const kindLabel = (k) => (k ? bi(KIND_LABELS[k] || { ar: k, en: k }) : '—');
export const roleLabel = (r) => (r ? bi(ROLE_LABELS[r] || { ar: r, en: r }) : '—');
/** Role options for selects (the super admin is never an owner). */
export const ROLE_OPTIONS = Object.entries(ROLE_LABELS).filter(([k]) => k !== 'super').map(([v, l]) => ({ v, l }));

// ---------------------------------------------------------------- exception rows
/**
 * Exception row (GET /exceptions, /exceptions/:number):
 * { id, number, kind, severity, status, ownerRole, slaHours, entityType, entityNumber, documentType, documentNumber,
 *   textAr, textEn, resolution, createdBy, createdAt, acknowledgedAt, resolvedAt,
 *   sla: { dueAt, leftMin, breached, labelAr }, events: [{ id, fromStatus, toStatus, username, note, at }] }
 */
export const excText = (e, lang) => (lang === 'ar' ? e.textAr : e.textEn || e.textAr);

/** SLA countdown label — the API's `sla.labelAr` for Arabic; the English form is derived from `leftMin`. */
export function slaLabel(sla, slaHours, lang) {
  if (!sla) return '—';
  if (lang === 'ar') return sla.labelAr;
  const m = Math.abs(sla.leftMin);
  const hm = m >= 60 ? `${Math.floor(m / 60)}h ${m % 60}m` : `${m}m`;
  return sla.breached ? `SLA breached by ${hm}` : `SLA ${slaHours ?? ''}h · ${hm} left`;
}

/**
 * The API gives `sla.leftMin` at fetch time — recompute it from `dueAt` against the local clock (the exception page
 * calls this every 30 s). Resolved exceptions keep the API value.
 */
export function liveSlaOf(e) {
  if (!e?.sla || e.status === 'resolved') return e?.sla;
  const leftMin = Math.round((new Date(e.sla.dueAt).getTime() - Date.now()) / 60000);
  const m = Math.abs(leftMin);
  const labelAr = leftMin < 0 ? `SLA متجاوز بـ ${m} د` : `SLA ${e.slaHours}h · متبقٍ ${m >= 60 ? `${Math.floor(m / 60)} س ${m % 60} د` : `${m} د`}`;
  return { ...e.sla, leftMin, breached: leftMin < 0, labelAr };
}

/** Deep link of the exception's related entity (order / shipment / trip …) if one is known. */
export const relatedPath = (e) => entityPath(e.entityType, e.entityNumber) || entityPath(e.documentType, e.documentNumber);

// ---------------------------------------------------------------- ack / resolve
/**
 * State of the ack / resolve note modal:
 *   const exc = useExceptionActions();
 *   <ExceptionActions :row="e" @act="(mode) => exc.open(mode, e.number)" />        (hidden without `exception.manage`)
 *   <ExceptionActionModal :mode="exc.state.value?.mode" :number="exc.state.value?.number" @close="exc.close()" @done="…" />
 */
export function useExceptionActions() {
  const auth = useAuth();
  /** { mode: 'ack' | 'resolve', number } | null */
  const state = ref(null);
  return {
    state,
    manage: auth.can('exception.manage'),
    open: (mode, number) => { state.value = { mode, number }; },
    close: () => { state.value = null; },
  };
}

// ---------------------------------------------------------------- integrations
/** Honest integration states — "connected" only when the API says so. */
export const INTEGRATION_LABELS = {
  connected: { ar: 'متصل ✓', en: 'Connected ✓', fg: '#1d7a3e', bg: '#e6f9ec' },
  integration_pending: { ar: 'Integration Pending — بانتظار التكامل', en: 'Integration Pending', fg: '#b26a16', bg: '#fbf0dd' },
  error: { ar: 'خطأ في الاتصال', en: 'Error', fg: '#b23b3b', bg: '#fdecec' },
};

// ---------------------------------------------------------------- query-string state
/**
 * Filters that live in the URL (deep links such as /tower?severity=c or /activity?tab=audit&entity=EXC-1).
 *   const qs = useQueryState();  qs.get('status')  qs.setParam('status', 'ack')  qs.replace({ tab: 'audit' })
 * `setParam` drops `page` whenever another key changes, exactly like the reference.
 */
export function useQueryState() {
  const route = useRoute();
  const router = useRouter();
  const raw = (k) => { const v = route.query[k]; return Array.isArray(v) ? v[0] : v; };
  const get = (k) => raw(k) || '';
  const has = (k) => raw(k) != null;
  const replace = (query) => router.replace({ path: route.path, query });
  function setParam(k, v) {
    const n = { ...route.query };
    if (v) n[k] = v; else delete n[k];
    if (k !== 'page') delete n.page;
    return replace(n);
  }
  return { route, router, get, has, raw, replace, setParam };
}
