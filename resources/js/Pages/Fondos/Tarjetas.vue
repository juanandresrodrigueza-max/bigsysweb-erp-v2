<template>
  <AppLayout titulo="Tarjetas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/fondos" class="text-xs text-marca-muted hover:text-carmin">← Fondos</Link>
        <h1 class="page-title">Cupones y liquidaciones de tarjeta</h1>
        <p class="page-subtitle">Cada cobro con tarjeta deja un cupón. Cuando el emisor acredita, cargás la liquidación: bruto − comisión − IVA − retenciones = neto al banco.</p>
      </div>
      <button v-if="puede('fondos','crear') && seleccion.length" @click="liqAbierto = true" class="btn-primary">Liquidar {{ seleccion.length }} cupón{{ seleccion.length > 1 ? 'es' : '' }} · {{ moneda(brutoSel, 0) }}</button>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En cartera</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(totales.cartera, 0) }}</p><p class="text-xs text-marca-muted">{{ totales.cartera_n }} cupones</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Acreditado este mes</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ moneda(totales.liquidado_mes, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Costo tarjetas del mes</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(totales.costo_mes, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Tasa efectiva</p><p class="text-xl font-extrabold tabular-nums">{{ totales.liquidado_mes + totales.costo_mes > 0 ? (totales.costo_mes / (totales.liquidado_mes + totales.costo_mes) * 100).toFixed(1) + '%' : '—' }}</p></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 card p-0 overflow-x-auto">
        <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde">
          <h2 class="font-bold">Cupones {{ filtros.estado ? filtros.estado + 's' : 'en cartera' }}</h2>
          <select :value="filtros.estado ?? ''" @change="$inertia.visit(`/fondos/tarjetas?estado=${$event.target.value}`)" class="input w-auto !py-1 text-xs"><option value="">En cartera</option><option value="liquidado">Liquidados</option><option value="rechazado">Rechazados</option></select>
        </div>
        <table class="table">
          <thead><tr><th v-if="!filtros.estado"><input type="checkbox" :checked="cupones.length && seleccion.length === cupones.length" @change="e => (seleccion = e.target.checked ? cupones.map(c => c.id) : [])" /></th><th>Fecha</th><th>Tarjeta</th><th>Cupón</th><th>Cliente</th><th class="text-right">Cuotas</th><th class="text-right">Monto</th><th></th></tr></thead>
          <tbody>
            <tr v-for="c in cupones" :key="c.id">
              <td v-if="!filtros.estado"><input v-model="seleccion" :value="c.id" type="checkbox" class="accent-carmin" /></td>
              <td class="tabular-nums text-marca-muted">{{ c.fecha }}<span v-if="c.estado === 'cartera' && c.dias > 20" class="block text-[10px] text-amber-700">{{ c.dias }} días</span></td>
              <td class="font-medium">{{ c.tarjeta }}</td>
              <td class="tabular-nums text-xs">{{ c.numero ?? '—' }}<span v-if="c.lote" class="text-marca-muted"> · lote {{ c.lote }}</span></td>
              <td>{{ c.cliente }}<Link v-if="c.cobro_id" :href="`/clientes/cobros/${c.cobro_id}/imprimir`" class="block text-xs text-violeta">{{ c.recibo }}</Link></td>
              <td class="text-right tabular-nums">{{ c.cuotas }}</td>
              <td class="text-right tabular-nums font-semibold">{{ moneda(c.monto) }}</td>
              <td><button v-if="c.estado === 'cartera' && puede('fondos','editar')" @click="rechazar(c)" class="text-xs text-marca-muted hover:text-carmin">Rechazado</button></td>
            </tr>
            <tr v-if="!cupones.length"><td colspan="8" class="text-center text-marca-muted py-10">No hay cupones.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="card">
        <h2 class="font-bold mb-3">Últimas liquidaciones</h2>
        <div v-for="l in liquidaciones" :key="l.id" class="py-2 border-t border-marca-borde first:border-0 text-sm" :class="l.estado === 'anulada' ? 'opacity-50 line-through' : ''">
          <div class="flex justify-between gap-2"><span class="font-semibold">{{ l.codigo }} · {{ l.tarjeta }}</span><b class="tabular-nums text-emerald-700">{{ moneda(l.neto, 0) }}</b></div>
          <p class="text-xs text-marca-muted">{{ l.fecha }} · {{ l.cupones }} cupones · bruto {{ moneda(l.bruto, 0) }} · costo {{ moneda(l.descuentos, 0) }} · {{ l.banco }}<button v-if="l.estado === 'registrada' && puede('fondos','anular')" @click="anular(l)" class="ml-2 text-carmin font-semibold">anular</button></p>
        </div>
        <p v-if="!liquidaciones.length" class="text-sm text-marca-muted">Todavía no hay liquidaciones.</p>
      </div>
    </div>

    <Modal :abierto="liqAbierto" titulo="Cargar liquidación de tarjeta" ancho="max-w-2xl" @cerrar="liqAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Copiá los datos del comprobante de liquidación. Bruto de los cupones elegidos: <b class="tabular-nums">{{ moneda(brutoSel) }}</b>.</p>
      <div class="grid sm:grid-cols-3 gap-3">
        <div><label class="label">Fecha</label><input v-model="lf.fecha" type="date" class="input" /></div>
        <div><label class="label">N° liquidación</label><input v-model="lf.numero" class="input" /></div>
        <div><label class="label">Tarjeta</label><select v-model="lf.tarjeta" class="input"><option v-for="t in tarjetas" :key="t">{{ t }}</option></select></div>
        <div class="sm:col-span-3"><label class="label">Acredita en</label><select v-model="lf.cuenta_fondos_id" class="input"><option v-for="b in bancos" :key="b.id" :value="b.id">{{ b.nombre }}</option></select><p v-if="lf.errors.cuenta_fondos_id" class="text-carmin text-xs mt-1">{{ lf.errors.cuenta_fondos_id }}</p></div>
        <div><label class="label">Comisión (arancel)</label><input v-model.number="lf.comision" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">IVA sobre comisión</label><input v-model.number="lf.iva_comision" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">Otros gastos</label><input v-model.number="lf.otros" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">Retención IVA</label><input v-model.number="lf.ret_iva" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">Retención IIBB</label><input v-model.number="lf.ret_iibb" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">Retención Ganancias</label><input v-model.number="lf.ret_ganancias" type="number" step="any" min="0" class="input" /></div>
        <div class="sm:col-span-3"><label class="label">Notas</label><input v-model="lf.notas" class="input" /></div>
      </div>
      <div class="mt-3 rounded-xl bg-marca-fondo p-3 text-sm flex justify-between"><span>Neto que entra al banco</span><b class="tabular-nums" :class="neto <= 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(neto) }}</b></div>
      <p v-if="lf.errors.comision || lf.errors.cupones" class="text-carmin text-xs mt-2">{{ lf.errors.comision || lf.errors.cupones }}</p>
      <template #pie><button class="btn-secondary" @click="liqAbierto = false">Cancelar</button><button class="btn-primary" :disabled="lf.processing || neto <= 0" @click="lf.transform(d => ({ ...d, cupones: seleccion })).post('/fondos/tarjetas/liquidar', { preserveScroll: true, onSuccess: () => { liqAbierto = false; seleccion = [] } })">Registrar liquidación</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, hoyISO } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ cupones: Array, filtros: Object, totales: Object, liquidaciones: Array, bancos: Array, tarjetas: Array })
const { puede } = usePermisos()
const seleccion = ref([]), liqAbierto = ref(false)
const brutoSel = computed(() => props.cupones.filter(c => seleccion.value.includes(c.id)).reduce((a, c) => a + c.monto, 0))
const lf = useForm({ fecha: hoyISO(), numero: '', tarjeta: 'Visa', cuenta_fondos_id: props.bancos[0]?.id ?? null, comision: 0, iva_comision: 0, otros: 0, ret_iva: 0, ret_iibb: 0, ret_ganancias: 0, notas: '' })
const neto = computed(() => brutoSel.value - ['comision', 'iva_comision', 'otros', 'ret_iva', 'ret_iibb', 'ret_ganancias'].reduce((a, k) => a + (Number(lf[k]) || 0), 0))
function rechazar(c) { const motivo = window.prompt(`Motivo del rechazo del cupón ${c.numero ?? ''}:`); if (motivo) router.post(`/fondos/tarjetas/cupones/${c.id}/rechazar`, { motivo }, { preserveScroll: true }) }
function anular(l) { const motivo = window.prompt(`Motivo para anular ${l.codigo}:`); if (motivo) router.post(`/fondos/tarjetas/liquidaciones/${l.id}/anular`, { motivo }, { preserveScroll: true }) }
</script>
