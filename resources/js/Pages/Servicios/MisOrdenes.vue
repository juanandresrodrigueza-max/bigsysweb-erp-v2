<template>
  <AppLayout titulo="Mis órdenes">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/servicios" class="text-xs text-marca-muted hover:text-carmin">← Servicio técnico</Link><h1 class="page-title">Mis órdenes</h1><p class="page-subtitle">Lo que tenés asignado, con el próximo paso de cada una. Lo urgente y lo prometido primero.</p></div>
    </div>
    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3" data-e2e="mis-ordenes">
      <Link v-for="o in ordenes" :key="o.id" :href="`/servicios/${o.id}`" class="card block hover:shadow" :class="o.prioridad === 'alta' ? 'border-carmin/50' : o.atrasada ? 'border-amber-300' : ''">
        <div class="flex justify-between"><b class="tabular-nums">{{ o.numero }}</b><span class="badge bg-gris-light text-xs">{{ estados[o.estado] }}</span></div>
        <p class="font-semibold mt-1">{{ o.equipo }}<span v-if="o.marca_modelo" class="font-normal text-marca-muted"> · {{ o.marca_modelo }}</span></p>
        <p class="text-sm">{{ o.cliente }}<span v-if="o.prometida" class="text-xs" :class="o.atrasada ? 'text-carmin font-bold' : 'text-marca-muted'"> · prometido {{ o.prometida }}</span></p>
        <p v-if="o.que_sigue" class="text-sm text-violeta font-semibold mt-1">→ {{ o.que_sigue }}</p>
      </Link>
      <p v-if="!ordenes.length" class="card text-center text-marca-muted py-10 md:col-span-2 xl:col-span-3">No tenés órdenes abiertas asignadas.</p>
    </div>
  </AppLayout>
</template>
<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
defineProps({ ordenes: Array, estados: Object, config: Object })
</script>
