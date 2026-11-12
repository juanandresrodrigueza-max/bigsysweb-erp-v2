<template>
  <!-- Fase 80: la cabecera única de todas las pantallas. Un título, una acción principal, a lo sumo una secundaria visible,
       y lo demás en "Más". Los verbos: Nuevo/a (algo que no existía), Registrar (un hecho: gasto, cobro, pago), Guardar (un formulario). -->
  <div class="flex flex-wrap items-end justify-between gap-3" :class="compacto ? 'mb-3' : 'mb-5'">
    <div class="min-w-0">
      <Link v-if="volver" :href="volver.href" class="text-xs text-marca-muted hover:text-carmin">← {{ volver.label }}</Link>
      <h1 class="page-title">{{ titulo }}</h1>
      <p v-if="subtitulo || $slots.subtitulo" class="page-subtitle"><slot name="subtitulo">{{ subtitulo }}</slot></p>
    </div>
    <div v-if="principal || secundaria || masVisibles.length || $slots.extra" class="flex flex-wrap items-center gap-2">
      <slot name="extra" />
      <div v-if="masVisibles.length" class="relative" ref="masRef">
        <button type="button" class="btn-secondary" :aria-expanded="abierto" aria-haspopup="menu" data-e2e="mas" @click="abierto = !abierto">
          Más <Icono nombre="chevron" clase="w-4 h-4 -mr-1" />
        </button>
        <div v-if="abierto" role="menu" class="absolute right-0 mt-2 w-60 bg-white rounded-2xl shadow-2xl border border-marca-borde overflow-hidden z-40 py-1">
          <component v-for="(it, i) in masVisibles" :key="i" :is="tag(it)" v-bind="attrs(it)" role="menuitem"
                     class="w-full text-left flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-marca-fondo" :class="it.peligro ? 'text-carmin' : 'text-marca-texto'" @click="elegir(it)">
            <Icono v-if="it.icono" :nombre="it.icono" clase="w-4 h-4 text-marca-muted" /><span class="flex-1">{{ it.label }}</span>
          </component>
        </div>
      </div>
      <component v-if="secundaria" :is="tag(secundaria)" v-bind="attrs(secundaria)" class="btn-secondary" @click="elegir(secundaria, false)">
        <Icono v-if="secundaria.icono" :nombre="secundaria.icono" clase="w-4 h-4" />{{ secundaria.label }}
      </component>
      <component v-if="principal" :is="tag(principal)" v-bind="attrs(principal)" class="btn-primary" :data-e2e="principal.e2e || 'accion-principal'" @click="elegir(principal, false)">
        <Icono :nombre="principal.icono ?? 'plus'" clase="w-4 h-4" />{{ principal.label }}
      </component>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'

// Cada acción: { label, href | click, icono?, title?, externo? (link común, no Inertia), method?, cuando? (false la oculta), e2e? }
const props = defineProps({
  titulo: { type: String, required: true }, subtitulo: { type: String, default: '' }, volver: { type: Object, default: null },
  principal: { type: Object, default: null }, secundaria: { type: Object, default: null }, mas: { type: Array, default: () => [] }, compacto: Boolean,
})
const abierto = ref(false), masRef = ref(null)
const masVisibles = computed(() => props.mas.filter(it => it && it.cuando !== false))
const tag = it => it.href ? (it.externo ? 'a' : Link) : 'button'
function attrs(it) {
  const a = { title: it.title }
  if (it.e2e) a['data-e2e'] = it.e2e
  if (it.href) { a.href = it.href; if (it.method) { a.method = it.method; a.as = 'button'; a.preserveScroll = true } } else a.type = 'button'
  return a
}
function elegir(it, cerrar = true) { if (cerrar) abierto.value = false; it.click?.() }
function afuera(e) { if (masRef.value && !masRef.value.contains(e.target)) abierto.value = false }
function esc(e) { if (e.key === 'Escape') abierto.value = false }
onMounted(() => { document.addEventListener('click', afuera); document.addEventListener('keydown', esc) })
onBeforeUnmount(() => { document.removeEventListener('click', afuera); document.removeEventListener('keydown', esc) })
</script>
