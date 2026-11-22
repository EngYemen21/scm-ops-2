<script setup>
// Odoo tab → master data: Odoo's products, customers and suppliers as OPS products, customers and suppliers
// (app/Integration/Odoo/OdooMasterData.php). The view opens on what is cheap to know — what is linked so far and how
// many changes of Odoo wait — and works the plan out only when asked for:
//   GET  /integration/odoo/master-data            { continuous, ready, linked, waiting, applyLimit }
//   GET  /integration/odoo/master-data?plan=1     + { counts, notes, work }   (nothing is changed)
//   POST /integration/odoo/master-data/apply      integration.manage + product.manage; 422 ODOO_APPLY_TOO_LARGE above
//                                                 `applyLimit` records (a first load is a job for the server command)
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, KpiCard, KpiGrid } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import { MASTER_ACTION, MASTER_ENTITY, MASTER_WRITES, TONE, useOdooNav } from './odoo';

const auth = useAuth();
const canApply = auth.can('integration.manage') && auth.can('product.manage');
const nav = useOdooNav();
const router = useRouter();

const state = useGet('/integration/odoo/master-data', null, { refetchInterval: 30000 });
const s = computed(() => state.data.value || null);

/** The plan last asked for (GET ?plan=1) or the answer of the apply that followed it. */
const plan = ref(null);
const applied = ref(false);
const planning = useAction({ success: false, invalidate: false });
const applying = useAction({ invalidate: ['integration/odoo', 'products', 'customers', 'procurement'] });
async function preview() {
  const r = await planning.run(() => api.get('/integration/odoo/master-data', { plan: 1 }));
  if (r) { plan.value = r; applied.value = false; }
}
async function apply() {
  const n = plan.value?.work || 0;
  if (!(await confirm({
    title: { ar: `تطبيق ${fmtNum(n)} سجلًا على بيانات نظام العمليات؟`, en: `Apply ${fmtNum(n)} records to the OPS master data?` },
    sub: { ar: 'تُنشأ الأصناف والعملاء والموردون غير الموجودين، ويُربط الموجود بهويته، ويُحدَّث ما غيّره أودو. لا يُكتب شيء في أودو. كل تغيير يُسجَّل في سجل التدقيق باسم svc.odoo.', en: 'Products, customers and suppliers OPS lacks are created, existing ones are linked by identity, and what Odoo changed is refreshed. Nothing is written to Odoo. Every change is audited as svc.odoo.' },
    okLabel: { ar: 'تطبيق', en: 'Apply' }, tone: 'dark',
  }))) return;
  const r = await applying.run(() => api.postIdempotent('/integration/odoo/master-data/apply'), { success: { ar: 'طُبّقت بيانات أودو ✓', en: 'Odoo data applied ✓' } });
  if (r) { plan.value = r; applied.value = true; }
}

const ENTITIES = Object.keys(MASTER_ENTITY);
/** Where each kind of record lives in OPS (categories are edited inside the products page). */
const LINK_TO = { products: { path: '/products' }, customers: { path: '/sales', query: { tab: 'cust' } }, suppliers: { path: '/procurement', query: { tab: 'sup' } }, categories: { path: '/products' } };
/** The plan as rows: one per kind of record, its actions in the order of MASTER_ACTION, unknown ones after them. */
const rows = computed(() => ENTITIES.map((entity) => {
  const acts = plan.value?.counts?.[entity] || {};
  const keys = [...Object.keys(MASTER_ACTION).filter((k) => acts[k]), ...Object.keys(acts).filter((k) => acts[k] && !MASTER_ACTION[k])];
  return { entity, parts: keys.map((k) => ({ k, n: acts[k], label: MASTER_ACTION[k] ? bi(MASTER_ACTION[k]) : k, tone: MASTER_ACTION[k]?.tone || (MASTER_WRITES.includes(k) ? 'blue' : null) })) };
}));
/** The examples the server attached to a finding: [{ title, lines }]. */
const notes = computed(() => Object.entries(plan.value?.notes || {}).map(([key, lines]) => {
  const [entity, what] = key.split('.');
  return { key, title: `${bi(MASTER_ENTITY[entity] || entity)} · ${MASTER_ACTION[what] ? bi(MASTER_ACTION[what]) : what}`, lines: Array.isArray(lines) ? lines : [] };
}));
const work = computed(() => plan.value?.work ?? null);
const tooLarge = computed(() => work.value != null && s.value && work.value > s.value.applyLimit);
</script>

<template>
  <ErrorBanner :error="state.error.value || planning.error.value || applying.error.value" class="!mb-0" @close="planning.clearError(); applying.clearError()" />
  <div v-if="state.isLoading.value" class="skel h-[150px]" />

  <template v-else-if="s">
    <div v-if="!s.ready" class="hint amber !mt-0">
      {{ t('النسخة الأولى من الأصناف والفئات والشركاء لم تكتمل بعد. بعد اكتمالها يمكن معاينة ما سيدخل إلى بيانات النظام وتطبيقه.', 'The first copy of products, categories and partners is not complete yet. Once it is, what would enter the OPS master data can be previewed and applied.') }}
    </div>

    <KpiGrid class="!mb-0">
      <KpiCard v-for="e in ENTITIES" :key="e" clickable :value="s.linked[e]" :label="MASTER_ENTITY[e]" @click="router.push(LINK_TO[e])">
        <template #sub>{{ t('مرتبطة بسجلها في أودو', 'linked to their Odoo record') }}</template>
      </KpiCard>
      <KpiCard clickable :value="s.waiting" :color="s.waiting > 0 ? TONE.warn.fg : TONE.ok.fg" :label="{ ar: 'تغييرات في أودو لم تُطبَّق بعد', en: 'Odoo changes not applied yet' }" @click="nav.go('changes')">
        <template #sub>{{ s.continuous ? t('تُطبَّق في المزامنة القادمة', 'applied on the next run') : t('تنتظر تطبيقًا يدويًا', 'waiting for a manual apply') }}</template>
      </KpiCard>
    </KpiGrid>

    <div class="card">
      <div class="card-head flex-wrap">
        <div class="card-title">{{ t('من أودو إلى بيانات النظام', 'From Odoo to the OPS master data') }}</div>
        <Chip small :label="s.continuous ? { ar: 'التحديث المستمر يعمل', en: 'Continuous update is on' } : { ar: 'التحديث المستمر متوقف', en: 'Continuous update is off' }"
              :fg="s.continuous ? TONE.ok.fg : TONE.warn.fg" :bg="s.continuous ? TONE.ok.bg : TONE.warn.bg" dot />
        <Btn tone="soft" size="sm" :disabled="!s.ready" :loading="planning.pending.value" :label="{ ar: 'معاينة ما سيُطبَّق', en: 'Preview what would be applied' }" @click="preview" />
        <Btn v-if="canApply && plan && !applied && work > 0 && !tooLarge" tone="dark" size="sm" :loading="applying.pending.value" :label="{ ar: 'تطبيق', en: 'Apply' }" @click="apply" />
      </div>
      <div class="card-body">
        <p class="m-0 text-[11px] leading-[1.9] text-sec">
          {{ s.continuous
            ? t('كل تعديل في أودو على صنف أو عميل أو مورد يصل إلى سجله في نظام العمليات بعد المزامنة التالية.', 'Every edit in Odoo of a product, a customer or a supplier reaches its OPS record after the next run.')
            : t('النسخة تتحدّث دائمًا، لكن بيانات النظام لا تتغيّر إلا بتطبيق من هنا أو من الخادم. التشغيل المستمر يُفعَّل من إعدادات الخادم (ODOO_PROJECT_MASTER).', 'The copy is always updated, but the OPS master data changes only through an apply from here or from the server. Continuous update is switched on in the server settings (ODOO_PROJECT_MASTER).') }}
        </p>

        <template v-if="plan">
          <div class="mt-3 text-[11.5px] font-extrabold">
            {{ applied ? t('ما طُبِّق الآن', 'What was applied just now') : t('ما سيفعله التطبيق (لم يتغيّر شيء بعد)', 'What an apply would do (nothing has changed yet)') }}
            <span v-if="work != null" class="num ms-1.5 text-[10.5px] font-bold" :class="work > 0 ? 'text-azure' : 'text-ok'">{{ work > 0 ? t(`${fmtNum(work)} سجلًا يُكتب`, `${fmtNum(work)} records written`) : t('لا شيء يحتاج تطبيقًا ✓', 'nothing to apply ✓') }}</span>
          </div>
          <div class="mt-2 grid grid-cols-[repeat(auto-fit,minmax(250px,1fr))] gap-2">
            <div v-for="r in rows" :key="r.entity" class="tile !items-start !text-start">
              <div class="text-[11px] font-extrabold text-ink">{{ bi(MASTER_ENTITY[r.entity]) }}</div>
              <div v-if="!r.parts.length" class="mt-1 text-[10.5px] text-faint">{{ t('لا شيء', 'Nothing') }}</div>
              <div v-for="p in r.parts" :key="p.k" class="mt-1 flex items-baseline justify-between gap-3 text-[10.5px]">
                <span :style="p.tone ? { color: TONE[p.tone].fg } : null" :class="{ 'text-sec': !p.tone }">{{ p.label }}</span>
                <b class="num" :style="p.tone ? { color: TONE[p.tone].fg } : null">{{ fmtNum(p.n) }}</b>
              </div>
            </div>
          </div>
          <div v-if="tooLarge && !applied" class="hint amber">
            {{ t(`الدفعة أكبر من حدّ الشاشة (${fmtNum(s.applyLimit)} سجلًا). التحميل الأول يُنفَّذ مرة واحدة من الخادم بالأمر:`, `This batch is above the screen limit (${fmtNum(s.applyLimit)} records). A first load is run once on the server with:`) }}
            <span class="num ltr font-bold">php artisan scm:odoo-master --apply</span>
          </div>
          <div v-for="n in notes" :key="n.key" class="mt-3">
            <div class="text-[10.5px] font-extrabold text-ink">{{ n.title }}</div>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
              <span v-for="(line, i) in n.lines" :key="i" dir="auto" class="rounded-[8px] px-2 py-[3px] text-[10.5px] leading-[1.6]" :style="{ color: TONE.neutral.fg, background: TONE.neutral.bg }">{{ line }}</span>
            </div>
            <div v-if="n.lines.length >= 15" class="mt-1 text-[9.5px] text-faint">{{ t('أول 15 مثالًا فقط', 'the first 15 examples only') }}</div>
          </div>
        </template>
      </div>
    </div>

    <div class="hint !mt-0">
      {{ t('القواعد: الربط بالهوية فقط (رابط سابق، ثم رمز الصنف، ثم الرقم الضريبي للمورد) ولا ربط بتشابه الأسماء أبدًا. عميل أودو الذي يحمل اسم عميل موجود غير مربوط لا يُنشأ بل يُعرض للمراجعة. أودو يملك الاسم والرمز والفئة والنشاط، ونظام العمليات يملك فئة التخزين والوزن والأبعاد وحدود إعادة الطلب وشروط الائتمان، والتحديث لا يمسها. أودو بلا أوزان، فوزن الصنف الجديد وأبعاده وفئة تخزينه تقديرات من اسمه تُستبدل بالقياس الفعلي من شاشة الصنف.', 'The rules: linking is by identity only (an earlier link, then the product code, then a supplier’s VAT number) and never by similar names. An Odoo customer that carries the name of an existing unlinked customer is not created but listed for a review. Odoo owns the name, the code, the category and the active flag; OPS owns the storage class, weight and dimensions, reorder points and credit terms, and a refresh never touches them. Odoo holds no weights, so a new product’s weight, dimensions and storage class are estimates from its name, to be replaced by real measurements on the product screen.') }}
    </div>
  </template>
</template>
