<template>
  <AppLayout titulo="Copias de seguridad">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <div class="card">
        <h2 class="font-bold mb-1">Copia de seguridad</h2>
        <p class="text-sm text-marca-muted mb-3">Un zip con todos los datos de la empresa: cada tabla en JSON (para restaurar) y en CSV (para abrir en Excel), más adjuntos y certificados.</p>
        <button class="btn-primary w-full" :disabled="bk.processing" @click="bk.post('/configuracion/datos/backup', { preserveScroll: true })">{{ bk.processing ? 'Generando…' : 'Generar copia ahora' }}</button>
        <label class="flex items-center gap-2 text-sm mt-3"><input type="checkbox" :checked="backupAuto" class="accent-carmin" @change="router.post('/configuracion/datos/backup-auto', {}, { preserveScroll: true })" /> Copia automática todas las noches (se guardan las últimas 10)</label>
      </div>
      <div class="card">
        <h2 class="font-bold mb-1">Llevarte todos tus datos</h2>
        <p class="text-sm text-marca-muted mb-3">Son tuyos. La misma copia sirve para migrar a otro sistema: adentro hay un CSV por tabla ({{ tablas.length }} tablas) con nombres claros.</p>
        <button class="btn-secondary w-full" :disabled="bk.processing" @click="bk.post('/configuracion/datos/backup', { preserveScroll: true })">Exportar todo (zip)</button>
      </div>
      <div class="card border-carmin">
        <h2 class="font-bold mb-1">Restaurar</h2>
        <p class="text-sm text-marca-muted mb-3">Vuelve la empresa al estado de una copia. Antes se guarda una copia del estado actual, así siempre podés volver.</p>
        <button class="btn-secondary w-full" @click="restAbierto = true">Restaurar desde una copia…</button>
      </div>
    </div>

    <div class="card p-0 overflow-hidden">
      <div class="px-4 py-3 border-b border-marca-borde font-bold">Copias guardadas</div>
      <table class="table text-sm">
        <thead><tr><th>Fecha</th><th>Origen</th><th class="text-right">Tamaño</th><th class="text-right">Tablas</th><th class="text-right">Filas</th><th>Usuario</th><th></th></tr></thead>
        <tbody>
          <tr v-for="b in backups" :key="b.id">
            <td class="tabular-nums">{{ b.fecha }}</td><td><span class="badge" :class="{ 'bg-lavanda-light text-violeta': b.origen === 'auto', 'bg-emerald-50 text-emerald-700': b.origen === 'manual', 'bg-amber-50 text-amber-700': b.origen === 'pre_restauracion' }">{{ { auto: 'Automática', manual: 'Manual', pre_restauracion: 'Antes de restaurar' }[b.origen] }}</span></td><td class="text-right tabular-nums">{{ b.mb }} MB</td><td class="text-right tabular-nums">{{ b.tablas }}</td><td class="text-right tabular-nums">{{ b.filas.toLocaleString('es-AR') }}</td><td>{{ b.usuario ?? 'Sistema' }}</td>
            <td class="text-right whitespace-nowrap"><a :href="`/configuracion/datos/backups/${b.id}`" class="btn-ghost !px-2 text-xs">Descargar</a><button class="btn-ghost !px-2 text-xs" @click="rest.backup_id = b.id; restAbierto = true">Restaurar</button><button class="btn-ghost !px-2 text-xs text-carmin" @click="router.delete(`/configuracion/datos/backups/${b.id}`, { preserveScroll: true })">Borrar</button></td>
          </tr>
          <tr v-if="!backups.length"><td colspan="7" class="text-center text-marca-muted py-6">Todavía no hay copias. Generá la primera ahora.</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="restAbierto" titulo="Restaurar datos" @cerrar="restAbierto = false">
      <div class="space-y-3 text-sm">
        <p class="p-3 rounded-xl bg-red-50 text-carmin">Esto reemplaza <b>todos</b> los datos actuales de la empresa por los de la copia. Lo que cargaste después de esa copia se pierde (queda en la copia "antes de restaurar" por las dudas).</p>
        <div><label class="label">Copia guardada</label><select v-model="rest.backup_id" class="input"><option :value="null">— o subí un archivo —</option><option v-for="b in backups" :key="b.id" :value="b.id">{{ b.fecha }} · {{ b.mb }} MB</option></select></div>
        <div><label class="label">Archivo .zip</label><input type="file" accept=".zip" @change="rest.archivo = $event.target.files[0]" class="input !py-1.5 text-xs" /></div>
        <div><label class="label">Escribí RESTAURAR para confirmar</label><input v-model="rest.confirmacion" class="input" /></div>
        <p v-if="rest.errors.archivo" class="text-carmin text-xs">{{ rest.errors.archivo }}</p>
      </div>
      <template #pie><button class="btn-secondary" @click="restAbierto = false">Cancelar</button><button class="btn-primary" :disabled="rest.processing || rest.confirmacion !== 'RESTAURAR' || (!rest.backup_id && !rest.archivo)" @click="rest.post('/configuracion/datos/restaurar', { forceFormData: true, preserveScroll: true, onSuccess: () => { restAbierto = false; rest.reset() } })">{{ rest.processing ? 'Restaurando…' : 'Restaurar' }}</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
import Modal from '@/Components/Modal.vue'
defineProps({ backups: Array, backupAuto: Boolean, tablas: Array })
const bk = useForm({})
const restAbierto = ref(false)
const rest = useForm({ backup_id: null, archivo: null, confirmacion: '' })
</script>
