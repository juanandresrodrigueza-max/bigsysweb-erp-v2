<template>
  <AppLayout titulo="Contable">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Contable</h1><p class="page-subtitle">Los asientos se arman solos con cada venta, compra, cobro, pago y movimiento de fondos.</p></div>
      <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" />
    </div>
    <ContableTabs />

    <div v-if="pendientes" class="mb-4 px-4 py-3 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-3">
      <Icono nombre="alert" clase="w-5 h-5 shrink-0" /><span>Hay <b>{{ pendientes }}</b> operaciones sin asiento.</span>
      <Link v-if="puede('contable','crear')" href="/contable/sincronizar" method="post" as="button" preserve-scroll class="ml-auto btn-primary !py-1 text-xs">Generar asientos</Link>
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <div class="card lg:col-span-2">
        <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Resultado del período</h2><Link href="/contable/balance" class="text-xs font-semibold text-carmin">Ver detalle</Link></div>
        <div class="grid grid-cols-3 gap-3 mb-4">
          <div class="p-3 rounded-xl bg-emerald-50"><p class="text-[11px] font-bold uppercase tracking-widest text-emerald-700">Ingresos</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ moneda(resultado.total_ingresos, 0) }}</p></div>
          <div class="p-3 rounded-xl bg-carmin-light"><p class="text-[11px] font-bold uppercase tracking-widest text-carmin">Egresos</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(resultado.total_egresos, 0) }}</p></div>
          <div class="p-3 rounded-xl" :class="resultado.resultado >= 0 ? 'bg-violeta-grad text-white' : 'bg-gris-light'"><p class="text-[11px] font-bold uppercase tracking-widest opacity-80">Resultado</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resultado.resultado, 0) }}</p><p class="text-[11px] opacity-80">{{ resultado.total_ingresos ? Math.round(resultado.resultado / resultado.total_ingresos * 100) + '% sobre ingresos' : '' }}</p></div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4 text-sm">
          <div><p class="label">Ingresos</p><div v-for="i in resultado.ingresos" :key="i.id" class="flex justify-between py-1 border-t border-marca-borde/60"><Link :href="`/contable/mayor?cuenta=${i.id}&desde=${periodo.desde}&hasta=${periodo.hasta}`" class="hover:text-carmin">{{ i.nombre }}</Link><span class="tabular-nums">{{ moneda(i.monto, 0) }}</span></div><p v-if="!resultado.ingresos.length" class="text-marca-muted">Sin ingresos en el período.</p></div>
          <div><p class="label">Egresos</p><div v-for="e in resultado.egresos" :key="e.id" class="flex justify-between py-1 border-t border-marca-borde/60"><Link :href="`/contable/mayor?cuenta=${e.id}&desde=${periodo.desde}&hasta=${periodo.hasta}`" class="hover:text-carmin">{{ e.nombre }}</Link><span class="tabular-nums">{{ moneda(e.monto, 0) }}</span></div><p v-if="!resultado.egresos.length" class="text-marca-muted">Sin egresos en el período.</p></div>
        </div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-3">Últimos 6 meses</h2>
        <div class="flex items-end gap-2 h-40">
          <div v-for="m in meses" :key="m.mes" class="flex-1 flex flex-col items-center justify-end h-full gap-1">
            <div class="w-full flex items-end gap-0.5 flex-1">
              <div class="flex-1 rounded-t bg-emerald-500" :style="{ height: (m.ingresos / maxMes * 100) + '%' }" :title="'Ingresos ' + moneda(m.ingresos, 0)"></div>
              <div class="flex-1 rounded-t bg-carmin" :style="{ height: (m.egresos / maxMes * 100) + '%' }" :title="'Egresos ' + moneda(m.egresos, 0)"></div>
            </div>
            <span class="text-[10px] text-marca-muted uppercase">{{ m.mes }}</span>
            <span class="text-[10px] font-bold tabular-nums" :class="m.resultado >= 0 ? 'text-emerald-700' : 'text-carmin'">{{ moneda(m.resultado / 1000, 0) }}k</span>
          </div>
        </div>
        <p class="text-[11px] text-marca-muted mt-2"><span class="inline-block w-2 h-2 rounded-sm bg-emerald-500"></span> ingresos · <span class="inline-block w-2 h-2 rounded-sm bg-carmin"></span> egresos · abajo el resultado</p>
      </div>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
      <div class="card">
        <h2 class="font-bold mb-2">Lo que tenemos</h2>
        <div class="text-sm">
          <div class="flex justify-between py-1.5 border-t border-marca-borde/60 first:border-0"><span>Caja, bancos y billeteras</span><b class="tabular-nums">{{ moneda(posicion.caja, 0) }}</b></div>
          <div class="flex justify-between py-1.5 border-t border-marca-borde/60"><span>Cheques de terceros en cartera</span><b class="tabular-nums">{{ moneda(posicion.cheques, 0) }}</b></div>
          <div class="flex justify-between py-1.5 border-t border-marca-borde/60"><span>Nos deben los clientes</span><b class="tabular-nums">{{ moneda(posicion.deudores, 0) }}</b></div>
          <div class="flex justify-between py-1.5 border-t border-marca-borde/60"><span>Mercaderías (a costo)</span><b class="tabular-nums">{{ moneda(posicion.mercaderias, 0) }}</b></div>
        </div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-2">Lo que debemos</h2>
        <div class="text-sm">
          <div class="flex justify-between py-1.5 border-t border-marca-borde/60 first:border-0"><span>A proveedores</span><b class="tabular-nums text-carmin">{{ moneda(posicion.proveedores, 0) }}</b></div>
          <div class="flex justify-between py-1.5 border-t border-marca-borde/60"><span>Cheques propios entregados</span><b class="tabular-nums text-carmin">{{ moneda(posicion.cheques_propios, 0) }}</b></div>
          <div class="flex justify-between py-1.5 border-t border-marca-borde/60"><span>Posición IVA (débito − crédito)</span><b class="tabular-nums" :class="posicion.iva > 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(posicion.iva, 0) }}</b></div>
        </div>
        <p class="text-xs text-marca-muted mt-3">{{ asientos }} asientos registrados<span v-if="ultimo"> · último el {{ ultimo }}</span>. <Link href="/contable/asientos" class="text-carmin font-semibold">Ver asientos</Link></p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import Icono from '@/Components/Icono.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ periodo: Object, resultado: Object, meses: Array, posicion: Object, asientos: Number, ultimo: String, pendientes: Number })
const { puede } = usePermisos()
const maxMes = computed(() => Math.max(1, ...props.meses.flatMap(m => [m.ingresos, m.egresos])))
</script>
