<template>
  <Modal :abierto="abierto" :titulo="obra ? 'Editar obra' : 'Nueva obra'" ancho="max-w-2xl" @cerrar="$emit('cerrar')">
    <div class="grid sm:grid-cols-2 gap-3">
      <div class="sm:col-span-2"><label class="label">Nombre</label><input v-model="f.nombre" class="input" placeholder="Ampliación galpón · Ruta 9 km 12" /><p v-if="f.errors.nombre" class="text-carmin text-xs mt-1">{{ f.errors.nombre }}</p></div>
      <div><label class="label">Cliente</label><select v-model="f.contact_id" class="input"><option :value="null">Sin cliente (obra propia)</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      <div><label class="label">Responsable</label><select v-model="f.responsable_id" class="input"><option :value="null">—</option><option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
      <div class="sm:col-span-2"><label class="label">Dirección de la obra</label><input v-model="f.direccion" class="input" /></div>
      <div><label class="label">Presupuesto de venta (con IVA)</label><input v-model.number="f.presupuesto_venta" type="number" min="0" class="input" /></div>
      <div><label class="label">Costo previsto</label><input v-model.number="f.presupuesto_costo" type="number" min="0" class="input" /></div>
      <div><label class="label">Inicio</label><input v-model="f.fecha_inicio" type="date" class="input" /></div>
      <div><label class="label">Entrega prevista</label><input v-model="f.fecha_fin_prevista" type="date" class="input" /></div>
      <div v-if="obra"><label class="label">Estado</label><select v-model="f.estado" class="input"><option v-for="(l, k) in estados" :key="k" :value="k">{{ l }}</option></select></div>
      <div v-if="obra"><label class="label">Avance físico %</label><input v-model.number="f.avance" type="number" min="0" max="100" class="input" /></div>
      <div class="sm:col-span-2"><label class="label">Descripción / alcance</label><textarea v-model="f.descripcion" rows="2" class="input"></textarea></div>
    </div>
    <template #pie><button class="btn-secondary" @click="$emit('cerrar')">Cancelar</button><button class="btn-primary" :disabled="f.processing || !f.nombre" @click="f.post(obra ? `/obras/${obra.id}` : '/obras', { preserveScroll: true, onSuccess: () => $emit('cerrar') })">Guardar</button></template>
  </Modal>
</template>

<script setup>
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
const props = defineProps({ abierto: Boolean, obra: Object, clientes: Array, usuarios: Array, estados: Object })
defineEmits(['cerrar'])
const f = useForm({ nombre: '', contact_id: null, responsable_id: null, direccion: '', presupuesto_venta: 0, presupuesto_costo: 0, fecha_inicio: '', fecha_fin_prevista: '', estado: 'presupuestado', avance: 0, descripcion: '' })
watch(() => props.abierto, a => { if (!a) return; f.clearErrors(); const o = props.obra; Object.assign(f, { nombre: o?.nombre ?? '', contact_id: o?.contact_id ?? null, responsable_id: o?.responsable_id ?? null, direccion: o?.direccion ?? '', presupuesto_venta: o?.presupuesto_venta ?? 0, presupuesto_costo: o?.presupuesto_costo ?? 0, fecha_inicio: o?.fecha_inicio ?? '', fecha_fin_prevista: o?.fecha_fin_prevista ?? '', estado: o?.estado ?? 'presupuestado', avance: o?.avance ?? 0, descripcion: o?.descripcion ?? '' }) }, { immediate: true })
</script>
