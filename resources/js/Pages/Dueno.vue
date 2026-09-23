<template>
  <AppLayout titulo="Mi negocio">
    <div class="max-w-lg mx-auto space-y-3">
      <div class="flex items-end justify-between gap-2">
        <div><h1 class="page-title">Mi negocio</h1><p class="page-subtitle">{{ fecha }} · lo importante, de un vistazo.</p></div>
        <Link href="/dashboard" class="text-xs text-violeta font-semibold">Panel completo →</Link>
      </div>

      <!-- Hoy -->
      <div class="rounded-2xl p-4 text-white bg-marca-grad shadow-pop">
        <p class="text-[11px] font-bold uppercase tracking-widest opacity-80">Ventas de hoy</p>
        <p class="text-3xl font-extrabold tabular-nums leading-tight">{{ moneda(hoy.ventas, 0) }}</p>
        <p class="text-sm opacity-90">{{ hoy.facturas }} facturas · cobrado {{ moneda(hoy.cobrado, 0) }}<span v-if="hoy.vs_ayer !== null"> · {{ hoy.vs_ayer >= 0 ? '+' : '' }}{{ hoy.vs_ayer }}% vs ayer</span></p>
        <p class="text-xs opacity-80 mt-1">Mes: {{ moneda(mes.ventas, 0) }}<span v-if="mes.vs_mes_anterior !== null"> ({{ mes.vs_mes_anterior >= 0 ? '+' : '' }}{{ mes.vs_mes_anterior }}% vs el mes pasado a esta altura)</span></p>
      </div>

      <!-- Acciones de un toque -->
      <div class="grid grid-cols-4 gap-2 text-center text-[11px] font-semibold">
        <Link href="/clientes/cobranzas" class="card !p-3 hover:border-carmin/40"><Icono nombre="wallet" clase="w-5 h-5 mx-auto text-carmin mb-1" />Cobrar</Link>
        <Link href="/fondos?nuevo=egreso" class="card !p-3 hover:border-carmin/40"><Icono nombre="receipt" clase="w-5 h-5 mx-auto text-violeta mb-1" />Gasto</Link>
        <Link href="/comprobantes/nuevo" class="card !p-3 hover:border-carmin/40"><Icono nombre="plus" clase="w-5 h-5 mx-auto text-magenta mb-1" />Factura</Link>
        <Link href="/estadisticas/rentabilidad" class="card !p-3 hover:border-carmin/40"><Icono nombre="chart" clase="w-5 h-5 mx-auto text-violeta mb-1" />Rentab.</Link>
      </div>

      <!-- Plata -->
      <div class="card">
        <div class="grid grid-cols-3 gap-2 text-center">
          <div><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">Caja</p><p class="font-extrabold tabular-nums">{{ moneda(fondos.caja, 0) }}</p></div>
          <div><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">Banco</p><p class="font-extrabold tabular-nums">{{ moneda(fondos.banco, 0) }}</p></div>
          <div><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">Billeteras</p><p class="font-extrabold tabular-nums">{{ moneda(fondos.billetera, 0) }}</p></div>
        </div>
        <div class="grid grid-cols-2 gap-2 mt-3 text-sm">
          <Link href="/clientes/cobranzas" class="p-2 rounded-xl bg-marca-fondo"><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">Por cobrar</p><p class="font-bold tabular-nums">{{ moneda(cobrar.por_cobrar, 0) }}</p><p v-if="cobrar.vencido > 0" class="text-xs text-carmin font-semibold">vencido {{ moneda(cobrar.vencido, 0) }} · {{ cobrar.deudores }} clientes</p></Link>
          <Link href="/proveedores?estado=deudores" class="p-2 rounded-xl bg-marca-fondo"><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">Por pagar</p><p class="font-bold tabular-nums">{{ moneda(pagar.total, 0) }}</p><p v-if="pagar.vencido > 0" class="text-xs text-carmin font-semibold">vencido {{ moneda(pagar.vencido, 0) }}</p></Link>
        </div>
      </div>

      <!-- Atender -->
      <div v-if="alertas.length || pedidos_web || pendientes_cae" class="card">
        <h2 class="font-bold mb-2">Para atender</h2>
        <Link v-if="pedidos_web" href="/comprobantes/pedidos" class="flex items-center gap-2 py-1.5 text-sm"><span class="w-2 h-2 rounded-full bg-violeta"></span>{{ pedidos_web }} pedido{{ pedidos_web > 1 ? 's' : '' }} web sin confirmar</Link>
        <Link v-if="pendientes_cae" href="/comprobantes?estado=emitido" class="flex items-center gap-2 py-1.5 text-sm"><span class="w-2 h-2 rounded-full bg-carmin"></span>{{ pendientes_cae }} comprobante{{ pendientes_cae > 1 ? 's' : '' }} pendiente{{ pendientes_cae > 1 ? 's' : '' }} de CAE</Link>
        <Link v-for="a in alertas" :key="a.id" :href="a.url || '/alertas'" class="flex items-center gap-2 py-1.5 text-sm"><span class="w-2 h-2 rounded-full shrink-0" :class="{ critica: 'bg-carmin', aviso: 'bg-amber-500', info: 'bg-violeta' }[a.severidad]"></span><span class="truncate">{{ a.titulo }}</span></Link>
      </div>

      <!-- Lo más vendido hoy -->
      <div class="card">
        <h2 class="font-bold mb-2">Lo más vendido hoy</h2>
        <div v-if="top_hoy.length" class="text-sm">
          <div v-for="t in top_hoy" :key="t.nombre" class="flex justify-between py-1 border-t border-marca-borde/60 first:border-0"><span class="truncate">{{ t.nombre }} <span class="text-marca-muted">× {{ cantidad(t.cantidad) }}</span></span><span class="tabular-nums font-semibold">{{ moneda(t.total, 0) }}</span></div>
        </div>
        <p v-else class="text-sm text-marca-muted">Todavía no se vendió nada hoy.</p>
      </div>

      <!-- Últimas ventas -->
      <div class="card">
        <h2 class="font-bold mb-2">Últimas ventas</h2>
        <Link v-for="v in ultimas" :key="v.id" :href="`/comprobantes/${v.id}`" class="flex items-center justify-between py-1.5 text-sm border-t border-marca-borde/60 first:border-0">
          <span class="min-w-0"><span class="font-medium truncate block">{{ v.cliente }}</span><span class="text-xs text-marca-muted">{{ v.tipo }} {{ v.numero ?? '' }} · {{ v.hora }}</span></span>
          <span class="tabular-nums font-semibold" :class="v.estado_cobro === 'pendiente' ? 'text-carmin' : ''">{{ moneda(v.total, 0) }}</span>
        </Link>
        <p v-if="!ultimas.length" class="text-sm text-marca-muted">Sin ventas todavía.</p>
      </div>
      <p class="text-[11px] text-marca-muted text-center pb-4">Agregá esta pantalla a la pantalla de inicio del celular: en el navegador, "Agregar a inicio". Preguntale al asistente (botón ✦) y pedile que cobre, registre gastos o mande recordatorios.</p>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import { moneda, cantidad } from '@/util/formato'
defineProps({ hoy: Object, mes: Object, fondos: Object, cobrar: Object, pagar: Object, pedidos_web: Number, alertas: Array, top_hoy: Array, ultimas: Array, pendientes_cae: Number })
const fecha = new Date().toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' })
</script>
