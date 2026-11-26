// «الفرص والعملاء المحتملون» — what the views of the section share: label maps (with chip colours), small formats, the
// query-string state of the page and the lookups every form and filter starts from.
// API (routes/api/crm.php): GET /crm/{lookups,board,leads,leads/{id},leads/{id}/customer,activities,report/funnel} ·
// POST /crm/leads · PATCH /crm/leads/{id} · POST /crm/leads/{id}/{move,win,lose,reopen,notes,convert,quotations,activities} ·
// PATCH /crm/activities/{id} · POST /crm/activities/{id}/{done,cancel} · POST | PATCH /crm/{stages,teams,lost-reasons,tags}.
// A record whose `owned` is true is Odoo's while the section follows Odoo: the server refuses to change what Odoo holds
// (CRM_OWNED_BY_SOURCE) and the screens then offer only what is OPS's own — notes, to-dos, the customer, quotations.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useGet } from '@/api/client';
import { bi, fmtDateOnly, fmtMoney, lang, num, t } from '@/i18n';
import { useQueryState } from '../dashboard/shared';

/** `map[key]` for a key that comes from the server: only the map's own entries count. */
const own = (map, key) => (key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);

// ---------------------------------------------------------------- labels
/** The colour pairs every status chip of the application uses (shared/constants.json). */
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, teal: { fg: '#0d7f93', bg: '#e4f8fb' }, neutral: { fg: '#55506a', bg: '#F1EFF6' },
};

/** `lead.status` — the state machine: open → won | lost, and back to open by a reopening. */
export const LEAD_STATUS = {
  open: { ar: 'مفتوحة', en: 'Open', ...TONE.blue },
  won: { ar: 'مكسوبة', en: 'Won', ...TONE.ok },
  lost: { ar: 'خاسرة', en: 'Lost', ...TONE.bad },
};
export const ARCHIVED_CHIP = { ar: 'مؤرشفة', en: 'Archived', ...TONE.neutral };
export const OWNED_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const OWNED_HELP = { ar: 'هذه الفرصة تُدار من أودو: بياناتها ومرحلتها وإغلاقها تُعدَّل هناك. هنا تُضاف الملاحظات والأنشطة ويُحدَّد عميلها وعروض أسعارها.', en: 'This opportunity is owned by Odoo: its values, its stage and its closing are changed there. Here you add notes and to-dos and set its customer and quotations.' };
export const KIND_CHIP = { ar: 'عميل محتمل', en: 'Lead', ...TONE.neutral };

/** The lists of leads (`state`). */
export const LEAD_STATES = [
  { k: 'open', label: { ar: 'المفتوحة', en: 'Open' } }, { k: 'won', label: { ar: 'المكسوبة', en: 'Won' } }, { k: 'lost', label: { ar: 'الخاسرة', en: 'Lost' } },
  { k: 'archived', label: { ar: 'المؤرشفة', en: 'Archived' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } },
];
/** `lead.priority` 0..3 — the stars of a card. */
export const PRIORITY = [{ ar: 'عادية', en: 'Normal' }, { ar: 'متوسطة', en: 'Medium' }, { ar: 'عالية', en: 'High' }, { ar: 'عالية جدًا', en: 'Very high' }];
export const priorityOptions = () => PRIORITY.map((p, i) => ({ v: String(i), l: p }));
export const stars = (n) => '★'.repeat(Math.max(0, Math.min(3, Number(n) || 0)));

/** `activity.type` — the keys of the server (CrmActivity::TYPES); the lookups carry the same list for the forms. */
export const ACTIVITY_TYPE = {
  call: { ar: 'اتصال', en: 'Call', ...TONE.blue }, visit: { ar: 'زيارة', en: 'Visit', ...TONE.violet }, meeting: { ar: 'اجتماع', en: 'Meeting', ...TONE.teal },
  email: { ar: 'بريد إلكتروني', en: 'E-mail', ...TONE.neutral }, message: { ar: 'رسالة / واتساب', en: 'Message / WhatsApp', ...TONE.ok },
  sample: { ar: 'إرسال عينات', en: 'Send samples', ...TONE.warn }, todo: { ar: 'مهمة', en: 'To-do', ...TONE.neutral },
};
export const activityTypeOptions = () => Object.entries(ACTIVITY_TYPE).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));
export const activityTypeLabel = (k) => bi(own(ACTIVITY_TYPE, k) || { ar: k || '—', en: k || '—' });
export const ACTIVITY_STATUS = { open: { ar: 'مفتوحة', en: 'Open', ...TONE.blue }, done: { ar: 'منجزة', en: 'Done', ...TONE.ok }, cancelled: { ar: 'ملغاة', en: 'Cancelled', ...TONE.neutral } };
/** The to-do lists (`state` of GET /crm/activities). */
export const ACTIVITY_STATES = [
  { k: 'open', label: { ar: 'المفتوحة', en: 'Open' } }, { k: 'overdue', label: { ar: 'المتأخرة', en: 'Overdue' } }, { k: 'today', label: { ar: 'اليوم', en: 'Today' } },
  { k: 'upcoming', label: { ar: 'القادمة', en: 'Upcoming' } }, { k: 'done', label: { ar: 'المنجزة', en: 'Done' } }, { k: 'cancelled', label: { ar: 'الملغاة', en: 'Cancelled' } },
];

/** A line of a lead's timeline (`event.kind`). */
export const EVENT_KIND = {
  created: { ar: 'أُنشئت الفرصة', en: 'Created', ...TONE.blue }, stage: { ar: 'انتقلت بين المراحل', en: 'Moved', ...TONE.violet }, won: { ar: 'كُسبت', en: 'Won', ...TONE.ok },
  lost: { ar: 'خُسرت', en: 'Lost', ...TONE.bad }, reopened: { ar: 'أُعيد فتحها', en: 'Reopened', ...TONE.warn }, assigned: { ar: 'أُسندت', en: 'Assigned', ...TONE.neutral },
  note: { ar: 'ملاحظة', en: 'Note', ...TONE.teal }, converted: { ar: 'حُدِّد عميلها', en: 'Customer set', ...TONE.ok }, quotation: { ar: 'عرض سعر', en: 'Quotation', ...TONE.violet },
  activity: { ar: 'أُنجزت مهمة', en: 'To-do done', ...TONE.ok }, archived: { ar: 'أُرشفت', en: 'Archived', ...TONE.neutral }, restored: { ar: 'استُعيدت', en: 'Restored', ...TONE.warn },
};
export const eventLabel = (k) => own(EVENT_KIND, k) || { ar: k, en: k, ...TONE.neutral };

/** How a conversion found the customer (`conversion.by`, `match.by`). */
export const MATCH_BY = {
  link: { ar: 'مرتبط بعميل في أودو', en: 'Linked through Odoo' }, vat: { ar: 'الرقم الضريبي نفسه', en: 'Same VAT number' }, person: { ar: 'حدّده المستخدم', en: 'Chosen by a person' },
  new: { ar: 'عميل جديد', en: 'New customer' }, already: { ar: 'محدَّد مسبقًا', en: 'Already set' },
};
/** Why a customer is offered as a candidate (`candidate.why[]`). */
export const CANDIDATE_WHY = { sameVat: { ar: 'الرقم الضريبي نفسه', en: 'Same VAT number' }, sameName: { ar: 'الاسم نفسه', en: 'Same name' }, samePhone: { ar: 'رقم الهاتف نفسه', en: 'Same phone' }, similarName: { ar: 'اسم مشابه', en: 'Similar name' } };

/** Tag colours: the palette index people picked (0..11). */
const TAG_COLORS = [
  TONE.neutral, { fg: '#b23b3b', bg: '#fdecec' }, { fg: '#b26a16', bg: '#fbf0dd' }, { fg: '#8a6d00', bg: '#fbf6d0' }, { fg: '#3C79F5', bg: '#e8effe' }, { fg: '#8a3d6b', bg: '#f8e6f0' },
  { fg: '#b05a3a', bg: '#fbe9e1' }, { fg: '#0d7f93', bg: '#e4f8fb' }, { fg: '#3b4a9c', bg: '#e6e9fb' }, { fg: '#a13a5a', bg: '#fbe4ea' }, { fg: '#1d7a3e', bg: '#e6f9ec' }, { fg: '#654e92', bg: '#efeaf8' },
];
export const tagColor = (i) => TAG_COLORS[Number(i) % TAG_COLORS.length] || TONE.neutral;
export const TAG_COLOR_COUNT = TAG_COLORS.length;

// ---------------------------------------------------------------- names and figures
/** Arabic / English name of a stage, a team, a reason, a person (nameAr / nameEn). */
export const nameOf = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || '—' : '—');
/** Who a lead is: the company, else the contact. */
export const leadWho = (l) => l?.companyName || l?.contactName || '';
export const SAR = { ar: 'ر.س', en: 'SAR' };
/** An amount with its currency, «—» for nothing. */
export const money = (v) => (num(v) ? `${fmtMoney(v)} ${bi(SAR)}` : '—');
export const pct = (v) => (v == null ? '—' : `${Number(num(v).toFixed(1))}%`);
/** A day the API gives as `YYYY-MM-DD`, shown as the same calendar day. */
export const fmtDay = (d) => (d ? fmtDateOnly(String(d).slice(0, 10).replace(/-/g, '/')) : '—');
export const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
/** "متأخرة 3 أيام" / "اليوم" / the day — for a to-do's due date. */
export function dueLabel(a) {
  if (!a) return '';
  if (a.today) return t('اليوم', 'Today');
  return fmtDay(a.dueDate || a.date);
}

// ---------------------------------------------------------------- query-string state
export const VIEWS = ['board', 'list', 'activities', 'report', 'settings'];
/** Filters the board and the list share (keys of the query string = parameters of the API). */
export const LEAD_FILTERS = ['q', 'owner', 'team', 'priority', 'tag', 'activity', 'origin'];

/**
 * Everything the page shows is in the query string of /crm, so every view, filter and open lead is a link:
 *   view=board|list|activities|report|settings · q · owner · team · priority · tag · activity · origin
 *   list:       state=open|won|lost|archived|all · stage · sort · order · page
 *   activities: todo=open|overdue|today|upcoming|done|cancelled · who=me|all|<user id> · type · q · page
 *   report:     from · to · team · owner              settings: set=stages|reasons|tags|teams
 *   lead=<number> opens the lead — on any view
 */
export function useCrmNav() {
  const qs = useQueryState();
  const { get } = qs;
  const view = computed(() => (VIEWS.includes(get('view')) ? get('view') : 'board'));
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  const leadOpen = computed(() => get('lead') || null);

  /** Changes some keys of the query string and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...qs.route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return qs.replace(next);
  }
  /** Opens a view; what belonged to the previous one (filters, page) is dropped, an open lead closes. */
  const go = (to, params = {}) => qs.replace({ ...(to && to !== 'board' ? { view: to } : {}), ...Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== '')) });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const openLead = (number) => patch({ lead: number });
  const closeLead = () => patch({ lead: null });
  /** The lead filters of the query string as API parameters. */
  const leadParams = () => Object.fromEntries(LEAD_FILTERS.map((k) => [k, get(k) || undefined]));
  const hasLeadFilters = computed(() => LEAD_FILTERS.some((k) => !!get(k)) || !!get('stage'));
  const clearLeadFilters = () => patch(Object.fromEntries([...LEAD_FILTERS, 'stage', 'page'].map((k) => [k, null])));
  return { get, view, page, leadOpen, patch, go, setFilter, setPage, openLead, closeLead, leadParams, hasLeadFilters, clearLeadFilters };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL (and so the list) 350 ms after the
 * last key, at once on Enter; a deep link or a filter that clears the key refills the box.
 *   const { text, type, apply } = useSearchParam(nav);
 */
export function useSearchParam(nav, key = 'q') {
  let applied = nav.get(key);
  const text = ref(applied);
  let timer = null;
  function apply() {
    clearTimeout(timer);
    const v = String(text.value ?? '').trim();
    if (v === applied) return;
    applied = v;
    nav.setFilter(key, v);
  }
  function type(v) { text.value = v; clearTimeout(timer); timer = setTimeout(apply, 350); }
  watch(() => nav.get(key), (v) => { if (v !== applied) { applied = v; text.value = v; } });
  onBeforeUnmount(() => clearTimeout(timer));
  return { text, type, apply };
}

// ---------------------------------------------------------------- lookups
/**
 * GET /crm/lookups, shared by every view (one request, kept fresh for half a minute) and shaped for the pickers:
 *   const lk = useCrmLookups();  lk.stages.value · lk.stageOptions.value · lk.follows.value …
 */
export function useCrmLookups() {
  const q = useGet('/crm/lookups', undefined, { staleTime: 30_000 });
  const data = computed(() => q.data.value || null);
  const list = (key) => computed(() => data.value?.[key] || []);
  const stages = list('stages');
  const teams = list('teams');
  const lostReasons = list('lostReasons');
  const tags = list('tags');
  const users = list('users');
  const owners = list('owners');
  const activeStages = computed(() => stages.value.filter((s) => s.active));
  const named = (rows) => rows.map((x) => ({ v: x.id, l: { ar: x.nameAr, en: x.nameEn || x.nameAr } }));
  return {
    query: q, data, stages, teams, lostReasons, tags, users, owners, activeStages,
    /** The section follows Odoo: what came from Odoo is read-only here. */
    follows: computed(() => !!data.value?.follows),
    loaded: computed(() => !!data.value),
    stageOptions: computed(() => named(activeStages.value)),
    openStageOptions: computed(() => named(activeStages.value.filter((s) => !s.isWon))),
    wonStageOptions: computed(() => named(activeStages.value.filter((s) => s.isWon))),
    teamOptions: computed(() => named(teams.value.filter((x) => x.active && x.pipeline))),
    allTeamOptions: computed(() => named(teams.value)),
    reasonOptions: computed(() => named(lostReasons.value.filter((x) => x.active))),
    tagOptions: computed(() => tags.value.filter((x) => x.active).map((x) => ({ v: x.id, l: x.name }))),
    userOptions: computed(() => users.value.map((u) => ({ v: u.id, l: { ar: u.nameAr, en: u.nameEn || u.nameAr } }))),
    /** Who holds leads today — OPS users and the salespeople Odoo names — for the filters (`key` is the filter value). */
    ownerOptions: computed(() => owners.value.map((o) => ({ v: o.key, l: { ar: `${o.nameAr} (${o.count})`, en: `${o.nameEn || o.nameAr} (${o.count})` } }))),
    sources: list('sources'),
  };
}
