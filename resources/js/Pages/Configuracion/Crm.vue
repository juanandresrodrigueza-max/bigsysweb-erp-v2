<template>
  <AppLayout titulo="CRM">
    <h1 class="page-title mb-4">CRM</h1>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h2 class="font-bold text-lg">Integración con el CRM de BigSys</h2><p class="text-sm text-marca-muted">Un botón para pasar del ERP al CRM (y al revés) ya logueado, y las dos bases hablándose por API. Nadie escribe en la base del otro.</p></div>
      <span class="badge text-sm px-3 py-1" :class="listo ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ listo ? 'Activa' : 'Inactiva' }}</span>
    </div>

    <!-- Fase 75: un solo plan para los dos sistemas -->
    <div class="card mb-4 border-l-4" :class="plan.incluye_crm ? 'border-l-emerald-500' : 'border-l-amber-400'" data-e2e="crm-plan">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 class="font-bold">Tu plan <span v-if="plan.plan">{{ plan.plan }}</span> {{ plan.incluye_crm ? 'incluye el CRM' : 'no incluye el CRM' }}</h3>
          <p class="text-sm mt-1 text-marca-muted">
            <template v-if="plan.incluye_crm">El CRM se habilita con el mismo plan del ERP: una sola suscripción, hasta {{ plan.max_usuarios < 0 ? 'usuarios ilimitados' : plan.max_usuarios + ' usuario(s)' }} en los dos sistemas ({{ plan.usuarios_activos }} activos){{ plan.vence ? ', vence el ' + plan.vence.split('-').reverse().join('/') : '' }}. El CRM lee este estado y se frena solo si la suscripción se corta.</template>
            <template v-else>Para usar el CRM con esta empresa, contratá un plan que lo incluya desde Suscripción. El CRM queda a la espera: lo que configurás acá no se pierde.</template>
          </p>
        </div>
        <Link v-if="!plan.incluye_crm" href="/suscripcion" class="btn-primary !py-1 text-xs">Ver planes</Link>
      </div>
    </div>

    <!-- Fase 68: salud de la conexión -->
    <div v-if="salud.estado !== 'inactiva'" class="card mb-4 border-l-4" :class="{ 'border-l-emerald-500': salud.estado === 'verde', 'border-l-amber-400': salud.estado === 'amarillo', 'border-l-carmin': salud.estado === 'rojo' }" data-e2e="crm-salud">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 class="font-bold flex items-center gap-2"><span class="inline-block w-3 h-3 rounded-full" :class="{ 'bg-emerald-500': salud.estado === 'verde', 'bg-amber-400': salud.estado === 'amarillo', 'bg-carmin': salud.estado === 'rojo' }"></span> Salud de la conexión</h3>
          <p class="text-sm mt-1">{{ salud.texto }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button v-if="salud.cola.fallidos" type="button" class="btn-primary !py-1 text-xs" @click="router.post('/configuracion/crm/reintentar', {}, { preserveScroll: true })" data-e2e="crm-reintentar">Reintentar {{ salud.cola.fallidos }} fallido(s)</button>
          <button type="button" class="btn-secondary !py-1 text-xs" title="Vuelve a mandar al CRM lo de las últimas 48 horas (clientes, artículos, comprobantes, cobros). Del otro lado no se duplica nada." @click="router.post('/configuracion/crm/reconciliar', {}, { preserveScroll: true })" data-e2e="crm-reconciliar">Reconciliar ahora</button>
        </div>
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3 text-sm">
        <div class="rounded-xl bg-marca-fondo p-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Último enviado</p><p v-if="salud.ultimo_enviado" class="font-semibold">{{ salud.ultimo_enviado.evento }}</p><p class="text-xs text-marca-muted">{{ salud.ultimo_enviado ? salud.ultimo_enviado.cuando : 'nada todavía' }}</p></div>
        <div class="rounded-xl bg-marca-fondo p-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Último recibido</p><p v-if="salud.ultimo_recibido" class="font-semibold">{{ salud.ultimo_recibido.evento }}</p><p class="text-xs text-marca-muted">{{ salud.ultimo_recibido ? salud.ultimo_recibido.cuando : 'nada todavía' }}</p></div>
        <div class="rounded-xl bg-marca-fondo p-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En cola</p><p class="text-xl font-extrabold tabular-nums">{{ salud.cola.pendientes }}</p><p class="text-xs" :class="salud.cola.fallidos ? 'text-carmin' : 'text-marca-muted'">{{ salud.cola.fallidos }} fallidos</p></div>
        <div class="rounded-xl bg-marca-fondo p-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Últimas 24 h</p><p class="text-xl font-extrabold tabular-nums">{{ salud.ok_24h }} <span class="text-sm font-normal text-marca-muted">OK</span></p><p class="text-xs" :class="salud.errores_24h ? 'text-amber-700' : 'text-marca-muted'">{{ salud.errores_24h }} con error</p></div>
      </div>
      <details v-if="salud.ultimos.length" class="mt-3 text-xs"><summary class="cursor-pointer text-violeta">Últimos {{ salud.ultimos.length }} eventos</summary>
        <table class="table text-xs mt-2"><thead><tr><th>Cuándo</th><th>Sentido</th><th>Evento</th><th>Referencia</th><th>Estado</th><th class="text-right">ms</th><th>Detalle</th></tr></thead>
          <tbody><tr v-for="(e, i) in salud.ultimos" :key="i"><td class="whitespace-nowrap">{{ e.cuando }}</td><td>{{ e.direccion === 'salida' ? 'ERP → CRM' : 'CRM → ERP' }}</td><td class="font-mono">{{ e.evento }}</td><td>{{ e.referencia }}</td><td><span class="badge" :class="e.estado === 'ok' ? 'bg-emerald-50 text-emerald-700' : e.estado === 'ignorado' ? 'bg-gris-light text-marca-muted' : 'bg-carmin-light text-carmin'">{{ e.estado }}{{ e.http ? ' · ' + e.http : '' }}</span></td><td class="text-right tabular-nums">{{ e.ms ?? '' }}</td><td class="text-marca-muted">{{ e.detalle }}</td></tr></tbody></table>
      </details>
      <div v-if="salud.crm" class="mt-3 rounded-xl border border-marca-borde p-3 text-sm flex flex-wrap items-center gap-2" data-e2e="crm-salud-crm">
        <span class="inline-block w-2.5 h-2.5 rounded-full" :class="{ 'bg-emerald-500': salud.crm.estado === 'verde', 'bg-amber-400': salud.crm.estado === 'amarillo', 'bg-carmin': salud.crm.estado === 'rojo' }"></span>
        <b>Lo que ve el CRM:</b><span>{{ salud.crm.texto }}</span>
        <span class="text-xs text-marca-muted">· {{ salud.crm.cola.pendientes }} en cola hacia el ERP, {{ salud.crm.cola.fallidos }} caídos, {{ salud.crm.ok_24h }} OK en 24 h</span>
      </div>
      <p class="text-[11px] text-marca-muted mt-2">Se revisa cada 15 minutos: si el CRM deja de responder te llega una alerta, y todos los días a las 6:30 se vuelve a mandar lo de las últimas 48 horas por si algo no llegó.</p>
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
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
const props = defineProps({ config: Object, cuit: String, urlEntrada: String, urlWebhook: String, listo: Boolean, prueba: Object, salud: Object, plan: { type: Object, default: () => ({ incluye_crm: false }) } })
const page = usePage()
const secretoNuevo = computed(() => page.props.flash?.crm_secreto_nuevo)
const form = useForm({ activo: props.config.activo, url: props.config.url, secreto: '', api_key: '', webhook_secreto: '', lista_precios: props.config.lista_precios, presupuesto_como: props.config.presupuesto_como, generar_secreto: false })
function guardar() { form.post('/configuracion/crm', { preserveScroll: true, onSuccess: () => { form.secreto = ''; form.api_key = ''; form.webhook_secreto = ''; form.generar_secreto = false } }) }
function copiar(t) { navigator.clipboard?.writeText(t) }
</script>
