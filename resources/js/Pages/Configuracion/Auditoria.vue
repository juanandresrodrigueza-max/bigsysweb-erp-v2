<template>
  <AppLayout titulo="Auditoría">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />

    <div class="card mb-4 flex flex-wrap gap-2">
      <select v-model="f.usuario" @change="filtrar" class="input w-auto"><option value="">Todos los usuarios</option><option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option></select>
      <select v-model="f.accion" @change="filtrar" class="input w-auto"><option value="">Todas las acciones</option><option v-for="a in ['login','crear','editar','eliminar','anular','cambio_sucursal']" :key="a" :value="a">{{ a }}</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th><th>IP</th></tr></thead>
        <tbody>
          <tr v-for="l in logs.data" :key="l.id">
            <td class="text-marca-muted whitespace-nowrap">{{ l.fecha }}</td>
            <td class="font-medium">{{ l.usuario }}</td>
            <td><span class="badge bg-marca-fondo text-marca-texto">{{ l.accion }}</span></td>
            <td>{{ l.descripcion }} <span v-if="l.modelo" class="text-xs text-marca-muted">({{ l.modelo }})</span></td>
            <td class="text-xs text-marca-muted">{{ l.ip }}</td>
          </tr>
          <tr v-if="!logs.data.length"><td colspan="5" class="text-center text-marca-muted py-8">Sin registros.</td></tr>
        </tbody>
      </table>
    </div>
    <div v-if="logs.links?.length > 3" class="flex justify-center gap-1 mt-4">
      <Link v-for="l in logs.links" :key="l.label" :href="l.url || '#'" v-html="l.label" class="px-3 py-1 rounded-lg text-sm" :class="l.active ? 'bg-carmin text-white' : 'bg-white border border-marca-borde text-marca-muted'" />
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
const props = defineProps({ logs: Object, usuarios: Array, filtros: Object })
const f = reactive({ usuario: props.filtros.usuario ?? '', accion: props.filtros.accion ?? '' })
function filtrar() { router.get('/configuracion/auditoria', f, { preserveState: true, replace: true }) }
</script>
