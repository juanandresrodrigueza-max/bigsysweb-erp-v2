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
import { estadoCobro } from '@/util/formato'

const props = defineProps({ kpis: Array, serie: Array, ultimas: Array, destacadas: Array, periodo: String, operacion: Object })
const page = usePage()
const nombre = computed(() => page.props.auth?.user?.name?.split(' ')[0])
const periodos = [{ key: 'hoy', label: 'Hoy' }, { key: 'semana', label: 'Semana' }, { key: 'mes', label: 'Mes' }, { key: 'trimestre', label: 'Trimestre' }, { key: 'anio', label: 'Año' }]
const etiquetaPeriodo = computed(() => ({ hoy: 'hoy', semana: 'esta semana', mes: 'este mes', trimestre: 'este trimestre', anio: 'este año' }[props.periodo]))
const maxSerie = computed(() => Math.max(1, ...props.serie.map(d => d.monto)))
const totalSerie = computed(() => props.serie.reduce((a, d) => a + d.monto, 0))
const moneda = n => '$ ' + Number(n ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 0 })
function cambiar(p) { router.get('/dashboard', { periodo: p }, { preserveState: true, preserveScroll: true, replace: true }) }
</script>
