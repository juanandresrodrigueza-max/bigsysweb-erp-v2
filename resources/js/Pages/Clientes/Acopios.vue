<template>
  <AppLayout titulo="Acopios">
    <!-- Fase 35.6: lo que el cliente tiene para retirar no es deuda. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/clientes/cobranzas" class="text-xs text-marca-muted hover:text-carmin">← Cobranzas</Link><h1 class="page-title">Acopios</h1><p class="page-subtitle">Lo que cada cliente tiene para retirar, separado de lo que debe de verdad. En plata y en kilos.</p></div>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4" data-e2e="totales">
      <div class="card py-3"><p class="lbl">Para retirar</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(totales.pendiente, 0) }}</p><p class="text-xs text-marca-muted">{{ totales.clientes }} clientes</p></div>
      <div class="card py-3"><p class="lbl">Lo que deben de verdad</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(totales.debe, 0) }}</p><p class="text-xs text-marca-muted">sin contar el acopio</p></div>
      <div class="card py-3"><p class="lbl">Artículos sin stock para entregar</p><p class="text-xl font-extrabold tabular-nums" :class="totales.articulos_con_faltante ? 'text-carmin' : ''">{{ totales.articulos_con_faltante }}</p></div>
      <div class="card py-3"><p class="lbl">Avisos</p><p class="text-xl font-extrabold tabular-nums">{{ avisos.length }}</p></div>
    </div>
    <div v-if="avisos.length" class="card mb-4" data-e2e="avisos"><h2 class="font-bold mb-1">Lo que te avisa</h2>
      <p v-for="(a, i) in avisos" :key="i" class="text-sm py-0.5"><span class="inline-block w-2.5 h-2.5 rounded-full mr-2" :class="{ rojo: 'bg-carmin', naranja: 'bg-orange-400', amarillo: 'bg-amber-300' }[a.nivel]"></span><Link :href="`/clientes/${a.contact_id}`" class="font-semibold">{{ a.cliente }}</Link>: {{ a.texto }}</p></div>
    <div class="grid lg:grid-cols-[1fr_360px] gap-4">
      <div class="card p-0 overflow-x-auto" data-e2e="clientes">
        <table class="table text-sm"><thead><tr><th>Cliente</th><th class="text-right">Acopiado</th><th class="text-right">Retirado</th><th class="text-right">Para retirar</th><th class="text-right">Debe</th></tr></thead>
          <tbody><template v-for="c in clientes" :key="c.id"><tr class="cursor-pointer hover:bg-gris-light" @click="abierto = abierto === c.id ? null : c.id"><td class="font-medium">{{ c.cliente }}</td><td class="text-right tabular-nums">{{ moneda(c.acopiado, 0) }}</td><td class="text-right tabular-nums">{{ moneda(c.retirado, 0) }}</td><td class="text-right tabular-nums font-bold">{{ moneda(c.pendiente, 0) }}</td><td class="text-right tabular-nums" :class="c.debe ? 'text-carmin' : 'text-marca-muted'">{{ moneda(c.debe, 0) }}</td></tr>
            <tr v-if="abierto === c.id"><td colspan="5" class="bg-gris-light/50"><div v-for="a in c.acopios" :key="a.id" class="mb-2"><p class="text-xs font-semibold">Acopio #{{ a.id }} · {{ a.fecha }} · {{ a.factura }} · {{ a.estado }}<span v-if="a.ultimo_retiro"> · último retiro {{ a.ultimo_retiro }}</span></p>
              <table class="table text-xs"><thead><tr><th>Qué le queda por retirar</th><th class="text-right">Acopiado</th><th class="text-right">Retirado</th><th class="text-right">Le queda</th></tr></thead><tbody><tr v-for="(it, i) in a.items" :key="i" :class="it.negativo ? 'text-carmin' : ''"><td>{{ it.articulo }}</td><td class="text-right tabular-nums">{{ it.acopiado }} {{ it.unidad }}</td><td class="text-right tabular-nums">{{ it.retirado }}</td><td class="text-right tabular-nums font-bold">{{ it.pendiente }} {{ it.unidad }}</td></tr></tbody></table>
              <Link :href="`/clientes/${c.id}#acopios`" class="text-xs text-violeta font-semibold">Armar retiro en la ficha →</Link></div></td></tr></template>
            <tr v-if="!clientes.length"><td colspan="5" class="text-center text-marca-muted py-8">No hay acopios. Se crean al facturar con "Es acopio".</td></tr></tbody></table>
      </div>
      <div class="card self-start" data-e2e="comprometido"><h2 class="font-bold mb-1">Stock comprometido en acopio</h2><p class="text-xs text-marca-muted mb-2">Lo que ya vendiste y todavía está acá. Disponible = stock − comprometido.</p>
        <div v-for="a in articulos" :key="a.id" class="flex justify-between text-sm border-t border-marca-borde py-1"><span>{{ a.articulo }}</span><span class="tabular-nums text-right"><span class="text-xs text-marca-muted">stock {{ a.stock }} · comprometido {{ a.comprometido }}</span><b class="block" :class="a.falta ? 'text-carmin' : ''">{{ a.disponible }} {{ a.unidad }}</b></span></div>
        <p v-if="!articulos.length" class="text-sm text-marca-muted">Nada comprometido.</p></div>
    </div>
  </AppLayout>
</template>
<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
defineProps({ clientes: Array, articulos: Array, avisos: Array, totales: Object })
const abierto = ref(null)
</script>
<style scoped>.lbl { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: #6b6b6b }</style>
