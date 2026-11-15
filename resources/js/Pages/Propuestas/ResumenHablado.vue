<template>
  <AppLayout titulo="Resumen hablado">
    <div class="max-w-2xl mx-auto" data-e2e="resumen-hablado">
      <p class="kicker mb-1">{{ fecha }}</p>
      <h1 class="page-title">Tu día en un minuto</h1>
      <p class="page-subtitle mb-4">Bigcito te lo lee con la voz del celular. Si querés saber más de algo, preguntale abajo.</p>
      <div class="card">
        <div class="flex flex-wrap items-center gap-2 mb-3">
          <button class="btn-primary" @click="hablando ? parar() : decir()" :disabled="!soportado" data-e2e="escuchar">{{ hablando ? 'Parar' : 'Escuchar' }}</button>
          <Link v-if="propuestas" href="/propuestas" class="btn-secondary">Ver las {{ propuestas }} propuesta{{ propuestas > 1 ? 's' : '' }}</Link>
          <p v-if="!soportado" class="text-xs text-marca-muted">Este navegador no tiene voz; abajo está el texto.</p>
        </div>
        <pre class="whitespace-pre-wrap font-sans text-sm leading-relaxed">{{ texto }}</pre>
      </div>
      <div class="card mt-4">
        <p class="text-sm font-semibold mb-2">Preguntale a Bigcito</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="q in preguntas" :key="q" class="btn-secondary !py-1 text-xs" @click="preguntar(q)">{{ q }}</button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
const props = defineProps({ texto: String, hablado: String, propuestas: Number, fecha: String, empresa: String })
const soportado = typeof window !== 'undefined' && 'speechSynthesis' in window
const hablando = ref(false)
const preguntas = ['¿Por qué vendí menos que ayer?', '¿A quién le cobro primero?', '¿Qué me falta reponer?', '¿Cómo viene el mes?']
function decir() {
  if (!soportado) return
  const u = new SpeechSynthesisUtterance(props.hablado); u.lang = 'es-AR'; u.rate = 1.0
  const v = window.speechSynthesis.getVoices().find(x => x.lang?.startsWith('es-AR')) || window.speechSynthesis.getVoices().find(x => x.lang?.startsWith('es')); if (v) u.voice = v
  u.onend = () => (hablando.value = false); u.onerror = () => (hablando.value = false)
  window.speechSynthesis.cancel(); window.speechSynthesis.speak(u); hablando.value = true
}
function parar() { window.speechSynthesis?.cancel(); hablando.value = false }
// Abre Bigcito con la pregunta ya escrita (AgenteIA escucha este evento).
function preguntar(q) { window.dispatchEvent(new CustomEvent('bigcito:preguntar', { detail: { texto: q } })) }
onMounted(() => { if (soportado && new URLSearchParams(location.search).get('auto') === '1') setTimeout(decir, 400) })
onBeforeUnmount(parar)
</script>
