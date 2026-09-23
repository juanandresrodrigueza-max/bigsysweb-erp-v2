<template>
  <AppLayout titulo="Stock">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Stock</h1><p class="page-subtitle">Artículos, existencias por depósito y valorización a costo.</p></div>
      <div class="flex flex-wrap gap-2">
        <Link href="/stock/movimientos" class="btn-secondary">Movimientos</Link>
        <Link v-if="puede('stock','editar')" href="/stock/inventario" class="btn-secondary">Inventario</Link>
        <button v-if="puede('stock','crear')" @click="transfAbierto = true" class="btn-secondary">Transferir</button>
        <button v-if="puede('stock','editar')" @click="preciosAbierto = true" class="btn-secondary">Actualizar precios</button>
        <Link v-if="puede('stock','editar')" href="/stock/importar" class="btn-secondary">Importar lista</Link>
        <button v-if="puede('stock','editar')" @click="dolarAbierto = true" class="btn-ghost text-xs" :title="cotizacion ? `Dólar ${cotizacion.manual ? 'fijado' : 'automático'} del ${cotizacion.fecha}` : 'Sin cotización'">U$S {{ cotizacion ? moneda(cotizacion.venta, 0).replace('$ ', '') : '—' }}</button>
        <button v-if="puede('stock','editar')" @click="configAbierto = true" class="btn-ghost"><Icono nombre="settings" clase="w-4 h-4" /></button>
        <button v-if="puede('stock','crear')" @click="abrirArticulo()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Artículo</button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Artículos activos</p><p class="text-xl font-extrabold tabular-nums">{{ entero(kpis.articulos) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Stock valorizado (costo)</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.valorizado, 0) }}</p></div>
      <button @click="f.estado = 'bajo_minimo'; filtrar()" class="card py-3 text-left hover:border-carmin/40"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Bajo mínimo</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.bajo_minimo ? 'text-amber-600' : ''">{{ kpis.bajo_minimo }}</p></button>
      <button @click="f.estado = 'sin_stock'; filtrar()" class="card py-3 text-left hover:border-carmin/40"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Sin stock</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.sin_stock ? 'text-carmin' : ''">{{ kpis.sin_stock }}</p></button>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input lg:col-span-2" placeholder="Nombre, código, barras o marca…" />
      <select v-model="f.rubro" @change="filtrar" class="input"><option value="">Todos los rubros</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.completo }}</option></select>
      <select v-model="f.tipo" @change="filtrar" class="input"><option value="">Todos los tipos</option><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l.split(' (')[0] }}</option></select>
      <select v-model="f.estado" @change="filtrar" class="input"><option value="">Activos</option><option value="bajo_minimo">Bajo mínimo</option><option value="sin_stock">Sin stock</option><option value="inactivos">Inactivos</option><option value="todos">Todos</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Artículo</th><th>Rubro</th><th class="text-right">Costo</th><th class="text-right">Precio</th><th v-for="d in depositosActivos" :key="d.id" class="text-right whitespace-nowrap">{{ d.nombre.replace('Depósito ', '') }}</th><th class="text-right">Total</th><th class="text-right">Mín.</th><th class="text-right">Valor</th><th></th></tr></thead>
        <tbody>
          <tr v-for="p in lista.data" :key="p.id" class="cursor-pointer" :class="!p.active ? 'opacity-50' : ''" @click="$inertia.visit(`/stock/${p.id}`)">
            <td><p class="font-semibold">{{ p.name }}</p><p class="text-xs text-marca-muted tabular-nums">{{ p.sku }}<span v-if="p.marca"> · {{ p.marca }}</span><span v-if="p.tipo !== 'producto'" class="badge ml-1" :class="{ insumo: 'bg-gris-light text-marca-muted', elaborado: 'bg-violeta-light text-violeta', servicio: 'bg-lavanda-light text-violeta' }[p.tipo]">{{ p.tipo }}</span></p></td>
            <td><span v-if="p.rubro" class="badge text-white" :style="{ background: p.rubro_color || '#6f6a62' }">{{ p.rubro }}</span></td>
            <td class="text-right tabular-nums text-marca-muted">{{ moneda(p.cost) }}</td>
            <td class="text-right tabular-nums font-semibold">{{ moneda(p.precio_pesos) }}<span v-if="p.moneda === 'USD'" class="block text-[10px] text-violeta font-normal">U$S {{ p.price }}</span></td>
            <td v-for="d in depositosActivos" :key="d.id" class="text-right tabular-nums" :class="(p.por_deposito[d.id] ?? 0) < 0 ? 'text-carmin' : 'text-marca-muted'">{{ p.controla ? cantidad(p.por_deposito[d.id] ?? 0) : '' }}</td>
            <td class="text-right tabular-nums font-bold" :class="!p.controla ? 'text-marca-muted' : p.stock <= 0 ? 'text-carmin' : p.bajo ? 'text-amber-600' : ''">{{ p.controla ? cantidad(p.stock) + ' ' + p.unit : '—' }}</td>
            <td class="text-right tabular-nums text-marca-muted">{{ p.controla ? cantidad(p.stock_min) : '' }}</td>
            <td class="text-right tabular-nums">{{ p.controla ? moneda(p.valor, 0) : '' }}</td>
            <td class="text-right"><button v-if="puede('stock','editar')" @click.stop="abrirArticulo(p)" class="btn-ghost !px-2"><Icono nombre="edit" clase="w-4 h-4" /></button></td>
          </tr>
          <tr v-if="!lista.data.length"><td :colspan="8 + depositosActivos.length" class="text-center text-marca-muted py-10">No hay artículos con estos filtros.</td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />

    <Modal :abierto="dolarAbierto" titulo="Cotización del dólar" @cerrar="dolarAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Los artículos en dólares se muestran y facturan en pesos con esta cotización. <b v-if="cotizacion">Hoy: {{ moneda(cotizacion.venta) }} ({{ cotizacion.manual ? 'fijada a mano' : 'automática, dólar oficial' }}, {{ cotizacion.fecha }}).</b></p>
      <label class="label">Fijar cotización a mano</label><input v-model.number="dol.venta" type="number" step="any" min="0" class="input" placeholder="Ej: 1450" />
      <template #pie><button class="btn-secondary" @click="dol.transform(() => ({ automatica: true })).post('/stock/cotizacion', { preserveScroll: true, onSuccess: () => (dolarAbierto = false) })">Usar la automática</button><button class="btn-primary" :disabled="!dol.venta" @click="dol.transform(d => ({ venta: d.venta })).post('/stock/cotizacion', { preserveScroll: true, onSuccess: () => (dolarAbierto = false) })">Fijar</button></template>
    </Modal>
    <ArticuloModal :abierto="artAbierto" :articulo="artEdit" :rubros="rubros" :depositos="depositos" :proveedores="proveedores" :tipos="tipos" :unidades="unidades" @cerrar="artAbierto = false" />

    <!-- Transferencia entre depósitos -->
    <Modal :abierto="transfAbierto" titulo="Transferir entre depósitos" ancho="max-w-2xl" @cerrar="transfAbierto = false">
      <div class="grid sm:grid-cols-3 gap-3 mb-3">
        <div><label class="label">Desde</label><select v-model="tf.origen_id" class="input"><option v-for="d in depositosActivos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></div>
        <div><label class="label">Hacia</label><select v-model="tf.destino_id" class="input"><option v-for="d in depositosActivos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select><p v-if="tf.errors.destino_id" class="text-carmin text-xs mt-1">{{ tf.errors.destino_id }}</p></div>
        <div><label class="label">Fecha</label><input v-model="tf.fecha" type="date" class="input" /></div>
      </div>
      <div v-for="(it, i) in tf.items" :key="i" class="grid grid-cols-[1fr_110px_28px] gap-2 mb-2">
        <BuscadorSelect v-model="it.product_id" :opciones="opcionesArticulos" placeholder="Artículo…" />
        <input v-model.number="it.cantidad" type="number" step="any" min="0" class="input text-right" placeholder="Cant." />
        <button @click="tf.items.splice(i, 1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
      </div>
      <button @click="tf.items.push({ product_id: null, cantidad: null })" class="btn-ghost !px-2 text-xs">+ Otro artículo</button>
      <input v-model="tf.notas" class="input mt-3" placeholder="Notas (camión, chofer, motivo…)" />
      <p v-if="tf.errors.items" class="text-carmin text-xs mt-2">{{ tf.errors.items }}</p>
      <template #pie><button class="btn-secondary" @click="transfAbierto = false">Cancelar</button><button class="btn-primary" :disabled="tf.processing" @click="tf.post('/stock/transferir', { preserveScroll: true, onSuccess: () => { transfAbierto = false; tf.reset('items', 'notas') } })">Transferir</button></template>
    </Modal>

    <!-- Actualizar precios -->
    <Modal :abierto="preciosAbierto" titulo="Actualizar precios en bloque" @cerrar="preciosAbierto = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div><label class="label">Porcentaje</label><input v-model.number="pr.porcentaje" type="number" step="any" class="input" placeholder="Ej: 8 (o -5 para bajar)" /><p v-if="pr.errors.porcentaje" class="text-carmin text-xs mt-1">{{ pr.errors.porcentaje }}</p></div>
        <div><label class="label">Sobre</label><select v-model="pr.campo" class="input"><option value="price">Precios de venta (todas las listas)</option><option value="cost">Costos</option><option value="ambos">Precios y costos</option></select></div>
        <div><label class="label">Solo el rubro</label><select v-model="pr.rubro_id" class="input"><option :value="null">Todos</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.completo }}</option></select></div>
        <div><label class="label">Solo el proveedor</label><select v-model="pr.proveedor_id" class="input"><option :value="null">Todos</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
        <div><label class="label">Redondear a</label><select v-model="pr.redondeo" class="input"><option value="0">Centavos</option><option value="1">$1</option><option value="10">$10</option><option value="100">$100</option></select></div>
      </div>
      <p class="text-xs text-marca-muted mt-3">Queda registrado en auditoría quién lo hizo y cuándo. La fecha de última actualización se ve en cada artículo.</p>
      <template #pie><button class="btn-secondary" @click="preciosAbierto = false">Cancelar</button><button class="btn-primary" :disabled="pr.processing || !pr.porcentaje" @click="pr.post('/stock/precios', { preserveScroll: true, onSuccess: () => (preciosAbierto = false) })">Aplicar {{ pr.porcentaje ? pr.porcentaje + '%' : '' }}</button></template>
    </Modal>

    <!-- Rubros y depósitos -->
    <Modal :abierto="configAbierto" titulo="Rubros y depósitos" ancho="max-w-2xl" @cerrar="configAbierto = false">
      <div class="grid md:grid-cols-2 gap-6">
        <div>
          <p class="label">Rubros</p>
          <div v-for="r in rubros" :key="r.id" class="flex items-center gap-2 py-1 text-sm" :class="r.parent_id ? 'pl-4' : ''">
            <span class="w-2.5 h-2.5 rounded-full" :style="{ background: r.color || '#6f6a62' }"></span><span class="flex-1">{{ r.nombre }}</span>
            <button @click="rubro.id = r.id; rubro.nombre = r.nombre; rubro.parent_id = r.parent_id; rubro.color = r.color || '#6f6a62'" class="text-xs text-violeta">editar</button>
            <Link :href="`/stock/rubros/${r.id}`" method="delete" as="button" preserve-scroll class="text-xs text-carmin">quitar</Link>
          </div>
          <div class="mt-3 p-3 rounded-xl bg-marca-fondo grid grid-cols-[1fr_auto] gap-2 items-end">
            <div><label class="label">{{ rubro.id ? 'Editar rubro' : 'Nuevo rubro' }}</label><input v-model="rubro.nombre" class="input !py-1.5" placeholder="Nombre" /></div>
            <input v-model="rubro.color" type="color" class="w-10 h-9 rounded-lg border border-marca-borde" />
            <select v-model="rubro.parent_id" class="input !py-1.5 col-span-2"><option :value="null">Rubro principal</option><option v-for="r in rubros.filter(x => !x.parent_id && x.id !== rubro.id)" :key="r.id" :value="r.id">Dentro de {{ r.nombre }}</option></select>
            <div class="col-span-2 flex gap-2"><button class="btn-primary !py-1 text-xs" :disabled="!rubro.nombre" @click="rubro.post(`/stock/rubros${rubro.id ? '/' + rubro.id : ''}`, { preserveScroll: true, onSuccess: () => rubro.reset() })">Guardar</button><button v-if="rubro.id" class="btn-ghost !py-1 text-xs" @click="rubro.reset()">Cancelar</button></div>
          </div>
        </div>
        <div>
          <p class="label">Depósitos</p>
          <div v-for="d in depositos" :key="d.id" class="flex items-center gap-2 py-1 text-sm" :class="!d.activo ? 'opacity-50' : ''">
            <span class="flex-1">{{ d.nombre }} <span class="text-xs text-marca-muted">· {{ d.sucursal }}</span><span v-if="d.es_default" class="badge bg-lavanda-light text-violeta ml-1">principal</span></span>
            <button @click="Object.assign(dep, { id: d.id, nombre: d.nombre, business_location_id: d.business_location_id, direccion: d.direccion, es_default: d.es_default, activo: d.activo })" class="text-xs text-violeta">editar</button>
          </div>
          <div class="mt-3 p-3 rounded-xl bg-marca-fondo grid gap-2">
            <div><label class="label">{{ dep.id ? 'Editar depósito' : 'Nuevo depósito' }}</label><input v-model="dep.nombre" class="input !py-1.5" placeholder="Ej: Galpón 2, Camión" /></div>
            <select v-model="dep.business_location_id" class="input !py-1.5"><option v-for="s in $page.props.sucursales?.lista ?? []" :key="s.id" :value="s.id">{{ s.nombre }}</option></select>
            <input v-model="dep.direccion" class="input !py-1.5" placeholder="Dirección (opcional)" />
            <div class="flex gap-4 text-xs"><label class="flex items-center gap-1"><input v-model="dep.es_default" type="checkbox" class="accent-carmin" /> Principal de la sucursal</label><label class="flex items-center gap-1"><input v-model="dep.activo" type="checkbox" class="accent-carmin" /> Activo</label></div>
            <div class="flex gap-2"><button class="btn-primary !py-1 text-xs" :disabled="!dep.nombre || !dep.business_location_id" @click="dep.post(`/stock/depositos${dep.id ? '/' + dep.id : ''}`, { preserveScroll: true, onSuccess: () => dep.reset() })">Guardar</button><button v-if="dep.id" class="btn-ghost !py-1 text-xs" @click="dep.reset()">Cancelar</button></div>
          </div>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import Paginacion from '@/Components/Paginacion.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import ArticuloModal from '@/Components/ArticuloModal.vue'
import { moneda, entero, cantidad, hoyISO } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ lista: Object, filtros: Object, depositos: Array, rubros: Array, tipos: Object, unidades: Object, kpis: Object, proveedores: Array, ultimosInventarios: Array, cotizacion: Object })
const { puede } = usePermisos()
const f = reactive({ buscar: props.filtros.buscar ?? '', rubro: props.filtros.rubro ?? '', tipo: props.filtros.tipo ?? '', estado: props.filtros.estado ?? '' })
function filtrar() { router.get('/stock', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
const depositosActivos = computed(() => props.depositos.filter(d => d.activo))

const artAbierto = ref(false), artEdit = ref(null)
function abrirArticulo(p = null) { artEdit.value = p ? { ...p, prices: undefined } : null; if (p) router.get(`/stock/${p.id}`); else artAbierto.value = true }

const transfAbierto = ref(false)
const tf = useForm({ origen_id: depositosActivos.value[0]?.id, destino_id: depositosActivos.value[1]?.id ?? depositosActivos.value[0]?.id, fecha: hoyISO(), notas: '', items: [{ product_id: null, cantidad: null }] })
const opcionesArticulos = computed(() => props.lista.data.filter(p => p.controla).map(p => ({ id: p.id, label: p.name, sub: p.sku, extra: cantidad(p.stock) + ' ' + p.unit })))

const preciosAbierto = ref(false), dolarAbierto = ref(false)
const dol = useForm({ venta: null })
const pr = useForm({ porcentaje: null, campo: 'price', rubro_id: null, proveedor_id: null, redondeo: '10' })

const configAbierto = ref(false)
const rubro = useForm({ id: null, nombre: '', parent_id: null, color: '#4f3089' })
const dep = useForm({ id: null, nombre: '', business_location_id: null, direccion: '', es_default: false, activo: true })
</script>
