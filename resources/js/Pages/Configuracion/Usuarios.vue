<template>
  <AppLayout titulo="Usuarios">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-marca-muted">{{ limite.usados }} de {{ limite.max < 0 ? '∞' : limite.max }} usuarios del plan.</p>
      <button @click="abrir()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nuevo usuario</button>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Usuario</th><th>Rol</th><th>Sucursales</th><th>Estado</th><th>Último acceso</th><th></th></tr></thead>
        <tbody>
          <tr v-for="u in usuarios" :key="u.id">
            <td>
              <div class="flex items-center gap-3">
                <span class="w-8 h-8 rounded-full bg-violeta text-white text-xs font-bold flex items-center justify-center">{{ iniciales(u.name) }}</span>
                <div><p class="font-semibold">{{ u.name }}</p><p class="text-xs text-marca-muted">{{ u.email }}</p></div>
              </div>
            </td>
            <td><span class="badge" :class="u.es_dueno ? 'bg-carmin-light text-carmin' : 'bg-violeta-light text-violeta'">{{ u.rol ?? 'Sin rol' }}</span></td>
            <td class="text-marca-muted">{{ u.es_dueno ? 'Todas' : (u.sucursales.map(s => s.name).join(', ') || 'Principal') }}</td>
            <td><span class="badge" :class="u.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-marca-fondo text-marca-muted'">{{ u.status === 'active' ? 'Activo' : 'Inactivo' }}</span></td>
            <td class="text-marca-muted text-xs">{{ u.ultimo_acceso ?? 'Nunca' }}</td>
            <td class="text-right"><button @click="abrir(u)" class="btn-ghost !px-2 text-xs"><Icono nombre="edit" clase="w-4 h-4" /></button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? 'Editar usuario' : 'Nuevo usuario'" @cerrar="modal = false">
      <form @submit.prevent="guardar" class="space-y-4">
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="label">Nombre</label><input v-model="form.name" class="input" /><p v-if="form.errors.name" class="text-carmin text-xs mt-1">{{ form.errors.name }}</p></div>
          <div><label class="label">Email</label><input v-model="form.email" type="email" class="input" /><p v-if="form.errors.email" class="text-carmin text-xs mt-1">{{ form.errors.email }}</p></div>
          <div><label class="label">{{ form.id ? 'Nueva contraseña (opcional)' : 'Contraseña' }}</label><input v-model="form.password" type="password" class="input" autocomplete="new-password" /><p v-if="form.errors.password" class="text-carmin text-xs mt-1">{{ form.errors.password }}</p></div>
          <div><label class="label">Estado</label><select v-model="form.status" class="input" :disabled="esDueno"><option value="active">Activo</option><option value="inactive">Inactivo</option></select></div>
          <div class="sm:col-span-2"><label class="label">Rol general</label>
            <select v-model="form.role_id" class="input" :disabled="esDueno"><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option></select>
            <p v-if="form.errors.role_id" class="text-carmin text-xs mt-1">{{ form.errors.role_id }}</p>
          </div>
        </div>
        <div>
          <label class="label">Sucursales a las que accede</label>
          <p v-if="esDueno" class="text-sm text-marca-muted">El dueño accede a todas las sucursales.</p>
          <div v-else class="space-y-2">
            <div v-for="s in listaSucursales" :key="s.id" class="flex items-center gap-3 p-2 rounded-xl border border-marca-borde">
              <label class="flex items-center gap-2 text-sm flex-1"><input type="checkbox" class="accent-carmin" :checked="tiene(s.id)" @change="toggle(s.id)" /> {{ s.name }}</label>
              <select v-if="tiene(s.id)" :value="rolEn(s.id)" @change="setRol(s.id, $event.target.value)" class="input w-44 !py-1 text-xs">
                <option value="">Mismo rol general</option>
                <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
              </select>
            </div>
          </div>
        </div>
      </form>
      <template #pie>
        <button class="btn-secondary" @click="modal = false">Cancelar</button>
        <button class="btn-primary" @click="guardar" :disabled="form.processing">Guardar</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'

const props = defineProps({ usuarios: Array, roles: Array, listaSucursales: Array, limite: Object })
const modal = ref(false)
const esDueno = ref(false)
const vacio = { id: null, name: '', email: '', password: '', status: 'active', role_id: null, sucursales: [] }
const form = useForm({ ...vacio })

const iniciales = n => (n ?? '?').split(' ').slice(0, 2).map(p => p[0]).join('').toUpperCase()
function abrir(u) {
  form.clearErrors()
  esDueno.value = !!u?.es_dueno
  Object.assign(form, u ? { ...vacio, id: u.id, name: u.name, email: u.email, status: u.status, role_id: u.role_id ?? props.roles[0]?.id, sucursales: u.sucursales.map(s => ({ id: s.id, role_id: s.role_id })) } : { ...vacio, role_id: props.roles.find(r => r.slug === 'vendedor')?.id ?? props.roles[0]?.id })
  modal.value = true
}
const tiene = id => form.sucursales.some(s => s.id === id)
const rolEn = id => form.sucursales.find(s => s.id === id)?.role_id ?? ''
function toggle(id) { form.sucursales = tiene(id) ? form.sucursales.filter(s => s.id !== id) : [...form.sucursales, { id, role_id: null }] }
function setRol(id, v) { form.sucursales = form.sucursales.map(s => s.id === id ? { ...s, role_id: v ? Number(v) : null } : s) }
function guardar() { form.post(`/configuracion/usuarios${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal.value = false) }) }
</script>
