<template>
  <div class="fixed bottom-5 right-5 z-50">
    <transition name="fade">
      <div v-if="abierto" class="absolute bottom-16 right-0 w-[360px] max-w-[calc(100vw-2.5rem)] h-[520px] max-h-[calc(100vh-7rem)] bg-white rounded-2xl shadow-2xl border border-marca-borde flex flex-col overflow-hidden">
        <div class="px-4 py-3 text-white flex items-center justify-between bg-violeta-grad">
          <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center"><Icono nombre="sparkles" clase="w-4 h-4" /></span>
            <div>
              <p class="text-sm font-bold leading-tight">Bigcito</p>
              <p class="text-[11px] text-white/80 leading-tight">{{ modo === 'ia' ? 'Con IA · conoce tu negocio' : 'Modo básico' }}</p>
            </div>
          </div>
          <div class="flex items-center gap-1"><button v-if="vozSoportada" @click="alternarVoz" class="p-1 rounded hover:bg-white/15 text-xs" :title="habla ? 'Bigcito responde en voz alta (tocá para silenciar)' : 'Que Bigcito responda en voz alta'" data-e2e="voz">{{ habla ? '🔊' : '🔇' }}</button>
          <button @click="abierto = false" class="p-1 rounded hover:bg-white/15"><Icono nombre="x" clase="w-4 h-4" /></button></div>
        </div>

        <div ref="scroll" class="flex-1 overflow-y-auto px-3 py-3 space-y-2 bg-marca-fondo">
          <div v-if="!mensajes.length" class="text-xs text-marca-muted space-y-2 px-1 pt-1">
            <p>Hola {{ nombre }}. Preguntame cómo hacer algo en el sistema o consultame datos de {{ empresa }}.</p>
            <div class="flex flex-wrap gap-1.5">
              <button v-for="s in sugerencias" :key="s" @click="enviar(s)" class="px-2.5 py-1 rounded-full bg-white border border-marca-borde text-marca-texto hover:border-violeta text-[11px]">{{ s }}</button>
            </div>
          </div>
          <div v-for="(m, i) in mensajes" :key="i" :class="m.rol === 'user' ? 'justify-end' : 'justify-start'" class="flex">
            <div :class="m.rol === 'user' ? 'bg-carmin text-white rounded-br-sm' : 'bg-white border border-marca-borde text-marca-texto rounded-bl-sm'" class="max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap leading-snug">
              {{ m.texto }}
              <div v-if="m.propuesta" class="mt-2 rounded-xl border p-2 text-xs" :class="m.propuesta.estado === 'hecho' ? 'border-emerald-200 bg-emerald-50' : m.propuesta.estado === 'cancelado' ? 'border-marca-borde bg-marca-fondo opacity-70' : 'border-violeta/30 bg-violeta-light/40'">
                <p class="font-bold text-sm">{{ m.propuesta.titulo }}</p>
                <p class="text-marca-muted mt-0.5">{{ m.propuesta.detalle }}</p>
                <div v-if="!m.propuesta.estado" class="flex gap-2 mt-2"><button class="btn-primary !py-1 !px-3 text-xs" :disabled="ejecutando" @click="confirmar(m)">{{ ejecutando ? 'Haciendo…' : 'Confirmar' }}</button><button class="btn-secondary !py-1 !px-3 text-xs" @click="cancelar(m)">Cancelar</button></div>
                <p v-else-if="m.propuesta.estado === 'cancelado'" class="mt-1">Cancelado.</p>
              </div>
              <a v-if="m.url" :href="m.url" class="block mt-1 text-xs text-violeta font-semibold underline">Ver →</a>
            </div>
          </div>
          <div v-if="cargando" class="flex"><div class="bg-white border border-marca-borde rounded-2xl rounded-bl-sm px-3 py-2 text-sm text-marca-muted">Pensando…</div></div>
        </div>

        <form @submit.prevent="enviar()" class="p-2 border-t border-marca-borde flex gap-2 bg-white">
          <input v-model="texto" class="input rounded-full" :placeholder="dictado.escuchando.value ? 'Escuchando… hablá' : 'Escribí tu consulta…'" :disabled="cargando" />
          <button v-if="dictado.soportado" type="button" class="btn-secondary !px-3 rounded-full" :class="dictado.escuchando.value ? '!bg-carmin !text-white !border-carmin animate-pulse' : ''" :title="dictado.escuchando.value ? 'Parar' : 'Dictar por voz'" @click="dictado.alternar()"><Icono nombre="mic" clase="w-4 h-4" /></button>
          <button type="submit" class="btn-primary !px-3" :disabled="cargando || !texto.trim()"><Icono nombre="send" clase="w-4 h-4" /></button>
        </form>
      </div>
    </transition>

    <button @click="abierto = !abierto" class="w-14 h-14 rounded-full text-white shadow-pop flex items-center justify-center hover:scale-105 transition bg-violeta-grad" aria-label="Asistente">
      <Icono :nombre="abierto ? 'x' : 'sparkles'" clase="w-6 h-6" />
    </button>
  </div>
</template>

<script setup>
import { ref, nextTick, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'
import { useDictado } from '@/util/dictado'

const page = usePage()
const abierto = ref(false)
const texto = ref('')
// Dictado: el texto final reemplaza lo dictado y se manda solo.
let baseDictado = ''
const dictado = useDictado((t, final) => { texto.value = (baseDictado ? baseDictado + ' ' : '') + t; if (final) { baseDictado = ''; enviar(undefined, true) } })

// Bigcito habla: si le hablaste, te contesta en voz alta (y te pregunta antes de hacer algo). "Sí" o "dale" confirma, "no" cancela.
const vozSoportada = typeof window !== 'undefined' && 'speechSynthesis' in window
const habla = ref((() => { try { return localStorage.getItem('bigcito_voz') !== '0' } catch { return true } })())
function alternarVoz() { habla.value = !habla.value; try { localStorage.setItem('bigcito_voz', habla.value ? '1' : '0') } catch {} if (!habla.value) window.speechSynthesis?.cancel() }
function decir(t) {
  if (!vozSoportada || !habla.value || !t) return
  const limpio = t.replace(/https?:\/\/\S+/g, '').replace(/[*_#`]/g, '').replace(/\$\s?([\d.]+),(\d{2})/g, '$1 pesos con $2').slice(0, 600)
  const u = new SpeechSynthesisUtterance(limpio); u.lang = 'es-AR'; u.rate = 1.05
  const v = window.speechSynthesis.getVoices().find(x => x.lang?.startsWith('es')); if (v) u.voice = v
  window.speechSynthesis.cancel(); window.speechSynthesis.speak(u)
}
const pendiente = () => [...mensajes.value].reverse().find(m => m.propuesta && !m.propuesta.estado)

const cargando = ref(false)
const modo = ref('ia')
const mensajes = ref([])
const scroll = ref(null)

const nombre = computed(() => page.props.auth?.user?.name?.split(' ')[0] ?? '')
const empresa = computed(() => page.props.empresa?.nombre ?? 'tu empresa')
const sugerencias = ['¿Cuánto vendí hoy?', '¿Quiénes me deben?', 'Cobrale 10000 a … en efectivo', 'Gasté 5000 en nafta', 'Stock de cemento', 'Recordale a … la deuda']
const ejecutando = ref(false)
async function confirmar(m) {
  ejecutando.value = true
  try {
    const { data } = await window.axios.post('/agente/ejecutar', { propuesta_id: m.propuesta.id })
    m.propuesta.estado = 'hecho'
    mensajes.value.push({ rol: 'assistant', texto: data.texto ?? 'Listo.', url: data.url ?? null })
    if (porVozUltimo) decir(data.texto ?? 'Listo.')
  } catch (e) {
    mensajes.value.push({ rol: 'assistant', texto: e.response?.data?.message ?? 'No se pudo hacer. ' + (e.response?.status === 403 ? 'No tenés permiso para esta acción.' : 'Probá de nuevo.') })
  } finally { ejecutando.value = false; await bajar() }
}

function cancelar(m) { m.propuesta.estado = 'cancelado'; window.axios.post('/agente/ejecutar', { propuesta_id: m.propuesta.id, cancelar: true }).catch(() => {}) }

let porVozUltimo = false
async function enviar(pre, porVoz = false) {
  const msg = (pre ?? texto.value).trim()
  if (!msg || cargando.value) return
  porVozUltimo = porVoz
  // Confirmar o cancelar hablando lo que Bigcito propuso.
  const p = pendiente()
  if (p && porVoz && /^(s[ií]|dale|confirm|hacelo|hacé(lo)?|de una|ok|okey|listo|adelante)\b/i.test(msg)) { texto.value = ''; mensajes.value.push({ rol: 'user', texto: msg }); return confirmar(p) }
  if (p && porVoz && /^(no|cancel|dej[aá]|par[aá])\b/i.test(msg)) { texto.value = ''; mensajes.value.push({ rol: 'user', texto: msg }); cancelar(p); mensajes.value.push({ rol: 'assistant', texto: 'Listo, no lo hago.' }); decir('Listo, no lo hago.'); return }
  texto.value = ''
  const historial = mensajes.value.slice(-10).map(m => ({ rol: m.rol, texto: m.texto + (m.propuesta ? ` [propuesta: ${m.propuesta.titulo} → ${m.propuesta.estado ?? 'sin confirmar'}]` : '') }))
  mensajes.value.push({ rol: 'user', texto: msg })
  cargando.value = true
  await bajar()
  try {
    const { data } = await window.axios.post('/agente/chat', { mensaje: msg, historial, pantalla: page.component })
    modo.value = data.modo ?? 'ia'
    mensajes.value.push({ rol: 'assistant', texto: data.respuesta ?? 'No pude responder.', propuesta: data.propuesta ?? null })
    if (porVoz) decir((data.respuesta ?? '') + (data.propuesta ? ` ${data.propuesta.titulo}. ¿Lo hago? Decime sí o no.` : ''))
  } catch (e) {
    const st = e.response?.status
    mensajes.value.push({ rol: 'assistant', texto: st === 419 ? 'Tu sesión expiró. Recargá la página y volvé a intentar.' : `No pude conectarme${st ? ` (HTTP ${st})` : ''}. Probá de nuevo en unos segundos.` })
  } finally {
    cargando.value = false
    await bajar()
  }
}

async function bajar() {
  await nextTick()
  if (scroll.value) scroll.value.scrollTop = scroll.value.scrollHeight
}
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .15s, transform .15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(8px); }
</style>
