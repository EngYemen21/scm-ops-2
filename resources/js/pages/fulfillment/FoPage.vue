<script setup>
// Fulfillment order — deep link /fo/:number: header + stepper, lines (qty / picked / packed / delivered / returned),
// pick lists with tasks (scan & confirm when pickable), packages, trip / stop, PODs, returns, inventory movements, history.
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KV, PageHead, ProgressBar, SectionCard, Stepper, Timeline } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { FO_LABELS, MOVEMENT_LABELS, RETURN_LABELS, SO_LABELS, TRIP_LABELS } from '@/shared';
import { showLabel } from '@/stores/ui';
import PackRow from './PackRow.vue';
import PickTaskCard from './PickTaskCard.vue';
import { FO_STEPS, custName, foStepIndex, historyItems, pickProgress, prodName } from './shared';

const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));
const det = useGet(() => (number.value ? `/fulfillment/orders/${encodeURIComponent(number.value)}` : null));
const fo = computed(() => det.data.value);

const step = computed(() => (fo.value ? foStepIndex(fo.value.status) : { idx: 0, failed: false }));
const steps = FO_STEPS.map((s) => ({ label: { ar: FO_LABELS[s].ar, en: FO_LABELS[s].en } }));
const prog = computed(() => pickProgress(fo.value));
const taskCount = computed(() => (fo.value?.pickLists || []).reduce((s, p) => s + p.tasks.length, 0));
const history = computed(() => historyItems(fo.value?.history));
const sub = computed(() => (fo.value
  ? `${custName(fo.value.customer)} · ${fo.value.warehouse.code} ${fo.value.warehouse.nameAr} · ${t('أُنشئ', 'Created')} ${fmtDate(fo.value.createdAt)}`
  : t('أمر تنفيذ — الأسطر ومهام التجهيز والطرود والرحلة والتتبع', 'Fulfillment order — lines, pick tasks, packages, trip, traceability')));
const enc = encodeURIComponent;
/** Colour of a quantity cell: `on` colour when the value is non-zero, faint otherwise. */
const qtyColor = (v, on) => (v ? on : '#a8a4b8');
const pickedColor = (l) => (l.pickedQty >= l.qty ? '#1d7a3e' : l.pickedQty ? '#b26a16' : '#a8a4b8');

const lineCols = [
  { key: 'lineNo', header: '#', width: '36px', kind: 'num' },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(180px,1.6fr)' },
  { key: 'qty', header: { ar: 'المطلوب', en: 'Qty' }, width: '80px', kind: 'num', value: (l) => fmtNum(l.qty) },
  { key: 'pickedQty', header: { ar: 'مصروف', en: 'Picked' }, width: '80px', kind: 'num' },
  { key: 'packedQty', header: { ar: 'معبأ', en: 'Packed' }, width: '80px', kind: 'num' },
  { key: 'deliveredQty', header: { ar: 'مسلّم', en: 'Delivered' }, width: '80px', kind: 'num' },
  { key: 'returnedQty', header: { ar: 'مرتجع', en: 'Returned' }, width: '80px', kind: 'num' },
  { key: 'kg', header: { ar: 'كجم', en: 'kg' }, width: '70px', kind: 'num' },
];
const pkgCols = [
  { key: 'number', header: { ar: 'الطرد', en: 'Package' }, width: '120px', kind: 'id' },
  { key: 'cartons', header: { ar: 'كراتين', en: 'Cartons' }, width: '70px', kind: 'num' },
  { key: 'weightKg', header: { ar: 'الوزن كجم', en: 'Weight kg' }, width: '90px', kind: 'num', value: (p) => fmtNum(p.weightKg, 1) },
  { key: 'volumeM3', header: { ar: 'الحجم م³', en: 'Volume m³' }, width: '90px', kind: 'num', value: (p) => (p.volumeM3 != null ? fmtNum(p.volumeM3, 2) : '—') },
  { key: 'labelRef', header: { ar: 'الملصق', en: 'Label' }, width: 'minmax(120px,1fr)' },
  { key: 'packedBy', header: { ar: 'عبّأه', en: 'Packed by' }, width: '110px', kind: 'muted' },
  { key: 'packedAt', header: { ar: 'التاريخ', en: 'At' }, width: '120px', kind: 'date', value: (p) => fmtDate(p.packedAt) },
];
const mvCols = [
  { key: 'number', header: { ar: 'الحركة', en: 'Movement' }, width: '120px', kind: 'id' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '130px' },
  { key: 'sku', header: 'SKU', width: '110px' },
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Batch' }, width: '100px' },
  { key: 'from', header: { ar: 'من', en: 'From' }, width: '90px' },
  { key: 'to', header: { ar: 'إلى', en: 'To' }, width: '90px' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num', value: (m) => fmtNum(m.qty) },
  { key: 'createdAt', header: { ar: 'التاريخ', en: 'At' }, width: '120px', kind: 'date', value: (m) => fmtDate(m.createdAt) },
];
const TRACE_ROW = 'row wrap !gap-2.5 rounded-[11px] border border-line-2 px-[13px] py-2';
const UNIT = 'font-sans text-[9px] text-faint';
</script>

<template>
  <PageHead :title="number" :sub="sub">
    <Btn tone="outline" :label="{ ar: '← التجهيز والتعبئة', en: '← Pick & Pack' }" @click="router.push('/picking')" />
    <Btn v-if="fo?.so" tone="softPurple" :label="{ ar: `أمر البيع ${fo.so.number}`, en: `Sales order ${fo.so.number}` }" @click="router.push(`/so/${enc(fo.so.number)}`)" />
    <Btn v-if="fo?.trip" tone="softBlue" :label="{ ar: `الرحلة ${fo.trip.number}`, en: `Trip ${fo.trip.number}` }" @click="router.push(`/trip/${enc(fo.trip.number)}`)" />
    <Btn v-if="fo && ['alloc', 'picking', 'picked'].includes(fo.status)" tone="primary" :label="{ ar: 'فتح في التجهيز', en: 'Open in picking' }" @click="router.push({ path: '/picking', query: { fo: fo.number } })" />
    <Btn v-if="fo && fo.status === 'packed' && fo.trip" tone="dark" :label="{ ar: 'فتح في التحميل', en: 'Open in loading' }" @click="router.push({ path: '/dispatch', query: { trip: fo.trip.number } })" />
    <Btn v-if="fo" tone="soft" :label="{ ar: 'ملصق الشحن', en: 'Shipping label' }" @click="showLabel({ type: 'code128', text: fo.number, title: fo.number, sub: fo.customer ? (lang === 'ar' ? fo.customer.nameAr : fo.customer.nameEn || fo.customer.nameAr) : null })" />
  </PageHead>
  <ErrorBanner :error="det.error.value" :closable="false" />
  <div v-if="det.isLoading.value && !fo" class="skel min-h-[240px]" />

  <template v-if="fo">
    <SectionCard class="mb-3.5">
      <div class="row wrap !gap-3 pt-3.5">
        <div>
          <div class="num-mixed text-[15px] font-extrabold">{{ fo.number }}</div>
          <div class="row mt-1 !gap-1.5">
            <Chip :map="FO_LABELS" :k="fo.status" />
            <Chip v-if="fo.so?.priority === 'high'" :label="{ ar: 'أولوية عالية', en: 'High priority' }" fg="#b23b3b" bg="#fdecec" small />
            <Chip v-if="fo.loaded" :label="{ ar: 'محمَّل ✓', en: 'Loaded ✓' }" fg="#0d7f93" bg="#d9f4f9" small />
          </div>
        </div>
        <span class="grow" />
        <div class="tile"><div class="tile-v">{{ fmtNum(fo.lines.length) }}</div><div class="tile-l">{{ t('أسطر', 'Lines') }}</div></div>
        <div class="tile teal"><div class="tile-v">{{ prog.done }} / {{ prog.need }}</div><div class="tile-l">{{ t('مصروف / مطلوب', 'Picked / required') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fo.cartons || '—' }}</div><div class="tile-l">{{ t('كراتين', 'Cartons') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtNum(fo.weightKg, 1) }} <span :class="UNIT">{{ t('كجم', 'kg') }}</span></div><div class="tile-l">{{ t('الوزن', 'Weight') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtNum(fo.cbm, 1) }} <span :class="UNIT">{{ t('م³', 'm³') }}</span></div><div class="tile-l">{{ t('الحجم', 'Volume') }}</div></div>
      </div>
      <div class="mt-4"><Stepper :steps="steps" :current="step.idx" :failed="step.failed" /></div>
      <div v-if="prog.need > 0" class="mt-3 max-w-[420px]"><ProgressBar :pct="Math.round((prog.done / prog.need) * 100)" :label="{ ar: 'تقدم التجهيز', en: 'Pick progress' }" show-pct auto /></div>
      <div class="kv-grid mt-4">
        <KV :k="{ ar: 'العميل', en: 'Customer' }"><span>{{ custName(fo.customer) }} <span class="cell-sub">{{ fo.customer.code }}</span></span></KV>
        <KV :k="{ ar: 'المنطقة · العنوان', en: 'Zone · address' }" :v="[fo.customer.zone, fo.customer.address].filter(Boolean).join(' · ') || '—'" />
        <KV :k="{ ar: 'جهة الاتصال', en: 'Contact' }" :v="fo.customer.contact || '—'" />
        <KV :k="{ ar: 'أمر البيع', en: 'Sales order' }">
          <span v-if="fo.so" class="row !gap-1.5"><RouterLink :to="`/so/${enc(fo.so.number)}`" class="cell-id">{{ fo.so.number }}</RouterLink><Chip :map="SO_LABELS" :k="fo.so.status" small /></span>
          <template v-else>—</template>
        </KV>
        <KV :k="{ ar: 'التسليم', en: 'Delivery' }" ltr><span class="num">{{ fmtDateOnly(fo.so?.dueDate) }}{{ fo.so?.window ? ` · ${fo.so.window}` : '' }}</span></KV>
        <KV :k="{ ar: 'الرحلة', en: 'Trip' }">
          <span v-if="fo.trip" class="row wrap !gap-1.5">
            <RouterLink :to="`/trip/${enc(fo.trip.number)}`" class="cell-id !text-brand-dark">{{ fo.trip.number }}</RouterLink>
            <Chip :map="TRIP_LABELS" :k="fo.trip.status" small />
            <span v-if="fo.trip.vehicle" class="num text-[9.5px]">{{ fo.trip.vehicle.code }}{{ fo.trip.vehicle.plateAr ? ` · ${fo.trip.vehicle.plateAr}` : '' }}</span>
            <span v-if="fo.trip.driver" class="text-[9.5px]">{{ fo.trip.driver.nameAr }}</span>
          </span>
          <template v-else>{{ t('لا رحلة بعد', 'No trip yet') }}</template>
        </KV>
        <KV :k="{ ar: 'عُبّئ', en: 'Packed' }" ltr><span class="num">{{ fmtDate(fo.packedAt) }}</span></KV>
        <KV :k="{ ar: 'حُمّل', en: 'Loaded' }" ltr><span class="num">{{ fmtDate(fo.loadedAt) }}</span></KV>
        <KV :k="{ ar: 'أُرسل', en: 'Dispatched' }" ltr><span class="num">{{ fmtDate(fo.dispatchedAt) }}</span></KV>
        <KV :k="{ ar: 'سُلّم', en: 'Delivered' }" ltr><span class="num">{{ fmtDate(fo.deliveredAt) }}</span></KV>
      </div>
    </SectionCard>

    <SectionCard :title="{ ar: 'الأسطر', en: 'Lines' }" :count="fo.lines.length" :padded="false" class="mb-3.5">
      <DataTable :columns="lineCols" :rows="fo.lines" :row-key="(l) => l.id" :min-width="760">
        <template #cell-product="{ row }">
          <div>
            <RouterLink :to="`/product/${enc(row.product.sku)}`" class="cell-name !text-ink no-underline">{{ prodName(row.product) }}</RouterLink>
            <div class="num text-[8.5px] text-faint">{{ row.product.sku }}{{ row.product.storageClass ? ` · ${row.product.storageClass}` : '' }}</div>
          </div>
        </template>
        <template #cell-pickedQty="{ row }"><span class="font-extrabold" :style="{ color: pickedColor(row) }">{{ fmtNum(row.pickedQty) }}</span></template>
        <template #cell-packedQty="{ row }"><span :style="{ color: qtyColor(row.packedQty, '#654e92') }">{{ fmtNum(row.packedQty) }}</span></template>
        <template #cell-deliveredQty="{ row }"><span :style="{ color: qtyColor(row.deliveredQty, '#1d7a3e') }">{{ fmtNum(row.deliveredQty || 0) }}</span></template>
        <template #cell-returnedQty="{ row }"><span :style="{ color: qtyColor(row.returnedQty, '#b23b3b') }">{{ fmtNum(row.returnedQty || 0) }}</span></template>
        <template #cell-kg="{ row }"><span class="muted">{{ fmtNum((row.product.weightKg || 0) * row.qty, 1) }}</span></template>
      </DataTable>
    </SectionCard>

    <SectionCard :title="{ ar: 'قوائم التجهيز ومهام الصرف', en: 'Pick lists & tasks' }" :count="taskCount" class="mb-3.5">
      <div v-if="fo.pickLists.length === 0" class="empty !p-3">{{ t('لا قوائم تجهيز بعد', 'No pick lists yet') }}</div>
      <div v-for="pl in fo.pickLists" :key="pl.id" class="mb-2.5">
        <div class="row wrap mb-2">
          <span class="num-mixed text-[11.5px] font-extrabold">{{ pl.number }}</span>
          <Chip :label="pl.status === 'done' ? { ar: 'مكتملة ✓', en: 'Done ✓' } : { ar: 'مفتوحة', en: 'Open' }" :fg="pl.status === 'done' ? '#1d7a3e' : '#b26a16'" :bg="pl.status === 'done' ? '#e6f9ec' : '#fbf0dd'" small />
          <span v-if="pl.assignedTo" class="muted text-[9.5px]">{{ pl.assignedTo }}</span>
          <span class="cell-date">{{ fmtDate(pl.createdAt) }}{{ pl.completedAt ? ` → ${fmtDate(pl.completedAt)}` : '' }}</span>
        </div>
        <div class="col"><PickTaskCard v-for="x in pl.tasks" :key="x.id" :task="x" :fo-status="fo.status" @changed="det.refetch()" /></div>
      </div>
    </SectionCard>

    <SectionCard :title="{ ar: 'التعبئة والطرود', en: 'Packing & packages' }" :count="fo.packages.length" :padded="false" class="mb-3.5">
      <div v-if="fo.status === 'picked'" class="px-[18px] py-3"><PackRow :fo="fo" standalone @changed="det.refetch()" /></div>
      <DataTable :columns="pkgCols" :rows="fo.packages" :row-key="(p) => p.id" :min-width="700" :empty-text="{ ar: 'لا طرود بعد — تُنشأ عند إتمام التعبئة', en: 'No packages yet — created on packing' }">
        <template #cell-labelRef="{ row }"><span class="num text-[9.5px] text-violet">{{ row.labelRef || '—' }}</span></template>
      </DataTable>
    </SectionCard>

    <SectionCard :title="{ ar: 'التتبع: أمر بيع ← أمر تنفيذ ← رحلة ← إثبات تسليم ← مرتجع', en: 'Traceability: SO → FO → trip → POD → return' }" class="mb-3.5">
      <div class="col !gap-1.5">
        <div v-if="fo.so" :class="TRACE_ROW">
          <RouterLink :to="`/so/${enc(fo.so.number)}`" class="cell-id min-w-[110px]">{{ fo.so.number }}</RouterLink>
          <span class="text-[10px] font-extrabold">{{ t('أمر بيع', 'Sales order') }}</span>
          <Chip :map="SO_LABELS" :k="fo.so.status" small />
        </div>
        <div v-if="fo.trip" :class="TRACE_ROW">
          <RouterLink :to="`/trip/${enc(fo.trip.number)}`" class="cell-id min-w-[110px] !text-brand-dark">{{ fo.trip.number }}</RouterLink>
          <span class="text-[10px] font-extrabold">{{ t('رحلة', 'Trip') }}</span>
          <Chip :map="TRIP_LABELS" :k="fo.trip.status" small />
          <span v-if="fo.trip.vehicle" class="num text-[9.5px] text-sec">{{ fo.trip.vehicle.code }}</span>
          <span v-if="fo.trip.driver" class="text-[9.5px] text-sec">{{ fo.trip.driver.nameAr }}</span>
        </div>
        <div v-else class="empty !p-2">{{ t('لا رحلة بعد — تُضاف من الرحلات بعد التعبئة', 'No trip yet — added from Trips after packing') }}</div>
        <div v-for="p in fo.pods || []" :key="p.number" :class="TRACE_ROW" class="bg-[#FAFDFB]">
          <span class="cell-id min-w-[110px] !text-ok">{{ p.number }}</span>
          <span class="text-[10px] font-extrabold">{{ t('إثبات تسليم', 'POD') }} · {{ p.result }}</span>
          <span v-if="p.receiverName" class="muted text-[10px]">{{ p.receiverName }}</span>
          <span class="cell-date">{{ fmtDate(p.at) }}</span>
        </div>
        <div v-for="r in fo.returns || []" :key="r.number" :class="TRACE_ROW">
          <RouterLink :to="`/rtn/${enc(r.number)}`" class="cell-id min-w-[110px] !text-bad">{{ r.number }}</RouterLink>
          <span class="text-[10px] font-extrabold">{{ t('مرتجع', 'Return') }}</span>
          <Chip :map="RETURN_LABELS" :k="r.status" small />
        </div>
      </div>
    </SectionCard>

    <SectionCard :title="{ ar: 'حركات المخزون', en: 'Inventory movements' }" :count="fo.movements?.length" :padded="false" class="mb-3.5">
      <DataTable :columns="mvCols" :rows="fo.movements || []" :row-key="(m) => m.number" :min-width="820" :empty-text="{ ar: 'لا حركات بعد', en: 'No movements yet' }">
        <template #cell-type="{ row }"><Chip :map="MOVEMENT_LABELS" :k="row.type" small /></template>
        <template #cell-sku="{ row }"><span class="num text-[10px]">{{ row.product.sku }}</span></template>
        <template #cell-batchNo="{ row }"><span class="num text-[9.5px] text-brand-dark">{{ row.batchNo || '—' }}</span></template>
        <template #cell-from="{ row }"><span class="num text-[10px] text-violet">{{ row.srcBin?.code || '—' }}</span></template>
        <template #cell-to="{ row }"><span class="num text-[10px] text-violet">{{ row.dstBin?.code || '—' }}</span></template>
      </DataTable>
    </SectionCard>

    <SectionCard :title="{ ar: 'سجل الحالة', en: 'Status history' }" class="mb-3.5">
      <Timeline :items="history" :empty-text="{ ar: 'لا سجل', en: 'No history' }" />
    </SectionCard>
  </template>
</template>
