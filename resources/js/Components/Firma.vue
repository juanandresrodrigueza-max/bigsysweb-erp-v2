<template>
  <div>
    <canvas ref="lienzo" :width="ancho" :height="alto" class="w-full border border-marca-borde rounded-xl bg-white touch-none" @pointerdown="pd" @pointermove="pm" @pointerup="fin" @pointerleave="fin"></canvas>
    <div class="flex items-center justify-between mt-1 text-xs"><span class="text-marca-muted">{{ trazos ? 'Firmado' : 'Firmá con el dedo' }}</span><button type="button" class="text-marca-muted hover:text-carmin" @click="limpiar">Limpiar</button></div>
  </div>
</template>

<script setup>
// Firma con el dedo o el mouse. Emite el PNG como data URL cada vez que se suelta el trazo, y '' al limpiar.
import { ref } from 'vue'
const props = defineProps({ modelValue: String, ancho: { type: Number, default: 600 }, alto: { type: Number, default: 160 } })
const emit = defineEmits(['update:modelValue'])
const lienzo = ref(null), dibujando = ref(false), trazos = ref(0)
const pos = e => { const r = lienzo.value.getBoundingClientRect(); return [(e.clientX - r.left) * lienzo.value.width / r.width, (e.clientY - r.top) * lienzo.value.height / r.height] }
function pd(e) { dibujando.value = true; lienzo.value.setPointerCapture?.(e.pointerId); const c = lienzo.value.getContext('2d'); c.lineWidth = 2.5; c.lineCap = 'round'; c.strokeStyle = '#1c1a18'; c.beginPath(); c.moveTo(...pos(e)); trazos.value++ }
function pm(e) { if (!dibujando.value) return; const c = lienzo.value.getContext('2d'); c.lineTo(...pos(e)); c.stroke() }
function fin() { if (!dibujando.value) return; dibujando.value = false; emit('update:modelValue', lienzo.value.toDataURL('image/png')) }
function limpiar() { lienzo.value.getContext('2d').clearRect(0, 0, lienzo.value.width, lienzo.value.height); trazos.value = 0; emit('update:modelValue', '') }
</script>
