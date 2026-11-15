<template>
  <AppLayout titulo="Posición IVA">
    <!-- Fase 99: la posición mensual de IVA como la arma el contador, con el saldo a favor arrastrado. -->
    <PageHeader titulo="Posición IVA" compacto :volver="{ href: '/contable/iva', label: 'Libros IVA' }"
      :principal="puede('contable','crear') && p.estado !== 'cerrada' ? { label: 'Cerrar el mes', click: () => guardar(true) } : null"
      :secundaria="{ label: 'Copiar para el contador', click: copiar }"
      :mas="[{ label: 'Bajar el resumen (.txt)', href: `/contable/iva/posicion/texto?mes=${p.mes}` }, p.estado === 'cerrada' && puede('contable','anular') ? { label: 'Reabrir', click: () => router.post(`/contable/iva/posicion/${p.id}/reabrir`, {}, { preserveScroll: true }) } : null]">
      <template #subtitulo>Débito fiscal de las ventas menos crédito fiscal de las compras, menos percepciones y retenciones de IVA sufridas, con el saldo a favor del mes anterior. Es la cuenta que presenta el contador; él la confirma.</template>
    </PageHeader>

    <div class="flex flex-wrap items-center gap-2 mb-4">
      <button class="btn-ghost !py-1 text-xs" @click="irMes(-1)" aria-label="Mes anterior">‹</button><input type="month" :value="p.mes" class="input !py-1 !w-44 text-sm" @change="router.get('/contable/iva/posicion', { mes: $event.target.value })" /><button class="btn-ghost !py-1 text-xs" @click="irMes(1)" aria-label="Mes siguiente">›</button>
      <span class="badge" :class="{ cerrada: 'bg-emerald-50 text-emerald-700', borrador: 'bg-amber-50 text-amber-700', sin_guardar: 'bg-gris-light text-marca-muted' }[p.estado]">{{ { cerrada: 'Cerrada ' + (p.cerrada_en || ''), borrador: 'Borrador', sin_guardar: 'Sin cerrar' }[p.estado] }}</span>
      <span v-if="p.anterior_mes && p.anterior_estado !== 'cerrada'" class="text-xs text-amber-700">El mes anterior ({{ p.anterior_mes }}) no está cerrado: el arrastre puede cambiar.</span>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Débito fiscal</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(p.debito, 0) }}</p><p class="text-[11px] text-marca-muted">{{ p.ventas.comprobantes }} comprobantes de venta</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Crédito fiscal</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ moneda(p.credito, 0) }}</p><p class="text-[11px] text-marca-muted">{{ p.compras.comprobantes }} compras</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Perc. y ret. sufridas</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(p.percepciones + p.retenciones, 0) }}</p><p class="text-[11px] text-marca-muted">libre disponibilidad</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Saldo anterior</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(p.saldo_anterior_tecnico + p.saldo_anterior_libre, 0) }}</p><p class="text-[11px] text-marca-muted">técnico {{ moneda(p.saldo_anterior_tecnico, 0) }} · libre {{ moneda(p.saldo_anterior_libre, 0) }}</p></div>
      <div class="card py-3" :class="p.resultado === 'a_pagar' ? 'ring-2 ring-carmin/40' : 'ring-2 ring-emerald-300/60'" data-e2e="resultado">
        <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ p.resultado === 'a_pagar' ? 'A pagar' : 'A favor' }}</p>
        <p class="text-xl font-extrabold tabular-nums" :class="p.resultado === 'a_pagar' ? 'text-carmin' : 'text-emerald-700'">{{ moneda(p.resultado === 'a_pagar' ? p.a_pagar : p.saldo_tecnico + p.saldo_libre, 0) }}</p>
        <p class="text-[11px] text-marca-muted">{{ p.resultado === 'a_pagar' ? 'vence el ' + fecha(p.vencimiento) : 'pasa al mes siguiente' }}</p>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-0 overflow-x-auto">
          <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">La cuenta del mes</h2></div>
          <table class="table text-sm">
            <tbody>
              <tr><td>Débito fiscal (IVA de las ventas)</td><td class="text-right tabular-nums">{{ moneda(p.debito) }}</td></tr>
              <tr class="text-xs text-marca-muted"><td class="pl-8">21 % · 10,5 % · 27 %</td><td class="text-right tabular-nums">{{ moneda(p.ventas['21']) }} · {{ moneda(p.ventas['10.5']) }} · {{ moneda(p.ventas['27']) }}</td></tr>
              <tr v-if="p.ajuste"><td>Ajuste del contador <span class="text-xs text-marca-muted">{{ p.ajuste_nota }}</span></td><td class="text-right tabular-nums">{{ moneda(p.ajuste) }}</td></tr>
              <tr><td>− Crédito fiscal (IVA de las compras)</td><td class="text-right tabular-nums">− {{ moneda(p.credito) }}</td></tr>
              <tr class="text-xs text-marca-muted"><td class="pl-8">21 % · 10,5 % · 27 %</td><td class="text-right tabular-nums">{{ moneda(p.compras['21']) }} · {{ moneda(p.compras['10.5']) }} · {{ moneda(p.compras['27']) }}</td></tr>
              <tr><td>− Saldo técnico a favor del mes anterior</td><td class="text-right tabular-nums">− {{ moneda(p.saldo_anterior_tecnico) }}</td></tr>
              <tr class="font-bold bg-marca-fondo"><td>= Saldo técnico del mes</td><td class="text-right tabular-nums" :class="p.tecnico > 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(p.tecnico) }}</td></tr>
              <tr><td>Libre disponibilidad: saldo anterior + percepciones de IVA sufridas + retenciones de IVA sufridas</td><td class="text-right tabular-nums">{{ moneda(p.libre_disponible) }}</td></tr>
              <tr class="text-xs text-marca-muted"><td class="pl-8">anterior · percepciones · retenciones</td><td class="text-right tabular-nums">{{ moneda(p.saldo_anterior_libre) }} · {{ moneda(p.percepciones) }} · {{ moneda(p.retenciones) }}</td></tr>
              <tr class="font-extrabold text-base"><td>{{ p.a_pagar > 0 ? 'A PAGAR' : 'Sin impuesto a pagar' }}</td><td class="text-right tabular-nums" :class="p.a_pagar > 0 ? 'text-carmin' : ''">{{ moneda(p.a_pagar) }}</td></tr>
              <tr><td>Saldo a favor que pasa al mes siguiente</td><td class="text-right tabular-nums text-emerald-700">técnico {{ moneda(p.saldo_tecnico) }} · libre {{ moneda(p.saldo_libre) }}</td></tr>
            </tbody>
          </table>
        </div>

        <div v-if="puede('contable','crear') && p.estado !== 'cerrada'" class="card" data-e2e="ajuste">
          <h2 class="font-bold mb-1">Ajuste del contador</h2>
          <p class="text-xs text-marca-muted mb-3">Para lo que el sistema no sabe: crédito fiscal no computable, un comprobante que no corresponde, diferencias con el libro. Positivo suma al débito; negativo suma al crédito.</p>
          <div class="grid sm:grid-cols-[160px_1fr_auto] gap-2 items-end">
            <div><label class="label">Importe</label><input v-model.number="f.ajuste" type="number" step="0.01" class="input" /></div>
            <div><label class="label">Motivo</label><input v-model="f.ajuste_nota" class="input" placeholder="Crédito no computable, nota de crédito pendiente…" /></div>
            <button class="btn-secondary" :disabled="f.processing" @click="guardar(false)">Guardar</button>
          </div>
        </div>

        <div class="card">
          <h2 class="font-bold mb-1">Para mandarle al contador</h2>
          <pre class="text-xs whitespace-pre-wrap bg-marca-fondo rounded-xl p-3 font-mono" data-e2e="texto">{{ texto }}</pre>
          <p v-if="copiado" class="text-xs text-emerald-700 mt-1">Copiado.</p>
        </div>
      </div>

      <div class="card p-0 overflow-hidden">
        <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Últimos meses</h2><p class="text-xs text-marca-muted">Cerrá cada mes para que el arrastre quede fijo.</p></div>
        <table class="table text-xs">
          <thead><tr><th>Mes</th><th class="text-right">A pagar</th><th class="text-right">A favor</th><th></th></tr></thead>
          <tbody>
            <tr v-for="h in historial" :key="h.id" class="cursor-pointer" @click="router.get('/contable/iva/posicion', { mes: h.mes })">
              <td>{{ h.periodo }}</td><td class="text-right tabular-nums" :class="h.a_pagar ? 'text-carmin' : ''">{{ h.a_pagar ? moneda(h.a_pagar, 0) : '—' }}</td><td class="text-right tabular-nums text-emerald-700">{{ h.saldo_tecnico + h.saldo_libre ? moneda(h.saldo_tecnico + h.saldo_libre, 0) : '—' }}</td>
              <td><span class="badge" :class="h.estado === 'cerrada' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ h.estado === 'cerrada' ? 'cerrada' : 'borrador' }}</span></td>
            </tr>
            <tr v-if="!historial.length"><td colspan="4" class="text-center text-marca-muted py-6">Todavía no cerraste ningún mes.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const { puede } = usePermisos()
const props = defineProps({ posicion: Object, texto: String, historial: Array })
const p = props.posicion
const f = useForm({ mes: p.mes, ajuste: p.ajuste || 0, ajuste_nota: p.ajuste_nota || '', cerrar: false })
function guardar(cerrar) { f.cerrar = cerrar; f.post('/contable/iva/posicion', { preserveScroll: true }) }
function irMes(d) { const [y, m] = p.mes.split('-').map(Number); const n = new Date(y, m - 1 + d, 1); router.get('/contable/iva/posicion', { mes: `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}` }) }
const fecha = iso => iso ? iso.split('-').reverse().join('/') : ''
const copiado = ref(false)
function copiar() { navigator.clipboard?.writeText(props.texto).then(() => { copiado.value = true; setTimeout(() => (copiado.value = false), 2000) }).catch(() => {}) }
</script>
