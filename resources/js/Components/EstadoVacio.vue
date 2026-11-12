<template>
  <!-- Fase 81: el estado vacío de un listado. Distingue "no hay nada todavía" (con qué hacer) de "nada con estos filtros" (con cómo limpiarlos).
       Va adentro de la celda de la tabla o de la tarjeta; el texto lo pone cada pantalla. -->
  <div class="text-center px-4" :class="compacto ? 'py-6' : 'py-10'" data-estado-vacio>
    <span class="mx-auto mb-2 w-10 h-10 rounded-full grid place-items-center" :class="filtrado ? 'bg-amber-50 text-amber-600' : 'bg-marca-fondo text-marca-muted'">
      <Icono :nombre="filtrado ? 'search' : icono" clase="w-5 h-5" />
    </span>
    <template v-if="filtrado">
      <p class="font-semibold text-marca-texto">Nada con estos filtros</p>
      <p class="text-sm text-marca-muted mt-0.5">Probá con otra búsqueda u otro período.</p>
      <button type="button" class="btn-secondary !py-1 text-xs mt-3" @click="limpiar" data-e2e="limpiar-filtros">Limpiar filtros</button>
    </template>
    <template v-else>
      <p class="text-marca-muted" :class="compacto ? 'text-sm' : ''">{{ texto }}</p>
      <component v-if="accion" :is="accion.href ? Link : 'button'" :href="accion.href" :type="accion.href ? undefined : 'button'" class="btn-primary !py-1.5 text-xs mt-3" @click="accion.click?.()">
        <Icono nombre="plus" clase="w-3.5 h-3.5" />{{ accion.label }}
      </component>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'

const props = defineProps({
  texto: { type: String, default: 'Todavía no hay nada acá.' },
  // El estado de filtros de la pantalla (su reactive). Si alguno está puesto, el vacío es "con estos filtros", no "no hay nada".
  filtros: { type: Object, default: null },
  accion: { type: Object, default: null }, // { label, href | click } sólo cuando de verdad no hay nada
  icono: { type: String, default: 'inbox' },
  compacto: Boolean,
})
const porDefecto = ['', 'todos', 'todas', 'activos', 'activas', 'todo']
const filtrado = computed(() => props.filtros && Object.entries(props.filtros).some(([k, v]) => k !== 'page' && v !== null && v !== undefined && !(Array.isArray(v) && !v.length) && !porDefecto.includes(String(v).toLowerCase())))
// Limpiar = volver a la misma pantalla sin query: la página se monta de nuevo con los filtros por defecto.
function limpiar() { router.visit(window.location.pathname, { preserveState: false, preserveScroll: true }) }
</script>
