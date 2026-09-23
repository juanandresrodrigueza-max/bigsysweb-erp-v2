<template>
  <AdminLayout titulo="Soporte">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Tickets de soporte</h1><p class="page-subtitle">Lo que las empresas preguntan o reportan. Respondé y la empresa recibe una alerta.</p></div>
      <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1"><Link v-for="e in [['', 'Pendientes'], ['abierto', 'Sin responder'], ['respondido', 'Respondidos'], ['cerrado', 'Cerrados']]" :key="e[0]" :href="`/admin/soporte${e[0] ? '?estado=' + e[0] : ''}`" class="px-3 py-1 rounded-full text-xs font-semibold" :class="(filtros.estado ?? '') === e[0] ? 'bg-carmin text-white' : 'text-marca-muted'">{{ e[1] }}</Link></div>
    </div>
    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Sin responder</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.abiertos ? 'text-carmin' : ''">{{ kpis.abiertos }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Esperando a la empresa</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.respondidos }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Cerrados este mes</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.cerrados_mes }}</p></div>
    </div>
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card p-0 overflow-hidden">
        <div class="divide-y divide-marca-borde/60 max-h-[70vh] overflow-y-auto">
          <button v-for="t in tickets" :key="t.id" class="w-full text-left px-4 py-3 hover:bg-marca-fondo" :class="sel?.id === t.id ? 'bg-lavanda-light/50' : ''" @click="sel = t">
            <div class="flex items-center justify-between gap-2"><span class="text-xs text-marca-muted tabular-nums">{{ t.numero }} · {{ t.empresa }}</span><span class="badge" :class="{ 'bg-red-50 text-carmin': t.prioridad === 'alta', 'bg-gris-light text-marca-muted': t.prioridad !== 'alta' }">{{ t.prioridad }}</span></div>
            <p class="font-medium text-sm mt-0.5 truncate">{{ t.asunto }}</p>
            <p class="text-xs text-marca-muted">{{ t.categoria }} · {{ t.usuario }} · {{ t.ultimo }} · <span :class="t.estado === 'abierto' ? 'text-carmin font-semibold' : ''">{{ t.estado }}</span></p>
          </button>
          <p v-if="!tickets.length" class="text-sm text-marca-muted p-6 text-center">Nada pendiente.</p>
        </div>
      </div>
      <div class="card lg:col-span-2" v-if="sel">
        <p class="text-xs text-marca-muted">{{ sel.numero }} · <Link :href="`/admin/empresas/${sel.business_id}`" class="underline">{{ sel.empresa }}</Link> · {{ sel.categoria }} · {{ sel.usuario }} · {{ sel.creado }}</p>
        <h2 class="font-bold text-lg mb-3">{{ sel.asunto }}</h2>
        <div class="space-y-3 max-h-[45vh] overflow-y-auto pr-1">
          <div v-for="(m, i) in sel.mensajes" :key="i" class="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm" :class="m.de === 'soporte' ? 'bg-lavanda-light ml-auto' : 'bg-marca-fondo'"><p class="text-[11px] font-bold text-marca-muted">{{ m.de === 'soporte' ? 'Soporte · ' + m.usuario : m.usuario }} · {{ m.fecha_f }}</p><p class="whitespace-pre-wrap">{{ m.texto }}</p></div>
        </div>
        <form @submit.prevent="enviar(false)" class="mt-3 space-y-2">
          <textarea v-model="resp.mensaje" rows="3" class="input" placeholder="Respuesta para la empresa…"></textarea>
          <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" :disabled="resp.processing || !resp.mensaje.trim()" @click="enviar(true)">Responder y cerrar</button><button class="btn-primary" :disabled="resp.processing || !resp.mensaje.trim()">Responder</button></div>
        </form>
      </div>
      <div v-else class="card lg:col-span-2 flex items-center justify-center text-sm text-marca-muted min-h-48">Elegí un ticket.</div>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
const props = defineProps({ tickets: Array, filtros: Object, kpis: Object, abrirId: Number })
const sel = ref(props.tickets.find(t => t.id === props.abrirId) ?? props.tickets[0] ?? null)
watch(() => props.tickets, ts => { sel.value = ts.find(t => t.id === sel.value?.id) ?? ts[0] ?? null })
const resp = useForm({ mensaje: '', cerrar: false })
function enviar(cerrar) { resp.cerrar = cerrar; resp.post(`/admin/soporte/${sel.value.id}/responder`, { preserveScroll: true, onSuccess: () => resp.reset() }) }
</script>
