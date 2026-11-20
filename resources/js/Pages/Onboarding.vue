<template>
  <AppLayout titulo="Primeros pasos">
    <div class="max-w-3xl mx-auto">
      <div class="card-oscura !p-6 mb-5">
        <p class="text-xs font-bold uppercase tracking-widest text-white/80">Primeros pasos</p>
        <h1 class="text-2xl font-black mt-1" style="text-wrap: balance">{{ completado ? 'Todo configurado' : (hechos === total ? '¡Ya está todo!' : `Vas ${hechos} de ${total}`) }}</h1>
        <p class="text-sm text-white/90 mt-1">Seguí de arriba hacia abajo: cada paso dice qué es, para qué sirve y cuánto lleva. Lo que hacés en otra pantalla se tilda solo, y podés cortar y volver cuando quieras.</p>
        <div class="h-2 rounded-full bg-white/25 mt-4"><div class="h-2 rounded-full bg-white" :style="{ width: (hechos / total * 100) + '%' }"></div></div>
        <div class="flex flex-wrap gap-2 mt-4"><Link href="/ayuda/implementacion" class="px-3 py-1.5 rounded-full bg-white text-violeta text-xs font-bold">Guía de implementación paso a paso</Link><a href="/ayuda/manual" target="_blank" class="px-3 py-1.5 rounded-full bg-white/20 text-white text-xs font-bold">Manual completo</a></div>
      </div>

      <!-- Fase 122: siempre a la vista qué sigue -->
      <div v-if="sig" class="card mb-5 border-2 border-carmin/40" data-e2e="siguiente-paso">
        <p class="text-xs font-bold uppercase tracking-widest text-carmin">Siguiente paso · {{ etapas[sig.etapa]?.titulo }}</p>
        <div class="flex flex-wrap items-start justify-between gap-3 mt-1">
          <div class="flex-1 min-w-[14rem]">
            <p class="text-xl font-black leading-tight">{{ sig.titulo }}</p>
            <p class="text-sm mt-1">{{ sig.desc }}</p>
            <p v-if="sig.porque" class="text-sm text-marca-muted mt-1"><b class="text-marca-texto">Para qué:</b> {{ sig.porque }}</p>
          </div>
          <span v-if="sig.minutos" class="badge bg-lavanda-light text-violeta shrink-0">≈ {{ sig.minutos }} min</span>
        </div>
        <div class="flex flex-wrap items-center gap-2 mt-3">
          <Link :href="sig.url" class="btn-primary">Empezar</Link>
          <Link v-if="sig.ayuda" :href="ayudaUrl(sig.ayuda)" class="btn-secondary">¿Cómo se hace?</Link>
          <button class="text-xs text-marca-muted underline ml-auto" @click="marcar(sig, true)">Ya lo hice</button>
        </div>
      </div>

      <details class="card mb-5 group" :open="!hechos" data-e2e="como-funciona">
        <summary class="font-bold cursor-pointer list-none flex items-center justify-between gap-2">Cómo funciona BigSys Web, en un minuto <span class="text-marca-muted text-sm group-open:rotate-180 transition">▾</span></summary>
        <ol class="mt-3 space-y-2 text-sm list-decimal pl-5 marker:text-carmin marker:font-bold">
          <li><b>Está en internet.</b> Entrás desde la compu o el celular con tu usuario, sin instalar nada. Cada persona tiene el suyo y ve sólo lo que su rol permite.</li>
          <li><b>Todo sale de la venta.</b> Hacés la factura (o la venta de mostrador) y solo se descuenta el stock, se carga la cuenta corriente del cliente, entra la plata en la caja o el banco y se arma el asiento contable. No se carga nada dos veces.</li>
          <li><b>La factura electrónica la hace BigSys por vos.</b> Le das permiso en ARCA con tu clave fiscal una sola vez y el CAE sale en el momento. No hay certificados que generar ni que renovar.</li>
          <li><b>Si venís de BigSys de escritorio, no perdés nada.</b> Con BIG Connect se traen clientes, proveedores, artículos, saldos, cajas y cheques. Primero lo probás sin guardar; el escritorio sigue andando hasta el día que elijas para el corte.</li>
          <li><b>Bigcito te acompaña.</b> El asistente violeta responde «¿cuánto me debe López?», hace cosas («cobrale 5000 a Pérez») y te avisa lo importante. El <b>?</b> de arriba abre la ayuda de cada pantalla.</li>
        </ol>
      </details>

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

      <div class="space-y-6">
        <section v-for="(g, n) in grupos" :key="g.key" :data-etapa="g.key">
          <div class="flex items-baseline justify-between gap-2 mb-2 px-1">
            <h2 class="font-black"><span class="text-carmin">{{ n + 1 }}.</span> {{ g.titulo }}</h2>
            <span class="text-xs text-marca-muted whitespace-nowrap">{{ g.hechos }} de {{ g.pasos.length }} listos</span>
          </div>
          <p v-if="g.texto" class="text-xs text-marca-muted -mt-1 mb-2 px-1">{{ g.texto }}</p>
          <div class="space-y-2">
            <div v-for="p in g.pasos" :key="p.key" class="card flex items-start gap-4" :class="[p.hecho ? 'opacity-75' : '', p.key === siguiente ? 'ring-2 ring-carmin/40' : '']" :data-paso="p.key">
              <button class="w-8 h-8 rounded-full grid place-items-center shrink-0 font-bold text-sm" :class="p.hecho ? 'bg-emerald-500 text-white' : 'bg-marca-fondo text-marca-muted border border-marca-borde'" :title="p.hecho ? 'Marcar como pendiente' : 'Marcar como hecho'" @click="marcar(p, !p.hecho)">{{ p.hecho ? '✓' : numero[p.key] }}</button>
              <div class="flex-1 min-w-0"><p class="font-bold" :class="p.hecho ? 'line-through' : ''">{{ p.titulo }} <span v-if="p.bigcito" class="badge bg-lavanda-light text-violeta ml-1">para tu negocio</span> <span v-if="p.opcional" class="badge bg-gris-light text-marca-muted ml-1">opcional</span></p><p class="text-sm text-marca-muted">{{ p.desc }}</p><p v-if="!p.hecho && p.minutos" class="text-[11px] text-marca-muted mt-0.5">≈ {{ p.minutos }} min</p></div>
              <div class="flex flex-col gap-1 shrink-0"><Link :href="p.url" class="btn-secondary !py-1.5 text-xs text-center">{{ p.hecho ? 'Ver' : 'Ir' }}</Link><Link v-if="p.ayuda" :href="ayudaUrl(p.ayuda)" class="text-[11px] text-violeta font-semibold text-center">¿Cómo?</Link></div>
            </div>
          </div>
        </section>
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
const props = defineProps({ pasos: Array, etapas: { type: Object, default: () => ({}) }, siguiente: { type: String, default: null }, completado: Boolean, hechos: Number, total: Number, soporte: Object, induccion: { type: Object, default: () => ({ hecha: true, puede: false, preguntas: {}, respuestas: {}, seguimiento: [], ocultos: [] }) } })
// Las preguntas dependen del rubro: un servicio técnico no ve la de reparto, un corralón no ve la de turnos.
const r = reactive({ ...(props.induccion.respuestas ?? {}) })
const rehacer = ref(false), libre = ref(''), leyendo = ref(false)
const preguntasVis = computed(() => Object.fromEntries(Object.entries(props.induccion.preguntas).filter(([k, p]) => !p.rubros || !r.rubro || p.rubros.includes(r.rubro))))
const respondidas = computed(() => Object.keys(preguntasVis.value).filter(k => r[k]))
const actual = computed(() => Object.keys(preguntasVis.value).find(k => !r[k]) ?? null)
const resumen = computed(() => respondidas.value.map(k => preguntasVis.value[k].opciones[r[k]]).join(' · '))
// Fase 122: los pasos agrupados por etapa, en el orden en que llegan, con un número corrido.
const grupos = computed(() => {
  const out = []
  for (const p of props.pasos) {
    const k = p.etapa || 'otros'
    let g = out.find(x => x.key === k)
    if (!g) { g = { key: k, titulo: props.etapas[k]?.titulo ?? 'Más', texto: props.etapas[k]?.texto ?? '', pasos: [], hechos: 0 }; out.push(g) }
    g.pasos.push(p); if (p.hecho) g.hechos++
  }
  return out
})
const numero = computed(() => Object.fromEntries(grupos.value.flatMap(g => g.pasos).map((p, i) => [p.key, i + 1])))
const sig = computed(() => props.pasos.find(p => p.key === props.siguiente) ?? null)
const ayudaUrl = (a) => a.startsWith('guia-') ? `/ayuda/guia/${a.slice(5)}` : `/ayuda/${a}`
const marcar = (p, hecho) => router.post('/primeros-pasos/marcar', { paso: p.key, hecho }, { preserveScroll: true })
async function interpretar() {
  leyendo.value = true
  try { const { data } = await window.axios.post('/primeros-pasos/interpretar', { texto: libre.value }); Object.assign(r, data.respuestas ?? {}) } catch (e) {} finally { leyendo.value = false }
}
</script>
