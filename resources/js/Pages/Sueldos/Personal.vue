<template>
  <AppLayout titulo="Ausencias y vacaciones">
    <!-- Fase 98: licencias y vacaciones (LCT), calendario de feriados de la empresa. Lo que se carga acá cae solo en las novedades del mes al liquidar. -->
    <PageHeader titulo="Ausencias y vacaciones" compacto
      :principal="puede('sueldos','crear') ? { label: 'Cargar ausencia', click: () => abrirAusencia() } : null"
      :secundaria="puede('sueldos','editar') ? { label: 'Feriado propio', click: () => (feriadoAbierto = true) } : null"
      :volver="{ href: '/sueldos', label: 'Sueldos' }">
      <template #subtitulo>Vacaciones por antigüedad como dice la LCT, licencias, faltas y los feriados del año. Al liquidar el mes, los días ya vienen puestos.</template>
    </PageHeader>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Ausentes hoy</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.hoy ? 'text-carmin' : ''">{{ kpis.hoy }}</p><p class="text-[11px] text-marca-muted truncate">{{ kpis.hoy_nombres.join(', ') || 'Están todos' }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vacaciones en 30 días</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.proximas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Días sin tomar {{ anio }}</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.saldo_total }}</p><p class="text-[11px] text-marca-muted">entre todos los activos</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Próximo feriado</p><p v-if="kpis.proximo_feriado" class="text-sm font-extrabold">{{ kpis.proximo_feriado.dia }}</p><p v-if="kpis.proximo_feriado" class="text-[11px] text-marca-muted truncate">{{ kpis.proximo_feriado.nombre }} · en {{ kpis.proximo_feriado.en_dias }} días</p><p v-else class="text-sm text-marca-muted">—</p></div>
    </div>

    <div v-if="pendientes.length" class="card mb-4 border-amber-200 bg-amber-50/60" data-e2e="pendientes">
      <p class="font-bold text-sm mb-2">Esperan tu aprobación</p>
      <div v-for="a in pendientes" :key="a.id" class="flex flex-wrap items-center gap-2 text-sm py-1 border-t border-amber-100 first:border-0">
        <span class="font-semibold">{{ a.empleado }}</span><span>{{ a.tipo_label }}</span><span class="text-marca-muted">{{ a.desde_label }} → {{ a.hasta_label }} · {{ a.dias }} d</span><span v-if="a.notas" class="text-xs text-marca-muted italic">{{ a.notas }}</span>
        <span class="ml-auto flex gap-1" v-if="puede('sueldos','editar')"><button class="btn-primary !py-0.5 text-xs" @click="decidir(a, 'aprobada')">Aprobar</button><button class="btn-ghost !py-0.5 text-xs text-carmin" @click="decidir(a, 'rechazada')">Rechazar</button></span>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-0 overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-marca-borde">
            <h2 class="font-bold">Ausencias de {{ mesLabel }}</h2>
            <div class="flex items-center gap-1"><button class="btn-ghost !py-1 text-xs" @click="irMes(-1)" aria-label="Mes anterior">‹</button><input type="month" :value="mes" class="input !py-1 !w-40 text-xs" @change="router.get('/sueldos/personal', { mes: $event.target.value, anio }, { preserveState: true, replace: true })" /><button class="btn-ghost !py-1 text-xs" @click="irMes(1)" aria-label="Mes siguiente">›</button></div>
          </div>
          <div class="overflow-x-auto"><table class="table text-sm">
            <thead><tr><th>Empleado</th><th>Tipo</th><th>Desde</th><th>Hasta</th><th class="text-right">Días</th><th>Estado</th><th></th></tr></thead>
            <tbody>
              <tr v-for="a in ausencias" :key="a.id" :data-ausencia="a.id">
                <td><p class="font-semibold">{{ a.empleado }}</p><p v-if="a.notas" class="text-xs text-marca-muted truncate max-w-[220px]" :title="a.notas">{{ a.notas }}</p></td>
                <td class="whitespace-nowrap"><span class="badge" :class="colorTipo(a)">{{ a.tipo_label }}</span><span v-if="a.certificado" class="ml-1 text-[10px] text-marca-muted" title="Con certificado">📎</span></td>
                <td class="tabular-nums">{{ a.desde_label }}</td><td class="tabular-nums">{{ a.hasta_label }}</td>
                <td class="text-right tabular-nums font-semibold">{{ a.dias }}</td>
                <td><span class="badge" :class="{ pendiente: 'bg-amber-50 text-amber-700', aprobada: 'bg-emerald-50 text-emerald-700', rechazada: 'bg-gris-light text-marca-muted' }[a.estado]">{{ estados[a.estado] }}</span></td>
                <td class="text-right whitespace-nowrap"><button v-if="puede('sueldos','crear')" class="btn-ghost !py-0.5 text-xs" @click="abrirAusencia(a)" title="Editar" aria-label="Editar">Editar</button><button v-if="puede('sueldos','anular')" class="btn-ghost !py-0.5 text-xs text-carmin" @click="router.post(`/sueldos/personal/ausencias/${a.id}/borrar`, {}, { preserveScroll: true })" title="Borrar" aria-label="Borrar">×</button></td>
              </tr>
              <tr v-if="!ausencias.length"><td colspan="7" class="!py-0"><EstadoVacio texto="Nadie falta este mes. Cargá vacaciones, licencias o faltas y al liquidar ya vienen puestas." /></td></tr>
            </tbody>
          </table></div>
        </div>

        <div class="card p-0 overflow-hidden" data-e2e="vacaciones">
          <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-marca-borde">
            <div><h2 class="font-bold">Vacaciones {{ anio }}</h2><p class="text-xs text-marca-muted">LCT art. 150: 14 días hasta 5 años de antigüedad, 21 hasta 10, 28 hasta 20, 35 después. Menos de 6 meses: 1 día cada 20 trabajados.</p></div>
            <select :value="anio" class="input !py-1 !w-28 text-xs" @change="router.get('/sueldos/personal', { mes, anio: $event.target.value }, { preserveState: true, replace: true })"><option v-for="a in anios" :key="a" :value="a">{{ a }}</option></select>
          </div>
          <div class="overflow-x-auto"><table class="table text-sm">
            <thead><tr><th>Empleado</th><th>Ingreso</th><th class="text-right">Le corresponden</th><th class="text-right">Ajuste</th><th class="text-right">Tomados</th><th class="text-right">Saldo</th></tr></thead>
            <tbody>
              <tr v-for="v in vacaciones" :key="v.id">
                <td><p class="font-semibold">{{ v.nombre }}</p><p class="text-[11px] text-marca-muted">{{ v.regla }}</p></td>
                <td class="tabular-nums text-xs">{{ v.ingreso }}</td>
                <td class="text-right tabular-nums">{{ v.derecho }}</td><td class="text-right tabular-nums text-marca-muted">{{ v.ajuste || '—' }}</td><td class="text-right tabular-nums">{{ v.tomadas }}</td>
                <td class="text-right tabular-nums font-extrabold" :class="v.saldo < 0 ? 'text-carmin' : v.saldo > 0 ? 'text-violeta' : ''">{{ v.saldo }}</td>
              </tr>
              <tr v-if="!vacaciones.length"><td colspan="6" class="!py-0"><EstadoVacio texto="Todavía no hay empleados activos." /></td></tr>
            </tbody>
          </table></div>
        </div>
      </div>

      <div class="card p-0 overflow-hidden" data-e2e="feriados">
        <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Feriados {{ anio }}</h2><p class="text-xs text-marca-muted">Los nacionales se calculan solos (ley 27.399). Agregá los puentes del año y los propios; si un nacional en tu rubro se trabaja, quitalo.</p></div>
        <ul class="divide-y divide-marca-borde/60 text-sm max-h-[640px] overflow-y-auto">
          <li v-for="f in feriados" :key="f.fecha" class="flex items-center gap-2 px-4 py-2" :class="f.quitado ? 'opacity-50 line-through' : ''">
            <span class="tabular-nums text-xs w-28 shrink-0" :class="f.dow >= 6 ? 'text-marca-muted' : ''">{{ f.fecha_label }}</span>
            <span class="flex-1 truncate" :title="f.nombre">{{ f.nombre }}</span>
            <span class="badge text-[10px]" :class="f.origen === 'nacional' ? 'bg-gris-light text-marca-muted' : f.tipo === 'puente' ? 'bg-violeta/10 text-violeta' : 'bg-emerald-50 text-emerald-700'">{{ f.origen === 'nacional' ? (f.tipo === 'trasladable' ? 'trasladable' : 'nacional') : tiposFeriado[f.tipo] }}</span>
            <template v-if="puede('sueldos','editar')">
              <button v-if="f.origen === 'nacional' && !f.quitado" class="text-marca-muted hover:text-carmin text-xs" title="En esta empresa se trabaja" aria-label="Quitar" @click="router.post('/sueldos/personal/feriados', { fecha: f.fecha, nombre: f.nombre, tipo: 'quitado' }, { preserveScroll: true })">quitar</button>
              <button v-else-if="f.id" class="text-marca-muted hover:text-carmin text-xs" :title="f.quitado ? 'Volver a tenerlo como feriado' : 'Borrar'" aria-label="Borrar" @click="router.post(`/sueldos/personal/feriados/${f.id}/borrar`, {}, { preserveScroll: true })">{{ f.quitado ? 'volver' : '×' }}</button>
            </template>
          </li>
        </ul>
      </div>
    </div>

    <!-- Ausencia -->
    <Modal :abierto="ausenciaAbierta" :titulo="af.id ? 'Editar ausencia' : 'Cargar ausencia'" @cerrar="ausenciaAbierta = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2"><label class="label">Empleado</label><select v-model="af.empleado_id" class="input" data-e2e="aus-empleado"><option :value="null">Elegí</option><option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.legajo }} · {{ e.nombre }}</option></select></div>
        <div class="sm:col-span-2"><label class="label">Tipo</label><select v-model="af.tipo" class="input" data-e2e="aus-tipo"><option v-for="t in tipos" :key="t.key" :value="t.key">{{ t.label }}</option></select><p class="text-[11px] text-marca-muted mt-1">{{ ayudaTipo }}</p></div>
        <div><label class="label">Desde</label><input v-model="af.desde" type="date" class="input" data-e2e="aus-desde" /></div><div><label class="label">Hasta</label><input v-model="af.hasta" type="date" class="input" data-e2e="aus-hasta" /></div>
        <div><label class="label">Días <span class="font-normal text-marca-muted">(vacío: los cuenta solo)</span></label><input v-model="af.dias" type="number" step="0.5" min="0" class="input" :placeholder="String(diasEstimados)" /></div>
        <div><label class="label">Estado</label><select v-model="af.estado" class="input"><option v-for="(l, k) in estados" :key="k" :value="k">{{ l }}</option></select></div>
        <div v-if="af.tipo === 'vacaciones'"><label class="label">Período de vacaciones</label><select v-model="af.anio_vacaciones" class="input"><option :value="null">El que corresponde (enero a abril: el año anterior)</option><option v-for="a in anios" :key="a" :value="a">{{ a }}</option></select><p v-if="saldoElegido" class="text-[11px] mt-1" :class="saldoElegido.saldo - diasEstimados < 0 ? 'text-carmin' : 'text-marca-muted'">Le quedan {{ saldoElegido.saldo }} de {{ saldoElegido.derecho }}{{ saldoElegido.saldo - diasEstimados < 0 ? ' · se pasa' : '' }}</p></div>
        <label class="flex items-center gap-2 text-sm"><input v-model="af.certificado" type="checkbox" class="accent-carmin" /> Trajo certificado</label>
        <div class="sm:col-span-2"><label class="label">Notas</label><input v-model="af.notas" class="input" placeholder="Opcional" /></div>
        <p v-if="af.errors.error || Object.keys(af.errors).length" class="sm:col-span-2 text-carmin text-xs">{{ Object.values(af.errors)[0] }}</p>
      </div>
      <template #pie><button class="btn-secondary" @click="ausenciaAbierta = false">Cancelar</button><button class="btn-primary" :disabled="af.processing || !af.empleado_id || !af.desde" @click="af.post(`/sueldos/personal/ausencias/${af.id || ''}`, { preserveScroll: true, onSuccess: () => (ausenciaAbierta = false) })" data-e2e="aus-guardar">Guardar</button></template>
    </Modal>

    <!-- Feriado propio -->
    <Modal :abierto="feriadoAbierto" titulo="Feriado propio o puente" @cerrar="feriadoAbierto = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Fecha</label><input v-model="ff.fecha" type="date" class="input" /></div>
        <div><label class="label">Tipo</label><select v-model="ff.tipo" class="input"><option value="propio">Feriado propio de la empresa</option><option value="puente">Puente turístico</option><option value="no_laborable">Día no laborable</option></select></div>
        <div class="sm:col-span-2"><label class="label">Nombre</label><input v-model="ff.nombre" class="input" placeholder="Día del empleado de comercio, puente turístico…" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="feriadoAbierto = false">Cancelar</button><button class="btn-primary" :disabled="ff.processing || !ff.fecha || !ff.nombre" @click="ff.post('/sueldos/personal/feriados', { preserveScroll: true, onSuccess: () => { feriadoAbierto = false; ff.reset() } })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import Modal from '@/Components/Modal.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import { hoyISO } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const { puede } = usePermisos()
const props = defineProps({ mes: String, anio: Number, empleados: Array, ausencias: Array, pendientes: Array, vacaciones: Array, feriados: Array, tipos: Array, estados: Object, tiposFeriado: Object, kpis: Object })
const mesLabel = computed(() => { const [y, m] = props.mes.split('-'); return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' }) })
const anios = computed(() => { const y = new Date().getFullYear(); return [y - 2, y - 1, y, y + 1] })
function irMes(d) { const [y, m] = props.mes.split('-').map(Number); const n = new Date(y, m - 1 + d, 1); router.get('/sueldos/personal', { mes: `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}`, anio: props.anio }, { preserveState: true, replace: true }) }
const colorTipo = a => a.efecto === 'vacaciones' ? 'bg-violeta/10 text-violeta' : a.efecto === 'descuenta' ? 'bg-carmin/10 text-carmin' : 'bg-sky-50 text-sky-700'
function decidir(a, estado) { router.post(`/sueldos/personal/ausencias/${a.id}/decidir`, { estado }, { preserveScroll: true }) }

const ausenciaAbierta = ref(false)
const af = useForm({ id: null, empleado_id: null, tipo: 'vacaciones', desde: hoyISO(), hasta: hoyISO(), dias: '', estado: 'aprobada', anio_vacaciones: null, certificado: false, notas: '' })
function abrirAusencia(a = null) { af.clearErrors(); Object.assign(af, { id: a?.id ?? null, empleado_id: a?.empleado_id ?? null, tipo: a?.tipo ?? 'vacaciones', desde: a?.desde ?? hoyISO(), hasta: a?.hasta ?? hoyISO(), dias: a ? a.dias : '', estado: a?.estado ?? 'aprobada', anio_vacaciones: a?.anio_vacaciones ?? null, certificado: a?.certificado ?? false, notas: a?.notas ?? '' }); ausenciaAbierta.value = true }
const tipoActual = computed(() => props.tipos.find(t => t.key === af.tipo))
const ayudaTipo = computed(() => { const t = tipoActual.value; if (!t) return ''; return (t.conteo === 'corridos' ? 'Se cuentan días corridos. ' : 'Se cuentan sin domingos ni feriados. ') + (t.efecto === 'vacaciones' ? 'Va a la columna Vacaciones del recibo y baja los días normales.' : t.efecto === 'descuenta' ? 'Baja los días trabajados del recibo.' : 'Se paga igual: no toca el recibo.') })
// Estimación en pantalla (la cuenta final la hace el servidor, con los feriados de la empresa).
const diasEstimados = computed(() => { if (!af.desde || !af.hasta) return 0; const d1 = new Date(af.desde + 'T00:00'), d2 = new Date(af.hasta + 'T00:00'); if (d2 < d1) return 0; if (tipoActual.value?.conteo === 'corridos') return Math.round((d2 - d1) / 86400000) + 1; const fer = new Set(props.feriados.filter(f => !f.quitado).map(f => f.fecha)); let n = 0; for (let d = new Date(d1); d <= d2; d.setDate(d.getDate() + 1)) { if (d.getDay() !== 0 && !fer.has(d.toISOString().slice(0, 10))) n++ } return n })
const saldoElegido = computed(() => { if (af.tipo !== 'vacaciones' || !af.empleado_id) return null; const v = props.vacaciones.find(x => x.id === af.empleado_id); return v && (af.anio_vacaciones == null || af.anio_vacaciones === props.anio) ? v : null })

const feriadoAbierto = ref(false)
const ff = useForm({ fecha: '', nombre: '', tipo: 'propio' })
</script>
