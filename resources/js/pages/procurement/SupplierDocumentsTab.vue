<script setup>
// Supplier qualification documents (CR, VAT certificate, licences…) with expiry: GET/POST/DELETE /suppliers/:code/documents.
// An expired *mandatory* document blocks new POs for the supplier (the server enforces it; this tab shows why).
//   <SupplierDocumentsTab :code="supplierCode" />
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DateInput, ErrorBanner, SelectInput, TextInput } from '@/components';
import { fmtDateOnly, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import { DOC_STATE_LABELS, DOC_TYPE_LABELS, DOC_TYPE_OPTIONS, plusDays } from './shared';

const props = defineProps({ code: { type: String, required: true } });
const auth = useAuth();
const act = useAction({ invalidate: ['suppliers', 'procurement'] });
/** { supplier, documents[{ id, type, number?, issuedAt?, expiresAt?, mandatory, notes?, fileName?, state: valid|expiring|expired, daysLeft? }], expired, expiringSoon, warnDays } */
const docsQ = useGet(() => `/suppliers/${encodeURIComponent(props.code)}/documents`);
const data = computed(() => docsQ.data.value);

const adding = ref(false);
const blank = () => ({ type: 'cr', number: '', issuedAt: '', expiresAt: plusDays(365), mandatory: true, fileName: '', notes: '' });
const f = ref(blank());
function startAdd() { f.value = blank(); adding.value = true; act.clearError(); }
/** CR and VAT certificates are mandatory by default (the server applies the same default). */
function setType(v) { f.value = { ...f.value, type: v, mandatory: ['cr', 'vat'].includes(v) }; }
const ok = computed(() => !!f.value.type && (!f.value.issuedAt || !f.value.expiresAt || f.value.expiresAt >= f.value.issuedAt));

async function save() {
  const v = f.value;
  const body = { type: v.type, number: v.number || undefined, issuedAt: v.issuedAt || undefined, expiresAt: v.expiresAt || undefined, mandatory: v.mandatory, fileName: v.fileName || undefined, notes: v.notes || undefined };
  const r = await act.run(() => api.postIdempotent(`/suppliers/${encodeURIComponent(props.code)}/documents`, body), { success: t('أُضيف المستند', 'Document added') });
  if (r) { adding.value = false; docsQ.refetch(); }
}
async function remove(d) {
  const yes = await confirm({ title: { ar: `حذف المستند ${DOC_TYPE_LABELS[d.type]?.ar || d.type}؟`, en: `Remove ${DOC_TYPE_LABELS[d.type]?.en || d.type}?` }, sub: { ar: 'يُسجَّل الحذف في Audit Trail', en: 'Recorded in the audit trail' }, okLabel: { ar: 'حذف', en: 'Remove' } });
  if (!yes) return;
  const r = await act.run(() => api.del(`/suppliers/${encodeURIComponent(props.code)}/documents/${d.id}`), { success: t('حُذف المستند', 'Document removed') });
  if (r) docsQ.refetch();
}
const daysText = (d) => (d.daysLeft == null ? '' : d.daysLeft < 0 ? t(`منتهٍ منذ ${fmtNum(-d.daysLeft)} يوم`, `expired ${fmtNum(-d.daysLeft)} day(s) ago`) : t(`${fmtNum(d.daysLeft)} يوم متبقٍ`, `${fmtNum(d.daysLeft)} day(s) left`));
</script>

<template>
  <ErrorBanner :error="docsQ.error.value || act.error.value" @close="act.clearError()" />
  <div v-if="docsQ.isLoading.value" class="skel min-h-[80px]" />
  <template v-if="data">
    <div v-if="data.expired" class="banner red mb-2">{{ t(`${fmtNum(data.expired)} مستند إلزامي منتهٍ — أوامر الشراء الجديدة موقوفة حتى التجديد (أو استثناء موثق من صاحب صلاحية الاعتماد)`, `${fmtNum(data.expired)} mandatory document(s) expired — new POs are blocked until renewal (or a documented override by a po.approve holder)`) }}</div>
    <div v-else-if="data.expiringSoon" class="banner amber mb-2">{{ t(`${fmtNum(data.expiringSoon)} مستند ينتهي خلال ${data.warnDays} يومًا — تابع التجديد مع المورد`, `${fmtNum(data.expiringSoon)} document(s) expire within ${data.warnDays} days — follow up the renewal`) }}</div>

    <div v-if="!data.documents.length && !adding" class="empty">{{ t('لا مستندات مسجلة — سجّل السجل التجاري والشهادة الضريبية بتاريخ انتهائهما', 'No documents yet — record the CR and VAT certificate with their expiry dates') }}</div>
    <div class="col !gap-1.5">
      <div v-for="d in data.documents" :key="d.id" class="row wrap rounded-[11px] border border-line-2 px-[13px] py-[9px] text-[10.5px]">
        <span class="font-extrabold">{{ t(DOC_TYPE_LABELS[d.type]?.ar || d.type, DOC_TYPE_LABELS[d.type]?.en || d.type) }}</span>
        <Chip v-if="d.mandatory" small fg="#654e92" bg="#efeaf8" :label="{ ar: 'إلزامي', en: 'Mandatory' }" />
        <span v-if="d.number" class="num ltr text-[10px] text-muted">{{ d.number }}</span>
        <span class="grow" />
        <span class="cell-date ltr" :title="t('ينتهي', 'Expires')">{{ d.expiresAt ? fmtDateOnly(d.expiresAt) : t('بلا انتهاء', 'No expiry') }}</span>
        <Chip :map="DOC_STATE_LABELS" :k="d.state" small />
        <span class="text-[9px] text-faint">{{ daysText(d) }}</span>
        <span v-if="d.fileName" class="num ltr text-[9px] text-brand-dark">{{ d.fileName }}</span>
        <button v-if="auth.can('supplier.manage')" type="button" class="x-btn !h-6 !w-6" aria-label="remove" @click="remove(d)">✕</button>
      </div>
    </div>

    <div v-if="adding" class="tile mt-2">
      <div class="form-grid">
        <SelectInput :model-value="f.type" :label="{ ar: 'نوع المستند', en: 'Document type' }" required :options="DOC_TYPE_OPTIONS" @update:model-value="setType" />
        <TextInput v-model="f.number" :label="{ ar: 'الرقم', en: 'Number' }" dir="ltr" />
        <DateInput v-model="f.issuedAt" :label="{ ar: 'تاريخ الإصدار', en: 'Issued' }" />
        <DateInput v-model="f.expiresAt" :label="{ ar: 'تاريخ الانتهاء', en: 'Expires' }" :error="!ok ? t('تاريخ الانتهاء قبل الإصدار', 'Expiry before issue date') : null" />
        <TextInput v-model="f.fileName" :label="{ ar: 'اسم الملف (الرفع Integration Pending)', en: 'File name (upload is Integration Pending)' }" dir="ltr" placeholder="cr-2026.pdf" />
        <TextInput v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" />
        <label class="field full row cursor-pointer !gap-2 text-[10.5px] font-extrabold"><input v-model="f.mandatory" type="checkbox">{{ t('إلزامي — انتهاؤه يوقف أوامر الشراء الجديدة', 'Mandatory — expiry blocks new POs') }}</label>
      </div>
      <div class="row mt-2 justify-end !gap-1.5">
        <Btn tone="dark" size="sm" :disabled="!ok" :loading="act.pending.value" :label="{ ar: 'حفظ المستند', en: 'Save document' }" @click="save" />
        <Btn tone="soft" size="sm" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="adding = false" />
      </div>
    </div>
    <div v-else-if="auth.can('supplier.manage')" class="mt-2"><Btn tone="soft" size="sm" :label="{ ar: '+ مستند', en: '+ Document' }" @click="startAdd" /></div>
  </template>
</template>
