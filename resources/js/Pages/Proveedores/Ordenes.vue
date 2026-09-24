<template>
  <AppLayout titulo="Órdenes de compra">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/proveedores" class="text-xs text-marca-muted hover:text-carmin">← Proveedores</Link>
        <h1 class="page-title">Órdenes de compra</h1>
        <p class="page-subtitle">Lo que pediste a cada proveedor y qué falta recibir. Al recibir, la factura se arma sola.</p>
      </div>
      <div v-if="puede('proveedores','crear')" class="flex gap-2">
        <Link href="/proveedores/ordenes/nueva?sugerir=1" class="btn-secondary"><Icono nombre="sparkles" clase="w-4 h-4" /> Pedido sugerido</Link>
        <Link href="/proveedores/ordenes/nueva" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nueva orden</Link>
      </div>
    </div>

    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Abiertas</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.abiertas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Comprometido</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.monto_abierto, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Atrasadas</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.atrasadas ? 'text-carmin' : ''">{{ resumen.atrasadas }}</p></div>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-3">
      <select v-model="f.estado" @change="filtrar" class="input"><option value="">Todas</option><option value="abiertas">Abiertas</option><option v-for="(l, k) in estados" :key="k" :value="k">{{ l }}</option></select>
      <select v-model="f.contact_id" @change="filtrar" class="input"><option value="">Todos los proveedores</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Orden</th><th>Fecha</th><th>Proveedor</th><th>Entrega</th><th class="text-right">Ítems</th><th class="text-right">Total</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="o in lista.data" :key="o.id" class="cursor-pointer" @click="$inertia.visit(`/proveedores/ordenes/${o.id}`)">
            <td class="font-semibold tabular-nums">{{ o.numero }}</td>
            <td class="text-marca-muted tabular-nums">{{ o.fecha }}</td>
            <td class="font-medium">{{ o.proveedor }}</td>
            <td class="tabular-nums" :class="o.atrasada ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ o.entrega ?? '—' }}</td>
            <td class="text-right tabular-nums">{{ o.items_count }}</td>
            <td class="text-right font-semibold tabular-nums">{{ moneda(o.total) }}</td>
            <td><span class="badge" :class="claseEstado[o.estado]">{{ estados[o.estado] }}</span></td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="7" class="text-center text-marca-muted py-10">No hay órdenes con estos filtros.</td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ lista: Object, filtros: Object, proveedores: Array, estados: Object, resumen: Object })
const { puede } = usePermisos()
const f = reactive({ estado: props.filtros.estado ?? '', contact_id: props.filtros.contact_id ?? '' })
function filtrar() { router.get('/proveedores/ordenes', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
const claseEstado = { borrador: 'bg-gris-light text-marca-muted', enviada: 'bg-violeta-light text-violeta', parcial: 'bg-amber-50 text-amber-700', recibida: 'bg-emerald-50 text-emerald-700', cancelada: 'bg-carmin-light text-carmin' }
</script>
