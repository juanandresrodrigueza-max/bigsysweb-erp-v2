<template>
  <AppLayout titulo="Qué pagarle a cada uno">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/servicios" class="text-xs text-marca-muted hover:text-carmin">← Servicio técnico</Link><h1 class="page-title">Qué pagarle a cada {{ config.tecnico.toLowerCase() }}</h1><p class="page-subtitle">Su parte de la mano de obra aprobada de las órdenes entregadas en el período, por el {{ pago.pct }} % que se paga.</p></div>
      <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" />
    </div>
    <div class="card p-0 overflow-x-auto" data-e2e="pago">
      <table class="table text-sm"><thead><tr><th>{{ config.tecnico }}</th><th class="text-right">Órdenes</th><th class="text-right">Mano de obra</th><th class="text-right">A pagar</th></tr></thead>
        <tbody><template v-for="t in pago.tecnicos" :key="t.user_id"><tr class="cursor-pointer hover:bg-gris-light" @click="abierto = abierto === t.user_id ? null : t.user_id"><td class="font-medium">{{ t.tecnico }}</td><td class="text-right tabular-nums">{{ t.ordenes }}</td><td class="text-right tabular-nums">{{ moneda(t.mano_obra) }}</td><td class="text-right tabular-nums font-bold">{{ moneda(t.a_pagar) }}</td></tr>
          <tr v-if="abierto === t.user_id"><td colspan="4" class="bg-gris-light/50 text-xs"><p v-for="(d, i) in t.detalle" :key="i">{{ d.ot }} · {{ d.trabajo }} · {{ d.pct }} % · {{ moneda(d.monto) }}</p></td></tr></template>
          <tr v-if="!pago.tecnicos.length"><td colspan="4" class="text-center text-marca-muted py-8">No hay órdenes entregadas con mano de obra en el período.</td></tr></tbody></table>
    </div>
  </AppLayout>
</template>
<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { moneda } from '@/util/formato'
defineProps({ periodo: Object, pago: Object, config: Object })
const abierto = ref(null)
</script>
