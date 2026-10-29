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
          <a v-if="whatsappSoporte" :href="whatsappSoporte" target="_blank" class="p-1 rounded hover:bg-white/15 text-xs" title="Hablar con una persona de soporte por WhatsApp (con lo que venías preguntando)" data-e2e="bigcito-soporte">🙋</a>
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
              <img v-if="m.foto" :src="m.foto" class="w-32 rounded-lg mb-1" alt="" />{{ m.texto }}
              <div v-if="m.propuesta" class="mt-2 rounded-xl border p-2 text-xs" :class="m.propuesta.estado === 'hecho' ? 'border-emerald-200 bg-emerald-50' : m.propuesta.estado === 'cancelado' ? 'border-marca-borde bg-marca-fondo opacity-70' : 'border-violeta/30 bg-violeta-light/40'">
                <p class="font-bold text-sm">{{ m.propuesta.titulo }}</p>
                <p class="text-marca-muted mt-0.5">{{ m.propuesta.detalle }}</p>
                <div v-if="!m.propuesta.estado" class="flex gap-2 mt-2"><button class="btn-primary !py-1 !px-3 text-xs" :disabled="ejecutando" @click="confirmar(m)">{{ ejecutando ? 'Haciendo…' : 'Confirmar' }}</button><button class="btn-secondary !py-1 !px-3 text-xs" @click="cancelar(m)">Cancelar</button></div>
                <p v-else-if="m.propuesta.estado === 'cancelado'" class="mt-1">Cancelado.</p>
              </div>
              <a v-if="m.url" :href="m.url" class="block mt-1 text-xs text-violeta font-semibold underline">Ver →</a>
              <!-- Fase 40.1: gráfico, planilla y repreguntas -->
              <div v-if="m.grafico?.valores?.length" class="mt-2" data-e2e="grafico"><p class="text-[11px] font-semibold text-marca-muted mb-1">{{ m.grafico.titulo }}</p>
                <div v-for="(v, k) in m.grafico.valores" :key="k" class="flex items-center gap-1.5 text-[10px] leading-4"><span class="w-24 truncate text-marca-muted">{{ m.grafico.etiquetas[k] }}</span><div class="flex-1 h-2.5 rounded bg-gris-light"><div class="h-2.5 rounded" :class="String(m.grafico.etiquetas[k]).includes('proy') ? 'bg-violeta/50' : 'bg-violeta'" :style="{ width: Math.max(2, v / Math.max(...m.grafico.valores, 1) * 100) + '%' }"></div></div><span class="w-16 text-right tabular-nums">{{ abreviar(v) }}</span></div></div>
              <button v-if="m.tabla?.filas?.length" class="mt-2 text-xs text-violeta font-semibold underline" @click="excel(m.tabla)" data-e2e="excel">Bajar la lista a Excel ({{ m.tabla.filas.length }} filas)</button>
              <div v-if="m.sugerencias?.length" class="flex flex-wrap gap-1 mt-2"><button v-for="q in m.sugerencias" :key="q" class="px-2 py-0.5 rounded-full bg-marca-fondo border border-marca-borde text-[11px] hover:border-violeta" @click="enviar(q)" data-e2e="repregunta">{{ q }}</button></div>
            </div>
          </div>
          <div v-if="cargando" class="flex"><div class="bg-white border border-marca-borde rounded-2xl rounded-bl-sm px-3 py-2 text-sm text-marca-muted">Pensando…</div></div>
        </div>

        <div v-if="foto" class="px-3 pt-2 flex items-center gap-2 text-xs text-marca-muted bg-white border-t border-marca-borde"><img :src="foto" class="w-10 h-10 object-cover rounded-lg" alt="" /> Foto lista: escribí qué querés saber. <button type="button" class="text-carmin" @click="foto = null">Sacar</button></div>
        <form @submit.prevent="enviar()" class="p-2 border-t border-marca-borde flex gap-2 bg-white">
          <input v-model="texto" class="input rounded-full" :placeholder="dictado.escuchando.value ? 'Escuchando… hablá' : 'Escribí tu consulta…'" :disabled="cargando" />
          <input ref="fotoInput" type="file" accept="image/*" capture="environment" class="hidden" @change="elegirFoto" />
          <button type="button" class="btn-secondary !px-3 rounded-full" :class="foto ? '!border-violeta !text-violeta' : ''" title="Mandar una foto" @click="fotoInput.click()" data-e2e="bigcito-foto">📷</button>
          <button v-if="!dictado.soportado && grabadorSoportado" type="button" class="btn-secondary !px-3 rounded-full" :class="grabando ? '!bg-carmin !text-white !border-carmin animate-pulse' : ''" :title="grabando ? 'Parar y mandar' : 'Mandar un audio'" @click="alternarGrabacion"><Icono nombre="mic" clase="w-4 h-4" /></button>
          <button v-if="dictado.soportado" type="button" class="btn-secondary !px-3 rounded-full" :class="dictado.escuchando.value ? '!bg-carmin !text-white !border-carmin animate-pulse' : ''" :title="dictado.escuchando.value ? 'Parar' : 'Dictar por voz'" @click="dictado.alternar()"><Icono nombre="mic" clase="w-4 h-4" /></button>
          <button type="submit" class="btn-primary !px-3" :disabled="cargando || (!texto.trim() && !foto)"><Icono nombre="send" clase="w-4 h-4" /></button>
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
// Fase 40.4: si Bigcito no alcanza, el WhatsApp a soporte sale ya escrito: quién, de qué empresa, en qué pantalla y qué preguntó.
const whatsappSoporte = computed(() => {
  const n = String(page.props.soporteWhatsapp ?? '').replace(/\D/g, ''); if (!n) return null
  const ultima = [...mensajes.value].reverse().find(m => m.rol === 'user')?.texto
  const t = `Hola, soy ${page.props.auth?.user?.name ?? ''} de ${page.props.empresa?.nombre ?? ''}. Estoy en la pantalla ${page.component}.` + (ultima ? ` Le pregunté a Bigcito: "${ultima.slice(0, 200)}".` : '') + ' Necesito una mano con: '
  return `https://wa.me/${n}?text=${encodeURIComponent(t)}`
})
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

const abreviar = n => Math.abs(n) >= 1e6 ? (n / 1e6).toFixed(1) + ' M' : Math.abs(n) >= 1e3 ? Math.round(n / 1e3) + ' mil' : Math.round(n)
// La planilla se arma acá mismo: CSV con separador ";" y BOM para que Excel lo abra con acentos.
function excel(t) {
  const esc = v => { const x = v === null || v === undefined ? '' : (typeof v === 'number' ? String(v).replace('.', ',') : String(v)); return /[;"\n]/.test(x) ? '"' + x.replace(/"/g, '""') + '"' : x }
  const csv = '\ufeff' + [t.columnas, ...t.filas].map(f => f.map(esc).join(';')).join('\n')
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); a.download = (t.nombre || 'bigcito') + '.csv'; a.click(); URL.revokeObjectURL(a.href)
}
let porVozUltimo = false
// Fase 40.3: foto con la pregunta (se achica antes de mandarla) y audio grabado cuando el navegador no dicta.
const fotoInput = ref(null), foto = ref(null)
function elegirFoto(e) {
  const f = e.target.files?.[0]; e.target.value = ''; if (!f) return
  const img = new Image(); img.onload = () => { const k = Math.min(1, 1400 / Math.max(img.width, img.height)); const c = document.createElement('canvas'); c.width = img.width * k; c.height = img.height * k; c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); foto.value = c.toDataURL('image/jpeg', 0.82); URL.revokeObjectURL(img.src) }
  img.src = URL.createObjectURL(f)
}
const grabadorSoportado = typeof window !== 'undefined' && !!window.MediaRecorder && !!navigator.mediaDevices?.getUserMedia
const grabando = ref(false); let grabador = null, trozos = []
async function alternarGrabacion() {
  if (grabando.value) { grabador?.stop(); return }
  try {
    const st = await navigator.mediaDevices.getUserMedia({ audio: true }); trozos = []
    grabador = new MediaRecorder(st); grabador.ondataavailable = ev => trozos.push(ev.data)
    grabador.onstop = async () => {
      grabando.value = false; st.getTracks().forEach(t => t.stop())
      const fd = new FormData(); fd.append('audio', new Blob(trozos, { type: grabador.mimeType || 'audio/webm' }), 'audio.webm')
      cargando.value = true
      try { const { data } = await window.axios.post('/agente/audio', fd); cargando.value = false; if (data.texto) enviar(data.texto, true); else mensajes.value.push({ rol: 'assistant', texto: data.error ?? 'No entendí el audio.' }) }
      catch (e) { cargando.value = false; mensajes.value.push({ rol: 'assistant', texto: e.response?.data?.error ?? e.response?.data?.message ?? 'No pude pasar el audio a texto.' }) }
    }
    grabador.start(); grabando.value = true
  } catch { mensajes.value.push({ rol: 'assistant', texto: 'No tengo permiso para usar el micrófono.' }) }
}
async function enviar(pre, porVoz = false) {
  let msg = (pre ?? texto.value).trim()
  if (!msg && foto.value) msg = '¿Qué ves en esta foto?'
  if (!msg || cargando.value) return
  porVozUltimo = porVoz
  // Confirmar o cancelar hablando lo que Bigcito propuso.
  const p = pendiente()
  if (p && porVoz && /^(s[ií]|dale|confirm|hacelo|hacé(lo)?|de una|ok|okey|listo|adelante)\b/i.test(msg)) { texto.value = ''; mensajes.value.push({ rol: 'user', texto: msg }); return confirmar(p) }
  if (p && porVoz && /^(no|cancel|dej[aá]|par[aá])\b/i.test(msg)) { texto.value = ''; mensajes.value.push({ rol: 'user', texto: msg }); cancelar(p); mensajes.value.push({ rol: 'assistant', texto: 'Listo, no lo hago.' }); decir('Listo, no lo hago.'); return }
  texto.value = ''
  const historial = mensajes.value.slice(-10).map(m => ({ rol: m.rol, texto: m.texto + (m.propuesta ? ` [propuesta: ${m.propuesta.titulo} → ${m.propuesta.estado ?? 'sin confirmar'}]` : '') }))
  const imagen = foto.value; foto.value = null
  mensajes.value.push({ rol: 'user', texto: msg, foto: imagen })
  cargando.value = true
  await bajar()
  try {
    const { data } = await window.axios.post('/agente/chat', { mensaje: msg, historial, pantalla: page.component, ...(imagen ? { imagen } : {}) })
    modo.value = data.modo ?? 'ia'
    mensajes.value.push({ rol: 'assistant', texto: data.respuesta ?? 'No pude responder.', propuesta: data.propuesta ?? null, tabla: data.tabla ?? null, grafico: data.grafico ?? null, sugerencias: data.sugerencias ?? [] })
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
