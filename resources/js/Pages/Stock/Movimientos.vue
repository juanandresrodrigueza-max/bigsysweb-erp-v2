<template>
  <AppLayout titulo="Movimientos de stock">
    <div class="mb-5"><Link href="/stock" class="text-xs text-marca-muted hover:text-carmin">← Stock</Link><h1 class="page-title">Movimientos de stock</h1><p class="page-subtitle">Todo lo que entró y salió, con el comprobante u orden que lo originó.</p></div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input lg:col-span-2" placeholder="Artículo…" />
      <select v-model="f.deposito" @change="filtrar" class="input"><option value="">Todos los depósitos</option><option v-for="d in depositos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select>
      <select v-model="f.tipo" @change="filtrar" class="input"><option value="">Todos los tipos</option><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l }}</option></select>
      <input v-model="f.desde" @change="filtrar" type="date" class="input" /><input v-model="f.hasta" @change="filtrar" type="date" class="input" />
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 card p-0 overflow-x-auto">
        <table class="table">
          <thead><tr><th>Fecha</th><th>Artículo</th><th>Movimiento</th><th>Depósito</th><th class="text-right">Cant.</th><th class="text-right">Quedó</th><th>Usuario</th></tr></thead>
          <tbody>
            <tr v-for="m in movimientos.data" :key="m.id">
              <td class="tabular-nums text-marca-muted whitespace-nowrap">{{ m.fecha }}</td>
              <td><Link :href="`/stock/${m.product_id}`" class="font-medium hover:text-carmin">{{ m.articulo }}</Link><p class="text-xs text-marca-muted">{{ m.sku }}</p></td>
              <td><component :is="m.url ? Link : 'span'" :href="m.url" class="text-sm" :class="m.url ? 'hover:text-carmin' : ''">{{ m.motivo }}</component><br /><span class="badge" :class="{ in: 'bg-emerald-50 text-emerald-700', out: 'bg-carmin-light text-carmin', ajuste: 'bg-amber-50 text-amber-700', transferencia: 'bg-lavanda-light text-violeta', inventario: 'bg-amber-50 text-amber-700', produccion: 'bg-violeta-light text-violeta' }[m.tipo]">{{ m.tipo_label }}</span></td>
              <td class="text-xs text-marca-muted">{{ m.deposito ?? '—' }}</td>
              <td class="text-right tabular-nums font-semibold" :class="m.entrada ? 'text-emerald-700' : 'text-carmin'">{{ m.entrada ? '+' : '−' }}{{ cantidad(m.cantidad) }} {{ m.unit }}</td>
              <td class="text-right tabular-nums text-marca-muted">{{ cantidad(m.despues) }}</td>
              <td class="text-xs text-marca-muted">{{ m.usuario }}</td>
            </tr>
            <tr v-if="!movimientos.data.length"><td colspan="7" class="text-center text-marca-muted py-10">Sin movimientos con estos filtros.</td></tr>
          </tbody>
        </table>
        <div class="px-4"><Paginacion :links="movimientos.links" :desde="movimientos.from" :hasta="movimientos.to" :total="movimientos.total" /></div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-2">Últimas transferencias</h2>
        <div v-for="t in transferencias" :key="t.id" class="py-2 border-t border-marca-borde/60 first:border-0 text-sm" :class="t.estado === 'anulada' ? 'opacity-50' : ''">
          <div class="flex items-center justify-between"><span class="font-semibold tabular-nums">{{ t.numero }} <span class="text-marca-muted font-normal">· {{ t.fecha }}</span></span><span v-if="t.estado === 'anulada'" class="badge bg-gris-light text-marca-muted">Anulada</span><button v-else-if="puede('stock','anular')" @click="anular(t)" class="text-xs text-carmin">anular</button></div>
          <p class="text-xs">{{ t.origen }} → {{ t.destino }}</p>
          <p class="text-xs text-marca-muted">{{ t.items }}</p>
        </div>
        <p v-if="!transferencias.length" class="text-sm text-marca-muted">Todavía no hubo transferencias.</p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { cantidad } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ movimientos: Object, filtros: Object, depositos: Array, tipos: Object, transferencias: Array })
const { puede } = usePermisos()
const f = reactive({ buscar: props.filtros.buscar ?? '', deposito: props.filtros.deposito ?? '', tipo: props.filtros.tipo ?? '', desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '' })
function filtrar() { router.get('/stock/movimientos', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
function anular(t) { const motivo = window.prompt(`Motivo para anular ${t.numero}:`); if (motivo) router.post(`/stock/transferencias/${t.id}/anular`, { motivo }, { preserveScroll: true }) }
</script>
