<template>
  <AdminLayout titulo="Inicio">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Así está BigSysWeb hoy</h1><p class="page-subtitle">Empresas, cobros y lo que vence pronto.</p></div>
      <Link href="/admin/sistema" class="btn-secondary !py-1.5 text-xs">Parámetros del sistema</Link>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-5">
      <StatCard label="Empresas" :valor="kpis.empresas" formato="entero" url="/admin/empresas" />
      <StatCard label="Activas" :valor="kpis.activas" formato="entero" url="/admin/empresas?estado=active" />
      <StatCard label="En prueba" :valor="kpis.prueba" formato="entero" url="/admin/empresas?estado=trial" />
      <StatCard label="Vencidas / suspendidas" :valor="kpis.gracia + kpis.suspendidas" formato="entero" :alerta="kpis.gracia + kpis.suspendidas > 0" url="/admin/empresas?estado=grace" />
      <StatCard label="MRR" :valor="kpis.mrr" url="/admin/cobros" />
      <StatCard label="Cobrado este mes" :valor="kpis.cobrado_mes" :tendencia="kpis.cobrado_anterior ? Math.round((kpis.cobrado_mes - kpis.cobrado_anterior) / kpis.cobrado_anterior * 100) : null" url="/admin/cobros" />
    </div>

    <div v-if="kpis.pendientes" class="mb-5 px-4 py-3 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-3">
      <Icono nombre="alert" clase="w-5 h-5 shrink-0" /><span><b>{{ kpis.pendientes }}</b> transferencia{{ kpis.pendientes > 1 ? 's' : '' }} informada{{ kpis.pendientes > 1 ? 's' : '' }} por empresas, esperando que la confirmes.</span>
      <Link href="/admin/cobros?estado=pendiente" class="ml-auto font-semibold hover:underline">Ver</Link>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2">
        <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Vencen en los próximos 10 días</h2><Link href="/admin/empresas" class="text-xs font-semibold text-carmin">Todas las empresas</Link></div>
        <table class="table" v-if="vencen.length">
          <thead><tr><th>Empresa</th><th>Plan</th><th>Estado</th><th>Vence</th><th></th></tr></thead>
          <tbody>
            <tr v-for="v in vencen" :key="v.id" class="cursor-pointer" @click="$inertia.visit(`/admin/empresas/${v.id}`)">
              <td class="font-semibold">{{ v.empresa }}</td><td>{{ v.plan }}</td>
              <td><span class="badge" :class="estadoClase[v.estado]">{{ v.estado_label }}</span></td>
              <td class="tabular-nums" :class="v.dias <= 1 ? 'text-carmin font-bold' : v.dias <= 3 ? 'text-amber-600 font-semibold' : ''">{{ v.fecha }} <span class="text-xs">({{ v.dias <= 0 ? 'hoy' : `en ${v.dias} d` }})</span></td>
              <td class="text-right"><Icono nombre="chevron" clase="w-4 h-4 -rotate-90 text-marca-muted" /></td>
            </tr>
          </tbody>
        </table>
        <p v-else class="text-sm text-marca-muted py-6 text-center">Nada vence en los próximos días.</p>
      </div>

      <div class="card">
        <h2 class="font-bold mb-3">Altas por mes</h2>
        <div class="flex items-end gap-2 h-32">
          <div v-for="a in altas" :key="a.mes" class="flex-1 flex flex-col items-center justify-end gap-1 h-full">
            <span class="text-xs font-bold tabular-nums">{{ a.n }}</span>
            <div class="w-full rounded-t-lg bg-violeta-grad" :style="{ height: (a.n / maxAltas * 100) + '%', minHeight: a.n ? '6px' : '2px' }"></div>
            <span class="text-[10px] text-marca-muted uppercase">{{ a.mes }}</span>
          </div>
        </div>
        <p class="text-xs text-marca-muted mt-3">{{ kpis.usuarios }} usuarios en total en todas las empresas.</p>
      </div>

      <div class="card">
        <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Últimos cobros</h2><Link href="/admin/cobros" class="text-xs font-semibold text-carmin">Ver todos</Link></div>
        <div v-for="p in ultimosPagos" :key="p.id" class="flex items-center gap-3 py-2 border-t border-marca-borde/60 first:border-0 text-sm">
          <div class="flex-1 min-w-0"><Link :href="`/admin/empresas/${p.business_id}`" class="font-medium truncate block hover:text-carmin">{{ p.empresa }}</Link><p class="text-xs text-marca-muted">{{ p.plan }} · {{ p.medio }} · {{ p.fecha }}</p></div>
          <span class="tabular-nums font-semibold" :class="p.estado === 'aprobado' ? 'text-emerald-700' : p.estado === 'pendiente' ? 'text-amber-600' : 'text-marca-muted line-through'">{{ moneda(p.monto, 0) }}</span>
        </div>
      </div>

      <div class="card">
        <h2 class="font-bold mb-3">Empresas nuevas</h2>
        <div v-for="n in nuevas" :key="n.id" class="flex items-center gap-3 py-2 border-t border-marca-borde/60 first:border-0 text-sm">
          <span class="w-8 h-8 rounded-lg bg-lavanda-light text-violeta grid place-items-center font-extrabold text-xs">{{ n.nombre[0] }}</span>
          <div class="flex-1 min-w-0"><Link :href="`/admin/empresas/${n.id}`" class="font-medium truncate block hover:text-carmin">{{ n.nombre }}</Link><p class="text-xs text-marca-muted">{{ n.vertical }} · {{ n.hace }}</p></div>
          <span class="badge" :class="estadoClase[n.estado]">{{ estados[n.estado] ?? '—' }}</span>
        </div>
      </div>

      <div class="card">
        <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Actividad</h2><Link href="/admin/auditoria" class="text-xs font-semibold text-carmin">Auditoría</Link></div>
        <div v-for="a in actividad" :key="a.id" class="py-2 border-t border-marca-borde/60 first:border-0 text-sm"><p class="leading-snug">{{ a.descripcion }}</p><p class="text-xs text-marca-muted">{{ a.usuario }} · {{ a.hace }}</p></div>
        <p v-if="!actividad.length" class="text-sm text-marca-muted">Sin actividad todavía.</p>
      </div>
    </div>
  </AdminLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import Icono from '@/Components/Icono.vue'
import { moneda } from '@/util/formato'
import { estadoClase, estados } from '@/util/suscripcion'
const props = defineProps({ kpis: Object, vencen: Array, altas: Array, ultimosPagos: Array, nuevas: Array, actividad: Array })
const maxAltas = computed(() => Math.max(1, ...props.altas.map(a => a.n)))
</script>
