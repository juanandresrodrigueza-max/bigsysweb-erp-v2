<template>
  <AppLayout titulo="Fondos">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <h1 class="page-title">Fondos</h1>
        <p class="page-subtitle">Cajas, bancos y billeteras. Disponible {{ moneda(totales.disponible, 0) }} · cheques en cartera {{ moneda(totales.cheques_cartera, 0) }} · cheques propios a debitar {{ moneda(totales.cheques_propios, 0) }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <Link href="/fondos/cheques" class="btn-secondary">Cheques</Link>
        <button v-if="puede('fondos','crear')" @click="transfAbierto = true" class="btn-secondary">Transferir</button>
        <button v-if="puede('fondos','crear')" @click="abrirMov('egreso')" class="btn-secondary">Gasto</button>
        <button v-if="puede('fondos','crear')" @click="abrirMov('ingreso')" class="btn-secondary">Ingreso</button>
        <button v-if="puede('fondos','editar')" @click="abrirCuenta()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Cuenta</button>
      </div>
    </div>

    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-5">
      <button v-for="c in cuentas" :key="c.id" @click="verCuenta(c.id)" class="card text-left transition hover:border-carmin/40" :class="{ 'ring-2 ring-carmin': c.id === cuentaActual, 'opacity-50': !c.activa }">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ tipos[c.tipo] }}<span v-if="c.sucursal"> · {{ c.sucursal }}</span></p><p class="font-bold truncate">{{ c.nombre }}</p><p v-if="c.banco || c.alias" class="text-xs text-marca-muted truncate">{{ [c.banco, c.alias].filter(Boolean).join(' · ') }}</p></div>
          <span class="w-9 h-9 rounded-xl grid place-items-center shrink-0" :class="{ caja: 'bg-carmin-light text-carmin', banco: 'bg-violeta-light text-violeta', billetera: 'bg-magenta-light text-magenta', tarjeta: 'bg-gris-light text-marca-muted' }[c.tipo]"><Icono :nombre="{ caja: 'wallet', banco: 'building', billetera: 'store', tarjeta: 'receipt' }[c.tipo]" clase="w-4 h-4" /></span>
        </div>
        <p class="text-2xl font-extrabold tabular-nums mt-2" :class="c.saldo < 0 ? 'text-carmin' : c.saldo_minimo > 0 && c.saldo < c.saldo_minimo ? 'text-amber-600' : ''">{{ moneda(c.saldo, 0) }}</p>
        <div v-if="c.tipo === 'caja'" class="mt-2 text-xs">
          <span v-if="c.turno" class="badge bg-emerald-50 text-emerald-700">Turno abierto · {{ c.turno.usuario }} · {{ c.turno.desde }}</span>
          <span v-else class="badge bg-gris-light text-marca-muted">Sin turno abierto</span>
        </div>
      </button>
    </div>

    <div class="grid lg:grid-cols-4 gap-4">
      <div class="lg:col-span-3 card p-0 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-marca-borde">
          <h2 class="font-bold">Movimientos de {{ cuenta?.nombre ?? '…' }}</h2>
          <div class="flex flex-wrap gap-2 items-center">
            <input v-model="f.desde" @change="filtrar" type="date" class="input !py-1 w-auto text-xs" /><input v-model="f.hasta" @change="filtrar" type="date" class="input !py-1 w-auto text-xs" />
            <template v-if="cuenta?.tipo === 'caja' && puede('fondos','crear')">
              <button v-if="!cuenta.turno" @click="turnoAbierto = true" class="btn-primary !py-1 text-xs">Abrir turno</button>
              <button v-else @click="cierreAbierto = true" class="btn-violeta !py-1 text-xs">Cerrar turno</button>
            </template>
            <button v-if="cuenta && puede('fondos','editar')" @click="abrirCuenta(cuenta)" class="btn-ghost !px-2 text-xs"><Icono nombre="edit" clase="w-4 h-4" /></button>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead><tr><th>Fecha</th><th>Concepto</th><th>Origen</th><th class="text-right">Ingreso</th><th class="text-right">Egreso</th><th>Usuario</th></tr></thead>
            <tbody>
              <tr v-for="m in movimientos?.data ?? []" :key="m.id">
                <td class="tabular-nums text-marca-muted">{{ m.fecha }}</td>
                <td><p class="font-medium">{{ m.concepto }}</p><p v-if="m.categoria || m.referencia" class="text-xs text-marca-muted">{{ [m.categoria, m.referencia].filter(Boolean).join(' · ') }}</p></td>
                <td><span class="badge bg-gris-light text-marca-muted capitalize">{{ m.origen }}</span></td>
                <td class="text-right tabular-nums text-emerald-700 font-semibold">{{ m.ingreso ? moneda(m.ingreso) : '' }}</td>
                <td class="text-right tabular-nums text-carmin font-semibold">{{ m.egreso ? moneda(m.egreso) : '' }}</td>
                <td class="text-xs text-marca-muted">{{ m.usuario }}</td>
              </tr>
              <tr v-if="!movimientos?.data?.length"><td colspan="6" class="text-center text-marca-muted py-10">Sin movimientos.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="px-4"><Paginacion :links="movimientos?.links" :desde="movimientos?.from" :hasta="movimientos?.to" :total="movimientos?.total" /></div>
      </div>
      <div class="space-y-4">
        <div class="card"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Este mes</p>
          <div class="flex justify-between text-sm mt-2"><span>Ingresos</span><b class="tabular-nums text-emerald-700">{{ moneda(totales.ingresos_mes, 0) }}</b></div>
          <div class="flex justify-between text-sm"><span>Egresos</span><b class="tabular-nums text-carmin">{{ moneda(totales.egresos_mes, 0) }}</b></div>
          <div class="flex justify-between text-sm pt-1 border-t border-marca-borde mt-1"><span>Neto</span><b class="tabular-nums">{{ moneda(totales.ingresos_mes - totales.egresos_mes, 0) }}</b></div>
        </div>
        <div class="card text-sm">
          <div class="flex items-center justify-between mb-2"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Categorías de gasto</p><button @click="catAbierto = true" class="text-xs text-violeta font-semibold">+ Nueva</button></div>
          <div class="flex flex-wrap gap-1.5"><span v-for="c in categorias" :key="c.id" class="badge text-white" :style="{ background: c.color || '#6f6a62' }">{{ c.name }}</span><span v-if="!categorias.length" class="text-marca-muted">Sin categorías.</span></div>
        </div>
      </div>
    </div>

    <!-- Cuenta -->
    <Modal :abierto="cuentaModal" :titulo="cf.id ? 'Editar cuenta' : 'Nueva cuenta de fondos'" @cerrar="cuentaModal = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div><label class="label">Tipo</label><select v-model="cf.tipo" class="input" :disabled="!!cf.id"><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="label">Nombre</label><input v-model="cf.nombre" class="input" /><p v-if="cf.errors.nombre" class="text-carmin text-xs mt-1">{{ cf.errors.nombre }}</p></div>
        <template v-if="cf.tipo === 'banco'"><div><label class="label">Banco</label><input v-model="cf.banco" class="input" /></div><div><label class="label">CBU</label><input v-model="cf.cbu" class="input tabular-nums" /></div><div><label class="label">Alias</label><input v-model="cf.alias" class="input" /></div></template>
        <div><label class="label">Sucursal</label><select v-model="cf.business_location_id" class="input"><option :value="null">Toda la empresa</option><option v-for="s in listaSucursales" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
        <div><label class="label">Saldo mínimo (alerta)</label><input v-model.number="cf.saldo_minimo" type="number" min="0" class="input" /></div>
        <div v-if="!cf.id"><label class="label">Saldo inicial</label><input v-model.number="cf.saldo_inicial" type="number" class="input" /></div>
        <label class="flex items-center gap-2 text-sm"><input v-model="cf.es_default" type="checkbox" class="accent-carmin" /> Predeterminada para su tipo</label>
        <label v-if="cf.id" class="flex items-center gap-2 text-sm"><input v-model="cf.activa" type="checkbox" class="accent-carmin" /> Activa</label>
      </div>
      <template #pie><button class="btn-secondary" @click="cuentaModal = false">Cancelar</button><button class="btn-primary" :disabled="cf.processing" @click="cf.post(`/fondos/cuentas${cf.id ? '/' + cf.id : ''}`, { preserveScroll: true, onSuccess: () => (cuentaModal = false) })">Guardar</button></template>
    </Modal>

    <!-- Movimiento manual -->
    <Modal :abierto="movModal" :titulo="mv.tipo === 'egreso' ? 'Registrar gasto' : 'Registrar ingreso'" @cerrar="movModal = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2"><label class="label">Cuenta</label><select v-model="mv.cuenta_fondos_id" class="input"><option v-for="c in cuentas.filter(x => x.activa)" :key="c.id" :value="c.id">{{ c.nombre }} · {{ moneda(c.saldo, 0) }}</option></select></div>
        <div class="sm:col-span-2"><label class="label">Concepto</label><input v-model="mv.concepto" class="input" placeholder="Ej: Alquiler galpón septiembre" /><p v-if="mv.errors.concepto" class="text-carmin text-xs mt-1">{{ mv.errors.concepto }}</p></div>
        <div><label class="label">Importe</label><input v-model.number="mv.monto" type="number" step="any" min="0" class="input" /><p v-if="mv.errors.monto" class="text-carmin text-xs mt-1">{{ mv.errors.monto }}</p></div>
        <div><label class="label">Fecha</label><input v-model="mv.fecha" type="date" class="input" /></div>
        <div v-if="mv.tipo === 'egreso'"><label class="label">Categoría</label><select v-model="mv.expense_category_id" class="input"><option :value="null">Sin categoría</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div><label class="label">Referencia</label><input v-model="mv.referencia" class="input" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="movModal = false">Cancelar</button><button class="btn-primary" :disabled="mv.processing" @click="mv.post('/fondos/movimiento', { preserveScroll: true, onSuccess: () => { movModal = false; mv.reset('concepto', 'monto', 'referencia') } })">Registrar</button></template>
    </Modal>

    <!-- Transferencia -->
    <Modal :abierto="transfAbierto" titulo="Transferir entre cuentas" @cerrar="transfAbierto = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div><label class="label">Desde</label><select v-model="tf.desde" class="input"><option v-for="c in cuentas.filter(x => x.activa)" :key="c.id" :value="c.id">{{ c.nombre }} · {{ moneda(c.saldo, 0) }}</option></select></div>
        <div><label class="label">Hacia</label><select v-model="tf.hasta" class="input"><option v-for="c in cuentas.filter(x => x.activa)" :key="c.id" :value="c.id">{{ c.nombre }}</option></select><p v-if="tf.errors.hasta" class="text-carmin text-xs mt-1">{{ tf.errors.hasta }}</p></div>
        <div><label class="label">Importe</label><input v-model.number="tf.monto" type="number" step="any" min="0" class="input" /><p v-if="tf.errors.monto" class="text-carmin text-xs mt-1">{{ tf.errors.monto }}</p></div>
        <div><label class="label">Fecha</label><input v-model="tf.fecha" type="date" class="input" /></div>
        <div class="sm:col-span-2"><label class="label">Referencia</label><input v-model="tf.referencia" class="input" placeholder="Ej: depósito de efectivo en banco" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="transfAbierto = false">Cancelar</button><button class="btn-primary" :disabled="tf.processing" @click="tf.post('/fondos/transferir', { preserveScroll: true, onSuccess: () => (transfAbierto = false) })">Transferir</button></template>
    </Modal>

    <!-- Turnos -->
    <Modal :abierto="turnoAbierto" :titulo="`Abrir turno · ${cuenta?.nombre}`" @cerrar="turnoAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Contá el efectivo con el que arranca la caja. Si difiere del saldo del sistema ({{ moneda(cuenta?.saldo ?? 0) }}) se registra un ajuste.</p>
      <label class="label">Efectivo inicial</label><input v-model.number="ta.saldo_inicial" type="number" step="any" min="0" class="input" />
      <template #pie><button class="btn-secondary" @click="turnoAbierto = false">Cancelar</button><button class="btn-primary" :disabled="ta.processing" @click="ta.post(`/fondos/cuentas/${cuenta.id}/abrir-turno`, { preserveScroll: true, onSuccess: () => (turnoAbierto = false) })">Abrir</button></template>
    </Modal>
    <Modal :abierto="cierreAbierto" :titulo="`Cerrar turno · ${cuenta?.nombre}`" @cerrar="cierreAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Saldo esperado según el sistema: <b class="tabular-nums">{{ moneda(cuenta?.saldo ?? 0) }}</b>. Ingresá lo contado; la diferencia queda registrada.</p>
      <label class="label">Efectivo contado</label><input v-model.number="tc.saldo_contado" type="number" step="any" min="0" class="input" />
      <label class="label mt-3">Notas</label><input v-model="tc.notas" class="input" />
      <template #pie><button class="btn-secondary" @click="cierreAbierto = false">Cancelar</button><button class="btn-violeta" :disabled="tc.processing" @click="tc.post(`/fondos/turnos/${cuenta.turno.id}/cerrar`, { preserveScroll: true, onSuccess: () => (cierreAbierto = false) })">Cerrar turno</button></template>
    </Modal>

    <Modal :abierto="catAbierto" titulo="Nueva categoría de gasto" @cerrar="catAbierto = false">
      <div class="flex gap-2 items-end"><div class="flex-1"><label class="label">Nombre</label><input v-model="cat.name" class="input" /></div><input v-model="cat.color" type="color" class="w-10 h-10 rounded-lg border border-marca-borde" /></div>
      <template #pie><button class="btn-secondary" @click="catAbierto = false">Cancelar</button><button class="btn-primary" :disabled="cat.processing || !cat.name" @click="cat.post('/fondos/categorias', { preserveScroll: true, onSuccess: () => { catAbierto = false; cat.reset() } })">Crear</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda, hoyISO } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ cuentas: Array, cuentaActual: Number, movimientos: Object, filtros: Object, totales: Object, categorias: Array, listaSucursales: Array, tipos: Object })
const { puede } = usePermisos()
const cuenta = computed(() => props.cuentas.find(c => c.id === props.cuentaActual))
const f = reactive({ cuenta: props.cuentaActual, desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '' })
function filtrar() { router.get('/fondos', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true }) }
function verCuenta(id) { f.cuenta = id; filtrar() }

const cuentaModal = ref(false)
const cf = useForm({ id: null, tipo: 'caja', nombre: '', banco: '', cbu: '', alias: '', business_location_id: null, saldo_minimo: 0, saldo_inicial: 0, es_default: false, activa: true })
function abrirCuenta(c) { cf.clearErrors(); Object.assign(cf, c ? { ...c, saldo_inicial: 0 } : { id: null, tipo: 'caja', nombre: '', banco: '', cbu: '', alias: '', business_location_id: null, saldo_minimo: 0, saldo_inicial: 0, es_default: false, activa: true }); cuentaModal.value = true }

const movModal = ref(false)
const mv = useForm({ cuenta_fondos_id: props.cuentaActual, fecha: hoyISO(), tipo: 'egreso', monto: null, concepto: '', expense_category_id: null, referencia: '' })
function abrirMov(tipo) { mv.tipo = tipo; mv.cuenta_fondos_id = props.cuentaActual; movModal.value = true }

const transfAbierto = ref(false)
const tf = useForm({ desde: props.cuentaActual, hasta: null, monto: null, fecha: hoyISO(), referencia: '' })
const turnoAbierto = ref(false), cierreAbierto = ref(false), catAbierto = ref(false)
const ta = useForm({ saldo_inicial: 0 })
const tc = useForm({ saldo_contado: 0, notas: '' })
const cat = useForm({ name: '', color: '#4f3089' })
</script>
