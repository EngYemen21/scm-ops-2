<script setup>
// "Process" a driver's operational request that becomes a fleet document → POST /transport/ops-requests/:number/status
// { to: 'processed', liters?, odometer?, shop? }.
//   fuel request        → a fuel record through the normal fuel path: the amount is the cost and is posted to the ledger;
//                         litres and the odometer reading are typed from the receipt when it has them — nothing is derived
//                         from a price. Without litres the fill is recorded as partial and stays out of the km/L average.
//   maint / tire / vehicle → an open maintenance order; the workshop is optional here and can be set later by editing the order.
//   <OpsRequestProcessModal :request="processing" @close="processing = null" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, NumberInput, TextInput } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { OPREQ_TYPE_LABELS } from './tms';

const props = defineProps({ request: { type: Object, default: null } });
const emit = defineEmits(['close']);

const act = useAction();
const liters = ref(null);
const odometer = ref(null);
const shop = ref('');
const isFuel = computed(() => props.request?.type === 'fuel');
// A fresh form for every request.
watch(() => props.request?.number, () => { liters.value = null; odometer.value = null; shop.value = ''; act.clearError(); });

const amount = computed(() => Number(props.request?.amount) || 0);
const pricePerL = computed(() => (Number(liters.value) > 0 ? amount.value / Number(liters.value) : null));
const priceError = computed(() => (pricePerL.value != null && pricePerL.value > 4 ? t('سعر اللتر يتجاوز 4 ر.س — راجع اللترات', 'Price per liter exceeds 4 SAR — check the litres') : null));

async function submit() {
  if (priceError.value) return;
  const body = { to: 'processed' };
  if (isFuel.value) {
    if (Number(liters.value) > 0) body.liters = Number(liters.value);
    if (odometer.value != null && odometer.value !== '') body.odometer = Number(odometer.value);
  } else if (shop.value.trim()) body.shop = shop.value.trim();
  const r = await act.run(() => api.postIdempotent(`/transport/ops-requests/${props.request.number}/status`, body), { success: (x) => x?.message || t('تمت معالجة الطلب', 'Request processed'), invalidate: ['transport', 'finance'] });
  if (r !== undefined) emit('close');
}
</script>

<template>
  <Modal :open="!!request" :width="480" @close="emit('close')">
    <template #title>
      {{ t('معالجة الطلب', 'Process request') }}
      <div v-if="request" class="drawer-sub"><span class="num">{{ request.number }}</span> · {{ bi(OPREQ_TYPE_LABELS[request.type] || { ar: request.type, en: request.type }) }} · {{ request.driver?.nameAr || request.driverName || '—' }}<template v-if="request.vehicleCode"> · <span class="num">{{ request.vehicleCode }}</span></template></div>
    </template>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <template v-if="request && isFuel">
      <div class="hint !mt-0">
        {{ t(`يُسجَّل المبلغ ${fmtNum(amount, 2)} ر.س تعبئة وقود على المركبة ويُقيَّد مصروف وقود${request.tripNumber ? ` ويُحمَّل على الرحلة ${request.tripNumber} إن كانت على هذه المركبة` : ''}. أدخل اللترات وقراءة العداد من الإيصال إن وُجدتا؛ بدون لترات تُسجَّل التعبئة «جزئية» ولا تدخل في متوسط الاستهلاك.`,
             `${fmtNum(amount, 2)} SAR is recorded as a fuel fill on the vehicle and posted as fuel expense${request.tripNumber ? `, charged to trip ${request.tripNumber} when it runs on this vehicle` : ''}. Type the litres and the odometer reading from the receipt when it has them; without litres the fill is recorded as partial and stays out of the consumption average.`) }}
      </div>
      <div class="form-grid mt-3">
        <NumberInput v-model="liters" :label="{ ar: 'اللترات (من الإيصال)', en: 'Litres (from the receipt)' }" :min="0.1" :error="priceError" :hint="pricePerL != null && !priceError ? { ar: `${fmtNum(pricePerL, 2)} ر.س / لتر`, en: `${fmtNum(pricePerL, 2)} SAR / L` } : { ar: 'اختياري', en: 'Optional' }" />
        <NumberInput v-model="odometer" :label="{ ar: 'قراءة العداد (من الإيصال)', en: 'Odometer (from the receipt)' }" :min="0" :hint="{ ar: 'اختياري — بدونها لا يتحرك عداد المركبة', en: 'Optional — without it the vehicle odometer does not move' }" />
      </div>
    </template>
    <template v-else-if="request">
      <div class="hint !mt-0">{{ t('يُنشأ أمر صيانة مفتوح للمركبة من هذا الطلب (لا يُخرج المركبة من الخدمة). حدّد الورشة الآن أو لاحقًا من «تعديل» الأمر.', 'An open maintenance order is created for the vehicle from this request (the vehicle stays in service). Name the workshop now or later by editing the order.') }}</div>
      <div class="form-grid mt-3">
        <TextInput v-model="shop" :label="{ ar: 'الورشة', en: 'Workshop' }" :hint="{ ar: 'اختياري', en: 'Optional' }" full @enter="submit" />
      </div>
    </template>
    <template #footer>
      <div class="row">
        <Btn tone="dark" class="!h-[42px] flex-1" :loading="act.pending.value" :disabled="!!priceError" :label="isFuel ? { ar: 'معالجة وتسجيل الوقود', en: 'Process & record fuel' } : { ar: 'معالجة وإنشاء أمر الصيانة', en: 'Process & open the order' }" @click="submit" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
