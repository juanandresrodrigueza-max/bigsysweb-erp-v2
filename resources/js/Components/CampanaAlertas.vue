<template>
  <div class="relative" ref="root">
    <button @click="abierto = !abierto" class="relative p-2 rounded-full hover:bg-marca-fondo text-marca-texto" aria-label="Alertas">
      <Icono nombre="bell" />
      <span v-if="alertas.sin_leer" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-carmin text-white text-[10px] font-bold flex items-center justify-center">{{ alertas.sin_leer > 99 ? '99+' : alertas.sin_leer }}</span>
    </button>
    <div v-if="abierto" class="absolute right-0 mt-2 w-80 bg-white rounded-2xl shadow-2xl border border-marca-borde overflow-hidden z-40">
      <div class="px-4 py-2.5 flex items-center justify-between border-b border-marca-borde">
        <p class="text-sm font-bold">Alertas</p>
        <Link v-if="alertas.sin_leer" href="/alertas/leer-todas" method="post" as="button" preserve-scroll class="text-xs text-violeta hover:underline">Marcar todas leídas</Link>
      </div>
      <div class="max-h-80 overflow-y-auto divide-y divide-marca-borde/70">
        <p v-if="!alertas.ultimas.length" class="px-4 py-6 text-sm text-marca-muted text-center">Sin alertas activas.</p>
        <Link v-for="a in alertas.ultimas" :key="a.id" :href="a.url || '/alertas'" class="flex gap-3 px-4 py-3 hover:bg-marca-fondo" :class="{ 'opacity-60': a.leida }">
          <span class="mt-0.5 w-2 h-2 rounded-full shrink-0" :class="puntoClase(a.severidad)"></span>
          <div class="min-w-0">
            <p class="text-sm font-semibold truncate">{{ a.titulo }}</p>
            <p v-if="a.detalle" class="text-xs text-marca-muted line-clamp-2">{{ a.detalle }}</p>
            <p class="text-[11px] text-marca-muted/80 mt-0.5">{{ a.hace }}</p>
          </div>
        </Link>
      </div>
      <Link href="/alertas" class="block text-center text-xs font-semibold text-carmin py-2.5 border-t border-marca-borde hover:bg-marca-fondo">Ver todas las alertas</Link>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'

defineProps({ alertas: { type: Object, default: () => ({ sin_leer: 0, ultimas: [] }) } })
const abierto = ref(false)
const root = ref(null)

function puntoClase(s) {
  return { critica: 'bg-carmin', aviso: 'bg-amber-500', info: 'bg-violeta' }[s] ?? 'bg-marca-muted'
}
function clickAfuera(e) { if (root.value && !root.value.contains(e.target)) abierto.value = false }
onMounted(() => document.addEventListener('click', clickAfuera))
onBeforeUnmount(() => document.removeEventListener('click', clickAfuera))
</script>
