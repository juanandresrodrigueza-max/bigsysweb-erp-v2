<template>
  <AppLayout titulo="Estadísticas · Gastronomía">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Salón y cocina</h1><p class="page-subtitle">Cuánto vendió cada mozo, a qué hora se llena, qué platos salen más y cuánto tarda la cocina. <span class="cursor-help" :title="como">ⓘ</span></p></div>
      <form class="flex items-center gap-2 text-sm" @submit.prevent="router.get('/estadisticas/gastronomia', f, { preserveState: true })"><input v-model="f.desde" type="date" class="input !py-1 !w-auto" /><span>a</span><input v-model="f.hasta" type="date" class="input !py-1 !w-auto" /><button class="btn-secondary !py-1 text-xs">Ver</button></form>
    </div>
    <EstadisticasTabs activa="gastronomia" />

    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-4" data-e2e="kpis-gastro">
      <div v-for="k in tarjetas" :key="k[0]" class="card !py-3"><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">{{ k[0] }}</p><p class="text-lg font-extrabold tabular-nums">{{ k[1] }}</p></div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
      <div class="card">
        <h2 class="font-bold mb-2">Por mozo</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-xs text-marca-muted"><th class="py-1">Mozo</th><th class="text-right">Mesas</th><th class="text-right">Cubiertos</th><th class="text-right">Vendió</th><th class="text-right">Ticket prom.</th><th class="text-right">Propinas</th></tr></thead>
          <tbody><tr v-for="m in por_mozo" :key="m.mozo" class="border-t border-marca-borde"><td class="py-1 font-semibold">{{ m.mozo }}</td><td class="text-right tabular-nums">{{ m.comandas }}</td><td class="text-right tabular-nums">{{ m.cubiertos }}</td><td class="text-right tabular-nums">{{ moneda(m.total, 0) }}</td><td class="text-right tabular-nums">{{ moneda(m.ticket, 0) }}</td><td class="text-right tabular-nums">{{ moneda(m.propinas, 0) }}</td></tr></tbody></table>
          <p v-if="!por_mozo.length" class="text-sm text-marca-muted py-4 text-center">Sin mesas cerradas en el período.</p></div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-2">A qué hora se vende</h2>
        <div v-for="h in por_hora" :key="h.hora" class="flex items-center gap-2 text-sm py-0.5"><span class="w-10 tabular-nums text-marca-muted">{{ h.hora }} h</span><div class="flex-1 h-4 rounded bg-marca-fondo overflow-hidden"><div class="h-4 rounded bg-carmin/80" :style="{ width: (h.total / maxHora * 100) + '%' }"></div></div><span class="w-24 text-right tabular-nums">{{ moneda(h.total, 0) }}</span><span class="w-10 text-right text-xs text-marca-muted">{{ h.comandas }}</span></div>
        <p v-if="!por_hora.length" class="text-sm text-marca-muted py-4 text-center">Sin datos.</p>
        <div class="flex flex-wrap gap-2 mt-3"><span v-for="t in por_tipo" :key="t.tipo" class="badge bg-marca-fondo text-marca-texto">{{ t.tipo }} · {{ t.comandas }} · {{ moneda(t.total, 0) }}</span></div>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-2">Platos más pedidos</h2>
        <div class="overflow-x-auto max-h-96"><table class="w-full text-sm"><thead><tr class="text-left text-xs text-marca-muted"><th class="py-1">Plato</th><th class="text-right">Unidades</th><th class="text-right">Facturado</th></tr></thead>
          <tbody><tr v-for="p in platos" :key="p.plato" class="border-t border-marca-borde"><td class="py-1">{{ p.plato }}</td><td class="text-right tabular-nums font-semibold">{{ cantidad(p.unidades) }}</td><td class="text-right tabular-nums">{{ moneda(p.total, 0) }}</td></tr></tbody></table></div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-2">Tiempos de cocina</h2>
        <div v-for="c in cocina" :key="c.estacion" class="flex justify-between text-sm py-1 border-t border-marca-borde/60"><span class="font-semibold">{{ c.estacion }}</span><span class="tabular-nums">prom. <b>{{ c.promedio }}'</b> · máx {{ c.maximo }}' · {{ c.platos }} platos</span></div>
        <p v-if="!cocina.length" class="text-sm text-marca-muted py-4 text-center">Todavía no hay platos marcados como listos.</p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadisticasTabs from '@/Components/EstadisticasTabs.vue'
import { moneda, cantidad } from '@/util/formato'
const props = defineProps({ periodo: Object, kpis: Object, por_mozo: Array, por_hora: Array, por_tipo: Array, platos: Array, cocina: Array, como: String })
const f = reactive({ ...props.periodo })
const maxHora = computed(() => Math.max(1, ...props.por_hora.map(h => h.total)))
const tarjetas = computed(() => [['Vendido', moneda(props.kpis.total, 0)], ['Mesas / pedidos', props.kpis.comandas], ['Ticket promedio', moneda(props.kpis.ticket, 0)], ['Cubiertos', props.kpis.cubiertos], ['Por cubierto', moneda(props.kpis.por_cubierto, 0)], ['Propinas', moneda(props.kpis.propinas, 0)], ['Permanencia', props.kpis.permanencia + ' min']])
</script>
