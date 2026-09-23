<template>
  <AppLayout :titulo="orden.numero">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/comprobantes/entregas" class="text-xs text-marca-muted hover:text-carmin">← Hojas de reparto</Link>
        <h1 class="page-title flex items-center gap-3">{{ orden.numero }} <span class="badge text-sm" :class="{ pendiente: 'bg-gris-light text-marca-muted', en_curso: 'bg-violeta-light text-violeta', entregada: 'bg-emerald-50 text-emerald-700', cancelada: 'bg-carmin-light text-carmin' }[orden.estado]">{{ estados[orden.estado] }}</span></h1>
        <p class="page-subtitle">{{ orden.fecha }} · {{ orden.repartidor ?? 'sin repartidor' }}<span v-if="orden.vehiculo"> · {{ orden.vehiculo }}</span> · cargó {{ orden.usuario }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a :href="`/comprobantes/entregas/${orden.id}/imprimir`" target="_blank" class="btn-secondary">Imprimir hoja</a>
        <button v-if="orden.estado === 'pendiente' && puede('comprobantes','editar')" @click="estado('en_curso')" class="btn-violeta">Salió a repartir</button>
        <button v-if="['pendiente','en_curso'].includes(orden.estado) && puede('comprobantes','editar')" @click="estado('cancelada')" class="btn-ghost text-carmin">Cancelar</button>
      </div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>#</th><th>Cliente y dirección</th><th>Comprobante</th><th>Mercadería</th><th class="text-right">Cobrar</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="(i, n) in orden.items" :key="i.id">
            <td class="text-marca-muted">{{ n + 1 }}</td>
            <td><p class="font-semibold">{{ i.cliente }}</p><p class="text-xs text-marca-muted">{{ i.direccion ?? 'sin dirección' }}<span v-if="i.telefono"> · {{ i.telefono }}</span></p></td>
            <td><Link :href="`/comprobantes/${i.comprobante_id}`" class="hover:text-carmin">{{ i.comprobante }}</Link></td>
            <td class="text-xs max-w-xs">{{ i.detalle }}</td>
            <td class="text-right tabular-nums">{{ i.contado ? moneda(i.total) : '—' }}</td>
            <td>
              <div v-if="i.estado === 'pendiente' && puede('comprobantes','editar')" class="flex gap-1">
                <button @click="marcar(i, 'entregado')" class="btn-primary !py-1 text-xs">Entregado</button>
                <button @click="marcar(i, 'no_entregado')" class="btn-ghost !py-1 text-xs text-carmin">No se pudo</button>
              </div>
              <span v-else class="badge" :class="i.estado === 'entregado' ? 'bg-emerald-50 text-emerald-700' : 'bg-carmin-light text-carmin'">{{ i.estado === 'entregado' ? 'Entregado' : 'No entregado' }}<span v-if="i.observacion" class="block text-[10px] font-normal">{{ i.observacion }}</span></span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="orden.notas" class="text-sm text-marca-muted mt-3">{{ orden.notas }}</p>
  </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ orden: Object, estados: Object })
const { puede } = usePermisos()
function estado(e) { router.post(`/comprobantes/entregas/${props.orden.id}/estado`, { estado: e }, { preserveScroll: true }) }
function marcar(i, e) { const observacion = e === 'no_entregado' ? window.prompt('¿Qué pasó? (queda en la hoja)') : null; if (e === 'no_entregado' && observacion === null) return; router.post(`/comprobantes/entregas/items/${i.id}`, { estado: e, observacion }, { preserveScroll: true }) }
</script>
