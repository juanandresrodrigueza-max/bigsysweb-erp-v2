<template>
  <AppLayout titulo="Inventario">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><Link href="/stock" class="text-xs text-marca-muted hover:text-carmin">← Stock</Link><h1 class="page-title">Inventario físico</h1><p class="page-subtitle">Contá lo que hay en el depósito; lo que difiere del sistema se ajusta al cerrar.</p></div>
      <div class="flex gap-2">
        <select :value="depositoId" @change="cambiar({ deposito: $event.target.value })" class="input w-auto"><option v-for="d in depositos.filter(x => x.activo)" :key="d.id" :value="d.id">{{ d.nombre }} · {{ d.sucursal }}</option></select>
        <select :value="filtros.rubro ?? ''" @change="cambiar({ rubro: $event.target.value })" class="input w-auto"><option value="">Todos los rubros</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.completo }}</option></select>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 card p-0 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-marca-borde">
          <p class="text-sm"><b>{{ contados }}</b> de {{ items.length }} contados · <b :class="conDiferencia ? 'text-amber-600' : ''">{{ conDiferencia }}</b> con diferencia · <b class="tabular-nums" :class="valorDif < 0 ? 'text-carmin' : valorDif > 0 ? 'text-emerald-700' : ''">{{ moneda(valorDif, 0) }}</b></p>
          <div class="flex gap-2"><button @click="items.forEach(i => (conteos[i.id] = i.sistema))" class="btn-ghost !py-1 text-xs">Copiar sistema</button><button @click="Object.keys(conteos).forEach(k => (conteos[k] = ''))" class="btn-ghost !py-1 text-xs">Limpiar</button></div>
        </div>
        <div class="overflow-x-auto max-h-[60vh]">
          <table class="table">
            <thead class="sticky top-0"><tr><th>Artículo</th><th>Rubro</th><th class="text-right">Sistema</th><th class="text-right w-32">Contado</th><th class="text-right">Diferencia</th></tr></thead>
            <tbody>
              <tr v-for="it in items" :key="it.id">
                <td><p class="font-medium">{{ it.name }}</p><p class="text-xs text-marca-muted">{{ it.sku }}</p></td>
                <td class="text-xs text-marca-muted">{{ it.rubro ?? '—' }}</td>
                <td class="text-right tabular-nums text-marca-muted">{{ cantidad(it.sistema) }} {{ it.unit }}</td>
                <td><input v-model="conteos[it.id]" type="number" step="any" min="0" class="input text-right !py-1" placeholder="—" @keydown.enter.prevent="siguiente($event)" /></td>
                <td class="text-right tabular-nums font-semibold" :class="dif(it) === null ? 'text-marca-muted' : dif(it) < 0 ? 'text-carmin' : dif(it) > 0 ? 'text-emerald-700' : ''">{{ dif(it) === null ? '' : (dif(it) > 0 ? '+' : '') + cantidad(dif(it)) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="px-4 py-3 border-t border-marca-borde flex flex-wrap items-center gap-3">
          <input v-model="form.fecha" type="date" class="input w-auto" /><input v-model="form.notas" class="input flex-1 min-w-[160px]" placeholder="Notas (quién contó, observaciones)" />
          <button class="btn-primary" :disabled="form.processing || !contados" @click="cerrar">Cerrar inventario ({{ contados }})</button>
        </div>
        <p v-if="form.errors.conteos" class="text-carmin text-xs px-4 pb-3">{{ form.errors.conteos }}</p>
      </div>
      <div class="card">
        <h2 class="font-bold mb-2">Inventarios anteriores</h2>
        <button v-for="h in historial" :key="h.id" @click="ver(h.id)" class="w-full text-left py-2 border-t border-marca-borde/60 first:border-0 text-sm hover:text-carmin">
          <div class="flex justify-between"><span class="font-semibold tabular-nums">{{ h.numero }}</span><span class="text-marca-muted">{{ h.fecha }}</span></div>
          <p class="text-xs text-marca-muted">{{ h.deposito }} · {{ h.usuario }} · {{ h.contados }} contados, {{ h.difs }} ajustados · <span :class="h.valor < 0 ? 'text-carmin' : ''">{{ moneda(h.valor, 0) }}</span></p>
        </button>
        <p v-if="!historial.length" class="text-sm text-marca-muted">Todavía no se hizo ningún inventario.</p>
      </div>
    </div>

    <Modal :abierto="!!detalle" :titulo="detalle ? `${detalle.numero} · ${detalle.deposito}` : ''" ancho="max-w-2xl" @cerrar="detalle = null">
      <template v-if="detalle">
        <p class="text-sm text-marca-muted mb-3">{{ detalle.fecha }} · {{ detalle.usuario }}<span v-if="detalle.notas"> · {{ detalle.notas }}</span></p>
        <table class="table"><thead><tr><th>Artículo</th><th class="text-right">Sistema</th><th class="text-right">Contado</th><th class="text-right">Dif.</th><th class="text-right">Valor</th></tr></thead>
          <tbody><tr v-for="(i, k) in detalle.items" :key="k" :class="i.diferencia ? 'font-semibold' : 'text-marca-muted'"><td>{{ i.articulo }}</td><td class="text-right tabular-nums">{{ cantidad(i.sistema) }}</td><td class="text-right tabular-nums">{{ cantidad(i.contado) }}</td><td class="text-right tabular-nums" :class="i.diferencia < 0 ? 'text-carmin' : i.diferencia > 0 ? 'text-emerald-700' : ''">{{ i.diferencia ? (i.diferencia > 0 ? '+' : '') + cantidad(i.diferencia) : '—' }}</td><td class="text-right tabular-nums">{{ i.diferencia ? moneda(i.valor, 0) : '' }}</td></tr></tbody></table>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad, hoyISO } from '@/util/formato'
const props = defineProps({ items: Array, depositoId: Number, depositos: Array, rubros: Array, filtros: Object, historial: Array })
const conteos = reactive({})
const form = useForm({ deposito_id: props.depositoId, fecha: hoyISO(), notas: '', conteos: {} })
const dif = it => conteos[it.id] === undefined || conteos[it.id] === '' || conteos[it.id] === null ? null : Math.round((Number(conteos[it.id]) - it.sistema) * 1000) / 1000
const contados = computed(() => props.items.filter(i => dif(i) !== null).length)
const conDiferencia = computed(() => props.items.filter(i => dif(i) !== null && dif(i) !== 0).length)
const valorDif = computed(() => props.items.reduce((a, i) => a + (dif(i) ?? 0) * i.cost, 0))
function cambiar(q) { router.get('/stock/inventario', { deposito: props.depositoId, rubro: props.filtros.rubro, ...q }, { preserveState: false }) }
function siguiente(e) { const inputs = [...document.querySelectorAll('tbody input')]; const i = inputs.indexOf(e.target); inputs[i + 1]?.focus() }
function cerrar() { form.conteos = Object.fromEntries(Object.entries(conteos).filter(([, v]) => v !== '' && v !== null)); form.post('/stock/inventario', { preserveScroll: true, onSuccess: () => Object.keys(conteos).forEach(k => (conteos[k] = '')) }) }
const detalle = ref(null)
async function ver(id) { detalle.value = (await window.axios.get(`/stock/inventario/${id}`)).data }
</script>
