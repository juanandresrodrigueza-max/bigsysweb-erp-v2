// Fase 119.2: voz por servidor, para que Bigcito escuche y hable igual en cualquier navegador o celular.
// - useGrabadorVoz: graba con el micrófono y corta sola cuando dejás de hablar (o al tocar de nuevo). Entrega el audio para transcribir.
// - useVozServidor: reproduce la respuesta hablada que arma el servidor (voz natural). Si no hay, el panel usa la voz del navegador.
import { ref } from 'vue'

export function useGrabadorVoz(onAudio, { silencioMs = 1300, esperaMs = 7000, maxMs = 30000 } = {}) {
  const soportado = typeof window !== 'undefined' && !!window.MediaRecorder && !!navigator.mediaDevices?.getUserMedia
  const escuchando = ref(false)
  const nivel = ref(0)
  let rec = null, stream = null, ctx = null, raf = null, tope = null, trozos = [], huboVoz = false, cancelado = false, forzado = false, mime = ''

  async function iniciar() {
    if (!soportado || escuchando.value) return false
    try { stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }) } catch { onAudio(null, null, 'permiso'); return false }
    trozos = []; huboVoz = false; cancelado = false; forzado = false
    mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'].find(m => { try { return MediaRecorder.isTypeSupported(m) } catch { return false } }) || ''
    try { rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined) } catch { rec = new MediaRecorder(stream) }
    rec.ondataavailable = e => { if (e.data?.size) trozos.push(e.data) }
    rec.onstop = () => {
      const tipo = rec.mimeType || mime || 'audio/webm'
      limpiar()
      if (!cancelado && (huboVoz || forzado) && trozos.length) onAudio(new Blob(trozos, { type: tipo }), tipo)
      else onAudio(null, null, cancelado ? 'cancelado' : 'silencio')
    }
    rec.start(250); escuchando.value = true
    vigilar()
    return true
  }

  // Mira el volumen: cuando hubo voz y después un silencio, corta. Si nadie habla en unos segundos, también.
  function vigilar() {
    tope = setTimeout(() => escuchando.value && detener(), maxMs)
    try {
      const AC = window.AudioContext || window.webkitAudioContext; ctx = new AC()
      const an = ctx.createAnalyser(); an.fftSize = 1024; ctx.createMediaStreamSource(stream).connect(an)
      const buf = new Uint8Array(an.fftSize); const t0 = performance.now(); let ultimoSonido = t0, ruido = 0.012
      const paso = () => {
        if (!escuchando.value) return
        an.getByteTimeDomainData(buf); let s = 0; for (let i = 0; i < buf.length; i++) { const v = (buf[i] - 128) / 128; s += v * v }
        const rms = Math.sqrt(s / buf.length); nivel.value = rms
        const ahora = performance.now()
        if (rms > Math.max(0.03, ruido * 3)) { huboVoz = true; ultimoSonido = ahora } else ruido = ruido * 0.95 + rms * 0.05
        if (huboVoz && ahora - ultimoSonido > silencioMs) return detener()
        if (!huboVoz && ahora - t0 > esperaMs) return detener()
        raf = requestAnimationFrame(paso)
      }
      raf = requestAnimationFrame(paso)
    } catch { /* sin analizador de audio: se corta tocando de nuevo o al llegar al máximo */ }
  }

  function limpiar() {
    escuchando.value = false; nivel.value = 0
    if (raf) cancelAnimationFrame(raf); raf = null
    if (tope) clearTimeout(tope); tope = null
    try { stream?.getTracks().forEach(t => t.stop()) } catch {}
    try { ctx?.close() } catch {}
    ctx = null; stream = null
  }
  // manual = true: el usuario tocó para cortar; se manda aunque el medidor no haya detectado voz.
  function detener(manual = false) { if (manual) forzado = true; try { if (rec && rec.state !== 'inactive') rec.stop(); else limpiar() } catch { limpiar() } }
  function cancelar() { if (!escuchando.value) return; cancelado = true; detener() }
  return { soportado, escuchando, nivel, iniciar, detener, cancelar }
}

export function useVozServidor() {
  const hablando = ref(false)
  let audio = null, url = null

  // En iPhone el primer sonido tiene que salir de un toque del usuario: se "destraba" el reproductor con un silencio.
  function destrabar() {
    if (audio || typeof Audio === 'undefined') return
    audio = new Audio(); audio.playsInline = true
    audio.src = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA='
    audio.play().catch(() => {})
  }

  // Pide el audio y lo reproduce. Devuelve true si habló; false si no hay voz por servidor (el panel usa la del navegador).
  async function decir(texto, alTerminar) {
    try {
      const r = await window.axios.post('/agente/voz', { texto }, { responseType: 'blob' })
      if (r.status !== 200 || !r.data?.size) return false
      if (!audio) { audio = new Audio(); audio.playsInline = true }
      if (url) URL.revokeObjectURL(url)
      url = URL.createObjectURL(r.data)
      const fin = () => { if (!hablando.value) return; hablando.value = false; alTerminar?.() }
      audio.onended = fin; audio.onerror = fin
      audio.src = url; hablando.value = true
      await audio.play()
      return true
    } catch { hablando.value = false; return false }
  }
  function callar() { try { audio?.pause() } catch {} hablando.value = false }
  return { hablando, decir, callar, destrabar }
}
