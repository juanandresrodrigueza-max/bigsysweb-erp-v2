<template>
  <div class="card" data-e2e="embudo-crm">
    <div class="flex flex-wrap items-start justify-between gap-2 mb-2">
      <div><h2 class="font-bold">El embudo del CRM <span class="text-marca-muted font-normal text-sm">· lo que todavía no se facturó</span></h2>
        <p class="text-xs text-marca-muted">Oportunidades abiertas por etapa, lo ganado y perdido en el período y cómo viene cada vendedor en los dos sistemas.</p></div>
      <a :href="crm.url" target="_blank" class="btn-secondary !py-1 text-xs">Abrir el CRM</a>
    </div>
    <div class="grid sm:grid-cols-3 gap-3 mb-3 text-sm">
      <div class="rounded-xl bg-marca-fondo p-3"><p class="text-xs text-marca-muted">Abiertas</p><p class="font-bold tabular-nums text-lg">{{ moneda(crm.abiertas.total, 0) }}</p><p class="text-xs text-marca-muted">{{ crm.abiertas.cantidad }} oportunidad(es)</p></div>
      <div class="rounded-xl bg-emerald-50 dark:bg-emerald-950/40 p-3"><p class="text-xs text-marca-muted">Ganadas en el período</p><p class="font-bold tabular-nums text-lg text-emerald-700 dark:text-emerald-300">{{ moneda(crm.ganadas.total, 0) }}</p><p class="text-xs text-marca-muted">{{ crm.ganadas.cantidad }} · tasa {{ crm.tasa_cierre ?? '—' }}%</p></div>
      <div class="rounded-xl bg-marca-fondo p-3"><p class="text-xs text-marca-muted">Perdidas en el período</p><p class="font-bold tabular-nums text-lg">{{ crm.perdidas.cantidad }}</p><p class="text-xs text-marca-muted">{{ crm.tareas_vencidas }} tarea(s) vencida(s) en el CRM</p></div>
    </div>
    <div v-if="crm.etapas.length" class="space-y-1 mb-3">
      <div v-for="e in crm.etapas" :key="e.etapa" class="flex items-center gap-2 text-sm">
        <span class="w-32 truncate" :title="e.etapa">{{ e.etapa }}</span>
        <div class="flex-1 h-4 rounded bg-marca-fondo overflow-hidden"><div class="h-full bg-sky-500/70" :style="{ width: Math.max(2, e.total / maxEtapa * 100) + '%' }"></div></div>
        <span class="tabular-nums text-xs w-28 text-right">{{ e.cantidad }} · {{ moneda(e.total, 0) }}</span>
      </div>
    </div>
    <p v-else class="text-xs text-marca-muted mb-3">No hay oportunidades abiertas en el CRM.</p>
    <div class="overflow-x-auto"><table class="table text-sm"><thead><tr><th>Vendedor</th><th class="text-right">Abiertas (CRM)</th><th class="text-right">Ganadas (CRM)</th><th class="text-right">Vendido (ERP)</th><th class="text-right">Tareas vencidas</th></tr></thead>
      <tbody><tr v-for="v in crm.por_vendedor" :key="v.vendedor"><td class="font-semibold">{{ v.vendedor }}</td><td class="text-right tabular-nums">{{ v.abiertas }} · {{ moneda(v.abiertas_total, 0) }}</td><td class="text-right tabular-nums">{{ v.ganadas }} · {{ moneda(v.ganadas_total, 0) }}</td>
        <td class="text-right tabular-nums">{{ v.vendido_erp === null ? '—' : moneda(v.vendido_erp, 0) }}</td><td class="text-right tabular-nums" :class="v.tareas_vencidas ? 'text-amber-600' : ''">{{ v.tareas_vencidas }}</td></tr>
        <tr v-if="!crm.por_vendedor.length"><td colspan="5" class="text-center text-marca-muted py-4">El CRM no tiene oportunidades asignadas a vendedores.</td></tr></tbody></table></div>
    <p class="text-[11px] text-marca-muted mt-2">"Vendido (ERP)" cruza el vendedor del CRM con el del ERP por nombre o mail. Los montos del CRM son los que cargó el vendedor en cada oportunidad; se facturan y se cobran en el ERP.</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { moneda } from '@/util/formato'
const props = defineProps({ crm: { type: Object, required: true } })
const maxEtapa = computed(() => Math.max(1, ...props.crm.etapas.map(e => e.total)))
</script>
