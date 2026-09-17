<script setup>
// Fleet · operational requests of drivers: status pills → GET /transport/ops-requests (polled every 30s); the flow buttons
// come from the server (`allowed` next states) → POST /transport/ops-requests/:number/status { to, note }.
// Attachments are references: "Integration Pending" (no file storage connected).
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, EmptyState, ErrorBanner, SectionCard } from '@/components';
import { bi, fmtDate, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import { OPREQ_ACTION_LABELS, OPREQ_STATE_LABELS, OPREQ_TYPE_LABELS, PENDING, labelOf } from './tms';

const emit = defineEmits(['new', 'open-trip', 'open-vehicle']);

const auth = useAuth();
const act = useAction();
const status = ref('submitted,review,approved');
const page = ref(1);
const list = useList('/transport/ops-requests', () => ({ status: status.value || undefined, page: page.value, pageSize: 20 }), { refetchInterval: 30_000 });
const items = computed(() => list.data.value?.items || []);

const STATUS_PILLS = [['submitted,review,approved', { ar: 'المفتوحة', en: 'Open' }], ...['submitted', 'review', 'approved', 'processed', 'rejected', 'closed'].map((s) => [s, OPREQ_STATE_LABELS[s]]), ['', { ar: 'الكل', en: 'All' }]];
const tone = (to) => (to === 'approved' ? 'softGreen' : to === 'rejected' ? 'softRed' : to === 'processed' ? 'softPurple' : to === 'review' ? 'softBlue' : 'soft');

async function setState(r, to) {
  let note;
  if (to === 'rejected') { const n = await ask({ title: { ar: `رفض الطلب ${r.number}؟`, en: `Reject ${r.number}?` }, label: { ar: 'سبب الرفض', en: 'Rejection reason' }, tone: 'danger', okLabel: { ar: 'رفض', en: 'Reject' } }); if (n == null) return; note = n || undefined; }
  else if (to === 'processed' && !(await confirm({ title: { ar: `معالجة الطلب ${r.number}؟`, en: `Process ${r.number}?` }, sub: { ar: 'صرف المبلغ / تنفيذ الطلب وتسجيله في Audit.', en: 'Disburse / execute the request and record it in the audit trail.' }, tone: 'dark' }))) return;
  await act.run(() => api.postIdempotent(`/transport/ops-requests/${r.number}/status`, { to, note }), { success: (x) => x?.message || t('تم تحديث الطلب', 'Request updated'), invalidate: ['transport'] });
}
</script>

<template>
  <div>
    <div class="row wrap mb-2.5">
      <div class="pill-bar !mb-0">
        <button v-for="[v, l] in STATUS_PILLS" :key="v" type="button" class="pill" :class="{ active: status === v }" @click="status = v; page = 1">{{ bi(l) }}</button>
      </div>
      <div class="grow" />
      <Btn v-if="auth.can('opreq.create')" size="sm" :label="{ ar: '+ طلب تشغيلي', en: '+ Ops request' }" @click="emit('new')" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <SectionCard :padded="false" :title="{ ar: 'طلبات السائقين التشغيلية', en: 'Driver operational requests' }" :count="list.data.value?.total">
      <div v-if="list.isLoading.value" class="skel m-3.5 h-20" />
      <EmptyState v-else-if="items.length === 0" :text="{ ar: 'لا طلبات تشغيلية مفتوحة ✓', en: 'No open operational requests ✓' }" />
      <div v-for="r in items" :key="r.number" class="border-t border-line-2 px-[15px] py-[11px]">
        <div class="row wrap">
          <span class="num text-[11px] font-bold text-violet">{{ r.number }}</span>
          <b class="text-[11px]">{{ bi(OPREQ_TYPE_LABELS[r.type] || { ar: r.typeLabel || r.type, en: r.type }) }}</b>
          <span class="text-[10px] text-muted">
            {{ r.driver?.nameAr || r.driverName || '—' }}
            <template v-if="r.vehicleCode"> · <span class="cell-id cursor-pointer" @click="emit('open-vehicle', r.vehicleCode)">{{ r.vehicleCode }}</span></template>
            <template v-if="r.tripNumber"> · <span class="cell-id cursor-pointer" @click="emit('open-trip', r.tripNumber)">{{ r.tripNumber }}</span></template>
          </span>
          <b v-if="Number(r.amount) > 0" class="num text-[11px] text-warn">{{ fmtNum(r.amount, 2) }} {{ t('ر.س', 'SAR') }}</b>
          <div class="grow" />
          <Chip :map="OPREQ_STATE_LABELS" :k="r.status" />
        </div>
        <div class="row wrap mt-1.5 !gap-2.5 text-[10px] text-sec">
          <span>{{ r.desc }}</span><span v-if="r.location" class="muted">· {{ r.location }}</span><span class="muted">· {{ t('مرفق', 'Attachment') }}: {{ r.attachment ? bi(PENDING) : t('لا يوجد', 'none') }}</span>
          <span class="num muted text-[9px]">· {{ fmtDate(r.createdAt) }}</span>
        </div>
        <div v-if="auth.can('opreq.manage') && (r.allowed || []).length > 0" class="row wrap mt-2 !gap-1.5">
          <Btn v-for="to in r.allowed" :key="to" size="sm" :tone="tone(to)" :loading="act.pending.value" :label="labelOf(OPREQ_ACTION_LABELS, to)" @click="setState(r, to)" />
        </div>
      </div>
      <div v-if="list.data.value && list.data.value.pages > 1" class="row justify-center !gap-1.5 px-[15px] py-2.5">
        <button v-for="i in list.data.value.pages" :key="i" type="button" class="pg-btn" :class="{ active: page === i }" @click="page = i">{{ i }}</button>
      </div>
    </SectionCard>
  </div>
</template>
