<template>
  <AppLayout titulo="Tienda y canales">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs />
    <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1 w-fit mb-4"><button v-for="t in tabs" :key="t.k" class="px-4 py-1.5 rounded-full text-sm font-semibold" :class="tab === t.k ? 'bg-carmin text-white' : 'text-marca-muted'" @click="tab = t.k">{{ t.label }}</button></div>

    <!-- Tienda propia -->
    <div v-if="tab === 'tienda'" class="grid lg:grid-cols-3 gap-4">
      <form class="card lg:col-span-2 space-y-4" @submit.prevent="ft.post('/configuracion/tienda', { preserveScroll: true })">
        <div class="flex items-center justify-between"><h2 class="font-bold">Tienda online propia</h2><label class="flex items-center gap-2 text-sm font-semibold"><input v-model="ft.tienda.activa" type="checkbox" class="accent-carmin" /> Activa</label></div>
        <p class="text-sm text-marca-muted">Tus clientes ven el catálogo, arman el carrito y el pedido te llega a <Link href="/comprobantes/pedidos" class="underline">Pedidos</Link>. Sin comisiones, con tus precios y tu stock.</p>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label">Dirección de la tienda</label><div class="flex items-center gap-1 text-sm"><span class="text-marca-muted whitespace-nowrap">{{ base }}/t/</span><input v-model="ft.tienda.slug" class="input" /></div><p v-if="ft.errors['tienda.slug']" class="text-carmin text-xs mt-1">{{ ft.errors['tienda.slug'] }}</p></div>
          <div><label class="label">Nombre visible</label><input v-model="ft.tienda.nombre" class="input" /></div>
          <div class="sm:col-span-2"><label class="label">Texto de bienvenida</label><input v-model="ft.tienda.descripcion" class="input" placeholder="Ej. Materiales de construcción con entrega en el día en Córdoba" /></div>
          <div><label class="label">WhatsApp de contacto</label><input v-model="ft.tienda.whatsapp" class="input" placeholder="549351..." /></div>
          <div><label class="label">Lista de precios</label><select v-model.number="ft.tienda.lista_precios" class="input"><option v-for="n in [1,2,3,4,5]" :key="n" :value="n">Lista {{ n }}</option></select></div>
          <div><label class="label">Pedido mínimo $</label><input v-model.number="ft.tienda.minimo_pedido" type="number" class="input" /></div>
          <label class="flex items-center gap-2 text-sm mt-6"><input v-model="ft.tienda.iva_incluido" type="checkbox" class="accent-carmin" /> Mostrar precios con IVA incluido</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="ft.tienda.mostrar_stock" type="checkbox" class="accent-carmin" /> Mostrar si hay stock</label>
        </div>
        <div class="grid sm:grid-cols-2 gap-3 p-3 rounded-xl bg-marca-fondo">
          <p class="sm:col-span-2 text-[11px] font-bold uppercase tracking-widest text-marca-muted">Entrega</p>
          <label class="flex items-center gap-2 text-sm"><input v-model="ft.tienda.retiro" type="checkbox" class="accent-carmin" /> Retiro en el local</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="ft.tienda.envio" type="checkbox" class="accent-carmin" /> Envío a domicilio</label>
          <div v-if="ft.tienda.envio"><label class="label">Costo de envío $</label><input v-model.number="ft.tienda.costo_envio" type="number" class="input" /></div>
          <div v-if="ft.tienda.envio"><label class="label">Envío gratis desde $</label><input v-model.number="ft.tienda.envio_gratis_desde" type="number" class="input" /></div>
          <div v-if="ft.tienda.envio" class="sm:col-span-2"><label class="label">Zona de envío (texto)</label><input v-model="ft.tienda.zona_envio" class="input" placeholder="Ej. Córdoba capital y Villa Allende" /></div>
        </div>
        <div class="grid sm:grid-cols-3 gap-3 p-3 rounded-xl bg-marca-fondo">
          <p class="sm:col-span-3 text-[11px] font-bold uppercase tracking-widest text-marca-muted">Formas de pago</p>
          <label class="flex items-center gap-2 text-sm"><input v-model="ft.tienda.pagos.link" type="checkbox" class="accent-carmin" /> Link de pago (MercadoPago)</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="ft.tienda.pagos.transferencia" type="checkbox" class="accent-carmin" /> Transferencia</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="ft.tienda.pagos.efectivo" type="checkbox" class="accent-carmin" /> Efectivo al recibir</label>
          <div v-if="ft.tienda.pagos.transferencia"><label class="label">CBU</label><input v-model="ft.tienda.cbu" class="input" /></div>
          <div v-if="ft.tienda.pagos.transferencia"><label class="label">Alias</label><input v-model="ft.tienda.alias" class="input" /></div>
        </div>
        <div><label class="label">Rubros que se muestran (vacío = todos)</label><div class="flex flex-wrap gap-2"><label v-for="r in rubros" :key="r.id" class="flex items-center gap-1 text-sm px-2 py-1 rounded-full border border-marca-borde"><input type="checkbox" :value="r.id" v-model="ft.tienda.rubros" class="accent-carmin" /> {{ r.nombre }}</label></div></div>
        <div class="grid sm:grid-cols-2 gap-3 p-3 rounded-xl bg-marca-fondo">
          <p class="sm:col-span-2 text-[11px] font-bold uppercase tracking-widest text-marca-muted">Gastronomía</p>
          <label class="flex items-center gap-2 text-sm"><input v-model="ft.tienda.menu_activo" type="checkbox" class="accent-carmin" /> Menú QR activo (<a :href="urlMenu" target="_blank" class="underline">ver</a>)</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="ft.tienda.reservas_activas" type="checkbox" class="accent-carmin" /> Reservas online activas (<a :href="urlReservas" target="_blank" class="underline">ver</a>)</label>
          <div v-if="ft.tienda.reservas_activas"><label class="label">Horarios (texto)</label><input v-model="ft.tienda.reservas_horario" class="input" /></div>
          <div v-if="ft.tienda.reservas_activas"><label class="label">Capacidad por turno (personas)</label><input v-model.number="ft.tienda.reservas_capacidad" type="number" class="input" /></div>
        </div>
        <div class="flex justify-end"><button class="btn-primary" :disabled="ft.processing">Guardar</button></div>
      </form>
      <div class="space-y-4">
        <div class="card">
          <h2 class="font-bold mb-1">Tu tienda</h2>
          <p class="text-sm break-all"><a :href="urlTienda" target="_blank" class="underline text-violeta">{{ urlTienda }}</a></p>
          <p class="text-xs text-marca-muted mt-1">{{ config.activa ? 'Activa: compartila por WhatsApp, redes o ponela en el ticket.' : 'Todavía no está activa.' }}</p>
          <div class="flex justify-center mt-3"><canvas ref="qr"></canvas></div>
        </div>
        <div class="card">
          <h2 class="font-bold mb-1">Artículos publicados</h2>
          <p class="text-sm text-marca-muted">{{ enTienda }} de {{ totalArticulos }} artículos aparecen en la tienda. Se marca en cada artículo (Stock → editar → "Publicado en la tienda").</p>
          <div class="flex gap-2 mt-2"><button class="btn-secondary !py-1 text-xs" @click="router.post('/configuracion/tienda/articulos', { ids: 'todos', en_tienda: true })" disabled title="Marcá desde la ficha de cada artículo">Publicar todos</button><Link href="/stock" class="btn-ghost !py-1 text-xs">Ir a stock</Link></div>
        </div>
      </div>
    </div>

    <!-- Canales -->
    <div v-if="tab === 'canales'" class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2">
        <div class="flex items-center justify-between mb-1"><h2 class="font-bold">Marketplaces y delivery</h2><button class="btn-primary !py-1 text-xs" @click="abrirCanal()">Conectar canal</button></div>
        <p class="text-sm text-marca-muted mb-3">Los pedidos de MercadoLibre, WooCommerce, Shopify, PedidosYa y Rappi entran a la misma bandeja. El stock se empuja hacia los canales que lo permiten.</p>
        <div v-for="c in canales" :key="c.id" class="border-t border-marca-borde/60 py-3 text-sm">
          <div class="flex items-center justify-between gap-2">
            <div><p class="font-medium">{{ c.nombre }} <span class="badge bg-lavanda-light text-violeta ml-1">{{ c.tipo_label }}</span><span class="badge ml-1" :class="c.activo ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ c.activo ? 'activo' : 'pausado' }}</span></p><p class="text-xs text-marca-muted">{{ c.pedidos_importados }} pedidos importados · último sync {{ c.ultimo_sync ?? 'nunca' }}<span v-if="c.sync_stock"> · empuja stock</span></p><p v-if="c.ultimo_error" class="text-xs text-carmin">{{ c.ultimo_error }}</p></div>
            <div class="flex gap-1 shrink-0"><button v-if="['woocommerce','shopify','mercadolibre'].includes(c.tipo)" class="btn-ghost !px-2 text-xs" @click="router.post(`/configuracion/tienda/canales/${c.id}/sincronizar`, {}, { preserveScroll: true })">Sincronizar</button><button class="btn-ghost !px-2 text-xs" @click="abrirCanal(c)">Editar</button><button class="btn-ghost !px-2 text-xs text-carmin" @click="router.delete(`/configuracion/tienda/canales/${c.id}`, { preserveScroll: true })">Borrar</button></div>
          </div>
          <p class="text-[11px] text-marca-muted mt-1">Webhook de entrada: <code class="select-all break-all">{{ c.url_entrada }}</code></p>
        </div>
        <p v-if="!canales.length" class="text-sm text-marca-muted">Sin canales conectados.</p>
      </div>
      <div class="card">
        <h2 class="font-bold mb-1">Pedidos por WhatsApp</h2>
        <p class="text-sm text-marca-muted mb-3">Con la API de WhatsApp Cloud (Meta) los mensajes se convierten en pedidos solos y el cliente recibe la confirmación. Sin API, pegás el mensaje en Pedidos → "Pedido por WhatsApp".</p>
        <p class="text-xs mb-2"><span class="badge" :class="whatsapp.configurado ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ whatsapp.configurado ? 'API conectada' : 'Sin API (modo manual)' }}</span> <span class="badge ml-1" :class="whatsapp.ia ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ whatsapp.ia ? 'interpreta con IA' : 'interpretación básica' }}</span></p>
        <form @submit.prevent="fw.post('/configuracion/tienda/whatsapp', { preserveScroll: true })" class="space-y-2">
          <input v-model="fw.phone_id" class="input" placeholder="Phone number ID" />
          <input v-model="fw.token" class="input" placeholder="Token permanente (vacío = no cambiar)" />
          <div class="text-[11px] text-marca-muted">Webhook: <code class="select-all break-all">{{ whatsapp.url_entrada }}</code><br>Verify token: <code class="select-all">{{ whatsapp.verify_token ?? '(se genera al guardar)' }}</code></div>
          <label class="flex items-center gap-2 text-sm"><input v-model="fw.auto_pedidos" type="checkbox" class="accent-carmin" /> Crear el pedido automáticamente</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="fw.auto_responder" type="checkbox" class="accent-carmin" /> Responder automáticamente</label>
          <button class="btn-primary w-full" :disabled="fw.processing">Guardar</button>
        </form>
      </div>
    </div>

    <!-- Fidelización -->
    <div v-if="tab === 'puntos'" class="grid lg:grid-cols-3 gap-4">
      <form class="card lg:col-span-2 space-y-4" @submit.prevent="ff.post('/configuracion/tienda/fidelizacion', { preserveScroll: true })">
        <div class="flex items-center justify-between"><h2 class="font-bold">Programa de puntos</h2><label class="flex items-center gap-2 text-sm font-semibold"><input v-model="ff.activo" type="checkbox" class="accent-carmin" /> Activo</label></div>
        <p class="text-sm text-marca-muted">Cada factura suma puntos al cliente; los canjea como descuento en la próxima compra (botón "Usar puntos" en la factura) o desde <Link href="/clientes/fidelizacion" class="underline">Clientes → Puntos</Link>.</p>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label">1 punto por cada $</label><input v-model.number="ff.pesos_por_punto" type="number" class="input" /></div>
          <div><label class="label">Cada punto vale $</label><input v-model.number="ff.valor_punto" type="number" step="any" class="input" /></div>
          <div><label class="label">Canje mínimo (puntos)</label><input v-model.number="ff.minimo_canje" type="number" class="input" /></div>
          <div><label class="label">Puntos de bienvenida</label><input v-model.number="ff.bienvenida" type="number" class="input" /></div>
          <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="ff.excluir_cf" type="checkbox" class="accent-carmin" /> No sumar puntos a "Consumidor Final" (ventas sin cliente)</label>
        </div>
        <p class="text-sm p-3 rounded-xl bg-lavanda-light">Ejemplo: una compra de $ 100.000 suma <b>{{ Math.floor(100000 / (ff.pesos_por_punto || 1)) }} puntos</b>, que valen <b>{{ moneda(Math.floor(100000 / (ff.pesos_por_punto || 1)) * ff.valor_punto, 0) }}</b> de descuento ({{ (Math.floor(100000 / (ff.pesos_por_punto || 1)) * ff.valor_punto / 1000).toFixed(1) }}%).</p>
        <div class="flex justify-end"><button class="btn-primary" :disabled="ff.processing">Guardar</button></div>
      </form>
    </div>

    <Modal :abierto="canalAbierto" :titulo="fc.id ? 'Editar canal' : 'Conectar canal'" @cerrar="canalAbierto = false">
      <div class="space-y-3">
        <div><label class="label">Plataforma</label><select v-model="fc.tipo" class="input" :disabled="!!fc.id"><option v-for="(t, k) in tiposCanal" :key="k" :value="k">{{ t.label }}</option></select><p class="text-xs text-marca-muted mt-1">{{ tiposCanal[fc.tipo]?.ayuda }}</p></div>
        <div><label class="label">Nombre</label><input v-model="fc.nombre" class="input" placeholder="Ej. Tienda ML" /></div>
        <div v-for="(lbl, k) in tiposCanal[fc.tipo]?.campos ?? {}" :key="k"><label class="label">{{ lbl }}</label><input v-model="fc.credenciales[k]" class="input" :placeholder="fc.id && fc.credenciales[k] === '' ? '(guardado; vacío = no cambiar)' : ''" /></div>
        <div class="grid grid-cols-2 gap-2 text-sm"><label class="flex items-center gap-2"><input v-model="fc.activo" type="checkbox" class="accent-carmin" /> Activo</label><label class="flex items-center gap-2"><input v-model="fc.importar_pedidos" type="checkbox" class="accent-carmin" /> Importar pedidos</label><label class="flex items-center gap-2"><input v-model="fc.sync_stock" type="checkbox" class="accent-carmin" /> Empujar stock</label><label class="flex items-center gap-2"><input v-model="fc.sync_precios" type="checkbox" class="accent-carmin" /> Empujar precios</label></div>
      </div>
      <template #pie><button class="btn-secondary" @click="canalAbierto = false">Cancelar</button><button class="btn-primary" :disabled="fc.processing || !fc.nombre" @click="fc.post(`/configuracion/tienda/canales${fc.id ? '/' + fc.id : ''}`, { preserveScroll: true, onSuccess: () => (canalAbierto = false) })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import QRCode from 'qrcode'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ config: Object, urlTienda: String, urlMenu: String, urlReservas: String, rubros: Array, enTienda: Number, totalArticulos: Number, fidelizacion: Object, canales: Array, tiposCanal: Object, whatsapp: Object })
const tabs = [{ k: 'tienda', label: 'Tienda propia y menú' }, { k: 'canales', label: 'Marketplaces, delivery y WhatsApp' }, { k: 'puntos', label: 'Programa de puntos' }]
const tab = ref(location.hash === '#canales' ? 'canales' : location.hash === '#puntos' ? 'puntos' : 'tienda')
const base = location.origin
const ft = useForm({ tienda: JSON.parse(JSON.stringify({ ...props.config, rubros: props.config.rubros ?? [] })) })
const ff = useForm({ ...props.fidelizacion })
const fw = useForm({ phone_id: props.whatsapp.phone_id ?? '', token: '', verify_token: '', auto_pedidos: true, auto_responder: true })
const qr = ref(null)
onMounted(() => { if (qr.value) QRCode.toCanvas(qr.value, props.urlTienda, { width: 140, margin: 1 }).catch(() => {}) })
const canalAbierto = ref(false)
const fc = useForm({ id: null, tipo: 'woocommerce', nombre: '', credenciales: {}, activo: true, sync_stock: true, sync_precios: false, importar_pedidos: true })
function abrirCanal(c = null) { fc.clearErrors(); Object.assign(fc, c ? { id: c.id, tipo: c.tipo, nombre: c.nombre, credenciales: Object.fromEntries(Object.keys(props.tiposCanal[c.tipo].campos).map(k => [k, ''])), activo: c.activo, sync_stock: c.sync_stock, sync_precios: c.sync_precios, importar_pedidos: c.importar_pedidos } : { id: null, tipo: 'woocommerce', nombre: '', credenciales: {}, activo: true, sync_stock: true, sync_precios: false, importar_pedidos: true }); canalAbierto.value = true }
</script>
