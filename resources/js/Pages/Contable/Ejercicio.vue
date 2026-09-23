<template>
  <AppLayout titulo="Ejercicio">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Cierre de ejercicio</h1><p class="page-subtitle">Refundición de resultados, asientos de cierre y apertura, y ajuste por inflación con IPC.</p></div>
      <a :href="`/contable/diario?desde=${actual.desde}&hasta=${actual.hasta}`" target="_blank" class="btn-secondary">Libro diario del ejercicio</a>
    </div>
    <ContableTabs />

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <div class="card">
        <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Ejercicio en curso</p>
        <p class="text-xl font-extrabold tabular-nums">{{ fecha(actual.desde) }} → {{ fecha(actual.hasta) }}</p>
        <p class="text-xs text-marca-muted">{{ actual.asientos }} asientos confirmados · cierre en {{ meses[cierreMes - 1] }} (cambialo en Configuración → Impuestos)</p>
      </div>
      <div class="card">
        <h2 class="font-bold mb-1">Ajuste por inflación</h2>
        <p class="text-xs text-marca-muted mb-2">Reexpresa las cuentas ajustables (mercaderías, capital, resultados acumulados) por IPC y manda la diferencia a RECPAM. IPC de {{ periodoCierre }}: <b>{{ ipcCierre ? Number(ipcCierre).toLocaleString('es-AR') : 'falta cargarlo' }}</b>.</p>
        <div class="flex gap-2 items-end">
          <div class="flex-1"><label class="label">Al</label><input v-model="aj.hasta" type="date" class="input" /></div>
          <button class="btn-secondary" :disabled="aj.processing || !ipcCierre" @click="aj.post('/contable/ejercicio/ajuste', { preserveScroll: true })">Generar asiento</button>
        </div>
        <p v-if="aj.errors.hasta" class="text-carmin text-xs mt-1">{{ aj.errors.hasta }}</p>
        <ul v-if="ajustes.length" class="mt-3 text-xs space-y-1"><li v-for="a in ajustes" :key="a.id" :class="a.estado === 'anulado' ? 'line-through text-marca-muted' : ''">{{ a.fecha }} · {{ a.concepto }} · <b class="tabular-nums">{{ moneda(a.total, 0) }}</b></li></ul>
      </div>
      <div class="card border-carmin">
        <h2 class="font-bold mb-1">Cerrar el ejercicio</h2>
        <p class="text-xs text-marca-muted mb-2">Genera tres asientos: refundición de ingresos y egresos contra Resultados acumulados, cierre de todas las cuentas patrimoniales y apertura al día siguiente. Se puede reabrir.</p>
        <div class="flex gap-2 items-end">
          <div class="flex-1"><label class="label">Cerrar al</label><input v-model="ci.hasta" type="date" class="input" /></div>
          <button class="btn-primary" :disabled="ci.processing" @click="confirmar = true">Cerrar</button>
        </div>
        <p v-if="ci.errors.hasta" class="text-carmin text-xs mt-1">{{ ci.errors.hasta }}</p>
      </div>
    </div>

    <div class="card p-0 overflow-hidden">
      <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Ejercicios cerrados</h2></div>
      <table class="table text-sm">
        <thead><tr><th>Período</th><th>Estado</th><th>Cerrado</th><th class="text-right">Resultado</th><th>Asientos</th><th></th></tr></thead>
        <tbody>
          <tr v-for="e in ejercicios" :key="e.id">
            <td class="tabular-nums font-medium">{{ e.desde }} → {{ e.hasta }}</td>
            <td><span class="badge" :class="e.estado === 'cerrado' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ e.estado === 'cerrado' ? 'Cerrado' : 'Reabierto' }}</span></td>
            <td class="text-marca-muted tabular-nums">{{ e.cerrado_en }}</td>
            <td class="text-right tabular-nums font-semibold" :class="e.resultado < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(e.resultado) }}</td>
            <td class="text-xs text-marca-muted"><Link v-for="(id, k) in e.asientos" v-show="k !== 'resultado'" :key="k" :href="`/contable/asientos?buscar=${id}`" class="mr-2 underline">{{ k }}</Link></td>
            <td class="text-right"><button v-if="e.estado === 'cerrado'" class="btn-ghost !px-2 text-xs" @click="router.post(`/contable/ejercicio/${e.id}/reabrir`, {}, { preserveScroll: true })">Reabrir</button></td>
          </tr>
          <tr v-if="!ejercicios.length"><td colspan="6" class="text-center text-marca-muted py-6">Todavía no cerraste ningún ejercicio.</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="confirmar" titulo="Cerrar el ejercicio" @cerrar="confirmar = false">
      <p class="text-sm">Se van a generar los asientos de refundición, cierre y apertura al <b>{{ fecha(ci.hasta) }}</b>. Los asientos posteriores a esa fecha quedan en el ejercicio siguiente. ¿Seguimos?</p>
      <template #pie><button class="btn-secondary" @click="confirmar = false">Cancelar</button><button class="btn-primary" :disabled="ci.processing" @click="ci.post('/contable/ejercicio/cerrar', { preserveScroll: true, onFinish: () => (confirmar = false) })">Sí, cerrar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ actual: Object, cierreMes: Number, ejercicios: Array, ipcCierre: Number, periodoCierre: String, ajustes: Array, ajusteDefault: String })
const meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
const fecha = d => d ? d.split('-').reverse().join('/') : ''
const ci = useForm({ hasta: props.actual.hasta })
const aj = useForm({ hasta: props.ajusteDefault })
const confirmar = ref(false)
</script>
