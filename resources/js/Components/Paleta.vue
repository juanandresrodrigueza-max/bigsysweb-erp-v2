<template>
  <!-- Buscador global y paleta de comandos: Ctrl+K (o "/" fuera de un campo). Escribí un cliente, artículo, número de comprobante o una pantalla. -->
  <div v-if="abierto" class="fixed inset-0 z-[60] flex items-start justify-center pt-[12vh] px-4" @mousedown.self="cerrar">
    <div class="absolute inset-0 bg-marca-texto/40 backdrop-blur-[2px]" @mousedown="cerrar"></div>
    <div class="relative w-full max-w-xl bg-white rounded-2xl shadow-2xl border border-marca-borde overflow-hidden">
      <div class="flex items-center gap-2 px-4 border-b border-marca-borde">
        <Icono nombre="search" clase="w-4 h-4 text-marca-muted shrink-0" />
        <input ref="input" v-model="q" class="flex-1 py-3 text-base outline-none bg-transparent" placeholder="Buscar cliente, artículo, comprobante o pantalla…" autocomplete="off"
               @keydown.down.prevent="mover(1)" @keydown.up.prevent="mover(-1)" @keydown.enter.prevent="ir(plano[idx])" @keydown.esc.prevent="cerrar" />
        <kbd class="text-[10px] px-1.5 py-0.5 rounded border border-marca-borde text-marca-muted">Esc</kbd>
      </div>
      <div class="max-h-[60vh] overflow-y-auto py-2">
        <template v-for="g in grupos" :key="g.label">
          <p v-if="g.items.length" class="px-4 pt-2 pb-1 text-[10px] font-bold uppercase tracking-widest text-marca-muted">{{ g.label }}</p>
          <button v-for="it in g.items" :key="it.url + it.titulo" type="button" @mousedown.prevent="ir(it)" @mousemove="idx = it._i"
                  class="w-full text-left px-4 py-2 flex items-center gap-3 text-sm" :class="idx === it._i ? 'bg-marca-fondo' : ''">
            <span class="w-7 h-7 rounded-lg grid place-items-center shrink-0" :class="it.color ?? 'bg-marca-fondo text-violeta'"><Icono :nombre="it.icono ?? 'chevron'" clase="w-4 h-4" /></span>
            <span class="min-w-0 flex-1"><span class="font-medium block truncate">{{ it.titulo }}</span><span v-if="it.sub" class="text-xs text-marca-muted block truncate">{{ it.sub }}</span></span>
            <kbd v-if="it.atajo" class="text-[10px] px-1.5 py-0.5 rounded border border-marca-borde text-marca-muted shrink-0">{{ it.atajo }}</kbd>
          </button>
        </template>
        <p v-if="!plano.length" class="px-4 py-6 text-sm text-marca-muted text-center">{{ cargando ? 'Buscando…' : (q ? 'Nada coincide.' : 'Escribí para buscar. Atajos: Alt+N factura · Alt+P punto de venta · Alt+C clientes · Alt+S stock · Alt+F fondos · ? ayuda') }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'

const props = defineProps({ nav: { type: Array, default: () => [] } })
const abierto = ref(false), q = ref(''), idx = ref(0), input = ref(null), cargando = ref(false), remoto = ref({ clientes: [], proveedores: [], articulos: [], comprobantes: [] })
let timer = null
const page = usePage()

const norm = s => (s ?? '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
const acciones = computed(() => {
  const tiene = key => props.nav.some(g => g.items.some(i => i.key === key && i.disponible !== false))
  const pos = props.nav.flatMap(g => g.items).find(i => ['retail', 'minimarket'].includes(i.key))
  return [
    tiene('comprobantes') && { titulo: 'Nueva factura', sub: 'Comprobantes → nuevo', url: '/comprobantes/nuevo', icono: 'plus', atajo: 'Alt+N', color: 'bg-carmin-light text-carmin' },
    tiene('comprobantes') && { titulo: 'Nuevo presupuesto', url: '/comprobantes/nuevo?tipo=PRE', icono: 'plus' },
    pos && { titulo: pos.label, sub: 'Cobrar rápido', url: pos.ruta, icono: pos.icono, atajo: 'Alt+P', color: 'bg-violeta-light text-violeta' },
    tiene('clientes') && { titulo: 'Cobranzas', url: '/clientes/cobranzas', icono: 'wallet' },
    tiene('fondos') && { titulo: 'Nuevo gasto', sub: 'Fondos', url: '/fondos?nuevo=egreso', icono: 'wallet' },
    tiene('stock') && { titulo: 'Actualizar precios', url: '/stock?precios=1', icono: 'boxes' },
  ].filter(Boolean)
})
const pantallas = computed(() => [...props.nav.flatMap(g => g.items.filter(i => i.disponible !== false).map(i => ({ titulo: i.label, sub: g.label, url: i.ruta, icono: i.icono }))), { titulo: 'Centro de ayuda', sub: 'Sistema', url: '/ayuda', icono: 'info' }, { titulo: 'Soporte', sub: 'Sistema', url: '/soporte', icono: 'info' }])

const grupos = computed(() => {
  const t = norm(q.value.trim())
  const filtra = l => t ? l.filter(i => norm(i.titulo + ' ' + (i.sub ?? '')).includes(t)) : l
  const gs = [
    { label: 'Acciones', items: filtra(acciones.value) },
    { label: 'Clientes', items: remoto.value.clientes.map(i => ({ ...i, icono: 'users' })) },
    { label: 'Artículos', items: remoto.value.articulos.map(i => ({ ...i, icono: 'boxes' })) },
    { label: 'Comprobantes', items: remoto.value.comprobantes.map(i => ({ ...i, icono: 'receipt' })) },
    { label: 'Proveedores', items: remoto.value.proveedores.map(i => ({ ...i, icono: 'truck' })) },
    { label: 'Pantallas', items: filtra(pantallas.value).slice(0, t ? 8 : 6) },
  ]
  let i = 0; gs.forEach(g => g.items.forEach(it => (it._i = i++)))
  return gs
})
const plano = computed(() => grupos.value.flatMap(g => g.items))

watch(q, t => { idx.value = 0; clearTimeout(timer); if (t.trim().length < 2) { remoto.value = { clientes: [], proveedores: [], articulos: [], comprobantes: [] }; return } timer = setTimeout(buscar, 180) })
async function buscar() {
  cargando.value = true
  try { const r = await fetch(`/buscar/global?q=${encodeURIComponent(q.value.trim())}`, { headers: { Accept: 'application/json' } }); if (r.ok) remoto.value = await r.json() } catch (e) {} finally { cargando.value = false }
}
function mover(d) { if (!plano.value.length) return; idx.value = (idx.value + d + plano.value.length) % plano.value.length }
function ir(it) { if (!it) return; cerrar(); router.visit(it.url) }
function abrir() { abierto.value = true; q.value = ''; idx.value = 0; nextTick(() => input.value?.focus()) }
function cerrar() { abierto.value = false }

// Atajos globales (no actúan mientras se escribe en un campo).
function enCampo(e) { const t = e.target; return t && (['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName) || t.isContentEditable) }
function teclas(e) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); abierto.value ? cerrar() : abrir(); return }
  if (abierto.value) return
  if (enCampo(e)) return
  if (e.key === '/') { e.preventDefault(); abrir(); return }
  if (e.key === '?') { e.preventDefault(); abrir(); q.value = ''; return }
  if (e.altKey && !e.ctrlKey && !e.metaKey) {
    const k = e.key.toLowerCase(); const pos = props.nav.flatMap(g => g.items).find(i => ['retail', 'minimarket'].includes(i.key))
    const map = { n: '/comprobantes/nuevo', p: pos?.ruta, c: '/clientes', s: '/stock', f: '/fondos', d: '/dashboard', v: '/proveedores' }
    if (map[k]) { e.preventDefault(); router.visit(map[k]) }
  }
}
defineExpose({ abrir })
onMounted(() => window.addEventListener('keydown', teclas)); onBeforeUnmount(() => window.removeEventListener('keydown', teclas))
</script>
