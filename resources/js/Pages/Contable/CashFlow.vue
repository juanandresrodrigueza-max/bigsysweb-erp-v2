<template>
  <AppLayout titulo="Cash flow 13 semanas">
    <ContableTabs />
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Cash flow proyectado</h1><p class="page-subtitle">Con lo que hay hoy en caja y bancos, lo que está por cobrar y lo que hay que pagar, semana por semana. Es una proyección: sirve para ver el bache antes de que llegue.</p></div>
      <a href="/contable/cashflow?export=1" class="btn-secondary">Exportar CSV</a>
    </div>
    <div v-if="p.alerta" class="rounded-xl border border-red-200 bg-red-50 text-carmin px-4 py-3 text-sm font-semibold mb-4">⚠ {{ p.alerta }}</div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Saldo hoy (caja + bancos)</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(p.saldo_inicial, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Ingresos proyectados</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ moneda(totalIn, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Egresos proyectados</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(totalOut, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Punto más bajo</p><p class="text-xl font-extrabold tabular-nums" :class="p.minimo.saldo < 0 ? 'text-carmin' : ''">{{ moneda(p.minimo.saldo, 0) }}</p><p class="text-xs text-marca-muted">semana {{ p.minimo.semana + 1 }} · {{ p.columnas[p.minimo.semana]?.desde }}</p></div>
    </div>
    <div class="card mb-4">
      <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Saldo proyectado por semana</p>
      <svg :viewBox="`0 0 ${W} ${H}`" class="w-full h-40" preserveAspectRatio="none">
        <line :x1="0" :x2="W" :y1="y(0)" :y2="y(0)" stroke="#c5bcdd" stroke-width="1" />
        <rect v-for="(c, i) in p.columnas" :key="i" :x="x(i)" :width="bw" :y="Math.min(y(0), y(c.saldo))" :height="Math.max(1, Math.abs(y(c.saldo) - y(0)))" :fill="c.saldo < 0 ? '#e4003f' : '#4f3089'" rx="2" />
      </svg>
      <div class="flex justify-between text-[10px] text-marca-muted mt-1 tabular-nums"><span v-for="c in p.columnas" :key="c.semana">{{ c.desde }}</span></div>
    </div>
    <div class="card p-0 overflow-x-auto">
      <table class="table text-xs whitespace-nowrap">
        <thead><tr><th class="sticky left-0 bg-white">Concepto</th><th v-for="c in p.columnas" :key="c.semana" class="text-right">{{ c.label }}<br><span class="font-normal text-marca-muted">{{ c.desde }}–{{ c.hasta }}</span></th><th class="text-right">Total</th></tr></thead>
        <tbody>
          <tr class="font-bold bg-gris-light/50"><td class="sticky left-0 bg-gris-light">Ingresos</td><td v-for="c in p.columnas" :key="c.semana" class="text-right tabular-nums text-emerald-700">{{ n(c.ingresos) }}</td><td class="text-right tabular-nums text-emerald-700">{{ n(totalIn) }}</td></tr>
          <tr v-for="(f, k) in filasIn" :key="k"><td class="sticky left-0 bg-white pl-6 text-marca-muted">{{ f.label }}</td><td v-for="(v, i) in f.semanas" :key="i" class="text-right tabular-nums">{{ v ? n(v) : '·' }}</td><td class="text-right tabular-nums">{{ n(f.total) }}</td></tr>
          <tr class="font-bold bg-gris-light/50"><td class="sticky left-0 bg-gris-light">Egresos</td><td v-for="c in p.columnas" :key="c.semana" class="text-right tabular-nums text-carmin">{{ n(c.egresos) }}</td><td class="text-right tabular-nums text-carmin">{{ n(totalOut) }}</td></tr>
          <tr v-for="(f, k) in filasOut" :key="k"><td class="sticky left-0 bg-white pl-6 text-marca-muted">{{ f.label }}</td><td v-for="(v, i) in f.semanas" :key="i" class="text-right tabular-nums">{{ v ? n(v) : '·' }}</td><td class="text-right tabular-nums">{{ n(f.total) }}</td></tr>
          <tr class="font-bold"><td class="sticky left-0 bg-white">Neto de la semana</td><td v-for="c in p.columnas" :key="c.semana" class="text-right tabular-nums" :class="c.neto < 0 ? 'text-carmin' : ''">{{ n(c.neto) }}</td><td></td></tr>
          <tr class="font-extrabold border-t-2 border-marca-borde"><td class="sticky left-0 bg-white">Saldo proyectado</td><td v-for="c in p.columnas" :key="c.semana" class="text-right tabular-nums" :class="c.saldo < 0 ? 'text-carmin bg-red-50' : ''">{{ n(c.saldo) }}</td><td></td></tr>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-marca-muted mt-3">Cómo se arma: cobranzas por fecha de vencimiento (85% en fecha, 15% dos semanas después), cheques por fecha de pago, abonos por su próximo vencimiento, ventas de contado y gastos como promedio de los últimos 90 días. Lo vencido cae en esta semana.</p>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ p: Object })
const n = v => Number(v ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 0 })
const filasIn = computed(() => Object.fromEntries(Object.entries(props.p.filas).filter(([, f]) => f.tipo === 'in')))
const filasOut = computed(() => Object.fromEntries(Object.entries(props.p.filas).filter(([, f]) => f.tipo === 'out')))
const totalIn = computed(() => props.p.columnas.reduce((a, c) => a + c.ingresos, 0))
const totalOut = computed(() => props.p.columnas.reduce((a, c) => a + c.egresos, 0))
const W = 780, H = 160, bw = computed(() => W / props.p.columnas.length - 6)
const max = computed(() => Math.max(1, ...props.p.columnas.map(c => Math.abs(c.saldo)), Math.abs(props.p.saldo_inicial)))
const y = v => H / 2 - (v / max.value) * (H / 2 - 8)
const x = i => i * (W / props.p.columnas.length) + 3
</script>
