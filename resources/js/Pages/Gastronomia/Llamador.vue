<template>
  <Head title="Pedidos listos" />
  <div class="min-h-screen p-4 md:p-8 flex flex-col" style="background:#1c1a18;color:#fff">
    <div class="flex items-center justify-between gap-3 mb-6"><div class="flex items-center gap-3"><Logo negativo clase="h-7" /><h1 class="page-title">Pedidos</h1></div><Link href="/gastronomia" class="px-3 py-1.5 rounded-full bg-white/10 hover:bg-white/20 text-xs font-semibold">Volver al salón</Link></div>
    <div class="grid md:grid-cols-2 gap-6 flex-1">
      <section>
        <h2 class="text-sm font-bold uppercase tracking-widest text-white/50 mb-3">Preparando</h2>
        <div class="flex flex-wrap gap-3"><div v-for="p in preparando" :key="p.id" class="rounded-2xl px-6 py-4 min-w-[120px] text-center" style="background:#292724"><p class="text-4xl font-black tabular-nums">{{ p.corto }}</p><p class="text-xs text-white/60 truncate max-w-[140px]">{{ p.cliente || p.numero }}</p></div></div>
        <p v-if="!preparando.length" class="text-white/40 text-sm">Nada en preparación.</p>
      </section>
      <section>
        <h2 class="text-sm font-bold uppercase tracking-widest text-emerald-400 mb-3">Listos para retirar</h2>
        <div class="flex flex-wrap gap-3"><div v-for="p in listos" :key="p.id" class="rounded-2xl px-6 py-4 min-w-[140px] text-center bg-emerald-500 text-white animate-pulse" :data-listo="p.id"><p class="text-5xl font-black tabular-nums">{{ p.corto }}</p><p class="text-sm font-semibold truncate max-w-[160px]">{{ p.cliente || p.numero }}</p><p v-if="p.avisado" class="text-[10px] opacity-80">avisado por WhatsApp</p>
          <button v-if="puede('gastronomia','crear')" class="mt-2 px-3 py-1 rounded-full bg-white/25 text-xs font-bold" @click="router.post(`/gastronomia/comandas/${p.id}/retirado`, {}, { preserveScroll: true })">Lo retiró</button></div></div>
        <p v-if="!listos.length" class="text-white/40 text-sm">Todavía no hay pedidos listos.</p>
      </section>
    </div>
    <p class="text-center text-white/40 text-xs mt-6">Se actualiza solo cada 10 segundos. Cuando el último plato queda listo, al cliente le llega un WhatsApp si dejó su número.</p>
  </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ pedidos: Array })
const { puede } = usePermisos()
const preparando = computed(() => props.pedidos.filter(p => !p.listo))
const listos = computed(() => props.pedidos.filter(p => p.listo))
let t; onMounted(() => { t = setInterval(() => router.reload({ only: ['pedidos'] }), 10000) }); onBeforeUnmount(() => clearInterval(t))
</script>
