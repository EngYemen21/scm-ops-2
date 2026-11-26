<script setup>
// The mark of a record that came from Odoo. While the section follows Odoo such a record is read-only here
// (`owned`); once the section is handed over it is OPS's and the mark only tells where it came from.
//   <OdooMark :row="ticket" />
import { Chip } from '@/components';
import { t } from '@/i18n';
import { ODOO_CHIP } from './workspace';

defineProps({
  /** Anything that carries Origin: { sourceSystem, externalRef, owned }. */
  row: { type: Object, default: null },
});
</script>

<template>
  <Chip v-if="row && row.sourceSystem === 'odoo'" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg"
        :title="row.owned ? t('مصدره أودو ويُدار هناك — هنا للقراءة وإضافة الردود والتعليقات', 'Comes from Odoo and is managed there — here it is read, and replies and comments are added')
          : t('مصدره أودو — صار يُدار في نظام العمليات', 'Comes from Odoo — now managed in OPS')" />
</template>
