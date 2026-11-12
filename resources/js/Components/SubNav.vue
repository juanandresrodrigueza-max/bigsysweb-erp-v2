<template>
  <!-- Fase 80: la sub-navegación estándar de cada módulo. Columna a la izquierda en la compu, chips con scroll en el celular.
       Un solo nav en el DOM (cambia el layout con CSS) para que los data-ir-* de las pruebas apunten a un único elemento. -->
  <nav :aria-label="`Secciones de ${subnav.titulo}`" data-subnav
       class="flex gap-1 overflow-x-auto -mx-4 px-4 pb-2 mb-3 md:mx-0 md:px-0 md:pb-0 md:mb-0 md:block md:w-48 lg:w-52 md:shrink-0 md:sticky md:top-20 md:self-start md:overflow-visible">
    <Link :href="subnav.ruta" class="hidden md:flex items-center gap-2 px-3 pb-3 mb-1 border-b border-marca-borde text-marca-texto">
      <Icono :nombre="subnav.icono" clase="w-5 h-5 text-carmin" /><span class="font-extrabold tracking-tight">{{ subnav.titulo }}</span>
    </Link>
    <template v-for="(grupo, gi) in grupos" :key="gi">
      <p v-if="grupo.label" class="hidden md:block px-3 pt-4 pb-1 text-[10px] font-bold uppercase tracking-widest text-marca-muted">{{ grupo.label }}</p>
      <span v-else-if="gi > 0" class="md:hidden w-px shrink-0 bg-marca-borde mx-1"></span>
      <Link v-for="it in grupo.items" :key="it.key" :href="it.ruta" :title="it.ayuda || undefined"
            :data-e2e="`ir-${it.key}`" v-bind="{ [`data-ir-${it.key}`]: '' }"
            class="whitespace-nowrap text-sm transition shrink-0 rounded-full px-3 py-1.5 border md:border-0 md:rounded-xl md:flex md:items-center md:gap-2 md:w-full md:my-0.5"
            :class="it.activo ? 'bg-carmin text-white border-carmin md:bg-white md:text-carmin md:font-semibold md:shadow-sm md:ring-1 md:ring-marca-borde' : 'bg-white border-marca-borde text-marca-texto md:bg-transparent md:text-marca-muted hover:md:text-marca-texto hover:md:bg-white/70'"
            :aria-current="it.activo ? 'page' : undefined">
        <span v-if="it.activo" class="hidden md:block w-1 h-4 rounded-full bg-carmin -ml-1"></span>{{ it.label }}
      </Link>
    </template>
  </nav>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'
const props = defineProps({ subnav: { type: Object, required: true } })
// Los ítems vienen planos con 'grupo'; acá se arman tramos consecutivos (el orden manda, como en el menú principal).
const grupos = computed(() => {
  const out = []
  for (const it of props.subnav.items ?? []) {
    const g = it.grupo || ''
    if (!out.length || out[out.length - 1].label !== g) out.push({ label: g, items: [] })
    out[out.length - 1].items.push(it)
  }
  return out
})
</script>
