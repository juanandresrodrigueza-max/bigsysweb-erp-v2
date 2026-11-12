<template>
  <AppLayout titulo="Importar datos">
    <h1 class="page-title mb-4">Importar datos</h1>

    <div class="card mb-4" data-e2e="bigconnect">
      <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-bold">Traer todo desde BIG Connect</h2><span class="badge" :class="bigconnect?.conectado ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light'">{{ bigconnect?.conectado ? 'Conectado · ' + bigconnect.clave + (bigconnect.ultima ? ' · última migración ' + bigconnect.ultima : '') : 'Sin conectar' }}</span></div>
      <p class="text-sm text-marca-muted mt-1">Para clientes de BigSys que ya usan BIG Connect: proveedores, clientes, artículos con sus 6 listas y stock, y lo que cada cuenta tiene impago, comprobante por comprobante. Sin exportar planillas. Se puede repetir el día del corte: actualiza, no duplica.</p>
      <form class="grid sm:grid-cols-[1fr_1fr_8rem_auto] gap-2 items-end mt-3" @submit.prevent="bc.post('/configuracion/importar/bigconnect', { preserveScroll: true, onSuccess: () => bc.reset('clave') })">
        <div><label class="label">Dirección del Hub</label><input v-model="bc.url" class="input" placeholder="https://hub.bigsys.com.ar" /></div>
        <div><label class="label">Clave de API (bk_…)</label><input v-model="bc.clave" type="password" autocomplete="off" class="input font-mono" :placeholder="bigconnect?.conectado ? 'Dejala vacía para mantenerla' : 'bk_…'" /></div>
        <div><label class="label">Empresa</label><input v-model="bc.empresa" class="input" placeholder="e01" /></div>
        <button class="btn-secondary" :disabled="bc.processing">{{ bc.processing ? 'Probando…' : 'Conectar' }}</button>
      </form>
      <p v-if="bc.errors.url || bc.errors.clave" class="text-sm text-carmin mt-1">{{ bc.errors.url || bc.errors.clave }}</p>
      <div v-if="bigconnect?.conectado" class="flex flex-wrap gap-2 mt-3">
        <button class="btn-secondary" :disabled="mig.processing" @click="migrar(true)" data-e2e="bc-prueba">{{ mig.processing ? 'Trayendo…' : '1. Probar sin guardar' }}</button>
        <button class="btn-primary" :disabled="mig.processing || !migracion?.prueba" :title="migracion?.prueba ? '' : 'Primero hacé la prueba'" @click="migrar(false)" data-e2e="bc-migrar">2. Migrar de verdad</button>
      </div>
      <div v-if="migracion" class="mt-4" data-e2e="bc-resultado">
        <p class="text-sm font-semibold" :class="migracion.prueba ? 'text-violeta' : 'text-emerald-700'">{{ migracion.prueba ? 'Resultado de la prueba (no se guardó nada)' : 'Migración guardada' }}</p>
        <div class="overflow-x-auto"><table class="w-full text-sm mt-2"><thead><tr class="text-left text-marca-muted"><th class="py-1">Qué</th><th>En BigSys</th><th>Nuevos</th><th>Actualizados</th><th>Con error</th></tr></thead>
          <tbody><tr v-for="p in migracion.pasos" :key="p.paso" class="border-t border-marca-borde/60"><td class="py-1">{{ p.que }}</td><td>{{ p.en_bigsys }}</td><td>{{ p.creadas }}</td><td>{{ p.actualizadas }}</td><td :class="p.errores ? 'text-carmin font-semibold' : ''">{{ p.errores }}<span v-if="p.detalle?.length" class="block text-xs font-normal">{{ p.detalle.join(' · ') }}</span></td></tr></tbody></table></div>
        <p v-if="migracion.cuenta_contado" class="text-xs text-marca-muted mt-1">No se traen {{ migracion.cuenta_contado }} cuenta(s) de contado / consumidor final: en el ERP ya existe.</p>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-1">Traer tus datos del sistema anterior o de Excel</h2>
        <p class="text-sm text-marca-muted mb-4">Subí un Excel (.xlsx) o CSV con encabezados. El sistema adivina qué columna es cada cosa y vos confirmás. Si un cliente o artículo ya existe (por CUIT, código o nombre) se actualiza, no se duplica.</p>
        <form v-if="!preview" @submit.prevent="sub.post('/configuracion/importar/previsualizar', { forceFormData: true, preserveScroll: true })" class="grid sm:grid-cols-4 gap-3 items-end">
          <div><label class="label">Qué vas a importar</label><select v-model="sub.entidad" class="input"><option v-for="e in entidades" :key="e.key" :value="e.key">{{ e.label }}</option></select></div>
          <div><label class="label">Viene de</label><select v-model="sub.perfil" class="input"><option value="">Excel / CSV genérico</option><option value="tango">Tango Gestión</option><option value="bejerman">Bejerman / Softland</option><option value="colppy">Colppy</option><option value="bigsys">BigSys (Clarion)</option></select></div>
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
            <template v-if="preview.entidad === 'articulos'">
              <label class="flex items-center gap-2 text-sm"><input v-model="ap.stock_inicial" type="checkbox" class="accent-carmin" /> Cargar el stock inicial de los artículos nuevos</label>
              <label class="flex items-center gap-2 text-sm"><input v-model="ap.ajustar_stock" type="checkbox" class="accent-carmin" /> Ajustar el stock de los que ya existen al valor del archivo</label>
              <label class="flex items-center gap-2 text-sm"><input v-model="ap.precios_con_iva" type="checkbox" class="accent-carmin" data-precios-con-iva /> Los precios del archivo son finales con IVA (se pasan a neto con el IVA de cada artículo)</label>
            </template>
            <p v-if="preview.perfil === 'bigsys' && preview.entidad.startsWith('saldos')" class="text-xs text-violeta sm:col-span-2">Con la tabla de movimientos (mov) el saldo de cada cuenta se calcula sumando debe menos haber sin los anulados. No se usa totsal porque no descuenta los pagos a cuenta.</p>
            <p v-if="preview.perfil === 'bigsys' && !preview.entidad.startsWith('saldos') && preview.entidad !== 'articulos'" class="text-xs text-violeta sm:col-span-2">La tabla de cuentas de BigSys trae clientes, proveedores, empleados y agenda: se importan solo los de tipo {{ preview.entidad === 'clientes' ? 'C' : 'P' }} y se guarda el código de cada uno para cruzar los saldos.</p>
            <template v-else-if="preview.entidad.startsWith('saldos')">
              <label class="flex items-center gap-2 text-sm"><input v-model="ap.reemplazar_saldos" type="checkbox" class="accent-carmin" /> Si el comprobante ya estaba cargado, reemplazarlo</label>
              <div><label class="label">Fecha si la fila no la trae</label><input v-model="ap.fecha_saldos" type="date" class="input" /></div>
            </template>
            <template v-else>
              <label class="flex items-center gap-2 text-sm"><input v-model="ap.saldos" type="checkbox" class="accent-carmin" /> Cargar los saldos iniciales en cuenta corriente</label>
              <label v-if="ap.saldos" class="flex items-center gap-2 text-sm"><input v-model="ap.reemplazar_saldos" type="checkbox" class="accent-carmin" /> Reemplazar el saldo inicial de los que ya existen</label>
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
          <li><b>Artículos:</b> descripción obligatoria; código, barras, rubro, costo, 6 listas, IVA, stock y descuentos por cantidad son opcionales. El rubro se crea si no existe; con <code>Hierros / Aletado / Chicos</code> se crean los niveles.</li>
          <li><b>Saldos por comprobante:</b> una fila por factura pendiente con fecha, vencimiento e importe. Así funcionan mora, antigüedad y cobranzas desde el primer día.</li>
          <li>Saldo positivo = el cliente te debe (o le debés al proveedor). Negativo = a favor.</li>
          <li>Podés importar varias veces: lo existente se actualiza.</li>
        </ul>
        <p class="text-xs text-marca-muted mt-3">Del BigSys viejo: exportá cada listado a Excel desde el menú Listados y subilo acá tal cual. Orden recomendado: proveedores, clientes, artículos y por último los saldos por comprobante.</p>
        <div class="mt-4 pt-3 border-t border-marca-borde">
          <p class="label">Plantillas y exportación</p>
          <p class="text-xs text-marca-muted mb-2">Bajá la plantilla con un ejemplo, completala y subila. La exportación usa las mismas columnas: sirve de backup y para pasar datos entre empresas.</p>
          <div v-for="e in entidades" :key="e.key" class="flex items-center justify-between gap-2 py-1 text-xs border-t border-marca-borde/60 first:border-0"><span class="truncate">{{ e.label }}</span><span class="flex gap-2 shrink-0"><a :href="`/configuracion/importar/plantilla/${e.key}`" class="text-violeta font-semibold">Plantilla</a><a :href="`/configuracion/importar/exportar/${e.key}`" class="text-carmin font-semibold">Exportar</a></span></div>
        </div>
      </div>
    </div>

    <div class="card mt-4 p-0 overflow-hidden">
      <div class="px-4 py-3 border-b border-marca-borde font-bold">Importaciones anteriores</div>
      <table class="table text-sm">
        <thead><tr><th>Fecha</th><th>Qué</th><th>Archivo</th><th class="text-right">Leídas</th><th class="text-right">Nuevas</th><th class="text-right">Actualizadas</th><th class="text-right">Errores</th><th>Usuario</th></tr></thead>
        <tbody>
          <template v-for="h in historial" :key="h.id">
            <tr><td class="tabular-nums">{{ h.fecha }}</td><td>{{ h.entidad }}</td><td class="text-marca-muted">{{ h.archivo }}</td><td class="text-right tabular-nums">{{ h.leidas }}</td><td class="text-right tabular-nums text-emerald-700">{{ h.creadas }}</td><td class="text-right tabular-nums">{{ h.actualizadas }}</td><td class="text-right tabular-nums" :class="h.errores ? 'text-carmin font-bold' : ''">{{ h.errores }}</td><td>{{ h.usuario }}</td></tr>
            <tr v-if="h.detalle?.length"><td colspan="8" class="text-xs" :class="h.errores ? 'text-carmin bg-red-50/40' : 'text-marca-muted bg-marca-fondo'"><span v-for="(d, i) in h.detalle.slice(0, 10)" :key="i" class="block">{{ d }}</span></td></tr>
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
import { hoyISO } from '@/util/formato'
const props = defineProps({ entidades: Array, historial: Array, bigconnect: Object, migracion: Object })
// Fase 41: migrar desde BIG Connect (primero la prueba, después la de verdad).
const bc = useForm({ url: props.bigconnect?.url ?? '', clave: '', empresa: props.bigconnect?.empresa ?? '' })
const mig = useForm({ prueba: true })
function migrar(prueba) { mig.prueba = prueba; mig.post('/configuracion/importar/bigconnect/migrar', { preserveScroll: true }) }
const page = usePage()
const preview = computed(() => page.props.flash?.preview)
const entidadActual = computed(() => props.entidades.find(e => e.key === (preview.value?.entidad ?? sub.entidad)) ?? props.entidades[0])
const sub = useForm({ entidad: 'clientes', archivo: null, perfil: '' })
const ap = useForm({ entidad: '', archivo: '', filas: [], mapeo: {}, stock_inicial: true, ajustar_stock: false, saldos: true, reemplazar_saldos: false, fecha_saldos: hoyISO(), precios_con_iva: false })
watch(preview, p => { if (p) { ap.entidad = p.entidad; ap.archivo = p.archivo; ap.filas = p.filas; ap.mapeo = Object.fromEntries(p.encabezado.map((_, i) => [String(i), p.mapeo?.[i] ?? null])); ap.precios_con_iva = p.perfil === 'bigsys' } }, { immediate: true })
function aplicar() { ap.post('/configuracion/importar/aplicar', { preserveScroll: true, onSuccess: () => router.get('/configuracion/importar') }) }
</script>
