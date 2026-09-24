<template>
  <AppLayout titulo="Cobranzas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/clientes" class="text-xs text-marca-muted hover:text-carmin">← Clientes</Link>
        <h1 class="page-title">Cobranzas</h1>
        <p class="page-subtitle">Quién debe, hace cuánto, y los avisos que salen solos por mail o WhatsApp.</p>
      </div>
      <div class="flex gap-2">
        <button v-if="puede('clientes','crear')" @click="router.post('/clientes/cobranzas/correr', {}, { preserveScroll: true })" class="btn-secondary" :title="config.activo ? '' : 'Activá los avisos automáticos primero'">Enviar avisos de hoy</button>
        <button v-if="puede('clientes','editar')" @click="cfgAbierta = true" class="btn-violeta"><Icono nombre="settings" clase="w-4 h-4" /> Avisos automáticos {{ config.activo ? 'ON' : 'OFF' }}</button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Por cobrar</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.por_cobrar, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vencido</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(kpis.vencido, 0) }}</p><p class="text-xs text-marca-muted">{{ kpis.deudores }} clientes</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Mora acumulada</p><p class="text-xl font-extrabold tabular-nums text-amber-700">{{ moneda(kpis.mora, 0) }}</p><p class="text-xs text-marca-muted">según % de cada cliente</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Avisos</p><p class="text-xl font-extrabold tabular-nums">{{ config.activo ? config.dias.join(' / ') : 'OFF' }}</p><p class="text-xs text-marca-muted">días respecto del vencimiento</p></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 card p-0 overflow-x-auto">
        <table class="table">
          <thead><tr><th>Cliente</th><th class="text-right">Saldo</th><th class="text-right">Vencido</th><th class="text-right">Atraso</th><th class="text-right">Mora</th><th>Último aviso</th><th></th></tr></thead>
          <tbody>
            <tr v-for="d in deudores" :key="d.id">
              <td><Link :href="`/clientes/${d.id}`" class="font-semibold hover:text-carmin">{{ d.nombre }}</Link><p class="text-xs text-marca-muted">{{ [d.vendedor, d.email, d.telefono].filter(Boolean).join(' · ') }}<span v-if="d.plan" class="badge bg-violeta-light text-violeta ml-1">plan de pago</span></p></td>
              <td class="text-right tabular-nums">{{ moneda(d.saldo, 0) }}</td>
              <td class="text-right tabular-nums" :class="d.vencido > 0 ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ moneda(d.vencido, 0) }}</td>
              <td class="text-right tabular-nums" :class="d.dias > 30 ? 'text-carmin' : d.dias > 0 ? 'text-amber-700' : 'text-marca-muted'">{{ d.dias ? d.dias + ' d' : '—' }}</td>
              <td class="text-right tabular-nums text-amber-700">{{ d.mora ? moneda(d.mora, 0) : '' }}</td>
              <td class="text-xs text-marca-muted">{{ d.ultimo_aviso ?? '—' }}</td>
              <td class="text-right whitespace-nowrap">
                <button v-if="d.email && puede('clientes','crear')" @click="recordar(d, 'mail')" class="btn-ghost !px-2 text-xs" title="Recordatorio por mail">Mail</button>
                <button v-if="d.telefono && puede('clientes','crear')" @click="recordar(d, 'whatsapp')" class="btn-ghost !px-2 text-xs" title="Recordatorio por WhatsApp">WhatsApp</button><button v-if="crmActivo && puede('clientes','crear')" @click="recordar(d, 'crm')" class="btn-ghost !px-2 text-xs text-violeta" title="Crea la tarea de cobranza en el CRM">CRM</button>
                <button v-if="d.vencido > 0 && !d.plan && puede('clientes','crear')" @click="abrirRefi(d)" class="btn-ghost !px-2 text-xs text-violeta">Refinanciar</button>
              </td>
            </tr>
            <tr v-if="!deudores.length"><td colspan="7" class="text-center text-marca-muted py-10">Nadie debe nada. 🎉</td></tr>
          </tbody>
        </table>
      </div>
      <div class="space-y-4">
        <div class="card text-sm">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Planes de pago</p>
          <div v-for="p in planes" :key="p.id" class="py-2 border-t border-marca-borde first:border-0">
            <div class="flex justify-between"><span class="font-semibold">{{ p.numero }} · {{ p.cliente }}</span><span class="badge" :class="p.estado === 'pagado' ? 'bg-emerald-50 text-emerald-700' : p.estado === 'vigente' ? 'bg-violeta-light text-violeta' : 'bg-gris-light text-marca-muted'">{{ p.estado }}</span></div>
            <p class="text-xs text-marca-muted">{{ p.fecha }} · {{ moneda(p.total, 0) }} en {{ p.cuotas.length }} cuotas<span v-if="p.interes"> · interés {{ moneda(p.interes, 0) }}</span></p>
            <div class="flex flex-wrap gap-1 mt-1"><span v-for="q in p.cuotas" :key="q.numero" class="text-[10px] px-1.5 py-0.5 rounded" :class="q.estado === 'pagada' ? 'bg-emerald-50 text-emerald-700' : q.estado === 'vencida' ? 'bg-carmin-light text-carmin' : 'bg-gris-light text-marca-muted'" :title="`Cuota ${q.numero} vence ${q.vencimiento}: ${moneda(q.monto)} (pagado ${moneda(q.pagado)})`">{{ q.numero }} · {{ q.vencimiento.slice(0, 5) }}</span></div>
          </div>
          <p v-if="!planes.length" class="text-marca-muted">Sin planes de pago.</p>
        </div>
        <div class="card text-sm">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Últimos avisos</p>
          <div v-for="e in envios" :key="e.id" class="flex items-center justify-between gap-2 py-1.5 border-t border-marca-borde first:border-0">
            <span class="min-w-0"><span class="block truncate">{{ e.cliente }} · {{ e.canal }}</span><span class="text-xs text-marca-muted">{{ e.fecha }} · {{ e.destino }}</span></span>
            <span class="text-xs font-semibold" :class="e.estado === 'enviado' ? 'text-emerald-700' : e.estado === 'error' ? 'text-carmin' : 'text-amber-700'" :title="e.error ?? ''">{{ e.estado }}<a v-if="e.link && e.estado === 'pendiente'" :href="e.link" target="_blank" class="text-violeta ml-1">abrir</a></span>
          </div>
          <p v-if="!envios.length" class="text-marca-muted">Todavía no salió ningún aviso.</p>
        </div>
      </div>
    </div>

    <!-- Configuración -->
    <Modal :abierto="cfgAbierta" titulo="Avisos automáticos de cobranza" ancho="max-w-2xl" @cerrar="cfgAbierta = false">
      <label class="flex items-center gap-2 text-sm mb-3"><input v-model="cf.activo" type="checkbox" class="accent-carmin" /> <b>Mandar recordatorios solos</b> (todos los días a las 9:30)</label>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Días respecto del vencimiento</label><input v-model="diasTexto" class="input" placeholder="-3, 0, 7, 30" /><p class="text-[10px] text-marca-muted mt-1">Negativo = antes de vencer. Ej: -3 avisa 3 días antes, 7 avisa a la semana de vencida.</p></div>
        <div><label class="label">Canales</label><div class="flex gap-3 mt-2"><label class="flex items-center gap-1.5 text-sm"><input v-model="cf.canales" value="mail" type="checkbox" class="accent-carmin" /> Mail</label><label class="flex items-center gap-1.5 text-sm"><input v-model="cf.canales" value="whatsapp" type="checkbox" class="accent-carmin" /> WhatsApp</label><label v-if="crmActivo" class="flex items-center gap-1.5 text-sm" title="Crea una tarea de cobranza en el CRM para que el vendedor la mande desde la conversación del cliente"><input v-model="cf.canales" value="crm" type="checkbox" class="accent-carmin" /> Por el CRM</label></div><p class="text-[10px] text-marca-muted mt-1">{{ mailConfigurado ? 'Mail configurado.' : 'Mail en modo prueba (se registra, no sale).' }} {{ whatsapp.configurado ? 'WhatsApp por API.' : 'WhatsApp sin API: quedan como pendientes para mandar a mano.' }}</p></div>
        <div class="sm:col-span-2"><label class="label">Texto del aviso</label><textarea v-model="cf.texto" rows="3" class="input text-sm"></textarea><p class="text-[10px] text-marca-muted mt-1">Variables: {cliente} {comprobante} {importe} {estado} {link} {empresa}</p></div>
        <div class="sm:col-span-2 border-t border-marca-borde pt-3"><p class="label">WhatsApp Business API (opcional)</p><div class="grid sm:grid-cols-2 gap-2"><input v-model="cf.whatsapp_token" class="input !py-1 text-xs" placeholder="Token permanente de Meta" /><input v-model="cf.whatsapp_phone_id" class="input !py-1 text-xs" placeholder="Phone number ID" /></div><p class="text-[10px] text-marca-muted mt-1">Con esto los mensajes salen solos desde tu número. Sin esto, se abre WhatsApp Web con el texto listo.</p></div>
      </div>
      <template #pie><button class="btn-secondary" @click="cfgAbierta = false">Cancelar</button><button class="btn-primary" :disabled="cf.processing" @click="cf.transform(d => ({ ...d, dias: diasTexto.split(/[,\s]+/).filter(x => x !== '').map(Number) })).post('/clientes/cobranzas/configurar', { preserveScroll: true, onSuccess: () => (cfgAbierta = false) })">Guardar</button></template>
    </Modal>

    <!-- Refinanciar -->
    <Modal :abierto="!!refi" :titulo="`Refinanciar a ${refi?.nombre}`" ancho="max-w-2xl" @cerrar="refi = null">
      <p class="text-sm text-marca-muted mb-3">Se genera una nota de débito por el interés y un plan en cuotas. Las facturas elegidas dejan de figurar vencidas hasta la última cuota.</p>
      <div v-if="cargandoPend" class="text-sm text-marca-muted">Cargando facturas…</div>
      <div v-else class="max-h-48 overflow-y-auto border border-marca-borde rounded-xl divide-y divide-marca-borde/60 mb-3">
        <label v-for="p in pendRefi" :key="p.id" class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer"><input v-model="rf.comprobantes" :value="p.id" type="checkbox" class="accent-carmin" /><span class="flex-1">{{ p.nombre }} {{ p.numero }} <span class="text-xs text-marca-muted">vence {{ p.fecha_vto }}</span></span><b class="tabular-nums">{{ moneda(p.saldo) }}</b></label>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div><label class="label">Cuotas</label><input v-model.number="rf.cuotas" type="number" min="1" max="36" class="input" /></div>
        <div><label class="label">Interés total %</label><input v-model.number="rf.interes_pct" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">Primer vencimiento</label><input v-model="rf.primer_vencimiento" type="date" class="input" /></div>
      </div>
      <div class="mt-3 rounded-xl bg-marca-fondo p-3 text-sm space-y-1">
        <div class="flex justify-between"><span>Deuda a refinanciar</span><span class="tabular-nums">{{ moneda(deudaRefi) }}</span></div>
        <div class="flex justify-between"><span>Interés</span><span class="tabular-nums">{{ moneda(deudaRefi * (rf.interes_pct || 0) / 100) }}</span></div>
        <div class="flex justify-between font-bold"><span>{{ rf.cuotas }} cuotas de</span><span class="tabular-nums">{{ moneda(deudaRefi * (1 + (rf.interes_pct || 0) / 100) / (rf.cuotas || 1)) }}</span></div>
      </div>
      <p v-if="rf.errors.comprobantes" class="text-carmin text-xs mt-2">{{ rf.errors.comprobantes }}</p>
      <template #pie><button class="btn-secondary" @click="refi = null">Cancelar</button><button class="btn-primary" :disabled="rf.processing || !rf.comprobantes.length" @click="rf.post(`/clientes/cobranzas/${refi.id}/refinanciar`, { preserveScroll: true, onSuccess: () => (refi = null) })">Crear plan</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ deudores: Array, kpis: Object, config: Object, whatsapp: Object, mailConfigurado: Boolean, envios: Array, planes: Array, crmActivo: Boolean })
const { puede } = usePermisos()
const page = usePage()
const cfgAbierta = ref(false)
const cf = useForm({ activo: props.config.activo, canales: [...props.config.canales], texto: props.config.texto, whatsapp_token: props.whatsapp.token, whatsapp_phone_id: props.whatsapp.phone_id })
const diasTexto = ref(props.config.dias.join(', '))
function recordar(d, canal) { router.post(`/clientes/cobranzas/${d.id}/recordar`, { canal }, { preserveScroll: true, onSuccess: () => { const abrir = page.props.flash?.abrir; if (abrir) { window.open(abrir, '_blank'); const id = page.props.flash?.envio_id; if (id) window.axios.post(`/envios/${id}/marcar`).catch(() => {}) } } }) }

const refi = ref(null), pendRefi = ref([]), cargandoPend = ref(false)
const rf = useForm({ comprobantes: [], cuotas: 3, interes_pct: 0, primer_vencimiento: new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10), notas: '' })
async function abrirRefi(d) {
  refi.value = d; rf.clearErrors(); rf.comprobantes = []; rf.interes_pct = d.interes_mora * 2 || 0; cargandoPend.value = true
  try { const { data } = await window.axios.get(`/clientes/${d.id}/pendientes`); pendRefi.value = data; rf.comprobantes = data.filter(p => p.vencido).map(p => p.id) } finally { cargandoPend.value = false }
}
const deudaRefi = computed(() => pendRefi.value.filter(p => rf.comprobantes.includes(p.id)).reduce((a, p) => a + p.saldo, 0))
</script>
