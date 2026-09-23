<template>
  <AppLayout titulo="Comprobantes">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <h1 class="page-title">Comprobantes</h1>
        <p class="page-subtitle">Facturas, notas de crédito y débito, remitos y presupuestos de venta.</p>
      </div>
      <div v-if="puede('comprobantes', 'crear')" class="flex gap-2">
        <Link href="/comprobantes/pedidos" class="btn-secondary">Pedidos web</Link>
        <Link href="/comprobantes/pendientes" class="btn-secondary">Pendientes</Link>
        <Link href="/comprobantes/abonos" class="btn-secondary">Abonos</Link>
        <Link href="/comprobantes/lote" class="btn-secondary">Facturación por lote</Link>
        <Link href="/comprobantes/nuevo?tipo=PRE" class="btn-secondary">Presupuesto</Link>
        <Link href="/comprobantes/nuevo" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nueva factura</Link>
      </div>
    </div>

    <div v-if="!afipConfigurado" class="mb-4 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm flex flex-wrap items-center gap-2">
      <Icono nombre="alert" clase="w-4 h-4" /> Sin certificado AFIP: las facturas se emiten <b>simuladas</b> (sin CAE). <Link href="/configuracion/puntos-venta" class="underline font-semibold">Configurar AFIP</Link>
    </div>

    <div class="flex gap-1 overflow-x-auto mb-4 bg-white border border-marca-borde rounded-full p-1 w-fit max-w-full">
      <button v-for="g in grupos" :key="g.key" @click="f.grupo = g.key; filtrar()" class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap transition" :class="(f.grupo || 'todos') === g.key ? 'bg-carmin text-white' : 'text-marca-muted hover:text-marca-texto'">{{ g.label }}</button>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input lg:col-span-2" placeholder="Buscar por número, cliente o nota…" />
      <select v-model="f.estado" @change="filtrar" class="input"><option value="">Todo estado</option><option value="borrador">Borradores</option><option value="emitido">Emitidos</option><option value="pendiente">Pendientes de cobro</option><option value="vencido">Vencidos</option><option value="anulado">Anulados</option></select>
      <select v-model="f.contact_id" @change="filtrar" class="input"><option value="">Todos los clientes</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }}</option></select>
      <input v-model="f.desde" @change="filtrar" type="date" class="input" />
      <input v-model="f.hasta" @change="filtrar" type="date" class="input" />
    </div>

    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Comprobantes</p><p class="text-xl font-extrabold tabular-nums">{{ entero(resumen.cantidad) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Total emitido</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.total, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Saldo por cobrar</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.saldo > 0 ? 'text-carmin' : ''">{{ moneda(resumen.saldo, 0) }}</p></div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Comprobante</th><th>Fecha</th><th>Cliente</th><th>Vence</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in lista.data" :key="c.id" class="cursor-pointer" @click="$inertia.visit(`/comprobantes/${c.id}`)">
            <td>
              <div class="flex items-center gap-2">
                <span class="w-7 h-7 rounded-lg grid place-items-center text-xs font-extrabold" :class="letraClase(c)">{{ c.letra }}</span>
                <div><p class="font-semibold leading-tight">{{ c.nombre }}</p><p class="text-xs text-marca-muted tabular-nums">{{ c.numero ?? 'Borrador' }} <span v-if="c.es_acopio" class="badge bg-violeta-light text-violeta ml-1">Acopio</span></p></div>
              </div>
            </td>
            <td class="text-marca-muted tabular-nums">{{ c.fecha }}</td>
            <td class="font-medium">{{ c.cliente ?? 'Consumidor final' }}</td>
            <td class="tabular-nums" :class="c.vencido ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ c.grupo === 'factura' || c.grupo === 'nd' ? c.fecha_vto : '' }}</td>
            <td class="text-right font-semibold tabular-nums">{{ moneda(c.total) }}</td>
            <td class="text-right tabular-nums" :class="c.saldo > 0 ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ c.estado_cobro !== 'na' ? moneda(c.saldo) : '' }}</td>
            <td>
              <span class="badge" :class="estadoComprobante[c.estado].clase">{{ estadoComprobante[c.estado].label }}</span>
              <span v-if="c.estado === 'emitido' && c.estado_cobro !== 'na'" class="badge ml-1" :class="estadoCobro[c.estado_cobro].clase">{{ estadoCobro[c.estado_cobro].label }}</span>
              <span v-if="c.afip_estado === 'simulado'" class="badge ml-1 bg-amber-50 text-amber-700">Sin CAE</span>
              <span v-else-if="c.afip_estado === 'pendiente'" class="badge ml-1 bg-carmin-light text-carmin">Pendiente CAE</span>
            </td>
            <td class="text-right"><a :href="`/comprobantes/${c.id}/imprimir`" target="_blank" @click.stop class="btn-ghost !px-2 text-xs" title="Imprimir">PDF</a></td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="8" class="text-center text-marca-muted py-10">No hay comprobantes con estos filtros.</td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda, entero, estadoCobro, estadoComprobante } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const { puede } = usePermisos()

const props = defineProps({ lista: Object, resumen: Object, filtros: Object, grupos: Array, clientes: Array, afipConfigurado: Boolean })
const f = reactive({ grupo: props.filtros.grupo ?? 'todos', estado: props.filtros.estado ?? '', contact_id: props.filtros.contact_id ?? '', desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '', buscar: props.filtros.buscar ?? '' })
function filtrar() { router.get('/comprobantes', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
const letraClase = c => c.estado === 'anulado' ? 'bg-gris-light text-marca-muted line-through' : ({ factura: 'bg-carmin text-white', nc: 'bg-violeta text-white', nd: 'bg-magenta text-white', remito: 'bg-lavanda text-violeta', presupuesto: 'bg-gris-light text-marca-texto' }[c.grupo])
</script>
