<template>
  <Modal :abierto="abierto" :titulo="form.id ? `Editar ${form.name}` : 'Nuevo artículo'" ancho="max-w-3xl" @cerrar="$emit('cerrar')">
    <div class="grid sm:grid-cols-3 gap-4">
      <div class="sm:col-span-2"><label class="label">Nombre</label><input v-model="form.name" class="input" placeholder="Ej: Cemento x 50 kg" /><p v-if="form.errors.name" class="text-carmin text-xs mt-1">{{ form.errors.name }}</p></div>
      <div><label class="label">Código (SKU)</label><input v-model="form.sku" class="input tabular-nums" placeholder="Se genera solo" /><p v-if="form.errors.sku" class="text-carmin text-xs mt-1">{{ form.errors.sku }}</p></div>
      <div><label class="label">Tipo</label><select v-model="form.tipo" class="input"><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l.split(' (')[0] }}</option></select><p class="text-[11px] text-marca-muted mt-1">{{ tipos[form.tipo]?.match(/\((.*)\)/)?.[1] }}</p></div>
      <div><label class="label">Rubro</label><select v-model="form.rubro_id" class="input"><option :value="null">Sin rubro</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.completo }}</option></select></div>
      <div><label class="label">Unidad</label><select v-model="form.unit" class="input"><option v-for="(l, k) in unidades" :key="k" :value="k">{{ l }}</option></select></div>
      <div><label class="label">Código de barras</label><input v-model="form.barcode" class="input tabular-nums" /></div>
      <div><label class="label">Marca</label><input v-model="form.marca" class="input" /></div>
      <div><label class="label">Proveedor habitual</label><select v-model="form.proveedor_id" class="input"><option :value="null">—</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>

      <div class="sm:col-span-3 grid grid-cols-2 sm:grid-cols-4 gap-3 p-3 rounded-xl bg-marca-fondo">
        <div><label class="label">Costo (neto)</label><input v-model.number="form.cost" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">Precio lista 1</label><input v-model.number="form.price" type="number" step="any" min="0" class="input" /><p v-if="form.errors.price" class="text-carmin text-xs mt-1">{{ form.errors.price }}</p></div>
        <div><label class="label">IVA %</label><select v-model.number="form.iva" class="input"><option v-for="a in [0,10.5,21,27]" :key="a" :value="a">{{ a }}%</option></select></div>
        <div><label class="label">Margen</label><p class="input bg-white tabular-nums" :class="margen !== null && margen < 0 ? 'text-carmin' : ''">{{ margen === null ? '—' : margen + '%' }}</p></div>
        <div class="col-span-2 sm:col-span-4 grid grid-cols-4 gap-2">
          <div v-for="n in [2,3,4,5]" :key="n"><label class="label">Lista {{ n }}</label><input v-model.number="form.prices[n]" type="number" step="any" min="0" class="input !py-1.5 text-sm" :placeholder="form.price ? String(form.price) : ''" /></div>
          <p class="col-span-4 text-[11px] text-marca-muted">Las listas vacías usan el precio de lista 1. Cada tipo de cliente tiene su lista.</p>
        </div>
      </div>

      <div v-if="form.tipo !== 'servicio'" class="sm:col-span-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div><label class="label">Stock mínimo</label><input v-model.number="form.stock_min" type="number" step="any" min="0" class="input" /></div>
        <template v-if="!form.id">
          <div><label class="label">Stock inicial</label><input v-model.number="form.stock_inicial" type="number" step="any" min="0" class="input" /></div>
          <div class="col-span-2"><label class="label">En depósito</label><select v-model="form.deposito_id" class="input"><option :value="null">El de mi sucursal</option><option v-for="d in depositos.filter(x => x.activo)" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></div>
        </template>
        <label class="flex items-center gap-2 text-sm col-span-2"><input v-model="form.controla_stock" type="checkbox" class="accent-carmin" /> Controla stock (avisa bajo mínimo)</label>
      </div>
      <div class="sm:col-span-3"><label class="label">Descripción</label><input v-model="form.description" class="input" /></div>
      <label v-if="form.id" class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" class="accent-carmin" /> Activo (se puede vender)</label>
    </div>
    <template #pie>
      <button class="btn-secondary" @click="$emit('cerrar')">Cancelar</button>
      <button class="btn-primary" :disabled="form.processing" @click="guardar">{{ form.processing ? 'Guardando…' : 'Guardar' }}</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'

const props = defineProps({ abierto: Boolean, articulo: Object, rubros: Array, depositos: Array, proveedores: Array, tipos: Object, unidades: Object })
const emit = defineEmits(['cerrar', 'guardado'])
const vacio = () => ({ id: null, name: '', sku: '', tipo: 'producto', rubro_id: null, unit: 'un', barcode: '', marca: '', proveedor_id: null, cost: 0, price: 0, iva: 21, prices: { 2: '', 3: '', 4: '', 5: '' }, stock_min: 0, stock_inicial: '', deposito_id: null, controla_stock: true, description: '', active: true })
const form = useForm(vacio())
watch(() => props.abierto, v => { if (v) { form.clearErrors(); Object.assign(form, vacio(), props.articulo ? { ...props.articulo, prices: { 2: '', 3: '', 4: '', 5: '', ...(props.articulo.prices ?? {}) } } : {}) } })
const margen = computed(() => form.cost > 0 ? Math.round((form.price - form.cost) / form.cost * 1000) / 10 : null)
function guardar() {
  form.transform(d => ({ ...d, prices: Object.fromEntries(Object.entries(d.prices).filter(([, v]) => v !== '' && v !== null)) }))
    .post(`/stock/articulos${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => { emit('guardado'); emit('cerrar') } })
}
</script>
