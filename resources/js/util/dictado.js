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
