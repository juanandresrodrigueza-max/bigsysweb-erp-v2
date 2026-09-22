<template>
  <AppLayout titulo="Sucursales">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-marca-muted">{{ limite.usadas }} de {{ limite.max < 0 ? '∞' : limite.max }} sucursales del plan.</p>
      <button @click="abrir()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nueva sucursal</button>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="s in items" :key="s.id" class="card">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="font-bold">{{ s.name }} <span v-if="s.short_name" class="text-marca-muted font-normal text-sm">({{ s.short_name }})</span></p>
            <p class="text-sm text-marca-muted">{{ [s.address, s.city, s.province].filter(Boolean).join(', ') || 'Sin dirección' }}</p>
          </div>
          <div class="flex gap-1">
            <span v-if="s.is_default" class="badge bg-carmin-light text-carmin">Principal</span>
            <span v-if="!s.is_active" class="badge bg-marca-fondo text-marca-muted">Inactiva</span>
          </div>
        </div>
        <div class="flex items-center justify-between mt-4 text-sm">
          <span class="text-marca-muted">{{ s.usuarios }} usuario(s)</span>
          <button @click="abrir(s)" class="btn-ghost !px-2 text-xs"><Icono nombre="edit" clase="w-4 h-4" /> Editar</button>
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
      </form>
      <template #pie>
        <button class="btn-secondary" @click="modal = false">Cancelar</button>
        <button class="btn-primary" @click="guardar" :disabled="form.processing">Guardar</button>
      </template>
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

defineProps({ items: Array, limite: Object })
const modal = ref(false)
const vacio = { id: null, name: '', short_name: '', address: '', city: '', province: '', phone: '', email: '', is_active: true, is_default: false }
const form = useForm({ ...vacio })

function abrir(s) { form.clearErrors(); Object.assign(form, s ? { ...vacio, ...s } : { ...vacio }); modal.value = true }
function guardar() { form.post(`/configuracion/sucursales${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal.value = false) }) }
</script>
