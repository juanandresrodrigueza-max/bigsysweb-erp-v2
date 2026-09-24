<template>
  <AppLayout titulo="Hojas de reparto">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/comprobantes/pendientes" class="text-xs text-marca-muted hover:text-carmin">← Pendientes</Link>
        <h1 class="page-title">Hojas de reparto</h1>
        <p class="page-subtitle">Agrupá las entregas del día por repartidor, imprimí la hoja y marcá cada entrega al volver.</p>
      </div>
      <button v-if="puede('comprobantes','crear')" @click="nuevaAbierta = true" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nueva hoja</button>
    </div>

    <div class="card mb-4 flex gap-2 items-center">
      <select :value="filtros.estado ?? ''" @change="$inertia.visit(`/comprobantes/entregas?estado=${$event.target.value}`)" class="input w-auto"><option value="">Abiertas</option><option v-for="(l, k) in estados" :key="k" :value="k">{{ l }}</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Hoja</th><th>Fecha</th><th>Repartidor</th><th class="text-right">Entregas</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="o in ordenes" :key="o.id" class="cursor-pointer" @click="$inertia.visit(`/comprobantes/entregas/${o.id}`)">
            <td class="font-semibold tabular-nums">{{ o.numero }}</td><td class="tabular-nums text-marca-muted">{{ o.fecha }}</td><td>{{ o.repartidor ?? '—' }}</td>
            <td class="text-right tabular-nums">{{ o.entregados }} / {{ o.items }}</td>
            <td><span class="badge" :class="{ pendiente: 'bg-gris-light text-marca-muted', en_curso: 'bg-violeta-light text-violeta', entregada: 'bg-emerald-50 text-emerald-700', cancelada: 'bg-carmin-light text-carmin' }[o.estado]">{{ estados[o.estado] }}</span></td>
          </tr>
          <tr v-if="!ordenes.length"><td colspan="5" class="text-center text-marca-muted py-10">No hay hojas de reparto.</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="nuevaAbierta" titulo="Nueva hoja de reparto" ancho="max-w-2xl" @cerrar="nuevaAbierta = false">
      <div class="grid sm:grid-cols-3 gap-3 mb-3">
        <div><label class="label">Fecha</label><input v-model="nf.fecha" type="date" class="input" /></div>
        <div><label class="label">Repartidor</label><input v-model="nf.repartidor" class="input" placeholder="Nombre" /></div>
        <div><label class="label">Vehículo</label><input v-model="nf.vehiculo" class="input" placeholder="Patente / camioneta" /></div>
      </div>
      <p class="label">Qué se entrega</p>
      <div class="max-h-72 overflow-y-auto border border-marca-borde rounded-xl divide-y divide-marca-borde/60">
        <label v-for="c in candidatos" :key="c.id" class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-marca-fondo"><input v-model="nf.comprobantes" :value="c.id" type="checkbox" class="accent-carmin" /><span class="flex-1 min-w-0"><span class="block font-medium truncate">{{ c.label }}</span><span class="text-xs text-marca-muted">{{ c.fecha }} · {{ c.direccion || 'sin dirección' }}</span></span></label>
        <p v-if="!candidatos.length" class="px-3 py-4 text-sm text-marca-muted">No hay remitos ni facturas con entrega pendiente para repartir.</p>
      </div>
      <p v-if="nf.errors.comprobantes" class="text-carmin text-xs mt-2">{{ nf.errors.comprobantes }}</p>
      <label class="label mt-3">Notas</label><input v-model="nf.notas" class="input" />
      <template #pie><button class="btn-secondary" @click="nuevaAbierta = false">Cancelar</button><button class="btn-primary" :disabled="nf.processing || !nf.comprobantes.length" @click="nf.post('/comprobantes/entregas')">Crear hoja con {{ nf.comprobantes.length }}</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { hoyISO } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

defineProps({ ordenes: Array, filtros: Object, estados: Object, candidatos: Array })
const { puede } = usePermisos()
const nuevaAbierta = ref(false)
const nf = useForm({ fecha: hoyISO(), repartidor: '', vehiculo: '', notas: '', comprobantes: [] })
</script>
