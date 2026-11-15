<template>
  <Head title="Esperando habilitación · BigSys" />
  <div class="min-h-screen flex items-center justify-center p-6 bg-marca-fondo">
    <div class="w-full max-w-md text-center" data-e2e="esperando-aprobacion">
      <Logo clase="h-9 mb-8 mx-auto" />
      <span class="inline-grid place-items-center w-14 h-14 rounded-2xl bg-lavanda-light text-violeta mb-4"><Icono nombre="clock" clase="w-7 h-7" /></span>
      <h1 class="font-display text-3xl font-medium tracking-tight text-ciruela-900">Casi listo, {{ empresa }}</h1>
      <p class="text-sm text-marca-muted mt-3">{{ mensaje }}</p>
      <div class="mt-6 card text-left text-sm space-y-2">
        <p class="font-semibold">Si querés acelerarlo, escribinos:</p>
        <p v-if="soporte.whatsapp">WhatsApp: <span class="font-semibold select-all">{{ soporte.whatsapp }}</span></p>
        <p v-if="soporte.email">Email: <span class="font-semibold select-all">{{ soporte.email }}</span></p>
        <p class="text-xs text-marca-muted">Tus datos quedaron guardados. Cuando habilitemos la prueba entrás con el mismo email y contraseña.</p>
      </div>
      <Link href="/logout" method="post" as="button" class="btn-secondary mt-6">Cerrar sesión</Link>
    </div>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
import Icono from '@/Components/Icono.vue'
defineProps({ empresa: String, mensaje: String, soporte: { type: Object, default: () => ({}) } })
</script>
