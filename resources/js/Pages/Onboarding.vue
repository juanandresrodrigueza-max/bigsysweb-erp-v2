<template>
  <AppLayout titulo="Primeros pasos">
    <div class="max-w-3xl mx-auto">
      <div class="card-oscura !p-6 mb-5">
        <p class="text-xs font-bold uppercase tracking-widest text-white/80">Primeros pasos</p>
        <h1 class="text-2xl font-black mt-1" style="text-wrap: balance">{{ completado ? 'Todo configurado' : `Vas ${hechos} de ${total}` }}</h1>
        <p class="text-sm text-white/90 mt-1">Con esto la empresa queda lista para facturar, cobrar y controlar stock. Podés hacerlo en el orden que quieras y volver cuando necesites.</p>
        <div class="h-2 rounded-full bg-white/25 mt-4"><div class="h-2 rounded-full bg-white" :style="{ width: (hechos / total * 100) + '%' }"></div></div>
        <div class="flex flex-wrap gap-2 mt-4"><Link href="/ayuda/implementacion" class="px-3 py-1.5 rounded-full bg-white text-violeta text-xs font-bold">Guía de implementación día por día</Link><a href="/ayuda/manual" target="_blank" class="px-3 py-1.5 rounded-full bg-white/20 text-white text-xs font-bold">Manual completo</a></div>
      </div>

      <!-- Fase 48: el inductor de Bigcito -->
      <div v-if="induccion.puede && (!induccion.hecha || rehacer)" class="card mb-5" data-e2e="inductor">
        <div class="flex items-center gap-2 mb-3"><img src="/img/bigcito.webp" alt="Bigcito" class="w-16 h-auto shrink-0" /><div><p class="font-bold leading-tight">Bigcito te deja el sistema a medida</p><p class="text-xs text-marca-muted">Unas preguntas cortas según tu negocio. Lo que no uses lo saco del menú; lo que sí, te lo pongo en los pasos.</p></div></div>
        <div class="space-y-2 max-h-80 overflow-y-auto pr-1">
          <template v-for="k in respondidas" :key="k">
            <p class="text-sm bg-marca-fondo rounded-2xl rounded-tl-sm px-3 py-2 max-w-[85%]">{{ preguntasVis[k].texto }}</p>
            <p class="text-sm bg-lavanda-light text-violeta rounded-2xl rounded-tr-sm px-3 py-2 max-w-[85%] ml-auto text-right cursor-pointer" title="Cambiar" @click="delete r[k]">{{ preguntasVis[k].opciones[r[k]] }}</p>
          </template>
          <template v-if="actual">
            <div class="flex items-end gap-2"><img src="/img/bigcito-cara.webp" alt="" class="w-7 h-7 rounded-full shrink-0" /><p class="text-sm bg-marca-fondo rounded-2xl rounded-bl-sm px-3 py-2 max-w-[85%] font-medium">{{ preguntasVis[actual].texto }}</p></div>
            <div class="flex flex-wrap gap-1.5 justify-end"><button v-for="(l, v) in preguntasVis[actual].opciones" :key="v" class="btn-secondary !py-1 text-xs" @click="r[actual] = v" :data-opcion="actual + ':' + v">{{ l }}</button></div>
          </template>
          <p v-else class="text-sm bg-marca-fondo rounded-2xl rounded-tl-sm px-3 py-2 max-w-[85%]">¡Listo! Con esto te dejo: <b>{{ resumen }}</b>. ¿Lo dejo así?</p>
        </div>
        <div v-if="actual === 'rubro' && induccion.ia && !respondidas.length" class="mt-3 flex gap-2"><input v-model="libre" class="input text-sm" placeholder="O contamelo con tus palabras: «corralón con dos camiones, vendo en Mercado Libre y tengo 5 empleados»" @keyup.enter="interpretar" /><button class="btn-secondary text-xs whitespace-nowrap" :disabled="!libre || leyendo" @click="interpretar">{{ leyendo ? 'Leyendo…' : 'Contar' }}</button></div>
        <div class="flex justify-between items-center mt-3"><button v-if="respondidas.length" class="text-xs text-marca-muted" @click="Object.keys(r).forEach(k => delete r[k])">Empezar de nuevo</button><span v-else></span>
          <button v-if="!actual" class="btn-primary" @click="router.post('/primeros-pasos/induccion', { respuestas: r }, { preserveScroll: true, onSuccess: () => (rehacer = false) })" data-e2e="inductor-aplicar">Dejarlo así</button></div>
      </div>

      <div v-if="induccion.seguimiento.length" class="card mb-5 border-violeta/30" data-e2e="seguimiento">
        <p class="font-bold mb-1">Bigcito te sugiere</p>
        <div v-for="s in induccion.seguimiento" :key="s.uso" class="flex items-start justify-between gap-3 py-1.5 border-t border-marca-borde/60 text-sm"><div><p class="font-semibold">{{ s.titulo }}</p><p class="text-xs text-marca-muted">{{ s.texto }}</p></div><Link :href="s.url" class="btn-secondary !py-1 text-xs shrink-0">Ir</Link></div>
      </div>

      <div v-if="induccion.hecha && !rehacer" class="flex flex-wrap items-center gap-2 text-xs text-marca-muted mb-3">
        <span>Configurado por Bigcito.</span><button v-if="induccion.puede" class="text-violeta font-semibold" @click="rehacer = true">Cambiar respuestas</button>
        <template v-if="induccion.ocultos.length"><span>· Oculto del menú:</span><button v-for="m in induccion.ocultos" :key="m.key" class="badge bg-gris-light text-marca-muted hover:text-violeta" title="Volver a mostrar" @click="router.post('/primeros-pasos/mostrar-modulo', { modulo: m.key }, { preserveScroll: true })">{{ m.label }} ✕</button></template>
      </div>

      <div class="space-y-2">
        <div v-for="(p, i) in pasos" :key="p.key" class="card flex items-start gap-4" :class="p.hecho ? 'opacity-75' : ''">
          <button class="w-8 h-8 rounded-full grid place-items-center shrink-0 font-bold text-sm" :class="p.hecho ? 'bg-emerald-500 text-white' : 'bg-marca-fondo text-marca-muted border border-marca-borde'" :title="p.hecho ? 'Marcar como pendiente' : 'Marcar como hecho'" @click="router.post('/primeros-pasos/marcar', { paso: p.key, hecho: !p.hecho }, { preserveScroll: true })">{{ p.hecho ? '✓' : i + 1 }}</button>
          <div class="flex-1 min-w-0"><p class="font-bold" :class="p.hecho ? 'line-through' : ''">{{ p.titulo }} <span v-if="p.bigcito" class="badge bg-lavanda-light text-violeta ml-1">para tu negocio</span> <span v-if="p.opcional" class="badge bg-gris-light text-marca-muted ml-1">opcional</span></p><p class="text-sm text-marca-muted">{{ p.desc }}</p></div>
          <div class="flex flex-col gap-1 shrink-0"><Link :href="p.url" class="btn-secondary !py-1.5 text-xs text-center">{{ p.hecho ? 'Ver' : 'Ir' }}</Link><Link v-if="p.ayuda" :href="p.ayuda.startsWith('guia-') ? `/ayuda/guia/${p.ayuda.slice(5)}` : `/ayuda/${p.ayuda}`" class="text-[11px] text-violeta font-semibold text-center">¿Cómo?</Link></div>
        </div>
      </div>

      <div class="card mt-5 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm"><p class="font-bold">¿Necesitás una mano?</p><p class="text-marca-muted">WhatsApp {{ soporte.whatsapp }} · {{ soporte.email }} · o abrí un ticket desde <Link href="/soporte" class="underline">Soporte</Link>.</p></div>
        <button v-if="!completado" class="btn-primary" @click="router.post('/primeros-pasos/completar')">Listo, ya arranqué</button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
const props = defineProps({ pasos: Array, completado: Boolean, hechos: Number, total: Number, soporte: Object, induccion: { type: Object, default: () => ({ hecha: true, puede: false, preguntas: {}, respuestas: {}, seguimiento: [], ocultos: [] }) } })
// Las preguntas dependen del rubro: un servicio técnico no ve la de reparto, un corralón no ve la de turnos.
const r = reactive({ ...(props.induccion.respuestas ?? {}) })
const rehacer = ref(false), libre = ref(''), leyendo = ref(false)
const preguntasVis = computed(() => Object.fromEntries(Object.entries(props.induccion.preguntas).filter(([k, p]) => !p.rubros || !r.rubro || p.rubros.includes(r.rubro))))
const respondidas = computed(() => Object.keys(preguntasVis.value).filter(k => r[k]))
const actual = computed(() => Object.keys(preguntasVis.value).find(k => !r[k]) ?? null)
const resumen = computed(() => respondidas.value.map(k => preguntasVis.value[k].opciones[r[k]]).join(' · '))
async function interpretar() {
  leyendo.value = true
  try { const { data } = await window.axios.post('/primeros-pasos/interpretar', { texto: libre.value }); Object.assign(r, data.respuestas ?? {}) } catch (e) {} finally { leyendo.value = false }
}
</script>
