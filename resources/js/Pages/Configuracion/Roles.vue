<template>
  <AppLayout titulo="Roles y permisos">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-marca-muted">Cada rol define qué módulos ve y qué puede hacer. Los roles del sistema se pueden ajustar pero no borrar.</p>
      <button @click="abrir()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nuevo rol</button>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="r in roles" :key="r.id" class="card">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="font-bold flex items-center gap-2"><Icono nombre="shield" clase="w-4 h-4 text-violeta" /> {{ r.nombre }}</p>
            <p class="text-sm text-marca-muted mt-0.5">{{ r.descripcion }}</p>
          </div>
          <span v-if="r.es_sistema" class="badge bg-marca-fondo text-marca-muted">Sistema</span>
        </div>
        <p class="text-xs text-marca-muted mt-3">{{ resumen(r) }}</p>
        <div class="flex items-center justify-between mt-3 text-sm">
          <span class="text-marca-muted">{{ r.usuarios }} usuario(s)</span>
          <div class="flex gap-1">
            <button v-if="r.slug !== 'dueno'" @click="abrir(r)" class="btn-ghost !px-2 text-xs"><Icono nombre="edit" clase="w-4 h-4" /></button>
            <Link v-if="!r.es_sistema" :href="`/configuracion/roles/${r.id}`" method="delete" as="button" class="btn-ghost !px-2 text-xs text-carmin" @click.prevent="confirmarEliminar(r)"><Icono nombre="trash" clase="w-4 h-4" /></Link>
          </div>
        </div>
      </div>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? `Editar rol: ${form.nombre}` : 'Nuevo rol'" ancho="max-w-3xl" @cerrar="modal = false">
      <form @submit.prevent="guardar" class="space-y-4">
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="label">Nombre</label><input v-model="form.nombre" class="input" /><p v-if="form.errors.nombre" class="text-carmin text-xs mt-1">{{ form.errors.nombre }}</p></div>
          <div><label class="label">Descripción</label><input v-model="form.descripcion" class="input" /></div>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead><tr><th>Módulo</th><th v-for="a in acciones" :key="a" class="text-center capitalize">{{ a }}</th><th class="text-center">Todo</th></tr></thead>
            <tbody>
              <tr v-for="m in modulos" :key="m.key">
                <td class="font-medium">{{ m.label }} <span class="text-xs text-marca-muted">{{ m.grupo }}</span></td>
                <td v-for="a in acciones" :key="a" class="text-center"><input type="checkbox" class="accent-carmin" :checked="tiene(m.key, a)" @change="toggle(m.key, a)" /></td>
                <td class="text-center"><button type="button" class="text-xs text-violeta font-semibold" @click="todo(m.key)">{{ tieneTodo(m.key) ? 'Nada' : 'Todo' }}</button></td>
              </tr>
            </tbody>
          </table>
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
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'

const props = defineProps({ roles: Array, modulos: Array, acciones: Array })
const modal = ref(false)
const form = useForm({ id: null, nombre: '', descripcion: '', permisos: {} })

function expandir(p) {
  if (p === '*' || p?.['*'] === '*') return Object.fromEntries(props.modulos.map(m => [m.key, [...props.acciones]]))
  const out = {}
  for (const m of props.modulos) {
    const acc = new Set([...(p?.[m.key] ?? []), ...(p?.['*'] ?? [])])
    if (acc.size) out[m.key] = [...acc]
  }
  return out
}
function resumen(r) {
  const p = expandir(r.permisos)
  const n = Object.keys(p).length
  if (n === props.modulos.length && Object.values(p).every(a => a.length === props.acciones.length)) return 'Acceso total a todos los módulos.'
  return n ? `Accede a ${n} módulo(s): ${Object.keys(p).map(k => props.modulos.find(m => m.key === k)?.label).join(', ')}.` : 'Sin permisos.'
}
function abrir(r) { form.clearErrors(); form.id = r?.id ?? null; form.nombre = r?.nombre ?? ''; form.descripcion = r?.descripcion ?? ''; form.permisos = r ? expandir(r.permisos) : {}; modal.value = true }
const tiene = (m, a) => (form.permisos[m] ?? []).includes(a)
const tieneTodo = m => props.acciones.every(a => tiene(m, a))
function toggle(m, a) {
  const cur = new Set(form.permisos[m] ?? [])
  cur.has(a) ? cur.delete(a) : cur.add(a)
  if (cur.size && !cur.has('ver')) cur.add('ver')
  form.permisos = { ...form.permisos, [m]: [...cur] }
}
function todo(m) { form.permisos = { ...form.permisos, [m]: tieneTodo(m) ? [] : [...props.acciones] } }
function guardar() { form.post(`/configuracion/roles${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal.value = false) }) }
function confirmarEliminar(r) { if (confirm(`¿Eliminar el rol "${r.nombre}"?`)) router.delete(`/configuracion/roles/${r.id}`, { preserveScroll: true }) }
</script>
