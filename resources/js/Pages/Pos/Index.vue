<template>
  <AppLayout :titulo="vertical === 'minimarket' ? 'Caja' : 'Punto de venta'">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div><h1 class="page-title">{{ vertical === 'minimarket' ? 'Caja rápida' : 'Punto de venta' }}</h1><p class="page-subtitle">Escaneá o buscá, cobrá y sale el ticket. <kbd class="px-1 rounded bg-marca-fondo text-[11px]">F2</kbd> buscar · <kbd class="px-1 rounded bg-marca-fondo text-[11px]">F9</kbd> cobrar</p></div>
      <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="badge" :class="online ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-carmin'"><Icono nombre="wifi" clase="w-3.5 h-3.5 inline" /> {{ online ? 'Con conexión' : 'Sin conexión: las ventas se guardan acá' }}</span>
        <button v-if="cola.length" class="badge bg-amber-50 text-amber-700" :disabled="!online || sincronizando" @click="sincronizar">{{ sincronizando ? 'Sincronizando…' : `${cola.length} venta${cola.length > 1 ? 's' : ''} por sincronizar` }}</button>
        <button v-if="posConfig.impresora === 'serial' && tieneSerial" class="badge" :class="impresoraOk ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'" @click="conectarImpresora"><Icono nombre="printer" clase="w-3.5 h-3.5 inline" /> {{ impresoraOk ? 'Impresora conectada' : 'Conectar impresora' }}</button>
        <button v-if="ultimaVenta?.comprobante_id" class="badge bg-lavanda-light text-violeta" @click="imprimirTicket(ultimaVenta.comprobante_id)"><Icono nombre="printer" clase="w-3.5 h-3.5 inline" /> Imprimir último ticket</button>
      </div>
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <span v-if="caja" class="badge" :class="caja.turno ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ caja.nombre }} · {{ caja.turno ? `turno abierto ${caja.turno.desde}` : 'sin turno abierto' }}</span>
        <Link v-if="caja && !caja.turno" href="/fondos" class="btn-secondary !py-1 text-xs">Abrir turno</Link>
        <span class="text-marca-muted">Hoy: <b class="text-marca-texto tabular-nums">{{ moneda(hoy.ventas, 0) }}</b> · {{ hoy.tickets }} tickets</span>
      </div>
    </div>

    <div v-if="ultimaVenta" class="mb-4 px-4 py-3 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex flex-wrap items-center gap-3">
      <span>Venta <b>{{ ultimaVenta.numero }}</b> por <b class="tabular-nums">{{ moneda(ultimaVenta.total) }}</b><span v-if="ultimaVenta.vuelto > 0"> · vuelto <b class="tabular-nums text-lg">{{ moneda(ultimaVenta.vuelto) }}</b></span></span>
      <a :href="`/${vertical}/ticket/${ultimaVenta.comprobante_id}?vuelto=${ultimaVenta.vuelto}`" target="_blank" class="btn-primary !py-1 text-xs">Imprimir ticket</a>
      <Link :href="`/comprobantes/${ultimaVenta.comprobante_id}`" class="text-xs font-semibold">Ver comprobante</Link>
      <button @click="ultimaVenta = null" class="ml-auto text-xs">✕</button>
    </div>

    <div class="grid lg:grid-cols-5 gap-4">
      <!-- Buscador y grilla -->
      <div class="lg:col-span-3 space-y-3">
        <div class="relative">
          <Icono nombre="search" clase="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-marca-muted" />
          <input ref="buscador" v-model="q" @keydown.enter.prevent="enterBuscar" class="input !pl-10 !py-3 text-base" placeholder="Código de barras, nombre o SKU… (Enter agrega el primero)" autofocus />
        </div>
        <div class="flex gap-1.5 overflow-x-auto pb-1">
          <button @click="rubroSel = null" class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border" :class="rubroSel === null ? 'bg-marca-texto text-white border-marca-texto' : 'bg-white border-marca-borde text-marca-muted'">Favoritos</button>
          <button v-for="r in rubros.filter(x => !x.parent_id)" :key="r.id" @click="rubroSel = r.id" class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border" :class="rubroSel === r.id ? 'text-white border-transparent' : 'bg-white border-marca-borde text-marca-muted'" :style="rubroSel === r.id ? { background: r.color || '#4f3089' } : {}">{{ r.nombre }}</button>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2 max-h-[62vh] overflow-y-auto pr-1">
          <button v-for="p in visibles" :key="p.id" @click="agregar(p)" class="card !p-3 text-left hover:border-carmin/50 active:scale-[0.98] transition relative" :class="p.controla && p.stock <= 0 ? 'opacity-50' : ''">
            <span class="absolute top-2 right-2 w-2 h-2 rounded-full" :style="{ background: p.color || '#d6d1ca' }"></span>
            <p class="font-semibold text-sm leading-tight line-clamp-2 min-h-[2.4em]">{{ p.name }}</p>
            <p class="text-lg font-extrabold tabular-nums mt-1">{{ moneda(precioDe(p), 0) }}</p>
            <p class="text-[11px] text-marca-muted">{{ p.controla ? `${cantidad(p.stock)} ${p.unit}` : p.sku }}</p>
          </button>
          <p v-if="!visibles.length" class="col-span-full text-center text-sm text-marca-muted py-10">{{ q ? 'Nada coincide.' : 'Sin favoritos: marcá artículos como favoritos desde Stock, o elegí un rubro.' }}</p>
        </div>
      </div>

      <!-- Ticket -->
      <div class="lg:col-span-2 card p-0 flex flex-col max-h-[calc(100vh-190px)]">
        <div class="px-4 py-3 border-b border-marca-borde flex items-center gap-2">
          <select v-model="contactId" class="input !py-1.5 text-sm flex-1"><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }}{{ c.condicion_iva === 'Responsable Inscripto' ? ' (A)' : '' }}</option></select>
          <span class="badge bg-lavanda-light text-violeta">Factura {{ letra }}</span>
        </div>
        <div class="flex-1 overflow-y-auto">
          <div v-for="(it, i) in ticket" :key="i" class="flex items-center gap-2 px-4 py-2 border-b border-marca-borde/60 text-sm">
            <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ it.descripcion }}</p><p class="text-xs text-marca-muted tabular-nums">{{ moneda(it.precio_unit) }} c/u<span v-if="it.descuento"> · −{{ it.descuento }}%</span></p></div>
            <div class="flex items-center gap-1"><button @click="it.cantidad = Math.max(0, it.cantidad - 1); if (!it.cantidad) ticket.splice(i, 1)" class="w-7 h-7 rounded-lg bg-marca-fondo font-bold">−</button><input v-model.number="it.cantidad" type="number" step="any" min="0" class="input !py-1 w-16 text-center tabular-nums" /><button @click="it.cantidad++" class="w-7 h-7 rounded-lg bg-marca-fondo font-bold">+</button></div>
            <span class="w-24 text-right font-semibold tabular-nums">{{ moneda(it.cantidad * it.precio_unit * (1 - it.descuento / 100)) }}</span>
            <button @click="ticket.splice(i, 1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
          </div>
          <p v-if="!ticket.length" class="text-center text-marca-muted text-sm py-16">Ticket vacío. Escaneá o tocá un artículo.</p>
        </div>
        <div class="px-4 py-3 border-t border-marca-borde space-y-2">
          <div class="flex items-center justify-between text-sm"><span class="text-marca-muted">{{ ticket.reduce((a, i) => a + i.cantidad, 0) }} ítems</span><button v-if="ticket.length" @click="ticket = []" class="text-xs text-carmin">Vaciar</button></div>
          <div class="flex items-baseline justify-between"><span class="font-bold">TOTAL</span><span class="text-3xl font-black tabular-nums">{{ moneda(totalFinal) }}</span></div>
          <p v-if="letra === 'A'" class="text-[11px] text-marca-muted text-right">neto {{ moneda(total) }} + IVA</p>
          <button class="btn-primary w-full !py-3 text-base" :disabled="!ticket.length" @click="abrirCobro">Cobrar <span class="opacity-70 text-xs ml-1">F9</span></button>
        </div>
      </div>
    </div>

    <Modal :abierto="cobroAbierto" :titulo="`Cobrar ${moneda(aCobrar)}`" @cerrar="cobroAbierto = false">
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
        <button v-for="m in [['efectivo','Efectivo'],['tarjeta','Tarjeta'],['transferencia','Transfer.'],['mercadopago','MercadoPago']]" :key="m[0]" @click="soloMedio(m[0])" class="py-2 rounded-xl border text-sm font-semibold" :class="medios.length === 1 && medios[0].medio === m[0] ? 'bg-carmin text-white border-carmin' : 'bg-white border-marca-borde'">{{ m[1] }}</button>
      </div>
      <div v-for="(m, i) in medios" :key="i" class="grid grid-cols-[1fr_130px_28px] gap-2 mb-2">
        <select v-model="m.medio" class="input"><option v-for="(l, k) in mediosLabels" :key="k" :value="k">{{ l }}</option></select>
        <input v-model.number="m.monto" type="number" step="any" min="0" class="input text-right text-lg font-bold tabular-nums" @keydown.enter.prevent="cobrar" ref="montoInputs" />
        <button @click="medios.splice(i, 1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
      </div>
      <button @click="medios.push({ medio: 'tarjeta', monto: Math.max(0, total - pagado) })" class="btn-ghost !px-2 text-xs">+ Otro medio (pago mixto)</button>
      <div v-if="medios.some(m => m.medio === 'efectivo')" class="flex gap-1.5 mt-2 flex-wrap"><span class="text-xs text-marca-muted self-center">Paga con:</span><button v-for="b in billetes" :key="b" @click="ponerEfectivo(b)" class="px-2.5 py-1 rounded-lg bg-marca-fondo text-xs font-semibold tabular-nums">{{ moneda(b, 0) }}</button></div>
      <div class="mt-4 p-3 rounded-xl bg-marca-fondo text-sm space-y-1">
        <div class="flex justify-between"><span>Total</span><b class="tabular-nums">{{ moneda(aCobrar) }}</b></div>
        <div class="flex justify-between"><span>Pagado</span><b class="tabular-nums">{{ moneda(pagado) }}</b></div>
        <div class="flex justify-between text-lg" :class="pagado >= aCobrar ? 'text-emerald-700' : 'text-carmin'"><span>{{ pagado >= aCobrar ? 'Vuelto' : 'Falta' }}</span><b class="tabular-nums">{{ moneda(Math.abs(pagado - aCobrar)) }}</b></div>
      </div>
      <label v-if="pagado < aCobrar - 0.005 && contactId !== consumidorFinalId" class="flex items-center gap-2 text-sm mt-3"><input v-model="aCuenta" type="checkbox" class="accent-carmin" /> Lo que falta queda en cuenta corriente del cliente</label>
      <p v-if="error" class="text-carmin text-xs mt-2">{{ error }}</p>
      <template #pie>
        <button class="btn-secondary" @click="cobroAbierto = false">Cancelar</button>
        <button class="btn-primary !px-6" :disabled="enviando || (pagado < aCobrar - 0.005 && !aCuenta)" @click="cobrar">{{ enviando ? 'Cobrando…' : 'Confirmar y emitir' }}</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'

const props = defineProps({ vertical: String, productos: Array, rubros: Array, clientes: Array, consumidorFinalId: Number, cuentas: Array, caja: Object, hoy: Object, empresaLetra: String, preciosConIva: Boolean, posConfig: { type: Object, default: () => ({}) } })
const page = usePage()
const mediosLabels = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', transferencia: 'Transferencia', mercadopago: 'MercadoPago', billetera: 'Billetera' }
const q = ref(''), rubroSel = ref(null), buscador = ref(null)
const ticket = ref([]), contactId = ref(props.consumidorFinalId)
const cliente = computed(() => props.clientes.find(c => c.id === contactId.value))
const letra = computed(() => cliente.value?.condicion_iva === 'Responsable Inscripto' && props.empresaLetra === 'B' ? 'A' : props.empresaLetra)
// Factura A (cliente RI): se muestra neto + IVA aparte; B/C: precio final.
const precioDe = p => { const f = p.prices?.[cliente.value?.lista_precios ?? 1] ?? p.price; return letra.value === 'A' && props.preciosConIva ? Math.round(f / (1 + p.iva / 100) * 100) / 100 : f }
const norm = s => (s ?? '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
const visibles = computed(() => {
  if (q.value.trim()) { const t = norm(q.value); return props.productos.filter(p => norm(p.name).includes(t) || norm(p.sku).includes(t) || (p.barcode && p.barcode.includes(q.value.trim()))).slice(0, 60) }
  if (rubroSel.value) return props.productos.filter(p => p.rubro_id === rubroSel.value || props.rubros.find(r => r.id === p.rubro_id)?.parent_id === rubroSel.value)
  const fav = props.productos.filter(p => p.favorito); return fav.length ? fav : props.productos.slice(0, 24)
})
function agregar(p, cant = 1) {
  const ex = ticket.value.find(i => i.product_id === p.id)
  if (ex) ex.cantidad += cant; else ticket.value.push({ product_id: p.id, descripcion: p.name, cantidad: cant, precio_unit: precioDe(p), descuento: Number(cliente.value?.descuento ?? 0), alicuota_iva: p.iva })
  q.value = ''; nextTick(() => buscador.value?.focus())
}
// Balanza: EAN-13 de peso variable (prefijo + 5 dígitos de artículo + 5 de peso/importe + verificador).
function balanza(t) {
  const cfg = props.posConfig; const pre = cfg.balanza_prefijo || '2'
  if (!/^\d{13}$/.test(t) || !t.startsWith(pre)) return null
  const cod = t.slice(pre.length, pre.length + 5); const val = Number(t.slice(pre.length + 5, pre.length + 10)); const dec = Number(cfg.balanza_decimales ?? 3)
  const p = props.productos.find(x => x.sku === cod || x.barcode === cod || Number(x.sku) === Number(cod) || (x.barcode && x.barcode.endsWith(cod)))
  if (!p) return null
  const cant = cfg.balanza_modo === 'importe' ? Math.round(val / Math.pow(10, 2) / precioDe(p) * 1000) / 1000 : val / Math.pow(10, dec)
  return { p, cant }
}
function enterBuscar() {
  const t = q.value.trim(); if (!t) return
  const bz = balanza(t); if (bz) { agregar(bz.p, bz.cant); return }
  const porBarra = props.productos.find(p => p.barcode === t) ?? props.productos.find(p => norm(p.sku) === norm(t))
  const p = porBarra ?? visibles.value[0]
  if (p) agregar(p); else { error.value = null }
}
const total = computed(() => Math.round(ticket.value.reduce((a, i) => a + i.cantidad * i.precio_unit * (1 - i.descuento / 100), 0) * 100) / 100)
// Con factura A el total a cobrar lleva el IVA aparte.
const totalFinal = computed(() => letra.value === 'A' && props.preciosConIva ? Math.round(ticket.value.reduce((a, i) => a + i.cantidad * i.precio_unit * (1 - i.descuento / 100) * (1 + i.alicuota_iva / 100), 0) * 100) / 100 : total.value)

const cobroAbierto = ref(false), medios = ref([]), aCuenta = ref(false), enviando = ref(false), error = ref(null), montoInputs = ref([]), ultimaVenta = ref(null)
const pagado = computed(() => medios.value.reduce((a, m) => a + (Number(m.monto) || 0), 0))
const aCobrar = computed(() => totalFinal.value)
const billetes = computed(() => { const t = aCobrar.value; const base = [1000, 2000, 5000, 10000, 20000, 50000]; const r = [Math.ceil(t / 1000) * 1000, Math.ceil(t / 5000) * 5000, Math.ceil(t / 10000) * 10000, ...base.filter(b => b > t)]; return [...new Set(r.filter(b => b >= t))].sort((a, b) => a - b).slice(0, 5) })
function abrirCobro() { if (!ticket.value.length) return; medios.value = [{ medio: 'efectivo', monto: aCobrar.value }]; aCuenta.value = false; error.value = null; cobroAbierto.value = true; nextTick(() => { montoInputs.value?.[0]?.select?.() }) }
function soloMedio(m) { medios.value = [{ medio: m, monto: aCobrar.value }]; nextTick(() => montoInputs.value?.[0]?.select?.()) }
function ponerEfectivo(b) { const ef = medios.value.find(m => m.medio === 'efectivo'); if (ef) ef.monto = b }
// --- Sin conexión: la venta se guarda en el navegador y se sincroniza cuando vuelve internet ---
const online = ref(navigator.onLine)
const cola = ref([]); try { cola.value = JSON.parse(localStorage.getItem('pos_cola') || '[]') } catch (e) {}
const guardarCola = () => { try { localStorage.setItem('pos_cola', JSON.stringify(cola.value)) } catch (e) {} }
const sincronizando = ref(false)
async function sincronizar() {
  if (sincronizando.value || !cola.value.length || !navigator.onLine) return
  sincronizando.value = true
  const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
  for (const v of [...cola.value]) {
    try {
      const r = await fetch(`/${props.vertical}/vender`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token }, body: JSON.stringify(v) })
      if (r.ok || r.status === 422) { cola.value = cola.value.filter(x => x.offline_id !== v.offline_id); guardarCola(); if (r.ok) { const j = await r.json(); ultimaVenta.value = { comprobante_id: j.comprobante_id, numero: j.numero, total: j.total, vuelto: j.vuelto, sync: true } } }
      else break
    } catch (e) { break }
  }
  sincronizando.value = false
}
function cobrar() {
  if (enviando.value) return
  enviando.value = true; error.value = null
  const datos = { contact_id: contactId.value, a_cuenta: aCuenta.value, precios_con_iva: props.preciosConIva && letra.value !== 'A', items: ticket.value, medios: medios.value.filter(m => m.monto > 0) }
  if (!navigator.onLine) {
    cola.value.push({ ...datos, offline_id: 'off-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8), fecha_offline: new Date().toISOString() }); guardarCola()
    ultimaVenta.value = { numero: 'SIN CONEXIÓN', total: totalFinal.value, vuelto: Math.max(0, pagado.value - aCobrar.value), offline: true }
    ticket.value = []; cobroAbierto.value = false; contactId.value = props.consumidorFinalId; enviando.value = false; nextTick(() => buscador.value?.focus()); return
  }
  router.post(`/${props.vertical}/vender`, datos, {
    preserveScroll: true,
    onSuccess: () => { ultimaVenta.value = page.props.flash?.pos ?? null; ticket.value = []; cobroAbierto.value = false; contactId.value = props.consumidorFinalId; nextTick(() => buscador.value?.focus()); if (ultimaVenta.value?.comprobante_id) imprimirAuto(ultimaVenta.value.comprobante_id) },
    onError: e => { error.value = Object.values(e)[0] },
    onFinish: () => (enviando.value = false),
  })
}
// --- Impresora térmica directa (WebSerial) ---
let puerto = null
const impresoraOk = ref(false)
const tieneSerial = 'serial' in navigator
async function conectarImpresora() { try { puerto = await navigator.serial.requestPort(); await puerto.open({ baudRate: 9600 }); impresoraOk.value = true } catch (e) { impresoraOk.value = false } }
async function imprimirSerial(id) {
  if (!puerto) return false
  try { const bytes = new Uint8Array(await (await fetch(`/${props.vertical}/ticket/${id}/escpos`)).arrayBuffer()); const w = puerto.writable.getWriter(); await w.write(bytes); w.releaseLock(); return true } catch (e) { impresoraOk.value = false; return false }
}
async function imprimirTicket(id) {
  if (props.posConfig.impresora === 'ninguna') return
  if (props.posConfig.impresora === 'serial' && await imprimirSerial(id)) return
  window.open(`/${props.vertical}/ticket/${id}`, '_blank')
}
function imprimirAuto(id) { if (props.posConfig.imprimir_auto) imprimirTicket(id) }
function teclas(e) { if (e.key === 'F2') { e.preventDefault(); buscador.value?.focus() } if (e.key === 'F9') { e.preventDefault(); cobroAbierto.value ? cobrar() : abrirCobro() } if (e.key === 'Escape' && cobroAbierto.value) cobroAbierto.value = false }
const onOnline = () => { online.value = true; sincronizar() }; const onOffline = () => (online.value = false)
onMounted(() => { window.addEventListener('keydown', teclas); window.addEventListener('online', onOnline); window.addEventListener('offline', onOffline); buscador.value?.focus(); sincronizar() })
onBeforeUnmount(() => { window.removeEventListener('keydown', teclas); window.removeEventListener('online', onOnline); window.removeEventListener('offline', onOffline) })
</script>
