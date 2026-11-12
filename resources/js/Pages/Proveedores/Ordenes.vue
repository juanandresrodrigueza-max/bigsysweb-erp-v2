<template>
  <AppLayout titulo="Órdenes de compra">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <h1 class="page-title">Órdenes de compra</h1>
        <p class="page-subtitle">Lo que pediste a cada proveedor y qué falta recibir. Al recibir, la factura se arma sola.</p>
      </div>
      <div v-if="puede('proveedores','crear')" class="flex gap-2">
        <button v-if="$page.props.auth?.ia" class="btn-secondary" @click="cot.abierto = true" data-e2e="comparar-cotizaciones">✦ Comparar cotizaciones</button>
        <Link href="/proveedores/ordenes/nueva?sugerir=1" class="btn-secondary"><Icono nombre="sparkles" clase="w-4 h-4" /> Pedido sugerido</Link>
        <Link href="/proveedores/ordenes/nueva" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nueva orden</Link>
      </div>
    </div>

    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Abiertas</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.abiertas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Comprometido</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.monto_abierto, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Atrasadas</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.atrasadas ? 'text-carmin' : ''">{{ resumen.atrasadas }}</p></div>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-3">
      <select v-model="f.estado" @change="filtrar" class="input"><option value="">Todas</option><option value="abiertas">Abiertas</option><option v-for="(l, k) in estados" :key="k" :value="k">{{ l }}</option></select>
      <select v-model="f.contact_id" @change="filtrar" class="input"><option value="">Todos los proveedores</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select>
    </div>

    <div v-if="juntas.length" class="mb-3 flex flex-wrap items-center gap-2 text-sm rounded-xl bg-lavanda-light px-3 py-2" data-e2e="facturar-juntas">
      <span>{{ juntas.length }} órdenes de {{ juntasProv }} elegidas.</span>
      <Link :href="`/proveedores/compras/nueva?orden=${juntas.join(',')}`" class="btn-primary !py-1 text-xs">Cargar una factura contra las {{ juntas.length }}</Link>
      <button class="text-xs text-marca-muted" @click="juntas = []">Limpiar</button>
    </div>
    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th class="w-8" title="Para cargar una sola factura contra varias órdenes del mismo proveedor"></th><th>Orden</th><th>Fecha</th><th>Proveedor</th><th>Entrega</th><th class="text-right">Ítems</th><th class="text-right">Total</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="o in lista.data" :key="o.id" class="cursor-pointer" @click="$inertia.visit(`/proveedores/ordenes/${o.id}`)">
            <td @click.stop><input v-if="['enviada', 'parcial'].includes(o.estado)" type="checkbox" class="accent-carmin" :value="o.id" v-model="juntas" :disabled="juntas.length > 0 && juntasProv !== o.proveedor" :title="juntas.length && juntasProv !== o.proveedor ? 'Sólo órdenes del mismo proveedor' : 'Facturar junto con otras'" /></td>
            <td class="font-semibold tabular-nums">{{ o.numero }}</td>
            <td class="text-marca-muted tabular-nums">{{ o.fecha }}</td>
            <td class="font-medium">{{ o.proveedor }}</td>
            <td class="tabular-nums" :class="o.atrasada ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ o.entrega ?? '—' }}</td>
            <td class="text-right tabular-nums">{{ o.items_count }}</td>
            <td class="text-right font-semibold tabular-nums">{{ moneda(o.total) }}</td>
            <td><span class="badge" :class="claseEstado[o.estado]">{{ estados[o.estado] }}</span></td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="8" class="!py-0"><EstadoVacio texto="Todavía no hay órdenes de compra." :filtros="f" :accion="puede('proveedores','crear') ? { label: 'Nueva orden', href: '/proveedores/ordenes/nueva' } : null" /></td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />
    <!-- Fase 62: cotizaciones de varios proveedores → tabla comparada → órdenes divididas al más barato -->
    <Modal :abierto="cot.abierto" titulo="Comparar cotizaciones" ancho="max-w-5xl" @cerrar="cot.abierto = false">
      <div v-if="!cot.comparacion" class="space-y-3 text-sm">
        <p class="text-marca-muted">Subí la cotización de cada proveedor (foto, PDF) o pegá el mensaje que te mandaron. La IA lee los renglones, los cruza con tus artículos y arma la tabla.</p>
        <label class="btn-secondary !py-1 text-xs cursor-pointer inline-flex">📎 Agregar fotos o PDF<input type="file" multiple accept=".pdf,image/*" class="hidden" data-e2e="cot-archivos" @change="e => { cot.archivos.push(...e.target.files); e.target.value = '' }" /></label>
        <ul v-if="cot.archivos.length" class="text-xs text-marca-muted">
          <li v-for="(a, i) in cot.archivos" :key="i">{{ a.name }} <button class="text-carmin" @click="cot.archivos.splice(i, 1)">quitar</button></li>
        </ul>
        <div v-for="(t, i) in cot.textos" :key="'t' + i" class="flex gap-2"><textarea v-model="cot.textos[i]" class="input flex-1" rows="3" :placeholder="`Mensaje del proveedor ${i + 1} (pegalo tal cual)`" data-e2e="cot-texto"></textarea><button class="text-xs text-carmin" @click="cot.textos.splice(i, 1)">quitar</button></div>
        <button class="text-xs text-violeta" @click="cot.textos.push('')">+ pegar otro mensaje</button>
        <p v-if="cot.error" class="text-carmin text-xs">{{ cot.error }}</p>
      </div>
      <div v-else class="text-sm space-y-3">
        <p class="rounded-xl bg-lavanda-light px-3 py-2">{{ cot.comparacion.texto }}</p>
        <ul v-if="cot.avisos.length" class="text-xs text-amber-700">
          <li v-for="(a, i) in cot.avisos" :key="i">⚠ {{ a }}</li>
        </ul>
        <div class="overflow-x-auto">
          <table class="w-full text-xs" data-e2e="cot-tabla">
            <thead><tr class="text-left text-marca-muted"><th class="py-1 pr-2">Artículo</th><th class="py-1 pr-2 text-right">Cant.</th><th v-for="p in cot.comparacion.proveedores" :key="p.i" class="py-1 px-2 text-right">{{ p.proveedor }}<span v-if="!p.proveedor_id" class="block text-[10px] font-normal text-carmin">sin ficha</span></th><th class="py-1 pl-2">Comprar a</th></tr></thead>
            <tbody>
              <tr v-for="f in cot.comparacion.filas" :key="f.clave" class="border-t border-marca-borde/60">
                <td class="py-1 pr-2">{{ f.descripcion }}<span v-if="!f.product_id" class="text-marca-muted"> · no está en el stock</span></td>
                <td class="py-1 pr-2 text-right tabular-nums">{{ f.cantidad }} {{ f.unidad }}</td>
                <td v-for="(pr, i) in f.precios" :key="i" class="py-1 px-2 text-right tabular-nums" :class="f.gana === i ? 'font-bold text-emerald-700' : pr === null ? 'text-marca-muted' : ''" :title="f.detalles[i] ?? ''">{{ pr === null ? '—' : moneda(pr) }}</td>
                <td class="py-1 pl-2"><select v-model="cot.eleccion[f.clave]" class="input !py-0.5 !text-xs"><option v-for="(pr, i) in f.precios" :key="i" :value="i" :disabled="pr === null">{{ cot.comparacion.proveedores[i].proveedor }}</option><option :value="-1">No pedir</option></select></td>
              </tr>
            </tbody>
            <tfoot><tr class="border-t-2 border-marca-borde font-semibold"><td class="py-1 pr-2" colspan="2">Todo en uno solo</td><td v-for="r in cot.comparacion.resumen" :key="r.proveedor" class="py-1 px-2 text-right tabular-nums">{{ r.cotiza_todo ? moneda(r.total_todo) : 'no cotiza todo' }}</td><td class="py-1 pl-2 text-emerald-700">Mixto: {{ moneda(totalElegido) }}</td></tr></tfoot>
          </table>
        </div>
        <div v-for="p in cot.comparacion.proveedores" :key="'c' + p.i" v-show="p.condiciones" class="text-xs text-marca-muted"><b>{{ p.proveedor }}:</b> {{ p.condiciones }}</div>
        <input v-model="cot.notas" class="input text-sm" placeholder="Nota para las órdenes (obra, motivo…)" maxlength="300" />
      </div>
      <template #pie>
        <button class="btn-secondary" @click="cot.comparacion ? (cot.comparacion = null) : (cot.abierto = false)">{{ cot.comparacion ? 'Volver' : 'Cancelar' }}</button>
        <button v-if="!cot.comparacion" class="btn-primary" :disabled="cot.leyendo || (!cot.archivos.length && !cot.textos.some(t => t.trim()))" @click="leerCotizaciones" data-e2e="cot-leer">{{ cot.leyendo ? 'Leyendo…' : 'Comparar' }}</button>
        <button v-else class="btn-primary" :disabled="!repartoElegido.length" @click="armarOrdenes" data-e2e="cot-pedidos">Armar {{ repartoElegido.length }} {{ repartoElegido.length === 1 ? 'orden' : 'órdenes' }}</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { reactive, ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import Icono from '@/Components/Icono.vue'
import Paginacion from '@/Components/Paginacion.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ lista: Object, filtros: Object, proveedores: Array, estados: Object, resumen: Object })
// Fase 42.4: una factura contra varias órdenes del mismo proveedor.
const juntas = ref([])
const juntasProv = computed(() => props.lista.data.find(o => o.id === juntas.value[0])?.proveedor ?? '')
const { puede } = usePermisos()
const f = reactive({ estado: props.filtros.estado ?? '', contact_id: props.filtros.contact_id ?? '' })
function filtrar() { router.get('/proveedores/ordenes', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
// Fase 62: comparar cotizaciones.
const cot = reactive({ abierto: false, archivos: [], textos: [''], leyendo: false, error: '', comparacion: null, avisos: [], eleccion: {}, notas: '' })
async function leerCotizaciones() {
  cot.leyendo = true; cot.error = ''
  try {
    const fd = new FormData(); cot.archivos.forEach(a => fd.append('archivos[]', a)); cot.textos.filter(t => t.trim()).forEach(t => fd.append('textos[]', t))
    const { data } = await window.axios.post('/proveedores/ordenes/cotizaciones/leer', fd)
    cot.comparacion = data.comparacion; cot.avisos = data.avisos ?? []
    cot.eleccion = Object.fromEntries(data.comparacion.filas.map(f => [f.clave, f.gana ?? -1]))
  } catch (e) { cot.error = e.response?.data?.aviso || e.response?.data?.message || 'No se pudieron leer las cotizaciones.'; if (e.response?.data?.avisos?.length) cot.error += ' ' + e.response.data.avisos.join(' ') }
  finally { cot.leyendo = false }
}
const repartoElegido = computed(() => {
  if (!cot.comparacion) return []
  const porProv = {}
  for (const f of cot.comparacion.filas) { const i = cot.eleccion[f.clave]; if (i === -1 || i === undefined || f.precios[i] === null) continue; const p = cot.comparacion.proveedores[i]; porProv[i] ??= { proveedor_id: p.proveedor_id, proveedor: p.proveedor, items: [] }; porProv[i].items.push({ product_id: f.product_id, descripcion: f.descripcion, cantidad: f.cantidad, precio_unit: f.precios[i] }) }
  return Object.values(porProv)
})
const totalElegido = computed(() => repartoElegido.value.reduce((s, r) => s + r.items.reduce((t, i) => t + i.cantidad * i.precio_unit, 0), 0))
function armarOrdenes() { router.post('/proveedores/ordenes/cotizaciones/pedidos', { reparto: repartoElegido.value, notas: cot.notas }, { onSuccess: () => { cot.abierto = false; cot.comparacion = null; cot.archivos = []; cot.textos = [''] } }) }
const claseEstado = { borrador: 'bg-gris-light text-marca-muted', enviada: 'bg-violeta-light text-violeta', parcial: 'bg-amber-50 text-amber-700', recibida: 'bg-emerald-50 text-emerald-700', cancelada: 'bg-carmin-light text-carmin' }
</script>
