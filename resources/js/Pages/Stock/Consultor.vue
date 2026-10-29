<template>
  <AppLayout titulo="Consultor de precios">
    <!-- Fase 32.4–32.5: precio y stock al toque. Empleados: buscar, las seis listas, el precio para un cliente, el simulador y la cotización
         por WhatsApp; el comprador ve además costo, margen y a qué proveedores comprar. Clientes: la pantalla del salón con su llave. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <Link href="/stock" class="text-xs text-marca-muted hover:text-carmin">← Stock</Link>
        <h1 class="page-title">Consultor de precios y stock</h1>
        <p class="page-subtitle">Sólo consulta: no vende ni toca el stock. Pasá el código con la pistola o buscá por nombre.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <select v-model.number="lista" class="input !py-1.5 w-auto" data-e2e="lista" @change="recordar(); rebuscar()"><option v-for="l in [1,2,3,4,5,6]" :key="l" :value="l">Lista {{ l }}</option><option v-if="veCostos" :value="0">Costo y margen</option></select>
        <select v-model="clienteId" class="input !py-1.5 w-56" data-e2e="cliente" @change="rebuscar"><option :value="null">Precio de lista</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        <button v-if="puedeConfigurar" class="btn-secondary !py-1.5" data-e2e="pantallas" @click="pantallasAbierto = true">Pantalla para clientes</button>
      </div>
    </div>

    <div class="grid lg:grid-cols-5 gap-4">
      <div class="lg:col-span-3 space-y-3">
        <div class="relative">
          <Icono nombre="search" clase="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-marca-muted" />
          <input ref="buscador" v-model="q" class="input !pl-10 !py-3 text-base" placeholder="Nombre, código, código de barras… (3*código entra con 3 al simulador)" data-e2e="buscador" autofocus @input="buscarDebounced" @keydown.enter.prevent="enter" @keydown.down.prevent="mover(1)" @keydown.up.prevent="mover(-1)" @keydown.escape="ficha = null" />
        </div>
        <p v-if="res.sin_codigo" class="text-xs text-amber-700" data-e2e="sin-codigo">{{ res.sin_codigo }}</p>
        <div class="card p-0 overflow-hidden">
          <div v-for="(p, i) in res.items" :key="p.id" class="flex items-center gap-3 px-3 py-2 border-b border-marca-borde/60 cursor-pointer" :class="i === sel ? 'bg-violeta/5' : 'hover:bg-gris-light/60'" :data-e2e="`res-${p.id}`" @click="abrir(p)">
            <img v-if="p.imagen" :src="p.imagen" class="w-10 h-10 rounded-lg object-cover bg-gris-light shrink-0" /><div v-else class="w-10 h-10 rounded-lg bg-gris-light shrink-0 grid place-items-center text-[10px] text-marca-muted">sin foto</div>
            <div class="flex-1 min-w-0"><p class="font-semibold truncate">{{ p.nombre }} <span v-if="p.discontinuado" class="badge bg-gris-light text-marca-muted">discontinuado</span></p><p class="text-xs text-marca-muted">{{ p.sku }}<span v-if="p.barcode"> · {{ p.barcode }}</span> · stock <b :class="p.controla && p.stock <= 0 ? 'text-carmin' : ''">{{ cantidad(p.stock) }} {{ p.unit }}</b><span v-if="p.promo"> · llevando {{ cantidad(p.promo.min) }} o más, {{ p.promo.pct }}% menos</span></p></div>
            <div class="text-right shrink-0">
              <template v-if="lista === 0"><p class="font-extrabold tabular-nums">{{ p.costo != null ? moneda(p.costo) : 'sin costo' }}</p><p class="text-xs" :class="margenClase(p.margen)">{{ p.margen != null ? 'margen ' + p.margen + '%' : '' }}</p></template>
              <template v-else><p class="font-extrabold tabular-nums text-lg">{{ moneda(p.cliente ? p.cliente.precio : p.precio) }}</p><p v-if="p.cliente && p.cliente.descuento" class="text-[11px] text-violeta">−{{ p.cliente.descuento }}% ({{ p.cliente.origen }})</p></template>
            </div>
          </div>
          <p v-if="!res.items.length" class="text-center text-sm text-marca-muted py-10">{{ q.trim() ? (buscando ? 'Buscando…' : 'Nada coincide.') : 'Escribí o escaneá para buscar.' }}</p>
        </div>
      </div>

      <!-- El simulador: una lista que se arma pasando códigos; se guarda en este dispositivo -->
      <div class="lg:col-span-2 space-y-3">
        <div class="card p-0 flex flex-col">
          <div class="px-4 py-3 border-b border-marca-borde flex items-center justify-between"><h2 class="font-bold">Simulador</h2><span class="text-xs text-marca-muted">{{ sim.length }} renglones</span></div>
          <div class="max-h-[46vh] overflow-y-auto">
            <div v-for="(it, i) in sim" :key="i" class="flex items-center gap-2 px-4 py-2 border-b border-marca-borde/60 text-sm" :data-e2e="`sim-${it.id}`">
              <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ it.nombre }}</p><p class="text-xs text-marca-muted tabular-nums">{{ moneda(it.precio) }} {{ it.unit ? 'por ' + it.unit : 'c/u' }}</p></div>
              <input v-model.number="it.cantidad" type="number" step="any" min="0" class="input !py-1 w-20 text-right" @change="guardarSim" />
              <span class="w-24 text-right font-semibold tabular-nums">{{ moneda(it.cantidad * it.precio) }}</span>
              <button class="text-marca-muted hover:text-carmin" @click="sim.splice(i, 1); guardarSim()"><Icono nombre="x" clase="w-4 h-4" /></button>
            </div>
            <p v-if="!sim.length" class="text-center text-sm text-marca-muted py-8">Pasá un código y Enter: entra uno. Escribí un número y Enter: es la cantidad del último.</p>
          </div>
          <div class="px-4 py-3 border-t border-marca-borde space-y-2">
            <div class="flex items-baseline justify-between"><span class="font-bold">TOTAL</span><span class="text-3xl font-black tabular-nums" data-e2e="sim-total">{{ moneda(totalSim) }}</span></div>
            <div class="flex gap-2"><input v-model="tel" class="input !py-1.5 text-sm flex-1" placeholder="WhatsApp del cliente (54911…)" inputmode="tel" @keydown.enter.prevent="cotizar(true)" /><button class="btn-primary !py-1.5" :disabled="!sim.length || cotizando" data-e2e="enviar-wa" @click="cotizar(true)">Enviar por WhatsApp</button></div>
            <div class="flex flex-wrap gap-2 text-xs"><button class="btn-secondary !py-1" :disabled="!sim.length || cotizando" @click="cotizar(false)">Imprimir / PDF</button><button class="btn-secondary !py-1" :disabled="!sim.length" @click="vaciar">Vaciar</button><span v-if="cot" class="self-center text-emerald-700" data-e2e="cot-ok">Cotización guardada 30 días · <a :href="cot.url" target="_blank" class="underline">ver</a></span></div>
          </div>
        </div>
        <details v-if="recientes.length" class="card !py-2 text-xs"><summary class="cursor-pointer font-semibold">Recientes de este dispositivo ({{ recientes.length }})</summary>
          <div v-for="(r, i) in recientes" :key="i" class="flex items-center justify-between gap-2 py-1 border-t border-marca-borde"><span>{{ r.fecha }} · {{ r.cliente || 'sin cliente' }} · {{ r.items.length }} art. · <b class="tabular-nums">{{ moneda(r.total) }}</b> <span class="text-marca-muted">{{ r.estado }}</span></span><span class="flex gap-2"><button class="text-violeta font-semibold" @click="sim = JSON.parse(JSON.stringify(r.items)); guardarSim()">Abrir</button><button class="text-carmin" @click="recientes.splice(i, 1); guardarRecientes()">borrar</button></span></div>
        </details>
      </div>
    </div>

    <!-- La ficha: precios, stock por depósito, costo y margen, proveedores -->
    <Modal :abierto="!!ficha" :titulo="ficha?.nombre ?? ''" @cerrar="ficha = null; foco()" ancho="max-w-2xl">
      <div v-if="ficha" class="text-sm" data-e2e="ficha">
        <div class="flex gap-4">
          <img v-if="ficha.imagen" :src="ficha.imagen" class="w-28 h-28 rounded-xl object-cover bg-gris-light" />
          <div class="flex-1">
            <p class="text-marca-muted">{{ ficha.sku }}<span v-if="ficha.barcode"> · {{ ficha.barcode }}</span><span v-if="ficha.rubro"> · {{ ficha.rubro }}</span></p>
            <p class="text-4xl font-black tabular-nums mt-1">{{ moneda(ficha.cliente ? ficha.cliente.precio : ficha.precio) }}</p>
            <p v-if="ficha.cliente" class="text-xs text-violeta">precio para {{ clienteNombre }} · {{ ficha.cliente.descuento ? '−' + ficha.cliente.descuento + '% (' + ficha.cliente.origen + ')' : 'de su lista' }}</p>
            <p v-if="ficha.promo" class="inline-block mt-1 rounded-full bg-amber-100 text-amber-900 font-bold px-2 py-0.5 text-xs">LLEVANDO {{ cantidad(ficha.promo.min) }} O MÁS, {{ ficha.promo.pct }}% MENOS</p>
            <div class="flex items-center gap-2 mt-3"><span class="text-xs text-marca-muted">Cantidad</span><input ref="cantInput" v-model.number="cantFicha" type="number" step="any" min="0.001" class="input !py-1 w-24 text-right" @keydown.enter.prevent="agregarFicha" /><button class="btn-primary !py-1" data-e2e="al-simulador" @click="agregarFicha">Al simulador · Enter</button></div>
          </div>
        </div>
        <div class="grid grid-cols-3 sm:grid-cols-6 gap-1.5 mt-4"><div v-for="(v, l) in ficha.precios" :key="l" class="rounded-lg border p-1.5 text-center" :class="Number(l) === lista ? 'border-violeta bg-violeta/5' : 'border-marca-borde'"><p class="text-[10px] text-marca-muted">Lista {{ l }}</p><p class="font-bold tabular-nums text-xs">{{ moneda(v) }}</p></div></div>
        <div class="grid sm:grid-cols-2 gap-3 mt-4">
          <div><p class="text-xs font-bold uppercase tracking-widest text-marca-muted">Stock</p><p class="font-bold" :class="ficha.controla && ficha.stock <= 0 ? 'text-carmin' : ''">{{ cantidad(ficha.stock) }} {{ ficha.unit }}</p><p v-for="d in ficha.depositos" :key="d.deposito" class="text-xs text-marca-muted">{{ d.deposito }}: {{ cantidad(d.cantidad) }}</p><p v-if="ficha.ultima_venta" class="text-xs text-marca-muted">última venta {{ ficha.ultima_venta }}</p></div>
          <div v-if="veCostos" data-e2e="costo"><p class="text-xs font-bold uppercase tracking-widest text-marca-muted">Costo y margen</p><p class="font-bold tabular-nums">{{ ficha.costo != null ? moneda(ficha.costo) : 'Sin costo cargado' }}</p><p v-if="ficha.margen != null" class="text-xs" :class="margenClase(ficha.margen)">margen {{ ficha.margen }}% sobre {{ ficha.cliente ? 'el precio del cliente' : 'la lista ' + lista }}</p></div>
        </div>
        <div v-if="ficha.proveedores" class="mt-4" data-e2e="proveedores">
          <p class="text-xs font-bold uppercase tracking-widest text-marca-muted mb-1">A qué proveedores comprar</p>
          <p v-if="!ficha.proveedores.length" class="text-xs text-marca-muted">Todavía no hay compras ni listas de proveedores con este artículo.</p>
          <div v-for="pr in ficha.proveedores" :key="pr.contact_id" class="flex flex-wrap items-center justify-between gap-2 py-1.5 border-t border-marca-borde text-xs">
            <span><b>{{ pr.proveedor }}</b> <span v-if="pr.es_ultimo" class="badge bg-emerald-50 text-emerald-700">último</span> <span class="text-marca-muted">· precio del {{ pr.fecha }}<span v-if="pr.ultima_compra"> · última compra {{ pr.ultima_compra }}</span></span></span>
            <span class="tabular-nums"><b>{{ moneda(pr.costo) }}</b><span v-if="pr.costo_usd" class="text-marca-muted"> (US$ {{ pr.costo_usd }})</span> <span :class="pr.mas_caro_pct > 0 ? 'text-carmin' : 'text-emerald-700'">{{ pr.mas_caro_pct > 0 ? '+' + pr.mas_caro_pct + '%' : 'el más barato' }}</span></span>
          </div>
        </div>
      </div>
    </Modal>

    <!-- Pantallas del salón: el Consultor en modo clientes -->
    <Modal :abierto="pantallasAbierto" titulo="Pantalla para clientes (salón)" @cerrar="pantallasAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Una pantalla para el monitor del salón: el cliente pasa el producto por el lector y ve el nombre, la foto y el precio final, bien grande. Tiene su propia llave de sólo lectura (no vence), actualiza los precios cada 10 minutos y sigue andando sin internet. La cartelera es la misma de la caja.</p>
      <div v-for="p in pantallasLista" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 py-2 border-t border-marca-borde text-sm" :data-e2e="`pantalla-${p.id}`">
        <span><b>{{ p.nombre }}</b> · lista {{ p.lista }} <span class="text-xs text-marca-muted">· desde {{ p.creada }}</span></span>
        <span class="flex gap-2 text-xs"><button class="btn-primary !py-1" @click="abrirPantalla(p)">Usar ésta</button><button class="btn-secondary !py-1" @click="renombrar(p)">Cambiar nombre o lista</button><button class="btn-ghost !py-1 text-carmin" @click="baja(p)">Dar de baja</button></span>
      </div>
      <div class="mt-3 rounded-xl bg-gris-light p-3">
        <p class="text-xs font-bold uppercase tracking-widest text-marca-muted mb-2">Armar una nueva</p>
        <div class="grid grid-cols-[1fr_110px_auto] gap-2"><input v-model="nueva.nombre" class="input !py-1.5 text-sm" placeholder="Góndola bebidas" data-e2e="pantalla-nombre" /><select v-model.number="nueva.lista" class="input !py-1.5 text-sm"><option v-for="l in [1,2,3,4,5,6]" :key="l" :value="l">Lista {{ l }}</option></select><button class="btn-primary !py-1.5" data-e2e="pantalla-crear" @click="crearPantalla">Armar y abrir</button></div>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'

const props = defineProps({ veCostos: Boolean, veProveedores: Boolean, clientes: Array, pantallas: Array, puedeConfigurar: Boolean })
const buscador = ref(null), cantInput = ref(null)
const q = ref(''), res = ref({ items: [] }), buscando = ref(false), sel = ref(0), ficha = ref(null), cantFicha = ref(1)
const lista = ref(Number(localStorage.getItem('consultor_lista') || 1)), clienteId = ref(null)
const clienteNombre = computed(() => props.clientes.find(c => c.id === clienteId.value)?.name)
function recordar() { try { localStorage.setItem('consultor_lista', String(lista.value)) } catch (e) {} }
const foco = () => nextTick(() => buscador.value?.focus())
const margenClase = m => m == null ? '' : m < 15 ? 'text-carmin' : m < 30 ? 'text-amber-700' : 'text-emerald-700'
let t = null
function buscarDebounced() { clearTimeout(t); t = setTimeout(buscar, 220) }
async function buscar() {
  const term = q.value.trim(); if (!term) { res.value = { items: [] }; return }
  buscando.value = true
  try { const { data } = await window.axios.get('/stock/consultor/buscar', { params: { q: term, lista: lista.value || 1, contact_id: clienteId.value } }); res.value = data; sel.value = 0 } catch (e) { res.value = { items: [] } }
  buscando.value = false
}
function rebuscar() { if (q.value.trim()) buscar(); if (ficha.value) abrir(ficha.value) }
function mover(d) { if (!res.value.items.length) return; sel.value = (sel.value + d + res.value.items.length) % res.value.items.length }
// Como una caja: un número solo y Enter es la cantidad del último renglón; un código exacto (o 3*código) entra directo al simulador.
async function enter() {
  const term = q.value.trim(); if (!term) return
  if (/^\d+([.,]\d+)?$/.test(term) && term.length < 6 && sim.value.length) { sim.value[sim.value.length - 1].cantidad = Number(term.replace(',', '.')); guardarSim(); q.value = ''; return }
  clearTimeout(t); await buscar()
  if (res.value.exacto && res.value.items.length === 1) { agregar(res.value.items[0], res.value.cantidad || 1); q.value = ''; res.value = { items: [] }; return }
  if (res.value.items.length) abrir(res.value.items[sel.value])
}
async function abrir(p) {
  try { const { data } = await window.axios.get(`/stock/consultor/articulo/${p.id}`, { params: { lista: lista.value || 1, contact_id: clienteId.value } }); ficha.value = data; cantFicha.value = res.value.cantidad || 1; nextTick(() => cantInput.value?.select?.()) } catch (e) {}
}
function agregarFicha() { if (!ficha.value) return; agregar(ficha.value, cantFicha.value || 1); ficha.value = null; q.value = ''; res.value = { items: [] }; foco() }
function agregar(p, cant) {
  const precio = p.cliente ? p.cliente.precio : (lista.value ? p.precio : p.precios[1])
  const ex = sim.value.find(i => i.id === p.id && i.precio === precio)
  if (ex) ex.cantidad = Math.round((ex.cantidad + cant) * 1000) / 1000; else sim.value.push({ id: p.id, nombre: p.nombre, unit: p.unit, precio, cantidad: cant })
  guardarSim()
}
// --- El simulador no se pierde: queda en este dispositivo, y las últimas diez listas en Recientes ---
const sim = ref([]), recientes = ref([]), tel = ref(''), cot = ref(null), cotizando = ref(false)
try { sim.value = JSON.parse(localStorage.getItem('consultor_sim') || '[]'); recientes.value = JSON.parse(localStorage.getItem('consultor_recientes') || '[]') } catch (e) {}
const guardarSim = () => { try { localStorage.setItem('consultor_sim', JSON.stringify(sim.value)) } catch (e) {} }
const guardarRecientes = () => { try { localStorage.setItem('consultor_recientes', JSON.stringify(recientes.value.slice(0, 10))) } catch (e) {} }
const totalSim = computed(() => Math.round(sim.value.reduce((a, i) => a + (Number(i.cantidad) || 0) * i.precio, 0) * 100) / 100)
function aRecientes(estado) { recientes.value.unshift({ fecha: new Date().toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }), cliente: clienteNombre.value ?? null, items: JSON.parse(JSON.stringify(sim.value)), total: totalSim.value, estado }); recientes.value = recientes.value.slice(0, 10); guardarRecientes() }
function vaciar() { if (!sim.value.length) return; aRecientes('vaciada'); sim.value = []; guardarSim(); cot.value = null; foco() }
async function cotizar(whatsapp) {
  cotizando.value = true
  try {
    const { data } = await window.axios.post('/stock/consultor/cotizar', { items: sim.value, contact_id: clienteId.value, cliente: clienteNombre.value ?? null, telefono: tel.value || null, lista: lista.value || 1 })
    cot.value = data; aRecientes(whatsapp ? 'enviada' : 'impresa')
    if (whatsapp) { if (data.whatsapp) window.open(data.whatsapp, '_blank'); else window.open('https://wa.me/?text=' + encodeURIComponent(data.texto), '_blank') } else window.open(data.pdf, '_blank')
  } catch (e) {}
  cotizando.value = false
}
// --- Pantallas del salón ---
const pantallasAbierto = ref(false), pantallasLista = ref([...props.pantallas]), nueva = ref({ nombre: '', lista: 1 })
async function crearPantalla() { const { data } = await window.axios.post('/stock/consultor/pantallas', nueva.value); pantallasLista.value.push({ id: data.id, nombre: data.nombre, lista: data.lista, creada: 'hoy' }); nueva.value = { nombre: '', lista: 1 }; window.open(data.url, '_blank') }
async function abrirPantalla(p) { const { data } = await window.axios.get(`/stock/consultor/pantallas/${p.id}/url`); window.open(data.url, '_blank') }
function renombrar(p) { const nombre = window.prompt('Nombre de la pantalla', p.nombre); if (nombre === null) return; const l = Number(window.prompt('Lista de precios (1 a 6)', String(p.lista)) || p.lista); router.patch(`/stock/consultor/pantallas/${p.id}`, { nombre, lista: l }, { preserveScroll: true, onSuccess: () => { p.nombre = nombre; p.lista = l } }) }
function baja(p) { if (!window.confirm(`¿Dar de baja «${p.nombre}»? Su llave deja de servir.`)) return; router.delete(`/stock/consultor/pantallas/${p.id}`, { preserveScroll: true, onSuccess: () => (pantallasLista.value = pantallasLista.value.filter(x => x.id !== p.id)) }) }
onMounted(foco)
</script>
