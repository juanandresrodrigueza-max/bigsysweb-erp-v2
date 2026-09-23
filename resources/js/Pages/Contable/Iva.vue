<template>
  <AppLayout titulo="Libros IVA">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Libro IVA {{ libro }}</h1><p class="page-subtitle">Listo para el contador o para cargar en AFIP. Las notas de crédito restan.</p></div>
      <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" :extra="{ libro }" />
    </div>
    <ContableTabs />
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1">
        <Link v-for="l in ['ventas', 'compras']" :key="l" :href="`/contable/iva?libro=${l}&desde=${periodo.desde}&hasta=${periodo.hasta}`" class="px-4 py-1.5 rounded-full text-sm font-semibold capitalize" :class="libro === l ? 'bg-carmin text-white' : 'text-marca-muted'">{{ l }}</Link>
      </div>
      <a :href="`/contable/iva?libro=${libro}&desde=${periodo.desde}&hasta=${periodo.hasta}&export=1`" class="btn-secondary">Exportar CSV</a>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Neto gravado</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(totales.neto, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">IVA {{ libro === 'ventas' ? 'débito' : 'crédito' }}</p><p class="text-xl font-extrabold tabular-nums" :class="libro === 'ventas' ? 'text-carmin' : 'text-emerald-700'">{{ moneda(totales.iva, 0) }}</p><p class="text-[11px] text-marca-muted">21%: {{ moneda(totales.iva_21, 0) }} · 10,5%: {{ moneda(totales.iva_105, 0) }} · 27%: {{ moneda(totales.iva_27, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Percepciones</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(totales.percepciones, 0) }}</p><p class="text-[11px] text-marca-muted">exento {{ moneda(totales.exento, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Total</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(totales.total, 0) }}</p><p class="text-[11px] text-marca-muted">{{ filas.length }} comprobantes</p></div>
    </div>
    <div class="card p-0 overflow-x-auto">
      <table class="table text-xs">
        <thead><tr><th>Fecha</th><th>Comprobante</th><th>{{ libro === 'ventas' ? 'Cliente' : 'Proveedor' }}</th><th>CUIT</th><th>Cond.</th><th class="text-right">Neto</th><th class="text-right">IVA 21</th><th class="text-right">IVA 10,5</th><th class="text-right">Percep.</th><th class="text-right">Exento</th><th class="text-right">Total</th></tr></thead>
        <tbody>
          <tr v-for="f in filas" :key="f.id" class="cursor-pointer" :class="f.nc ? 'text-carmin' : ''" @click="$inertia.visit(f.direccion === 'compra' ? `/proveedores/compras/${f.id}` : `/comprobantes/${f.id}`)">
            <td class="tabular-nums whitespace-nowrap">{{ f.fecha }}</td><td class="whitespace-nowrap font-medium">{{ f.tipo }} <span class="tabular-nums">{{ f.numero }}</span></td><td>{{ f.contacto }}</td><td class="tabular-nums">{{ f.cuit }}</td><td class="text-marca-muted">{{ f.condicion.replace('Responsable Inscripto', 'RI').replace('Consumidor Final', 'CF').replace('Monotributista', 'Mono') }}</td>
            <td class="text-right tabular-nums">{{ moneda(f.neto) }}</td><td class="text-right tabular-nums">{{ f.iva_21 ? moneda(f.iva_21) : '' }}</td><td class="text-right tabular-nums">{{ f.iva_105 ? moneda(f.iva_105) : '' }}</td><td class="text-right tabular-nums">{{ f.percepciones ? moneda(f.percepciones) : '' }}</td><td class="text-right tabular-nums">{{ f.exento ? moneda(f.exento) : '' }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(f.total) }}</td>
          </tr>
          <tr v-if="!filas.length"><td colspan="11" class="text-center text-marca-muted py-8">Sin comprobantes en el período.</td></tr>
        </tbody>
        <tfoot v-if="filas.length"><tr class="font-bold bg-marca-fondo"><td colspan="5">Totales</td><td class="text-right tabular-nums">{{ moneda(totales.neto) }}</td><td class="text-right tabular-nums">{{ moneda(totales.iva_21) }}</td><td class="text-right tabular-nums">{{ moneda(totales.iva_105) }}</td><td class="text-right tabular-nums">{{ moneda(totales.percepciones) }}</td><td class="text-right tabular-nums">{{ moneda(totales.exento) }}</td><td class="text-right tabular-nums">{{ moneda(totales.total) }}</td></tr></tfoot>
      </table>
    </div>
  </AppLayout>
</template>
<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { moneda } from '@/util/formato'
defineProps({ libro: String, periodo: Object, filas: Array, totales: Object })
</script>
