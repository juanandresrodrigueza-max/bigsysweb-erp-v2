<template>
  <AppLayout titulo="Sueldos · Firmas y recibos">
    <!-- Fase 38: el estado de firma de cada recibo, los PDF que vienen de afuera, la firma del empleador y los datos del período. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/sueldos" class="text-xs text-marca-muted hover:text-carmin">← Sueldos</Link><h1 class="page-title">Firmas y recibos</h1><p class="page-subtitle">Quién recibió, vio y firmó su recibo. Los PDF de otro programa o del contador también se mandan y se firman.</p></div>
      <a :href="`/sueldos/recibos?csv=1${periodo ? '&periodo=' + periodo : ''}`" class="btn-secondary">Bajar historial (Excel)</a>
    </div>
    <div class="flex gap-1.5 text-xs mb-3"><button v-for="t in [['firmas', 'Firmas'], ['externos', 'Recibos externos'], ['periodo', 'Datos del período'], ['empleador', 'Firma del empleador']]" :key="t[0]" class="rounded-full px-3 py-1 font-semibold border" :class="tab === t[0] ? 'bg-marca-texto text-white border-marca-texto' : 'border-marca-borde'" @click="tab = t[0]" :data-tab="t[0]">{{ t[1] }}</button></div>

    <div v-if="tab === 'firmas'" class="card p-0 overflow-x-auto" data-e2e="firmas">
      <table class="table text-sm"><thead><tr><th>Empleado</th><th>Período</th><th>Enviado</th><th>Visto</th><th>Firmado</th><th>IP</th><th></th></tr></thead>
        <tbody><tr v-for="h in historial" :key="h.tipo + h.id"><td>{{ h.empleado }}<span v-if="h.tipo === 'externo'" class="badge bg-gris-light !text-[10px] ml-1">PDF externo</span></td><td>{{ h.periodo }}</td><td class="text-xs">{{ h.enviado ?? '—' }}</td><td class="text-xs">{{ h.visto ?? '—' }}</td>
          <td class="text-xs"><span v-if="h.firmado" :class="h.conformidad === 'disconforme' ? 'text-carmin font-semibold' : 'text-emerald-700'">{{ h.firmado }}<span v-if="h.conformidad === 'disconforme'"> · disconforme: {{ h.observacion }}</span></span><span v-else class="text-marca-muted">falta</span></td><td class="text-xs tabular-nums">{{ h.ip }}</td>
          <td><button v-if="!h.firmado && h.empleado !== '— sin asignar —'" class="text-xs text-violeta font-semibold" @click="router.post(`/sueldos/enviar/${h.tipo}/${h.id}`, {}, { preserveScroll: true })">{{ h.enviado ? 'Reenviar' : 'Enviar' }}</button></td></tr>
          <tr v-if="!historial.length"><td colspan="7" class="text-center text-marca-muted py-8">Todavía no hay recibos.</td></tr></tbody></table>
    </div>

    <div v-if="tab === 'externos'" class="space-y-3" data-e2e="externos">
      <div class="card text-sm"><h2 class="font-bold mb-1">Subir el PDF de la liquidación</h2><p class="text-xs text-marca-muted mb-2">El PDF entero, como lo manda el contador. Se separa por hoja y cada una se asigna a su empleado por CUIL, DNI o legajo. Si lo volvés a subir (aunque tenga otro nombre), no se duplica.</p>
        <div class="flex flex-wrap gap-2 items-end"><label class="text-xs">Período (si el PDF no lo dice)<input v-model="ext.periodo" type="month" class="input !py-1" /></label><input type="file" accept="application/pdf" class="input !w-auto !py-1.5 text-xs" @change="ext.archivo = $event.target.files[0]" data-e2e="pdf" /><button class="btn-primary !py-1.5" :disabled="ext.processing || !ext.archivo" @click="ext.post('/sueldos/externos', { forceFormData: true, preserveScroll: true, onSuccess: () => ext.reset() })" data-e2e="subir-pdf">Separar y repartir</button></div>
        <p v-if="ext.errors.archivo" class="text-carmin text-xs mt-1">{{ ext.errors.archivo }}</p></div>
      <div class="card p-0 overflow-x-auto"><table class="table text-sm"><thead><tr><th>Hoja</th><th>Empleado</th><th>Período</th><th>Estado</th><th></th></tr></thead>
        <tbody><tr v-for="r in externos" :key="r.id" :class="!r.empleado_id ? 'bg-amber-50/60' : ''"><td class="text-xs">{{ r.archivo }} · hoja {{ r.pagina }}<p v-if="!r.empleado_id" class="text-[11px] text-marca-muted truncate max-w-xs">{{ r.pista }}</p></td>
          <td><span v-if="r.empleado_id">{{ r.empleado }} <span class="text-[10px] text-marca-muted">por {{ { cuil: 'CUIL', dni: 'DNI', legajo: 'legajo', nombre: 'nombre', a_mano: 'vos' }[r.por] }}</span></span>
            <select v-else class="input !py-1 text-xs" @change="router.post(`/sueldos/externos/${r.id}/asignar`, { empleado_id: $event.target.value }, { preserveScroll: true })" data-e2e="asignar"><option value="">¿De quién es?</option><option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.nombre }} · {{ e.cuil ?? 'leg. ' + e.legajo }}</option></select></td>
          <td class="text-xs">{{ r.periodo ?? '—' }}</td><td class="text-xs">{{ r.firmado ? 'firmado ' + r.firmado : r.enviado ? 'enviado ' + r.enviado : '' }}</td>
          <td class="whitespace-nowrap"><a :href="r.url" target="_blank" class="text-xs text-violeta">ver</a><button v-if="r.empleado_id && !r.firmado" class="text-xs text-violeta font-semibold ml-2" @click="router.post(`/sueldos/enviar/externo/${r.id}`, {}, { preserveScroll: true })">enviar</button></td></tr>
          <tr v-if="!externos.length"><td colspan="5" class="text-center text-marca-muted py-6">No subiste recibos externos.</td></tr></tbody></table></div>
    </div>

    <div v-if="tab === 'periodo'" class="grid lg:grid-cols-2 gap-4" data-e2e="periodo">
      <div class="card text-sm"><h2 class="font-bold mb-1">Datos del período</h2><p class="text-xs text-marca-muted mb-2">Se cargan una vez por mes y salen en todos los recibos.</p>
        <div class="grid grid-cols-2 gap-2"><label class="text-xs">Período<input v-model="per.periodo" type="month" class="input !py-1" /></label><label class="text-xs">Fecha de pago<input v-model="per.fecha_pago" type="date" class="input !py-1" /></label><label class="text-xs">Último depósito jubilatorio<input v-model="per.deposito_fecha" type="date" class="input !py-1" /></label><label class="text-xs">Banco<input v-model="per.deposito_banco" class="input !py-1" /></label><label class="text-xs">Mes depositado<input v-model="per.deposito_periodo" type="month" class="input !py-1" /></label></div>
        <button class="btn-primary mt-2" :disabled="!per.periodo" @click="router.post('/sueldos/periodo', per, { preserveScroll: true })">Guardar para todos los recibos</button></div>
      <div class="card text-sm"><h2 class="font-bold mb-1">Costo laboral de los últimos 12 meses</h2><p class="text-xs text-marca-muted mb-2">Bruto + no remunerativo + contribuciones del empleador. Total {{ moneda(costo.total, 0) }} · promedio {{ moneda(costo.promedio, 0) }} por mes.</p>
        <div v-for="m in costo.meses" :key="m.periodo" class="flex items-center gap-2 py-0.5"><span class="w-16 text-xs">{{ m.periodo }}</span><div class="flex-1 h-3 rounded bg-gris-light"><div class="h-3 rounded bg-violeta" :style="{ width: (costo.meses.length ? m.costo / Math.max(...costo.meses.map(x => x.costo)) * 100 : 0) + '%' }"></div></div><span class="w-28 text-right tabular-nums text-xs">{{ moneda(m.costo, 0) }}</span></div>
        <p v-if="!costo.meses.length" class="text-xs text-marca-muted">Sin liquidaciones confirmadas en el último año.</p></div>
    </div>

    <div v-if="tab === 'empleador'" class="card text-sm max-w-xl" data-e2e="empleador">
      <h2 class="font-bold mb-1">Firma del empleador</h2><p class="text-xs text-marca-muted mb-2">Firmás una sola vez y va en todos los recibos.</p>
      <div v-if="firma_empleador" class="mb-2"><img :src="firma_empleador.img" class="h-16 border border-marca-borde rounded bg-white" /><p class="text-xs text-marca-muted">{{ firma_empleador.nombre }} · {{ firma_empleador.en }}</p></div>
      <canvas ref="lienzo" width="600" height="180" class="w-full border border-dashed border-marca-borde rounded-xl bg-white touch-none" @pointerdown="pd" @pointermove="pm" @pointerup="dib = false" @pointerleave="dib = false"></canvas>
      <div class="flex gap-2 mt-2"><input v-model="nombre" class="input !py-1" placeholder="Nombre y cargo" /><button class="btn-ghost !py-1 text-xs" @click="limpiar">Limpiar</button><button class="btn-primary !py-1 text-xs" :disabled="!trazos" @click="router.post('/sueldos/firma-empleador', { firma: lienzo.toDataURL('image/png'), nombre }, { preserveScroll: true })">{{ firma_empleador ? 'Reemplazar firma' : 'Guardar firma' }}</button></div>
    </div>
  </AppLayout>
</template>
<script setup>
import { ref, reactive } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ historial: Array, periodo: String, externos: Array, empleados: Array, costo: Object, firma_empleador: Object })
const tab = ref('firmas')
const ext = useForm({ archivo: null, periodo: '' })
const per = reactive({ periodo: new Date().toISOString().slice(0, 7), fecha_pago: '', deposito_fecha: '', deposito_banco: '', deposito_periodo: '' })
const lienzo = ref(null), dib = ref(false), trazos = ref(0), nombre = ref(props.firma_empleador?.nombre ?? '')
const pos = e => { const r = lienzo.value.getBoundingClientRect(); return [(e.clientX - r.left) * lienzo.value.width / r.width, (e.clientY - r.top) * lienzo.value.height / r.height] }
function pd(e) { dib.value = true; const c = lienzo.value.getContext('2d'); c.lineWidth = 2.5; c.lineCap = 'round'; c.beginPath(); c.moveTo(...pos(e)); trazos.value++ }
function pm(e) { if (!dib.value) return; const c = lienzo.value.getContext('2d'); c.lineTo(...pos(e)); c.stroke() }
function limpiar() { lienzo.value.getContext('2d').clearRect(0, 0, 600, 180); trazos.value = 0 }
</script>
