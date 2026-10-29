<template>
  <AppLayout titulo="Tienda online">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Tienda online</h1><p class="page-subtitle">Publicar desde el catálogo, atar lo que ya tenés publicado y que el precio y el stock se mantengan solos. Cada venta entra a Comprobantes → Pedidos.</p></div>
      <div class="flex flex-wrap gap-2"><Link href="/stock/catalogos" class="btn-secondary !py-1 text-xs">Catálogos</Link><Link href="/configuracion/tienda#canales" class="btn-secondary !py-1 text-xs">Conectar tienda</Link><Link href="/comprobantes/pedidos" class="btn-secondary !py-1 text-xs">Pedidos</Link></div>
    </div>

    <div v-if="!canales.length" class="card text-center py-10" data-e2e="sin-tiendas">
      <p class="font-bold">Todavía no hay ninguna tienda conectada.</p>
      <p class="text-sm text-marca-muted mt-1">Se conecta con un botón en <Link href="/configuracion/tienda#canales" class="text-violeta font-semibold">Configuración → Tienda y canales</Link>: MercadoLibre, Tienda Nube, WooCommerce o Shopify. Empretienda va por planilla.</p>
    </div>

    <template v-else>
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <div class="flex gap-1 bg-marca-fondo rounded-full p-1 text-xs overflow-x-auto"><Link v-for="c in canales" :key="c.id" :href="`/stock/tienda-online?canal=${c.id}`" class="px-3 py-1 rounded-full font-semibold whitespace-nowrap" :class="c.id === canal ? 'bg-white shadow' : 'text-marca-muted'" :data-e2e="`canal-${c.id}`">{{ c.nombre }} <span class="opacity-60">· {{ c.publicadas }}</span></Link></div>
        <div class="flex gap-1 bg-marca-fondo rounded-full p-1 text-xs ml-auto"><button v-for="t in solapas" :key="t.k" class="px-3 py-1 rounded-full font-semibold" :class="solapa === t.k ? 'bg-white shadow' : 'text-marca-muted'" @click="solapa = t.k" :data-e2e="`solapa-${t.k}`">{{ t.t }}</button></div>
      </div>
      <p v-if="actual" class="text-xs text-marca-muted mb-3">Publicar con la lista <b>{{ actual.cfg.lista ?? 1 }}</b>{{ !actual.cfg.lista ? ' (todavía sin elegir)' : '' }} · colchón {{ actual.cfg.colchon }} · {{ actual.con_error ? `${actual.con_error} con error · ` : '' }}última sincronización {{ actual.ultimo_sync ?? 'nunca' }} · <Link href="/configuracion/tienda#canales" class="text-violeta">ajustes</Link> · <button class="text-violeta" @click="router.post(`/stock/tienda-online/${canal}/sincronizar`, {}, { preserveScroll: true })">sincronizar ahora</button></p>

      <!-- PUBLICAR -->
      <div v-if="solapa === 'publicar'" class="grid lg:grid-cols-[22rem_1fr] gap-4">
        <div class="card p-0" data-e2e="rubros">
          <div class="px-4 py-2.5 border-b border-marca-borde flex items-center justify-between"><h2 class="font-bold">Por rubro</h2><span class="text-[11px] text-marca-muted">{{ actual.tipo === 'mercadolibre' ? 'con su categoría de ML' : 'con su categoría en la web' }}</span></div>
          <div class="max-h-[70vh] overflow-y-auto divide-y divide-marca-borde">
            <div v-for="r in rubros" :key="r.id" class="px-4 py-2 text-sm cursor-pointer hover:bg-marca-fondo" :class="rubro === r.id ? 'bg-violeta/5' : ''" @click="elegirRubro(r)" :data-e2e="`rubro-${r.id}`">
              <div class="flex justify-between gap-2"><span class="font-medium truncate">{{ r.nombre }}</span><span class="text-xs text-marca-muted tabular-nums shrink-0">{{ r.publicados }}/{{ r.articulos }}</span></div>
              <p class="text-[11px] text-marca-muted truncate">
                <span v-if="r.canal?.categoria_nombre">{{ r.canal.categoria_nombre }}</span><span v-else-if="actual.tipo === 'mercadolibre'" class="text-amber-700">🔒 sin categoría de ML</span><span v-else>se crea con el nombre del rubro</span>
                · se publica con {{ r.canal?.lista ? `lista ${r.canal.lista}` : `la de la tienda (${actual.cfg.lista ?? 1})` }}
              </p>
            </div>
          </div>
        </div>

        <div class="space-y-3 min-w-0">
          <!-- Configuración del rubro -->
          <div v-if="rubroActual" class="card text-sm" data-e2e="config-rubro">
            <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-bold">{{ rubroActual.nombre }}</h2><div class="flex items-center gap-2 text-xs"><span>Se publica con</span><select v-model="rc.lista" class="input !py-1 w-auto" @change="guardarRubro"><option :value="null">la lista de la tienda</option><option v-for="l in listas" :key="l" :value="l">lista {{ l }}</option></select></div></div>
            <div v-if="actual.tipo === 'mercadolibre'" class="mt-2">
              <p class="text-xs text-marca-muted">Categoría de MercadoLibre: <b v-if="rc.categoria_nombre">{{ rc.categoria_nombre }} ({{ rc.categoria_id }})</b><span v-else class="text-amber-700">todavía no tiene</span>. MercadoLibre la propone a partir del producto más vendido del rubro; si ninguna es la tuya, escribí qué vendés con tus palabras.</p>
              <div class="flex gap-1 mt-1"><input v-model="qCat" class="input !py-1 text-xs flex-1" placeholder="Buscar categoría con tus palabras (lentes de lectura)" @keydown.enter="buscarCategorias" /><button class="btn-secondary !py-1 text-xs" @click="buscarCategorias" :disabled="cargandoCat" data-e2e="buscar-categoria">{{ cargandoCat ? 'Buscando…' : 'Sugerir' }}</button></div>
              <p v-if="errorCat" class="text-carmin text-xs mt-1">{{ errorCat }}</p>
              <div v-if="categorias.length" class="grid gap-1 mt-1"><button v-for="k in categorias" :key="k.id" class="text-left rounded-lg border px-2 py-1 text-xs" :class="rc.categoria_id === k.id ? 'border-violeta bg-violeta/5' : 'border-marca-borde'" @click="elegirCategoria(k)" :data-e2e="`cat-${k.id}`">{{ k.nombre }} <span class="text-marca-muted">· {{ k.dominio ?? k.id }}</span></button></div>
              <div v-if="atributos" class="mt-2 rounded-xl bg-marca-fondo p-3">
                <p class="text-xs font-bold mb-1">Lo que MercadoLibre pide para esta categoría <span class="font-normal text-marca-muted">· una vez por rubro</span></p>
                <p v-if="atributos.admite_envios === false" class="text-xs text-carmin mb-1">Esta categoría no admite Mercado Envíos: MercadoLibre va a rechazar la publicación. Probá con otra categoría.</p>
                <p v-if="atributos.precio_minimo" class="text-xs text-amber-800 mb-1">Precio mínimo de la categoría: {{ moneda(atributos.precio_minimo) }}. Los más baratos no se pueden publicar ahí.</p>
                <div class="grid sm:grid-cols-2 gap-2">
                  <div v-for="a in atributos.requeridos" :key="a.id"><label class="label">{{ a.nombre }} * <span class="font-normal normal-case text-marca-muted">· {{ a.motivo }}</span></label><input v-model="rc.atributos.extra[a.id]" class="input !py-1 text-xs" :list="a.valores?.length ? `v-${a.id}` : null" /><datalist v-if="a.valores?.length" :id="`v-${a.id}`"><option v-for="v in a.valores" :key="v" :value="v" /></datalist></div>
                  <div v-for="a in atributos.envio" :key="a.id"><label class="label">{{ a.nombre }} * <span class="font-normal normal-case text-marca-muted">· {{ a.motivo }}</span></label><input v-model="rc.atributos[a.id]" type="number" step="any" class="input !py-1 text-xs" /></div>
                  <div><label class="label">IVA del artículo %</label><input v-model.number="rc.atributos.iva" type="number" class="input !py-1 text-xs" placeholder="21" /></div>
                  <div><label class="label">Impuesto interno %</label><input v-model.number="rc.atributos.internos" type="number" class="input !py-1 text-xs" placeholder="0" /></div>
                </div>
                <button class="btn-secondary !py-1 text-xs mt-2" @click="guardarRubro" data-e2e="guardar-rubro">Guardar datos del rubro</button>
              </div>
            </div>
            <div v-else class="mt-2 flex flex-wrap items-center gap-2 text-xs"><span>Categoría en la web:</span><select v-model="rc.categoria_id" class="input !py-1 w-auto" @change="guardarRubro"><option :value="null">se crea con el nombre del rubro</option><option v-for="k in categorias" :key="k.id" :value="k.id">{{ k.nombre }}</option></select><button class="text-violeta" @click="buscarCategorias">{{ categorias.length ? 'actualizar' : 'ver las categorías de la web' }}</button></div>
          </div>

          <!-- Productos -->
          <div class="card p-0" data-e2e="productos">
            <div class="px-4 py-2.5 border-b border-marca-borde flex flex-wrap items-center gap-2">
              <input v-model="q" class="input !py-1.5 text-sm flex-1 min-w-[12rem]" placeholder="Buscar productos en todos los rubros (nombre, código, barras, descripción)" @keydown.enter="cargarProductos" data-e2e="buscar-producto" />
              <label class="text-xs flex items-center gap-1"><input v-model="sinPublicar" type="checkbox" class="accent-carmin" @change="cargarProductos" /> sin publicar</label>
              <label class="text-xs flex items-center gap-1"><input v-model="sinFoto" type="checkbox" class="accent-carmin" @change="cargarProductos" /> sin foto</label>
              <button class="btn-secondary !py-1 text-xs" @click="cargarProductos">Buscar</button>
            </div>
            <div class="px-4 py-1.5 text-xs text-marca-muted flex flex-wrap items-center gap-3 border-b border-marca-borde bg-marca-fondo/50">
              <label class="flex items-center gap-1"><input type="checkbox" class="accent-carmin" :checked="productos.length && productos.every(p => sel[p.id])" @change="marcarTodos($event.target.checked)" /> marcar los que se ven</label>
              <span><b>{{ seleccionados.length }}</b> para publicar<span v-if="seleccionados.filter(p => !p.foto).length"> · {{ seleccionados.filter(p => !p.foto).length }} sin foto</span></span>
              <button class="btn-primary !py-1 text-xs ml-auto" :disabled="!seleccionados.length" @click="revisar" data-e2e="revisar">Revisar y publicar ({{ seleccionados.length }})</button>
            </div>
            <div class="max-h-[60vh] overflow-y-auto divide-y divide-marca-borde">
              <div v-for="p in productos" :key="p.id" class="px-4 py-2 flex items-center gap-2 text-sm" :data-e2e="`prod-${p.id}`">
                <input type="checkbox" class="accent-carmin w-4 h-4" :checked="!!sel[p.id]" :disabled="p.motivos.some(m => m.includes('categoría'))" @change="toggle(p)" />
                <img v-if="p.foto" :src="p.foto" class="w-9 h-9 rounded object-cover bg-marca-fondo" /><div v-else class="w-9 h-9 rounded bg-marca-fondo grid place-items-center text-[10px] text-marca-muted">sin foto</div>
                <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ p.nombre }}</p><p class="text-[11px] text-marca-muted">{{ p.sku }} · {{ p.rubro }} · {{ p.stock }} u.<span v-if="p.publicada" class="text-emerald-700"> · publicado</span><span v-for="m in p.motivos" :key="m" class="text-amber-700"> · {{ m }}</span></p></div>
                <div class="text-right"><p class="font-bold tabular-nums">{{ p.precio != null ? moneda(p.precio) : '—' }}</p><p class="text-[10px] text-marca-muted">lista {{ p.lista }}</p></div>
              </div>
              <p v-if="!productos.length" class="px-4 py-8 text-center text-xs text-marca-muted">{{ cargando ? 'Cargando…' : 'Elegí un rubro o buscá un producto.' }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- LO PUBLICADO -->
      <div v-else-if="solapa === 'publicadas'" class="card p-0" data-e2e="publicadas">
        <div class="px-4 py-2.5 border-b border-marca-borde flex flex-wrap items-center gap-2"><h2 class="font-bold">Lo publicado en {{ actual.nombre }}</h2><input v-model="qPub" class="input !py-1 text-xs flex-1 min-w-[10rem]" placeholder="Filtrar" /><div class="flex gap-1 text-xs"><button v-for="f in [['todas','Todas'],['activa','Activas'],['pausada','Pausadas'],['error','Con error']]" :key="f[0]" class="px-2 py-1 rounded-full" :class="filtroPub === f[0] ? 'bg-marca-texto text-white' : 'bg-marca-fondo text-marca-muted'" @click="filtroPub = f[0]">{{ f[1] }}</button></div></div>
        <div class="divide-y divide-marca-borde text-sm max-h-[70vh] overflow-y-auto">
          <div v-for="p in publicacionesVisibles" :key="p.id" class="px-4 py-2 flex items-center gap-2" :data-e2e="`pub-${p.id}`">
            <img v-if="p.foto" :src="p.foto" class="w-9 h-9 rounded object-cover bg-marca-fondo" /><div v-else class="w-9 h-9 rounded bg-marca-fondo"></div>
            <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ p.nombre }}</p><p class="text-[11px] text-marca-muted">{{ p.sku }} · {{ p.origen === 'publicada' ? 'publicada desde acá' : p.adoptada ? 'tuya, adoptada (el ERP le manda precio y stock)' : 'tuya, atada a mano' }} · lista {{ p.lista ?? actual.cfg.lista ?? 1 }} · {{ p.sync ?? 'sin sincronizar' }}<span v-if="p.error" class="text-carmin"> · {{ p.error }}</span></p></div>
            <div class="text-right"><p class="font-bold tabular-nums">{{ moneda(p.precio ?? 0) }}</p><p class="text-[10px] tabular-nums" :class="p.stock > 0 ? 'text-marca-muted' : 'text-carmin'">{{ p.stock ?? 0 }} u. · {{ p.estado }}</p></div>
            <a v-if="p.permalink" :href="p.permalink" target="_blank" class="text-violeta text-xs font-semibold">ver</a>
            <button class="btn-ghost !px-2 text-xs" @click="pausar(p)">{{ p.estado === 'pausada' ? 'Activar' : 'Pausar' }}</button>
          </div>
          <p v-if="!publicacionesVisibles.length" class="px-4 py-8 text-center text-xs text-marca-muted">Nada todavía. Publicá desde la solapa anterior o atá lo que ya tenés en la tienda.</p>
        </div>
      </div>

      <!-- LAS QUE YA TENÉS EN LA TIENDA: atar, adoptar, desatar -->
      <div v-else-if="solapa === 'existentes'" class="space-y-3" data-e2e="existentes">
        <div class="card text-sm">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div><h2 class="font-bold">Lo que ya tenés en {{ actual.nombre }}</h2><p class="text-xs text-marca-muted">Se lee de la tienda. Cada una viene con el artículo propuesto; <b>nada se ata solo</b>: siempre confirmás vos. <b>Atar</b> sirve para facturar sus ventas; <b>adoptar</b> además hace que el precio y el stock los mande el ERP (lo que la web tenía se pisa). Con variantes se ata pero no se adopta.</p></div>
            <div class="flex flex-wrap gap-1"><button class="btn-secondary !py-1 text-xs" :disabled="leyendo" @click="leerTienda" data-e2e="leer-tienda">{{ leyendo ? 'Leyendo la tienda…' : (ex ? 'Volver a leer la tienda' : 'Leer la tienda') }}</button><button v-if="ex?.seguras" class="btn-primary !py-1 text-xs" @click="atarSeguras" data-e2e="atar-seguras">Emparejar las {{ ex.seguras }} seguras</button><button v-if="ex?.atadas_sin_adoptar" class="btn-secondary !py-1 text-xs" @click="adoptarAtadas">Adoptar las {{ ex.atadas_sin_adoptar }} atadas</button><button v-if="ex" class="btn-ghost !px-2 text-xs" @click="traerFotos">Traer las fotos al catálogo</button></div>
          </div>
          <p v-if="ex?.pendientes?.ventas" class="mt-2 text-xs rounded-lg bg-amber-50 text-amber-900 px-2 py-1">{{ ex.pendientes.ventas }} venta{{ ex.pendientes.ventas > 1 ? 's esperan' : ' espera' }} por {{ ex.pendientes.publicaciones }} publicaci{{ ex.pendientes.publicaciones > 1 ? 'ones' : 'ón' }} sin artículo: al tocar «Es éste» todas quedan con el artículo.</p>
          <p v-if="exError" class="mt-2 text-xs text-carmin">{{ exError }} <button class="underline" @click="leerTienda">Reintentar</button></p>
          <p class="mt-2 text-xs text-marca-muted">Lista de precios de la tienda: <b>{{ actual.cfg.lista ?? 1 }}</b> (con IVA). Cada producto dice con qué precio queda con el ERP y cuál tiene la web hoy.</p>
        </div>
        <div v-if="ex" class="card p-0">
          <div class="px-4 py-2 border-b border-marca-borde flex flex-wrap items-center gap-2 text-xs">
            <input v-model="qEx" class="input !py-1 flex-1 min-w-[10rem]" placeholder="Filtrar por título, código de la tienda, SKU o artículo (palabras en cualquier orden)" data-e2e="filtro-existentes" />
            <div class="flex gap-1"><button v-for="f in [['todas','Todas'],['activa','Activas'],['pausada','Pausadas']]" :key="f[0]" class="px-2 py-1 rounded-full" :class="filtroEx === f[0] ? 'bg-marca-texto text-white' : 'bg-marca-fondo text-marca-muted'" @click="filtroEx = f[0]">{{ f[1] }}</button><button class="px-2 py-1 rounded-full" :class="soloFaltan ? 'bg-amber-600 text-white' : 'bg-marca-fondo text-marca-muted'" @click="soloFaltan = !soloFaltan" data-e2e="faltan-emparejar">{{ ex.items.filter(i => !i.articulo).length }} faltan emparejar</button></div>
            <span class="text-marca-muted ml-auto">Se ven {{ existentesVisibles.length }} de {{ ex.items.length }}</span>
          </div>
          <div class="divide-y divide-marca-borde text-sm max-h-[70vh] overflow-y-auto">
            <div v-for="p in existentesVisibles" :key="p.id" class="px-4 py-2 flex flex-wrap items-center gap-2" :data-e2e="`ex-${p.id}`">
              <img v-if="p.foto" :src="p.foto" class="w-10 h-10 rounded object-cover bg-marca-fondo" /><div v-else class="w-10 h-10 rounded bg-marca-fondo"></div>
              <div class="flex-1 min-w-[14rem]">
                <p class="font-medium">{{ p.titulo }} <a v-if="p.permalink" :href="p.permalink" target="_blank" class="text-violeta text-xs">ver</a></p>
                <p class="text-[11px] text-marca-muted">{{ p.external_id }}{{ p.variante_id ? ' · var ' + p.variante_id : '' }}<span v-if="p.sku_remoto"> · SKU {{ p.sku_remoto }}</span> · en la web hoy <b class="tabular-nums">{{ moneda(p.precio_web ?? 0) }}</b> · {{ p.stock_web ?? 0 }} u. · {{ p.estado }}<span v-if="p.vendidas != null"> · {{ p.vendidas }} vendidas</span><span v-if="p.categoria"> · {{ p.categoria }}</span><span v-if="p.con_variantes" class="text-amber-700"> · con variantes</span></p>
                <p v-if="p.articulo" class="text-[11px]"><span class="text-emerald-700 font-semibold">{{ p.origen === 'publicada' ? 'publicada desde acá' : p.adoptada ? 'adoptada: el ERP le manda precio y stock' : 'atada a mano' }}</span> · {{ p.articulo.nombre }} ({{ p.articulo.sku }}) · con el ERP queda en <b class="tabular-nums">{{ p.articulo.precio_erp != null ? moneda(p.articulo.precio_erp) : 'sin precio' }}</b> · lista {{ p.articulo.lista }} · {{ p.articulo.stock_erp }} u.<span v-if="p.error" class="text-carmin"> · la tienda rechazó el último envío: {{ p.error }}</span></p>
              </div>
              <div class="flex flex-wrap gap-1 items-center text-xs">
                <template v-if="p.articulo">
                  <button v-if="!p.adoptada && !p.con_variantes && p.origen !== 'publicada'" class="btn-secondary !py-1" @click="adoptar(p, true)" :data-e2e="`adoptar-${p.id}`">Adoptar</button>
                  <button v-else-if="p.adoptada" class="btn-ghost !px-2" @click="adoptar(p, false)">Dejar de sincronizar</button>
                  <button class="btn-ghost !px-2 text-carmin" @click="desatar(p)">Desatar</button>
                </template>
                <template v-else-if="p.sugerencia?.tipo === 'segura'">
                  <button class="btn-primary !py-1" @click="atar(p, p.sugerencia.product_id)" :data-e2e="`es-${p.id}`">Es «{{ p.sugerencia.nombre }}» <span class="opacity-70">· {{ p.sugerencia.por }}</span></button>
                  <button class="btn-ghost !px-2" @click="buscando = p">¿No es? Buscar otro…</button>
                </template>
                <template v-else-if="p.sugerencia?.tipo === 'dudosa'">
                  <span class="text-marca-muted">¿Cuál es?<span v-if="p.sugerencia.por"> ({{ p.sugerencia.por }})</span></span>
                  <button v-for="k in p.sugerencia.candidatos" :key="k.id" class="btn-secondary !py-1" @click="atar(p, k.id)" :data-e2e="`cand-${p.id}-${k.id}`">{{ k.nombre }}</button>
                  <button class="btn-ghost !px-2" @click="buscando = p">Otro…</button>
                </template>
                <template v-else>
                  <span class="text-marca-muted">no encontré ninguno seguro</span>
                  <button class="btn-secondary !py-1" @click="buscando = p" :data-e2e="`decir-${p.id}`">Decir cuál artículo es</button>
                </template>
              </div>
            </div>
            <p v-if="!existentesVisibles.length" class="px-4 py-8 text-center text-xs text-marca-muted">{{ ex.items.length ? 'Ninguna coincide con el filtro.' : 'La tienda contestó que no tiene publicaciones.' }}</p>
          </div>
        </div>
      </div>

      <div v-else class="card text-center py-10 text-sm text-marca-muted">Esta solapa llega en el próximo paso de la fase.</div>
    </template>

    <!-- Buscar el artículo para atar -->
    <Modal :abierto="!!buscando" :titulo="buscando ? `¿Cuál artículo es «${buscando.titulo}»?` : ''" @cerrar="buscando = null">
      <input v-model="qArt" class="input" placeholder="Nombre, descripción, código o código de barras" autofocus @input="buscarArticulo" data-e2e="buscar-articulo" />
      <div class="mt-2 max-h-72 overflow-y-auto divide-y divide-marca-borde text-sm">
        <button v-for="a in resultadosArt" :key="a.id" class="w-full text-left px-2 py-1.5 hover:bg-marca-fondo flex items-center gap-2" @click="atar(buscando, a.id); buscando = null" :data-e2e="`art-${a.id}`"><img v-if="a.foto" :src="a.foto" class="w-8 h-8 rounded object-cover" /><span class="flex-1"><b>{{ a.nombre }}</b> <span class="text-marca-muted text-xs">{{ a.sku }}</span><span v-if="a.por" class="block text-[11px] text-marca-muted">{{ a.por }}</span></span><span class="text-violeta text-xs font-semibold">Es este</span></button>
        <p v-if="qArt && !resultadosArt.length" class="text-xs text-marca-muted py-4 text-center">Nada parecido.</p>
      </div>
    </Modal>

    <!-- Revisar y publicar -->
    <Modal :abierto="!!revision" titulo="Revisar y publicar" @cerrar="revision = null" ancho="max-w-3xl">
      <p class="text-sm text-marca-muted mb-2">Es el último lugar para arrepentirse: cada producto con su precio, cuánto se va a ofrecer y de qué rubro es. Sacá con la ✕ lo que no quieras.</p>
      <div v-if="revision" class="flex items-center gap-2 text-xs mb-2"><span>Poner a todos con</span><select class="input !py-1 w-auto" @change="todosLista($event.target.value)"><option value="">la lista que corresponde</option><option v-for="l in listas" :key="l" :value="l">lista {{ l }}</option></select></div>
      <div v-if="revision" class="max-h-[55vh] overflow-y-auto divide-y divide-marca-borde text-sm">
        <div v-for="p in revision.items" :key="p.id" class="py-2 flex items-center gap-2" :class="p.motivos.length ? 'bg-amber-50/60' : ''">
          <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ p.nombre }}</p><p class="text-[11px] text-marca-muted">{{ p.rubro }} · {{ p.stock }} u. a ofrecer<span v-for="m in p.motivos" :key="m" class="text-carmin"> · {{ m }} (no va a salir así)</span></p></div>
          <select v-model.number="revision.listas[p.id]" class="input !py-1 w-auto text-xs" @change="recalcular"><option v-for="l in listas" :key="l" :value="l" :disabled="p.precios[l] == null">lista {{ l }}: {{ p.precios[l] != null ? moneda(p.precios[l]) : 'sin precio' }}</option></select>
          <button class="text-marca-muted hover:text-carmin" @click="revision.items = revision.items.filter(x => x.id !== p.id)">✕</button>
        </div>
      </div>
      <div v-if="revision?.resultado" class="mt-3 text-sm rounded-xl p-3" :class="revision.resultado.errores.length ? 'bg-amber-50' : 'bg-emerald-50'" data-e2e="resultado-publicar">
        <p><b>{{ revision.resultado.ok.length }}</b> publicados.<span v-if="revision.resultado.errores.length"> <b>{{ revision.resultado.errores.length }}</b> quedaron para corregir:</span></p>
        <p v-for="e in revision.resultado.errores" :key="e.id" class="text-xs text-carmin">{{ e.nombre }}: {{ e.error }}</p>
      </div>
      <template #pie><button class="btn-secondary" @click="revision = null">{{ revision?.resultado ? 'Cerrar' : 'Cancelar' }}</button><button v-if="!revision?.resultado" class="btn-primary" :disabled="!revision?.items.length || publicando" @click="publicar" data-e2e="publicar">{{ publicando ? 'Publicando…' : `Publicar ${revision?.items.filter(p => !p.motivos.length).length ?? 0}` }}</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ canales: Array, canal: Number, rubros: Array, publicaciones: Array, listas: Array })
const actual = computed(() => props.canales.find(c => c.id === props.canal))
const solapas = [{ k: 'publicar', t: 'Publicar desde el catálogo' }, { k: 'publicadas', t: 'Lo publicado' }, { k: 'existentes', t: 'Las que ya tenés en la tienda' }]
const solapa = ref(location.hash === '#publicadas' ? 'publicadas' : 'publicar')
const xsrf = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
const api = (url, body) => fetch(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(body) } : { headers: { Accept: 'application/json' } })

// --- Rubro elegido y su configuración en la tienda ---
const rubro = ref(null), rubroActual = computed(() => props.rubros.find(r => r.id === rubro.value))
const rc = reactive({ lista: null, categoria_id: null, categoria_nombre: null, atributos: { extra: {} } })
const categorias = ref([]), atributos = ref(null), qCat = ref(''), cargandoCat = ref(false), errorCat = ref(null)
function elegirRubro(r) { rubro.value = r.id; Object.assign(rc, { lista: r.canal?.lista ?? null, categoria_id: r.canal?.categoria_id ?? null, categoria_nombre: r.canal?.categoria_nombre ?? null, atributos: { extra: {}, ...(r.canal?.atributos ?? {}) } }); categorias.value = []; atributos.value = null; errorCat.value = null; q.value = ''; cargarProductos(); if (rc.categoria_id && actual.value.tipo === 'mercadolibre') cargarAtributos(rc.categoria_id) }
async function buscarCategorias() { cargandoCat.value = true; errorCat.value = null; try { const r = await api(`/stock/tienda-online/${props.canal}/categorias?q=${encodeURIComponent(qCat.value)}&rubro=${rubro.value ?? ''}`); const j = await r.json(); if (!r.ok) throw new Error(j.message ?? 'No se pudo'); categorias.value = j } catch (e) { errorCat.value = e.message } finally { cargandoCat.value = false } }
async function elegirCategoria(k) { rc.categoria_id = k.id; rc.categoria_nombre = k.nombre; await guardarRubro(); cargarAtributos(k.id) }
async function cargarAtributos(id) { try { const r = await api(`/stock/tienda-online/${props.canal}/atributos/${id}`); if (r.ok) atributos.value = await r.json() } catch (e) {} }
async function guardarRubro() { if (!rubro.value) return; const r = await api(`/stock/tienda-online/${props.canal}/rubro/${rubro.value}`, { lista: rc.lista, categoria_id: rc.categoria_id, categoria_nombre: rc.categoria_nombre ?? categorias.value.find(k => k.id === rc.categoria_id)?.nombre, atributos: rc.atributos }); if (r.ok) { const j = await r.json(); const rr = props.rubros.find(x => x.id === rubro.value); if (rr) rr.canal = j.rubro; cargarProductos() } }

// --- Productos del rubro o la búsqueda ---
const productos = ref([]), q = ref(''), sinPublicar = ref(false), sinFoto = ref(false), cargando = ref(false), sel = reactive({})
async function cargarProductos() { cargando.value = true; try { const r = await api(`/stock/tienda-online/${props.canal}/catalogo?rubro=${q.value ? '' : (rubro.value ?? '')}&q=${encodeURIComponent(q.value)}&sin_publicar=${sinPublicar.value ? 1 : 0}&sin_foto=${sinFoto.value ? 1 : 0}`); productos.value = r.ok ? await r.json() : [] } finally { cargando.value = false } }
function toggle(p) { if (sel[p.id]) delete sel[p.id]; else sel[p.id] = p }
function marcarTodos(on) { productos.value.forEach(p => { if (on && !p.motivos.some(m => m.includes('categoría'))) sel[p.id] = p; else delete sel[p.id] }) }
const seleccionados = computed(() => Object.values(sel))

// --- Revisar y publicar ---
const revision = ref(null), publicando = ref(false)
async function revisar() { const ids = seleccionados.value.map(p => p.id); const r = await api(`/stock/tienda-online/${props.canal}/revisar`, { ids }); const items = await r.json(); revision.value = { items, listas: Object.fromEntries(items.map(p => [p.id, p.lista])), resultado: null } }
async function recalcular() { const r = await api(`/stock/tienda-online/${props.canal}/revisar`, { ids: revision.value.items.map(p => p.id), listas: revision.value.listas }); if (r.ok) revision.value.items = await r.json() }
function todosLista(l) { if (!l) return; revision.value.items.forEach(p => (revision.value.listas[p.id] = Number(l))); recalcular() }
async function publicar() {
  publicando.value = true
  try { const ids = revision.value.items.filter(p => !p.motivos.length).map(p => p.id); const r = await api(`/stock/tienda-online/${props.canal}/publicar`, { ids, listas: revision.value.listas }); const j = await r.json(); revision.value.resultado = r.ok ? j : { ok: [], errores: [{ id: 0, nombre: 'Error', error: j.message ?? 'No se pudo' }] }; j.ok?.forEach(o => delete sel[o.id]); if (j.ok?.length) setTimeout(() => router.reload({ only: ['publicaciones', 'rubros', 'canales'] }), 500); cargarProductos() } finally { publicando.value = false }
}

// --- Las que ya tenés en la tienda ---
const ex = ref(null), exError = ref(null), leyendo = ref(false), qEx = ref(''), filtroEx = ref('todas'), soloFaltan = ref(false), buscando = ref(null), qArt = ref(''), resultadosArt = ref([])
async function cargarExistentes(leer = false) { leyendo.value = leer; exError.value = null; try { const r = await api(`/stock/tienda-online/${props.canal}/existentes${leer ? '?leer=1' : ''}`); const j = await r.json(); if (!r.ok) throw new Error(j.message ?? 'No se pudo'); ex.value = j } catch (e) { exError.value = e.message } finally { leyendo.value = false } }
function leerTienda() { cargarExistentes(true) }
const norm = s => (s ?? '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
const existentesVisibles = computed(() => { if (!ex.value) return []; const tk = norm(qEx.value).split(' ').filter(Boolean); return ex.value.items.filter(p => (filtroEx.value === 'todas' || p.estado === filtroEx.value) && (!soloFaltan.value || !p.articulo) && tk.every(t => norm(`${p.titulo} ${p.external_id} ${p.sku_remoto ?? ''} ${p.articulo?.nombre ?? ''} ${p.articulo?.sku ?? ''}`).includes(t))) })
async function atar(p, productId) { const r = await api(`/stock/tienda-online/publicaciones/${p.id}/atar`, { product_id: productId, adoptar: false }); if (r.ok) cargarExistentes() }
async function desatar(p) { if (!confirm('¿Desatar? Sus próximas ventas entran sin artículo.')) return; const r = await api(`/stock/tienda-online/publicaciones/${p.id}/desatar`, {}); if (r.ok) cargarExistentes() }
async function adoptar(p, si) { if (si && !confirm(`Adoptar pisa lo que la web tenía: queda en ${p.articulo.precio_erp != null ? moneda(p.articulo.precio_erp) : 'sin precio'} y ${p.articulo.stock_erp} u. ¿Seguimos?`)) return; const r = await api(`/stock/tienda-online/publicaciones/${p.id}/adoptar`, { dejar: !si }); const j = await r.json().catch(() => ({})); if (!r.ok) exError.value = j.message; cargarExistentes() }
async function atarSeguras() { const n = ex.value.items.filter(i => i.sugerencia?.tipo === 'segura').length; if (!confirm(`Se emparejan y adoptan ${n} publicaciones: desde ahí el precio y el stock los manda el ERP con la lista de la tienda. ¿Seguimos?`)) return; const r = await api(`/stock/tienda-online/${props.canal}/atar-seguras`, {}); if (r.ok) cargarExistentes() }
async function adoptarAtadas() { if (!confirm('Adoptar pisa el precio y el stock que la web tenía en todas las atadas. ¿Seguimos?')) return; const r = await api(`/stock/tienda-online/${props.canal}/adoptar-atadas`, {}); if (r.ok) cargarExistentes() }
async function traerFotos() { const r = await api(`/stock/tienda-online/${props.canal}/traer-fotos`, {}); const j = await r.json(); alert(`${j.fotos} fotos pasaron al catálogo.`) }
let tArt = null
function buscarArticulo() { clearTimeout(tArt); tArt = setTimeout(async () => { const r = await api(`/stock/tienda-online/buscar-articulo?q=${encodeURIComponent(qArt.value)}`); resultadosArt.value = r.ok ? await r.json() : [] }, 250) }
watch(solapa, s => { if (s === 'existentes' && !ex.value) cargarExistentes(false) }, { immediate: true })

// --- Lo publicado ---
const qPub = ref(''), filtroPub = ref('todas')
const publicacionesVisibles = computed(() => { const t = qPub.value.toLowerCase(); return props.publicaciones.filter(p => (filtroPub.value === 'todas' || p.estado === filtroPub.value) && (!t || (p.nombre ?? '').toLowerCase().includes(t) || (p.sku ?? '').toLowerCase().includes(t))) })
async function pausar(p) { const r = await api(`/stock/tienda-online/publicaciones/${p.id}/pausar`, { activar: p.estado === 'pausada' }); if (r.ok) { const j = await r.json(); p.estado = j.estado } }
</script>
