<template>
  <AppLayout titulo="Compras">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/proveedores" class="text-xs text-marca-muted hover:text-carmin">← Proveedores</Link>
        <h1 class="page-title">Compras</h1>
        <p class="page-subtitle">Facturas de compra cargadas a mano, leídas con IA o importadas de AFIP.</p>
      </div>
      <div v-if="puede('proveedores','crear')" class="flex gap-2">
        <button @click="importarAbierto = true" class="btn-secondary">Importar de AFIP</button>
        <Link href="/proveedores/compras/nueva" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Cargar factura</Link>
      </div>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input" placeholder="Número o proveedor…" />
      <select v-model="f.estado" @change="filtrar" class="input"><option value="">Todo estado</option><option value="borrador">Borradores</option><option value="emitido">Registradas</option><option value="pendiente">Pendientes de pago</option><option value="vencido">Vencidas</option><option value="anulado">Anuladas</option></select>
      <select v-model="f.contact_id" @change="filtrar" class="input"><option value="">Todos los proveedores</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select>
      <input v-model="f.desde" @change="filtrar" type="date" class="input" /><input v-model="f.hasta" @change="filtrar" type="date" class="input" />
    </div>

    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Comprobantes</p><p class="text-xl font-extrabold tabular-nums">{{ entero(resumen.cantidad) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Total registrado</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.total, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Saldo a pagar</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.saldo > 0 ? 'text-carmin' : ''">{{ moneda(resumen.saldo, 0) }}</p></div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Comprobante</th><th>Fecha</th><th>Proveedor</th><th>Vence</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="c in lista.data" :key="c.id" class="cursor-pointer" @click="$inertia.visit(`/proveedores/compras/${c.id}`)">
            <td><div class="flex items-center gap-2"><span class="w-7 h-7 rounded-lg grid place-items-center text-xs font-extrabold" :class="c.estado === 'anulado' ? 'bg-gris-light text-marca-muted' : c.grupo === 'nc' ? 'bg-violeta text-white' : 'bg-lavanda text-violeta'">{{ c.letra }}</span><div><p class="font-semibold leading-tight">{{ c.nombre }}</p><p class="text-xs text-marca-muted tabular-nums">{{ c.numero ?? 'sin número' }} <span v-if="c.origen_carga && c.origen_carga !== 'manual'" class="badge bg-violeta-light text-violeta ml-1">{{ c.origen_carga === 'ocr' ? 'IA' : 'AFIP' }}</span></p></div></div></td>
            <td class="text-marca-muted tabular-nums">{{ c.fecha }}</td><td class="font-medium">{{ c.proveedor }}</td>
            <td class="tabular-nums" :class="c.vencido ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ c.grupo === 'factura' || c.grupo === 'nd' ? c.fecha_vto : '' }}</td>
            <td class="text-right font-semibold tabular-nums">{{ moneda(c.total) }}</td><td class="text-right tabular-nums" :class="c.saldo > 0 ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ c.estado_pago !== 'na' ? moneda(c.saldo) : '' }}</td>
            <td><span class="badge" :class="estadoComprobante[c.estado].clase">{{ c.estado === 'emitido' ? 'Registrada' : estadoComprobante[c.estado].label }}</span> <span v-if="c.estado === 'emitido' && c.estado_pago !== 'na'" class="badge ml-1" :class="estadoCobro[c.estado_pago].clase">{{ { pendiente: 'A pagar', parcial: 'Parcial', cobrado: 'Pagada' }[c.estado_pago] }}</span></td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="7" class="text-center text-marca-muted py-10">No hay compras con estos filtros.</td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />

    <Modal :abierto="importarAbierto" titulo="Importar de AFIP · Mis Comprobantes" @cerrar="importarAbierto = false">
      <p class="text-sm text-marca-muted mb-3">En AFIP entrá a <b>Mis Comprobantes → Recibidos</b>, filtrá el período y exportá el CSV. Subilo acá: se crean las facturas que falten y los proveedores nuevos por CUIT. Las que ya cargaste no se duplican.</p>
      <input type="file" accept=".csv,.txt" class="input !py-1.5 text-xs" @change="imp.archivo = $event.target.files[0]" />
      <label class="flex items-center gap-2 text-sm mt-3"><input v-model="imp.registrar" type="checkbox" class="accent-carmin" /> Registrar directamente (impacta cuenta corriente). Si no, quedan como borrador para revisar.</label>
      <p v-if="imp.errors.archivo" class="text-carmin text-xs mt-2">{{ imp.errors.archivo }}</p>
      <template #pie>
        <button class="btn-secondary" @click="importarAbierto = false">Cancelar</button>
        <button class="btn-primary" :disabled="imp.processing || !imp.archivo" @click="imp.post('/proveedores/compras/importar-afip', { forceFormData: true, onSuccess: () => (importarAbierto = false) })">{{ imp.processing ? 'Importando…' : 'Importar' }}</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda, entero, estadoCobro, estadoComprobante } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ lista: Object, resumen: Object, filtros: Object, proveedores: Array })
const { puede } = usePermisos()
const f = reactive({ estado: props.filtros.estado ?? '', contact_id: props.filtros.contact_id ?? '', desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '', buscar: props.filtros.buscar ?? '' })
function filtrar() { router.get('/proveedores/compras', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
const importarAbierto = ref(false)
const imp = useForm({ archivo: null, registrar: false })
</script>
