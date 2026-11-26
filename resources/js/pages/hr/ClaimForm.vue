<script setup>
// Write an expense claim, or rewrite a draft: POST /hr/claims · PATCH /hr/claims/{id}, then the receipts of the lines
// (POST …/lines/{lineId}/receipts, one request per file — a receipt is up to 5 MB) and, when asked, «إرسال للمراجعة».
// Every line: date, category, description, amount (as written on the receipt, VAT included, or before VAT), VAT %,
// who paid — the employee (to be reimbursed) or the company — and the receipt. The figures shown are a preview of the
// server's own computation; the server's are the ones kept.
// A reviewer may enter a claim for an employee who has no login (`employee`); it is then decided by another reviewer.
//   <ClaimForm :open :claim="detail | null" @close @done="(claim) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, NumberInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import HrFilePick from './HrFilePick.vue';
import HrFiles from './HrFiles.vue';
import HrFormDrawer from './HrFormDrawer.vue';
import { PAID_BY_OPTIONS, SELECT, lineFigures, money, newClaimLine, pname, todayIso, useHrLookups } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The draft to rewrite (the claim as GET /hr/claims/{id} returns it); null writes a new claim. */
  claim: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction({ invalidate: ['hr'] });
const lookups = useHrLookups(() => props.open);
const f = reactive({ title: '', notes: '', employee: '' });
const lines = ref([]);
const tried = ref(false);

watch(() => props.open, (open) => {
  if (!open) return;
  const c = props.claim;
  Object.assign(f, { title: c?.title || '', notes: c?.notes || '', employee: '' });
  // a stored line carries its amount before VAT
  lines.value = c ? c.lines.map((l) => ({ ...newClaimLine(), id: l.id, date: l.date, category: l.category?.id || '', description: l.description, amount: Number(l.amount), vatPct: l.vatPct, amountIncludesVat: false, paidBy: l.paidBy, receipts: l.receipts || [] })) : [newClaimLine()];
  tried.value = false;
  act.clearError();
}, { immediate: true });

const categoryOptions = computed(() => {
  const out = [...lookups.categoryOptions.value];
  // a line of an older draft may carry a category that is no longer offered: it stays selectable on that line
  for (const l of props.claim?.lines || []) { if (l.category && !out.some(([id]) => id === l.category.id)) out.push([l.category.id, pname(l.category)]); }
  return out;
});
const categoryVat = (id) => lookups.categories.value.find((c) => c.id === id)?.vatPct ?? 0;
const employeeOptions = computed(() => [['', { ar: '— أنا —', en: '— myself —' }], ...lookups.employeeOptions.value]);
const upd = (i, patch) => { lines.value = lines.value.map((l, j) => (j === i ? { ...l, ...patch } : l)); };
/** Picking a category proposes its VAT rate while the person has not typed one. */
const pickCategory = (i, id) => upd(i, { category: id, vatPct: lines.value[i].vatPct == null ? categoryVat(id) : lines.value[i].vatPct });
const add = () => { lines.value = [...lines.value, newClaimLine()]; };
const remove = (i) => { lines.value = lines.value.length > 1 ? lines.value.filter((_, j) => j !== i) : [newClaimLine()]; };
const figures = (l) => lineFigures(l.amount, l.vatPct ?? categoryVat(l.category), l.amountIncludesVat);
const totals = computed(() => lines.value.reduce((s, l) => { const x = figures(l); const due = l.paidBy === 'company' ? 0 : x.total; return { amount: s.amount + x.amount, vat: s.vat + x.vat, total: s.total + x.total, due: s.due + due }; }, { amount: 0, vat: 0, total: 0, due: 0 }));

/** What is wrong with a line, field by field (shown after the first attempt to save). */
const problems = (l) => ({
  date: !l.date ? t('التاريخ مطلوب', 'Required') : l.date > todayIso() ? t('لا يكون في المستقبل', 'Not in the future') : null,
  category: !l.category ? t('اختر الفئة', 'Pick a category') : null,
  description: String(l.description || '').trim().length < 2 ? t('اكتب الوصف', 'Describe it') : null,
  amount: !(Number(l.amount) > 0) ? t('أكبر من صفر', 'Greater than zero') : null,
});
const lineOk = (l) => Object.values(problems(l)).every((x) => !x);
const ok = computed(() => f.title.trim().length >= 3 && lines.value.length > 0 && lines.value.every(lineOk));
const err = (l, k) => (tried.value ? problems(l)[k] : null);

async function save(thenSubmit) {
  tried.value = true;
  if (!ok.value) return;
  const body = { title: f.title.trim(), notes: f.notes.trim() || null,
    lines: lines.value.map((l) => ({ ...(l.id ? { id: l.id } : {}), date: l.date, category: l.category, description: l.description.trim(), amount: Number(l.amount), vatPct: Number(l.vatPct ?? categoryVat(l.category)) || 0,
      amountIncludesVat: !!l.amountIncludesVat, paidBy: l.paidBy })) };
  if (!props.claim && f.employee) body.employee = f.employee;
  let saved = await act.run(() => (props.claim ? api.patch(`/hr/claims/${props.claim.id}`, body) : api.postIdempotent('/hr/claims', body)), { success: (c) => t(`حُفظت المطالبة ${c.number}`, `Claim ${c.number} saved`) });
  if (!saved) return;
  // the lines come back in the order they were sent: each receipt goes to its line
  for (let i = 0; i < lines.value.length; i++) {
    const file = lines.value[i].file;
    const lineId = saved.lines[i]?.id;
    if (!file || !lineId) continue;
    const withReceipt = await act.run(() => api.postIdempotent(`/hr/claims/${saved.id}/lines/${lineId}/receipts`, { fileName: file.fileName, mime: file.mime, base64: file.base64 }), { success: false });
    if (!withReceipt) { emit('done', saved); emit('close'); return; } // the claim is saved as a draft; the receipt can be attached from its card
    saved = withReceipt;
  }
  if (thenSubmit) {
    const submitted = await act.run(() => api.postIdempotent(`/hr/claims/${saved.id}/submit`), { success: t('أُرسلت المطالبة للمراجعة', 'The claim was submitted for review') });
    if (submitted) saved = submitted;
  }
  emit('done', saved);
  emit('close');
}
</script>

<template>
  <HrFormDrawer :open="open" :width="720" :title="claim ? { ar: `تعديل المطالبة ${claim.number}`, en: `Edit claim ${claim.number}` } : { ar: 'مطالبة مصروفات جديدة', en: 'New expense claim' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="tried && !ok" :submit-label="{ ar: 'حفظ كمسودة', en: 'Save as a draft' }" :second-label="{ ar: 'حفظ وإرسال للمراجعة', en: 'Save and submit for review' }"
                :missing="{ ar: 'أكمل عنوان المطالبة (3 أحرف على الأقل) والحقول المعلَّمة في السطور', en: 'Complete the title (at least 3 characters) and the marked fields of the lines' }"
                @submit="save(false)" @second="save(true)" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid mb-3">
      <TextInput v-model="f.title" :label="{ ar: 'موضوع المطالبة', en: 'Subject of the claim' }" required full :placeholder="{ ar: 'مثال: رحلة عمل إلى جدة — مايو', en: 'e.g. Business trip to Jeddah — May' }"
                 :error="tried && f.title.trim().length < 3 ? t('3 أحرف على الأقل', 'At least 3 characters') : null" />
      <SelectInput v-if="!claim && auth.can('expense.review')" v-model="f.employee" :label="{ ar: 'صاحب المصروف', en: 'Whose expenses' }" :options="employeeOptions" full
                   :hint="{ ar: 'لإدخال مطالبة عن موظف لا حساب له — يراجعها حينها مراجع آخر', en: 'To enter a claim for an employee without a login — another reviewer then decides it' }" />
    </div>

    <div class="card-title mb-1.5">{{ t('سطور المصروفات', 'Expense lines') }}<span class="card-count num ms-2">{{ lines.length }}</span></div>
    <div class="col !gap-2.5">
      <div v-for="(l, i) in lines" :key="l.key" class="rounded-[14px] border border-line bg-white p-3">
        <div class="row mb-2">
          <span class="text-[10px] font-extrabold text-faint">{{ t('سطر', 'Line') }} <span class="num">{{ i + 1 }}</span></span>
          <span class="grow" />
          <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :aria-label="t('حذف السطر', 'Remove the line')" @click="remove(i)">✕</button>
        </div>
        <div class="form-grid">
          <DateInput :model-value="l.date" :label="{ ar: 'تاريخ المصروف', en: 'Date of the expense' }" required :max="todayIso()" :error="err(l, 'date')" @update:model-value="upd(i, { date: $event })" />
          <SelectInput :model-value="l.category" :label="{ ar: 'الفئة', en: 'Category' }" required :options="categoryOptions" :placeholder="SELECT" :error="err(l, 'category')" @update:model-value="pickCategory(i, $event)" />
          <TextInput :model-value="l.description" :label="{ ar: 'الوصف', en: 'Description' }" required full :error="err(l, 'description')" @update:model-value="upd(i, { description: $event })" />
          <NumberInput :model-value="l.amount" :label="l.amountIncludesVat ? { ar: 'المبلغ كما في الإيصال (شامل الضريبة)', en: 'Amount as on the receipt (VAT included)' } : { ar: 'المبلغ قبل الضريبة', en: 'Amount before VAT' }" required :min="0"
                       :error="err(l, 'amount')" @update:model-value="upd(i, { amount: $event })" />
          <NumberInput :model-value="l.vatPct ?? categoryVat(l.category)" :label="{ ar: 'ضريبة القيمة المضافة %', en: 'VAT %' }" :min="0" :max="100" @update:model-value="upd(i, { vatPct: $event })" />
          <PillChoice :model-value="l.amountIncludesVat ? 'in' : 'ex'" :label="{ ar: 'المبلغ المكتوب', en: 'The amount typed is' }" :options="[['in', { ar: 'شامل الضريبة', en: 'VAT included' }], ['ex', { ar: 'قبل الضريبة', en: 'Before VAT' }]]"
                      @update:model-value="upd(i, { amountIncludesVat: $event === 'in' })" />
          <PillChoice :model-value="l.paidBy" :label="{ ar: 'من دفع؟', en: 'Who paid?' }" :options="PAID_BY_OPTIONS" @update:model-value="upd(i, { paidBy: $event })" />
        </div>
        <div class="row wrap mt-2.5">
          <HrFilePick small :model-value="l.file" @update:model-value="upd(i, { file: $event })" />
          <HrFiles :files="l.receipts" />
          <span class="grow" />
          <span class="text-[10px] text-sec">
            {{ t('قبل الضريبة', 'Before VAT') }} <b class="num">{{ money(figures(l).amount) }}</b> · {{ t('ضريبة', 'VAT') }} <b class="num">{{ money(figures(l).vat) }}</b> · {{ t('الإجمالي', 'Total') }} <b class="num text-ink">{{ money(figures(l).total) }}</b>
          </span>
        </div>
      </div>
    </div>
    <div class="row mt-2.5 flex-wrap justify-between !gap-2.5">
      <Btn tone="softPurple" size="sm" :label="{ ar: '+ سطر', en: '+ Line' }" @click="add" />
      <div class="rounded-[12px] bg-soft px-3 py-2 text-[10.5px] leading-[1.9]">
        {{ t('قبل الضريبة', 'Before VAT') }} <b class="num">{{ money(totals.amount) }}</b> · {{ t('ضريبة', 'VAT') }} <b class="num">{{ money(totals.vat) }}</b> · {{ t('الإجمالي', 'Total') }} <b class="num">{{ money(totals.total) }}</b>
        <div>{{ t('المستحق للموظف', 'Due to the employee') }}: <b class="num text-ink">{{ money(totals.due) }}</b> {{ t('ر.س', 'SAR') }}</div>
      </div>
    </div>
    <TextArea v-model="f.notes" field-class="mt-3" :label="{ ar: 'ملاحظات للمراجع', en: 'Notes for the reviewer' }" :rows="2" />
  </HrFormDrawer>
</template>
