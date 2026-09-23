<template>
  <AppLayout titulo="Balance">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Balance de sumas y saldos</h1><p class="page-subtitle">Todas las cuentas con movimiento en el período y el estado de resultados.</p></div>
      <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" />
    </div>
    <ContableTabs />
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 card p-0 overflow-x-auto">
        <table class="table">
          <thead><tr><th>Cuenta</th><th class="text-right">Debe</th><th class="text-right">Haber</th><th class="text-right">Saldo deudor</th><th class="text-right">Saldo acreedor</th></tr></thead>
          <tbody>
            <template v-for="(grupo, tipo) in porTipo" :key="tipo">
              <tr class="bg-marca-fondo/60"><td colspan="5" class="font-bold uppercase text-xs tracking-widest">{{ tipos[tipo] }}</td></tr>
              <tr v-for="f in grupo" :key="f.id" class="cursor-pointer" @click="$inertia.visit(`/contable/mayor?cuenta=${f.id}&desde=${periodo.desde}&hasta=${periodo.hasta}`)">
                <td><span class="text-marca-muted tabular-nums text-xs mr-2">{{ f.codigo }}</span>{{ f.nombre }}</td>
                <td class="text-right tabular-nums">{{ moneda(f.debe) }}</td><td class="text-right tabular-nums">{{ moneda(f.haber) }}</td>
                <td class="text-right tabular-nums font-semibold">{{ f.deudor ? moneda(f.deudor) : '' }}</td><td class="text-right tabular-nums font-semibold">{{ f.acreedor ? moneda(f.acreedor) : '' }}</td>
              </tr>
            </template>
          </tbody>
          <tfoot><tr class="font-bold bg-marca-fondo"><td>Totales</td><td class="text-right tabular-nums">{{ moneda(totales.debe) }}</td><td class="text-right tabular-nums">{{ moneda(totales.haber) }}</td><td class="text-right tabular-nums">{{ moneda(totales.deudor) }}</td><td class="text-right tabular-nums">{{ moneda(totales.acreedor) }}</td></tr></tfoot>
        </table>
        <p class="px-4 py-2 text-xs" :class="Math.abs(totales.debe - totales.haber) < 0.05 ? 'text-emerald-700' : 'text-carmin'">{{ Math.abs(totales.debe - totales.haber) < 0.05 ? '✓ Debe = Haber: el libro cierra.' : 'Atención: debe y haber no coinciden.' }}</p>
      </div>
      <div class="card">
        <h2 class="font-bold mb-3">Estado de resultados</h2>
        <p class="label">Ingresos</p>
        <div v-for="i in resultado.ingresos" :key="i.id" class="flex justify-between text-sm py-1 border-t border-marca-borde/60"><span>{{ i.nombre }}</span><span class="tabular-nums">{{ moneda(i.monto, 0) }}</span></div>
        <div class="flex justify-between text-sm py-1 font-bold border-t border-marca-borde"><span>Total ingresos</span><span class="tabular-nums text-emerald-700">{{ moneda(resultado.total_ingresos, 0) }}</span></div>
        <p class="label mt-4">Egresos</p>
        <div v-for="e in resultado.egresos" :key="e.id" class="flex justify-between text-sm py-1 border-t border-marca-borde/60"><span>{{ e.nombre }}</span><span class="tabular-nums">{{ moneda(e.monto, 0) }}</span></div>
        <div class="flex justify-between text-sm py-1 font-bold border-t border-marca-borde"><span>Total egresos</span><span class="tabular-nums text-carmin">{{ moneda(resultado.total_egresos, 0) }}</span></div>
        <div class="mt-4 p-3 rounded-xl text-white" :class="resultado.resultado >= 0 ? 'bg-violeta-grad' : 'bg-carmin'"><p class="text-[11px] font-bold uppercase tracking-widest opacity-80">Resultado del período</p><p class="text-2xl font-extrabold tabular-nums">{{ moneda(resultado.resultado, 0) }}</p></div>
      </div>
    </div>
  </AppLayout>
</template>
<script setup>
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ periodo: Object, filas: Array, totales: Object, resultado: Object, tipos: Object })
const porTipo = computed(() => { const g = {}; for (const t of Object.keys(props.tipos)) { const f = props.filas.filter(x => x.tipo === t); if (f.length) g[t] = f } return g })
</script>
