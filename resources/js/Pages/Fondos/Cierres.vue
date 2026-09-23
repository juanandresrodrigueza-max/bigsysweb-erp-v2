<template>
  <AppLayout titulo="Cierres de caja">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Control de cajas</h1><p class="page-subtitle">Cada cierre de turno con lo esperado, lo contado y la diferencia. Quién tiene faltantes, en qué caja, y los turnos abiertos ahora.</p></div>
      <div class="flex gap-2"><Link href="/fondos" class="btn-secondary">Fondos</Link><a :href="`/fondos/cierres?export=1&desde=${filtros.desde}&hasta=${filtros.hasta}`" class="btn-secondary">Exportar CSV</a></div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Turnos cerrados</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.turnos }}</p><p class="text-xs text-marca-muted">{{ kpis.promedio_horas }} h promedio</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Con diferencia</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.con_diferencia ? 'text-carmin' : 'text-emerald-700'">{{ kpis.con_diferencia }}</p><p class="text-xs text-marca-muted">{{ kpis.turnos ? Math.round(kpis.con_diferencia / kpis.turnos * 100) : 0 }}% de los turnos</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Faltantes</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(kpis.faltantes, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Sobrantes</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ moneda(kpis.sobrantes, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Cobrado en caja</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.cobros, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Abiertos ahora</p><p class="text-xl font-extrabold tabular-nums" :class="abiertos.some(a => a.largo) ? 'text-amber-600' : ''">{{ kpis.abiertos }}</p></div>
    </div>

    <div v-if="abiertos.length" class="card mb-4">
      <h2 class="font-bold mb-2">Turnos abiertos</h2>
      <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
        <div v-for="a in abiertos" :key="a.id" class="rounded-xl border p-3 text-sm" :class="a.largo ? 'border-amber-300 bg-amber-50/40' : 'border-marca-borde'">
          <div class="flex justify-between"><b>{{ a.caja }}</b><span class="text-xs text-marca-muted">{{ a.cajero }} · desde {{ a.apertura }} ({{ a.horas }} h)</span></div>
          <p class="text-xs mt-1">Esperado en caja: <b class="tabular-nums">{{ moneda(a.esperado) }}</b><span v-if="a.largo" class="text-amber-700 font-semibold"> · lleva más de 14 horas abierto</span></p>
          <div v-if="a.arqueos.length" class="mt-1 text-xs text-marca-muted">Arqueos: <span v-for="(q, i) in a.arqueos" :key="i" class="mr-2">{{ q.hora }} <span :class="Math.abs(q.diferencia) < 0.005 ? 'text-emerald-700' : 'text-carmin'">{{ moneda(q.diferencia, 0) }}</span></span></div>
        </div>
      </div>
    </div>

    <div class="card mb-4 flex flex-wrap items-end gap-3">
      <div><label class="label">Desde</label><input v-model="f.desde" type="date" class="input" /></div><div><label class="label">Hasta</label><input v-model="f.hasta" type="date" class="input" /></div>
      <div><label class="label">Caja</label><select v-model="f.caja" class="input"><option :value="null">Todas</option><option v-for="c in cajas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div>
      <div><label class="label">Cajero</label><select v-model="f.cajero" class="input"><option :value="null">Todos</option><option v-for="u in cajeros" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
      <label class="flex items-center gap-2 text-sm pb-2"><input v-model="f.solo_dif" type="checkbox" class="accent-carmin" /> Solo con diferencia</label>
      <button class="btn-primary" @click="filtrar">Ver</button>
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <div class="card lg:col-span-2">
        <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Diferencia neta por día</p>
        <svg v-if="porDia.length" :viewBox="`0 0 ${W} ${H}`" class="w-full h-36" preserveAspectRatio="none">
          <line x1="0" :x2="W" :y1="y(0)" :y2="y(0)" stroke="#c5bcdd" />
          <rect v-for="(d, i) in porDia" :key="i" :x="x(i)" :width="bw" :y="Math.min(y(0), y(d.diferencia))" :height="Math.max(1, Math.abs(y(d.diferencia) - y(0)))" :fill="d.diferencia < 0 ? '#e4003f' : '#1f9d5b'" rx="2"><title>{{ d.fecha }}: {{ moneda(d.diferencia) }} · {{ d.turnos }} turnos</title></rect>
        </svg>
        <p v-else class="text-sm text-marca-muted py-8 text-center">Sin cierres en el período.</p>
        <div class="flex justify-between text-[10px] text-marca-muted tabular-nums"><span>{{ porDia[0]?.fecha }}</span><span>{{ porDia[porDia.length - 1]?.fecha }}</span></div>
      </div>
      <div class="card p-0 overflow-hidden">
        <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Por cajero</h2><p class="text-xs text-marca-muted">Ordenado del que más falta al que más sobra</p></div>
        <table class="table text-xs">
          <thead><tr><th>Cajero</th><th class="text-right">Turnos</th><th class="text-right">Con dif.</th><th class="text-right">Neto</th></tr></thead>
          <tbody><tr v-for="c in porCajero" :key="c.cajero"><td class="font-medium">{{ c.cajero }}<p class="text-marca-muted">{{ c.horas }} h · cobró {{ moneda(c.cobros, 0) }}</p></td><td class="text-right tabular-nums">{{ c.turnos }}</td><td class="text-right tabular-nums" :class="c.con_diferencia ? 'text-carmin font-semibold' : ''">{{ c.con_diferencia }}</td><td class="text-right tabular-nums font-bold" :class="c.neto < 0 ? 'text-carmin' : c.neto > 0 ? 'text-emerald-700' : ''">{{ moneda(c.neto, 0) }}<p class="font-normal text-marca-muted">−{{ moneda(-c.faltantes, 0) }} / +{{ moneda(c.sobrantes, 0) }}</p></td></tr><tr v-if="!porCajero.length"><td colspan="4" class="text-center text-marca-muted py-5">Sin datos.</td></tr></tbody>
        </table>
      </div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <div class="px-4 py-3 border-b border-marca-borde flex items-center justify-between"><h2 class="font-bold">Cierres</h2><span class="text-xs text-marca-muted">Tocá un cierre para ver el detalle por medio de pago</span></div>
      <table class="table text-sm">
        <thead><tr><th>Caja</th><th>Cajero</th><th>Turno</th><th class="text-right">Inicial</th><th class="text-right">Cobros</th><th class="text-right">Gastos</th><th class="text-right">Retiros</th><th class="text-right">Esperado</th><th class="text-right">Contado</th><th class="text-right">Diferencia</th><th></th></tr></thead>
        <tbody>
          <template v-for="t in turnos" :key="t.id">
            <tr class="cursor-pointer hover:bg-gris-light/40" @click="abierto = abierto === t.id ? null : t.id">
              <td><p class="font-medium">{{ t.caja }}</p><p class="text-xs text-marca-muted">{{ t.sucursal }}</p></td><td>{{ t.cajero }}</td><td class="text-xs tabular-nums">{{ t.apertura }} → {{ t.cierre }}<p class="text-marca-muted">{{ t.horas }} h · {{ t.movimientos }} mov.<span v-if="t.arqueos"> · {{ t.arqueos }} arqueo{{ t.arqueos > 1 ? 's' : '' }}</span></p></td>
              <td class="text-right tabular-nums">{{ moneda(t.inicial, 0) }}</td><td class="text-right tabular-nums text-emerald-700">{{ moneda(t.cobros, 0) }}</td><td class="text-right tabular-nums">{{ moneda(t.gastos, 0) }}</td><td class="text-right tabular-nums">{{ moneda(t.retiros, 0) }}</td><td class="text-right tabular-nums">{{ moneda(t.esperado, 0) }}</td><td class="text-right tabular-nums">{{ moneda(t.contado, 0) }}</td>
              <td class="text-right tabular-nums font-bold" :class="Math.abs(t.diferencia) < 0.005 ? 'text-emerald-700' : 'text-carmin'">{{ Math.abs(t.diferencia) < 0.005 ? 'justo' : moneda(t.diferencia, 0) }}</td>
              <td class="text-right"><a :href="`/fondos/turnos/${t.id}/rendicion`" target="_blank" class="text-xs text-violeta font-semibold" @click.stop>Rendición</a></td>
            </tr>
            <tr v-if="abierto === t.id"><td colspan="11" class="bg-gris-light/40 !py-2">
              <div class="flex flex-wrap gap-4 text-xs">
                <div v-for="m in t.medios" :key="m.medio" class="rounded-lg bg-white border border-marca-borde px-3 py-1.5"><p class="font-semibold capitalize">{{ m.medio.replace('_', ' ') }}</p><p class="tabular-nums">esperado {{ moneda(m.esperado, 0) }}<span v-if="m.declarado !== null"> · declarado {{ moneda(m.declarado, 0) }} <b :class="Math.abs(m.declarado - m.esperado) < 0.005 ? 'text-emerald-700' : 'text-carmin'">{{ moneda(m.declarado - m.esperado, 0) }}</b></span><span v-else class="text-marca-muted"> · sin declarar</span></p></div>
                <p v-if="t.notas" class="self-center text-marca-muted">Notas: {{ t.notas }}</p>
              </div>
            </td></tr>
          </template>
          <tr v-if="!turnos.length"><td colspan="11" class="text-center text-marca-muted py-8">Sin cierres en el período.</td></tr>
        </tbody>
      </table>
    </div>
    <div v-if="porCaja.length > 1" class="card mt-4 p-0 overflow-hidden">
      <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Por caja</h2></div>
      <table class="table text-sm"><thead><tr><th>Caja</th><th class="text-right">Turnos</th><th class="text-right">Con diferencia</th><th class="text-right">Cobrado</th><th class="text-right">Diferencia neta</th></tr></thead>
        <tbody><tr v-for="c in porCaja" :key="c.caja"><td class="font-medium">{{ c.caja }}</td><td class="text-right tabular-nums">{{ c.turnos }}</td><td class="text-right tabular-nums">{{ c.con_diferencia }}</td><td class="text-right tabular-nums">{{ moneda(c.cobros, 0) }}</td><td class="text-right tabular-nums font-bold" :class="c.neto < 0 ? 'text-carmin' : c.neto > 0 ? 'text-emerald-700' : ''">{{ moneda(c.neto, 0) }}</td></tr></tbody></table>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ turnos: Array, porCajero: Array, porCaja: Array, porDia: Array, abiertos: Array, filtros: Object, cajas: Array, cajeros: Array, cuentasDestino: Array, kpis: Object })
const f = reactive({ ...props.filtros })
function filtrar() { router.get('/fondos/cierres', { desde: f.desde, hasta: f.hasta, caja: f.caja || undefined, cajero: f.cajero || undefined, solo_dif: f.solo_dif ? 1 : undefined }, { preserveState: true }) }
const abierto = ref(null)
const W = 760, H = 140, bw = computed(() => Math.max(3, W / Math.max(1, props.porDia.length) - 4))
const max = computed(() => Math.max(1, ...props.porDia.map(d => Math.abs(d.diferencia))))
const y = v => H / 2 - (v / max.value) * (H / 2 - 6)
const x = i => i * (W / Math.max(1, props.porDia.length)) + 2
</script>
