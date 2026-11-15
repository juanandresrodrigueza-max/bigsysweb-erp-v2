<template>
  <AppLayout titulo="Importaciones">
    <!-- Fase 97: despachos de importación con el costo nacionalizado. -->
    <PageHeader titulo="Importaciones" :principal="puede('proveedores','crear') ? { label: 'Nuevo despacho', href: '/proveedores/importaciones/nueva' } : null" :volver="{ href: '/proveedores', label: 'Comprar' }">
      <template #subtitulo>Cada despacho trae la mercadería del exterior con su FOB y los gastos de nacionalizar. El sistema prorratea los gastos por valor y calcula el costo real por unidad, que entra al stock.</template>
    </PageHeader>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En curso</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.en_curso }}</p><p class="text-[11px] text-marca-muted">FOB {{ moneda(resumen.fob_en_curso, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Nacionalizados este año</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.nacionalizados_anio }}</p></div>
      <div class="card py-3 col-span-2"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Costo nacionalizado este año</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.costo_anio, 0) }}</p><p class="text-[11px] text-marca-muted">FOB en pesos más flete, seguro, derechos, tasas y despachante. El IVA y las percepciones no van al costo.</p></div>
    </div>

    <div class="card mb-4 flex flex-wrap gap-2 items-center">
      <select v-model="f.estado" @change="filtrar" class="input !w-48"><option value="">Todos</option><option v-for="(l, k) in estados" :key="k" :value="k">{{ l }}</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Despacho</th><th>Fecha</th><th>Proveedor</th><th class="text-right">FOB</th><th class="text-right">Gastos</th><th class="text-right">Costo total</th><th class="text-right">Ítems</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="d in lista.data" :key="d.id" class="cursor-pointer" @click="$inertia.visit(`/proveedores/importaciones/${d.id}`)" :data-despacho="d.id">
            <td><p class="font-semibold">{{ d.numero }}</p><p v-if="d.despacho" class="text-xs text-marca-muted">{{ d.despacho }}</p></td>
            <td class="tabular-nums">{{ d.fecha }}</td>
            <td>{{ d.proveedor ?? '—' }}</td>
            <td class="text-right tabular-nums">{{ moneda(d.fob_total, 0) }}</td>
            <td class="text-right tabular-nums text-marca-muted">{{ moneda(d.gastos_total, 0) }}</td>
            <td class="text-right tabular-nums font-semibold">{{ moneda(d.costo_total, 0) }}</td>
            <td class="text-right tabular-nums">{{ d.items }}</td>
            <td><span class="badge" :class="{ borrador: 'bg-amber-50 text-amber-700', nacionalizado: 'bg-emerald-50 text-emerald-700', anulado: 'bg-gris-light text-marca-muted' }[d.estado]">{{ d.estado_label }}</span></td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="8" class="!py-0"><EstadoVacio texto="Todavía no hay despachos. Con el primero, cargás los artículos con su FOB, los gastos del despacho y el sistema te dice el costo real de cada unidad." /></td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import Paginacion from '@/Components/Paginacion.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const { puede } = usePermisos()
const props = defineProps({ lista: Object, filtros: Object, estados: Object, resumen: Object })
const f = reactive({ estado: props.filtros.estado ?? '' })
function filtrar() { router.get('/proveedores/importaciones', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
</script>
