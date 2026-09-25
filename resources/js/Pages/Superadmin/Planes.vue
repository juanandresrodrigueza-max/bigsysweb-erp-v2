<template>
  <AdminLayout titulo="Planes">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Planes</h1><p class="page-subtitle">Qué incluye cada plan, cuánto cuesta y cuántas empresas lo usan.</p></div>
      <button @click="abrir()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nuevo plan</button>
    </div>

    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
      <div v-for="p in planes" :key="p.id" class="card flex flex-col" :class="!p.is_active ? 'opacity-60' : ''">
        <div class="flex items-start justify-between gap-2">
          <div><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ p.is_free ? 'Gratis' : 'Plan' }}</p><h2 class="text-xl font-extrabold">{{ p.name }}</h2></div>
          <button @click="abrir(p)" class="btn-ghost !px-2"><Icono nombre="edit" clase="w-4 h-4" /></button>
        </div>
        <p class="text-sm text-marca-muted mt-1 min-h-[2.5rem]">{{ p.description }}</p>
        <p class="mt-3"><span class="text-2xl font-black tabular-nums">{{ moneda(p.price_monthly, 0) }}</span><span class="text-xs text-marca-muted"> /mes</span></p>
        <p class="text-xs text-marca-muted tabular-nums">{{ moneda(p.price_yearly, 0) }} /año<span v-if="p.price_monthly"> · ahorra {{ Math.round((1 - p.price_yearly / (p.price_monthly * 12)) * 100) }}%</span></p>
        <ul class="mt-4 space-y-1 text-sm flex-1">
          <li class="flex justify-between"><span class="text-marca-muted">Usuarios</span><b>{{ p.max_users < 0 ? 'Sin límite' : p.max_users }}</b></li>
          <li class="flex justify-between"><span class="text-marca-muted">Sucursales</span><b>{{ p.max_locations < 0 ? 'Sin límite' : p.max_locations }}</b></li>
          <li class="flex justify-between"><span class="text-marca-muted">Productos</span><b>{{ p.max_products < 0 ? 'Sin límite' : entero(p.max_products) }}</b></li><li class="flex justify-between"><span class="text-marca-muted">Facturas por mes</span><b>{{ p.max_facturas_mes < 0 ? 'Sin límite' : entero(p.max_facturas_mes) }}</b></li>
        </ul>
        <div class="flex flex-wrap gap-1 mt-3"><span v-for="m in modulosDe(p)" :key="m" class="badge bg-marca-fondo">{{ m }}</span></div>
        <div class="flex items-center justify-between mt-4 pt-3 border-t border-marca-borde text-xs"><span class="text-marca-muted">{{ p.activas }} empresa{{ p.activas === 1 ? '' : 's' }}</span><span class="badge" :class="p.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ p.is_active ? 'Se ofrece' : 'Oculto' }}</span></div>
      </div>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? `Editar plan ${form.name}` : 'Nuevo plan'" ancho="max-w-2xl" @cerrar="modal = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div><label class="label">Nombre</label><input v-model="form.name" class="input" /><p v-if="form.errors.name" class="text-carmin text-xs mt-1">{{ form.errors.name }}</p></div>
        <div><label class="label">Descripción corta</label><input v-model="form.description" class="input" /></div>
        <div><label class="label">Precio mensual</label><input v-model.number="form.price_monthly" type="number" step="any" class="input" /></div>
        <div><label class="label">Precio anual</label><input v-model.number="form.price_yearly" type="number" step="any" class="input" /></div>
        <div><label class="label">Usuarios (-1 = sin límite)</label><input v-model.number="form.max_users" type="number" class="input" /></div>
        <div><label class="label">Sucursales (-1 = sin límite)</label><input v-model.number="form.max_locations" type="number" class="input" /></div>
        <div><label class="label">Productos (-1 = sin límite)</label><input v-model.number="form.max_products" type="number" class="input" /></div>
        <div><label class="label">Facturas por mes (-1 = sin límite)</label><input v-model.number="form.max_facturas_mes" type="number" class="input" /></div>
        <div class="flex flex-col gap-2 pt-5"><label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="accent-carmin" /> Se ofrece a empresas nuevas</label><label class="flex items-center gap-2 text-sm"><input v-model="form.is_free" type="checkbox" class="accent-carmin" /> Es gratis</label></div>
        <div class="sm:col-span-2">
          <label class="label">Módulos incluidos</label>
          <label class="flex items-center gap-2 text-sm font-semibold mb-2"><input type="checkbox" class="accent-carmin" :checked="form.features.includes('*')" @change="toggle('*')" /> Todo (incluye lo que se agregue a futuro)</label>
          <div class="grid sm:grid-cols-3 gap-1.5" :class="form.features.includes('*') ? 'opacity-40 pointer-events-none' : ''">
            <label v-for="m in modulos" :key="m.key" class="flex items-center gap-2 text-sm"><input type="checkbox" class="accent-carmin" :checked="m.core || form.features.includes(m.key)" :disabled="m.core" @change="toggle(m.key)" /> {{ m.label }}<span v-if="m.core" class="text-[10px] text-marca-muted">base</span></label>
            <label v-for="(l, k) in extras" :key="k" class="flex items-center gap-2 text-sm"><input type="checkbox" class="accent-carmin" :checked="form.features.includes(k)" @change="toggle(k)" /> {{ l }}</label>
          </div>
        </div>
      </div>
      <template #pie><button class="btn-secondary" @click="modal = false">Cancelar</button><button class="btn-primary" :disabled="form.processing" @click="form.post(`/admin/planes${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal = false) })">Guardar</button></template>
    </Modal>
  </AdminLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
import { moneda, entero } from '@/util/formato'
const props = defineProps({ planes: Array, modulos: Array, extras: Object })
const modal = ref(false)
const form = useForm({ id: null, name: '', description: '', price_monthly: 0, price_yearly: 0, max_users: 3, max_locations: 1, max_products: 500, max_facturas_mes: -1, features: [], is_active: true, is_free: false })
function abrir(p) { form.clearErrors(); Object.assign(form, p ? { ...p, features: [...p.features] } : { id: null, name: '', description: '', price_monthly: 0, price_yearly: 0, max_users: 3, max_locations: 1, max_products: 500, max_facturas_mes: -1, features: [], is_active: true, is_free: false }); modal.value = true }
function toggle(k) { form.features = form.features.includes(k) ? form.features.filter(x => x !== k) : [...form.features, k] }
const modulosDe = p => p.features.includes('*') ? ['Todos los módulos'] : props.modulos.filter(m => !m.core && p.features.includes(m.key)).map(m => m.label).concat(Object.entries(props.extras).filter(([k]) => p.features.includes(k)).map(([, l]) => l))
</script>
