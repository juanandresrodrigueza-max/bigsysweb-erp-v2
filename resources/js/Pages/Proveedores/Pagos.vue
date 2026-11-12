<template>
  <AppLayout titulo="Pagos a proveedores">
    <PageHeader titulo="Pagos" :principal="puede('proveedores','crear') ? { label: 'Registrar pago', icono: 'cash', click: () => elegirAbierto = true } : null">
      <template #subtitulo>{{ resumen.cantidad }} {{ resumen.cantidad === 1 ? 'orden' : 'órdenes' }} de pago · {{ moneda(resumen.total, 0) }}<span v-if="f.desde || f.hasta || f.contact_id"> con estos filtros</span></template>
    </PageHeader>

    <div class="card mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input" placeholder="Buscar por número o proveedor…" />
      <select v-model="f.contact_id" @change="filtrar" class="input"><option value="">Todos los proveedores</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select>
      <input v-model="f.desde" type="date" @change="filtrar" class="input" /><input v-model="f.hasta" type="date" @change="filtrar" class="input" />
      <select v-model="f.estado" @change="filtrar" class="input"><option value="">Vigentes</option><option value="anulado">Anulados</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Fecha</th><th>N°</th><th>Proveedor</th><th>Medios</th><th class="text-right">Total</th><th class="text-right">A cuenta</th><th></th></tr></thead>
        <tbody>
          <tr v-for="p in lista.data" :key="p.id" :class="p.estado === 'anulado' ? 'opacity-50' : ''">
            <td class="whitespace-nowrap">{{ fecha(p.fecha) }}</td>
            <td class="font-mono text-xs">{{ p.numero }}</td>
            <td><Link :href="`/proveedores/${p.contact_id}`" class="font-semibold hover:text-carmin">{{ p.proveedor }}</Link><p v-if="p.notas" class="text-xs text-marca-muted truncate max-w-xs">{{ p.notas }}</p></td>
            <td class="text-xs text-marca-muted">{{ p.medios.join(' · ') }}</td>
            <td class="text-right tabular-nums font-semibold">{{ moneda(p.total) }}</td>
            <td class="text-right tabular-nums text-marca-muted">{{ p.a_cuenta ? moneda(p.a_cuenta) : '—' }}</td>
            <td class="text-right whitespace-nowrap"><span v-if="p.estado === 'anulado'" class="badge bg-gris-light text-marca-muted mr-2">anulado</span><a :href="`/proveedores/pagos/${p.id}/imprimir`" target="_blank" class="text-xs font-semibold text-marca-muted hover:text-carmin">Imprimir</a></td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="7" class="!py-0"><EstadoVacio texto="Todavía no hay pagos a proveedores. Se registran desde la ficha del proveedor o con Registrar pago." :filtros="f" :accion="puede('proveedores','crear') ? { label: 'Registrar pago', click: () => elegirAbierto = true } : null" /></td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />

    <Modal :abierto="elegirAbierto" titulo="¿A qué proveedor le pagás?" @cerrar="elegirAbierto = false">
      <div class="space-y-3 text-sm">
        <p class="text-marca-muted">El pago se registra en la ficha del proveedor, donde ves qué facturas le debés e imputás lo que corresponde.</p>
        <select v-model="elegido" class="input" data-e2e="pago-proveedor"><option :value="null">Elegí el proveedor…</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}<template v-if="p.balance > 0"> · debés {{ moneda(p.balance, 0) }}</template></option></select>
      </div>
      <template #pie><button class="btn-secondary" @click="elegirAbierto = false">Cancelar</button><Link :href="elegido ? `/proveedores/${elegido}?pago=1` : '#'" class="btn-primary" :class="{ 'opacity-50 pointer-events-none': !elegido }">Ir a registrar el pago</Link></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import Modal from '@/Components/Modal.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda } from '@/util/formato'
const fecha = d => d ? d.split('-').reverse().join('/') : ''
import { usePermisos } from '@/util/permisos'
const props = defineProps({ lista: Object, resumen: Object, filtros: Object, proveedores: Array })
const { puede } = usePermisos()
const f = reactive({ buscar: props.filtros.buscar ?? '', contact_id: props.filtros.contact_id ?? '', desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '', estado: props.filtros.estado ?? '' })
const elegirAbierto = ref(false), elegido = ref(null)
function filtrar() { router.get('/proveedores/pagos', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
</script>
