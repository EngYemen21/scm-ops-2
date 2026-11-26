<script setup>
// Step 2 of the import: how the file is read (the answer of POST /banking/import/preview) and what it holds.
// The person may change the row of titles, the options (amount mode, order of the date parts, decimal mark, sign,
// order of rows, delimiter / encoding of a CSV, sheet of a workbook) and the column of every field; the wizard reads
// the file again after each change. The check is set against the statement's own figures.
//   <ImportMapping :preview :statement :options :mapping :missing :reading @mapping="(field, value) => …" @option="(key, value) => …" />
import { computed } from 'vue';
import { Chip, Field, NumberInput, SelectInput } from '@/components';
import { bi, t } from '@/i18n';
import { AMOUNT_MODE, DATE_ORDER, DELIMITERS, ENCODINGS, IMPORT_FIELD, ROW_ORDER, centsStr, fmtDay, money, signClass, toCents, useBankingLookups } from './banking';

const props = defineProps({
  preview: { type: Object, required: true },
  statement: { type: Object, required: true },
  options: { type: Object, required: true },
  mapping: { type: Object, required: true },
  /** Required fields that have no column yet. */
  missing: { type: Array, default: () => [] },
  reading: { type: Boolean, default: false },
});
const emit = defineEmits(['mapping', 'option']);
const lk = useBankingLookups();

const file = computed(() => props.preview.file || {});
const columns = computed(() => props.preview.columns || []);
const columnOptions = computed(() => columns.value.map((c) => ({ v: c.name, l: `${c.name}${c.samples?.length ? ` — ${c.samples.slice(0, 2).join(' | ')}` : ''}` })));
const fields = computed(() => lk.importFields.value.filter((f) => f !== 'description'));
const modes = computed(() => lk.amountModes.value.map((m) => ({ v: m, l: AMOUNT_MODE[m] || m })));
const dateOrders = Object.entries(DATE_ORDER).map(([v, l]) => ({ v, l }));
const rowOrders = Object.entries(ROW_ORDER).map(([v, l]) => ({ v, l }));
const decimals = [{ v: '.', l: { ar: 'نقطة (1,234.50)', en: 'Point (1,234.50)' } }, { v: ',', l: { ar: 'فاصلة (1.234,50)', en: 'Comma (1.234,50)' } }];
const sheetOptions = computed(() => (file.value.sheets || []).map((s) => ({ v: s, l: s })));
const descriptions = computed(() => (Array.isArray(props.mapping.description) ? props.mapping.description : props.mapping.description ? [props.mapping.description] : []));
function toggleDescription(name) {
  const now = descriptions.value;
  emit('mapping', 'description', now.includes(name) ? now.filter((x) => x !== name) : [...now, name]);
}
const required = (f) => props.missing.includes(f) || ['date'].includes(f);
const headerRow = computed(() => Number(props.options.headerRow) || 0);
const width = computed(() => Math.max(columns.value.length, 1));
const cell = (row, i) => (Array.isArray(row) ? row[i] ?? '' : '');

// ---- the check against the statement's own figures (hundredths)
const check = computed(() => props.preview.check);
const st = computed(() => props.statement);
/** What the statement still needs to balance: closing − opening − the net of the lines it already holds. */
const needed = computed(() => toCents(st.value.closingBalance) - toCents(st.value.openingBalance) - toCents(st.value.totals?.net || '0'));
const fits = computed(() => {
  const c = check.value;
  if (!c) return [];
  const out = [];
  const inside = (!c.dateFrom || c.dateFrom >= st.value.dateFrom) && (!c.dateTo || c.dateTo <= st.value.dateTo);
  out.push({ ok: inside, text: inside ? t('كل تواريخ الملف داخل فترة الكشف', 'Every date of the file is inside the statement period')
    : t(`تواريخ الملف (${fmtDay(c.dateFrom)} — ${fmtDay(c.dateTo)}) تتجاوز فترة الكشف — ما يقع خارجها يُرفض`, `The file’s dates (${fmtDay(c.dateFrom)} — ${fmtDay(c.dateTo)}) go beyond the statement period — rows outside it are refused`) });
  if (c.opening != null) {
    const ok = toCents(c.opening) === toCents(st.value.openingBalance);
    out.push({ ok, text: ok ? t('الرصيد الافتتاحي في الملف يساوي افتتاحي الكشف', 'The file opens with the statement’s opening balance') : t(`الملف يفتتح بـ ${money(c.opening)} والكشف بـ ${money(st.value.openingBalance)}`, `The file opens at ${money(c.opening)}, the statement at ${money(st.value.openingBalance)}`) });
  }
  if (c.closing != null) {
    const ok = toCents(c.closing) === toCents(st.value.closingBalance);
    out.push({ ok, text: ok ? t('الرصيد الختامي في الملف يساوي ختامي الكشف', 'The file closes with the statement’s closing balance') : t(`الملف يختم بـ ${money(c.closing)} والكشف بـ ${money(st.value.closingBalance)}`, `The file closes at ${money(c.closing)}, the statement at ${money(st.value.closingBalance)}`) });
  }
  const net = toCents(c.net);
  const ok = net === needed.value;
  out.push({ ok, text: ok ? t('صافي الملف يُكمل الكشف حتى يتوازن', 'The net of the file brings the statement to balance')
    : t(`صافي الملف ${money(c.net)} والكشف يحتاج ${money(centsStr(needed.value))}${(st.value.totals?.lines || 0) > 0 ? ' — للكشف سطور مسبقًا؛ المكرر منها لا يُضاف' : ''}`, `The file nets ${money(c.net)}, the statement needs ${money(centsStr(needed.value))}${(st.value.totals?.lines || 0) > 0 ? ' — the statement holds lines already; duplicates are not added' : ''}`) });
  if (c.balanceBreaks > 0) out.push({ ok: false, text: t(`الرصيد الجاري في الملف ينقطع ${c.balanceBreaks} مرة — سطور ناقصة أو ترتيب مختلف`, `The running balance of the file breaks ${c.balanceBreaks} time(s) — rows are missing or out of order`) });
  return out;
});
</script>

<template>
  <div :class="{ 'opacity-60': reading }">
    <div class="row wrap mb-2 text-[10.5px] text-sec">
      <span>{{ t('الصيغة', 'Format') }}: <b class="num uppercase">{{ file.format }}</b></span>
      <span>· {{ t('صفوف الملف', 'File rows') }}: <b class="num">{{ file.rows }}</b></span>
      <span v-if="file.encoding">· {{ t('الترميز', 'Encoding') }}: <b class="num">{{ file.encoding }}</b></span>
      <span v-if="file.delimiter">· {{ t('الفاصل', 'Delimiter') }}: <b class="num">{{ file.delimiter }}</b></span>
      <span>· {{ t('صف العناوين', 'Header row') }}: <b class="num">{{ preview.headerRow || t('لا يوجد', 'none') }}</b></span>
      <span v-if="preview.profile">· {{ t('الربط المحفوظ', 'Saved mapping') }}: <b dir="auto">{{ preview.profile.name }}</b></span>
      <span v-if="reading" class="font-bold text-violet">· {{ t('تُعاد القراءة…', 'Reading again…') }}</span>
    </div>
    <div v-if="preview.mappingProblem" class="banner amber mb-2.5">
      <div class="text-[11px] font-bold" dir="auto">{{ t(preview.mappingProblem.ar, preview.mappingProblem.en) }}</div>
      <div class="text-[9px] opacity-70"><code>{{ preview.mappingProblem.code }}</code> · {{ t('اختر الأعمدة الصحيحة أدناه.', 'Choose the right columns below.') }}</div>
    </div>

    <!-- options -->
    <div class="card-title mb-1.5">{{ t('خيارات القراءة', 'Reading options') }}</div>
    <div class="form-grid">
      <SelectInput full :model-value="options.amountMode || ''" :options="modes" :label="{ ar: 'كيف يظهر المبلغ', en: 'How the amount is shown' }" @update:model-value="(v) => emit('option', 'amountMode', v)" />
      <SelectInput :model-value="options.dateOrder || ''" :options="dateOrders" :label="{ ar: 'ترتيب أجزاء التاريخ', en: 'Order of the date parts' }"
                   :hint="preview.options?.dateOrderGuessed ? { ar: 'لم يُحسم من الملف — تأكد منه', en: 'Not settled by the file — check it' } : null" @update:model-value="(v) => emit('option', 'dateOrder', v)" />
      <SelectInput :model-value="options.decimal || ''" :options="decimals" :label="{ ar: 'العلامة العشرية', en: 'Decimal mark' }" @update:model-value="(v) => emit('option', 'decimal', v)" />
      <SelectInput :model-value="options.order || 'auto'" :options="rowOrders" :label="{ ar: 'ترتيب الصفوف', en: 'Row order' }" @update:model-value="(v) => emit('option', 'order', v)" />
      <NumberInput :model-value="options.headerRow ?? 0" :min="0" :max="200" :step="1" :label="{ ar: 'رقم صف العناوين (0 = بلا عناوين)', en: 'Header row number (0 = none)' }" @update:model-value="(v) => emit('option', 'headerRow', v == null ? 0 : Math.max(0, Math.min(200, Math.trunc(v))))" />
      <SelectInput v-if="file.format === 'csv'" :model-value="options.delimiter || ''" :options="DELIMITERS" :label="{ ar: 'الفاصل', en: 'Delimiter' }" @update:model-value="(v) => emit('option', 'delimiter', v)" />
      <SelectInput v-if="file.format === 'csv'" :model-value="options.encoding || ''" :options="ENCODINGS" :label="{ ar: 'الترميز', en: 'Encoding' }" @update:model-value="(v) => emit('option', 'encoding', v)" />
      <SelectInput v-if="file.format === 'xlsx' && sheetOptions.length > 1" :model-value="options.sheet || ''" :options="sheetOptions" :label="{ ar: 'الورقة', en: 'Sheet' }" @update:model-value="(v) => emit('option', 'sheet', v)" />
      <Field :label="{ ar: 'الإشارة', en: 'Sign' }">
        <label class="row !gap-1.5 pt-2 text-[10.5px]"><input type="checkbox" :checked="!!options.invert" @change="emit('option', 'invert', $event.target.checked)"> {{ t('اعكس الإشارة (البنك يعرض الصادر موجبًا)', 'Invert the sign (the bank shows money out as positive)') }}</label>
      </Field>
    </div>

    <!-- mapping -->
    <div class="card-title mb-1.5 mt-4">{{ t('ربط الأعمدة', 'Column mapping') }}</div>
    <div class="form-grid">
      <SelectInput v-for="f in fields" :key="f" :model-value="typeof mapping[f] === 'string' ? mapping[f] : ''" :options="columnOptions" :required="required(f)"
                   :label="IMPORT_FIELD[f] || { ar: f, en: f }" :placeholder="{ ar: '— لا يوجد —', en: '— none —' }"
                   :error="missing.includes(f) ? t('هذا الحقل إلزامي لهذه الطريقة', 'Required for this amount mode') : null" @update:model-value="(v) => emit('mapping', f, v)" />
      <Field full :label="{ ar: 'الوصف (عمود أو أكثر، يُضمّ بعضها إلى بعض)', en: 'Description (one or more columns, joined)' }">
        <div class="row wrap !gap-1.5">
          <button v-for="c in columns" :key="c.ref" type="button" class="pill" :class="{ active: descriptions.includes(c.name) }" :aria-pressed="descriptions.includes(c.name)" @click="toggleDescription(c.name)">{{ c.name }}</button>
        </div>
      </Field>
    </div>

    <!-- the top of the file, with the row of titles marked -->
    <div class="card-title mb-1.5 mt-4">{{ t('أعلى الملف', 'Top of the file') }}</div>
    <div class="overflow-x-auto rounded-[12px] border border-line-2">
      <table class="w-full text-[10px]">
        <tbody>
          <tr v-for="(row, i) in preview.top" :key="`t${i}`" :class="i + 1 === headerRow ? 'bg-[#efeaf8] font-extrabold text-violet' : i % 2 ? 'bg-soft' : ''">
            <td class="num w-[36px] border-e border-line-2 px-2 py-1 text-faint">{{ i + 1 }}</td>
            <td v-for="ci in width" :key="ci" class="max-w-[180px] truncate px-2 py-1" dir="auto" :title="cell(row, ci - 1)">{{ cell(row, ci - 1) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="card-title mb-1.5 mt-4">{{ t('أول صفوف البيانات', 'First data rows') }}</div>
    <div class="overflow-x-auto rounded-[12px] border border-line-2">
      <table class="w-full text-[10px]">
        <thead>
          <tr class="bg-soft">
            <th v-for="c in columns" :key="c.ref" class="whitespace-nowrap px-2 py-1.5 text-start font-extrabold text-sec" dir="auto">
              {{ c.name }}
              <span v-for="(v, k) in mapping" :key="k" class="ms-1">
                <Chip v-if="v === c.name || (Array.isArray(v) && v.includes(c.name))" small :label="IMPORT_FIELD[k] || k" fg="#654e92" bg="#efeaf8" />
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in preview.sample" :key="`s${i}`" :class="i % 2 ? 'bg-soft' : ''">
            <td v-for="(c, ci) in columns" :key="c.ref" class="max-w-[200px] truncate px-2 py-1" dir="auto" :title="cell(row, ci)">{{ cell(row, ci) }}</td>
          </tr>
          <tr v-if="!preview.sample?.length"><td :colspan="width" class="px-2 py-3 text-center text-faint">{{ t('لا صفوف بيانات بعد صف العناوين', 'No data row after the header row') }}</td></tr>
        </tbody>
      </table>
    </div>

    <!-- what an import would find -->
    <div class="card-title mb-1.5 mt-4">{{ t('التحقق', 'Check') }}</div>
    <div v-if="!check" class="hint amber !mt-0">{{ t('أكمل ربط الأعمدة ليُفحص الملف.', 'Complete the column mapping to check the file.') }}</div>
    <template v-else>
      <div class="grid grid-cols-4 gap-2 max-[640px]:grid-cols-2">
        <div class="tile"><div class="tile-v">{{ check.rows }}</div><div class="tile-l">{{ t('صفوف', 'Rows') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ check.readable }}</div><div class="tile-l">{{ t('مقروءة', 'Readable') }}</div></div>
        <div class="tile" :class="check.refused ? 'red' : ''"><div class="tile-v">{{ check.refused }}</div><div class="tile-l">{{ t('مرفوضة', 'Refused') }}</div></div>
        <div class="tile"><div class="tile-v !text-[12px]">{{ fmtDay(check.dateFrom) }} — {{ fmtDay(check.dateTo) }}</div><div class="tile-l">{{ t('مدى التواريخ', 'Date range') }}</div></div>
        <div class="tile green"><div class="tile-v">+{{ money(check.in) }}</div><div class="tile-l">{{ t('الوارد', 'Money in') }}</div></div>
        <div class="tile red"><div class="tile-v">{{ money(check.out) }}</div><div class="tile-l">{{ t('الصادر', 'Money out') }}</div></div>
        <div class="tile"><div class="tile-v" :class="signClass(check.net)">{{ money(check.net) }}</div><div class="tile-l">{{ t('الصافي', 'Net') }}</div></div>
        <div class="tile purple"><div class="tile-v !text-[12px]">{{ money(check.opening) }} → {{ money(check.closing) }}</div><div class="tile-l">{{ t('من الرصيد الجاري في الملف', 'From the file’s running balance') }}</div></div>
      </div>
      <div v-if="check.refusedBy?.length" class="mt-2 col !gap-1">
        <div v-for="r in check.refusedBy" :key="r.code" class="text-[10.5px] text-bad"><b class="num">{{ r.count }}</b> × <span dir="auto">{{ bi({ ar: r.ar, en: r.en }) }}</span> <code class="text-[9px] opacity-60">{{ r.code }}</code></div>
      </div>
      <div class="mt-2.5 col !gap-1">
        <div v-for="(f, i) in fits" :key="i" class="text-[10.5px] font-bold" :class="f.ok ? 'text-ok' : 'text-warn'">{{ f.ok ? '✓' : '!' }} {{ f.text }}</div>
      </div>
    </template>
  </div>
</template>
