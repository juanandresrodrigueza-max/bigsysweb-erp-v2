// Dictado por voz con el reconocimiento del navegador (Chrome, Edge, Safari): sin API paga ni servidor.
// Uso: const d = useDictado(t => campo.value = t); d.soportado, d.escuchando, d.alternar()
import { ref } from 'vue'

export function useDictado(onTexto, { idioma = 'es-AR', continuo = false } = {}) {
  const Rec = typeof window !== 'undefined' ? (window.SpeechRecognition || window.webkitSpeechRecognition) : null
  const soportado = !!Rec
  const escuchando = ref(false)
  const parcial = ref('')
  let rec = null

  function iniciar() {
    if (!Rec || escuchando.value) return
    rec = new Rec(); rec.lang = idioma; rec.continuous = continuo; rec.interimResults = true; rec.maxAlternatives = 1
    let final = ''
    rec.onresult = e => {
      let interim = ''
      for (let i = e.resultIndex; i < e.results.length; i++) { const t = e.results[i][0].transcript; if (e.results[i].isFinal) final += t; else interim += t }
      parcial.value = interim
      if (final) { onTexto(final.trim(), true); final = '' }
      else if (interim) onTexto(interim.trim(), false)
    }
    rec.onend = () => { escuchando.value = false; parcial.value = '' }
    rec.onerror = () => { escuchando.value = false; parcial.value = '' }
    try { rec.start(); escuchando.value = true } catch (e) { escuchando.value = false }
  }
  function detener() { try { rec?.stop() } catch (e) {} escuchando.value = false }
  function alternar() { escuchando.value ? detener() : iniciar() }
  return { soportado, escuchando, parcial, iniciar, detener, alternar }
}

// Fase 119.3: «Hola Bigcito». ¿En lo que se oyó lo nombraron? Devuelve null si no, o lo que dijo después del nombre
// («Bigcito, ¿cuánto vendí hoy?» → «¿cuánto vendí hoy?»; «hola Bigcito» → ''). El reconocedor lo escribe de muchas formas.
const NOMBRE = /(?:^|[\s,.;:!¡¿?])(?:(?:(?:hola|hey|ey|che|oye|ok|okey|dale|a ver)[\s,]+)?(?:[bvp]i(?:g|c|k)[\s-]?(?:c|s|z)ito|[bvp]ixito|[bv]i[cz]ito|bisito)|(?:hola|hey|ey|che|oye|ok|okey)[\s,]+(?:bichito|visito|pisito))(?=$|[\s,.;:!¡¿?])[\s,.;:!?]*(.*)$/i
export function llamado(texto) {
  const m = String(texto ?? '').trim().match(NOMBRE)
  return m ? (m[1] || '').trim() : null
}

// Escucha de fondo (sólo donde el navegador dicta: Chrome, Edge, Android) y avisa cuando lo nombran. Se reinicia solo
// cuando el navegador corta por silencio; se pausa mientras Bigcito escucha una orden o habla.
export function useDespertador(onLlamado, { idioma = 'es-AR' } = {}) {
  const Rec = typeof window !== 'undefined' ? (window.SpeechRecognition || window.webkitSpeechRecognition) : null
  const soportado = !!Rec
  const activo = ref(false)
  const negado = ref(false)
  let rec = null, querido = false, pausado = false, reinicio = null

  function arrancar() {
    if (!Rec || !querido || pausado || rec) return
    rec = new Rec(); rec.lang = idioma; rec.continuous = true; rec.interimResults = false; rec.maxAlternatives = 3
    rec.onresult = e => {
      for (let i = e.resultIndex; i < e.results.length; i++) {
        if (!e.results[i].isFinal) continue
        for (let k = 0; k < e.results[i].length; k++) { const resto = llamado(e.results[i][k].transcript); if (resto !== null) { onLlamado(resto); return } }
      }
    }
    rec.onend = () => { rec = null; activo.value = false; if (querido && !pausado) reinicio = setTimeout(arrancar, 500) }
    rec.onerror = ev => { if (ev.error === 'not-allowed' || ev.error === 'service-not-allowed') { querido = false; negado.value = true } }
    try { rec.start(); activo.value = true } catch (e) { rec = null }
  }
  function parar() { clearTimeout(reinicio); try { rec?.abort() } catch (e) {} rec = null; activo.value = false }
  function encender() { querido = true; pausado = false; negado.value = false; arrancar() }
  function apagar() { querido = false; parar() }
  function pausar() { pausado = true; parar() }
  function reanudar() { if (!querido) return; pausado = false; clearTimeout(reinicio); reinicio = setTimeout(arrancar, 400) }
  return { soportado, activo, negado, encender, apagar, pausar, reanudar }
}
