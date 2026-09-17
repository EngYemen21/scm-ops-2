<script setup>
// Related-document links of an exception (traceability): the entity, the source document (when different) and the
// audit trail filtered on the exception. A link without a known deep link renders as a disabled button.
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { Btn } from '@/components';
import { t } from '@/i18n';
import { entityPath } from '@/router/routes';
import { relatedPath } from './shared';

const props = defineProps({
  /** Exception row: { number, entityType, entityNumber, documentType, documentNumber } */
  exception: { type: Object, required: true },
});

const router = useRouter();
const related = computed(() => relatedPath(props.exception));
const docPath = computed(() => entityPath(props.exception.documentType, props.exception.documentNumber));
const go = (path) => { if (path) router.push(path); };
</script>

<template>
  <div class="row wrap">
    <Btn v-if="exception.entityNumber" :tone="related ? 'softPurple' : 'soft'" size="sm" :disabled="!related" @click="go(related)">
      <span>{{ exception.entityType || t('كيان', 'Entity') }} · <span class="num">{{ exception.entityNumber }}</span></span>
    </Btn>
    <Btn v-if="exception.documentNumber && exception.documentNumber !== exception.entityNumber" :tone="docPath ? 'softBlue' : 'soft'" size="sm" :disabled="!docPath" @click="go(docPath)">
      <span>{{ exception.documentType || t('مستند', 'Document') }} · <span class="num">{{ exception.documentNumber }}</span></span>
    </Btn>
    <Btn tone="soft" size="sm" :label="{ ar: 'Audit Trail', en: 'Audit trail' }" @click="go(`/activity?tab=audit&entity=${encodeURIComponent(exception.number)}`)" />
    <span v-if="!exception.entityNumber && !exception.documentNumber" class="muted text-[10.5px]">{{ t('لا مستند مرتبط — استثناء يدوي', 'No linked document — manual exception') }}</span>
  </div>
</template>
