<script setup>
// List row inside a card: flex, gap 10, padding 10×18, top separator; hover + pointer when `clickable`.
// On a phone (app.css .list-row) it wraps: the text takes the full width and chips / buttons drop to their own line.
//   <ListRow clickable @click="router.push(a.path)"> … </ListRow>
defineProps({ clickable: { type: Boolean, default: false } });
</script>

<template>
  <div class="list-row flex items-center gap-2.5 border-t border-line-2 px-[18px] py-2.5" :class="{ 'cursor-pointer hover:bg-soft': clickable }"><slot /></div>
</template>
