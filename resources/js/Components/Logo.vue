<template>
  <!-- Isotipo BS: B carmín, S lavanda, flecha en degradé (o todo blanco en negativo) -->
  <svg v-if="variante === 'iso'" :class="clase" viewBox="0 0 120 110" aria-label="BigSys" role="img">
    <defs>
      <linearGradient :id="`bsArrow-${uid}`" x1="0" x2="1"><stop offset="0" stop-color="#e4003f" /><stop offset="1" stop-color="#a42785" /></linearGradient>
    </defs>
    <text x="4" y="72" font-family="Montserrat, Figtree, sans-serif" font-weight="800" font-style="italic" font-size="78" :fill="negativo ? '#fff' : '#e4003f'">B</text>
    <text x="56" y="72" font-family="Montserrat, Figtree, sans-serif" font-weight="800" font-style="italic" font-size="78" :fill="negativo ? '#fff' : '#c5bcdd'">S</text>
    <path d="M52 92 C 70 100, 92 98, 108 84" fill="none" :stroke="negativo ? '#fff' : `url(#bsArrow-${uid})`" stroke-width="7" stroke-linecap="round" />
    <path d="M100 80 L 110 82 L 106 92" fill="none" :stroke="negativo ? '#fff' : '#a42785'" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />
  </svg>

  <!-- Wordmark BigSys web -->
  <svg v-else :class="clase" viewBox="0 0 340 100" aria-label="BigSys web" role="img">
    <defs>
      <linearGradient :id="`bsArrow-${uid}`" x1="0" x2="1"><stop offset="0" stop-color="#e4003f" /><stop offset="1" stop-color="#a42785" /></linearGradient>
    </defs>
    <text x="0" y="66" font-family="Montserrat, Figtree, sans-serif" font-weight="800" font-style="italic" font-size="72" :fill="negativo ? '#fff' : '#e4003f'">Big</text>
    <text x="126" y="66" font-family="Montserrat, Figtree, sans-serif" font-weight="800" font-style="italic" font-size="72" :fill="negativo ? '#fff' : '#c5bcdd'">Sys</text>
    <text x="262" y="60" font-family="Montserrat, Figtree, sans-serif" font-weight="600" font-size="24" :fill="negativo ? '#fff' : '#c5bcdd'">web</text>
    <path d="M205 82 C 225 92, 250 90, 268 76" fill="none" :stroke="negativo ? '#fff' : `url(#bsArrow-${uid})`" stroke-width="6" stroke-linecap="round" />
    <path d="M260 72 L 270 74 L 266 84" fill="none" :stroke="negativo ? '#fff' : '#a42785'" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round" />
  </svg>
</template>

<script setup>
defineProps({ variante: { type: String, default: 'wordmark' }, negativo: Boolean, clase: { type: String, default: 'h-8' } })
const uid = Math.random().toString(36).slice(2, 8)
</script>
