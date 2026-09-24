<template>
  <AdminLayout titulo="Uso">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Métricas de uso</h1><p class="page-subtitle">Qué empresas usan el sistema, cuáles se están enfriando y qué módulos importan. Últimos {{ dias }} días.</p></div>
      <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1"><Link v-for="d in [7, 30, 90]" :key="d" :href="`/admin/uso?dias=${d}`" class="px-3 py-1 rounded-full text-xs font-semibold" :class="dias === d ? 'bg-carmin text-white' : 'text-marca-muted'">{{ d }} días</Link></div>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Empresas</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.empresas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Activas en el período</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ kpis.activas_periodo }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Activas esta semana</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.activas_7 }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Usuarios activos</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.usuarios_activos }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Sin uso 14+ días</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.sin_uso_14 ? 'text-carmin' : ''">{{ kpis.sin_uso_14 }}</p></div>
    </div>

    <div class="card mb-5">
      <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Empresas activas por día</p>
      <div class="flex items-end gap-[3px] h-28">
        <div v-for="p in serie" :key="p.fecha" class="flex-1 rounded-t bg-violeta-grad min-w-[3px]" :style="{ height: (maxEmp ? p.empresas / maxEmp * 100 : 0) + '%' }" :title="`${p.fecha}: ${p.empresas} empresas · ${p.usuarios} usuarios · ${p.vistas} vistas`"></div>
      </div>
      <div class="flex justify-between text-[10px] text-marca-muted mt-1"><span>{{ serie[0]?.fecha }}</span><span>{{ serie.at(-1)?.fecha }}</span></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2 p-0 overflow-x-auto">
        <div class="px-4 py-3 border-b border-marca-borde flex items-center justify-between"><h2 class="font-bold">En riesgo: sin uso hace 7 días o más</h2><span class="text-xs text-marca-muted">{{ en_riesgo.length }} empresas</span></div>
        <table class="table text-sm"><thead><tr><th>Empresa</th><th>Estado</th><th class="text-right">Usuarios</th><th class="text-right">Último uso</th><th class="text-right">Sin uso</th></tr></thead>
          <tbody>
            <tr v-for="e in en_riesgo" :key="e.id"><td><Link :href="`/admin/empresas/${e.id}`" class="font-medium hover:text-carmin">{{ e.nombre }}</Link><p class="text-[11px] text-marca-muted">alta {{ e.alta }}</p></td><td><span class="badge" :class="{ active: 'bg-emerald-50 text-emerald-700', trial: 'bg-lavanda-light text-violeta', grace: 'bg-amber-50 text-amber-700' }[e.estado]">{{ e.estado }}</span></td><td class="text-right tabular-nums">{{ e.usuarios }}</td><td class="text-right tabular-nums">{{ e.ultimo ?? 'nunca' }}</td><td class="text-right tabular-nums font-semibold text-carmin">{{ e.sin_uso_dias === null ? '—' : e.sin_uso_dias + ' d' }}</td></tr>
            <tr v-if="!en_riesgo.length"><td colspan="5" class="text-center text-marca-muted py-6">Todas las empresas activas usaron el sistema esta semana.</td></tr>
          </tbody></table>
      </div>
      <div class="card">
        <h2 class="font-bold mb-2">Módulos más usados</h2>
        <div v-for="m in modulos" :key="m.modulo" class="mb-2"><div class="flex justify-between text-xs"><span class="font-medium">{{ m.label }}</span><span class="text-marca-muted tabular-nums">{{ m.vistas.toLocaleString('es-AR') }} · {{ m.empresas }} emp.</span></div><div class="h-1.5 rounded-full bg-marca-fondo overflow-hidden"><div class="h-full bg-marca-grad" :style="{ width: (modulos[0] ? m.vistas / modulos[0].vistas * 100 : 0) + '%' }"></div></div></div>
        <p v-if="!modulos.length" class="text-sm text-marca-muted">Sin datos todavía.</p>
      </div>
      <div class="card lg:col-span-3 p-0 overflow-x-auto">
        <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Ranking de actividad</h2></div>
        <table class="table text-sm"><thead><tr><th>Empresa</th><th>Estado</th><th class="text-right">Usuarios activos</th><th class="text-right">Días activos</th><th class="text-right">Vistas</th><th class="text-right">Acciones</th><th class="text-right">Último uso</th></tr></thead>
          <tbody><tr v-for="e in ranking" :key="e.id"><td><Link :href="`/admin/empresas/${e.id}`" class="font-medium hover:text-carmin">{{ e.nombre }}</Link></td><td class="text-xs text-marca-muted">{{ e.estado }}</td><td class="text-right tabular-nums">{{ e.usuarios_activos }} / {{ e.usuarios }}</td><td class="text-right tabular-nums">{{ e.dias_activos }}</td><td class="text-right tabular-nums">{{ e.vistas.toLocaleString('es-AR') }}</td><td class="text-right tabular-nums">{{ e.acciones.toLocaleString('es-AR') }}</td><td class="text-right tabular-nums">{{ e.ultimo ?? '—' }}</td></tr></tbody></table>
      </div>
    </div>
  </AdminLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
const props = defineProps({ dias: Number, kpis: Object, ranking: Array, en_riesgo: Array, modulos: Array, serie: Array })
const maxEmp = computed(() => Math.max(0, ...props.serie.map(p => p.empresas)))
</script>
