<template>
  <AppLayout titulo="Etiquetas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4 no-print">
      <div><h1 class="page-title">Etiquetas y códigos de barras</h1><p class="page-subtitle">Elegí artículos, formato y cantidad; imprimí en hoja A4 o en impresora de etiquetas.</p></div>
      <div class="flex gap-2"><Link href="/stock" class="btn-secondary">Volver</Link><button class="btn-primary" :disabled="!seleccion.length" @click="imprimir">Imprimir {{ total }} etiquetas</button></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4 no-print">
      <div class="card lg:col-span-2 p-0 overflow-hidden">
        <div class="p-3 border-b border-marca-borde flex flex-wrap gap-2">
          <input v-model="q" class="input flex-1 min-w-40" placeholder="Buscar por nombre, código o barras…" @keyup.enter="buscar" />
          <select v-model="rubro" class="input" @change="buscar"><option value="">Todos los rubros</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.nombre }}</option></select>
          <button class="btn-secondary" @click="buscar">Filtrar</button>
          <button class="btn-ghost text-xs" @click="todos(true)">Todos</button><button class="btn-ghost text-xs" @click="todos(false)">Ninguno</button>
        </div>
        <div class="max-h-[60vh] overflow-y-auto"><table class="table text-xs">
          <thead><tr><th></th><th>Artículo</th><th>Código</th><th class="text-right">Precio</th><th class="text-right w-24">Cant.</th></tr></thead>
          <tbody><tr v-for="a in articulos" :key="a.id"><td><input type="checkbox" :checked="cant[a.id] > 0" @change="cant[a.id] = $event.target.checked ? 1 : 0" /></td><td>{{ a.nombre }}<span class="text-marca-muted"> · {{ a.rubro }}</span></td><td class="tabular-nums">{{ a.barcode }}</td><td class="text-right tabular-nums">{{ moneda(a.precio) }}</td><td><input v-model.number="cant[a.id]" type="number" min="0" class="input !py-1 text-right" /></td></tr>
          <tr v-if="!articulos.length"><td colspan="5" class="text-center text-marca-muted py-6">Sin artículos con ese filtro.</td></tr></tbody></table></div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-3">Formato</h2>
        <div class="space-y-3 text-sm">
          <div><label class="label">Tamaño</label><select v-model="fmt.tamano" class="input"><option value="a4_65">A4 · 65 por hoja (38×21 mm)</option><option value="a4_24">A4 · 24 por hoja (70×37 mm)</option><option value="a4_8">A4 · 8 por hoja (105×74 mm) góndola</option><option value="rollo50">Rollo 50×30 mm (impresora de etiquetas)</option></select></div>
          <div><label class="label">Código de barras</label><select v-model="fmt.simbologia" class="input"><option value="auto">Automático (EAN-13 si es válido, si no CODE128)</option><option value="CODE128">CODE128</option><option value="EAN13">EAN-13</option></select></div>
          <label class="flex items-center gap-2"><input v-model="fmt.precio" type="checkbox" class="accent-carmin" /> Mostrar precio</label>
          <label class="flex items-center gap-2"><input v-model="fmt.nombre" type="checkbox" class="accent-carmin" /> Mostrar nombre</label>
          <label class="flex items-center gap-2"><input v-model="fmt.empresa" type="checkbox" class="accent-carmin" /> Mostrar nombre del comercio</label>
          <div><label class="label">Texto extra (ej. OFERTA)</label><input v-model="fmt.extra" class="input" /></div>
        </div>
        <div class="mt-4"><p class="label">Vista previa</p><div class="border border-dashed border-marca-borde rounded-lg p-2 flex justify-center bg-white"><div v-if="seleccion.length" class="etiqueta" :class="'t-' + fmt.tamano"><Etiqueta :a="seleccion[0]" :fmt="fmt" :empresa="empresa.name" /></div><p v-else class="text-xs text-marca-muted py-4">Marcá un artículo para ver la etiqueta.</p></div></div>
      </div>
    </div>

    <div id="hoja" class="hoja" :class="'h-' + fmt.tamano">
      <template v-for="a in seleccion" :key="a.id"><div v-for="n in cant[a.id]" :key="n" class="etiqueta" :class="'t-' + fmt.tamano"><Etiqueta :a="a" :fmt="fmt" :empresa="empresa.name" /></div></template>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, h, onMounted, onUpdated, defineComponent } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import JsBarcode from 'jsbarcode'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ articulos: Array, rubros: Array, filtros: Object, empresa: Object })
const q = ref(props.filtros.q ?? ''); const rubro = ref(props.filtros.rubro ?? '')
const cant = reactive(Object.fromEntries(props.articulos.map(a => [a.id, props.filtros.ids ? 1 : 0])))
const seleccion = computed(() => props.articulos.filter(a => cant[a.id] > 0))
const total = computed(() => seleccion.value.reduce((s, a) => s + (Number(cant[a.id]) || 0), 0))
const fmt = reactive({ tamano: 'a4_65', simbologia: 'auto', precio: true, nombre: true, empresa: false, extra: '' })
function buscar() { router.get('/stock/etiquetas', { q: q.value, rubro: rubro.value }, { preserveState: true, preserveScroll: true }) }
function todos(on) { props.articulos.forEach(a => (cant[a.id] = on ? 1 : 0)) }
const esEan = c => /^\d{12,13}$/.test(c)
function dibujar() {
  document.querySelectorAll('canvas.barcode').forEach(el => {
    const code = el.dataset.code; const sym = fmt.simbologia === 'auto' ? (esEan(code) ? 'EAN13' : 'CODE128') : fmt.simbologia
    try { JsBarcode(el, sym === 'EAN13' ? code.slice(0, 12) : code, { format: sym, displayValue: true, fontSize: 10, height: fmt.tamano === 'a4_8' ? 50 : 28, width: fmt.tamano === 'a4_65' ? 1 : 1.4, margin: 0 }) } catch (e) { try { JsBarcode(el, code, { format: 'CODE128', displayValue: true, fontSize: 10, height: 28, width: 1, margin: 0 }) } catch (e2) {} }
  })
}
onMounted(dibujar); onUpdated(dibujar)
function imprimir() { window.print() }
const Etiqueta = defineComponent({ props: { a: Object, fmt: Object, empresa: String }, setup: p => () => h('div', { class: 'et-in' }, [
  p.fmt.empresa ? h('div', { class: 'et-emp' }, p.empresa) : null,
  p.fmt.nombre ? h('div', { class: 'et-nom' }, p.a.nombre) : null,
  h('canvas', { class: 'barcode', 'data-code': p.a.barcode, style: 'max-width:100%' }),
  p.fmt.precio ? h('div', { class: 'et-pre' }, moneda(p.a.precio, p.a.precio % 1 ? 2 : 0)) : null,
  p.fmt.extra ? h('div', { class: 'et-ext' }, p.fmt.extra) : null,
]) })
</script>

<style>
.hoja { display: none; }
.etiqueta { background: #fff; color: #000; overflow: hidden; box-sizing: border-box; display: flex; align-items: center; justify-content: center; text-align: center; font-family: Montserrat, Arial, sans-serif; }
.et-in { width: 100%; padding: 1mm; }
.et-emp { font-size: 7px; letter-spacing: .1em; text-transform: uppercase; color: #444; }
.et-nom { font-size: 9px; font-weight: 700; line-height: 1.1; max-height: 2.2em; overflow: hidden; }
.et-pre { font-size: 14px; font-weight: 800; }
.et-ext { font-size: 9px; font-weight: 800; color: #e4003f; letter-spacing: .1em; }
.t-a4_65 { width: 38.1mm; height: 21.2mm; } .t-a4_65 .et-nom { font-size: 7px; } .t-a4_65 .et-pre { font-size: 11px; }
.t-a4_24 { width: 70mm; height: 37mm; }
.t-a4_8 { width: 105mm; height: 74mm; } .t-a4_8 .et-nom { font-size: 16px; } .t-a4_8 .et-pre { font-size: 32px; } .t-a4_8 .et-ext { font-size: 18px; }
.t-rollo50 { width: 50mm; height: 30mm; }
@media print {
  body * { visibility: hidden; } .no-print { display: none !important; }
  #hoja, #hoja * { visibility: visible; }
  #hoja { display: flex !important; flex-wrap: wrap; position: absolute; left: 0; top: 0; width: 100%; align-content: flex-start; }
  .h-a4_65 .etiqueta { border: 0; } .h-a4_24 .etiqueta, .h-a4_8 .etiqueta { border: 0; }
  .h-rollo50 .etiqueta { page-break-after: always; }
  @page { margin: 8mm 5mm; }
}
</style>
