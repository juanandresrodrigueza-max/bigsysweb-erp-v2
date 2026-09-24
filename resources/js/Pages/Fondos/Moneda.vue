<template>
  <AppLayout titulo="Dólares">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Posición en dólares</h1><p class="page-subtitle">Cotización del día, cuentas en dólares, lo que hay por cobrar y pagar en dólares y la diferencia de cambio.</p></div>
      <div class="flex flex-wrap gap-2"><Link href="/fondos" class="btn-secondary">Fondos</Link><button class="btn-secondary" @click="router.post('/fondos/moneda/actualizar', {}, { preserveScroll: true })">Bajar cotización</button><button class="btn-primary" @click="revaluarAbierto = true">Revaluar tenencia</button></div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div v-for="(c, k) in cotizaciones" :key="k" class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Dólar {{ c.label }}</p><p class="text-xl font-extrabold tabular-nums">{{ c.venta ? moneda(c.venta) : '—' }}</p><p class="text-xs text-marca-muted">{{ c.fecha || 'sin datos' }}<span v-if="c.propia"> · fijada por vos</span><span v-else-if="c.fuente"> · {{ c.fuente }}</span></p></div>
    </div>
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-0 overflow-hidden">
          <div class="px-4 py-3 border-b border-marca-borde flex items-center justify-between"><h2 class="font-bold">Tenencia en dólares</h2><span class="text-sm">USD <b class="tabular-nums">{{ n(posicion.tenencia_usd) }}</b> · {{ moneda(posicion.tenencia_ars, 0) }} a {{ moneda(posicion.cotizacion) }}</span></div>
          <table class="table text-sm">
            <thead><tr><th>Cuenta</th><th class="text-right">Saldo USD</th><th class="text-right">Última valuación</th><th class="text-right">Valuación hoy</th><th class="text-right">Dif. pendiente</th></tr></thead>
            <tbody>
              <tr v-for="c in posicion.cuentas" :key="c.id"><td class="font-medium">{{ c.nombre }}<span class="text-xs text-marca-muted"> · {{ c.tipo }}</span></td><td class="text-right tabular-nums font-bold">USD {{ n(c.saldo_usd) }}</td><td class="text-right tabular-nums text-marca-muted">{{ c.cotizacion_cierre ? moneda(c.cotizacion_cierre) : 'nunca' }}</td><td class="text-right tabular-nums">{{ moneda(c.valuacion, 0) }}</td><td class="text-right tabular-nums" :class="c.dif_pendiente > 0 ? 'text-emerald-700' : c.dif_pendiente < 0 ? 'text-carmin' : 'text-marca-muted'">{{ moneda(c.dif_pendiente, 0) }}</td></tr>
              <tr v-if="!posicion.cuentas.length"><td colspan="5" class="text-center text-marca-muted py-6">No tenés cuentas en dólares. Creá una en Fondos con moneda USD (caja fuerte, cuenta bancaria en USD).</td></tr>
            </tbody>
          </table>
        </div>
        <div class="grid md:grid-cols-2 gap-4">
          <div class="card p-0 overflow-hidden">
            <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Por cobrar en USD</h2><p class="text-xs text-marca-muted">USD {{ n(posicion.por_cobrar_usd) }} pendientes</p></div>
            <div class="divide-y divide-marca-borde/60 text-sm">
              <Link v-for="c in posicion.por_cobrar" :key="c.id" :href="`/comprobantes/${c.id}`" class="flex justify-between px-4 py-2 hover:bg-gris-light/40"><span><b>{{ c.numero }}</b> <span class="text-xs text-marca-muted">{{ c.cliente }} · {{ c.fecha }} · cot. {{ n(c.cotizacion) }}</span></span><span class="tabular-nums">USD {{ n(c.saldo_me) }}</span></Link>
              <p v-if="!posicion.por_cobrar.length" class="px-4 py-5 text-center text-marca-muted text-xs">Nada pendiente en dólares.</p>
            </div>
          </div>
          <div class="card p-0 overflow-hidden">
            <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Por pagar en USD</h2><p class="text-xs text-marca-muted">USD {{ n(posicion.por_pagar_usd) }} pendientes</p></div>
            <div class="divide-y divide-marca-borde/60 text-sm">
              <Link v-for="c in posicion.por_pagar" :key="c.id" :href="`/proveedores/compras/${c.id}`" class="flex justify-between px-4 py-2 hover:bg-gris-light/40"><span><b>{{ c.numero }}</b> <span class="text-xs text-marca-muted">{{ c.proveedor }} · {{ c.fecha }}</span></span><span class="tabular-nums">USD {{ n(c.saldo_me) }}</span></Link>
              <p v-if="!posicion.por_pagar.length" class="px-4 py-5 text-center text-marca-muted text-xs">Nada pendiente en dólares.</p>
            </div>
          </div>
        </div>
      </div>
      <div class="space-y-4">
        <div class="card">
          <h2 class="font-bold mb-1">Fijar cotización propia</h2>
          <p class="text-xs text-marca-muted mb-2">Si querés facturar a un dólar distinto al del mercado (oficial, blue o el que arregles), fijalo acá. Vale para hoy.</p>
          <div class="grid grid-cols-2 gap-2"><div><label class="label">Venta</label><input v-model.number="fj.venta" type="number" step="any" class="input" /></div><div><label class="label">Compra</label><input v-model.number="fj.compra" type="number" step="any" class="input" /></div></div>
          <button class="btn-primary w-full mt-2" :disabled="fj.processing || !fj.venta" @click="fj.post('/fondos/moneda/fijar', { preserveScroll: true })">Fijar</button>
        </div>
        <div class="card text-sm">
          <h2 class="font-bold mb-2">Diferencias de cambio</h2>
          <div class="divide-y divide-marca-borde/60">
            <div v-for="a in posicion.ultimas" :key="a.id" class="flex justify-between py-1.5"><span class="text-xs"><span class="text-marca-muted">{{ a.fecha }}</span> {{ a.concepto }}</span><span class="tabular-nums text-xs font-semibold">{{ moneda(a.total, 0) }}</span></div>
            <p v-if="!posicion.ultimas.length" class="py-3 text-xs text-marca-muted">Todavía no hay revaluaciones. La primera fija la base; las siguientes generan el asiento de diferencia de cambio.</p>
          </div>
        </div>
        <div class="card text-xs text-marca-muted">
          <b class="text-marca-texto">Cómo se usa</b><br>Facturás en dólares desde el comprobante (moneda USD): sale en pesos a la cotización y queda el importe en USD. Cobrás o pagás en dólares tildando "En dólares" en el medio de pago: entra a la cuenta USD y se contabiliza en pesos. Cada fin de mes revaluás la tenencia.
        </div>
      </div>
    </div>
    <Modal :abierto="revaluarAbierto" titulo="Revaluar tenencia en dólares" @cerrar="revaluarAbierto = false">
      <p class="text-sm mb-3">Se valúan los saldos en USD a la cotización que indiques. La diferencia contra la última valuación se contabiliza como diferencia de cambio.</p>
      <div class="grid grid-cols-2 gap-3"><div><label class="label">Cotización</label><input v-model.number="rv.cotizacion" type="number" step="any" class="input" /></div><div><label class="label">Fecha</label><input v-model="rv.fecha" type="date" class="input" /></div></div>
      <template #pie><button class="btn-secondary" @click="revaluarAbierto = false">Cancelar</button><button class="btn-primary" :disabled="rv.processing || !rv.cotizacion" @click="rv.post('/fondos/moneda/revaluar', { preserveScroll: true, onSuccess: () => (revaluarAbierto = false) })">Revaluar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, hoyISO } from '@/util/formato'
const props = defineProps({ cotizaciones: Object, posicion: Object })
const n = v => Number(v ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const revaluarAbierto = ref(false)
const fj = useForm({ venta: props.cotizaciones.oficial?.venta || null, compra: props.cotizaciones.oficial?.compra || null, tipo: 'oficial' })
const rv = useForm({ cotizacion: props.posicion.cotizacion || null, fecha: hoyISO() })
</script>
