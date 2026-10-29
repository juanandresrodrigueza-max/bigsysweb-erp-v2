<template>
  <AppLayout titulo="Estadísticas · Vendedores">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">El equipo de ventas</h1><p class="page-subtitle">Quién vende, cómo viene la venta, a quién le falta visitar, dónde estuvieron y por qué no se vendió.</p></div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="flex gap-1 bg-marca-fondo rounded-full p-1 text-xs"><Link v-for="(t, k) in periodo.opciones" :key="k" :href="`/estadisticas/vendedores?periodo=${k}`" class="px-3 py-1 rounded-full font-semibold" :class="periodo.k === k ? 'bg-white shadow' : 'text-marca-muted'" :data-e2e="`periodo-${k}`">{{ t }}</Link></div>
        <Link href="/estadisticas" class="btn-secondary !py-1 text-xs">← Estadísticas</Link>
        <Link href="/clientes/vendedores" class="btn-secondary !py-1 text-xs">Vendedores y comisiones</Link>
      </div>
    </div>

    <EstadisticasTabs activa="vendedores" />

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <div class="card lg:col-span-2" data-e2e="ranking">
        <h2 class="font-bold mb-2">Ranking <span class="text-marca-muted font-normal text-sm">· {{ periodo.desde }} a {{ periodo.hasta }}</span></h2>
        <div class="overflow-x-auto"><table class="table text-sm"><thead><tr><th>#</th><th>Vendedor</th><th class="text-right">Vendido</th><th class="text-right">Comp.</th><th class="text-right">Ticket</th><th class="text-right">Clientes</th><th>Objetivo del mes</th></tr></thead>
          <tbody><tr v-for="(v, i) in ranking" :key="v.id"><td class="text-marca-muted">{{ i + 1 }}</td><td class="font-semibold">{{ v.nombre }}<span v-if="v.modo === 'calle'" class="badge bg-sky-50 text-sky-700 ml-1">calle</span></td><td class="text-right tabular-nums font-bold">{{ moneda(v.total, 0) }}<span v-if="v.devoluciones" class="block text-[10px] text-carmin">−{{ moneda(v.devoluciones, 0) }} devuelto</span></td><td class="text-right tabular-nums">{{ v.comprobantes }}</td><td class="text-right tabular-nums">{{ moneda(v.ticket, 0) }}</td><td class="text-right tabular-nums">{{ v.clientes }}</td>
            <td><div v-if="v.objetivo" class="min-w-[9rem]"><div class="flex justify-between text-[11px]"><span class="tabular-nums">{{ v.objetivo.pct }}%</span><span :class="v.objetivo.ritmo === 'adelantado' ? 'text-emerald-700' : 'text-carmin'">{{ v.objetivo.ritmo }}</span></div><div class="h-1.5 rounded-full bg-marca-fondo overflow-hidden"><div class="h-full bg-marca-grad" :style="{ width: Math.min(100, v.objetivo.pct) + '%' }"></div></div></div><span v-else class="text-xs text-marca-muted">sin objetivo</span></td></tr>
          <tr v-if="!ranking.length"><td colspan="7" class="text-center text-marca-muted py-8">Sin vendedores activos.</td></tr></tbody></table></div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-2">Cómo viene la venta</h2>
        <div class="flex items-end gap-px h-28">
          <div v-for="d in por_dia" :key="d.fecha" class="flex-1 flex flex-col items-center justify-end h-full min-w-0" :title="`${d.fecha}: ${moneda(d.monto, 0)}`"><div class="w-full rounded-t" :class="d.monto < 0 ? 'bg-carmin' : 'bg-marca-grad'" :style="{ height: Math.max(2, Math.abs(d.monto) / maxDia * 100) + '%' }"></div></div>
        </div>
        <p class="text-[10px] text-marca-muted mt-1">{{ por_dia[0]?.fecha }} → {{ por_dia[por_dia.length - 1]?.fecha }} · una barra por día</p>
        <h2 class="font-bold mt-4 mb-1">Por qué no se vendió</h2>
        <div v-if="Object.keys(motivos).length" class="space-y-1 text-sm"><div v-for="(n, m) in motivos" :key="m" class="flex justify-between"><span>{{ m }}</span><b class="tabular-nums">{{ n }}</b></div></div>
        <p v-else class="text-xs text-marca-muted">Ninguna visita sin venta en el período.</p>
      </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
      <div class="card" data-e2e="ruta">
        <h2 class="font-bold mb-1">Cumplimiento de ruta</h2>
        <p class="text-xs text-marca-muted mb-2">Cuántos clientes tiene cada vendedor de calle, a cuántos visitó, a cuántos les vendió y quiénes le faltan: con esa lista se arma el día siguiente.</p>
        <div v-for="v in ranking.filter(x => x.ruta.asignados)" :key="v.id" class="py-2 border-t border-marca-borde first:border-0 text-sm">
          <div class="flex flex-wrap justify-between gap-2"><b>{{ v.nombre }}</b><span class="text-xs tabular-nums">{{ v.ruta.asignados }} asignados · {{ v.ruta.visitados }} visitados · {{ v.ruta.vendidos }} con venta<span v-if="v.no_vendi" class="text-carmin"> · {{ v.no_vendi }} sin venta</span></span></div>
          <div class="h-1.5 rounded-full bg-marca-fondo overflow-hidden mt-1"><div class="h-full bg-emerald-500" :style="{ width: (v.ruta.vendidos / v.ruta.asignados * 100) + '%' }"></div></div>
          <p v-if="v.ruta.faltan.length" class="text-xs text-marca-muted mt-1">Faltan: {{ v.ruta.faltan.slice(0, 12).join(', ') }}<span v-if="v.ruta.faltan.length > 12"> y {{ v.ruta.faltan.length - 12 }} más</span></p>
        </div>
        <p v-if="!ranking.some(x => x.ruta.asignados)" class="text-xs text-marca-muted">Ningún vendedor tiene clientes asignados. Se asignan en la ficha de cada cliente.</p>
      </div>
      <div class="card p-0" data-e2e="donde">
        <div class="px-4 py-3"><h2 class="font-bold">Dónde estuvieron</h2><p class="text-xs text-marca-muted">La ubicación se sella sólo cuando el vendedor confirma una venta, un cobro o toca "Llegué". No hay seguimiento continuo.</p></div>
        <div class="max-h-96 overflow-y-auto divide-y divide-marca-borde text-sm">
          <div v-for="(d, i) in donde" :key="i" class="px-4 py-1.5 flex items-center gap-2"><span class="text-xs text-marca-muted tabular-nums w-20">{{ d.cuando }}</span><span class="flex-1 min-w-0 truncate"><b>{{ d.quien }}</b> · {{ d.tipo }} · {{ d.cliente }}<span v-if="d.motivo" class="text-marca-muted"> ({{ d.motivo }})</span></span><span v-if="d.distancia" class="text-xs" :class="d.distancia.m === null ? 'text-marca-muted' : d.distancia.m < 150 ? 'text-emerald-700' : 'text-amber-700'">{{ d.distancia.texto }}</span><a v-if="d.lat" :href="`https://www.google.com/maps?q=${d.lat},${d.lng}`" target="_blank" class="text-violeta text-xs font-semibold">mapa</a></div>
          <p v-if="!donde.length" class="px-4 py-6 text-center text-xs text-marca-muted">Sin visitas en el período.</p>
        </div>
      </div>
    </div>

    <div class="card mt-4" data-e2e="relevamiento">
      <h2 class="font-bold mb-1">Relevamiento en la calle</h2>
      <p class="text-xs text-marca-muted mb-2">Fotos de la góndola, del precio de la competencia o del stock del cliente, tomadas en la visita.</p>
      <div v-if="relevamientos.length" class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <a v-for="r in relevamientos" :key="r.id" :href="`/estadisticas/vendedores/relevamiento/${r.id}/foto`" target="_blank" class="block rounded-xl border border-marca-borde overflow-hidden hover:border-violeta">
          <img :src="`/estadisticas/vendedores/relevamiento/${r.id}/foto`" class="w-full h-36 object-cover bg-marca-fondo" loading="lazy" />
          <div class="p-2 text-xs"><p class="font-semibold truncate">{{ r.cliente }}</p><p class="text-marca-muted">{{ r.tipo }} · {{ r.quien }} · {{ r.cuando }}</p><p v-if="r.nota">{{ r.nota }}</p></div>
        </a>
      </div>
      <p v-else class="text-xs text-marca-muted">Nada relevado en el período.</p>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadisticasTabs from '@/Components/EstadisticasTabs.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ periodo: Object, ranking: Array, por_dia: Array, motivos: Object, donde: Array, relevamientos: Array })
const maxDia = computed(() => Math.max(1, ...props.por_dia.map(d => Math.abs(d.monto))))
</script>
