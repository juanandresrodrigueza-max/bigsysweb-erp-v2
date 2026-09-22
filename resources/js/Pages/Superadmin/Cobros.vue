<template>
  <AdminLayout titulo="Cobros">
    <div class="mb-5"><h1 class="page-title">Cobros de suscripción</h1><p class="page-subtitle">Lo que las empresas le pagan a BigSys. Las transferencias informadas se confirman acá o desde la ficha de la empresa.</p></div>

    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Cobrado este mes</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ moneda(resumen.mes, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Pendientes de confirmar</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.pendientes_n ? 'text-amber-600' : ''">{{ moneda(resumen.pendientes, 0) }}</p><p class="text-xs text-marca-muted">{{ resumen.pendientes_n }} pago{{ resumen.pendientes_n === 1 ? '' : 's' }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Cobrado en el año</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.anio, 0) }}</p></div>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-3">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input" placeholder="Empresa…" />
      <select v-model="f.estado" @change="filtrar" class="input"><option value="">Todos los estados</option><option v-for="(l, k) in estados" :key="k" :value="k">{{ l }}</option></select>
      <select v-model="f.medio" @change="filtrar" class="input"><option value="">Todos los medios</option><option v-for="(l, k) in medios" :key="k" :value="k">{{ l }}</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Fecha</th><th>Empresa</th><th>Plan</th><th>Período</th><th>Medio</th><th class="text-right">Importe</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <tr v-for="p in lista.data" :key="p.id">
            <td class="tabular-nums text-marca-muted">{{ p.fecha }}</td>
            <td><Link :href="`/admin/empresas/${p.business_id}`" class="font-semibold hover:text-carmin">{{ p.empresa }}</Link></td>
            <td>{{ p.plan }} <span class="text-xs text-marca-muted">{{ cicloLabel[p.ciclo] }}</span></td>
            <td class="text-xs text-marca-muted">{{ p.periodo ?? '—' }}</td>
            <td>{{ p.medio_label }}<p v-if="p.referencia" class="text-xs text-marca-muted">{{ p.referencia }}</p></td>
            <td class="text-right tabular-nums font-semibold">{{ moneda(p.monto) }}</td>
            <td><span class="badge" :class="pagoClase[p.estado]">{{ pagoLabel[p.estado] }}</span></td>
            <td class="text-right whitespace-nowrap">
              <template v-if="p.estado === 'pendiente'">
                <Link :href="`/admin/empresas/${p.business_id}/pagos/${p.id}/aprobar`" method="post" as="button" preserve-scroll class="btn-primary !py-1 text-xs">Confirmar</Link>
                <Link :href="`/admin/empresas/${p.business_id}/pagos/${p.id}/rechazar`" method="post" as="button" preserve-scroll class="btn-ghost !py-1 !px-2 text-xs text-carmin">Rechazar</Link>
              </template>
            </td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="8" class="text-center text-marca-muted py-10">Sin cobros con este filtro.</td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />
  </AdminLayout>
</template>

<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda } from '@/util/formato'
import { pagoClase, pagoLabel, cicloLabel } from '@/util/suscripcion'
const props = defineProps({ lista: Object, filtros: Object, estados: Object, medios: Object, resumen: Object })
const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? '', medio: props.filtros.medio ?? '' })
function filtrar() { router.get('/admin/cobros', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
</script>
