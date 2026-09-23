<template>
  <AppLayout :titulo="titulo">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <h1 class="page-title">{{ titulo }}</h1>
        <p class="page-subtitle" v-if="origen">Desde {{ origen.nombre }} {{ origen.numero }}.</p>
        <p class="page-subtitle" v-else>Cargá cliente e ítems; el tipo de factura sale solo según la condición de IVA.</p>
      </div>
      <div class="flex gap-2">
        <button type="button" @click="abrirIA = true" class="btn-violeta"><Icono nombre="sparkles" clase="w-4 h-4" /> Cargar con IA</button>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Tipo</label>
            <select v-model="form.tipo" class="input" :disabled="!!form.origen_id && esConversion">
              <option v-for="t in tipos" :key="t.key" :value="t.key">{{ t.label }}</option>
            </select>
            <p v-if="tipoResuelto" class="text-xs text-marca-muted mt-1">Se emitirá como <b>{{ tipoResuelto }}</b>.</p>
          </div>
          <div>
            <label class="label">Cliente</label>
            <BuscadorSelect v-model="form.contact_id" :opciones="opcionesClientes" placeholder="Buscar cliente por nombre o CUIT…" @elegido="alElegirCliente">
              <template #pie><Link href="/clientes" class="block px-3 py-2 text-xs text-carmin font-semibold border-t border-marca-borde">+ Crear cliente nuevo</Link></template>
            </BuscadorSelect>
            <p v-if="form.errors.contact_id" class="text-carmin text-xs mt-1">{{ form.errors.contact_id }}</p>
            <p v-if="cliente" class="text-xs text-marca-muted mt-1">{{ cliente.condicion_iva }} · Lista {{ cliente.lista_precios }} <span v-if="cliente.descuento">· {{ cliente.descuento }}% dto.</span> · Saldo {{ moneda(cliente.balance, 0) }}<span v-if="cliente.credit_limit > 0"> / límite {{ moneda(cliente.credit_limit, 0) }}</span></p>
          </div>
          <div><label class="label">Fecha</label><input v-model="form.fecha" type="date" class="input" /><p v-if="form.errors.fecha" class="text-carmin text-xs mt-1">{{ form.errors.fecha }}</p></div>
          <div>
            <label class="label">Condición</label>
            <div class="flex gap-1 bg-marca-fondo rounded-xl p-1">
              <button type="button" v-for="c in [['contado','Contado'],['cta_cte','Cuenta corriente']]" :key="c[0]" @click="form.condicion = c[0]" class="flex-1 py-1.5 rounded-lg text-sm font-semibold transition" :class="form.condicion === c[0] ? 'bg-white shadow-card' : 'text-marca-muted'">{{ c[1] }}</button>
            </div>
          </div>
          <div v-if="form.condicion === 'cta_cte' && esFactura"><label class="label">Días para el vencimiento</label><input v-model.number="form.dias_vto" type="number" min="0" class="input" /></div>
          <div v-if="vendedores.length"><label class="label">Vendedor</label><select v-model="form.vendedor_id" class="input"><option :value="null">{{ cliente?.vendedor ? 'El del cliente' : 'Sin vendedor' }}</option><option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.nombre }}</option></select></div>
          <div v-if="puntosVenta.length > 1"><label class="label">Punto de venta</label><select v-model="form.punto_venta_id" class="input"><option v-for="p in puntosVenta" :key="p.id" :value="p.id">{{ String(p.numero).padStart(4,'0') }} · {{ p.sucursal ?? 'General' }}</option></select></div>
          <label v-if="esFactura" class="flex items-center gap-2 text-sm sm:col-span-2 p-3 rounded-xl border" :class="form.es_acopio ? 'border-violeta bg-violeta-light' : 'border-marca-borde'">
            <input v-model="form.es_acopio" type="checkbox" class="accent-violeta" />
            <span><b>Es acopio</b> · el cliente paga ahora y retira la mercadería en partes. El stock no se descuenta hasta cada retiro y el precio queda congelado.</span>
          </label>
          <label v-if="esFactura && !form.es_acopio && !form.origen_id" class="flex items-center gap-2 text-sm sm:col-span-2 p-3 rounded-xl border" :class="form.entrega_pendiente ? 'border-violeta bg-violeta-light' : 'border-marca-borde'">
            <input v-model="form.entrega_pendiente" type="checkbox" class="accent-violeta" />
            <span><b>Entrega pendiente</b> · se factura ahora y la mercadería sale después con remito (en una o varias entregas). El stock se descuenta con cada remito.</span>
          </label>
          <label v-if="esFactura && cliente && cliente.condicion_iva === 'Responsable Inscripto'" class="flex items-center gap-2 text-sm sm:col-span-2 p-3 rounded-xl border" :class="form.fce ? 'border-carmin bg-red-50/40' : 'border-marca-borde'">
            <input v-model="form.fce" type="checkbox" class="accent-carmin" />
            <span class="flex-1"><b>Factura de Crédito Electrónica MiPyME</b> · obligatoria si el cliente es empresa grande y el total supera el mínimo vigente. Vence a 30 días y se puede negociar.<span v-if="!cbuFce" class="text-carmin"> Falta el CBU en Configuración → Impuestos.</span></span>
            <span v-if="form.fce" class="flex items-center gap-1 text-xs whitespace-nowrap">Vto. pago <input v-model="form.fce_vto_pago" type="date" class="input !py-1 text-xs" @click.stop /></span>
          </label>
        </div>

        <div class="card p-0 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde">
            <h2 class="font-bold">Ítems</h2>
            <button type="button" @click="agregar()" class="btn-secondary !py-1 text-xs"><Icono nombre="plus" clase="w-3.5 h-3.5" /> Agregar</button>
          </div>
          <div class="overflow-x-auto">
            <table class="table min-w-[720px]">
              <thead><tr><th class="w-[36%]">Artículo</th><th class="w-24 text-right">Cant.</th><th class="w-32 text-right">P. unit. (neto)</th><th class="w-20 text-right">Dto %</th><th class="w-20 text-right">IVA</th><th class="text-right">Total</th><th class="w-8"></th></tr></thead>
              <tbody>
                <tr v-for="(it, i) in form.items" :key="i" class="align-top">
                  <td>
                    <BuscadorSelect v-model="it.product_id" :opciones="opcionesProductos" placeholder="Buscar artículo…" @elegido="o => alElegirProducto(it, o)" />
                    <input v-if="!it.product_id" v-model="it.descripcion" class="input mt-1 !py-1 text-xs" placeholder="Descripción libre" />
                    <p v-else class="text-[11px] text-marca-muted mt-1">{{ it.descripcion }} <span v-if="stockDe(it) !== null" :class="stockDe(it) < it.cantidad ? 'text-carmin font-semibold' : ''">· stock {{ cantidad(stockDe(it)) }}</span></p>
                  </td>
                  <td><input v-model.number="it.cantidad" type="number" min="0" step="any" class="input text-right" /></td>
                  <td><input v-model.number="it.precio_unit" type="number" min="0" step="any" class="input text-right" /></td>
                  <td><input v-model.number="it.descuento" type="number" min="0" max="100" step="any" class="input text-right" /></td>
                  <td><select v-model.number="it.alicuota_iva" class="input !px-1"><option v-for="a in [0,2.5,5,10.5,21,27]" :key="a" :value="a">{{ a }}%</option></select></td>
                  <td class="text-right font-semibold tabular-nums pt-3">{{ moneda(totalItem(it)) }}</td>
                  <td class="pt-2"><button type="button" @click="form.items.splice(i,1)" class="text-marca-muted hover:text-carmin"><Icono nombre="trash" clase="w-4 h-4" /></button></td>
                </tr>
                <tr v-if="!form.items.length"><td colspan="7" class="text-center text-marca-muted py-8">Agregá ítems o usá "Cargar con IA".</td></tr>
              </tbody>
            </table>
          </div>
          <p v-if="form.errors.items" class="text-carmin text-xs px-4 pb-3">{{ form.errors.items }}</p>
        </div>

        <div class="card"><label class="label">Notas (salen impresas)</label><textarea v-model="form.notas" rows="2" class="input"></textarea></div>
      </div>

      <div class="space-y-4">
        <div class="card sticky top-20">
          <h2 class="font-bold mb-3">Resumen</h2>
          <div class="space-y-1.5 text-sm">
            <div class="flex justify-between"><span class="text-marca-muted">Neto</span><span class="tabular-nums">{{ moneda(totales.neto) }}</span></div>
            <div class="flex justify-between"><span class="text-marca-muted">IVA</span><span class="tabular-nums">{{ moneda(totales.iva) }}</span></div>
            <div v-if="cliente?.percepcion_iibb && esFactura" class="flex justify-between"><span class="text-marca-muted">Percepción IIBB 3%</span><span class="tabular-nums">{{ moneda(totales.neto * 0.03) }}</span></div>
            <div class="flex justify-between text-lg font-extrabold pt-2 border-t border-marca-borde"><span>Total</span><span class="tabular-nums">{{ moneda(totales.total) }}</span></div>
          </div>
          <p v-if="form.errors.afip" class="text-carmin text-xs mt-3">{{ form.errors.afip }}</p>
          <p v-if="Object.keys(form.errors).length && !form.errors.afip" class="text-carmin text-xs mt-3">Revisá los campos marcados.</p>
          <div class="grid gap-2 mt-5">
            <button type="button" @click="guardar(true)" class="btn-primary w-full" :disabled="form.processing || !form.items.length">{{ form.processing ? 'Procesando…' : 'Emitir' }}</button>
            <button type="button" @click="guardar(false)" class="btn-secondary w-full" :disabled="form.processing">Guardar borrador</button>
            <Link :href="comprobante ? `/comprobantes/${comprobante.id}` : '/comprobantes'" class="btn-ghost w-full">Cancelar</Link>
          </div>
          <p v-if="!afipConfigurado && esFiscal" class="text-[11px] text-amber-700 mt-3">Sin certificado AFIP se emite simulado (sin CAE).</p>
        </div>
      </div>
    </div>

    <!-- Cargar con IA -->
    <Modal :abierto="abrirIA" titulo="Cargar ítems con IA" ancho="max-w-2xl" @cerrar="abrirIA = false">
      <p class="text-sm text-marca-muted mb-3">Pegá el mensaje del cliente (WhatsApp, mail) o subí una foto del pedido. La IA lo cruza con tus artículos y arma los ítems; después revisás y emitís.</p>
      <textarea v-model="ia.texto" rows="5" class="input" placeholder="Ej: Hola, necesito 20 bolsas de cemento, 6 hierros del 8 y 1 metro de arena para el lunes"></textarea>
      <div class="mt-3 flex flex-wrap items-center gap-3">
        <label class="btn-secondary cursor-pointer"><input type="file" accept="image/*" class="hidden" @change="ia.imagen = $event.target.files[0]" /> Subir foto</label>
        <span v-if="ia.imagen" class="text-xs text-marca-muted">{{ ia.imagen.name }}</span>
        <span class="text-xs text-marca-muted">Audio: próximamente (pegá la transcripción por ahora).</span>
      </div>
      <div v-if="ia.resultado" class="mt-4">
        <p class="text-xs font-bold uppercase tracking-widest text-marca-muted mb-2">Detectado ({{ ia.resultado.modo === 'ia' ? 'con IA' : 'reconocimiento básico' }})</p>
        <table class="table">
          <thead><tr><th>Pedido</th><th>Artículo</th><th class="text-right">Cant.</th><th class="text-right">Confianza</th></tr></thead>
          <tbody>
            <tr v-for="(r, i) in ia.resultado.items" :key="i">
              <td class="text-marca-muted">{{ r.pedido }}</td>
              <td><BuscadorSelect v-model="r.product_id" :opciones="opcionesProductos" placeholder="Elegir artículo…" @elegido="o => { if (o) { r.descripcion = o.label; r.precio_unit = o.precios[lista] } }" /></td>
              <td><input v-model.number="r.cantidad" type="number" step="any" class="input text-right w-20" /></td>
              <td class="text-right"><span class="badge" :class="r.confianza >= 0.7 ? 'bg-emerald-50 text-emerald-700' : r.confianza > 0 ? 'bg-amber-50 text-amber-700' : 'bg-carmin-light text-carmin'">{{ Math.round(r.confianza * 100) }}%</span></td>
            </tr>
          </tbody>
        </table>
        <p v-if="ia.resultado.observaciones" class="text-xs text-marca-muted mt-2">{{ ia.resultado.observaciones }}</p>
        <p v-if="ia.resultado.aviso" class="text-xs text-amber-700 mt-1">{{ ia.resultado.aviso }}</p>
      </div>
      <p v-if="ia.error" class="text-carmin text-sm mt-2">{{ ia.error }}</p>
      <template #pie>
        <button class="btn-secondary" @click="abrirIA = false">Cerrar</button>
        <button v-if="!ia.resultado" class="btn-violeta" @click="interpretar" :disabled="ia.cargando || (!ia.texto && !ia.imagen)">{{ ia.cargando ? 'Interpretando…' : 'Interpretar' }}</button>
        <button v-else class="btn-primary" @click="aplicarIA">Agregar {{ ia.resultado.items.filter(r => r.product_id).length }} ítems</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import { moneda, cantidad, hoyISO } from '@/util/formato'

const props = defineProps({ comprobante: Object, tipoInicial: String, origen: Object, tipos: Array, clientes: Array, productos: Array, puntosVenta: Array, puntoVentaDefault: Number, empresa: Object, afipConfigurado: Boolean, vendedores: { type: Array, default: () => [] }, vendedorDefault: Number, cbuFce: String })

const base = props.comprobante ?? (props.origen ? { contact_id: props.origen.contact_id, origen_id: props.origen.id, items: props.origen.items } : null)
const form = useForm({
  tipo: props.tipoInicial, contact_id: base?.contact_id ?? null, punto_venta_id: base?.punto_venta_id ?? props.puntoVentaDefault, vendedor_id: base?.vendedor_id ?? props.vendedorDefault ?? null, origen_id: base?.origen_id ?? null,
  fecha: base?.fecha ?? hoyISO(), condicion: base?.condicion ?? 'cta_cte', dias_vto: null, es_acopio: base?.es_acopio ?? false, entrega_pendiente: base?.entrega_pendiente ?? false, fce: base?.fce ?? false, fce_vto_pago: base?.fce_vto_pago ?? null, notas: base?.notas ?? '',
  items: (base?.items ?? []).map(i => ({ ...i })), emitir: false,
})
const esConversion = !!props.origen

const cliente = computed(() => props.clientes.find(c => c.id === form.contact_id))
const lista = computed(() => cliente.value?.lista_precios ?? 1)
const esFactura = computed(() => ['FX', 'FA', 'FB', 'FC'].includes(form.tipo))
const esFiscal = computed(() => !['PRE', 'REM'].includes(form.tipo))
const letra = computed(() => props.empresa.condicion_iva === 'Responsable Inscripto' ? (cliente.value?.condicion_iva === 'Responsable Inscripto' ? 'A' : 'B') : 'C')
const tipoResuelto = computed(() => ({ FX: `Factura ${letra.value}`, NCX: `Nota de crédito ${letra.value}`, NDX: `Nota de débito ${letra.value}` }[form.tipo] ?? null))
const titulo = computed(() => props.comprobante ? 'Editar borrador' : ({ PRE: 'Nuevo presupuesto', REM: 'Nuevo remito', NCX: 'Nueva nota de crédito', NDX: 'Nueva nota de débito' }[form.tipo] ?? 'Nueva factura'))

const opcionesClientes = computed(() => props.clientes.map(c => ({ id: c.id, label: c.name, sub: c.cuit ?? c.condicion_iva, extra: c.tipo })))
const opcionesProductos = computed(() => props.productos.map(p => ({ id: p.id, label: p.name, sub: p.sku, extra: moneda(p.precios[lista.value]), precios: p.precios, unit: p.unit, iva: p.iva, stock: p.stock })))
const stockDe = it => props.productos.find(p => p.id === it.product_id)?.stock ?? null

function alElegirCliente(o) {
  const c = props.clientes.find(x => x.id === o?.id)
  if (!c) return
  form.dias_vto = c.dias_pago
  if (c.dias_pago === 0 && !esConversion) form.condicion = 'contado'
  form.items.forEach(it => { const p = props.productos.find(x => x.id === it.product_id); if (p) { it.precio_unit = p.precios[c.lista_precios]; it.descuento = c.descuento } })
}
function alElegirProducto(it, o) {
  if (!o) return
  it.descripcion = o.label; it.unidad = o.unit; it.alicuota_iva = o.iva; it.precio_unit = o.precios[lista.value]; it.descuento = cliente.value?.descuento ?? 0
  if (!it.cantidad) it.cantidad = 1
}
function agregar(pre = {}) { form.items.push({ product_id: null, descripcion: '', cantidad: 1, unidad: null, precio_unit: 0, descuento: cliente.value?.descuento ?? 0, alicuota_iva: 21, ...pre }) }
const netoItem = it => (Number(it.cantidad) || 0) * (Number(it.precio_unit) || 0) * (1 - (Number(it.descuento) || 0) / 100)
const totalItem = it => netoItem(it) * (1 + (Number(it.alicuota_iva) || 0) / 100)
const totales = computed(() => {
  const neto = form.items.reduce((a, it) => a + netoItem(it), 0)
  const iva = form.items.reduce((a, it) => a + netoItem(it) * (Number(it.alicuota_iva) || 0) / 100, 0)
  const percep = cliente.value?.percepcion_iibb && esFactura.value ? neto * 0.03 : 0
  return { neto, iva, total: neto + iva + percep }
})

function guardar(emitir) {
  form.emitir = emitir
  form.post(props.comprobante ? `/comprobantes/${props.comprobante.id}` : '/comprobantes', { preserveScroll: true })
}

onMounted(() => {
  const q = new URLSearchParams(location.search)
  if (q.get('contact_id') && !form.contact_id) { form.contact_id = Number(q.get('contact_id')); alElegirCliente({ id: form.contact_id }) }
})

// IA
const abrirIA = ref(false)
const ia = reactive({ texto: '', imagen: null, cargando: false, resultado: null, error: null })
async function interpretar() {
  ia.cargando = true; ia.error = null
  try {
    const fd = new FormData()
    if (ia.texto) fd.append('texto', ia.texto)
    if (ia.imagen) fd.append('imagen', ia.imagen)
    fd.append('lista', lista.value)
    const { data } = await window.axios.post('/comprobantes/ia/interpretar', fd)
    ia.resultado = data
  } catch (e) { ia.error = e.response?.data?.message ?? 'No se pudo interpretar el pedido.' }
  finally { ia.cargando = false }
}
function aplicarIA() {
  ia.resultado.items.filter(r => r.product_id).forEach(r => {
    const p = props.productos.find(x => x.id === r.product_id)
    agregar({ product_id: r.product_id, descripcion: p?.name ?? r.descripcion, cantidad: r.cantidad, unidad: p?.unit, precio_unit: p ? p.precios[lista.value] : r.precio_unit, alicuota_iva: p?.iva ?? 21 })
  })
  abrirIA.value = false; ia.resultado = null; ia.texto = ''; ia.imagen = null
}
</script>
