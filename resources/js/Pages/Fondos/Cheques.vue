<template>
  <AppLayout titulo="Cheques">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/fondos" class="text-xs text-marca-muted hover:text-carmin">← Fondos</Link>
        <h1 class="page-title">Cartera de cheques</h1>
        <p class="page-subtitle">Los cheques de terceros entran por cobros; los propios salen por órdenes de pago.</p>
      </div>
      <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1">
        <button v-for="t in [['tercero','De terceros'],['propio','Propios']]" :key="t[0]" @click="$inertia.visit(`/fondos/cheques?tipo=${t[0]}`)" class="px-3 py-1.5 rounded-full text-xs font-semibold" :class="tipo === t[0] ? 'bg-carmin text-white' : 'text-marca-muted'">{{ t[1] }}</button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En cartera</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(totales.cartera, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Depositados</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(totales.depositados, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Propios a debitar</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(totales.propios, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Rechazados</p><p class="text-xl font-extrabold tabular-nums" :class="totales.rechazados > 0 ? 'text-carmin' : ''">{{ moneda(totales.rechazados, 0) }}</p></div>
    </div>

    <div class="card mb-4 flex gap-2 items-center">
      <select :value="filtros.estado ?? ''" @change="$inertia.visit(`/fondos/cheques?tipo=${tipo}&estado=${$event.target.value}`)" class="input w-auto"><option value="">{{ tipo === 'tercero' ? 'En cartera, depositados y rechazados' : 'Entregados y rechazados' }}</option><option v-for="(l, k) in estados" :key="k" :value="k">{{ l }}</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Cheque</th><th>{{ tipo === 'tercero' ? 'Emisor / cliente' : 'Proveedor' }}</th><th>Fecha de pago</th><th class="text-right">Monto</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in lista" :key="c.id">
            <td><p class="font-semibold tabular-nums">{{ c.numero }} <span v-if="c.echeq" class="badge bg-violeta-light text-violeta ml-1">eCheq</span></p><p class="text-xs text-marca-muted">{{ c.banco }}<span v-if="c.cuenta"> · {{ c.cuenta }}</span></p></td>
            <td><p class="font-medium">{{ c.emisor }}</p><p v-if="c.contacto && c.contacto !== c.emisor" class="text-xs text-marca-muted">{{ c.contacto }}</p></td>
            <td class="tabular-nums" :class="c.dias < 0 && ['cartera','entregado'].includes(c.estado) ? 'text-carmin font-semibold' : c.dias <= 5 ? 'text-amber-600 font-semibold' : 'text-marca-muted'">{{ c.fecha_pago }} <span class="text-xs">({{ c.dias >= 0 ? `en ${c.dias} d` : `hace ${-c.dias} d` }})</span></td>
            <td class="text-right tabular-nums font-semibold">{{ moneda(c.monto) }}</td>
            <td><span class="badge" :class="{ cartera: 'bg-lavanda-light text-violeta', depositado: 'bg-amber-50 text-amber-700', entregado: 'bg-amber-50 text-amber-700', cobrado: 'bg-emerald-50 text-emerald-700', pagado: 'bg-emerald-50 text-emerald-700', rechazado: 'bg-carmin-light text-carmin', anulado: 'bg-gris-light text-marca-muted' }[c.estado]">{{ estados[c.estado] }}</span></td>
            <td class="text-right whitespace-nowrap">
              <template v-if="puede('fondos','crear')">
                <button v-if="c.estado === 'cartera'" @click="depositar(c)" class="btn-ghost !px-2 text-xs">Depositar</button>
                <Link v-if="c.estado === 'depositado'" :href="`/fondos/cheques/${c.id}/cobrado`" method="post" as="button" preserve-scroll class="btn-ghost !px-2 text-xs text-emerald-700">Acreditado</Link>
                <Link v-if="c.estado === 'entregado' && tipo === 'propio'" :href="`/fondos/cheques/${c.id}/debitar`" method="post" as="button" preserve-scroll class="btn-ghost !px-2 text-xs">Debitado</Link>
                <button v-if="['cartera','depositado','entregado'].includes(c.estado)" @click="rechazar(c)" class="btn-ghost !px-2 text-xs text-carmin">Rechazado</button>
              </template>
            </td>
          </tr>
          <tr v-if="!lista.length"><td colspan="6" class="text-center text-marca-muted py-10">No hay cheques con este filtro.</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="!!dep" :titulo="`Depositar cheque ${dep?.numero}`" @cerrar="dep = null">
      <label class="label">Cuenta bancaria</label>
      <select v-model="df.cuenta_fondos_id" class="input"><option v-for="b in bancos" :key="b.id" :value="b.id">{{ b.nombre }}</option></select>
      <p class="text-xs text-marca-muted mt-2">Se acredita en el banco con fecha {{ dep?.fecha_pago }} (o hoy si ya venció).</p>
      <template #pie><button class="btn-secondary" @click="dep = null">Cancelar</button><button class="btn-primary" :disabled="df.processing || !df.cuenta_fondos_id" @click="df.post(`/fondos/cheques/${dep.id}/depositar`, { preserveScroll: true, onSuccess: () => (dep = null) })">Depositar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ lista: Array, tipo: String, filtros: Object, estados: Object, totales: Object, bancos: Array })
const { puede } = usePermisos()
const dep = ref(null)
const df = useForm({ cuenta_fondos_id: props.bancos[0]?.id ?? null })
function depositar(c) { dep.value = c }
function rechazar(c) { const motivo = window.prompt(`Motivo del rechazo del cheque ${c.numero}:`); if (motivo) router.post(`/fondos/cheques/${c.id}/rechazar`, { motivo }, { preserveScroll: true }) }
</script>
