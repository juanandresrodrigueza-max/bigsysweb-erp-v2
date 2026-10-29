<template>
  <AppLayout titulo="Compras">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/proveedores" class="text-xs text-marca-muted hover:text-carmin">← Proveedores</Link>
        <h1 class="page-title">Compras</h1>
        <p class="page-subtitle">Facturas de compra cargadas a mano, leídas con IA o importadas de AFIP.</p>
      </div>
      <div v-if="puede('proveedores','crear')" class="flex gap-2">
        <Link href="/proveedores/ordenes" class="btn-secondary">Órdenes de compra</Link>
        <button @click="importarAbierto = true" class="btn-secondary" data-e2e="importar-afip">Qué me falta cargar</button>
        <button @click="abrirReclamos" class="btn-secondary" data-e2e="reclamos">Reclamos <span v-if="reclamosAbiertos" class="ml-1 rounded-full bg-carmin text-white text-[10px] px-1.5 py-0.5">{{ reclamosAbiertos }}</span></button>
        <Link href="/proveedores/compras/nueva" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Cargar factura</Link>
      </div>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input" placeholder="Número o proveedor…" />
      <select v-model="f.estado" @change="filtrar" class="input"><option value="">Todo estado</option><option value="borrador">Borradores</option><option value="emitido">Registradas</option><option value="pendiente">Pendientes de pago</option><option value="vencido">Vencidas</option><option value="anulado">Anuladas</option></select>
      <select v-model="f.contact_id" @change="filtrar" class="input"><option value="">Todos los proveedores</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select>
      <input v-model="f.desde" @change="filtrar" type="date" class="input" /><input v-model="f.hasta" @change="filtrar" type="date" class="input" />
    </div>

    <div v-if="borradores.length" class="card mb-4 text-sm" data-e2e="a-medio-cargar">
      <h2 class="font-bold mb-1">Facturas a medio cargar</h2>
      <div v-for="b in borradores" :key="b.id" class="flex flex-wrap items-center justify-between gap-2 py-1 border-t border-marca-borde first:border-0 text-xs"><span><b>{{ b.proveedor ?? 'Sin proveedor' }}</b><span v-if="b.numero"> · {{ b.numero }}</span><span v-if="b.total"> · {{ moneda(b.total) }}</span> · {{ b.cuando }}</span><span class="flex gap-1"><Link :href="`/proveedores/compras/nueva?borrador=${b.id}`" class="btn-primary !py-1 text-xs">Retomar</Link><button class="btn-ghost !py-1 text-xs text-carmin" @click="borrarBorrador(b)">Descartar</button></span></div>
    </div>
    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Comprobantes</p><p class="text-xl font-extrabold tabular-nums">{{ entero(resumen.cantidad) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Total registrado</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.total, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Saldo a pagar</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.saldo > 0 ? 'text-carmin' : ''">{{ moneda(resumen.saldo, 0) }}</p></div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Comprobante</th><th>Fecha</th><th>Proveedor</th><th>Vence</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="c in lista.data" :key="c.id" class="cursor-pointer" @click="$inertia.visit(`/proveedores/compras/${c.id}`)">
            <td><div class="flex items-center gap-2"><span class="w-7 h-7 rounded-lg grid place-items-center text-xs font-extrabold" :class="c.estado === 'anulado' ? 'bg-gris-light text-marca-muted' : c.grupo === 'nc' ? 'bg-violeta text-white' : 'bg-lavanda text-violeta'">{{ c.letra }}</span><div><p class="font-semibold leading-tight">{{ c.nombre }}</p><p class="text-xs text-marca-muted tabular-nums">{{ c.numero ?? 'sin número' }} <span v-if="c.origen_carga && c.origen_carga !== 'manual'" class="badge bg-violeta-light text-violeta ml-1">{{ c.origen_carga === 'ocr' ? 'IA' : 'AFIP' }}</span></p></div></div></td>
            <td class="text-marca-muted tabular-nums">{{ c.fecha }}</td><td class="font-medium">{{ c.proveedor }}</td>
            <td class="tabular-nums" :class="c.vencido ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ c.grupo === 'factura' || c.grupo === 'nd' ? c.fecha_vto : '' }}</td>
            <td class="text-right font-semibold tabular-nums">{{ moneda(c.total) }}</td><td class="text-right tabular-nums" :class="c.saldo > 0 ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ c.estado_pago !== 'na' ? moneda(c.saldo) : '' }}</td>
            <td><span class="badge" :class="estadoComprobante[c.estado].clase">{{ c.estado === 'emitido' ? 'Registrada' : estadoComprobante[c.estado].label }}</span> <span v-if="c.estado === 'emitido' && c.estado_pago !== 'na'" class="badge ml-1" :class="estadoCobro[c.estado_pago].clase">{{ { pendiente: 'A pagar', parcial: 'Parcial', cobrado: 'Pagada' }[c.estado_pago] }}</span></td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="7" class="text-center text-marca-muted py-10">No hay compras con estos filtros.</td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />

    <!-- Fase 31.5: lo que faltó y se reclamó al proveedor. Se cierra solo con la nota de crédito o a mano. -->
    <Modal :abierto="reclamosAbierto" titulo="Reclamos a proveedores" @cerrar="reclamosAbierto = false">
      <label v-if="puede('configuracion','editar')" class="flex items-center gap-2 text-sm mb-3 rounded-xl bg-gris-light px-3 py-2"><input type="checkbox" class="accent-carmin" :checked="exigeControl" @change="exigir($event.target.checked)" /> Exigir el control con la pistola antes de registrar una compra con mercadería <span class="text-xs text-marca-muted">(las notas de crédito y las facturas de remito no lo piden)</span></label>
      <label class="flex items-center gap-2 text-xs text-marca-muted mb-2"><input v-model="verTodos" type="checkbox" class="accent-carmin" @change="cargarReclamos" /> ver también los resueltos</label>
      <div v-if="!reclamos.length" class="text-sm text-marca-muted py-6 text-center">No hay reclamos {{ verTodos ? '' : 'abiertos' }}: lo que llegó fue lo facturado.</div>
      <div v-for="r in reclamos" :key="r.id" class="rounded-xl border p-3 mb-2 text-sm" :class="r.estado === 'abierto' ? 'border-amber-300' : 'border-marca-borde opacity-70'">
        <div class="flex flex-wrap items-center justify-between gap-2"><p><b>{{ r.proveedor }}</b> · <Link :href="`/proveedores/compras/${r.comprobante_id}`" class="text-carmin hover:underline">{{ r.comprobante }}</Link> · {{ r.fecha }} <span v-if="r.estado !== 'abierto'" class="badge bg-emerald-50 text-emerald-700 ml-1">resuelto: {{ r.resolucion }}</span></p>
          <div v-if="r.estado === 'abierto'" class="flex gap-1.5 text-xs"><a v-if="r.telefono" :href="`https://wa.me/${r.telefono.length <= 10 ? '549' + r.telefono : r.telefono}?text=${encodeURIComponent(r.texto)}`" target="_blank" class="btn-secondary !py-1">WhatsApp</a><button class="btn-secondary !py-1" @click="copiar(r.texto)">Copiar</button><button class="btn-primary !py-1" @click="resolver(r)">Resuelto</button></div></div>
        <ul class="text-xs text-marca-muted mt-1"><li v-for="(d, i) in r.detalle" :key="i">{{ d.descripcion }}: facturado {{ d.facturado }}, llegó {{ d.llego }} <span :class="d.diferencia < 0 ? 'text-carmin' : 'text-amber-700'">({{ d.tipo === 'no_facturado' ? 'no facturado' : d.tipo === 'pendiente' ? 'pendiente' : d.diferencia < 0 ? 'faltaron ' + (-d.diferencia) : 'sobraron ' + d.diferencia }})</span></li></ul>
      </div>
    </Modal>

    <Modal :abierto="importarAbierto" titulo="Qué me falta cargar · Mis Comprobantes de ARCA" @cerrar="importarAbierto = false" ancho="max-w-3xl">
      <template v-if="!cruce">
        <p class="text-sm text-marca-muted mb-3">En ARCA entrá a <b>Mis Comprobantes → Recibidos</b>, filtrá el período y exportá el CSV. Subilo acá: te dice qué comprobantes tiene ARCA y vos no (cada uno es IVA que no te estás tomando), cuáles están cargados sin el CUIT del proveedor, por otro importe, dos veces, y cuáles no cierran. Después creás las que faltan con un toque.</p>
        <input type="file" accept=".csv,.txt" class="input !py-1.5 text-xs" data-e2e="afip-archivo" @change="imp.archivo = $event.target.files[0]" />
        <p v-if="imp.errors.archivo || cruceError" class="text-carmin text-xs mt-2">{{ imp.errors.archivo || cruceError }}</p>
      </template>
      <template v-else>
        <div class="flex flex-wrap gap-1.5 mb-3 text-xs" data-e2e="cruce-solapas">
          <button v-for="(t, k) in solapas" :key="k" class="rounded-full px-3 py-1 font-semibold border" :class="solapa === k ? 'bg-violeta text-white border-violeta' : 'border-marca-borde hover:bg-gris-light'" @click="solapa = k">{{ t }} <span class="opacity-80">{{ cruce[k].length }}</span></button>
          <span class="ml-auto self-center text-marca-muted">{{ cruce.filas }} en ARCA · {{ cruce.ok }} coinciden · {{ cruce.periodo.desde }} a {{ cruce.periodo.hasta }}</span>
        </div>
        <div v-if="solapa === 'faltan'">
          <p class="text-sm mb-2" :class="cruce.faltan.length ? 'text-carmin font-semibold' : 'text-emerald-700'">{{ cruce.faltan.length ? `Faltan ${cruce.faltan.length} · IVA que no te estás tomando: ${moneda(cruce.iva_faltante)}` : 'No falta ninguna: todo lo que tiene ARCA está cargado.' }}<span v-if="cruce.proveedores_nuevos" class="text-xs text-marca-muted font-normal"> · {{ cruce.proveedores_nuevos }} de proveedores que no tenés (se crean por CUIT)</span></p>
          <div class="max-h-72 overflow-y-auto border border-marca-borde rounded-xl"><table class="table text-xs"><thead><tr><th><input type="checkbox" :checked="sel.length === cruce.faltan.length" @change="sel = $event.target.checked ? cruce.faltan.map(f => f.clave) : []" /></th><th>Comprobante</th><th>Proveedor</th><th class="text-right">IVA</th><th class="text-right">Total</th></tr></thead>
            <tbody><tr v-for="f in cruce.faltan" :key="f.clave"><td><input type="checkbox" :value="f.clave" v-model="sel" /></td><td class="tabular-nums">{{ f.tipo }} {{ f.numero }} · {{ f.fecha }}</td><td>{{ f.proveedor }} <span v-if="f.proveedor_nuevo" class="badge bg-amber-100 text-amber-800">nuevo</span></td><td class="text-right tabular-nums font-semibold">{{ moneda(f.iva) }}</td><td class="text-right tabular-nums">{{ moneda(f.total) }}</td></tr></tbody></table></div>
          <label v-if="cruce.faltan.length" class="flex items-center gap-2 text-sm mt-3"><input v-model="imp.registrar" type="checkbox" class="accent-carmin" /> Registrarlas directamente (impactan cuenta corriente). Si no, quedan como borrador para completar los artículos.</label>
        </div>
        <div v-else-if="solapa === 'sin_cuit'">
          <p class="text-sm text-marca-muted mb-2">Están cargadas, pero a un proveedor sin CUIT: por eso el libro de IVA no cruza solo con ARCA. Se arregla poniendo el CUIT en la ficha.</p>
          <div v-for="f in cruce.sin_cuit" :key="f.clave" class="flex flex-wrap justify-between gap-2 text-xs py-1.5 border-t border-marca-borde"><span>{{ f.tipo }} {{ f.numero }} · {{ f.fecha }} · cargada a <b>{{ f.cargada_a }}</b> · en ARCA: {{ f.proveedor }} CUIT {{ f.cuit }}</span><Link :href="`/proveedores/${f.contact_id}`" class="text-carmin hover:underline">poner el CUIT</Link></div>
          <p v-if="!cruce.sin_cuit.length" class="text-sm text-emerald-700">Ninguna.</p>
        </div>
        <div v-else-if="solapa === 'otro_importe'">
          <p class="text-sm text-marca-muted mb-2">Cargadas por un importe distinto al que figura en ARCA.</p>
          <div v-for="f in cruce.otro_importe" :key="f.clave" class="flex flex-wrap justify-between gap-2 text-xs py-1.5 border-t border-marca-borde"><span>{{ f.tipo }} {{ f.numero }} · {{ f.proveedor }} · ARCA {{ moneda(f.total) }} · cargada {{ moneda(f.total_cargado) }} <b :class="f.diferencia > 0 ? 'text-carmin' : 'text-amber-700'">({{ f.diferencia > 0 ? '+' : '' }}{{ moneda(f.diferencia) }})</b></span><Link :href="`/proveedores/compras/${f.compra_id}`" class="text-carmin hover:underline">ver</Link></div>
          <p v-if="!cruce.otro_importe.length" class="text-sm text-emerald-700">Ninguna.</p>
        </div>
        <div v-else-if="solapa === 'duplicadas'">
          <p class="text-sm text-marca-muted mb-2">Cargadas dos veces: es IVA computado de más, y eso te lo pueden observar.</p>
          <div v-for="(f, i) in cruce.duplicadas" :key="i" class="flex flex-wrap justify-between gap-2 text-xs py-1.5 border-t border-marca-borde"><span>{{ f.tipo }} {{ f.numero }} · {{ f.proveedor }} · {{ f.veces }} veces · {{ moneda(f.total) }}</span><span><Link v-for="id in f.ids" :key="id" :href="`/proveedores/compras/${id}`" class="text-carmin hover:underline mr-2">ver #{{ id }}</Link></span></div>
          <p v-if="!cruce.duplicadas.length" class="text-sm text-emerald-700">Ninguna.</p>
        </div>
        <div v-else>
          <p class="text-sm text-marca-muted mb-2">Neto + exento + IVA + percepciones no da el total. Lo más común: una alícuota de IVA o una percepción que se perdió al cargar.</p>
          <div v-for="f in cruce.no_cierran" :key="f.compra_id" class="flex flex-wrap justify-between gap-2 text-xs py-1.5 border-t border-marca-borde"><span>{{ f.tipo }} {{ f.numero }} · {{ f.proveedor }} · total {{ moneda(f.total) }} · suma {{ moneda(f.suma) }} <b class="text-carmin">({{ moneda(f.diferencia) }})</b></span><Link :href="`/proveedores/compras/${f.compra_id}`" class="text-carmin hover:underline">ver</Link></div>
          <p v-if="!cruce.no_cierran.length" class="text-sm text-emerald-700">Todas cierran.</p>
        </div>
      </template>
      <template #pie>
        <button class="btn-secondary" @click="cruce ? (cruce = null) : (importarAbierto = false)">{{ cruce ? 'Otro archivo' : 'Cancelar' }}</button>
        <button v-if="!cruce" class="btn-primary" data-e2e="cruzar" :disabled="cruzando || !imp.archivo" @click="cruzar">{{ cruzando ? 'Cruzando…' : 'Ver qué me falta' }}</button>
        <button v-else-if="solapa === 'faltan' && cruce.faltan.length" class="btn-primary" data-e2e="crear-faltantes" :disabled="imp.processing || !sel.length" @click="crearFaltantes">{{ imp.processing ? 'Creando…' : `Crear ${sel.length === cruce.faltan.length ? 'todas' : sel.length} las que faltan` }}</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda, entero, estadoCobro, estadoComprobante } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ lista: Object, resumen: Object, filtros: Object, proveedores: Array, reclamosAbiertos: Number, exigeControl: Boolean })
const { puede } = usePermisos()
const f = reactive({ estado: props.filtros.estado ?? '', contact_id: props.filtros.contact_id ?? '', desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '', buscar: props.filtros.buscar ?? '' })
function filtrar() { router.get('/proveedores/compras', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
const importarAbierto = ref(false)
// Fase 31: las que se dejaron para después.
const borradores = ref([])
async function cargarBorradores() { try { const { data } = await window.axios.get('/proveedores/compras/borradores'); borradores.value = data } catch (e) {} }
async function borrarBorrador(b) { if (!confirm('¿Descartar esta factura a medio cargar?')) return; await window.axios.delete(`/proveedores/compras/borradores/${b.id}`); cargarBorradores() }
onMounted(cargarBorradores)
const imp = useForm({ archivo: null, registrar: false, token: null, solo: [] })
// Fase 31.6: el CSV de Mis Comprobantes se cruza contra lo cargado antes de crear nada.
const cruce = ref(null), cruzando = ref(false), cruceError = ref(null), solapa = ref('faltan'), sel = ref([])
const solapas = { faltan: 'Faltan cargar', sin_cuit: 'Sin CUIT', otro_importe: 'Otro importe', duplicadas: 'Dos veces', no_cierran: 'No cierran' }
async function cruzar() {
  cruzando.value = true; cruceError.value = null
  try { const fd = new FormData(); fd.append('archivo', imp.archivo); const { data } = await window.axios.post('/proveedores/compras/cruzar-afip', fd); cruce.value = data; sel.value = data.faltan.map(f => f.clave); solapa.value = 'faltan' }
  catch (e) { cruceError.value = e.response?.data?.message ?? 'No se pudo leer el archivo.' }
  cruzando.value = false
}
function crearFaltantes() { imp.token = cruce.value.token; imp.solo = sel.value; imp.transform(d => ({ registrar: d.registrar, token: d.token, solo: d.solo })).post('/proveedores/compras/importar-afip', { onSuccess: () => { importarAbierto.value = false; cruce.value = null } }) }
// Fase 31.5: reclamos a proveedores y la opción de exigir el control con la pistola.
const reclamosAbierto = ref(false), reclamos = ref([]), verTodos = ref(false)
async function cargarReclamos() { try { const { data } = await window.axios.get('/proveedores/compras/reclamos', { params: { todos: verTodos.value ? 1 : 0 } }); reclamos.value = data } catch (e) {} }
function abrirReclamos() { reclamosAbierto.value = true; cargarReclamos() }
async function copiar(t) { try { await navigator.clipboard.writeText(t) } catch (e) {} }
async function resolver(r) { const como = prompt('¿Cómo se resolvió?'); if (como === null) return; await window.axios.post(`/proveedores/compras/reclamos/${r.id}/resolver`, { como: como || 'resuelto' }); cargarReclamos(); router.reload({ only: ['reclamosAbiertos'] }) }
function exigir(v) { router.post('/proveedores/compras/config', { exigir_control: v }, { preserveScroll: true }) }
</script>
