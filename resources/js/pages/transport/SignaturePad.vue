<script setup>
// Signature canvas → base64 PNG. Pointer events cover mouse, touch and pen (`touch-action: none` keeps the page from
// scrolling while signing). The PNG is sent with the POD; the server stores it as an attachment reference ("Integration Pending").
//   <SignaturePad v-model="signature" />
import { onBeforeUnmount, ref } from 'vue';
import { Btn } from '@/components';
import { t } from '@/i18n';

defineProps({ modelValue: { type: String, default: undefined } });
const emit = defineEmits(['update:modelValue']);

const canvas = ref(null);
let drawing = false;
let pointerId = null;

/** Pointer position in canvas pixels (the canvas is 600×200 but displayed at 100% × 120px). */
function pos(e) {
  const c = canvas.value; const r = c.getBoundingClientRect();
  return { x: ((e.clientX - r.left) / r.width) * c.width, y: ((e.clientY - r.top) / r.height) * c.height };
}
function down(e) {
  const c = canvas.value; if (!c) return;
  const ctx = c.getContext('2d'); const p = pos(e);
  ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.strokeStyle = '#1E2130';
  ctx.beginPath(); ctx.moveTo(p.x, p.y);
  drawing = true; pointerId = e.pointerId;
  try { c.setPointerCapture(e.pointerId); } catch { /* pointer already gone */ }
}
function move(e) {
  if (!drawing || !canvas.value) return;
  const ctx = canvas.value.getContext('2d'); const p = pos(e);
  ctx.lineTo(p.x, p.y); ctx.stroke();
}
function release() {
  const c = canvas.value;
  if (c && pointerId != null) { try { if (c.hasPointerCapture(pointerId)) c.releasePointerCapture(pointerId); } catch { /* ignore */ } }
  pointerId = null;
}
function up() {
  if (!drawing) return;
  drawing = false; release();
  if (canvas.value) emit('update:modelValue', canvas.value.toDataURL('image/png'));
}
function clear() {
  const c = canvas.value; if (!c) return;
  c.getContext('2d').clearRect(0, 0, c.width, c.height);
  emit('update:modelValue', undefined);
}
// Leaving the panel mid-stroke: stop drawing and give the pointer back.
onBeforeUnmount(() => { drawing = false; release(); });
</script>

<template>
  <div class="field mt-2">
    <label class="field-l">{{ t('توقيع المستلم', 'Receiver signature') }} <span v-if="modelValue" class="text-ok">✓</span></label>
    <canvas ref="canvas" width="600" height="200" class="block h-[120px] w-full touch-none rounded-xl border-[1.5px] border-dashed border-[#DCD2EE] bg-soft"
            @pointerdown="down" @pointermove="move" @pointerup="up" @pointerleave="up" @pointercancel="up" />
    <div class="row mt-1"><span class="flex-1 text-[9px] text-faint">{{ t('وقّع بالإصبع أو الماوس', 'Sign with finger or mouse') }}</span><Btn size="sm" tone="ghost" :label="{ ar: 'مسح', en: 'Clear' }" @click="clear" /></div>
  </div>
</template>
