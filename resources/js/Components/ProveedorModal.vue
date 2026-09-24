<template>
  <Modal :abierto="abierto" :titulo="form.id ? 'Editar proveedor' : 'Nuevo proveedor'" ancho="max-w-2xl" @cerrar="$emit('cerrar')">
    <form @submit.prevent="guardar" class="grid sm:grid-cols-2 gap-4">
      <div class="sm:col-span-2"><label class="label">Razón social / Nombre</label><input v-model="form.name" class="input" /><p v-if="form.errors.name" class="text-carmin text-xs mt-1">{{ form.errors.name }}</p></div>
      <div><label class="label">Condición IVA</label><select v-model="form.condicion_iva" class="input"><option v-for="c in condicionesIva" :key="c">{{ c }}</option></select></div>
      <div><label class="label">CUIT</label><input v-model="form.cuit" class="input" placeholder="30-12345678-9" /></div>
      <div><label class="label">Plazo de pago (días)</label><input v-model.number="form.dias_pago" type="number" min="0" class="input" /></div>
      <div><label class="label">Email</label><input v-model="form.email" type="email" class="input" /></div>
      <div><label class="label">Teléfono</label><input v-model="form.phone" class="input" /></div>
      <div><label class="label">Ciudad</label><input v-model="form.city" class="input" /></div>
      <div class="sm:col-span-2"><label class="label">Dirección</label><input v-model="form.address" class="input" /></div>
      <div class="sm:col-span-2"><label class="label">Notas</label><textarea v-model="form.notes" rows="2" class="input"></textarea></div>
      <label v-if="form.id" class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="accent-carmin" /> Activo</label>
    </form>
    <template #pie>
      <button class="btn-secondary" @click="$emit('cerrar')">Cancelar</button>
      <button class="btn-primary" :disabled="form.processing" @click="guardar">Guardar</button>
    </template>
  </Modal>
</template>

<script setup>
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'

const props = defineProps({ abierto: Boolean, proveedor: Object, condicionesIva: Array })
const emit = defineEmits(['cerrar'])
const vacio = { id: null, name: '', condicion_iva: 'Responsable Inscripto', cuit: '', dias_pago: 0, email: '', phone: '', city: '', address: '', province: '', notes: '', is_active: true }
const form = useForm({ ...vacio })
watch(() => props.abierto, v => { if (v) { form.clearErrors(); Object.assign(form, { ...vacio, ...(props.proveedor ?? {}) }) } })
function guardar() { form.post(form.id ? `/proveedores/${form.id}` : '/proveedores', { preserveScroll: true, onSuccess: () => emit('cerrar') }) }
</script>
