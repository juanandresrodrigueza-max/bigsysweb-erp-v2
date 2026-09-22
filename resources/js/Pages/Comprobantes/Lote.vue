<template>
  <AppLayout titulo="Facturación por lote">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <h1 class="page-title">Facturación por lote</h1>
        <p class="page-subtitle">Elegí presupuestos o remitos emitidos y facturalos todos juntos.</p>
      </div>
      <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1">
        <button v-for="o in [['presupuestos','Presupuestos'],['remitos','Remitos']]" :key="o[0]" @click="$inertia.visit(`/comprobantes/lote?origen=${o[0]}`)" class="px-3 py-1.5 rounded-full text-xs font-semibold" :class="origen === o[0] ? 'bg-carmin text-white' : 'text-marca-muted'">{{ o[1] }}</button>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 card p-0 overflow-x-auto">
        <table class="table">
          <thead><tr><th class="w-8"><input type="checkbox" :checked="todos" @change="toggleTodos" class="accent-carmin" /></th><th>Comprobante</th><th>Cliente</th><th>Fecha</th><th class="text-right">Total</th></tr></thead>
          <tbody>
            <tr v-for="p in pendientes" :key="p.id" class="cursor-pointer" @click="toggle(p.id)">
              <td><input type="checkbox" :checked="form.ids.includes(p.id)" @click.stop @change="toggle(p.id)" class="accent-carmin" /></td>
              <td class="font-semibold tabular-nums">{{ p.numero }}</td>
              <td>{{ p.cliente }}</td>
              <td class="text-marca-muted">{{ p.fecha }}</td>
              <td class="text-right tabular-nums font-semibold">{{ moneda(p.total) }}</td>
            </tr>
            <tr v-if="!pendientes.length"><td colspan="5" class="text-center text-marca-muted py-10">No hay {{ origen }} pendientes de facturar.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="space-y-4">
        <div class="card sticky top-20">
          <h2 class="font-bold mb-2">Seleccionados</h2>
          <p class="text-3xl font-extrabold tabular-nums">{{ form.ids.length }}</p>
          <p class="text-sm text-marca-muted">Total {{ moneda(totalSel, 0) }}</p>
          <label class="label mt-4">Condición de las facturas</label>
          <select v-model="form.condicion" class="input"><option value="">La de cada comprobante</option><option value="cta_cte">Cuenta corriente</option><option value="contado">Contado</option></select>
          <button class="btn-primary w-full mt-4" :disabled="!form.ids.length || form.processing" @click="form.post('/comprobantes/lote', { onSuccess: () => (form.ids = []) })">{{ form.processing ? 'Facturando…' : `Facturar ${form.ids.length}` }}</button>
          <p class="text-[11px] text-marca-muted mt-2">Cada factura se numera y emite por separado; si una falla, las demás siguen.</p>
        </div>
      </div>
    </div>

    <div v-if="ultimos.length" class="card mt-4">
      <h2 class="font-bold mb-3">Últimos lotes</h2>
      <details v-for="l in ultimos" :key="l.id" class="border-t border-marca-borde/60 first:border-0 py-2">
        <summary class="cursor-pointer text-sm flex justify-between"><span>{{ l.fecha }} · {{ l.origen }} · {{ l.cantidad }} comprobantes</span><span><span class="badge bg-emerald-50 text-emerald-700">{{ l.emitidos }} ok</span> <span v-if="l.con_error" class="badge bg-carmin-light text-carmin">{{ l.con_error }} error</span></span></summary>
        <div class="mt-2 text-xs space-y-1">
          <div v-for="(d, i) in l.detalle" :key="i" class="flex justify-between gap-2"><span>{{ d.origen }} · {{ d.cliente }}</span><Link v-if="d.ok" :href="`/comprobantes/${d.id}`" class="text-emerald-700 font-semibold">{{ d.resultado }}</Link><span v-else class="text-carmin">{{ d.resultado }}</span></div>
        </div>
      </details>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'

const props = defineProps({ origen: String, pendientes: Array, ultimos: Array })
const form = useForm({ ids: [], origen: props.origen, condicion: '' })
const todos = computed(() => props.pendientes.length > 0 && form.ids.length === props.pendientes.length)
const totalSel = computed(() => props.pendientes.filter(p => form.ids.includes(p.id)).reduce((a, p) => a + p.total, 0))
function toggle(id) { form.ids = form.ids.includes(id) ? form.ids.filter(x => x !== id) : [...form.ids, id] }
function toggleTodos() { form.ids = todos.value ? [] : props.pendientes.map(p => p.id) }
</script>
