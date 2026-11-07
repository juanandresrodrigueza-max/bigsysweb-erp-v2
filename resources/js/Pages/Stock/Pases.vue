<template>
  <AppLayout titulo="Pases entre sucursales">
    <!-- Fase 37.4 y 42: pases entre depósitos, pedidos de la sucursal al depósito y cambios o devoluciones a proveedor. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/stock/movimientos" class="text-xs text-marca-muted hover:text-carmin">← Movimientos</Link><h1 class="page-title">Pases entre sucursales</h1><p class="page-subtitle">Lo que sale se cuenta al salir y al llegar. Las sucursales piden al depósito y lo que va al proveedor queda anotado hasta que vuelve.</p></div>
    </div>
    <div class="grid lg:grid-cols-[1fr_1fr] gap-4">
      <div class="card" data-e2e="armar">
        <div class="flex gap-1 mb-3 text-sm" data-e2e="modos">
          <button v-for="(n, k) in modos" :key="k" class="px-3 py-1 rounded-full border" :class="modo === k ? 'bg-violeta text-white border-violeta' : 'border-marca-borde'" :disabled="!!pedido" @click="modo = k">{{ n }}</button>
        </div>
        <p v-if="pedido" class="text-sm bg-lavanda-light rounded-xl px-3 py-2 mb-2">Armando el pedido <b>{{ pedido.numero }}</b> de {{ pedido.destino }}. Escaneá lo que mandás: al lado ves lo pedido.</p>
        <div class="grid grid-cols-2 gap-2 text-sm mb-2">
          <label>{{ modo === 'pedido' ? 'Le pido a' : 'Sale de' }}<select v-model="f.origen_id" class="input"><option v-for="d in depositos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></label>
          <label v-if="modo !== 'proveedor'">{{ modo === 'pedido' ? 'Para' : 'Va a' }}<select v-model="f.destino_id" class="input" data-e2e="destino"><option v-for="d in depositos.filter(x => x.id !== f.origen_id)" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></label>
          <label v-else>Proveedor<select v-model="f.contact_id" class="input" data-e2e="proveedor"><option :value="null">Elegí…</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
        </div>
        <input ref="lector" v-model="codigo" class="input text-lg" placeholder="Escaneá (3*código para tres; etiquetas de balanza)" @keydown.enter.prevent="leer" data-e2e="lector" />
        <p v-if="msg" class="text-xs mt-1" :class="msgOk ? 'text-emerald-700' : 'text-carmin'">{{ msg }}</p>
        <table class="table text-sm mt-2"><tbody><tr v-for="(it, k) in items" :key="it.product_id + '-' + k"><td>{{ it.nombre }}<span v-if="it.pedido != null" class="block text-xs" :class="it.cantidad < it.pedido ? 'text-carmin' : 'text-marca-muted'">pedido {{ it.pedido }}</span></td>
          <td class="w-28"><input :ref="el => cant[it.product_id] = el" v-model.number="it.cantidad" type="number" step="any" class="input !py-0.5 text-right" @keydown.enter.prevent="volver" @focus="$event.target.select()" /></td><td class="text-xs">{{ it.unidad }}</td><td><button class="text-carmin text-xs" @click="items.splice(k, 1)">✕</button></td></tr>
          <tr v-if="!items.length"><td class="text-center text-marca-muted py-6">Escaneá lo que va. El cursor queda en la cantidad; Enter vuelve al lector.</td></tr></tbody></table>
        <input v-model="f.notas" class="input mt-2" :placeholder="modo === 'proveedor' ? 'Motivo (cambio, falla, vencido…)' : 'Notas (quién lo lleva, en qué vehículo)'" />
        <p v-if="f.errors.items" class="text-carmin text-xs mt-1">{{ f.errors.items }}</p>
        <button class="btn-primary w-full mt-2" :disabled="f.processing || !items.length || (modo === 'proveedor' ? !f.contact_id : !f.destino_id)" @click="enviar" data-e2e="enviar">{{ boton }}</button>
      </div>
      <div class="card p-0 overflow-x-auto" data-e2e="lista">
        <div class="px-4 py-3 border-b border-marca-borde flex flex-wrap gap-2 items-center text-sm" data-e2e="filtros">
          <h2 class="font-bold mr-auto">Pases</h2>
          <select v-model="fl.tipo" class="input !w-auto !py-1"><option value="">Todo</option><option value="pase">Pases</option><option value="pedido">Pedidos</option><option value="proveedor">A proveedor</option></select>
          <select v-model="fl.estado" class="input !w-auto !py-1"><option value="">Cualquier estado</option><option v-for="(n, k) in estados" :key="k" :value="k">{{ n }}</option></select>
          <select v-model="fl.deposito" class="input !w-auto !py-1"><option value="">Toda sucursal</option><option v-for="d in depositos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select>
          <input v-model="fl.desde" type="date" class="input !w-auto !py-1" /><input v-model="fl.q" class="input !w-36 !py-1" placeholder="Número o artículo" @keydown.enter="filtrar" />
          <button class="btn-secondary !py-1 text-xs" @click="filtrar">Buscar</button>
        </div>
        <table class="table text-sm"><thead><tr><th>Número</th><th>De → a</th><th>Estado</th></tr></thead>
          <tbody><tr v-for="p in pases" :key="p.id" class="cursor-pointer hover:bg-gris-light" @click="router.visit(`/stock/pases/${p.id}`)"><td class="font-semibold">{{ p.numero }}<span class="block text-xs text-marca-muted">{{ p.fecha }}</span></td><td>{{ p.tipo === 'pedido' ? p.destino + ' pide a ' + p.origen : p.tipo === 'proveedor' ? p.origen + ' → ' + p.proveedor : p.origen + ' → ' + p.destino }}</td>
            <td><span class="badge" :class="{ en_viaje: 'bg-violeta/10 text-violeta', pedido: 'bg-amber-50 text-amber-700', en_proveedor: 'bg-amber-50 text-amber-700', recibida: 'bg-emerald-50 text-emerald-700', vuelto: 'bg-emerald-50 text-emerald-700', armado: 'bg-emerald-50 text-emerald-700', con_diferencias: 'bg-carmin-light text-carmin' }[p.estado] ?? 'bg-gris-light'">{{ p.estado_label }}</span></td></tr>
            <tr v-if="!pases.length"><td colspan="3" class="text-center text-marca-muted py-6">No hay nada con esos filtros.</td></tr></tbody></table>
      </div>
    </div>
  </AppLayout>
</template>
<script setup>
import { ref, reactive, computed, nextTick, onMounted } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
const props = defineProps({ pases: Array, depositos: Array, proveedores: { type: Array, default: () => [] }, estados: Object, filtros: Object, pedido: Object })
const modos = { pase: 'Pase', pedido: 'Pedir al depósito', proveedor: 'A proveedor' }
const modo = ref('pase')
const f = useForm({ origen_id: props.pedido?.origen_id ?? props.depositos[0]?.id ?? null, destino_id: props.pedido?.destino_id ?? props.depositos[1]?.id ?? null, contact_id: null, notas: '', items: [], pedido_id: props.pedido?.id ?? null })
// Armar desde un pedido: lo pedido queda al lado, la cantidad arranca igual y se corrige con lo que hay.
const items = ref((props.pedido?.items ?? []).map(i => ({ product_id: i.product_id, nombre: i.articulo, unidad: i.unidad, cantidad: i.enviado, pedido: i.enviado })))
const codigo = ref(''), msg = ref(''), msgOk = ref(true), lector = ref(null), cant = {}
const boton = computed(() => modo.value === 'pedido' ? 'Hacer el pedido (todavía no mueve stock)' : modo.value === 'proveedor' ? 'Mandar al proveedor: sale del stock' : `Enviar: sale el stock de ${props.depositos.find(d => d.id === f.origen_id)?.nombre ?? ''}`)
async function leer() {
  if (!codigo.value.trim()) return
  const { data } = await window.axios.post('/stock/pases/leer', { codigo: codigo.value })
  codigo.value = ''
  if (!data.ok) { msg.value = data.mensaje; msgOk.value = false; return lector.value?.focus() }
  let it = items.value.find(i => i.product_id === data.product_id && !data.pesable)
  if (it) it.cantidad = Math.round((it.cantidad + data.cantidad) * 1000) / 1000
  else { it = { product_id: data.product_id, nombre: data.nombre, unidad: data.unidad, cantidad: data.cantidad, pedido: null }; items.value.unshift(it) }
  msg.value = `+${data.cantidad} ${data.nombre}`; msgOk.value = true
  // Fase 42.7: escanear lleva al renglón y deja el cursor en su cantidad.
  await nextTick(); cant[data.product_id]?.focus()
}
function volver() { lector.value?.focus() }
function enviar() {
  const url = modo.value === 'pedido' ? '/stock/pases/pedir' : modo.value === 'proveedor' ? '/stock/pases/proveedor' : '/stock/pases'
  f.transform(d => ({ ...d, items: items.value.filter(i => i.cantidad > 0).map(i => ({ product_id: i.product_id, cantidad: i.cantidad })) })).post(url)
}
const fl = reactive({ tipo: props.filtros?.tipo ?? '', estado: props.filtros?.estado ?? '', deposito: props.filtros?.deposito ?? '', desde: props.filtros?.desde ?? '', q: props.filtros?.q ?? '' })
function filtrar() { router.get('/stock/pases', Object.fromEntries(Object.entries(fl).filter(([, v]) => v)), { preserveState: true, preserveScroll: true }) }
onMounted(() => lector.value?.focus())
</script>
