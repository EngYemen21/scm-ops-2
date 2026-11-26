// «الدعم والمشاريع والمستندات» / "Helpdesk, projects & documents" — what the three tools of the page share:
// label maps (with chip colours), people and file helpers, the query-string state of /workspace, the shared lookups
// and the download of a file with the session's token.
//
// API, all under /api/workspace (permission page.workspace; ticket.manage / project.manage / doc.manage configure).
// camelCase JSON, ids are ULID strings, a day is `YYYY-MM-DD`, a moment is ISO-8601, lists are the Paging envelope.
//   Person    { key, id|null, username|null, nameAr, nameEn, external }      external = kept by name from Odoo; key = id or 'name:<name>'
//   Origin    { sourceSystem: 'odoo'|null, externalRef, owned }              owned = Odoo owns it right now → read-only here
//   File      { id, name, mime, size, fromOdoo, available, versionNo|null, note|null, createdBy, createdAt, url, removable }
//   RecordRef { type, number, labelAr, labelEn } | null                      linked with entityPath(type, number)
//   Upload    { fileName, mime?, base64 }                                    ≤ maxFiles and ≤ maxBytes IN TOTAL per request
//   Message   { id, kind: reply|note|comment|event, event|null, body|null, data|null, author: Person|null, at, origin, files: [File] }
//   GET summary          { follows, me: { id, username, manages: { tickets, projects, documents } }, tickets: { open, mine, unassigned, overdue },
//                          tasks: { open, mine, overdue, dueToday }, documents: { total, folders } }
//   GET lookups          { follows, users: [{ id, username, nameAr, nameEn }], roles: [{ key, nameAr, nameEn }], priorities, recordTypes: [{ key, ar, en }],
//                          files: { maxBytes, maxFiles, extensions } }
//   GET customers?q=     [{ id, code, nameAr, nameEn, city }]        GET records?type=&number=   { type, number, found, labelAr, labelEn, title }
//   GET files/{id}       the bytes                                    POST files/{id}/remove { reason? }
//   tickets   GET tickets/lookups { teams, stages, types, assignees } · GET tickets · GET tickets/{n} · POST tickets · PATCH tickets/{id} ·
//             POST tickets/{id}/assign|move|close|reopen|archive|restore|messages · POST|PATCH ticket-teams · ticket-stages · ticket-types
//   tasks     GET tasks/lookups { projects, tags, assignees } · GET projects · GET projects/{id} · POST|PATCH projects · PUT projects/{id}/members ·
//             POST projects/{id}/status|archive|restore|stages · PATCH task-stages/{id} · GET projects/{id}/board · GET tasks · GET tasks/{n} ·
//             POST tasks · PATCH tasks/{id} · POST tasks/{id}/move|done|reopen|cancel|archive|restore|comments|checklist ·
//             PATCH tasks/{id}/checklist/{item} · POST tasks/{id}/checklist/{item}/remove · POST|PATCH task-tags
//   documents GET documents/lookups { tags } · GET folders · POST|PATCH folders · POST folders/{id}/archive|restore · GET documents ·
//             GET documents/{n} · POST documents · PATCH documents/{id} · POST documents/{id}/move|versions|archive|restore · POST|PATCH doc-tags
// The page keeps everything in the query string of /workspace: `tab`, the filters of the tab on screen, and what is
// open (`ticket`, `task`, `prj`, `doc`) — see WorkspacePage.vue.
import { onBeforeUnmount, ref, watch } from 'vue';
import { API_BASE, ApiError, buildQuery, refreshAccess, tokens, useGet } from '@/api/client';
import { daysTo, fmtDate, fmtNum, getLang, nm, t, toDate } from '@/i18n';
import { entityPath } from '@/router/routes';
import { useQueryState } from '../dashboard/shared';

export const API = '/workspace';
export const PAGE_SIZE = 25;
/** Every change of the section refreshes what the page shows of it (lists, the open drawer, the counters). */
export const INVALIDATE = ['workspace'];

// ---------------------------------------------------------------- labels
/** The colour pairs every status chip of the application uses. */
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' }, blue: { fg: '#3C79F5', bg: '#e8effe' },
  violet: { fg: '#654e92', bg: '#efeaf8' }, teal: { fg: '#0d7f93', bg: '#d9f4f9' }, neutral: { fg: '#55506a', bg: '#F1EFF6' }, off: { fg: '#7d7990', bg: '#F1EFF6' },
};
export const TICKET_STATUS = { open: { ar: 'مفتوحة', en: 'Open', ...TONE.blue }, closed: { ar: 'مغلقة', en: 'Closed', ...TONE.ok } };
/** Priority 0..3 of a ticket or a task (the map is read with `String(priority)`). */
export const PRIORITY = {
  0: { ar: 'عادية', en: 'Normal', ...TONE.neutral }, 1: { ar: 'متوسطة', en: 'Medium', ...TONE.blue },
  2: { ar: 'عالية', en: 'High', ...TONE.warn }, 3: { ar: 'عاجلة', en: 'Urgent', fg: '#fff', bg: '#b23b3b' },
};
export const PRIORITY_OPTIONS = Object.entries(PRIORITY).map(([v, l]) => ({ v, l }));
export const PROJECT_STATUS = {
  active: { ar: 'نشط', en: 'Active', ...TONE.ok }, on_hold: { ar: 'متوقف مؤقتًا', en: 'On hold', ...TONE.warn }, done: { ar: 'منتهٍ', en: 'Done', ...TONE.neutral },
};
export const TASK_STATUS = {
  open: { ar: 'مفتوحة', en: 'Open', ...TONE.blue }, done: { ar: 'مكتملة', en: 'Done', ...TONE.ok }, cancelled: { ar: 'ملغاة', en: 'Cancelled', ...TONE.off },
};
export const VISIBILITY = { all: { ar: 'كل من يفتح القسم', en: 'Everyone in the section' }, members: { ar: 'الأعضاء فقط', en: 'Members only' } };
export const VISIBILITY_OPTIONS = Object.entries(VISIBILITY).map(([v, l]) => ({ v, l }));
export const REQUESTER_KIND = { staff: { ar: 'موظف', en: 'Staff' }, customer: { ar: 'عميل', en: 'Customer' }, other: { ar: 'جهة أخرى', en: 'Someone else' } };
export const MESSAGE_KIND = {
  reply: { ar: 'رد', en: 'Reply', ...TONE.teal }, note: { ar: 'ملاحظة داخلية', en: 'Internal note', ...TONE.warn }, comment: { ar: 'تعليق', en: 'Comment', ...TONE.violet },
};
/** What happened to a ticket or a task (`Message.event`), with the colour of its dot. */
export const EVENT_LABEL = {
  created: { ar: 'أُنشئ السجل', en: 'Created', color: '#1BC4DB' }, assigned: { ar: 'تغيّر المسؤول', en: 'Assignment changed', color: '#654e92' },
  moved: { ar: 'انتقل إلى مرحلة أخرى', en: 'Moved to another stage', color: '#3C79F5' }, closed: { ar: 'أُغلقت التذكرة', en: 'Closed', color: '#1d7a3e' },
  reopened: { ar: 'أُعيد الفتح', en: 'Reopened', color: '#b26a16' }, archived: { ar: 'أُرشف السجل', en: 'Archived', color: '#7d7990' },
  restored: { ar: 'استُعيد من الأرشيف', en: 'Restored', color: '#0d7f93' }, edited: { ar: 'عُدّلت البيانات', en: 'Edited', color: '#a8a4b8' },
  done: { ar: 'أُنجزت المهمة', en: 'Done', color: '#1d7a3e' }, cancelled: { ar: 'أُلغيت المهمة', en: 'Cancelled', color: '#b23b3b' },
};
export const ODOO_CHIP = { ar: 'أودو', en: 'Odoo', ...TONE.violet };
export const ARCHIVED_CHIP = { ar: 'مؤرشف', en: 'Archived', ...TONE.off };
export const OWNED_NOTE = {
  ar: 'هذا السجل مصدره أودو وما زال القسم يتبع أودو: يُعدَّل هناك، وهنا تُضاف الردود والتعليقات فقط.',
  en: 'This record comes from Odoo and the section still follows Odoo: it is changed there; here only replies and comments are added.',
};
export const SELECT_PH = { ar: '— اختر —', en: '— select —' };
export const ALL_PH = { ar: 'الكل', en: 'All' };

/** The twelve colours a tag may carry (`color` 0..11). */
export const TAG_COLORS = [
  { fg: '#55506a', bg: '#F1EFF6' }, { fg: '#b23b3b', bg: '#fdecec' }, { fg: '#b26a16', bg: '#fbf0dd' }, { fg: '#8a6d00', bg: '#fdf6cf' },
  { fg: '#3C79F5', bg: '#e8effe' }, { fg: '#7a3e8f', bg: '#f5e8fa' }, { fg: '#b5562f', bg: '#fdeadf' }, { fg: '#0d7f93', bg: '#d9f4f9' },
  { fg: '#2f4fb5', bg: '#e3e9fb' }, { fg: '#b23b77', bg: '#fde8f2' }, { fg: '#1d7a3e', bg: '#e6f9ec' }, { fg: '#654e92', bg: '#efeaf8' },
];
export const tagStyle = (color) => TAG_COLORS[Math.abs(Math.trunc(Number(color)) || 0) % TAG_COLORS.length];

// ---------------------------------------------------------------- small readers
const NONE = Object.freeze([]);
/** A list, whatever arrived (PHP writes an empty map as `[]` and a missing list as null). */
export const arr = (v) => (Array.isArray(v) ? v : NONE);
/** Name of a Person / a user / a dictionary row in the language on screen. */
export const personName = (p) => (p ? nm(p) || p.username || '—' : '—');
/** Two letters for the round mark of a person on a card. */
export function initials(p) {
  const words = personName(p).split(/\s+/).filter(Boolean);
  return ((words[0]?.[0] || '') + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase() || '؟';
}
export const userOptions = (users) => arr(users).map((u) => ({ v: u.id, l: personName(u) }));
/** Options of a dictionary (teams, stages, types): active rows, plus the one already chosen when it was put away. */
export const dictOptions = (rows, keep = null) => arr(rows).filter((r) => r.active !== false || r.id === keep).map((r) => ({ v: r.id, l: personName(r) }));
/** Deep link of a linked OPS record, or null when the client has no page for that kind. */
export const refPath = (ref) => (ref ? entityPath(ref.type, ref.number) : null);
export const refLabel = (ref) => (ref ? `${t(ref.labelAr, ref.labelEn) || ref.type} · ${ref.number}` : '—');

// ---------------------------------------------------------------- dates
/** How a ticket stands against a moment it is due at: `{ text, late }`, or null without one. */
export function dueInfo(at, finished = false) {
  const d = toDate(at);
  if (!d) return null;
  const minutes = Math.round((d.getTime() - Date.now()) / 60000);
  const span = (m) => { const a = Math.abs(m); return a < 60 ? t(`${a} د`, `${a}m`) : a < 1440 ? t(`${Math.round(a / 60)} س`, `${Math.round(a / 60)}h`) : t(`${Math.round(a / 1440)} ي`, `${Math.round(a / 1440)}d`); };
  if (finished) return { text: fmtDate(d), late: false };
  return minutes < 0 ? { text: t(`متأخرة ${span(minutes)}`, `${span(minutes)} overdue`), late: true } : { text: t(`خلال ${span(minutes)}`, `in ${span(minutes)}`), late: false };
}
/** How a task stands against its deadline (a day): `{ text, late, today }`, or null without one. */
export function deadlineInfo(day, finished = false) {
  if (!day) return null;
  const n = daysTo(day);
  const date = fmtDate(day, { time: false });
  if (finished) return { text: date, late: false, today: false };
  if (n < 0) return { text: t(`${date} · متأخرة ${fmtNum(-n)} ي`, `${date} · ${fmtNum(-n)}d late`), late: true, today: false };
  if (n === 0) return { text: t(`${date} · اليوم`, `${date} · today`), late: false, today: true };
  return { text: n === 1 ? t(`${date} · غدًا`, `${date} · tomorrow`) : date, late: false, today: false };
}

// ---------------------------------------------------------------- files
/** Size of a file in words: 0 → '', 1,536 → "1.5 KB". */
export function fileSize(bytes) {
  const n = Number(bytes);
  if (!Number.isFinite(n) || n <= 0) return '';
  if (n < 1024) return `${fmtNum(n)} B`;
  if (n < 1048576) return `${fmtNum(n / 1024, n < 10240 ? 1 : 0)} KB`;
  return `${fmtNum(n / 1048576, 1)} MB`;
}
const EXT_KIND = {
  pdf: 'pdf', png: 'image', jpg: 'image', jpeg: 'image', gif: 'image', webp: 'image', doc: 'doc', docx: 'doc', odt: 'doc', txt: 'doc', rtf: 'doc',
  xls: 'sheet', xlsx: 'sheet', ods: 'sheet', csv: 'sheet', ppt: 'slides', pptx: 'slides', odp: 'slides',
};
export const FILE_KIND = {
  pdf: { ar: 'PDF', en: 'PDF', ...TONE.bad }, image: { ar: 'صورة', en: 'Image', ...TONE.teal }, doc: { ar: 'مستند نصي', en: 'Text document', ...TONE.blue },
  sheet: { ar: 'جدول بيانات', en: 'Spreadsheet', ...TONE.ok }, slides: { ar: 'عرض تقديمي', en: 'Slides', ...TONE.warn },
  link: { ar: 'رابط', en: 'Link', ...TONE.violet }, other: { ar: 'ملف', en: 'File', ...TONE.neutral },
};
export const extOf = (name) => { const m = /\.([A-Za-z0-9]{1,8})$/.exec(String(name || '').trim()); return m ? m[1].toLowerCase() : ''; };
/** pdf | image | doc | sheet | slides | other — from the type the server states, else from the name. */
export function fileKind(mime, name) {
  const m = String(mime || '').toLowerCase();
  if (m === 'application/pdf') return 'pdf';
  if (m.startsWith('image/')) return 'image';
  if (/spreadsheet|ms-excel|csv/.test(m)) return 'sheet';
  if (/presentation|powerpoint/.test(m)) return 'slides';
  if (/wordprocessing|msword|opendocument\.text|^text\//.test(m)) return 'doc';
  return EXT_KIND[extOf(name)] || 'other';
}
/** What a browser shows in place (the server sends these inline): pictures and PDF. */
export const isInline = (mime) => ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'].includes(String(mime || '').toLowerCase());

/** The rules of an upload as GET lookups states them, with the system's own figures until it has answered. */
export const fileRules = (lookups) => ({ maxBytes: 5 * 1048576, maxFiles: 5, extensions: null, ...(lookups?.files || {}) });
/**
 * Why this file cannot join what is already chosen — said BEFORE anything is sent — or null when it can.
 * `used` = { bytes, count } of the files already chosen for the same request (the cap is on their total).
 */
export function fileProblem(file, rules, used) {
  const ext = extOf(file.name);
  const allowed = arr(rules.extensions).map((e) => String(e).toLowerCase());
  if (allowed.length && !allowed.includes(ext)) {
    return { ar: `«${file.name}»: نوع الملف غير مقبول. الأنواع المقبولة: ${allowed.join('، ')}`, en: `“${file.name}”: this type of file is not accepted. Accepted: ${allowed.join(', ')}` };
  }
  if (!file.size) return { ar: `«${file.name}»: الملف فارغ`, en: `“${file.name}”: the file is empty` };
  if (used.count + 1 > rules.maxFiles) {
    return { ar: `«${file.name}»: الحد ${fmtNum(rules.maxFiles)} ملفات في الطلب الواحد — أرسل الباقي في رسالة أخرى`, en: `“${file.name}”: at most ${fmtNum(rules.maxFiles)} files per request — send the rest in another one` };
  }
  if (used.bytes + file.size > rules.maxBytes) {
    return { ar: `«${file.name}» (${fileSize(file.size)}): مجموع الملفات في الطلب الواحد لا يتجاوز ${fileSize(rules.maxBytes)}`, en: `“${file.name}” (${fileSize(file.size)}): the files of one request may not exceed ${fileSize(rules.maxBytes)} in total` };
  }
  return null;
}
/** A picked file as the API takes it: `{ fileName, mime, base64 }` (+ `size`, for the list on screen). */
export function readUpload(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error(t(`تعذّرت قراءة الملف «${file.name}»`, `The file “${file.name}” could not be read`)));
    reader.onload = () => resolve({ fileName: file.name, mime: file.type || undefined, base64: String(reader.result), size: file.size });
    reader.readAsDataURL(file);
  });
}
/** What is sent of the chosen files (`size` is the page's own figure). */
export const toUploads = (files) => arr(files).map(({ fileName, mime, base64 }) => ({ fileName, mime, base64 }));

// ---------------------------------------------------------------- downloads
/** Name of the file a download answers with (`Content-Disposition`, plain or RFC 5987 for Arabic names). */
function fileNameOf(res) {
  const cd = res.headers.get('content-disposition') || '';
  const star = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(cd);
  if (star) { try { return decodeURIComponent(star[1].trim()); } catch { /* the plain name below */ } }
  return /filename\s*=\s*"?([^";]+)"?/i.exec(cd)?.[1]?.trim() || '';
}
/** The path under /api of a stored file (`/api/workspace/files/01J…` → `/workspace/files/01J…`). */
export const apiPathOf = (url) => (typeof url === 'string' && /^\/api\/[^/]/.test(url) ? url.slice(4) : null);
/**
 * A file of this API asked for with the session's bearer token — a plain address could not carry it. An expired
 * token is refreshed once. Resolves `{ blob, name }`; throws ApiError (403 without the permission, 404 …).
 */
async function fetchFile(path, params) {
  const url = `${API_BASE}${path}${buildQuery(params)}`;
  const call = () => fetch(url, { headers: { Authorization: `Bearer ${tokens.access || ''}`, 'Accept-Language': getLang() }, credentials: 'same-origin' })
    .catch(() => { throw new ApiError({ status: 0, category: 'NETWORK', code: 'NETWORK', message: 'تعذر التنزيل: لا اتصال بالخادم', messageEn: 'Download failed: the server cannot be reached' }); });
  let res = await call();
  if (res.status === 401 && (await refreshAccess())) res = await call();
  if (!res.ok) { const p = await res.json().catch(() => ({})); throw new ApiError({ status: res.status, ...(p && typeof p === 'object' ? p : {}) }); }
  return { blob: await res.blob(), name: fileNameOf(res) };
}
function saveBlob(blob, name) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = name;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 4000);
}
/** Saves a File of the section through a link. Resolves the name it was saved under. */
export async function downloadFile(file) {
  const got = await fetchFile(apiPathOf(file.url) || `${API}/files/${file.id}`);
  const name = got.name || file.name || 'file';
  saveBlob(got.blob, name);
  return name;
}
/**
 * Shows a picture or a PDF in a new tab. The tab is opened at once — a browser only lets a click open one — and
 * given the file when it has arrived; a browser that refuses the tab gets the file as a download instead.
 */
export async function openFile(file) {
  const tab = window.open('', '_blank');
  try {
    const got = await fetchFile(apiPathOf(file.url) || `${API}/files/${file.id}`);
    if (!tab) { saveBlob(got.blob, got.name || file.name || 'file'); return; }
    const url = URL.createObjectURL(got.blob);
    tab.location.href = url;
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (e) {
    tab?.close();
    throw e;
  }
}

// ---------------------------------------------------------------- query-string state
/**
 * The state of the page in the address of /workspace.
 *   const qs = useWsQuery();  qs.get('state')  qs.patch({ state: 'closed', team: '' })  qs.open('ticket', 'TKT-…')
 * `patch` removes empty values and goes back to the first page unless told to keep it.
 */
export function useWsQuery() {
  const base = useQueryState();
  function patch(changes, keepPage = false) {
    const next = { ...base.route.query };
    for (const [k, v] of Object.entries(changes)) {
      if (v === undefined || v === null || v === '' || v === false) delete next[k]; else next[k] = v === true ? '1' : String(v);
    }
    if (!keepPage && !('page' in changes)) delete next.page;
    return base.replace(next);
  }
  return {
    route: base.route, router: base.router, get: base.get, replace: base.replace, patch,
    page: () => Math.max(1, Number.parseInt(base.get('page'), 10) || 1),
    /** The sort of the address as DataTable takes it, or null for the list's own order. */
    sort: () => (base.get('sort') ? { key: base.get('sort'), order: base.get('order') === 'asc' ? 'asc' : 'desc' } : null),
    setSort: (s) => patch({ sort: s?.key, order: s?.key ? s.order : '' }),
    /** Opens / closes what a key names (a drawer, a form) without touching the list behind it. */
    open: (key, value) => patch({ [key]: value }, true),
    close: (...keys) => patch(Object.fromEntries(keys.map((k) => [k, ''])), true),
  };
}
/** A search box that writes the address a moment after the typing stops (and follows it when it changes elsewhere). */
export function useSearchBox(qs, key = 'q', ms = 350) {
  const text = ref(qs.get(key));
  let timer = null;
  watch(() => qs.get(key), (v) => { if (v !== text.value.trim()) text.value = v; });
  watch(text, (v) => {
    clearTimeout(timer);
    timer = setTimeout(() => { if (v.trim() !== qs.get(key)) qs.patch({ [key]: v.trim() }); }, ms);
  });
  onBeforeUnmount(() => clearTimeout(timer));
  return text;
}

// ---------------------------------------------------------------- shared queries
/** The counters of the page and what the caller manages; every part reads the same cached answer. */
export const useSummary = () => useGet(`${API}/summary`, undefined, { staleTime: 15_000 });
/** Users, roles, priorities, record kinds and the upload rules — asked once, kept five minutes. */
export const useLookups = (enabled = () => true) => useGet(`${API}/lookups`, undefined, { enabled, staleTime: 300_000 });
