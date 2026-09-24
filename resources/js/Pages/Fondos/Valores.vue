<template>
  <AppLayout titulo="Trazabilidad de valores">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Trazabilidad de valores</h1><p class="page-subtitle">De qué cliente vino cada cheque o cupón, dónde está hoy y a qué proveedor o banco fue.</p></div>
      <div class="flex gap-2"><Link href="/fondos/cheques" class="btn-secondary">Cheques</Link><Link href="/fondos/tarjetas" class="btn-secondary">Tarjetas</Link><Link href="/fondos" class="btn-secondary">Fondos</Link></div>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Cheques en cartera</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.cartera, 0) }}</p><p class="text-[11px] text-marca-muted">{{ resumen.cartera_n }} cheques</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Propios a debitar</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(resumen.propios, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Cupones sin liquidar</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.cupones, 0) }}</p><p class="text-[11px] text-marca-muted">{{ resumen.cupones_n }} cupones</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Rechazados</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.rechazados ? 'text-carmin' : ''">{{ resumen.rechazados }}</p></div>
    </div>
    <form class="flex flex-wrap gap-2 mb-4" @submit.prevent="router.get('/fondos/valores', f, { preserveState: true })">
      <input v-model="f.q" class="input flex-1 min-w-48" placeholder="Número, emisor, banco, lote o tarjeta…" />
      <select v-model="f.tipo" class="input"><option value="">Terceros y propios</option><option value="tercero">De terceros</option><option value="propio">Propios</option></select>
      <select v-model="f.estado" class="input"><option value="">Todos los estados</option><option v-for="(l, k) in estadosCheque" :key="k" :value="k">{{ l }}</option></select>
      <button class="btn-secondary">Buscar</button>
    </form>
    <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1 w-fit mb-4"><button v-for="t in ['cheques', 'cupones']" :key="t" class="px-4 py-1.5 rounded-full text-sm font-semibold capitalize" :class="tab === t ? 'bg-carmin text-white' : 'text-marca-muted'" @click="tab = t">{{ t }}</button></div>

    <div v-if="tab === 'cheques'" class="card p-0 overflow-x-auto">
      <table class="table text-xs">
        <thead><tr><th>Cheque</th><th>Emisor / banco</th><th class="text-right">Monto</th><th>Cobro</th><th>Estado</th><th>Origen (de dónde vino)</th><th>Destino (a dónde fue)</th></tr></thead>
        <tbody>
          <tr v-for="c in cheques" :key="c.id">
            <td class="tabular-nums font-semibold">{{ c.numero }}<span v-if="c.echeq" class="badge bg-violeta-light text-violeta ml-1">e-cheq</span><span class="badge ml-1" :class="c.tipo === 'propio' ? 'bg-amber-50 text-amber-700' : 'bg-gris-light text-marca-muted'">{{ c.tipo }}</span></td>
            <td>{{ c.emisor }}<span class="text-marca-muted"> · {{ c.banco }}</span></td><td class="text-right tabular-nums">{{ moneda(c.monto) }}</td><td class="tabular-nums">{{ c.fecha_pago }}</td>
            <td><span class="badge" :class="{ 'bg-emerald-50 text-emerald-700': ['cobrado', 'depositado', 'pagado'].includes(c.estado), 'bg-red-50 text-carmin': c.estado === 'rechazado', 'bg-gris-light text-marca-muted': ['cartera', 'entregado', 'anulado'].includes(c.estado) }">{{ c.estado_label }}</span></td>
            <td><Link v-if="c.contact_id && c.tipo === 'tercero'" :href="`/clientes/${c.contact_id}`" class="hover:underline">{{ c.origen }}</Link><span v-else>{{ c.origen }}</span></td>
            <td><Link v-if="c.pago_id && c.contact_id" :href="`/proveedores/${c.contact_id}`" class="hover:underline">{{ c.destino }}</Link><span v-else>{{ c.destino }}</span><span v-if="c.notas" class="text-marca-muted"> · {{ c.notas }}</span></td>
          </tr>
          <tr v-if="!cheques.length"><td colspan="7" class="text-center text-marca-muted py-8">Sin cheques con ese filtro.</td></tr>
        </tbody>
      </table>
    </div>
    <div v-else class="card p-0 overflow-x-auto">
      <table class="table text-xs">
        <thead><tr><th>Cupón</th><th>Tarjeta</th><th class="text-right">Monto</th><th>Fecha</th><th>Estado</th><th>Origen</th><th>Destino</th></tr></thead>
        <tbody>
          <tr v-for="c in cupones" :key="c.id">
            <td class="tabular-nums font-semibold">{{ c.numero }}<span class="text-marca-muted"> lote {{ c.lote }}</span></td><td>{{ c.tarjeta }}<span v-if="c.cuotas > 1" class="text-marca-muted"> · {{ c.cuotas }} cuotas</span></td><td class="text-right tabular-nums">{{ moneda(c.monto) }}</td><td class="tabular-nums">{{ c.fecha }}</td>
            <td><span class="badge" :class="{ 'bg-emerald-50 text-emerald-700': c.estado === 'liquidado', 'bg-red-50 text-carmin': c.estado === 'rechazado', 'bg-gris-light text-marca-muted': c.estado === 'cartera' }">{{ c.estado }}</span></td>
            <td><Link v-if="c.contact_id" :href="`/clientes/${c.contact_id}`" class="hover:underline">{{ c.origen }}</Link><span v-else>{{ c.origen }}</span></td>
            <td>{{ c.destino }}</td>
          </tr>
          <tr v-if="!cupones.length"><td colspan="7" class="text-center text-marca-muted py-8">Sin cupones con ese filtro.</td></tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ cheques: Array, cupones: Array, filtros: Object, estadosCheque: Object, resumen: Object })
const f = reactive({ q: props.filtros.q ?? '', tipo: props.filtros.tipo ?? '', estado: props.filtros.estado ?? '' })
const tab = ref('cheques')
</script>
