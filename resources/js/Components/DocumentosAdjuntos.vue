<template>
  <div class="card" data-e2e="documentos">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <div><h2 class="font-bold">Documentos</h2><p class="text-xs text-marca-muted">{{ ayuda }}</p></div>
      <label class="btn-secondary !py-1 text-xs cursor-pointer" :class="subiendo ? 'opacity-60 pointer-events-none' : ''">
        <input type="file" class="hidden" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.xlsx,.xls,.csv,.docx,.doc,.txt,.zip" data-e2e="subir-doc" @change="subir" />
        {{ subiendo ? 'Subiendo…' : 'Adjuntar archivo' }}
      </label>
    </div>
    <p v-if="error" class="text-carmin text-xs mb-2">{{ error }}</p>
    <div v-if="cargando" class="text-sm text-marca-muted py-4 text-center">Cargando…</div>
    <div v-else class="divide-y divide-marca-borde/60">
      <div v-for="d in docs" :key="d.id" class="flex items-center gap-3 py-2 text-sm">
        <a :href="d.url + '?ver=1'" target="_blank" class="w-10 h-10 rounded-lg bg-gris-light flex items-center justify-center overflow-hidden shrink-0">
          <img v-if="d.imagen" :src="d.url + '?ver=1'" alt="" class="w-full h-full object-cover" /><span v-else class="text-[10px] font-bold text-marca-muted uppercase">{{ ext(d.nombre) }}</span>
        </a>
        <div class="flex-1 min-w-0">
          <a :href="d.url + '?ver=1'" target="_blank" class="font-medium hover:text-carmin truncate block">{{ d.nombre }}</a>
          <p class="text-xs text-marca-muted">{{ d.fecha ? d.fecha.split('-').reverse().join('/') : d.subido }} · {{ peso(d.tamano) }}<span v-if="d.usuario"> · {{ d.usuario }}</span><span v-if="d.notas"> · {{ d.notas }}</span></p>
        </div>
        <a :href="d.url" class="text-xs text-violeta">bajar</a>
        <button class="text-xs text-carmin" @click="borrar(d)">quitar</button>
      </div>
      <p v-if="!docs.length" class="text-sm text-marca-muted text-center py-4">Sin documentos. Hasta 10 MB por archivo: PDF, fotos, planillas, Word.</p>
    </div>
    <!-- Fase 59: preguntarle a los documentos en castellano -->
    <div v-if="docs.some(d => d.leido)" class="mt-3 pt-3 border-t border-marca-borde/60" data-e2e="preguntar-docs">
      <form class="flex gap-2" @submit.prevent="preguntar">
        <input v-model="pregunta" class="input flex-1 text-sm" placeholder="Preguntale a estos documentos: ¿cuándo vence el contrato? ¿qué precio tiene el caño de 4 m?" maxlength="500" />
        <button class="btn-secondary !py-1 text-xs whitespace-nowrap" :disabled="preguntando || !pregunta.trim()">{{ preguntando ? 'Buscando…' : '✦ Preguntar' }}</button>
      </form>
      <div v-if="rta" class="mt-2 text-sm">
        <p v-if="rta.respuesta" class="whitespace-pre-line bg-marca-fondo rounded-lg p-3">{{ rta.respuesta }}</p>
        <p v-else-if="!rta.fuentes.length" class="text-marca-muted">Ningún documento habla de eso.</p>
        <p v-else-if="!rta.ia" class="text-xs text-marca-muted mb-1">Sin clave de IA sólo se muestran los pedazos que coinciden (Configuración → IA para respuestas en castellano).</p>
        <details v-if="rta.fuentes.length" class="mt-1 text-xs"><summary class="cursor-pointer text-violeta">Dónde lo encontré ({{ rta.fuentes.length }})</summary>
          <div v-for="(f, i) in rta.fuentes" :key="i" class="mt-2 border-l-2 border-marca-borde pl-2"><a :href="f.url" target="_blank" class="font-semibold hover:text-carmin">{{ f.nombre }}</a><span v-if="f.fecha" class="text-marca-muted"> · {{ f.fecha }}</span><p class="text-marca-muted whitespace-pre-line line-clamp-4">{{ f.fragmento }}</p></div>
        </details>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
const props = defineProps({ tipo: { type: String, required: true }, id: { type: Number, required: true }, ayuda: { type: String, default: 'Contratos, constancias, fichas técnicas, fotos: todo lo que tenga que ver con esta cuenta.' } })
const docs = ref([]), cargando = ref(true), subiendo = ref(false), error = ref('')
const ext = n => (n.split('.').pop() || '').slice(0, 4)
const peso = b => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'
async function cargar() { cargando.value = true; try { docs.value = (await window.axios.get(`/documentos/${props.tipo}/${props.id}`)).data } finally { cargando.value = false } }
async function subir(e) {
  const files = [...e.target.files]; if (!files.length) return
  subiendo.value = true; error.value = ''
  try {
    for (const f of files) { const fd = new FormData(); fd.append('archivo', f); await window.axios.post(`/documentos/${props.tipo}/${props.id}`, fd) }
  } catch (err) { error.value = Object.values(err.response?.data?.errors ?? {}).flat().join(' ') || 'No se pudo subir el archivo.' }
  finally { subiendo.value = false; e.target.value = ''; await cargar() }
}
async function borrar(d) { await window.axios.delete(`/documentos/${d.id}`); await cargar() }
const pregunta = ref(''), rta = ref(null), preguntando = ref(false)
async function preguntar() { if (!pregunta.value.trim()) return; preguntando.value = true; rta.value = null; try { rta.value = (await window.axios.post('/documentos/preguntar', { pregunta: pregunta.value, tipo: props.tipo, id: props.id })).data } catch (e) { error.value = 'No se pudo preguntar.' } finally { preguntando.value = false } }
onMounted(cargar)
</script>
