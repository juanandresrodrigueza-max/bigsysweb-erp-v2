<template>
  <AppLayout titulo="Fórmulas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><Link href="/produccion" class="text-xs text-marca-muted hover:text-carmin">← Producción</Link><h1 class="page-title">Fórmulas</h1><p class="page-subtitle">Qué insumos y en qué cantidad lleva cada producto elaborado. El costo se calcula solo.</p></div>
      <button v-if="puede('produccion','editar')" @click="abrir()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Fórmula</button>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
      <div v-for="f in formulas" :key="f.id" class="card" :class="!f.is_active ? 'opacity-60' : ''">
        <div class="flex items-start justify-between gap-2">
          <div><h2 class="font-extrabold text-lg leading-tight">{{ f.name }}</h2><p class="text-sm text-marca-muted">Produce <b class="text-marca-texto">{{ cantidad(f.yield_quantity) }} {{ f.unit }}</b> de {{ f.producto }}<span v-if="f.tiempo_minutos"> · {{ f.tiempo_minutos }} min</span></p></div>
          <button v-if="puede('produccion','editar')" @click="abrir(f)" class="btn-ghost !px-2"><Icono nombre="edit" clase="w-4 h-4" /></button>
        </div>
        <table class="table mt-3 text-sm"><tbody>
          <tr v-for="i in f.items" :key="i.product_id"><td class="!py-1.5">{{ i.nombre }}<span v-if="i.notes" class="text-xs text-marca-muted"> · {{ i.notes }}</span></td><td class="!py-1.5 text-right tabular-nums">{{ cantidad(i.quantity) }} {{ i.unit }}</td><td class="!py-1.5 text-right tabular-nums text-marca-muted">{{ moneda(i.costo, 0) }}</td></tr>
        </tbody></table>
        <div class="flex flex-wrap justify-between gap-2 mt-3 pt-3 border-t border-marca-borde text-sm">
          <span>Costo tanda <b class="tabular-nums">{{ moneda(f.costo, 0) }}</b> · por {{ f.unit }} <b class="tabular-nums">{{ moneda(f.costo_unit) }}</b></span>
          <span v-if="f.precio" class="text-marca-muted">vende a {{ moneda(f.precio) }} · margen <b :class="f.precio > f.costo_unit ? 'text-emerald-700' : 'text-carmin'">{{ f.costo_unit ? Math.round((f.precio - f.costo_unit) / f.costo_unit * 100) : '—' }}%</b></span>
        </div>
        <p class="text-xs text-marca-muted mt-1">{{ f.ordenes }} orden{{ f.ordenes === 1 ? '' : 'es' }} hasta ahora</p>
      </div>
      <div v-if="!formulas.length" class="card md:col-span-2 text-center text-marca-muted py-12">Todavía no hay fórmulas. Creá la primera: elegís el producto que se fabrica y los insumos que lleva.</div>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? `Editar ${form.name}` : 'Nueva fórmula'" ancho="max-w-2xl" @cerrar="modal = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2"><label class="label">Producto que se fabrica</label><BuscadorSelect v-model="form.product_id" :opciones="opcionesProductos" :url="props.catalogoParcial.productos ? '/buscar/articulos/produccion' : null" @cargados="f => sumar(productosCat, f)" placeholder="Buscar artículo…" @elegido="o => { if (o && !form.name) form.name = o.label }" /><p v-if="form.errors.product_id" class="text-carmin text-xs mt-1">{{ form.errors.product_id }}</p></div>
        <div class="sm:col-span-2"><label class="label">Nombre de la fórmula</label><input v-model="form.name" class="input" placeholder="Ej: Pan francés (tanda 50 kg)" /><p v-if="form.errors.name" class="text-carmin text-xs mt-1">{{ form.errors.name }}</p></div>
        <div><label class="label">Rinde (cantidad producida por tanda)</label><input v-model.number="form.yield_quantity" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">Tiempo (minutos)</label><input v-model.number="form.tiempo_minutos" type="number" min="0" class="input" /></div>
        <div class="sm:col-span-2">
          <div class="flex items-center justify-between mb-1"><label class="label !mb-0">Insumos por tanda</label><span class="text-xs text-marca-muted tabular-nums">Costo tanda {{ moneda(costoTanda, 0) }} · por unidad {{ moneda(form.yield_quantity ? costoTanda / form.yield_quantity : 0) }}</span></div>
          <div v-for="(it, i) in form.items" :key="i" class="grid grid-cols-[1fr_90px_70px_28px] gap-2 mb-2">
            <BuscadorSelect v-model="it.product_id" :opciones="opcionesInsumos" :url="props.catalogoParcial.productos ? '/buscar/articulos/produccion' : null" @cargados="f => sumar(productosCat, f)" placeholder="Insumo…" @elegido="o => { if (o) it.unit = o.unit }" />
            <input v-model.number="it.quantity" type="number" step="any" min="0" class="input text-right" placeholder="Cant." />
            <select v-model="it.unit" class="input !px-1"><option v-for="(l, k) in unidades" :key="k" :value="k">{{ k }}</option></select>
            <button @click="form.items.splice(i, 1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
          </div>
          <button @click="form.items.push({ product_id: null, quantity: null, unit: 'un', notes: '' })" class="btn-ghost !px-2 text-xs">+ Insumo</button>
          <p v-if="form.errors.items" class="text-carmin text-xs mt-1">{{ form.errors.items }}</p>
        </div>
        <div class="sm:col-span-2"><label class="label">Instrucciones</label><textarea v-model="form.instructions" rows="3" class="input" placeholder="Pasos, temperaturas, tiempos…"></textarea></div>
        <label v-if="form.id" class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="accent-carmin" /> Fórmula activa</label>
      </div>
      <template #pie><button class="btn-secondary" @click="modal = false">Cancelar</button><button class="btn-primary" :disabled="form.processing" @click="form.post(`/produccion/formulas${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal = false) })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import { moneda, cantidad } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ catalogoParcial: { type: Object, default: () => ({}) },  formulas: Array, productos: Array, unidades: Object })
const productosCat = ref([...props.productos])
function sumar(lista, filas) { const arr = Array.isArray(lista) ? lista : lista.value; const por = new Map(arr.map(x => [x.id, x])); filas.forEach(f => { const e = por.get(f.id); if (e) Object.assign(e, f); else arr.push(f) }) } // en el template los refs llegan desenvueltos; lo que ya está se actualiza (sugerido, precios)
const { puede } = usePermisos()
const modal = ref(false)
const vacio = () => ({ id: null, product_id: null, name: '', yield_quantity: 1, tiempo_minutos: null, instructions: '', is_active: true, items: [{ product_id: null, quantity: null, unit: 'un', notes: '' }] })
const form = useForm(vacio())
function abrir(f = null) { form.clearErrors(); Object.assign(form, vacio(), f ? { id: f.id, product_id: f.product_id, name: f.name, yield_quantity: f.yield_quantity, tiempo_minutos: f.tiempo_minutos, instructions: f.instructions ?? '', is_active: f.is_active, items: f.items.map(i => ({ product_id: i.product_id, quantity: i.quantity, unit: i.unit, notes: i.notes ?? '' })) } : {}); modal.value = true }
const opcionesProductos = computed(() => productosCat.value.map(p => ({ id: p.id, label: p.name, sub: p.sku, unit: p.unit })))
const opcionesInsumos = computed(() => productosCat.value.filter(p => p.id !== form.product_id).map(p => ({ id: p.id, label: p.name, sub: p.sku, extra: 'costo ' + moneda(p.cost), unit: p.unit })))
const costoTanda = computed(() => form.items.reduce((a, it) => a + (Number(it.quantity) || 0) * (productosCat.value.find(p => p.id === it.product_id)?.cost ?? 0), 0))
</script>
