<template>
  <AppLayout titulo="Obras">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Obras y proyectos</h1><p class="page-subtitle">Presupuesto contra costo real, avance, certificaciones y margen de cada obra.</p></div>
      <button class="btn-primary" @click="modal = true">Nueva obra</button>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En curso</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.en_curso }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Cartera (presupuestos)</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.cartera, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Avance sin certificar</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.por_certificar ? 'text-carmin' : ''">{{ moneda(kpis.por_certificar, 0) }}</p><p class="text-xs text-marca-muted">para facturar</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Desvío de costos</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.desvio > 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(kpis.desvio, 0) }}</p><p class="text-xs text-marca-muted">gastado vs. lo previsto al avance</p></div>
    </div>
    <div class="flex flex-wrap gap-2 mb-3">
      <Link href="/obras" class="px-3 py-1.5 rounded-full text-xs font-semibold border" :class="!filtros.estado ? 'bg-violeta text-white border-violeta' : 'border-marca-borde text-marca-muted'">Activas</Link>
      <Link v-for="(l, k) in estados" :key="k" :href="`/obras?estado=${k}`" class="px-3 py-1.5 rounded-full text-xs font-semibold border" :class="filtros.estado === k ? 'bg-violeta text-white border-violeta' : 'border-marca-borde text-marca-muted'">{{ l }}</Link>
    </div>
    <div class="card p-0 overflow-x-auto">
      <table class="table text-sm">
        <thead><tr><th>Obra</th><th>Cliente</th><th>Estado</th><th>Avance</th><th class="text-right">Presup. venta</th><th class="text-right">Costo previsto</th><th class="text-right">Costo real</th><th class="text-right">Facturado</th><th class="text-right">Margen proy.</th></tr></thead>
        <tbody>
          <tr v-for="o in obras" :key="o.id" class="cursor-pointer hover:bg-gris-light/40" @click="router.visit(`/obras/${o.id}`)">
            <td><p class="font-medium">{{ o.codigo }} · {{ o.nombre }}</p><p class="text-xs text-marca-muted">{{ o.responsable || 'Sin responsable' }}<span v-if="o.fin_prevista"> · entrega {{ o.fin_prevista }}</span><span v-if="o.atrasada" class="text-carmin font-semibold"> · atrasada</span></p></td>
            <td class="text-xs">{{ o.cliente }}</td>
            <td><span class="badge" :class="{ 'bg-gris-light text-marca-muted': o.estado === 'presupuestado', 'bg-violeta/10 text-violeta': o.estado === 'en_curso', 'bg-amber-50 text-amber-700': o.estado === 'pausado', 'bg-emerald-50 text-emerald-700': o.estado === 'terminado', 'bg-red-50 text-carmin': o.estado === 'cancelado' }">{{ estados[o.estado] }}</span></td>
            <td><div class="flex items-center gap-2"><div class="w-20 h-1.5 rounded-full bg-gris-light overflow-hidden relative"><div class="h-full bg-violeta" :style="{ width: o.avance + '%' }"></div><div class="absolute top-0 h-full w-0.5 bg-carmin" :style="{ left: o.avance_certificado + '%' }" title="certificado"></div></div><span class="tabular-nums text-xs">{{ o.avance }}%</span></div></td>
            <td class="text-right tabular-nums">{{ moneda(o.presupuesto_venta, 0) }}</td><td class="text-right tabular-nums text-marca-muted">{{ moneda(o.presupuesto_costo, 0) }}</td>
            <td class="text-right tabular-nums" :class="o.desvio > 0 ? 'text-carmin font-semibold' : ''">{{ moneda(o.costo_real, 0) }}</td><td class="text-right tabular-nums">{{ moneda(o.facturado, 0) }}</td>
            <td class="text-right tabular-nums font-bold" :class="o.margen_proyectado < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(o.margen_proyectado, 0) }}</td>
          </tr>
          <tr v-if="!obras.length"><td colspan="9" class="text-center text-marca-muted py-8">Sin obras. Creá la primera con el botón de arriba.</td></tr>
        </tbody>
      </table>
    </div>
    <ObraModal :abierto="modal" :clientes="clientes" :usuarios="usuarios" :estados="estados" @cerrar="modal = false" />
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ObraModal from '@/Pages/Obras/ObraModal.vue'
import { moneda } from '@/util/formato'
defineProps({ obras: Array, estados: Object, filtros: Object, clientes: Array, usuarios: Array, kpis: Object })
const modal = ref(false)
</script>
