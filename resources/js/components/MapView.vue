<script setup>
// Real map (Mapbox GL, lazy-loaded). Draws only what it is given — markers and lines with stored coordinates.
//   <MapView :height="260" :markers="[{ id, lat, lng, kind: 'stop', text: '1', color, title, sub }]" :lines="[{ id, geometry, color, dashed }]" :fit-key="trip.number" @marker="open" />
//   pick mode:  <MapView pickable v-model:pin="pin" />   (tap / drag sets the pin)
// Without a configured provider it shows the honest "Integration Pending" placeholder; a load failure says so.
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { isMobile } from '@/composables/viewport';
import { hasPoint, loadMapbox, mapConfig } from '@/composables/mapbox';
import { bi, lang, t } from '@/i18n';

const props = defineProps({
  height: { type: [Number, String], default: 240 },
  /** [{ id, lat, lng, kind: 'warehouse' | 'stop' | 'fix' | 'vehicle', text?, color?, title?, sub?, data? }] */
  markers: { type: Array, default: () => [] },
  /** [{ id, geometry: GeoJSON LineString, color?, width?, dashed? }] */
  lines: { type: Array, default: () => [] },
  /** The view re-fits to the content whenever this value changes (and on first content). */
  fitKey: { type: [String, Number], default: '' },
  pickable: { type: Boolean, default: false },
  pin: { type: Object, default: null },
  pendingLabel: { type: [String, Object], default: null },
});
const emit = defineEmits(['marker', 'update:pin', 'ready']);

const SAUDI = { center: [45.2, 24.2], zoom: 4.3 };
const AR_LOCALE = {
  'ScrollZoomBlocker.CtrlMessage': 'استخدم Ctrl + التمرير لتكبير الخريطة', 'ScrollZoomBlocker.CmdMessage': 'استخدم ⌘ + التمرير لتكبير الخريطة',
  'TouchPanBlocker.Message': 'حرّك الخريطة بإصبعين', 'NavigationControl.ZoomIn': 'تكبير', 'NavigationControl.ZoomOut': 'تصغير', 'FullscreenControl.Enter': 'ملء الشاشة', 'FullscreenControl.Exit': 'إنهاء ملء الشاشة',
};
const GRID = {
  backgroundImage: 'linear-gradient(rgba(30,33,48,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(30,33,48,.06) 1px,transparent 1px)',
  backgroundSize: '36px 36px',
};

const el = ref(null);
const state = ref('loading'); // loading | ready | pending | error
const map = shallowRef(null);
let gl = null;
let drawn = [];
let pinMarker = null;
let popup = null;
let observer = null;
let fitted = null;
let dead = false;
let cleanup = null;

const cssHeight = computed(() => (typeof props.height === 'number' ? `${props.height}px` : props.height));
const points = computed(() => props.markers.filter(hasPoint));

function markerEl(m) {
  const d = document.createElement('div');
  d.className = `map-dot ${m.kind || 'stop'}`;
  if (m.color) d.style.setProperty('--dot', m.color);
  d.textContent = m.text ?? '';
  return d;
}

function showPopup(m) {
  popup?.remove();
  if (!m.title && !m.sub) return;
  const box = document.createElement('div');
  box.dir = lang.value === 'ar' ? 'rtl' : 'ltr';
  const head = document.createElement('div'); head.className = 'map-pop-title'; head.textContent = bi(m.title) || '';
  box.append(head);
  for (const line of [].concat(m.sub || [])) { const s = document.createElement('div'); s.className = 'map-pop-sub'; s.textContent = bi(line); box.append(s); }
  popup = new gl.Popup({ offset: 16, closeButton: false, maxWidth: '240px' }).setLngLat([m.lng, m.lat]).setDOMContent(box).addTo(map.value);
}

function drawMarkers() {
  if (!map.value) return;
  drawn.forEach((x) => x.remove());
  drawn = points.value.map((m) => {
    const node = markerEl(m);
    node.addEventListener('click', (e) => { e.stopPropagation(); showPopup(m); emit('marker', m); });
    return new gl.Marker({ element: node, anchor: 'center' }).setLngLat([m.lng, m.lat]).addTo(map.value);
  });
}

function drawLines() {
  const src = map.value?.getSource('lines');
  if (!src) return;
  src.setData({
    type: 'FeatureCollection',
    features: props.lines.filter((l) => l.geometry?.coordinates?.length > 1).map((l) => ({ type: 'Feature', geometry: l.geometry, properties: { color: l.color || '#6b4fd8', width: l.width || 4, dashed: !!l.dashed } })),
  });
}

function drawPin() {
  if (!map.value || !props.pickable) return;
  if (!hasPoint(props.pin)) { pinMarker?.remove(); pinMarker = null; return; }
  if (!pinMarker) {
    pinMarker = new gl.Marker({ color: '#6b4fd8', draggable: true }).setLngLat([props.pin.lng, props.pin.lat]).addTo(map.value);
    pinMarker.on('dragend', () => { const p = pinMarker.getLngLat(); emit('update:pin', { lat: +p.lat.toFixed(6), lng: +p.lng.toFixed(6) }); });
  } else pinMarker.setLngLat([props.pin.lng, props.pin.lat]);
}

function fit(force = false) {
  if (!map.value) return;
  const all = [...points.value, ...(hasPoint(props.pin) ? [props.pin] : [])];
  const key = `${props.fitKey}|${all.length > 0}`;
  if (!force && fitted === key) return;
  fitted = key;
  // a single marker with a trail behind it (vehicle drawer) frames the whole trail, not just the marker
  const lineCoords = props.lines.flatMap((l) => l.geometry?.coordinates || []);
  if (all.length === 0 && lineCoords.length < 2) { map.value.jumpTo(SAUDI); return; }
  if (all.length === 1 && lineCoords.length < 2) { map.value.jumpTo({ center: [all[0].lng, all[0].lat], zoom: props.pickable ? 14 : 11.5 }); return; }
  const b = new gl.LngLatBounds();
  all.forEach((p) => b.extend([p.lng, p.lat]));
  lineCoords.forEach((c) => b.extend(c));
  map.value.fitBounds(b, { padding: 42, maxZoom: 14, duration: 0 });
}

/** Moves the view to a point (used by the place search of the picker). */
function flyTo(p, zoom = 15) { map.value?.flyTo({ center: [p.lng, p.lat], zoom, duration: 600 }); }
defineExpose({ flyTo, refit: () => fit(true) });

onMounted(async () => {
  try {
    const cfg = await mapConfig();
    if (dead) return;
    if (!cfg.configured || !cfg.token) { state.value = 'pending'; return; }
    gl = await loadMapbox();
    if (dead) return;
    if (gl.supported && !gl.supported()) { state.value = 'error'; return; }
    gl.accessToken = cfg.token;
    const m = new gl.Map({
      container: el.value, style: 'mapbox://styles/mapbox/streets-v12', ...SAUDI, language: lang.value === 'ar' ? 'ar' : 'en', locale: lang.value === 'ar' ? AR_LOCALE : undefined,
      attributionControl: false, cooperativeGestures: isMobile.value && !props.pickable, dragRotate: false, pitchWithRotate: false, touchPitch: false,
    });
    m.touchZoomRotate.disableRotation();
    m.addControl(new gl.AttributionControl({ compact: true }));
    m.addControl(new gl.NavigationControl({ showCompass: false }), 'top-left');
    m.addControl(new gl.FullscreenControl(), 'top-left');
    m.on('error', (e) => { if (state.value !== 'ready') state.value = 'error'; console.warn('[map]', e?.error?.message || e); });
    m.on('load', () => {
      if (dead) return;
      m.addSource('lines', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
      const paint = { 'line-color': ['get', 'color'], 'line-width': ['get', 'width'], 'line-opacity': 0.85 };
      m.addLayer({ id: 'lines-casing', type: 'line', source: 'lines', filter: ['!', ['get', 'dashed']], layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#ffffff', 'line-width': ['+', ['get', 'width'], 3], 'line-opacity': 0.9 } });
      m.addLayer({ id: 'lines-solid', type: 'line', source: 'lines', filter: ['!', ['get', 'dashed']], layout: { 'line-cap': 'round', 'line-join': 'round' }, paint });
      m.addLayer({ id: 'lines-dashed', type: 'line', source: 'lines', filter: ['get', 'dashed'], paint: { ...paint, 'line-dasharray': [1.5, 1.5], 'line-width': 2.5 } });
      map.value = m;
      state.value = 'ready';
      drawLines(); drawMarkers(); drawPin(); fit(true);
      emit('ready');
    });
    if (props.pickable) m.on('click', (e) => emit('update:pin', { lat: +e.lngLat.lat.toFixed(6), lng: +e.lngLat.lng.toFixed(6) }));
    else m.on('click', () => popup?.remove());
    observer = new ResizeObserver(() => m.resize());
    observer.observe(el.value);
    cleanup = () => { observer?.disconnect(); popup?.remove(); m.remove(); };
  } catch (e) {
    console.warn('[map] failed to start', e);
    if (!dead) state.value = 'error';
  }
});
onBeforeUnmount(() => { dead = true; cleanup?.(); });

watch(points, () => { drawMarkers(); fit(); }, { deep: true });
watch(() => props.lines, () => { drawLines(); fit(); }, { deep: true });
watch(() => props.pin, drawPin, { deep: true });
watch(() => props.fitKey, () => fit());
</script>

<template>
  <div class="map-view" :style="{ height: cssHeight }">
    <div ref="el" dir="ltr" class="map-canvas" :class="{ 'opacity-0': state !== 'ready' }" />
    <div v-if="state === 'loading'" class="map-cover skel" />
    <div v-else-if="state !== 'ready'" dir="ltr" class="map-cover flex items-center justify-center bg-[#EEF1F6]" :style="GRID">
      <div dir="auto" class="rounded-xl border border-dashed border-[#A8E4EF] bg-white px-4 py-2.5 text-center">
        <template v-if="state === 'pending'">
          <div class="text-[11px] font-extrabold text-[#0d5866]">{{ pendingLabel ? bi(pendingLabel) : t('الخريطة', 'Map') }}</div>
          <div class="mt-[3px] text-[9.5px] text-muted">{{ t('Integration Pending — يتطلب ربط مزود الخرائط (MAPBOX_PUBLIC_TOKEN)', 'Integration Pending — a maps provider is not configured (MAPBOX_PUBLIC_TOKEN)') }}</div>
        </template>
        <template v-else>
          <div class="text-[11px] font-extrabold text-bad">{{ t('تعذّر تحميل الخريطة', 'The map could not be loaded') }}</div>
          <div class="mt-[3px] text-[9.5px] text-muted">{{ t('تحقق من الاتصال بالإنترنت أو من دعم المتصفح لـ WebGL', 'Check the connection, or that this browser supports WebGL') }}</div>
        </template>
      </div>
    </div>
    <slot />
  </div>
</template>
