<template>
  <AppLayout titulo="Novedades de facturación">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Novedades de facturación</h1><p class="page-subtitle">Cada vez que alguien facturó con un precio distinto al de lista, queda registrado acá: quién, cuánto y en qué comprobante.</p></div>
      <div class="flex gap-2 items-end">
        <select :value="$page.url.includes('usuario=') ? new URL($page.url, 'http://x').searchParams.get('usuario') : ''" class="input" @change="router.get('/comprobantes/novedades', { desde: periodo.desde, hasta: periodo.hasta, usuario: $event.target.value })"><option value="">Todos los usuarios</option><option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option></select>
        <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" />
      </div>
    </div>
    <div class="grid sm:grid-cols-3 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Precios modificados</p><p class="text-2xl font-extrabold tabular-nums">{{ resumen.n }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Diferencia contra lista</p><p class="text-2xl font-extrabold tabular-nums" :class="resumen.diferencia < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(resumen.diferencia, 0) }}</p><p class="text-[11px] text-marca-muted">negativo = se vendió más barato que la lista</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Por usuario</p><p class="text-xs" v-for="u in resumen.usuarios" :key="u.usuario"><b>{{ u.usuario }}</b>: {{ u.n }} · <span class="tabular-nums" :class="u.diferencia < 0 ? 'text-carmin' : ''">{{ moneda(u.diferencia, 0) }}</span></p></div>
    </div>
    <div class="card p-0 overflow-x-auto">
      <table class="table text-xs">
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Artículo</th><th class="text-right">Lista</th><th class="text-right">Facturado</th><th class="text-right">Cant.</th><th class="text-right">Diferencia</th><th>Detalle</th></tr></thead>
        <tbody>
          <tr v-for="n in novedades.data" :key="n.id" class="cursor-pointer" @click="n.url && $inertia.visit(n.url)"><td class="tabular-nums whitespace-nowrap">{{ n.fecha }}</td><td>{{ n.usuario }}</td><td class="font-medium">{{ n.articulo }}</td><td class="text-right tabular-nums">{{ moneda(n.lista) }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(n.facturado) }}</td><td class="text-right tabular-nums">{{ cantidad(n.cantidad) }}</td><td class="text-right tabular-nums font-bold" :class="n.diferencia < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(n.diferencia) }}</td><td class="text-marca-muted">{{ n.descripcion }}</td></tr>
          <tr v-if="!novedades.data.length"><td colspan="8" class="text-center text-marca-muted py-8">Nadie modificó precios al facturar en el período.</td></tr>
        </tbody>
      </table>
      <Paginacion v-bind="novedades" />
    </div>
  </AppLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda, cantidad } from '@/util/formato'
defineProps({ periodo: Object, resumen: Object, novedades: Object, usuarios: Array })
</script>
