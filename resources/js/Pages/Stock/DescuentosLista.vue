<template>
  <AppLayout titulo="Descuentos por lista">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <Link href="/stock" class="text-xs text-marca-muted hover:text-carmin">← Stock</Link>
        <h1 class="page-title">Descuentos por lista</h1>
        <p class="page-subtitle">Precio especial o % para un artículo o un rubro entero, en una lista de precios, desde una cantidad mínima y con vigencia. Vale para todos los clientes de esa lista; el precio pactado de un cliente sigue mandando.</p>
      </div>
      <select v-model="filtroLista" class="input !w-40" @change="router.get('/stock/descuentos-lista', filtroLista ? { lista: filtroLista } : {}, { preserveState: true })"><option :value="null">Todas las listas</option><option v-for="n in 6" :key="n" :value="n">Lista {{ n }}</option></select>
    </div>

    <form class="card grid sm:grid-cols-[80px_2fr_1fr_1fr_1fr_1fr_1fr_auto] gap-2 items-end mb-4" data-e2e="form-desc-lista" @submit.prevent="guardar">
      <div><label class="label">Lista</label><select v-model="form.lista" class="input"><option v-for="n in 6" :key="n" :value="n">{{ n }}</option></select></div>
      <div>
        <div class="flex gap-3 text-xs mb-1"><label class="flex items-center gap-1"><input v-model="modo" type="radio" value="articulo" class="accent-carmin" /> Artículo</label><label class="flex items-center gap-1"><input v-model="modo" type="radio" value="rubro" class="accent-carmin" /> Rubro entero</label></div>
        <BuscadorSelect v-if="modo === 'articulo'" v-model="form.product_id" :opciones="opciones" url="/buscar/articulos/venta" placeholder="Buscar artículo…" @cargados="sumar" />
        <select v-else v-model="form.rubro_id" class="input"><option :value="null">Elegí un rubro…</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.nombre }}</option></select>
      </div>
      <div><label class="label">Desde cantidad</label><input v-model.number="form.cantidad_minima" type="number" min="0" step="any" class="input text-right" placeholder="1" data-e2e="min" /></div>
      <div><label class="label">Precio neto</label><input v-model.number="form.precio" type="number" min="0" step="any" class="input text-right" placeholder="—" :disabled="modo === 'rubro'" /></div>
      <div><label class="label">Descuento %</label><input v-model.number="form.descuento" type="number" min="-100" max="100" step="any" class="input text-right" placeholder="—" data-e2e="pct" /></div>
      <div><label class="label">Desde</label><input v-model="form.vigente_desde" type="date" class="input" /></div>
      <div><label class="label">Hasta</label><input v-model="form.vigente_hasta" type="date" class="input" /></div>
      <button class="btn-primary" :disabled="form.processing || !(form.product_id || form.rubro_id)">{{ form.id ? 'Guardar' : 'Agregar' }}</button>
      <p v-if="Object.keys(form.errors).length" class="text-xs text-carmin sm:col-span-8">{{ Object.values(form.errors).join(' ') }}</p>
    </form>

    <div class="card p-0 overflow-x-auto">
      <table class="table text-sm min-w-[720px]">
        <thead><tr><th>Lista</th><th>Artículo o rubro</th><th class="text-right">Desde</th><th class="text-right">Precio lista</th><th class="text-right">Especial</th><th class="text-right">Dto %</th><th>Vigencia</th><th></th></tr></thead>
        <tbody>
          <tr v-for="r in reglas" :key="r.id" :class="r.vencida ? 'opacity-50' : ''">
            <td class="tabular-nums">{{ r.lista }}</td>
            <td><span v-if="r.articulo" class="font-medium">{{ r.articulo }} <span class="text-xs text-marca-muted">{{ r.sku }}</span></span><span v-else>Rubro <b>{{ r.rubro }}</b> <span class="text-xs text-marca-muted">(y subrubros)</span></span></td>
            <td class="text-right tabular-nums">{{ r.cantidad_minima > 0 ? cantidad(r.cantidad_minima) : '—' }}</td>
            <td class="text-right tabular-nums text-marca-muted">{{ r.precio_lista != null ? moneda(r.precio_lista) : '—' }}</td>
            <td class="text-right tabular-nums font-semibold">{{ r.precio != null ? moneda(r.precio) : '—' }}</td>
            <td class="text-right tabular-nums">{{ r.descuento != null ? r.descuento + '%' : '—' }}</td>
            <td class="text-xs">{{ vigencia(r) }}</td>
            <td class="text-right whitespace-nowrap"><button class="text-xs text-violeta" @click="editar(r)">editar</button> <button class="text-xs text-carmin ml-1" @click="router.delete(`/stock/descuentos-lista/${r.id}`, { preserveScroll: true })">quitar</button></td>
          </tr>
          <tr v-if="!reglas.length"><td colspan="8" class="text-center text-marca-muted py-8">Sin descuentos especiales. Cada lista vende a su precio.</td></tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import { moneda, cantidad } from '@/util/formato'

const props = defineProps({ reglas: Array, rubros: Array, filtros: Object })
const filtroLista = ref(props.filtros?.lista ? Number(props.filtros.lista) : null)
const modo = ref('articulo')
const vacio = () => ({ id: null, lista: filtroLista.value ?? 1, product_id: null, rubro_id: null, cantidad_minima: null, precio: null, descuento: null, vigente_desde: null, vigente_hasta: null })
const form = useForm(vacio())
const productos = ref([])
const opciones = computed(() => productos.value.map(p => ({ id: p.id, label: p.name, sub: p.sku, extra: p.precios ? moneda(p.precios[form.lista]) : null })))
function sumar(f) { const ids = new Set(productos.value.map(p => p.id)); productos.value.push(...f.filter(p => !ids.has(p.id))) }
const fmt = iso => iso ? iso.split('-').reverse().join('/') : ''
const vigencia = r => r.vigente_desde || r.vigente_hasta ? `${r.vigente_desde ? 'desde ' + fmt(r.vigente_desde) : ''} ${r.vigente_hasta ? (r.vencida ? 'venció ' : 'hasta ') + fmt(r.vigente_hasta) : ''}`.trim() : 'siempre'
function editar(r) {
  modo.value = r.product_id ? 'articulo' : 'rubro'
  if (r.product_id && !productos.value.some(p => p.id === r.product_id)) productos.value.push({ id: r.product_id, name: r.articulo, sku: r.sku })
  Object.assign(form, vacio(), { id: r.id, lista: r.lista, product_id: r.product_id, rubro_id: r.rubro_id, cantidad_minima: r.cantidad_minima || null, precio: r.precio, descuento: r.descuento, vigente_desde: r.vigente_desde, vigente_hasta: r.vigente_hasta })
}
function guardar() {
  form.transform(d => modo.value === 'articulo' ? { ...d, rubro_id: null } : { ...d, product_id: null, precio: null })
    .post(`/stock/descuentos-lista${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => { Object.assign(form, vacio()) } })
}
</script>
