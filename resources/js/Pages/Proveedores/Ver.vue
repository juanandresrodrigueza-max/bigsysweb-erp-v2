<template>
  <AppLayout :titulo="proveedor.name">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div>
        <Link href="/proveedores" class="text-xs text-marca-muted hover:text-carmin">← Proveedores</Link>
        <h1 class="page-title">{{ proveedor.name }}</h1>
        <p class="page-subtitle">{{ proveedor.condicion_iva }} · {{ proveedor.cuit ?? 'sin CUIT' }} · {{ proveedor.dias_pago ? proveedor.dias_pago + ' días' : 'contado' }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button @click="editarAbierto = true" class="btn-secondary"><Icono nombre="edit" clase="w-4 h-4" /> Editar</button>
        <Link :href="`/proveedores/compras/nueva?contact_id=${proveedor.id}`" class="btn-secondary">Cargar factura</Link>
        <button @click="pagoAbierto = true" class="btn-primary">Registrar pago</button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Le debemos</p><p class="text-xl font-extrabold tabular-nums" :class="proveedor.balance > 0 ? 'text-carmin' : ''">{{ moneda(proveedor.balance, 0) }}</p><p v-if="proveedor.balance < 0" class="text-[11px] text-marca-muted">saldo a nuestro favor</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vencido</p><p class="text-xl font-extrabold tabular-nums" :class="proveedor.deuda_vencida > 0 ? 'text-carmin' : ''">{{ moneda(proveedor.deuda_vencida, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Facturas pendientes</p><p class="text-xl font-extrabold tabular-nums">{{ pendientes.length }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Próximo vencimiento</p><p class="text-xl font-extrabold tabular-nums">{{ pendientes[0]?.fecha_vto ?? '—' }}</p></div>
    </div>

    <div class="flex gap-1 overflow-x-auto mb-4 bg-white border border-marca-borde rounded-full p-1 w-fit max-w-full">
      <button v-for="t in tabs" :key="t.key" @click="tab = t.key" class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap" :class="tab === t.key ? 'bg-carmin text-white' : 'text-marca-muted hover:text-marca-texto'">{{ t.label }}<span v-if="t.n" class="ml-1 opacity-70">({{ t.n }})</span></button>
    </div>

    <div v-if="tab === 'cc'" class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Fecha</th><th>Concepto</th><th>Vence</th><th class="text-right">Debe</th><th class="text-right">Haber</th><th class="text-right">Saldo</th></tr></thead>
        <tbody>
          <tr v-for="m in cc" :key="m.id" class="cursor-pointer" @click="abrirMov(m)">
            <td class="tabular-nums text-marca-muted">{{ m.fecha }}</td><td class="font-medium">{{ m.concepto }}</td><td class="tabular-nums text-marca-muted">{{ m.fecha_vto }}</td>
            <td class="text-right tabular-nums">{{ m.debe ? moneda(m.debe) : '' }}</td><td class="text-right tabular-nums text-emerald-700">{{ m.haber ? moneda(m.haber) : '' }}</td>
            <td class="text-right tabular-nums font-semibold" :class="m.saldo > 0 ? 'text-carmin' : ''">{{ moneda(m.saldo) }}</td>
          </tr>
          <tr v-if="!cc.length"><td colspan="6" class="text-center text-marca-muted py-10">Sin movimientos.</td></tr>
        </tbody>
      </table>
    </div>

    <div v-if="tab === 'pendientes'" class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Factura</th><th>Fecha</th><th>Vence</th><th class="text-right">Total</th><th class="text-right">Saldo</th></tr></thead>
        <tbody>
          <tr v-for="p in pendientes" :key="p.id" class="cursor-pointer" @click="$inertia.visit(`/proveedores/compras/${p.id}`)">
            <td class="font-semibold">{{ p.nombre }} <span class="tabular-nums">{{ p.numero }}</span></td><td class="text-marca-muted">{{ p.fecha }}</td>
            <td class="tabular-nums" :class="p.vencido ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ p.fecha_vto }} <span v-if="p.vencido" class="badge bg-carmin-light text-carmin ml-1">Vencida</span></td>
            <td class="text-right tabular-nums">{{ moneda(p.total) }}</td><td class="text-right tabular-nums font-semibold text-carmin">{{ moneda(p.saldo) }}</td>
          </tr>
          <tr v-if="!pendientes.length"><td colspan="5" class="text-center text-marca-muted py-10">Nada pendiente de pago.</td></tr>
        </tbody>
      </table>
    </div>

    <div v-if="tab === 'compras'" class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Comprobante</th><th>Fecha</th><th>Carga</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="x in compras" :key="x.id" class="cursor-pointer" @click="$inertia.visit(`/proveedores/compras/${x.id}`)">
            <td class="font-semibold">{{ x.nombre }} <span class="tabular-nums">{{ x.numero ?? '(borrador)' }}</span></td><td class="text-marca-muted">{{ x.fecha }}</td>
            <td><span class="badge bg-gris-light text-marca-muted">{{ { manual: 'Manual', ocr: 'IA', afip_csv: 'AFIP' }[x.origen_carga] ?? '' }}</span></td>
            <td class="text-right tabular-nums">{{ moneda(x.total) }}</td><td class="text-right tabular-nums" :class="x.saldo > 0 ? 'text-carmin' : 'text-marca-muted'">{{ x.estado_cobro !== 'na' ? moneda(x.saldo) : '' }}</td>
            <td><span class="badge" :class="estadoComprobante[x.estado].clase">{{ x.estado === 'emitido' ? 'Registrada' : estadoComprobante[x.estado].label }}</span></td>
          </tr>
          <tr v-if="!compras.length"><td colspan="6" class="text-center text-marca-muted py-10">Sin compras cargadas.</td></tr>
        </tbody>
      </table>
    </div>

    <ContactosPersonas v-if="tab === 'contactos'" :contacto-id="proveedor.id" />
    <div v-if="tab === 'pagos'" class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Orden de pago</th><th>Fecha</th><th>Medios</th><th class="text-right">Total</th><th class="text-right">A cuenta</th><th></th></tr></thead>
        <tbody>
          <tr v-for="k in pagos" :key="k.id" :class="{ 'opacity-50 line-through': k.estado === 'anulado' }">
            <td class="font-semibold tabular-nums">{{ k.numero }}</td><td class="text-marca-muted">{{ k.fecha }}</td><td class="text-xs text-marca-muted">{{ k.medios }}</td>
            <td class="text-right tabular-nums font-semibold">{{ moneda(k.total) }}</td><td class="text-right tabular-nums text-marca-muted">{{ k.a_cuenta ? moneda(k.a_cuenta) : '' }}</td>
            <td class="text-right whitespace-nowrap"><a :href="`/proveedores/pagos/${k.id}/imprimir`" target="_blank" class="btn-ghost !px-2 text-xs">Imprimir</a><button v-if="k.estado !== 'anulado' && k.a_cuenta > 0 && pendientes.length" @click="abrirAplicar(k)" class="btn-ghost !px-2 text-xs text-violeta font-semibold">Aplicar a facturas</button><button v-if="k.estado !== 'anulado'" @click="anularPago(k)" class="btn-ghost !px-2 text-xs text-carmin">Anular</button></td>
          </tr>
          <tr v-if="!pagos.length"><td colspan="6" class="text-center text-marca-muted py-10">Sin pagos registrados.</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="!!aplicarDe" :titulo="`Aplicar ${aplicarDe?.numero ?? ''} a facturas`" @cerrar="aplicarDe = null">
      <p class="text-sm text-marca-muted mb-3">La orden de pago tiene <b class="tabular-nums">{{ moneda(aplicarDe?.a_cuenta ?? 0) }}</b> a cuenta. Repartilo entre las facturas pendientes.</p>
      <div class="max-h-64 overflow-y-auto border border-marca-borde rounded-xl divide-y divide-marca-borde/60">
        <div v-for="p in pendientes" :key="p.id" class="flex items-center gap-2 px-3 py-2 text-sm">
          <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ p.nombre }} {{ p.numero }} <span v-if="p.saldo_usd" class="badge bg-violeta-light text-violeta !py-0">USD</span></p><p class="text-xs text-marca-muted">vence {{ p.fecha_vto }} · <template v-if="p.saldo_usd">saldo USD {{ p.saldo_usd }} · hoy {{ moneda(p.saldo_usd * (Number(aplicar.cotizacion) || cotizacionUsd || 0)) }}</template><template v-else>saldo {{ moneda(p.saldo) }}</template></p></div>
          <input v-model.number="imputAplicar[p.id]" type="number" step="any" min="0" class="input w-28 text-right !py-1" placeholder="0" />
        </div>
      </div>
      <div v-if="pendientes.some(p => p.saldo_usd)" class="mt-2 flex items-center gap-2 text-xs"><span class="text-marca-muted">Cotización (USD)</span><input v-model.number="aplicar.cotizacion" type="number" step="any" min="0" class="input !py-1 !w-28 text-right tabular-nums" /></div>
      <div class="mt-3 text-sm flex justify-between bg-marca-fondo rounded-xl p-3"><span>Imputado</span><b class="tabular-nums" :class="totalAplicar > (aplicarDe?.a_cuenta ?? 0) + 0.005 ? 'text-carmin' : ''">{{ moneda(totalAplicar) }}</b></div>
      <p v-if="aplicar.errors.imputaciones" class="text-carmin text-xs mt-2">{{ aplicar.errors.imputaciones }}</p>
      <template #pie><button class="btn-secondary" @click="aplicarDe = null">Cancelar</button><button class="btn-primary" :disabled="aplicar.processing || totalAplicar <= 0 || totalAplicar > (aplicarDe?.a_cuenta ?? 0) + 0.005" @click="enviarAplicar">Aplicar</button></template>
    </Modal>

    <div v-if="tab === 'datos'" class="card grid sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
      <div><p class="label">Email</p>{{ proveedor.email ?? '—' }}</div><div><p class="label">Teléfono</p>{{ proveedor.phone ?? proveedor.mobile ?? '—' }}</div>
      <div class="sm:col-span-2"><p class="label">Dirección</p>{{ [proveedor.address, proveedor.city, proveedor.province].filter(Boolean).join(', ') || '—' }}</div>
      <div class="sm:col-span-2"><p class="label">Notas</p><span class="whitespace-pre-line">{{ proveedor.notes ?? '—' }}</span></div>
    </div>

    <ProveedorModal :abierto="editarAbierto" :proveedor="proveedor" :condicionesIva="condicionesIva" @cerrar="editarAbierto = false" />

    <!-- Orden de pago -->
    <Modal :abierto="pagoAbierto" titulo="Registrar orden de pago" ancho="max-w-4xl" @cerrar="pagoAbierto = false">
      <div class="grid md:grid-cols-2 gap-5">
        <div>
          <p class="label">Medios de pago</p>
          <div v-for="(m, i) in pago.medios" :key="i" class="rounded-xl border border-marca-borde p-2 mb-2 space-y-1.5">
            <div class="grid grid-cols-[1fr_110px_28px] gap-2">
              <select v-model="m.medio" class="input" @change="cambiarMedio(m)"><option v-for="(lbl, k) in medios" :key="k" :value="k">{{ lbl }}</option></select>
              <input v-model.number="m.monto" type="number" step="any" min="0" class="input text-right" placeholder="0" :disabled="m.medio === 'cheque_tercero'" />
              <button @click="pago.medios.splice(i,1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
            </div>
            <select v-if="['efectivo','transferencia','billetera','tarjeta','cheque_propio'].includes(m.medio)" v-model="m.cuenta_fondos_id" class="input !py-1 text-xs">
              <option :value="null">Cuenta: la predeterminada</option><option v-for="c in cuentasPara(m.medio)" :key="c.id" :value="c.id">{{ c.nombre }} · {{ c.moneda === 'USD' ? 'USD ' + Number(c.saldo).toLocaleString('es-AR') : moneda(c.saldo, 0) }}</option>
            </select>
            <div v-if="['efectivo','transferencia'].includes(m.medio) && cuentas.some(c => c.moneda === 'USD')" class="flex items-center gap-2 text-xs">
              <label class="flex items-center gap-1"><input type="checkbox" class="accent-carmin" :checked="m.moneda === 'USD'" @change="m.moneda = $event.target.checked ? 'USD' : 'ARS'; if (m.moneda === 'USD') { m.cotizacion = m.cotizacion || cotizacionUsd; m.cuenta_fondos_id = cuentas.find(c => c.moneda === 'USD')?.id ?? m.cuenta_fondos_id }" /> En dólares</label>
              <template v-if="m.moneda === 'USD'"><span class="text-marca-muted">cotización</span><input v-model.number="m.cotizacion" type="number" step="any" class="input !py-0.5 !w-24 text-xs" /><span class="text-marca-muted">= {{ moneda((m.monto || 0) * (m.cotizacion || 0), 0) }}</span></template>
            </div>
            <select v-if="m.medio === 'cheque_tercero'" v-model="m.cheque_id" class="input !py-1 text-xs" @change="m.monto = chequesCartera.find(c => c.id === m.cheque_id)?.monto ?? 0">
              <option :value="null">Elegir cheque en cartera…</option><option v-for="c in chequesCartera" :key="c.id" :value="c.id">{{ c.numero }} · {{ c.banco }} · {{ c.emisor }} · vto {{ c.fecha_pago }} · {{ moneda(c.monto, 0) }}</option>
            </select>
            <div v-if="m.medio === 'cheque_propio'" class="grid grid-cols-2 gap-2"><input v-model="m.datos.numero" class="input !py-1 text-xs" placeholder="N° cheque" /><input v-model="m.datos.fecha_pago" type="date" class="input !py-1 text-xs" /></div>
            <div v-if="m.medio === 'retencion'" class="grid grid-cols-3 gap-2">
              <select v-model="m.datos.tipo" class="input !py-1 text-xs"><option v-for="(lbl, k) in retencionTipos" :key="k" :value="k">{{ lbl }}</option></select>
              <input v-model.number="m.datos.alicuota" type="number" step="any" class="input !py-1 text-xs" placeholder="Alícuota %" @input="m.monto = Math.round((m.datos.base || 0) * (m.datos.alicuota || 0)) / 100" />
              <input v-model="m.datos.certificado" class="input !py-1 text-xs" placeholder="N° certificado" />
              <input v-model.number="m.datos.base" type="number" step="any" class="input !py-1 text-xs col-span-2" placeholder="Base imponible" @input="m.monto = Math.round((m.datos.base || 0) * (m.datos.alicuota || 0)) / 100" />
              <button type="button" class="btn-secondary !py-1 text-xs" :disabled="!['iibb','ganancias','iva'].includes(m.datos.tipo)" @click="sugerirRetencion(m)">Sugerir</button>
              <p v-if="m.datos.motivo" class="col-span-3 text-[11px] text-marca-muted">{{ m.datos.motivo }}</p>
            </div>
            <input v-if="['transferencia','billetera','tarjeta'].includes(m.medio)" v-model="m.referencia" class="input !py-1 text-xs" placeholder="Referencia / N° operación" />
          </div>
          <button @click="pago.medios.push({ medio: 'transferencia', monto: 0, cuenta_fondos_id: null, cheque_id: null, referencia: '', datos: {}, moneda: 'ARS', cotizacion: null })" class="btn-ghost !px-2 text-xs">+ Otro medio</button>
          <div class="mt-4 grid grid-cols-2 gap-2"><div><label class="label">Fecha</label><input v-model="pago.fecha" type="date" class="input" /></div><div><label class="label">Notas</label><input v-model="pago.notas" class="input" /></div></div>
          <p v-if="pago.errors.medios" class="text-carmin text-xs mt-2">{{ pago.errors.medios }}</p>
        </div>
        <div>
          <div class="flex items-center justify-between"><p class="label">Aplicar a</p><button @click="autoImputar" class="text-xs text-violeta font-semibold">Aplicar automáticamente</button></div>
          <div class="max-h-64 overflow-y-auto border border-marca-borde rounded-xl divide-y divide-marca-borde/60">
            <div v-for="p in pendientes" :key="p.id" class="flex items-center gap-2 px-3 py-2 text-sm">
              <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ p.nombre }} {{ p.numero }} <span v-if="p.saldo_usd" class="badge bg-violeta-light text-violeta !py-0">USD</span></p><p class="text-xs text-marca-muted">vence {{ p.fecha_vto }} · <template v-if="p.saldo_usd">saldo USD {{ p.saldo_usd }} · hoy {{ moneda(p.saldo_usd * (Number(pago.cotizacion) || cotizacionUsd || 0)) }}</template><template v-else>saldo {{ moneda(p.saldo) }}</template></p></div>
              <input v-model.number="imput[p.id]" type="number" step="any" min="0" :max="p.saldo_usd ? Math.round(p.saldo_usd * (Number(pago.cotizacion) || cotizacionUsd || 0) * 100) / 100 : p.saldo" class="input w-28 text-right !py-1" placeholder="0" />
            </div>
            <p v-if="!pendientes.length" class="px-3 py-4 text-sm text-marca-muted">Sin facturas pendientes: el pago queda a cuenta.</p>
          </div>
          <p class="text-[10px] text-marca-muted mt-1">Podés dejar todo sin aplicar: la orden de pago queda a cuenta y se imputa a las facturas después.</p>
          <div v-if="pendientes.some(p => p.saldo_usd)" class="mt-2 flex items-center gap-2 text-xs"><span class="text-marca-muted">Cotización del pago (USD)</span><input v-model.number="pago.cotizacion" type="number" step="any" min="0" class="input !py-1 !w-28 text-right tabular-nums" /><span class="text-marca-muted">la diferencia con la de la factura es diferencia de cambio</span></div>
          <div class="mt-3 text-sm space-y-1 bg-marca-fondo rounded-xl p-3">
            <div class="grid grid-cols-2 gap-2 mb-2"><div><label class="label">Descuento obtenido</label><input v-model.number="pago.descuento" type="number" step="any" min="0" class="input !py-1" placeholder="0" /></div><div><label class="label">Interés pagado</label><input v-model.number="pago.interes" type="number" step="any" min="0" class="input !py-1" placeholder="0" /></div></div>
            <div class="flex justify-between"><span>Total pagado</span><b class="tabular-nums">{{ moneda(totalPago) }}</b></div>
            <div v-if="pago.descuento > 0 || pago.interes > 0" class="flex justify-between font-semibold"><span>Cancela deuda</span><span class="tabular-nums">{{ moneda(cancelaPago) }}</span></div>
            <div class="flex justify-between"><span>Imputado</span><span class="tabular-nums">{{ moneda(totalImputado) }}</span></div>
            <div class="flex justify-between"><span>A cuenta</span><span class="tabular-nums">{{ moneda(cancelaPago - totalImputado) }}</span></div>
          </div>
          <p v-if="pago.errors.imputaciones" class="text-carmin text-xs mt-2">{{ pago.errors.imputaciones }}</p>
        </div>
      </div>
      <template #pie>
        <button class="btn-secondary" @click="pagoAbierto = false">Cancelar</button>
        <button class="btn-primary" :disabled="pago.processing || totalPago <= 0 || totalImputado > cancelaPago + 0.005" @click="registrarPago">Registrar {{ moneda(totalPago) }}</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import ProveedorModal from '@/Components/ProveedorModal.vue'
import ContactosPersonas from '@/Components/ContactosPersonas.vue'
import { moneda, hoyISO, estadoComprobante } from '@/util/formato'

const props = defineProps({ proveedor: Object, cc: Array, pendientes: Array, compras: Array, pagos: Array, medios: Object, condicionesIva: Array, cuentas: Array, chequesCartera: Array, retencionTipos: Object, cotizacionUsd: { type: Number, default: 0 } })
const tab = ref('cc')
const tabs = computed(() => [{ key: 'cc', label: 'Cuenta corriente' }, { key: 'pendientes', label: 'Pendientes', n: props.pendientes.length }, { key: 'compras', label: 'Compras' }, { key: 'pagos', label: 'Pagos' }, { key: 'contactos', label: 'Contactos' }, { key: 'datos', label: 'Datos' }])
const editarAbierto = ref(false)
function abrirMov(m) { if (m.comprobante_id) router.visit(`/proveedores/compras/${m.comprobante_id}`); else if (m.pago_id) window.open(`/proveedores/pagos/${m.pago_id}/imprimir`, '_blank') }
const cuentasPara = medio => props.cuentas.filter(c => ({ efectivo: ['caja'], transferencia: ['banco'], cheque_propio: ['banco'], billetera: ['billetera', 'banco'], tarjeta: ['tarjeta', 'banco'] }[medio] ?? ['banco']).includes(c.tipo))

const pagoAbierto = ref(false)
const pago = useForm({ fecha: hoyISO(), notas: '', descuento: 0, interes: 0, cotizacion: props.cotizacionUsd || null, medios: [{ medio: 'transferencia', monto: 0, cuenta_fondos_id: null, cheque_id: null, referencia: '', datos: {}, moneda: 'ARS', cotizacion: null }], imputaciones: [] })
const imput = reactive({})
function cambiarMedio(m) { m.cheque_id = null; m.cuenta_fondos_id = null; m.datos = m.medio === 'retencion' ? { tipo: Object.keys(props.retencionTipos)[0], alicuota: null, base: null, certificado: '' } : m.medio === 'cheque_propio' ? { numero: '', fecha_pago: hoyISO() } : {}; if (m.medio === 'cheque_tercero') m.monto = 0 }
async function sugerirRetencion(m) {
  const base = Number(m.datos.base) || props.pendientes.reduce((a, p) => a + Number(p.saldo || 0), 0)
  const r = await fetch(`/proveedores/${props.proveedor.id}/retencion-sugerida`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '') }, body: JSON.stringify({ tipo: m.datos.tipo, base }) })
  if (!r.ok) return
  const d = await r.json()
  m.datos.base = base; m.datos.alicuota = d.alicuota; m.datos.motivo = d.motivo; m.datos.jurisdiccion = d.jurisdiccion ?? null; m.monto = d.monto
}
const totalPago = computed(() => pago.medios.reduce((a, m) => a + (Number(m.monto) || 0) * (m.moneda === 'USD' ? (Number(m.cotizacion) || 0) : 1), 0))
const cancelaPago = computed(() => totalPago.value + (Number(pago.descuento) || 0) - (Number(pago.interes) || 0))
const totalImputado = computed(() => Object.values(imput).reduce((a, v) => a + (Number(v) || 0), 0))
function autoImputar() { let resto = totalPago.value; const cot = Number(pago.cotizacion) || props.cotizacionUsd || 0; Object.keys(imput).forEach(k => delete imput[k]); for (const p of props.pendientes) { if (resto <= 0) break; const saldoHoy = p.saldo_usd ? p.saldo_usd * cot : p.saldo; const m = Math.min(resto, saldoHoy); imput[p.id] = Math.round(m * 100) / 100; resto -= m } }
function registrarPago() {
  pago.imputaciones = Object.entries(imput).filter(([, v]) => Number(v) > 0).map(([id, v]) => ({ comprobante_id: Number(id), monto: Number(v) }))
  pago.post(`/proveedores/${props.proveedor.id}/pagos`, { preserveScroll: true, onSuccess: () => { pagoAbierto.value = false; pago.reset(); Object.keys(imput).forEach(k => delete imput[k]) } })
}
const aplicarDe = ref(null)
const imputAplicar = reactive({})
const aplicar = useForm({ cotizacion: props.cotizacionUsd || null, imputaciones: [] })
const totalAplicar = computed(() => Object.values(imputAplicar).reduce((a, v) => a + (Number(v) || 0), 0))
function abrirAplicar(k) {
  Object.keys(imputAplicar).forEach(x => delete imputAplicar[x]); aplicar.clearErrors()
  let resto = k.a_cuenta; const cot = Number(aplicar.cotizacion) || props.cotizacionUsd || 0
  for (const p of props.pendientes) { if (resto <= 0) break; const saldoHoy = p.saldo_usd ? p.saldo_usd * cot : p.saldo; const m = Math.min(resto, saldoHoy); imputAplicar[p.id] = Math.round(m * 100) / 100; resto -= m }
  aplicarDe.value = k
}
function enviarAplicar() {
  aplicar.imputaciones = Object.entries(imputAplicar).filter(([, v]) => Number(v) > 0).map(([id, v]) => ({ comprobante_id: Number(id), monto: Number(v) }))
  aplicar.post(`/proveedores/pagos/${aplicarDe.value.id}/aplicar`, { preserveScroll: true, onSuccess: () => (aplicarDe.value = null) })
}
function anularPago(k) { const motivo = window.prompt(`Motivo para anular ${k.numero}:`); if (motivo) router.post(`/proveedores/pagos/${k.id}/anular`, { motivo }, { preserveScroll: true }) }
onMounted(() => { const q = new URLSearchParams(location.search); if (q.get('pagar')) { const p = props.pendientes.find(x => x.id === Number(q.get('pagar'))); if (p) { imput[p.id] = p.saldo; pago.medios[0].monto = p.saldo } pagoAbierto.value = true; tab.value = 'pendientes' } })
</script>
