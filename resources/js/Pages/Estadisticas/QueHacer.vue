<template>
  <AppLayout titulo="Estadísticas · Qué hacer">
    <!-- Fase 33.6: los hallazgos de la semana (lo que no sabés y te cuesta plata, con el número y qué hacer) y el control de usuarios. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
      <div><h1 class="page-title">Qué hacer</h1><p class="page-subtitle">Lo que el tablero encontró esta semana ({{ hallazgos.semana }}) y quién hizo qué.</p></div>
    </div>
    <EstadisticasTabs activa="que-hacer" />

    <h2 class="font-bold mb-2">Hallazgos de la semana <span class="badge" :class="hallazgos.graves ? 'bg-carmin-light text-carmin' : 'bg-emerald-50 text-emerald-700'">{{ hallazgos.graves ? hallazgos.graves + ' para mirar' : 'todo en orden' }}</span></h2>
    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
      <div v-for="h in hallazgos.hallazgos" :key="h.clave" class="card flex flex-col" :class="h.grave ? 'border-amber-300' : ''" :data-e2e="`hallazgo-${h.clave}`">
        <div class="flex items-start justify-between gap-2"><h3 class="font-bold">{{ h.titulo }}</h3><span class="text-2xl font-black tabular-nums" :class="h.grave ? 'text-carmin' : 'text-emerald-700'">{{ h.numero }}</span></div>
        <p class="text-sm mt-1">{{ h.texto }}</p>
        <p v-if="h.grave" class="text-xs text-marca-muted mt-1"><b>Qué hacer:</b> {{ h.que_hacer }}</p>
        <details v-if="h.items.length" class="text-xs mt-2 flex-1"><summary class="cursor-pointer text-violeta font-semibold">Ver cuáles</summary>
          <div v-for="(i, k) in h.items" :key="k" class="flex justify-between gap-2 py-0.5 border-t border-marca-borde">
            <span class="truncate">{{ i.nombre ?? i.caja ?? i.usuario }}<span v-if="i.lista" class="text-marca-muted"> · lista {{ i.lista }} {{ moneda(i.precio) }} vs lista 1 {{ moneda(i.lista1) }}</span><span v-if="i.telefono" class="text-marca-muted"> · {{ i.telefono }}</span><span v-if="i.desde && !i.horas" class="text-marca-muted"> · desde {{ i.desde }}</span><span v-if="i.horas" class="text-marca-muted"> · {{ i.usuario }} · {{ i.horas }} h</span><span v-if="i.minimo !== undefined" class="text-marca-muted"> · {{ i.margen }}% de {{ i.minimo }}%</span></span>
            <span class="tabular-nums whitespace-nowrap">{{ i.saldo !== undefined ? moneda(i.saldo, 0) : i.monto !== undefined ? moneda(i.monto, 0) : '' }}</span>
          </div>
        </details>
        <Link :href="h.url" class="text-xs font-semibold mt-2" :class="h.grave ? 'text-carmin' : 'text-marca-muted'">Ir →</Link>
      </div>
    </div>

    <div class="card" data-e2e="control">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
        <h2 class="font-bold">Control de usuarios <span class="text-marca-muted cursor-help font-normal text-sm" :title="control.como">ⓘ</span></h2>
        <select :value="control.dias" class="input !py-1 !text-xs w-auto" @change="$inertia.get('/estadisticas/que-hacer', { dias: $event.target.value }, { preserveState: true, replace: true })"><option :value="7">7 días</option><option :value="30">30 días</option><option :value="90">90 días</option></select>
      </div>
      <table class="table text-sm mb-3"><thead><tr><th>Usuario</th><th class="text-right">Anuló</th><th class="text-right">Modificó después</th><th class="text-right">A deshora</th><th class="text-right">Fecha atrasada</th></tr></thead>
        <tbody><tr v-for="u in control.por_usuario" :key="u.usuario"><td class="font-medium">{{ u.usuario }}</td><td class="text-right tabular-nums" :class="u.anulados ? 'text-carmin font-bold' : ''">{{ u.anulados }}</td><td class="text-right tabular-nums" :class="u.modificados ? 'text-amber-700 font-bold' : ''">{{ u.modificados }}</td><td class="text-right tabular-nums" :class="u.deshora ? 'text-amber-700 font-bold' : ''">{{ u.deshora }}</td><td class="text-right tabular-nums" :class="u.atrasados ? 'text-amber-700 font-bold' : ''">{{ u.atrasados }}</td></tr>
        <tr v-if="!control.por_usuario.length"><td colspan="5" class="text-center text-emerald-700 py-6">Nada raro en estos días.</td></tr></tbody></table>
      <div class="flex gap-1.5 text-xs mb-2"><button v-for="t in [['anulados', 'Anulados'], ['modificados', 'Modificados después'], ['deshora', 'A deshora'], ['atrasados', 'Fecha atrasada']]" :key="t[0]" class="rounded-full px-2.5 py-1 font-semibold border" :class="tab === t[0] ? 'bg-marca-texto text-white border-marca-texto' : 'border-marca-borde'" @click="tab = t[0]">{{ t[1] }} ({{ control[t[0]].length }})</button></div>
      <div class="max-h-72 overflow-y-auto text-xs">
        <div v-for="(r, i) in control[tab]" :key="i" class="flex justify-between gap-2 py-1 border-t border-marca-borde"><span><b>{{ r.usuario }}</b> · {{ r.cuando }} · {{ r.descripcion ?? (r.numero + ' (' + r.motivo + ')') }}</span><span v-if="r.total !== undefined" class="tabular-nums">{{ moneda(r.total, 0) }}</span></div>
        <p v-if="!control[tab].length" class="text-marca-muted py-4 text-center">Nada.</p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadisticasTabs from '@/Components/EstadisticasTabs.vue'
import { moneda } from '@/util/formato'
defineProps({ hallazgos: Object, control: Object })
const tab = ref('anulados')
</script>
