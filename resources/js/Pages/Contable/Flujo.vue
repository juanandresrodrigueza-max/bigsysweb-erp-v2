<template>
  <AppLayout titulo="Flujo de fondos">
    <div class="mb-4"><h1 class="page-title">Flujo de fondos</h1><p class="page-subtitle">Plata que entró y salió de cajas, bancos y billeteras, mes a mes, y lo que viene en los próximos 30 días.</p></div>
    <ContableTabs />

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Disponible hoy</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(disponible, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">A cobrar 30 días</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ moneda(proyeccion.por_cobrar_30 + proyeccion.cheques_cobrar, 0) }}</p><p class="text-[11px] text-marca-muted">facturas {{ moneda(proyeccion.por_cobrar_30, 0) }} · cheques {{ moneda(proyeccion.cheques_cobrar, 0) }}<span v-if="proyeccion.vencido_cobrar"> · vencido {{ moneda(proyeccion.vencido_cobrar, 0) }}</span></p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">A pagar 30 días</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(proyeccion.por_pagar_30 + proyeccion.cheques_pagar, 0) }}</p><p class="text-[11px] text-marca-muted">facturas {{ moneda(proyeccion.por_pagar_30, 0) }} · cheques {{ moneda(proyeccion.cheques_pagar, 0) }}<span v-if="proyeccion.vencido_pagar"> · vencido {{ moneda(proyeccion.vencido_pagar, 0) }}</span></p></div>
      <div class="card py-3" :class="proyectado < 0 ? 'border-carmin/40' : ''"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Proyectado a 30 días</p><p class="text-xl font-extrabold tabular-nums" :class="proyectado < 0 ? 'text-carmin' : ''">{{ moneda(proyectado, 0) }}</p><p class="text-[11px] text-marca-muted">si se cobra y paga todo lo que vence</p></div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Concepto</th><th v-for="m in meses" :key="m.key" class="text-right whitespace-nowrap">{{ m.label }}</th><th class="text-right">Total</th></tr></thead>
        <tbody>
          <tr v-for="f in filas" :key="f.label"><td class="font-medium">{{ f.label }}</td><td v-for="m in meses" :key="m.key" class="text-right tabular-nums" :class="(f.meses[m.key] ?? 0) < 0 ? 'text-carmin' : (f.meses[m.key] ?? 0) > 0 ? 'text-emerald-700' : 'text-marca-muted'">{{ f.meses[m.key] ? moneda(f.meses[m.key], 0) : '—' }}</td><td class="text-right tabular-nums font-semibold" :class="f.total < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(f.total, 0) }}</td></tr>
        </tbody>
        <tfoot><tr class="font-bold bg-marca-fondo"><td>Neto del mes</td><td v-for="m in meses" :key="m.key" class="text-right tabular-nums" :class="neto[m.key] < 0 ? 'text-carmin' : ''">{{ moneda(neto[m.key], 0) }}</td><td class="text-right tabular-nums">{{ moneda(Object.values(neto).reduce((a, b) => a + b, 0), 0) }}</td></tr></tfoot>
      </table>
    </div>
  </AppLayout>
</template>
<script setup>
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ meses: Array, filas: Array, neto: Object, disponible: Number, proyeccion: Object })
const proyectado = computed(() => props.disponible + props.proyeccion.por_cobrar_30 + props.proyeccion.cheques_cobrar - props.proyeccion.por_pagar_30 - props.proyeccion.cheques_pagar)
</script>
