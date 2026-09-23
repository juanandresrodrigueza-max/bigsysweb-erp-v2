<template>
  <div class="min-h-screen bg-violeta text-white flex flex-col items-center justify-center p-6 select-none" style="font-family: Montserrat, sans-serif" @click="foco">
    <div class="absolute top-4 left-4 text-xs opacity-60">BigSys · Verificador de precios</div>
    <Link href="/stock" class="absolute top-4 right-4 text-xs opacity-60 hover:opacity-100">Salir</Link>
    <input ref="inp" v-model="codigo" class="absolute opacity-0 w-0 h-0" autofocus @keyup.enter="buscar" />

    <div v-if="!p && !buscando && !noEncontrado" class="text-center">
      <div class="text-7xl mb-6">▮▯▮▮▯▮</div>
      <h1 class="text-4xl font-extrabold mb-2">Pasá el producto por el lector</h1>
      <p class="text-lg opacity-80">o escribí el código y apretá Enter</p>
      <input v-model="codigo" class="mt-6 px-4 py-3 rounded-xl text-marca-texto text-center text-2xl w-80" placeholder="Código" @keyup.enter="buscar" @click.stop />
    </div>

    <div v-else-if="noEncontrado" class="text-center">
      <h1 class="text-4xl font-extrabold text-white mb-2">No encontramos ese producto</h1>
      <p class="text-xl opacity-80">Código {{ ultimo }} · consultá en caja</p>
    </div>

    <div v-else-if="p" class="text-center max-w-3xl w-full">
      <img v-if="p.imagen" :src="p.imagen" class="mx-auto h-40 object-contain rounded-xl mb-4 bg-white/10" />
      <h1 class="text-4xl md:text-5xl font-extrabold leading-tight mb-2" style="text-wrap: balance">{{ p.nombre }}</h1>
      <p class="text-sm opacity-70 tabular-nums mb-4">{{ p.sku }}</p>
      <p class="text-7xl md:text-8xl font-black tabular-nums text-white drop-shadow-lg">{{ moneda(p.precio, p.precio % 1 ? 2 : 0) }}</p>
      <p class="text-lg opacity-80 mt-2">por {{ p.unit }}<span v-if="p.desc_cant_pct > 0"> · llevando {{ cantidad(p.desc_cant_min) }} o más, {{ p.desc_cant_pct }}% de descuento</span></p>
      <div v-if="Object.keys(p.precios).length > 1" class="flex justify-center gap-6 mt-6 text-sm opacity-80"><span v-for="(v, l) in p.precios" :key="l">Lista {{ l }}: <b class="tabular-nums">{{ moneda(v) }}</b></span></div>
      <p class="mt-8 text-sm opacity-50">Vuelve al inicio en {{ cuenta }} s</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'
import { moneda, cantidad } from '@/util/formato'
const inp = ref(null); const codigo = ref(''); const p = ref(null); const buscando = ref(false); const noEncontrado = ref(false); const ultimo = ref(''); const cuenta = ref(8)
let timer = null; let tick = null
function foco() { inp.value?.focus() }
async function buscar() {
  const c = codigo.value.trim(); if (!c) return
  ultimo.value = c; codigo.value = ''; buscando.value = true
  try { const r = await fetch('/stock/verificar?codigo=' + encodeURIComponent(c), { headers: { Accept: 'application/json' } }); const d = await r.json(); p.value = d; noEncontrado.value = !d } catch (e) { noEncontrado.value = true }
  buscando.value = false; reiniciar()
}
function reiniciar() {
  clearTimeout(timer); clearInterval(tick); cuenta.value = 8
  tick = setInterval(() => (cuenta.value = Math.max(0, cuenta.value - 1)), 1000)
  timer = setTimeout(() => { p.value = null; noEncontrado.value = false; clearInterval(tick); foco() }, 8000)
}
onMounted(foco); onBeforeUnmount(() => { clearTimeout(timer); clearInterval(tick) })
</script>
