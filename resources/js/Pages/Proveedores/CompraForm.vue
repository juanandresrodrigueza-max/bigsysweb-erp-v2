<template>
  <AppLayout :titulo="compra ? 'Editar compra' : 'Cargar factura de compra'">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/proveedores/compras" class="text-xs text-marca-muted hover:text-carmin">← Compras</Link>
        <h1 class="page-title">{{ compra ? 'Editar compra' : 'Cargar factura de compra' }}</h1>
        <p class="page-subtitle">Cargá los datos tal como vienen en el comprobante del proveedor. Al registrar impacta en su cuenta corriente y en el stock.</p>
      </div>
      <label class="btn-violeta cursor-pointer"><input type="file" accept="image/*,.pdf" class="hidden" @change="leerOCR($event.target.files[0])" /><Icono nombre="sparkles" clase="w-4 h-4" /> {{ ocr.cargando ? 'Leyendo…' : 'Leer factura con IA' }}</label>
    </div>
    <p v-if="desdeOrden" class="mb-4 px-4 py-2.5 rounded-xl text-sm bg-violeta-light text-violeta">Recibiendo la orden <b>{{ desdeOrden.numero_oc }}</b>: ajustá cantidades si el proveedor entregó distinto, cargá el número de factura y registrá. La orden se marca recibida sola.</p>
    <p v-if="ocr.aviso" class="mb-4 px-4 py-2.5 rounded-xl text-sm" :class="ocr.ok ? 'bg-violeta-light text-violeta' : 'bg-amber-50 text-amber-800 border border-amber-200'">{{ ocr.aviso }}</p>
    <div v-if="ocr.avisos.length" class="mb-4 space-y-1" data-e2e="avisos-lectura"><p v-for="(a, i) in ocr.avisos" :key="i" class="px-3 py-1.5 rounded-lg text-xs" :class="a.startsWith('Los datos de la factura salieron del código QR') ? 'bg-emerald-50 text-emerald-800' : 'bg-marca-fondo text-marca-texto'">{{ a }}</p></div>
    <div v-if="!compra" class="mb-4 flex flex-wrap items-center gap-2 text-xs" data-e2e="modo-carga">
      <label class="flex items-center gap-1 rounded-full border px-3 py-1" :class="form.tipo === 'REM' ? 'border-violeta bg-violeta/5' : 'border-marca-borde'"><input type="checkbox" :checked="form.tipo === 'REM'" class="accent-carmin" @change="form.tipo = $event.target.checked ? 'REM' : 'FA'" /> Es un remito sin precios: entra sólo al stock (la factura llega después)</label>
      <select v-if="abiertos.remitos.length && form.tipo !== 'REM'" v-model="form.remito_id" class="input !py-1 w-auto" data-e2e="remito-de"><option :value="null">¿Es la factura de un remito que ya entró? No</option><option v-for="r in abiertos.remitos" :key="r.id" :value="r.id">Sí: del remito {{ r.numero }} del {{ r.fecha }} (sólo contable, el stock ya entró)</option></select>
      <button v-if="abiertos.ordenes.length" type="button" class="btn-secondary !py-1" @click="comparar" data-e2e="comparar-oc">Comparar con orden de compra</button>
      <button type="button" class="btn-ghost !py-1 ml-auto" :disabled="!form.items.length" @click="dejarParaDespues" data-e2e="dejar-despues">{{ borradorId ? 'Guardar lo que va (a medio cargar)' : 'Dejarla para después' }}</button>
    </div>
    <div v-if="comparacion" class="mb-4 card text-sm" data-e2e="comparacion">
      <div class="flex items-center justify-between"><h2 class="font-bold">Contra la orden {{ comparacion.orden }} <span class="text-xs text-marca-muted font-normal">· tolerancia de precio {{ comparacion.tolerancia }} %</span></h2><button class="text-xs text-marca-muted" @click="comparacion = null">✕</button></div>
      <p v-if="!comparacion.diferencias.length" class="text-emerald-700 text-xs mt-1">La factura coincide con la orden.</p>
      <ul v-else class="mt-1 text-xs space-y-0.5"><li v-for="(d, i) in comparacion.diferencias" :key="i" :class="d.tipo === 'precio' ? 'text-amber-800' : 'text-carmin'">• {{ d.descripcion }}: {{ d.texto }}</li></ul>
      <button v-if="comparacion.reclamo" class="btn-secondary !py-1 text-xs mt-2" @click="copiar(comparacion.reclamo)">Copiar el reclamo para mandarlo</button>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card grid sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="label">Proveedor</label>
            <BuscadorSelect v-model="form.contact_id" :opciones="opcionesProveedores" :url="props.catalogoParcial.proveedores ? '/buscar/contactos/proveedor' : null" @cargados="f => sumar(proveedoresCat, f)" placeholder="Buscar proveedor por nombre o CUIT…" @elegido="alElegirProveedor">
              <template #pie><Link href="/proveedores?nuevo=1" class="block px-3 py-2 text-xs text-carmin font-semibold border-t border-marca-borde">+ Crear proveedor nuevo</Link></template>
            </BuscadorSelect>
            <p v-if="form.errors.contact_id" class="text-carmin text-xs mt-1">{{ form.errors.contact_id }}</p>
            <p v-if="proveedor" class="text-xs text-marca-muted mt-1">{{ proveedor.condicion_iva }} · le debemos {{ moneda(proveedor.balance, 0) }}</p>
          </div>
          <div><label class="label">Tipo</label><select v-model="form.tipo" class="input"><option v-for="t in tipos" :key="t.key" :value="t.key">{{ t.label }}</option></select></div>
          <div><label class="label">Número del proveedor</label><input v-model="form.numero_proveedor" class="input tabular-nums" placeholder="0003-00012345" /><p v-if="form.errors.numero_proveedor" class="text-carmin text-xs mt-1">{{ form.errors.numero_proveedor }}</p></div>
          <div><label class="label">Fecha del comprobante</label><input v-model="form.fecha" type="date" class="input" /><p v-if="form.errors.fecha" class="text-carmin text-xs mt-1">{{ form.errors.fecha }}</p></div>
          <div><label class="label">Vencimiento (vacío = plazo del proveedor)</label><input v-model="form.fecha_vto" type="date" class="input" /></div>
          <div><label class="label">CAE (opcional)</label><input v-model="form.cae_proveedor" class="input tabular-nums" /></div>
          <div>
            <label class="label">Moneda</label>
            <div class="flex items-center gap-2">
              <select v-model="form.moneda" class="input !w-28"><option value="ARS">Pesos</option><option value="USD">Dólares</option></select>
              <template v-if="form.moneda === 'USD'"><span class="text-xs text-marca-muted">cotización</span><input v-model.number="form.cotizacion" type="number" step="any" min="0" class="input text-right tabular-nums" placeholder="$ por USD" /></template>
            </div>
            <p v-if="form.errors.cotizacion" class="text-carmin text-xs mt-1">{{ form.errors.cotizacion }}</p>
            <p v-else-if="form.moneda === 'USD'" class="text-[10px] text-marca-muted mt-1">Cargá los precios en dólares: se guardan en pesos a esta cotización y la deuda queda en USD.</p>
          </div>
          <div><label class="label">Condición</label><select v-model="form.condicion" class="input"><option value="cta_cte">Cuenta corriente</option><option value="contado">Contado</option></select></div>
        </div>

        <div class="card p-0 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Ítems</h2><button type="button" @click="agregar()" class="btn-secondary !py-1 text-xs"><Icono nombre="plus" clase="w-3.5 h-3.5" /> Agregar</button></div>
          <div class="overflow-x-auto">
            <table class="table min-w-[720px]">
              <thead><tr><th class="w-[36%]">Artículo (para stock) / descripción</th><th class="w-24 text-right">Cant.</th><th class="w-32 text-right">Costo unit. neto{{ form.moneda === 'USD' ? ' (USD)' : '' }}</th><th class="w-20 text-right">Dto %</th><th class="w-20 text-right">IVA</th><th class="text-right">Total</th><th class="w-8"></th></tr></thead>
              <tbody>
                <tr v-for="(it, i) in form.items" :key="i" class="align-top">
                  <td><BuscadorSelect v-model="it.product_id" :opciones="opcionesProductos" :url="props.catalogoParcial.productos ? '/buscar/articulos/compra' : null" @cargados="f => sumar(productosCat, f)" placeholder="Sin artículo (solo gasto)" @elegido="o => { if (o) { it.descripcion = o.label; it.unidad = o.unit; it.alicuota_iva = sinIva ? 0 : o.iva } }" /><input v-model="it.descripcion" class="input mt-1 !py-1 text-xs" placeholder="Descripción" />
                    <div v-if="prodDe(it)?.perecedero || prodDe(it)?.seriado" class="grid grid-cols-2 gap-1 mt-1">
                      <input v-if="prodDe(it)?.perecedero" v-model="it.lote" class="input !py-1 text-xs" placeholder="Lote" />
                      <input v-if="prodDe(it)?.perecedero" v-model="it.vencimiento" type="date" class="input !py-1 text-xs" title="Vencimiento" />
                      <input v-if="prodDe(it)?.seriado" v-model="it.serie" class="input !py-1 text-xs col-span-2" placeholder="N° de serie (separá con coma si son varios)" />
                    </div></td>
                  <td><input v-model.number="it.cantidad" type="number" min="0" step="any" class="input text-right" /></td>
                  <td><input v-model.number="it.precio_unit" type="number" min="0" step="any" class="input text-right" /></td>
                  <td><input v-model.number="it.descuento" type="number" min="0" max="100" step="any" class="input text-right" /></td>
                  <td><select v-model.number="it.alicuota_iva" class="input !px-1"><option v-for="a in [0,2.5,5,10.5,21,27]" :key="a" :value="a">{{ a }}%</option></select></td>
                  <td class="text-right font-semibold tabular-nums pt-3">{{ moneda(totalItem(it)) }}</td>
                  <td class="pt-2"><button type="button" @click="form.items.splice(i,1)" class="text-marca-muted hover:text-carmin"><Icono nombre="trash" clase="w-4 h-4" /></button></td>
                </tr>
                <tr v-if="!form.items.length"><td colspan="7" class="text-center text-marca-muted py-8">Agregá ítems o leé la factura con IA.</td></tr>
              </tbody>
            </table>
          </div>
          <div class="px-4 py-3 border-t border-marca-borde">
            <div class="flex items-center justify-between mb-2"><p class="label !mb-0">Percepciones y otros impuestos</p><button type="button" @click="form.impuestos.push({ tipo: 'iibb', jurisdiccion: '', monto: 0 })" class="text-xs text-violeta font-semibold">+ Agregar</button></div>
            <div v-for="(imp, i) in form.impuestos" :key="i" class="grid grid-cols-[1fr_120px_140px_28px] gap-2 mb-1">
              <select v-model="imp.tipo" class="input !py-1 text-xs"><option value="iibb">Percepción IIBB</option><option value="iva">Percepción IVA</option><option value="ganancias">Percepción Ganancias</option><option value="otros">Otros / tasas</option></select>
              <select v-if="imp.tipo === 'iibb'" v-model="imp.jurisdiccion" class="input !py-1 text-xs" title="Jurisdicción (para SIFERE)" data-jurisdiccion><option value="">Jurisdicción…</option><option v-for="j in JURISDICCIONES" :key="j.sigla" :value="j.sigla">{{ j.nombre }}</option></select><span v-else></span>
              <input v-model.number="imp.monto" type="number" step="any" min="0" class="input !py-1 text-xs text-right" />
              <button type="button" @click="form.impuestos.splice(i,1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
            </div>
          </div>
          <p v-if="form.errors.items" class="text-carmin text-xs px-4 pb-3">{{ form.errors.items }}</p>
        </div>
        <div class="card"><label class="label">Notas</label><textarea v-model="form.notas" rows="2" class="input"></textarea></div>
      </div>

      <div class="space-y-4">
        <div class="card sticky top-20">
          <h2 class="font-bold mb-3">Resumen</h2>
          <div class="space-y-1.5 text-sm">
            <div class="flex justify-between"><span class="text-marca-muted">Neto</span><span class="tabular-nums">{{ moneda(totales.neto) }}</span></div>
            <div class="flex justify-between"><span class="text-marca-muted">IVA</span><span class="tabular-nums">{{ moneda(totales.iva) }}</span></div>
            <div v-if="totales.otros" class="flex justify-between"><span class="text-marca-muted">Percepciones</span><span class="tabular-nums">{{ moneda(totales.otros) }}</span></div>
            <div class="flex justify-between text-lg font-extrabold pt-2 border-t border-marca-borde"><span>Total</span><span class="tabular-nums">{{ form.moneda === 'USD' ? 'USD ' + totales.total.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : moneda(totales.total) }}</span></div>
            <div v-if="form.moneda === 'USD'" class="flex justify-between text-xs text-marca-muted"><span>En pesos a {{ moneda(form.cotizacion || 0, 2) }}</span><span class="tabular-nums">{{ moneda(totales.total * (Number(form.cotizacion) || 0)) }}</span></div>
          </div>
          <p v-if="ocr.totalLeido && Math.abs(ocr.totalLeido - totales.total) > 1" class="text-xs text-amber-700 mt-2">La factura dice {{ moneda(ocr.totalLeido) }}: revisá ítems e impuestos.</p>
          <div v-if="revision" class="mt-3 text-xs rounded-xl border p-2" :class="revision.cierra ? 'border-marca-borde' : 'border-carmin bg-carmin-light/40'" data-e2e="revision">
            <p class="font-bold mb-1">Antes de mandar</p>
            <p v-for="(a, i) in revision.alertas" :key="i" :class="a.tipo === 'no_cierra' || a.tipo === 'bajo_costo' ? 'text-carmin' : 'text-amber-800'">• {{ a.texto }}</p>
            <p v-if="!revision.alertas.length" class="text-emerald-700">Cierra: los renglones suman {{ moneda(revision.suma) }}.</p>
            <div class="max-h-48 overflow-y-auto mt-1 divide-y divide-marca-borde">
              <label v-for="f in revision.filas.filter(x => x.product_id)" :key="f.k" class="flex items-center gap-2 py-1" :class="f.cambia_mucho || f.bajo_costo ? 'text-carmin' : ''"><input type="checkbox" v-model="form.items[f.k].actualiza_costo" class="accent-carmin" /><span class="flex-1 truncate">{{ f.nombre }}</span><span class="tabular-nums">{{ f.costo_actual != null ? moneda(f.costo_actual) : '—' }} → <b>{{ moneda(f.costo_nuevo) }}</b><span v-if="f.variacion != null"> ({{ f.variacion > 0 ? '+' : '' }}{{ f.variacion }} %)</span><span v-if="f.margen != null" class="text-marca-muted"> · margen {{ f.margen }} %</span></span></label>
            </div>
            <p class="text-marca-muted mt-1">Tildado = se escribe ese costo en el artículo al registrar.</p>
          </div>
          <div class="grid gap-2 mt-5">
            <button type="button" @click="revisarAntes" class="btn-secondary w-full" :disabled="!form.items.length" data-e2e="revisar">Revisar antes de mandar</button>
            <button type="button" @click="guardar(true)" class="btn-primary w-full" :disabled="form.processing || !form.items.length || (revision && !revision.cierra)">{{ form.processing ? 'Procesando…' : 'Registrar compra' }}</button>
            <button type="button" @click="guardar(false)" class="btn-secondary w-full" :disabled="form.processing">Guardar borrador</button>
            <Link :href="compra ? `/proveedores/compras/${compra.id}` : '/proveedores/compras'" class="btn-ghost w-full">Cancelar</Link>
          </div>
        </div>
      </div>
    </div>
      <Modal :abierto="!!pie" titulo="El pie de la factura: ¿qué es esto?" @cerrar="pie = null">
      <p v-if="pie" class="text-sm">La factura trae <b>«{{ pie.concepto }}»</b> por <b>{{ moneda(pie.monto) }}</b> y el sistema no sabe dónde va. Se pregunta una vez: queda aprendido para este proveedor, para todos los negocios.</p>
      <div v-if="pie" class="grid gap-1 mt-3"><button v-for="(t, k) in tratamientos" :key="k" class="text-left rounded-lg border border-marca-borde px-3 py-2 text-sm hover:border-violeta" @click="resolverPie(k)" :data-e2e="`pie-${k}`">{{ t }}</button></div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { JURISDICCIONES } from '@/util/jurisdicciones'
import { reactive, computed, onMounted, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, hoyISO } from '@/util/formato'

const props = defineProps({ catalogoParcial: { type: Object, default: () => ({}) },  compra: Object, contactIdInicial: Number, proveedores: Array, productos: Array, iaDisponible: Boolean, desdeOrden: Object, cotizacionUsd: { type: Number, default: 0 } })
const productosCat = ref([...props.productos])
const proveedoresCat = ref([...props.proveedores])
function sumar(lista, filas) { const arr = Array.isArray(lista) ? lista : lista.value; const por = new Map(arr.map(x => [x.id, x])); filas.forEach(f => { const e = por.get(f.id); if (e) Object.assign(e, f); else arr.push(f) }) } // en el template los refs llegan desenvueltos; lo que ya está se actualiza (sugerido, precios)
const tipos = [['FA', 'Factura A'], ['FB', 'Factura B'], ['FC', 'Factura C'], ['FE', 'Factura E'], ['NCA', 'Nota de crédito A'], ['NCB', 'Nota de crédito B'], ['NCC', 'Nota de crédito C'], ['NDA', 'Nota de débito A'], ['NDB', 'Nota de débito B'], ['NDC', 'Nota de débito C']].map(([key, label]) => ({ key, label }))
const b = props.compra ?? props.desdeOrden
const form = useForm({
  contact_id: b?.contact_id ?? props.contactIdInicial ?? null, tipo: b?.tipo ?? 'FA', numero_proveedor: b?.numero_proveedor ?? '', cae_proveedor: b?.cae_proveedor ?? '', origen_id: b?.origen_id ?? null, orden_compra_id: b?.orden_compra_id ?? null,
  fecha: b?.fecha ?? hoyISO(), fecha_vto: b?.fecha_vto ?? '', condicion: b?.condicion ?? 'cta_cte', origen_carga: props.compra ? undefined : 'manual', notas: b?.notas ?? '',
  moneda: b?.moneda ?? 'ARS', cotizacion: (b?.moneda === 'USD' ? b?.cotizacion : null) || props.cotizacionUsd || null,
  solo_stock: false, remito_id: null, borrador_id: null,
  items: (b?.items ?? []).map(i => ({ ...i, precio_unit: i.precio_unit_me ?? i.precio_unit, actualiza_costo: i.actualiza_costo ?? true })), impuestos: (b?.impuestos ?? []).map(i => ({ ...i, jurisdiccion: i.tipo?.startsWith('iibb_') ? i.tipo.slice(5).toUpperCase() : '', tipo: i.tipo?.startsWith('iibb') ? 'iibb' : i.tipo })), registrar: false,
})
const proveedor = computed(() => proveedoresCat.value.find(p => p.id === form.contact_id))
const opcionesProveedores = computed(() => proveedoresCat.value.map(p => ({ id: p.id, label: p.name, sub: p.cuit ?? p.condicion_iva })))
const opcionesProductos = computed(() => productosCat.value.map(p => ({ id: p.id, label: p.name, sub: p.sku, extra: 'costo ' + moneda(p.cost, 0), unit: p.unit, iva: p.iva })))
// Factura C (monotributista) no discrimina IVA: el costo va completo y la alícuota queda en 0.
const sinIva = computed(() => ['FC', 'NCC', 'NDC'].includes(form.tipo))
function alElegirProveedor(o) { cargarAbiertos(); const p = proveedoresCat.value.find(x => x.id === o?.id); if (p) { form.tipo = p.condicion_iva === 'Responsable Inscripto' ? 'FA' : (p.condicion_iva === 'Monotributista' ? 'FC' : 'FB'); if (sinIva.value) form.items.forEach(it => (it.alicuota_iva = 0)) } }
function agregar(pre = {}) { form.items.push({ product_id: null, descripcion: '', cantidad: 1, unidad: null, precio_unit: 0, descuento: 0, alicuota_iva: sinIva.value ? 0 : 21, lote: '', vencimiento: '', serie: '', actualiza_costo: true, ...pre }) }
const prodDe = it => it.product_id ? productosCat.value.find(p => p.id === it.product_id) : null
const netoItem = it => (Number(it.cantidad) || 0) * (Number(it.precio_unit) || 0) * (1 - (Number(it.descuento) || 0) / 100)
const totalItem = it => netoItem(it) * (1 + (Number(it.alicuota_iva) || 0) / 100)
const totales = computed(() => { const neto = form.items.reduce((a, it) => a + netoItem(it), 0); const iva = form.items.reduce((a, it) => a + netoItem(it) * (Number(it.alicuota_iva) || 0) / 100, 0); const otros = form.impuestos.reduce((a, i) => a + (Number(i.monto) || 0), 0); return { neto, iva, otros, total: neto + iva + otros } })
function guardar(registrar) { form.registrar = registrar; form.transform(d => ({ ...d, impuestos: d.impuestos.map(i => ({ ...i, tipo: i.tipo === 'iibb' && i.jurisdiccion ? `iibb_${i.jurisdiccion.toLowerCase()}` : i.tipo })) })).post(props.compra ? `/proveedores/compras/${props.compra.id}` : '/proveedores/compras', { preserveScroll: true }) }

const ocr = reactive({ cargando: false, aviso: null, ok: false, totalLeido: null, avisos: [], qr: null })
// Fase 31: el QR de ARCA se lee en el teléfono (BarcodeDetector) antes de mandar la foto: la cabecera sale exacta.
async function leerQr(file) {
  try {
    if (!('BarcodeDetector' in window) || !file.type.startsWith('image/')) return null
    const det = new window.BarcodeDetector({ formats: ['qr_code'] }); const bmp = await createImageBitmap(file)
    const codes = await det.detect(bmp); return codes.map(c => c.rawValue).find(v => /afip\.gob\.ar\/fe\/qr|[?&]p=/.test(v)) ?? null
  } catch (e) { return null }
}
async function leerOCR(file) {
  if (!file) return
  ocr.cargando = true; ocr.aviso = null; ocr.avisos = []
  try {
    const fd = new FormData(); fd.append('archivo', file)
    const qr = await leerQr(file); if (qr) fd.append('qr', qr)
    const { data } = await window.axios.post('/proveedores/compras/ocr', fd)
    if (!data.ok) { ocr.ok = false; ocr.aviso = data.aviso; return }
    const d = data.datos
    if (data.proveedor_id) form.contact_id = data.proveedor_id
    if (d.tipo) form.tipo = d.tipo
    if (d.numero) form.numero_proveedor = d.numero
    if (d.fecha) form.fecha = d.fecha
    if (d.fecha_vto) form.fecha_vto = d.fecha_vto
    if (d.cae) form.cae_proveedor = d.cae
    form.origen_carga = 'ocr'
    form.items = (d.items ?? []).map(i => ({ product_id: i.product_id ?? null, descripcion: i.descripcion ?? '', codigo: i.codigo ?? null, cantidad: Number(i.cantidad) || 1, unidad: null, precio_unit: Number(i.precio_unit) || 0, descuento: Number(i.descuento) || 0, alicuota_iva: Number(i.alicuota_iva ?? 21), actualiza_costo: i.actualiza_costo ?? !i.sin_cargo, bulto: i.bulto ?? null, por: i.por ?? null, sin_cargo: !!i.sin_cargo }))
    ocr.avisos = d.avisos ?? []; ocr.qr = !!d.cabecera_qr; pendientesPie.value = d.preguntar ?? []; siguientePie()
    if (form.contact_id) cargarAbiertos()
    form.impuestos = (d.percepciones ?? []).filter(p => Number(p.monto) > 0).map(p => ({ tipo: (p.tipo || 'otros').startsWith('iibb') ? 'iibb' : (['iva', 'ganancias'].includes(p.tipo) ? p.tipo : 'otros'), jurisdiccion: '', monto: Number(p.monto) }))
    ocr.totalLeido = Number(d.total) || null
    ocr.ok = true
    ocr.aviso = `Factura leída (confianza ${Math.round((d.confianza ?? 0) * 100)}%). ${data.proveedor_nuevo ? `El proveedor "${d.proveedor?.nombre}" (${d.proveedor?.cuit ?? 'sin CUIT'}) no existe: crealo y elegilo. ` : ''}${d.observaciones ?? ''} Revisá todo antes de registrar.`
  } catch (e) { ocr.ok = false; ocr.aviso = e.response?.data?.message ?? 'No se pudo leer el archivo.' }
  finally { ocr.cargando = false }
}
// --- El pie que se pregunta una vez por proveedor ---
const tratamientos = { percepcion_iibb: 'Percepción de Ingresos Brutos', percepcion_iva: 'Percepción de IVA', percepcion_ganancias: 'Percepción de Ganancias', descuento: 'Descuento sobre la factura (se reparte en los renglones)', flete: 'Flete o gasto: un renglón más', ignorar: 'No es nada: ignorarlo' }
const pendientesPie = ref([]), pie = ref(null)
function siguientePie() { pie.value = pendientesPie.value.shift() ?? null }
async function resolverPie(k) {
  const p = pie.value
  try { await window.axios.post('/proveedores/compras/regla-pie', { contact_id: form.contact_id, concepto: p.concepto, tratamiento: k }) } catch (e) {}
  if (k === 'percepcion_iibb') form.impuestos.push({ tipo: 'iibb', jurisdiccion: '', monto: p.monto })
  else if (k === 'percepcion_iva') form.impuestos.push({ tipo: 'iva', jurisdiccion: '', monto: p.monto })
  else if (k === 'percepcion_ganancias') form.impuestos.push({ tipo: 'ganancias', jurisdiccion: '', monto: p.monto })
  else if (k === 'flete') agregar({ descripcion: p.concepto, precio_unit: p.monto, actualiza_costo: false })
  else if (k === 'descuento') { const base = form.items.reduce((a, it) => a + netoItem(it), 0); if (base > 0) form.items.forEach(it => { it.descuento = Math.round(Math.min(100, (Number(it.descuento) || 0) + p.monto / base * 100 * (1 - (Number(it.descuento) || 0) / 100)) * 100) / 100 }) }
  siguientePie()
}

// --- Revisar antes de mandar ---
const revision = ref(null)
async function revisarAntes() { const { data } = await window.axios.post('/proveedores/compras/revisar', { items: form.items, total: ocr.totalLeido, impuestos: form.impuestos }); revision.value = data; data.filas.forEach(f => { if (form.items[f.k]) form.items[f.k].actualiza_costo = f.actualiza_costo && !f.bajo_costo }) }

// --- Remitos abiertos, órdenes para comparar ---
const abiertos = reactive({ ordenes: [], remitos: [] }), comparacion = ref(null)
async function cargarAbiertos() { if (!form.contact_id) return; try { const { data } = await window.axios.get(`/proveedores/compras/abiertos/${form.contact_id}`); Object.assign(abiertos, data) } catch (e) {} }
async function comparar() { const oc = abiertos.ordenes.length === 1 ? abiertos.ordenes[0] : abiertos.ordenes.find(o => String(o.id) === window.prompt('¿Con qué orden? ' + abiertos.ordenes.map(o => `${o.id}: ${o.numero} (${o.fecha})`).join(' · '), String(abiertos.ordenes[0].id))); if (!oc) return; const { data } = await window.axios.post('/proveedores/compras/comparar-oc', { orden_compra_id: oc.id, items: form.items }); comparacion.value = data; form.orden_compra_id = oc.id }
async function copiar(t) { try { await navigator.clipboard.writeText(t) } catch (e) { window.prompt('Copiá el texto', t) } }

// --- Facturas a medio cargar ---
const borradorId = ref(new URLSearchParams(location.search).get('borrador') ? Number(new URLSearchParams(location.search).get('borrador')) : null)
async function dejarParaDespues() {
  const { data } = await window.axios.post(`/proveedores/compras/borradores${borradorId.value ? '/' + borradorId.value : ''}`, { datos: { ...form.data(), ocr: { totalLeido: ocr.totalLeido, avisos: ocr.avisos }, proveedor_nombre: proveedor.value?.name } })
  borradorId.value = data.id; form.borrador_id = data.id; ocr.aviso = 'Quedó guardada a medio cargar: la retomás desde Compras, desde esta computadora o desde otra.'; ocr.ok = true
}
async function retomar(id) { try { const { data } = await window.axios.get(`/proveedores/compras/borradores/${id}`); const d = data.datos; Object.keys(d).forEach(k => { if (k in form && k !== 'ocr' && k !== 'proveedor_nombre') form[k] = d[k] }); form.borrador_id = id; ocr.totalLeido = d.ocr?.totalLeido ?? null; ocr.avisos = d.ocr?.avisos ?? []; ocr.ok = true; ocr.aviso = 'Retomada tal como la dejaste.'; cargarAbiertos() } catch (e) {} }
onMounted(() => { if (form.contact_id && !props.compra) alElegirProveedor({ id: form.contact_id }); if (borradorId.value) retomar(borradorId.value); if (form.contact_id) cargarAbiertos() })
</script>
