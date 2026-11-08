<template>
  <Modal :abierto="abierto" :titulo="form.id ? `Editar ${form.name}` : 'Nuevo artículo'" ancho="max-w-3xl" @cerrar="$emit('cerrar')">
    <div class="grid sm:grid-cols-3 gap-4">
      <div class="sm:col-span-2"><label class="label">Nombre</label><input v-model="form.name" class="input" placeholder="Ej: Cemento x 50 kg" /><p v-if="form.errors.name" class="text-carmin text-xs mt-1">{{ form.errors.name }}</p>
        <!-- Fase 61: la IA completa el resto desde el nombre o una foto -->
        <div v-if="$page.props.auth?.ia" class="flex flex-wrap items-center gap-2 mt-1.5">
          <button type="button" class="btn-secondary !py-0.5 text-xs" :disabled="iaCargando || !form.name" @click="completar()" data-e2e="completar-ia">{{ iaCargando ? 'Completando…' : '✦ Completar con IA' }}</button>
          <label class="btn-secondary !py-0.5 text-xs cursor-pointer" :class="iaCargando ? 'opacity-60 pointer-events-none' : ''">📷 Desde una foto<input type="file" accept="image/*" capture="environment" class="hidden" data-e2e="foto-ia" @change="desdeFoto" /></label>
          <span v-if="iaNota" class="text-[11px]" :class="iaError ? 'text-carmin' : 'text-violeta'">{{ iaNota }}</span>
        </div>
      </div>
      <div><label class="label">Código (SKU)</label><input v-model="form.sku" class="input tabular-nums" placeholder="Se genera solo" /><p v-if="form.errors.sku" class="text-carmin text-xs mt-1">{{ form.errors.sku }}</p></div>
      <div><label class="label">Tipo</label><select v-model="form.tipo" class="input"><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l.split(' (')[0] }}</option></select><p class="text-[11px] text-marca-muted mt-1">{{ tipos[form.tipo]?.match(/\((.*)\)/)?.[1] }}</p></div>
      <div><label class="label">Rubro</label><select v-model="form.rubro_id" class="input"><option :value="null">Sin rubro</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.completo }}</option></select></div>
      <div><label class="label">Unidad</label><select v-model="form.unit" class="input"><option v-for="(l, k) in unidades" :key="k" :value="k">{{ l }}</option></select></div>
      <div><label class="label">Código de barras</label><input v-model="form.barcode" class="input tabular-nums" /></div>
      <div><label class="label">Marca</label><input v-model="form.marca" class="input" /></div>
      <div><label class="label">Proveedor habitual</label><select v-model="form.proveedor_id" class="input"><option :value="null">—</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>

      <div class="sm:col-span-3 grid grid-cols-2 sm:grid-cols-4 gap-3 p-3 rounded-xl bg-marca-fondo">
        <div class="col-span-2 sm:col-span-4 flex flex-wrap items-center gap-3 text-xs">
          <span class="font-bold uppercase tracking-widest text-marca-muted text-[11px]">Precios</span>
          <label class="flex items-center gap-1.5"><input v-model="form.usar_margenes" type="checkbox" class="accent-carmin" /> Calcular listas por margen sobre el costo</label>
          <label class="flex items-center gap-1.5 ml-auto">Moneda <select v-model="form.moneda" class="input !py-0.5 !w-auto text-xs"><option value="ARS">Pesos</option><option value="USD">Dólares</option></select></label>
        </div>
        <div><label class="label">Precio de lista proveedor</label><input v-model.number="form.precio_compra" type="number" step="any" min="0" class="input" @input="derivarCosto" /></div>
        <div><label class="label">Dto. proveedor %</label><input v-model.number="form.descuento_proveedor" type="number" step="any" min="0" max="100" class="input" @input="derivarCosto" /></div>
        <div><label class="label">Costo (neto)</label><input v-model.number="form.cost" type="number" step="any" min="0" class="input" @input="derivarPrecios" /></div>
        <div><label class="label">IVA %</label><select v-model.number="form.iva" class="input"><option v-for="a in [0,10.5,21,27]" :key="a" :value="a">{{ a }}%</option></select></div>
        <template v-if="form.usar_margenes">
          <div v-for="n in [1,2,3,4,5,6]" :key="'m'+n"><label class="label">Margen lista {{ n }} %</label><input v-model.number="form.margenes[n]" type="number" step="any" class="input !py-1.5 text-sm" @input="derivarPrecios" :placeholder="n > 1 ? 'vacío = lista 1' : ''" /></div>
          <div class="col-span-2 sm:col-span-4 grid grid-cols-6 gap-2 text-xs">
            <div v-for="n in [1,2,3,4,5,6]" :key="'p'+n" class="rounded-lg bg-white p-2"><p class="text-marca-muted">Lista {{ n }}</p><p class="font-bold tabular-nums">{{ moneda(n === 1 ? form.price : (form.prices[n] || form.price)) }}</p></div>
          </div>
        </template>
        <template v-else>
          <div><label class="label">Precio lista 1</label><input v-model.number="form.price" type="number" step="any" min="0" class="input" /><p v-if="form.errors.price" class="text-carmin text-xs mt-1">{{ form.errors.price }}</p></div>
          <div><label class="label">Margen</label><p class="input bg-white tabular-nums" :class="margen !== null && margen < 0 ? 'text-carmin' : ''">{{ margen === null ? '—' : margen + '%' }}</p></div>
          <div class="col-span-2 sm:col-span-4 grid grid-cols-5 gap-2">
            <div v-for="n in [2,3,4,5,6]" :key="n"><label class="label">Lista {{ n }}</label><input v-model.number="form.prices[n]" type="number" step="any" min="0" class="input !py-1.5 text-sm" :placeholder="form.price ? String(form.price) : ''" /></div>
            <p class="col-span-5 text-[11px] text-marca-muted">Las listas vacías usan el precio de lista 1. Cada tipo de cliente tiene su lista.</p>
          </div>
        </template>
        <div class="col-span-2 sm:col-span-4 grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t border-marca-borde/60">
          <div><label class="label">Descuento por cantidad 1: desde</label><input v-model.number="form.desc_cant_min" type="number" step="any" min="0" class="input !py-1.5 text-sm" placeholder="Ej: 10 unidades" /></div>
          <div><label class="label">se descuenta %</label><input v-model.number="form.desc_cant_pct" type="number" step="any" min="0" max="100" class="input !py-1.5 text-sm" placeholder="Ej: 10" /></div><div><label class="label">Descuento por cantidad 2: desde</label><input v-model.number="form.desc_cant2_min" type="number" step="any" min="0" class="input !py-1.5 text-sm" placeholder="Ej: 50 unidades" /></div>
          <div><label class="label">se descuenta %</label><input v-model.number="form.desc_cant2_pct" type="number" step="any" min="0" max="100" class="input !py-1.5 text-sm" placeholder="Ej: 50" /></div>
        </div>
        <p v-if="form.moneda === 'USD'" class="col-span-2 sm:col-span-4 text-[11px] text-violeta">Los precios quedan en dólares y se convierten a pesos con la cotización del día al vender.</p>
      </div>

      <div v-if="form.tipo !== 'servicio'" class="sm:col-span-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div><label class="label">Stock mínimo</label><input v-model.number="form.stock_min" type="number" step="any" min="0" class="input" /></div>
        <template v-if="!form.id">
          <div><label class="label">Stock inicial</label><input v-model.number="form.stock_inicial" type="number" step="any" min="0" class="input" /></div>
          <div class="col-span-2"><label class="label">En depósito</label><select v-model="form.deposito_id" class="input"><option :value="null">El de mi sucursal</option><option v-for="d in depositos.filter(x => x.activo)" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></div>
        </template>
        <label class="flex items-center gap-2 text-sm col-span-2"><input v-model="form.controla_stock" type="checkbox" class="accent-carmin" /> Controla stock (avisa bajo mínimo)</label>
        <label class="flex items-center gap-2 text-sm" title="Pide lote y vencimiento al comprar; sale primero lo que vence primero"><input v-model="form.perecedero" type="checkbox" class="accent-carmin" /> Perecedero (lote y vencimiento)</label>
        <label class="flex items-center gap-2 text-sm" title="Pide número de serie por unidad al comprar"><input v-model="form.seriado" type="checkbox" class="accent-carmin" /> Con número de serie</label>
        <div class="flex items-center gap-2 text-sm" title="Para mostrar el precio por kilo o por litro en la etiqueta de góndola">Contenido neto <input v-model.number="form.contenido_neto" type="number" step="any" min="0" class="input !py-1 !w-20 text-right" data-e2e="contenido" /><select v-model="form.contenido_unidad" class="input !py-1 !w-20"><option :value="null">—</option><option v-for="u in ['g', 'kg', 'ml', 'l', 'un']" :key="u" :value="u">{{ u }}</option></select></div>
        <label class="flex items-center gap-2 text-sm" title="Se vende por peso con etiqueta de balanza"><input v-model="form.pesable" type="checkbox" class="accent-carmin" data-e2e="pesable" /> Pesable (balanza)</label>
        <div v-if="form.pesable" class="flex flex-wrap items-center gap-2 text-sm"><label class="flex items-center gap-1">PLU <input v-model="form.plu" class="input !py-1 !w-20" maxlength="6" placeholder="00123" data-e2e="plu" /></label><label class="flex items-center gap-1">Vence a los <input v-model.number="form.dias_vencimiento" type="number" min="0" class="input !py-1 !w-16 text-right" /> días</label></div>
        <label v-if="form.seriado" class="flex items-center gap-2 text-sm">Garantía <input v-model.number="form.garantia_meses" type="number" min="0" max="240" class="input !py-1 !w-20 text-right" data-e2e="garantia" /> meses</label>
        <label class="flex items-center gap-2 text-sm" title="Al cerrar el turno de caja se cuenta este artículo y se compara lo que salió con lo cobrado"><input v-model="form.control_turno" type="checkbox" class="accent-carmin" data-e2e="control-turno" /> Contar en el cierre de turno</label>
        <label class="flex items-center gap-2 text-sm col-span-2" title="Aparece en la tienda online y el menú QR"><input v-model="form.en_tienda" type="checkbox" class="accent-carmin" /> Publicado en la tienda online / menú</label>
      </div>
      <div class="sm:col-span-3"><label class="label">Descripción</label><input v-model="form.description" class="input" /></div>
      <div v-if="form.en_tienda || form.descripcion_tienda" class="sm:col-span-3"><label class="label">Descripción para la tienda online</label><textarea v-model="form.descripcion_tienda" class="input" rows="2" maxlength="500" placeholder="Lo que ve el cliente en la tienda y en los marketplaces"></textarea></div>
      <details v-if="form.atributos && (form.atributos.titulo_publicacion || (form.atributos.ml && Object.keys(form.atributos.ml).length))" class="sm:col-span-3 text-sm" data-e2e="atributos-ml">
        <summary class="cursor-pointer text-violeta text-xs">Para publicar en MercadoLibre y tiendas ({{ Object.keys(form.atributos.ml ?? {}).length }} atributos)</summary>
        <div class="grid sm:grid-cols-2 gap-2 mt-2">
          <label class="text-xs sm:col-span-2">Título de la publicación<input v-model="form.atributos.titulo_publicacion" class="input !py-1" maxlength="60" /></label>
          <label v-for="(v, k) in form.atributos.ml" :key="k" class="text-xs">{{ k }}<input v-model="form.atributos.ml[k]" class="input !py-1" /></label>
        </div>
      </details>
      <label v-if="form.id" class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" class="accent-carmin" /> Activo (se puede vender)</label>
    </div>
    <template #pie>
      <button class="btn-secondary" @click="$emit('cerrar')">Cancelar</button>
      <button class="btn-primary" :disabled="form.processing" @click="guardar">{{ form.processing ? 'Guardando…' : 'Guardar' }}</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'

const props = defineProps({ abierto: Boolean, articulo: Object, rubros: Array, depositos: Array, proveedores: Array, tipos: Object, unidades: Object })
const emit = defineEmits(['cerrar', 'guardado'])
const vacio = () => ({ id: null, name: '', sku: '', tipo: 'producto', rubro_id: null, unit: 'un', barcode: '', marca: '', proveedor_id: null, cost: 0, price: 0, iva: 21, prices: { 2: '', 3: '', 4: '', 5: '', 6: '' }, stock_min: 0, stock_inicial: '', deposito_id: null, controla_stock: true, description: '', descripcion_tienda: '', atributos: null, active: true,
  precio_compra: 0, descuento_proveedor: 0, moneda: 'ARS', usar_margenes: false, margenes: { 1: '', 2: '', 3: '', 4: '', 5: '', 6: '' }, desc_cant_min: 0, desc_cant_pct: 0, desc_cant2_min: 0, desc_cant2_pct: 0, perecedero: false, seriado: false, garantia_meses: null, pesable: false, plu: '', dias_vencimiento: null, contenido_neto: null, contenido_unidad: null, control_turno: false, en_tienda: true })
const form = useForm(vacio())
// Artículo nuevo: al elegir el rubro toma sus datos (IVA, tipo y marcas de stock), propios o heredados.
watch(() => form.rubro_id, id => {
  if (form.id || !id) return
  const e = props.rubros?.find(r => r.id === id)?.efectivos ?? {}
  for (const k of ['iva', 'tipo', 'perecedero', 'seriado', 'controla_stock', 'control_turno', 'en_tienda']) if (e[k]) form[k] = e[k].valor
})
watch(() => props.abierto, v => { if (v) { form.clearErrors(); const a = props.articulo; Object.assign(form, vacio(), a ? { ...a, prices: { 2: '', 3: '', 4: '', 5: '', ...(a.prices ?? {}) }, margenes: { 1: '', 2: '', 3: '', 4: '', 5: '', ...(a.margenes ?? {}) }, usar_margenes: !!(a.margenes && Object.keys(a.margenes).length) } : {}) } })
const margen = computed(() => form.cost > 0 ? Math.round((form.price - form.cost) / form.cost * 1000) / 10 : null)
const r2 = n => Math.round(n * 100) / 100
function derivarCosto() { if (form.precio_compra > 0) { form.cost = r2(form.precio_compra * (1 - (form.descuento_proveedor || 0) / 100)); derivarPrecios() } }
function derivarPrecios() {
  if (!form.usar_margenes) return
  const m1 = form.margenes[1]
  if (m1 !== '' && m1 !== null) form.price = r2(form.cost * (1 + Number(m1) / 100))
  for (const n of [2, 3, 4, 5, 6]) { const m = form.margenes[n]; form.prices[n] = m !== '' && m !== null ? r2(form.cost * (1 + Number(m) / 100)) : '' }
}
// Fase 61: completar con IA. Sólo rellena lo que está vacío; lo que ya escribió el usuario se respeta.
const iaCargando = ref(false), iaNota = ref(''), iaError = ref(false)
async function completar(foto = null) {
  iaCargando.value = true; iaNota.value = ''; iaError.value = false
  try {
    const { data } = await window.axios.post('/stock/articulos/completar-ia', { nombre: form.name || null, foto })
    const c = data.campos, puestos = []
    const poner = (k, v, vacio = v => v === '' || v === null || v === undefined || v === 0) => { if (v !== null && v !== undefined && vacio(form[k])) { form[k] = v; puestos.push(k) } }
    if (!form.name || foto) poner('name', c.name, () => true)
    poner('marca', c.marca); poner('barcode', c.barcode); poner('description', c.description); poner('descripcion_tienda', c.descripcion_tienda)
    poner('contenido_neto', c.contenido_neto); poner('contenido_unidad', c.contenido_unidad)
    if (!form.id) { poner('rubro_id', c.rubro_id); if (c.tipo) { form.tipo = c.tipo; puestos.push('tipo') } if (c.unit) { form.unit = c.unit; puestos.push('unidad') } if (c.iva !== undefined) { form.iva = c.iva; puestos.push('IVA') } }
    if (c.atributos && Object.keys(c.atributos).length) { form.atributos = { ...(form.atributos ?? {}), ...c.atributos, ml: { ...(form.atributos?.ml ?? {}), ...(c.atributos.ml ?? {}) } }; puestos.push('atributos ML') }
    iaNota.value = (puestos.length ? 'Completado con IA: ' + puestos.join(', ') + '. Revisá y corregí lo que haga falta.' : 'No había nada para completar.') + (data.rubro_sugerido ? ` Rubro sugerido «${data.rubro_sugerido}»: no existe todavía, crealo en Rubros.` : '')
  } catch (e) { iaError.value = true; iaNota.value = e.response?.data?.aviso || e.response?.data?.message || 'No se pudo completar con IA.' }
  finally { iaCargando.value = false }
}
function desdeFoto(e) {
  const f = e.target.files?.[0]; e.target.value = ''; if (!f) return
  const img = new Image(), url = URL.createObjectURL(f)
  img.onload = () => { const k = Math.min(1, 1280 / Math.max(img.width, img.height)); const cv = document.createElement('canvas'); cv.width = Math.round(img.width * k); cv.height = Math.round(img.height * k); cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height); URL.revokeObjectURL(url); completar(cv.toDataURL('image/jpeg', 0.85)) }
  img.src = url
}
function guardar() {
  derivarPrecios()
  form.transform(d => ({ ...d, prices: Object.fromEntries(Object.entries(d.prices).filter(([, v]) => v !== '' && v !== null)), margenes: Object.fromEntries(Object.entries(d.margenes).filter(([, v]) => v !== '' && v !== null)) }))
    .post(`/stock/articulos${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => { emit('guardado'); emit('cerrar') } })
}
</script>
