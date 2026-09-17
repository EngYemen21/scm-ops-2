<script setup>
// Suppliers linked to a product: list, unlink (with confirm) and — with `can-manage` — the link form
// (supplier, price, lead days, supplier SKU, preferred).
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, NumberInput, SelectInput, TextInput } from '@/components';
import { fmtMoney, t } from '@/i18n';
import { confirm, toast } from '@/stores/ui';
import { supOpts, useLookups } from './_shared';

const props = defineProps({
  /** @type {import('./_shared').Product} */
  product: { type: Object, required: true },
  canManage: { type: Boolean, default: false },
});

const lookups = useLookups();
const act = useAction({ invalidate: ['products'] });
const code = ref('');
const price = ref(null);
const lead = ref(null);
const pref = ref(false);
const ssku = ref('');
const list = computed(() => props.product.suppliers || []);
const suppliers = computed(() => supOpts(lookups.data.value));
const leadOf = (s) => s.leadDays ?? s.supplier.leadDays;

async function link() {
  if (!code.value) { toast.say({ ar: 'اختر المورد أولًا', en: 'Choose the supplier first' }); return; }
  const body = { supplierCode: code.value, price: price.value ?? undefined, leadDays: lead.value ?? undefined, preferred: pref.value, supplierSku: ssku.value || undefined };
  const r = await act.run(() => api.postIdempotent(`/products/${props.product.id}/suppliers`, body), { success: { ar: 'رُبط المورد بالمنتج', en: 'Supplier linked' } });
  if (r) { code.value = ''; price.value = null; lead.value = null; pref.value = false; ssku.value = ''; }
}
async function unlink(s) {
  if (!(await confirm({ title: { ar: `فك ارتباط ${s.supplier.nameAr}؟`, en: `Unlink ${s.supplier.nameEn || s.supplier.nameAr}?` } }))) return;
  await act.run(() => api.del(`/products/${props.product.id}/suppliers/${encodeURIComponent(s.supplier.code)}`), { success: { ar: 'فُك الارتباط', en: 'Unlinked' } });
}
</script>

<template>
  <div>
    <div v-if="list.length === 0" class="empty !p-3">{{ t('لا موردون مرتبطون', 'No linked suppliers') }}</div>
    <div v-for="s in list" :key="s.id" class="row wrap border-t border-[#F7F6FA] py-[7px] text-[10.5px]">
      <span class="font-extrabold">{{ s.supplier.nameAr }}</span>
      <span class="cell-id !text-[9.5px]">{{ s.supplier.code }}</span>
      <Chip v-if="s.preferred" small :label="{ ar: 'مفضل', en: 'Preferred' }" fg="#1d7a3e" bg="#e6f9ec" />
      <Chip v-if="s.supplier.isNew" small :label="{ ar: 'جديد', en: 'New' }" fg="#b26a16" bg="#fbf0dd" />
      <span class="grow" />
      <span v-if="s.supplier.score != null" class="num text-[9.5px]" :class="s.supplier.score < 65 ? 'text-bad' : 'text-muted'">{{ t('تقييم', 'Score') }} {{ s.supplier.score }}</span>
      <span v-if="s.price != null" class="num text-[10px]">{{ fmtMoney(s.price) }} {{ t('ر.س', 'SAR') }}</span>
      <span v-if="leadOf(s) != null" class="muted num text-[9.5px]">{{ leadOf(s) }} {{ t('يوم', 'd') }}</span>
      <span v-if="s.supplierSku" class="cell-sub !mt-0">{{ s.supplierSku }}</span>
      <span v-if="canManage" class="cursor-pointer text-[8.5px] font-extrabold text-bad" @click="unlink(s)">{{ t('فك', 'Unlink') }}</span>
    </div>
    <div v-if="canManage" class="form-grid mt-2 !grid-cols-[repeat(auto-fit,minmax(120px,1fr))]">
      <div class="col-span-2"><SelectInput v-model="code" small :label="{ ar: 'المورد', en: 'Supplier' }" :options="suppliers" :placeholder="{ ar: '— اختر —', en: '— select —' }" /></div>
      <NumberInput v-model="price" small :label="{ ar: 'السعر ر.س', en: 'Price SAR' }" :min="0" />
      <NumberInput v-model="lead" small :label="{ ar: 'مدة التوريد (يوم)', en: 'Lead days' }" :min="0" />
      <TextInput v-model="ssku" small :label="{ ar: 'رمز المورد للصنف', en: 'Supplier SKU' }" dir="ltr" />
      <div class="row !items-end">
        <label class="row h-[34px] !gap-[5px] text-[9.5px] font-extrabold text-muted"><input v-model="pref" type="checkbox">{{ t('مفضل', 'Preferred') }}</label>
        <Btn tone="softPurple" size="sm" class="!h-[34px]" :loading="act.pending.value" :label="{ ar: '+ ربط', en: '+ Link' }" @click="link" />
      </div>
    </div>
  </div>
</template>
