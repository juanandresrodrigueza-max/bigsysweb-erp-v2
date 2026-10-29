<template>
  <AppLayout titulo="Estadísticas · Ventas">
    <!-- Fase 33.2: la matriz ABC, cuándo vende el negocio, puntos de venta contra el año anterior, lo que se frenó de golpe y los vendedores. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
      <div><h1 class="page-title">Ventas</h1><p class="page-subtitle">Qué pesa, cuándo se vende, por dónde y quién lo vende. Pasá el mouse por ⓘ para ver cómo se calcula cada cosa.</p></div>
      <div class="flex flex-wrap items-center gap-2">
        <select :value="sucursalId ?? ''" @change="$inertia.get('/estadisticas/ventas', { ...periodo, sucursal: $event.target.value || undefined }, { preserveState: true, replace: true })" class="input w-auto !py-1 text-xs"><option value="">Todas las sucursales</option><option v-for="s in listaSucursales" :key="s.id" :value="s.id">{{ s.name }}</option></select>
        <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" :extra="{ sucursal: sucursalId }" />
      </div>
    </div>
    <EstadisticasTabs activa="ventas" />

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <!-- Matriz ABC -->
      <div class="card lg:col-span-2" data-e2e="abc">
        <div class="flex items-center justify-between mb-2"><h2 class="font-bold">Matriz ABC del período</h2><span class="text-marca-muted cursor-help" :title="abc.como">ⓘ</span></div>
        <div class="grid grid-cols-3 gap-2 mb-3 text-sm">
          <div v-for="k in ['A','B','C']" :key="k" class="rounded-xl p-2.5" :class="{ 'bg-emerald-50': k === 'A', 'bg-amber-50': k === 'B', 'bg-gris-light': k === 'C' }"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ k }} · {{ { A: 'no pueden faltar', B: 'importantes', C: 'el resto' }[k] }}</p><p class="font-extrabold">{{ abc.cantidad[k] }} artículos <span class="font-normal text-marca-muted">· {{ abc.total ? Math.round(abc.monto[k] / abc.total * 100) : 0 }}% de lo facturado</span></p></div>
        </div>
        <div class="flex gap-1.5 mb-2 text-xs"><button v-for="k in ['todos','A','B','C']" :key="k" class="rounded-full px-2.5 py-1 font-semibold border" :class="filtroAbc === k ? 'bg-marca-texto text-white border-marca-texto' : 'border-marca-borde'" @click="filtroAbc = k">{{ k === 'todos' ? 'Todos' : 'Sólo ' + k }}</button><input v-model="qAbc" class="input !py-1 !text-xs ml-auto w-44" placeholder="Buscar…" /></div>
        <div class="max-h-80 overflow-y-auto">
          <table class="table text-xs"><thead><tr><th>Clase</th><th>Artículo</th><th class="text-right">Facturado s/IVA</th><th class="text-right">%</th><th class="text-right">Acum.</th><th class="text-right">Unidades</th><th class="text-right">Stock</th></tr></thead>
            <tbody><tr v-for="i in abcVisibles" :key="i.id"><td><span class="badge" :class="{ 'bg-emerald-50 text-emerald-700': i.clase === 'A', 'bg-amber-50 text-amber-800': i.clase === 'B', 'bg-gris-light text-marca-muted': i.clase === 'C' }">{{ i.clase }}</span></td><td><Link :href="`/stock/${i.id}`" class="hover:text-carmin">{{ i.nombre }}</Link> <span class="text-marca-muted">{{ i.sku }}</span></td><td class="text-right tabular-nums font-semibold">{{ moneda(i.neto, 0) }}</td><td class="text-right tabular-nums">{{ i.pct }}%</td><td class="text-right tabular-nums text-marca-muted">{{ i.acum }}%</td><td class="text-right tabular-nums">{{ cantidad(i.unidades) }}</td><td class="text-right tabular-nums" :class="i.clase === 'A' && i.stock <= 0 ? 'text-carmin font-bold' : ''">{{ cantidad(i.stock) }}</td></tr>
            <tr v-if="!abcVisibles.length"><td colspan="7" class="text-center text-marca-muted py-6">Sin ventas con artículo en el período.</td></tr></tbody></table>
        </div>
      </div>
      <!-- Cuándo vende -->
      <div class="card" data-e2e="cuando">
        <div class="flex items-center justify-between mb-2"><h2 class="font-bold">Cuándo vende el negocio</h2><span class="text-marca-muted cursor-help" :title="cuando.como">ⓘ</span></div>
        <div class="text-sm space-y-1 mb-3">
          <p v-if="cuando.mejor_dia"><b>Mejor día:</b> {{ fecha(cuando.mejor_dia.fecha) }} · {{ moneda(cuando.mejor_dia.monto, 0) }} ({{ cuando.mejor_dia.n }} facturas)</p>
          <p v-if="cuando.peor_dia"><b>Peor día:</b> {{ fecha(cuando.peor_dia.fecha) }} · {{ moneda(cuando.peor_dia.monto, 0) }}</p>
          <p v-if="cuando.mejor_hora"><b>Hora pico:</b> {{ cuando.mejor_hora.hora }}:00 · <b>la que menos:</b> {{ cuando.peor_hora.hora }}:00 <span v-if="cuando.mejor_dia_semana">· <b>el día que más:</b> {{ cuando.mejor_dia_semana }}</span></p>
          <p v-if="!cuando.celdas.length" class="text-marca-muted">Sin comprobantes con hora en el período.</p>
        </div>
        <!-- mapa de calor: filas = días, columnas = horas de 6 a 23 -->
        <div class="overflow-x-auto"><table class="text-[10px] tabular-nums border-separate" style="border-spacing:2px">
          <thead><tr><th></th><th v-for="h in horas" :key="h" class="text-marca-muted font-normal w-5">{{ h }}</th></tr></thead>
          <tbody><tr v-for="d in [1,2,3,4,5,6,0]" :key="d"><td class="text-marca-muted pr-1">{{ dias[d] }}</td><td v-for="h in horas" :key="h" class="w-5 h-5 rounded" :style="{ background: color(celda(d, h)) }" :title="`${dias[d]} ${h}:00 · ${moneda(celda(d, h), 0)}`"></td></tr></tbody>
        </table></div>
      </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
      <!-- Puntos de venta contra el año anterior -->
      <div class="card" data-e2e="puntos">
        <div class="flex items-center justify-between mb-2"><h2 class="font-bold">Por punto de venta, contra el año anterior</h2><span class="text-marca-muted cursor-help" :title="puntos.como">ⓘ</span></div>
        <table class="table text-sm"><thead><tr><th>Punto de venta</th><th class="text-right">Este período</th><th class="text-right">Año anterior</th><th class="text-right">Var.</th></tr></thead>
          <tbody><tr v-for="p in puntos.items" :key="p.punto_venta_id ?? 0"><td>{{ p.nombre }} <span class="text-xs text-marca-muted">{{ p.n }} fact.</span></td><td class="text-right tabular-nums font-semibold">{{ moneda(p.monto, 0) }}</td><td class="text-right tabular-nums text-marca-muted">{{ moneda(p.anterior, 0) }} <span class="text-xs">{{ p.n_anterior }} fact.</span></td><td class="text-right tabular-nums" :class="p.var === null ? 'text-marca-muted' : p.var >= 0 ? 'text-emerald-600' : 'text-carmin'">{{ p.var === null ? '—' : (p.var >= 0 ? '+' : '') + p.var + '%' }}</td></tr>
          <tr v-if="!puntos.items.length"><td colspan="4" class="text-center text-marca-muted py-6">Sin ventas.</td></tr></tbody></table>
      </div>
      <!-- Lo que se frenó de golpe -->
      <div class="card" data-e2e="frenados">
        <div class="flex items-center justify-between mb-2"><h2 class="font-bold">Lo que se frenó de golpe</h2><span class="text-marca-muted cursor-help" :title="frenados.como">ⓘ</span></div>
        <p class="text-xs text-marca-muted mb-2">Se vendían seguido, tienen stock y hace dos semanas que no sale nada: un precio mal cargado, mal ubicado, vencido.</p>
        <table class="table text-sm"><thead><tr><th>Artículo</th><th class="text-right">Por semana</th><th class="text-right">Stock</th><th class="text-right">Precio</th></tr></thead>
          <tbody><tr v-for="f in frenados.items" :key="f.id"><td><Link :href="`/stock/${f.id}`" class="hover:text-carmin">{{ f.nombre }}</Link> <span class="text-xs text-marca-muted">{{ f.sku }} · {{ f.semanas }} de 6 semanas</span></td><td class="text-right tabular-nums">{{ cantidad(f.por_semana) }}</td><td class="text-right tabular-nums">{{ cantidad(f.stock) }}</td><td class="text-right tabular-nums">{{ moneda(f.precio) }}<span v-if="f.actualizado" class="block text-[10px] text-marca-muted">precio del {{ f.actualizado }}</span></td></tr>
          <tr v-if="!frenados.items.length"><td colspan="4" class="text-center text-emerald-700 py-6">Nada se frenó: lo que se vendía, se sigue vendiendo.</td></tr></tbody></table>
      </div>
    </div>

    <!-- Vendedores -->
    <div class="card" data-e2e="vendedores">
      <div class="flex items-center justify-between mb-2"><h2 class="font-bold">Cada vendedor en el período</h2><span class="text-marca-muted cursor-help" :title="vendedores.como">ⓘ</span></div>
      <div v-if="vendedores.items.length" class="grid sm:grid-cols-4 gap-2 mb-3 text-sm">
        <div v-for="(d, k) in vendedores.destacados" :key="k" class="rounded-xl bg-gris-light p-2.5"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ { mas_vendio: 'El que más vendió', mejor_margen: 'Mejor margen', mejor_ticket: 'Mejor ticket', mas_crecio: 'El que más creció' }[k] }}</p><p class="font-bold">{{ d?.nombre ?? '—' }}</p><p class="text-xs text-marca-muted">{{ d ? ({ mas_vendio: moneda(d.ventas, 0), mejor_margen: (d.margen_pct ?? 0) + '%', mejor_ticket: moneda(d.ticket, 0), mas_crecio: (d.var === null ? 'sin período anterior' : (d.var >= 0 ? '+' : '') + d.var + '%') }[k]) : '' }}</p></div>
      </div>
      <div class="overflow-x-auto"><table class="table text-sm"><thead><tr><th>Vendedor</th><th class="text-right">Ventas</th><th class="text-right">vs anterior</th><th class="text-right">Parte</th><th class="text-right">Facturas</th><th class="text-right">Ticket</th><th class="text-right">Clientes</th><th class="text-right">Margen</th><th class="text-right">N. crédito</th><th class="text-right">Hora pico</th></tr></thead>
        <tbody><tr v-for="v in vendedores.items" :key="v.nombre"><td class="font-medium">{{ v.nombre }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(v.ventas, 0) }}</td><td class="text-right tabular-nums" :class="v.var === null ? 'text-marca-muted' : v.var >= 0 ? 'text-emerald-600' : 'text-carmin'">{{ v.var === null ? '—' : (v.var >= 0 ? '+' : '') + v.var + '%' }}</td><td class="text-right tabular-nums">{{ v.parte }}%</td><td class="text-right tabular-nums">{{ v.facturas }}</td><td class="text-right tabular-nums">{{ moneda(v.ticket, 0) }}</td><td class="text-right tabular-nums">{{ v.clientes }}</td><td class="text-right tabular-nums">{{ moneda(v.margen, 0) }} <span class="text-xs text-marca-muted">{{ v.margen_pct ?? '—' }}%</span></td><td class="text-right tabular-nums text-marca-muted">{{ v.nc ? moneda(v.nc, 0) : '—' }}</td><td class="text-right tabular-nums">{{ v.hora_pico !== null ? v.hora_pico + ':00' : '—' }}</td></tr>
        <tr v-if="!vendedores.items.length"><td colspan="10" class="text-center text-marca-muted py-6">Sin ventas en el período.</td></tr></tbody></table></div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import EstadisticasTabs from '@/Components/EstadisticasTabs.vue'
import { moneda, cantidad } from '@/util/formato'
const props = defineProps({ periodo: Object, sucursalId: Number, listaSucursales: Array, abc: Object, cuando: Object, puntos: Object, frenados: Object, vendedores: Object })
const filtroAbc = ref('todos'), qAbc = ref('')
const abcVisibles = computed(() => props.abc.items.filter(i => (filtroAbc.value === 'todos' || i.clase === filtroAbc.value) && (!qAbc.value || (i.nombre + ' ' + (i.sku ?? '')).toLowerCase().includes(qAbc.value.toLowerCase()))).slice(0, 200))
const dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']
const horas = Array.from({ length: 18 }, (_, i) => i + 6)
const celda = (d, h) => props.cuando.celdas.find(c => c.d === d && c.h === h)?.monto ?? 0
const max = computed(() => Math.max(1, ...props.cuando.celdas.map(c => c.monto)))
const color = v => v <= 0 ? '#f1eee8' : `rgba(228, 0, 63, ${0.12 + 0.88 * Math.min(1, v / max.value)})`
const fecha = f => { const [y, m, d] = f.split('-'); return `${d}/${m}` }
</script>
