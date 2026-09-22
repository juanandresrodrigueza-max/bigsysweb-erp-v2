<template>
  <component :is="url ? Link : 'div'" :href="url" class="card flex flex-col gap-1 hover:border-carmin/40 transition">
    <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ label }}</p>
    <p class="text-xl font-extrabold tracking-tight tabular-nums whitespace-nowrap" :class="alerta ? 'text-carmin' : ''">{{ valorFormateado }}</p>
    <p v-if="tendencia !== null && tendencia !== undefined" class="text-xs flex items-center gap-1" :class="positiva ? 'text-emerald-600' : 'text-carmin'">
      <Icono :nombre="tendencia >= 0 ? 'trendUp' : 'trendDown'" clase="w-3.5 h-3.5" />
      {{ Math.abs(tendencia) }}% vs período anterior
    </p>
  </component>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'

const props = defineProps({
  label: String, valor: [Number, String], formato: { type: String, default: 'moneda' },
  tendencia: { type: Number, default: null }, invertida: Boolean, url: String, alerta: Boolean,
})
const valorFormateado = computed(() => {
  if (props.formato === 'moneda') return '$ ' + Number(props.valor ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 0 })
  return Number(props.valor ?? 0).toLocaleString('es-AR')
})
const positiva = computed(() => props.invertida ? props.tendencia <= 0 : props.tendencia >= 0)
</script>
