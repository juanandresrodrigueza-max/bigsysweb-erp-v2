<template>
  <AppLayout titulo="Primeros pasos">
    <div class="max-w-3xl mx-auto">
      <div class="rounded-2xl p-6 text-white mb-5" style="background: linear-gradient(135deg,#e4003f 0%,#a42785 100%)">
        <p class="text-xs font-bold uppercase tracking-widest text-white/80">Primeros pasos</p>
        <h1 class="text-2xl font-black mt-1" style="text-wrap: balance">{{ completado ? 'Todo configurado' : `Vas ${hechos} de ${total}` }}</h1>
        <p class="text-sm text-white/90 mt-1">Con esto la empresa queda lista para facturar, cobrar y controlar stock. Podés hacerlo en el orden que quieras y volver cuando necesites.</p>
        <div class="h-2 rounded-full bg-white/25 mt-4"><div class="h-2 rounded-full bg-white" :style="{ width: (hechos / total * 100) + '%' }"></div></div>
        <div class="flex flex-wrap gap-2 mt-4"><Link href="/ayuda/implementacion" class="px-3 py-1.5 rounded-full bg-white text-violeta text-xs font-bold">Guía de implementación día por día</Link><a href="/ayuda/manual" target="_blank" class="px-3 py-1.5 rounded-full bg-white/20 text-white text-xs font-bold">Manual completo</a></div>
      </div>

      <div class="space-y-2">
        <div v-for="(p, i) in pasos" :key="p.key" class="card flex items-start gap-4" :class="p.hecho ? 'opacity-75' : ''">
          <button class="w-8 h-8 rounded-full grid place-items-center shrink-0 font-bold text-sm" :class="p.hecho ? 'bg-emerald-500 text-white' : 'bg-marca-fondo text-marca-muted border border-marca-borde'" :title="p.hecho ? 'Marcar como pendiente' : 'Marcar como hecho'" @click="router.post('/primeros-pasos/marcar', { paso: p.key, hecho: !p.hecho }, { preserveScroll: true })">{{ p.hecho ? '✓' : i + 1 }}</button>
          <div class="flex-1 min-w-0"><p class="font-bold" :class="p.hecho ? 'line-through' : ''">{{ p.titulo }} <span v-if="p.opcional" class="badge bg-gris-light text-marca-muted ml-1">opcional</span></p><p class="text-sm text-marca-muted">{{ p.desc }}</p></div>
          <div class="flex flex-col gap-1 shrink-0"><Link :href="p.url" class="btn-secondary !py-1.5 text-xs text-center">{{ p.hecho ? 'Ver' : 'Ir' }}</Link><Link v-if="p.ayuda" :href="p.ayuda.startsWith('guia-') ? `/ayuda/guia/${p.ayuda.slice(5)}` : `/ayuda/${p.ayuda}`" class="text-[11px] text-violeta font-semibold text-center">¿Cómo?</Link></div>
        </div>
      </div>

      <div class="card mt-5 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm"><p class="font-bold">¿Necesitás una mano?</p><p class="text-marca-muted">WhatsApp {{ soporte.whatsapp }} · {{ soporte.email }} · o abrí un ticket desde <Link href="/soporte" class="underline">Soporte</Link>.</p></div>
        <button v-if="!completado" class="btn-primary" @click="router.post('/primeros-pasos/completar')">Listo, ya arranqué</button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
defineProps({ pasos: Array, completado: Boolean, hechos: Number, total: Number, soporte: Object })
</script>
