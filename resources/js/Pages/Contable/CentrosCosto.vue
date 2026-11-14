<template>
  <AppLayout titulo="Centros de costo">
    <!-- Fase 96: centros de costo con presupuesto anual contra real. El real sale solo de los gastos de Plata y de las compras que llevan centro. -->
    <PageHeader titulo="Centros de costo" :principal="puede('contable','editar') ? { label: 'Nuevo centro', click: () => abrirCentro(null) } : null" :volver="{ href: '/contable', label: 'Contable' }">
      <template #subtitulo>Cada gasto y cada compra se imputa a un centro (área, sucursal, línea de negocio). Acá cargás el presupuesto de cada mes y ves cuánto se gastó de verdad.</template>
    </PageHeader>

    <div class="flex flex-wrap items-center gap-3 mb-4">
      <select :value="tablero.anio" class="input !w-28" data-e2e="anio" @change="router.get('/contable/centros', { anio: $event.target.value }, { preserveState: true })"><option v-for="a in anios" :key="a" :value="a">{{ a }}</option></select>
      <p class="text-sm" :class="tablero.pasados.length ? 'text-carmin font-semibold' : 'text-marca-muted'" data-e2e="resumen">{{ tablero.resumen }}</p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Presupuesto {{ tablero.anio }}</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(tablero.totales.presupuesto, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Gastado {{ tablero.anio }}</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(tablero.totales.real, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">A la fecha</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(tablero.totales.a_la_fecha.real, 0) }}</p><p class="text-[11px] text-marca-muted">de {{ moneda(tablero.totales.a_la_fecha.presupuesto, 0) }} presupuestados<span v-if="tablero.totales.a_la_fecha.sin_presupuesto"> · {{ moneda(tablero.totales.a_la_fecha.sin_presupuesto, 0) }} sin presupuesto</span></p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Desvío a la fecha</p><p class="text-xl font-extrabold tabular-nums" :class="claseDesvio(tablero.totales.a_la_fecha.desvio)">{{ textoDesvio(tablero.totales.a_la_fecha.desvio) }}</p></div>
    </div>

    <div v-if="!tablero.filas.length" class="card text-center py-12">
      <p class="font-bold text-lg">Todavía no hay centros de costo</p>
      <p class="text-sm text-marca-muted mt-1 max-w-lg mx-auto">Creá los primeros (por ejemplo «Administración», «Depósito», «Local Centro») y después, al cargar un gasto en Plata o una compra, elegís a cuál va. Acá vas a ver mes a mes cuánto se gastó contra lo que presupuestaste.</p>
      <button v-if="puede('contable','editar')" class="btn-primary mt-4" @click="abrirCentro(null)">Crear el primero</button>
    </div>

    <div v-for="f in tablero.filas" :key="f.id ?? 'sin'" class="card !p-0 mb-4 overflow-hidden" :data-centro="f.id ?? 'sin'">
      <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-marca-borde/60">
        <div class="flex items-center gap-2 min-w-0">
          <p class="font-bold truncate">{{ f.nombre }}<span v-if="f.codigo" class="text-xs font-normal text-marca-muted"> · {{ f.codigo }}</span></p>
          <span v-if="!f.activo" class="badge bg-gris-light text-marca-muted">inactivo</span>
          <span class="badge" :class="claseDesvio(f.a_la_fecha.desvio, true)" :title="'Gastado contra presupuestado, hasta el mes actual'">{{ textoDesvio(f.a_la_fecha.desvio) }} a la fecha</span>
        </div>
        <div class="flex items-center gap-1 text-xs">
          <span class="text-marca-muted tabular-nums mr-2">{{ moneda(f.total_real, 0) }} / {{ moneda(f.total_presupuesto, 0) }}</span>
          <button v-if="puede('contable','editar')" class="btn-ghost !px-2" @click="abrirPresupuesto(f)" :data-presupuesto="f.id ?? 'sin'">Presupuesto</button>
          <button v-if="puede('contable','editar') && f.id" class="btn-ghost !px-2" @click="abrirCentro(f)" title="Editar" aria-label="Editar"><Icono nombre="edit" clase="w-4 h-4" /></button>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="table text-xs">
          <thead><tr><th></th><th v-for="m in f.meses" :key="m.mes" class="text-right" :class="m.actual ? 'text-carmin' : ''">{{ m.label }}</th><th class="text-right">Total</th></tr></thead>
          <tbody>
            <tr><td class="text-marca-muted">Presupuesto</td><td v-for="m in f.meses" :key="m.mes" class="text-right tabular-nums text-marca-muted">{{ m.presupuesto ? moneda(m.presupuesto, 0) : '—' }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(f.total_presupuesto, 0) }}</td></tr>
            <tr><td class="font-semibold">Real</td><td v-for="m in f.meses" :key="m.mes" class="text-right tabular-nums font-semibold" :class="m.presupuesto && m.real > m.presupuesto ? 'text-carmin' : ''">{{ m.real ? moneda(m.real, 0) : '—' }}</td><td class="text-right tabular-nums font-bold">{{ moneda(f.total_real, 0) }}</td></tr>
            <tr><td class="text-marca-muted">Desvío</td><td v-for="m in f.meses" :key="m.mes" class="text-right tabular-nums" :class="claseDesvio(m.desvio)">{{ m.desvio === null ? '' : textoDesvio(m.desvio) }}</td><td></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal :abierto="!!centro" :titulo="centro?.id ? 'Editar centro de costo' : 'Nuevo centro de costo'" @cerrar="centro = null">
      <div class="grid sm:grid-cols-3 gap-3">
        <div class="sm:col-span-2"><label class="label">Nombre</label><input v-model="cf.nombre" class="input" placeholder="Administración, Depósito, Local Centro…" /><p v-if="cf.errors.nombre" class="text-carmin text-xs mt-1">{{ cf.errors.nombre }}</p></div>
        <div><label class="label">Código (opcional)</label><input v-model="cf.codigo" class="input" placeholder="ADM" /></div>
      </div>
      <label v-if="centro?.id" class="flex items-center gap-2 text-sm mt-3"><input v-model="cf.activo" type="checkbox" class="accent-carmin" /> Activo (se puede elegir en gastos y compras)</label>
      <template #pie><button class="btn-secondary" @click="centro = null">Cancelar</button><button class="btn-primary" :disabled="cf.processing || !cf.nombre" @click="cf.post(`/contable/centros${centro?.id ? '/' + centro.id : ''}`, { preserveScroll: true, onSuccess: () => (centro = null) })">Guardar</button></template>
    </Modal>

    <Modal :abierto="!!pres" :titulo="pres ? `Presupuesto ${tablero.anio} · ${pres.nombre}` : ''" ancho="max-w-3xl" @cerrar="pres = null">
      <p class="text-sm text-marca-muted mb-3">Cuánto pensás gastar cada mes en este centro. Podés poner un mismo importe para todos los meses y después ajustar.</p>
      <div class="flex items-center gap-2 mb-3"><input v-model.number="mismo" type="number" step="any" min="0" class="input !w-40" placeholder="Importe mensual" /><button class="btn-secondary !py-1 text-xs" @click="repetir">Poner en los 12 meses</button></div>
      <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2">
        <div v-for="m in tablero.meses" :key="m"><label class="label">{{ m }}</label><input v-model.number="pf.montos[tablero.meses.indexOf(m) + 1]" type="number" step="any" min="0" class="input !py-1.5 text-right tabular-nums" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="pres = null">Cancelar</button><button class="btn-primary" :disabled="pf.processing" @click="pf.post('/contable/centros/presupuesto', { preserveScroll: true, onSuccess: () => (pres = null) })" data-e2e="guardar-presupuesto">Guardar presupuesto</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const { puede } = usePermisos()
const props = defineProps({ tablero: Object, anios: Array })
const centro = ref(null), pres = ref(null), mismo = ref(null)
const cf = useForm({ nombre: '', codigo: '', activo: true })
const pf = useForm({ centro_costo_id: null, anio: props.tablero.anio, montos: {} })
function abrirCentro(f) { centro.value = f ?? { id: null }; cf.nombre = f?.nombre ?? ''; cf.codigo = f?.codigo ?? ''; cf.activo = f?.activo ?? true; cf.clearErrors() }
function abrirPresupuesto(f) { pres.value = f; pf.centro_costo_id = f.id; pf.anio = props.tablero.anio; pf.montos = Object.fromEntries(f.meses.map(m => [m.mes, m.presupuesto || null])) }
function repetir() { for (let m = 1; m <= 12; m++) pf.montos[m] = mismo.value }
const claseDesvio = (d, badge = false) => d === null || d === undefined ? (badge ? 'bg-gris-light text-marca-muted' : 'text-marca-muted') : d > 10 ? (badge ? 'bg-carmin-light text-carmin' : 'text-carmin font-semibold') : d > 0 ? (badge ? 'bg-amber-50 text-amber-700' : 'text-amber-700') : (badge ? 'bg-emerald-50 text-emerald-700' : 'text-emerald-700')
const textoDesvio = d => d === null || d === undefined ? 'sin presupuesto' : (d > 0 ? '+' : '') + d + ' %'
</script>
