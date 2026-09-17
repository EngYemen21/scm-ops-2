<script setup>
// Trip Control Room body: the tab strip + the active tab. Shared by the drawer (TripRoom) and the full page (TripPage).
//   <TripBody v-model:tab="tab" :trip="trip" />      tab: 'stops' | 'assign' | 'load' | 'time' | 'cost' | 'pods'
import { computed } from 'vue';
import { Tabs } from '@/components';
import { TRIP_TABS } from './tms';
import TripAssign from './TripAssign.vue';
import TripCost from './TripCost.vue';
import TripEvents from './TripEvents.vue';
import TripLoad from './TripLoad.vue';
import TripPods from './TripPods.vue';
import TripStops from './TripStops.vue';

const props = defineProps({
  trip: { type: Object, required: true },
  tab: { type: String, default: 'stops' },
});
const emit = defineEmits(['update:tab']);
const tabs = computed(() => TRIP_TABS(props.trip));
</script>

<template>
  <Tabs variant="sm" class="!mb-3" :tabs="tabs" :model-value="tab" @update:model-value="emit('update:tab', $event)" />
  <TripStops v-if="tab === 'stops'" :trip="trip" />
  <TripAssign v-else-if="tab === 'assign'" :trip="trip" />
  <TripLoad v-else-if="tab === 'load'" :trip="trip" />
  <TripEvents v-else-if="tab === 'time'" :trip="trip" />
  <TripCost v-else-if="tab === 'cost'" :trip="trip" />
  <TripPods v-else-if="tab === 'pods'" :trip="trip" />
</template>
