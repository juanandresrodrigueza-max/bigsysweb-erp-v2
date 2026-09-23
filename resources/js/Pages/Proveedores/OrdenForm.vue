<template>
  <AppLayout :titulo="orden ? 'Editar orden' : 'Nueva orden de compra'">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/proveedores/ordenes" class="text-xs text-marca-muted hover:text-carmin">← Órdenes de compra</Link>
        <h1 class="page-title">{{ orden ? `Editar ${orden.numero}` : 'Nueva orden de compra' }}</h1>
        <p class="page-subtitle">Armala a mano o dejá que el sistema sugiera qué pedir según lo que vendiste o lo que está bajo mínimo.</p>
      </div>
      <button type="button" @click="sugAbierto = true" class="btn-violeta"><Icono nombre="sparkles" clase="w-4 h-4" /> Sugerir pedido</button>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card grid sm:grid-cols-3 gap-4">
          <div class="sm:col-span-3">
            <label class="label">Proveedor</label>
            <BuscadorSelect v-model="form.contact_id" :opciones="opcionesProveedores" :url="props.catalogoParcial.proveedores ? '/buscar/contactos/proveedor' : null" @cargados="f => sumar(proveedoresCat, f)" placeholder="Buscar proveedor…" />
            <p v-if="form.errors.contact_id" class="text-carmin text-xs mt-1">{{ form.errors.contact_id }}</p>
          </div>
          <div><label class="label">Fecha</label><input v-model="form.fecha" type="date" class="input" /></div>
          <div><label class="label">Entrega esperada</label><input v-model="form.fecha_entrega" type="date" class="input" /></div>
          <div><label class="label">Origen</label><p class="input bg-marca-fondo capitalize">{{ form.origen }}</p></div>
        </div>

        <div class="card p-0 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Artículos a pedir</h2><button type="button" @click="agregar()" class="btn-secondary !py-1 text-xs"><Icono nombre="plus" clase="w-3.5 h-3.5" /> Agregar</button></div>
          <div class="overflow-x-auto">
            <table class="table min-w-[680px]">
              <thead><tr><th class="w-[44%]">Artículo</th><th class="w-24 text-right">Stock</th><th class="w-24 text-right">Cant.</th><th class="w-32 text-right">Precio neto</th><th class="text-right">Subtotal</th><th class="w-8"></th></tr></thead>
              <tbody>
                <tr v-for="(it, i) in form.items" :key="i" class="align-top">
                  <td><BuscadorSelect v-model="it.product_id" :opciones="opcionesProductos" :url="props.catalogoParcial.productos ? '/buscar/articulos/orden' : null" @cargados="f => sumar(productosCat, f)" placeholder="Artículo…" @elegido="o => { if (o) { it.descripcion = o.label; it.precio_unit = o.precio_compra; it.stock = o.stock } }" /><input v-model="it.notas" class="input mt-1 !py-1 text-xs" placeholder="Nota para el proveedor (opcional)" /></td>
                  <td class="text-right tabular-nums text-marca-muted pt-3">{{ it.stock ?? '' }}</td>
                  <td><input v-model.number="it.cantidad" type="number" min="0" step="any" class="input text-right" /></td>
                  <td><input v-model.number="it.precio_unit" type="number" min="0" step="any" class="input text-right" /></td>
                  <td class="text-right font-semibold tabular-nums pt-3">{{ moneda((it.cantidad || 0) * (it.precio_unit || 0)) }}</td>
                  <td class="pt-2"><button type="button" @click="form.items.splice(i,1)" class="text-marca-muted hover:text-carmin"><Icono nombre="trash" clase="w-4 h-4" /></button></td>
                </tr>
                <tr v-if="!form.items.length"><td colspan="6" class="text-center text-marca-muted py-8">Agregá artículos o usá "Sugerir pedido".</td></tr>
              </tbody>
            </table>
          </div>
          <p v-if="form.errors.items" class="text-carmin text-xs px-4 pb-3">{{ form.errors.items }}</p>
        </div>
        <div class="card"><label class="label">Notas / condiciones</label><textarea v-model="form.notas" rows="2" class="input" placeholder="Ej: entregar por la mañana, pago a 30 días"></textarea></div>
      </div>

      <div class="space-y-4">
        <div class="card sticky top-20">
          <h2 class="font-bold mb-3">Resumen</h2>
          <div class="flex justify-between text-sm"><span class="text-marca-muted">Artículos</span><span class="tabular-nums">{{ form.items.filter(i => i.cantidad > 0).length }}</span></div>
          <div class="flex justify-between text-lg font-extrabold pt-2 mt-2 border-t border-marca-borde"><span>Total neto</span><span class="tabular-nums">{{ moneda(total) }}</span></div>
          <div class="grid gap-2 mt-5">
            <button type="button" @click="guardar(true)" class="btn-primary w-full" :disabled="form.processing || !form.items.length">{{ form.processing ? 'Guardando…' : 'Guardar y marcar enviada' }}</button>
            <button type="button" @click="guardar(false)" class="btn-secondary w-full" :disabled="form.processing">Guardar borrador</button>
            <Link href="/proveedores/ordenes" class="btn-ghost w-full">Cancelar</Link>
          </div>
        </div>
      </div>
    </div>

    <!-- Sugerencia -->
    <Modal :abierto="sugAbierto" titulo="Sugerir pedido" ancho="max-w-3xl" @cerrar="sugAbierto = false">
      <div class="grid sm:grid-cols-4 gap-3 mb-3">
        <div class="sm:col-span-4 flex gap-1 p-1 rounded-xl bg-marca-fondo">
          <button type="button" v-for="m in [['ventas','Cubrir lo vendido en un período'],['faltantes','Reponer lo que está bajo mínimo']]" :key="m[0]" @click="sug.modo = m[0]" class="flex-1 py-1.5 rounded-lg text-sm font-semibold" :class="sug.modo === m[0] ? 'bg-white shadow-card' : 'text-marca-muted'">{{ m[1] }}</button>
        </div>
        <template v-if="sug.modo === 'ventas'">
          <div><label class="label">Ventas desde</label><input v-model="sug.desde" type="date" class="input" /></div>
          <div><label class="label">hasta</label><input v-model="sug.hasta" type="date" class="input" /></div>
        </template>
        <div><label class="label">Proveedor</label><select v-model="sug.contact_id" class="input"><option :value="null">Cualquiera</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
        <div><label class="label">Rubro</label><select v-model="sug.rubro_id" class="input"><option :value="null">Todos</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.nombre }}</option></select></div>
      </div>
      <button type="button" @click="calcular" class="btn-violeta mb-3" :disabled="sug.cargando">{{ sug.cargando ? 'Calculando…' : 'Calcular' }}</button>
      <p class="text-xs text-marca-muted mb-2" v-if="sug.modo === 'ventas'">Pedido = lo vendido en el período − stock actual. Usar el mismo mes del año pasado captura la estacionalidad.</p>
      <div v-if="sug.items.length" class="max-h-72 overflow-y-auto border border-marca-borde rounded-xl">
        <table class="table text-sm">
          <thead><tr><th><input type="checkbox" :checked="sug.items.every(i => i.sel)" @change="e => sug.items.forEach(i => (i.sel = e.target.checked))" /></th><th>Artículo</th><th class="text-right">Vendido</th><th class="text-right">Stock</th><th class="text-right">Pedir</th><th class="text-right">Precio</th></tr></thead>
          <tbody>
            <tr v-for="i in sug.items" :key="i.product_id"><td><input v-model="i.sel" type="checkbox" class="accent-carmin" /></td><td>{{ i.descripcion }} <span class="text-xs text-marca-muted">{{ i.sku }}</span></td><td class="text-right tabular-nums">{{ sug.modo === 'ventas' ? i.vendido : 'mín ' + i.minimo }}</td><td class="text-right tabular-nums">{{ i.stock }}</td><td class="text-right"><input v-model.number="i.cantidad" type="number" step="any" min="0" class="input !py-0.5 w-20 text-right" /></td><td class="text-right tabular-nums">{{ moneda(i.precio_unit) }}</td></tr>
          </tbody>
        </table>
      </div>
      <p v-else-if="sug.calculado" class="text-sm text-marca-muted">Nada para pedir con esos filtros.</p>
      <template #pie>
        <button class="btn-secondary" @click="sugAbierto = false">Cerrar</button>
        <button class="btn-primary" :disabled="!sug.items.some(i => i.sel)" @click="aplicarSugerencia">Agregar {{ sug.items.filter(i => i.sel).length }} a la orden</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { reactive, computed, ref, onMounted } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import { moneda, hoyISO } from '@/util/formato'

const props = defineProps({ catalogoParcial: { type: Object, default: () => ({}) },  orden: Object, contactIdInicial: Number, proveedores: Array, productos: Array, rubros: Array })
const productosCat = ref([...props.productos])
const proveedoresCat = ref([...props.proveedores])
function sumar(lista, filas) { const arr = Array.isArray(lista) ? lista : lista.value; const ids = new Set(arr.map(x => x.id)); filas.forEach(f => { if (!ids.has(f.id)) arr.push(f) }) } // en el template los refs llegan desenvueltos
const o = props.orden
const form = useForm({ contact_id: o?.contact_id ?? props.contactIdInicial ?? null, fecha: o?.fecha ?? hoyISO(), fecha_entrega: o?.fecha_entrega ?? '', notas: o?.notas ?? '', origen: o?.origen ?? 'manual', items: (o?.items ?? []).map(i => ({ ...i })), enviar: false })
const opcionesProveedores = computed(() => proveedoresCat.value.map(p => ({ id: p.id, label: p.name, sub: p.cuit })))
const opcionesProductos = computed(() => productosCat.value.map(p => ({ id: p.id, label: p.name, sub: p.sku, extra: `stock ${p.stock}`, precio_compra: p.precio_compra, stock: p.stock })))
const total = computed(() => form.items.reduce((a, i) => a + (Number(i.cantidad) || 0) * (Number(i.precio_unit) || 0), 0))
function agregar(pre = {}) { form.items.push({ product_id: null, descripcion: '', cantidad: 1, precio_unit: 0, notas: '', stock: null, ...pre }) }
function guardar(enviar) { form.enviar = enviar; form.post(o ? `/proveedores/ordenes/${o.id}` : '/proveedores/ordenes') }

const sugAbierto = ref(false)
const y = new Date(); const lastYear = y.getFullYear() - 1; const mm = String(y.getMonth() + 1).padStart(2, '0')
const sug = reactive({ modo: 'ventas', desde: `${lastYear}-${mm}-01`, hasta: new Date(lastYear, y.getMonth() + 1, 0).toISOString().slice(0, 10), contact_id: form.contact_id, rubro_id: null, items: [], cargando: false, calculado: false })
async function calcular() {
  sug.cargando = true
  try { const { data } = await window.axios.post('/proveedores/ordenes/sugerir', { modo: sug.modo, contact_id: sug.contact_id, rubro_id: sug.rubro_id, desde: sug.desde, hasta: sug.hasta }); sug.items = data.map(i => ({ ...i, sel: true })); sug.calculado = true }
  finally { sug.cargando = false }
}
function aplicarSugerencia() {
  const sel = sug.items.filter(i => i.sel && i.cantidad > 0)
  if (!form.contact_id && sug.contact_id) form.contact_id = sug.contact_id
  if (!form.contact_id) { const prov = sel.find(i => i.proveedor)?.proveedor; if (prov) form.contact_id = prov }
  form.items = form.items.filter(i => i.product_id || i.descripcion)
  sel.forEach(i => { const ex = form.items.find(x => x.product_id === i.product_id); if (ex) ex.cantidad = i.cantidad; else agregar({ product_id: i.product_id, descripcion: i.descripcion, cantidad: i.cantidad, precio_unit: i.precio_unit, stock: i.stock }) })
  form.origen = sug.modo === 'ventas' ? 'sugerido' : 'faltantes'
  sugAbierto.value = false
}
onMounted(() => { if (new URLSearchParams(location.search).get('sugerir')) sugAbierto.value = true })
</script>
