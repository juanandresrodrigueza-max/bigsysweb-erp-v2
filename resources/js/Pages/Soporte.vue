<template>
  <AppLayout titulo="Soporte">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Soporte</h1><p class="page-subtitle">Contanos qué pasa y te respondemos acá mismo. Urgente: WhatsApp {{ contacto.whatsapp }} · {{ contacto.email }}</p></div>
      <button class="btn-primary" @click="nuevoAbierto = true">Nuevo ticket</button>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card p-0 overflow-hidden">
        <div class="px-4 py-3 border-b border-marca-borde font-bold">Mis tickets</div>
        <div class="divide-y divide-marca-borde/60 max-h-[70vh] overflow-y-auto">
          <button v-for="t in tickets" :key="t.id" class="w-full text-left px-4 py-3 hover:bg-marca-fondo" :class="sel?.id === t.id ? 'bg-lavanda-light/50' : ''" @click="sel = t">
            <div class="flex items-center justify-between gap-2"><span class="text-xs text-marca-muted tabular-nums">{{ t.numero }}</span><span class="badge" :class="{ 'bg-amber-50 text-amber-700': t.estado === 'abierto', 'bg-emerald-50 text-emerald-700': t.estado === 'respondido', 'bg-gris-light text-marca-muted': t.estado === 'cerrado' }">{{ { abierto: 'Esperando respuesta', respondido: 'Respondido', cerrado: 'Cerrado' }[t.estado] }}</span></div>
            <p class="font-medium text-sm mt-0.5 truncate">{{ t.asunto }}</p>
            <p class="text-xs text-marca-muted">{{ t.categoria }} · {{ t.ultimo }}</p>
          </button>
          <p v-if="!tickets.length" class="text-sm text-marca-muted p-6 text-center">Sin tickets. Si algo no anda o querés sugerir algo, abrí uno.</p>
        </div>
      </div>
      <div class="card lg:col-span-2" v-if="sel">
        <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
          <div><p class="text-xs text-marca-muted">{{ sel.numero }} · {{ sel.categoria }} · prioridad {{ sel.prioridad }} · abierto por {{ sel.usuario }} el {{ sel.creado }}</p><h2 class="font-bold text-lg">{{ sel.asunto }}</h2></div>
          <button v-if="sel.estado !== 'cerrado'" class="btn-ghost text-xs" @click="router.post(`/soporte/${sel.id}/cerrar`, {}, { preserveScroll: true })">Marcar resuelto</button>
        </div>
        <div class="space-y-3 max-h-[50vh] overflow-y-auto pr-1">
          <div v-for="(m, i) in sel.mensajes" :key="i" class="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm" :class="m.de === 'soporte' ? 'bg-lavanda-light text-marca-texto' : 'bg-marca-fondo ml-auto'"><p class="text-[11px] font-bold" :class="m.de === 'soporte' ? 'text-violeta' : 'text-marca-muted'">{{ m.de === 'soporte' ? 'Soporte BigSys' : m.usuario }} · {{ m.fecha_f }}</p><p class="whitespace-pre-wrap">{{ m.texto }}</p></div>
        </div>
        <form v-if="sel.estado !== 'cerrado'" @submit.prevent="resp.post(`/soporte/${sel.id}/responder`, { preserveScroll: true, onSuccess: () => resp.reset() })" class="flex gap-2 mt-3"><textarea v-model="resp.mensaje" rows="2" class="input" placeholder="Escribí tu respuesta…"></textarea><button class="btn-primary self-end" :disabled="resp.processing || !resp.mensaje.trim()">Enviar</button></form>
      </div>
      <div v-else class="card lg:col-span-2 flex items-center justify-center text-sm text-marca-muted min-h-48">Elegí un ticket o abrí uno nuevo.</div>
    </div>

    <Modal :abierto="nuevoAbierto" titulo="Nuevo ticket de soporte" @cerrar="nuevoAbierto = false">
      <div class="space-y-3">
        <div><label class="label">Asunto</label><input v-model="nuevo.asunto" class="input" placeholder="Ej. No me deja emitir una factura A" /><p v-if="nuevo.errors.asunto" class="text-carmin text-xs mt-1">{{ nuevo.errors.asunto }}</p></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Tipo</label><select v-model="nuevo.categoria" class="input"><option v-for="(l, k) in categorias" :key="k" :value="k">{{ l }}</option></select></div>
          <div><label class="label">Prioridad</label><select v-model="nuevo.prioridad" class="input"><option v-for="(l, k) in prioridades" :key="k" :value="k">{{ l }}</option></select></div>
        </div>
        <div><label class="label">Contanos qué pasa</label><textarea v-model="nuevo.mensaje" rows="5" class="input" placeholder="Qué querías hacer, qué pasó y, si hay, el mensaje de error."></textarea><p v-if="nuevo.errors.mensaje" class="text-carmin text-xs mt-1">{{ nuevo.errors.mensaje }}</p></div>
      </div>
      <template #pie><button class="btn-secondary" @click="nuevoAbierto = false">Cancelar</button><button class="btn-primary" :disabled="nuevo.processing || !nuevo.asunto || !nuevo.mensaje" @click="nuevo.post('/soporte', { preserveScroll: true, onSuccess: () => { nuevoAbierto = false; nuevo.reset() } })">Enviar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
const props = defineProps({ tickets: Array, categorias: Object, prioridades: Object, contacto: Object, abrirId: Number })
const sel = ref(props.tickets.find(t => t.id === props.abrirId) ?? props.tickets[0] ?? null)
watch(() => props.tickets, ts => { sel.value = ts.find(t => t.id === sel.value?.id) ?? ts[0] ?? null })
const nuevoAbierto = ref(false)
const nuevo = useForm({ asunto: '', categoria: 'consulta', prioridad: 'normal', mensaje: '' })
const resp = useForm({ mensaje: '' })
</script>
