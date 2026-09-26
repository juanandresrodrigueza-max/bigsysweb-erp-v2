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
            <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ it.descripcion }}</p><p class="text-xs text-marca-muted tabular-nums">{{ moneda(it.precio_unit) }} c/u<span v-if="it.descuento"> · −{{ it.descuento }}%</span></p>
              <input v-if="it.seriado" v-model="it.serie" class="input !py-0.5 mt-0.5 text-[11px] font-mono" :class="(it.serie || '').split(',').filter(x => x.trim()).length !== Math.round(it.cantidad) ? 'border-amber-400' : ''" placeholder="Serie(s), separadas por coma" data-e2e="pos-serie" /></div>
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
      <div v-for="(m, i) in medios" :key="i" class="mb-2">
        <div class="grid grid-cols-[1fr_130px_28px] gap-2">
          <select v-model="m.medio" class="input"><option v-for="(l, k) in mediosLabels" :key="k" :value="k">{{ l }}</option></select>
          <input v-model.number="m.monto" type="number" step="any" min="0" class="input text-right text-lg font-bold tabular-nums" @keydown.enter.prevent="cobrar" ref="montoInputs" />
          <button @click="medios.splice(i, 1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
        </div>
        <div v-if="m.medio === 'tarjeta' && planesCuotas.length" class="grid grid-cols-2 gap-2 mt-1">
          <select v-model="m.datos.tarjeta" class="input !py-1 text-xs" @change="m.datos.cuotas = 1; aplicarCuotas(m)"><option v-for="t in planesCuotas" :key="t.nombre" :value="t.nombre">{{ t.nombre }}</option></select>
          <select v-model.number="m.datos.cuotas" class="input !py-1 text-xs" @change="aplicarCuotas(m)"><option v-for="pl in planesDe(m.datos.tarjeta)" :key="pl.cuotas" :value="pl.cuotas">{{ pl.cuotas }} cuota{{ pl.cuotas > 1 ? 's' : '' }}{{ pl.recargo ? ` · ${pl.recargo > 0 ? '+' : ''}${pl.recargo}%` : ' · sin recargo' }}</option></select>
          <p v-if="recargoDe(m)" class="col-span-2 text-[11px] text-marca-muted">{{ m.datos.cuotas }} cuotas de <b class="tabular-nums">{{ moneda(m.monto / m.datos.cuotas) }}</b> · recargo {{ recargoDe(m) }}% incluido en el importe (entra como ítem de la factura).</p>
        </div>
        <div v-if="m.medio === 'mercadopago' && (mp.qr || mp.point)" class="mt-1 rounded-xl border border-marca-borde p-2 text-xs">
          <div v-if="!mpCobro" class="flex flex-wrap gap-1.5 items-center"><span class="text-marca-muted">Cobrar {{ moneda(m.monto) }} con:</span><button v-if="mp.qr" type="button" class="btn-secondary !py-1 text-xs" @click="mpIniciar('qr', m)">QR de mostrador</button><button v-if="mp.point" type="button" class="btn-secondary !py-1 text-xs" @click="mpIniciar('point', m)">Point (lector)</button></div>
          <div v-else class="flex gap-3 items-center">
            <canvas v-if="mpCobro.tipo === 'qr'" ref="mpQr" class="w-28 h-28 rounded-lg border border-marca-borde shrink-0"></canvas>
            <div class="flex-1"><p class="font-semibold">{{ mpCobro.tipo === 'qr' ? 'El cliente escanea el QR del mostrador' : 'Importe enviado al Point' }} · {{ moneda(mpCobro.monto) }}</p><p class="text-marca-muted">{{ mpCobro.estado === 'pagado' ? '¡Pago aprobado! Emitiendo…' : mpCobro.estado === 'cancelado' ? 'Cancelado o vencido.' : 'Esperando el pago…' }}<span v-if="mpCobro.error" class="text-carmin"> {{ mpCobro.error }}</span></p><button type="button" class="btn-ghost !px-2 text-xs mt-1" @click="mpCancelar">Cancelar</button></div>
          </div>
        </div>
      </div>
      <button @click="medios.push({ medio: 'tarjeta', monto: Math.max(0, aCobrar - pagado), datos: { tarjeta: planesCuotas[0]?.nombre ?? null, cuotas: 1 } })" class="btn-ghost !px-2 text-xs">+ Otro medio (pago mixto)</button>
      <div v-if="medios.some(m => m.medio === 'efectivo')" class="flex gap-1.5 mt-2 flex-wrap"><span class="text-xs text-marca-muted self-center">Paga con:</span><button v-for="b in billetes" :key="b" @click="ponerEfectivo(b)" class="px-2.5 py-1 rounded-lg bg-marca-fondo text-xs font-semibold tabular-nums">{{ moneda(b, 0) }}</button></div>
      <div class="mt-4 p-3 rounded-xl bg-marca-fondo text-sm space-y-1">
        <div class="flex justify-between"><span>Total</span><b class="tabular-nums">{{ moneda(aCobrar) }}</b></div>
        <div v-if="recargoTotal > 0.005" class="flex justify-between text-marca-muted"><span>Recargo por cuotas</span><b class="tabular-nums">{{ moneda(recargoTotal) }}</b></div>
        <div class="flex justify-between"><span>Pagado</span><b class="tabular-nums">{{ moneda(pagado) }}</b></div>
        <div class="flex justify-between text-lg" :class="pagado >= aCobrar + recargoTotal - 0.005 ? 'text-emerald-700' : 'text-carmin'"><span>{{ pagado >= aCobrar + recargoTotal - 0.005 ? 'Vuelto' : 'Falta' }}</span><b class="tabular-nums">{{ moneda(Math.abs(pagado - aCobrar - recargoTotal)) }}</b></div>
      </div>
      <label v-if="pagado < aCobrar + recargoTotal - 0.005 && contactId !== consumidorFinalId" class="flex items-center gap-2 text-sm mt-3"><input v-model="aCuenta" type="checkbox" class="accent-carmin" /> Lo que falta queda en cuenta corriente del cliente</label>
      <p v-if="error" class="text-carmin text-xs mt-2">{{ error }}</p>
      <template #pie>
        <button class="btn-secondary" @click="cobroAbierto = false">Cancelar</button>
        <button class="btn-primary !px-6" :disabled="enviando || (pagado < aCobrar + recargoTotal - 0.005 && !aCuenta)" @click="cobrar">{{ enviando ? 'Cobrando…' : 'Confirmar y emitir' }}</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'
import QRCode from 'qrcode'

const props = defineProps({ vertical: String, catalogoParcial: Boolean, productos: Array, rubros: Array, clientes: Array, consumidorFinalId: Number, cuentas: Array, caja: Object, hoy: Object, empresaLetra: String, preciosConIva: Boolean, posConfig: { type: Object, default: () => ({}) }, planesCuotas: { type: Array, default: () => [] }, mp: { type: Object, default: () => ({ qr: false, point: false }) } })
const page = usePage()
const mediosLabels = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', transferencia: 'Transferencia', mercadopago: 'MercadoPago', billetera: 'Billetera' }
const q = ref(''), rubroSel = ref(null), buscador = ref(null)
const ticket = ref([]), contactId = ref(props.consumidorFinalId)
const cliente = computed(() => props.clientes.find(c => c.id === contactId.value))
const letra = computed(() => cliente.value?.condicion_iva === 'Responsable Inscripto' && props.empresaLetra === 'B' ? 'A' : props.empresaLetra)
// Factura A (cliente RI): se muestra neto + IVA aparte; B/C: precio final.
const precioDe = p => { const f = p.prices?.[cliente.value?.lista_precios ?? 1] ?? p.price; return letra.value === 'A' && props.preciosConIva ? Math.round(f / (1 + p.iva / 100) * 100) / 100 : f }
const norm = s => (s ?? '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
// Catálogo grande: lo que no está en la página se busca en el servidor y se suma a la lista local (también sirve para el lector de barras).
const catalogo = ref([...props.productos]); const remotos = ref([]); let timerRemoto = null
function sumarRemotos(filas) { const ids = new Set(catalogo.value.map(x => x.id)); filas.forEach(f => { if (!ids.has(f.id)) catalogo.value.push(f) }); remotos.value = filas }
watch(q, t => { if (!props.catalogoParcial || t.trim().length < 2) { remotos.value = []; return } clearTimeout(timerRemoto); timerRemoto = setTimeout(async () => { try { const r = await fetch(`${location.pathname.replace(/\/$/, '')}/buscar?q=${encodeURIComponent(t.trim())}`, { headers: { Accept: 'application/json' } }); if (r.ok) sumarRemotos(await r.json()) } catch (e) {} }, 200) })
const visibles = computed(() => {
  if (q.value.trim()) { const t = norm(q.value); return catalogo.value.filter(p => norm(p.name).includes(t) || norm(p.sku).includes(t) || (p.barcode && p.barcode.includes(q.value.trim()))).slice(0, 60) }
  if (rubroSel.value) return catalogo.value.filter(p => p.rubro_id === rubroSel.value || props.rubros.find(r => r.id === p.rubro_id)?.parent_id === rubroSel.value)
  const fav = catalogo.value.filter(p => p.favorito); return fav.length ? fav : catalogo.value.slice(0, 24)
})
function agregar(p, cant = 1, serie = null) {
  const ex = ticket.value.find(i => i.product_id === p.id)
  if (ex) { ex.cantidad += cant; if (serie) ex.serie = [ex.serie, serie].filter(Boolean).join(', ') } else ticket.value.push({ product_id: p.id, descripcion: p.name, cantidad: cant, precio_unit: precioDe(p), descuento: Number(cliente.value?.descuento ?? 0), alicuota_iva: p.iva, seriado: !!p.seriado, serie: serie ?? '' })
  q.value = ''; nextTick(() => buscador.value?.focus())
}
// Balanza: EAN-13 de peso variable (prefijo + 5 dígitos de artículo + 5 de peso/importe + verificador).
function balanza(t) {
  const cfg = props.posConfig; const pre = cfg.balanza_prefijo || '2'
  if (!/^\d{13}$/.test(t) || !t.startsWith(pre)) return null
  const dig = Math.min(6, Math.max(4, Number(cfg.balanza_digitos_plu ?? 5))); const cod = t.slice(pre.length, pre.length + dig); const val = Number(t.slice(pre.length + dig, pre.length + dig + 5)); const dec = Number(cfg.balanza_decimales ?? 3)
  const p = catalogo.value.find(x => x.plu && Number(x.plu) === Number(cod)) ?? catalogo.value.find(x => x.sku === cod || x.barcode === cod || Number(x.sku) === Number(cod) || (x.barcode && x.barcode.endsWith(cod)))
  if (!p) return null
  const cant = cfg.balanza_modo === 'importe' ? Math.round(val / Math.pow(10, dec) / precioDe(p) * 1000) / 1000 : val / Math.pow(10, dec)
  return { p, cant }
}
function enterBuscar() {
  const t = q.value.trim(); if (!t) return
  const bz = balanza(t); if (bz) { agregar(bz.p, bz.cant); return }
  const porBarra = catalogo.value.find(p => p.barcode === t) ?? catalogo.value.find(p => norm(p.sku) === norm(t))
  const p = porBarra ?? visibles.value[0]
  if (p) { agregar(p); return }
  // Etiqueta de serie de una unidad: suma ese artículo con esa serie.
  fetch(`${location.pathname.replace(/\/$/, '')}/serie?codigo=${encodeURIComponent(t)}`, { headers: { Accept: 'application/json' } }).then(r => r.ok ? r.json() : null).then(d => {
    if (!d) { error.value = `Nada con el código ${t}.`; return }
    if (!catalogo.value.some(x => x.id === d.producto.id)) catalogo.value.push(d.producto)
    const ya = ticket.value.find(i => i.product_id === d.producto.id)
    if (ya && (ya.serie || '').split(',').map(x => x.trim()).includes(d.serie)) { error.value = `La serie ${d.serie} ya está en el ticket.`; return }
    agregar(d.producto, 1, d.serie)
  }).catch(() => {})
}
const total = computed(() => Math.round(ticket.value.reduce((a, i) => a + i.cantidad * i.precio_unit * (1 - i.descuento / 100), 0) * 100) / 100)
// Con factura A el total a cobrar lleva el IVA aparte.
const totalFinal = computed(() => letra.value === 'A' && props.preciosConIva ? Math.round(ticket.value.reduce((a, i) => a + i.cantidad * i.precio_unit * (1 - i.descuento / 100) * (1 + i.alicuota_iva / 100), 0) * 100) / 100 : total.value)

const cobroAbierto = ref(false), medios = ref([]), aCuenta = ref(false), enviando = ref(false), error = ref(null), montoInputs = ref([]), ultimaVenta = ref(null)
const pagado = computed(() => medios.value.reduce((a, m) => a + (Number(m.monto) || 0), 0))
const aCobrar = computed(() => totalFinal.value)
const billetes = computed(() => { const t = aCobrar.value; const base = [1000, 2000, 5000, 10000, 20000, 50000]; const r = [Math.ceil(t / 1000) * 1000, Math.ceil(t / 5000) * 5000, Math.ceil(t / 10000) * 10000, ...base.filter(b => b > t)]; return [...new Set(r.filter(b => b >= t))].sort((a, b) => a - b).slice(0, 5) })
const nuevoMedio = (medio, monto) => ({ medio, monto, datos: { tarjeta: props.planesCuotas[0]?.nombre ?? null, cuotas: 1 } })
function abrirCobro() { if (!ticket.value.length) return; medios.value = [nuevoMedio('efectivo', aCobrar.value)]; aCuenta.value = false; error.value = null; mpCobro.value = null; cobroAbierto.value = true; nextTick(() => { montoInputs.value?.[0]?.select?.() }) }
function soloMedio(m) { medios.value = [nuevoMedio(m, aCobrar.value)]; mpCobro.value = null; nextTick(() => montoInputs.value?.[0]?.select?.()) }
// --- Tarjeta en cuotas: el importe del medio pasa a incluir el recargo del plan; el servidor lo agrega como ítem ---
const planesDe = nombre => props.planesCuotas.find(t => t.nombre === nombre)?.planes ?? []
const recargoDe = m => planesDe(m.datos?.tarjeta).find(p => p.cuotas === Number(m.datos?.cuotas))?.recargo ?? 0
function aplicarCuotas(m) {
  // Base = lo que faltaba cobrar sin este medio; el importe queda con recargo.
  const otros = medios.value.filter(x => x !== m).reduce((a, x) => a + (Number(x.monto) || 0), 0)
  const base = Math.max(0, aCobrar.value - otros)
  m.monto = Math.round(base * (1 + recargoDe(m) / 100) * 100) / 100
}
const recargoTotal = computed(() => medios.value.filter(m => m.medio === 'tarjeta' && recargoDe(m)).reduce((a, m) => a + (Number(m.monto) || 0) - Math.round((Number(m.monto) || 0) / (1 + recargoDe(m) / 100) * 100) / 100, 0))
// --- Mercado Pago presencial (QR / Point) ---
const mpCobro = ref(null), mpQr = ref(null); let mpTimer = null
const xsrf = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
async function mpIniciar(tipo, m) {
  const monto = Number(m.monto) || 0; if (monto <= 0) return
  mpCobro.value = { tipo, monto, estado: 'iniciando', medio: m }
  try {
    const r = await fetch(`/${props.vertical}/mp/iniciar`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ tipo, monto }) })
    const j = await r.json(); if (!r.ok) { mpCobro.value = null; error.value = j.error ?? j.message ?? 'No se pudo iniciar el cobro.'; return }
    mpCobro.value = { ...mpCobro.value, id: j.id, qr_data: j.qr_data, estado: 'pendiente' }
    if (tipo === 'qr' && j.qr_data) nextTick(() => { const c = Array.isArray(mpQr.value) ? mpQr.value[0] : mpQr.value; if (c) QRCode.toCanvas(c, j.qr_data, { width: 112, margin: 1, color: { dark: '#4f3089' } }).catch(() => {}) })
    mpTimer = setInterval(mpConsultar, 3000)
  } catch (e) { mpCobro.value = null; error.value = 'No se pudo iniciar el cobro con Mercado Pago.' }
}
async function mpConsultar() {
  if (!mpCobro.value?.id) return
  try {
    const r = await fetch(`/${props.vertical}/mp/estado?tipo=${mpCobro.value.tipo}&id=${encodeURIComponent(mpCobro.value.id)}`, { headers: { Accept: 'application/json' } }); const j = await r.json()
    mpCobro.value.estado = j.estado; mpCobro.value.error = j.error ?? null
    if (j.estado === 'pagado') { clearInterval(mpTimer); mpCobro.value.medio.referencia = j.pago_id || mpCobro.value.id; if (j.monto) mpCobro.value.medio.monto = j.monto; cobrar() }
    if (j.estado === 'cancelado') clearInterval(mpTimer)
  } catch (e) {}
}
async function mpCancelar() {
  clearInterval(mpTimer)
  if (mpCobro.value?.id) try { await fetch(`/${props.vertical}/mp/cancelar`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ tipo: mpCobro.value.tipo, id: mpCobro.value.id }) }) } catch (e) {}
  mpCobro.value = null
}
watch(cobroAbierto, v => { if (!v) { clearInterval(mpTimer); mpCobro.value = null } })
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
  const datos = { contact_id: contactId.value, a_cuenta: aCuenta.value, precios_con_iva: props.preciosConIva && letra.value !== 'A', items: ticket.value, medios: medios.value.filter(m => m.monto > 0).map(m => ({ medio: m.medio, monto: m.monto, referencia: m.referencia ?? null, datos: m.medio === 'tarjeta' ? m.datos : null })) }
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
