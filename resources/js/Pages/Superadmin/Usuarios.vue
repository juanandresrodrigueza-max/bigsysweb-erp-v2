<template>
  <AdminLayout titulo="Usuarios">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Usuarios</h1><p class="page-subtitle">Todas las personas que entran al sistema, de todas las empresas.</p></div>
      <button @click="nuevo = true" class="btn-primary"><Icono nombre="shield" clase="w-4 h-4" /> Nuevo superadmin</button>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-3">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input sm:col-span-2" placeholder="Nombre o email…" />
      <select v-model="f.tipo" @change="filtrar" class="input"><option value="">Todos</option><option value="superadmin">Superadmins</option><option value="inactivos">Inactivos</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Usuario</th><th>Empresa</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th></th></tr></thead>
        <tbody>
          <tr v-for="u in lista.data" :key="u.id">
            <td><p class="font-semibold">{{ u.name }}</p><p class="text-xs text-marca-muted">{{ u.email }}</p></td>
            <td><Link v-if="u.business_id" :href="`/admin/empresas/${u.business_id}`" class="hover:text-carmin">{{ u.empresa }}</Link><span v-else class="text-marca-muted">BigSys</span></td>
            <td><span class="badge" :class="u.is_superadmin ? 'bg-violeta text-white' : 'bg-lavanda-light text-violeta'">{{ u.rol }}</span></td>
            <td><span class="badge" :class="u.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ u.status === 'active' ? 'Activo' : 'Inactivo' }}</span></td>
            <td class="text-xs text-marca-muted">{{ u.ultimo }}</td>
            <td class="text-right whitespace-nowrap">
              <button @click="pass = { id: u.id, nombre: u.name, password: '' }" class="btn-ghost !px-2 text-xs">Contraseña</button>
              <Link :href="`/admin/usuarios/${u.id}`" method="post" :data="{ accion: u.status === 'active' ? 'desactivar' : 'activar' }" as="button" preserve-scroll class="btn-ghost !px-2 text-xs" :class="u.status === 'active' ? 'text-carmin' : 'text-emerald-700'">{{ u.status === 'active' ? 'Desactivar' : 'Activar' }}</Link>
              <Link v-if="!u.business_id || u.is_superadmin" :href="`/admin/usuarios/${u.id}`" method="post" :data="{ accion: u.is_superadmin ? 'quitar_superadmin' : 'superadmin' }" as="button" preserve-scroll class="btn-ghost !px-2 text-xs">{{ u.is_superadmin ? 'Quitar superadmin' : 'Hacer superadmin' }}</Link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />

    <Modal :abierto="nuevo" titulo="Nuevo superadmin" @cerrar="nuevo = false">
      <div class="space-y-3">
        <div><label class="label">Nombre</label><input v-model="sa.name" class="input" /></div>
        <div><label class="label">Email</label><input v-model="sa.email" type="email" class="input" /><p v-if="sa.errors.email" class="text-carmin text-xs mt-1">{{ sa.errors.email }}</p></div>
        <div><label class="label">Contraseña</label><input v-model="sa.password" class="input" /><p v-if="sa.errors.password" class="text-carmin text-xs mt-1">{{ sa.errors.password }}</p></div>
      </div>
      <template #pie><button class="btn-secondary" @click="nuevo = false">Cancelar</button><button class="btn-primary" :disabled="sa.processing" @click="sa.post('/admin/usuarios', { onSuccess: () => { nuevo = false; sa.reset() } })">Crear</button></template>
    </Modal>
    <Modal :abierto="!!pass" :titulo="`Nueva contraseña · ${pass?.nombre}`" @cerrar="pass = null">
      <input v-if="pass" v-model="pass.password" class="input" placeholder="Mínimo 6 caracteres" />
      <template #pie><button class="btn-secondary" @click="pass = null">Cancelar</button><button class="btn-primary" @click="router.post(`/admin/usuarios/${pass.id}`, { accion: 'password', password: pass.password }, { preserveScroll: true, onSuccess: () => (pass = null) })">Guardar</button></template>
    </Modal>
  </AdminLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
import Paginacion from '@/Components/Paginacion.vue'
const props = defineProps({ lista: Object, filtros: Object })
const f = reactive({ buscar: props.filtros.buscar ?? '', tipo: props.filtros.tipo ?? '' })
function filtrar() { router.get('/admin/usuarios', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
const nuevo = ref(false), pass = ref(null)
const sa = useForm({ name: '', email: '', password: '' })
</script>
