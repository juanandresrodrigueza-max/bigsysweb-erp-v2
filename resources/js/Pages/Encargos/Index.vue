<template>
  <AppLayout titulo="Encargos">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><p class="kicker mb-1">Equipo</p><h1 class="page-title">Encargos</h1>
        <p class="page-subtitle">Dejale algo a alguien del equipo: le llega por WhatsApp y por la campana, contesta HECHO y te enterás. También se lo podés pedir a Bigcito: «decile a Pedro que prepare el pedido 12 para las 17».</p></div>
      <button class="btn-primary" @click="abrir()" data-e2e="encargo-nuevo">Nuevo encargo</button>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
      <section class="card" data-e2e="encargos-para-mi">
        <h2 class="font-bold mb-1">Para hacer yo <span v-if="paraMi.length" class="badge bg-violeta-light text-violeta ml-1">{{ paraMi.length }}</span></h2>
        <EstadoVacio v-if="!paraMi.length" texto="No tenés nada pendiente." />
        <div v-for="e in paraMi" :key="e.id" class="py-2.5 border-t border-marca-borde first:border-0" data-e2e="encargo">
          <div class="flex items-start gap-3">
            <button class="mt-0.5 w-6 h-6 shrink-0 rounded-full border-2 border-violeta/50 hover:bg-violeta hover:text-white grid place-items-center text-xs transition" title="Marcar como hecho" @click="hecho(e)" data-e2e="encargo-hecho">✓</button>
            <div class="min-w-0 flex-1">
              <p class="font-semibold leading-snug">{{ e.texto }}</p>
              <p v-if="e.detalle" class="text-sm text-marca-muted whitespace-pre-line">{{ e.detalle }}</p>
              <p class="text-xs text-marca-muted mt-0.5"><span v-if="e.de_id !== yo">de {{ e.de }} · </span><span v-else>lo anoté yo · </span>{{ e.cuando }}<span v-if="e.vence" :class="e.vencido ? 'text-carmin font-semibold' : ''"> · para {{ e.vence }}{{ e.vencido ? ' (vencido)' : '' }}</span></p>
            </div>
          </div>
        </div>
      </section>

      <section class="card" data-e2e="encargos-encargue">
        <h2 class="font-bold mb-1">Lo que encargué <span v-if="encargue.length" class="badge bg-gris-light ml-1">{{ encargue.length }}</span></h2>
        <EstadoVacio v-if="!encargue.length" texto="No le dejaste nada pendiente a nadie." />
        <div v-for="e in encargue" :key="e.id" class="py-2.5 border-t border-marca-borde first:border-0">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-semibold leading-snug">{{ e.texto }}</p>
              <p class="text-xs text-marca-muted mt-0.5">a {{ e.para }} · {{ e.cuando }}<span v-if="e.vence" :class="e.vencido ? 'text-carmin font-semibold' : ''"> · para {{ e.vence }}{{ e.vencido ? ' (vencido)' : '' }}</span> · {{ e.aviso === 'whatsapp' ? 'avisado por WhatsApp' : 'avisado en la campana' }}</p>
            </div>
            <span class="flex gap-1 shrink-0"><button class="btn-ghost !py-1 text-xs" @click="router.post(`/encargos/${e.id}/hecho`, {}, { preserveScroll: true })">Ya está</button><button class="btn-ghost !py-1 text-xs text-carmin" @click="cancelar(e)">Cancelar</button></span>
          </div>
        </div>
      </section>
    </div>

    <section v-if="cerrados.length" class="card mt-4 text-sm">
      <h2 class="font-bold mb-1">Últimos cerrados</h2>
      <div v-for="e in cerrados" :key="e.id" class="flex flex-wrap items-center justify-between gap-2 py-1.5 border-t border-marca-borde first:border-0">
        <span class="min-w-0"><span class="badge mr-1" :class="e.estado === 'hecho' ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light'">{{ e.estado === 'hecho' ? 'Hecho' : 'Cancelado' }}</span>{{ e.texto }}
          <span class="text-marca-muted"> · {{ e.de_id === yo ? `a ${e.para}` : `de ${e.de}` }}<span v-if="e.hecho"> · {{ e.hecho }}</span><span v-if="e.nota"> · «{{ e.nota }}»</span></span></span>
        <button v-if="e.de_id === yo" class="btn-ghost !py-1 text-xs" @click="router.post(`/encargos/${e.id}/reabrir`, {}, { preserveScroll: true })">Reabrir</button>
      </div>
    </section>

    <Modal :abierto="abierto" titulo="Nuevo encargo" @cerrar="abierto = false">
      <div class="space-y-3 text-sm" data-e2e="encargo-form">
        <div><label class="label">¿Para quién?</label>
          <select v-model="f.para_user_id" class="input" data-e2e="encargo-para"><option v-for="p in equipo" :key="p.id" :value="p.id">{{ p.yo ? 'Para mí (recordatorio)' : p.nombre }}{{ !p.yo && p.whatsapp && whatsappEmpresa ? ' · le llega por WhatsApp' : '' }}</option></select>
          <p v-if="elegido && !elegido.yo && !(elegido.whatsapp && whatsappEmpresa)" class="text-[11px] text-marca-muted mt-0.5">{{ whatsappEmpresa ? 'No tiene celular cargado en su usuario: le llega a la campana y al aviso del celular.' : 'La empresa no tiene el WhatsApp conectado: le llega a la campana y al aviso del celular.' }}</p></div>
        <div><label class="label">¿Qué hay que hacer?</label><input v-model="f.texto" class="input" maxlength="300" placeholder="Preparar el pedido 12" data-e2e="encargo-texto" /><p v-if="f.errors.texto" class="text-carmin text-xs mt-0.5">{{ f.errors.texto }}</p></div>
        <div><label class="label">Aclaraciones <span class="font-normal text-marca-muted">(opcional)</span></label><textarea v-model="f.detalle" class="input" rows="2" maxlength="2000"></textarea></div>
        <div><label class="label">¿Para cuándo? <span class="font-normal text-marca-muted">(opcional)</span></label><input v-model="f.vence_en" type="datetime-local" class="input" /><p v-if="f.errors.vence_en" class="text-carmin text-xs mt-0.5">{{ f.errors.vence_en }}</p>
          <p class="text-[11px] text-marca-muted mt-0.5">Si pasa la fecha y sigue pendiente, se lo recordamos una vez por día y te avisamos a vos.</p></div>
      </div>
      <template #pie><button class="btn-secondary" @click="abierto = false">Cancelar</button><button class="btn-primary" :disabled="f.processing || !f.texto.trim() || !f.para_user_id" @click="guardar" data-e2e="encargo-guardar">Encargar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import Modal from '@/Components/Modal.vue'

const props = defineProps({ paraMi: Array, encargue: Array, cerrados: Array, equipo: Array, whatsappEmpresa: Boolean, yo: Number })
const abierto = ref(false)
const f = useForm({ para_user_id: null, texto: '', detalle: '', vence_en: '' })
const elegido = computed(() => props.equipo.find(p => p.id === f.para_user_id))
function abrir() { f.reset(); f.clearErrors(); f.para_user_id = (props.equipo.find(p => !p.yo) ?? props.equipo[0])?.id ?? null; abierto.value = true }
function guardar() { f.post('/encargos', { preserveScroll: true, onSuccess: () => (abierto.value = false) }) }
function hecho(e) {
  const nota = e.de_id !== props.yo ? window.prompt(`¿Algo para decirle a ${e.de}? (podés dejarlo vacío)`, '') : ''
  if (nota === null) return
  router.post(`/encargos/${e.id}/hecho`, { nota }, { preserveScroll: true })
}
function cancelar(e) { if (confirm(`¿Cancelar el encargo «${e.texto}»?`)) router.post(`/encargos/${e.id}/cancelar`, {}, { preserveScroll: true }) }
</script>
