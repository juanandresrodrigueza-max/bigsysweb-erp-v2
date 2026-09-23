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

        <!-- Paso 3 -->
        <div v-if="prev" class="card">
          <div class="flex items-center gap-3 mb-3"><span class="w-7 h-7 rounded-lg grid place-items-center bg-carmin text-white font-extrabold text-sm">3</span><h2 class="font-bold">Cómo se cargan</h2></div>
          <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div><label class="label">Margen para lista 1 (%)</label><input v-model="opt.margen" type="number" step="any" min="0" class="input" placeholder="Vacío = no tocar" /><p class="text-[10px] text-marca-muted mt-1">Si lo cargás, el precio de venta = costo + margen y queda guardado en el artículo.</p></div>
            <div><label class="label">IVA si el archivo no lo trae</label><select v-model.number="opt.iva" class="input"><option v-for="a in [0,10.5,21,27]" :key="a" :value="a">{{ a }}%</option></select></div>
            <div><label class="label">Rubro para los nuevos</label><select v-model="opt.rubro_id" class="input"><option :value="null">El del archivo / ninguno</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.nombre }}</option></select></div>
            <div><label class="label">Moneda de la lista</label><select v-model="opt.moneda" class="input"><option value="ARS">Pesos</option><option value="USD">Dólares</option></select></div>
            <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="opt.crear" type="checkbox" class="accent-carmin" /> Crear los artículos que no existan</label>
            <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="opt.solo_proveedor" type="checkbox" class="accent-carmin" /> Buscar por descripción solo entre artículos de este proveedor</label>
          </div>
          <p v-if="form.errors.mapeo || form.errors.archivo" class="text-carmin text-xs mt-2">{{ form.errors.mapeo || form.errors.archivo }}</p>
          <button @click="aplicar" class="btn-primary mt-4" :disabled="form.processing">{{ form.processing ? 'Importando…' : `Importar ${prev.total - (opt.encabezado ? 1 : 0)} filas` }}</button>
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
import { ref, reactive } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({ proveedores: Array, rubros: Array, campos: Object, ultimas: Array })
const prev = ref(null), error = ref(null), mapeo = reactive({})
const opt = reactive({ contact_id: null, encabezado: true, margen: '', iva: 21, rubro_id: null, moneda: 'ARS', crear: true, solo_proveedor: false })
const form = useForm({})
async function subir(file) {
  if (!file) return
  error.value = null; prev.value = null
  try {
    const fd = new FormData(); fd.append('archivo', file)
    const { data } = await window.axios.post('/stock/importar/previsualizar', fd)
    prev.value = data; Object.keys(mapeo).forEach(k => delete mapeo[k]); Object.assign(mapeo, data.mapeo)
  } catch (e) { error.value = e.response?.data?.message ?? e.response?.data?.errors?.archivo?.[0] ?? 'No se pudo leer el archivo.' }
}
function aplicar() {
  form.transform(() => ({ path: prev.value.path, nombre: prev.value.nombre, mapeo: Object.fromEntries(Object.entries(mapeo).filter(([, v]) => v)), ...opt })).post('/stock/importar/aplicar', { onSuccess: () => (prev.value = null) })
}
</script>
