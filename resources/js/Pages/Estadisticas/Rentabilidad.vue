<template>
  <AppLayout titulo="Rentabilidad">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Rentabilidad real</h1><p class="page-subtitle">Cuánto queda después de pagar la mercadería, los costos variables y los fijos.</p></div>
      <div class="flex flex-wrap items-center gap-2">
        <select :value="sucursalId ?? ''" @change="$inertia.get('/estadisticas/rentabilidad', { ...periodo, sucursal: $event.target.value || undefined }, { preserveState: true, replace: true })" class="input w-auto !py-1 text-xs"><option value="">Todas las sucursales</option><option v-for="s in listaSucursales" :key="s.id" :value="s.id">{{ s.name }}</option></select>
        <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" :extra="{ sucursal: sucursalId }" />
        <Link href="/estadisticas" class="btn-secondary !py-1 text-xs">← Estadísticas</Link>
        <button class="btn-secondary !py-1 text-xs" @click="clasifAbierto = true">Clasificar costos<span v-if="sin_clasificar" class="ml-1 badge bg-carmin text-white">{{ sin_clasificar }}</span></button>
        <a :href="`/estadisticas/rentabilidad?desde=${periodo.desde}&hasta=${periodo.hasta}${sucursalId ? '&sucursal=' + sucursalId : ''}&export=1`" class="btn-secondary !py-1 text-xs">Exportar CSV</a>
      </div>
    </div>

    <!-- KPIs -->
    <EstadisticasTabs activa="rentabilidad" />
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <StatCard label="Ventas netas" :valor="kpis.ventas" />
      <StatCard :label="`Margen bruto ${pct(kpis.margen_bruto_pct)}`" :valor="kpis.margen_bruto" :alerta="kpis.margen_bruto < 0" />
      <StatCard :label="`Margen de contribución ${pct(kpis.margen_contribucion_pct)}`" :valor="kpis.margen_contribucion" :alerta="kpis.margen_contribucion < 0" />
      <StatCard :label="`Resultado ${pct(kpis.resultado_pct)}`" :valor="kpis.resultado" :alerta="kpis.resultado < 0" />
    </div>

    <div class="grid lg:grid-cols-3 gap-5 mb-5">
      <!-- Cascada -->
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-1">De la venta al resultado</h2>
        <p class="text-xs text-marca-muted mb-3">Cada barra descuenta un escalón de costos. Si el resultado es negativo, el negocio pierde plata en el período.</p>
        <div class="space-y-2 text-sm">
          <div v-for="e in cascada" :key="e.label" class="grid grid-cols-[150px_1fr_120px] items-center gap-2">
            <span :class="e.fuerte ? 'font-bold' : 'text-marca-muted'">{{ e.label }}</span>
            <div class="h-5 bg-marca-fondo rounded-full overflow-hidden relative">
              <div class="h-full rounded-full" :class="e.color" :style="{ width: Math.min(100, Math.abs(e.valor) / maxCascada * 100) + '%' }"></div>
            </div>
            <span class="text-right tabular-nums" :class="[e.fuerte ? 'font-bold' : '', e.valor < 0 && e.fuerte ? 'text-carmin' : '']">{{ e.signo }}{{ moneda(Math.abs(e.valor), 0) }}</span>
          </div>
        </div>
      </div>
      <!-- Punto de equilibrio -->
      <div class="card">
        <h2 class="font-bold mb-1">Punto de equilibrio</h2>
        <p class="text-xs text-marca-muted mb-3">Lo mínimo que hay que vender en el período para cubrir todos los costos fijos.</p>
        <template v-if="kpis.punto_equilibrio !== null">
          <p class="text-2xl font-extrabold tabular-nums">{{ moneda(kpis.punto_equilibrio, 0) }}</p>
          <p class="text-xs text-marca-muted">≈ {{ moneda(kpis.punto_equilibrio_diario, 0) }} por día · vendés {{ moneda(kpis.ventas_diarias, 0) }} por día</p>
          <div class="mt-3 h-3 bg-marca-fondo rounded-full overflow-hidden"><div class="h-full rounded-full" :class="kpis.cobertura_pct >= 100 ? 'bg-emerald-500' : 'bg-carmin'" :style="{ width: Math.min(100, kpis.cobertura_pct) + '%' }"></div></div>
          <p class="text-sm mt-2" :class="kpis.cobertura_pct >= 100 ? 'text-emerald-700' : 'text-carmin'">
            <template v-if="kpis.cobertura_pct >= 100">Cubrís los fijos: estás {{ kpis.cobertura_pct - 100 }}% arriba del punto de equilibrio.</template>
            <template v-else>Te falta {{ moneda(kpis.punto_equilibrio - kpis.ventas, 0) }} de ventas para cubrir los fijos ({{ kpis.cobertura_pct }}%).</template>
          </p>
        </template>
        <p v-else class="text-sm text-marca-muted">No se puede calcular: el margen de contribución es cero o negativo{{ kpis.ventas ? '' : ' y no hay ventas' }}.</p>
        <dl class="mt-4 text-xs grid grid-cols-2 gap-1">
          <dt class="text-marca-muted">Costos fijos</dt><dd class="text-right tabular-nums">{{ moneda(kpis.fijos, 0) }}</dd>
          <dt class="text-marca-muted">Fijos por día</dt><dd class="text-right tabular-nums">{{ moneda(kpis.fijos_diarios, 0) }}</dd>
          <dt class="text-marca-muted">Costos directos</dt><dd class="text-right tabular-nums">{{ moneda(kpis.directos, 0) }}</dd>
          <dt class="text-marca-muted">Costos indirectos</dt><dd class="text-right tabular-nums">{{ moneda(kpis.indirectos, 0) }}</dd>
        </dl>
      </div>
    </div>

    <!-- Fase 33.3: márgenes a vigilar, precios a revisar, el margen que se achica -->
    <div class="card mb-5" data-e2e="vigilar">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
        <h2 class="font-bold">Márgenes a vigilar <span class="text-marca-muted cursor-help font-normal text-sm" :title="vigilar.como">ⓘ</span></h2>
        <div class="flex flex-wrap items-center gap-1.5 text-xs">
          <span v-for="(n, k) in vigilar.cantidad" :key="k" class="rounded-full px-2.5 py-1 font-semibold cursor-pointer border" :class="[{ rojo: 'bg-red-100 text-red-800', amarillo: 'bg-amber-100 text-amber-800', verde: 'bg-emerald-50 text-emerald-700' }[k], filtroColor === k ? 'border-marca-texto' : 'border-transparent']" @click="ir({ color: filtroColor === k ? undefined : k })">{{ n }} {{ k }}</span>
          <select :value="vigilar.lista" class="input !py-1 !text-xs w-auto" @change="ir({ lista: $event.target.value })"><option v-for="l in [1,2,3,4,5,6]" :key="l" :value="l">Lista {{ l }}</option></select>
          <input :value="filtros.q" class="input !py-1 !text-xs w-36" placeholder="Buscar…" @keydown.enter="ir({ q: $event.target.value })" />
          <button class="btn-secondary !py-1" data-e2e="minimos" @click="minAbierto = true">Mínimos</button>
        </div>
      </div>
      <p v-if="vigilar.general === null && !vigilar.rubros.some(r => r.margen_minimo !== null) && !vigilar.items.length" class="text-sm text-marca-muted">Todavía no hay ningún mínimo: poné uno general, por rubro o por artículo en <b>Mínimos</b> y los artículos aparecen en rojo, amarillo o verde.</p>
      <div v-else class="overflow-x-auto max-h-96"><table class="table text-xs">
        <thead><tr><th></th><th>Artículo</th><th class="text-right">Precio</th><th class="text-right">Costo</th><th class="text-right">Margen s/costo</th><th class="text-right">Mínimo</th><th class="text-right">Hace 30 d</th><th class="text-right">Hace 90 d</th><th>Último cambio</th><th></th></tr></thead>
        <tbody><tr v-for="i in vigilar.items" :key="i.id" :data-e2e="`vig-${i.id}`">
          <td><span class="inline-block w-3 h-3 rounded-full" :class="{ rojo: 'bg-red-500', amarillo: 'bg-amber-400', verde: 'bg-emerald-500' }[i.estado]"></span></td>
          <td><Link :href="`/stock/${i.id}`" class="font-medium hover:text-carmin">{{ i.nombre }}</Link> <span class="text-marca-muted">{{ i.sku }}<span v-if="i.rubro"> · {{ i.rubro }}</span></span></td>
          <td class="text-right tabular-nums">{{ moneda(i.precio) }}</td><td class="text-right tabular-nums text-marca-muted">{{ moneda(i.costo) }}</td>
          <td class="text-right tabular-nums font-bold" :class="{ rojo: 'text-red-700', amarillo: 'text-amber-700', verde: 'text-emerald-700' }[i.estado]">{{ i.margen }}%</td>
          <td class="text-right tabular-nums">{{ i.minimo }}% <span class="text-marca-muted">({{ i.origen_minimo }})</span></td>
          <td class="text-right tabular-nums">{{ flecha(i.margen, i.hace30) }}</td><td class="text-right tabular-nums">{{ flecha(i.margen, i.hace90) }}</td>
          <td class="text-marca-muted">{{ i.ultimo_cambio ? `${i.ultimo_cambio.fecha}: precio ${moneda(i.ultimo_cambio.precio_antes, 0)} → ${moneda(i.ultimo_cambio.precio, 0)}, costo ${moneda(i.ultimo_cambio.costo_antes, 0)} → ${moneda(i.ultimo_cambio.costo, 0)}` : '—' }}</td>
          <td class="text-right whitespace-nowrap"><button class="text-violeta font-semibold" @click="verHistorial(i)">Historial</button> · <input :value="i.margen_minimo_articulo" type="number" step="any" class="input !py-0.5 !text-xs w-16 text-right" placeholder="mín." title="Mínimo de este artículo" @change="minArticulo(i, $event.target.value)" /></td>
        </tr><tr v-if="!vigilar.items.length"><td colspan="10" class="text-center text-marca-muted py-6">Nada con este filtro.</td></tr></tbody></table></div>
    </div>

    <div class="grid lg:grid-cols-2 gap-5 mb-5">
      <div class="card" data-e2e="revisar">
        <h2 class="font-bold mb-2">Precios a revisar <span class="text-marca-muted cursor-help font-normal text-sm" :title="revisar.como">ⓘ</span></h2>
        <div class="flex gap-1.5 text-xs mb-2"><button v-for="t in [['debajo', `Debajo del costo (${revisar.debajo_costo.length})`], ['bajo', `Bajo el mínimo (${revisar.bajo_minimo.length})`], ['viejo', `Precio viejo (${revisar.total_viejo})`]]" :key="t[0]" class="rounded-full px-2.5 py-1 font-semibold border" :class="tabRev === t[0] ? 'bg-marca-texto text-white border-marca-texto' : 'border-marca-borde'" @click="tabRev = t[0]">{{ t[1] }}</button></div>
        <div class="max-h-72 overflow-y-auto text-sm">
          <template v-if="tabRev === 'debajo'"><div v-for="r in revisar.debajo_costo" :key="r.id" class="flex justify-between gap-2 py-1 border-t border-marca-borde"><Link :href="`/stock/${r.id}`" class="truncate hover:text-carmin">{{ r.nombre }}</Link><span class="tabular-nums text-carmin whitespace-nowrap">{{ moneda(r.precio) }} &lt; costo {{ moneda(r.costo) }}</span></div><p v-if="!revisar.debajo_costo.length" class="text-emerald-700 py-4 text-center">Ningún precio por debajo del costo.</p></template>
          <template v-else-if="tabRev === 'bajo'"><div v-for="r in revisar.bajo_minimo" :key="r.id" class="flex justify-between gap-2 py-1 border-t border-marca-borde"><Link :href="`/stock/${r.id}`" class="truncate hover:text-carmin">{{ r.nombre }}</Link><span class="tabular-nums whitespace-nowrap"><b class="text-carmin">{{ r.margen }}%</b> de {{ r.minimo }}% mínimo</span></div><p v-if="!revisar.bajo_minimo.length" class="text-emerald-700 py-4 text-center">Todos cumplen su mínimo.</p></template>
          <template v-else><div v-for="(g, k) in revisar.viejo" :key="k"><p v-if="g.length" class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mt-2">{{ { '90_180': '90 a 180 días', '180_365': '6 meses a 1 año', mas_365: 'más de un año', nunca: 'sin fecha de actualización' }[k] }} · {{ g.length }}</p><div v-for="r in g" :key="r.id" class="flex justify-between gap-2 py-1 border-t border-marca-borde"><Link :href="`/stock/${r.id}`" class="truncate hover:text-carmin">{{ r.nombre }}</Link><span class="tabular-nums whitespace-nowrap text-marca-muted">{{ moneda(r.precio) }}{{ r.dias ? ' · ' + r.dias + ' días' : '' }}</span></div></div><p v-if="!revisar.total_viejo" class="text-emerald-700 py-4 text-center">Todo lo que se vende tiene el precio actualizado.</p></template>
        </div>
      </div>
      <div class="card" data-e2e="achica">
        <h2 class="font-bold mb-1">El margen que se está achicando <span class="text-marca-muted cursor-help font-normal text-sm" :title="achica.como">ⓘ</span></h2>
        <p class="text-xs text-marca-muted mb-2">{{ achica.mes }} contra {{ achica.mes_anterior }}. {{ achica.items.length ? `Dejaste de ganar ${moneda(achica.total, 0)} este mes.` : 'Ningún artículo perdió 2 puntos o más de margen.' }}</p>
        <div class="max-h-72 overflow-y-auto text-sm"><div v-for="a in achica.items" :key="a.id" class="py-1.5 border-t border-marca-borde"><div class="flex justify-between gap-2"><Link :href="`/stock/${a.id}`" class="font-medium truncate hover:text-carmin">{{ a.nombre }}</Link><span class="tabular-nums whitespace-nowrap"><span class="text-marca-muted">{{ a.margen_anterior }}%</span> → <b class="text-carmin">{{ a.margen_actual }}%</b></span></div><p class="text-xs text-marca-muted">{{ a.motivo }}: costo {{ moneda(a.costo_anterior) }} → {{ moneda(a.costo_actual) }}, precio {{ moneda(a.precio_anterior) }} → {{ moneda(a.precio_actual) }} · dejaste de ganar <b>{{ moneda(a.dejaste_de_ganar, 0) }}</b></p></div></div>
      </div>
    </div>

    <Modal :abierto="minAbierto" titulo="Márgenes mínimos a vigilar" ancho="max-w-2xl" @cerrar="minAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Sobre el costo, como la ficha. Manda el del artículo, después el del rubro, por último el general. Vacío = no se vigila.</p>
      <div class="flex items-center gap-2 mb-3"><label class="label !mb-0">General para todo el negocio</label><input v-model="minForm.general" type="number" step="any" min="0" class="input w-28" data-e2e="min-general" /><span class="text-sm">%</span></div>
      <div class="max-h-64 overflow-y-auto border border-marca-borde rounded-xl"><table class="table text-sm"><tbody><tr v-for="r in minForm.rubros" :key="r.id"><td :class="r.parent_id ? 'pl-8' : ''">{{ r.nombre }}</td><td class="text-right"><input v-model="r.margen_minimo" type="number" step="any" min="0" class="input !py-1 w-24 text-right" placeholder="—" /></td></tr></tbody></table></div>
      <template #pie><button class="btn-secondary" @click="minAbierto = false">Cancelar</button><button class="btn-primary" :disabled="minForm.processing" data-e2e="min-guardar" @click="minForm.post('/estadisticas/rentabilidad/minimos', { preserveScroll: true, onSuccess: () => (minAbierto = false) })">Guardar</button></template>
    </Modal>

    <Modal :abierto="!!hist" :titulo="hist ? 'Historial · ' + hist.nombre : ''" ancho="max-w-2xl" @cerrar="hist = null">
      <template v-if="hist">
        <div class="flex items-end gap-1 h-32 mb-3 border-b border-marca-borde relative">
          <div v-if="hist.minimo !== null" class="absolute left-0 right-0 border-t border-dashed border-carmin text-[10px] text-carmin" :style="{ bottom: Math.min(100, Math.max(0, hist.minimo / maxHist * 100)) + '%' }">mínimo {{ hist.minimo }}%</div>
          <div v-for="(s, i) in hist.serie" :key="i" class="flex-1 flex flex-col items-center justify-end h-full" :title="`${s.fecha}: margen ${s.margen ?? '—'}%`"><div class="w-full max-w-[22px] rounded-t" :class="s.margen !== null && hist.minimo !== null && s.margen < hist.minimo ? 'bg-carmin' : 'bg-violeta'" :style="{ height: Math.max(2, ((s.margen ?? 0) / maxHist) * 100) + '%' }"></div></div>
        </div>
        <table class="table text-xs"><thead><tr><th>Fecha</th><th class="text-right">Precio</th><th class="text-right">Costo</th><th class="text-right">Margen</th><th>Quién</th></tr></thead><tbody><tr v-for="(s, i) in [...hist.serie].reverse()" :key="i"><td>{{ s.fecha }}{{ s.hoy ? ' (hoy)' : '' }}</td><td class="text-right tabular-nums">{{ moneda(s.precio) }}<span v-if="s.precio_antes !== undefined && s.precio_antes !== s.precio" class="text-marca-muted"> (antes {{ moneda(s.precio_antes) }})</span></td><td class="text-right tabular-nums">{{ moneda(s.costo) }}<span v-if="s.costo_antes !== undefined && s.costo_antes !== s.costo" class="text-marca-muted"> (antes {{ moneda(s.costo_antes) }})</span></td><td class="text-right tabular-nums font-semibold">{{ s.margen ?? '—' }}%</td><td class="text-marca-muted">{{ s.usuario ?? '' }}</td></tr></tbody></table>
      </template>
    </Modal>

    <!-- Serie mensual -->
    <div class="card mb-5">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3"><h2 class="font-bold">Últimos 12 meses</h2><p class="text-[11px] text-marca-muted">Barra: ventas netas · línea violeta: margen bruto · punto: resultado</p></div>
      <div class="flex items-end gap-1 h-40">
        <div v-for="m in serie" :key="m.mes" class="flex-1 flex flex-col items-center justify-end h-full min-w-0 relative" :title="`${m.mes}: ventas ${moneda(m.ventas, 0)} · margen bruto ${moneda(m.margen_bruto, 0)} · fijos ${moneda(m.fijos, 0)} · resultado ${moneda(m.resultado, 0)}`">
          <div class="w-full h-full flex items-end relative">
            <div class="w-full rounded-t bg-marca-borde" :style="{ height: Math.max(1, m.ventas / maxSerie * 100) + '%' }"></div>
            <div class="absolute left-1/4 right-1/4 rounded-t bg-violeta" :style="{ height: Math.max(0, m.margen_bruto / maxSerie * 100) + '%' }"></div>
            <div class="absolute left-1/2 -translate-x-1/2 w-2 h-2 rounded-full border-2 border-white" :class="m.resultado >= 0 ? 'bg-emerald-500' : 'bg-carmin'" :style="{ bottom: Math.max(0, Math.min(100, m.resultado / maxSerie * 100)) + '%' }"></div>
          </div>
          <span class="text-[9px] text-marca-muted mt-1 truncate w-full text-center">{{ m.mes }}</span>
        </div>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-5 mb-5">
      <!-- Costos del período -->
      <div class="card">
        <h2 class="font-bold mb-1">Costos del período</h2>
        <p class="text-xs text-marca-muted mb-3">Gastos de fondos por categoría, compras no inventariables, comisiones, sueldos y amortizaciones.</p>
        <p v-if="dobles.length" class="text-xs bg-amber-50 text-amber-800 rounded-lg p-2 mb-2">La categoría "{{ dobles.join('", "') }}" no se suma porque ya están las liquidaciones de sueldos del período.</p>
        <div class="flex gap-1 bg-marca-fondo rounded-full p-1 text-xs mb-3"><button v-for="v in [['tipo', 'Fijos / variables'], ['imputacion', 'Directos / indirectos']]" :key="v[0]" class="flex-1 px-3 py-1 rounded-full font-semibold" :class="vista === v[0] ? 'bg-white shadow' : 'text-marca-muted'" @click="vista = v[0]">{{ v[1] }}</button></div>
        <div v-for="g in grupos" :key="g.clave" class="mb-3">
          <div class="flex justify-between text-xs font-bold uppercase tracking-widest text-marca-muted mb-1"><span>{{ g.label }}</span><span class="tabular-nums">{{ moneda(g.total, 0) }}</span></div>
          <div v-for="l in g.lineas" :key="l.nombre" class="flex items-center gap-2 text-sm py-0.5" :class="l.excluido ? 'opacity-50 line-through' : ''">
            <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ background: l.color }"></span>
            <span class="flex-1 truncate">{{ l.nombre }}</span>
            <span class="tabular-nums">{{ moneda(l.monto, 0) }}</span>
          </div>
          <p v-if="!g.lineas.length" class="text-xs text-marca-muted">Nada en este período.</p>
        </div>
        <p v-if="!lineas.length" class="text-sm text-marca-muted">No hay costos cargados en el período. Cargá gastos en Fondos con su categoría.</p>
      </div>

      <!-- Por dimensión -->
      <div class="card lg:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
          <h2 class="font-bold">Rentabilidad por…</h2>
          <div class="flex flex-wrap gap-1 bg-marca-fondo rounded-full p-1 text-xs"><button v-for="t in tabs" :key="t[0]" class="px-3 py-1 rounded-full font-semibold" :class="tab === t[0] ? 'bg-white shadow' : 'text-marca-muted'" @click="tab = t[0]">{{ t[1] }}</button></div>
        </div>
        <p class="text-xs text-marca-muted mb-2">
          Margen bruto = ventas − costo de la mercadería al momento de vender.
          <template v-if="config.distribuir_indirectos">Los costos indirectos se reparten proporcional a las ventas de cada fila.</template>
          <template v-else>Los costos indirectos no se reparten (Configurar).</template>
          <template v-if="tab === 'vendedores'"> Las comisiones van como costo directo de cada vendedor.</template>
          <template v-if="(por[tab] ?? []).length >= 500"> Se muestran los 500 con más ventas.</template>
        </p>
        <div class="overflow-x-auto">
          <table class="tabla text-sm">
            <thead><tr><th>{{ tabs.find(t => t[0] === tab)[1] }}</th><th class="text-right">Ventas</th><th class="text-right">Costo</th><th class="text-right">Margen bruto</th><th class="text-right">%</th><th v-if="tab === 'vendedores'" class="text-right">Comisión</th><th v-if="config.distribuir_indirectos" class="text-right">Indirectos</th><th class="text-right">Resultado</th></tr></thead>
            <tbody>
              <tr v-for="r in filas" :key="r.clave ?? r.nombre">
                <td><p class="font-medium truncate max-w-[220px]">{{ r.nombre }}</p><p class="text-[11px] text-marca-muted">{{ r.participacion }}% de las ventas<template v-if="tab === 'articulos' && r.cantidad"> · {{ cantidad(r.cantidad) }} un.</template></p></td>
                <td class="text-right tabular-nums">{{ moneda(r.ventas, 0) }}</td>
                <td class="text-right tabular-nums text-marca-muted">{{ moneda(r.costo, 0) }}</td>
                <td class="text-right tabular-nums" :class="r.margen_bruto < 0 ? 'text-carmin' : ''">{{ moneda(r.margen_bruto, 0) }}</td>
                <td class="text-right tabular-nums"><span class="badge" :class="r.margen_pct === null ? '' : r.margen_pct < 10 ? 'bg-red-100 text-red-800' : r.margen_pct < 25 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'">{{ r.margen_pct === null ? '—' : r.margen_pct + '%' }}</span></td>
                <td v-if="tab === 'vendedores'" class="text-right tabular-nums text-marca-muted">{{ moneda(r.directos || 0, 0) }}</td>
                <td v-if="config.distribuir_indirectos" class="text-right tabular-nums text-marca-muted">{{ moneda(r.indirectos, 0) }}</td>
                <td class="text-right tabular-nums font-semibold" :class="r.resultado < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(r.resultado, 0) }}</td>
              </tr>
              <tr v-if="!filas.length"><td colspan="8" class="text-center text-marca-muted py-6">{{ cargandoDim ? 'Calculando…' : 'Sin ventas en el período.' }}</td></tr>
            </tbody>
          </table>
        </div>
        <button v-if="(por[tab] ?? []).length > 15" class="text-xs text-violeta font-semibold mt-2" @click="verTodo = !verTodo">{{ verTodo ? 'Ver menos' : `Ver los ${(por[tab] ?? []).length}` }}</button>
      </div>
    </div>

    <!-- Configuración -->
    <div class="card">
      <h2 class="font-bold mb-1">Cómo se calcula</h2>
      <div class="grid sm:grid-cols-3 gap-3 text-sm">
        <label class="flex items-start gap-2"><input v-model="cfg.distribuir_indirectos" type="checkbox" class="mt-1" /><span>Repartir los costos indirectos entre artículos, clientes, etc. según su peso en las ventas.</span></label>
        <div><label class="label">Compras no inventariables (servicios, insumos) son</label><select v-model="cfg.compras_gastos" class="input"><option value="variable">Costo variable</option><option value="fijo">Costo fijo</option></select></div>
        <div><label class="label">Gastos sin categoría son</label><select v-model="cfg.sin_categoria" class="input"><option value="fijo">Costo fijo</option><option value="variable">Costo variable</option></select></div>
      </div>
      <div class="flex justify-end mt-3"><button class="btn-primary" :disabled="cfg.processing" @click="cfg.post('/estadisticas/rentabilidad/config', { preserveScroll: true })">Guardar</button></div>
    </div>

    <Modal :abierto="clasifAbierto" titulo="Clasificar categorías de gasto" ancho="max-w-3xl" @cerrar="clasifAbierto = false">
      <p class="text-sm text-marca-muted mb-3"><b>Fijo</b>: se paga igual vendas o no (alquiler, sueldos, servicios). <b>Variable</b>: crece con las ventas (fletes, comisiones, embalajes). <b>Directo</b>: se puede atribuir a una venta u obra puntual. <b>Indirecto</b>: es de todo el negocio.</p>
      <div class="overflow-x-auto"><table class="tabla text-sm">
        <thead><tr><th>Categoría</th><th>Tipo de costo</th><th>Imputación</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in clasif.categorias" :key="c.id">
            <td><span class="inline-block w-2.5 h-2.5 rounded-full mr-1" :style="{ background: c.color }"></span>{{ c.name }}</td>
            <td><select v-model="c.tipo_costo" class="input !py-1"><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l }}</option></select></td>
            <td><select v-model="c.imputacion" class="input !py-1"><option v-for="(l, k) in imputaciones" :key="k" :value="k">{{ l }}</option></select></td>
            <td class="text-xs text-marca-muted"><button v-if="c.sugerencia.tipo_costo !== c.tipo_costo || c.sugerencia.imputacion !== c.imputacion" class="text-violeta" @click="c.tipo_costo = c.sugerencia.tipo_costo; c.imputacion = c.sugerencia.imputacion">Sugerido: {{ tipos[c.sugerencia.tipo_costo] }} · {{ imputaciones[c.sugerencia.imputacion] }}</button></td>
          </tr>
          <tr v-if="!clasif.categorias.length"><td colspan="4" class="text-center text-marca-muted py-4">Todavía no hay categorías. Crealas en Fondos → Nueva categoría.</td></tr>
        </tbody>
      </table></div>
      <template #pie><button class="btn-secondary" @click="clasifAbierto = false">Cancelar</button><button class="btn-primary" :disabled="clasif.processing || !clasif.categorias.length" @click="clasif.post('/estadisticas/rentabilidad/categorias', { preserveScroll: true, onSuccess: () => (clasifAbierto = false) })">Guardar clasificación</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link, useForm, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadisticasTabs from '@/Components/EstadisticasTabs.vue'
import StatCard from '@/Components/StatCard.vue'
import Modal from '@/Components/Modal.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { moneda, cantidad } from '@/util/formato'

const props = defineProps({ periodo: Object, sucursalId: Number, listaSucursales: Array, kpis: Object, lineas: Array, dobles: Array, por: Object, serie: Array, config: Object, categorias: Array, sin_clasificar: Number, tipos: Object, imputaciones: Object, vigilar: Object, revisar: Object, achica: Object })
// Fase 33.3: márgenes a vigilar
const pagina = usePage()
const filtros = computed(() => Object.fromEntries(new URLSearchParams(pagina.url.split('?')[1] ?? '')))
const filtroColor = computed(() => filtros.value.color ?? null)
function ir(extra) { router.get('/estadisticas/rentabilidad', Object.fromEntries(Object.entries({ ...filtros.value, ...extra }).filter(([, v]) => v !== undefined && v !== '')), { preserveState: true, preserveScroll: true, replace: true, only: ['vigilar'] }) }
const flecha = (ahora, antes) => antes === null || antes === undefined ? '—' : (ahora > antes + 0.05 ? '▲ ' : ahora < antes - 0.05 ? '▼ ' : '= ') + antes + '%'
const minAbierto = ref(false), tabRev = ref('debajo'), hist = ref(null)
const minForm = useForm({ general: props.vigilar.general, rubros: props.vigilar.rubros.map(r => ({ id: r.id, parent_id: r.parent_id, nombre: r.nombre, margen_minimo: r.margen_minimo })) })
function minArticulo(i, v) { router.post('/estadisticas/rentabilidad/minimos', { articulos: [{ id: i.id, margen_minimo: v === '' ? null : Number(v) }] }, { preserveScroll: true, only: ['vigilar'] }) }
async function verHistorial(i) { const { data } = await window.axios.get(`/estadisticas/rentabilidad/historial/${i.id}`); hist.value = data }
const maxHist = computed(() => Math.max(10, ...(hist.value?.serie.map(s => s.margen ?? 0) ?? []), hist.value?.minimo ?? 0))

const pct = (v) => (v === null || v === undefined ? '' : `· ${v}%`)
const cascada = computed(() => [
  { label: 'Ventas netas', valor: props.kpis.ventas, signo: '', color: 'bg-marca-borde', fuerte: true },
  { label: 'Costo de lo vendido', valor: props.kpis.cmv, signo: '−', color: 'bg-carmin/60' },
  { label: 'Margen bruto', valor: props.kpis.margen_bruto, signo: '=', color: 'bg-violeta', fuerte: true },
  { label: 'Costos variables', valor: props.kpis.variables, signo: '−', color: 'bg-carmin/60' },
  { label: 'Margen de contribución', valor: props.kpis.margen_contribucion, signo: '=', color: 'bg-magenta', fuerte: true },
  { label: 'Costos fijos', valor: props.kpis.fijos, signo: '−', color: 'bg-carmin/60' },
  { label: 'Resultado', valor: props.kpis.resultado, signo: '=', color: props.kpis.resultado >= 0 ? 'bg-emerald-500' : 'bg-carmin', fuerte: true },
])
const maxCascada = computed(() => Math.max(1, ...cascada.value.map(e => Math.abs(e.valor))))
const maxSerie = computed(() => Math.max(1, ...props.serie.map(m => m.ventas)))

const vista = ref('tipo')
const grupos = computed(() => {
  const defs = vista.value === 'tipo' ? [['fijo', 'Costos fijos'], ['variable', 'Costos variables']] : [['directo', 'Costos directos'], ['indirecto', 'Costos indirectos']]
  return defs.map(([clave, label]) => {
    const ls = props.lineas.filter(l => l[vista.value] === clave).sort((a, b) => b.monto - a.monto)
    return { clave, label, lineas: ls, total: ls.filter(l => !l.excluido).reduce((s, l) => s + l.monto, 0) }
  })
})

const tabs = [['articulos', 'Artículo'], ['rubros', 'Rubro'], ['clientes', 'Cliente'], ['vendedores', 'Vendedor'], ['sucursales', 'Sucursal'], ['obras', 'Obra']]
const tab = ref('articulos')
const verTodo = ref(false)
// Las aperturas se piden al servidor al abrir cada solapa (con muchos comprobantes cada una es una consulta pesada).
const por = reactive({ ...props.por }); const cargandoDim = ref(false)
watch(tab, async t => {
  if (por[t]) return
  cargandoDim.value = true
  try { const q = new URLSearchParams({ dim: t, desde: props.periodo.desde, hasta: props.periodo.hasta, ...(props.sucursalId ? { sucursal: props.sucursalId } : {}) }); const r = await fetch(`/estadisticas/rentabilidad/dim?${q}`, { headers: { Accept: 'application/json' } }); por[t] = r.ok ? await r.json() : [] } catch (e) { por[t] = [] }
  finally { cargandoDim.value = false }
})
const filas = computed(() => { const l = por[tab.value] ?? []; return verTodo.value ? l : l.slice(0, 15) })

const cfg = useForm({ distribuir_indirectos: !!props.config.distribuir_indirectos, compras_gastos: props.config.compras_gastos, sin_categoria: props.config.sin_categoria })
const clasifAbierto = ref(false)
const clasif = useForm({ categorias: props.categorias.map(c => ({ ...c, tipo_costo: c.tipo_costo || c.sugerencia.tipo_costo, imputacion: c.imputacion || c.sugerencia.imputacion })) })
</script>
