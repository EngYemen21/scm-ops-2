<script setup>
// The calculated lines of one statement, paged (a statement of Odoo's history holds thousands): one line per invoice
// and rule, a negative line per credit note, a reversal of a line that no longer holds.
// GET /commissions/statements/{number}/lines?q=&kind=&page=&pageSize=50 → the Paging envelope + totals { base, commission, invoiced }.
// Its search, kind filter and page live in the query string (lq · lkind · lpage). A row opens "how this figure was
// reached" under the table; with `canChange` (a draft the user manages) an invoice can be excluded, with a reason, or taken back in.
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { API, LINES_PAGE_SIZE, LINE_KIND, commissionText, dayText, documentLink, explainRows, money, pct, personName, recordName, useCommissions, useSearchBox } from './commissions';

const props = defineProps({
  number: { type: String, required: true },
  canChange: { type: Boolean, default: false },
});
const emit = defineEmits(['exclude', 'include']);

const { nav } = useCommissions();
const { text, type, apply } = useSearchBox(nav, 'lq');
const kind = computed(() => nav.get('lkind'));
const page = computed(() => { const n = Number(nav.get('lpage')); return Number.isInteger(n) && n > 0 ? n : 1; });
const list = useList(() => `${API}/statements/${encodeURIComponent(props.number)}/lines`, () => ({ q: nav.get('lq'), kind: kind.value || 'invoice,credit_note,reversal', page: page.value, pageSize: LINES_PAGE_SIZE }));
const d = computed(() => list.data.value || null);

const KINDS = [{ k: '', label: { ar: 'كل السطور', en: 'All lines' } }, { k: 'invoice', label: LINE_KIND.invoice }, { k: 'credit_note', label: LINE_KIND.credit_note }, { k: 'reversal', label: LINE_KIND.reversal }];
const columns = [
  { key: 'docNumber', header: { ar: 'المستند', en: 'Document' }, width: '150px' },
  { key: 'docDate', header: { ar: 'تاريخه', en: 'Date' }, width: '84px', kind: 'date', value: (l) => dayText(l.docDate) },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(130px,1.3fr)', kind: 'name', value: (l) => recordName(l.customer) },
  { key: 'salesperson', header: { ar: 'المندوب', en: 'Salesperson' }, width: 'minmax(100px,1fr)', class: 'text-[10px]', value: (l) => (l.salesperson ? personName(l.salesperson) : '—') },
  { key: 'baseAmount', header: { ar: 'الأساس', en: 'Base' }, width: '92px', kind: 'num', value: (l) => money(l.baseAmount) },
  { key: 'rate', header: { ar: 'النسبة', en: 'Rate' }, width: '62px', kind: 'num', value: (l) => (l.excluded ? '—' : pct(l.rate)) },
  { key: 'commission', header: { ar: 'العمولة', en: 'Commission' }, width: '92px', kind: 'num' },
  { key: 'flags', header: '', width: '112px', bare: true },
];

const selected = ref(null);
watch(() => [props.number, page.value, kind.value, nav.get('lq')], () => { selected.value = null; });
const line = computed(() => (selected.value ? (d.value?.items || []).find((l) => l.id === selected.value) || null : null));
const filtered = computed(() => !!nav.get('lq') || !!kind.value);
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[220px]" :model-value="text" :placeholder="{ ar: 'بحث برقم الفاتورة أو العميل…', en: 'Search invoice number or customer…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="kind" :tabs="KINDS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.patch({ lkind: k, lpage: null }, true)" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="overflow-hidden rounded-xl border border-line-2">
    <DataTable :columns="columns" :paged="d" :loading="list.isFetching.value" :row-key="(l) => l.id" :selected-key="selected" dense
               :empty-text="filtered ? { ar: 'لا سطر يطابق هذا البحث أو هذا النوع.', en: 'No line matches this search or kind.' } : { ar: 'لا سطور محتسبة في هذا الكشف.', en: 'This statement has no calculated line.' }"
               @page="(p) => nav.patch({ lpage: p > 1 ? p : null }, true)" @row-click="(l) => (selected = selected === l.id ? null : l.id)">
      <template #cell-docNumber="{ row }">
        <div class="min-w-0">
          <div class="cell-id truncate" :class="{ 'line-through opacity-60': row.voided }">{{ row.docNumber || '—' }}</div>
          <Chip :map="LINE_KIND" :k="row.kind" small />
        </div>
      </template>
      <template #cell-commission="{ row }"><span class="num ltr font-bold" :class="[Number(row.commission) < 0 ? 'text-bad' : '', row.voided ? 'line-through opacity-60' : '']">{{ commissionText(row.commission) }}</span></template>
      <template #cell-flags="{ row }">
        <div class="flex flex-wrap items-center justify-center gap-1">
          <Chip v-if="row.voided" small fg="#7d7990" bg="#F1EFF6" :label="{ ar: 'ملغى', en: 'Void' }" />
          <Chip v-else-if="row.excluded" small fg="#b26a16" bg="#fbf0dd" :label="{ ar: 'مستبعدة', en: 'Excluded' }" />
          <Chip v-else-if="row.explain && row.explain.late" small fg="#3C79F5" bg="#e8effe" :label="{ ar: 'متأخرة', en: 'Late' }" />
          <template v-if="canChange && !row.voided && (row.kind === 'invoice' || row.kind === 'credit_note')">
            <Btn v-if="row.excluded" size="sm" tone="soft" class="!h-7 !text-[9px]" :label="{ ar: 'إرجاع', en: 'Include' }" @click.stop="emit('include', row)" />
            <Btn v-else size="sm" tone="ghost" class="!h-7 !text-[9px]" :label="{ ar: 'استبعاد', en: 'Exclude' }" @click.stop="emit('exclude', row)" />
          </template>
        </div>
      </template>
      <template v-if="d && d.totals" #footer>
        <span class="flex flex-wrap items-baseline gap-x-3 font-bold text-sec">
          <span>{{ t('الأساس', 'Base') }} <span class="num ltr text-ink">{{ money(d.totals.base) }}</span></span>
          <span>{{ t('العمولة', 'Commission') }} <span class="num ltr text-ink">{{ commissionText(d.totals.commission) }}</span></span>
          <span v-if="filtered" class="font-normal text-faint">{{ t('(للنتائج المطابقة)', '(of the matching lines)') }}</span>
        </span>
      </template>
    </DataTable>
  </div>

  <div v-if="line" class="mt-2.5 rounded-xl border border-line-2 bg-canvas p-3">
    <div class="mb-2 flex flex-wrap items-center gap-2">
      <span class="text-[11px] font-extrabold">{{ t('كيف حُسب هذا السطر', 'How this line was reached') }}</span>
      <span class="num ltr text-[10.5px] text-sec">{{ line.docNumber }}</span>
      <RouterLink v-if="documentLink(line)" :to="documentLink(line)" class="text-[10px] font-extrabold text-violet">{{ t('فتح الفاتورة في المالية', 'Open the invoice in finance') }}</RouterLink>
      <span v-else-if="line.sourceType === 'OdooInvoice'" class="text-[9.5px] text-muted">{{ t('فاتورة أودو رقم', 'Odoo invoice id') }} <span class="num ltr">{{ line.sourceId }}</span></span>
      <span class="grow" />
      <button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-[11px] font-extrabold text-muted" :aria-label="t('إغلاق', 'Close')" @click="selected = null">✕</button>
    </div>
    <div class="kv-grid">
      <div v-for="(row, i) in explainRows(line)" :key="i" class="kv"><div class="kv-k">{{ row.k }}</div><div class="kv-v" dir="auto">{{ row.v }}</div></div>
      <div v-if="line.ruleLabel" class="kv"><div class="kv-k">{{ t('القاعدة', 'Rule') }}</div><div class="kv-v" dir="auto">{{ line.ruleLabel }}</div></div>
      <div v-if="line.invoiceTotal != null" class="kv"><div class="kv-k">{{ t('إجمالي المستند', 'Document total') }}</div><div class="kv-v num ltr">{{ money(line.invoiceTotal) }}</div></div>
      <div v-if="line.voided" class="kv"><div class="kv-k">{{ t('أُلغي', 'Voided') }}</div><div class="kv-v" dir="auto">{{ line.voided.reason }}</div></div>
    </div>
  </div>
  <div v-else-if="d && d.items.length" class="mt-1.5 text-[9.5px] text-faint">{{ t('اضغط سطرًا لترى كيف حُسبت عمولته: الأساس وأجزاؤه، القاعدة والنسبة.', 'Click a line to see how its commission was reached: the base and its parts, the rule and the rate.') }}</div>
</template>
