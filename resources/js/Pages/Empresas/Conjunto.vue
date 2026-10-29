<template>
  <AppLayout :titulo="conjunto.nombre">
    <!-- Fase 39.2: el tablero del conjunto. Suma las empresas y saca lo que se venden entre ellas. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/contador/empresas" class="text-xs text-marca-muted hover:text-carmin">← Mis empresas</Link><h1 class="page-title">{{ conjunto.nombre }}</h1><p class="page-subtitle">{{ empresas.length }} empresas juntas. Las ventas entre ellas (por CUIT) no se cuentan dos veces.</p></div>
      <div class="flex gap-2 items-center"><input type="month" :value="mes" class="input !w-auto !py-1.5" @change="router.get(`/conjuntos/${conjunto.id}`, { mes: $event.target.value })" /><button class="btn-ghost text-carmin text-xs" @click="confirm('¿Borrar el conjunto?') && router.post(`/conjuntos/${conjunto.id}/borrar`)">Borrar</button></div>
    </div>
    <p v-if="sin_acceso" class="rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-2 mb-3">{{ sin_acceso }} empresa{{ sin_acceso === 1 ? '' : 's' }} del conjunto ya no te dan acceso: no se suman.</p>
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4" data-e2e="total">
      <div class="card py-3"><p class="lbl">Ventas del grupo</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(total.ventas_afuera, 0) }}</p><p class="text-xs text-marca-muted">sin {{ moneda(total.entre_grupo, 0) }} entre empresas</p></div>
      <div class="card py-3"><p class="lbl">Compras</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(total.compras, 0) }}</p></div>
      <div class="card py-3"><p class="lbl">Por cobrar</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(total.cobrar, 0) }}</p></div>
      <div class="card py-3"><p class="lbl">Por pagar</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(total.pagar, 0) }}</p></div>
      <div class="card py-3"><p class="lbl">Disponible</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(total.fondos, 0) }}</p></div>
    </div>
    <div v-if="atrasadas.length" class="card mb-4 border-amber-300" data-e2e="atrasadas"><h2 class="font-bold text-sm mb-1">Empresas atrasadas</h2><p v-for="a in atrasadas" :key="a.nombre" class="text-sm"><b>{{ a.nombre }}</b>: {{ a.texto }}</p></div>
    <div class="card p-0 overflow-x-auto mb-4"><table class="table text-sm"><thead><tr><th>Empresa</th><th class="text-right">Ventas</th><th class="text-right">Entre el grupo</th><th class="text-right">Compras</th><th class="text-right">Por cobrar</th><th class="text-right">Por pagar</th><th class="text-right">Disponible</th><th></th></tr></thead>
      <tbody><tr v-for="e in empresas" :key="e.id"><td class="font-medium">{{ e.nombre }}<span class="block text-xs text-marca-muted">{{ e.cuit }} · última venta {{ e.ultima_venta ?? '—' }}</span></td><td class="text-right tabular-nums">{{ moneda(e.ventas, 0) }}</td><td class="text-right tabular-nums text-marca-muted">{{ moneda(e.entre_grupo, 0) }}</td><td class="text-right tabular-nums">{{ moneda(e.compras, 0) }}</td><td class="text-right tabular-nums">{{ moneda(e.cobrar, 0) }}</td><td class="text-right tabular-nums">{{ moneda(e.pagar, 0) }}</td><td class="text-right tabular-nums">{{ moneda(e.fondos, 0) }}</td><td><Link :href="`/empresa/${e.id}`" method="post" as="button" class="text-xs text-violeta font-semibold">Entrar</Link></td></tr></tbody></table></div>
    <div v-if="entre.length" class="card text-sm"><h2 class="font-bold mb-1">Ventas entre empresas</h2><p v-for="(x, i) in entre" :key="i">{{ x.de }} → {{ x.a }}: {{ moneda(x.monto) }}</p></div>
  </AppLayout>
</template>
<script setup>
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
defineProps({ conjunto: Object, mes: String, empresas: Array, entre: Array, total: Object, atrasadas: Array, sin_acceso: Number, empresasAcc: Array, elegidas: Array })
</script>
<style scoped>.lbl { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: #6b6b6b }</style>
