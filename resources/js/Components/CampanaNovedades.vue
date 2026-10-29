<template>
  <div class="relative" ref="root">
    <button @click="alternar" class="relative p-2 rounded-full hover:bg-marca-fondo text-marca-texto" aria-label="Novedades" data-e2e="novedades">
      <Icono nombre="sparkles" />
      <span v-if="sinLeer" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-violeta text-white text-[10px] font-bold flex items-center justify-center">{{ sinLeer }}</span>
    </button>
    <div v-if="abierto" class="absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] bg-white rounded-2xl shadow-2xl border border-marca-borde overflow-hidden z-40">
      <p class="px-4 py-2.5 text-sm font-bold border-b border-marca-borde">Novedades de BigSys</p>
      <div class="max-h-96 overflow-y-auto divide-y divide-marca-borde/70">
        <p v-if="!lista.length" class="px-4 py-6 text-sm text-marca-muted text-center">{{ cargando ? 'Cargando…' : 'No hay novedades por ahora.' }}</p>
        <div v-for="n in lista" :key="n.id" class="px-4 py-3" :class="{ 'opacity-60': n.leida }" data-e2e="novedad">
          <p class="text-sm font-semibold">{{ n.titulo }} <span class="text-[11px] text-marca-muted font-normal">· {{ n.fecha }}</span></p>
          <p class="text-xs text-marca-muted whitespace-pre-line mt-0.5">{{ n.texto }}</p>
          <div class="flex flex-wrap gap-2 mt-1.5 text-[11px]">
            <Link v-if="n.url" :href="n.url" class="text-carmin font-semibold" @click="marcar(n)">Ver</Link>
            <button v-if="!n.leida" class="text-violeta" @click="marcar(n)">Leída</button>
            <button class="text-marca-muted" @click="marcar(n, 7)" data-e2e="posponer-7">En 7 días</button>
            <button class="text-marca-muted" @click="marcar(n, 30)">En 30 días</button>
          </div>
        </div>
      </div>
      <div class="flex border-t border-marca-borde text-xs font-semibold"><Link href="/avisos-celular" class="flex-1 text-center py-2.5 hover:bg-marca-fondo">Avisos al celular</Link><Link href="/mejoras" class="flex-1 text-center py-2.5 hover:bg-marca-fondo text-carmin border-l border-marca-borde">Mejoras</Link></div>
    </div>
  </div>
</template>

<script setup>
// Fase 40.5: la campanita de novedades de BigSys, con posponer 7 o 30 días.
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'
const props = defineProps({ cantidad: { type: Number, default: 0 } })
const abierto = ref(false), lista = ref([]), cargando = ref(false), sinLeer = ref(props.cantidad), root = ref(null)
watch(() => props.cantidad, v => { sinLeer.value = v })
async function alternar() {
  abierto.value = !abierto.value
  if (abierto.value) { cargando.value = true; try { lista.value = (await window.axios.get('/novedades')).data.novedades } finally { cargando.value = false } }
}
async function marcar(n, dias) {
  const { data } = await window.axios.post(`/novedades/${n.id}`, dias ? { posponer: dias } : {})
  sinLeer.value = data.sin_leer
  if (dias) lista.value = lista.value.filter(x => x.id !== n.id); else n.leida = true
}
const fuera = e => { if (root.value && !root.value.contains(e.target)) abierto.value = false }
onMounted(() => document.addEventListener('click', fuera)); onBeforeUnmount(() => document.removeEventListener('click', fuera))
</script>
