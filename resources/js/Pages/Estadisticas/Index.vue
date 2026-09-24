<template>
  <AppLayout titulo="Estadísticas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Estadísticas</h1><p class="page-subtitle">Qué se vende, a quién, dónde y cómo se cobra.</p></div>
      <div class="flex flex-wrap items-center gap-2">
        <select :value="sucursalId ?? ''" @change="$inertia.get('/estadisticas', { ...periodo, sucursal: $event.target.value || undefined }, { preserveState: true, replace: true })" class="input w-auto !py-1 text-xs"><option value="">Todas las sucursales</option><option v-for="s in listaSucursales" :key="s.id" :value="s.id">{{ s.name }}</option></select>
        <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" :extra="{ sucursal: sucursalId }" />
        <Link href="/estadisticas/rentabilidad" class="btn-secondary !py-1 text-xs">Rentabilidad</Link>
        <Link href="/estadisticas/analista" class="btn-violeta !py-1 text-xs">✦ Analista IA</Link>
        <a :href="`/estadisticas?desde=${periodo.desde}&hasta=${periodo.hasta}${sucursalId ? '&sucursal=' + sucursalId : ''}&export=1`" class="btn-secondary !py-1 text-xs">Exportar CSV</a>
      </div>
    </div>

    <div class="card mb-5">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h2 class="font-bold">Comparativo con el año anterior</h2>
        <div class="flex gap-1 bg-marca-fondo rounded-full p-1 text-xs"><button class="px-3 py-1 rounded-full font-semibold" :class="!pesosHoy ? 'bg-white shadow' : 'text-marca-muted'" @click="pesosHoy = false">Pesos nominales</button><button class="px-3 py-1 rounded-full font-semibold" :class="pesosHoy ? 'bg-white shadow' : 'text-marca-muted'" :disabled="!comparativo.pesos_hoy" :title="comparativo.pesos_hoy ? '' : 'Cargá el IPC en Configuración → Impuestos'" @click="comparativo.pesos_hoy && (pesosHoy = true)">Pesos de hoy</button></div>
      </div>
      <div class="grid sm:grid-cols-4 gap-3 mb-3 text-sm">
        <div><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Mismo período {{ comparativo.anterior.desde.slice(0, 4) }}</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(pesosHoy ? comparativo.pesos_hoy.anterior : comparativo.anterior.ventas, 0) }}</p><p class="text-xs text-marca-muted">{{ comparativo.anterior.comprobantes }} comprobantes · ticket {{ moneda(comparativo.anterior.ticket, 0) }}</p></div>
        <div><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Este período</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(pesosHoy ? comparativo.pesos_hoy.actual : kpis.ventas, 0) }}</p><p class="text-xs text-marca-muted">{{ kpis.comprobantes }} comprobantes · ticket {{ moneda(kpis.ticket, 0) }}</p></div>
        <div><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Variación {{ pesosHoy ? 'real' : 'nominal' }}</p><p class="text-xl font-extrabold tabular-nums" :class="(pesosHoy ? comparativo.pesos_hoy.var_real : comparativo.var_nominal) < 0 ? 'text-carmin' : 'text-emerald-700'">{{ (pesosHoy ? comparativo.pesos_hoy.var_real : comparativo.var_nominal) === null ? '—' : ((pesosHoy ? comparativo.pesos_hoy.var_real : comparativo.var_nominal) > 0 ? '+' : '') + (pesosHoy ? comparativo.pesos_hoy.var_real : comparativo.var_nominal) + '%' }}</p><p class="text-xs text-marca-muted">{{ pesosHoy ? 'descontando inflación (IPC ' + comparativo.pesos_hoy.ipc + ')' : 'sin descontar inflación' }}</p></div>
        <div><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Comprobantes</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.comprobantes }} <span class="text-sm text-marca-muted">vs {{ comparativo.anterior.comprobantes }}</span></p></div>
      </div>
      <div class="flex items-end gap-1 h-32">
        <div v-for="m in comparativo.mensual" :key="m.mes" class="flex-1 flex flex-col items-center justify-end h-full min-w-0" :title="`${m.mes}: ${moneda(m.actual, 0)} · año anterior ${moneda(m.anterior, 0)}`">
          <div class="w-full flex items-end gap-px h-full"><div class="flex-1 rounded-t bg-marca-borde" :style="{ height: Math.max(1, m.anterior / maxComp * 100) + '%' }"></div><div class="flex-1 rounded-t bg-marca-grad" :style="{ height: Math.max(1, (pesosHoy && m.actual_hoy !== null ? m.actual_hoy : m.actual) / maxComp * 100) + '%' }"></div></div>
          <span class="text-[9px] text-marca-muted mt-1 truncate w-full text-center">{{ m.mes }}</span>
        </div>
      </div>
      <p class="text-[11px] text-marca-muted mt-1">Gris: mismo mes del año anterior · Color: últimos 12 meses.</p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
      <StatCard label="Ventas" :valor="kpis.ventas" :tendencia="kpis.ventas_var" />
      <StatCard label="Comprobantes" :valor="kpis.comprobantes" formato="entero" :tendencia="kpis.comprobantes_var" />
      <StatCard label="Ticket promedio" :valor="kpis.ticket" />
      <div class="card flex flex-col gap-1"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Margen bruto</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.margen !== null && kpis.margen < 0 ? 'text-carmin' : ''">{{ kpis.margen === null ? '—' : kpis.margen + '%' }}</p><p class="text-xs text-marca-muted tabular-nums">{{ moneda(kpis.margen_monto, 0) }} sobre costo</p></div>
      <StatCard label="Clientes que compraron" :valor="kpis.clientes" formato="entero" />
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-3">Ventas por {{ serie.length && serie[0].label.length > 5 ? 'mes' : 'día' }}</h2>
        <div class="flex items-end gap-1 h-44">
          <div v-for="s in serie" :key="s.label" class="flex-1 flex flex-col items-center justify-end h-full min-w-0" :title="`${s.label}: ${moneda(s.monto, 0)} (${s.n})`">
            <div class="w-full rounded-t bg-marca-grad" :style="{ height: Math.max(2, s.monto / maxSerie * 100) + '%' }"></div>
            <span class="text-[9px] text-marca-muted mt-1 truncate w-full text-center">{{ s.label }}</span>
          </div>
          <p v-if="!serie.length" class="w-full text-center text-sm text-marca-muted self-center">Sin ventas en el período.</p>
        </div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-3">Por sucursal</h2>
        <Barras :items="porSucursal.map(s => ({ label: s.nombre, valor: Number(s.monto), sub: s.n + ' comp.' }))" />
        <h2 class="font-bold mt-5 mb-3">Cómo se cobró</h2>
        <Barras :items="cobros.map(c => ({ label: c.medio, valor: c.monto, sub: c.n + ' cobros' }))" color="bg-violeta" />
      </div>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div class="card"><h2 class="font-bold mb-3">Mejores clientes</h2><Barras :items="clientes.map(c => ({ label: c.nombre, valor: Number(c.monto), sub: c.n + ' comp.', url: `/clientes/${c.id}` }))" /></div>
      <div class="card"><h2 class="font-bold mb-3">Artículos más vendidos</h2><Barras :items="articulos.map(a => ({ label: a.nombre, valor: Number(a.monto), sub: `${cantidad(a.cantidad)} ${a.unit} · margen ${moneda(a.margen, 0)}`, url: `/stock/${a.id}` }))" color="bg-violeta" /></div>
      <div class="card"><h2 class="font-bold mb-3">Por rubro</h2><Barras :items="rubros.map(r => ({ label: r.nombre, valor: Number(r.monto), color: r.color }))" /></div>
      <div class="card"><h2 class="font-bold mb-3">Por vendedor</h2><Barras :items="vendedores.map(v => ({ label: v.nombre, valor: Number(v.monto), sub: v.n + ' comp.' }))" color="bg-magenta" /></div>
      <div class="card"><h2 class="font-bold mb-3">A quién le compramos</h2><Barras :items="proveedores.map(p => ({ label: p.nombre, valor: Number(p.monto), sub: p.n + ' facturas', url: `/proveedores/${p.id}` }))" color="bg-amber-500" /></div>
      <div class="card"><h2 class="font-bold mb-3">Horas de venta</h2>
        <div class="flex items-end gap-0.5 h-28">
          <div v-for="h in 24" :key="h" class="flex-1 flex flex-col items-center justify-end h-full" :title="`${h - 1}:00 · ${horaN(h - 1)} comp.`"><div class="w-full rounded-t bg-lavanda-dark" :style="{ height: Math.max(horaN(h - 1) ? 4 : 1, horaN(h - 1) / maxHora * 100) + '%' }"></div><span v-if="(h - 1) % 4 === 0" class="text-[9px] text-marca-muted">{{ h - 1 }}</span></div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, h, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { moneda, cantidad } from '@/util/formato'
const props = defineProps({ periodo: Object, sucursalId: Number, listaSucursales: Array, kpis: Object, serie: Array, porSucursal: Array, clientes: Array, articulos: Array, rubros: Array, vendedores: Array, cobros: Array, proveedores: Array, horas: Array, comparativo: Object })
const pesosHoy = ref(false)
const maxComp = computed(() => Math.max(1, ...props.comparativo.mensual.flatMap(m => [m.actual, m.anterior, m.actual_hoy ?? 0])))
const maxSerie = computed(() => Math.max(1, ...props.serie.map(s => s.monto)))
const horaN = hh => Number(props.horas.find(x => Number(x.h) === hh)?.n ?? 0)
const maxHora = computed(() => Math.max(1, ...props.horas.map(x => Number(x.n))))

// Barras horizontales con etiqueta y valor (sin librerías).
const Barras = {
  props: { items: Array, color: { type: String, default: 'bg-carmin' } },
  setup(p) {
    return () => {
      if (!p.items.length) return h('p', { class: 'text-sm text-marca-muted' }, 'Sin datos en el período.')
      const max = Math.max(1, ...p.items.map(i => Math.abs(i.valor)))
      return h('div', { class: 'space-y-2' }, p.items.map(i => h('div', { class: 'text-sm' }, [
        h('div', { class: 'flex justify-between gap-2 mb-0.5' }, [
          i.url ? h(Link, { href: i.url, class: 'font-medium truncate hover:text-carmin' }, () => i.label) : h('span', { class: 'font-medium truncate' }, i.label),
          h('span', { class: 'tabular-nums font-semibold shrink-0' }, moneda(i.valor, 0)),
        ]),
        h('div', { class: 'h-2 rounded-full bg-marca-fondo overflow-hidden' }, [h('div', { class: `h-full rounded-full ${i.color ? '' : p.color}`, style: { width: Math.max(2, Math.abs(i.valor) / max * 100) + '%', background: i.color || undefined } })]),
        i.sub ? h('p', { class: 'text-[11px] text-marca-muted' }, i.sub) : null,
      ])))
    }
  },
}
</script>
