<template>
  <div class="fixed bottom-20 md:bottom-5 right-4 md:right-5 z-50">
    <transition name="fade">
      <div v-if="abierto" class="absolute bottom-16 right-0 w-[360px] max-w-[calc(100vw-2.5rem)] h-[520px] max-h-[calc(100vh-7rem)] bg-white rounded-3xl shadow-2xl border border-marca-borde flex flex-col overflow-hidden">
        <div class="px-4 py-3 text-white flex items-center justify-between bg-ciruela-grad">
          <div class="flex items-center gap-2">
            <img src="/img/bigcito-cara.webp" alt="Bigcito" class="w-9 h-9 rounded-full bg-white object-cover ring-2 ring-white/40" />
            <div>
              <p class="text-sm font-bold leading-tight">Bigcito</p>
              <p class="text-[11px] text-white/80 leading-tight">{{ modo === 'ia' ? 'Con IA · conoce tu negocio' : 'Modo básico' }}</p>
            </div>
          </div>
          <div class="flex items-center gap-1"><button v-if="vozSoportada" @click="alternarVoz" class="p-1 rounded hover:bg-white/15 text-xs" :title="habla ? 'Bigcito responde en voz alta (tocá para silenciar)' : 'Que Bigcito responda en voz alta'" data-e2e="voz">{{ habla ? '🔊' : '🔇' }}</button>
          <button v-if="puedeOir" @click="alternarManos" class="p-1 rounded hover:bg-white/15 text-xs" :class="manosLibres ? 'bg-white/25 ring-1 ring-white/60' : ''" :title="manosLibres ? 'Manos libres activado: después de contestarte vuelve a escuchar (tocá para apagar)' : 'Manos libres: hablale, te contesta y vuelve a escuchar sin tocar nada'" data-e2e="manos-libres">🎙️</button>
          <button v-if="desp.soportado" @click="alternarDespertar" class="p-1 rounded hover:bg-white/15 text-xs" :class="despertar ? 'bg-white/25 ring-1 ring-white/60' : ''" :title="despertar ? 'Te escucho de fondo: decí «Hola Bigcito» y te atiendo (tocá para apagar)' : 'Llamarlo con la voz: decí «Hola Bigcito» desde cualquier pantalla, sin tocar nada'" data-e2e="despertar">👂</button>
          <a v-if="whatsappSoporte" :href="whatsappSoporte" target="_blank" class="p-1 rounded hover:bg-white/15 text-xs" title="Hablar con una persona de soporte por WhatsApp (con lo que venías preguntando)" data-e2e="bigcito-soporte">🙋</a>
          <button @click="abierto = false" class="p-1 rounded hover:bg-white/15" title="Cerrar" aria-label="Cerrar"><Icono nombre="x" clase="w-4 h-4" /></button></div>
        </div>

        <div ref="scroll" class="flex-1 overflow-y-auto px-3 py-3 space-y-2 bg-marca-fondo">
          <div v-if="!mensajes.length" class="text-xs text-marca-muted space-y-2 px-1 pt-1">
            <p>Hola {{ nombre }}. Preguntame cómo hacer algo, consultame datos de {{ empresa }} o pedime que haga cosas: facturar, cobrar, cargar un cliente, cambiar un precio. Siempre te muestro qué voy a hacer y espero tu sí.</p>
            <p v-if="puedeOir" class="text-[11px]">Tocá el micrófono o apretá <kbd class="px-1 rounded border border-marca-borde bg-white">Alt</kbd>+<kbd class="px-1 rounded border border-marca-borde bg-white">B</kbd> desde cualquier pantalla. Con 🎙️ (manos libres) te vuelve a escuchar después de contestarte.<span v-if="desp.soportado"> Con 👂 lo llamás diciendo «Hola Bigcito».</span> Para terminar, decile «nada más».</p>
            <div class="flex flex-wrap gap-1.5">
              <button v-for="s in sugerencias" :key="s" @click="enviar(s)" class="px-2.5 py-1 rounded-full bg-white border border-marca-borde text-marca-texto hover:border-violeta text-[11px]">{{ s }}</button>
            </div>
          </div>
          <div v-for="(m, i) in mensajes" :key="i" :class="m.rol === 'user' ? 'justify-end' : 'justify-start'" class="flex">
            <div :class="m.rol === 'user' ? 'bg-marca-grad text-white rounded-br-sm' : 'bg-white border border-marca-borde text-marca-texto rounded-bl-sm'" class="max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap leading-snug">
              <img v-if="m.foto" :src="m.foto" class="w-32 rounded-lg mb-1" alt="" />{{ m.texto }}
              <div v-if="m.propuesta" class="mt-2 rounded-xl border p-2 text-xs" :class="m.propuesta.estado === 'hecho' ? 'border-emerald-200 bg-emerald-50' : m.propuesta.estado === 'cancelado' ? 'border-marca-borde bg-marca-fondo opacity-70' : 'border-violeta/30 bg-violeta-light/40'">
                <p class="font-bold text-sm">{{ m.propuesta.titulo }}</p>
                <p class="text-marca-muted mt-0.5">{{ m.propuesta.detalle }}</p>
                <div v-if="!m.propuesta.estado" class="flex gap-2 mt-2"><button class="btn-primary !py-1 !px-3 text-xs" :disabled="ejecutando" @click="confirmar(m)">{{ ejecutando ? 'Haciendo…' : 'Confirmar' }}</button><button class="btn-secondary !py-1 !px-3 text-xs" @click="cancelar(m)">Cancelar</button></div>
                <p v-else-if="m.propuesta.estado === 'cancelado'" class="mt-1">Cancelado.</p>
              </div>
              <a v-if="m.url" :href="m.url" :target="m.nueva ? '_blank' : null" class="block mt-1 text-xs text-violeta font-semibold underline">{{ m.nueva ? 'Abrir para imprimir →' : 'Ver →' }}</a>
              <!-- Fase 40.1: gráfico, planilla y repreguntas -->
              <div v-if="m.grafico?.valores?.length" class="mt-2" data-e2e="grafico"><p class="text-[11px] font-semibold text-marca-muted mb-1">{{ m.grafico.titulo }}</p>
                <div v-for="(v, k) in m.grafico.valores" :key="k" class="flex items-center gap-1.5 text-[11px] leading-4"><span class="w-24 truncate text-marca-muted">{{ m.grafico.etiquetas[k] }}</span><div class="flex-1 h-2.5 rounded bg-gris-light"><div class="h-2.5 rounded" :class="String(m.grafico.etiquetas[k]).includes('proy') ? 'bg-violeta/50' : 'bg-violeta'" :style="{ width: Math.max(2, v / Math.max(...m.grafico.valores, 1) * 100) + '%' }"></div></div><span class="w-16 text-right tabular-nums">{{ abreviar(v) }}</span></div></div>
              <button v-if="m.tabla?.filas?.length" class="mt-2 text-xs text-violeta font-semibold underline" @click="excel(m.tabla)" data-e2e="excel">Bajar la lista a Excel ({{ m.tabla.filas.length }} filas)</button>
              <div v-if="m.sugerencias?.length" class="flex flex-wrap gap-1 mt-2"><button v-for="q in m.sugerencias" :key="q" class="px-2 py-0.5 rounded-full bg-marca-fondo border border-marca-borde text-[11px] hover:border-violeta" @click="enviar(q)" data-e2e="repregunta">{{ q }}</button></div>
            </div>
          </div>
          <div v-if="cargando" class="flex"><div class="bg-white border border-marca-borde rounded-2xl rounded-bl-sm px-3 py-2 text-sm text-marca-muted">Pensando…</div></div>
        </div>

        <div v-if="foto" class="px-3 pt-2 flex items-center gap-2 text-xs text-marca-muted bg-white border-t border-marca-borde"><img :src="foto" class="w-10 h-10 object-cover rounded-lg" alt="" /> Foto lista: escribí qué querés saber. <button type="button" class="text-carmin" @click="foto = null">Sacar</button></div>
        <form @submit.prevent="enviar()" class="p-2 border-t border-marca-borde flex gap-2 bg-white">
          <input v-model="texto" class="input rounded-full" :placeholder="oyendo ? 'Escuchando… hablá' : (transcribiendo ? 'Pasando tu audio a texto…' : 'Escribí tu consulta…')" :disabled="cargando" />
          <input ref="fotoInput" type="file" accept="image/*" capture="environment" class="hidden" @change="elegirFoto" />
          <button type="button" class="btn-secondary !px-3 rounded-full" :class="foto ? '!border-violeta !text-violeta' : ''" title="Mandar una foto" @click="fotoInput.click()" data-e2e="bigcito-foto">📷</button>
          <button v-if="puedeOir" type="button" class="btn-secondary !px-3 rounded-full" :class="oyendo ? '!bg-carmin !text-white !border-carmin animate-pulse' : ''" :title="oyendo ? 'Parar' : 'Hablarle a Bigcito'" @click="alternarOido" data-e2e="bigcito-mic"><Icono nombre="mic" clase="w-4 h-4" /></button>
          <button type="submit" class="btn-primary !px-3" :disabled="cargando || (!texto.trim() && !foto)" title="Enviar" aria-label="Enviar"><Icono nombre="send" clase="w-4 h-4" /></button>
        </form>
      </div>
    </transition>

    <button @click="abierto = !abierto" class="w-16 h-16 rounded-full text-white shadow-pop flex items-center justify-center hover:scale-105 transition overflow-hidden ring-4 ring-white" :class="abierto ? 'bg-violeta-grad' : 'bg-white'" aria-label="Bigcito" title="Hablá con Bigcito">
      <Icono v-if="abierto" nombre="x" clase="w-6 h-6" />
      <img v-else src="/img/bigcito-cara.webp" alt="Bigcito" class="w-full h-full object-cover" />
    </button>
    <span v-if="despertar && desp.activo.value && !abierto" class="absolute bottom-[50px] right-0 w-3.5 h-3.5 rounded-full bg-emerald-400 ring-2 ring-white animate-pulse" title="Decí «Hola Bigcito» y te atiendo" data-e2e="despertar-activo"></span>
  </div>
</template>

<script setup>
import { ref, nextTick, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'
import { useDictado, useDespertador } from '@/util/dictado'
import { useGrabadorVoz, useVozServidor } from '@/util/vozServidor'

const page = usePage()
// Fase 119.1: la charla sigue cuando Bigcito te lleva a otra pantalla (el panel se vuelve a montar en cada página).
// Se guarda por pestaña, por usuario y por empresa; no guarda las fotos.
const CLAVE = `bigcito_chat_${page.props.auth?.user?.id ?? 0}_${page.props.empresa?.id ?? 0}`
const guardado = (() => { try { return JSON.parse(sessionStorage.getItem(CLAVE) || 'null') } catch { return null } })()
const abierto = ref(!!guardado?.abierto)
const yo = Symbol('bigcito'); if (typeof window !== 'undefined') window.__bigcito = yo
const texto = ref('')
// Dictado: el texto final reemplaza lo dictado y se manda solo.
let baseDictado = ''
const dictado = useDictado((t, final) => { texto.value = (baseDictado ? baseDictado + ' ' : '') + t; if (final) { baseDictado = ''; enviar(undefined, true) } })

// Fase 119.2: el oído. Donde el navegador dicta (Chrome, Edge, Android) se usa eso, que es instantáneo; donde no
// (iPhone, Safari, Firefox) se graba, se corta sola al dejar de hablar y el servidor la pasa a texto.
const vozSrv = !!page.props.vozServidor
const transcribiendo = ref(false)
const grab = useGrabadorVoz(async (blob, mime, motivo) => {
  if (!blob) { if (motivo === 'permiso') mensajes.value.push({ rol: 'assistant', texto: 'No tengo permiso para usar el micrófono. Habilitalo en el navegador y probá de nuevo.' }); return }
  const fd = new FormData(); fd.append('audio', blob, 'audio.' + (mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm')); fd.append('mime', mime)
  transcribiendo.value = true
  try { const { data } = await window.axios.post('/agente/audio', fd); transcribiendo.value = false; if (data.texto) enviar(data.texto, true); else mensajes.value.push({ rol: 'assistant', texto: data.error ?? 'No entendí el audio.' }) }
  catch (e) { transcribiendo.value = false; mensajes.value.push({ rol: 'assistant', texto: e.response?.data?.errors?.audio?.[0] ?? e.response?.data?.error ?? e.response?.data?.message ?? 'No pude pasar el audio a texto.' }); bajar() }
})
const usaGrabador = !dictado.soportado && grab.soportado
const puedeOir = dictado.soportado || usaGrabador
const oyendo = computed(() => dictado.escuchando.value || grab.escuchando.value)
const vozS = useVozServidor()
function callar() { vozS.callar(); try { window.speechSynthesis?.cancel() } catch {} }
function escuchar() {
  if (oyendo.value || cargando.value || transcribiendo.value) return
  callar(); vozS.destrabar(); desp.pausar()
  if (dictado.soportado) dictado.iniciar(); else if (usaGrabador) grab.iniciar()
}
function dejarDeEscuchar() { dictado.detener(); grab.cancelar() }
function alternarOido() { if (!oyendo.value) return escuchar(); if (dictado.soportado) dictado.detener(); else grab.detener(true) }

// Bigcito habla: si le hablaste, te contesta en voz alta (y te pregunta antes de hacer algo). "Sí" o "dale" confirma, "no" cancela.
// Con voz por servidor suena natural; si no hay, usa la del navegador.
const vozNavegador = typeof window !== 'undefined' && 'speechSynthesis' in window
const vozSoportada = vozNavegador || vozSrv
const habla = ref((() => { try { return localStorage.getItem('bigcito_voz') !== '0' } catch { return true } })())
function alternarVoz() { habla.value = !habla.value; try { localStorage.setItem('bigcito_voz', habla.value ? '1' : '0') } catch {} if (!habla.value) callar() }
function decir(t) {
  if (!habla.value || !t) return volverAEscuchar()
  if (vozSrv) { vozS.decir(t, volverAEscuchar).then(ok => { if (!ok) decirNavegador(t) }); return }
  decirNavegador(t)
}
function decirNavegador(t) {
  if (!vozNavegador) return volverAEscuchar()
  const limpio = t.replace(/https?:\/\/\S+/g, '').replace(/[*_#`]/g, '').replace(/\$\s?([\d.]+),(\d{2})/g, '$1 pesos con $2').slice(0, 600)
  const u = new SpeechSynthesisUtterance(limpio); u.lang = 'es-AR'; u.rate = 1.05
  const v = window.speechSynthesis.getVoices().find(x => x.lang?.startsWith('es')); if (v) u.voice = v
  u.onend = volverAEscuchar
  window.speechSynthesis.cancel(); window.speechSynthesis.speak(u)
}
// Fase 111: manos libres. Después de hablarte, Bigcito vuelve a escuchar solo; Alt+B lo abre escuchando desde cualquier pantalla.
const manosLibres = ref((() => { try { return localStorage.getItem('bigcito_manos') === '1' } catch { return false } })())
function alternarManos() {
  manosLibres.value = !manosLibres.value
  try { localStorage.setItem('bigcito_manos', manosLibres.value ? '1' : '0') } catch {}
  if (manosLibres.value) { habla.value = true; escuchar() } else dejarDeEscuchar()
}
function volverAEscuchar() {
  // Si en el medio Bigcito cambió de pantalla, este panel ya no es el que se ve: le pasa la posta al nuevo.
  if (window.__bigcito !== yo) { window.dispatchEvent(new Event('bigcito:escuchar')); return }
  if (noReescuchar) { noReescuchar = false; return }
  if (manosLibres.value && abierto.value && puedeOir) escuchar()
}
let noReescuchar = false
const atajo = e => {
  if (!e.altKey || e.ctrlKey || e.metaKey || (e.key || '').toLowerCase() !== 'b') return
  e.preventDefault(); abierto.value = true
  nextTick(() => { if (puedeOir) escuchar() })
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
const modo = ref(guardado?.modo ?? 'ia')
const mensajes = ref(Array.isArray(guardado?.mensajes) ? guardado.mensajes : [])
const scroll = ref(null)
watch([mensajes, abierto, modo], () => {
  try { sessionStorage.setItem(CLAVE, JSON.stringify({ abierto: abierto.value, modo: modo.value, mensajes: mensajes.value.slice(-30).map(m => ({ ...m, foto: undefined, tabla: m.tabla?.filas?.length > 200 ? null : m.tabla })) })) } catch {}
}, { deep: true })

// Fase 119.3: «Hola Bigcito». Con 👂 prendido escucha de fondo (donde el navegador dicta) y, cuando lo nombran, abre y atiende:
// «Bigcito, ¿cuánto vendí hoy?» manda la pregunta directo; «Hola Bigcito» solo, pita y se queda escuchando la orden.
const despertar = ref((() => { try { return localStorage.getItem('bigcito_despertar') === '1' } catch { return false } })())
function pitido() { try { const AC = window.AudioContext || window.webkitAudioContext; const c = new AC(); const o = c.createOscillator(), g = c.createGain(); o.frequency.value = 880; g.gain.value = 0.07; o.connect(g); g.connect(c.destination); o.start(); o.stop(c.currentTime + 0.12); o.onended = () => c.close() } catch {} }
const desp = useDespertador(resto => {
  if (window.speechSynthesis?.speaking || vozS.hablando.value || cargando.value) return // no se despierta con su propia voz
  abierto.value = true; desp.pausar(); pitido()
  if (resto) enviar(resto, true); else nextTick(() => escuchar())
})
function alternarDespertar() {
  despertar.value = !despertar.value
  try { localStorage.setItem('bigcito_despertar', despertar.value ? '1' : '0') } catch {}
  if (despertar.value) { habla.value = true; desp.encender() } else desp.apagar()
}
// Mientras escucha una orden, piensa o habla, el despertador descansa; cuando queda libre, vuelve a estar atento.
watch([oyendo, cargando, transcribiendo, () => vozS.hablando.value], ([o, c, t, h]) => {
  if (!despertar.value) return
  if (o || c || t || h) desp.pausar()
  else setTimeout(() => { if (despertar.value && !oyendo.value && !cargando.value && !transcribiendo.value && !vozS.hablando.value) desp.reanudar() }, 1200)
})

const nombre = computed(() => page.props.auth?.user?.name?.split(' ')[0] ?? '')
const empresa = computed(() => page.props.empresa?.nombre ?? 'tu empresa')
const sugerencias = ['¿Qué podés hacer?', '¿Cuánto vendí hoy?', '¿Quiénes me deben?', 'Abrime la ficha de …', 'Llevame a cerrar caja', 'Cobrale 10000 a … en efectivo', 'Facturale a … 2 bolsas de cemento', 'Gasté 5000 en nafta', 'Cambiale el teléfono a …']
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
// Fase 107: otra pantalla (el resumen hablado) abre a Bigcito con una pregunta ya escrita.
const alPreguntar = (e) => { abierto.value = true; nextTick(() => enviar(e.detail?.texto ?? '')) }
onMounted(() => { window.addEventListener('bigcito:preguntar', alPreguntar); window.addEventListener('keydown', atajo); window.addEventListener('bigcito:escuchar', volverAEscuchar); if (abierto.value) bajar(); if (despertar.value && desp.soportado) desp.encender() })
onBeforeUnmount(() => { window.removeEventListener('bigcito:preguntar', alPreguntar); window.removeEventListener('keydown', atajo); window.removeEventListener('bigcito:escuchar', volverAEscuchar); dejarDeEscuchar(); desp.apagar() })
// Fase 119.1: Bigcito abre pantallas. La charla queda guardada y sigue en la pantalla nueva.
function irA(ruta) {
  if (!ruta || ruta === location.pathname + location.search) return
  setTimeout(() => router.visit(ruta, { preserveScroll: false }), 250)
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
  // Fase 119.3: «nada más», «listo, gracias», «chau Bigcito» cierran la charla hablada.
  if (porVoz && /^(nada m[aá]s|eso es todo|eso era todo|listo,? gracias|gracias,? (nada m[aá]s|bigcito|eso es todo)|chau( bigcito)?|hasta luego( bigcito)?)[\s.!]*$/i.test(msg)) {
    texto.value = ''; mensajes.value.push({ rol: 'user', texto: msg }, { rol: 'assistant', texto: 'Dale. Cuando me necesites, llamame.' })
    noReescuchar = true; decir('Dale. Cuando me necesites, llamame.'); dejarDeEscuchar()
    setTimeout(() => { abierto.value = false }, 2600); await bajar(); return
  }
  texto.value = ''
  // El historial lleva qué se propuso y con qué datos, para que «lo mismo para García» o «otro igual» se entiendan.
  const historial = mensajes.value.slice(-12).map(m => ({ rol: m.rol, texto: (m.texto + (m.propuesta ? ` [propuesta ${m.propuesta.accion ?? ''}${m.propuesta.args ? ' ' + JSON.stringify(m.propuesta.args) : ''}: ${m.propuesta.titulo} → ${m.propuesta.estado ?? 'sin confirmar'}]` : '')).slice(0, 3900) }))
  const imagen = foto.value; foto.value = null
  mensajes.value.push({ rol: 'user', texto: msg, foto: imagen })
  cargando.value = true
  await bajar()
  try {
    const { data } = await window.axios.post('/agente/chat', { mensaje: msg, historial, pantalla: page.component, url: location.pathname + location.search, ...(imagen ? { imagen } : {}) })
    modo.value = data.modo ?? 'ia'
    mensajes.value.push({ rol: 'assistant', texto: data.respuesta ?? 'No pude responder.', propuesta: data.propuesta ?? null, tabla: data.tabla ?? null, grafico: data.grafico ?? null, sugerencias: data.sugerencias ?? [], url: data.abrir ?? null, nueva: !!data.abrir })
    if (data.abrir) { try { window.open(data.abrir, '_blank') } catch {} }
    if (data.navegar) irA(data.navegar)
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
