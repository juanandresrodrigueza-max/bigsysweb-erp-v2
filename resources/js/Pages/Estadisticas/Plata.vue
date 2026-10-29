<template>
  <AppLayout titulo="Estadísticas · Plata">
    <!-- Fase 33.5: qué entra y cuándo, la cartera de cheques por semana, la cobranza por cobrador y el IVA del mes. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
      <div><h1 class="page-title">Plata</h1><p class="page-subtitle">Qué entra y cuándo, quién cobra y cuánto IVA te da el mes. Son cuentas sobre lo cargado (ⓘ dice cómo).</p></div>
      <div class="flex flex-wrap items-center gap-2"><span class="text-xs text-marca-muted">Cobranza por cobrador:</span><PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" :extra="{ mes }" /></div>
    </div>
    <EstadisticasTabs activa="plata" />

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <div class="card lg:col-span-2" data-e2e="entra">
        <h2 class="font-bold">Qué entra y cuándo <span class="text-marca-muted cursor-help font-normal text-sm" :title="entra.como">ⓘ</span></h2>
        <p class="text-3xl font-black tabular-nums mt-1">{{ moneda(entra.total, 0) }} <span class="text-sm font-normal text-marca-muted">{{ entra.cantidad_facturas }} facturas con saldo · {{ entra.cantidad_cheques }} cheques en cartera</span></p>
        <div class="grid grid-cols-5 gap-2 mt-3 text-sm">
          <div v-for="(t, k) in tramos" :key="k" class="rounded-xl p-2.5" :class="k === 'vencido' ? 'bg-red-50' : 'bg-gris-light'">
            <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ t }}</p>
            <p class="font-extrabold tabular-nums" :class="k === 'vencido' && entra.tramos[k] > 0 ? 'text-carmin' : ''">{{ moneda(entra.tramos[k], 0) }}</p>
            <p class="text-[11px] text-marca-muted tabular-nums">fact. {{ moneda(entra.facturas[k], 0) }}<br />cheq. {{ moneda(entra.cheques[k], 0) }}</p>
          </div>
        </div>
        <div class="flex h-3 rounded-full overflow-hidden mt-3"><div v-for="(t, k) in tramos" :key="k" :class="{ vencido: 'bg-carmin', d30: 'bg-emerald-500', d60: 'bg-emerald-300', d90: 'bg-amber-300', mas90: 'bg-marca-borde' }[k]" :style="{ width: (entra.total ? entra.tramos[k] / entra.total * 100 : 0) + '%' }" :title="`${t}: ${moneda(entra.tramos[k], 0)}`"></div></div>
      </div>
      <div class="card" data-e2e="iva">
        <div class="flex items-center justify-between"><h2 class="font-bold">IVA de {{ iva.mes }} <span class="text-marca-muted cursor-help font-normal text-sm" :title="iva.como">ⓘ</span></h2><input type="month" :value="mes" class="input !py-1 !text-xs w-auto" @change="$inertia.get('/estadisticas/plata', { ...periodo, mes: $event.target.value }, { preserveState: true, replace: true })" /></div>
        <p class="text-3xl font-black tabular-nums mt-1" :class="iva.posicion > 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(Math.abs(iva.posicion), 0) }} <span class="text-sm font-normal text-marca-muted">{{ iva.posicion > 0 ? 'a pagar' : 'a favor' }}</span></p>
        <dl class="text-sm mt-2 space-y-1">
          <div class="flex justify-between"><dt>Débito fiscal (ventas)</dt><dd class="tabular-nums">{{ moneda(iva.debito, 0) }}</dd></div>
          <div class="flex justify-between"><dt>Crédito fiscal (compras)</dt><dd class="tabular-nums">− {{ moneda(iva.credito, 0) }}</dd></div>
          <div class="flex justify-between text-marca-muted"><dt>Percepciones de IVA sufridas</dt><dd class="tabular-nums">− {{ moneda(iva.percepciones, 0) }}</dd></div>
          <div class="flex justify-between text-marca-muted"><dt>Retenciones de IVA al cobrar</dt><dd class="tabular-nums">− {{ moneda(iva.retenciones, 0) }}</dd></div>
        </dl>
        <p class="text-[11px] text-marca-muted mt-2">Ventas netas {{ moneda(iva.ventas_neto, 0) }} · compras netas {{ moneda(iva.compras_neto, 0) }}. No es una declaración jurada.</p>
      </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
      <div class="card" data-e2e="cartera">
        <h2 class="font-bold">Cartera por semana de depósito <span class="text-marca-muted cursor-help font-normal text-sm" :title="cartera.como">ⓘ</span></h2>
        <p class="text-2xl font-black tabular-nums mt-1">{{ moneda(cartera.total, 0) }} <span class="text-sm font-normal text-marca-muted">en cheques de terceros</span></p>
        <div v-for="s in cartera.semanas" :key="s.semana" class="border-t border-marca-borde py-1.5 text-sm">
          <details><summary class="flex justify-between gap-2 cursor-pointer"><span :class="s.semana === 'vencidos' ? 'text-carmin font-semibold' : ''">{{ s.semana === 'vencidos' ? 'Ya se pueden depositar (fecha pasada)' : `Semana del ${fecha(s.desde)} al ${fecha(s.hasta)}` }} <span class="text-xs text-marca-muted">· {{ s.cantidad }} cheque{{ s.cantidad === 1 ? '' : 's' }}</span></span><b class="tabular-nums">{{ moneda(s.monto, 0) }}</b></summary>
            <div v-for="c in s.cheques" :key="c.id" class="flex justify-between gap-2 text-xs text-marca-muted pl-3 py-0.5"><span>{{ fecha(c.fecha_pago) }} · {{ c.banco }} {{ c.numero }} · {{ c.emisor }}</span><span class="tabular-nums">{{ moneda(c.monto, 0) }}</span></div>
          </details>
        </div>
        <p v-if="!cartera.semanas.length" class="text-sm text-marca-muted py-4 text-center">No hay cheques en cartera.</p>
      </div>
      <div class="card" data-e2e="cobradores">
        <h2 class="font-bold">Cobranza por cobrador <span class="text-marca-muted cursor-help font-normal text-sm" :title="cobradores.como">ⓘ</span></h2>
        <p class="text-2xl font-black tabular-nums mt-1">{{ moneda(cobradores.total, 0) }} <span class="text-sm font-normal text-marca-muted">cobrados en el período</span></p>
        <table class="table text-sm mt-1"><thead><tr><th>Cobrador</th><th class="text-right">Cobrado</th><th class="text-right">Parte</th><th class="text-right">Recibos</th><th class="text-right">Clientes</th></tr></thead>
          <tbody><tr v-for="c in cobradores.items" :key="c.nombre"><td class="font-medium">{{ c.nombre }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(c.monto, 0) }}</td><td class="text-right tabular-nums">{{ c.parte }}%</td><td class="text-right tabular-nums">{{ c.recibos }}</td><td class="text-right tabular-nums">{{ c.clientes }}</td></tr>
          <tr v-if="!cobradores.items.length"><td colspan="5" class="text-center text-marca-muted py-6">Sin recibos en el período.</td></tr></tbody></table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadisticasTabs from '@/Components/EstadisticasTabs.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { moneda } from '@/util/formato'
defineProps({ periodo: Object, mes: String, entra: Object, cartera: Object, cobradores: Object, iva: Object })
const tramos = { vencido: 'Vencido', d30: 'Hasta 30 días', d60: '31 a 60', d90: '61 a 90', mas90: 'Más de 90' }
const fecha = f => { if (!f) return ''; const [y, m, d] = f.split('-'); return `${d}/${m}` }
</script>
