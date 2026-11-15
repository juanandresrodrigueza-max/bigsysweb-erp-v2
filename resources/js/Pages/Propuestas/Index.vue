<template>
  <AppLayout titulo="Propuestas de Bigcito">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><p class="kicker mb-1">Bigcito trabaja de noche</p><h1 class="page-title">Lo que te dejé para aprobar</h1><p class="page-subtitle">Cada madrugada reviso cobranzas, reposición, precios, el cierre del mes y el control interno. Nada se hace solo: aprobás de a un toque y recién ahí se ejecuta.</p></div>
      <div class="flex gap-2">
        <Link href="/resumen-hablado" class="btn-secondary"><Icono nombre="sparkles" clase="w-4 h-4" /> Escuchar el resumen</Link>
        <button v-if="puedeResolver" class="btn-primary" :disabled="correr.processing" @click="correr.post('/propuestas/correr', { preserveScroll: true })" data-e2e="correr-agentes">Revisar ahora</button>
      </div>
    </div>

    <div v-if="!pendientes.length" class="card !py-0 mb-5"><EstadoVacio texto="No hay propuestas esperando. Las nuevas aparecen cada mañana y te llegan al celular." /></div>
    <div v-else class="space-y-3 mb-6">
      <div v-for="p in pendientes" :key="p.id" class="card" :class="p.prioridad === 1 ? 'border-carmin/40' : ''" :data-e2e="`propuesta-${p.id}`">
        <div class="flex flex-wrap items-start gap-3">
          <span class="badge shrink-0" :class="colorAgente[p.agente]">{{ p.agente_label }}</span>
          <div class="flex-1 min-w-0">
            <p class="font-semibold">{{ p.titulo }} <span v-if="p.prioridad === 1" class="badge bg-carmin-light text-carmin ml-1">urgente</span></p>
            <p v-if="p.detalle" class="text-sm text-marca-muted whitespace-pre-line mt-0.5">{{ p.detalle }}</p>
            <button v-if="tieneDetalle(p)" type="button" class="text-xs font-semibold text-violeta mt-1" @click="abierta = abierta === p.id ? null : p.id">{{ abierta === p.id ? 'Ocultar' : 'Ver el detalle' }}</button>
            <div v-if="abierta === p.id" class="mt-2 text-sm">
              <pre v-if="p.datos?.texto" class="whitespace-pre-wrap font-sans bg-marca-fondo rounded-xl p-3 text-[13px]" data-e2e="texto-propuesto">{{ p.datos.texto }}</pre>
              <div v-if="p.datos?.items?.length" class="overflow-x-auto mt-2"><table class="table text-xs"><tbody>
                <tr v-for="(it, i) in p.datos.items" :key="i"><td v-for="(v, k) in columnas(it)" :key="k" :class="typeof v === 'number' ? 'tabular-nums text-right' : ''">{{ typeof v === 'number' ? fmt(k, v) : v }}</td></tr>
              </tbody></table></div>
              <ul v-if="p.datos?.facturas?.length" class="mt-2 text-xs space-y-0.5"><li v-for="f in p.datos.facturas" :key="f.id">{{ f.numero }} · {{ moneda(f.saldo) }} · venció {{ f.vencio }}</li></ul>
              <ul v-if="p.datos?.motivos?.length" class="mt-2 text-xs space-y-0.5"><li v-for="(m, i) in p.datos.motivos" :key="i">• {{ m.evidencia }} <span class="text-marca-muted">({{ m.resto }})</span></li></ul>
            </div>
          </div>
          <div class="text-right shrink-0">
            <p v-if="p.importe" class="font-bold tabular-nums">{{ moneda(p.importe) }}</p>
            <p class="text-[11px] text-marca-muted">{{ p.hace }}</p>
          </div>
        </div>
        <div v-if="puedeResolver" class="flex flex-wrap justify-end gap-2 mt-3 pt-3 border-t border-marca-borde/60">
          <button class="btn-ghost text-xs text-carmin" @click="rechazar(p)" :data-e2e="`rechazar-${p.id}`">{{ p.solo_aviso ? 'No me interesa' : 'Rechazar' }}</button>
          <button class="btn-primary !py-1.5 text-xs" :disabled="res.processing" @click="resolver(p, true)" :data-e2e="`aprobar-${p.id}`">{{ p.solo_aviso ? 'Lo vi' : 'Aprobar' }}</button>
        </div>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-2">Últimas resueltas</h2>
        <p v-if="!historial.length" class="text-sm text-marca-muted">Todavía nada.</p>
        <div v-for="h in historial" :key="h.id" class="py-2 border-t border-marca-borde/60 first:border-0 text-sm">
          <div class="flex items-center gap-2"><span class="badge" :class="estadoClase[h.estado]">{{ h.estado }}</span><span class="font-medium truncate">{{ h.titulo }}</span><span class="ml-auto text-xs text-marca-muted whitespace-nowrap">{{ h.resuelta }}<span v-if="h.por"> · {{ h.por }}</span></span></div>
          <p v-if="h.resultado" class="text-xs text-marca-muted mt-0.5">{{ h.resultado }}</p><p v-else-if="h.motivo" class="text-xs text-marca-muted mt-0.5">Motivo: {{ h.motivo }}</p>
        </div>
      </div>
      <div class="card" data-e2e="config-agentes">
        <h2 class="font-bold mb-1">Qué hace Bigcito de noche</h2>
        <p class="text-xs text-marca-muted mb-3">Corre a las 05:45. Apagá lo que no quieras que mire.</p>
        <label v-for="a in agentes" :key="a.key" class="flex items-start gap-2 py-1.5 text-sm"><input type="checkbox" class="accent-carmin mt-1" :value="a.key" v-model="cfg.activos" :disabled="!esDueno" /><span><b>{{ a.label }}</b><span class="block text-xs text-marca-muted">{{ a.que }}</span></span></label>
        <label class="flex items-center gap-2 pt-2 mt-2 border-t border-marca-borde text-sm"><input type="checkbox" class="accent-carmin" v-model="cfg.push" :disabled="!esDueno" /> Avisarme al celular cuando haya propuestas</label>
        <div v-if="esDueno" class="flex justify-end mt-3"><button class="btn-secondary !py-1 text-xs" :disabled="cfg.processing" @click="cfg.post('/propuestas/configurar', { preserveScroll: true })" data-e2e="guardar-agentes">Guardar</button></div>
      </div>
    </div>

    <Modal :abierto="!!rech" titulo="Rechazar la propuesta" @cerrar="rech = null">
      <p class="text-sm mb-2">{{ rech?.titulo }}</p>
      <input v-model="res.motivo" class="input" placeholder="Por qué (opcional): me sirve para aprender" />
      <template #pie><button class="btn-secondary" @click="rech = null">Cancelar</button><button class="btn-danger" :disabled="res.processing" @click="resolver(rech, false)" data-e2e="confirmar-rechazo">Rechazar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ pendientes: Array, historial: Array, agentes: Array, config: Object, puedeResolver: Boolean })
const page = usePage()
const esDueno = computed(() => !!page.props.auth?.user?.es_dueno || props.puedeResolver)
const abierta = ref(null), rech = ref(null)
const res = useForm({ aprobar: true, motivo: '' })
const correr = useForm({})
const cfg = useForm({ activos: [...(props.config?.activos ?? [])], push: props.config?.push ?? true })
const colorAgente = { cobrador: 'bg-emerald-50 text-emerald-700', reposicion: 'bg-lavanda-light text-violeta', precios: 'bg-amber-50 text-amber-700', cierre: 'bg-sky-50 text-sky-700', control: 'bg-carmin-light text-carmin' }
const estadoClase = { aprobada: 'bg-emerald-50 text-emerald-700', rechazada: 'bg-carmin-light text-carmin', vencida: 'bg-marca-fondo text-marca-muted' }
const ocultas = ['id', 'categoria_id', 'proveedor_id', 'sku', 'por_historia', 'unidad']
function tieneDetalle(p) { return p.datos && (p.datos.texto || p.datos.items?.length || p.datos.facturas?.length || p.datos.motivos?.length) }
function columnas(it) { const o = {}; for (const k in it) if (!ocultas.includes(k) && it[k] !== null && it[k] !== false) o[k] = it[k]; return o }
function fmt(k, v) { return /importe|precio|costo|total|faltante|diferencia|descuentos|plata/.test(k) ? moneda(v) : v }
function rechazar(p) { rech.value = p; res.motivo = '' }
function resolver(p, aprobar) { res.aprobar = aprobar; res.post(`/propuestas/${p.id}/resolver`, { preserveScroll: true, onSuccess: () => { rech.value = null; res.reset() } }) }
</script>
