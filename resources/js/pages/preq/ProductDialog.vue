<script setup>
// Two small decisions of a reviewer that name a product of the catalogue:
//   mode="reject"  rejecting a request, with the reason — and, when the reason is "it already exists", the product
//                  it duplicates, so the person who asked finds it from his request (POST …/reject { reason, existingProduct? });
//   mode="link"    saying which catalogue product an approved request corresponds to, when the copy of Odoo could not
//                  tell (POST …/link-product { product }).
//   <ProductDialog :request="detailOrNull" mode="reject" @close="…" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, TextArea } from '@/components';
import { t } from '@/i18n';
import ProductPick from './ProductPick.vue';
import { API, productName } from './preq';

const props = defineProps({
  /** The request as GET /product-requests/{id} answers it; null closes the dialog. */
  request: { type: Object, default: null },
  /** reject | link */
  mode: { type: String, default: 'reject' },
});
const emit = defineEmits(['close', 'done']);

const act = useAction({ invalidate: ['product-requests'] });
const reason = ref('');
const product = ref(null);
const errs = ref({});
watch(() => [props.request?.id, props.mode], () => { reason.value = ''; product.value = null; errs.value = {}; act.clearError(); });

const rejecting = computed(() => props.mode === 'reject');
const title = computed(() => (rejecting.value ? { ar: 'رفض الطلب', en: 'Reject the request' } : { ar: 'ربط الطلب بصنف من الكتالوج', en: 'Link the request to a catalogue product' }));

async function submit() {
  const e = {};
  if (rejecting.value && !reason.value.trim()) e.reason = t('سبب الرفض مطلوب', 'A reason is needed');
  if (!rejecting.value && !product.value) e.product = t('اختر الصنف', 'Pick the product');
  errs.value = e;
  if (Object.keys(e).length) return;
  const id = props.request.id;
  const done = rejecting.value
    ? await act.run(() => api.postIdempotent(`${API}/${id}/reject`, { reason: reason.value.trim(), existingProduct: product.value ? product.value.sku : undefined }), { success: t('رُفض الطلب وأُبلغ صاحبه', 'The request was rejected and its author was told') })
    : await act.run(() => api.postIdempotent(`${API}/${id}/link-product`, { product: product.value.sku }), { success: t(`رُبط الطلب بالصنف ${product.value.sku}`, `The request was linked to ${product.value.sku}`) });
  if (done) { emit('done', done); emit('close'); }
}
</script>

<template>
  <Modal :open="!!request" :width="520" :title="title" :sub="request ? `${request.number} — ${productName(request)}` : ''" @close="emit('close')">
    <template v-if="request">
      <template v-if="rejecting">
        <TextArea v-model="reason" :rows="3" autofocus required :label="{ ar: 'سبب الرفض', en: 'Reason' }" :placeholder="{ ar: 'يصل السبب إلى صاحب الطلب ويبقى في سجله', en: 'The reason reaches the requester and stays in the history' }" :error="errs.reason" />
        <ProductPick v-model="product" class="mt-3" :label="{ ar: 'الصنف الموجود (إن كان الرفض لأنه موجود في الكتالوج)', en: 'The existing product (when it is rejected because the catalogue has it)' }" :hint="{ ar: 'اختياري — يظهر لصاحب الطلب رابط الصنف', en: 'Optional — the requester gets the link to the product' }" />
      </template>
      <template v-else>
        <div class="hint purple !mt-0 mb-3">{{ t('اختر الصنف الذي أصبحه هذا الطلب في الكتالوج. يُسجَّل الربط باسمك ولا يغيّره تحديث أودو بعد ذلك.', 'Pick the catalogue product this request became. The link is recorded under your name, and a later update from Odoo does not change it.') }}</div>
        <ProductPick v-model="product" required :label="{ ar: 'الصنف في الكتالوج', en: 'Catalogue product' }" :error="errs.product" />
      </template>
    </template>
    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn :tone="rejecting ? 'danger' : 'dark'" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="rejecting ? { ar: 'رفض الطلب', en: 'Reject the request' } : { ar: 'ربط الصنف', en: 'Link the product' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :disabled="act.pending.value" :label="{ ar: 'تراجع', en: 'Back' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
