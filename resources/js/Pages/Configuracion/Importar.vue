<template>
  <AppLayout titulo="Importar datos">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-1">Traer tus datos del sistema anterior o de Excel</h2>
        <p class="text-sm text-marca-muted mb-4">Subí un Excel (.xlsx) o CSV con encabezados. El sistema adivina qué columna es cada cosa y vos confirmás. Si un cliente o artículo ya existe (por CUIT, código o nombre) se actualiza, no se duplica.</p>
        <form v-if="!preview" @submit.prevent="sub.post('/configuracion/importar/previsualizar', { forceFormData: true, preserveScroll: true })" class="grid sm:grid-cols-3 gap-3 items-end">
          <div><label class="label">Qué vas a importar</label><select v-model="sub.entidad" class="input"><option v-for="e in entidades" :key="e.key" :value="e.key">{{ e.label }}</option></select></div>
          <div><label class="label">Archivo</label><input type="file" accept=".xlsx,.csv,.txt" @change="sub.archivo = $event.target.files[0]" class="input !py-1.5 text-xs" /></div>
          <button class="btn-primary" :disabled="sub.processing || !sub.archivo">Leer archivo</button>
          <p v-if="sub.errors.archivo" class="text-carmin text-xs sm:col-span-3">{{ sub.errors.archivo }}</p>
        </form>

        <div v-else>
          <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <p class="text-sm"><b>{{ preview.archivo }}</b> · {{ preview.total }} filas de {{ entidadActual.label.toLowerCase() }}</p>
            <button class="btn-ghost text-xs" @click="router.get('/configuracion/importar')">Elegir otro archivo</button>
          </div>
          <p class="text-xs text-marca-muted mb-2">Decí qué es cada columna (dejá en blanco las que no importan):</p>
          <div class="overflow-x-auto border border-marca-borde rounded-xl">
            <table class="table text-xs">
              <thead><tr><th v-for="(h, i) in preview.encabezado" :key="i" class="min-w-40 align-top"><div class="font-bold mb-1 truncate" :title="h">{{ h || 'Columna ' + (i + 1) }}</div><select v-model="ap.mapeo[i]" class="input !py-1 text-xs font-normal"><option :value="null">— ignorar —</option><option v-for="(lbl, k) in entidadActual.campos" :key="k" :value="k">{{ lbl }}</option></select></th></tr></thead>
              <tbody><tr v-for="(f, r) in preview.muestra" :key="r"><td v-for="(h, i) in preview.encabezado" :key="i" class="truncate max-w-48 text-marca-muted">{{ f[i] }}</td></tr></tbody>
            </table>
          </div>
          <div class="flex flex-wrap gap-4 items-end mt-4">
            <label v-if="preview.entidad === 'articulos'" class="flex items-center gap-2 text-sm"><input v-model="ap.stock_inicial" type="checkbox" class="accent-carmin" /> Cargar el stock inicial de los artículos nuevos</label>
            <template v-else>
              <label class="flex items-center gap-2 text-sm"><input v-model="ap.saldos" type="checkbox" class="accent-carmin" /> Cargar los saldos iniciales en cuenta corriente</label>
              <div v-if="ap.saldos"><label class="label">Fecha de los saldos</label><input v-model="ap.fecha_saldos" type="date" class="input" /></div>
            </template>
            <button class="btn-primary ml-auto" :disabled="ap.processing || !Object.values(ap.mapeo).some(Boolean)" @click="aplicar">{{ ap.processing ? 'Importando…' : `Importar ${preview.total} filas` }}</button>
          </div>
          <p v-if="ap.errors.mapeo" class="text-carmin text-xs mt-2">{{ ap.errors.mapeo }}</p>
        </div>
      </div>

      <div class="card">
        <h2 class="font-bold mb-2">Cómo armar el archivo</h2>
        <ul class="text-sm text-marca-muted space-y-2 list-disc pl-4">
          <li>Primera fila con los nombres de columna (ej. <code>Nombre;CUIT;Saldo</code>).</li>
          <li><b>Clientes / proveedores:</b> nombre obligatorio; CUIT, condición IVA, email, teléfono, dirección, lista, límite y saldo son opcionales.</li>
          <li><b>Artículos:</b> descripción obligatoria; código, barras, rubro, costo, precios por lista, IVA y stock son opcionales. El rubro se crea si no existe.</li>
          <li>Saldo positivo = el cliente te debe (o le debés al proveedor). Negativo = a favor.</li>
          <li>Podés importar varias veces: lo existente se actualiza.</li>
        </ul>
        <p class="text-xs text-marca-muted mt-3">Del BigSys viejo: exportá cada listado a Excel desde el menú Listados y subilo acá tal cual.</p>
      </div>
    </div>

    <div class="card mt-4 p-0 overflow-hidden">
      <div class="px-4 py-3 border-b border-marca-borde font-bold">Importaciones anteriores</div>
      <table class="table text-sm">
        <thead><tr><th>Fecha</th><th>Qué</th><th>Archivo</th><th class="text-right">Leídas</th><th class="text-right">Nuevas</th><th class="text-right">Actualizadas</th><th class="text-right">Errores</th><th>Usuario</th></tr></thead>
        <tbody>
          <template v-for="h in historial" :key="h.id">
            <tr><td class="tabular-nums">{{ h.fecha }}</td><td>{{ h.entidad }}</td><td class="text-marca-muted">{{ h.archivo }}</td><td class="text-right tabular-nums">{{ h.leidas }}</td><td class="text-right tabular-nums text-emerald-700">{{ h.creadas }}</td><td class="text-right tabular-nums">{{ h.actualizadas }}</td><td class="text-right tabular-nums" :class="h.errores ? 'text-carmin font-bold' : ''">{{ h.errores }}</td><td>{{ h.usuario }}</td></tr>
            <tr v-if="h.errores && h.detalle?.length"><td colspan="8" class="text-xs text-carmin bg-red-50/40"><span v-for="(d, i) in h.detalle.slice(0, 10)" :key="i" class="block">{{ d }}</span></td></tr>
          </template>
          <tr v-if="!historial.length"><td colspan="8" class="text-center text-marca-muted py-6">Todavía no importaste nada.</td></tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
import { hoyISO } from '@/util/formato'
const props = defineProps({ entidades: Array, historial: Array })
const page = usePage()
const preview = computed(() => page.props.flash?.preview)
const entidadActual = computed(() => props.entidades.find(e => e.key === (preview.value?.entidad ?? sub.entidad)) ?? props.entidades[0])
const sub = useForm({ entidad: 'clientes', archivo: null })
const ap = useForm({ entidad: '', archivo: '', filas: [], mapeo: {}, stock_inicial: true, saldos: true, fecha_saldos: hoyISO() })
watch(preview, p => { if (p) { ap.entidad = p.entidad; ap.archivo = p.archivo; ap.filas = p.filas; ap.mapeo = Object.fromEntries(p.encabezado.map((_, i) => [String(i), p.mapeo?.[i] ?? null])) } }, { immediate: true })
function aplicar() { ap.post('/configuracion/importar/aplicar', { preserveScroll: true, onSuccess: () => router.get('/configuracion/importar') }) }
</script>
