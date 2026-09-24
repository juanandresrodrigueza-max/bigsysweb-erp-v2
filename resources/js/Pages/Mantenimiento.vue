<template>
  <Head title="Mantenimiento" />
  <div class="min-h-screen flex items-center justify-center p-6 text-white text-center" style="background: linear-gradient(150deg,#e4003f 0%,#a42785 60%,#4f3089 100%)">
    <div class="max-w-md">
      <Logo negativo clase="h-10 mx-auto mb-8" />
      <div class="text-6xl mb-4">🛠️</div>
      <h1 class="text-3xl font-black tracking-tight" style="text-wrap: balance">Estamos haciendo mejoras</h1>
      <p class="mt-3 text-white/90">{{ mensaje }}</p>
      <p v-if="hasta" class="mt-2 text-sm text-white/70">Volvemos aproximadamente: <b>{{ hasta }}</b></p>
      <p class="mt-8 text-xs text-white/60">Tus datos están a salvo. No hace falta que hagas nada.</p>
      <button class="mt-4 px-4 py-2 rounded-full bg-white/15 hover:bg-white/25 text-sm font-semibold" @click="location.reload()">Reintentar</button>
    </div>
  </div>
</template>

<script setup>
import { Head } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
defineProps({ mensaje: String, hasta: String })
</script>
