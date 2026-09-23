<template>
  <Teleport to="body">
    <div v-if="activo && paso" class="fixed inset-0 z-[60]" @keydown.esc="terminar">
      <!-- Foco: todo oscuro menos el elemento -->
      <div class="absolute rounded-xl transition-all duration-300 pointer-events-none" :style="{ left: caja.x + 'px', top: caja.y + 'px', width: caja.w + 'px', height: caja.h + 'px', boxShadow: '0 0 0 9999px rgba(36,28,54,.62)', border: '2px solid #e4003f' }"></div>
      <div class="absolute inset-0" @click="siguiente"></div>
      <div class="absolute bg-white rounded-2xl shadow-2xl p-4 w-[320px] max-w-[calc(100vw-24px)]" :style="{ left: tip.x + 'px', top: tip.y + 'px' }">
        <p class="text-[10px] font-bold uppercase tracking-widest text-violeta">Paso {{ i + 1 }} de {{ pasos.length }}</p>
        <p class="font-extrabold text-base mt-0.5">{{ paso.titulo }}</p>
        <p class="text-sm text-marca-muted mt-1">{{ paso.texto }}</p>
        <div class="flex items-center gap-2 mt-3">
          <button class="btn-ghost !px-2 text-xs" @click.stop="terminar">Saltar</button>
          <span class="flex-1"></span>
          <button v-if="i > 0" class="btn-secondary !py-1 text-xs" @click.stop="i--; ubicar()">Atrás</button>
          <button class="btn-primary !py-1 text-xs" @click.stop="siguiente">{{ i === pasos.length - 1 ? 'Listo' : 'Siguiente' }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
// Tour guiado: resalta elementos con data-tour y explica cada uno. Se marca como visto en el servidor al terminar o saltar.
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
const props = defineProps({ pasosIniciales: { type: Array, default: null } })
const activo = ref(false), pasos = ref([]), i = ref(0), caja = ref({ x: 0, y: 0, w: 0, h: 0 }), tip = ref({ x: 16, y: 16 })
const paso = computed(() => pasos.value[i.value])
function visibles(lista) { return lista.filter(p => { const el = document.querySelector(p.sel); return el && el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden' }) }
function iniciar(lista) { pasos.value = visibles(lista ?? []); if (!pasos.value.length) return; i.value = 0; activo.value = true; nextTick(ubicar) }
function ubicar() {
  const el = document.querySelector(paso.value.sel); if (!el) { siguiente(); return }
  const r = el.getBoundingClientRect(); const m = 6
  caja.value = { x: r.left - m, y: r.top - m, w: r.width + 2 * m, h: r.height + 2 * m }
  const W = window.innerWidth, H = window.innerHeight, tw = 320, th = 170
  let x = r.left, y = r.bottom + 14
  if (y + th > H) y = Math.max(12, r.top - th - 14)
  if (x + tw > W - 12) x = Math.max(12, W - tw - 12)
  if (r.right + tw + 20 < W && r.width < 120 && r.left > W / 2) { x = r.left - tw - 14; y = Math.min(r.top, H - th - 12) }
  tip.value = { x, y }
}
function siguiente() { if (i.value < pasos.value.length - 1) { i.value++; ubicar() } else terminar() }
async function terminar() {
  activo.value = false
  try { await fetch('/ayuda/tour-visto', { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '') } }) } catch (e) {}
}
const onResize = () => { if (activo.value) ubicar() }
onMounted(() => { window.addEventListener('resize', onResize); if (props.pasosIniciales?.length) setTimeout(() => iniciar(props.pasosIniciales), 600) })
onBeforeUnmount(() => window.removeEventListener('resize', onResize))
defineExpose({ iniciar })
</script>
