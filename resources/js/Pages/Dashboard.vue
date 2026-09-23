<template>
  <AppLayout titulo="Inicio">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-6">
      <div>
        <h1 class="page-title">Hola, {{ nombre }}</h1>
        <p class="page-subtitle">Así va {{ $page.props.sucursales?.actual?.nombre ?? 'tu negocio' }} {{ etiquetaPeriodo }}.</p>
      </div>
      <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1">
        <button v-for="p in periodos" :key="p.key" @click="cambiar(p.key)" class="px-3 py-1 rounded-full text-xs font-semibold transition" :class="periodo === p.key ? 'bg-carmin text-white' : 'text-marca-muted hover:text-marca-texto'">{{ p.label }}</button>
      </div>
    </div>

    <!-- Panel por rol: lo que esa persona tiene que mirar hoy -->
    <div v-if="panel" class="card mb-6 border-violeta/30">
      <template v-if="panel.tipo === 'vendedor'">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3"><h2 class="font-bold">Mis ventas {{ etiquetaPeriodo }}</h2><Link href="/comprobantes/nuevo" class="btn-primary !py-1 text-xs">Nueva factura</Link></div>
        <div class="grid grid-cols-3 gap-3 mb-3">
          <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vendido</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(panel.ventas, 0) }}</p></div>
          <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Facturas</p><p class="text-lg font-extrabold tabular-nums">{{ panel.facturas }}</p></div>
          <div class="p-3 rounded-xl bg-violeta-light"><p class="text-[11px] font-bold uppercase tracking-widest text-violeta">Comisión</p><p class="text-lg font-extrabold tabular-nums text-violeta">{{ panel.comision === null ? '—' : moneda(panel.comision, 0) }}</p></div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4 text-sm">
          <div><p class="text-xs font-bold uppercase tracking-widest text-marca-muted mb-1">Presupuestos sin respuesta</p><Link v-for="p in panel.presupuestos" :key="p.id" :href="`/comprobantes/${p.id}`" class="flex justify-between py-1 border-t border-marca-borde/60 hover:text-carmin"><span>{{ p.cliente }} · {{ p.numero }}</span><span class="tabular-nums">{{ moneda(p.total, 0) }}</span></Link><p v-if="!panel.presupuestos.length" class="text-marca-muted">Ninguno.</p></div>
          <div><p class="text-xs font-bold uppercase tracking-widest text-marca-muted mb-1">Mis clientes que deben</p><Link v-for="d in panel.deudores" :key="d.id" :href="`/clientes/${d.id}`" class="flex justify-between py-1 border-t border-marca-borde/60 hover:text-carmin"><span>{{ d.nombre }}</span><span class="tabular-nums text-carmin">{{ moneda(d.saldo, 0) }}</span></Link><p v-if="!panel.deudores.length" class="text-marca-muted">Nadie.</p></div>
        </div>
      </template>
      <template v-else-if="panel.tipo === 'cajero'">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3"><h2 class="font-bold">Mi caja{{ panel.caja ? ' · ' + panel.caja.nombre : '' }}</h2><Link href="/fondos" class="btn-primary !py-1 text-xs">{{ panel.turno ? 'Cerrar turno' : 'Abrir turno' }}</Link></div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div class="p-3 rounded-xl" :class="panel.turno ? 'bg-emerald-50' : 'bg-amber-50'"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Turno</p><p class="font-extrabold">{{ panel.turno ? 'Abierto desde ' + panel.turno.desde : 'Sin abrir' }}</p></div>
          <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Efectivo esperado</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(panel.turno ? panel.turno.esperado : (panel.caja?.saldo ?? 0), 0) }}</p></div>
          <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Mis ventas hoy</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(panel.ventas_hoy, 0) }}</p><p class="text-xs text-marca-muted">{{ panel.tickets_hoy }} tickets</p></div>
          <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Mis cobros hoy</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(panel.cobros_hoy, 0) }}</p></div>
        </div>
      </template>
      <template v-else-if="panel.tipo === 'deposito'">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3"><h2 class="font-bold">Depósito</h2><Link href="/comprobantes/entregas" class="btn-primary !py-1 text-xs">Entregas</Link></div>
        <div class="grid grid-cols-3 gap-3 mb-3">
          <Link href="/stock?estado=bajo_minimo" class="p-3 rounded-xl bg-amber-50"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Bajo mínimo</p><p class="text-lg font-extrabold tabular-nums">{{ panel.bajo_minimo }}</p></Link>
          <Link href="/stock?estado=sin_stock" class="p-3 rounded-xl bg-carmin-light"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Sin stock</p><p class="text-lg font-extrabold tabular-nums">{{ panel.sin_stock }}</p></Link>
          <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Transferencias</p><p class="text-lg font-extrabold tabular-nums">{{ panel.transferencias_pendientes }}</p></div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4 text-sm">
          <div><p class="text-xs font-bold uppercase tracking-widest text-marca-muted mb-1">Entregas pendientes</p><Link v-for="e in panel.entregas" :key="e.id" :href="`/comprobantes/${e.id}`" class="flex justify-between py-1 border-t border-marca-borde/60 hover:text-carmin"><span>{{ e.cliente }} · {{ e.numero }}</span><span class="tabular-nums">{{ cantidad(e.pendiente) }} un.</span></Link><p v-if="!panel.entregas.length" class="text-marca-muted">Nada pendiente.</p></div>
          <div><p class="text-xs font-bold uppercase tracking-widest text-marca-muted mb-1">Para reponer</p><Link v-for="f in panel.faltantes" :key="f.id" :href="`/stock/${f.id}`" class="flex justify-between py-1 border-t border-marca-borde/60 hover:text-carmin"><span>{{ f.nombre }}</span><span class="tabular-nums text-carmin">{{ cantidad(f.stock) }} / mín {{ cantidad(f.min) }}</span></Link><p v-if="!panel.faltantes.length" class="text-marca-muted">Todo por encima del mínimo.</p></div>
        </div>
      </template>
      <template v-else-if="panel.tipo === 'contador'">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3"><h2 class="font-bold">Contable · este mes</h2><Link href="/contable/iva" class="btn-primary !py-1 text-xs">Libros IVA</Link></div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">IVA débito</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(panel.iva_df, 0) }}</p></div>
          <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">IVA crédito</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(panel.iva_cf, 0) }}</p></div>
          <div class="p-3 rounded-xl" :class="panel.posicion_iva > 0 ? 'bg-carmin-light' : 'bg-emerald-50'"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Posición IVA</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(panel.posicion_iva, 0) }}</p></div>
          <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Resultado</p><p class="text-lg font-extrabold tabular-nums" :class="(panel.resultado?.resultado ?? 0) < 0 ? 'text-carmin' : ''">{{ moneda(panel.resultado?.resultado ?? 0, 0) }}</p></div>
        </div>
        <div class="flex flex-wrap gap-2 mt-3 text-xs">
          <Link v-if="panel.sin_asiento" href="/contable/asientos" class="badge bg-amber-50 text-amber-800">{{ panel.sin_asiento }} comprobante(s) sin asiento</Link>
          <Link v-if="panel.pendientes_cae" href="/comprobantes" class="badge bg-carmin-light text-carmin">{{ panel.pendientes_cae }} pendiente(s) de CAE</Link>
          <Link v-if="panel.sueldos_pendientes" href="/sueldos" class="badge bg-violeta-light text-violeta">{{ panel.sueldos_pendientes }} liquidación(es) sin pagar</Link>
        </div>
      </template>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-3 mb-6">
      <StatCard v-for="k in kpis" :key="k.key" v-bind="k" />
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-bold">Ventas por día</h2>
          <span class="text-xs text-marca-muted">Total {{ moneda(totalSerie) }}</span>
        </div>
        <div v-if="serie.length" class="h-48 flex items-stretch gap-1">
          <div v-for="(d, i) in serie" :key="d.fecha" class="flex-1 flex flex-col justify-end items-center gap-1 group min-w-0" :title="`${d.label}: ${moneda(d.monto)}`">
            <div class="w-full rounded-t-md bg-marca-grad transition group-hover:opacity-80" :style="{ height: `${Math.max(3, d.monto / maxSerie * 100)}%` }"></div>
            <span class="text-[9px] text-marca-muted h-3 leading-3">{{ serie.length <= 16 || i % 3 === 0 ? d.label : '' }}</span>
          </div>
        </div>
        <p v-else class="text-sm text-marca-muted py-12 text-center">Sin ventas en este período.</p>
      </div>

      <div class="card">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-bold">Alertas</h2>
          <Link href="/alertas" class="text-xs text-carmin font-semibold">Ver todas</Link>
        </div>
        <div v-if="destacadas.length" class="space-y-2">
          <Link v-for="a in destacadas" :key="a.id" :href="a.url || '/alertas'" class="flex gap-3 p-2 -mx-2 rounded-xl hover:bg-marca-fondo">
            <span class="mt-1.5 w-2 h-2 rounded-full shrink-0" :class="{ critica: 'bg-carmin', aviso: 'bg-amber-500', info: 'bg-violeta' }[a.severidad]"></span>
            <div class="min-w-0">
              <p class="text-sm font-semibold leading-snug">{{ a.titulo }}</p>
              <p v-if="a.detalle" class="text-xs text-marca-muted line-clamp-2">{{ a.detalle }}</p>
            </div>
          </Link>
        </div>
        <p v-else class="text-sm text-marca-muted py-6 text-center">Todo en orden.</p>
      </div>

      <template v-if="operacion">
        <div class="card" :class="operacion.produccion ? 'lg:col-span-2' : 'lg:col-span-3'">
          <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Stock</h2><Link href="/stock" class="text-xs text-carmin font-semibold">Ver stock</Link></div>
          <div class="grid grid-cols-3 gap-3 mb-3">
            <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Valorizado</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(operacion.valorizado, 0) }}</p></div>
            <Link href="/stock?estado=bajo_minimo" class="p-3 rounded-xl bg-marca-fondo hover:bg-amber-50"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Bajo mínimo</p><p class="text-lg font-extrabold tabular-nums" :class="operacion.bajo_minimo ? 'text-amber-600' : ''">{{ operacion.bajo_minimo }}</p></Link>
            <Link href="/stock?estado=sin_stock" class="p-3 rounded-xl bg-marca-fondo hover:bg-carmin-light"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Sin stock</p><p class="text-lg font-extrabold tabular-nums" :class="operacion.sin_stock ? 'text-carmin' : ''">{{ operacion.sin_stock }}</p></Link>
          </div>
          <div v-if="operacion.faltantes.length" class="text-sm">
            <p class="text-xs text-marca-muted mb-1">Para reponer</p>
            <Link v-for="f in operacion.faltantes" :key="f.id" :href="`/stock/${f.id}`" class="flex justify-between py-1 border-t border-marca-borde/60 hover:text-carmin"><span>{{ f.nombre }}</span><span class="tabular-nums" :class="f.stock <= 0 ? 'text-carmin font-semibold' : 'text-amber-600'">{{ f.stock }} / mín. {{ f.min }} {{ f.unit }}</span></Link>
          </div>
          <p v-else class="text-sm text-marca-muted">Nada por debajo del mínimo.</p>
        </div>
        <div v-if="operacion.resultado" class="card lg:col-span-3 grid sm:grid-cols-4 gap-3 items-center">
          <div class="sm:col-span-1"><h2 class="font-bold">Resultado del mes</h2><p class="text-xs text-marca-muted">Según la contabilidad automática.</p><Link href="/contable" class="text-xs text-carmin font-semibold">Ver contable</Link></div>
          <div class="p-3 rounded-xl bg-emerald-50"><p class="text-[11px] font-bold uppercase tracking-widest text-emerald-700">Ingresos</p><p class="text-lg font-extrabold tabular-nums text-emerald-700">{{ moneda(operacion.resultado.total_ingresos, 0) }}</p></div>
          <div class="p-3 rounded-xl bg-carmin-light"><p class="text-[11px] font-bold uppercase tracking-widest text-carmin">Egresos</p><p class="text-lg font-extrabold tabular-nums text-carmin">{{ moneda(operacion.resultado.total_egresos, 0) }}</p></div>
          <div class="p-3 rounded-xl text-white" :class="operacion.resultado.resultado >= 0 ? 'bg-violeta-grad' : 'bg-carmin'"><p class="text-[11px] font-bold uppercase tracking-widest opacity-80">Resultado</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(operacion.resultado.resultado, 0) }}</p></div>
        </div>
        <div v-if="operacion.produccion" class="card">
          <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Producción</h2><Link href="/produccion" class="text-xs text-carmin font-semibold">Ver órdenes</Link></div>
          <div class="grid grid-cols-2 gap-3">
            <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En curso</p><p class="text-lg font-extrabold tabular-nums">{{ operacion.produccion.en_curso }}</p></div>
            <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Pendientes</p><p class="text-lg font-extrabold tabular-nums">{{ operacion.produccion.pendientes }}</p></div>
            <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Atrasadas</p><p class="text-lg font-extrabold tabular-nums" :class="operacion.produccion.atrasadas ? 'text-carmin' : ''">{{ operacion.produccion.atrasadas }}</p></div>
            <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Terminadas mes</p><p class="text-lg font-extrabold tabular-nums">{{ operacion.produccion.terminadas_mes }}</p></div>
          </div>
        </div>
      </template>

      <div class="card lg:col-span-3">
        <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Últimos comprobantes</h2><Link href="/comprobantes" class="text-xs text-carmin font-semibold">Ver todos</Link></div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead><tr><th>Comprobante</th><th>Cliente</th><th>Fecha</th><th>Cobro</th><th class="text-right">Total</th></tr></thead>
            <tbody>
              <tr v-for="v in ultimas" :key="v.id" class="cursor-pointer" @click="router.visit(`/comprobantes/${v.id}`)">
                <td class="font-semibold">{{ v.tipo }} <span class="tabular-nums text-marca-muted">{{ v.numero }}</span></td>
                <td class="font-medium">{{ v.cliente }}</td>
                <td class="text-marca-muted">{{ v.fecha }}</td>
                <td><span v-if="v.estado_cobro !== 'na'" class="badge" :class="estadoCobro[v.estado_cobro].clase">{{ estadoCobro[v.estado_cobro].label }}</span></td>
                <td class="text-right font-semibold tabular-nums">{{ moneda(v.total) }}</td>
              </tr>
              <tr v-if="!ultimas.length"><td colspan="5" class="text-center text-marca-muted py-6">Todavía no hay ventas.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import { estadoCobro, cantidad } from '@/util/formato'

const props = defineProps({ kpis: Array, serie: Array, ultimas: Array, destacadas: Array, periodo: String, operacion: Object, panel: Object })
const page = usePage()
const nombre = computed(() => page.props.auth?.user?.name?.split(' ')[0])
const periodos = [{ key: 'hoy', label: 'Hoy' }, { key: 'semana', label: 'Semana' }, { key: 'mes', label: 'Mes' }, { key: 'trimestre', label: 'Trimestre' }, { key: 'anio', label: 'Año' }]
const etiquetaPeriodo = computed(() => ({ hoy: 'hoy', semana: 'esta semana', mes: 'este mes', trimestre: 'este trimestre', anio: 'este año' }[props.periodo]))
const maxSerie = computed(() => Math.max(1, ...props.serie.map(d => d.monto)))
const totalSerie = computed(() => props.serie.reduce((a, d) => a + d.monto, 0))
const moneda = n => '$ ' + Number(n ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 0 })
function cambiar(p) { router.get('/dashboard', { periodo: p }, { preserveState: true, preserveScroll: true, replace: true }) }
</script>
