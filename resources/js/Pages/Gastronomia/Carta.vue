<template>
  <AppLayout titulo="Big Restó · Carta">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <Link href="/gastronomia" class="text-xs text-marca-muted hover:text-carmin">← Salón</Link>
        <h1 class="page-title">Carta</h1>
        <p class="page-subtitle">Qué va a cocina y las opciones de cada plato: el punto de la carne, la guarnición, los extras con precio. El mozo las elige al pedir y salen en la comanda.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2"><button v-if="puede('gastronomia','editar')" class="btn-secondary" @click="abrirVitrina" data-e2e="vitrina">✨ Carta vitrina</button><button v-if="puede('gastronomia','editar') && ia" class="btn-secondary" @click="traducir" data-e2e="traducir">🌐 Traducir carta</button><button v-if="puede('gastronomia','editar')" class="btn-secondary" :disabled="mdCargando" @click="menuDelDia" data-e2e="menu-del-dia">{{ mdCargando ? 'Pensando…' : '✦ Menú del día' }}</button><label v-if="puede('gastronomia','editar') && ia" class="btn-primary cursor-pointer" data-e2e="carta-foto">{{ leyendo ? 'Leyendo…' : '📷 Cargar carta desde foto' }}<input type="file" accept="image/*,application/pdf" class="hidden" @change="leerCarta" /></label><input v-model="q" class="input w-60" placeholder="Buscar plato…" /></div>
    </div>
    <div class="card mb-4" data-e2e="config-estaciones">
      <div class="flex flex-wrap items-center gap-2"><p class="font-bold text-sm mr-2">Estaciones</p>
        <span v-for="(e, i) in est" :key="i" class="flex items-center gap-1 rounded-full border border-marca-borde pl-2 pr-1 py-0.5 text-xs"><input v-model="e.nombre" class="w-20 bg-transparent font-semibold outline-none" /> avisa a los <input v-model.number="e.alerta" type="number" min="1" class="w-10 bg-transparent text-right outline-none" />' <button v-if="est.length > 1" class="text-marca-muted hover:text-carmin px-1" @click="est.splice(i, 1)">×</button></span>
        <button class="btn-ghost !px-2 text-xs" @click="est.push({ nombre: '', alerta: 15 })">+ Estación</button>
        <button v-if="puede('gastronomia','editar')" class="btn-secondary !py-1 text-xs ml-auto" @click="router.post('/gastronomia/estaciones', { estaciones: est.filter(e => e.nombre.trim()), qr_confirma: qrc, receta_al_vender: rav, whatsapp_a_resto: war }, { preserveScroll: true })">Guardar estaciones</button></div>
      <label class="flex items-center gap-2 text-xs mt-2"><input v-model="rav" type="checkbox" class="accent-carmin" /> <b>Cada venta descuenta los insumos de la receta del plato</b> (café, leche, vaso…). El costo del plato sale de la receta.</label>
      <label class="flex items-center gap-2 text-xs mt-2"><input v-model="war" type="checkbox" class="accent-carmin" /> Los pedidos por <b>WhatsApp</b> entran directo como comanda de Big Restó (delivery o para retirar), con las opciones de cada plato</label>
      <label class="flex items-center gap-2 text-xs mt-2"><input v-model="qrc" type="checkbox" class="accent-carmin" /> Los pedidos que hacen desde el QR de la mesa los confirma el mozo antes de ir a cocina</label>
      <p class="text-xs text-marca-muted mt-1">Cada estación tiene su pantalla (Cocina → elegís la estación) y su comanda impresa. Si un plato pasa los minutos que pusiste, se marca en rojo.</p>
    </div>
    <div class="card mb-4" data-e2e="happy-hours">
      <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-bold text-sm">Happy hour y precios por horario</p>
        <div class="flex gap-2"><button class="btn-ghost !px-2 text-xs" @click="hhs.push({ nombre: 'Happy hour', activo: true, pct: 20, desde: '18:00', hasta: '20:00', dias: [1, 2, 3, 4, 5], rubros: [] })">+ Franja</button>
          <button v-if="puede('gastronomia','editar')" class="btn-secondary !py-1 text-xs" @click="router.post('/gastronomia/happy-hours', { happy_hours: hhs }, { preserveScroll: true })" data-e2e="hh-guardar">Guardar</button></div></div>
      <div v-for="(h, i) in hhs" :key="i" class="flex flex-wrap items-center gap-2 mt-2 text-xs border-t border-marca-borde/60 pt-2">
        <input v-model="h.activo" type="checkbox" class="accent-carmin" /><input v-model="h.nombre" class="input !py-0.5 !text-xs !w-32" />
        <input v-model.number="h.pct" type="number" min="1" max="90" class="input !py-0.5 !text-xs !w-14 text-right" />% off de
        <input v-model="h.desde" type="time" class="input !py-0.5 !text-xs !w-auto" /> a <input v-model="h.hasta" type="time" class="input !py-0.5 !text-xs !w-auto" />
        <span class="flex gap-0.5"><button v-for="(d, k) in dias" :key="k" type="button" class="w-6 h-6 rounded-full border text-[11px] font-bold" :class="h.dias.includes(k + 1) ? 'bg-carmin text-white border-carmin' : 'border-marca-borde'" @click="h.dias = h.dias.includes(k + 1) ? h.dias.filter(x => x !== k + 1) : [...h.dias, k + 1]">{{ d }}</button></span>
        <select multiple v-model="h.rubros" class="input !py-0 !text-xs !w-40 !h-14" title="Vacío = toda la carta"><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.nombre }}</option></select>
        <button class="text-marca-muted hover:text-carmin" @click="hhs.splice(i, 1)">Quitar</button>
      </div>
      <p class="text-xs text-marca-muted mt-1">En esa franja, los platos de esos rubros (sin rubros elegidos, toda la carta) salen con el descuento, en el salón y en el QR. Una franja puede cruzar la medianoche.</p>
    </div>
    <div class="card p-0">
      <div v-for="p in visibles" :key="p.id" class="flex flex-wrap items-center gap-3 px-4 py-2.5 border-b border-marca-borde/60 text-sm">
        <img v-if="p.foto" :src="p.foto" alt="" class="w-10 h-10 object-cover shrink-0" :class="p.foto_gira ? 'rounded-full' : 'rounded-lg'" />
        <div class="flex-1 min-w-[180px]"><p class="font-semibold">{{ p.name }}<span v-if="p.alergenos?.length" class="ml-1 text-[10px] font-normal text-amber-700" :title="p.alergenos.map(a => alergenosLista[a]).join(', ')">· {{ p.alergenos.length }} alérgeno{{ p.alergenos.length > 1 ? 's' : '' }}</span></p><p class="text-xs text-marca-muted">{{ p.rubro ?? 'Sin rubro' }} · {{ moneda(p.price, 0) }}</p>
          <p class="text-xs" :data-margen="p.id"><span class="text-marca-muted">costo {{ moneda(p.costo, 0) }}{{ p.receta ? ' (receta)' : '' }} · margen </span><b :class="p.margen === null ? 'text-marca-muted' : p.margen < (p.margen_objetivo ?? 30) ? 'text-carmin' : 'text-emerald-700'">{{ p.margen === null ? '—' : p.margen + ' %' }}</b><span v-if="p.margen_objetivo !== null" class="text-marca-muted"> / objetivo {{ p.margen_objetivo }} %</span></p></div>
        <button class="btn-ghost !py-1 text-xs" @click="verHistoria(p)" :data-historia="p.id" title="Cómo fue cambiando el costo, el precio y el margen mes a mes">Historia</button>
        <button v-if="puede('gastronomia','editar')" class="btn-ghost !py-1 text-xs" @click="editarReceta(p)" :data-receta="p.id">{{ p.receta ? 'Receta' : '+ Receta' }}</button>
        <div class="flex flex-wrap gap-1 max-w-md"><span v-if="p.horario" class="badge bg-amber-50 text-amber-700">{{ p.horario.desde }}–{{ p.horario.hasta }}</span><span v-for="g in p.modificadores" :key="g.nombre" class="badge bg-lavanda-light text-violeta">{{ g.nombre }}{{ g.obligatorio ? ' *' : '' }} · {{ g.opciones.length }}</span></div>
        <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" class="accent-carmin" :checked="p.va_cocina" :disabled="!puede('gastronomia','editar')" @change="router.post(`/gastronomia/carta/${p.id}`, { va_cocina: $event.target.checked, modificadores: p.modificadores }, { preserveScroll: true })" /> Se prepara</label>
        <select v-if="p.va_cocina" class="input !py-0.5 !text-xs !w-28" :value="p.estacion ?? estaciones[0].nombre" :disabled="!puede('gastronomia','editar')" @change="router.post(`/gastronomia/carta/${p.id}`, { estacion: $event.target.value, modificadores: p.modificadores }, { preserveScroll: true })" :data-estacion="p.id"><option v-for="e in estaciones" :key="e.nombre" :value="e.nombre">{{ e.nombre }}</option></select>
        <!-- Fase 95: "se acabó": sale de la carta QR y del salón al instante; vuelve solo mañana o con este mismo botón -->
        <button v-if="puede('gastronomia','editar')" class="!py-1 text-xs" :class="p.agotado ? 'btn-primary !bg-none !bg-carmin' : 'btn-ghost text-marca-muted'" :title="p.agotado ? 'Agotado por hoy: tocá para volver a ofrecerlo' : 'Marcar agotado por hoy'" @click="router.post(`/gastronomia/carta/${p.id}/agotado`, { agotado: !p.agotado }, { preserveScroll: true })" :data-agotado="p.id">{{ p.agotado ? 'Agotado hoy' : 'Se acabó' }}</button>
        <button v-if="puede('gastronomia','editar')" class="btn-secondary !py-1 text-xs" @click="editar(p)" :data-opciones="p.id">Opciones y foto</button>
      </div>
      <p v-if="!visibles.length" class="text-center text-marca-muted text-sm py-10">No hay platos con ese nombre.</p>
    </div>

    <Modal :abierto="!!ed" :titulo="ed ? `Opciones de ${ed.name}` : ''" ancho="max-w-2xl" @cerrar="ed = null">
      <div v-if="ed" class="space-y-3">
        <div v-for="(g, gi) in ed.grupos" :key="gi" class="rounded-xl border border-marca-borde p-3">
          <div class="flex flex-wrap items-center gap-2">
            <input v-model="g.nombre" class="input !py-1 text-sm flex-1 min-w-[140px]" placeholder="Grupo: Punto, Guarnición, Extras…" />
            <label class="flex items-center gap-1 text-xs"><input v-model="g.obligatorio" type="checkbox" class="accent-carmin" /> Obligatorio</label>
            <label class="flex items-center gap-1 text-xs"><input v-model="g.multiple" type="checkbox" class="accent-carmin" /> Puede elegir varios</label>
            <button class="text-marca-muted hover:text-carmin text-xs" @click="ed.grupos.splice(gi, 1)">Quitar grupo</button>
          </div>
          <div v-for="(o, oi) in g.opciones" :key="oi" class="grid grid-cols-[1fr_110px_24px] gap-2 mt-2">
            <input v-model="o.nombre" class="input !py-1 text-sm" placeholder="Jugoso, Papas fritas, + Queso…" />
            <input v-model.number="o.precio" type="number" min="0" step="any" class="input !py-1 text-sm text-right" placeholder="$ extra" />
            <button class="text-marca-muted hover:text-carmin" @click="g.opciones.splice(oi, 1)">×</button>
          </div>
          <button class="btn-ghost !px-2 text-xs mt-1" @click="g.opciones.push({ nombre: '', precio: 0 })">+ Opción</button>
        </div>
        <div class="flex flex-wrap gap-2"><button class="btn-secondary text-xs" @click="ed.grupos.push({ nombre: '', obligatorio: false, multiple: false, opciones: [{ nombre: '', precio: 0 }] })">+ Grupo de opciones</button>
          <button v-for="m in modelos" :key="m.nombre" class="btn-ghost text-xs" @click="ed.grupos.push(JSON.parse(JSON.stringify(m)))">+ {{ m.nombre }}</button></div>
        <p class="text-xs text-marca-muted">Obligatorio: el mozo no puede cargar el plato sin elegir (por ejemplo, el punto). El precio extra se suma al plato. Para un <b>menú o combo</b> (entrada + principal + postre), armá un grupo obligatorio por paso.</p>
        <div class="rounded-xl border border-marca-borde p-3 text-sm">
          <label class="flex items-center gap-2 font-semibold"><input v-model="ed.conHorario" type="checkbox" class="accent-carmin" /> Sólo se sirve en un horario (menú del día, ejecutivo al mediodía)</label>
          <div v-if="ed.conHorario" class="flex flex-wrap items-center gap-2 mt-2 text-xs"><input v-model="ed.horario.desde" type="time" class="input !py-0.5 !text-xs !w-auto" /> a <input v-model="ed.horario.hasta" type="time" class="input !py-0.5 !text-xs !w-auto" />
            <span class="flex gap-0.5"><button v-for="(d, k) in dias" :key="k" type="button" class="w-6 h-6 rounded-full border text-[11px] font-bold" :class="ed.horario.dias.includes(k + 1) ? 'bg-carmin text-white border-carmin' : 'border-marca-borde'" @click="ed.horario.dias = ed.horario.dias.includes(k + 1) ? ed.horario.dias.filter(x => x !== k + 1) : [...ed.horario.dias, k + 1]">{{ d }}</button></span></div>
          <p class="text-xs text-marca-muted mt-1">Fuera de ese horario no aparece ni para el mozo ni en el QR.</p>
        </div>
        <div class="rounded-xl border border-marca-borde p-3 text-sm space-y-2" data-e2e="carta-vitrina-plato">
          <p class="font-semibold">En la carta del QR</p>
          <div class="flex flex-wrap items-center gap-3">
            <img v-if="ed.foto" :src="ed.foto" alt="" class="w-20 h-20 object-cover" :class="ed.foto_gira ? 'rounded-full' : 'rounded-xl'" />
            <div class="space-y-1">
              <label class="btn-secondary !py-1 text-xs cursor-pointer">{{ subiendo ? 'Subiendo…' : (ed.foto ? 'Cambiar la foto' : '📷 Subir foto') }}<input type="file" accept="image/*" class="hidden" @change="subirFoto" data-e2e="foto-plato" /></label>
              <button v-if="ed.foto_propia" type="button" class="btn-ghost !py-1 text-xs text-marca-muted" @click="quitarFoto">Quitar</button>
              <p v-if="errorFoto" class="text-xs text-carmin">{{ errorFoto }}</p>
            </div>
          </div>
          <label class="flex items-center gap-2 text-xs"><input v-model="ed.foto_gira" type="checkbox" class="accent-carmin" /> La foto es el plato visto desde arriba (en la vitrina se ve redonda y gira despacio)</label>
          <div>
            <div class="flex items-center justify-between"><span class="label !mb-0">Ingredientes</span><button v-if="ed.receta?.length" type="button" class="btn-ghost !py-0 text-xs" @click="ed.ingredientes = ed.receta.join(', ')">Traer de la receta</button></div>
            <textarea v-model="ed.ingredientes" rows="2" maxlength="500" class="input text-sm" placeholder="Tomate San Marzano, mozzarella fior di latte, albahaca, aceite de oliva" />
          </div>
          <div>
            <span class="label">Alérgenos</span>
            <div class="flex flex-wrap gap-1"><button v-for="(n, k) in alergenosLista" :key="k" type="button" class="px-2 py-0.5 rounded-full border text-xs" :class="ed.alergenos.includes(k) ? 'bg-amber-100 border-amber-400 text-amber-800 font-semibold' : 'border-marca-borde text-marca-muted'" @click="ed.alergenos = ed.alergenos.includes(k) ? ed.alergenos.filter(x => x !== k) : [...ed.alergenos, k]" :data-alergeno="k">{{ n }}</button></div>
            <p class="text-xs text-marca-muted mt-1">Los que lleva el plato, según el Código Alimentario. Lo marca el local: el sistema no los deduce.</p>
          </div>
        </div>
      </div>
      <template #pie><button class="btn-secondary" @click="ed = null">Cancelar</button><button class="btn-primary" @click="guardar" data-e2e="carta-guardar">Guardar</button></template>
    </Modal>

    <!-- Fase 55: menú del día con lo que está por vencer -->
    <Modal :abierto="!!md" titulo="Menú del día sugerido" ancho="max-w-2xl" @cerrar="md = null">
      <div v-if="md" class="space-y-3 text-sm">
        <p v-if="md.aviso" class="text-xs text-amber-700">{{ md.aviso }}</p>
        <div v-if="md.usar.length"><p class="label">Conviene usar primero</p><div class="flex flex-wrap gap-1.5"><span v-for="u in md.usar" :key="u.id" class="badge bg-amber-50 text-amber-800" :title="u.platos?.length ? 'Lo usan: ' + u.platos.join(', ') : ''">{{ u.insumo }} · {{ u.cantidad }} {{ u.unit }} · {{ u.motivo }}</span></div></div>
        <div v-for="(m, i) in md.menus" :key="i" class="rounded-xl border border-marca-borde p-3">
          <div class="flex items-start justify-between gap-2"><div><p class="font-bold">{{ m.nombre }} <span v-if="m.precio_sugerido" class="text-marca-muted font-normal">· {{ moneda(m.precio_sugerido, 0) }}</span></p><p>{{ m.platos.join(' + ') }}</p><p class="text-xs text-marca-muted">Usa: {{ m.usa.join(', ') }}</p></div>
            <button class="btn-secondary !py-1 text-xs whitespace-nowrap" @click="copiar(m)">Copiar para la pizarra</button></div>
          <p v-if="m.pizarra" class="mt-2 text-xs italic bg-marca-fondo rounded-lg px-3 py-2">“{{ m.pizarra }}”</p>
        </div>
        <p class="text-xs text-marca-muted">Para venderlo, creá el plato en la carta con «Sólo se sirve en un horario» (mediodía) y su receta.</p>
      </div>
      <template #pie><button class="btn-secondary" @click="md = null">Cerrar</button></template>
    </Modal>

    <!-- Fase 53.2: vista previa de la carta leída -->
    <Modal :abierto="!!lc" titulo="Tu carta, leída por Bigcito" ancho="max-w-3xl" @cerrar="lc = null">
      <div v-if="lc" class="space-y-3 text-sm">
        <p v-if="lc.error" class="text-carmin">{{ lc.error }}</p>
        <p v-else class="text-xs text-marca-muted">Revisá nombres y precios antes de cargar. Lo que ya existe en tu carta aparece marcado y sólo se le actualiza el precio. Destildá lo que no quieras cargar.</p>
        <div v-for="(r, ri) in lc.rubros" :key="ri" class="rounded-xl border border-marca-borde p-3">
          <input v-model="r.nombre" class="font-bold bg-transparent outline-none mb-1" />
          <div v-for="(p, pi) in r.platos" :key="pi" class="grid grid-cols-[20px_1fr_110px_auto] gap-2 items-center py-1 border-t border-marca-borde/60">
            <input v-model="p.cargar" type="checkbox" class="accent-carmin" />
            <div><input v-model="p.nombre" class="w-full bg-transparent outline-none" /><p v-if="p.opciones?.length" class="text-[11px] text-violeta">{{ p.opciones.map(g => g.grupo + ': ' + g.opciones.map(o => o.nombre).join('/')).join(' · ') }}</p></div>
            <input v-model.number="p.precio" type="number" min="0" class="input !py-0.5 text-right" placeholder="precio" />
            <span class="text-[11px]" :class="p.existe_id ? 'text-amber-700' : 'text-emerald-700'">{{ p.existe_id ? 'ya existe' : 'nuevo' }}</span>
          </div>
        </div>
      </div>
      <template #pie><button class="btn-secondary" @click="lc = null">Cancelar</button><button v-if="lc && !lc.error" class="btn-primary" :disabled="!aCargar" @click="router.post('/gastronomia/carta/importar', { rubros: lc.rubros }, { preserveScroll: true, onSuccess: () => (lc = null) })" data-e2e="carta-importar">Cargar {{ aCargar }} platos</button></template>
    </Modal>

    <!-- Fase 114: historia del plato: costo de la receta, precio y margen mes a mes -->
    <Modal :abierto="!!hp" :titulo="hp ? `Historia de ${hp.nombre}` : ''" ancho="max-w-3xl" @cerrar="hp = null">
      <div v-if="hp" class="space-y-3 text-sm" data-e2e="historia-plato">
        <p v-if="hp.cargando" class="text-marca-muted">Reconstruyendo los últimos meses…</p>
        <template v-else-if="hp.datos">
          <p class="text-xs text-marca-muted">{{ hp.datos.resumen }}</p>
          <div class="grid grid-cols-3 gap-2 text-center rounded-xl bg-marca-fondo p-3">
            <div><p class="text-[11px] uppercase text-marca-muted font-bold">Costo hoy</p><p class="font-extrabold tabular-nums">{{ moneda(hp.datos.hoy.costo, 0) }}</p><p v-if="hp.datos.variacion.costo_pct !== null" class="text-[11px]" :class="hp.datos.variacion.costo_pct > 0 ? 'text-carmin' : 'text-emerald-700'">{{ hp.datos.variacion.costo_pct > 0 ? '+' : '' }}{{ hp.datos.variacion.costo_pct }} % en el período</p></div>
            <div><p class="text-[11px] uppercase text-marca-muted font-bold">Precio neto</p><p class="font-extrabold tabular-nums">{{ moneda(hp.datos.hoy.precio, 0) }}</p><p v-if="hp.datos.variacion.precio_pct !== null" class="text-[11px] text-marca-muted">{{ hp.datos.variacion.precio_pct > 0 ? '+' : '' }}{{ hp.datos.variacion.precio_pct }} %</p></div>
            <div><p class="text-[11px] uppercase text-marca-muted font-bold">Margen</p><p class="font-extrabold tabular-nums" :class="hp.datos.margen_objetivo !== null && hp.datos.hoy.margen < hp.datos.margen_objetivo ? 'text-carmin' : ''">{{ hp.datos.hoy.margen ?? '—' }} %</p><p v-if="hp.datos.margen_objetivo !== null" class="text-[11px] text-marca-muted">objetivo {{ hp.datos.margen_objetivo }} %</p></div>
          </div>
          <div class="overflow-x-auto"><table class="table text-xs">
            <thead><tr><th>Mes</th><th class="text-right">Costo</th><th class="text-right">Precio</th><th class="text-right">Margen</th><th>Qué cambió</th></tr></thead>
            <tbody><tr v-for="m in hp.datos.meses" :key="m.mes">
              <td class="whitespace-nowrap">{{ m.etiqueta }}</td>
              <td class="text-right tabular-nums"><div class="flex items-center justify-end gap-2"><div class="h-2 rounded bg-carmin/70" :style="{ width: barra(m.costo, hp.datos.meses) }"></div>{{ moneda(m.costo, 0) }}</div></td>
              <td class="text-right tabular-nums">{{ moneda(m.precio, 0) }}</td>
              <td class="text-right tabular-nums font-semibold" :class="hp.datos.margen_objetivo !== null && m.margen !== null && m.margen < hp.datos.margen_objetivo ? 'text-carmin' : (m.margen !== null && m.margen < 30 ? 'text-amber-700' : 'text-emerald-700')">{{ m.margen ?? '—' }} %</td>
              <td class="text-marca-muted"><span v-if="m.cambio_precio" class="mr-2">Precio {{ moneda(m.cambio_precio.de, 0) }} → {{ moneda(m.cambio_precio.a, 0) }}.</span><span v-for="c in m.cambios.slice(0, 3)" :key="c.que" class="mr-2">{{ c.que }} {{ moneda(c.de, 0) }} → {{ moneda(c.a, 0) }} ({{ c.impacto > 0 ? '+' : '' }}{{ moneda(c.impacto, 0) }} por porción)</span><span v-if="!m.cambio_precio && !m.cambios.length">—</span></td>
            </tr></tbody>
          </table></div>
          <p v-if="!hp.datos.con_receta" class="text-xs text-amber-700">Este plato no tiene receta: la historia es del costo cargado a mano. Con receta, cada compra que cambia un insumo queda explicada acá.</p>
        </template>
        <p v-else class="text-carmin text-xs">No pude traer la historia. Probá de nuevo.</p>
      </div>
      <template #pie><button class="btn-secondary" @click="hp = null">Cerrar</button></template>
    </Modal>

    <!-- Fase 53: la receta del plato, con sugerencia de la IA -->
    <Modal :abierto="!!rc" :titulo="rc ? `Receta de ${rc.name}` : ''" ancho="max-w-2xl" @cerrar="rc = null">
      <div v-if="rc" class="space-y-3 text-sm">
        <p class="text-xs text-marca-muted">Lo que lleva <b>una porción</b>. Con «Cada venta descuenta la receta» activado, cada plato vendido baja estos insumos del stock.</p>
        <div v-for="(it, i) in rc.items" :key="i" class="grid grid-cols-[1fr_90px_60px_80px_24px] gap-2 items-center">
          <select v-model="it.product_id" class="input !py-1 text-sm"><option :value="null">Insumo…</option><option v-for="x in insumos" :key="x.id" :value="x.id">{{ x.nombre }} ({{ x.unit }})</option></select>
          <input v-model.number="it.quantity" type="number" min="0" step="any" class="input !py-1 text-sm text-right" />
          <span class="text-xs text-marca-muted">{{ insumos.find(x => x.id === it.product_id)?.unit ?? '' }}</span>
          <span class="text-xs text-right tabular-nums">{{ moneda((insumos.find(x => x.id === it.product_id)?.cost ?? 0) * (it.quantity || 0), 0) }}</span>
          <button class="text-marca-muted hover:text-carmin" @click="rc.items.splice(i, 1)">×</button>
        </div>
        <div class="flex flex-wrap items-center gap-2"><button class="btn-ghost !px-2 text-xs" @click="rc.items.push({ product_id: null, quantity: 1 })">+ Insumo</button>
          <button class="btn-secondary !py-1 text-xs" :disabled="rc.pensando" @click="sugerirReceta" data-e2e="receta-ia">{{ rc.pensando ? 'Pensando…' : '✦ Sugerir con IA' }}</button>
          <button class="btn-ghost !py-1 text-xs" @click="verHistoria(rc)">Historia del plato</button>
          <span v-if="rc.nota" class="text-xs text-amber-700">{{ rc.nota }}</span><span v-if="rc.error" class="text-xs text-carmin">{{ rc.error }}</span></div>
        <div class="rounded-xl bg-marca-fondo p-3 grid grid-cols-3 gap-2 text-center"><div><p class="text-[11px] uppercase text-marca-muted font-bold">Costo</p><p class="font-extrabold tabular-nums">{{ moneda(costoReceta, 0) }}</p></div><div><p class="text-[11px] uppercase text-marca-muted font-bold">Precio neto</p><p class="font-extrabold tabular-nums">{{ moneda(rc.precio_neto, 0) }}</p></div><div><p class="text-[11px] uppercase text-marca-muted font-bold">Margen</p><p class="font-extrabold tabular-nums" :class="margenReceta !== null && margenReceta < (rc.margen_objetivo ?? 30) ? 'text-carmin' : 'text-emerald-700'">{{ margenReceta === null ? '—' : margenReceta + ' %' }}</p></div></div>
        <label class="flex items-center gap-2 text-xs">Margen objetivo <input v-model.number="rc.margen_objetivo" type="number" min="0" max="99" class="input !py-0.5 !w-16 text-right" /> % · si una compra lo deja por debajo, te avisa con el precio sugerido.</label>
      </div>
      <template #pie><button class="btn-secondary" @click="rc = null">Cancelar</button><button class="btn-primary" @click="guardarReceta" data-e2e="receta-guardar">Guardar receta</button></template>
    </Modal>
    <!-- Fase 127: la carta vitrina -->
    <Modal :abierto="!!vt" titulo="Carta vitrina" ancho="max-w-2xl" @cerrar="vt = null">
      <div v-if="vt" class="space-y-3 text-sm">
        <p class="text-marca-muted">Cómo se ve la carta cuando el cliente escanea el QR. En <b>vitrina</b>, arriba aparece tu local (la foto, la historia y el horario) y después un plato por pantalla, con la foto grande, los ingredientes, los alérgenos, los tamaños y el precio. Se pasa de un plato al otro con el dedo. El pedido desde la mesa funciona igual en las dos.</p>
        <div class="flex gap-2"><label v-for="o in [['lista', 'Lista (la de siempre)'], ['vitrina', 'Vitrina']]" :key="o[0]" class="flex-1 rounded-xl border p-2 cursor-pointer" :class="vt.menu_estilo === o[0] ? 'border-carmin bg-carmin-light/40 font-semibold' : 'border-marca-borde'"><input v-model="vt.menu_estilo" type="radio" :value="o[0]" class="accent-carmin mr-1" :data-estilo="o[0]" />{{ o[1] }}</label></div>
        <template v-if="vt.menu_estilo === 'vitrina'">
          <div class="flex flex-wrap items-center gap-3">
            <img v-if="vitrina.portada" :src="vitrina.portada" alt="" class="w-28 h-20 object-cover rounded-xl" />
            <label class="btn-secondary !py-1 text-xs cursor-pointer">{{ subiendo ? 'Subiendo…' : (vitrina.portada ? 'Cambiar la foto del local' : '📷 Foto del local (el horno, el salón)') }}<input type="file" accept="image/*" class="hidden" @change="subirPortada" /></label>
            <button v-if="vitrina.portada" type="button" class="btn-ghost !py-1 text-xs text-marca-muted" @click="router.delete('/gastronomia/carta/portada', { preserveScroll: true })">Quitar</button>
            <p v-if="errorFoto" class="text-xs text-carmin w-full">{{ errorFoto }}</p>
          </div>
          <div><label class="label">Título de la historia</label><input v-model="vt.menu_historia_titulo" maxlength="80" class="input" placeholder="Leña de espinillo. Noventa segundos." /></div>
          <div><label class="label">La historia del local</label><textarea v-model="vt.menu_historia" rows="4" maxlength="1200" class="input" placeholder="Qué hacen distinto: la masa, el horno, de dónde viene la receta. Dos o tres líneas alcanzan." /></div>
          <div><label class="label">Horario que se muestra</label><input v-model="vt.menu_horario" maxlength="160" class="input" placeholder="Martes a domingo · 12 a 16 y 20 a 24" /></div>
          <p class="text-xs text-marca-muted">Los platos sin foto se muestran igual, con su nombre en grande. Las fotos se suben en «Opciones y foto» de cada plato.</p>
        </template>
        <p class="text-xs">Tu carta: <a :href="vitrina.url" target="_blank" class="text-violeta underline">{{ vitrina.url }}</a><span v-if="!vitrina.activa" class="text-carmin"> · la carta del QR está apagada: se prende en Big Restó → Reservas y QR.</span></p>
      </div>
      <template #pie><button class="btn-secondary" @click="vt = null">Cancelar</button><button class="btn-primary" @click="router.post('/gastronomia/carta/vitrina', vt, { preserveScroll: true, onSuccess: () => (vt = null) })" data-e2e="vitrina-guardar">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ vitrina: { type: Object, default: () => ({ estilo: 'lista', url: '' }) }, alergenosLista: { type: Object, default: () => ({}) }, productos: Array, estaciones: { type: Array, default: () => [{ nombre: 'Cocina', alerta: 20 }] }, qrConfirma: Boolean, recetaAlVender: Boolean, whatsappAResto: Boolean, ia: Boolean, insumos: { type: Array, default: () => [] }, happyHours: { type: Array, default: () => [] }, rubros: { type: Array, default: () => [] } })
const est = ref(JSON.parse(JSON.stringify(props.estaciones)))
const qrc = ref(props.qrConfirma), rav = ref(props.recetaAlVender), war = ref(props.whatsappAResto)
// Fase 56: carta traducida para el QR
function traducir() { const idioma = window.prompt('¿A qué idioma? en = inglés, pt = portugués', 'en'); if (idioma && ['en', 'pt'].includes(idioma.trim())) router.post('/gastronomia/carta/traducir', { idioma: idioma.trim() }, { preserveScroll: true }) }
// Fase 55: menú del día sugerido
const md = ref(null), mdCargando = ref(false)
async function menuDelDia() { mdCargando.value = true; try { const { data } = await window.axios.post('/gastronomia/carta/menu-del-dia'); md.value = data } catch (e) { md.value = { usar: [], menus: [], aviso: 'No pude consultar.' } } finally { mdCargando.value = false } }
function copiar(m) { const t = `${m.nombre}${m.precio_sugerido ? ' · $ ' + Math.round(m.precio_sugerido) : ''}\n${m.platos.join(' + ')}\n${m.pizarra ?? ''}`; try { navigator.clipboard.writeText(t) } catch (e) {} }
// Fase 53.2: la carta desde una foto
const leyendo = ref(false), lc = ref(null)
async function leerCarta(ev) {
  const file = ev.target.files?.[0]; ev.target.value = ''; if (!file) return
  leyendo.value = true
  try { const fd = new FormData(); fd.append('archivo', file); const { data } = await window.axios.post('/gastronomia/carta/leer', fd); if (!data.ok) { lc.value = { error: data.aviso, rubros: [] }; return } lc.value = { rubros: data.rubros, error: '' } } catch (e) { lc.value = { error: 'No pude leer el archivo.', rubros: [] } } finally { leyendo.value = false }
}
const aCargar = computed(() => (lc.value?.rubros ?? []).reduce((a, r) => a + r.platos.filter(p => p.cargar).length, 0))
const { puede } = usePermisos()
const q = ref('')
const norm = s => (s ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
const visibles = computed(() => props.productos.filter(p => !q.value || norm(p.name + ' ' + (p.rubro ?? '')).includes(norm(q.value))))
const modelos = [
  { nombre: 'Punto', obligatorio: true, multiple: false, opciones: [{ nombre: 'Jugoso', precio: 0 }, { nombre: 'A punto', precio: 0 }, { nombre: 'Cocido', precio: 0 }] },
  { nombre: 'Guarnición', obligatorio: true, multiple: false, opciones: [{ nombre: 'Papas fritas', precio: 0 }, { nombre: 'Puré', precio: 0 }, { nombre: 'Ensalada', precio: 0 }] },
  { nombre: 'Extras', obligatorio: false, multiple: true, opciones: [{ nombre: '+ Queso', precio: 0 }, { nombre: '+ Huevo', precio: 0 }] },
  { nombre: 'Sin', obligatorio: false, multiple: true, opciones: [{ nombre: 'Sin sal', precio: 0 }, { nombre: 'Sin cebolla', precio: 0 }, { nombre: 'Sin TACC', precio: 0 }] },
]
const ed = ref(null), rc = ref(null)
// Fase 114: historia del plato (costo de la receta, precio y margen mes a mes).
const hp = ref(null)
async function verHistoria(p) {
  hp.value = { nombre: p.name, cargando: true, datos: null }
  try { const { data } = await window.axios.get(`/gastronomia/carta/${p.id}/historia`); hp.value = { nombre: p.name, cargando: false, datos: data } }
  catch { hp.value = { nombre: p.name, cargando: false, datos: null } }
}
const barra = (v, meses) => { const max = Math.max(...meses.map(m => m.costo), 1); return Math.max(4, Math.round(v / max * 90)) + 'px' }
function editarReceta(p) { rc.value = { id: p.id, name: p.name, precio_neto: p.precio_neto, margen_objetivo: p.margen_objetivo, items: JSON.parse(JSON.stringify(p.receta?.items ?? [])), pensando: false, nota: '', error: '' } }
const costoReceta = computed(() => (rc.value?.items ?? []).reduce((a, it) => a + (props.insumos.find(x => x.id === it.product_id)?.cost ?? 0) * (Number(it.quantity) || 0), 0))
const margenReceta = computed(() => rc.value?.precio_neto > 0 ? Math.round((rc.value.precio_neto - costoReceta.value) / rc.value.precio_neto * 1000) / 10 : null)
async function sugerirReceta() {
  rc.value.pensando = true; rc.value.error = ''; rc.value.nota = ''
  try { const { data } = await window.axios.post(`/gastronomia/carta/${rc.value.id}/sugerir-receta`); if (!data.ok) { rc.value.error = data.aviso; return } rc.value.items = data.items.map(i => ({ product_id: i.product_id, quantity: i.quantity })); rc.value.nota = data.nota ?? '' } catch (e) { rc.value.error = 'No pude consultar a la IA.' } finally { rc.value.pensando = false }
}
function guardarReceta() {
  const items = rc.value.items.filter(i => i.product_id && Number(i.quantity) > 0)
  router.post(`/gastronomia/carta/${rc.value.id}/receta`, { items }, { preserveScroll: true, onSuccess: () => { if (rc.value.margen_objetivo !== null && rc.value.margen_objetivo !== '') router.post(`/gastronomia/carta/${rc.value.id}`, { margen_objetivo: rc.value.margen_objetivo, modificadores: props.productos.find(p => p.id === rc.value.id)?.modificadores ?? [] }, { preserveScroll: true }); rc.value = null } })
}
function editar(p) { errorFoto.value = ''; ed.value = { foto: p.foto, foto_propia: p.foto_propia, foto_gira: p.foto_gira, ingredientes: p.ingredientes ?? '', alergenos: [...(p.alergenos ?? [])], receta: (p.receta?.items ?? []).map(i => i.nombre).filter(Boolean), id: p.id, name: p.name, va_cocina: p.va_cocina, grupos: JSON.parse(JSON.stringify(p.modificadores ?? [])), conHorario: !!p.horario, horario: p.horario ? { ...p.horario, dias: [...(p.horario.dias ?? [])] } : { desde: '12:00', hasta: '16:00', dias: [1, 2, 3, 4, 5] } } }
const dias = ['L', 'M', 'M', 'J', 'V', 'S', 'D']
const hhs = ref(JSON.parse(JSON.stringify(props.happyHours ?? [])))
function guardar() {
  const grupos = ed.value.grupos.map(g => ({ ...g, opciones: g.opciones.filter(o => o.nombre.trim()) })).filter(g => g.nombre.trim() && g.opciones.length)
  router.post(`/gastronomia/carta/${ed.value.id}`, { va_cocina: ed.value.va_cocina, modificadores: grupos, horario: ed.value.conHorario ? ed.value.horario : {}, ingredientes: ed.value.ingredientes, alergenos: ed.value.alergenos, foto_gira: !!ed.value.foto_gira }, { preserveScroll: true, onSuccess: () => (ed.value = null) })
}
// Fase 127: la carta vitrina y las fotos de los platos.
const vt = ref(null), subiendo = ref(false), errorFoto = ref('')
function abrirVitrina() { errorFoto.value = ''; vt.value = { menu_estilo: props.vitrina.estilo ?? 'lista', menu_historia_titulo: props.vitrina.titulo ?? '', menu_historia: props.vitrina.historia ?? '', menu_horario: props.vitrina.horario ?? '' } }
const errorDe = e => Object.values(e ?? {})[0] ?? 'No se pudo subir la foto.'
function subirFoto(ev) {
  const f = ev.target.files?.[0]; ev.target.value = ''; if (!f || !ed.value) return
  subiendo.value = true; errorFoto.value = ''; const id = ed.value.id
  router.post(`/gastronomia/carta/${id}/foto`, { foto: f }, { forceFormData: true, preserveScroll: true,
    onSuccess: () => { const p = props.productos.find(x => x.id === id); if (ed.value && p) { ed.value.foto = p.foto; ed.value.foto_propia = p.foto_propia } },
    onError: e => (errorFoto.value = errorDe(e)), onFinish: () => (subiendo.value = false) })
}
function quitarFoto() { const id = ed.value.id; router.delete(`/gastronomia/carta/${id}/foto`, { preserveScroll: true, onSuccess: () => { if (ed.value) { ed.value.foto = null; ed.value.foto_propia = false; ed.value.foto_gira = false } } }) }
function subirPortada(ev) {
  const f = ev.target.files?.[0]; ev.target.value = ''; if (!f) return
  subiendo.value = true; errorFoto.value = ''
  router.post('/gastronomia/carta/portada', { foto: f }, { forceFormData: true, preserveScroll: true, onError: e => (errorFoto.value = errorDe(e)), onFinish: () => (subiendo.value = false) })
}
</script>
