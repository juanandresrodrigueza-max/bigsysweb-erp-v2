<template>
  <Teleport to="body">
    <div v-if="abierto" class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-black/30" @click="cerrar"></div>
      <aside class="absolute right-0 top-0 bottom-0 w-full sm:w-[440px] bg-white shadow-2xl flex flex-col">
        <div class="flex items-center gap-2 px-4 py-3 border-b border-marca-borde">
          <span class="w-8 h-8 rounded-full bg-marca-grad text-white grid place-items-center font-black">?</span>
          <div class="flex-1 min-w-0"><p class="font-bold leading-tight">Ayuda</p><p class="text-[11px] text-marca-muted truncate">{{ articulo ? 'De esta pantalla: ' + articulo.titulo : 'Guías y búsqueda' }}</p></div>
          <button class="p-1.5 rounded-lg hover:bg-marca-fondo" @click="cerrar"><Icono nombre="x" clase="w-5 h-5" /></button>
        </div>
        <div class="px-4 py-2 border-b border-marca-borde"><input v-model="q" class="input !py-1.5 text-sm" placeholder="Buscar en la ayuda… ej. anular factura" @input="buscar" /></div>
        <div class="flex-1 overflow-y-auto px-4 py-3 text-sm">
          <template v-if="q.trim().length >= 2">
            <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-1">Resultados</p>
            <Link v-for="r in resultados" :key="r.url" :href="r.url" class="block py-2 border-t border-marca-borde/60 first:border-0 hover:text-carmin" @click="cerrar"><p class="font-semibold">{{ r.titulo }} <span class="text-marca-muted font-normal">› {{ r.seccion }}</span></p><p class="text-xs text-marca-muted">…{{ r.fragmento }}…</p></Link>
            <p v-if="!resultados.length && !cargando" class="text-marca-muted">Nada con esas palabras.</p>
          </template>
          <template v-else>
            <p v-if="cargando" class="text-marca-muted">Cargando…</p>
            <template v-else-if="articulo">
              <div v-if="articulo.secciones?.length" class="flex flex-wrap gap-1 mb-3"><a v-for="s in articulo.secciones" :key="s.ancla" href="#" class="badge bg-marca-fondo text-marca-muted hover:text-carmin" @click.prevent="irA(s.ancla)">{{ s.titulo }}</a></div>
              <div class="ayuda-prose" ref="cuerpo" v-html="articulo.html"></div>
            </template>
            <div v-else>
              <p class="text-marca-muted mb-2">Esta pantalla no tiene guía propia todavía. Estas pueden servir:</p>
              <Link v-for="a in sugeridos" :key="a.slug" :href="`/ayuda/${a.slug}`" class="block py-1.5 border-t border-marca-borde/60 first:border-0 hover:text-carmin" @click="cerrar"><p class="font-semibold">{{ a.titulo }}</p><p class="text-xs text-marca-muted">{{ a.resumen }}</p></Link>
            </div>
          </template>
        </div>
        <div class="px-4 py-3 border-t border-marca-borde flex flex-wrap gap-2 text-xs">
          <Link :href="articulo ? `/ayuda/${articulo.slug}` : '/ayuda'" class="btn-secondary !py-1.5" @click="cerrar">Centro de ayuda</Link>
          <button class="btn-ghost !py-1.5" @click="verTour">Ver el tour</button>
          <Link href="/soporte" class="btn-primary !py-1.5 ml-auto" @click="cerrar">Soporte</Link>
        </div>
      </aside>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'
const emit = defineEmits(['tour'])
const page = usePage()
const abierto = ref(false), q = ref(''), resultados = ref([]), articulo = ref(null), sugeridos = ref([]), tour = ref([]), cargando = ref(false), cuerpo = ref(null)
function irA(ancla) { cuerpo.value?.querySelector('#' + CSS.escape(ancla))?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }
let timer = null
async function abrir() {
  abierto.value = true; cargando.value = true; q.value = ''
  try { const r = await fetch(`/ayuda/contexto?ruta=${encodeURIComponent(page.url)}`, { headers: { Accept: 'application/json' } }); const d = await r.json(); articulo.value = d.articulo; sugeridos.value = d.sugeridos; tour.value = d.tour } catch (e) { articulo.value = null } finally { cargando.value = false }
}
function cerrar() { abierto.value = false }
function buscar() { clearTimeout(timer); const t = q.value.trim(); if (t.length < 2) { resultados.value = []; return } timer = setTimeout(async () => { cargando.value = true; try { const r = await fetch(`/ayuda/buscar?q=${encodeURIComponent(t)}`, { headers: { Accept: 'application/json' } }); resultados.value = await r.json() } catch (e) {} finally { cargando.value = false } }, 200) }
function verTour() { cerrar(); emit('tour', tour.value) }
watch(() => page.url, () => { if (abierto.value) abrir() })
defineExpose({ abrir })
</script>
