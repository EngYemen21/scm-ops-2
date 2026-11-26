<script setup>
// A customer or a product of a row. The OPS record shows its name and its code; one that the source system names and
// OPS does not have yet shows the name the source gave, its id there, and says so.
//   <PartyName :party="row.customer" />            <PartyName :party="line.product" product />
import { computed } from 'vue';
import { t } from '@/i18n';
import { nameOf } from './portal';

const props = defineProps({
  /** { id, code | sku, nameAr, nameEn, linked, ref } or null */
  party: { type: Object, default: null },
  product: { type: Boolean, default: false },
});

const code = computed(() => (props.party ? (props.product ? props.party.sku : props.party.code) : null));
const pending = computed(() => !!props.party && !props.party.linked);
</script>

<template>
  <span v-if="!party" class="text-faint">—</span>
  <div v-else class="min-w-0">
    <div class="cell-name truncate" dir="auto" :title="nameOf(party)">{{ nameOf(party) || '—' }}</div>
    <div v-if="code" class="cell-sub">{{ code }}</div>
    <div v-else-if="pending" class="mt-0.5 text-[9px] font-bold text-warn" :title="product ? t('صنف في أودو لم يُربط بصنف في نظام العمليات بعد', 'An Odoo product that is not linked to an OPS product yet') : t('عميل في أودو لم يصبح عميلًا في نظام العمليات بعد', 'An Odoo customer that is not an OPS customer yet')">
      {{ t('غير مربوط', 'Not linked') }}<span v-if="party.ref" class="num ltr ms-1 font-normal text-faint">Odoo #{{ party.ref }}</span>
    </div>
  </div>
</template>
