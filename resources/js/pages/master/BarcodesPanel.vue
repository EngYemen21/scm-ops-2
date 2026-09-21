<script setup>
// Barcodes of a product: list, remove (with confirm) and — with `can-manage` — the add row (barcode, UoM, primary).
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { confirm, toast } from '@/stores/ui';
import { uomOpts, useLookups } from './_shared';

const props = defineProps({
  /** @type {import('./_shared').Product} */
  product: { type: Object, required: true },
  canManage: { type: Boolean, default: false },
});

const lookups = useLookups();
const act = useAction({ invalidate: ['products'] });
const bc = ref('');
const uom = ref('');
const primary = ref(false);
const list = computed(() => props.product.barcodes || []);
const uoms = computed(() => uomOpts(lookups.data.value));

async function add() {
  const code = bc.value.trim();
  if (!code) { toast.say({ ar: 'اكتب الباركود أو امسحه أولًا', en: 'Type or scan the barcode first' }); return; }
  const r = await act.run(() => api.postIdempotent(`/products/${props.product.id}/barcodes`, { barcode: code, uomCode: uom.value || undefined, isPrimary: primary.value }), { success: { ar: `أُضيف الباركود ${code}`, en: `Barcode ${code} added` } });
  if (r) { bc.value = ''; uom.value = ''; primary.value = false; }
}
async function remove(b) {
  if (!(await confirm({ title: { ar: `حذف الباركود ${b.barcode}؟`, en: `Remove barcode ${b.barcode}?` }, sub: { ar: 'لن يُقبل هذا الباركود في المسح بعد الآن.', en: 'Scans of this barcode will no longer resolve.' } }))) return;
  await act.run(() => api.del(`/products/${props.product.id}/barcodes/${encodeURIComponent(b.barcode)}`), { success: { ar: 'حُذف الباركود', en: 'Barcode removed' } });
}
</script>

<template>
  <div>
    <div v-if="list.length === 0" class="empty !p-3">{{ t('لا باركود مسجل', 'No barcodes') }}</div>
    <div v-for="b in list" :key="b.id" class="row border-t border-[#F7F6FA] py-[7px] text-[10.5px]">
      <span class="num ltr text-[11px]">{{ b.barcode }}</span>
      <Chip v-if="b.isPrimary" small :label="{ ar: 'أساسي', en: 'Primary' }" fg="#654e92" bg="#EFEAF8" />
      <span v-if="b.uom" class="muted text-[9.5px]">{{ b.uom.code }}</span>
      <span class="grow" />
      <span v-if="canManage" class="cursor-pointer text-[8.5px] font-extrabold text-bad" @click="remove(b)">{{ t('حذف', 'Remove') }}</span>
    </div>
    <div v-if="canManage" class="row wrap mt-2 !items-end">
      <div class="min-w-[150px] flex-[2]"><TextInput v-model="bc" scan small :label="{ ar: 'باركود جديد', en: 'New barcode' }" dir="ltr" mono placeholder="628…" @enter="add" /></div>
      <div class="min-w-[100px] flex-1"><SelectInput v-model="uom" small :label="{ ar: 'الوحدة', en: 'UoM' }" :options="uoms" :placeholder="{ ar: '—', en: '—' }" /></div>
      <label class="row h-[34px] !gap-[5px] text-[9.5px] font-extrabold text-muted"><input v-model="primary" type="checkbox">{{ t('أساسي', 'Primary') }}</label>
      <Btn tone="softPurple" size="sm" class="!h-[34px]" :loading="act.pending.value" :label="{ ar: '+ إضافة', en: '+ Add' }" @click="add" />
    </div>
  </div>
</template>
