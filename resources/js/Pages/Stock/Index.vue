<template>
  <AppLayout titulo="Stock">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Stock</h1><p class="page-subtitle">Artículos, existencias por depósito y valorización a costo.</p></div>
      <div class="flex flex-wrap gap-2">
        <Link href="/stock/movimientos" class="btn-secondary">Movimientos</Link>
        <Link v-if="puede('stock','editar')" href="/stock/inventario" class="btn-secondary">Inventario</Link>
        <button v-if="puede('stock','crear')" @click="transfAbierto = true" class="btn-secondary">Transferir</button>
        <a href="/configuracion/importar/exportar/articulos" class="btn-secondary" title="Todos los artículos con sus 6 listas, stock y descuentos">Exportar CSV</a>
        <button v-if="puede('stock','editar')" @click="preciosAbierto = true" class="btn-secondary">Actualizar precios</button>
        <Link v-if="puede('stock','editar')" href="/stock/importar" class="btn-secondary">Importar lista</Link>
        <Link href="/stock/informes" class="btn-secondary">Informes</Link>
        <Link href="/stock/etiquetas" class="btn-secondary">Etiquetas</Link>
        <Link href="/stock/catalogos" class="btn-secondary" data-ir-catalogos>Catálogos</Link>
        <Link href="/stock/descuentos-lista" class="btn-secondary" data-ir-desc-lista>Descuentos por lista</Link>
        <Link href="/stock/verificador" class="btn-ghost text-xs" title="Pantalla para que el cliente consulte precios con el lector">Verificador</Link>
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

    <div v-if="sel.length" class="flex flex-wrap items-center gap-2 mb-3 rounded-xl bg-violeta/10 border border-violeta/30 px-4 py-2 text-sm">
      <b>{{ sel.length }} artículo{{ sel.length > 1 ? 's' : '' }} seleccionado{{ sel.length > 1 ? 's' : '' }}</b>
      <button class="btn-primary !py-1 text-xs" @click="pr.ids = [...sel]; preciosAbierto = true">Actualizar precios de estos</button>
      <button class="btn-ghost !py-1 text-xs" @click="sel = []">Quitar selección</button>
      <span class="text-xs text-marca-muted">Podés ir marcando en varias páginas.</span>
    </div>
    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th class="w-8"><input type="checkbox" class="accent-carmin" :checked="lista.data.length && lista.data.every(p => sel.includes(p.id))" @change="$event.target.checked ? lista.data.forEach(p => !sel.includes(p.id) && sel.push(p.id)) : (sel = sel.filter(id => !lista.data.some(p => p.id === id)))" title="Seleccionar todos los de esta página" /></th><th>Artículo</th><th>Rubro</th><th class="text-right">Costo</th><th class="text-right">Precio</th><th v-for="d in depositosActivos" :key="d.id" class="text-right whitespace-nowrap">{{ d.nombre.replace('Depósito ', '') }}</th><th class="text-right">Total</th><th class="text-right">Mín.</th><th class="text-right">Valor</th><th></th></tr></thead>
        <tbody>
          <tr v-for="p in lista.data" :key="p.id" class="cursor-pointer" :class="[!p.active ? 'opacity-50' : '', sel.includes(p.id) ? 'bg-violeta/5' : '']" @click="$inertia.visit(`/stock/${p.id}`)">
            <td @click.stop><input type="checkbox" class="accent-carmin" :checked="sel.includes(p.id)" @change="$event.target.checked ? sel.push(p.id) : (sel = sel.filter(id => id !== p.id))" /></td>
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
          <tr v-if="!lista.data.length"><td :colspan="9 + depositosActivos.length" class="text-center text-marca-muted py-10">No hay artículos con estos filtros.</td></tr>
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
    <Modal :abierto="preciosAbierto" :titulo="pr.ids.length ? `Actualizar precios de ${pr.ids.length} artículos seleccionados` : 'Actualizar precios en bloque'" ancho="max-w-2xl" @cerrar="preciosAbierto = false; pr.ids = []">
      <p v-if="pr.ids.length" class="text-xs text-violeta font-semibold mb-2">Solo se tocan los artículos que marcaste en la lista. Los filtros de rubro, proveedor y marca no aplican.</p>
      <div class="flex gap-1 bg-marca-fondo rounded-xl p-1 mb-4 text-sm">
        <button v-for="m in [['porcentaje', 'Subir o bajar un %'], ['margen', 'Recalcular por margen desde el costo']]" :key="m[0]" @click="pr.modo = m[0]" class="flex-1 py-1.5 rounded-lg font-semibold transition" :class="pr.modo === m[0] ? 'bg-white shadow' : 'text-marca-muted'">{{ m[1] }}</button>
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        <template v-if="pr.modo === 'porcentaje'">
          <div><label class="label">Porcentaje</label><input v-model.number="pr.porcentaje" type="number" step="any" class="input" placeholder="Ej: 8 (o -5 para bajar)" /><p v-if="pr.errors.porcentaje" class="text-carmin text-xs mt-1">{{ pr.errors.porcentaje }}</p></div>
          <div><label class="label">Sobre</label><select v-model="pr.campo" class="input"><option value="price">Precios de venta</option><option value="cost">Costos</option><option value="ambos">Precios y costos</option></select></div>
          <div v-if="pr.campo !== 'cost'" class="sm:col-span-2"><label class="label">Listas a tocar</label><div class="flex flex-wrap gap-2"><label v-for="n in 6" :key="n" class="flex items-center gap-1 text-sm px-2 py-1 rounded-lg border" :class="pr.listas.includes(n) ? 'border-violeta bg-violeta/5' : 'border-marca-borde'"><input type="checkbox" class="accent-violeta" :checked="pr.listas.includes(n)" @change="pr.listas = $event.target.checked ? [...pr.listas, n] : pr.listas.filter(x => x !== n)" /> Lista {{ n }}</label></div><p class="text-[11px] text-marca-muted mt-1">Sin ninguna marcada se actualizan todas.</p></div>
        </template>
        <p v-else class="sm:col-span-2 text-sm text-marca-muted">Vuelve a calcular las listas de cada artículo con sus márgenes sobre el costo actual. Solo toca los artículos que tienen márgenes cargados. Útil después de subir costos o de importar una lista del proveedor.</p>
        <div><label class="label">Solo el rubro</label><select v-model="pr.rubro_id" class="input"><option :value="null">Todos</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.completo }}</option></select></div>
        <div><label class="label">Solo el proveedor</label><select v-model="pr.proveedor_id" class="input"><option :value="null">Todos</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
        <div><label class="label">Solo la marca</label><input v-model="pr.marca" class="input" list="marcas" placeholder="Todas" /><datalist id="marcas"><option v-for="m in marcas" :key="m" :value="m" /></datalist></div>
        <div><label class="label">Redondear a</label><select v-model="pr.redondeo" class="input"><option value="0">Centavos</option><option value="1">$1</option><option value="10">$10</option><option value="100">$100</option></select></div>
        <div class="sm:col-span-2"><label class="label">Que el nombre o código contenga</label><input v-model="pr.buscar" class="input" placeholder="Opcional, ej: cemento" /></div>
      </div>
      <div class="mt-3 rounded-xl bg-marca-fondo p-3 text-sm">
        <div class="flex items-center justify-between"><span class="font-semibold">Vista previa</span><button class="btn-ghost !py-1 text-xs" @click="previsualizar" :disabled="prevCargando || (pr.modo === 'porcentaje' && !pr.porcentaje)">{{ prevCargando ? 'Calculando…' : 'Calcular' }}</button></div>
        <template v-if="prev">
          <p class="text-xs mt-1">Se van a tocar <b>{{ prev.n }}</b> artículos. Ejemplos:</p>
          <table class="table text-xs mt-1"><tbody><tr v-for="e in prev.ejemplos" :key="e.sku"><td>{{ e.nombre }}</td><td class="text-right tabular-nums"><template v-if="pr.campo !== 'cost' || pr.modo === 'margen'">{{ moneda(e.antes) }} → <b>{{ moneda(e.despues) }}</b></template><template v-else>costo {{ moneda(e.costo_antes) }} → <b>{{ moneda(e.costo_despues) }}</b></template></td></tr></tbody></table>
        </template>
        <p v-else class="text-xs text-marca-muted mt-1">Calculá antes de aplicar para ver cuántos artículos cambian y cómo quedan.</p>
      </div>
      <p class="text-xs text-marca-muted mt-3">Queda en auditoría quién lo hizo y cuándo, y se puede deshacer la última actualización durante 7 días.</p>
      <template #pie><button class="btn-ghost text-carmin" @click="router.post('/stock/precios/deshacer', {}, { preserveScroll: true, onSuccess: () => (preciosAbierto = false) })">Deshacer la última</button><span class="flex-1"></span><button class="btn-secondary" @click="preciosAbierto = false">Cancelar</button><button class="btn-primary" :disabled="pr.processing || (pr.modo === 'porcentaje' && !pr.porcentaje)" @click="pr.post('/stock/precios', { preserveScroll: true, onSuccess: () => { preciosAbierto = false; prev = null; pr.ids = []; sel = [] } })">{{ pr.modo === 'margen' ? 'Recalcular' : 'Aplicar ' + (pr.porcentaje ? pr.porcentaje + '%' : '') }}</button></template>
    </Modal>

    <!-- Rubros y depósitos -->
    <Modal :abierto="configAbierto" titulo="Rubros y depósitos" ancho="max-w-4xl" @cerrar="configAbierto = false">
      <div class="grid md:grid-cols-2 gap-6">
        <div>
          <p class="label">Rubros</p>
          <div v-for="r in rubros" :key="r.id" class="flex items-center gap-2 py-1 text-sm" :style="{ paddingLeft: (r.nivel || 0) * 16 + 'px' }">
            <span class="w-2.5 h-2.5 rounded-full" :style="{ background: r.color || '#6f6a62' }"></span><span class="flex-1">{{ r.nombre }}</span>
            <span v-if="r.articulos" class="text-[10px] text-marca-muted">{{ r.articulos }} art.</span>
            <button @click="editarRubro(r)" class="text-xs text-violeta" data-e2e="editar-rubro">editar</button>
            <Link :href="`/stock/rubros/${r.id}`" method="delete" as="button" preserve-scroll class="text-xs text-carmin">quitar</Link>
          </div>
          <div class="mt-3 p-3 rounded-xl bg-marca-fondo grid grid-cols-[1fr_auto] gap-2 items-end">
            <div><label class="label">{{ rubro.id ? 'Editar rubro' : 'Nuevo rubro' }}</label><input v-model="rubro.nombre" class="input !py-1.5" placeholder="Nombre" /></div>
            <input v-model="rubro.color" type="color" class="w-10 h-9 rounded-lg border border-marca-borde" />
            <select v-model="rubro.parent_id" class="input !py-1.5 col-span-2"><option :value="null">Categoría principal</option><option v-for="r in rubros.filter(x => !esDescendiente(x, rubro.id))" :key="r.id" :value="r.id">Dentro de {{ r.completo }}</option></select>
            <details class="col-span-2 text-xs" data-e2e="rubro-datos" :open="rubroTieneDatos">
              <summary class="cursor-pointer font-semibold text-violeta">Datos que heredan los artículos</summary>
              <p class="text-[11px] text-marca-muted my-1.5">Lo que dejes en "Hereda" lo toma del rubro de arriba. Un artículo nuevo arranca con estos datos; con <b>Aplicar</b> se llevan a los que ya existen.</p>
              <div class="grid grid-cols-2 gap-2">
                <div><label class="label !text-[10px]">IVA</label><select v-model="rubro.iva" class="input !py-1"><option :value="null">Hereda{{ heredado('iva', v => v + ' %') }}</option><option v-for="a in [0, 2.5, 5, 10.5, 21, 27]" :key="a" :value="a">{{ a }} %</option></select></div>
                <div><label class="label !text-[10px]">Tipo</label><select v-model="rubro.tipo" class="input !py-1"><option :value="null">Hereda{{ heredado('tipo', v => tipos[v]?.split(' (')[0]) }}</option><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l.split(' (')[0] }}</option></select></div>
                <div v-for="m in marcasRubro" :key="m.k"><label class="label !text-[10px]">{{ m.l }}</label><select v-model="rubro[m.k]" class="input !py-1" :data-e2e="`rubro-${m.k}`"><option :value="null">Hereda{{ heredado(m.k, v => v ? 'sí' : 'no') }}</option><option :value="true">Sí</option><option :value="false">No</option></select></div>
                <div class="col-span-2"><label class="label !text-[10px]">Cuenta contable de ventas</label><select v-model="rubro.cuenta_ventas_id" class="input !py-1"><option :value="null">Hereda (o Ventas)</option><option v-for="c in cuentasVentas" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option></select></div>
                <div><label class="label !text-[10px]">Percepción IVA especial %</label><input v-model.number="rubro.perc_iva" type="number" step="0.01" min="0" class="input !py-1" placeholder="la general" /></div>
                <div><label class="label !text-[10px]">Percepción IIBB especial %</label><input v-model.number="rubro.perc_iibb" type="number" step="0.01" min="0" class="input !py-1" placeholder="la general" /></div>
                <div class="col-span-2"><label class="label !text-[10px]">Foto para la tienda (URL)</label><input v-model="rubro.imagen" class="input !py-1" placeholder="https://…" /></div>
              </div>
            </details>
            <div class="col-span-2 flex flex-wrap gap-2"><button class="btn-primary !py-1 text-xs" :disabled="!rubro.nombre" @click="guardarRubro">Guardar</button><button v-if="rubro.id" class="btn-ghost !py-1 text-xs" @click="rubro.reset()">Cancelar</button>
              <button v-if="rubro.id" class="btn-secondary !py-1 text-xs ml-auto" data-e2e="aplicar-rubro" @click="router.post(`/stock/rubros/${rubro.id}/aplicar`, { campos: ['iva', 'tipo', 'perecedero', 'seriado', 'controla_stock', 'control_turno', 'en_tienda'] }, { preserveScroll: true })">Aplicar a sus artículos</button></div>
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

const props = defineProps({ cuentasVentas: { type: Array, default: () => [] }, lista: Object, filtros: Object, depositos: Array, rubros: Array, tipos: Object, unidades: Object, kpis: Object, proveedores: Array, ultimosInventarios: Array, cotizacion: Object })
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
const pr = useForm({ modo: 'porcentaje', porcentaje: null, campo: 'price', rubro_id: null, proveedor_id: null, marca: '', listas: [], redondeo: '10', buscar: '', ids: [] })
const sel = ref([])
const prev = ref(null), prevCargando = ref(false)
const marcas = computed(() => [...new Set((props.lista?.data ?? props.lista ?? []).map(a => a.marca).filter(Boolean))].sort())
async function previsualizar() { prevCargando.value = true; try { const r = await fetch('/stock/precios/previsualizar', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '') }, body: JSON.stringify(pr.data()) }); prev.value = r.ok ? await r.json() : null } catch (e) { prev.value = null } finally { prevCargando.value = false } }

const configAbierto = ref(false)
const rubroVacio = { id: null, nombre: '', parent_id: null, color: '#4f3089', iva: null, tipo: null, perecedero: null, seriado: null, controla_stock: null, control_turno: null, en_tienda: null, cuenta_ventas_id: null, perc_iva: null, perc_iibb: null, imagen: null }
const rubro = useForm({ ...rubroVacio })
const marcasRubro = [{ k: 'controla_stock', l: 'Controla stock' }, { k: 'perecedero', l: 'Perecedero' }, { k: 'seriado', l: 'Con número de serie' }, { k: 'control_turno', l: 'Se cuenta en el turno' }, { k: 'en_tienda', l: 'Sale en la tienda' }]
function editarRubro(r) { Object.assign(rubro, rubroVacio, Object.fromEntries(Object.keys(rubroVacio).map(k => [k, r[k] ?? rubroVacio[k]])), { color: r.color || '#6f6a62' }) }
const rubroTieneDatos = computed(() => ['iva', 'tipo', 'perecedero', 'seriado', 'controla_stock', 'control_turno', 'en_tienda', 'cuenta_ventas_id', 'perc_iva', 'perc_iibb', 'imagen'].some(k => rubro[k] !== null && rubro[k] !== ''))
// Lo que heredaría del rubro padre elegido, para mostrarlo en la opción "Hereda".
function heredado(k, fmt) { const p = props.rubros.find(x => x.id === rubro.parent_id); const e = p?.efectivos?.[k]; return e ? ` (${fmt(e.valor)} de ${e.de || p.nombre})` : '' }
function guardarRubro() { rubro.transform(d => ({ ...d, perc_iva: d.perc_iva === '' ? null : d.perc_iva, perc_iibb: d.perc_iibb === '' ? null : d.perc_iibb, imagen: d.imagen || null })).post(`/stock/rubros${rubro.id ? '/' + rubro.id : ''}`, { preserveScroll: true, onSuccess: () => rubro.reset() }) }
// Un rubro no puede colgar de sí mismo ni de sus propias subcategorías.
const esDescendiente = (r, id) => { if (!id) return false; let x = r; while (x) { if (x.id === id) return true; x = props.rubros.find(y => y.id === x.parent_id) } return false }
const dep = useForm({ id: null, nombre: '', business_location_id: null, direccion: '', es_default: false, activo: true })
</script>
