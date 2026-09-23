<template>
  <AppLayout titulo="Agenda">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Agenda de turnos</h1><p class="page-subtitle">Turnos por día y por profesional. Recordá por WhatsApp y cobrá desde acá: el turno atendido se factura con un clic.</p></div>
      <div class="flex gap-2"><button class="btn-primary" @click="abrir()">Nuevo turno</button></div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Turnos hoy</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.hoy }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Esta semana</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.semana }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Sin confirmar</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.sin_confirmar ? 'text-amber-600' : ''">{{ kpis.sin_confirmar }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Atendidos sin facturar</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.facturable ? 'text-carmin' : ''">{{ moneda(kpis.facturable, 0) }}</p></div>
    </div>
    <div class="flex flex-wrap items-center gap-2 mb-3">
      <Link :href="url(semanaMas(-7))" class="btn-secondary !px-3">‹</Link>
      <span class="font-bold text-sm tabular-nums">{{ fmt(semana.desde) }} al {{ fmt(semana.hasta) }}</span>
      <Link :href="url(semanaMas(7))" class="btn-secondary !px-3">›</Link>
      <Link href="/agenda" class="btn-ghost text-xs">Hoy</Link>
      <select class="input !w-auto ml-auto" :value="filtros.profesional || ''" @change="router.get('/agenda', { semana: semana.desde, profesional: $event.target.value || undefined }, { preserveState: true })">
        <option value="">Todos los profesionales</option><option v-for="p in profesionales" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
    </div>
    <div class="overflow-x-auto">
      <div class="grid grid-cols-7 gap-2 min-w-[900px]">
        <div v-for="d in semana.dias" :key="d.fecha" class="card p-2 min-h-[260px]" :class="d.hoy ? 'ring-2 ring-carmin/40' : ''">
          <div class="flex items-center justify-between mb-2"><p class="font-bold text-sm capitalize" :class="d.hoy ? 'text-carmin' : ''">{{ d.label }}</p><button class="btn-ghost !px-1.5 !py-0 text-xs" title="Agendar" @click="abrir(null, d.fecha)">+</button></div>
          <div class="space-y-1.5">
            <button v-for="t in porDia(d.fecha)" :key="t.id" class="w-full text-left rounded-lg border px-2 py-1.5 text-xs hover:shadow transition" :class="clase(t)" @click="ver(t)">
              <div class="flex justify-between"><b class="tabular-nums">{{ t.hora }}</b><span class="text-[10px]">{{ estados[t.estado] }}</span></div>
              <p class="font-medium truncate">{{ t.cliente }}</p>
              <p class="truncate opacity-80">{{ t.servicio || 'Sin servicio' }}<span v-if="t.profesional"> · {{ t.profesional }}</span></p>
            </button>
            <p v-if="!porDia(d.fecha).length" class="text-[11px] text-marca-muted text-center pt-6">Libre</p>
          </div>
        </div>
      </div>
    </div>

    <Modal :abierto="modal" :titulo="f.id ? 'Turno' : 'Nuevo turno'" @cerrar="modal = false">
      <div v-if="sel" class="mb-3 flex flex-wrap items-center gap-2 text-xs">
        <span class="badge" :class="clase(sel)">{{ estados[sel.estado] }}</span>
        <span v-if="sel.comprobante" class="badge bg-emerald-50 text-emerald-700">Facturado: {{ sel.comprobante }}</span>
        <span v-if="sel.recordado" class="text-marca-muted">Recordatorio enviado</span>
        <span v-if="sel.origen && sel.origen !== 'manual'" class="text-marca-muted">Origen: {{ sel.origen }}</span>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2"><label class="label">Cliente</label>
          <select v-model="f.contact_id" class="input" @change="alElegirCliente"><option :value="null">Sin ficha (cargar nombre)</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div><label class="label">Nombre</label><input v-model="f.nombre" class="input" :disabled="!!f.contact_id" placeholder="Si no tiene ficha" /></div>
        <div><label class="label">Teléfono</label><input v-model="f.telefono" class="input" placeholder="Para el recordatorio" /></div>
        <div><label class="label">Servicio</label><select v-model="f.service_id" class="input" @change="alElegirServicio"><option :value="null">—</option><option v-for="s in servicios" :key="s.id" :value="s.id">{{ s.name }} · {{ moneda(s.price, 0) }}</option></select></div>
        <div><label class="label">Profesional</label><select v-model="f.assigned_to" class="input"><option :value="null">Cualquiera</option><option v-for="p in profesionales" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
        <div><label class="label">Fecha</label><input v-model="f.fecha" type="date" class="input" /></div>
        <div><label class="label">Hora</label><input v-model="f.hora" type="time" class="input" /><p v-if="f.errors.hora" class="text-xs text-carmin mt-1">{{ f.errors.hora }}</p></div>
        <div><label class="label">Duración (min)</label><input v-model.number="f.minutos" type="number" min="5" step="5" class="input" /></div>
        <div><label class="label">Precio</label><input v-model.number="f.precio" type="number" min="0" step="0.01" class="input" /></div>
        <div class="col-span-2"><label class="label">Notas</label><input v-model="f.notas" class="input" placeholder="Color, patente, obra social…" /></div>
        <div v-if="f.id"><label class="label">Estado</label><select v-model="f.estado" class="input"><option v-for="(l, k) in estados" :key="k" :value="k">{{ l }}</option></select></div>
      </div>
      <template #pie>
        <div class="flex flex-wrap gap-2 w-full">
          <template v-if="sel">
            <button v-if="['pending', 'confirmed'].includes(sel.estado)" class="btn-secondary text-xs" :disabled="!(f.telefono)" title="Recordar por WhatsApp" @click="recordar(sel)">Recordar</button>
            <button v-if="sel.estado === 'pending'" class="btn-secondary text-xs" @click="estado(sel, 'confirmed')">Confirmar</button>
            <button v-if="['pending', 'confirmed'].includes(sel.estado)" class="btn-secondary text-xs" @click="estado(sel, 'completed')">Atendido</button>
            <button v-if="['pending', 'confirmed'].includes(sel.estado)" class="btn-ghost text-xs text-carmin" @click="estado(sel, 'cancelled')">Cancelar turno</button>
            <button v-if="!sel.comprobante_id && sel.estado !== 'cancelled'" class="btn-primary text-xs" @click="cobrar(sel)">Cobrar</button>
          </template>
          <span class="flex-1"></span>
          <button class="btn-secondary" @click="modal = false">Cerrar</button>
          <button class="btn-primary" :disabled="f.processing || !f.fecha || !f.hora || (!f.contact_id && !f.nombre)" @click="guardar">Guardar</button>
        </div>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ semana: Object, turnos: Array, profesionales: Array, servicios: Array, clientes: Array, filtros: Object, estados: Object, kpis: Object })
const page = usePage()
const fmt = iso => iso.split('-').reverse().slice(0, 2).join('/')
const semanaMas = n => { const d = new Date(props.semana.desde + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10) }
const url = s => `/agenda?semana=${s}` + (props.filtros.profesional ? `&profesional=${props.filtros.profesional}` : '')
const porDia = fecha => props.turnos.filter(t => t.fecha === fecha)
const clase = t => ({ pending: 'bg-amber-50 border-amber-200 text-amber-900', confirmed: 'bg-violeta/10 border-violeta/30 text-marca-texto', completed: 'bg-emerald-50 border-emerald-200 text-emerald-900', cancelled: 'bg-gris-light border-marca-borde text-marca-muted line-through', no_show: 'bg-red-50 border-red-200 text-carmin' }[t.estado])
const modal = ref(false), sel = ref(null)
const f = useForm({ id: null, contact_id: null, nombre: '', telefono: '', service_id: null, assigned_to: props.filtros.profesional ? Number(props.filtros.profesional) : null, fecha: '', hora: '10:00', minutos: 30, precio: 0, notas: '', estado: 'confirmed' })
function abrir(t = null, fecha = null) { f.clearErrors(); sel.value = t; f.id = t?.id ?? null; f.contact_id = t?.contact_id ?? null; f.nombre = t?.contact_id ? '' : (t?.cliente ?? ''); f.telefono = t?.telefono ?? ''; f.service_id = t?.service_id ?? null; f.assigned_to = t?.assigned_to ?? (props.filtros.profesional ? Number(props.filtros.profesional) : null); f.fecha = t?.fecha ?? fecha ?? props.semana.dias.find(d => d.hoy)?.fecha ?? props.semana.desde; f.hora = t?.hora ?? '10:00'; f.minutos = t?.minutos ?? 30; f.precio = t?.precio ?? 0; f.notas = t?.notas ?? ''; f.estado = t?.estado ?? 'confirmed'; modal.value = true }
const ver = t => abrir(t)
function alElegirCliente() { const c = props.clientes.find(x => x.id === f.contact_id); if (c) { f.nombre = ''; if (!f.telefono) f.telefono = c.phone || '' } }
function alElegirServicio() { const s = props.servicios.find(x => x.id === f.service_id); if (s) f.precio = s.price }
function guardar() { f.post(`/agenda/turnos/${f.id || ''}`, { preserveScroll: true, onSuccess: () => { modal.value = false } }) }
function estado(t, e) { router.post(`/agenda/turnos/${t.id}/estado`, { estado: e }, { preserveScroll: true, onSuccess: () => { modal.value = false } }) }
function recordar(t) { router.post(`/agenda/turnos/${t.id}/recordar`, {}, { preserveScroll: true, onSuccess: () => { modal.value = false } }) }
function cobrar(t) { router.post(`/agenda/turnos/${t.id}/cobrar`) }
// Si el recordatorio no salió por la API de WhatsApp, abrimos el link wa.me
watch(() => page.props.flash?.abrir, l => { if (l) window.open(l, '_blank') }, { immediate: true })
</script>
