<script setup>
// The versions of the credit terms (GET /credit/terms): draft → published → retired. Exactly the published version
// whose effective date has come is «in force»; a request freezes the version it accepted. credit.manage:
//   POST /credit/terms { name, version, effectiveDate, termsAr?, termsEn?, jurisdictionCity? } · PATCH /credit/terms/{id}
//   (a draft only) · POST …/publish · POST …/retire { reason? }.  A version Odoo owns is changed there.
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, Modal, Tabs } from '@/components';
import { fmtDate, t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import { fmtDay } from './credit';
import { ORIGIN, TERMS, pick } from './labels';

const list = useList('/credit/terms');
const rows = computed(() => list.data.value?.items || []);
const act = useAction({ invalidate: ['credit'] });

/** The form: null closed · {} a new version · a draft being changed. */
const form = ref(null);
const editing = computed(() => (form.value && form.value.id ? form.value : null));
const fields = [
  { k: 'name', label: { ar: 'اسم الشروط', en: 'Name' }, required: true, full: true },
  { k: 'version', label: { ar: 'الإصدار', en: 'Version' }, required: true, dir: 'ltr' },
  { k: 'effectiveDate', label: { ar: 'سارية من', en: 'Effective from' }, type: 'date', required: true },
  { k: 'jurisdictionCity', label: { ar: 'مدينة الاختصاص القضائي', en: 'Jurisdiction city' }, full: true },
  { k: 'termsAr', label: { ar: 'نص الشروط بالعربية', en: 'Terms text (Arabic)' }, type: 'area', full: true },
  { k: 'termsEn', label: { ar: 'نص الشروط بالإنجليزية', en: 'Terms text (English)' }, type: 'area', full: true },
];
const initial = computed(() => (editing.value ? {
  name: editing.value.name, version: editing.value.version, effectiveDate: editing.value.effectiveDate ? String(editing.value.effectiveDate).slice(0, 10) : '',
  jurisdictionCity: editing.value.jurisdictionCity || '', termsAr: editing.value.termsAr || '', termsEn: editing.value.termsEn || '',
} : null));
function submit(x) {
  const body = { name: x.name, version: x.version, effectiveDate: x.effectiveDate, jurisdictionCity: x.jurisdictionCity ?? null, termsAr: x.termsAr ?? null, termsEn: x.termsEn ?? null };
  return editing.value ? api.patch(`/credit/terms/${editing.value.id}`, body) : api.postIdempotent('/credit/terms', body);
}
const action = { success: { ar: 'حُفظت الشروط', en: 'Terms saved' }, invalidate: ['credit'] };

async function publish(x) {
  if (!(await confirm({
    title: { ar: `نشر الشروط «${x.name}» الإصدار ${x.version}؟`, en: `Publish "${x.name}" version ${x.version}?` },
    sub: { ar: `بعد النشر لا يُعدَّل نصها. تسري من ${fmtDay(x.effectiveDate)} على كل طلب يقبلها بعد ذلك.`, en: `Once published its text cannot change. It applies from ${fmtDay(x.effectiveDate)} to every request that accepts it afterwards.` },
    tone: 'primary', okLabel: { ar: 'نشر', en: 'Publish' },
  }))) return;
  await act.run(() => api.postIdempotent(`/credit/terms/${x.id}/publish`), { success: { ar: 'نُشرت الشروط', en: 'Terms published' } });
}
async function retire(x) {
  const reason = await ask({ title: { ar: `إيقاف العمل بالإصدار ${x.version}`, en: `Retire version ${x.version}` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'إيقاف', en: 'Retire' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`/credit/terms/${x.id}/retire`, reason ? { reason } : undefined), { success: { ar: 'أُوقف العمل بالإصدار', en: 'Version retired' } });
}

/** Reading a version's text. */
const viewing = ref(null);
const viewLang = ref('ar');
const LANGS = [{ k: 'ar', label: { ar: 'العربية', en: 'Arabic' } }, { k: 'en', label: { ar: 'الإنجليزية', en: 'English' } }];
function view(x) { viewing.value = x; viewLang.value = x.termsAr ? 'ar' : 'en'; }

const columns = [
  { key: 'name', header: { ar: 'الشروط', en: 'Terms' }, width: 'minmax(180px,1.5fr)' },
  { key: 'version', header: { ar: 'الإصدار', en: 'Version' }, width: '90px', kind: 'num' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '130px', bare: true },
  { key: 'effectiveDate', header: { ar: 'سارية من', en: 'Effective' }, width: '92px', kind: 'date', value: (x) => fmtDay(x.effectiveDate) },
  { key: 'acceptedBy', header: { ar: 'قبلها', en: 'Accepted by' }, width: '76px', kind: 'num', value: (x) => x.acceptedBy ?? 0 },
  { key: 'history', header: { ar: 'النشر والإيقاف', en: 'Published / retired' }, width: 'minmax(140px,1fr)' },
  { key: 'act', header: '', width: '190px', bare: true },
];
</script>

<template>
  <div class="row wrap mb-2.5">
    <span class="grow text-[10.5px] text-sec">{{ t('الإصدار الساري هو المنشور الذي حلّ تاريخ سريانه. الطلب يحفظ نص الإصدار الذي قبله.', 'The version in force is the published one whose effective date has come. A request keeps the text of the version it accepted.') }}</span>
    <Btn size="sm" tone="primary" :label="{ ar: '+ إصدار شروط', en: '+ Terms version' }" @click="form = {}" />
  </div>
  <ErrorBanner :error="list.error.value || act.error.value" :closable="!list.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :page-size="25" :loading="list.isLoading.value" :row-key="(x) => x.id" :empty-text="{ ar: 'لا إصدارات شروط بعد — أضف أول إصدار.', en: 'No terms version yet — add the first one.' }">
      <template #cell-name="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.name }}</div>
          <div class="cell-sub truncate" dir="auto">{{ row.jurisdictionCity || '' }}<template v-if="row.createdBy"> · {{ row.createdBy }}</template></div>
        </div>
      </template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :label="pick(TERMS, row.status)" :fg="pick(TERMS, row.status).fg" :bg="pick(TERMS, row.status).bg" />
          <Chip v-if="row.inForce" small :label="{ ar: 'سارية الآن', en: 'In force' }" fg="#ffffff" bg="#1d7a3e" />
          <Chip v-if="row.origin === 'odoo'" small :label="ORIGIN.odoo" :fg="ORIGIN.odoo.fg" :bg="ORIGIN.odoo.bg" />
        </span>
      </template>
      <template #cell-history="{ row }">
        <div class="text-[9.5px] leading-[1.6] text-muted">
          <div v-if="row.publishedAt">{{ t('نُشرت', 'Published') }} <span class="num ltr inline-block">{{ fmtDate(row.publishedAt) }}</span> · <span dir="auto">{{ row.publishedBy || '—' }}</span></div>
          <div v-if="row.retiredAt">{{ t('أُوقفت', 'Retired') }} <span class="num ltr inline-block">{{ fmtDate(row.retiredAt) }}</span> · <span dir="auto">{{ row.retiredBy || '—' }}</span></div>
          <div v-if="!row.publishedAt && !row.retiredAt">—</div>
        </div>
      </template>
      <template #cell-act="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Btn size="sm" tone="ghost" :label="{ ar: 'النص', en: 'Text' }" @click.stop="view(row)" />
          <template v-if="!row.ownedBySource">
            <Btn v-if="row.status === 'draft'" size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="form = row" />
            <Btn v-if="row.status === 'draft'" size="sm" tone="softGreen" :loading="act.pending.value" :label="{ ar: 'نشر', en: 'Publish' }" @click.stop="publish(row)" />
            <Btn v-if="row.status === 'published'" size="sm" tone="ghost" :loading="act.pending.value" :label="{ ar: 'إيقاف', en: 'Retire' }" @click.stop="retire(row)" />
          </template>
          <span v-else class="text-[9px] text-faint">{{ t('في أودو', 'in Odoo') }}</span>
        </span>
      </template>
    </DataTable>
  </div>

  <FormDrawer :open="!!form" :title="editing ? { ar: `تعديل المسودة ${editing.version}`, en: `Edit draft ${editing.version}` } : { ar: 'إصدار شروط جديد', en: 'New terms version' }"
              :sub="{ ar: 'يُحفظ مسودة؛ النشر يثبّت نصه', en: 'Saved as a draft; publishing freezes its text' }" :fields="fields" :initial="initial" :submit="submit" :action="action" :width="640" @close="form = null" />

  <Modal :open="!!viewing" :width="680" :title="viewing ? `${viewing.name} · ${viewing.version}` : null" :sub="viewing ? { ar: `سارية من ${fmtDay(viewing.effectiveDate)}`, en: `Effective from ${fmtDay(viewing.effectiveDate)}` } : null" @close="viewing = null">
    <template v-if="viewing">
      <Tabs v-model="viewLang" :tabs="LANGS" variant="pill" class="!mb-2" />
      <div class="max-h-[60vh] overflow-auto whitespace-pre-wrap rounded-[12px] bg-soft px-3 py-2.5 text-[11px] leading-[1.9] text-sec [overflow-wrap:anywhere]" :dir="viewLang === 'ar' ? 'rtl' : 'ltr'">{{ (viewLang === 'ar' ? viewing.termsAr : viewing.termsEn) || t('لا نص بهذه اللغة.', 'No text in this language.') }}</div>
      <div v-if="viewing.hash" class="mt-2 text-[9px] text-faint">{{ t('البصمة', 'Fingerprint') }} <span class="num ltr inline-block break-all">{{ viewing.hash }}</span></div>
    </template>
  </Modal>
</template>
