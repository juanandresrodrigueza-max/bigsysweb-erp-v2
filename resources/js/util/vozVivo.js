// Fase 120.4: conversación en vivo con Bigcito (OpenAI Realtime por WebRTC).
// Es un único objeto para toda la aplicación: la llamada sigue aunque Bigcito te lleve a otra pantalla
// (el panel se vuelve a montar, pero esto queda vivo). El panel sólo «atiende»: dice qué hacer con cada pedido.
import { reactive } from 'vue'

export const vivo = reactive({ estado: 'libre', error: null, desde: 0, pensando: false }) // libre | conectando | hablando
export const vivoSoportado = typeof window !== 'undefined' && !!window.RTCPeerConnection && !!navigator.mediaDevices?.getUserMedia

let pc = null, canal = null, mic = null, parlante = null, tope = null
// Después de devolverle un resultado, la respuesta que sigue es el modelo leyéndolo: eso ya quedó escrito en la charla
// (lo puso el Bigcito de siempre, con sus tarjetas), así que esa transcripción no se vuelve a escribir.
let trasHerramienta = false, esperaDespedida = false, idDespedida = null
const lecturas = new Set()
let manos = {} // { pedir(pedido), confirmar(), cancelar(), dijo(rol, texto), fin(motivo) }: lo pone el panel de Bigcito
const atendidas = new Set()

export function alAtender(m) { manos = m ?? {} }

const mandar = (ev) => { try { if (canal?.readyState === 'open') canal.send(JSON.stringify(ev)) } catch {} }

export async function llamar() {
  if (vivo.estado !== 'libre' || !vivoSoportado) return
  vivo.estado = 'conectando'; vivo.error = null; trasHerramienta = esperaDespedida = false; idDespedida = null; atendidas.clear(); lecturas.clear()
  try {
    const { data } = await window.axios.post('/agente/vivo')
    mic = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } })
    pc = new RTCPeerConnection()
    parlante = new Audio(); parlante.autoplay = true
    pc.ontrack = (e) => { parlante.srcObject = e.streams[0]; parlante.play?.().catch(() => {}) }
    pc.onconnectionstatechange = () => { if (['failed', 'closed', 'disconnected'].includes(pc?.connectionState) && vivo.estado !== 'libre') colgar('corte') }
    mic.getTracks().forEach(t => pc.addTrack(t, mic))
    canal = pc.createDataChannel('oai-events')
    canal.onopen = () => {
      vivo.estado = 'hablando'; vivo.desde = Date.now()
      if (data.sesion) mandar({ type: 'session.update', session: data.sesion })
      mandar({ type: 'response.create', response: { instructions: 'Saludá en pocas palabras y preguntá en qué podés ayudar.' } })
    }
    canal.onmessage = (e) => { try { alEvento(JSON.parse(e.data)) } catch {} }
    const oferta = await pc.createOffer(); await pc.setLocalDescription(oferta)
    const r = await fetch('https://api.openai.com/v1/realtime/calls?model=' + encodeURIComponent(data.modelo), { method: 'POST', body: oferta.sdp, headers: { Authorization: 'Bearer ' + data.token, 'Content-Type': 'application/sdp' } })
    if (!r.ok) throw new Error('El servicio de voz no aceptó la llamada (' + r.status + ').')
    await pc.setRemoteDescription({ type: 'answer', sdp: await r.text() })
    tope = setTimeout(() => colgar('tiempo'), (data.max_segundos ?? 300) * 1000)
  } catch (e) {
    const msg = e?.response?.data?.error ?? (e?.name === 'NotAllowedError' ? 'No tengo permiso para usar el micrófono. Habilitalo en el navegador y probá de nuevo.' : (e?.name === 'NotFoundError' ? 'No encontré un micrófono.' : (e?.message || 'No se pudo abrir la conversación en vivo.')))
    cerrar(); vivo.estado = 'libre'; vivo.error = msg
    manos.fin?.('error', msg)
  }
}

export function colgar(motivo = 'usuario') {
  if (vivo.estado === 'libre') return
  const segundos = vivo.desde ? Math.round((Date.now() - vivo.desde) / 1000) : 0
  cerrar(); vivo.estado = 'libre'; vivo.desde = 0; vivo.pensando = false
  if (segundos > 0) window.axios.post('/agente/vivo/fin', { segundos }).catch(() => {})
  manos.fin?.(motivo)
}

function cerrar() {
  clearTimeout(tope); tope = null
  try { canal?.close() } catch {}
  try { mic?.getTracks().forEach(t => t.stop()) } catch {}
  try { pc?.close() } catch {}
  try { if (parlante) { parlante.pause(); parlante.srcObject = null } } catch {}
  pc = canal = mic = parlante = null
}

async function alEvento(ev) {
  const t = ev.type
  // Lo que dijo la persona y lo que contestó Bigcito, para que quede escrito en la charla.
  if (t === 'conversation.item.input_audio_transcription.completed') { if (ev.transcript?.trim()) manos.dijo?.('user', ev.transcript.trim()); return }
  if (t === 'response.output_audio_transcript.done' || t === 'response.audio_transcript.done') { if (ev.transcript?.trim() && !lecturas.has(ev.response_id)) manos.dijo?.('assistant', ev.transcript.trim()); return }
  if (t === 'response.created') { if (trasHerramienta) { trasHerramienta = false; const id = ev.response?.id; if (id) { lecturas.add(id); if (esperaDespedida) idDespedida = id } } return }
  if (t === 'response.done') { if (idDespedida && ev.response?.id === idDespedida) setTimeout(() => colgar('despedida'), 1200); return }
  if (t === 'error') { console.warn('Voz en vivo:', ev.error?.message ?? ev); return }

  // El modelo pide algo: se lo pasamos al Bigcito de siempre y le devolvemos el resultado para que lo diga.
  let llamada = null
  if (t === 'response.function_call_arguments.done') llamada = { id: ev.call_id, nombre: ev.name, args: ev.arguments }
  else if (t === 'response.output_item.done' && ev.item?.type === 'function_call') llamada = { id: ev.item.call_id, nombre: ev.item.name, args: ev.item.arguments }
  if (!llamada?.id || !llamada.nombre || atendidas.has(llamada.id)) return
  atendidas.add(llamada.id)
  let args = {}; try { args = JSON.parse(llamada.args || '{}') } catch {}
  let salida
  vivo.pensando = true
  try {
    if (llamada.nombre === 'bigcito') salida = await manos.pedir?.(String(args.pedido ?? '')) ?? { respuesta: 'No pude consultar el sistema.' }
    else if (llamada.nombre === 'confirmar') salida = await manos.confirmar?.() ?? { ok: false }
    else if (llamada.nombre === 'cancelar') salida = await manos.cancelar?.() ?? { ok: true }
    else if (llamada.nombre === 'colgar') { esperaDespedida = true; setTimeout(() => colgar('despedida'), 9000); salida = { ok: true } } // corta cuando termina de despedirse (o a los 9 s)
    else salida = { error: 'Herramienta desconocida.' }
  } catch (e) { salida = { error: 'Falló la consulta al sistema. Pedile a la persona que lo repita.' } }
  vivo.pensando = false
  mandar({ type: 'conversation.item.create', item: { type: 'function_call_output', call_id: llamada.id, output: JSON.stringify(salida).slice(0, 6000) } })
  trasHerramienta = true
  mandar({ type: 'response.create' })
}
