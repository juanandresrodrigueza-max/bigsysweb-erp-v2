<template>
  <AppLayout titulo="Pendientes">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/comprobantes" class="text-xs text-marca-muted hover:text-carmin">← Comprobantes</Link>
        <h1 class="page-title">Pendientes de entrega y facturación</h1>
        <p class="page-subtitle">Lo que se entregó sin facturar y lo que se facturó sin entregar. Ambos deberían tender a cero.</p>
      </div>
      <Link href="/comprobantes/entregas" class="btn-secondary">Hojas de reparto</Link>
    </div>

    <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1 w-fit mb-4">
      <button v-for="t in [['remitos', `Remitos sin facturar (${remitos.length})`], ['facturas', `Facturas sin entregar (${facturas.length})`]]" :key="t[0]" @click="tab = t[0]" class="px-3 py-1.5 rounded-full text-xs font-semibold" :class="tab === t[0] ? 'bg-carmin text-white' : 'text-marca-muted'">{{ t[1] }}</button>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Comprobante</th><th>Cliente</th><th>Fecha</th><th>Pendiente</th><th class="text-right">Total</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in lista" :key="c.id">
            <td><Link :href="`/comprobantes/${c.id}`" class="font-semibold hover:text-carmin">{{ c.nombre }} {{ c.numero }}</Link></td>
            <td>{{ c.cliente }}</td>
            <td class="tabular-nums text-marca-muted">{{ c.fecha }}<span v-if="c.dias > 15" class="block text-[10px] text-amber-700">{{ c.dias }} días</span></td>
            <td class="text-xs"><p v-for="i in c.items" :key="i.id">{{ cantidad(i.pendiente) }} {{ i.unidad }} {{ i.descripcion }}<span class="text-marca-muted"> de {{ cantidad(i.cantidad) }}</span></p></td>
            <td class="text-right tabular-nums">{{ moneda(c.total) }}</td>
            <td class="text-right"><button v-if="puede('comprobantes','crear')" @click="abrir(c)" class="btn-primary !py-1 text-xs">{{ tab === 'remitos' ? 'Facturar' : 'Entregar' }}</button></td>
          </tr>
          <tr v-if="!lista.length"><td colspan="6" class="text-center text-marca-muted py-10">Nada pendiente. 🎉</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="!!sel" :titulo="tab === 'remitos' ? `Facturar ${sel?.nombre} ${sel?.numero}` : `Entregar ${sel?.nombre} ${sel?.numero}`" ancho="max-w-2xl" @cerrar="sel = null">
      <p class="text-sm text-marca-muted mb-3">Indicá cuánto {{ tab === 'remitos' ? 'facturás' : 'entregás' }} ahora de cada línea. Lo que quede sigue pendiente.</p>
      <table class="table text-sm"><thead><tr><th>Artículo</th><th class="text-right">Pendiente</th><th class="text-right w-32">Ahora</th></tr></thead>
        <tbody><tr v-for="i in sel?.items ?? []" :key="i.id"><td>{{ i.descripcion }}</td><td class="text-right tabular-nums">{{ cantidad(i.pendiente) }} {{ i.unidad }}</td><td><input v-model.number="pf.items[i.id]" type="number" step="any" min="0" :max="i.pendiente" class="input !py-1 text-right" /></td></tr></tbody></table>
      <div v-if="tab === 'remitos'" class="mt-3"><label class="label">Condición</label><select v-model="pf.condicion" class="input"><option value="cta_cte">Cuenta corriente</option><option value="contado">Contado</option></select></div>
      <p v-if="pf.errors.items" class="text-carmin text-xs mt-2">{{ pf.errors.items }}</p>
      <template #pie><button class="btn-secondary" @click="sel = null">Cancelar</button><button class="btn-primary" :disabled="pf.processing" @click="pf.transform(d => ({ ...d, tipo: tab === 'remitos' ? 'FX' : 'REM' })).post(`/comprobantes/${sel.id}/parcial`)">{{ tab === 'remitos' ? 'Emitir factura' : 'Emitir remito' }}</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ remitos: Array, facturas: Array })
const { puede } = usePermisos()
const tab = ref(props.remitos.length || !props.facturas.length ? 'remitos' : 'facturas')
const lista = computed(() => tab.value === 'remitos' ? props.remitos : props.facturas)
const sel = ref(null)
const pf = useForm({ items: {}, condicion: 'cta_cte' })
function abrir(c) { pf.clearErrors(); pf.items = Object.fromEntries(c.items.map(i => [i.id, i.pendiente])); sel.value = c }
</script>
