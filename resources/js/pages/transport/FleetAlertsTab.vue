<script setup>
// Fleet · alert center: open alerts by severity (tiles), status pills, category filter, per-row view / assign / resolve /
// snooze, "refresh document alerts" → POST /transport/alerts/generate. GET /transport/alerts (polled every 60s).
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Modal, SelectInput } from '@/components';
import { bi, fmtDate, fmtDateOnly, fmtNum, lang, t, toDate } from '@/i18n';
import { ALERT_CATEGORY_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import AlertActionModal from './AlertActionModal.vue';
import KvList from './KvList.vue';
import { ALERT_SEV_LABELS, ALERT_STATUS_LABELS, entityKind, labelOf, optsOf } from './tms';

const props = defineProps({
  /** Initial category from the page query (?f=vehdoc | driverdoc …). */
  filter: { type: String, default: '' },
});
const emit = defineEmits(['open-vehicle', 'open-driver', 'open-trip']);

const auth = useAuth();
const act = useAction();
const status = ref('');
const severity = ref('');
const category = ref(props.filter && ALERT_CATEGORY_LABELS[props.filter] ? props.filter : '');
const page = ref(1);
const view = ref(null);
/** @type {import('vue').Ref<{ alert: object, action: 'resolve'|'snooze'|'assign' } | null>} */
const pending = ref(null);
const list = useList('/transport/alerts', () => ({ status: status.value || undefined, severity: severity.value || undefined, category: category.value || undefined, page: page.value, pageSize: 25 }), { refetchInterval: 60_000 });
const reset = () => { page.value = 1; };

const SEV_TILES = [{ k: ['c', 'high'], l: { ar: 'حرج', en: 'Critical' }, c: '#b23b3b' }, { k: ['w', 'med'], l: { ar: 'تحذير', en: 'Warning' }, c: '#b26a16' }, { k: ['i', 'low'], l: { ar: 'معلومة', en: 'Info' }, c: '#3C79F5' }];
const bySev = computed(() => list.data.value?.openBySeverity || {});
const sevCount = (keys) => keys.reduce((a, k) => a + (bySev.value[k] || 0), 0);
function toggleSeverity(keys) { const v = keys.join(','); severity.value = severity.value === v ? '' : v; reset(); }

function openEntity(a) {
  if (!a.entityCode) return;
  const kind = entityKind(a.entityType);
  if (kind === 'vehicle') emit('open-vehicle', a.entityCode); else if (kind === 'driver') emit('open-driver', a.entityCode); else if (kind === 'trip') emit('open-trip', a.entityCode);
}
/** Whole days until the due date (negative = overdue), null without one. */
function days(a) { const d = toDate(a.dueDate); return d ? Math.round((d.getTime() - Date.now()) / 86400000) : null; }
const daysColor = (d) => (d <= 7 ? '#b23b3b' : d <= 30 ? '#b26a16' : '#1d7a3e');
const canAct = (a) => auth.can('alert.manage') && ['open', 'assigned', 'snoozed'].includes(a.status);
function startAction(alert, action) { pending.value = { alert, action }; view.value = null; }
const generate = () => act.run(() => api.postIdempotent('/transport/alerts/generate'), { success: (r) => r?.message || t('حُدّثت تنبيهات الوثائق', 'Document alerts refreshed'), invalidate: ['transport'] });

const categoryOpts = optsOf(ALERT_CATEGORY_LABELS);
const columns = [
  { key: 'severity', header: { ar: 'الخطورة', en: 'Severity' }, width: '72px' },
  { key: 'category', header: { ar: 'الفئة', en: 'Category' }, width: '92px' },
  { key: 'entity', header: { ar: 'الكيان', en: 'Entity' }, width: '84px' },
  { key: 'text', header: { ar: 'الوصف', en: 'Description' }, width: 'minmax(200px,1.6fr)' },
  { key: 'due', header: { ar: 'الاستحقاق', en: 'Due' }, width: '88px', kind: 'date', value: (a) => (a.dueDate ? fmtDateOnly(a.dueDate) : '—') },
  { key: 'days', header: { ar: 'متبقٍ', en: 'Days' }, width: '60px', kind: 'num' },
  { key: 'owner', header: { ar: 'المسؤول', en: 'Owner' }, width: '96px', kind: 'muted', value: (a) => a.owner || '—' },
  { key: 'rec', header: { ar: 'الإجراء الموصى', en: 'Recommended' }, width: 'minmax(150px,1.2fr)' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '84px' },
  { key: 'act', header: '', width: '200px' },
];
const viewRows = computed(() => {
  const a = view.value;
  if (!a) return [];
  return [
    { id: 'sev', k: { ar: 'الخطورة', en: 'Severity' } }, { id: 'status', k: { ar: 'الحالة', en: 'Status' } },
    { id: 'entity', k: { ar: 'الكيان', en: 'Entity' }, v: '—' },
    { k: { ar: 'الاستحقاق', en: 'Due' }, num: !!a.dueDate, v: a.dueDate ? fmtDateOnly(a.dueDate) : '—' }, { k: { ar: 'المسؤول', en: 'Owner' }, v: a.owner || '—' },
    { k: { ar: 'الإجراء الموصى', en: 'Recommended' }, v: (lang.value === 'en' && a.recommendEn) || a.recommendAr || '—' },
    a.snoozedUntil && { k: { ar: 'مؤجل حتى', en: 'Snoozed until' }, num: true, v: fmtDate(a.snoozedUntil) }, a.resolvedAt && { k: { ar: 'حُل في', en: 'Resolved at' }, num: true, v: fmtDate(a.resolvedAt) },
    { k: { ar: 'أُنشئ', en: 'Created' }, num: true, v: fmtDate(a.createdAt) },
  ];
});
</script>

<template>
  <div>
    <div class="mb-3 grid max-w-[520px] grid-cols-3 gap-2">
      <div v-for="x in SEV_TILES" :key="x.k[0]" class="tile cursor-pointer text-center" :class="{ amber: severity === x.k.join(',') }" @click="toggleSeverity(x.k)">
        <div class="tile-v !text-center" :style="{ color: x.c }">{{ fmtNum(sevCount(x.k)) }}</div><div class="tile-l">{{ t(x.l.ar, x.l.en) }}</div>
      </div>
    </div>
    <div class="row wrap mb-2.5">
      <div class="pill-bar !mb-0">
        <button type="button" class="pill" :class="{ active: status === '' }" @click="status = ''; reset()">{{ t('المفتوحة', 'Open') }}</button>
        <button v-for="(l, k) in ALERT_STATUS_LABELS" :key="k" type="button" class="pill" :class="{ active: status === k }" @click="status = k; reset()">{{ bi(l) }}</button>
      </div>
      <SelectInput v-model="category" small class="w-[170px]" :placeholder="{ ar: '— كل الفئات —', en: '— all categories —' }" :options="categoryOpts" @update:model-value="reset" />
      <div class="grow" />
      <Btn v-if="auth.can('alert.manage')" size="sm" tone="outline" :loading="act.pending.value" :label="{ ar: 'تحديث تنبيهات الوثائق', en: 'Refresh document alerts' }" @click="generate" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="1300" :row-key="(a) => a.code" :empty-text="{ ar: 'لا تنبيهات ✓', en: 'No alerts ✓' }" @page="page = $event" @row-click="(a) => (view = a)">
        <template #cell-severity="{ row }"><Chip small :map="ALERT_SEV_LABELS" :k="row.severity" /></template>
        <template #cell-category="{ row }"><b class="text-[10px] text-violet">{{ bi(labelOf(ALERT_CATEGORY_LABELS, row.category)) }}</b></template>
        <template #cell-entity="{ row }"><span class="cell-id" :class="{ 'cursor-pointer': row.entityCode }" @click.stop="openEntity(row)">{{ row.entityCode || '—' }}</span></template>
        <template #cell-text="{ row }"><span class="text-[10.5px]">{{ (lang === 'en' && row.textEn) || row.textAr }}</span></template>
        <template #cell-days="{ row }"><template v-if="days(row) == null">—</template><b v-else :style="{ color: daysColor(days(row)) }">{{ days(row) }}</b></template>
        <template #cell-rec="{ row }"><span class="text-[10px] text-sec">{{ (lang === 'en' && row.recommendEn) || row.recommendAr || '—' }}</span></template>
        <template #cell-status="{ row }"><Chip :map="ALERT_STATUS_LABELS" :k="row.status" /></template>
        <template #cell-act="{ row }">
          <div class="row !gap-1" @click.stop>
            <Btn size="sm" tone="ghost" :label="{ ar: 'عرض', en: 'View' }" @click="view = row" />
            <template v-if="canAct(row)">
              <Btn size="sm" tone="softBlue" :label="{ ar: 'إسناد', en: 'Assign' }" @click="startAction(row, 'assign')" />
              <Btn size="sm" tone="softGreen" :label="{ ar: 'حل', en: 'Resolve' }" @click="startAction(row, 'resolve')" />
              <Btn v-if="row.status !== 'snoozed'" size="sm" tone="soft" :label="{ ar: 'تأجيل', en: 'Snooze' }" @click="startAction(row, 'snooze')" />
            </template>
          </div>
        </template>
      </DataTable>
    </div>

    <Modal :open="!!view" :width="520" @close="view = null">
      <template v-if="view" #title>
        <span><span class="num">{{ view.code }}</span> · {{ bi(labelOf(ALERT_CATEGORY_LABELS, view.category)) }}</span>
        <div class="drawer-sub">{{ (lang === 'en' && view.textEn) || view.textAr }}</div>
      </template>
      <KvList v-if="view" :rows="viewRows">
        <template #v-sev><Chip small :map="ALERT_SEV_LABELS" :k="view.severity" /></template>
        <template #v-status><Chip small :map="ALERT_STATUS_LABELS" :k="view.status" /></template>
        <template #v-entity="{ row }">
          <span v-if="view.entityCode" class="cell-id cursor-pointer" @click="openEntity(view); view = null">{{ view.entityType }} · {{ view.entityCode }}</span>
          <template v-else>{{ row.v }}</template>
        </template>
      </KvList>
      <template #footer>
        <div v-if="view" class="row">
          <template v-if="canAct(view)">
            <Btn tone="blue" :label="{ ar: 'إسناد', en: 'Assign' }" @click="startAction(view, 'assign')" />
            <Btn tone="success" :label="{ ar: 'حل', en: 'Resolve' }" @click="startAction(view, 'resolve')" />
            <Btn tone="dark" :label="{ ar: 'تأجيل', en: 'Snooze' }" @click="startAction(view, 'snooze')" />
          </template>
          <div class="grow" /><Btn tone="soft" :label="{ ar: 'إغلاق', en: 'Close' }" @click="view = null" />
        </div>
      </template>
    </Modal>
    <AlertActionModal :alert="pending?.alert || null" :action="pending?.action || null" @close="pending = null" />
  </div>
</template>
