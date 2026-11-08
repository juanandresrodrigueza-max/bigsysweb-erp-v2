<template>
  <AppLayout :titulo="comanda.titulo">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <Link href="/gastronomia" class="text-xs text-marca-muted hover:text-carmin">← Salón</Link>
        <h1 class="page-title">{{ comanda.titulo }}</h1>
        <p class="page-subtitle">{{ comanda.numero }} · abierta {{ comanda.abierta }} ({{ comanda.minutos }} min) · {{ comanda.mozo }}<span v-if="comanda.cubiertos"> · {{ comanda.cubiertos }} cubiertos</span><span v-if="comanda.direccion"> · {{ comanda.direccion }} · {{ comanda.telefono }}</span></p>
        <span class="badge mt-1" :class="{ abierta: 'bg-emerald-50 text-emerald-700', cuenta: 'bg-violeta text-white', cerrada: 'bg-gris-light text-marca-muted', anulada: 'bg-carmin-light text-carmin' }[comanda.estado]">{{ { abierta: 'Abierta', cuenta: 'Pidió la cuenta', cerrada: 'Cerrada', anulada: 'Anulada' }[comanda.estado] }}</span>
      </div>
      <div v-if="abierta" class="flex flex-wrap gap-2">
        <button v-if="comanda.tipo === 'mesa' && puede('gastronomia','crear')" @click="moverAbierto = true" class="btn-ghost text-xs">Mover de mesa</button>
        <button v-if="puede('gastronomia','editar')" @click="anular" class="btn-ghost text-xs text-carmin">Anular</button>
        <Link v-if="comanda.estado === 'abierta' && puede('gastronomia','crear')" :href="`/gastronomia/comandas/${comanda.id}/cuenta`" method="post" as="button" preserve-scroll class="btn-secondary">Pidió la cuenta</Link>
        <Link v-if="comanda.pendientes_envio && puede('gastronomia','crear')" :href="`/gastronomia/comandas/${comanda.id}/enviar`" method="post" as="button" preserve-scroll class="btn-violeta"><Icono nombre="send" clase="w-4 h-4" /> Enviar a cocina ({{ comanda.pendientes_envio }})</Link>
        <button v-if="puede('gastronomia','crear')" @click="abrirCierre" class="btn-primary" :disabled="!comanda.items.length">{{ comanda.pagos.length ? 'Cobrar lo que falta ' + moneda(comanda.pendiente, 0) : 'Cerrar y cobrar ' + moneda(comanda.total, 0) }}</button>
      </div>
      <Link v-else-if="comanda.comprobante_id" :href="`/comprobantes/${comanda.comprobante_id}`" class="btn-secondary">Ver comprobante</Link>
    </div>

    <div class="grid lg:grid-cols-5 gap-4">
      <div class="lg:col-span-3 space-y-3" v-if="abierta && puede('gastronomia','crear')">
        <input v-model="q" class="input !py-3 text-base" placeholder="Buscar en la carta…" />
        <div class="flex gap-1.5 overflow-x-auto pb-1">
          <button @click="rubroSel = null" class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border" :class="rubroSel === null ? 'bg-marca-texto text-white border-marca-texto' : 'bg-white border-marca-borde text-marca-muted'">Favoritos</button>
          <button v-for="r in rubros" :key="r.id" @click="rubroSel = r.id" class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border" :class="rubroSel === r.id ? 'text-white border-transparent' : 'bg-white border-marca-borde text-marca-muted'" :style="rubroSel === r.id ? { background: r.color || '#4f3089' } : {}">{{ r.nombre }}</button>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2 max-h-[58vh] overflow-y-auto pr-1">
          <button v-for="p in visibles" :key="p.id" @click="pedir(p)" class="card !p-3 text-left hover:border-carmin/50 active:scale-[0.98] transition relative">
            <span class="absolute top-2 right-2 w-2 h-2 rounded-full" :style="{ background: p.color || '#d6d1ca' }"></span>
            <p class="font-semibold text-sm leading-tight line-clamp-2 min-h-[2.4em]">{{ p.name }}</p>
            <p class="text-lg font-extrabold tabular-nums mt-1">{{ moneda(p.price, 0) }}</p>
            <p class="text-[11px] text-marca-muted">{{ p.va_cocina ? 'cocina' : 'directo' }}</p>
          </button>
        </div>
      </div>

      <div class="card p-0 flex flex-col" :class="abierta && puede('gastronomia','crear') ? 'lg:col-span-2' : 'lg:col-span-5'">
        <div class="px-4 py-3 border-b border-marca-borde flex items-center justify-between"><h2 class="font-bold">Comanda</h2><span class="text-xs text-marca-muted">{{ comanda.items.length }} ítems</span></div>
        <div class="flex-1 overflow-y-auto max-h-[60vh]">
          <template v-for="ronda in rondas" :key="ronda">
            <p class="px-4 pt-3 pb-1 text-[10px] font-bold uppercase tracking-widest text-marca-muted">Pedido {{ ronda }}</p>
            <div v-for="it in comanda.items.filter(i => i.ronda === ronda)" :key="it.id" class="flex items-center gap-2 px-4 py-2 border-b border-marca-borde/60 text-sm" :class="it.estado === 'anulado' ? 'opacity-40 line-through' : ''">
              <span class="w-8 text-right font-bold tabular-nums">{{ cantidad(it.cantidad) }}×</span>
              <div class="flex-1 min-w-0"><p class="font-medium">{{ it.descripcion }}</p><p v-if="it.notas" class="text-xs text-carmin">{{ it.notas }}</p><p v-if="it.pagado > 0" class="text-[11px] text-emerald-700">{{ it.pagado >= it.cantidad - 0.0005 ? 'pagado' : 'pagado ' + cantidad(it.pagado) }}</p></div>
              <span class="badge" :class="{ pedido: 'bg-gris-light text-marca-muted', cocina: 'bg-amber-50 text-amber-700', listo: 'bg-emerald-50 text-emerald-700', entregado: 'bg-lavanda-light text-violeta', anulado: 'bg-carmin-light text-carmin' }[it.estado]">{{ { pedido: 'sin enviar', cocina: 'en cocina', listo: 'listo', entregado: 'entregado', anulado: 'anulado' }[it.estado] }}</span>
              <span class="w-20 text-right tabular-nums">{{ moneda(it.cantidad * it.precio_unit, 0) }}</span>
              <template v-if="abierta && puede('gastronomia','crear')">
                <Link v-if="it.estado === 'listo'" :href="`/gastronomia/items/${it.id}/estado`" method="post" :data="{ estado: 'entregado' }" as="button" preserve-scroll class="btn-ghost !px-2 text-xs text-emerald-700" title="Entregado">✓</Link>
                <button v-if="it.estado === 'pedido'" @click="notaDe = it" class="btn-ghost !px-2 text-xs" title="Nota">✎</button>
                <Link v-if="it.estado !== 'entregado' && it.estado !== 'anulado'" :href="`/gastronomia/comandas/${comanda.id}/items/${it.id}`" method="delete" as="button" preserve-scroll class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></Link>
              </template>
            </div>
          </template>
          <p v-if="!comanda.items.length" class="text-center text-marca-muted text-sm py-12">Todavía no pidieron nada.</p>
        </div>
        <div class="px-4 py-3 border-t border-marca-borde">
          <div class="flex items-baseline justify-between"><span class="font-bold">TOTAL</span><span class="text-2xl font-black tabular-nums">{{ moneda(comanda.total) }}</span></div>
          <p v-if="comanda.propina" class="text-xs text-marca-muted text-right">+ propina {{ moneda(comanda.propina) }}</p>
          <div v-if="comanda.pagos.length" class="mt-2 text-xs"><p v-for="(pg, i) in comanda.pagos" :key="i" class="flex justify-between text-emerald-700"><span>Parte {{ i + 1 }} · {{ pg.hora }}</span><Link :href="`/comprobantes/${pg.comprobante_id}`" class="underline">{{ moneda(pg.total) }}</Link></p>
            <p class="flex justify-between font-bold mt-1"><span>Falta cobrar</span><span>{{ moneda(comanda.pendiente) }}</span></p></div>
        </div>
      </div>
    </div>

    <!-- Nota para cocina -->
    <Modal :abierto="!!notaDe" :titulo="notaDe ? `Nota para ${notaDe.descripcion}` : ''" @cerrar="notaDe = null">
      <input v-if="notaDe" v-model="nota" class="input" placeholder="Sin cebolla, bien cocido, para llevar…" @keydown.enter="guardarNota" />
      <template #pie><button class="btn-secondary" @click="notaDe = null">Cancelar</button><button class="btn-primary" @click="guardarNota">Guardar</button></template>
    </Modal>

    <!-- Fase 49: opciones del plato -->
    <Modal :abierto="!!modDe" :titulo="modDe ? modDe.name : ''" @cerrar="modDe = null">
      <div v-if="modDe" class="space-y-3">
        <div v-for="g in modDe.modificadores" :key="g.nombre">
          <p class="label">{{ g.nombre }} <span v-if="g.obligatorio" class="text-carmin normal-case">· obligatorio</span><span v-if="g.multiple" class="normal-case font-normal"> · podés elegir varios</span></p>
          <div class="flex flex-wrap gap-1.5"><button v-for="o in g.opciones" :key="o.nombre" type="button" class="px-3 py-1.5 rounded-full border text-sm" :class="(elegidos[g.nombre] ?? []).includes(o.nombre) ? 'bg-carmin text-white border-carmin' : 'bg-white border-marca-borde'" @click="elegir(g, o.nombre)" :data-mod="o.nombre">{{ o.nombre }}<span v-if="o.precio"> +{{ moneda(o.precio, 0) }}</span></button></div>
        </div>
      </div>
      <template #pie><button class="btn-secondary" @click="modDe = null">Cancelar</button><button class="btn-primary" :disabled="faltaObligatorio" @click="confirmarMods" data-e2e="mods-agregar">Agregar {{ moneda(modDe ? modDe.price + extraMods : 0, 0) }}</button></template>
    </Modal>

    <Modal :abierto="moverAbierto" titulo="Mover a otra mesa" @cerrar="moverAbierto = false">
      <select v-model="mesaDestino" class="input"><option v-for="m in mesas.filter(x => x.id !== comanda.mesa_id)" :key="m.id" :value="m.id">{{ m.sector }} · Mesa {{ m.nombre }}</option></select>
      <template #pie><button class="btn-secondary" @click="moverAbierto = false">Cancelar</button><button class="btn-primary" :disabled="!mesaDestino" @click="router.post(`/gastronomia/comandas/${comanda.id}/mover`, { mesa_id: mesaDestino }, { onSuccess: () => (moverAbierto = false) })">Mover</button></template>
    </Modal>

    <!-- Cierre -->
    <Modal :abierto="cierreAbierto" :titulo="`Cerrar ${comanda.titulo}`" @cerrar="cierreAbierto = false">
      <div class="flex gap-1 mb-3 p-1 rounded-xl bg-marca-fondo text-xs font-semibold" data-e2e="modo-cobro">
        <button v-for="m in [['todo', comanda.pagos.length ? 'Lo que falta' : 'Todo junto'], ['items', 'Cada uno lo suyo'], ['iguales', 'Partes iguales']]" :key="m[0]" class="flex-1 py-1.5 rounded-lg" :class="modo === m[0] ? 'bg-white shadow text-marca-texto' : 'text-marca-muted'" @click="cambiarModo(m[0])">{{ m[1] }}</button>
      </div>
      <div v-if="modo === 'items'" class="mb-3 max-h-48 overflow-y-auto border border-marca-borde rounded-xl">
        <div v-for="it in pendientes" :key="it.id" class="flex items-center gap-2 px-3 py-1.5 text-sm border-b border-marca-borde/60">
          <span class="flex-1 truncate">{{ it.descripcion }}</span><span class="text-xs text-marca-muted">de {{ cantidad(it.cantidad - it.pagado) }}</span>
          <input v-model.number="partes[it.id]" type="number" min="0" :max="it.cantidad - it.pagado" step="any" class="input !py-0.5 !w-16 text-right" @input="actualizarMonto" :data-parte="it.id" />
        </div>
      </div>
      <div v-if="modo === 'iguales'" class="mb-3 flex items-center gap-2 text-sm">Dividir lo que falta en <input v-model.number="dividir" type="number" min="2" max="50" class="input !py-1 !w-16 text-center" @input="actualizarMonto" /> partes · esta parte: <b>{{ moneda(totalCobrar, 0) }}</b></div>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div><label class="label">Cliente (factura)</label><select v-model="cierre.contact_id" class="input !py-1.5 text-sm"><option :value="null">Consumidor final</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div><label class="label">Descuento $</label><input v-model.number="cierre.descuento" type="number" step="any" min="0" class="input !py-1.5" /></div>
        <div><label class="label">Propina $</label><input v-model.number="cierre.propina" type="number" step="any" min="0" class="input !py-1.5" /><div class="flex gap-1 mt-1"><button v-for="p in [5, 10]" :key="p" @click="cierre.propina = Math.round(totalCobrar * p / 100)" class="px-2 py-0.5 rounded bg-marca-fondo text-[11px] font-semibold">{{ p }}%</button></div></div>
        <div class="p-3 rounded-xl bg-marca-fondo"><p class="text-[11px] text-marca-muted">A cobrar</p><p class="text-xl font-black tabular-nums">{{ moneda(totalCobrar + (cierre.propina || 0)) }}</p><p v-if="cierre.propina" class="text-[11px] text-marca-muted">incluye propina</p></div>
      </div>
      <div class="grid grid-cols-4 gap-2 mb-3">
        <button v-for="m in [['efectivo','Efectivo'],['tarjeta','Tarjeta'],['mercadopago','MP / QR'],['transferencia','Transfer.']]" :key="m[0]" @click="cierre.medios = [{ medio: m[0], monto: totalCobrar + (cierre.propina || 0) }]" class="py-2 rounded-xl border text-xs font-semibold" :class="cierre.medios.length === 1 && cierre.medios[0].medio === m[0] ? 'bg-carmin text-white border-carmin' : 'bg-white border-marca-borde'">{{ m[1] }}</button>
      </div>
      <div v-for="(m, i) in cierre.medios" :key="i" class="grid grid-cols-[1fr_130px_28px] gap-2 mb-2">
        <select v-model="m.medio" class="input"><option v-for="(l, k) in medios" :key="k" :value="k">{{ l }}</option></select>
        <input v-model.number="m.monto" type="number" step="any" min="0" class="input text-right font-bold tabular-nums" />
        <button @click="cierre.medios.splice(i, 1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
      </div>
      <button @click="cierre.medios.push({ medio: 'tarjeta', monto: 0 })" class="btn-ghost !px-2 text-xs">+ Otro medio de pago</button>
      <div class="mt-3 flex justify-between text-sm" :class="pagado >= totalCobrar + (cierre.propina || 0) - 0.005 ? 'text-emerald-700' : 'text-carmin'"><span>Pagado {{ moneda(pagado) }}</span><b>{{ pagado >= totalCobrar + (cierre.propina || 0) ? 'Vuelto ' + moneda(pagado - totalCobrar - (cierre.propina || 0)) : 'Falta ' + moneda(totalCobrar + (cierre.propina || 0) - pagado) }}</b></div>
      <p v-if="cierre.errors.medios || cierre.errors.items" class="text-carmin text-xs mt-2">{{ cierre.errors.medios || cierre.errors.items }}</p>
      <template #pie><button class="btn-secondary" @click="cierreAbierto = false">Cancelar</button><button class="btn-primary" :disabled="cierre.processing || totalCobrar <= 0 || pagado < totalCobrar + (cierre.propina || 0) - 0.005" @click="cobrarAhora" data-e2e="cobrar">{{ modo === 'todo' ? 'Cobrar y facturar' : 'Cobrar esta parte' }}</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ comanda: Object, productos: Array, rubros: Array, medios: Object, cuentas: Array, clientes: Array, mesas: Array })
const { puede } = usePermisos()
const abierta = computed(() => ['abierta', 'cuenta'].includes(props.comanda.estado))
const rondas = computed(() => [...new Set(props.comanda.items.map(i => i.ronda))].sort())
const q = ref(''), rubroSel = ref(null)
const norm = s => (s ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
const visibles = computed(() => q.value.trim() ? props.productos.filter(p => norm(p.name).includes(norm(q.value))) : rubroSel.value ? props.productos.filter(p => p.rubro_id === rubroSel.value) : (props.productos.filter(p => p.favorito).length ? props.productos.filter(p => p.favorito) : props.productos.slice(0, 24)))
function pedir(p) { if (p.modificadores?.length) { elegidos.value = {}; modDe.value = p; return } router.post(`/gastronomia/comandas/${props.comanda.id}/items`, { product_id: p.id, cantidad: 1 }, { preserveScroll: true, onSuccess: () => (q.value = '') }) }
const notaDe = ref(null), nota = ref('')
function guardarNota() { const it = notaDe.value; router.delete(`/gastronomia/comandas/${props.comanda.id}/items/${it.id}`, { preserveScroll: true, onSuccess: () => router.post(`/gastronomia/comandas/${props.comanda.id}/items`, { product_id: it.product_id, descripcion: it.descripcion, cantidad: it.cantidad, precio_unit: it.precio_unit, notas: nota.value }, { preserveScroll: true, onSuccess: () => { notaDe.value = null; nota.value = '' } }) }) }
const moverAbierto = ref(false), mesaDestino = ref(null)
function anular() { const motivo = window.prompt('Motivo de la anulación:'); if (motivo) router.post(`/gastronomia/comandas/${props.comanda.id}/anular`, { motivo }) }
const cierreAbierto = ref(false)
const cierre = useForm({ contact_id: null, descuento: 0, propina: 0, medios: [{ medio: 'efectivo', monto: 0 }], a_cuenta: false, partes: null, dividir: null })
// Fase 49: dividir la cuenta. El descuento se reparte en proporción a lo que paga cada parte.
const modo = ref('todo'), partes = ref({}), dividir = ref(2)
const pendientes = computed(() => props.comanda.items.filter(i => i.estado !== 'anulado' && i.cantidad - i.pagado > 0.0005))
const bruto = computed(() => props.comanda.items.filter(i => i.estado !== 'anulado').reduce((a, i) => a + i.cantidad * i.precio_unit, 0))
const factorDesc = computed(() => bruto.value > 0 ? 1 - (cierre.descuento || 0) / bruto.value : 1)
const totalCobrar = computed(() => {
  const pend = pendientes.value.reduce((a, i) => a + (i.cantidad - i.pagado) * i.precio_unit, 0)
  const base = modo.value === 'items' ? pendientes.value.reduce((a, i) => a + Math.min(Number(partes.value[i.id]) || 0, i.cantidad - i.pagado) * i.precio_unit, 0) : modo.value === 'iguales' ? pend / Math.max(1, dividir.value || 1) : pend
  return Math.max(0, Math.round(base * factorDesc.value * 100) / 100)
})
function actualizarMonto() { cierre.medios = [{ medio: cierre.medios[0]?.medio ?? 'efectivo', monto: totalCobrar.value + (cierre.propina || 0) }] }
function cambiarModo(m) { modo.value = m; partes.value = {}; actualizarMonto() }
function cobrarAhora() {
  cierre.partes = modo.value === 'items' ? Object.fromEntries(Object.entries(partes.value).filter(([, v]) => Number(v) > 0)) : null
  cierre.dividir = modo.value === 'iguales' ? dividir.value : null
  cierre.post(`/gastronomia/comandas/${props.comanda.id}/cerrar`, { preserveScroll: true, onSuccess: () => { if (props.comanda.estado === 'cerrada') return; if (modo.value === 'iguales' && dividir.value > 1) dividir.value--; partes.value = {}; cierre.propina = 0; actualizarMonto(); if (props.comanda.pendiente <= 0) cierreAbierto.value = false } })
}
// Opciones del plato al pedir
const modDe = ref(null), elegidos = ref({})
const extraMods = computed(() => (modDe.value?.modificadores ?? []).reduce((a, g) => a + (elegidos.value[g.nombre] ?? []).reduce((b, n) => b + (Number(g.opciones.find(o => o.nombre === n)?.precio) || 0), 0), 0))
const faltaObligatorio = computed(() => (modDe.value?.modificadores ?? []).some(g => g.obligatorio && !(elegidos.value[g.nombre] ?? []).length))
function elegir(g, n) { const a = elegidos.value[g.nombre] ?? []; elegidos.value[g.nombre] = a.includes(n) ? a.filter(x => x !== n) : (g.multiple ? [...a, n] : [n]) }
function confirmarMods() { const p = modDe.value; router.post(`/gastronomia/comandas/${props.comanda.id}/items`, { product_id: p.id, cantidad: 1, modificadores: elegidos.value }, { preserveScroll: true, onSuccess: () => { modDe.value = null; q.value = '' } }) }
const pagado = computed(() => cierre.medios.reduce((a, m) => a + (Number(m.monto) || 0), 0))
function abrirCierre() { cierre.descuento = props.comanda.descuento; cierre.propina = 0; modo.value = 'todo'; partes.value = {}; cierre.medios = [{ medio: 'efectivo', monto: totalCobrar.value }]; cierreAbierto.value = true }
</script>
