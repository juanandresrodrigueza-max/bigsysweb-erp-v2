<template>
  <AppLayout titulo="Proveedores">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <h1 class="page-title">Proveedores</h1>
        <p class="page-subtitle">{{ entero(totales.proveedores) }} proveedores · por pagar {{ moneda(totales.por_pagar, 0) }} <span v-if="totales.vencido > 0" class="text-carmin font-semibold">· vencido {{ moneda(totales.vencido, 0) }}</span></p>
      </div>
      <div class="flex gap-2">
        <Link href="/proveedores/ordenes" class="btn-secondary">Órdenes de compra</Link>
        <Link href="/proveedores/compras" class="btn-secondary">Compras</Link>
        <Link v-if="puede('proveedores','crear')" href="/proveedores/compras/nueva" class="btn-secondary">Cargar factura</Link>
        <button v-if="puede('proveedores','crear')" @click="editar(null)" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nuevo proveedor</button>
      </div>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-3">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input sm:col-span-2" placeholder="Buscar por nombre, CUIT o email…" />
      <select v-model="f.estado" @change="filtrar" class="input"><option value="activos">Activos</option><option value="deudores">Con saldo a pagar</option><option value="inactivos">Inactivos</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Proveedor</th><th>Condición IVA</th><th>Plazo</th><th>Contacto</th><th class="text-right">A pagar</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in lista.data" :key="c.id" class="cursor-pointer" @click="$inertia.visit(`/proveedores/${c.id}`)">
            <td><p class="font-semibold">{{ c.name }}</p><p class="text-xs text-marca-muted">{{ c.cuit ?? 'sin CUIT' }}<span v-if="c.city"> · {{ c.city }}</span></p></td>
            <td class="text-marca-muted">{{ c.condicion_iva }}</td>
            <td class="text-marca-muted">{{ c.dias_pago ? c.dias_pago + ' días' : 'Contado' }}</td>
            <td class="text-marca-muted text-xs">{{ c.phone }}<br>{{ c.email }}</td>
            <td class="text-right tabular-nums font-semibold" :class="c.balance > 0 ? 'text-carmin' : c.balance < 0 ? 'text-emerald-700' : 'text-marca-muted'">{{ moneda(c.balance) }}</td>
            <td class="text-right"><button @click.stop="editar(c)" class="btn-ghost !px-2 text-xs"><Icono nombre="edit" clase="w-4 h-4" /></button></td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="6" class="text-center text-marca-muted py-10">No hay proveedores con estos filtros.</td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />

    <ProveedorModal :abierto="modal" :proveedor="seleccionado" :condicionesIva="condicionesIva" @cerrar="modal = false" />
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Paginacion from '@/Components/Paginacion.vue'
import ProveedorModal from '@/Components/ProveedorModal.vue'
import { moneda, entero } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ lista: Object, totales: Object, filtros: Object, condicionesIva: Array })
const { puede } = usePermisos()
const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? 'activos' })
function filtrar() { router.get('/proveedores', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
const modal = ref(false), seleccionado = ref(null)
function editar(c) { seleccionado.value = c; modal.value = true }
</script>
