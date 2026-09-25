<template>
  <Modal :abierto="abierto" :titulo="titulo" @cerrar="$emit('cerrar')">
    <div v-if="cargando" class="text-sm text-marca-muted py-6 text-center">Preparando…</div>
    <template v-else>
      <div class="flex gap-1 p-1 rounded-xl bg-marca-fondo mb-3">
        <button type="button" v-for="c in [['mail','Mail'],['whatsapp','WhatsApp']]" :key="c[0]" @click="form.canal = c[0]; form.destino = c[0] === 'mail' ? (b.email ?? '') : (b.telefono ?? '')" class="flex-1 py-1.5 rounded-lg text-sm font-semibold" :class="form.canal === c[0] ? 'bg-white shadow-card' : 'text-marca-muted'">{{ c[1] }}</button>
      </div>
      <label class="label">{{ form.canal === 'mail' ? 'Email' : 'Teléfono (WhatsApp)' }}</label>
      <div v-if="b.personas?.length" class="flex flex-wrap gap-1 mb-1.5" data-e2e="personas-envio">
        <button v-for="p in b.personas.filter(x => form.canal === 'mail' ? x.email : x.telefono)" :key="p.nombre" type="button" class="badge" :class="form.destino === (form.canal === 'mail' ? p.email : p.telefono) ? 'bg-carmin text-white' : p.sugerido ? 'bg-violeta/10 text-violeta' : 'bg-gris-light text-marca-muted'" @click="form.destino = form.canal === 'mail' ? p.email : p.telefono">{{ p.nombre }}<span v-if="p.cargo"> · {{ p.cargo }}</span></button>
      </div>
      <input v-model="form.destino" class="input" :placeholder="form.canal === 'mail' ? 'cliente@ejemplo.com' : '351 555 0000'" />
      <p v-if="form.errors.destino" class="text-carmin text-xs mt-1">{{ form.errors.destino }}</p>
      <label class="label mt-3">Mensaje</label>
      <textarea v-model="form.mensaje" rows="5" class="input text-sm"></textarea>
      <label v-if="form.canal === 'mail' && conAdjunto" class="flex items-center gap-2 text-sm mt-2"><input v-model="form.adjuntar" type="checkbox" class="accent-carmin" /> Adjuntar PDF</label>
      <p class="text-[11px] text-marca-muted mt-2">
        <template v-if="form.canal === 'mail'">{{ b.mail_configurado ? 'Se envía desde el correo de la empresa.' : 'El correo todavía no está configurado en el servidor: el envío queda registrado pero no sale (modo prueba).' }}</template>
        <template v-else>{{ b.whatsapp_api ? 'Se manda por la API oficial de WhatsApp.' : 'Sin API de WhatsApp: se abre WhatsApp Web con el mensaje listo para mandar.' }}</template>
      </p>
      <div v-if="b.historial?.length" class="mt-3 text-xs">
        <p class="label">Envíos anteriores</p>
        <p v-for="h in b.historial" :key="h.id" class="text-marca-muted">{{ h.fecha }} · {{ h.canal }} · {{ h.destino }} · <span :class="h.estado === 'enviado' ? 'text-emerald-700' : h.estado === 'error' ? 'text-carmin' : 'text-amber-700'">{{ h.estado }}</span><a v-if="h.link && h.estado === 'pendiente'" :href="h.link" target="_blank" class="text-violeta font-semibold ml-1">abrir</a></p>
      </div>
    </template>
    <template #pie>
      <button class="btn-secondary" @click="$emit('cerrar')">Cancelar</button>
      <button class="btn-primary" :disabled="form.processing || !form.destino" @click="enviar">{{ form.processing ? 'Enviando…' : (form.canal === 'mail' ? 'Enviar mail' : 'Enviar WhatsApp') }}</button>
    </template>
  </Modal>
</template>

<script setup>
import { ref, watch, reactive } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'

const props = defineProps({ abierto: Boolean, modelo: String, id: Number, titulo: { type: String, default: 'Enviar' }, conAdjunto: { type: Boolean, default: true } })
const emit = defineEmits(['cerrar'])
const cargando = ref(false)
const b = reactive({ email: '', telefono: '', asunto: '', cuerpo: '', historial: [], whatsapp_api: false, mail_configurado: false })
const form = useForm({ modelo: props.modelo, id: props.id, canal: 'mail', destino: '', mensaje: '', adjuntar: true })
watch(() => props.abierto, async v => {
  if (!v) return
  cargando.value = true; form.clearErrors()
  try { const { data } = await window.axios.get('/envios/borrador', { params: { modelo: props.modelo, id: props.id } }); Object.assign(b, data); form.canal = data.email ? 'mail' : 'whatsapp'; form.destino = form.canal === 'mail' ? (data.email ?? '') : (data.telefono ?? ''); form.mensaje = data.cuerpo; form.modelo = props.modelo; form.id = props.id }
  finally { cargando.value = false }
})
const page = usePage()
function enviar() {
  form.post('/envios', { preserveScroll: true, onSuccess: () => { const abrir = page.props.flash?.abrir; if (abrir) { window.open(abrir, '_blank'); const id = page.props.flash?.envio_id; if (id) window.axios.post(`/envios/${id}/marcar`).catch(() => {}) } emit('cerrar') } })
}
</script>
