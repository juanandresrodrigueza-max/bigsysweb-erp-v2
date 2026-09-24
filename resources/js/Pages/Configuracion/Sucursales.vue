<template>
  <AppLayout titulo="Sucursales">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-marca-muted">{{ limite.usadas }} de {{ limite.max < 0 ? '∞' : limite.max }} sucursales del plan. La casa central ve el consolidado de todas desde el selector de sucursal.</p>
      <button @click="abrir()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nueva sucursal</button>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="s in items" :key="s.id" class="card">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="font-bold">{{ s.name }} <span v-if="s.short_name" class="text-marca-muted font-normal text-sm">({{ s.short_name }})</span></p>
            <p class="text-sm text-marca-muted">{{ [s.address, s.city, s.province].filter(Boolean).join(', ') || 'Sin dirección' }}</p>
          </div>
          <div class="flex flex-wrap gap-1 justify-end">
            <span v-if="s.is_default" class="badge bg-carmin-light text-carmin">Principal</span>
            <span v-if="!s.is_active" class="badge bg-marca-fondo text-marca-muted">Inactiva</span>
            <span v-if="s.cuit" class="badge bg-violeta-light text-violeta">CUIT propio</span>
          </div>
        </div>
        <div v-if="s.cuit" class="mt-3 text-xs rounded-xl bg-marca-fondo p-3 space-y-0.5">
          <p><b>{{ s.razon_social }}</b> · CUIT {{ s.cuit }} · {{ s.condicion_iva }}</p>
          <p :class="s.cert && s.key ? 'text-emerald-700' : 'text-amber-700'">{{ s.cert && s.key ? 'Certificado ARCA cargado' : 'Falta el certificado ARCA: factura en modo simulado' }} · {{ s.afip_produccion ? 'producción' : 'homologación' }}</p>
          <p :class="s.puntos_venta.length ? '' : 'text-amber-700'">Puntos de venta: {{ s.puntos_venta.length ? s.puntos_venta.map(n => String(n).padStart(4, '0')).join(', ') : 'ninguno (crealo en Puntos de venta y asignalo a esta sucursal)' }}</p>
        </div>
        <p v-else class="mt-3 text-xs text-marca-muted">Factura con el CUIT de la empresa ({{ empresa.cuit ?? 'sin CUIT' }}).</p>
        <div class="flex items-center justify-between mt-4 text-sm">
          <span class="text-marca-muted">{{ s.usuarios }} usuario(s)</span>
          <div class="flex gap-1">
            <button v-if="s.cuit" @click="certDe = s" class="btn-ghost !px-2 text-xs">Certificado ARCA</button>
            <button @click="abrir(s)" class="btn-ghost !px-2 text-xs"><Icono nombre="edit" clase="w-4 h-4" /> Editar</button>
          </div>
        </div>
      </div>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? 'Editar sucursal' : 'Nueva sucursal'" @cerrar="modal = false">
      <form @submit.prevent="guardar" class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2"><label class="label">Nombre</label><input v-model="form.name" class="input" /><p v-if="form.errors.name" class="text-carmin text-xs mt-1">{{ form.errors.name }}</p></div>
        <div><label class="label">Abreviatura</label><input v-model="form.short_name" class="input" maxlength="20" /></div>
        <div><label class="label">Teléfono</label><input v-model="form.phone" class="input" /></div>
        <div class="sm:col-span-2"><label class="label">Dirección</label><input v-model="form.address" class="input" /></div>
        <div><label class="label">Ciudad</label><input v-model="form.city" class="input" /></div>
        <div><label class="label">Provincia</label><input v-model="form.province" class="input" /></div>
        <div class="sm:col-span-2"><label class="label">Email</label><input v-model="form.email" type="email" class="input" /></div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="accent-carmin" /> Activa</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_default" type="checkbox" class="accent-carmin" /> Sucursal principal</label>

        <div class="sm:col-span-2 border-t border-marca-borde pt-3">
          <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" class="accent-carmin" :checked="cuitPropio" @change="cuitPropio = $event.target.checked; if (!cuitPropio) { form.cuit = ''; form.razon_social = ''; form.condicion_iva = ''; form.iibb = ''; form.inicio_actividades = '' }" /> Factura con su propio CUIT</label>
          <p class="text-[11px] text-marca-muted mt-1">Para sucursales que son otra razón social (o el mismo CUIT en otra jurisdicción). Emite con su certificado ARCA y sus puntos de venta; la casa central ve todo consolidado.</p>
        </div>
        <template v-if="cuitPropio">
          <div><label class="label">CUIT</label><input v-model="form.cuit" class="input tabular-nums" placeholder="30-12345678-9" /><p v-if="form.errors.cuit" class="text-carmin text-xs mt-1">{{ form.errors.cuit }}</p></div>
          <div><label class="label">Condición frente al IVA</label><select v-model="form.condicion_iva" class="input"><option value="">Igual que la empresa</option><option v-for="c in condicionesIva" :key="c" :value="c">{{ c }}</option></select></div>
          <div class="sm:col-span-2"><label class="label">Razón social</label><input v-model="form.razon_social" class="input" /><p v-if="form.errors.razon_social" class="text-carmin text-xs mt-1">{{ form.errors.razon_social }}</p></div>
          <div><label class="label">Ingresos Brutos</label><input v-model="form.iibb" class="input" /></div>
          <div><label class="label">Inicio de actividades</label><input v-model="form.inicio_actividades" type="date" class="input" /></div>
          <label class="sm:col-span-2 flex items-center gap-2 text-sm"><input v-model="form.afip_produccion" type="checkbox" class="accent-carmin" /> Certificado de producción (destildado: homologación / pruebas)</label>
        </template>
      </form>
      <template #pie>
        <button class="btn-secondary" @click="modal = false">Cancelar</button>
        <button class="btn-primary" @click="guardar" :disabled="form.processing">Guardar</button>
      </template>
    </Modal>

    <Modal :abierto="!!certDe" :titulo="`Certificado ARCA de ${certDe?.name ?? ''}`" @cerrar="certDe = null">
      <p class="text-sm text-marca-muted mb-3">Subí el certificado (.crt) y la clave privada (.key) generados en ARCA para el CUIT <b>{{ certDe?.cuit }}</b>. Se guardan cifrados. Es el mismo trámite que para la empresa: Administrador de certificados digitales, alias por sucursal.</p>
      <form @submit.prevent="subirCert" class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Certificado (.crt / .pem)</label><input type="file" class="input" @change="cert.cert = $event.target.files[0]" /></div>
        <div><label class="label">Clave privada (.key)</label><input type="file" class="input" @change="cert.key = $event.target.files[0]" /></div>
      </form>
      <p v-if="cert.errors.cert" class="text-carmin text-xs mt-2">{{ cert.errors.cert }}</p>
      <template #pie><button class="btn-secondary" @click="certDe = null">Cancelar</button><button class="btn-primary" :disabled="cert.processing || !cert.cert || !cert.key" @click="subirCert">Cargar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'

defineProps({ items: Array, limite: Object, empresa: { type: Object, default: () => ({}) }, condicionesIva: { type: Array, default: () => [] } })
const modal = ref(false)
const cuitPropio = ref(false)
const vacio = { id: null, name: '', short_name: '', address: '', city: '', province: '', phone: '', email: '', is_active: true, is_default: false, cuit: '', razon_social: '', condicion_iva: '', iibb: '', inicio_actividades: '', afip_produccion: false }
const form = useForm({ ...vacio })

function abrir(s) { form.clearErrors(); Object.assign(form, s ? { ...vacio, ...s, cuit: s.cuit ?? '', razon_social: s.razon_social ?? '', condicion_iva: s.condicion_iva ?? '', iibb: s.iibb ?? '', inicio_actividades: s.inicio_actividades ?? '' } : { ...vacio }); cuitPropio.value = !!s?.cuit; modal.value = true }
function guardar() { form.post(`/configuracion/sucursales${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal.value = false) }) }

const certDe = ref(null)
const cert = useForm({ cert: null, key: null })
function subirCert() { cert.post(`/configuracion/sucursales/${certDe.value.id}/certificados`, { preserveScroll: true, forceFormData: true, onSuccess: () => { certDe.value = null; cert.reset() } }) }
</script>
