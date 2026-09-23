<template>
  <div class="relative" ref="root">
    <input :value="abierto ? texto : etiqueta" @focus="abrir" @input="texto = $event.target.value; abierto = true" @keydown.down.prevent="mover(1)" @keydown.up.prevent="mover(-1)" @keydown.enter.prevent="elegir(filtradas[idx])" @keydown.esc="abierto = false"
           class="input pr-8" :placeholder="placeholder" :disabled="disabled" autocomplete="off" />
    <button v-if="modelValue && !disabled" type="button" @click.stop="limpiar" class="absolute right-2 top-1/2 -translate-y-1/2 text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
    <div v-if="abierto" class="absolute z-40 mt-1 w-full max-h-64 overflow-y-auto bg-white border border-marca-borde rounded-xl shadow-pop">
      <button v-for="(o, i) in filtradas" :key="o.id" type="button" @mousedown.prevent="elegir(o)" class="w-full text-left px-3 py-2 text-sm hover:bg-marca-fondo flex justify-between gap-2" :class="{ 'bg-marca-fondo': i === idx }">
        <span class="truncate"><span class="font-medium">{{ o.label }}</span><span v-if="o.sub" class="text-marca-muted"> · {{ o.sub }}</span></span>
        <span v-if="o.sugerido" class="text-[10px] px-1.5 rounded-full shrink-0" :class="o.sugerido === 'cliente' ? 'bg-violeta-light text-violeta' : 'bg-marca-fondo text-marca-muted'">{{ o.sugerido === 'cliente' ? 'lo compró' : o.sugerido === 'reciente' ? 'reciente' : 'más vendido' }}</span>
        <span v-if="o.extra" class="text-xs text-marca-muted shrink-0">{{ o.extra }}</span>
      </button>
      <p v-if="!filtradas.length" class="px-3 py-3 text-sm text-marca-muted">{{ buscando ? 'Buscando…' : (url && !texto.trim() ? 'Escribí para buscar.' : 'Sin resultados.') }}</p>
      <slot name="pie" />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import Icono from '@/Components/Icono.vue'

// `url`: con catálogos grandes las opciones se buscan en el servidor a medida que se escribe; las filas llegan por el evento `cargados`
// y el padre las suma a su lista, así el filtrado local y la etiqueta del elegido siguen funcionando igual.
const props = defineProps({ modelValue: [Number, String, null], opciones: { type: Array, default: () => [] }, placeholder: String, disabled: Boolean, url: String })
const emit = defineEmits(['update:modelValue', 'elegido', 'cargados'])
const abierto = ref(false), texto = ref(''), idx = ref(0), root = ref(null), buscando = ref(false)
let timer = null
watch(texto, t => { if (!props.url || !abierto.value) return; clearTimeout(timer); timer = setTimeout(() => remota(t), 220) })
async function remota(t) {
  buscando.value = true
  try { const r = await fetch(`${props.url}${props.url.includes('?') ? '&' : '?'}q=${encodeURIComponent(t.trim())}`, { headers: { Accept: 'application/json' } }); if (r.ok) emit('cargados', await r.json()) } catch (e) {}
  finally { buscando.value = false }
}

const etiqueta = computed(() => props.opciones.find(o => o.id === props.modelValue)?.label ?? '')
const rango = { cliente: 0, reciente: 0, top: 1 }
const filtradas = computed(() => {
  const t = texto.value.trim().toLowerCase()
  let base = t ? props.opciones.filter(o => (o.label + ' ' + (o.sub ?? '')).toLowerCase().includes(t)) : props.opciones
  if (!t) base = [...base].sort((a, b) => (a.sugerido ? rango[a.sugerido] ?? 2 : 3) - (b.sugerido ? rango[b.sugerido] ?? 2 : 3)) // sugeridos primero, el resto en su orden
  return base.slice(0, 40)
})
let ultimaUrl = null
function abrir() { texto.value = ''; idx.value = 0; abierto.value = true; if (props.url && (props.opciones.length < 30 || ultimaUrl !== props.url)) { ultimaUrl = props.url; remota('') } }
function elegir(o) { if (!o) return; emit('update:modelValue', o.id); emit('elegido', o); abierto.value = false }
function limpiar() { emit('update:modelValue', null); emit('elegido', null) }
function mover(d) { idx.value = Math.max(0, Math.min(filtradas.value.length - 1, idx.value + d)) }
function afuera(e) { if (root.value && !root.value.contains(e.target)) abierto.value = false }
onMounted(() => document.addEventListener('mousedown', afuera))
onBeforeUnmount(() => document.removeEventListener('mousedown', afuera))
</script>
