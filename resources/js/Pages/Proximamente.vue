<template>
  <AppLayout :titulo="modulo.label">
    <div class="max-w-xl mx-auto text-center py-16">
      <span class="inline-flex w-16 h-16 rounded-2xl items-center justify-center text-white mb-5 bg-marca-grad"><Icono :nombre="modulo.icono" clase="w-8 h-8" /></span>
      <h1 class="page-title">{{ modulo.label }}</h1>
      <p class="text-marca-muted mt-2">Este módulo está en construcción. Ya tenés el acceso y los permisos configurados; cuando se publique va a aparecer acá sin que hagas nada.</p>
      <Link href="/dashboard" class="btn-secondary mt-6">Volver al inicio</Link>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
defineProps({ modulo: Object })
</script>
