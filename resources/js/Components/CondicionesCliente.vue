<template>
  <div class="card p-0 overflow-hidden" data-condiciones>
    <div class="px-4 py-3 border-b border-marca-borde">
      <h2 class="font-bold">Precios pactados y descuentos</h2>
      <p class="text-xs text-marca-muted mt-0.5">Al facturar manda el precio pactado del artículo, después el descuento del rubro y por último la lista {{ lista }} con {{ descuento }}% de descuento del cliente.</p>
    </div>
    <form @submit.prevent="guardar" class="grid sm:grid-cols-[2fr_1fr_1fr_1fr_1fr_auto] gap-2 p-4 border-b border-marca-borde items-end">
      <div>
        <div class="flex gap-3 text-xs mb-1"><label class="flex items-center gap-1"><input type="radio" v-model="modo" value="articulo" class="accent-carmin" /> Artículo</label><label class="flex items-center gap-1"><input type="radio" v-model="modo" value="rubro" class="accent-carmin" /> Rubro entero</label></div>
        <BuscadorSelect v-if="modo === 'articulo'" v-model="form.product_id" :opciones="opciones" url="/buscar/articulos/venta" @cargados="f => sumar(f)" placeholder="Buscar artículo…" />
        <select v-else v-model="form.rubro_id" class="input" data-rubro><option :value="null">Elegí un rubro…</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.nombre }}</option></select>
      </div>
      <div v-if="modo === 'articulo'"><label class="label">Precio neto fijo</label><input v-model.number="form.precio" type="number" min="0" step="any" class="input text-right" placeholder="—" data-precio-pactado /></div>
      <div v-else></div>
      <div><label class="label">Descuento %</label><input v-model.number="form.descuento" type="number" min="0" max="100" step="any" class="input text-right" placeholder="—" data-descuento-pactado /></div>
      <div class="sm:col-span-2"><label class="label">Vigente hasta (opcional)</label><input v-model="form.vigente_hasta" type="date" class="input" /></div>
      <button class="btn-primary" :disabled="form.processing || !(form.product_id || form.rubro_id)">Guardar</button>
      <p v-if="form.errors.precio || form.errors.descuento || form.errors.product_id || form.errors.rubro_id" class="text-xs text-carmin sm:col-span-6">{{ form.errors.precio || form.errors.descuento || form.errors.product_id || form.errors.rubro_id }}</p>
    </form>
    <div class="overflow-x-auto">
      <table class="table min-w-[640px]">
        <thead><tr><th>Artículo o rubro</th><th class="text-right">Lista</th><th class="text-right">Pactado</th><th class="text-right">Dto %</th><th>Origen</th><th>Vigencia</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in condiciones" :key="c.id" :class="c.vencida ? 'opacity-50' : ''">
            <td><span v-if="c.articulo" class="font-medium">{{ c.articulo }} <span class="text-marca-muted text-xs">{{ c.sku }}</span></span><span v-else>Rubro <b>{{ c.rubro }}</b> <span class="text-xs text-marca-muted">(y subrubros)</span></span></td>
            <td class="text-right tabular-nums text-marca-muted">{{ c.precio_lista != null ? moneda(c.precio_lista) : '—' }}</td>
            <td class="text-right tabular-nums font-semibold">{{ c.precio != null ? moneda(c.precio) : '—' }}</td>
            <td class="text-right tabular-nums">{{ c.descuento != null ? c.descuento + '%' : '—' }}</td>
            <td><span class="badge" :class="c.origen === 'ultimo' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'">{{ c.origen === 'ultimo' ? 'último facturado' : 'pactado' }}</span></td>
            <td class="text-xs">{{ c.vigente_hasta ? (c.vencida ? 'venció ' : 'hasta ') + c.vigente_hasta.split('-').reverse().join('/') : 'sin vencimiento' }}</td>
            <td class="text-right"><button type="button" @click="borrar(c)" class="text-marca-muted hover:text-carmin" title="Eliminar"><Icono nombre="trash" clase="w-4 h-4" /></button></td>
          </tr>
          <tr v-if="!condiciones.length"><td colspan="7" class="text-center text-marca-muted py-8">Sin condiciones especiales. Este cliente paga su lista.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import Icono from '@/Components/Icono.vue'
import { moneda } from '@/util/formato'

const props = defineProps({ clienteId: Number, lista: Number, descuento: Number, rubros: { type: Array, default: () => [] } })
const condiciones = ref([])
const productos = ref([])
const modo = ref('articulo')
const form = useForm({ product_id: null, rubro_id: null, precio: null, descuento: null, vigente_hasta: null })
const opciones = computed(() => productos.value.map(p => ({ id: p.id, label: p.name, sub: p.sku, extra: p.precios ? moneda(p.precios[props.lista]) : null })))
function sumar(f) { const ids = new Set(productos.value.map(p => p.id)); productos.value.push(...f.filter(p => !ids.has(p.id))) }
async function cargar() { try { const r = await fetch(`/clientes/${props.clienteId}/condiciones`, { headers: { Accept: 'application/json' } }); if (r.ok) condiciones.value = (await r.json()).condiciones } catch (e) {} }
function guardar() {
  form.transform(d => modo.value === 'articulo' ? { ...d, rubro_id: null } : { ...d, product_id: null, precio: null })
    .post(`/clientes/${props.clienteId}/condiciones`, { preserveScroll: true, onSuccess: () => { form.reset(); cargar() } })
}
function borrar(c) { router.delete(`/clientes/${props.clienteId}/condiciones/${c.id}`, { preserveScroll: true, onSuccess: cargar }) }
onMounted(cargar)
</script>
