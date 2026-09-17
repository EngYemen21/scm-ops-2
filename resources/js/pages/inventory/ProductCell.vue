<script setup>
// Product cell: name (current language) + sku underneath in Quicksand. Default slot overrides the sub line.
//   <ProductCell :p="row.product" />      <ProductCell :p="row">{{ category }} · {{ storage }}</ProductCell>
import { pname } from './shared';

defineProps({
  /** { sku, nameAr, nameEn } */
  p: { type: Object, default: null },
});
</script>

<template>
  <div class="min-w-0">
    <div class="ellipsis text-[11.5px] font-extrabold">{{ pname(p) || p?.sku || '—' }}</div>
    <div class="cell-sub"><slot>{{ p?.sku }}</slot></div>
  </div>
</template>
