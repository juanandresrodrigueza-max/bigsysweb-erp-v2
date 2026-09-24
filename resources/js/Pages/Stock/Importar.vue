<template>
  <AppLayout titulo="Importar lista de precios">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/stock" class="text-xs text-marca-muted hover:text-carmin">← Stock</Link>
        <h1 class="page-title">Importar lista de precios</h1>
        <p class="page-subtitle">Subí el Excel o CSV del proveedor: se crean los artículos que falten y se actualizan los precios de los que ya existen.</p>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <!-- Paso 1 -->
        <div class="card">
          <div class="flex items-center gap-3 mb-3"><span class="w-7 h-7 rounded-lg grid place-items-center bg-carmin text-white font-extrabold text-sm">1</span><h2 class="font-bold">Elegí el archivo</h2></div>
          <div class="grid sm:grid-cols-2 gap-3">
            <div><label class="label">Proveedor</label><select v-model="opt.contact_id" class="input"><option :value="null">Sin proveedor</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
            <div><label class="label">Archivo (.xlsx, .csv)</label><input type="file" accept=".xlsx,.csv,.txt" class="input !py-1.5 text-xs" @change="subir($event.target.files[0])" /></div>
          </div>
          <p v-if="error" class="text-carmin text-xs mt-2">{{ error }}</p>
          <p v-if="prev" class="text-xs text-marca-muted mt-2">{{ prev.nombre }} · {{ prev.total }} filas · {{ prev.columnas }} columnas</p>
        </div>

        <!-- Paso 2 -->
        <div v-if="prev" class="card p-0 overflow-hidden">
          <div class="flex items-center gap-3 px-4 py-3 border-b border-marca-borde"><span class="w-7 h-7 rounded-lg grid place-items-center bg-carmin text-white font-extrabold text-sm">2</span><h2 class="font-bold">Decile al sistema qué es cada columna</h2></div>
          <div class="overflow-x-auto">
            <table class="table text-xs">
              <thead>
                <tr><th v-for="(c, i) in prev.muestra[0]" :key="i" class="min-w-[140px]">
                  <select v-model="mapeo[i]" class="input !py-1 text-xs" :class="mapeo[i] ? 'border-violeta text-violeta font-semibold' : ''"><option :value="undefined">— ignorar —</option><option v-for="(l, k) in campos" :key="k" :value="k">{{ l }}</option></select>
                </th></tr>
              </thead>
              <tbody><tr v-for="(f, r) in prev.muestra" :key="r" :class="r === 0 && opt.encabezado ? 'text-marca-muted italic' : ''"><td v-for="(c, i) in f" :key="i" class="truncate max-w-[200px]">{{ c }}</td></tr></tbody>
            </table>
          </div>
          <label class="flex items-center gap-2 text-sm px-4 py-3 border-t border-marca-borde"><input v-model="opt.encabezado" type="checkbox" class="accent-carmin" /> La primera fila es el encabezado (no se importa)</label>
        </div>

        <!-- Paso 3: revisión -->
        <div v-if="prev" class="card">
          <div class="flex flex-wrap items-center gap-3 mb-3"><span class="w-7 h-7 rounded-lg grid place-items-center bg-carmin text-white font-extrabold text-sm">3</span><h2 class="font-bold">Revisá qué va a pasar</h2><span v-if="prev.mapeo_ia" class="badge bg-violeta/10 text-violeta">Columnas reconocidas con IA</span><button class="btn-violeta !py-1 text-xs ml-auto" :disabled="analizando || !Object.values(mapeo).some(Boolean)" @click="analizar">{{ analizando ? 'Cruzando con tu catálogo…' : (an ? 'Volver a cruzar' : 'Cruzar con mi catálogo') }}</button></div>
          <p v-if="!an" class="text-xs text-marca-muted">Antes de importar, el sistema cruza cada fila con tus artículos: por código, código de barras, nombre exacto o parecido{{ ' ' }}<span class="text-violeta">y con la IA para los que quedan en duda</span>. Vos confirmás lo que se crea y lo que se actualiza.</p>
          <template v-else>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs mb-3">
              <div class="rounded-lg bg-marca-fondo p-2"><p class="text-marca-muted">Filas</p><b class="text-base">{{ an.resumen.total }}</b></div>
              <div class="rounded-lg bg-emerald-50 p-2"><p class="text-emerald-700">Ya existen</p><b class="text-base text-emerald-700">{{ an.resumen.existentes }}</b></div>
              <div class="rounded-lg bg-violeta/10 p-2"><p class="text-violeta">Parecidos (confirmar)</p><b class="text-base text-violeta">{{ an.resumen.sugeridos }}</b></div>
              <div class="rounded-lg bg-amber-50 p-2"><p class="text-amber-700">Nuevos</p><b class="text-base text-amber-700">{{ an.resumen.nuevos }}</b></div>
              <div class="rounded-lg bg-red-50 p-2"><p class="text-carmin">Suben +30% / sin precio</p><b class="text-base text-carmin">{{ an.resumen.suben_mucho }} / {{ an.resumen.sin_precio }}</b></div>
            </div>
            <p v-if="!an.resumen.ia" class="text-[11px] text-amber-700 mb-2">Sin clave de IA: el cruce se hizo por código y nombre parecido. Con la clave, la IA también reconoce abreviaturas y sugiere el rubro de los nuevos.</p>
            <div class="flex flex-wrap gap-2 mb-2 text-xs"><button v-for="(l, k) in { todos: 'Todas', sugerido: 'Parecidos', nuevo: 'Nuevos', existente: 'Existentes', sube: 'Suben mucho' }" :key="k" class="px-2.5 py-1 rounded-full border" :class="vista === k ? 'bg-violeta text-white border-violeta' : 'border-marca-borde text-marca-muted'" @click="vista = k">{{ l }}</button><span class="ml-auto text-marca-muted self-center">Para los "parecidos": aceptá, elegí otro artículo o marcalo como nuevo.</span></div>
            <div class="overflow-x-auto max-h-[60vh] border border-marca-borde rounded-xl">
              <table class="table text-xs">
                <thead><tr><th>Fila del proveedor</th><th>Va a…</th><th class="text-right">Costo actual → nuevo</th><th class="text-right">Var.</th><th>Qué hacer</th></tr></thead>
                <tbody>
                  <tr v-for="r in filasVista" :key="r.fila" :class="{ 'bg-violeta/5': r.estado === 'sugerido', 'bg-red-50/40': r.sin_precio }">
                    <td><p class="font-medium">{{ r.desc }}</p><p class="text-marca-muted">{{ r.codigo }}<span v-if="r.barcode"> · {{ r.barcode }}</span></p></td>
                    <td>
                      <template v-if="dec[r.fila] === 'omitir'"><span class="text-marca-muted">Se omite</span></template>
                      <template v-else-if="dec[r.fila] === 'nuevo' || (r.estado === 'nuevo' && !dec[r.fila])"><span class="badge bg-amber-50 text-amber-700">Nuevo</span> <input v-model="nombres[r.fila]" class="input !py-0.5 text-xs mt-1" :placeholder="r.desc" /><select v-model="rubrosFila[r.fila]" class="input !py-0.5 text-xs mt-1"><option :value="null">Rubro: el de la opción general</option><option v-for="rb in rubros" :key="rb.id" :value="rb.id">{{ rb.nombre }}</option></select></template>
                      <template v-else><span class="badge" :class="r.estado === 'existente' && !dec[r.fila] ? 'bg-emerald-50 text-emerald-700' : 'bg-violeta/10 text-violeta'">{{ r.estado === 'existente' && !dec[r.fila] ? 'Existe' : `Parecido ${r.confianza}%` }}<span v-if="r.ia"> · IA</span></span><p class="font-medium mt-0.5">{{ nombreDe(dec[r.fila] || r.product_id) }}</p></template>
                    </td>
                    <td class="text-right tabular-nums whitespace-nowrap"><span v-if="r.costo_actual !== null && dec[r.fila] !== 'nuevo'" class="text-marca-muted">{{ moneda(r.costo_actual, 0) }} → </span><b v-if="r.costo_nuevo !== null">{{ moneda(r.costo_nuevo, 0) }}</b><span v-else-if="r.precio_venta !== null">venta {{ moneda(r.precio_venta, 0) }}</span><span v-else class="text-carmin">sin precio</span></td>
                    <td class="text-right tabular-nums font-semibold" :class="r.variacion > 30 ? 'text-carmin' : r.variacion < 0 ? 'text-emerald-700' : ''">{{ r.variacion !== null && dec[r.fila] !== 'nuevo' ? (r.variacion > 0 ? '+' : '') + r.variacion + '%' : '' }}</td>
                    <td class="whitespace-nowrap">
                      <select class="input !py-0.5 text-xs !w-auto" :value="dec[r.fila] ?? (r.product_id ? String(r.product_id) : 'nuevo')" @change="dec[r.fila] = $event.target.value">
                        <option v-if="r.product_id" :value="String(r.product_id)">{{ r.estado === 'sugerido' ? 'Aceptar: ' : 'Actualizar: ' }}{{ r.product_nombre }}</option>
                        <option value="nuevo">Crear como artículo nuevo</option>
                        <option value="omitir">Omitir esta fila</option>
                        <option value="__otro">Elegir otro artículo…</option>
                      </select>
                      <BuscadorSelect v-if="dec[r.fila] === '__otro'" :modelValue="null" :opciones="opcionesArticulos" placeholder="Buscar artículo…" class="mt-1" @update:modelValue="v => { if (v) dec[r.fila] = String(v) }" />
                    </td>
                  </tr>
                  <tr v-if="!filasVista.length"><td colspan="5" class="text-center text-marca-muted py-5">Nada en esta vista.</td></tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>

        <!-- Paso 4 -->
        <div v-if="prev" class="card">
          <div class="flex items-center gap-3 mb-3"><span class="w-7 h-7 rounded-lg grid place-items-center bg-carmin text-white font-extrabold text-sm">4</span><h2 class="font-bold">Cómo se cargan</h2></div>
          <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div><label class="label">Margen para lista 1 (%)</label><input v-model="opt.margen" type="number" step="any" min="0" class="input" placeholder="Vacío = no tocar" /><p class="text-[10px] text-marca-muted mt-1">Si lo cargás, el precio de venta = costo + margen y queda guardado en el artículo.</p></div>
            <div><label class="label">IVA si el archivo no lo trae</label><select v-model.number="opt.iva" class="input"><option v-for="a in [0,10.5,21,27]" :key="a" :value="a">{{ a }}%</option></select></div>
            <div><label class="label">Rubro para los nuevos</label><select v-model="opt.rubro_id" class="input"><option :value="null">El del archivo / ninguno</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.nombre }}</option></select></div>
            <div><label class="label">Moneda de la lista</label><select v-model="opt.moneda" class="input"><option value="ARS">Pesos</option><option value="USD">Dólares</option></select></div>
            <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="opt.crear" type="checkbox" class="accent-carmin" /> Crear los artículos que no existan</label>
            <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="opt.solo_proveedor" type="checkbox" class="accent-carmin" /> Buscar por descripción solo entre artículos de este proveedor</label>
          </div>
          <p v-if="form.errors.mapeo || form.errors.archivo" class="text-carmin text-xs mt-2">{{ form.errors.mapeo || form.errors.archivo }}</p>
          <button @click="aplicar" class="btn-primary mt-4" :disabled="form.processing">{{ form.processing ? 'Importando…' : (an ? `Importar (${an.resumen.total - omitidas} filas)` : `Importar ${prev.total - (opt.encabezado ? 1 : 0)} filas`) }}</button>
          <p v-if="!an" class="text-[11px] text-marca-muted mt-1">Tip: cruzá primero con tu catálogo (paso 3) para no crear duplicados.</p>
        </div>
      </div>

      <div class="card">
        <h2 class="font-bold mb-3">Últimas importaciones</h2>
        <div v-for="u in ultimas" :key="u.id" class="py-2 border-t border-marca-borde first:border-0 text-sm">
          <p class="font-semibold truncate">{{ u.archivo }}</p>
          <p class="text-xs text-marca-muted">{{ u.fecha }} · {{ u.proveedor ?? 'sin proveedor' }} · {{ u.usuario }}</p>
          <p class="text-xs mt-1"><span class="text-emerald-700">{{ u.creados }} nuevos</span> · <span class="text-violeta">{{ u.actualizados }} actualizados</span> · <span :class="u.errores ? 'text-carmin' : 'text-marca-muted'">{{ u.errores }} errores</span></p>
          <details v-if="u.detalle?.length" class="text-xs mt-1"><summary class="cursor-pointer text-marca-muted">Ver errores</summary><p v-for="(d, i) in u.detalle" :key="i">Fila {{ d.fila }}: {{ d.desc }} → {{ d.error }}</p></details>
        </div>
        <p v-if="!ultimas.length" class="text-sm text-marca-muted">Todavía no importaste ninguna.</p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import { moneda } from '@/util/formato'

const props = defineProps({ proveedores: Array, rubros: Array, campos: Object, ultimas: Array, articulos: { type: Array, default: () => [] } })
const an = ref(null), analizando = ref(false), vista = ref('todos'), dec = reactive({}), nombres = reactive({}), rubrosFila = reactive({})
const opcionesArticulos = computed(() => props.articulos.map(a => ({ id: a.id, label: a.name, sub: a.sku })))
const nombreDe = id => props.articulos.find(a => a.id === Number(id))?.name ?? an.value?.filas.find(f => String(f.product_id) === String(id))?.product_nombre ?? ''
const filasVista = computed(() => (an.value?.filas ?? []).filter(r => vista.value === 'todos' || (vista.value === 'sube' ? (r.variacion ?? 0) > 30 : r.estado === vista.value)))
const omitidas = computed(() => Object.values(dec).filter(v => v === 'omitir').length)
async function analizar() {
  analizando.value = true
  try { const { data } = await window.axios.post('/stock/importar/analizar', { path: prev.value.path, nombre: prev.value.nombre, mapeo: Object.fromEntries(Object.entries(mapeo).filter(([, v]) => v)), encabezado: opt.encabezado, contact_id: opt.contact_id, solo_proveedor: opt.solo_proveedor }); an.value = data; Object.keys(dec).forEach(k => delete dec[k]); data.filas.forEach(r => { dec[r.fila] = r.product_id ? String(r.product_id) : 'nuevo'; if (r.rubro_sugerido) rubrosFila[r.fila] = r.rubro_sugerido; if (r.nombre_limpio) nombres[r.fila] = r.nombre_limpio }) }
  catch (e) { error.value = e.response?.data?.message ?? 'No se pudo cruzar la lista.' }
  finally { analizando.value = false }
}
const prev = ref(null), error = ref(null), mapeo = reactive({})
const opt = reactive({ contact_id: null, encabezado: true, margen: '', iva: 21, rubro_id: null, moneda: 'ARS', crear: true, solo_proveedor: false })
const form = useForm({})
async function subir(file) {
  if (!file) return
  error.value = null; prev.value = null
  try {
    const fd = new FormData(); fd.append('archivo', file)
    const { data } = await window.axios.post('/stock/importar/previsualizar', fd)
    prev.value = data; an.value = null; Object.keys(mapeo).forEach(k => delete mapeo[k]); Object.assign(mapeo, data.mapeo)
  } catch (e) { error.value = e.response?.data?.message ?? e.response?.data?.errors?.archivo?.[0] ?? 'No se pudo leer el archivo.' }
}
function aplicar() {
  const decisiones = Object.fromEntries(Object.entries(dec).filter(([, v]) => v && v !== '__otro'))
  form.transform(() => ({ path: prev.value.path, nombre: prev.value.nombre, mapeo: Object.fromEntries(Object.entries(mapeo).filter(([, v]) => v)), ...opt, decisiones, nombres: Object.fromEntries(Object.entries(nombres).filter(([, v]) => v)), rubros_fila: Object.fromEntries(Object.entries(rubrosFila).filter(([, v]) => v)) })).post('/stock/importar/aplicar', { onSuccess: () => { prev.value = null; an.value = null } })
}
</script>
