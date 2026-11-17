<script setup>
// Ship a transfer (ready → transit): who carries it — a vehicle with its driver, or a named carrier — plus the waybill.
// POST /api/inventory/transfers/:id/ship { carrier?, waybill?, vehicleCode?, driverCode?, note? }. With the policy
// transfers.requireCarrier the server refuses (CARRIER_REQUIRED) a shipment nobody carries; the button says why first.
// A vehicle is always named with its driver: the pair becomes a transfer trip that is on the road at once (the server
// checks both like a delivery trip; an open transfer run of the same pair takes this transfer as another stop).
// Mount it with v-if so every opening starts clean:  <TransferShipModal v-if="shipOpen" :transfer="tr" @close="…" @done="…" />
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, ErrorBanner, Modal, SelectInput, TextArea, TextInput } from '@/components';
import { fmtNum, lang, t } from '@/i18n';

const props = defineProps({ transfer: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const act = useAction({ invalidate: ['inventory', 'dashboard', 'transport'] });
const vehicleCode = ref('');
const driverCode = ref('');
const carrier = ref('');
const waybill = ref('');
const note = ref('');

const vehicles = useList('/transport/vehicles', { pageSize: 100 });
const drivers = useList('/transport/drivers', { pageSize: 100 });
const vehicleOpts = computed(() => (vehicles.data.value?.items || []).filter((v) => v.active !== false).map((v) => [v.code, `${v.code} · ${v.plateAr || v.plateEn || ''}`]));
const driverOpts = computed(() => (drivers.data.value?.items || []).filter((d) => d.active !== false && !d.blocked).map((d) => [d.code, `${(lang.value === 'en' && d.nameEn) || d.nameAr} · ${d.code}`]));

const required = computed(() => !!props.transfer.policy?.requireCarrier);
const qty = computed(() => props.transfer.totalQty ?? props.transfer.lines.reduce((n, l) => n + l.qty, 0));
/** Why the shipment cannot be sent yet (null = it can). */
const issue = computed(() => {
  if (!!vehicleCode.value !== !!driverCode.value) return t('اختر المركبة والسائق معًا — المركبة بلا سائق مسؤول لا تُقبل', 'Pick the vehicle and the driver together');
  if (!required.value || carrier.value.trim() || (vehicleCode.value && driverCode.value)) return null;
  return t('لا يُشحن التحويل بلا مركبة وسائق أو ناقل مسمّى (سياسة النظام)', 'A transfer cannot ship without a vehicle and driver, or a named carrier (system policy)');
});

async function submit() {
  const body = { vehicleCode: vehicleCode.value || undefined, driverCode: driverCode.value || undefined, carrier: carrier.value.trim() || undefined, waybill: waybill.value.trim() || undefined, note: note.value.trim() || undefined };
  const r = await act.run(() => api.postIdempotent(`/inventory/transfers/${props.transfer.id}/ship`, body), { success: { ar: 'شُحن التحويل — خرجت الكميات المحجوزة من المصدر', en: 'Transfer shipped — the held quantities left the source' } });
  if (r !== undefined) { emit('close'); emit('done', r); }
}
</script>

<template>
  <Modal open :width="520" :title="{ ar: 'شحن التحويل — صرف من المصدر', en: 'Ship the transfer' }" @close="emit('close')">
    <template #title>
      {{ t('شحن التحويل — صرف من المصدر', 'Ship the transfer') }}
      <div class="drawer-sub"><span class="num">{{ transfer.number }}</span> · <span class="num ltr">{{ transfer.fromWarehouse.code }} → {{ transfer.toWarehouse.code }}</span> · <span class="num">{{ fmtNum(qty) }}</span> {{ t('وحدة', 'units') }}</div>
    </template>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <ErrorBanner :error="vehicles.error.value || drivers.error.value" :closable="false" />
    <div class="form-grid">
      <SelectInput v-model="vehicleCode" :label="{ ar: 'المركبة', en: 'Vehicle' }" :options="vehicleOpts" :placeholder="{ ar: '— بلا مركبة —', en: '— no vehicle —' }" />
      <SelectInput v-model="driverCode" :label="{ ar: 'السائق', en: 'Driver' }" :options="driverOpts" :placeholder="{ ar: '— بلا سائق —', en: '— no driver —' }" />
      <TextInput v-model="carrier" :label="{ ar: 'الناقل (شركة نقل خارجية)', en: 'Carrier (third party)' }" :placeholder="{ ar: 'اسم شركة النقل', en: 'Carrier name' }" />
      <TextInput v-model="waybill" scan dir="ltr" mono :label="{ ar: 'رقم البوليصة / بيان الشحن', en: 'Waybill no.' }" />
      <TextArea v-model="note" :rows="2" :label="{ ar: 'ملاحظة (تُسجل في Audit)', en: 'Note (audited)' }" full />
    </div>
    <div class="hint mt-2.5" :class="required ? 'amber' : 'teal'">
      {{ required
        ? t('سياسة النظام: لا يخرج التحويل إلا مع مركبة وسائق، أو ناقل مسمّى.', 'System policy: a transfer leaves only with a vehicle and its driver, or a named carrier.')
        : t('بيانات الناقل اختيارية — تُحفظ على التحويل وتظهر لمن يستلمه.', 'Carrier details are optional — they are kept on the transfer and shown to whoever receives it.') }}
      {{ t('تُصرف الكميات المحجوزة من مواقع المصدر كحركات transit وتُحفظ تكلفة خروجها.', 'The held quantities leave the source bins as transit movements, at a cost kept for the receipt.') }}
    </div>
    <div v-if="vehicleCode && driverCode" class="hint teal mt-2">
      {{ t('ستُنشأ رحلة تحويل تنطلق الآن بهذه المركبة وهذا السائق، وتظهر للمرحّل في الرحلات. استلام مستودع الوجهة يقفل محطتها، ثم يقفل المرحّل الرحلة فتُحسب تكلفتها وتتاح المركبة.',
        'A transfer trip starts now with this vehicle and driver and shows up in dispatch. The destination receipt closes its stop; dispatch then closes the trip, which costs it and frees the vehicle.') }}
    </div>
    <div v-if="issue" class="field-err mt-2">{{ issue }}</div>

    <template #footer>
      <div class="row">
        <Btn tone="primary" class="!h-[42px] flex-1" :loading="act.pending.value" :disabled="!!issue" :title="issue || undefined" :label="{ ar: 'تأكيد الشحن', en: 'Confirm shipment' }" @click="submit" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'رجوع', en: 'Back' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
