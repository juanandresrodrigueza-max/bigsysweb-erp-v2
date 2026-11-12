<template>
  <!-- Fase 88: el menú con las secciones colgando de cada módulo. Tocar el módulo lo abre y lo despliega; la flecha pliega o despliega sin navegar.
       Los rubros habilitados (Big Restó, Servicio técnico…) son módulos como los demás, con sus pantallas debajo. -->
  <template v-for="grupo in nav" :key="grupo.label">
    <p v-if="abierto && grupo.label" class="px-4 pt-4 pb-1 text-[10px] font-bold uppercase tracking-widest text-lavanda">{{ grupo.label }}</p>
    <template v-for="item in grupo.items" :key="item.key">
      <div class="relative mx-2 my-0.5 flex items-center rounded-xl transition" :class="activo(item) ? 'bg-white text-violeta font-semibold shadow-sm' : 'text-white/80 hover:bg-white/15 hover:text-white'">
        <span v-if="activo(item)" class="absolute left-0 top-2 bottom-2 w-1 rounded-r bg-carmin"></span>
        <component :is="item.externo ? 'a' : Link" :href="item.ruta" class="flex-1 flex items-center gap-3 px-3 py-2 text-sm min-w-0" :title="item.externo ? item.label + ' (abre el CRM ya logueado)' : item.label" :data-e2e="`menu-${item.key}`" @click="elegir(item)">
          <Icono :nombre="item.icono" clase="w-5 h-5 shrink-0" />
          <span v-if="abierto" class="truncate">{{ item.label }}</span>
          <span v-if="abierto && !item.disponible" class="ml-auto text-[9px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-white/10 text-lavanda">pronto</span>
          <span v-if="abierto && item.externo" class="ml-auto text-[10px] text-lavanda">↗</span>
        </component>
        <button v-if="abierto && secciones(item).length" type="button" class="p-2 mr-1 rounded-lg opacity-70 hover:opacity-100" :class="activo(item) ? 'text-violeta' : 'text-white'" :aria-expanded="desplegado(item)" :aria-label="`${desplegado(item) ? 'Plegar' : 'Desplegar'} ${item.label}`" :data-e2e="`plegar-${item.key}`" @click.stop.prevent="alternar(item)">
          <Icono nombre="chevron" :clase="'w-4 h-4 transition-transform ' + (desplegado(item) ? 'rotate-180' : '')" />
        </button>
      </div>
      <div v-if="abierto && secciones(item).length && desplegado(item)" class="ml-6 mr-2 mb-1 pl-2 border-l border-white/15" :data-secciones="item.key">
        <template v-for="(s, i) in secciones(item)" :key="s.key">
          <p v-if="s.grupo && (i === 0 || secciones(item)[i - 1].grupo !== s.grupo)" class="px-2 pt-2 pb-0.5 text-[9px] font-bold uppercase tracking-widest text-lavanda">{{ s.grupo }}</p>
          <Link :href="s.ruta" class="block px-2 py-1.5 rounded-lg text-[13px] truncate transition" :class="seccionActiva(item, s) ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10'"
                :title="s.ayuda || undefined" :data-e2e="`ir-${s.key}`" v-bind="{ [`data-ir-${s.key}`]: '' }" :aria-current="seccionActiva(item, s) ? 'page' : undefined" @click="$emit('navegar')">{{ s.label }}</Link>
        </template>
      </div>
    </template>
  </template>
</template>

<script setup>
import { ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'

const props = defineProps({ nav: { type: Array, default: () => [] }, abierto: { type: Boolean, default: true }, rutaActiva: { type: String, default: null }, subnav: { type: Object, default: null } })
const emit = defineEmits(['navegar'])

// Un módulo cuya única sección es su propia pantalla (Hotelería → Habitaciones) no despliega nada.
const secciones = item => (item.secciones ?? []).length === 1 && item.secciones[0].ruta === item.ruta ? [] : (item.secciones ?? [])
const activo = item => item.ruta === props.rutaActiva
const seccionActiva = (item, s) => props.subnav?.key === item.key && (props.subnav.items ?? []).some(x => x.key === s.key && x.activo)

// El módulo activo arranca desplegado; el resto se pliega o despliega a mano y queda así mientras se navega.
const abiertos = ref(new Set())
const claveActiva = () => props.nav.flatMap(g => g.items).find(i => i.ruta === props.rutaActiva)?.key
watch(() => props.rutaActiva, () => { const k = claveActiva(); if (k) abiertos.value = new Set([...abiertos.value, k]) }, { immediate: true })
const desplegado = item => abiertos.value.has(item.key)
function alternar(item) { const s = new Set(abiertos.value); s.has(item.key) ? s.delete(item.key) : s.add(item.key); abiertos.value = s }
function elegir(item) { if (!item.externo) abiertos.value = new Set([...abiertos.value, item.key]); emit('navegar') }
</script>
