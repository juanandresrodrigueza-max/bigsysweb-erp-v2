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
          <div><label class="label">Lista de precios</label><select v-model.number="ft.tienda.lista_precios" class="input"><option v-for="n in [1,2,3,4,5,6]" :key="n" :value="n">Lista {{ n }}</option></select></div>
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
      <div class="card lg:col-span-2" data-e2e="tiendas">
        <div class="flex items-center justify-between mb-1"><h2 class="font-bold">Tus tiendas</h2><Link href="/stock/tienda-online" class="btn-secondary !py-1 text-xs">Publicar y emparejar →</Link></div>
        <p class="text-sm text-marca-muted mb-3">Conectás cada tienda una vez, entrando con tu usuario y sin copiar claves. De ahí en más: publicás desde el catálogo (Stock → Tienda online), el stock y el precio se mantienen solos, cada venta entra a Comprobantes → Pedidos y la factura vuelve al comprador.</p>
        <div v-for="t in plataformas" :key="t.k" class="border-t border-marca-borde/60 py-3 text-sm" :data-e2e="`tienda-${t.k}`">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold">{{ t.label }}<template v-for="c in canalesDe(t.k)" :key="c.id"><span class="badge ml-1" :class="c.conectado ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ c.conectado ? 'conectada' : 'desconectada' }}</span><span v-if="c.ultimo_error" class="badge bg-amber-50 text-amber-800 ml-1">con aviso</span></template></p>
              <p v-for="c in canalesDe(t.k)" :key="c.id" class="text-xs text-marca-muted">{{ c.nombre }}<span v-if="c.cuenta"> · cuenta {{ c.cuenta }}</span><span v-if="c.conectado_en"> · desde {{ c.conectado_en }}</span> · {{ c.publicadas }} publicadas · {{ c.pedidos_importados }} pedidos<span v-if="c.avisos"> · {{ c.avisos }} avisos de venta</span></p>
              <p v-for="c in canalesDe(t.k).filter(x => x.ultimo_error)" :key="'e' + c.id" class="text-xs text-amber-800">{{ c.ultimo_error }}</p>
              <p v-if="!canalesDe(t.k).length" class="text-xs text-marca-muted">{{ tiposCanal[t.k].ayuda }}</p>
            </div>
            <div class="flex flex-wrap gap-1 shrink-0">
              <template v-for="c in canalesDe(t.k)" :key="c.id">
                <button class="btn-ghost !px-2 text-xs" @click="abrirAjustes(c)" :data-e2e="`ajustes-${c.id}`">Ajustes</button>
                <button v-if="c.conectado" class="btn-ghost !px-2 text-xs" @click="router.post(`/configuracion/tienda/canales/${c.id}/probar`, {}, { preserveScroll: true })">Probar la conexión</button>
                <button v-if="c.conectado && ['woocommerce','shopify','mercadolibre','tiendanube'].includes(c.tipo)" class="btn-ghost !px-2 text-xs" @click="router.post(`/configuracion/tienda/canales/${c.id}/sincronizar`, {}, { preserveScroll: true })">Traer ventas</button>
                <button v-if="c.conectado" class="btn-ghost !px-2 text-xs text-carmin" @click="confirm('¿Desconectar? Lo publicado y los ajustes quedan.') && router.post(`/configuracion/tienda/canales/${c.id}/desconectar`, {}, { preserveScroll: true })">Desconectar</button>
              </template>
              <template v-if="!canalesDe(t.k).some(c => c.conectado)">
                <a v-if="t.k === 'mercadolibre' || t.k === 'tiendanube'" :href="oauth[t.k] ? `/conectar/${t.k}` : null" class="btn-primary !py-1 text-xs" :class="!oauth[t.k] ? 'opacity-50 cursor-not-allowed' : ''" :title="!oauth[t.k] ? 'Falta configurar la aplicación en el panel de BigSys: avisale a soporte' : ''" :data-e2e="`conectar-${t.k}`" @click="!oauth[t.k] && $event.preventDefault()">Conectar con {{ t.label }}</a>
                <button v-if="t.k === 'woocommerce'" class="btn-primary !py-1 text-xs" @click="wooAbierto = true" data-e2e="conectar-woocommerce">Conectar con WooCommerce</button>
                <button v-if="t.k !== 'empretienda'" class="btn-secondary !py-1 text-xs" @click="abrirClaves(t.k)" :data-e2e="`claves-${t.k}`">{{ t.k === 'shopify' ? 'Conectar (app a medida)' : 'Pegar claves' }}</button>
                <Link v-else href="/stock/tienda-online?empretienda=1" class="btn-secondary !py-1 text-xs">Bajar la planilla</Link>
              </template>
            </div>
          </div>
          <p v-for="c in canalesDe(t.k)" :key="'w' + c.id" class="text-[11px] text-marca-muted mt-1">Avisos de venta: <code class="select-all break-all">{{ c.url_entrada }}</code><span v-if="c.avisos"> (creados solos en la tienda)</span></p>
        </div>
        <div v-if="datosPersonales.length" class="mt-3 rounded-xl border border-amber-300 bg-amber-50/40 p-3 text-sm" data-e2e="datos-personales">
          <p class="font-semibold">Pedidos de datos personales (Tienda Nube)</p>
          <p class="text-xs text-marca-muted mb-2">Si pidió que le borren los datos, el sistema ya lo hizo (el comprobante queda: la ley fiscal obliga a conservarlo). Si pidió verlos, lo contestás vos: buscá sus compras en Pedidos, armale el detalle, mandáselo y marcá "Ya le contesté".</p>
          <div v-for="d in datosPersonales" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 py-1 border-t border-amber-200 text-xs"><span><b>{{ d.tipo === 'borrar' ? 'Borrar' : 'Ver' }}</b> · {{ d.cliente?.email ?? d.cliente?.id ?? 'comprador' }} · {{ d.pedidos?.length ?? 0 }} compra/s · {{ d.cuando }}<span v-if="d.estado === 'hecho'" class="text-emerald-700"> · hecho {{ d.hecho }}</span></span><button v-if="d.estado !== 'hecho'" class="btn-secondary !py-1 text-xs" @click="router.post(`/configuracion/tienda/datos-personales/${d.id}/contestado`, {}, { preserveScroll: true })">Ya le contesté</button></div>
        </div>
        <details class="mt-3 text-sm"><summary class="cursor-pointer font-semibold">Delivery: PedidosYa y Rappi</summary>
          <div v-for="c in canales.filter(x => ['pedidosya','rappi'].includes(x.tipo))" :key="c.id" class="border-t border-marca-borde/60 py-2"><p class="font-medium">{{ c.nombre }} <span class="badge bg-lavanda-light text-violeta ml-1">{{ c.tipo_label }}</span></p><p class="text-[11px] text-marca-muted">Webhook de entrada: <code class="select-all break-all">{{ c.url_entrada }}</code></p><div class="flex gap-1 mt-1"><button class="btn-ghost !px-2 text-xs" @click="abrirCanal(c)">Editar</button><button class="btn-ghost !px-2 text-xs text-carmin" @click="router.delete(`/configuracion/tienda/canales/${c.id}`, { preserveScroll: true })">Quitar</button></div></div>
          <button class="btn-secondary !py-1 text-xs mt-2" @click="abrirCanal()">Conectar delivery</button>
        </details>
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

    <Modal :abierto="wooAbierto" titulo="Conectar con WooCommerce" @cerrar="wooAbierto = false">
      <p class="text-sm text-marca-muted mb-2">Escribí la dirección de tu tienda. Tu WordPress te va a pedir que entres y apruebes el acceso, y volvés acá ya conectado. La tienda tiene que estar en HTTPS.</p>
      <input v-model="wooUrl" class="input" placeholder="https://mitienda.com" data-e2e="woo-url" />
      <template #pie><button class="btn-secondary" @click="wooAbierto = false">Cancelar</button><a :href="`/conectar/woocommerce?url=${encodeURIComponent(wooUrl)}`" class="btn-primary" :class="!/^https:\/\//.test(wooUrl) ? 'opacity-50 pointer-events-none' : ''">Conectar con WooCommerce</a></template>
    </Modal>

    <Modal :abierto="!!claves" :titulo="claves ? `Conectar ${tiposCanal[claves.tipo].label} con claves` : ''" @cerrar="claves = null">
      <p v-if="claves" class="text-sm text-marca-muted mb-3">{{ tiposCanal[claves.tipo].ayuda }} Antes de guardar, el sistema prueba que las claves sirvan.</p>
      <div v-if="claves" class="space-y-2">
        <div v-for="(lbl, k) in tiposCanal[claves.tipo].campos" :key="k"><label class="label">{{ lbl }}</label><input v-model="claves.credenciales[k]" class="input" :type="/token|secret/.test(k) ? 'password' : 'text'" autocomplete="off" :data-e2e="`clave-${k}`" /></div>
        <p v-if="claves.error" class="text-carmin text-xs">{{ claves.error }}</p>
      </div>
      <template #pie><button class="btn-secondary" @click="claves = null">Cancelar</button><button class="btn-primary" :disabled="claves?.processing" @click="guardarClaves" data-e2e="claves-guardar">Guardar y probar</button></template>
    </Modal>

    <Modal :abierto="!!ajustes" :titulo="ajustes ? `Ajustes de ${ajustes.nombre}` : ''" @cerrar="ajustes = null">
      <div v-if="ajustes" class="space-y-3 text-sm">
        <div><label class="label">Nombre</label><input v-model="ajustes.nombre" class="input" /></div>
        <div><label class="label">La cuenta a la que van las ventas</label><select v-model="ajustes.contact_id" class="input" data-e2e="ajuste-cuenta"><option :value="null">— Elegí un cliente (por ejemplo "VENTAS WEB" o "MERCADO LIBRE") —</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }} · {{ c.condicion_iva }}</option></select><p class="text-[11px] text-marca-muted">Todas las ventas de esta tienda se cargan a esa cuenta, con el CUIT y la condición de IVA del comprador cuando la tienda los trae.</p></div>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="label">Colchón de stock</label><input v-model.number="ajustes.colchon" type="number" min="0" class="input" data-e2e="ajuste-colchon" /><p class="text-[11px] text-marca-muted">Unidades que te guardás: con 12 y colchón 2 se publican 10.</p></div>
          <div><label class="label">Lista de precios de la tienda</label><select v-model="ajustes.lista" class="input"><option :value="null">Todavía sin elegir (lista 1)</option><option v-for="l in [1,2,3,4,5,6]" :key="l" :value="l">Lista {{ l }}</option></select><p class="text-[11px] text-marca-muted">Con IVA. Un rubro o un artículo puede tener otra.</p></div>
          <div><label class="label">Recargo sólo para esta tienda %</label><input v-model.number="ajustes.recargo_pct" type="number" step="any" class="input" /><p class="text-[11px] text-marca-muted">Por ejemplo, por las cuotas sin interés.</p></div>
          <div><label class="label">Desde qué fecha entran las ventas</label><input v-model="ajustes.fecha_corte" type="date" class="input" /><p class="text-[11px] text-marca-muted">Lo anterior se ve pero no se confirma ni descuenta stock.</p></div>
        </div>
        <div v-if="ajustes.tipo === 'mercadolibre'"><label class="label">Quién factura las ventas de MercadoLibre</label>
          <div class="grid gap-1"><label class="rounded-lg border px-3 py-2 flex gap-2" :class="ajustes.factura_por === 'tienda' ? 'border-violeta bg-violeta/5' : 'border-marca-borde'"><input v-model="ajustes.factura_por" type="radio" value="tienda" class="accent-carmin" /><span><b>La factura MercadoLibre.</b> Tenés prendida la facturación automática de ML: la venta entra como <b>remito</b> (baja el stock, no se factura dos veces).</span></label>
          <label class="rounded-lg border px-3 py-2 flex gap-2" :class="ajustes.factura_por === 'erp' ? 'border-violeta bg-violeta/5' : 'border-marca-borde'"><input v-model="ajustes.factura_por" type="radio" value="erp" class="accent-carmin" /><span><b>La factura del ERP.</b> La venta entra como factura; al confirmarla sale con CAE y se la subís a la venta de ML. <b>Apagá la automática en MercadoLibre</b> o sale dos veces.</span></label></div>
          <p class="text-[11px] text-marca-muted">Mientras no lo elijas, no se confirma ninguna venta de MercadoLibre: a propósito.</p></div>
        <label class="flex items-start gap-2"><input v-model="ajustes.automatico" type="checkbox" class="accent-carmin mt-0.5" /><span><b>Las ventas entran solas.</b> La que llega con todo en orden (cuenta, artículos emparejados, posterior a la fecha de corte) se confirma sola; la que tenga algo sin resolver queda por confirmar.</span></label>
        <label class="flex items-start gap-2"><input v-model="ajustes.pausar_en_cero" type="checkbox" class="accent-carmin mt-0.5" /><span>Pausar la publicación cuando el artículo se queda sin stock, y volverla cuando entra mercadería.</span></label>
        <div v-if="ajustes.tipo !== 'mercadolibre'"><label class="label">Qué partes del producto le manda el ERP a la tienda</label><div class="flex flex-wrap gap-2"><label v-for="k in ['precio','stock','nombre','descripcion','foto','categoria']" :key="k" class="flex items-center gap-1 text-xs"><input v-model="ajustes.campos" type="checkbox" :value="k" class="accent-carmin" /> {{ k }}</label></div><p class="text-[11px] text-marca-muted">Lo que destildás no se toca nunca, ni al publicar sobre un producto que ya existe ni en la sincronización.</p></div>
        <div v-if="['woocommerce','shopify'].includes(ajustes.tipo)"><label class="label">Claves fiscales del comprador (opcional)</label><input v-model="ajustes.claves_fiscales" class="input" placeholder="cuit, condicion_iva (los campos que usa tu plugin de facturación)" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="ajustes = null">Cancelar</button><button class="btn-primary" :disabled="ajustes?.processing" @click="guardarAjustes" data-e2e="ajustes-guardar">Guardar</button></template>
    </Modal>

    <Modal :abierto="canalAbierto" :titulo="fc.id ? 'Editar canal' : 'Conectar delivery'" @cerrar="canalAbierto = false">
      <div class="space-y-3">
        <div><label class="label">Plataforma</label><select v-model="fc.tipo" class="input" :disabled="!!fc.id"><option v-for="(t, k) in tiposCanal" v-show="['pedidosya','rappi'].includes(k) || k === fc.tipo" :key="k" :value="k">{{ t.label }}</option></select><p class="text-xs text-marca-muted mt-1">{{ tiposCanal[fc.tipo]?.ayuda }}</p></div>
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
const props = defineProps({ config: Object, urlTienda: String, urlMenu: String, urlReservas: String, rubros: Array, enTienda: Number, totalArticulos: Number, fidelizacion: Object, canales: Array, tiposCanal: Object, whatsapp: Object, oauth: Object, clientes: Array, datosPersonales: { type: Array, default: () => [] } })
// Fase 30: tiendas con un botón, claves probadas antes de guardar y ajustes por tienda.
const plataformas = [{ k: 'mercadolibre', label: 'MercadoLibre' }, { k: 'tiendanube', label: 'Tienda Nube' }, { k: 'woocommerce', label: 'WooCommerce' }, { k: 'shopify', label: 'Shopify' }, { k: 'empretienda', label: 'Empretienda' }]
const canalesDe = k => props.canales.filter(c => c.tipo === k)
const wooAbierto = ref(false), wooUrl = ref('')
const claves = ref(null), ajustes = ref(null)
function abrirClaves(tipo) { claves.value = { tipo, credenciales: {}, error: null, processing: false } }
function guardarClaves() { const c = claves.value; c.processing = true; c.error = null; router.post('/configuracion/tienda/conectar-claves', { tipo: c.tipo, credenciales: c.credenciales }, { preserveScroll: true, onSuccess: () => (claves.value = null), onError: e => { c.error = Object.values(e)[0]; c.processing = false } }) }
function abrirAjustes(c) { ajustes.value = { id: c.id, tipo: c.tipo, nombre: c.nombre, ...c.cfg, campos: [...(c.cfg.campos ?? [])], processing: false } }
function guardarAjustes() { const a = ajustes.value; a.processing = true; router.post(`/configuracion/tienda/canales/${a.id}/config`, a, { preserveScroll: true, onSuccess: () => (ajustes.value = null), onFinish: () => (a.processing = false) }) }
const tabs = [{ k: 'tienda', label: 'Tienda propia y menú' }, { k: 'canales', label: 'Marketplaces, delivery y WhatsApp' }, { k: 'puntos', label: 'Programa de puntos' }]
const tab = ref(location.hash === '#canales' ? 'canales' : location.hash === '#puntos' ? 'puntos' : 'tienda')
const base = location.origin
const ft = useForm({ tienda: JSON.parse(JSON.stringify({ ...props.config, rubros: props.config.rubros ?? [] })) })
const ff = useForm({ ...props.fidelizacion })
const fw = useForm({ phone_id: props.whatsapp.phone_id ?? '', token: '', verify_token: '', auto_pedidos: true, auto_responder: true })
const qr = ref(null)
onMounted(() => { if (qr.value) QRCode.toCanvas(qr.value, props.urlTienda, { width: 140, margin: 1 }).catch(() => {}) })
const canalAbierto = ref(false)
const fc = useForm({ id: null, tipo: 'pedidosya', nombre: '', credenciales: {}, activo: true, sync_stock: true, sync_precios: false, importar_pedidos: true })
function abrirCanal(c = null) { fc.clearErrors(); Object.assign(fc, c ? { id: c.id, tipo: c.tipo, nombre: c.nombre, credenciales: Object.fromEntries(Object.keys(props.tiposCanal[c.tipo].campos).map(k => [k, ''])), activo: c.activo, sync_stock: c.sync_stock, sync_precios: c.sync_precios, importar_pedidos: c.importar_pedidos } : { id: null, tipo: 'pedidosya', nombre: '', credenciales: {}, activo: true, sync_stock: true, sync_precios: false, importar_pedidos: true }); canalAbierto.value = true }
</script>
