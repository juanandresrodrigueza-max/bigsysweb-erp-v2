<template>
  <AppLayout titulo="Indicadores">
    <!-- Fase 99: los indicadores que el contador mira antes de hablar con el dueño. -->
    <PageHeader titulo="Indicadores" compacto :volver="{ href: '/contable/contador', label: 'Panel del contador' }">
      <template #subtitulo>Doce meses de ventas, compras, gastos e IVA, margen bruto, días de cobro y de pago, liquidez y presión fiscal. Con lo que falta cargar para que los números sean de verdad.</template>
    </PageHeader>
    <div class="flex items-center gap-2 mb-4">
      <input type="month" :value="t.mes" class="input !py-1 !w-44 text-sm" @change="router.get('/contable/indicadores', { mes: $event.target.value })" /><span class="text-sm text-marca-muted">Mes de corte: {{ t.periodo }}. Los acumulados son los 12 meses hasta ahí.</span>
    </div>

    <div v-if="t.salud.length" class="card mb-4 border-amber-200 bg-amber-50/60" data-e2e="salud">
      <p class="font-bold text-sm mb-1">Antes de leer los números</p>
      <div class="flex flex-wrap gap-2"><Link v-for="s in t.salud" :key="s.texto" :href="s.url" class="text-sm underline text-amber-800">{{ s.texto }}</Link></div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <Kpi label="Ventas del mes" :valor="moneda(k.ventas_mes, 0)" :nota="k.ventas_var !== null ? (k.ventas_var >= 0 ? '+' : '') + k.ventas_var + ' % vs mes anterior' : 'sin mes anterior'" :tono="k.ventas_var !== null && k.ventas_var < 0 ? 'carmin' : ''" />
      <Kpi label="Margen bruto 12 m" :valor="k.margen_bruto !== null ? k.margen_bruto + ' %' : '—'" :nota="`ventas ${moneda(k.ventas_12, 0)} · compras ${moneda(k.compras_12, 0)}`" />
      <Kpi label="Resultado aproximado 12 m" :valor="moneda(k.resultado_12, 0)" nota="ventas − compras − gastos (sin sueldos ni amortizaciones)" :tono="k.resultado_12 < 0 ? 'carmin' : 'verde'" />
      <Kpi label="Gastos sobre ventas" :valor="k.gastos_sobre_ventas !== null ? k.gastos_sobre_ventas + ' %' : '—'" :nota="`gastos 12 m ${moneda(k.gastos_12, 0)}`" />
      <Kpi label="Días de cobro" :valor="k.dias_cobro !== null ? k.dias_cobro + ' días' : '—'" :nota="`por cobrar ${moneda(k.por_cobrar, 0)} · vencido ${moneda(k.vencido, 0)}`" :tono="k.dias_cobro > 60 ? 'carmin' : ''" />
      <Kpi label="Días de pago" :valor="k.dias_pago !== null ? k.dias_pago + ' días' : '—'" :nota="`por pagar ${moneda(k.por_pagar, 0)}`" />
      <Kpi label="Liquidez" :valor="k.liquidez !== null ? k.liquidez : '—'" :nota="`disponible ${moneda(k.disponible, 0)} · capital de trabajo ${moneda(k.capital_trabajo, 0)}`" :tono="k.liquidez !== null && k.liquidez < 1 ? 'carmin' : 'verde'" />
      <Kpi label="IVA" :valor="moneda(k.iva_mes, 0)" :nota="`12 m ${moneda(k.iva_12, 0)} · ${k.presion_iva !== null ? k.presion_iva + ' % de las ventas' : ''}`" />
    </div>

    <div class="card p-0 overflow-x-auto">
      <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Mes a mes</h2><p class="text-xs text-marca-muted">Netos sin IVA. Las barras comparan ventas (ciruela) y compras más gastos (gris) contra el mejor mes.</p></div>
      <table class="table text-xs" data-e2e="serie">
        <thead><tr><th>Mes</th><th class="w-64"></th><th class="text-right">Ventas</th><th class="text-right">Compras</th><th class="text-right">Gastos</th><th class="text-right">IVA</th><th class="text-right">Cobrado</th><th class="text-right">Resultado aprox.</th></tr></thead>
        <tbody>
          <tr v-for="m in t.serie" :key="m.mes">
            <td class="font-semibold whitespace-nowrap">{{ m.label }}</td>
            <td><div class="space-y-0.5"><div class="h-2 rounded bg-violeta" :style="{ width: barra(m.ventas_neto) }"></div><div class="h-2 rounded bg-gris-light" :style="{ width: barra(m.compras_neto + m.gastos) }"></div></div></td>
            <td class="text-right tabular-nums">{{ moneda(m.ventas_neto, 0) }}</td><td class="text-right tabular-nums">{{ moneda(m.compras_neto, 0) }}</td><td class="text-right tabular-nums">{{ moneda(m.gastos, 0) }}</td>
            <td class="text-right tabular-nums" :class="m.iva_debito - m.iva_credito > 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(m.iva_debito - m.iva_credito, 0) }}</td>
            <td class="text-right tabular-nums">{{ moneda(m.cobrado, 0) }}</td>
            <td class="text-right tabular-nums font-semibold" :class="m.ventas_neto - m.compras_neto - m.gastos < 0 ? 'text-carmin' : ''">{{ moneda(m.ventas_neto - m.compras_neto - m.gastos, 0) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>

<script setup>
import { h, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ tablero: Object })
const t = props.tablero, k = t.kpis
const max = computed(() => Math.max(1, ...t.serie.map(m => Math.max(m.ventas_neto, m.compras_neto + m.gastos))))
const barra = v => Math.max(0, Math.round(v / max.value * 100)) + '%'
const Kpi = (p) => h('div', { class: 'card py-3' }, [h('p', { class: 'text-[11px] font-bold uppercase tracking-widest text-marca-muted' }, p.label), h('p', { class: 'text-xl font-extrabold tabular-nums ' + (p.tono === 'carmin' ? 'text-carmin' : p.tono === 'verde' ? 'text-emerald-700' : '') }, String(p.valor)), h('p', { class: 'text-[11px] text-marca-muted truncate', title: p.nota }, p.nota)])
Kpi.props = ['label', 'valor', 'nota', 'tono']
</script>
