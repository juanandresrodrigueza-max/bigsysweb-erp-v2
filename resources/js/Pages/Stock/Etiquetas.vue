<template>
  <AppLayout titulo="Etiquetas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4 no-print">
      <div><h1 class="page-title">Centro de etiquetas</h1><p class="page-subtitle">Góndola con precio por kilo o litro, ofertas, lotes y vencimientos, números de serie. En hoja A4, en rollo o directo a una impresora Zebra.</p></div>
      <div class="flex flex-wrap gap-2"><Link href="/stock" class="btn-secondary">Volver</Link><button class="btn-secondary" :disabled="!seleccion.length" data-e2e="zpl" @click="bajarZpl">Bajar ZPL (Zebra)</button><button class="btn-primary" :disabled="!seleccion.length" @click="imprimir">Imprimir {{ total }} etiquetas</button></div>
    </div>

    <div v-if="origen" class="card mb-3 no-print text-sm bg-violeta/5 flex flex-wrap items-center justify-between gap-2" data-e2e="origen"><span>Etiquetas de: <b>{{ origen }}</b></span><Link href="/stock/etiquetas" class="text-xs text-violeta font-semibold">Ver todos los artículos</Link></div>

    <div class="grid lg:grid-cols-3 gap-4 no-print">
      <div class="card lg:col-span-2 p-0 overflow-hidden">
        <div class="p-3 border-b border-marca-borde flex flex-wrap gap-2">
          <input v-model="q" class="input flex-1 min-w-40" placeholder="Buscar por nombre, código o barras…" @keyup.enter="buscar" />
          <select v-model="rubro" class="input" @change="buscar"><option value="">Todos los rubros</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.nombre }}</option></select>
          <select v-model.number="lista" class="input" @change="buscar"><option v-for="n in 6" :key="n" :value="n">Lista {{ n }}</option></select>
          <button class="btn-secondary" @click="buscar">Filtrar</button>
          <button class="btn-secondary" data-e2e="cambios" @click="router.get('/stock/etiquetas', { cambios: 7, lista })">Precios cambiados (7 días)</button>
          <button class="btn-ghost text-xs" @click="todos(true)">Todos</button><button class="btn-ghost text-xs" @click="todos(false)">Ninguno</button>
        </div>
        <div class="max-h-[60vh] overflow-y-auto"><table class="table text-xs">
          <thead><tr><th></th><th>Artículo</th><th>Código</th><th class="text-right">Precio</th><th class="text-right w-24">Cant.</th></tr></thead>
          <tbody><tr v-for="a in articulos" :key="a.k"><td><input type="checkbox" :checked="cant[a.k] > 0" @change="cant[a.k] = $event.target.checked ? (a.cantidad || 1) : 0" /></td>
            <td>{{ a.nombre }}<span class="text-marca-muted"> · {{ a.rubro }}</span><span v-if="a.serie" class="block font-mono text-marca-muted">S/N {{ a.serie }}</span><span v-else-if="a.lote || a.vence" class="block text-marca-muted">{{ a.lote ? 'Lote ' + a.lote : '' }} {{ a.vence ? '· vence ' + a.vence : '' }}</span></td>
            <td class="tabular-nums">{{ a.barcode }}</td><td class="text-right tabular-nums">{{ moneda(a.precio) }}<span v-if="a.medida" class="block text-marca-muted">{{ moneda(a.medida.precio) }}/{{ a.medida.unidad }}</span></td><td><input v-model.number="cant[a.k]" type="number" min="0" class="input !py-1 text-right" /></td></tr>
          <tr v-if="!articulos.length"><td colspan="5" class="text-center text-marca-muted py-6">Sin artículos con ese filtro.</td></tr></tbody></table></div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-3">Diseño y formato</h2>
        <div class="space-y-3 text-sm">
          <div><label class="label">Diseño</label><select v-model="fmt.diseno" class="input" data-e2e="diseno"><option value="estandar">Estándar (nombre, código y precio)</option><option value="gondola">Góndola (precio grande y precio por kg / litro)</option><option value="oferta">Oferta (antes tachado y precio nuevo)</option><option value="lote">Lote y vencimiento</option><option value="serie">Número de serie</option></select></div>
          <div v-if="fmt.diseno === 'oferta'"><label class="label">Descuento de la oferta (%)</label><input v-model.number="fmt.descuento" type="number" min="0" max="90" class="input" data-e2e="descuento" /></div>
          <div><label class="label">Tamaño</label><select v-model="fmt.tamano" class="input"><option value="a4_65">A4 · 65 por hoja (38×21 mm)</option><option value="a4_24">A4 · 24 por hoja (70×37 mm)</option><option value="a4_8">A4 · 8 por hoja (105×74 mm) góndola</option><option value="rollo50">Rollo 50×30 mm (impresora de etiquetas)</option></select></div>
          <div><label class="label">Código de barras</label><select v-model="fmt.simbologia" class="input"><option value="auto">Automático (EAN-13 si es válido, si no CODE128)</option><option value="CODE128">CODE128</option><option value="EAN13">EAN-13</option></select></div>
          <label class="flex items-center gap-2"><input v-model="fmt.precio" type="checkbox" class="accent-carmin" /> Mostrar precio</label>
          <label class="flex items-center gap-2"><input v-model="fmt.nombre" type="checkbox" class="accent-carmin" /> Mostrar nombre</label>
          <label class="flex items-center gap-2"><input v-model="fmt.empresa" type="checkbox" class="accent-carmin" /> Mostrar nombre del comercio</label>
          <label class="flex items-center gap-2"><input v-model="fmt.colores" type="checkbox" class="accent-carmin" /> Con el color de la empresa</label>
          <div><label class="label">Texto extra (ej. OFERTA)</label><input v-model="fmt.extra" class="input" /></div>
        </div>
        <div class="mt-4"><p class="label">Vista previa</p><div class="border border-dashed border-marca-borde rounded-lg p-2 flex justify-center bg-white" data-e2e="preview"><div v-if="seleccion.length" class="etiqueta" :class="'t-' + fmt.tamano"><Etiqueta :a="seleccion[0]" :fmt="fmt" :empresa="empresa" /></div></div></div>
      </div>
    </div>

    <div id="hoja" class="hoja" :class="'h-' + fmt.tamano">
      <template v-for="a in seleccion" :key="a.k"><div v-for="n in cant[a.k]" :key="n" class="etiqueta" :class="'t-' + fmt.tamano"><Etiqueta :a="a" :fmt="fmt" :empresa="empresa" /></div></template>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, h, onMounted, onUpdated, defineComponent, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import JsBarcode from 'jsbarcode'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ articulos: Array, rubros: Array, filtros: Object, empresa: Object, origen: String, preseleccion: Boolean })
const q = ref(props.filtros.q ?? ''); const rubro = ref(props.filtros.rubro ?? ''); const lista = ref(Number(props.filtros.lista ?? 1))
const cant = reactive(Object.fromEntries(props.articulos.map(a => [a.k, props.preseleccion ? (a.cantidad || 1) : 0])))
const seleccion = computed(() => props.articulos.filter(a => cant[a.k] > 0))
const total = computed(() => seleccion.value.reduce((s, a) => s + (Number(cant[a.k]) || 0), 0))
// El formato elegido se recuerda en este navegador.
const guardado = (() => { try { return JSON.parse(localStorage.getItem('etiquetas.fmt') || '{}') } catch (e) { return {} } })()
const fmt = reactive({ tamano: 'a4_65', simbologia: 'auto', precio: true, nombre: true, empresa: false, extra: '', diseno: 'estandar', descuento: 10, colores: false, ...guardado })
if (props.origen && props.articulos.some(a => a.serie)) fmt.diseno = 'serie'
else if (props.origen && props.articulos.some(a => a.lote || a.vence)) fmt.diseno = 'lote'
watch(fmt, v => { try { localStorage.setItem('etiquetas.fmt', JSON.stringify(v)) } catch (e) {} }, { deep: true })
function buscar() { router.get('/stock/etiquetas', { q: q.value, rubro: rubro.value, lista: lista.value }, { preserveState: true, preserveScroll: true }) }
function todos(on) { props.articulos.forEach(a => (cant[a.k] = on ? (a.cantidad || 1) : 0)) }
const esEan = c => /^\d{12,13}$/.test(c)
const codigoDe = a => fmt.diseno === 'serie' && a.serie ? a.serie : a.barcode
const oferta = a => Math.round(a.precio * (1 - (Number(fmt.descuento) || 0) / 100) * 100) / 100
const m = (n) => moneda(n, n % 1 ? 2 : 0)
function dibujar() {
  document.querySelectorAll('canvas.barcode').forEach(el => {
    const code = el.dataset.code; const sym = el.dataset.forzar || (fmt.simbologia === 'auto' ? (esEan(code) ? 'EAN13' : 'CODE128') : fmt.simbologia)
    try { JsBarcode(el, sym === 'EAN13' ? code.slice(0, 12) : code, { format: sym, displayValue: true, fontSize: 10, height: fmt.tamano === 'a4_8' ? 50 : 28, width: fmt.tamano === 'a4_65' ? 1 : 1.4, margin: 0 }) } catch (e) { try { JsBarcode(el, code, { format: 'CODE128', displayValue: true, fontSize: 10, height: 28, width: 1, margin: 0 }) } catch (e2) {} }
  })
}
onMounted(dibujar); onUpdated(dibujar)
function imprimir() { window.print() }
const Etiqueta = defineComponent({ props: { a: Object, fmt: Object, empresa: Object }, setup: p => () => {
  const a = p.a, f = p.fmt, d = f.diseno
  const banda = f.colores ? { background: p.empresa.color, color: p.empresa.texto } : {}
  const partes = [
    f.empresa || f.colores ? h('div', { class: 'et-emp', style: banda }, p.empresa.name) : null,
    f.nombre ? h('div', { class: 'et-nom' }, a.nombre + (a.contenido && d === 'gondola' ? ` · ${a.contenido}` : '')) : null,
  ]
  if (d === 'oferta') partes.push(h('div', { class: 'et-ext' }, f.extra || 'OFERTA'), h('div', { class: 'et-antes' }, m(a.precio)), h('div', { class: 'et-pre', style: f.colores ? { color: p.empresa.color } : {} }, m(oferta(a))))
  else if (d === 'gondola') partes.push(h('div', { class: 'et-pre et-grande', style: f.colores ? { color: p.empresa.color } : {} }, m(a.precio)), a.medida ? h('div', { class: 'et-medida' }, `${m(a.medida.precio)} por ${a.medida.unidad}`) : null)
  else if (d === 'lote') partes.push(h('div', { class: 'et-lote' }, [a.lote ? `Lote ${a.lote}` : null, a.vence ? `Vence ${a.vence}` : null].filter(Boolean).join(' · ') || 'Sin lote'))
  else if (d === 'serie') partes.push(h('div', { class: 'et-lote' }, a.serie ? `S/N ${a.serie}` : 'Sin serie'))
  partes.push(h('canvas', { class: 'barcode', 'data-code': d === 'serie' && a.serie ? a.serie : a.barcode, 'data-forzar': d === 'serie' ? 'CODE128' : null, style: 'max-width:100%' }))
  if (f.precio && !['oferta', 'gondola', 'serie'].includes(d)) partes.push(h('div', { class: 'et-pre' }, m(a.precio)))
  if (f.extra && d !== 'oferta') partes.push(h('div', { class: 'et-ext' }, f.extra))
  return h('div', { class: 'et-in' }, partes)
} })

// ZPL para impresoras Zebra (203 dpi, 8 puntos por mm): una etiqueta por unidad, con el texto y el código del diseño elegido.
const MM = { a4_65: [38, 21], a4_24: [70, 37], a4_8: [105, 74], rollo50: [50, 30] }
const zplTxt = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[\^~]/g, ' ').slice(0, 40)
function bajarZpl() {
  const [w, alto] = MM[fmt.tamano] ?? [50, 30]; const W = w * 8, H = alto * 8
  let out = ''
  for (const a of seleccion.value) for (let n = 0; n < (cant[a.k] || 0); n++) {
    const d = fmt.diseno; const code = codigoDe(a)
    const linea2 = d === 'oferta' ? `OFERTA ${m(oferta(a))} (antes ${m(a.precio)})` : d === 'lote' ? [a.lote && 'Lote ' + a.lote, a.vence && 'Vence ' + a.vence].filter(Boolean).join(' ') : d === 'serie' ? `S/N ${a.serie ?? ''}` : d === 'gondola' && a.medida ? `${m(a.precio)} - ${m(a.medida.precio)}/${a.medida.unidad}` : fmt.precio ? m(a.precio) : ''
    const bc = /^\d{12,13}$/.test(code) && d !== 'serie' ? `^BEN,${Math.round(H * 0.35)},Y,N^FD${code.slice(0, 12)}^FS` : `^BCN,${Math.round(H * 0.35)},Y,N,N^FD${zplTxt(code)}^FS`
    out += `^XA^PW${W}^LL${H}^CI28\n^FO16,12^A0N,${Math.round(H * 0.12)},${Math.round(H * 0.12)}^FB${W - 32},2,0,L^FD${zplTxt(a.nombre)}^FS\n^FO16,${Math.round(H * 0.34)}^A0N,${Math.round(H * 0.13)},${Math.round(H * 0.13)}^FD${zplTxt(linea2)}^FS\n^FO16,${Math.round(H * 0.52)}^BY2${bc}\n^XZ\n`
  }
  const url = URL.createObjectURL(new Blob([out], { type: 'text/plain' }))
  const el = document.createElement('a'); el.href = url; el.download = 'etiquetas.zpl'; el.click(); setTimeout(() => URL.revokeObjectURL(url), 2000)
}
</script>

<style>
.hoja { display: none; }
.etiqueta { background: #fff; color: #000; overflow: hidden; box-sizing: border-box; display: flex; align-items: center; justify-content: center; text-align: center; font-family: Montserrat, Arial, sans-serif; }
.et-in { width: 100%; padding: 1mm; }
.et-emp { font-size: 7px; letter-spacing: .1em; text-transform: uppercase; color: #444; border-radius: 2px; padding: 0 2px; }
.et-nom { font-size: 9px; font-weight: 700; line-height: 1.1; max-height: 2.2em; overflow: hidden; }
.et-pre { font-size: 14px; font-weight: 800; }
.et-grande { font-size: 20px; line-height: 1.05; }
.et-medida { font-size: 8px; color: #333; }
.et-antes { font-size: 9px; color: #666; text-decoration: line-through; }
.et-lote { font-size: 9px; font-weight: 700; font-family: ui-monospace, monospace; }
.et-ext { font-size: 9px; font-weight: 800; color: #e4003f; letter-spacing: .1em; }
.t-a4_65 { width: 38.1mm; height: 21.2mm; } .t-a4_65 .et-nom { font-size: 7px; } .t-a4_65 .et-pre { font-size: 11px; } .t-a4_65 .et-grande { font-size: 14px; }
.t-a4_24 { width: 70mm; height: 37mm; }
.t-a4_8 { width: 105mm; height: 74mm; } .t-a4_8 .et-nom { font-size: 16px; } .t-a4_8 .et-pre { font-size: 32px; } .t-a4_8 .et-grande { font-size: 44px; } .t-a4_8 .et-medida { font-size: 14px; } .t-a4_8 .et-antes { font-size: 16px; } .t-a4_8 .et-ext { font-size: 18px; } .t-a4_8 .et-lote { font-size: 14px; }
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
