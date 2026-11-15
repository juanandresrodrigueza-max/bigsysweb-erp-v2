<template>
  <AdminLayout titulo="Pendientes">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Altas pendientes de aprobación</h1><p class="page-subtitle">Las empresas nuevas entran al toque con acceso provisional por {{ horas }} horas. Si nadie las aprueba con la clave, se bloquean hasta que alguien lo haga.</p></div>
      <Link href="/admin/sistema#aprobacion" class="btn-secondary !py-1.5 text-xs">Clave y avisos</Link>
    </div>

    <div v-if="!claveConfigurada" class="mb-4 px-4 py-3 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-3" data-e2e="sin-clave">
      <Icono nombre="alert" clase="w-5 h-5 shrink-0" /><span>Todavía no hay <b>clave de aprobación</b>. Sin clave no se puede aprobar: cargala en <Link href="/admin/sistema" class="underline font-semibold">Sistema → Altas con aprobación</Link>.</span>
    </div>

    <div class="card p-0 overflow-hidden">
      <table class="table" v-if="lista.length">
        <thead><tr><th>Empresa</th><th>Dueño</th><th>Origen</th><th>Alta</th><th>Acceso provisional</th><th></th></tr></thead>
        <tbody>
          <tr v-for="e in lista" :key="e.id" :data-e2e="`pendiente-${e.id}`">
            <td><Link :href="`/admin/empresas/${e.id}`" class="font-semibold hover:text-carmin">{{ e.nombre }}</Link><p class="text-xs text-marca-muted">{{ e.rubro }}<span v-if="e.cuit"> · {{ e.cuit }}</span><span v-if="e.plan"> · {{ e.plan }}</span></p></td>
            <td><p class="font-medium">{{ e.dueno }}</p><p class="text-xs text-marca-muted">{{ e.email }}<span v-if="e.celular"> · {{ e.celular }}</span></p></td>
            <td class="text-sm">{{ e.origen }}</td>
            <td class="text-sm tabular-nums">{{ e.alta }}<p class="text-xs text-marca-muted">{{ e.hace }}<span v-if="e.ultimo_acceso"> · entró {{ e.ultimo_acceso }}</span></p></td>
            <td><span v-if="e.bloqueada" class="badge bg-carmin-light text-carmin">Bloqueada desde {{ e.corte }}</span><span v-else class="badge" :class="e.horas_restantes <= 12 ? 'bg-amber-50 text-amber-700' : 'bg-lavanda-light text-violeta'">{{ e.horas_restantes }} h · hasta {{ e.corte }}</span></td>
            <td class="text-right whitespace-nowrap"><button class="btn-primary !py-1 text-xs" @click="abrir(e, 'aprobar')" :data-e2e="`aprobar-${e.id}`">Aprobar</button> <button class="btn-ghost !py-1 text-xs text-carmin" @click="abrir(e, 'rechazar')">Rechazar</button></td>
          </tr>
        </tbody>
      </table>
      <p v-else class="text-sm text-marca-muted py-10 text-center">No hay altas esperando. Las nuevas aparecen acá y te llegan por mail, WhatsApp y al celular.</p>
    </div>

    <Modal :abierto="!!sel" :titulo="accion === 'aprobar' ? `Aprobar ${sel?.nombre}` : `Rechazar ${sel?.nombre}`" @cerrar="sel = null">
      <div class="space-y-3 text-sm">
        <p v-if="accion === 'aprobar'">La empresa y su dueño quedan habilitados en el acto y le avisamos por mail. Esto es lo que te separa de que cualquiera se dé de alta: pedí la clave.</p>
        <p v-else>Queda <b>suspendida</b> con el motivo; nadie de la empresa entra. Desde la ficha se puede reactivar.</p>
        <div v-if="accion === 'rechazar'"><label class="label">Motivo</label><input v-model="form.motivo" class="input" placeholder="Ej: datos falsos, duplicada, prueba interna" /><p v-if="form.errors.motivo" class="text-xs text-carmin mt-1">{{ form.errors.motivo }}</p></div>
        <div><label class="label">Clave de aprobación</label><input v-model="form.clave" type="password" class="input" autocomplete="off" data-e2e="clave-aprobacion" /><p v-if="form.errors.clave" class="text-xs text-carmin mt-1" data-e2e="error-clave">{{ form.errors.clave }}</p></div>
      </div>
      <template #pie><button class="btn-secondary" @click="sel = null">Cancelar</button><button :class="accion === 'aprobar' ? 'btn-primary' : 'btn-danger'" :disabled="form.processing || !form.clave" @click="enviar" data-e2e="confirmar-aprobacion">{{ accion === 'aprobar' ? 'Aprobar' : 'Rechazar' }}</button></template>
    </Modal>
  </AdminLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
defineProps({ lista: Array, horas: Number, claveConfigurada: Boolean })
const sel = ref(null), accion = ref('aprobar')
const form = useForm({ clave: '', motivo: '' })
function abrir(e, a) { sel.value = e; accion.value = a; form.reset(); form.clearErrors() }
function enviar() { form.post(`/admin/pendientes/${sel.value.id}/${accion.value}`, { preserveScroll: true, onSuccess: () => { sel.value = null; form.reset() } }) }
</script>
