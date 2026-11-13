<template>
  <AppLayout titulo="Qué reponer">
    <PageHeader titulo="Qué reponer" :subtitulo="total ? `${total} artículo${total === 1 ? '' : 's'} en el mínimo o por debajo, agrupados por proveedor habitual.` : 'Lo que está en el mínimo o por debajo, agrupado por proveedor habitual.'"
      :principal="puede('proveedores','crear') ? { label: 'Nueva orden', href: '/proveedores/ordenes/nueva?sugerir=1' } : null" />

    <div v-if="!grupos.length" class="card !py-0"><EstadoVacio texto="Nada que reponer: todo está por encima del mínimo. El mínimo se define en cada artículo (Stock → ficha → Controla stock)." icono="boxes" /></div>

    <div v-else class="space-y-4">
      <p v-if="sinProveedor" class="text-xs text-marca-muted">{{ sinProveedor }} sin proveedor habitual: asignalo en la ficha del artículo para que caigan en su orden.</p>
      <div v-for="g in grupos" :key="g.proveedor_id ?? 0" class="card p-0 overflow-hidden" :data-e2e="`reponer-${g.proveedor_id ?? 'sin'}`">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-marca-borde">
          <div><p class="font-bold">{{ g.proveedor ?? 'Sin proveedor habitual' }}</p><p class="text-xs text-marca-muted">{{ g.items.length }} artículo{{ g.items.length === 1 ? '' : 's' }} · estimado {{ moneda(g.estimado, 0) }} a costo</p></div>
          <Link v-if="puede('proveedores','crear')" :href="g.proveedor_id ? `/proveedores/ordenes/nueva?contact_id=${g.proveedor_id}&sugerir=1` : '/proveedores/ordenes/nueva?sugerir=1'" class="btn-secondary !py-1 text-xs">Armar orden</Link>
        </div>
        <div class="overflow-x-auto"><table class="table">
          <thead><tr><th>Artículo</th><th class="text-right">Stock</th><th class="text-right">Mínimo</th><th class="text-right">Sugerido</th><th class="text-right">Costo</th></tr></thead>
          <tbody>
            <tr v-for="i in g.items" :key="i.id" class="cursor-pointer" @click="$inertia.visit(`/stock/${i.id}`)">
              <td><p class="font-semibold">{{ i.nombre }}</p><p class="text-xs text-marca-muted">{{ i.sku }}</p></td>
              <td class="text-right tabular-nums" :class="i.stock <= 0 ? 'text-carmin font-bold' : 'text-amber-600 font-semibold'">{{ cantidad(i.stock) }} {{ i.unidad }}</td>
              <td class="text-right tabular-nums text-marca-muted">{{ i.stock_min ? cantidad(i.stock_min) : '—' }}</td>
              <td class="text-right tabular-nums font-semibold">{{ i.sugerido !== null ? cantidad(i.sugerido) : '—' }}<p v-if="i.sugerido === null" class="text-[11px] font-normal text-marca-muted">sin mínimo</p></td>
              <td class="text-right tabular-nums text-marca-muted">{{ moneda(i.costo) }}</td>
            </tr>
          </tbody>
        </table></div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import { moneda, cantidad } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
defineProps({ grupos: Array, total: Number, sinProveedor: Number })
const { puede } = usePermisos()
</script>
