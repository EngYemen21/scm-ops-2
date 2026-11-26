<script setup>
// One picture of a request, drawn inside whatever box the caller sizes (a thumbnail of a row, a tile of the drawer).
// An <img> cannot send the session's token, so the bytes are fetched through the API when the box comes on screen,
// shared with every other place that shows the same picture, and let go when it leaves. A picture of Odoo whose
// content the backup does not keep yet says so instead of showing a broken image.
//   <PreqPicture :picture="row.picture" class="h-10 w-10 rounded-lg" />
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Icon } from '@/components';
import { t } from '@/i18n';
import { dropPicture, holdPicture } from './preq';

const props = defineProps({
  /** A picture of the API ({ url, available, fileName }), or null: the placeholder is drawn. */
  picture: { type: Object, default: null },
  /** `cover` fills the box (thumbnails), `contain` shows the whole picture. */
  fit: { type: String, default: 'cover' },
});

const box = ref(null);
const src = ref(null);
const failed = ref(false);
/** The API path this component holds in the shared cache right now. */
let holding = null;
let observer = null;
let alive = true;

function release() {
  if (holding) { dropPicture(holding); holding = null; }
}
async function load() {
  const path = props.picture && props.picture.available ? props.picture.url : null;
  if (path === holding) return;
  release();
  src.value = null;
  failed.value = false;
  if (!path) return;
  holding = path;
  try {
    const url = await holdPicture(path);
    if (alive && holding === path) src.value = url;
  } catch {
    if (alive && holding === path) failed.value = true;
  }
}

onMounted(() => {
  if (typeof IntersectionObserver === 'undefined' || !box.value) { load(); return; }
  observer = new IntersectionObserver((entries) => {
    if (!entries.some((e) => e.isIntersecting)) return;
    observer.disconnect();
    observer = null;
    load();
  }, { rootMargin: '240px' });
  observer.observe(box.value);
});
// another picture in the same box (the next row of a list reuses the component): read it once the box was on screen
watch(() => [props.picture && props.picture.url, props.picture && props.picture.available], () => { if (!observer) load(); });
onBeforeUnmount(() => { alive = false; if (observer) observer.disconnect(); release(); });
</script>

<template>
  <div ref="box" class="relative flex flex-none items-center justify-center overflow-hidden bg-soft text-faint">
    <img v-if="src" :src="src" :alt="picture && picture.fileName ? picture.fileName : ''" class="h-full w-full" :class="fit === 'contain' ? 'object-contain' : 'object-cover'" draggable="false">
    <span v-else-if="picture && !picture.available" class="px-1 text-center text-[8px] font-bold leading-[1.5]" :title="t('صورة من أودو لم يُؤرشف محتواها هنا بعد', 'A picture of Odoo whose content is not archived here yet')">{{ t('لم تُؤرشف', 'Not archived') }}</span>
    <span v-else-if="failed" class="text-[10px] font-extrabold text-bad" :title="t('تعذّر تحميل الصورة', 'The picture could not be loaded')">!</span>
    <div v-else-if="picture" class="skel h-full w-full !rounded-none" />
    <Icon v-else name="box" :size="16" />
  </div>
</template>
