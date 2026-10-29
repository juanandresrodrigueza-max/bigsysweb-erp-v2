<template>
  <!-- Fase 32.2: la pantalla que mira el cliente en el segundo monitor de la caja. No hay nada que tocar: escucha a la caja por BroadcastChannel. -->
  <div class="h-screen w-screen overflow-hidden bg-[#1b1430] text-white flex select-none" style="font-family: Montserrat, system-ui, sans-serif">
    <div class="flex-1 flex flex-col p-8 min-w-0">
      <div class="flex items-center gap-3 text-sm opacity-70"><img v-if="logo" :src="logo" class="h-8 rounded" /><span class="font-bold tracking-wide">{{ empresa }}</span><span v-if="!conectada" class="ml-auto text-amber-300">esperando a la caja…</span></div>
      <div class="flex-1 flex flex-col justify-center">
        <template v-if="e.gracias">
          <p class="text-3xl opacity-80">¡Gracias por su compra!</p>
          <p class="text-[7rem] leading-none font-black tabular-nums mt-4" data-e2e="total">{{ moneda(e.total) }}</p>
          <p v-if="e.vuelto > 0" class="text-4xl mt-6">Su vuelto: <b class="tabular-nums">{{ moneda(e.vuelto) }}</b></p>
        </template>
        <template v-else-if="e.items > 0 || e.cobrando">
          <div v-if="e.ultimo" class="mb-8 rounded-3xl bg-white/10 p-6" data-e2e="ultimo">
            <p class="text-xs uppercase tracking-widest opacity-60">Último producto</p>
            <p class="text-4xl font-extrabold leading-tight mt-1" style="text-wrap: balance">{{ e.ultimo.nombre }}</p>
            <p class="text-2xl mt-1 tabular-nums opacity-90">{{ cantidad(e.ultimo.cantidad) }} × {{ moneda(e.ultimo.precio) }}</p>
            <p v-if="e.ultimo.promo" class="inline-block mt-3 rounded-full bg-amber-400 text-[#1b1430] font-black px-4 py-1 text-lg">{{ e.ultimo.promo }}</p>
          </div>
          <p class="text-xs uppercase tracking-widest opacity-60">Total · {{ e.items }} artículo{{ e.items === 1 ? '' : 's' }}</p>
          <p class="text-[7rem] leading-none font-black tabular-nums" data-e2e="total">{{ moneda(e.total) }}</p>
          <div v-if="e.cobrando" class="mt-6 flex gap-10 text-3xl">
            <p>Paga con <b class="tabular-nums">{{ moneda(e.pagado) }}</b></p>
            <p v-if="e.vuelto > 0" class="text-emerald-300">Vuelto <b class="tabular-nums">{{ moneda(e.vuelto) }}</b></p>
          </div>
        </template>
        <template v-else>
          <p class="text-5xl font-extrabold" style="text-wrap: balance">¡Bienvenido!</p>
          <p class="text-2xl opacity-70 mt-3">Pasá tus productos por la caja.</p>
        </template>
      </div>
      <p class="text-xs opacity-40">{{ hora }}</p>
    </div>
    <!-- La cartelera: se turna sola -->
    <div v-if="cartelera.length" class="w-[38%] h-full bg-black relative" data-e2e="cartelera">
      <template v-for="(c, i) in cartelera" :key="c.id">
        <img v-if="c.tipo === 'imagen'" v-show="i === idx" :src="c.url" class="absolute inset-0 w-full h-full object-contain" />
        <video v-else v-show="i === idx" :src="c.url" class="absolute inset-0 w-full h-full object-contain" autoplay muted loop playsinline></video>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue'
import { moneda, cantidad } from '@/util/formato'
const props = defineProps({ vertical: String, empresa: String, logo: String, cartelera: { type: Array, default: () => [] }, segundos: { type: Number, default: 8 } })
const e = reactive({ total: 0, items: 0, ultimo: null, pagado: 0, vuelto: 0, cobrando: false, gracias: false })
const conectada = ref(false), idx = ref(0), hora = ref('')
let canal = null, rot = null, reloj = null, graciasTimer = null
onMounted(() => {
  try { canal = new BroadcastChannel(`pos-cliente-${props.vertical}`); canal.onmessage = ev => { conectada.value = true; Object.assign(e, { gracias: false }, ev.data); if (ev.data.gracias) { clearTimeout(graciasTimer); graciasTimer = setTimeout(() => { e.gracias = false; e.total = 0; e.items = 0; e.ultimo = null }, 15000) } }; canal.postMessage({ hola: true }) } catch (err) {}
  rot = setInterval(() => { if (props.cartelera.length) idx.value = (idx.value + 1) % props.cartelera.length }, Math.max(3, props.segundos) * 1000)
  const tick = () => (hora.value = new Date().toLocaleString('es-AR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })); tick(); reloj = setInterval(tick, 15000)
})
onBeforeUnmount(() => { canal?.close(); clearInterval(rot); clearInterval(reloj); clearTimeout(graciasTimer) })
</script>
