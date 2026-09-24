<template>
  <AppLayout titulo="Hotelería">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Hotelería</h1><p class="page-subtitle">Cuadro de reservas por habitación, llegadas y salidas de hoy, check-in, consumos a la habitación y check-out con factura.</p></div>
      <div class="flex flex-wrap gap-2"><button class="btn-secondary" @click="configAbierto = true">Habitaciones</button><button class="btn-primary" @click="abrirReserva()">Nueva reserva</button></div>
    </div>
    <div class="grid grid-cols-3 md:grid-cols-6 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Ocupación hoy</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.ocupacion }}%</p><p class="text-xs text-marca-muted">{{ kpis.ocupadas }} de {{ kpis.habitaciones }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Llegadas</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.llegadas ? 'text-violeta' : ''">{{ kpis.llegadas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Salidas</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.salidas ? 'text-carmin' : ''">{{ kpis.salidas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En limpieza</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.limpieza }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Ocupación del mes</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.ocupacion_mes }}%</p><p class="text-xs text-marca-muted">tarifa media {{ moneda(kpis.adr, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Facturado en el mes</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.ingresos_mes, 0) }}</p></div>
    </div>

    <div class="grid lg:grid-cols-4 gap-4">
      <div class="lg:col-span-3 card p-0 overflow-hidden">
        <div class="flex items-center gap-2 px-4 py-3 border-b border-marca-borde">
          <Link :href="`/hoteleria?desde=${mas(-7)}`" class="btn-secondary !px-3">‹</Link><span class="font-bold text-sm">{{ fmt(cuadro.dias[0].fecha) }} al {{ fmt(cuadro.dias[cuadro.dias.length - 1].fecha) }}</span><Link :href="`/hoteleria?desde=${mas(7)}`" class="btn-secondary !px-3">›</Link><Link href="/hoteleria" class="btn-ghost text-xs">Hoy</Link>
          <span class="ml-auto text-[11px] text-marca-muted flex gap-3"><span><i class="inline-block w-3 h-3 rounded bg-amber-300 align-middle"></i> reservada</span><span><i class="inline-block w-3 h-3 rounded bg-violeta align-middle"></i> alojado</span></span>
        </div>
        <div class="overflow-x-auto">
          <div class="min-w-[900px]">
            <div class="grid" :style="`grid-template-columns: 150px repeat(${cuadro.dias.length}, 1fr)`">
              <div class="px-3 py-2 text-[11px] font-bold uppercase tracking-widest text-marca-muted border-b border-marca-borde">Habitación</div>
              <div v-for="d in cuadro.dias" :key="d.fecha" class="text-center py-2 border-b border-l border-marca-borde/60 text-xs" :class="[d.hoy ? 'bg-carmin/10 font-bold text-carmin' : d.finde ? 'bg-gris-light/60' : '']"><p class="capitalize text-[10px] text-marca-muted">{{ d.dia }}</p><p class="tabular-nums">{{ d.num }}</p></div>
            </div>
            <div v-for="h in cuadro.habitaciones" :key="h.id" class="grid relative border-b border-marca-borde/60" :style="`grid-template-columns: 150px repeat(${cuadro.dias.length}, 1fr)`">
              <div class="px-3 py-2 text-sm"><p class="font-bold">{{ h.nombre }} <span class="badge !text-[10px]" :class="{ 'bg-emerald-50 text-emerald-700': h.estado === 'libre', 'bg-violeta/10 text-violeta': h.estado === 'ocupada', 'bg-amber-50 text-amber-700': h.estado === 'limpieza', 'bg-red-50 text-carmin': h.estado === 'mantenimiento' }">{{ estadosHab[h.estado] }}</span></p><p class="text-[11px] text-marca-muted">{{ h.tipo }} · {{ h.capacidad }}p · {{ moneda(h.tarifa, 0) }}</p></div>
              <button v-for="d in cuadro.dias" :key="d.fecha" class="border-l border-marca-borde/40 min-h-[48px] hover:bg-violeta/5" :class="d.hoy ? 'bg-carmin/5' : ''" title="Reservar desde este día" @click="abrirReserva(h, d.fecha)"></button>
              <div v-for="e in h.estadias" :key="e.id" class="absolute top-2 h-8 rounded-lg px-2 text-[11px] font-semibold flex items-center overflow-hidden whitespace-nowrap shadow-sm cursor-pointer" :class="e.estado === 'checkin' ? 'bg-violeta text-white' : 'bg-amber-300 text-amber-900'" :style="barra(e)" @click="router.visit(`/hoteleria/estadias/${e.id}`)">{{ e.nombre }} · {{ e.personas }}p</div>
            </div>
            <p v-if="!cuadro.habitaciones.length" class="text-center text-marca-muted py-10 text-sm">Todavía no hay habitaciones. Cargalas con el botón "Habitaciones".</p>
          </div>
        </div>
      </div>
      <div class="space-y-4">
        <div class="card p-0 overflow-hidden">
          <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Llegadas de hoy</h2></div>
          <div class="divide-y divide-marca-borde/60 text-sm">
            <div v-for="l in llegadas" :key="l.id" class="px-4 py-2 flex items-center justify-between gap-2"><div class="min-w-0"><p class="font-medium truncate">{{ l.nombre }} <span class="text-xs text-marca-muted">· {{ l.habitacion }} · {{ l.personas }}p</span></p><p class="text-xs text-marca-muted">{{ l.desde }} al {{ l.hasta }}<span v-if="l.atrasada" class="text-carmin"> · debía llegar {{ l.desde }}</span></p></div><button class="btn-primary !py-1 text-xs" @click="router.post(`/hoteleria/estadias/${l.id}/checkin`, {}, { preserveScroll: true })">Check-in</button></div>
            <p v-if="!llegadas.length" class="px-4 py-5 text-center text-xs text-marca-muted">Sin llegadas pendientes.</p>
          </div>
        </div>
        <div class="card p-0 overflow-hidden">
          <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Salidas de hoy</h2></div>
          <div class="divide-y divide-marca-borde/60 text-sm">
            <Link v-for="s in salidas" :key="s.id" :href="`/hoteleria/estadias/${s.id}`" class="px-4 py-2 flex items-center justify-between gap-2 hover:bg-gris-light/40"><div class="min-w-0"><p class="font-medium truncate">{{ s.nombre }} <span class="text-xs text-marca-muted">· {{ s.habitacion }}</span></p><p class="text-xs" :class="s.atrasada ? 'text-carmin' : 'text-marca-muted'">{{ s.atrasada ? 'debía salir el ' + s.hasta : 'sale hoy' }} · saldo {{ moneda(s.saldo, 0) }}</p></div><span class="btn-secondary !py-1 text-xs">Check-out</span></Link>
            <p v-if="!salidas.length" class="px-4 py-5 text-center text-xs text-marca-muted">Sin salidas pendientes.</p>
          </div>
        </div>
      </div>
    </div>

    <Modal :abierto="reservaAbierta" titulo="Nueva reserva" ancho="max-w-2xl" @cerrar="reservaAbierta = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Desde</label><input v-model="rf.desde" type="date" class="input" /></div><div><label class="label">Hasta</label><input v-model="rf.hasta" type="date" class="input" /></div>
        <div><label class="label">Habitación</label><select v-model="rf.habitacion_id" class="input" @change="alElegirHab"><option v-for="h in cuadro.habitaciones" :key="h.id" :value="h.id">{{ h.nombre }} · {{ h.tipo }} · {{ h.capacidad }}p · {{ moneda(h.tarifa, 0) }}</option></select><p v-if="rf.errors.habitacion_id" class="text-carmin text-xs mt-1">{{ rf.errors.habitacion_id }}</p></div>
        <div><label class="label">Personas</label><input v-model.number="rf.personas" type="number" min="1" class="input" /><p v-if="rf.errors.personas" class="text-carmin text-xs mt-1">{{ rf.errors.personas }}</p></div>
        <div class="sm:col-span-2"><label class="label">Huésped con ficha</label><select v-model="rf.contact_id" class="input" @change="alElegirCliente"><option :value="null">Sin ficha (cargar datos)</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div><label class="label">Nombre</label><input v-model="rf.nombre" class="input" :disabled="!!rf.contact_id" /></div><div><label class="label">Teléfono</label><input v-model="rf.telefono" class="input" /></div>
        <div><label class="label">Email</label><input v-model="rf.email" type="email" class="input" /></div><div><label class="label">Documento</label><input v-model="rf.documento" class="input" /></div>
        <div><label class="label">Tarifa por noche</label><input v-model.number="rf.tarifa_noche" type="number" min="0" class="input" /></div><div><label class="label">Origen</label><select v-model="rf.origen" class="input"><option value="manual">Directo</option><option value="web">Web</option><option value="booking">Booking</option><option value="airbnb">Airbnb</option><option value="agencia">Agencia</option></select></div>
        <div class="sm:col-span-2"><label class="label">Notas</label><input v-model="rf.notas" class="input" placeholder="Cuna, llegada tarde, alergias…" /></div>
        <p class="sm:col-span-2 text-sm">{{ noches }} noche{{ noches === 1 ? '' : 's' }} · total <b class="tabular-nums">{{ moneda(noches * (rf.tarifa_noche || 0)) }}</b></p>
      </div>
      <template #pie><button class="btn-secondary" @click="reservaAbierta = false">Cancelar</button><button class="btn-primary" :disabled="rf.processing || !rf.habitacion_id || !rf.desde || !rf.hasta || (!rf.contact_id && !rf.nombre)" @click="rf.post('/hoteleria/reservas')">Reservar</button></template>
    </Modal>

    <Modal :abierto="configAbierto" titulo="Habitaciones" ancho="max-w-3xl" @cerrar="configAbierto = false">
      <table class="table text-xs">
        <thead><tr><th>Nombre</th><th>Tipo</th><th class="text-right">Cap.</th><th class="text-right">Tarifa</th><th>Piso</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <tr v-for="h in habs" :key="h.id ?? 'n'">
            <td><input v-model="h.nombre" class="input !py-1 !w-20" /></td><td><select v-model="h.tipo" class="input !py-1"><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l }}</option></select></td><td><input v-model.number="h.capacidad" type="number" min="1" class="input !py-1 !w-14 text-right" /></td><td><input v-model.number="h.tarifa" type="number" min="0" class="input !py-1 !w-24 text-right" /></td><td><input v-model="h.piso" class="input !py-1 !w-16" /></td>
            <td><select v-model="h.estado" class="input !py-1"><option v-for="(l, k) in estadosHab" :key="k" :value="k">{{ l }}</option></select></td>
            <td class="text-right whitespace-nowrap"><label v-if="h.id" class="text-[10px] mr-1"><input v-model="h.activa" type="checkbox" class="accent-carmin" /> activa</label><button class="btn-primary !py-1 text-xs" :disabled="!h.nombre" @click="router.post(`/hoteleria/habitaciones/${h.id || ''}`, h, { preserveScroll: true, onSuccess: () => { if (!h.id) Object.assign(h, { nombre: '', piso: '' }) } })">{{ h.id ? 'Guardar' : 'Agregar' }}</button></td>
          </tr>
        </tbody>
      </table>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, reactive } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, hoyISO } from '@/util/formato'
const props = defineProps({ cuadro: Object, desde: String, kpis: Object, tipos: Object, estadosHab: Object, estadosEst: Object, llegadas: Array, salidas: Array, clientes: Array, todasHabitaciones: Array })
const fmt = iso => iso.split('-').reverse().slice(0, 2).join('/')
const mas = n => { const d = new Date(props.desde + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10) }
const N = props.cuadro.dias.length
const barra = e => ({ left: `calc(150px + (100% - 150px) * ${e.inicio} / ${N} + 2px)`, width: `calc((100% - 150px) * ${Math.max(1, e.largo)} / ${N} - 4px)` })
const reservaAbierta = ref(false), configAbierto = ref(false)
const rf = useForm({ habitacion_id: null, contact_id: null, nombre: '', telefono: '', email: '', documento: '', personas: 2, desde: hoyISO(), hasta: '', tarifa_noche: 0, origen: 'manual', notas: '' })
const noches = computed(() => rf.desde && rf.hasta ? Math.max(0, Math.round((new Date(rf.hasta) - new Date(rf.desde)) / 86400000)) : 0)
function abrirReserva(h = null, fecha = null) { rf.clearErrors(); const hab = h ?? props.cuadro.habitaciones[0]; rf.habitacion_id = hab?.id ?? null; rf.tarifa_noche = hab?.tarifa ?? 0; rf.personas = Math.min(2, hab?.capacidad ?? 2); rf.desde = fecha ?? hoyISO(); const d = new Date(rf.desde + 'T12:00:00'); d.setDate(d.getDate() + 2); rf.hasta = d.toISOString().slice(0, 10); reservaAbierta.value = true }
function alElegirHab() { const h = props.cuadro.habitaciones.find(x => x.id === rf.habitacion_id); if (h) rf.tarifa_noche = h.tarifa }
function alElegirCliente() { const c = props.clientes.find(x => x.id === rf.contact_id); if (c) { rf.nombre = ''; rf.telefono = rf.telefono || c.phone || ''; rf.email = rf.email || c.email || ''; rf.documento = rf.documento || c.document || '' } }
const habs = reactive([...props.todasHabitaciones.map(h => ({ ...h })), { id: null, nombre: '', tipo: 'doble', capacidad: 2, tarifa: 0, piso: '', estado: 'libre', activa: true, orden: props.todasHabitaciones.length + 1 }])
</script>
