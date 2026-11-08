<template>
  <Head title="Cocina" />
  <div class="min-h-screen p-4 md:p-6" style="background:#1c1a18;color:#fff">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
      <div class="flex items-center gap-3"><Logo negativo clase="h-7" /><h1 class="text-2xl font-extrabold">{{ estacion ?? 'Cocina' }}</h1><span class="text-white/60 text-sm">{{ grupos.length }} comanda{{ grupos.length === 1 ? '' : 's' }} · {{ totalItems }} platos · {{ terminados }} entregados hoy</span></div>
      <div class="flex items-center gap-3 text-sm"><span class="text-white/50">{{ hora }}</span><Link href="/gastronomia" class="px-3 py-1.5 rounded-full bg-white/10 hover:bg-white/20 text-xs font-semibold">Volver al salón</Link></div>
    </div>
    <div class="flex flex-wrap items-center gap-2 mb-4" data-e2e="estaciones">
      <Link href="/gastronomia/cocina" class="px-3 py-1.5 rounded-full text-sm font-semibold" :class="!estacion ? 'bg-white text-marca-texto' : 'bg-white/10 hover:bg-white/20'">Todas</Link>
      <Link v-for="e in estaciones" :key="e.nombre" :href="`/gastronomia/cocina?estacion=${encodeURIComponent(e.nombre)}`" class="px-3 py-1.5 rounded-full text-sm font-semibold" :class="estacion === e.nombre ? 'bg-white text-marca-texto' : 'bg-white/10 hover:bg-white/20'">{{ e.nombre }}<span v-if="promedios[e.nombre] !== undefined" class="opacity-60 font-normal"> · prom. {{ promedios[e.nombre] }}'</span></Link>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
      <div v-for="g in grupos" :key="g.comanda_id" class="rounded-2xl overflow-hidden flex flex-col" :class="demorado(g) ? 'ring-2 ring-carmin' : ''" style="background:#292724">
        <div class="px-4 py-3 flex items-center justify-between" :class="demorado(g) ? 'bg-carmin' : g.items.some(i => i.estado === 'cocina' && i.minutos >= i.alerta / 2) ? 'bg-amber-600' : 'bg-violeta'">
          <div><p class="font-extrabold text-lg leading-tight">{{ g.titulo }}</p><p class="text-xs opacity-80">{{ g.mozo }} · enviado {{ g.enviado }}</p></div>
          <span class="text-2xl font-black tabular-nums">{{ g.minutos }}'</span>
        </div>
        <div class="flex-1 divide-y divide-white/10">
          <div v-for="it in g.items" :key="it.id" class="px-4 py-3 flex items-center gap-3" :class="it.estado === 'listo' ? 'opacity-50' : ''">
            <span class="text-2xl font-black tabular-nums w-10 text-right">{{ cantidad(it.cantidad) }}</span>
            <div class="flex-1"><p class="font-semibold text-lg leading-tight" :class="it.estado === 'listo' ? 'line-through' : ''">{{ it.descripcion }}</p><p v-if="it.notas" class="text-sm text-amber-300 font-semibold">⚠ {{ it.notas }}</p><p class="text-[10px] uppercase" :class="it.estado === 'cocina' && it.minutos >= it.alerta ? 'text-carmin font-bold' : 'text-white/50'"><span v-if="!estacion">{{ it.estacion }} · </span>{{ it.minutos }}'<span v-if="it.estado === 'cocina' && it.minutos >= it.alerta"> · demorado</span><span v-if="it.ronda > 1"> · Pedido {{ it.ronda }}</span></p></div>
            <Link v-if="it.estado === 'cocina'" :href="`/gastronomia/items/${it.id}/estado`" method="post" :data="{ estado: 'listo' }" as="button" preserve-scroll class="px-3 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-white font-bold text-sm">Listo</Link>
            <span v-else class="text-xs text-emerald-400 font-bold">✓ {{ it.listo ?? '' }}</span>
          </div>
        </div>
        <button v-if="g.items.some(i => i.estado === 'cocina')" @click="todoListo(g)" class="m-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-sm font-semibold">Todo listo</button>
      </div>
      <div v-if="!grupos.length" class="col-span-full text-center py-24 text-white/50"><p class="text-3xl font-extrabold text-white/80">Nada pendiente</p><p>Cuando el salón envíe un pedido aparece acá solo.</p></div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
import { cantidad } from '@/util/formato'
const props = defineProps({ grupos: Array, terminados: Number, estaciones: { type: Array, default: () => [] }, estacion: { type: String, default: null }, promedios: { type: Object, default: () => ({}) } })
const totalItems = computed(() => props.grupos.reduce((a, g) => a + g.items.filter(i => i.estado === 'cocina').length, 0))
const hora = ref(new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' }))
const demorado = g => g.items.some(i => i.estado === 'cocina' && i.minutos >= i.alerta)
function todoListo(g) { const pend = g.items.filter(i => i.estado === 'cocina'); pend.forEach((it, idx) => router.post(`/gastronomia/items/${it.id}/estado`, { estado: 'listo' }, { preserveScroll: true, only: idx === pend.length - 1 ? undefined : [] })) }
let t1, t2
onMounted(() => { t1 = setInterval(() => router.reload({ only: ['grupos', 'terminados', 'promedios'] }), 10000); t2 = setInterval(() => (hora.value = new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })), 30000) })
onBeforeUnmount(() => { clearInterval(t1); clearInterval(t2) })
</script>
