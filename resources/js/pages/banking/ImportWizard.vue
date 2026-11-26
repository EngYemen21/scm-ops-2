<script setup>
// Importing the bank's own file (CSV or Excel, 5 MB at most) into an open statement, in three steps:
//   1. the file and, optionally, a saved column mapping of this bank (GET /banking/profiles?bank=…)
//   2. how it is read — POST /banking/import/preview { fileName, base64, bankAccount, profile?, options?, mapping? }:
//      the row of titles, the first rows, a column per field, the options, and the check (rows, readable, refused and
//      why, dates, in / out / net, the bank's running balance) set against the statement's own figures; every change
//      of an option or a column reads the file again
//   3. a dry run or the import — POST /banking/statements/{id}/import { …, dryRun?, saveAs?, makeDefault? }: a report
//      of every row of the file (taken with its line number · duplicate and where it already is · refused and why).
// Safe to repeat: a line the bank account already holds is a duplicate, never a second line.
//   <ImportWizard :statement="detailOrNull" @close="…" @done="…" />
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Drawer, ErrorBanner, Field, SelectInput, Stepper, TextInput } from '@/components';
import { t } from '@/i18n';
import { toast } from '@/stores/ui';
import ImportMapping from './ImportMapping.vue';
import ImportProfiles from './ImportProfiles.vue';
import ImportReport from './ImportReport.vue';
import { MAX_FILE_BYTES, bankLabel, fmtDay, readAsDataUrl, requiredFields } from './banking';

const props = defineProps({
  /** The open statement the file goes into (GET /banking/statements/{id}); null closes the wizard. */
  statement: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const isOpen = computed(() => !!props.statement);
const bankCode = computed(() => props.statement?.bank?.code || '');
const profilesQ = useList(() => (isOpen.value && bankCode.value ? '/banking/profiles' : null), () => ({ bank: bankCode.value }));
const profiles = computed(() => profilesQ.data.value?.items || []);
const profileOptions = computed(() => profiles.value.map((p) => ({ v: p.id, l: `${p.name}${p.isDefault ? ` (${t('الافتراضي', 'default')})` : ''}${p.bank ? '' : ` — ${t('لكل الحسابات', 'any account')}`}` })));

const step = ref('file');
const file = ref(null);              // { name, size, dataUrl }
const fileError = ref('');
const profileId = ref('');
const preview = ref(null);
const options = reactive({});
const mapping = reactive({});
const mappingTouched = ref(false);
const result = ref(null);
const saveAs = ref('');
const makeDefault = ref(true);
const readAct = useAction({ invalidate: false });
const runAct = useAction();

function reset() {
  step.value = 'file'; file.value = null; fileError.value = ''; profileId.value = ''; preview.value = null; result.value = null; saveAs.value = ''; makeDefault.value = true;
  mappingTouched.value = false;
  for (const k of Object.keys(options)) delete options[k];
  for (const k of Object.keys(mapping)) delete mapping[k];
  readAct.clearError(); runAct.clearError();
}
watch(() => props.statement?.id, reset);

// ---- 1. the file
async function onFile(e) {
  const f = e.target.files?.[0];
  e.target.value = '';
  fileError.value = '';
  if (!f) return;
  if (/\.xls$/i.test(f.name)) { fileError.value = t('صيغة Excel القديمة (.xls) غير مدعومة — احفظ الملف بصيغة ‎.xlsx أو ‎.csv', 'The old Excel format (.xls) is not supported — save the file as .xlsx or .csv'); return; }
  if (!/\.(csv|xlsx|txt)$/i.test(f.name)) { fileError.value = t('اختر ملف ‎.csv أو ‎.xlsx كما نزّلته من موقع البنك', 'Choose a .csv or .xlsx file as downloaded from the bank'); return; }
  if (f.size > MAX_FILE_BYTES) { fileError.value = t('حجم الملف يتجاوز 5 ميجابايت — قسّم الكشف على ملفين', 'The file exceeds 5 MB — split the statement into two files'); return; }
  if (f.size === 0) { fileError.value = t('الملف فارغ', 'The file is empty'); return; }
  try {
    file.value = { name: f.name, size: f.size, dataUrl: await readAsDataUrl(f) };
  } catch {
    fileError.value = t('تعذّرت قراءة الملف من الجهاز', 'The file could not be read from the device');
  }
}
const sizeLabel = computed(() => (file.value ? `${(file.value.size / 1024).toFixed(file.value.size < 10240 ? 1 : 0)} KB` : ''));

// ---- 2. how it is read
/** What the person set: options always once the file was read; the mapping only when they changed it (else the profile / the titles decide). */
function readingBody() {
  const body = { fileName: file.value.name, base64: file.value.dataUrl };
  if (profileId.value) body.profile = profileId.value;
  if (preview.value) {
    const o = Object.fromEntries(Object.entries(options).filter(([, v]) => v !== null && v !== undefined && v !== ''));
    body.options = o;
  }
  if (mappingTouched.value) body.mapping = cleanMapping();
  return body;
}
function cleanMapping() {
  const out = {};
  for (const [k, v] of Object.entries(mapping)) {
    if (Array.isArray(v)) { if (v.length) out[k] = v.length === 1 ? v[0] : [...v]; } else if (v) out[k] = v;
  }
  return out;
}
let applying = false;
async function read() {
  if (!file.value) return;
  const r = await readAct.run(() => api.post('/banking/import/preview', { ...readingBody(), bankAccount: bankCode.value || undefined }), { success: false, invalidate: false });
  if (!r) return;
  applying = true;
  preview.value = r;
  const o = { ...r.options };
  delete o.dateOrderGuessed;
  for (const k of Object.keys(options)) delete options[k];
  Object.assign(options, o);
  for (const k of Object.keys(mapping)) delete mapping[k];
  for (const [k, v] of Object.entries(r.mapping || {})) mapping[k] = k === 'description' ? (Array.isArray(v) ? [...v] : v ? [v] : []) : Array.isArray(v) ? v[0] || '' : v || '';
  step.value = 'map';
  setTimeout(() => { applying = false; }, 0);
}
// a change of an option or a column reads the file again (debounced) so the check always matches what will be imported
let timer = null;
watch([() => ({ ...options }), () => JSON.stringify(mapping)], () => {
  if (applying || !preview.value || step.value !== 'map') return;
  clearTimeout(timer);
  timer = setTimeout(read, 450);
}, { deep: true });
onBeforeUnmount(() => clearTimeout(timer));
function onMapping(field, value) { mappingTouched.value = true; mapping[field] = value; }

const missingFields = computed(() => requiredFields(options.amountMode || 'signed').filter((f) => !mapping[f] || (Array.isArray(mapping[f]) && !mapping[f].length)));
const canRun = computed(() => !!preview.value?.mappingComplete && !missingFields.value.length && !readAct.pending.value);

// ---- 3. dry run / import
async function run(dryRun) {
  const body = { ...readingBody(), mapping: cleanMapping(), options: Object.fromEntries(Object.entries(options).filter(([, v]) => v !== null && v !== undefined && v !== '')), dryRun };
  if (!dryRun && saveAs.value.trim()) { body.saveAs = saveAs.value.trim(); body.makeDefault = makeDefault.value; }
  const r = await runAct.run(() => api.postIdempotent(`/banking/statements/${props.statement.id}/import`, body), {
    success: dryRun ? false : (x) => t(`استُورد ${x.taken} سطر — مكرر ${x.duplicates} · مرفوض ${x.refused}`, `${x.taken} line(s) imported — ${x.duplicates} duplicate · ${x.refused} refused`),
    invalidate: dryRun ? false : ['banking'],
  });
  if (!r) return;
  if (dryRun) toast.say({ ar: 'تجربة فقط — لم يُحفظ شيء', en: 'Dry run — nothing was saved' });
  result.value = r;
  step.value = 'report';
}
const steps = computed(() => [
  { k: 'file', label: { ar: 'الملف', en: 'File' } }, { k: 'map', label: { ar: 'القراءة والتحقق', en: 'Reading & check' } }, { k: 'report', label: { ar: 'التقرير', en: 'Report' } },
]);
const stepIndex = computed(() => steps.value.findIndex((s) => s.k === step.value));
</script>

<template>
  <Drawer :open="isOpen" :width="980" :z-index="74" :title="{ ar: 'استيراد ملف البنك', en: 'Import the bank’s file' }" @close="emit('close')">
    <template #sub>
      <span v-if="statement"><span class="num ltr">{{ statement.number }}</span> · <span dir="auto">{{ bankLabel(statement.bank) }}</span> · <span class="num ltr inline-block">{{ fmtDay(statement.dateFrom) }} — {{ fmtDay(statement.dateTo) }}</span></span>
    </template>
    <template v-if="statement">
      <Stepper :steps="steps" :current="stepIndex" class="mb-3" />

      <!-- 1. the file -->
      <template v-if="step === 'file'">
        <div class="hint !mt-0 mb-3">{{ t('نزّل كشف الحساب من موقع البنك بصيغة Excel ‏(.xlsx) أو CSV، حتى 5 ميجابايت. يُقرأ الملف أولًا وتراه قبل أي حفظ، والاستيراد آمن للتكرار: السطر الموجود مسبقًا يُعدّ مكررًا ولا يُضاف مرة ثانية.',
          'Download the account statement from the bank as Excel (.xlsx) or CSV, up to 5 MB. The file is read first and shown to you before anything is saved, and importing is safe to repeat: a line already held counts as a duplicate and is never added twice.') }}</div>
        <div class="form-grid">
          <Field full :label="{ ar: 'ملف الكشف', en: 'Statement file' }" :error="fileError || null">
            <input type="file" accept=".csv,.xlsx,.txt" class="inp" @change="onFile">
            <div v-if="file" class="mt-1 text-[10.5px] text-sec"><b dir="auto">{{ file.name }}</b> · <span class="num">{{ sizeLabel }}</span></div>
          </Field>
          <SelectInput v-model="profileId" full :label="{ ar: 'ربط أعمدة محفوظ (اختياري)', en: 'Saved column mapping (optional)' }" :options="profileOptions"
                       :placeholder="{ ar: '— الافتراضي لهذا الحساب، أو ما تقترحه عناوين الملف —', en: '— this account’s default, or what the file’s titles suggest —' }" />
        </div>
        <ImportProfiles class="mt-4" :profiles="profiles" :loading="profilesQ.isLoading.value" />
      </template>

      <!-- 2. reading and check -->
      <ImportMapping v-else-if="step === 'map' && preview" :preview="preview" :statement="statement" :options="options" :mapping="mapping" :missing="missingFields" :reading="readAct.pending.value" @mapping="onMapping" @option="(k, v) => (options[k] = v)" />

      <!-- 3. report -->
      <ImportReport v-else-if="step === 'report' && result" :result="result" />
    </template>

    <template v-if="statement" #footer>
      <ErrorBanner :error="readAct.error.value || runAct.error.value" @close="readAct.clearError(); runAct.clearError()" />
      <div v-if="step === 'map'" class="form-grid mb-2">
        <TextInput v-model="saveAs" small :label="{ ar: 'احفظ هذا الربط باسم (اختياري)', en: 'Save this mapping as (optional)' }" :placeholder="{ ar: 'مثال: كشف الراجحي CSV', en: 'e.g. Al Rajhi CSV export' }" />
        <label class="row !gap-1.5 self-end pb-2 text-[10.5px]"><input v-model="makeDefault" type="checkbox" :disabled="!saveAs.trim()"> {{ t('اجعله الافتراضي لهذا الحساب', 'Make it this account’s default') }}</label>
      </div>
      <div class="row wrap">
        <template v-if="step === 'file'">
          <Btn tone="dark" :disabled="!file" :loading="readAct.pending.value" :label="{ ar: 'قراءة الملف', en: 'Read the file' }" @click="read" />
        </template>
        <template v-else-if="step === 'map'">
          <Btn tone="soft" :label="{ ar: 'ملف آخر', en: 'Another file' }" @click="reset" />
          <Btn tone="softBlue" :disabled="!canRun || runAct.pending.value" :loading="runAct.pending.value" :label="{ ar: 'تجربة بلا حفظ', en: 'Dry run' }" @click="run(true)" />
          <Btn tone="primary" :disabled="!canRun || runAct.pending.value" :loading="runAct.pending.value" :label="{ ar: 'استيراد', en: 'Import' }" @click="run(false)" />
        </template>
        <template v-else>
          <Btn v-if="result?.dryRun" tone="soft" :label="{ ar: 'رجوع إلى الإعدادات', en: 'Back to the settings' }" @click="step = 'map'" />
          <Btn v-if="result?.dryRun" tone="primary" :loading="runAct.pending.value" :label="{ ar: 'استيراد الآن', en: 'Import now' }" @click="step = 'map'; run(false)" />
          <Btn v-else tone="soft" :label="{ ar: 'استيراد ملف آخر', en: 'Import another file' }" @click="reset" />
          <span class="grow" />
          <Btn v-if="!result?.dryRun" tone="dark" :label="{ ar: 'تم — عرض السطور', en: 'Done — show the lines' }" @click="emit('done')" />
        </template>
        <span class="grow" />
        <Btn tone="ghost" :label="{ ar: 'إغلاق', en: 'Close' }" @click="emit('close')" />
      </div>
      <div v-if="step === 'map' && missingFields.length" class="mt-1.5 text-[9.5px] font-bold text-bad">{{ t('حدّد أعمدة الحقول الإلزامية قبل الاستيراد.', 'Choose the columns of the required fields before importing.') }}</div>
    </template>
  </Drawer>
</template>
