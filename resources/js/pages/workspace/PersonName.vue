<script setup>
// A person of the section: an OPS user, or — marked «أودو» — a name kept from Odoo for somebody who has no OPS user.
//   <PersonName :person="ticket.assignee" :empty="{ ar: 'غير مسندة', en: 'Unassigned' }" />
import { Chip } from '@/components';
import { bi, t } from '@/i18n';
import { ODOO_CHIP, personName } from './workspace';

defineProps({
  /** { key, id, username, nameAr, nameEn, external } */
  person: { type: Object, default: null },
  empty: { type: [String, Object], default: null },
});
</script>

<template>
  <span v-if="person" class="inline-flex min-w-0 max-w-full items-center gap-1 align-middle">
    <span class="truncate" dir="auto">{{ personName(person) }}</span>
    <Chip v-if="person.external" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" :title="t('اسم محفوظ من أودو — لا يقابله مستخدم في نظام العمليات', 'A name kept from Odoo — no OPS user matches it')" />
  </span>
  <span v-else class="text-faint">{{ empty ? bi(empty) : '—' }}</span>
</template>
