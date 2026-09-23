<template>
  <div class="flex flex-wrap items-center gap-2">
    <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1">
      <button v-for="p in presets" :key="p.key" type="button" @click="aplicar(p)" class="px-3 py-1 rounded-full text-xs font-semibold" :class="activo === p.key ? 'bg-carmin text-white' : 'text-marca-muted hover:text-marca-texto'">{{ p.label }}</button>
    </div>
    <input :value="desde" type="date" class="input w-auto !py-1 text-xs" @change="ir($event.target.value, hasta)" />
    <span class="text-xs text-marca-muted">a</span>
    <input :value="hasta" type="date" class="input w-auto !py-1 text-xs" @change="ir(desde, $event.target.value)" />
  </div>
</template>
<script setup>
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
const props = defineProps({ desde: String, hasta: String, extra: { type: Object, default: () => ({}) } })
const page = usePage()
const iso = d => d.toISOString().slice(0, 10)
const hoy = new Date()
const presets = [
  { key: 'mes', label: 'Este mes', desde: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), hasta: iso(new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0)) },
  { key: 'anterior', label: 'Mes anterior', desde: iso(new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1)), hasta: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 0)) },
  { key: 'trimestre', label: 'Trimestre', desde: iso(new Date(hoy.getFullYear(), Math.floor(hoy.getMonth() / 3) * 3, 1)), hasta: iso(new Date(hoy.getFullYear(), Math.floor(hoy.getMonth() / 3) * 3 + 3, 0)) },
  { key: 'anio', label: 'Año', desde: iso(new Date(hoy.getFullYear(), 0, 1)), hasta: iso(new Date(hoy.getFullYear(), 11, 31)) },
]
const activo = computed(() => presets.find(p => p.desde === props.desde && p.hasta === props.hasta)?.key)
function ir(d, h) { router.get(page.url.split('?')[0], { ...props.extra, desde: d, hasta: h }, { preserveState: true, replace: true }) }
function aplicar(p) { ir(p.desde, p.hasta) }
</script>
