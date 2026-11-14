<template>
  <AppLayout titulo="Mensajes a clientes">
    <!-- Fase 96: campañas: un mensaje a muchos clientes por mail o WhatsApp, con variables y con lo que quedó para mandar a mano. -->
    <PageHeader titulo="Mensajes a clientes" :principal="puede('clientes','crear') ? { label: 'Nuevo mensaje', click: () => (abierto = true) } : null" :volver="{ href: '/clientes', label: 'Clientes' }">
      <template #subtitulo>Una promoción, un aviso de cierre, un cambio de lista: a todos los clientes o a un grupo, por mail o por WhatsApp. Cada envío queda en la ficha del cliente.</template>
    </PageHeader>

    <div v-if="!campanas.length" class="card text-center py-12">
      <p class="font-bold text-lg">Todavía no mandaste ningún mensaje masivo</p>
      <p class="text-sm text-marca-muted mt-1 max-w-lg mx-auto">Elegís a quién (todos, los que deben, un tipo de cliente), escribís el texto con {{ '{' }}cliente{{ '}' }} y {{ '{' }}saldo{{ '}' }} si querés, y sale por mail o WhatsApp. {{ whatsappApi ? 'Tu WhatsApp está conectado por API: salen solos.' : 'Sin la API de WhatsApp, te quedan los links listos para mandar uno por uno desde tu teléfono.' }}</p>
      <button v-if="puede('clientes','crear')" class="btn-primary mt-4" @click="abierto = true">Escribir el primero</button>
    </div>

    <div v-for="c in campanas" :key="c.id" class="card mb-3" :data-campana="c.id">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0"><p class="font-bold">{{ c.asunto || c.texto.slice(0, 70) }}</p><p class="text-xs text-marca-muted">{{ c.fecha }} · {{ c.canal }} · {{ c.usuario }}</p></div>
        <div class="flex flex-wrap gap-1 text-xs">
          <span class="badge bg-gris-light text-marca-muted">{{ c.destinatarios }} destinatarios</span>
          <span class="badge bg-emerald-50 text-emerald-700">{{ c.enviados }} enviados</span>
          <span v-if="c.pendientes" class="badge bg-amber-50 text-amber-700">{{ c.pendientes }} para mandar a mano</span>
          <span v-if="c.errores" class="badge bg-carmin-light text-carmin">{{ c.errores }} con error</span>
          <span v-if="!c.terminada" class="badge bg-lavanda-light text-violeta">en curso</span>
        </div>
      </div>
      <p class="text-sm mt-2 whitespace-pre-line text-marca-texto/80">{{ c.texto }}</p>
      <details v-if="c.para_mandar.length" class="mt-3" open>
        <summary class="text-sm font-semibold cursor-pointer">Para mandar desde tu WhatsApp ({{ c.para_mandar.length }})</summary>
        <div class="flex flex-wrap gap-2 mt-2">
          <a v-for="e in c.para_mandar" :key="e.id" :href="e.link" target="_blank" class="btn-secondary !py-1 text-xs" @click="router.post(`/clientes/campanas/envios/${e.id}/enviado`, {}, { preserveScroll: true })">💬 {{ e.cliente }}</a>
        </div>
        <p class="text-[11px] text-marca-muted mt-1">Cada link abre WhatsApp con el texto cargado; al tocarlo se marca como enviado.</p>
      </details>
      <details v-if="c.fallidos.length" class="mt-2">
        <summary class="text-sm font-semibold text-carmin cursor-pointer">Con error ({{ c.fallidos.length }})</summary>
        <p v-for="(f, i) in c.fallidos" :key="i" class="text-xs text-marca-muted">{{ f.cliente }}: {{ f.error }}</p>
      </details>
    </div>

    <Modal :abierto="abierto" titulo="Nuevo mensaje a clientes" ancho="max-w-2xl" @cerrar="abierto = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Por dónde</label><div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1 text-xs font-semibold"><button v-for="(l, k) in canales" :key="k" type="button" class="flex-1 px-3 py-1.5 rounded-full" :class="form.canal === k ? 'bg-carmin text-white' : 'text-marca-muted'" @click="form.canal = k" :data-canal="k">{{ l }}</button></div>
          <p class="text-[11px] text-marca-muted mt-1">{{ form.canal === 'whatsapp' ? (whatsappApi ? 'WhatsApp por API: salen solos.' : 'Sin API: te quedan los links para mandar uno por uno.') : 'Sale desde el correo configurado de la empresa.' }}</p></div>
        <div><label class="label">A quién</label>
          <select v-model="form.estado" class="input" @change="recontar"><option value="activos">Todos los clientes activos</option><option value="deudores">Los que tienen saldo</option><option value="inactivos">Inactivos</option></select>
          <select v-model="form.tipo_cliente_id" class="input mt-1" @change="recontar"><option value="">Todos los tipos</option><option v-for="t in tipos" :key="t.id" :value="t.id">{{ t.nombre }}</option></select>
          <p class="text-xs mt-1" :class="cuenta[form.canal] ? 'text-emerald-700 font-semibold' : 'text-carmin'" data-e2e="conteo">{{ cuenta[form.canal] }} cliente{{ cuenta[form.canal] === 1 ? '' : 's' }} con {{ form.canal === 'mail' ? 'email' : 'teléfono' }}<span v-if="cuenta[form.canal] >= maximo"> (máximo {{ maximo }} por envío)</span></p></div>
        <div v-if="form.canal === 'mail'" class="sm:col-span-2"><label class="label">Asunto</label><input v-model="form.asunto" class="input" placeholder="Nueva lista de precios desde el lunes" /><p v-if="form.errors.asunto" class="text-carmin text-xs mt-1">{{ form.errors.asunto }}</p></div>
        <div class="sm:col-span-2"><label class="label">Mensaje</label><textarea v-model="form.texto" rows="6" class="input" placeholder="Hola {cliente}, te contamos que…"></textarea>
          <p class="text-[11px] text-marca-muted mt-1">Variables: <button v-for="(d, v) in variables" :key="v" type="button" class="underline mr-2" :title="d" @click="form.texto += (form.texto && !form.texto.endsWith(' ') ? ' ' : '') + v">{{ v }}</button></p>
          <p v-if="form.errors.texto" class="text-carmin text-xs mt-1">{{ form.errors.texto }}</p></div>
      </div>
      <template #pie><button class="btn-secondary" @click="abierto = false">Cancelar</button><button class="btn-primary" :disabled="form.processing || !form.texto || !cuenta[form.canal]" @click="form.post('/clientes/campanas', { onSuccess: () => (abierto = false) })" data-e2e="enviar-campana">Mandar a {{ cuenta[form.canal] }}</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import Modal from '@/Components/Modal.vue'
import { usePermisos } from '@/util/permisos'
const { puede } = usePermisos()
const props = defineProps({ campanas: Array, variables: Object, canales: Object, whatsappApi: Boolean, tipos: Array, conteo: Object, maximo: Number })
const abierto = ref(false)
const cuenta = reactive({ ...props.conteo })
const form = useForm({ canal: 'whatsapp', asunto: '', texto: '', estado: 'activos', tipo_cliente_id: '' })
async function recontar() { try { const { data } = await window.axios.get('/clientes/campanas/contar', { params: { estado: form.estado, tipo_cliente_id: form.tipo_cliente_id || undefined } }); Object.assign(cuenta, data) } catch {} }
</script>
