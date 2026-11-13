<template>
  <div v-if="links?.length > 3" class="flex flex-wrap items-center justify-between gap-2 mt-4">
    <p class="text-xs text-marca-muted">{{ desde }}–{{ hasta }} de {{ total }}</p>
    <div class="flex gap-1">
      <Link v-for="(l, i) in links" :key="i" :href="l.url || '#'" v-html="l.label" preserve-scroll
            class="px-3 py-1 rounded-full text-sm transition" :class="l.active ? 'bg-marca-grad text-white shadow-glow-sm' : l.url ? 'bg-white border border-marca-borde text-marca-muted hover:text-violeta hover:border-violeta/40' : 'text-marca-muted/40'" />
    </div>
  </div>
</template>
<script setup>
import { Link } from '@inertiajs/vue3'
defineProps({ links: Array, desde: Number, hasta: Number, total: Number })
</script>
