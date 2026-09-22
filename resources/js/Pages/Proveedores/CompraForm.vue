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
    <p v-if="ocr.aviso" class="mb-4 px-4 py-2.5 rounded-xl text-sm" :class="ocr.ok ? 'bg-violeta-light text-violeta' : 'bg-amber-50 text-amber-800 border border-amber-200'">{{ ocr.aviso }}</p>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card grid sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="label">Proveedor</label>
            <BuscadorSelect v-model="form.contact_id" :opciones="opcionesProveedores" placeholder="Buscar proveedor por nombre o CUIT…" @elegido="alElegirProveedor">
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
          <div><label class="label">Condición</label><select v-model="form.condicion" class="input"><option value="cta_cte">Cuenta corriente</option><option value="contado">Contado</option></select></div>
        </div>

        <div class="card p-0 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Ítems</h2><button type="button" @click="agregar()" class="btn-secondary !py-1 text-xs"><Icono nombre="plus" clase="w-3.5 h-3.5" /> Agregar</button></div>
          <div class="overflow-x-auto">
            <table class="table min-w-[720px]">
              <thead><tr><th class="w-[36%]">Artículo (para stock) / descripción</th><th class="w-24 text-right">Cant.</th><th class="w-32 text-right">Costo unit. neto</th><th class="w-20 text-right">Dto %</th><th class="w-20 text-right">IVA</th><th class="text-right">Total</th><th class="w-8"></th></tr></thead>
              <tbody>
                <tr v-for="(it, i) in form.items" :key="i" class="align-top">
                  <td><BuscadorSelect v-model="it.product_id" :opciones="opcionesProductos" placeholder="Sin artículo (solo gasto)" @elegido="o => { if (o) { it.descripcion = o.label; it.unidad = o.unit; it.alicuota_iva = sinIva ? 0 : o.iva } }" /><input v-model="it.descripcion" class="input mt-1 !py-1 text-xs" placeholder="Descripción" /></td>
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
            <div class="flex items-center justify-between mb-2"><p class="label !mb-0">Percepciones y otros impuestos</p><button type="button" @click="form.impuestos.push({ tipo: 'iibb', monto: 0 })" class="text-xs text-violeta font-semibold">+ Agregar</button></div>
            <div v-for="(imp, i) in form.impuestos" :key="i" class="grid grid-cols-[1fr_140px_28px] gap-2 mb-1">
              <select v-model="imp.tipo" class="input !py-1 text-xs"><option value="iibb">Percepción IIBB</option><option value="iva">Percepción IVA</option><option value="ganancias">Percepción Ganancias</option><option value="otros">Otros / tasas</option></select>
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
            <div class="flex justify-between text-lg font-extrabold pt-2 border-t border-marca-borde"><span>Total</span><span class="tabular-nums">{{ moneda(totales.total) }}</span></div>
          </div>
          <p v-if="ocr.totalLeido && Math.abs(ocr.totalLeido - totales.total) > 1" class="text-xs text-amber-700 mt-2">La factura dice {{ moneda(ocr.totalLeido) }}: revisá ítems e impuestos.</p>
          <div class="grid gap-2 mt-5">
            <button type="button" @click="guardar(true)" class="btn-primary w-full" :disabled="form.processing || !form.items.length">{{ form.processing ? 'Procesando…' : 'Registrar compra' }}</button>
            <button type="button" @click="guardar(false)" class="btn-secondary w-full" :disabled="form.processing">Guardar borrador</button>
            <Link :href="compra ? `/proveedores/compras/${compra.id}` : '/proveedores/compras'" class="btn-ghost w-full">Cancelar</Link>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive, computed, onMounted } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import { moneda, hoyISO } from '@/util/formato'

const props = defineProps({ compra: Object, contactIdInicial: Number, proveedores: Array, productos: Array, iaDisponible: Boolean })
const tipos = [['FA', 'Factura A'], ['FB', 'Factura B'], ['FC', 'Factura C'], ['FE', 'Factura E'], ['NCA', 'Nota de crédito A'], ['NCB', 'Nota de crédito B'], ['NCC', 'Nota de crédito C'], ['NDA', 'Nota de débito A'], ['NDB', 'Nota de débito B'], ['NDC', 'Nota de débito C']].map(([key, label]) => ({ key, label }))
const b = props.compra
const form = useForm({
  contact_id: b?.contact_id ?? props.contactIdInicial ?? null, tipo: b?.tipo ?? 'FA', numero_proveedor: b?.numero_proveedor ?? '', cae_proveedor: b?.cae_proveedor ?? '', origen_id: b?.origen_id ?? null,
  fecha: b?.fecha ?? hoyISO(), fecha_vto: b?.fecha_vto ?? '', condicion: b?.condicion ?? 'cta_cte', origen_carga: b ? undefined : 'manual', notas: b?.notas ?? '',
  items: (b?.items ?? []).map(i => ({ ...i })), impuestos: (b?.impuestos ?? []).map(i => ({ ...i })), registrar: false,
})
const proveedor = computed(() => props.proveedores.find(p => p.id === form.contact_id))
const opcionesProveedores = computed(() => props.proveedores.map(p => ({ id: p.id, label: p.name, sub: p.cuit ?? p.condicion_iva })))
const opcionesProductos = computed(() => props.productos.map(p => ({ id: p.id, label: p.name, sub: p.sku, extra: 'costo ' + moneda(p.cost, 0), unit: p.unit, iva: p.iva })))
// Factura C (monotributista) no discrimina IVA: el costo va completo y la alícuota queda en 0.
const sinIva = computed(() => ['FC', 'NCC', 'NDC'].includes(form.tipo))
function alElegirProveedor(o) { const p = props.proveedores.find(x => x.id === o?.id); if (p) { form.tipo = p.condicion_iva === 'Responsable Inscripto' ? 'FA' : (p.condicion_iva === 'Monotributista' ? 'FC' : 'FB'); if (sinIva.value) form.items.forEach(it => (it.alicuota_iva = 0)) } }
function agregar(pre = {}) { form.items.push({ product_id: null, descripcion: '', cantidad: 1, unidad: null, precio_unit: 0, descuento: 0, alicuota_iva: sinIva.value ? 0 : 21, ...pre }) }
const netoItem = it => (Number(it.cantidad) || 0) * (Number(it.precio_unit) || 0) * (1 - (Number(it.descuento) || 0) / 100)
const totalItem = it => netoItem(it) * (1 + (Number(it.alicuota_iva) || 0) / 100)
const totales = computed(() => { const neto = form.items.reduce((a, it) => a + netoItem(it), 0); const iva = form.items.reduce((a, it) => a + netoItem(it) * (Number(it.alicuota_iva) || 0) / 100, 0); const otros = form.impuestos.reduce((a, i) => a + (Number(i.monto) || 0), 0); return { neto, iva, otros, total: neto + iva + otros } })
function guardar(registrar) { form.registrar = registrar; form.post(b ? `/proveedores/compras/${b.id}` : '/proveedores/compras', { preserveScroll: true }) }

const ocr = reactive({ cargando: false, aviso: null, ok: false, totalLeido: null })
async function leerOCR(file) {
  if (!file) return
  ocr.cargando = true; ocr.aviso = null
  try {
    const fd = new FormData(); fd.append('archivo', file)
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
    form.items = (d.items ?? []).map(i => ({ product_id: i.product_id ?? null, descripcion: i.descripcion ?? '', cantidad: Number(i.cantidad) || 1, unidad: null, precio_unit: Number(i.precio_unit) || 0, descuento: Number(i.descuento) || 0, alicuota_iva: Number(i.alicuota_iva ?? 21) }))
    form.impuestos = (d.percepciones ?? []).filter(p => Number(p.monto) > 0).map(p => ({ tipo: (p.tipo || 'otros').startsWith('iibb') ? 'iibb' : (['iva', 'ganancias'].includes(p.tipo) ? p.tipo : 'otros'), monto: Number(p.monto) }))
    ocr.totalLeido = Number(d.total) || null
    ocr.ok = true
    ocr.aviso = `Factura leída (confianza ${Math.round((d.confianza ?? 0) * 100)}%). ${data.proveedor_nuevo ? `El proveedor "${d.proveedor?.nombre}" (${d.proveedor?.cuit ?? 'sin CUIT'}) no existe: crealo y elegilo. ` : ''}${d.observaciones ?? ''} Revisá todo antes de registrar.`
  } catch (e) { ocr.ok = false; ocr.aviso = e.response?.data?.message ?? 'No se pudo leer el archivo.' }
  finally { ocr.cargando = false }
}
onMounted(() => { if (form.contact_id && !b) alElegirProveedor({ id: form.contact_id }) })
</script>
