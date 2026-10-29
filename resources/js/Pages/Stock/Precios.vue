<template>
  <AppLayout titulo="Stock · Precios y datos en masa">
    <!-- Fase 34: el asistente en tres pasos. 1) Qué hacer. 2) A qué artículos. 3) Cómo. Después, la pantalla que salva: ves cada cambio
         antes de grabar, lo raro viene en rojo y destildado, y se graba todo o nada. Queda en el historial con deshacer. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Precios y datos en masa</h1><p class="page-subtitle">Tocá cientos de artículos de una vez, viendo antes qué va a cambiar. Todo queda en el historial y se puede deshacer.</p></div>
      <div class="flex gap-2"><Link href="/stock/importar" class="btn-secondary">Importar lista de precios</Link><Link href="/stock" class="btn-secondary">Volver a Stock</Link></div>
    </div>

    <div v-if="$page.props.flash?.success" class="rounded-xl bg-emerald-50 text-emerald-800 px-4 py-2 mb-4 text-sm" data-e2e="ok">{{ $page.props.flash.success }}</div>

    <div class="grid lg:grid-cols-[1fr_320px] gap-4">
      <div class="space-y-4">
        <!-- Paso 1 -->
        <div class="card" data-e2e="paso1">
          <h2 class="font-bold mb-2"><span class="paso">1</span> ¿Qué querés hacer?</h2>
          <div class="grid sm:grid-cols-4 gap-2">
            <button v-for="q in ques" :key="q.k" class="rounded-xl border p-3 text-left text-sm" :class="que === q.k ? 'border-carmin bg-carmin-light/40' : 'border-marca-borde hover:border-marca-texto'" @click="elegirQue(q.k)" :data-que="q.k"><b class="block">{{ q.t }}</b><span class="text-xs text-marca-muted">{{ q.d }}</span></button>
          </div>
        </div>

        <!-- Paso 2 -->
        <div class="card" data-e2e="paso2">
          <h2 class="font-bold mb-2"><span class="paso">2</span> ¿A qué artículos?</h2>
          <div class="flex flex-wrap gap-1.5 text-xs mb-3">
            <button v-for="g in grupos" :key="g.k" class="rounded-full px-3 py-1 font-semibold border" :class="grupo === g.k ? 'bg-marca-texto text-white border-marca-texto' : 'border-marca-borde'" @click="grupo = g.k" :data-grupo="g.k">{{ g.t }}</button>
          </div>
          <div v-if="grupo === 'mano'">
            <div class="relative"><input v-model="busq" class="input" placeholder="Buscá por nombre, código o barras y agregá…" @input="buscar" data-e2e="busq" />
              <div v-if="resultados.length" class="absolute z-10 left-0 right-0 bg-white border border-marca-borde rounded-xl shadow-lg mt-1 max-h-56 overflow-y-auto">
                <button v-for="r in resultados" :key="r.id" class="w-full text-left px-3 py-1.5 text-sm hover:bg-gris-light flex justify-between" @click="agregar(r)"><span>{{ r.nombre }} <span class="text-marca-muted">{{ r.sku }}</span></span><span class="tabular-nums">{{ moneda(r.precio) }}</span></button>
              </div></div>
            <div class="flex flex-wrap gap-1.5 mt-2"><span v-for="a in aMano" :key="a.id" class="badge bg-gris-light text-xs">{{ a.nombre }} <button class="ml-1 text-carmin" @click="aMano = aMano.filter(x => x.id !== a.id)">×</button></span><span v-if="!aMano.length" class="text-xs text-marca-muted">Todavía no elegiste ninguno.</span></div>
          </div>
          <div v-else-if="grupo !== 'todo'" class="grid sm:grid-cols-3 gap-2 text-sm">
            <label v-if="grupo === 'rubro'" class="sm:col-span-2">Rubro<select v-model="sel.rubro_id" class="input" data-e2e="rubro"><option :value="null">Elegí…</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.parent_id ? '· ' : '' }}{{ r.nombre }}</option></select></label>
            <label v-if="grupo === 'proveedor'" class="sm:col-span-2">Proveedor<select v-model="sel.proveedor_id" class="input"><option :value="null">Elegí…</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
            <label v-if="grupo === 'marca'" class="sm:col-span-2">Marca<input v-model="sel.marca" class="input" list="marcas" /><datalist id="marcas"><option v-for="m in marcas" :key="m" :value="m" /></datalist></label>
            <label v-if="grupo === 'texto'" class="sm:col-span-2">Que digan…<input v-model="sel.texto" class="input" placeholder="parte del nombre o del código" data-e2e="texto" /></label>
            <template v-if="grupo === 'comprados'"><label>Comprados desde<input v-model="sel.comprados_desde" type="date" class="input" /></label><label>hasta<input v-model="sel.comprados_hasta" type="date" class="input" /></label></template>
            <label>Moneda<select v-model="sel.moneda" class="input"><option value="">Cualquiera</option><option value="ARS">Pesos</option><option value="USD">Dólares</option></select></label>
          </div>
          <p v-else class="text-sm text-marca-muted">Todos los artículos activos del catálogo.</p>
          <p class="text-sm mt-3" data-e2e="cuenta"><b class="text-lg tabular-nums">{{ cuenta.n ?? '—' }}</b> artículos <span v-if="cuenta.muestra?.length" class="text-xs text-marca-muted">· por ejemplo {{ cuenta.muestra.map(m => m.nombre).join(', ') }}{{ cuenta.n > cuenta.muestra.length ? '…' : '' }}</span></p>
        </div>

        <!-- Paso 3: precios -->
        <div v-if="que === 'precios'" class="card" data-e2e="paso3">
          <h2 class="font-bold mb-2"><span class="paso">3</span> ¿Cómo?</h2>
          <div class="grid sm:grid-cols-4 gap-2 text-sm">
            <label>Qué precio<select v-model="como.campo" class="input" data-e2e="campo"><option value="cost">El costo</option><option v-for="l in 6" :key="l" :value="String(l)">Lista {{ l }}</option></select></label>
            <label class="sm:col-span-2">Cambio<select v-model="como.modo" class="input" data-e2e="modo"><option value="porcentaje">Subir o bajar un porcentaje</option><option value="margen">Margen sobre el costo</option><option value="exacto">Precio exacto</option><option value="monto">Sumar o restar un monto fijo</option><option value="sobre_lista">Sobre otra lista (lista base + %)</option></select></label>
            <label>{{ como.modo === 'exacto' ? 'Precio' : como.modo === 'monto' ? 'Monto $' : '%' }}<input v-model.number="como.valor" type="number" step="0.01" class="input" data-e2e="valor" /></label>
            <label v-if="como.modo === 'sobre_lista'">Lista base<select v-model.number="como.lista_base" class="input"><option v-for="l in 6" :key="l" :value="l">Lista {{ l }}</option></select></label>
            <label v-if="como.modo === 'exacto'">Moneda<select v-model="como.moneda" class="input"><option value="">La de cada artículo</option><option value="ARS">Pesos</option><option value="USD">Dólares</option></select></label>
            <label>Redondeo<select v-model="como.redondeo" class="input"><option value="0">Centavos</option><option value="1">A $1</option><option value="10">A $10</option><option value="100">A $100</option></select></label>
            <label>Avisar si cambia más de<div class="flex items-center gap-1"><input v-model.number="como.limite_pct" type="number" class="input" /><span>%</span></div></label>
            <label v-if="como.campo === 'cost'" class="sm:col-span-2 flex items-center gap-2 self-end pb-2"><input v-model="como.recalcular_listas" type="checkbox" class="accent-carmin" /> Recalcular las listas de venta con los márgenes de cada artículo</label>
          </div>
          <p class="text-xs text-marca-muted mt-2">{{ explicacion }}</p>
          <div class="flex gap-2 mt-3"><button class="btn-primary" :disabled="cargando || !cuenta.n" @click="previsualizar" data-e2e="ver">Ver qué va a cambiar</button></div>
        </div>

        <!-- Paso 3: datos -->
        <div v-if="que === 'datos'" class="card" data-e2e="paso3d">
          <h2 class="font-bold mb-2"><span class="paso">3</span> ¿Qué dato cambiar?</h2>
          <p class="text-xs text-marca-muted mb-2">Lo que dejes vacío no se toca.</p>
          <div class="grid sm:grid-cols-3 gap-2 text-sm">
            <label>Rubro<select v-model="cambios.rubro_id" class="input"><option value="">— no tocar —</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.parent_id ? '· ' : '' }}{{ r.nombre }}</option></select></label>
            <label>Proveedor<select v-model="cambios.proveedor_id" class="input"><option value="">— no tocar —</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
            <label>Ubicación<input v-model="cambios.ubicacion" class="input" placeholder="góndola 3, estante B…" data-e2e="ubicacion" /></label>
            <label>IVA %<select v-model="cambios.iva" class="input"><option value="">— no tocar —</option><option v-for="i in ['21', '10.5', '27', '0']" :key="i" :value="i">{{ i }}%</option></select></label>
            <label>Stock mínimo<input v-model="cambios.stock_min" type="number" step="0.001" class="input" /></label>
            <label>Marca<input v-model="cambios.marca" class="input" list="marcas" /></label>
            <label>Moneda<select v-model="cambios.moneda" class="input"><option value="">— no tocar —</option><option value="ARS">Pesos</option><option value="USD">Dólares</option></select></label>
            <label>Estado<select v-model="cambios.active" class="input"><option value="">— no tocar —</option><option value="1">Activo</option><option value="0">Inactivo (no se vende más)</option></select></label>
            <label>Controla stock<select v-model="cambios.controla_stock" class="input"><option value="">— no tocar —</option><option value="1">Sí</option><option value="0">No</option></select></label>
            <label>En la tienda online<select v-model="cambios.en_tienda" class="input"><option value="">— no tocar —</option><option value="1">Sí</option><option value="0">No</option></select></label>
          </div>
          <div class="flex gap-2 mt-3"><button class="btn-primary" :disabled="cargando || !cuenta.n" @click="previsualizarDatos" data-e2e="ver-datos">Ver qué va a cambiar</button></div>
        </div>

        <!-- La pantalla que salva -->
        <div v-if="prev" class="card" data-e2e="preview">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <h2 class="font-bold">Así queda <span class="text-marca-muted font-normal text-sm">{{ prev.n }} artículos cambian · {{ incluidos }} tildados</span></h2>
            <div class="flex flex-wrap gap-1.5 text-xs">
              <span v-if="prev.avisos?.bajo_costo" class="badge bg-carmin-light text-carmin">{{ prev.avisos.bajo_costo }} bajo el costo</span>
              <span v-if="prev.avisos?.cero" class="badge bg-carmin-light text-carmin">{{ prev.avisos.cero }} en cero</span>
              <span v-if="prev.avisos?.mucho" class="badge bg-amber-100 text-amber-800">{{ prev.avisos.mucho }} cambian más de {{ prev.limite_pct }}%</span>
            </div>
          </div>
          <p v-if="prev.avisos && (prev.avisos.bajo_costo || prev.avisos.cero || prev.avisos.mucho)" class="text-xs text-marca-muted mb-2">Lo raro viene en rojo y destildado: revisalo y tildalo sólo si está bien. Se graba todo o nada.</p>
          <p v-if="prev.mezcla_monedas" class="text-xs text-amber-800 bg-amber-50 rounded-lg px-3 py-1.5 mb-2">Ojo: en la selección hay artículos en pesos y en dólares. Un precio exacto les queda a todos el mismo número.</p>
          <div class="max-h-[28rem] overflow-y-auto">
            <table class="table text-sm"><thead><tr><th><input type="checkbox" :checked="incluidos === prev.filas.length" @change="todos($event.target.checked)" /></th><th>Artículo</th>
              <template v-if="que === 'precios'"><th class="text-right">Antes</th><th class="text-right">Después</th><th class="text-right">Var.</th><th class="text-right">Costo</th></template><th v-else>Cambios</th></tr></thead>
              <tbody><tr v-for="f in prev.filas" :key="f.id" :class="f.alerta ? 'bg-red-50' : ''">
                <td><input type="checkbox" v-model="f.incluir" /></td>
                <td>{{ f.nombre }} <span class="text-xs text-marca-muted">{{ f.sku }}</span><span v-if="f.alerta" class="ml-1 badge bg-carmin-light text-carmin text-[10px]">{{ { bajo_costo: 'bajo costo', cero: 'cero', mucho: 'cambia mucho' }[f.alerta] }}</span></td>
                <template v-if="que === 'precios'"><td class="text-right tabular-nums">{{ moneda(f.antes) }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(f.despues) }}</td><td class="text-right tabular-nums" :class="f.var > 0 ? 'text-emerald-700' : f.var < 0 ? 'text-carmin' : ''">{{ f.var === null ? '—' : (f.var > 0 ? '+' : '') + f.var + '%' }}</td><td class="text-right tabular-nums text-marca-muted">{{ moneda(f.costo) }}</td></template>
                <td v-else class="text-xs"><span v-for="(c, k) in f.cambios" :key="k" class="mr-2">{{ campos[k] ?? k }}: <s class="text-marca-muted">{{ c.antes ?? '—' }}</s> → <b>{{ c.despues }}</b></span></td>
              </tr></tbody></table>
            <p v-if="prev.n > prev.filas.length" class="text-xs text-marca-muted mt-1">Se muestran {{ prev.filas.length }} de {{ prev.n }}; los demás cambian igual.</p>
          </div>
          <div class="flex items-center justify-between gap-2 mt-3">
            <button class="btn-secondary" @click="prev = null">Cambiar algo</button>
            <button class="btn-primary" :disabled="cargando || !incluidos" @click="aplicar" data-e2e="aplicar">Grabar {{ incluidos }} artículos</button>
          </div>
        </div>
      </div>

      <!-- Historial -->
      <div class="card self-start" data-e2e="historial">
        <h2 class="font-bold mb-2">Historial</h2>
        <p class="text-xs text-marca-muted mb-2">Quién, cuándo, qué. Se puede deshacer hasta 30 días después.</p>
        <div v-for="h in historial" :key="h.id" class="border-t border-marca-borde py-2 text-sm">
          <p><b>{{ h.descripcion }}</b></p>
          <p class="text-xs text-marca-muted">{{ h.fecha }} · {{ h.usuario }}</p>
          <details v-if="h.muestra.length" class="text-xs mt-1"><summary class="cursor-pointer text-violeta">Ver antes y después</summary>
            <div v-for="m in h.muestra" :key="m.id" class="py-0.5">{{ m.nombre }}: <span v-for="(v, k) in m.antes" :key="k" class="mr-1"><s class="text-marca-muted">{{ fmt(k, v) }}</s> → {{ fmt(k, m.despues?.[k] ?? m.despues?.cambios?.[k] ?? m.despues?.[k]) }}</span></div>
            <p v-if="h.n > h.muestra.length" class="text-marca-muted">…y {{ h.n - h.muestra.length }} más.</p>
          </details>
          <button v-if="h.se_puede_deshacer" class="text-xs text-carmin font-semibold mt-1" @click="deshacer(h)" :data-deshacer="h.id">Deshacer</button>
        </div>
        <p v-if="!historial.length" class="text-sm text-marca-muted">Todavía no hiciste ningún cambio masivo.</p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'

const props = defineProps({ rubros: Array, proveedores: Array, marcas: Array, campos: Object, historial: Array, ids: Array, inicial: Object })

const ques = [
  { k: 'precios', t: 'Cambiar precios', d: 'Subir, bajar, poner margen o un precio exacto.' },
  { k: 'datos', t: 'Cambiar datos', d: 'Rubro, ubicación, IVA, stock mínimo, estado…' },
  { k: 'crear', t: 'Crear artículos', d: 'Desde la lista de un proveedor.' },
  { k: 'comparar', t: 'Comparar proveedores', d: 'Quién te lo vende más barato, artículo por artículo (Consultor).' },
]
const grupos = [{ k: 'rubro', t: 'Un rubro' }, { k: 'proveedor', t: 'Un proveedor' }, { k: 'marca', t: 'Una marca' }, { k: 'texto', t: 'Los que dicen…' }, { k: 'comprados', t: 'Comprados entre fechas' }, { k: 'mano', t: 'A mano' }, { k: 'todo', t: 'Todo el catálogo' }]

const que = ref(props.inicial?.que === 'datos' ? 'datos' : 'precios')
const grupo = ref(props.ids?.length ? 'mano' : props.inicial?.rubro_id ? 'rubro' : props.inicial?.proveedor_id ? 'proveedor' : props.inicial?.texto ? 'texto' : 'rubro')
const sel = reactive({ rubro_id: props.inicial?.rubro_id ? Number(props.inicial.rubro_id) : null, proveedor_id: props.inicial?.proveedor_id ? Number(props.inicial.proveedor_id) : null, marca: '', texto: props.inicial?.texto ?? '', comprados_desde: '', comprados_hasta: '', moneda: '' })
const como = reactive({ campo: '1', modo: 'porcentaje', valor: 10, lista_base: 1, redondeo: '0', moneda: '', limite_pct: 50, recalcular_listas: true })
const cambios = reactive({ rubro_id: '', proveedor_id: '', ubicacion: '', iva: '', stock_min: '', marca: '', moneda: '', active: '', controla_stock: '', en_tienda: '' })
const aMano = ref([]); const busq = ref(''); const resultados = ref([])
const cuenta = ref({}); const prev = ref(null); const cargando = ref(false)

const elegirQue = k => { if (k === 'crear') return router.visit('/stock/importar'); if (k === 'comparar') return router.visit('/stock/consultor'); que.value = k; prev.value = null }

const seleccion = computed(() => {
  if (grupo.value === 'mano') return { ids: aMano.value.map(a => a.id) }
  if (grupo.value === 'todo') return { moneda: sel.moneda }
  const o = { moneda: sel.moneda }
  if (grupo.value === 'rubro') o.rubro_id = sel.rubro_id
  if (grupo.value === 'proveedor') o.proveedor_id = sel.proveedor_id
  if (grupo.value === 'marca') o.marca = sel.marca
  if (grupo.value === 'texto') o.texto = sel.texto
  if (grupo.value === 'comprados') { o.comprados_desde = sel.comprados_desde; o.comprados_hasta = sel.comprados_hasta }
  return o
})
const listaSel = computed(() => grupo.value === 'todo' || (grupo.value === 'mano' ? aMano.value.length > 0 : Object.entries(seleccion.value).some(([k, v]) => k !== 'moneda' && v)))

let t
watch(seleccion, () => { prev.value = null; clearTimeout(t); t = setTimeout(contar, 250) }, { deep: true })
async function contar() {
  if (!listaSel.value) { cuenta.value = {}; return }
  try { const { data } = await window.axios.post('/stock/precios/contar', { sel: seleccion.value }); cuenta.value = data } catch { cuenta.value = {} }
}

let tb
function buscar() { clearTimeout(tb); tb = setTimeout(async () => { if (!busq.value.trim()) return (resultados.value = []); const { data } = await window.axios.get('/stock/precios/buscar', { params: { q: busq.value } }); resultados.value = data }, 200) }
function agregar(r) { if (!aMano.value.find(x => x.id === r.id)) aMano.value.push(r); busq.value = ''; resultados.value = [] }

const explicacion = computed(() => {
  const q = como.campo === 'cost' ? 'el costo' : `la lista ${como.campo}`; const v = Number(como.valor) || 0
  return { porcentaje: `${v >= 0 ? 'Sube' : 'Baja'} ${Math.abs(v)}% ${q} de cada artículo.`, margen: `Pone ${q} = costo + ${v}% (los que no tienen costo no se tocan).`, exacto: `Pone ${q} en ${moneda(v)} para todos.`, monto: `${v >= 0 ? 'Suma' : 'Resta'} ${moneda(Math.abs(v))} a ${q}.`, sobre_lista: `Pone ${q} = lista ${como.lista_base} ${v >= 0 ? '+' : ''}${v}%.` }[como.modo]
})

async function previsualizar() {
  cargando.value = true
  try { const { data } = await window.axios.post('/stock/precios/masivo/previsualizar', { sel: seleccion.value, como }); prev.value = data } catch (e) { alert(e.response?.data?.message ?? 'No se pudo calcular.') } finally { cargando.value = false }
}
async function previsualizarDatos() {
  cargando.value = true
  try { const { data } = await window.axios.post('/stock/precios/datos/previsualizar', { sel: seleccion.value, cambios: limpios() }); data.filas.forEach(f => (f.incluir = true)); prev.value = data } catch (e) { alert(e.response?.data?.message ?? 'No se pudo calcular.') } finally { cargando.value = false }
}
const limpios = () => Object.fromEntries(Object.entries(cambios).filter(([, v]) => v !== '' && v !== null))
const incluidos = computed(() => prev.value?.filas.filter(f => f.incluir).length ?? 0)
const todos = v => prev.value.filas.forEach(f => (f.incluir = v))

function aplicar() {
  const excluir = prev.value.filas.filter(f => !f.incluir).map(f => f.id)
  // Los que no entraron en la muestra de la previsualización también se graban: la previsualización está limitada, la grabación no.
  const url = que.value === 'precios' ? '/stock/precios/masivo' : '/stock/precios/datos'
  const body = que.value === 'precios' ? { sel: seleccion.value, como, excluir } : { sel: seleccion.value, cambios: limpios(), excluir }
  cargando.value = true
  router.post(url, body, { preserveScroll: true, onSuccess: () => { prev.value = null; contar() }, onError: e => alert(Object.values(e)[0]), onFinish: () => (cargando.value = false) })
}
function deshacer(h) { if (!confirm(`¿Deshacer «${h.descripcion}»? Los ${h.n} artículos vuelven a los valores de antes.`)) return; router.post(`/stock/precios/deshacer/${h.id}`, {}, { preserveScroll: true, onSuccess: contar }) }

const fmt = (k, v) => v === null || v === undefined ? '—' : typeof v === 'object' ? Object.entries(v).map(([l, x]) => `L${l} ${moneda(Number(x))}`).join(' ') : ['price', 'cost'].includes(k) ? moneda(Number(v)) : typeof v === 'boolean' ? (v ? 'sí' : 'no') : String(v)

onMounted(async () => {
  if (props.ids?.length) { const { data } = await window.axios.post('/stock/precios/contar', { sel: { ids: props.ids } }); aMano.value = data.muestra }
  else contar()
})
</script>

<style scoped>
.paso { display: inline-flex; width: 1.5rem; height: 1.5rem; align-items: center; justify-content: center; border-radius: 9999px; background: #1f1f1f; color: #fff; font-size: 0.75rem; margin-right: 0.35rem; }
</style>
