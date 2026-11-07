<template>
  <AppLayout titulo="Mis repartos">
    <!-- Fase 43.2: el chofer entra y ve sólo sus viajes; cada uno abre la hoja del chofer (salí, entregué con foto, no se pudo). -->
    <h1 class="page-title mb-1">Mis repartos</h1>
    <p class="page-subtitle mb-4">Tocá un viaje para empezarlo. Al entregar, sacale foto al remito firmado.</p>
    <div class="space-y-3" data-e2e="mis-repartos">
      <a v-for="v in viajes" :key="v.id" :href="v.link" class="card block hover:border-carmin/50">
        <div class="flex items-center justify-between"><p class="font-bold text-lg">{{ v.numero }}</p><span class="badge bg-violeta/10 text-violeta">{{ v.estado }}</span></div>
        <p class="text-sm text-marca-muted">{{ v.fecha }} · {{ v.paradas }} paradas<span v-if="v.vehiculo"> · {{ v.vehiculo }}</span></p>
      </a>
      <p v-if="!viajes.length" class="card text-center text-marca-muted">No tenés repartos pendientes.</p>
    </div>
  </AppLayout>
</template>
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
defineProps({ viajes: Array })
</script>
