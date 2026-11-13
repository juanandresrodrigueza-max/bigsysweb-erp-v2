<template>
  <AppLayout titulo="Estadísticas · Big Restó">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Salón y cocina</h1><p class="page-subtitle">Cuánto vendió cada mozo, a qué hora se llena, qué platos salen más y cuánto tarda la cocina. <span class="cursor-help" :title="como">ⓘ</span></p></div>
      <form class="flex items-center gap-2 text-sm" @submit.prevent="router.get('/estadisticas/gastronomia', f, { preserveState: true })"><input v-model="f.desde" type="date" class="input !py-1 !w-auto" /><span>a</span><input v-model="f.hasta" type="date" class="input !py-1 !w-auto" /><button class="btn-secondary !py-1 text-xs">Ver</button></form>
    </div>
    <EstadisticasTabs activa="gastronomia" />

    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-4" data-e2e="kpis-gastro">
      <div v-for="k in tarjetas" :key="k[0]" class="card !py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ k[0] }}</p><p class="text-lg font-extrabold tabular-nums">{{ k[1] }}</p></div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
      <div class="card">
        <h2 class="font-bold mb-2">Por mozo</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-xs text-marca-muted"><th class="py-1">Mozo</th><th class="text-right">Mesas</th><th class="text-right">Cubiertos</th><th class="text-right">Vendió</th><th class="text-right">Ticket prom.</th><th class="text-right">Propinas</th></tr></thead>
          <tbody><tr v-for="m in por_mozo" :key="m.mozo" class="border-t border-marca-borde"><td class="py-1 font-semibold">{{ m.mozo }}</td><td class="text-right tabular-nums">{{ m.comandas }}</td><td class="text-right tabular-nums">{{ m.cubiertos }}</td><td class="text-right tabular-nums">{{ moneda(m.total, 0) }}</td><td class="text-right tabular-nums">{{ moneda(m.ticket, 0) }}</td><td class="text-right tabular-nums">{{ moneda(m.propinas, 0) }}</td></tr></tbody></table>
          <p v-if="!por_mozo.length" class="text-sm text-marca-muted py-4 text-center">Sin mesas cerradas en el período.</p></div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-2">A qué hora se vende</h2>
        <div v-for="h in por_hora" :key="h.hora" class="flex items-center gap-2 text-sm py-0.5"><span class="w-10 tabular-nums text-marca-muted">{{ h.hora }} h</span><div class="flex-1 h-4 rounded bg-marca-fondo overflow-hidden"><div class="h-4 rounded bg-carmin/80" :style="{ width: (h.total / maxHora * 100) + '%' }"></div></div><span class="w-24 text-right tabular-nums">{{ moneda(h.total, 0) }}</span><span class="w-10 text-right text-xs text-marca-muted">{{ h.comandas }}</span></div>
        <p v-if="!por_hora.length" class="text-sm text-marca-muted py-4 text-center">Sin datos.</p>
        <div class="flex flex-wrap gap-2 mt-3"><span v-for="t in por_tipo" :key="t.tipo" class="badge bg-marca-fondo text-marca-texto">{{ t.tipo }} · {{ t.comandas }} · {{ moneda(t.total, 0) }}</span></div>
      </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
      <div class="card" data-e2e="semana">
        <h2 class="font-bold mb-1">La semana que viene <span class="cursor-help text-marca-muted font-normal text-sm" :title="semana.como">ⓘ</span></h2>
        <p class="text-xs text-marca-muted mb-2">~{{ semana.total }} platos en 7 días, según lo que vendés cada día de la semana.</p>
        <div v-for="d in semana.dias" :key="d.fecha" class="flex items-start justify-between gap-3 text-sm py-1 border-t border-marca-borde/60"><div><b>{{ d.dia }}</b> <span class="text-xs text-marca-muted">{{ d.fecha.slice(8) }}/{{ d.fecha.slice(5, 7) }}</span><p class="text-xs text-marca-muted">{{ d.top.map(t => t.plato + ' ×' + t.unidades).join(' · ') || (d.base ? 'sin ventas ese día' : 'sin datos todavía') }}</p></div><b class="tabular-nums">{{ d.platos }}</b></div>
      </div>
      <div class="card" data-e2e="comprar">
        <h2 class="font-bold mb-1">Qué comprar para cubrirla <span class="cursor-help text-marca-muted font-normal text-sm" :title="comprar.como">ⓘ</span></h2>
        <p class="text-xs text-marca-muted mb-2">Insumos que faltan según las recetas, por proveedor. Estimado: <b>{{ moneda(comprar.total, 0) }}</b></p>
        <details v-for="p in comprar.proveedores" :key="p.proveedor" class="text-sm py-1 border-t border-marca-borde/60"><summary class="cursor-pointer flex justify-between"><span><b>{{ p.proveedor }}</b> <span class="text-xs text-marca-muted">· {{ p.renglones }}</span></span><span class="tabular-nums">{{ moneda(p.costo, 0) }}</span></summary>
          <div v-for="it in p.items" :key="it.id" class="flex justify-between text-xs py-0.5 pl-3"><span>{{ it.insumo }} · necesita {{ cantidad(it.necesita) }} {{ it.unit }}, hay {{ cantidad(it.stock) }}</span><b>falta {{ cantidad(it.falta) }}</b></div></details>
        <p v-if="!comprar.proveedores.length" class="text-sm text-emerald-700 py-3 text-center">Con el stock que hay alcanza para la semana.</p>
      </div>
    </div>

    <div class="card mb-4" data-e2e="ingenieria">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-2"><h2 class="font-bold">Ingeniería de menú <span class="cursor-help text-marca-muted font-normal text-sm" :title="ingenieria.como">ⓘ</span></h2>
        <div class="flex items-center gap-2 text-xs"><span class="badge bg-emerald-50 text-emerald-700">⭐ {{ ingenieria.resumen?.estrella ?? 0 }} estrellas</span><span class="badge bg-amber-50 text-amber-700">🐴 {{ ingenieria.resumen?.caballito ?? 0 }} caballitos</span><span class="badge bg-lavanda-light text-violeta">🧩 {{ ingenieria.resumen?.rompecabezas ?? 0 }} rompecabezas</span><span class="badge bg-carmin-light text-carmin">🐶 {{ ingenieria.resumen?.perro ?? 0 }} perros</span>
          <button v-if="ia" class="btn-secondary !py-1 text-xs" :disabled="accCargando" @click="pedirAcciones" data-e2e="acciones">{{ accCargando ? 'Pensando…' : '✦ 3 acciones para esta semana' }}</button></div></div>
      <ul v-if="acciones.length" class="text-sm mb-2 space-y-1"><li v-for="(a, i) in acciones" :key="i" class="rounded-lg bg-marca-fondo px-3 py-1.5">{{ i + 1 }}. {{ a }}</li></ul>
      <div class="overflow-x-auto max-h-80"><table class="w-full text-sm"><thead><tr class="text-left text-xs text-marca-muted"><th class="py-1">Plato</th><th>Clase</th><th class="text-right">Unid.</th><th class="text-right">Precio neto</th><th class="text-right">Costo</th><th class="text-right">Margen</th><th class="text-right">Contribución</th><th>Qué hacer</th></tr></thead>
        <tbody><tr v-for="p in ingenieria.platos" :key="p.id" class="border-t border-marca-borde"><td class="py-1 font-semibold">{{ p.plato }}</td><td><span class="badge" :class="{ estrella: 'bg-emerald-50 text-emerald-700', caballito: 'bg-amber-50 text-amber-700', rompecabezas: 'bg-lavanda-light text-violeta', perro: 'bg-carmin-light text-carmin', sin_costo: 'bg-gris-light text-marca-muted' }[p.clase]">{{ { estrella: 'estrella', caballito: 'caballito', rompecabezas: 'rompecabezas', perro: 'perro', sin_costo: 'sin costo' }[p.clase] }}</span></td><td class="text-right tabular-nums">{{ cantidad(p.unidades) }}</td><td class="text-right tabular-nums">{{ moneda(p.precio, 0) }}</td><td class="text-right tabular-nums">{{ moneda(p.costo, 0) }}</td><td class="text-right tabular-nums">{{ moneda(p.margen, 0) }}<span v-if="p.margen_pct !== null" class="text-xs text-marca-muted"> ({{ p.margen_pct }} %)</span></td><td class="text-right tabular-nums font-semibold">{{ moneda(p.contribucion, 0) }}</td><td class="text-xs text-marca-muted max-w-xs">{{ p.accion }}</td></tr></tbody></table>
        <p v-if="!ingenieria.platos.length" class="text-sm text-marca-muted py-4 text-center">Sin ventas en los últimos {{ ingenieria.dias }} días.</p></div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
      <div class="card" data-e2e="control">
        <h2 class="font-bold mb-1">Control del salón <span class="cursor-help text-marca-muted font-normal text-sm" :title="control.como">ⓘ</span></h2>
        <p class="text-xs text-marca-muted mb-2">Equipo: descuentos {{ control.mediana.descuento_pct }} % · propinas {{ control.mediana.propina_pct }} %. Se marca lo que se aparta; es para mirar, no una acusación.</p>
        <div v-for="m in control.mozos" :key="m.mozo" class="py-1.5 border-t border-marca-borde/60 text-sm"><div class="flex justify-between"><b>{{ m.mozo }}</b><span class="text-xs text-marca-muted">{{ m.comandas }} comandas · {{ moneda(m.venta, 0) }} · desc. {{ m.descuento_pct }} % · prop. {{ m.propina_pct }} % · {{ m.items_anulados }} anulados</span></div>
          <p v-for="r in m.raros" :key="r" class="text-xs text-carmin">⚠ {{ r }}</p></div>
        <p v-if="!control.mozos.length" class="text-sm text-marca-muted py-4 text-center">Sin comandas en el período.</p>
      </div>
      <div class="card" data-e2e="opiniones">
        <h2 class="font-bold mb-1">Qué dicen los clientes <span class="text-xs text-marca-muted font-normal">· últimos {{ opiniones.dias }} días</span></h2>
        <template v-if="opiniones.cantidad">
          <p class="text-2xl font-black">{{ opiniones.promedio }} <span class="text-amber-500">★</span> <span class="text-sm font-normal text-marca-muted">en {{ opiniones.cantidad }} opiniones</span></p>
          <div class="flex gap-1 text-xs my-1"><span v-for="(n, p) in opiniones.distribucion" :key="p" class="badge bg-marca-fondo">{{ p }}★ {{ n }}</span></div>
          <p v-if="opiniones.patron" class="rounded-lg bg-amber-50 text-amber-800 text-xs px-3 py-1.5 my-2">Las malas se concentran <b>los {{ opiniones.patron.cuando }}</b>: {{ opiniones.patron.cantidad }} de {{ opiniones.patron.de }}.</p>
          <div v-if="opiniones.ia" class="rounded-lg bg-lavanda-light/50 text-sm px-3 py-2 my-2"><p class="text-[11px] font-bold uppercase text-violeta">✦ Resumen</p><ul class="list-disc pl-4"><li v-for="(x, i) in opiniones.ia.puntos" :key="i">{{ x }}</li></ul><p v-if="opiniones.ia.lo_mas_repetido" class="text-xs mt-1"><b>Lo más repetido:</b> {{ opiniones.ia.lo_mas_repetido }}</p><p v-if="opiniones.ia.elogios" class="text-xs"><b>Elogios:</b> {{ opiniones.ia.elogios }}</p></div>
          <div class="flex flex-wrap gap-1 text-xs mb-2"><span v-for="m in opiniones.por_mozo" :key="m.mozo" class="badge bg-marca-fondo">{{ m.mozo }} {{ m.promedio }}★ ({{ m.cantidad }})</span></div>
          <div class="max-h-40 overflow-y-auto text-xs space-y-1"><p v-for="(c, i) in opiniones.comentarios" :key="i" class="border-t border-marca-borde/60 pt-1"><span class="text-amber-500">{{ '★'.repeat(c.puntaje) }}</span> {{ c.texto }} <span class="text-marca-muted">· {{ c.fecha }}{{ c.mozo ? ' · ' + c.mozo : '' }}</span></p></div>
        </template>
        <p v-else class="text-sm text-marca-muted py-4 text-center">Todavía no hay opiniones. Llegan desde el QR de la mesa al pedir la cuenta y desde el WhatsApp de «pedido listo».</p>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-2">Platos más pedidos</h2>
        <div class="overflow-x-auto max-h-96"><table class="w-full text-sm"><thead><tr class="text-left text-xs text-marca-muted"><th class="py-1">Plato</th><th class="text-right">Unidades</th><th class="text-right">Facturado</th></tr></thead>
          <tbody><tr v-for="p in platos" :key="p.plato" class="border-t border-marca-borde"><td class="py-1">{{ p.plato }}</td><td class="text-right tabular-nums font-semibold">{{ cantidad(p.unidades) }}</td><td class="text-right tabular-nums">{{ moneda(p.total, 0) }}</td></tr></tbody></table></div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-2">Tiempos de cocina</h2>
        <div v-for="c in cocina" :key="c.estacion" class="flex justify-between text-sm py-1 border-t border-marca-borde/60"><span class="font-semibold">{{ c.estacion }}</span><span class="tabular-nums">prom. <b>{{ c.promedio }}'</b> · máx {{ c.maximo }}' · {{ c.platos }} platos</span></div>
        <p v-if="!cocina.length" class="text-sm text-marca-muted py-4 text-center">Todavía no hay platos marcados como listos.</p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadisticasTabs from '@/Components/EstadisticasTabs.vue'
import { moneda, cantidad } from '@/util/formato'
const props = defineProps({ control: { type: Object, default: () => ({ mozos: [], mediana: {} }) }, opiniones: { type: Object, default: () => ({ cantidad: 0 }) }, ingenieria: { type: Object, default: () => ({ platos: [], resumen: {} }) }, ia: Boolean, semana: { type: Object, default: () => ({ dias: [], total: 0 }) }, comprar: { type: Object, default: () => ({ proveedores: [], total: 0 }) }, periodo: Object, kpis: Object, por_mozo: Array, por_hora: Array, por_tipo: Array, platos: Array, cocina: Array, como: String })
const f = reactive({ ...props.periodo })
const acciones = ref([]), accCargando = ref(false)
async function pedirAcciones() { accCargando.value = true; try { const { data } = await window.axios.post('/estadisticas/gastronomia/acciones'); acciones.value = data.acciones ?? [] } catch (e) {} finally { accCargando.value = false } }
const maxHora = computed(() => Math.max(1, ...props.por_hora.map(h => h.total)))
const tarjetas = computed(() => [['Vendido', moneda(props.kpis.total, 0)], ['Mesas / pedidos', props.kpis.comandas], ['Ticket promedio', moneda(props.kpis.ticket, 0)], ['Cubiertos', props.kpis.cubiertos], ['Por cubierto', moneda(props.kpis.por_cubierto, 0)], ['Propinas', moneda(props.kpis.propinas, 0)], ['Permanencia', props.kpis.permanencia + ' min']])
</script>
