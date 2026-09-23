<template>
  <AppLayout titulo="Rentabilidad">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Rentabilidad real</h1><p class="page-subtitle">Cuánto queda después de pagar la mercadería, los costos variables y los fijos.</p></div>
      <div class="flex flex-wrap items-center gap-2">
        <select :value="sucursalId ?? ''" @change="$inertia.get('/estadisticas/rentabilidad', { ...periodo, sucursal: $event.target.value || undefined }, { preserveState: true, replace: true })" class="input w-auto !py-1 text-xs"><option value="">Todas las sucursales</option><option v-for="s in listaSucursales" :key="s.id" :value="s.id">{{ s.name }}</option></select>
        <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" :extra="{ sucursal: sucursalId }" />
        <Link href="/estadisticas" class="btn-secondary !py-1 text-xs">← Estadísticas</Link>
        <button class="btn-secondary !py-1 text-xs" @click="clasifAbierto = true">Clasificar costos<span v-if="sin_clasificar" class="ml-1 badge bg-carmin text-white">{{ sin_clasificar }}</span></button>
        <a :href="`/estadisticas/rentabilidad?desde=${periodo.desde}&hasta=${periodo.hasta}${sucursalId ? '&sucursal=' + sucursalId : ''}&export=1`" class="btn-secondary !py-1 text-xs">Exportar CSV</a>
      </div>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <StatCard label="Ventas netas" :valor="kpis.ventas" />
      <StatCard :label="`Margen bruto ${pct(kpis.margen_bruto_pct)}`" :valor="kpis.margen_bruto" :alerta="kpis.margen_bruto < 0" />
      <StatCard :label="`Margen de contribución ${pct(kpis.margen_contribucion_pct)}`" :valor="kpis.margen_contribucion" :alerta="kpis.margen_contribucion < 0" />
      <StatCard :label="`Resultado ${pct(kpis.resultado_pct)}`" :valor="kpis.resultado" :alerta="kpis.resultado < 0" />
    </div>

    <div class="grid lg:grid-cols-3 gap-5 mb-5">
      <!-- Cascada -->
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-1">De la venta al resultado</h2>
        <p class="text-xs text-marca-muted mb-3">Cada barra descuenta un escalón de costos. Si el resultado es negativo, el negocio pierde plata en el período.</p>
        <div class="space-y-2 text-sm">
          <div v-for="e in cascada" :key="e.label" class="grid grid-cols-[150px_1fr_120px] items-center gap-2">
            <span :class="e.fuerte ? 'font-bold' : 'text-marca-muted'">{{ e.label }}</span>
            <div class="h-5 bg-marca-fondo rounded-full overflow-hidden relative">
              <div class="h-full rounded-full" :class="e.color" :style="{ width: Math.min(100, Math.abs(e.valor) / maxCascada * 100) + '%' }"></div>
            </div>
            <span class="text-right tabular-nums" :class="[e.fuerte ? 'font-bold' : '', e.valor < 0 && e.fuerte ? 'text-carmin' : '']">{{ e.signo }}{{ moneda(Math.abs(e.valor), 0) }}</span>
          </div>
        </div>
      </div>
      <!-- Punto de equilibrio -->
      <div class="card">
        <h2 class="font-bold mb-1">Punto de equilibrio</h2>
        <p class="text-xs text-marca-muted mb-3">Lo mínimo que hay que vender en el período para cubrir todos los costos fijos.</p>
        <template v-if="kpis.punto_equilibrio !== null">
          <p class="text-2xl font-extrabold tabular-nums">{{ moneda(kpis.punto_equilibrio, 0) }}</p>
          <p class="text-xs text-marca-muted">≈ {{ moneda(kpis.punto_equilibrio_diario, 0) }} por día · vendés {{ moneda(kpis.ventas_diarias, 0) }} por día</p>
          <div class="mt-3 h-3 bg-marca-fondo rounded-full overflow-hidden"><div class="h-full rounded-full" :class="kpis.cobertura_pct >= 100 ? 'bg-emerald-500' : 'bg-carmin'" :style="{ width: Math.min(100, kpis.cobertura_pct) + '%' }"></div></div>
          <p class="text-sm mt-2" :class="kpis.cobertura_pct >= 100 ? 'text-emerald-700' : 'text-carmin'">
            <template v-if="kpis.cobertura_pct >= 100">Cubrís los fijos: estás {{ kpis.cobertura_pct - 100 }}% arriba del punto de equilibrio.</template>
            <template v-else>Te falta {{ moneda(kpis.punto_equilibrio - kpis.ventas, 0) }} de ventas para cubrir los fijos ({{ kpis.cobertura_pct }}%).</template>
          </p>
        </template>
        <p v-else class="text-sm text-marca-muted">No se puede calcular: el margen de contribución es cero o negativo{{ kpis.ventas ? '' : ' y no hay ventas' }}.</p>
        <dl class="mt-4 text-xs grid grid-cols-2 gap-1">
          <dt class="text-marca-muted">Costos fijos</dt><dd class="text-right tabular-nums">{{ moneda(kpis.fijos, 0) }}</dd>
          <dt class="text-marca-muted">Fijos por día</dt><dd class="text-right tabular-nums">{{ moneda(kpis.fijos_diarios, 0) }}</dd>
          <dt class="text-marca-muted">Costos directos</dt><dd class="text-right tabular-nums">{{ moneda(kpis.directos, 0) }}</dd>
          <dt class="text-marca-muted">Costos indirectos</dt><dd class="text-right tabular-nums">{{ moneda(kpis.indirectos, 0) }}</dd>
        </dl>
      </div>
    </div>

    <!-- Serie mensual -->
    <div class="card mb-5">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3"><h2 class="font-bold">Últimos 12 meses</h2><p class="text-[11px] text-marca-muted">Barra: ventas netas · línea violeta: margen bruto · punto: resultado</p></div>
      <div class="flex items-end gap-1 h-40">
        <div v-for="m in serie" :key="m.mes" class="flex-1 flex flex-col items-center justify-end h-full min-w-0 relative" :title="`${m.mes}: ventas ${moneda(m.ventas, 0)} · margen bruto ${moneda(m.margen_bruto, 0)} · fijos ${moneda(m.fijos, 0)} · resultado ${moneda(m.resultado, 0)}`">
          <div class="w-full h-full flex items-end relative">
            <div class="w-full rounded-t bg-marca-borde" :style="{ height: Math.max(1, m.ventas / maxSerie * 100) + '%' }"></div>
            <div class="absolute left-1/4 right-1/4 rounded-t bg-violeta" :style="{ height: Math.max(0, m.margen_bruto / maxSerie * 100) + '%' }"></div>
            <div class="absolute left-1/2 -translate-x-1/2 w-2 h-2 rounded-full border-2 border-white" :class="m.resultado >= 0 ? 'bg-emerald-500' : 'bg-carmin'" :style="{ bottom: Math.max(0, Math.min(100, m.resultado / maxSerie * 100)) + '%' }"></div>
          </div>
          <span class="text-[9px] text-marca-muted mt-1 truncate w-full text-center">{{ m.mes }}</span>
        </div>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-5 mb-5">
      <!-- Costos del período -->
      <div class="card">
        <h2 class="font-bold mb-1">Costos del período</h2>
        <p class="text-xs text-marca-muted mb-3">Gastos de fondos por categoría, compras no inventariables, comisiones, sueldos y amortizaciones.</p>
        <p v-if="dobles.length" class="text-xs bg-amber-50 text-amber-800 rounded-lg p-2 mb-2">La categoría "{{ dobles.join('", "') }}" no se suma porque ya están las liquidaciones de sueldos del período.</p>
        <div class="flex gap-1 bg-marca-fondo rounded-full p-1 text-xs mb-3"><button v-for="v in [['tipo', 'Fijos / variables'], ['imputacion', 'Directos / indirectos']]" :key="v[0]" class="flex-1 px-3 py-1 rounded-full font-semibold" :class="vista === v[0] ? 'bg-white shadow' : 'text-marca-muted'" @click="vista = v[0]">{{ v[1] }}</button></div>
        <div v-for="g in grupos" :key="g.clave" class="mb-3">
          <div class="flex justify-between text-xs font-bold uppercase tracking-widest text-marca-muted mb-1"><span>{{ g.label }}</span><span class="tabular-nums">{{ moneda(g.total, 0) }}</span></div>
          <div v-for="l in g.lineas" :key="l.nombre" class="flex items-center gap-2 text-sm py-0.5" :class="l.excluido ? 'opacity-50 line-through' : ''">
            <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ background: l.color }"></span>
            <span class="flex-1 truncate">{{ l.nombre }}</span>
            <span class="tabular-nums">{{ moneda(l.monto, 0) }}</span>
          </div>
          <p v-if="!g.lineas.length" class="text-xs text-marca-muted">Nada en este período.</p>
        </div>
        <p v-if="!lineas.length" class="text-sm text-marca-muted">No hay costos cargados en el período. Cargá gastos en Fondos con su categoría.</p>
      </div>

      <!-- Por dimensión -->
      <div class="card lg:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
          <h2 class="font-bold">Rentabilidad por…</h2>
          <div class="flex flex-wrap gap-1 bg-marca-fondo rounded-full p-1 text-xs"><button v-for="t in tabs" :key="t[0]" class="px-3 py-1 rounded-full font-semibold" :class="tab === t[0] ? 'bg-white shadow' : 'text-marca-muted'" @click="tab = t[0]">{{ t[1] }}</button></div>
        </div>
        <p class="text-xs text-marca-muted mb-2">
          Margen bruto = ventas − costo de la mercadería al momento de vender.
          <template v-if="config.distribuir_indirectos">Los costos indirectos se reparten proporcional a las ventas de cada fila.</template>
          <template v-else>Los costos indirectos no se reparten (Configurar).</template>
          <template v-if="tab === 'vendedores'"> Las comisiones van como costo directo de cada vendedor.</template>
          <template v-if="(por[tab] ?? []).length >= 500"> Se muestran los 500 con más ventas.</template>
        </p>
        <div class="overflow-x-auto">
          <table class="tabla text-sm">
            <thead><tr><th>{{ tabs.find(t => t[0] === tab)[1] }}</th><th class="text-right">Ventas</th><th class="text-right">Costo</th><th class="text-right">Margen bruto</th><th class="text-right">%</th><th v-if="tab === 'vendedores'" class="text-right">Comisión</th><th v-if="config.distribuir_indirectos" class="text-right">Indirectos</th><th class="text-right">Resultado</th></tr></thead>
            <tbody>
              <tr v-for="r in filas" :key="r.clave ?? r.nombre">
                <td><p class="font-medium truncate max-w-[220px]">{{ r.nombre }}</p><p class="text-[11px] text-marca-muted">{{ r.participacion }}% de las ventas<template v-if="tab === 'articulos' && r.cantidad"> · {{ cantidad(r.cantidad) }} un.</template></p></td>
                <td class="text-right tabular-nums">{{ moneda(r.ventas, 0) }}</td>
                <td class="text-right tabular-nums text-marca-muted">{{ moneda(r.costo, 0) }}</td>
                <td class="text-right tabular-nums" :class="r.margen_bruto < 0 ? 'text-carmin' : ''">{{ moneda(r.margen_bruto, 0) }}</td>
                <td class="text-right tabular-nums"><span class="badge" :class="r.margen_pct === null ? '' : r.margen_pct < 10 ? 'bg-red-100 text-red-800' : r.margen_pct < 25 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'">{{ r.margen_pct === null ? '—' : r.margen_pct + '%' }}</span></td>
                <td v-if="tab === 'vendedores'" class="text-right tabular-nums text-marca-muted">{{ moneda(r.directos || 0, 0) }}</td>
                <td v-if="config.distribuir_indirectos" class="text-right tabular-nums text-marca-muted">{{ moneda(r.indirectos, 0) }}</td>
                <td class="text-right tabular-nums font-semibold" :class="r.resultado < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(r.resultado, 0) }}</td>
              </tr>
              <tr v-if="!filas.length"><td colspan="8" class="text-center text-marca-muted py-6">{{ cargandoDim ? 'Calculando…' : 'Sin ventas en el período.' }}</td></tr>
            </tbody>
          </table>
        </div>
        <button v-if="(por[tab] ?? []).length > 15" class="text-xs text-violeta font-semibold mt-2" @click="verTodo = !verTodo">{{ verTodo ? 'Ver menos' : `Ver los ${(por[tab] ?? []).length}` }}</button>
      </div>
    </div>

    <!-- Configuración -->
    <div class="card">
      <h2 class="font-bold mb-1">Cómo se calcula</h2>
      <div class="grid sm:grid-cols-3 gap-3 text-sm">
        <label class="flex items-start gap-2"><input v-model="cfg.distribuir_indirectos" type="checkbox" class="mt-1" /><span>Repartir los costos indirectos entre artículos, clientes, etc. según su peso en las ventas.</span></label>
        <div><label class="label">Compras no inventariables (servicios, insumos) son</label><select v-model="cfg.compras_gastos" class="input"><option value="variable">Costo variable</option><option value="fijo">Costo fijo</option></select></div>
        <div><label class="label">Gastos sin categoría son</label><select v-model="cfg.sin_categoria" class="input"><option value="fijo">Costo fijo</option><option value="variable">Costo variable</option></select></div>
      </div>
      <div class="flex justify-end mt-3"><button class="btn-primary" :disabled="cfg.processing" @click="cfg.post('/estadisticas/rentabilidad/config', { preserveScroll: true })">Guardar</button></div>
    </div>

    <Modal :abierto="clasifAbierto" titulo="Clasificar categorías de gasto" ancho="max-w-3xl" @cerrar="clasifAbierto = false">
      <p class="text-sm text-marca-muted mb-3"><b>Fijo</b>: se paga igual vendas o no (alquiler, sueldos, servicios). <b>Variable</b>: crece con las ventas (fletes, comisiones, embalajes). <b>Directo</b>: se puede atribuir a una venta u obra puntual. <b>Indirecto</b>: es de todo el negocio.</p>
      <div class="overflow-x-auto"><table class="tabla text-sm">
        <thead><tr><th>Categoría</th><th>Tipo de costo</th><th>Imputación</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in clasif.categorias" :key="c.id">
            <td><span class="inline-block w-2.5 h-2.5 rounded-full mr-1" :style="{ background: c.color }"></span>{{ c.name }}</td>
            <td><select v-model="c.tipo_costo" class="input !py-1"><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l }}</option></select></td>
            <td><select v-model="c.imputacion" class="input !py-1"><option v-for="(l, k) in imputaciones" :key="k" :value="k">{{ l }}</option></select></td>
            <td class="text-xs text-marca-muted"><button v-if="c.sugerencia.tipo_costo !== c.tipo_costo || c.sugerencia.imputacion !== c.imputacion" class="text-violeta" @click="c.tipo_costo = c.sugerencia.tipo_costo; c.imputacion = c.sugerencia.imputacion">Sugerido: {{ tipos[c.sugerencia.tipo_costo] }} · {{ imputaciones[c.sugerencia.imputacion] }}</button></td>
          </tr>
          <tr v-if="!clasif.categorias.length"><td colspan="4" class="text-center text-marca-muted py-4">Todavía no hay categorías. Crealas en Fondos → Nueva categoría.</td></tr>
        </tbody>
      </table></div>
      <template #pie><button class="btn-secondary" @click="clasifAbierto = false">Cancelar</button><button class="btn-primary" :disabled="clasif.processing || !clasif.categorias.length" @click="clasif.post('/estadisticas/rentabilidad/categorias', { preserveScroll: true, onSuccess: () => (clasifAbierto = false) })">Guardar clasificación</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import Modal from '@/Components/Modal.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { moneda, cantidad } from '@/util/formato'

const props = defineProps({ periodo: Object, sucursalId: Number, listaSucursales: Array, kpis: Object, lineas: Array, dobles: Array, por: Object, serie: Array, config: Object, categorias: Array, sin_clasificar: Number, tipos: Object, imputaciones: Object })

const pct = (v) => (v === null || v === undefined ? '' : `· ${v}%`)
const cascada = computed(() => [
  { label: 'Ventas netas', valor: props.kpis.ventas, signo: '', color: 'bg-marca-borde', fuerte: true },
  { label: 'Costo de lo vendido', valor: props.kpis.cmv, signo: '−', color: 'bg-carmin/60' },
  { label: 'Margen bruto', valor: props.kpis.margen_bruto, signo: '=', color: 'bg-violeta', fuerte: true },
  { label: 'Costos variables', valor: props.kpis.variables, signo: '−', color: 'bg-carmin/60' },
  { label: 'Margen de contribución', valor: props.kpis.margen_contribucion, signo: '=', color: 'bg-magenta', fuerte: true },
  { label: 'Costos fijos', valor: props.kpis.fijos, signo: '−', color: 'bg-carmin/60' },
  { label: 'Resultado', valor: props.kpis.resultado, signo: '=', color: props.kpis.resultado >= 0 ? 'bg-emerald-500' : 'bg-carmin', fuerte: true },
])
const maxCascada = computed(() => Math.max(1, ...cascada.value.map(e => Math.abs(e.valor))))
const maxSerie = computed(() => Math.max(1, ...props.serie.map(m => m.ventas)))

const vista = ref('tipo')
const grupos = computed(() => {
  const defs = vista.value === 'tipo' ? [['fijo', 'Costos fijos'], ['variable', 'Costos variables']] : [['directo', 'Costos directos'], ['indirecto', 'Costos indirectos']]
  return defs.map(([clave, label]) => {
    const ls = props.lineas.filter(l => l[vista.value] === clave).sort((a, b) => b.monto - a.monto)
    return { clave, label, lineas: ls, total: ls.filter(l => !l.excluido).reduce((s, l) => s + l.monto, 0) }
  })
})

const tabs = [['articulos', 'Artículo'], ['rubros', 'Rubro'], ['clientes', 'Cliente'], ['vendedores', 'Vendedor'], ['sucursales', 'Sucursal'], ['obras', 'Obra']]
const tab = ref('articulos')
const verTodo = ref(false)
// Las aperturas se piden al servidor al abrir cada solapa (con muchos comprobantes cada una es una consulta pesada).
const por = reactive({ ...props.por }); const cargandoDim = ref(false)
watch(tab, async t => {
  if (por[t]) return
  cargandoDim.value = true
  try { const q = new URLSearchParams({ dim: t, desde: props.periodo.desde, hasta: props.periodo.hasta, ...(props.sucursalId ? { sucursal: props.sucursalId } : {}) }); const r = await fetch(`/estadisticas/rentabilidad/dim?${q}`, { headers: { Accept: 'application/json' } }); por[t] = r.ok ? await r.json() : [] } catch (e) { por[t] = [] }
  finally { cargandoDim.value = false }
})
const filas = computed(() => { const l = por[tab.value] ?? []; return verTodo.value ? l : l.slice(0, 15) })

const cfg = useForm({ distribuir_indirectos: !!props.config.distribuir_indirectos, compras_gastos: props.config.compras_gastos, sin_categoria: props.config.sin_categoria })
const clasifAbierto = ref(false)
const clasif = useForm({ categorias: props.categorias.map(c => ({ ...c, tipo_costo: c.tipo_costo || c.sugerencia.tipo_costo, imputacion: c.imputacion || c.sugerencia.imputacion })) })
</script>
