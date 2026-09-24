<template>
  <AppLayout titulo="Puntos de venta y AFIP">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="grid lg:grid-cols-2 gap-4">
      <div class="card">
        <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Puntos de venta</h2><button @click="abrir()" class="btn-primary !py-1.5 text-xs"><Icono nombre="plus" clase="w-3.5 h-3.5" /> Nuevo</button></div>
        <p class="text-sm text-marca-muted mb-3">Cada punto de venta numera sus comprobantes. Tienen que estar dados de alta en AFIP con el mismo número.</p>
        <table class="table">
          <thead><tr><th>N°</th><th>Sucursal</th><th>Modo</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <tr v-for="p in puntos" :key="p.id">
              <td class="font-extrabold tabular-nums">{{ String(p.numero).padStart(4, '0') }}</td><td>{{ p.sucursal ?? 'General' }}</td><td class="capitalize text-marca-muted">{{ p.modo }}</td>
              <td><span class="badge" :class="p.activo ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ p.activo ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="text-right"><button @click="abrir(p)" class="btn-ghost !px-2 text-xs"><Icono nombre="edit" clase="w-4 h-4" /></button></td>
            </tr>
            <tr v-if="!puntos.length"><td colspan="5" class="text-center text-marca-muted py-6">Sin puntos de venta. Creá el 0001 para empezar.</td></tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h2 class="font-bold mb-1">Factura electrónica AFIP</h2>
        <div class="flex items-center gap-2 mb-3">
          <span class="w-2.5 h-2.5 rounded-full" :class="afip.configurado ? 'bg-emerald-500' : 'bg-amber-500'"></span>
          <span class="text-sm">{{ afip.configurado ? `Configurado · CUIT ${afip.cuit} · ${afip.produccion ? 'Producción' : 'Homologación'}` : 'Sin certificado: las facturas se emiten simuladas (sin CAE).' }}</span>
        </div>
        <ol class="text-sm text-marca-muted space-y-1 mb-3 list-decimal pl-5">
          <li>Generá el certificado en ARCA (Administrador de Relaciones → Facturación Electrónica / WSFE) y autorizalo para este CUIT.</li>
          <li>Subí acá el certificado (.crt) y la clave privada (.key). Quedan cifrados.</li>
          <li>Verificá el CUIT y el modo (homologación para probar, producción para facturar de verdad) en la pestaña Empresa.</li>
          <li>Dale de alta el punto de venta en ARCA como "Web Services" con el mismo número que acá.</li>
          <li>Probá la conexión y emití una factura de prueba en homologación antes de pasar a producción.</li>
        </ol>
        <div v-if="afip.configurado" class="flex flex-wrap items-center gap-2 mb-3">
          <button class="btn-violeta !py-1 text-xs" :disabled="probando" @click="probar">{{ probando ? 'Probando…' : 'Probar conexión con ARCA' }}</button>
          <span v-if="afip.pendientes" class="badge bg-carmin-light text-carmin">{{ afip.pendientes }} comprobante(s) pendiente(s) de CAE</span>
        </div>
        <div v-if="prueba" class="rounded-xl border p-3 mb-3 text-sm" :class="prueba.ok ? 'border-emerald-200 bg-emerald-50' : 'border-carmin/30 bg-carmin-light'">
          <p class="font-bold mb-1">Prueba en {{ prueba.modo }}: {{ prueba.ok ? 'todo OK' : 'con problemas' }}</p>
          <div v-for="p in prueba.pasos" :key="p.nombre" class="flex items-start gap-2 py-0.5"><span class="mt-1 w-2 h-2 rounded-full shrink-0" :class="p.ok ? 'bg-emerald-500' : 'bg-carmin'"></span><span><b>{{ p.nombre }}</b> · {{ p.detalle }}</span></div>
        </div>
        <form @submit.prevent="cert.post('/configuracion/afip/certificados', { forceFormData: true, preserveScroll: true })" class="grid sm:grid-cols-2 gap-3">
          <div><label class="label">Certificado (.crt / .pem)</label><input type="file" @change="cert.cert = $event.target.files[0]" class="input !py-1.5 text-xs" /><p v-if="cert.errors.cert" class="text-carmin text-xs mt-1">{{ cert.errors.cert }}</p></div>
          <div><label class="label">Clave privada (.key)</label><input type="file" @change="cert.key = $event.target.files[0]" class="input !py-1.5 text-xs" /><p v-if="cert.errors.key" class="text-carmin text-xs mt-1">{{ cert.errors.key }}</p></div>
          <div class="sm:col-span-2 flex justify-end"><button class="btn-primary" :disabled="cert.processing || !cert.cert || !cert.key">Cargar certificados</button></div>
        </form>
      </div>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? 'Editar punto de venta' : 'Nuevo punto de venta'" @cerrar="modal = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div><label class="label">Número</label><input v-model.number="form.numero" type="number" min="1" max="9999" class="input" /><p v-if="form.errors.numero" class="text-carmin text-xs mt-1">{{ form.errors.numero }}</p></div>
        <div><label class="label">Sucursal</label><select v-model="form.business_location_id" class="input"><option :value="null">General</option><option v-for="s in sucursales" :key="s.id" :value="s.id">{{ s.name }}{{ s.cuit ? ' · CUIT propio ' + s.cuit : '' }}</option></select></div>
        <div><label class="label">Modo</label><select v-model="form.modo" class="input"><option value="electronico">Electrónico (AFIP)</option><option value="manual">Manual / talonario</option></select></div>
        <label class="flex items-center gap-2 text-sm mt-6"><input v-model="form.activo" type="checkbox" class="accent-carmin" /> Activo</label>
      </div>
      <template #pie>
        <button class="btn-secondary" @click="modal = false">Cancelar</button>
        <button class="btn-primary" :disabled="form.processing" @click="form.post(`/configuracion/puntos-venta${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal = false) })">Guardar</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'

const props = defineProps({ puntos: Array, sucursales: Array, afip: Object, prueba: Object })
const probando = ref(false)
function probar() { probando.value = true; router.post('/configuracion/afip/probar', {}, { preserveScroll: true, onFinish: () => (probando.value = false) }) }
const modal = ref(false)
const form = useForm({ id: null, numero: (Math.max(0, ...props.puntos.map(p => p.numero)) + 1), business_location_id: null, modo: 'electronico', activo: true })
const cert = useForm({ cert: null, key: null })
function abrir(p) { form.clearErrors(); Object.assign(form, p ? { ...p } : { id: null, numero: Math.max(0, ...props.puntos.map(x => x.numero)) + 1, business_location_id: null, modo: 'electronico', activo: true }); modal.value = true }
</script>
