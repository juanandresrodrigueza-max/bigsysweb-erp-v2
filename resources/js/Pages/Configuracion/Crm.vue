<template>
  <AppLayout titulo="CRM">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h2 class="font-bold text-lg">Integración con el CRM de BigSys</h2><p class="text-sm text-marca-muted">Un botón para pasar del ERP al CRM (y al revés) ya logueado, y las dos bases hablándose por API. Nadie escribe en la base del otro.</p></div>
      <span class="badge text-sm px-3 py-1" :class="listo ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ listo ? 'Activa' : 'Inactiva' }}</span>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <form @submit.prevent="guardar" class="card lg:col-span-2 space-y-4">
        <div class="grid sm:grid-cols-2 gap-3">
          <div class="sm:col-span-2"><label class="label">URL del CRM</label><input v-model="form.url" class="input" placeholder="https://crm.bigsysweb.com" /><p v-if="form.errors.url" class="text-carmin text-xs mt-1">{{ form.errors.url }}</p></div>
          <div class="sm:col-span-2">
            <label class="label">Secreto compartido del handoff</label>
            <div class="flex gap-2"><input v-model="form.secreto" class="input font-mono text-xs" :placeholder="config.secreto_set ? '•••••••• (cargado; escribí otro para reemplazar)' : 'Mínimo 16 caracteres, el mismo en el CRM'" /><button type="button" class="btn-secondary whitespace-nowrap" @click="form.generar_secreto = true; guardar()">Generar</button></div>
            <p v-if="secretoNuevo" class="mt-2 text-xs bg-violeta-light text-violeta rounded-xl p-2 break-all">Secreto nuevo (se muestra una sola vez): <b class="font-mono">{{ secretoNuevo }}</b><button type="button" class="ml-2 underline" @click="copiar(secretoNuevo)">copiar</button></p>
            <p v-if="form.errors.secreto" class="text-carmin text-xs mt-1">{{ form.errors.secreto }}</p>
            <p class="text-[10px] text-marca-muted mt-1">Con este secreto el ERP firma el token que el CRM acepta, y viceversa. Cargalo en el CRM en Configuración → Integración ERP.</p>
          </div>
          <div><label class="label">Clave de API del CRM</label><input v-model="form.api_key" class="input font-mono text-xs" :placeholder="config.api_key_set ? '•••••••• (cargada)' : 'La creás en el CRM → API keys'" /><p class="text-[10px] text-marca-muted mt-1">Scopes: contacts, products, quotes, tasks, appointments (lectura y escritura).</p></div>
          <div><label class="label">Secreto del webhook del CRM</label><input v-model="form.webhook_secreto" class="input font-mono text-xs" :placeholder="config.webhook_secreto_set ? '•••••••• (cargado)' : 'El que muestra el CRM al crear el webhook'" /><p class="text-[10px] text-marca-muted mt-1">URL a cargar en el CRM: <code class="font-mono">{{ urlWebhook }}</code></p></div>
          <div><label class="label">Lista de precios que ve el CRM</label><select v-model.number="form.lista_precios" class="input"><option v-for="n in 6" :key="n" :value="n">Lista {{ n }}</option></select></div>
          <div><label class="label">Presupuesto aceptado en el CRM</label><select v-model="form.presupuesto_como" class="input"><option value="presupuesto">Crea un presupuesto en el ERP (para revisar y facturar)</option><option value="factura">Crea la factura en borrador</option></select></div>
        </div>
        <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="form.activo" type="checkbox" class="accent-carmin" /> Integración activa (muestra el botón CRM en el menú y acepta entradas desde el CRM)</label>
        <p v-if="form.errors.activo" class="text-carmin text-xs">{{ form.errors.activo }}</p>
        <div class="flex flex-wrap gap-2 justify-end">
          <button type="button" class="btn-secondary" @click="router.post('/configuracion/crm/probar', {}, { preserveScroll: true })">Probar conexión</button>
          <button v-if="listo" type="button" class="btn-secondary" title="Manda todos los clientes y artículos al CRM (carga inicial). Después cada cambio va solo." @click="router.post('/configuracion/crm/sincronizar', {}, { preserveScroll: true })">Sincronizar clientes y artículos</button>
          <button class="btn-primary" :disabled="form.processing">Guardar</button>
        </div>
        <div v-if="prueba" class="text-sm rounded-xl p-3" :class="prueba.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-carmin-light text-carmin'">{{ prueba.detalle }}</div>
      </form>

      <div class="card text-sm space-y-3">
        <h3 class="font-bold">Cómo se conectan</h3>
        <p><b>1.</b> El CUIT une las dos empresas: acá <b class="tabular-nums">{{ cuit ?? 'sin CUIT (cargalo en Empresa)' }}</b>; en el CRM cargá el mismo en Configuración → Empresa.</p>
        <p><b>2.</b> Generá el secreto acá y pegalo en el CRM. Pegá acá la clave de API que crea el CRM.</p>
        <p><b>3.</b> En el CRM (Integraciones → ERP) cargá esta URL del ERP: <code class="font-mono text-xs break-all">{{ urlEntrada }}</code></p>
        <p><b>4.</b> Tocá <b>Sincronizar clientes y artículos</b> una vez: manda todo al CRM. Después, cada cliente o artículo que se crea o cambia en el ERP viaja solo, y cada contacto que nace en el CRM (un WhatsApp nuevo) aparece acá como cliente. Lo fiscal (CUIT, condición IVA, domicilio, listas, límite de crédito) lo administra el ERP; los artículos y precios quedan en solo lectura en el CRM.</p>
        <p><b>5.</b> Activá y probá. Aparece <b>CRM</b> en el menú; al tocarlo entrás al CRM sin volver a loguearte. Los usuarios se dan de alta en el ERP; el CRM los espeja con el rol equivalente.</p>
        <p class="text-xs text-marca-muted">Roles: dueño y administrador → admin; encargado y contador → supervisor; vendedor y cajero → operador (solo lo suyo); depósito, producción y solo lectura → consulta.</p>
        <a v-if="listo" href="/integraciones/crm/ir" class="btn-primary inline-flex">Ir al CRM ahora ↗</a>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
const props = defineProps({ config: Object, cuit: String, urlEntrada: String, urlWebhook: String, listo: Boolean, prueba: Object })
const page = usePage()
const secretoNuevo = computed(() => page.props.flash?.crm_secreto_nuevo)
const form = useForm({ activo: props.config.activo, url: props.config.url, secreto: '', api_key: '', webhook_secreto: '', lista_precios: props.config.lista_precios, presupuesto_como: props.config.presupuesto_como, generar_secreto: false })
function guardar() { form.post('/configuracion/crm', { preserveScroll: true, onSuccess: () => { form.secreto = ''; form.api_key = ''; form.webhook_secreto = ''; form.generar_secreto = false } }) }
function copiar(t) { navigator.clipboard?.writeText(t) }
</script>
