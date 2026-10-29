<template>
  <!-- Fase 33: las solapas del tablero. Cada número dice cómo se calcula (ⓘ) y contra qué se compara. -->
  <div class="flex gap-1 overflow-x-auto pb-1 mb-4 border-b border-marca-borde">
    <Link v-for="t in tabs" :key="t.key" :href="t.href" class="px-3 py-2 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px" :class="t.key === activa ? 'border-carmin text-carmin' : 'border-transparent text-marca-muted hover:text-marca-texto'" :data-e2e="`tab-${t.key}`">{{ t.label }}</Link>
  </div>
</template>
<script setup>
import { Link } from '@inertiajs/vue3'
defineProps({ activa: String })
const tabs = [
  { key: 'resumen', label: 'Resumen', href: '/estadisticas' }, { key: 'ventas', label: 'Ventas', href: '/estadisticas/ventas' }, { key: 'rentabilidad', label: 'Rentabilidad', href: '/estadisticas/rentabilidad' },
  { key: 'vendedores', label: 'Vendedores', href: '/estadisticas/vendedores' }, { key: 'analista', label: '✦ Analista IA', href: '/estadisticas/analista' },
]
</script>
