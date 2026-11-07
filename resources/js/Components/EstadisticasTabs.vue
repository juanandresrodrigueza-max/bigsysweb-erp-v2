<template>
  <!-- Fase 33: las solapas del tablero. Cada número dice cómo se calcula (ⓘ) y contra qué se compara. -->
  <div class="flex gap-1 overflow-x-auto pb-1 mb-4 border-b border-marca-borde">
    <Link v-for="t in tabs" :key="t.key" :href="t.href" class="px-3 py-2 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px" :class="t.key === activa ? 'border-carmin text-carmin' : 'border-transparent text-marca-muted hover:text-marca-texto'" :data-e2e="`tab-${t.key}`">{{ t.label }}</Link>
  </div>
</template>
<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
defineProps({ activa: String })
const todas = [
  { key: 'resumen', label: 'Resumen', href: '/estadisticas' }, { key: 'ventas', label: 'Ventas', href: '/estadisticas/ventas' }, { key: 'rentabilidad', label: 'Rentabilidad', href: '/estadisticas/rentabilidad' }, { key: 'stock', label: 'Stock y compras', href: '/estadisticas/stock' }, { key: 'plata', label: 'Plata', href: '/estadisticas/plata' }, { key: 'que-hacer', label: 'Qué hacer', href: '/estadisticas/que-hacer' },
  { key: 'vendedores', label: 'Vendedores', href: '/estadisticas/vendedores' }, { key: 'analista', label: '✦ Analista IA', href: '/estadisticas/analista' },
]
// Fase 44.3: sólo las partes del tablero que el perfil tiene habilitadas.
const page = usePage()
const tabs = computed(() => { const p = page.props.auth?.partesEstadisticas; return p ? todas.filter(t => t.key === 'resumen' || p.includes(t.key.replace('-', '_'))) : todas })
</script>
