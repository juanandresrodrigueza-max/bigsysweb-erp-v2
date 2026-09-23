<template>
  <AppLayout titulo="Pedidos web">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Pedidos</h1><p class="page-subtitle">Lo que entra por la tienda, el portal, WhatsApp, marketplaces, delivery y el menú QR. Confirmás y sale la factura.</p></div>
      <div class="flex gap-2">
        <button class="btn-secondary" @click="waAbierto = true">Pedido por WhatsApp</button>
        <a v-if="tiendaActiva" :href="tiendaUrl" target="_blank" class="btn-secondary">Ver tienda</a>
        <Link v-else href="/configuracion/tienda" class="btn-secondary">Activar tienda</Link>
        <Link href="/comprobantes" class="btn-secondary">Comprobantes</Link>
      </div>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Nuevos sin confirmar</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.nuevos ? 'text-carmin' : ''">{{ kpis.nuevos }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En curso</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.en_curso }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Pedidos de hoy</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.hoy }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vendido hoy por canales</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.hoy_monto, 0) }}</p></div>
    </div>
    <div class="flex flex-wrap gap-2 mb-4">
      <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1"><Link v-for="e in [['', 'Activos'], ['nuevo', 'Nuevos'], ['confirmado', 'Confirmados'], ['preparando', 'Preparando'], ['enviado', 'En camino'], ['entregado', 'Entregados'], ['cancelado', 'Cancelados']]" :key="e[0]" :href="`/comprobantes/pedidos?estado=${e[0]}${filtros.canal ? '&canal=' + filtros.canal : ''}`" class="px-3 py-1 rounded-full text-xs font-semibold" :class="(filtros.estado ?? '') === e[0] ? 'bg-carmin text-white' : 'text-marca-muted'">{{ e[1] }}</Link></div>
      <select :value="filtros.canal ?? ''" class="input w-auto !py-1 text-xs" @change="router.get('/comprobantes/pedidos', { estado: filtros.estado, canal: $event.target.value || undefined })"><option value="">Todos los canales</option><option v-for="(l, k) in canales" :key="k" :value="k">{{ l }}</option></select>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card p-0 overflow-hidden">
        <div class="divide-y divide-marca-borde/60 max-h-[70vh] overflow-y-auto">
          <button v-for="p in pedidos" :key="p.id" class="w-full text-left px-4 py-3 hover:bg-marca-fondo" :class="sel?.id === p.id ? 'bg-lavanda-light/50' : ''" @click="sel = p">
            <div class="flex items-center justify-between gap-2"><span class="text-xs text-marca-muted tabular-nums">{{ p.numero }} · {{ p.hace }}</span><span class="badge" :class="estadoClase(p.estado)">{{ p.estado_label }}</span></div>
            <p class="font-medium text-sm mt-0.5 truncate">{{ p.cliente.nombre }} <span class="text-marca-muted font-normal">· {{ moneda(p.total, 0) }}</span></p>
            <p class="text-xs text-marca-muted">{{ p.canal_label }} · {{ p.items.length }} ítem/s · {{ p.entrega === 'envio' ? 'envío' : p.entrega === 'mesa' ? 'mesa' : 'retira' }}</p>
          </button>
          <p v-if="!pedidos.length" class="text-sm text-marca-muted p-6 text-center">Sin pedidos con ese filtro.</p>
        </div>
      </div>
      <div v-if="sel" class="card lg:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
          <div><p class="text-xs text-marca-muted">{{ sel.numero }} · {{ sel.canal_label }} · {{ sel.creado }}<span v-if="sel.external_id"> · ref. {{ sel.external_id }}</span></p><h2 class="font-bold text-lg">{{ sel.cliente.nombre }}</h2><p class="text-sm text-marca-muted">{{ sel.cliente.telefono }} <span v-if="sel.cliente.email">· {{ sel.cliente.email }}</span><span v-if="sel.cliente.direccion"> · {{ sel.cliente.direccion }}</span></p><p v-if="sel.cliente.notas" class="text-sm mt-1 italic">“{{ sel.cliente.notas }}”</p></div>
          <span class="badge text-sm" :class="estadoClase(sel.estado)">{{ sel.estado_label }}</span>
        </div>
        <table class="table text-sm mb-3">
          <thead><tr><th>Artículo</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Total</th></tr></thead>
          <tbody><tr v-for="(it, i) in sel.items" :key="i"><td>{{ it.descripcion }}<span v-if="!it.product_id" class="badge bg-amber-50 text-amber-700 ml-1">sin artículo</span></td><td class="text-right tabular-nums">{{ cantidad(it.cantidad) }} {{ it.unit ?? '' }}</td><td class="text-right tabular-nums">{{ moneda(it.precio_unit) }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(it.total) }}</td></tr></tbody>
          <tfoot><tr v-if="sel.envio"><td colspan="3" class="text-right text-marca-muted">Envío</td><td class="text-right tabular-nums">{{ moneda(sel.envio) }}</td></tr><tr v-if="sel.descuento"><td colspan="3" class="text-right text-marca-muted">Descuento</td><td class="text-right tabular-nums">−{{ moneda(sel.descuento) }}</td></tr><tr class="font-bold"><td colspan="3" class="text-right">Total</td><td class="text-right tabular-nums">{{ moneda(sel.total) }}</td></tr></tfoot>
        </table>
        <div class="text-sm text-marca-muted mb-3">Entrega: <b class="text-marca-texto">{{ { retiro: 'retira en el local', envio: 'envío a domicilio', mesa: 'en mesa' }[sel.entrega] }}</b> · Pago: <b class="text-marca-texto">{{ { link: 'link de pago', transferencia: 'transferencia', efectivo: 'efectivo al recibir', a_convenir: 'a convenir', pagado_externo: 'pagado en la plataforma' }[sel.pago] ?? sel.pago }}</b>
          <span v-if="sel.comprobante"> · <Link :href="`/comprobantes/${sel.comprobante_id}`" class="underline text-violeta">{{ sel.comprobante }}</Link> <span class="badge ml-1" :class="sel.cobrado ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ sel.cobrado ? 'cobrado' : 'sin cobrar' }}</span></span>
          <span v-if="sel.link_pago"> · <a :href="sel.link_pago" target="_blank" class="underline">link de pago</a></span>
        </div>
        <details v-if="sel.texto" class="text-xs text-marca-muted mb-3"><summary class="cursor-pointer">Mensaje original</summary><pre class="whitespace-pre-wrap bg-marca-fondo rounded-lg p-2 mt-1">{{ sel.texto }}</pre></details>
        <div class="flex flex-wrap gap-2">
          <template v-if="sel.estado === 'nuevo'">
            <button class="btn-primary" @click="confirmar(sel, 'FX')">Confirmar y facturar</button>
            <button class="btn-secondary" @click="confirmar(sel, 'PRE')">Confirmar como presupuesto</button>
            <button class="btn-ghost text-carmin" @click="estado(sel, 'cancelado')">Cancelar</button>
          </template>
          <template v-else-if="sel.estado !== 'entregado' && sel.estado !== 'cancelado'">
            <button v-if="sel.estado === 'confirmado'" class="btn-primary" @click="estado(sel, 'preparando')">Preparando</button>
            <button v-if="['confirmado', 'preparando'].includes(sel.estado)" class="btn-secondary" @click="estado(sel, 'enviado')">{{ sel.entrega === 'envio' ? 'Salió el envío' : 'Listo para retirar' }}</button>
            <button class="btn-secondary" @click="estado(sel, 'entregado')">Entregado</button>
            <button class="btn-ghost text-carmin" @click="estado(sel, 'cancelado')">Cancelar</button>
          </template>
          <template v-if="sel.envio_datos?.shipment_id">
            <a :href="`/comprobantes/pedidos/${sel.id}/etiqueta`" target="_blank" class="btn-secondary text-xs">Etiqueta Mercado Envíos</a>
            <button class="btn-ghost text-xs" @click="router.post(`/comprobantes/pedidos/${sel.id}/envio`, {}, { preserveScroll: true })">Estado del envío{{ sel.envio_datos.estado ? ` · ${sel.envio_datos.estado}` : '' }}{{ sel.envio_datos.tracking ? ` · ${sel.envio_datos.tracking}` : '' }}</button>
          </template>
          <button v-if="sel.cliente.telefono" class="btn-ghost text-xs" @click="respAbierto = true">Avisar por WhatsApp</button>
          <a :href="sel.url_publica" target="_blank" class="btn-ghost text-xs">Ver como el cliente</a>
        </div>
      </div>
      <div v-else class="card lg:col-span-2 flex items-center justify-center text-sm text-marca-muted min-h-48">Elegí un pedido.</div>
    </div>

    <Modal :abierto="waAbierto" titulo="Pedido por WhatsApp" ancho="max-w-2xl" @cerrar="waAbierto = false">
      <div v-if="!interp" class="space-y-3">
        <p class="text-sm text-marca-muted">Pegá el mensaje tal cual te lo mandó el cliente. El sistema identifica cantidades y artículos {{ $page.props.ia ? 'con IA' : '' }} y vos revisás antes de crear el pedido.</p>
        <input v-model="wa.telefono" class="input" placeholder="Teléfono del cliente (opcional)" />
        <textarea v-model="wa.texto" rows="5" class="input" placeholder="Ej: Hola! me mandás 10 bolsas de cemento y 3 hierros del 8 a Colón 1234? soy Marcelo"></textarea>
      </div>
      <div v-else class="space-y-3">
        <p class="text-xs text-marca-muted">Interpretado ({{ interp.modo === 'ia' ? 'con IA' : 'modo básico' }}). Corregí lo que haga falta.</p>
        <table class="table text-sm"><thead><tr><th>Artículo</th><th class="w-24 text-right">Cant.</th><th class="w-8"></th></tr></thead>
          <tbody><tr v-for="(it, i) in cw.items" :key="i"><td>{{ it.descripcion }}</td><td><input v-model.number="it.cantidad" type="number" step="any" class="input !py-1 text-right" /></td><td><button class="text-marca-muted hover:text-carmin" @click="cw.items.splice(i, 1)">✕</button></td></tr>
          <tr v-if="!cw.items.length"><td colspan="3" class="text-center text-marca-muted py-3">No se identificó ningún artículo. Cargalo a mano desde Comprobantes.</td></tr></tbody></table>
        <div class="grid sm:grid-cols-2 gap-2"><input v-model="cw.nombre" class="input" placeholder="Nombre del cliente" /><input v-model="cw.direccion" class="input" placeholder="Dirección de entrega" /><select v-model="cw.entrega" class="input"><option value="retiro">Retira</option><option value="envio">Envío</option></select><input v-model="cw.notas" class="input" placeholder="Notas" /></div>
        <div class="p-3 rounded-xl bg-lavanda-light text-sm"><p class="text-[11px] font-bold uppercase tracking-widest text-violeta mb-1">Respuesta sugerida</p><p>{{ interp.respuesta }}</p></div>
      </div>
      <template #pie>
        <button class="btn-secondary" @click="waAbierto = false; interp = null">Cancelar</button>
        <button v-if="!interp" class="btn-primary" :disabled="wa.processing || !wa.texto" @click="wa.post('/comprobantes/pedidos/interpretar', { preserveScroll: true })">{{ wa.processing ? 'Interpretando…' : 'Interpretar' }}</button>
        <button v-else class="btn-primary" :disabled="cw.processing || !cw.items.length" @click="cw.post('/comprobantes/pedidos/whatsapp', { onSuccess: () => { waAbierto = false; interp = null } })">Crear pedido</button>
      </template>
    </Modal>
    <Modal :abierto="respAbierto" titulo="Avisar por WhatsApp" @cerrar="respAbierto = false">
      <textarea v-model="resp.texto" rows="4" class="input"></textarea>
      <template #pie><button class="btn-secondary" @click="respAbierto = false">Cancelar</button><button class="btn-primary" :disabled="resp.processing || !resp.texto" @click="resp.post(`/comprobantes/pedidos/${sel.id}/responder`, { preserveScroll: true, onSuccess: () => (respAbierto = false) })">Enviar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'
const props = defineProps({ pedidos: Array, filtros: Object, canales: Object, estados: Object, kpis: Object, abrirId: Number, tiendaActiva: Boolean, tiendaUrl: String, interpretacion: Object })
const page = usePage()
const sel = ref(props.pedidos.find(p => p.id === props.abrirId) ?? props.pedidos[0] ?? null)
watch(() => props.pedidos, ps => { sel.value = ps.find(p => p.id === sel.value?.id) ?? ps.find(p => p.id === props.abrirId) ?? ps[0] ?? null })
const estadoClase = e => ({ nuevo: 'bg-red-50 text-carmin', confirmado: 'bg-lavanda-light text-violeta', preparando: 'bg-amber-50 text-amber-700', enviado: 'bg-sky-50 text-sky-700', entregado: 'bg-emerald-50 text-emerald-700', cancelado: 'bg-gris-light text-marca-muted' }[e])
function confirmar(p, tipo) { router.post(`/comprobantes/pedidos/${p.id}/confirmar`, { tipo }, { preserveScroll: true }) }
function estado(p, e) { router.post(`/comprobantes/pedidos/${p.id}/estado`, { estado: e }, { preserveScroll: true }) }
const waAbierto = ref(false); const wa = useForm({ texto: '', telefono: '' })
const interp = ref(null)
const cw = useForm({ texto: '', telefono: '', items: [], nombre: '', direccion: '', entrega: 'retiro', notas: '' })
watch(() => page.props.flash?.interpretacion ?? props.interpretacion, i => { if (i) { interp.value = i; Object.assign(cw, { texto: i.texto, telefono: i.telefono ?? wa.telefono, items: i.items.map(x => ({ ...x })), nombre: i.nombre ?? '', direccion: i.direccion ?? '', entrega: i.entrega ?? 'retiro', notas: i.notas ?? '' }); waAbierto.value = true } }, { immediate: true })
const respAbierto = ref(false)
const resp = useForm({ texto: '' })
watch(respAbierto, v => { if (v && sel.value) resp.texto = `Hola ${sel.value.cliente.nombre}! Tu pedido ${sel.value.numero} está ${sel.value.estado_label.toLowerCase()}. Total $ ${Number(sel.value.total).toLocaleString('es-AR')}. Seguilo acá: ${sel.value.url_publica}` })
</script>
