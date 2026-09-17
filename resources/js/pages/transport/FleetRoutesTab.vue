<script setup>
// Fleet · saved routes (templates for new trips): GET /transport/routes. Edit needs trip.manage; "Trips" jumps to /trips?q=<route name>.
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { TEMP_LABELS } from './tms';

const emit = defineEmits(['new', 'edit']);

const auth = useAuth();
const router = useRouter();
const page = ref(1);
const list = useList('/transport/routes', () => ({ page: page.value, pageSize: 50 }));

const TEMP_COLORS = { reefer: ['#3C79F5', '#e8effe'], chill: ['#0d7f93', '#d9f4f9'] };
const tempFg = (r) => (TEMP_COLORS[r.tempNeed] || ['#55506a'])[0];
const tempBg = (r) => (TEMP_COLORS[r.tempNeed] || [null, '#F1EFF6'])[1];
const onRow = (r) => { if (auth.can('trip.manage')) emit('edit', r); };

const columns = [
  { key: 'code', header: { ar: 'الكود', en: 'Code' }, width: '90px', kind: 'id' },
  { key: 'name', header: { ar: 'اسم المسار', en: 'Route' }, width: 'minmax(160px,1.2fr)', kind: 'name' },
  { key: 'warehouse', header: { ar: 'المستودع', en: 'Warehouse' }, width: '140px', kind: 'muted', value: (r) => (r.warehouse ? `${r.warehouse.code} · ${r.warehouse.nameAr}` : '—') },
  { key: 'zones', header: { ar: 'المناطق / الأحياء', en: 'Zones' }, width: 'minmax(180px,1.4fr)', value: (r) => r.zones || '—' },
  { key: 'days', header: { ar: 'أيام التشغيل', en: 'Days' }, width: '130px', kind: 'muted', value: (r) => r.days || '—' },
  { key: 'window', header: { ar: 'النافذة', en: 'Window' }, width: '110px', ltr: true, value: (r) => r.window || '—' },
  { key: 'tempNeed', header: { ar: 'الحرارة', en: 'Temp' }, width: '110px' },
  { key: 'act', header: '', width: '170px' },
];
</script>

<template>
  <div>
    <div class="row mb-2.5">
      <div class="text-[10.5px] text-muted">{{ t('المسارات المحفوظة تُستخدم كقوالب عند إنشاء الرحلات (اسم المسار، المناطق، النافذة، متطلب الحرارة).', 'Saved routes are templates for new trips (name, zones, window, temperature need).') }}</div>
      <div class="grow" />
      <Btn v-if="auth.can('trip.manage')" size="sm" :label="{ ar: '+ مسار', en: '+ Route' }" @click="emit('new')" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="1000" :row-key="(r) => r.code" :empty-text="{ ar: 'لا مسارات محفوظة', en: 'No saved routes' }" @page="page = $event" @row-click="onRow">
        <template #cell-tempNeed="{ row }"><Chip small :fg="tempFg(row)" :bg="tempBg(row)" :label="TEMP_LABELS[row.tempNeed] || row.tempNeed || '—'" /></template>
        <template #cell-act="{ row }">
          <div class="row !gap-1" @click.stop>
            <Btn v-if="auth.can('trip.manage')" size="sm" tone="ghost" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', row)" />
            <Btn size="sm" tone="soft" :label="{ ar: 'رحلات المسار', en: 'Trips' }" @click="router.push({ path: '/trips', query: { q: row.name } })" />
          </div>
        </template>
      </DataTable>
    </div>
  </div>
</template>
