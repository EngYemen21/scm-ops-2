<script setup>
// Records that a reviewed return was filed with the authority — POST /tax/returns/{id}/file { reference, filedOn }
// (vatreturn.file; somebody other than its reviewer). OPS does not file anything itself: the person types the
// reference the authority's portal gave.
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, TextInput } from '@/components';
import { t } from '@/i18n';
import TaxFormDrawer from './TaxFormDrawer.vue';
import { money, todayIso } from './tax';

const props = defineProps({
  open: { type: Boolean, default: false },
  ret: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['tax'] });
const f = reactive({ reference: '', filedOn: '' });
watch(() => props.open, (open) => { if (open) { Object.assign(f, { reference: '', filedOn: todayIso() }); act.clearError(); } }, { immediate: true });
const ok = computed(() => f.reference.trim() !== '' && !!f.filedOn && (!props.ret?.periodTo || f.filedOn > props.ret.periodTo) && f.filedOn <= todayIso());

async function submit() {
  const r = await act.run(() => api.postIdempotent(`/tax/returns/${encodeURIComponent(props.ret.id)}/file`, { reference: f.reference.trim(), filedOn: f.filedOn }),
    { success: t('سُجّل تقديم الإقرار', 'Filing recorded') });
  if (r) emit('close');
}
</script>

<template>
  <TaxFormDrawer :open="open && !!ret" :title="{ ar: 'تسجيل تقديم الإقرار', en: 'Record the filing' }" :sub="ret?.periodCode" :pending="act.pending.value" :error="act.error.value" :disabled="!ok"
                 :submit-label="{ ar: 'تسجيل التقديم', en: 'Record filing' }" submit-tone="primary"
                 :missing="{ ar: 'اكتب مرجع الهيئة، وتاريخ تقديم بعد نهاية الفترة ولا يتجاوز اليوم', en: 'Type the authority\'s reference and a filing date after the period end and not in the future' }"
                 @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="hint amber !mt-0 mb-3">{{ t('يُقدَّم الإقرار في بوابة الهيئة؛ هنا يُسجَّل مرجع التقديم فقط — لا يُرسل النظام شيئًا.', 'The return is filed on the authority\'s portal; only the filing reference is recorded here — the system sends nothing.') }}</div>
    <div v-if="ret" class="mb-3 text-[10.5px]">{{ t('صافي المستحق', 'Net due') }}: <b class="num">{{ money(ret.netDue) }}</b></div>
    <div class="form-grid">
      <TextInput v-model="f.reference" :label="{ ar: 'مرجع التقديم لدى الهيئة', en: 'Authority filing reference' }" required dir="ltr" mono />
      <DateInput v-model="f.filedOn" :label="{ ar: 'تاريخ التقديم', en: 'Filed on' }" required :max="todayIso()" />
    </div>
  </TaxFormDrawer>
</template>
