<template>
  <AppLayout titulo="Mayor">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Mayor</h1><p class="page-subtitle">Movimientos de una cuenta con saldo corrido.</p></div>
      <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" :extra="{ cuenta: cuentaId }" />
    </div>
    <ContableTabs />
    <div class="card mb-4 flex flex-wrap items-center gap-3">
      <select :value="cuentaId" @change="$inertia.get('/contable/mayor', { cuenta: $event.target.value, ...periodo }, { preserveState: true, replace: true })" class="input w-auto min-w-[280px]"><option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option></select>
      <span class="text-sm text-marca-muted">Saldo inicial <b class="tabular-nums text-marca-texto">{{ moneda(inicial) }}</b> · final <b class="tabular-nums" :class="final < 0 ? 'text-carmin' : 'text-marca-texto'">{{ moneda(final) }}</b> <span class="text-xs">({{ deudora ? 'deudor' : 'acreedor' }} positivo)</span></span>
    </div>
    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Fecha</th><th>Asiento</th><th>Concepto</th><th class="text-right">Debe</th><th class="text-right">Haber</th><th class="text-right">Saldo</th></tr></thead>
        <tbody>
          <tr><td colspan="5" class="text-marca-muted italic">Saldo inicial al {{ periodo.desde }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(inicial) }}</td></tr>
          <tr v-for="m in movimientos" :key="m.id" :class="m.url ? 'cursor-pointer' : ''" @click="m.url && $inertia.visit(m.url)">
            <td class="tabular-nums text-marca-muted">{{ m.fecha }}</td><td class="tabular-nums text-xs text-marca-muted">{{ m.asiento }}</td>
            <td><p class="font-medium">{{ m.concepto }}</p><p v-if="m.detalle || m.contacto" class="text-xs text-marca-muted">{{ [m.detalle, m.contacto].filter(Boolean).join(' · ') }}</p></td>
            <td class="text-right tabular-nums">{{ m.debe ? moneda(m.debe) : '' }}</td><td class="text-right tabular-nums">{{ m.haber ? moneda(m.haber) : '' }}</td>
            <td class="text-right tabular-nums font-semibold" :class="m.saldo < 0 ? 'text-carmin' : ''">{{ moneda(m.saldo) }}</td>
          </tr>
          <tr v-if="!movimientos.length"><td colspan="6" class="text-center text-marca-muted py-8">Sin movimientos en el período.</td></tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { moneda } from '@/util/formato'
defineProps({ cuentas: Array, cuentaId: Number, cuenta: Object, periodo: Object, inicial: Number, movimientos: Array, final: Number, deudora: Boolean })
</script>
