<template>
  <AdminLayout titulo="Auditoría">
    <div class="mb-5"><h1 class="page-title">Auditoría global</h1><p class="page-subtitle">Quién hizo qué, en qué empresa.</p></div>
    <div class="card mb-4 flex flex-wrap gap-2">
      <select v-model="f.empresa" @change="filtrar" class="input w-auto"><option value="">Todas las empresas</option><option v-for="e in empresas" :key="e.id" :value="e.id">{{ e.name }}</option></select>
      <select v-model="f.accion" @change="filtrar" class="input w-auto"><option value="">Todas las acciones</option><option v-for="a in ['login','alta_empresa','baja_empresa','suspender_empresa','reactivar_empresa','suscripcion','pago_suscripcion','impersonar','crear','editar','anular']" :key="a" :value="a">{{ a }}</option></select>
    </div>
    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Fecha</th><th>Empresa</th><th>Usuario</th><th>Acción</th><th>Detalle</th><th>IP</th></tr></thead>
        <tbody>
          <tr v-for="l in logs.data" :key="l.id"><td class="text-marca-muted whitespace-nowrap">{{ l.fecha }}</td><td class="font-medium">{{ l.empresa }}</td><td>{{ l.usuario }}</td><td><span class="badge bg-marca-fondo">{{ l.accion }}</span></td><td>{{ l.descripcion }}</td><td class="text-xs text-marca-muted">{{ l.ip }}</td></tr>
          <tr v-if="!logs.data.length"><td colspan="6" class="text-center text-marca-muted py-8">Sin registros.</td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="logs.links" :desde="logs.from" :hasta="logs.to" :total="logs.total" />
  </AdminLayout>
</template>
<script setup>
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Paginacion from '@/Components/Paginacion.vue'
const props = defineProps({ logs: Object, filtros: Object, empresas: Array })
const f = reactive({ empresa: props.filtros.empresa ?? '', accion: props.filtros.accion ?? '' })
function filtrar() { router.get('/admin/auditoria', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
</script>
