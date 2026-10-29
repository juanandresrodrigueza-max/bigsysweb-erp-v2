<template>
  <AppLayout :titulo="`Recepción · ${r.compra.nombre} ${r.compra.numero ?? ''}`">
    <!-- Fase 31.5: contar lo que bajó del camión contra la factura. La pistola escribe en el campo punteado, que siempre tiene el foco;
         cada lectura suena (agudo = bien, grave doble = algo anda mal) y la barra de arriba se ve de lejos: rojo, amarillo, verde. -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
      <div>
        <Link :href="`/proveedores/compras/${r.compra.id}`" class="text-xs text-marca-muted hover:text-carmin">← {{ r.compra.nombre }} {{ r.compra.numero ?? '' }}</Link>
        <h1 class="page-title">Controlar la recepción</h1>
        <p class="page-subtitle">{{ r.compra.proveedor }} · {{ r.compra.fecha }} · {{ r.items.filter(i => !i.no_facturado).length }} renglones · {{ r.lecturas }} lecturas</p>
      </div>
      <div class="flex flex-wrap gap-2 items-center">
        <template v-if="abierta">
          <div class="flex rounded-xl border border-marca-borde overflow-hidden text-sm" data-e2e="multiplicador">
            <button v-for="m in [1, 6, 12, 24]" :key="m" class="px-3 py-1.5 font-semibold" :class="mult === m ? 'bg-violeta text-white' : 'hover:bg-gris-light'" @click="mult = m">×{{ m }}</button>
            <input v-model.number="multLibre" type="number" min="1" class="w-16 px-2 text-sm border-l border-marca-borde outline-none" placeholder="×…" @focus="manual = true" @blur="manual = false; if (multLibre > 0) mult = multLibre" />
          </div>
          <button class="btn-secondary" data-e2e="agregar-no-facturado" @click="agregarAbierto = true">Llegó algo que no está</button>
          <button class="btn-primary" data-e2e="terminar" @click="terminar">Terminar el control</button>
        </template>
        <template v-else>
          <span class="badge" :class="r.diferencias ? 'bg-carmin-light text-carmin' : 'bg-emerald-50 text-emerald-700'">Control cerrado {{ r.cerrada_en }} · {{ r.diferencias ? r.diferencias + ' diferencia(s)' : 'llegó todo' }}</span>
          <Link v-if="r.compra.estado === 'borrador'" :href="`/proveedores/compras/${r.compra.id}/recepcion?nueva=1`" class="btn-secondary">Volver a controlar</Link>
          <Link :href="`/proveedores/compras/${r.compra.id}`" class="btn-primary">Volver a la compra</Link>
        </template>
      </div>
    </div>

    <!-- La barra: arranca en rojo, pasa a amarillo y termina en verde cuando llegó todo -->
    <div class="rounded-2xl h-9 mb-4 relative overflow-hidden" :class="r.completa ? 'bg-emerald-100' : r.avance > 0 ? 'bg-amber-100' : 'bg-carmin-light'" data-e2e="barra" :data-avance="r.avance">
      <div class="absolute inset-y-0 left-0 transition-all" :class="r.completa ? 'bg-emerald-500' : r.avance > 0 ? 'bg-amber-400' : 'bg-carmin'" :style="{ width: Math.max(2, r.avance) + '%' }"></div>
      <p class="absolute inset-0 grid place-items-center text-sm font-extrabold" :class="r.completa ? 'text-emerald-900' : r.avance > 0 ? 'text-amber-900' : 'text-white'">{{ r.completa ? '✓ Llegó todo lo facturado' : `${r.avance}% · faltan ${cantidad(r.items.filter(i => !i.no_facturado).reduce((a, i) => a + Math.max(0, i.esperado - i.contado), 0))} unidades` }}{{ r.diferencias && r.completa ? ` · ${r.diferencias} con diferencia` : '' }}</p>
    </div>

    <div class="grid gap-4" :class="foto ? 'lg:grid-cols-3' : ''">
      <div :class="foto ? 'lg:col-span-2' : ''" class="space-y-3">
        <div v-if="abierta" class="card !py-3 flex flex-wrap items-center gap-3">
          <div class="flex-1 min-w-[220px]">
            <input ref="lector" v-model="codigo" class="w-full rounded-xl border-2 border-dashed px-4 py-3 text-base outline-none" :class="ultimo?.estado === 'ok' ? 'border-emerald-400' : ultimo && ultimo.estado !== 'ok' ? 'border-carmin' : 'border-violeta/60'" placeholder="Disparale la pistola acá (o escribí el código y Enter)" data-e2e="lector" autocomplete="off" @keydown.enter.prevent="leer(codigo)" @blur="refocar" />
            <p class="text-xs text-marca-muted mt-1">Siempre tiene el foco: no hace falta tocar la pantalla entre disparo y disparo. {{ mult > 1 ? `Cada lectura cuenta ×${mult}.` : '' }}</p>
          </div>
          <button v-if="camara" class="btn-secondary" @click="abrirCamara">📷 Cámara</button>
          <div v-if="ultimo" class="text-sm min-w-[200px]" data-e2e="ultimo">
            <p class="font-bold" :class="ultimo.estado === 'ok' ? 'text-emerald-700' : ultimo.estado === 'sobra' ? 'text-amber-700' : 'text-carmin'">{{ { ok: '✓ ' + ultimo.descripcion, sobra: '⚠ ' + ultimo.descripcion + ' · ya pasó lo facturado', no_esta: '✗ No está en esta factura', desconocido: '✗ Código desconocido', repetido: '? Está en dos renglones' }[ultimo.estado] }}</p>
            <p v-if="ultimo.contado != null" class="text-xs text-marca-muted">contado {{ cantidad(ultimo.contado) }} de {{ cantidad(ultimo.esperado) }}<span v-if="ultimo.lote || ultimo.vencimiento"> · lote {{ ultimo.lote ?? '—' }} · vence {{ ultimo.vencimiento ?? '—' }}</span></p>
          </div>
        </div>

        <div class="card p-0 overflow-x-auto">
          <table class="table">
            <thead><tr><th>Renglón</th><th class="text-right">Facturado</th><th class="text-right">Contado</th><th>Lote / vence</th><th></th></tr></thead>
            <tbody>
              <tr v-for="i in r.items" :key="i.id" :data-e2e="`item-${i.id}`" :data-estado="i.estado" :class="{ 'bg-emerald-50/50': i.estado === 'ok', 'bg-amber-50/50': i.estado === 'parcial' || i.estado === 'sobra', 'bg-violeta/5': i.no_facturado }">
                <td><p class="font-medium">{{ i.descripcion }} <span v-if="i.no_facturado" class="badge bg-violeta/10 text-violeta">no facturado</span></p><p class="text-xs text-marca-muted">{{ i.sku }}<span v-if="i.barcode"> · {{ i.barcode }}</span><span v-if="!i.product_id"> · sin artículo (se cuenta a mano)</span><span v-if="i.bulto"> · bulto de {{ i.bulto }}</span></p></td>
                <td class="text-right tabular-nums">{{ i.no_facturado ? '—' : cantidad(i.esperado) }}</td>
                <td class="text-right">
                  <div v-if="abierta" class="inline-flex items-center gap-1">
                    <button class="w-7 h-7 rounded-lg border border-marca-borde hover:bg-gris-light" @click="contar(i, i.contado - 1)">−</button>
                    <input :value="cantidad(i.contado)" class="w-16 text-right tabular-nums rounded-lg border border-marca-borde px-1 py-1 text-sm font-bold" :class="i.estado === 'ok' ? 'text-emerald-700' : i.estado === 'sobra' ? 'text-amber-700' : i.estado === 'falta' ? 'text-carmin' : ''" @focus="manual = true" @blur="manual = false; contar(i, $event.target.value)" @keydown.enter="$event.target.blur()" />
                    <button class="w-7 h-7 rounded-lg border border-marca-borde hover:bg-gris-light" @click="contar(i, i.contado + 1)">+</button>
                  </div>
                  <span v-else class="tabular-nums font-bold" :class="i.diferencia < 0 ? 'text-carmin' : i.diferencia > 0 ? 'text-amber-700' : 'text-emerald-700'">{{ cantidad(i.contado) }}<span v-if="i.resolucion && i.resolucion !== 'facturado'" class="text-xs font-normal text-marca-muted"> · {{ { contado: 'entró lo contado', pendiente: 'pendiente' }[i.resolucion] }}</span><span v-else-if="i.diferencia" class="text-xs font-normal text-marca-muted"> · entró lo facturado</span></span>
                </td>
                <td class="text-xs">
                  <div v-if="abierta && (i.perecedero || i.lote || i.vencimiento)" class="flex gap-1"><input :value="i.lote" class="input !py-1 !text-xs w-20" placeholder="lote" @focus="manual = true" @blur="manual = false; lote(i, $event.target.value, i.vencimiento)" /><input :value="i.vencimiento" type="date" class="input !py-1 !text-xs w-32" @focus="manual = true" @blur="manual = false; lote(i, i.lote, $event.target.value)" /></div>
                  <span v-else-if="i.lote || i.vencimiento" class="text-marca-muted">{{ i.lote ?? '' }} {{ i.vencimiento ? '· vence ' + i.vencimiento : '' }}</span>
                </td>
                <td class="text-right"><button v-if="abierta && i.no_facturado" class="text-xs text-carmin hover:underline" @click="quitar(i)">quitar</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="r.ultimas.length" class="card !py-3 text-xs text-marca-muted"><b class="text-marca-texto">Últimas lecturas:</b> <span v-for="(u, k) in [...r.ultimas].reverse()" :key="k" class="inline-block mr-3">{{ u.hora }} {{ u.item }} ×{{ cantidad(u.cantidad) }}</span></div>
        <p class="text-xs text-marca-muted">Lo que no tiene código se cuenta a mano con los botones de cada renglón. Si dejás el control a medias se guarda solo: al volver a abrir esta compra, retoma donde ibas.</p>
      </div>

      <!-- La foto de la factura al costado: quieta en la computadora, chiquita en el celular -->
      <div v-if="foto" class="hidden lg:block"><div class="card sticky top-4 p-2"><img :src="foto" class="w-full rounded-xl cursor-zoom-in" @click="fotoGrande = true" /><p class="text-[11px] text-marca-muted text-center mt-1">La factura · tocá para agrandar</p></div></div>
      <button v-if="foto" class="lg:hidden fixed bottom-4 right-4 w-20 h-24 rounded-xl overflow-hidden shadow-lg border-2 border-white z-20" @click="fotoGrande = true"><img :src="foto" class="w-full h-full object-cover" /></button>
    </div>

    <div v-if="fotoGrande" class="fixed inset-0 z-50 bg-black/90 overflow-auto" @click.self="fotoGrande = false">
      <img :src="foto" class="mx-auto transition-all" :class="zoom ? 'max-w-none w-[200%]' : 'max-w-full'" @click="zoom = !zoom" />
      <button class="fixed top-3 right-3 btn-primary" @click="fotoGrande = false; zoom = false">Volver a contar</button>
    </div>

    <!-- Un código que no está en esta factura: casi siempre es el código interno de la caja -->
    <Modal :abierto="!!pregunta" :titulo="pregunta?.estado === 'repetido' ? 'Ese código está en dos renglones' : 'Ese código no está en esta factura'" @cerrar="pregunta = null">
      <template v-if="pregunta">
        <p class="text-sm text-marca-muted mb-3">
          <span v-if="pregunta.estado === 'repetido'">¿Cuál de estos es?</span>
          <span v-else-if="pregunta.producto">Es <b>{{ pregunta.producto.nombre }}</b> ({{ pregunta.producto.sku }}), que no está facturado. Si llegó igual, agregalo para reclamarlo.</span>
          <span v-else>Leíste <code class="tabular-nums">{{ pregunta.codigo }}</code>. Si es el código interno de la caja, elegí de qué renglón es: queda aprendido y la próxima la pistola lo reconoce sola.</span>
        </p>
        <div class="grid gap-1.5 max-h-72 overflow-y-auto" data-e2e="pregunta-opciones">
          <button v-for="o in (pregunta.estado === 'repetido' ? pregunta.opciones : r.items.filter(i => !i.no_facturado))" :key="o.id" class="text-left rounded-xl border border-marca-borde hover:border-violeta p-2.5 text-sm" @click="asignar(o)"><span class="font-semibold">{{ o.descripcion }}</span> <span class="text-xs text-marca-muted">· Es éste</span></button>
        </div>
      </template>
      <template #pie>
        <button class="btn-secondary" @click="pregunta = null; refocar()">No llegó (fue un disparo al pasar)</button>
        <button v-if="pregunta && pregunta.estado !== 'repetido'" class="btn-primary" @click="agregarDesde(pregunta)">Agregarlo para reclamar</button>
      </template>
    </Modal>

    <Modal :abierto="agregarAbierto" titulo="Llegó algo que no está en la factura" @cerrar="agregarAbierto = false; refocar()">
      <p class="text-sm text-marca-muted mb-3">Queda anotado como no facturado para reclamarlo. No entra al stock hasta que esté facturado.</p>
      <input v-model="agq" class="input mb-2" placeholder="Buscar el artículo (nombre, código)…" @focus="manual = true" @blur="manual = false" @input="buscar" />
      <div v-if="agr.length" class="grid gap-1 mb-2 max-h-40 overflow-y-auto"><button v-for="p in agr" :key="p.id" class="text-left rounded-lg border p-2 text-sm" :class="ag.product_id === p.id ? 'border-violeta bg-violeta/5' : 'border-marca-borde'" @click="ag.product_id = p.id; ag.descripcion = p.nombre">{{ p.nombre }} <span class="text-xs text-marca-muted">{{ p.sku }}</span></button></div>
      <div class="grid grid-cols-3 gap-2"><input v-model="ag.descripcion" class="input col-span-2" placeholder="o escribí qué llegó" @focus="manual = true" @blur="manual = false" /><input v-model.number="ag.cantidad" type="number" step="any" min="0.001" class="input" placeholder="cantidad" @focus="manual = true" @blur="manual = false" /></div>
      <template #pie><button class="btn-secondary" @click="agregarAbierto = false; refocar()">Cancelar</button><button class="btn-primary" :disabled="!ag.descripcion || !(ag.cantidad > 0)" @click="agregar">Anotarlo</button></template>
    </Modal>

    <!-- Terminar: lo que llegó de más o de menos lo decidís renglón por renglón -->
    <Modal :abierto="cierreAbierto" titulo="Terminar el control" @cerrar="cierreAbierto = false; refocar()">
      <div v-if="difs.length">
        <p class="text-sm text-marca-muted mb-2">Hay {{ difs.length }} renglón(es) con diferencia. Por cada uno: entra <b>lo que contaste</b>, entra <b>lo que dice la factura</b>, o queda <b>pendiente</b> (entra lo contado y el resto se reclama).</p>
        <div class="flex items-center gap-2 text-xs mb-2"><span>Aplicar a todos:</span><button v-for="(t, k) in resoluciones" :key="k" class="btn-secondary !py-1" @click="todas(k)">{{ t }}</button></div>
        <div v-for="i in difs" :key="i.id" class="flex flex-wrap items-center justify-between gap-2 py-2 border-t border-marca-borde text-sm">
          <div><p class="font-medium">{{ i.descripcion }}</p><p class="text-xs text-marca-muted">facturado {{ cantidad(i.esperado) }} · contado {{ cantidad(i.contado) }} · <span :class="i.diferencia < 0 ? 'text-carmin' : 'text-amber-700'">{{ i.diferencia < 0 ? 'faltan ' + cantidad(-i.diferencia) : 'sobran ' + cantidad(i.diferencia) }}</span></p></div>
          <select v-model="res[i.id]" class="input !py-1 !text-xs w-44" :data-e2e="`res-${i.id}`"><option v-for="(t, k) in resoluciones" :key="k" :value="k">{{ t }}</option></select>
        </div>
        <p v-if="noFact.length" class="text-xs text-violeta mt-2">Además llegaron {{ noFact.length }} cosa(s) sin facturar: van al reclamo y no entran al stock.</p>
        <label class="flex items-center gap-2 text-sm mt-3"><input v-model="reclamar" type="checkbox" class="accent-carmin" /> Dejar escrito el reclamo al proveedor (no se manda solo: lo leés antes)</label>
      </div>
      <p v-else class="text-sm">Llegó exactamente lo facturado. {{ r.compra.estado === 'borrador' ? 'Al registrar la compra entra todo al stock.' : '' }}</p>
      <template #pie><button class="btn-secondary" @click="cierreAbierto = false; refocar()">Seguir contando</button><button class="btn-primary" data-e2e="confirmar-cierre" :disabled="cerrando" @click="cerrar">{{ cerrando ? 'Cerrando…' : 'Terminar' }}</button></template>
    </Modal>

    <div v-if="camAbierta" class="fixed inset-0 z-50 bg-black grid place-items-center" @click.self="cerrarCamara">
      <video ref="video" autoplay playsinline class="max-w-full max-h-[80vh]"></video>
      <p class="text-white text-sm absolute top-3 left-0 right-0 text-center">{{ camMsg }}</p>
      <button class="btn-primary absolute bottom-6" @click="cerrarCamara">Cerrar la cámara</button>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { cantidad } from '@/util/formato'

const props = defineProps({ recepcion: Object, foto: String, exigeControl: Boolean })
const r = ref(props.recepcion)
const abierta = computed(() => r.value.estado === 'abierta')
const url = a => `/proveedores/compras/${r.value.compra.id}/recepcion/${a}`

// La pistola escribe acá. El foco vuelve solo salvo que estés escribiendo en otro campo (manual = true).
const lector = ref(null), codigo = ref(''), manual = ref(false), ultimo = ref(null), pregunta = ref(null)
const mult = ref(1), multLibre = ref(null)
function refocar() { if (!abierta.value) return; setTimeout(() => { if (!manual.value && !pregunta.value && !agregarAbierto.value && !cierreAbierto.value && !fotoGrande.value && !camAbierta.value) lector.value?.focus() }, 60) }

// Se escucha, no se mira: agudo corto si entró bien, grave doble si algo anda mal.
let actx = null
function beep(bien) {
  try {
    actx ??= new (window.AudioContext || window.webkitAudioContext)()
    const tono = (f, t0, dur) => { const o = actx.createOscillator(), g = actx.createGain(); o.frequency.value = f; o.type = 'square'; g.gain.value = 0.08; o.connect(g); g.connect(actx.destination); o.start(actx.currentTime + t0); o.stop(actx.currentTime + t0 + dur) }
    if (bien) tono(1760, 0, 0.09); else { tono(220, 0, 0.16); tono(220, 0.22, 0.16) }
  } catch (e) {}
}

async function leer(c) {
  c = (c ?? '').trim(); codigo.value = ''; if (!c) return
  try {
    const { data } = await window.axios.post(url('leer'), { codigo: c, mult: mult.value })
    r.value = data.recepcion; ultimo.value = data
    if (data.estado === 'ok') beep(true)
    else { beep(false); if (data.estado !== 'sobra') pregunta.value = { ...data, codigo: c } }
  } catch (e) { beep(false); ultimo.value = { estado: 'desconocido', descripcion: e.response?.data?.message } }
  refocar()
}
async function asignar(o) {
  const p = pregunta.value; pregunta.value = null
  const { data } = await window.axios.post(url('asignar'), { codigo: p.codigo, item_id: o.id, cantidad: p.cantidad ?? mult.value, aprender: p.estado !== 'repetido' })
  r.value = data.recepcion; ultimo.value = data; beep(true); refocar()
}
async function contar(i, v) { v = Math.max(0, Number(String(v).replace(',', '.')) || 0); if (v === i.contado) return; const { data } = await window.axios.post(url('contar'), { item_id: i.id, contado: v }); r.value = data.recepcion; refocar() }
async function lote(i, l, v) { if ((l ?? '') === (i.lote ?? '') && (v ?? '') === (i.vencimiento ?? '')) return; const { data } = await window.axios.post(url('lote'), { item_id: i.id, lote: l || null, vencimiento: v || null }); r.value = data.recepcion }
async function quitar(i) { const { data } = await window.axios.post(url('quitar'), { item_id: i.id }); r.value = data.recepcion; refocar() }

// Llegó algo que no está en la factura
const agregarAbierto = ref(false), agq = ref(''), agr = ref([]), ag = reactive({ product_id: null, descripcion: '', cantidad: 1 })
let tBuscar = null
function buscar() { clearTimeout(tBuscar); tBuscar = setTimeout(async () => { if (agq.value.trim().length < 2) { agr.value = []; return } try { const { data } = await window.axios.get('/stock/tienda-online/buscar-articulo', { params: { q: agq.value } }); agr.value = data } catch (e) { agr.value = [] } }, 250) }
async function agregar() { const { data } = await window.axios.post(url('agregar'), { product_id: ag.product_id, descripcion: ag.descripcion, cantidad: ag.cantidad }); r.value = data.recepcion; agregarAbierto.value = false; agq.value = ''; agr.value = []; Object.assign(ag, { product_id: null, descripcion: '', cantidad: 1 }); refocar() }
async function agregarDesde(p) { pregunta.value = null; const { data } = await window.axios.post(url('agregar'), { product_id: p.producto?.id ?? null, descripcion: p.producto?.nombre ?? `Código ${p.codigo}`, cantidad: p.cantidad ?? 1 }); r.value = data.recepcion; refocar() }

// Terminar
const cierreAbierto = ref(false), cerrando = ref(false), reclamar = ref(true), res = reactive({})
const resoluciones = { contado: 'Entra lo que conté', facturado: 'Entra lo facturado', pendiente: 'Queda pendiente' }
const difs = computed(() => r.value.items.filter(i => !i.no_facturado && i.diferencia !== 0))
const noFact = computed(() => r.value.items.filter(i => i.no_facturado && i.contado > 0))
function terminar() { difs.value.forEach(i => { res[i.id] ??= 'pendiente' }); cierreAbierto.value = true }
function todas(k) { difs.value.forEach(i => { res[i.id] = k }) }
function cerrar() { cerrando.value = true; router.post(url('cerrar'), { resoluciones: res, reclamar: reclamar.value }, { onFinish: () => (cerrando.value = false) }) }

// La foto de la factura
const fotoGrande = ref(false), zoom = ref(false)

// La cámara del celular (cuando el navegador trae el lector)
const camara = typeof window !== 'undefined' && 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia
const camAbierta = ref(false), camMsg = ref('Apuntá al código de barras'), video = ref(null)
let stream = null, camTimer = null, ultimoCod = '', ultimoT = 0
async function abrirCamara() {
  camAbierta.value = true; await nextTick()
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }); video.value.srcObject = stream
    const det = new window.BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'code_128', 'code_39', 'itf', 'qr_code', 'data_matrix'] })
    camTimer = setInterval(async () => { try { const cs = await det.detect(video.value); const c = cs[0]?.rawValue; if (c && (c !== ultimoCod || Date.now() - ultimoT > 1500)) { ultimoCod = c; ultimoT = Date.now(); camMsg.value = 'Leído: ' + c; leer(c) } } catch (e) {} }, 300)
  } catch (e) { camMsg.value = 'No se pudo abrir la cámara: ' + (e.message || e) }
}
function cerrarCamara() { clearInterval(camTimer); stream?.getTracks().forEach(t => t.stop()); stream = null; camAbierta.value = false; refocar() }

onMounted(() => { refocar(); document.addEventListener('click', refocar) })
onBeforeUnmount(() => { document.removeEventListener('click', refocar); cerrarCamara() })
</script>
