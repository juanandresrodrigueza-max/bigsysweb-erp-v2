<template>
  <div>
    <!-- Estado de la conexión y del paquete: siempre a la vista -->
    <div class="flex flex-wrap items-center gap-2 text-xs mb-3">
      <span class="badge" :class="online ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-carmin'"><Icono nombre="wifi" clase="w-3.5 h-3.5 inline" /> {{ online ? 'En línea' : 'Sin señal: se vende igual' }}</span>
      <button v-if="esperanOk.length" class="badge bg-amber-100 text-amber-900 border border-amber-300" @click="pedirOkPara(esperanOk[0])" data-e2e="esperan-ok">{{ esperanOk.length }} espera{{ esperanOk.length > 1 ? 'n' : '' }} el OK del encargado</button>
      <button v-if="cola.length" class="badge bg-amber-50 text-amber-700" :disabled="!online || sincronizando" @click="sincronizar" data-e2e="cola">{{ sincronizando ? 'Mandando…' : `${cola.length} por mandar` }}</button>
      <span v-if="rechazadas.length" class="badge bg-carmin-light text-carmin">{{ rechazadas.length }} que no entraron</span>
      <span v-if="paquete" class="text-marca-muted">Datos del {{ fecha(paquete.generado) }}</span>
      <span v-if="datosViejos" class="badge bg-amber-50 text-amber-700">Descargaste hace {{ datosViejos }} días: los precios pueden haber cambiado</span>
      <button class="ml-auto btn-ghost !px-2 text-xs" :class="pantalla === 'reporte' ? 'text-violeta font-bold' : ''" @click="irReporte" data-e2e="ir-reporte">Mi reporte</button>
      <button class="btn-ghost !px-2 text-xs" :class="pantalla === 'hoy' ? 'text-violeta font-bold' : ''" @click="irHoy" data-e2e="ir-hoy">Hoy<span v-if="hoy?.en_mano?.total"> · {{ moneda(hoy.en_mano.total, 0) }} en mano</span></button>
      <button class="btn-ghost !px-2 text-xs" @click="pantalla = pantalla === 'datos' ? 'ruta' : 'datos'">{{ pantalla === 'datos' ? '← Volver' : 'Datos y conexión' }}</button>
    </div>

    <!-- Avisos del encargado: arriba de todo al abrir. El urgente no se baja. -->
    <div v-for="a in avisosVisibles" :key="a.id" class="rounded-xl px-3 py-2 mb-2 text-sm flex items-start gap-2" :class="a.urgente ? 'bg-carmin-light text-carmin border border-carmin/30' : 'bg-violeta/10 text-violeta'" :data-e2e="`aviso-${a.id}`">
      <div class="flex-1"><p class="font-bold">{{ a.titulo }}<span v-if="a.hasta" class="font-normal text-xs opacity-70"> · hasta {{ a.hasta }}</span></p><p v-if="a.texto" class="text-xs">{{ a.texto }}</p></div>
      <button v-if="!a.urgente" class="text-xs opacity-70" @click="bajarAviso(a)">✕</button>
    </div>

    <!-- DATOS -->
    <div v-if="pantalla === 'datos'" class="grid lg:grid-cols-2 gap-4">
      <div class="card">
        <h2 class="font-bold mb-1">Datos en el teléfono</h2>
        <p class="text-sm text-marca-muted mb-3">Antes de salir, descargá con wifi. Después se actualiza solo cada vez que abrís con señal.</p>
        <p class="text-sm" v-if="paquete">{{ paquete.productos.length }} productos · {{ paquete.ruta.total }} clientes · descargado el {{ fecha(paquete.generado) }}</p>
        <p class="text-sm text-carmin" v-else>Todavía no descargaste nada.</p>
        <button class="btn-primary mt-3" :disabled="descargando || !online" @click="descargar" data-e2e="descargar">{{ descargando ? 'Descargando…' : 'Descargar ahora' }}</button>
      </div>
      <div class="card">
        <h2 class="font-bold mb-1">Por mandar</h2>
        <ul class="text-sm divide-y divide-marca-borde"><li v-for="c in cola" :key="c.offline_id" class="py-1.5 flex justify-between"><span>{{ c._que }}</span><span class="text-marca-muted">{{ fecha(c.fecha_offline) }}</span></li><li v-if="!cola.length" class="py-3 text-marca-muted">Nada pendiente.</li></ul>
        <h2 class="font-bold mt-4 mb-1 text-carmin">Las que NO entraron</h2>
        <p class="text-xs text-marca-muted mb-2">El sistema las rechazó. No se van solas: reintentá si se arregló, o descartalas.</p>
        <ul class="text-sm divide-y divide-marca-borde"><li v-for="(r, i) in rechazadas" :key="i" class="py-1.5"><p>{{ r._que }} <span class="text-carmin">· {{ r._error }}</span></p><div class="flex gap-2 mt-1"><button class="btn-secondary !py-0.5 text-xs" @click="cola.push(r); rechazadas.splice(i, 1); guardar(); sincronizar()">Reintentar</button><button class="btn-ghost !py-0.5 text-xs" @click="rechazadas.splice(i, 1); guardar()">Descartar</button></div></li><li v-if="!rechazadas.length" class="py-3 text-marca-muted">Ninguna.</li></ul>
      </div>
    </div>

    <!-- RUTA -->
    <div v-else-if="pantalla === 'ruta'" class="space-y-3">
      <div class="flex gap-2"><input v-model="q" class="input flex-1" placeholder="Buscar cliente por nombre, CUIT o localidad" data-e2e="buscar-cliente" /></div>
      <p v-if="ruta?.hasta && ruta.hasta < hoyISO()" class="text-xs text-marca-muted">Las compras que conoce el sistema llegan hasta el {{ ruta.hasta }}: el atraso se mide contra esa fecha.</p>
      <div v-for="g in gruposVisibles" :key="g.k" class="card p-0 overflow-hidden">
        <button class="w-full flex items-center justify-between px-4 py-2.5 text-left" @click="abiertos[g.k] = !abiertos[g.k]" :data-e2e="`grupo-${g.k}`">
          <span class="font-bold">{{ g.titulo }} <span class="text-marca-muted font-normal text-sm">· {{ g.items.length }}</span></span><span class="text-xs text-marca-muted">{{ abiertos[g.k] ? 'ocultar' : 'ver' }}</span>
        </button>
        <div v-if="abiertos[g.k]" class="divide-y divide-marca-borde border-t border-marca-borde">
          <div v-for="c in g.items" :key="c.id" class="px-4 py-2.5 flex flex-wrap items-center gap-2" :data-e2e="`cliente-${c.id}`">
            <div class="flex-1 min-w-[12rem]">
              <p class="font-semibold">{{ c.nombre }} <span v-if="c.agenda" class="badge bg-sky-50 text-sky-700 ml-1">{{ c.agenda }}</span></p>
              <p class="text-xs text-marca-muted">{{ c.direccion || 'sin dirección' }}<span v-if="c.ritmo"> · compra cada {{ c.ritmo }} días · última hace {{ c.dias }}</span><span v-else-if="c.dias !== null && c.dias !== undefined"> · última hace {{ c.dias }} días</span></p>
              <p class="text-xs"><span :class="c.vencido > 0 ? 'text-carmin font-semibold' : 'text-marca-muted'">Saldo {{ moneda(c.saldo, 0) }}<span v-if="c.vencido > 0"> · vencido {{ moneda(c.vencido, 0) }} ({{ c.mora_dias }} días)</span></span></p>
            </div>
            <div class="flex gap-1.5">
              <button class="btn-primary !py-1 text-xs" @click="elegir(c)" :data-e2e="`venderle-${c.id}`">Venderle</button>
              <button class="btn-secondary !py-1 text-xs" @click="visita(c, 'llegue')">Llegué</button>
              <button class="btn-secondary !py-1 text-xs" @click="noVendi = c">No vendí</button>
              <button class="btn-ghost !py-1 !px-2 text-xs" @click="hoyNoVa.push(c.id)">Hoy no va</button>
            </div>
          </div>
        </div>
      </div>
      <p v-if="!gruposVisibles.length" class="card text-center text-marca-muted">{{ paquete ? 'Ningún cliente coincide.' : 'Descargá los datos en "Datos y conexión" para ver tu ruta.' }}</p>
    </div>

    <!-- MI REPORTE: lo mío y nada más -->
    <div v-else-if="pantalla === 'reporte'" class="space-y-3" data-e2e="pantalla-reporte">
      <div class="flex items-center gap-2"><div class="flex gap-1 bg-marca-fondo rounded-full p-1 text-xs flex-1 overflow-x-auto"><button v-for="(t, k) in (reporte?.periodo?.opciones ?? { hoy: 'Hoy', '7d': '7 días', mes: 'Este mes', mes_pasado: 'Mes pasado' })" :key="k" class="px-3 py-1 rounded-full font-semibold whitespace-nowrap" :class="periodoReporte === k ? 'bg-white shadow' : 'text-marca-muted'" @click="periodoReporte = k; cargarReporte()">{{ t }}</button></div><button class="btn-ghost !px-2 text-xs" @click="pantalla = 'ruta'">← Ruta</button></div>
      <template v-if="reporte">
        <div class="card">
          <p class="label">Hoy, mientras trabajás</p>
          <div class="grid grid-cols-4 gap-1 text-center"><div><p class="font-extrabold tabular-nums">{{ moneda(reporte.hoy.total, 0) }}</p><p class="text-[10px] text-marca-muted">vendido</p></div><div><p class="font-extrabold tabular-nums">{{ reporte.hoy.comprobantes }}</p><p class="text-[10px] text-marca-muted">comprob.</p></div><div><p class="font-extrabold tabular-nums">{{ reporte.hoy.clientes }}</p><p class="text-[10px] text-marca-muted">clientes</p></div><div><p class="font-extrabold tabular-nums">{{ moneda(reporte.hoy.ticket, 0) }}</p><p class="text-[10px] text-marca-muted">ticket</p></div></div>
        </div>
        <div v-if="reporte.objetivo" class="card" data-e2e="objetivo">
          <div class="flex justify-between items-baseline"><p class="label">Objetivo del mes</p><span class="text-xs font-bold" :class="reporte.objetivo.ritmo === 'adelantado' ? 'text-emerald-700' : 'text-carmin'">{{ reporte.objetivo.ritmo === 'adelantado' ? 'Vas adelantado' : 'Vas atrasado' }} para el día {{ reporte.objetivo.dia }}</span></div>
          <div class="h-2.5 rounded-full bg-marca-fondo overflow-hidden my-1"><div class="h-full bg-marca-grad" :style="{ width: Math.min(100, reporte.objetivo.pct) + '%' }"></div></div>
          <p class="text-sm"><b class="tabular-nums">{{ moneda(reporte.objetivo.vendido, 0) }}</b> de {{ moneda(reporte.objetivo.objetivo, 0) }} · {{ reporte.objetivo.pct }}% · faltan {{ moneda(reporte.objetivo.falta, 0) }}</p>
          <p class="text-[11px] text-marca-muted">Para hoy tendrías que llevar {{ moneda(reporte.objetivo.esperado_hoy, 0) }}: vas {{ moneda(Math.abs(reporte.objetivo.diferencia), 0) }} {{ reporte.objetivo.diferencia >= 0 ? 'arriba' : 'abajo' }}.</p>
        </div>
        <div class="card">
          <p class="label">{{ reporte.periodo.opciones[reporte.periodo.k] }}</p>
          <div class="grid grid-cols-3 gap-1 text-center text-sm"><div><p class="font-extrabold tabular-nums">{{ moneda(reporte.periodo_resumen.total, 0) }}</p><p class="text-[10px] text-marca-muted">vendido</p></div><div><p class="font-extrabold tabular-nums">{{ reporte.periodo_resumen.comprobantes }}</p><p class="text-[10px] text-marca-muted">comprobantes</p></div><div><p class="font-extrabold tabular-nums">{{ reporte.periodo_resumen.visitas }}</p><p class="text-[10px] text-marca-muted">visitas<span v-if="reporte.periodo_resumen.no_vendi"> · {{ reporte.periodo_resumen.no_vendi }} sin venta</span></p></div></div>
          <div v-if="reporte.comision" class="mt-2 text-xs rounded-lg bg-marca-fondo px-2 py-1.5" data-e2e="comision"><b>Tu comisión:</b> {{ moneda(reporte.comision.por_ventas + reporte.comision.por_cobros) }} · {{ reporte.comision.venta_pct }}% de ventas = {{ moneda(reporte.comision.por_ventas) }} · {{ reporte.comision.cobro_pct }}% de cobranzas = {{ moneda(reporte.comision.por_cobros) }} ({{ reporte.comision.cobros }} recibos por {{ moneda(reporte.comision.cobrado, 0) }}). <span class="text-marca-muted">La liquidación que vale es la de la empresa; esto es para que sepas cómo venís.</span></div>
        </div>
        <div class="card">
          <p class="label">Tu cartera este mes</p>
          <p class="text-sm"><b>{{ reporte.mes.compraron }}</b> de {{ reporte.mes.clientes_cartera }} clientes te compraron · <b class="text-carmin">{{ reporte.faltan.length }}</b> todavía no</p>
          <div v-if="reporte.faltan.length" class="mt-1 flex flex-wrap gap-1"><button v-for="c in reporte.faltan" :key="c.id" class="badge bg-amber-50 text-amber-800" @click="elegirDesdeReporte(c)">{{ c.nombre }}</button></div>
          <div v-if="reporte.compraron.length" class="mt-2 text-xs divide-y divide-marca-borde"><div v-for="c in reporte.compraron" :key="c.id" class="flex justify-between py-1"><button class="text-left font-medium" @click="elegirDesdeReporte(c)">{{ c.nombre }}</button><span class="tabular-nums">{{ moneda(c.total, 0) }}</span></div></div>
        </div>
        <div v-if="reporte.top.length" class="card"><p class="label">Lo que más vendiste</p><div class="text-xs divide-y divide-marca-borde"><div v-for="t in reporte.top" :key="t.product_id" class="flex justify-between py-1"><span class="truncate">{{ t.nombre }} <span class="text-marca-muted">× {{ cantidad(t.cantidad) }}</span></span><span class="tabular-nums">{{ moneda(t.total, 0) }}</span></div></div></div>
      </template>
      <p v-else class="card text-center text-xs text-marca-muted">{{ online ? 'Cargando…' : 'Sin señal: el reporte necesita conexión.' }}</p>
    </div>

    <!-- HOY: lo hecho, lo cobrado en mano y cerrar el turno -->
    <div v-else-if="pantalla === 'hoy'" class="space-y-3" data-e2e="pantalla-hoy">
      <div class="card">
        <div class="flex items-center justify-between"><h2 class="font-bold">Lo que tenés en mano</h2><button class="btn-ghost !px-2 text-xs" @click="pantalla = 'ruta'">← Ruta</button></div>
        <p class="text-xs text-marca-muted mb-2">Lo que cobraste ya bajó la deuda del cliente, pero la plata queda a tu nombre hasta que rendís y el encargado la recibe.</p>
        <div v-if="hoy" class="grid grid-cols-3 gap-2 text-center">
          <div class="rounded-xl bg-marca-fondo p-2"><p class="text-[10px] uppercase tracking-wider text-marca-muted">Efectivo</p><p class="font-extrabold tabular-nums">{{ moneda(hoy.en_mano.efectivo, 0) }}</p></div>
          <div class="rounded-xl bg-marca-fondo p-2"><p class="text-[10px] uppercase tracking-wider text-marca-muted">Transf.</p><p class="font-extrabold tabular-nums">{{ moneda(hoy.en_mano.transferencias, 0) }}</p></div>
          <div class="rounded-xl bg-marca-fondo p-2"><p class="text-[10px] uppercase tracking-wider text-marca-muted">Cheques</p><p class="font-extrabold tabular-nums">{{ moneda(hoy.en_mano.cheques, 0) }}</p></div>
        </div>
        <button v-if="hoy?.en_mano?.total" class="btn-primary w-full mt-3 !py-3" :disabled="!online || rindiendo" @click="rendir" data-e2e="rendir">{{ rindiendo ? 'Rindiendo…' : 'Cerrar el turno y rendir' }}</button>
        <p v-if="hoy?.ultima_rendicion" class="text-[11px] text-marca-muted mt-2">Última rendición {{ hoy.ultima_rendicion.numero }} · {{ hoy.ultima_rendicion.cuando }} · {{ moneda(hoy.ultima_rendicion.total) }} · {{ hoy.ultima_rendicion.estado === 'recibida' ? 'recibida' : 'esperando que la reciban' }}</p>
        <p v-if="!online" class="text-[11px] text-amber-700 mt-1">Sin señal: lo de hoy se ve como estaba la última vez con conexión.</p>
      </div>
      <div class="card p-0">
        <p class="px-4 py-2 font-bold border-b border-marca-borde">Cobros de hoy <span class="text-marca-muted font-normal text-sm">· {{ hoy?.cobros?.length ?? 0 }}</span></p>
        <div v-for="co in hoy?.cobros ?? []" :key="co.id" class="px-4 py-2 border-b border-marca-borde/60 text-sm flex items-center gap-2">
          <div class="flex-1 min-w-0"><p class="font-semibold truncate">{{ co.cliente }}</p><p class="text-xs text-marca-muted">{{ co.numero }} · {{ co.hora }} · {{ co.medios }}<span v-if="co.firmado"> · firmado</span></p></div>
          <span class="font-bold tabular-nums">{{ moneda(co.total) }}</span>
          <a :href="`/clientes/cobros/${co.id}/imprimir`" target="_blank" class="text-violeta text-xs font-semibold">Recibo</a>
        </div>
        <p v-if="!hoy?.cobros?.length" class="px-4 py-4 text-center text-xs text-marca-muted">Nada cobrado todavía.</p>
      </div>
      <div class="card p-0">
        <p class="px-4 py-2 font-bold border-b border-marca-borde">Hecho hoy <span class="text-marca-muted font-normal text-sm">· {{ hoy?.ventas?.length ?? 0 }}</span></p>
        <div v-for="vt in hoy?.ventas ?? []" :key="vt.id" class="px-4 py-2 border-b border-marca-borde/60 text-sm flex items-center gap-2" :data-e2e="`hecho-${vt.id}`">
          <div class="flex-1 min-w-0"><p class="font-semibold truncate">{{ vt.cliente }}</p><p class="text-xs text-marca-muted">{{ vt.tipo }} {{ vt.numero }} · {{ vt.estado === 'emitido' ? 'emitido' : 'pendiente' }}<span v-if="vt.entregado" class="text-emerald-700"> · ✓ entregado · recibió {{ vt.entregado.nombre }} {{ vt.entregado.hora }}</span></p></div>
          <span class="font-bold tabular-nums" :class="vt.es_nc ? 'text-carmin' : ''">{{ vt.es_nc ? '−' : '' }}{{ moneda(vt.total) }}</span>
          <button v-if="vt.entregable && !vt.entregado" class="btn-secondary !py-1 text-xs" @click="abrirEntrega(vt)" :data-e2e="`entregar-${vt.id}`">Confirmar entrega</button>
        </div>
        <p v-if="!hoy?.ventas?.length" class="px-4 py-4 text-center text-xs text-marca-muted">Nada vendido todavía.</p>
      </div>
    </div>

    <!-- CLIENTE Y PEDIDO -->
    <div v-else class="grid lg:grid-cols-[1fr_22rem] gap-4">
      <div class="space-y-3 min-w-0">
        <div class="card !py-3" data-e2e="ficha-cliente">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
              <button class="text-xs text-marca-muted hover:text-carmin" @click="volverRuta">← Cambiar cliente</button>
              <p class="font-extrabold text-lg">{{ cli.nombre }}</p>
              <p class="text-xs text-marca-muted">Lista {{ cli.lista }}<span v-if="cli.descuento"> · {{ cli.descuento }}% general</span> · {{ cli.condicion_iva }}<span v-if="cli.limite"> · límite {{ moneda(cli.limite, 0) }}</span></p>
              <p class="text-sm mt-1"><b :class="cli.vencido > 0 ? 'text-carmin' : ''">Saldo {{ moneda(cli.saldo) }}</b><span v-if="cli.vencido > 0" class="text-carmin"> · {{ moneda(cli.vencido, 0) }} vencido hace {{ cli.mora_dias }} días</span></p>
            </div>
            <div class="flex gap-1.5 text-xs">
              <a v-if="cli.telefono" :href="`tel:${cli.telefono}`" class="btn-secondary !py-1">Llamar</a>
              <a v-if="cli.lat || cli.direccion" :href="cli.lat ? `https://www.google.com/maps?q=${cli.lat},${cli.lng}` : `https://www.google.com/maps/search/${encodeURIComponent(cli.direccion)}`" target="_blank" class="btn-secondary !py-1">Cómo llegar</a>
              <button class="btn-secondary !py-1" @click="noVendi = cli">No vendí</button>
              <button v-if="puede.cobrar" class="btn-primary !py-1 !bg-emerald-700 !border-emerald-700" @click="abrirCobro" data-e2e="cobrar">Cobrar</button>
              <button v-if="puede.facturar" class="btn-secondary !py-1" @click="abrirDevolucion" data-e2e="devolucion">Devolución</button>
            </div>
          </div>
          <p v-if="cli.notas" class="mt-2 text-xs rounded-lg bg-marca-fondo text-marca-muted px-2 py-1" title="Observaciones de la ficha (sólo lectura)">{{ cli.notas }}</p>
          <div v-for="n in ficha?.notas ?? []" :key="n.id" class="mt-1 text-xs rounded-lg bg-amber-50 text-amber-900 px-2 py-1 flex items-start gap-2" :data-e2e="`nota-${n.id}`"><span class="flex-1">{{ n.texto }} <span class="opacity-60">· {{ n.quien }} {{ n.cuando }}</span></span><button class="font-bold" @click="notaLista(n)">Listo</button></div>
          <div class="mt-1 flex gap-1"><input v-model="notaNueva" class="input !py-1 text-xs flex-1" placeholder="Anotar algo del cliente (aparece la próxima vez)" maxlength="500" data-e2e="nota-texto" @keydown.enter="anotar" /><button class="btn-secondary !py-1 text-xs" :disabled="!notaNueva.trim()" @click="anotar" data-e2e="nota-guardar">Anotar</button><button class="btn-secondary !py-1 text-xs" @click="abrirRelevar" data-e2e="relevar">📷 Relevar</button></div>
          <p v-if="ficha?.visitas?.length" class="mt-1 text-[11px] text-marca-muted">Última visita: {{ ficha.visitas[0].cuando }} · {{ ficha.visitas[0].tipo === 'venta' ? 'vendiste' : ficha.visitas[0].tipo === 'llegue' ? 'llegaste' : ficha.visitas[0].motivo }}</p>
        </div>

        <div class="flex gap-1.5 overflow-x-auto pb-1">
          <button v-for="a in atajos" :key="a.k" @click="atajo = a.k" class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border" :class="atajo === a.k ? 'bg-marca-texto text-white border-marca-texto' : 'bg-white border-marca-borde text-marca-muted'" :data-e2e="`atajo-${a.k}`">{{ a.t }}</button>
          <button class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border bg-white border-marca-borde text-violeta" @click="repetirUltimo" :disabled="!ficha?.ultimo" data-e2e="repetir">Repetir el último<span v-if="ficha?.ultimo" class="opacity-70"> ({{ ficha.ultimo.fecha }})</span></button>
          <button class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border bg-white border-marca-borde text-violeta" @click="cargarSugerido" :disabled="!ficha?.sugerido?.length" data-e2e="sugerido">Pedido sugerido</button>
        </div>
        <div class="relative"><Icono nombre="search" clase="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-marca-muted" /><input ref="buscador" v-model="qp" class="input !pl-10 !py-3" placeholder="Buscar producto, código o escanear" data-e2e="buscar-producto" @keydown.enter.prevent="enterProducto" /></div>

        <div class="card p-0 divide-y divide-marca-borde max-h-[60vh] overflow-y-auto overflow-x-hidden">
          <div v-for="p in listado" :key="p.id" class="flex items-center gap-2 px-3 py-2" :data-e2e="`prod-${p.id}`">
            <input type="checkbox" class="accent-carmin w-5 h-5" :checked="!!sel[p.id]" @change="toggle(p)" />
            <div class="flex-1 min-w-0" @click="toggle(p)">
              <p class="font-semibold text-sm leading-tight">{{ p.name }}</p>
              <p class="text-[11px] text-marca-muted">{{ p.controla ? `${cantidad(p.stock)} ${p.unit}` : p.sku }}<span v-if="p.promo"> · llevando {{ cantidad(p.promo.desde) }} o más: {{ p.promo.pct }}% menos</span><span v-if="p._motivo" class="text-violeta"> · {{ p._motivo }}</span></p>
            </div>
            <div class="text-right"><p class="font-extrabold tabular-nums">{{ moneda(precioFinal(p)) }}</p><p v-if="origen(p) !== 'lista'" class="text-[10px] text-violeta">{{ origen(p) === 'pactado' ? 'pactado' : origen(p) === 'rubro' ? 'pactado (rubro)' : 'último' }}</p></div>
            <input v-if="sel[p.id]" v-model.number="sel[p.id].cantidad" type="number" step="any" min="0" class="input !py-1 w-16 text-center tabular-nums" @click.stop />
          </div>
          <p v-if="!listado.length" class="text-center text-sm text-marca-muted py-8">{{ qp ? 'Nada coincide.' : 'Sin historial: buscá o pasá a Todo el catálogo.' }}</p>
        </div>
        <button v-if="seleccionados.length" class="btn-primary w-full" @click="agregarSeleccion" data-e2e="agregar">Agregar {{ seleccionados.length }} al pedido</button>
      </div>

      <!-- El pedido -->
      <div class="card p-0 flex flex-col lg:sticky lg:top-4 max-h-[calc(100vh-150px)]" data-e2e="pedido">
        <div class="px-4 py-2.5 border-b border-marca-borde">
          <p class="label">¿Con qué lo cerrás?</p>
          <div class="flex gap-1.5">
            <button v-for="t in tiposDisponibles" :key="t.k" class="flex-1 py-1.5 rounded-lg border text-xs font-semibold" :class="tipo === t.k ? 'bg-sky-700 text-white border-sky-700' : 'bg-white border-marca-borde'" @click="tipo = t.k" :data-e2e="`tipo-${t.k}`">{{ t.t }}</button>
          </div>
          <p class="text-[11px] text-marca-muted mt-1">{{ tipo === 'PRE' ? 'Queda para mandar al cliente; no mueve stock ni cuenta.' : config.calle_emite ? 'Se emite ahora.' : 'Queda pendiente de emitir en el local.' }}</p>
        </div>
        <div class="flex-1 overflow-y-auto">
          <div v-for="(it, i) in pedido" :key="i" class="px-4 py-2 border-b border-marca-borde/60 text-sm flex items-center gap-2">
            <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ it.descripcion }}</p><p class="text-xs text-marca-muted tabular-nums">{{ moneda(it.precio_unit) }} c/u<span v-if="it.descuento"> · {{ it.origen === 'promo' ? `${it.descuento}% de promo` : it.origen === 'pactado' || it.origen === 'rubro' ? `${it.descuento}% pactado` : `−${it.descuento}%` }}</span></p></div>
            <input v-model.number="it.cantidad" type="number" step="any" min="0" class="input !py-1 w-16 text-center tabular-nums" />
            <input v-if="puede.precios" v-model.number="it.descuento" type="number" step="any" min="0" max="100" class="input !py-1 w-14 text-center tabular-nums" :class="sobreTope(it) ? '!border-amber-500' : ''" title="Descuento %" @change="it.origen = it.descuento > (it._auto ?? 0) + 0.005 ? 'manual' : it._origenAuto" :data-e2e="`dto-${it.product_id}`" />
            <span class="w-20 text-right font-semibold tabular-nums">{{ moneda(it.cantidad * it.precio_unit * (1 - it.descuento / 100) * (ri ? 1 + it.alicuota_iva / 100 : 1)) }}</span>
            <button @click="pedido.splice(i, 1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
          </div>
          <p v-if="!pedido.length" class="text-center text-marca-muted text-sm py-10">Pedido vacío. Tildá lo que lleva.</p>
        </div>
        <div class="px-4 py-3 border-t border-marca-borde space-y-2">
          <div v-if="ri && totales.iva" class="flex justify-between text-xs text-marca-muted"><span>Neto {{ moneda(totales.neto) }}</span><span>IVA {{ moneda(totales.iva) }}</span></div>
          <div class="flex items-baseline justify-between"><span class="font-bold">TOTAL</span><span class="text-3xl font-black tabular-nums" data-e2e="total">{{ moneda(totales.total) }}</span></div>
          <input v-model="notas" class="input !py-1.5 text-xs" placeholder="Notas del comprobante (entregar después de las 14…)" maxlength="300" />
          <p v-if="avisoCredito" class="text-xs rounded-lg bg-carmin-light text-carmin px-2 py-1" data-e2e="aviso-credito">{{ avisoCredito }}</p>
          <p v-if="pideOk" class="text-xs rounded-lg bg-amber-50 text-amber-800 px-2 py-1" data-e2e="aviso-tope">Más de {{ config.tope_descuento }}%: al confirmar te va a pedir el OK del encargado{{ ok && ok.contact_id === cli.id && ok.pct + 0.005 >= pctMaximo ? ` (ya lo dio ${ok.encargado})` : '' }}.</p>
          <p v-if="error" class="text-carmin text-xs">{{ error }}</p>
          <button class="btn-primary w-full !py-3" :disabled="!pedido.length || enviando" @click="confirmar()" data-e2e="confirmar">{{ enviando ? 'Mandando…' : (tipo === 'PRE' ? 'Guardar presupuesto' : 'Confirmar pedido') }}</button>
          <button v-if="pedido.length" class="w-full text-xs text-carmin" @click="pedido = []">Vaciar</button>
        </div>
      </div>
    </div>

    <!-- Resultado -->
    <div v-if="resultado" class="fixed inset-x-3 bottom-3 z-40 rounded-2xl shadow-lg px-4 py-3 text-sm flex flex-wrap items-center gap-3" :class="resultado.offline ? 'bg-amber-50 border border-amber-300 text-amber-900' : 'bg-emerald-50 border border-emerald-200 text-emerald-800'" data-e2e="resultado">
      <span v-if="resultado.offline">Guardado en el teléfono: <b>entra solo cuando vuelva la señal</b>. No lo vuelvas a cargar.</span>
      <span v-else-if="resultado.relevado">Relevamiento guardado. El encargado lo ve en Estadísticas → Vendedores.</span>
      <span v-else-if="resultado.cobro"><b>Recibo {{ resultado.cobro.numero }}</b> por <b class="tabular-nums">{{ moneda(resultado.cobro.total) }}</b><span v-if="resultado.cobro.a_cuenta > 0"> · {{ moneda(resultado.cobro.a_cuenta) }} a cuenta</span> · saldo {{ moneda(resultado.cobro.saldo) }} <a :href="`/clientes/cobros/${resultado.cobro.cobro_id}/imprimir`" target="_blank" class="font-semibold underline">Recibo</a></span>
      <span v-else-if="resultado.rendicion"><b>Rendición {{ resultado.rendicion.numero }}</b>: efectivo {{ moneda(resultado.rendicion.efectivo) }} · transferencias {{ moneda(resultado.rendicion.transferencias) }} · cheques {{ moneda(resultado.rendicion.cheques) }}. Ahora la recibe el encargado.</span>
      <span v-else><b>{{ resultado.tipo }} {{ resultado.numero }}</b> por <b class="tabular-nums">{{ moneda(resultado.total) }}</b> · {{ resultado.estado === 'emitido' ? 'emitido' : resultado.presupuesto ? 'guardado' : 'pendiente de emitir' }}</span>
      <Link v-if="resultado.comprobante_id" :href="`/comprobantes/${resultado.comprobante_id}`" class="font-semibold text-xs">Ver</Link>
      <button v-if="resultado.comprobante_id && !resultado.presupuesto && !resultado.es_nc && !resultado.entregado" class="font-semibold text-xs underline" @click="abrirEntrega({ id: resultado.comprobante_id, tipo: resultado.tipo, numero: resultado.numero })" data-e2e="entregar-ahora">Confirmar entrega</button>
      <button @click="resultado = null" class="ml-auto text-xs">✕</button>
    </div>

    <!-- No vendí -->
    <Modal :abierto="!!noVendi" :titulo="noVendi ? `No vendí a ${noVendi.nombre}` : ''" @cerrar="noVendi = null">
      <p class="text-sm text-marca-muted mb-2">El motivo es de una lista para poder contarlos después: si "estaba cerrado" encabeza la lista, el problema es el horario de la ruta.</p>
      <div class="grid gap-1.5"><button v-for="(t, k) in motivos" :key="k" class="text-left rounded-lg border px-3 py-2 text-sm" :class="motivo === k ? 'border-violeta bg-violeta/5' : 'border-marca-borde'" @click="motivo = k" :data-e2e="`motivo-${k}`">{{ t }}</button></div>
      <input v-model="notaVisita" class="input mt-2" placeholder="Nota (opcional)" />
      <template #pie><button class="btn-secondary" @click="noVendi = null">Cancelar</button><button class="btn-primary" :disabled="!motivo" @click="visita(noVendi, 'no_vendi'); noVendi = null" data-e2e="guardar-no-vendi">Guardar</button></template>
    </Modal>

    <!-- Relevar con fotos (necesita señal) -->
    <Modal :abierto="!!relevar" :titulo="relevar ? `Relevar en ${cli?.nombre}` : ''" @cerrar="relevar = null">
      <div v-if="relevar" class="space-y-2">
        <div class="grid grid-cols-2 gap-1"><button v-for="(t, k) in tiposRelevamiento" :key="k" class="rounded-lg border px-2 py-1.5 text-xs text-left" :class="relevar.tipo === k ? 'border-violeta bg-violeta/5' : 'border-marca-borde'" @click="relevar.tipo = k" :data-e2e="`relevar-${k}`">{{ t }}</button></div>
        <label class="block text-xs"><span class="text-marca-muted">Foto</span><input type="file" accept="image/*" capture="environment" class="block mt-1 text-xs" @change="fotoRelevar" data-e2e="relevar-foto" /></label>
        <img v-if="relevar.foto" :src="relevar.foto" class="h-28 rounded-lg border border-marca-borde" />
        <input v-model="relevar.nota" class="input !py-1.5 text-xs" placeholder="Aclaración (la competencia lo tiene a $1.200)" maxlength="300" />
        <p class="text-[11px] text-marca-muted">La foto se achica en el teléfono antes de subir. Necesita señal: las fotos no esperan en la cola.</p>
        <p v-if="relevar.error" class="text-carmin text-xs">{{ relevar.error }}</p>
      </div>
      <template #pie><button class="btn-secondary" @click="relevar = null">Cancelar</button><button class="btn-primary" :disabled="!relevar?.foto || !relevar?.tipo || relevar?.enviando || !online" @click="confirmarRelevar" data-e2e="relevar-confirmar">Guardar</button></template>
    </Modal>

    <!-- Cobrar -->
    <Modal :abierto="!!cobro" :titulo="cobro ? `Cobrar a ${cli?.nombre}` : ''" @cerrar="cobro = null">
      <p class="text-xs text-marca-muted mb-2">Saldo {{ moneda(cli?.saldo ?? 0) }}. Lo cobrado baja la deuda ahora; la plata queda a tu nombre hasta que rendís. Se imputa a las facturas más viejas.</p>
      <div v-if="cobro" class="space-y-2">
        <div v-for="(m, i) in cobro.medios" :key="i" class="rounded-xl border border-marca-borde p-2 space-y-1.5">
          <div class="flex gap-1.5"><select v-model="m.medio" class="input !py-1.5 flex-1"><option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option><option value="cheque">Cheque</option></select><input v-model.number="m.monto" type="number" step="any" min="0" class="input !py-1.5 w-32 text-right tabular-nums" placeholder="Importe" :data-e2e="`cobro-monto-${i}`" /><button v-if="cobro.medios.length > 1" class="text-marca-muted" @click="cobro.medios.splice(i, 1)">✕</button></div>
          <input v-if="m.medio === 'transferencia'" v-model="m.referencia" class="input !py-1.5 text-xs" placeholder="Referencia (opcional)" />
          <div v-if="m.medio === 'cheque'" class="grid grid-cols-2 gap-1.5">
            <input v-model="m.datos.numero" class="input !py-1.5 text-xs" placeholder="Número" /><select v-model="m.datos.banco" class="input !py-1.5 text-xs"><option value="">Banco</option><option v-for="b in bancos" :key="b" :value="b">{{ b }}</option></select>
            <input v-model="m.datos.fecha_pago" type="date" class="input !py-1.5 text-xs" /><input v-model="m.datos.titular" class="input !py-1.5 text-xs" placeholder="Titular" />
            <label class="col-span-2 text-xs flex items-center gap-1"><input v-model="m.datos.echeq" type="checkbox" class="accent-carmin" /> Es un e-cheq</label>
          </div>
        </div>
        <button class="text-xs text-violeta font-semibold" @click="cobro.medios.push(medioVacio())">+ otro medio</button>
        <div class="flex justify-between font-bold"><span>Total</span><span class="tabular-nums" data-e2e="cobro-total">{{ moneda(totalCobro) }}</span></div>
        <input v-model="cobro.firma_nombre" class="input !py-1.5 text-xs" placeholder="Nombre de quien firma (opcional)" />
        <Firma v-model="cobro.firma" />
        <p v-if="cobro.error" class="text-carmin text-xs">{{ cobro.error }}</p>
        <label v-if="cobro.chequeRepetido" class="text-xs flex items-center gap-1 text-amber-800"><input v-model="cobro.forzar_cheque" type="checkbox" class="accent-carmin" /> Es otro cheque, cargarlo igual</label>
      </div>
      <template #pie><button class="btn-secondary" @click="cobro = null">Cancelar</button><button class="btn-primary" :disabled="totalCobro <= 0 || cobro?.enviando" @click="confirmarCobro" data-e2e="cobro-confirmar">Cobrar {{ moneda(totalCobro) }}</button></template>
    </Modal>

    <!-- Devolución -->
    <Modal :abierto="!!devolucion" :titulo="devolucion ? `Devolución de ${cli?.nombre}` : ''" @cerrar="devolucion = null">
      <p class="text-xs text-marca-muted mb-2">Sale como nota de crédito. Las cantidades van en positivo: el signo lo pone el comprobante.</p>
      <div v-if="devolucion" class="space-y-2">
        <input v-model="devolucion.q" class="input !py-1.5" placeholder="Buscar producto" />
        <div class="max-h-40 overflow-y-auto divide-y divide-marca-borde border border-marca-borde rounded-xl">
          <div v-for="p in listaDevolucion" :key="p.id" class="flex items-center gap-2 px-2 py-1.5 text-sm"><span class="flex-1 truncate">{{ p.name }}</span><input v-model.number="devolucion.cant[p.id]" type="number" step="any" min="0" class="input !py-1 w-16 text-center" placeholder="0" :data-e2e="`dev-${p.id}`" /></div>
        </div>
        <div class="grid gap-1"><button v-for="(t, k) in motivosDevolucion" :key="k" class="text-left rounded-lg border px-3 py-1.5 text-sm" :class="devolucion.motivo === k ? 'border-violeta bg-violeta/5' : 'border-marca-borde'" @click="devolucion.motivo = k" :data-e2e="`dev-motivo-${k}`">{{ t }}</button></div>
        <input v-model="devolucion.nota" class="input !py-1.5 text-xs" placeholder="Nota (opcional)" />
        <p v-if="devolucion.error" class="text-carmin text-xs">{{ devolucion.error }}</p>
      </div>
      <template #pie><button class="btn-secondary" @click="devolucion = null">Cancelar</button><button class="btn-primary" :disabled="!devolucion?.motivo || !itemsDevolucion.length || devolucion?.enviando" @click="confirmarDevolucion" data-e2e="dev-confirmar">Cargar devolución</button></template>
    </Modal>

    <!-- Confirmación de entrega -->
    <Modal :abierto="!!entrega" :titulo="entrega ? `Entrega de ${entrega.tipo} ${entrega.numero}` : ''" @cerrar="entrega = null">
      <div v-if="entrega" class="space-y-2">
        <input v-model="entrega.nombre" class="input" placeholder="Nombre y apellido de quien recibe" data-e2e="entrega-nombre" />
        <Firma v-model="entrega.firma" />
        <label class="block text-xs"><span class="text-marca-muted">Foto de la mercadería o del remito firmado (opcional)</span><input type="file" accept="image/*" capture="environment" class="block mt-1 text-xs" @change="fotoEntrega" /></label>
        <img v-if="entrega.foto" :src="entrega.foto" class="h-24 rounded-lg border border-marca-borde" />
        <p class="text-[11px] text-marca-muted">Con la firma o con la foto alcanza. Queda como constancia de que el cliente recibió la mercadería.</p>
        <p v-if="entrega.error" class="text-carmin text-xs">{{ entrega.error }}</p>
      </div>
      <template #pie><button class="btn-secondary" @click="entrega = null">Después</button><button class="btn-primary" :disabled="!entrega?.nombre || entrega?.enviando" @click="confirmarEntrega" data-e2e="entrega-confirmar">Confirmar entrega</button></template>
    </Modal>

    <!-- OK del encargado: pone su usuario y clave en este teléfono -->
    <Modal :abierto="!!okPedido" titulo="OK del encargado" @cerrar="okPedido = null">
      <p class="text-sm">Descuento de <b>{{ okPedido?.pct }}%</b> para <b>{{ okPedido?.nombre }}</b>: pasa el tope de {{ config.tope_descuento }}%. El encargado pone su usuario y clave acá. Vale 15 minutos y una sola venta.</p>
      <div class="grid gap-2 mt-3">
        <input v-model="okForm.email" type="email" class="input" placeholder="Usuario (email) del encargado" autocomplete="off" data-e2e="ok-email" />
        <input v-model="okForm.password" type="password" class="input" placeholder="Su clave" autocomplete="new-password" data-e2e="ok-clave" />
        <p v-if="okForm.error" class="text-carmin text-xs">{{ okForm.error }}</p>
        <p v-if="!online" class="text-amber-700 text-xs">Para autorizar hace falta señal: la clave la valida el sistema.</p>
      </div>
      <template #pie><button class="btn-secondary" @click="okPedido = null">Cancelar</button><button class="btn-primary" :disabled="!okForm.email || !okForm.password || okForm.enviando || !online" @click="pedirOk" data-e2e="ok-autorizar">Autorizar</button></template>
    </Modal>

    <!-- Vender igual -->
    <Modal :abierto="!!forzar" titulo="¿Vender igual?" @cerrar="forzar = null">
      <p class="text-sm">{{ forzar }}</p><p class="text-xs text-marca-muted mt-2">Podés vender, pero queda registrado que fue a pesar del aviso, con tu nombre.</p>
      <template #pie><button class="btn-secondary" @click="forzar = null">No</button><button class="btn-primary" @click="confirmar(true)" data-e2e="vender-igual">Sí, vender igual</button></template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import Firma from '@/Components/Firma.vue'
import { moneda, cantidad, hoyISO } from '@/util/formato'

const props = defineProps({ config: Object, puede: Object, motivos: Object, motivosDevolucion: Object, bancos: Array, tiposRelevamiento: Object, avisos: Array, preciosConIva: Boolean, vendedor: Object })
const ri = computed(() => props.preciosConIva)
const xsrf = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
const post = (url, body) => fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(body) })
const fecha = iso => iso ? new Date(iso).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : ''
const leer = (k, d) => { try { return JSON.parse(localStorage.getItem(k)) ?? d } catch (e) { return d } }
const escribir = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)) } catch (e) {} }

// --- El paquete: todo lo necesario para vender sin señal ---
const paquete = ref(leer('vender_paquete', null)), descargando = ref(false), online = ref(navigator.onLine)
const datosViejos = computed(() => { if (!paquete.value) return 0; const d = Math.floor((Date.now() - new Date(paquete.value.generado)) / 86400000); return d >= (props.config.avisar_datos_dias || 7) && props.config.avisar_datos_dias > 0 ? d : 0 })
async function descargar() {
  if (!navigator.onLine) return
  descargando.value = true
  try { const r = await fetch('/vender/paquete', { headers: { Accept: 'application/json' } }); if (r.ok) { paquete.value = await r.json(); escribir('vender_paquete', paquete.value) } } catch (e) {} finally { descargando.value = false }
}
const ruta = computed(() => paquete.value?.ruta)
const productos = computed(() => paquete.value?.productos ?? [])
const porId = computed(() => Object.fromEntries(productos.value.map(p => [p.id, p])))

// --- Ruta ---
const pantalla = ref('ruta'), q = ref(''), abiertos = reactive({ agenda: true, toca: true, dejo: false, no_toca: false, sin_historial: false, diarios: false }), hoyNoVa = ref(leer('vender_hoy_no_va_' + hoyISO(), []))
watch(hoyNoVa, v => escribir('vender_hoy_no_va_' + hoyISO(), v), { deep: true })
const titulos = { agenda: 'Hoy por agenda', toca: 'Le toca hoy', dejo: 'Dejó de comprar', no_toca: 'Todavía no le toca', sin_historial: 'Sin historial suficiente', diarios: 'Compran casi todos los días' }
const norm = s => (s ?? '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
const gruposVisibles = computed(() => {
  const g = ruta.value?.grupos ?? {}; const t = norm(q.value)
  return Object.keys(titulos).filter(k => g[k]?.length).map(k => ({ k, titulo: titulos[k], items: g[k].filter(c => !hoyNoVa.value.includes(c.id) && (!t || norm(c.nombre).includes(t) || (c.cuit || '').includes(q.value) || norm(c.direccion).includes(t))) })).filter(x => x.items.length)
})

// --- Cliente elegido ---
const cli = ref(null), ficha = ref(null), atajo = ref('suele'), qp = ref(''), sel = reactive({}), buscador = ref(null)
const atajos = [{ k: 'suele', t: 'Lo que suele llevar' }, { k: 'hace_rato', t: 'Hace rato que no lleva' }, { k: 'todo', t: 'Todo el catálogo' }]
async function elegir(c) {
  cli.value = c; ficha.value = null; pantalla.value = 'cliente'; Object.keys(sel).forEach(k => delete sel[k]); atajo.value = 'suele'
  const pend = leer('vender_pendiente_' + c.id, null); if (pend?.length && confirm('Tenés un pedido a medias con este cliente. ¿Lo retomás?')) pedido.value = pend; else pedido.value = []
  try { const r = await fetch(`/vender/cliente/${c.id}`, { headers: { Accept: 'application/json' } }); if (r.ok) { ficha.value = await r.json(); cli.value = { ...c, ...ficha.value.cliente }; escribir('vender_ficha_' + c.id, ficha.value) } else throw 0 } catch (e) { ficha.value = leer('vender_ficha_' + c.id, { condiciones: { lista: c.lista, descuento: c.descuento, rubros: {}, articulos: {} }, suele_llevar: [], sugerido: [], hace_rato: [] }) }
  if (!ficha.value.suele_llevar?.length) atajo.value = 'todo'
}
function volverRuta() { if (pedido.value.length) escribir('vender_pendiente_' + cli.value.id, pedido.value); pantalla.value = 'ruta'; cli.value = null }
const cond = computed(() => ficha.value?.condiciones ?? { lista: cli.value?.lista ?? 1, descuento: cli.value?.descuento ?? 0, rubros: {}, articulos: {} })
// El precio y el descuento de cada producto para este cliente: pactado por artículo, por rubro, su lista y descuento general. La promo por cantidad se aplica al cargar.
function base(p) { const a = cond.value.articulos?.[p.id]; const precio = a?.precio ?? p.prices?.[cond.value.lista] ?? p.price; let desc = a?.descuento ?? (a?.precio != null ? 0 : null); let origen = a ? (a.origen === 'ultimo' ? 'ultimo' : 'pactado') : null; if (desc == null && p.rubro_id && cond.value.rubros?.[p.rubro_id] != null) { desc = cond.value.rubros[p.rubro_id]; origen = origen ?? 'rubro' } return { precio, descuento: desc ?? cond.value.descuento ?? 0, origen: origen ?? 'lista' } }
const origen = p => base(p).origen
const precioFinal = p => { const b = base(p); return Math.round(b.precio * (1 - b.descuento / 100) * (ri.value ? 1 + p.iva / 100 : 1) * 100) / 100 }
const listado = computed(() => {
  const t = norm(qp.value)
  if (t) return productos.value.filter(p => norm(p.name).includes(t) || norm(p.sku).includes(t) || (p.barcode && p.barcode.includes(qp.value.trim()))).slice(0, 60)
  if (atajo.value === 'todo') return productos.value.slice(0, 200)
  const src = atajo.value === 'suele' ? (ficha.value?.suele_llevar ?? []) : (ficha.value?.hace_rato ?? [])
  return src.map(h => porId.value[h.product_id] ? { ...porId.value[h.product_id], _motivo: atajo.value === 'suele' ? `lleva ${cantidad(h.cantidad)} al año` : `lo llevó ${h.veces} veces` } : null).filter(Boolean)
})
function toggle(p) { if (sel[p.id]) delete sel[p.id]; else sel[p.id] = { p, cantidad: 1 } }
const seleccionados = computed(() => Object.values(sel))
function enterProducto() { const t = qp.value.trim(); if (!t) return; const p = productos.value.find(x => x.barcode === t || norm(x.sku) === norm(t)) ?? listado.value[0]; if (p) { sumar(p, 1); qp.value = '' } }
function agregarSeleccion() { seleccionados.value.forEach(s => sumar(s.p, s.cantidad)); Object.keys(sel).forEach(k => delete sel[k]) }

// --- El pedido ---
const pedido = ref([]), tipo = ref(props.config.calle_comprobante || 'PRE'), notas = ref(''), enviando = ref(false), error = ref(null), resultado = ref(null), forzar = ref(null)
const tiposDisponibles = computed(() => [{ k: 'PRE', t: 'Presupuesto' }, ...(props.puede.facturar ? [{ k: 'FX', t: 'Factura' }, { k: 'REM', t: 'Remito' }] : [])])
function sumar(p, cant) {
  const b = base(p); const ex = pedido.value.find(i => i.product_id === p.id)
  const cantidadTotal = (ex?.cantidad ?? 0) + cant
  // Promo por cantidad: gana la mayor entre la promo y el descuento del cliente, nunca se suman.
  let desc = b.descuento, orig = b.origen; if (p.promo && cantidadTotal >= p.promo.desde && p.promo.pct > desc) { desc = p.promo.pct; orig = 'promo' }
  if (ex) { if (ex.origen !== 'manual') { ex.descuento = desc; ex.origen = orig } ex.cantidad = cantidadTotal; ex._auto = desc; ex._origenAuto = orig } else pedido.value.push({ product_id: p.id, descripcion: p.name, cantidad: cant, precio_unit: b.precio, descuento: desc, origen: orig, alicuota_iva: p.iva, _promo: p.promo, _auto: desc, _origenAuto: orig })
}
// La promo por cantidad se recalcula al cambiar la cantidad; lo que puso el vendedor a mano por encima de lo automático se respeta.
watch(pedido, v => v.forEach(i => { if (i._promo) { const b = base(porId.value[i.product_id] ?? {}); const auto = i.cantidad >= i._promo.desde && i._promo.pct > b.descuento ? { d: i._promo.pct, o: 'promo' } : { d: b.descuento, o: b.origen }; i._auto = auto.d; i._origenAuto = auto.o; if (i.origen !== 'manual') { i.descuento = auto.d; i.origen = auto.o } } }), { deep: true })
// Tope de descuento: lo automático (pactado, promo, lista) no cuenta; lo que el vendedor pone de más, por encima del tope, pide el OK del encargado.
const sobreTope = it => props.config.tope_descuento > 0 && (it.descuento ?? 0) > (it._auto ?? 0) + 0.005 && it.descuento > props.config.tope_descuento
const pctMaximo = computed(() => Math.max(0, ...pedido.value.filter(sobreTope).map(i => i.descuento)))
const pideOk = computed(() => pedido.value.some(sobreTope))
const ok = ref(null), okPedido = ref(null), okForm = reactive({ email: '', password: '', error: null, enviando: false })
const esperanOk = computed(() => cola.value.filter(c => c._pide_ok))
// Abre el pedido de OK para el pedido en pantalla o para una venta de la cola que el servidor devolvió por el tope.
function pedirOkPara(c) { okPedido.value = c ? { contact_id: c.contact_id, pct: c._pide_ok.pct, nombre: c._pide_ok.nombre, cola: c.offline_id } : { contact_id: cli.value.id, pct: pctMaximo.value, nombre: cli.value.nombre } }
async function pedirOk() {
  okForm.enviando = true; okForm.error = null
  try {
    const r = await post('/vender/autorizar', { contact_id: okPedido.value.contact_id, pct: okPedido.value.pct, email: okForm.email, password: okForm.password }); const j = await r.json().catch(() => ({}))
    if (!r.ok) { okForm.error = Object.values(j.errors ?? {}).flat()[0] ?? j.message ?? 'No se pudo.'; return }
    ok.value = { token: j.token, pct: j.pct, encargado: j.encargado, contact_id: okPedido.value.contact_id, vence: j.vence }
    const enCola = okPedido.value.cola ? cola.value.find(x => x.offline_id === okPedido.value.cola) : null
    okPedido.value = null; okForm.password = ''
    if (enCola) { enCola.items.forEach(i => { if (i.descuento > j.pct - 0.005 || i._sobre_tope) i.autorizado = j.token }); delete enCola._pide_ok; guardar(); sincronizar() }
    else confirmar(forzar.value ? true : false)
  } catch (e) { okForm.error = 'Sin señal.' } finally { okForm.enviando = false }
}
const totales = computed(() => { let neto = 0, iva = 0; pedido.value.forEach(i => { const n = Math.round(i.cantidad * i.precio_unit * (1 - i.descuento / 100) * 100) / 100; neto += n; iva += Math.round(n * i.alicuota_iva / 100 * 100) / 100 }); return { neto: Math.round(neto * 100) / 100, iva: Math.round(iva * 100) / 100, total: Math.round((neto + iva) * 100) / 100 } })
const avisoCredito = computed(() => { if (!cli.value || tipo.value === 'PRE') return null; const a = []; if (cli.value.limite > 0 && cli.value.saldo + totales.value.total > cli.value.limite) a.push('con este pedido se pasa del límite de crédito'); if (cli.value.vencido > 0 && cli.value.mora_dias > (props.config.dias_mora || 0)) a.push(`tiene ${moneda(cli.value.vencido, 0)} vencidos`); return a.length ? a.join(' y ') + '. Al confirmar te va a preguntar.' : null })
function repetirUltimo() { if (!ficha.value?.ultimo) return; let faltan = 0; ficha.value.ultimo.items.forEach(i => { const p = porId.value[i.product_id]; if (p) sumar(p, i.cantidad); else faltan++ }); if (faltan) error.value = `${faltan} artículo(s) del último pedido ya no están en el catálogo.` }
function cargarSugerido() { (ficha.value?.sugerido ?? []).forEach(s => { const p = porId.value[s.product_id]; if (p) sumar(p, s.cantidad) }) }
function ubicacion() { return new Promise(res => { if (!navigator.geolocation) return res({}); navigator.geolocation.getCurrentPosition(p => res({ lat: p.coords.latitude, lng: p.coords.longitude }), () => res({}), { timeout: 5000, maximumAge: 60000 }) }) }

// --- Cola: lo que se hizo sin señal (ventas y visitas) espera y se manda solo. Cada cosa lleva un número único: nunca entra dos veces ---
const cola = ref(leer('vender_cola', [])), rechazadas = ref(leer('vender_rechazadas', [])), sincronizando = ref(false)
const guardar = () => { escribir('vender_cola', cola.value); escribir('vender_rechazadas', rechazadas.value) }
const offlineId = () => 'off-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8)
async function sincronizar() {
  if (sincronizando.value || !cola.value.length || !navigator.onLine) return
  sincronizando.value = true
  for (const c of [...cola.value]) {
    try {
      const r = await post(c._url, c); const j = await r.json().catch(() => ({}))
      if (r.ok) { cola.value = cola.value.filter(x => x.offline_id !== c.offline_id); if (j.comprobante_id) resultado.value = { ...j, sync: true } }
      else if (r.status === 422 && j.errors?.descuento && !c._pide_ok) { c._pide_ok = { pct: Math.max(...c.items.map(i => i.descuento || 0)), nombre: c._que.replace(/^.* a /, '').replace(/ por .*$/, '') }; c.items.forEach(i => { if (i.autorizado) i.autorizado = null }) }
      else if (r.status === 422 && j.errors?.descuento) { /* sigue esperando el OK */ }
      else if (r.status === 422 || r.status === 403) { cola.value = cola.value.filter(x => x.offline_id !== c.offline_id); rechazadas.value.push({ ...c, _error: j.message ?? Object.values(j.errors ?? {}).flat()[0] ?? 'rechazada' }) }
      else break
      guardar()
    } catch (e) { break }
  }
  sincronizando.value = false
}
async function visita(c, tipoV) {
  const pos = await ubicacion()
  const datos = { tipo: tipoV, motivo: tipoV === 'no_vendi' ? motivo.value : null, nota: notaVisita.value || null, ...pos, cuando: new Date().toISOString(), offline_id: offlineId(), _url: `/vender/cliente/${c.id}/visita`, _que: `${tipoV === 'llegue' ? 'Llegué a' : 'No vendí a'} ${c.nombre}`, fecha_offline: new Date().toISOString() }
  motivo.value = null; notaVisita.value = ''
  cola.value.push(datos); guardar(); if (navigator.onLine) sincronizar(); else resultado.value = { offline: true }
  if (tipoV === 'no_vendi') hoyNoVa.value.push(c.id)
}
const noVendi = ref(null), motivo = ref(null), notaVisita = ref('')

// --- Avisos del encargado: los no urgentes se bajan de un toque y la app se acuerda ---
const avisos = ref(props.avisos ?? leer('vender_avisos', [])), bajados = ref(leer('vender_avisos_bajados', []))
escribir('vender_avisos', avisos.value)
const avisosVisibles = computed(() => avisos.value.filter(a => a.urgente || !bajados.value.includes(a.id)))
function bajarAviso(a) { bajados.value.push(a.id); escribir('vender_avisos_bajados', bajados.value) }

// --- Mi reporte ---
const reporte = ref(null), periodoReporte = ref('mes')
async function cargarReporte() { if (!navigator.onLine) return; try { const r = await fetch(`/vender/reporte?periodo=${periodoReporte.value}`, { headers: { Accept: 'application/json' } }); if (r.ok) reporte.value = await r.json() } catch (e) {} }
function irReporte() { if (pantalla.value === 'cliente' && pedido.value.length) escribir('vender_pendiente_' + cli.value.id, pedido.value); pantalla.value = 'reporte'; cargarReporte() }
function elegirDesdeReporte(c) { const todos = Object.values(ruta.value?.grupos ?? {}).flat(); const fila = todos.find(x => x.id === c.id) ?? { id: c.id, nombre: c.nombre, lista: 1, descuento: 0, saldo: 0, vencido: 0 }; elegir(fila) }

// --- Notas del cliente: la memoria del vínculo ---
const notaNueva = ref('')
async function anotar() {
  const texto = notaNueva.value.trim(); if (!texto) return
  const datos = { texto, offline_id: offlineId(), fecha_offline: new Date().toISOString(), _url: `/vender/cliente/${cli.value.id}/notas`, _que: `Nota de ${cli.value.nombre}` }
  notaNueva.value = ''
  if (!navigator.onLine) { cola.value.push(datos); guardar(); if (ficha.value) (ficha.value.notas ??= []).unshift({ id: 'local-' + Date.now(), texto, quien: 'vos', cuando: 'ahora' }); return }
  try { const r = await post(datos._url, datos); const j = await r.json().catch(() => ({})); if (r.ok && ficha.value) { ficha.value.notas = j.notas; escribir('vender_ficha_' + cli.value.id, ficha.value) } } catch (e) { cola.value.push(datos); guardar() }
}
async function notaLista(n) { try { const r = await post(`/vender/cliente/${cli.value.id}/notas/${n.id}/listo`, {}); const j = await r.json().catch(() => ({})); if (r.ok) ficha.value.notas = j.notas } catch (e) { error.value = 'Para marcar una nota como lista hace falta señal.' } }

// --- Relevar con fotos ---
const relevar = ref(null)
function abrirRelevar() { relevar.value = { tipo: 'gondola', foto: '', nota: '', error: null, enviando: false } }
function fotoRelevar(e) { const f = e.target.files?.[0]; if (!f) return; const img = new Image(); img.onload = () => { const k = Math.min(1, 1024 / Math.max(img.width, img.height)); const cv = document.createElement('canvas'); cv.width = Math.round(img.width * k); cv.height = Math.round(img.height * k); cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height); relevar.value.foto = cv.toDataURL('image/jpeg', 0.75); URL.revokeObjectURL(img.src) }; img.src = URL.createObjectURL(f) }
async function confirmarRelevar() {
  const rv = relevar.value; rv.enviando = true; rv.error = null
  const pos = await ubicacion()
  try { const r = await post(`/vender/cliente/${cli.value.id}/relevar`, { tipo: rv.tipo, nota: rv.nota || null, foto: rv.foto, ...pos }); const j = await r.json().catch(() => ({})); if (r.ok) { relevar.value = null; resultado.value = { relevado: true }; return } rv.error = j.message ?? Object.values(j.errors ?? {}).flat()[0] ?? 'No se pudo.' } catch (e) { rv.error = 'Sin señal: el relevamiento necesita conexión.' } finally { rv.enviando = false }
}

// --- Hoy: lo hecho, lo cobrado en mano y rendir ---
const hoy = ref(leer('vender_hoy', null)), rindiendo = ref(false)
async function cargarHoy() { if (!navigator.onLine) return; try { const r = await fetch('/vender/hoy', { headers: { Accept: 'application/json' } }); if (r.ok) { hoy.value = await r.json(); escribir('vender_hoy', hoy.value) } } catch (e) {} }
function irHoy() { if (pantalla.value === 'cliente' && pedido.value.length) escribir('vender_pendiente_' + cli.value.id, pedido.value); pantalla.value = 'hoy'; cargarHoy() }
async function rendir() {
  if (!confirm('¿Cerrás el turno y rendís todo lo cobrado? Después lo recibe el encargado.')) return
  rindiendo.value = true
  try { const r = await post('/vender/rendir', {}); const j = await r.json().catch(() => ({})); if (r.ok) { resultado.value = { rendicion: j }; await cargarHoy() } else error.value = Object.values(j.errors ?? {}).flat()[0] ?? j.message } catch (e) {} finally { rindiendo.value = false }
}

// --- Cobrar: recibo real (baja la deuda) con la plata a nombre del vendedor hasta rendir ---
const cobro = ref(null)
const medioVacio = () => ({ medio: 'efectivo', monto: null, referencia: '', datos: { numero: '', banco: '', fecha_pago: hoyISO(), titular: '', echeq: false } })
function abrirCobro() { cobro.value = { medios: [medioVacio()], firma: '', firma_nombre: '', forzar_cheque: false, chequeRepetido: false, error: null, enviando: false } }
const totalCobro = computed(() => Math.round((cobro.value?.medios ?? []).reduce((a, m) => a + (Number(m.monto) || 0), 0) * 100) / 100)
async function confirmarCobro() {
  const c = cobro.value; c.enviando = true; c.error = null
  const pos = await ubicacion()
  const datos = { medios: c.medios.filter(m => Number(m.monto) > 0).map(m => ({ medio: m.medio, monto: Number(m.monto), referencia: m.referencia || null, datos: m.medio === 'cheque' ? m.datos : null })), firma: c.firma || null, firma_nombre: c.firma_nombre || null, forzar_cheque: c.forzar_cheque, ...pos, offline_id: offlineId(), fecha_offline: new Date().toISOString(), _url: `/vender/cliente/${cli.value.id}/cobrar`, _que: `Cobro a ${cli.value.nombre} por ${moneda(totalCobro.value)}` }
  if (!navigator.onLine) { cola.value.push(datos); guardar(); resultado.value = { offline: true }; cli.value.saldo -= totalCobro.value; cobro.value = null; return }
  try {
    const r = await post(datos._url, datos); const j = await r.json().catch(() => ({}))
    if (r.ok) { resultado.value = { cobro: j }; cli.value.saldo = j.saldo; cobro.value = null; cargarHoy(); return }
    if (r.status === 422 && j.errors?.cheque_repetido) { c.chequeRepetido = true; c.error = j.errors.cheque_repetido[0]; c.enviando = false; return }
    c.error = j.message ?? Object.values(j.errors ?? {}).flat()[0] ?? 'No se pudo.'; c.enviando = false
  } catch (e) { cola.value.push(datos); guardar(); resultado.value = { offline: true }; cobro.value = null }
}

// --- Devolución: nota de crédito con motivo ---
const devolucion = ref(null)
function abrirDevolucion() { devolucion.value = { q: '', cant: {}, motivo: null, nota: '', error: null, enviando: false } }
const listaDevolucion = computed(() => { if (!devolucion.value) return []; const t = norm(devolucion.value.q); if (t) return productos.value.filter(p => norm(p.name).includes(t) || norm(p.sku).includes(t)).slice(0, 30); const ids = (ficha.value?.suele_llevar ?? []).map(h => h.product_id); const base = ids.map(id => porId.value[id]).filter(Boolean); return base.length ? base : productos.value.slice(0, 30) })
const itemsDevolucion = computed(() => Object.entries(devolucion.value?.cant ?? {}).filter(([, c]) => Number(c) > 0).map(([id, c]) => ({ product_id: Number(id), cantidad: Number(c) })))
async function confirmarDevolucion() {
  const d = devolucion.value; d.enviando = true; d.error = null
  const pos = await ubicacion()
  const datos = { items: itemsDevolucion.value, motivo: d.motivo, nota: d.nota || null, ...pos, offline_id: offlineId(), fecha_offline: new Date().toISOString(), _url: `/vender/cliente/${cli.value.id}/devolucion`, _que: `Devolución de ${cli.value.nombre}` }
  if (!navigator.onLine) { cola.value.push(datos); guardar(); resultado.value = { offline: true }; devolucion.value = null; return }
  try {
    const r = await post(datos._url, datos); const j = await r.json().catch(() => ({}))
    if (r.ok) { resultado.value = { ...j, es_nc: true }; devolucion.value = null; cargarHoy(); return }
    d.error = j.message ?? Object.values(j.errors ?? {}).flat()[0] ?? 'No se pudo.'; d.enviando = false
  } catch (e) { cola.value.push(datos); guardar(); resultado.value = { offline: true }; devolucion.value = null }
}

// --- Confirmación de entrega: nombre (obligatorio), firma y foto ---
const entrega = ref(null)
function abrirEntrega(v) { entrega.value = { id: v.id, tipo: v.tipo, numero: v.numero, nombre: '', firma: '', foto: '', error: null, enviando: false } }
function fotoEntrega(e) {
  const f = e.target.files?.[0]; if (!f) return
  // Se achica en el teléfono para que viaje liviana (y sin señal).
  const img = new Image(); img.onload = () => { const k = Math.min(1, 1280 / Math.max(img.width, img.height)); const cv = document.createElement('canvas'); cv.width = Math.round(img.width * k); cv.height = Math.round(img.height * k); cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height); entrega.value.foto = cv.toDataURL('image/jpeg', 0.8); URL.revokeObjectURL(img.src) }
  img.src = URL.createObjectURL(f)
}
async function confirmarEntrega() {
  const en = entrega.value; en.enviando = true; en.error = null
  const datos = { nombre: en.nombre, firma: en.firma || null, foto: en.foto || null, cuando: new Date().toISOString(), offline_id: offlineId(), fecha_offline: new Date().toISOString(), _url: `/vender/comprobante/${en.id}/entrega`, _que: `Entrega de ${en.tipo} ${en.numero} (recibió ${en.nombre})` }
  if (!navigator.onLine) { cola.value.push(datos); guardar(); resultado.value = { offline: true }; entrega.value = null; return }
  try {
    const r = await post(datos._url, datos); const j = await r.json().catch(() => ({}))
    if (r.ok) { if (resultado.value?.comprobante_id === en.id) resultado.value.entregado = true; entrega.value = null; cargarHoy(); return }
    en.error = j.message ?? Object.values(j.errors ?? {}).flat()[0] ?? 'No se pudo.'; en.enviando = false
  } catch (e) { cola.value.push(datos); guardar(); resultado.value = { offline: true }; entrega.value = null }
}
async function confirmar(vendoIgual = false) {
  if (enviando.value || !pedido.value.length) return
  // Más del tope: con señal, primero el OK del encargado; sin señal, queda en la cola y lo pide al mandar.
  const tok = ok.value && ok.value.contact_id === cli.value.id && ok.value.pct + 0.005 >= pctMaximo.value && new Date(ok.value.vence) > new Date() ? ok.value.token : null
  if (pideOk.value && !tok && navigator.onLine) { pedirOkPara(null); return }
  enviando.value = true; error.value = null; forzar.value = null
  const pos = await ubicacion()
  const datos = { modo: 'calle', tipo: tipo.value, contact_id: cli.value.id, notas: notas.value || null, forzar: vendoIgual, items: pedido.value.map(i => ({ product_id: i.product_id, descripcion: i.descripcion, cantidad: i.cantidad, precio_unit: i.precio_unit, descuento: i.descuento, autorizado: sobreTope(i) ? tok : null, _sobre_tope: sobreTope(i) })), ...pos, offline_id: offlineId(), fecha_offline: new Date().toISOString(), _url: '/vender/vender', _que: `${tipo.value === 'PRE' ? 'Presupuesto' : tipo.value === 'REM' ? 'Remito' : 'Factura'} a ${cli.value.nombre} por ${moneda(totales.value.total)}` }
  const limpiar = () => { pedido.value = []; notas.value = ''; localStorage.removeItem('vender_pendiente_' + cli.value.id); enviando.value = false }
  if (!navigator.onLine) { if (pideOk.value && !tok) datos._pide_ok = { pct: pctMaximo.value, nombre: cli.value.nombre }; cola.value.push(datos); guardar(); resultado.value = { offline: true }; limpiar(); return }
  try {
    const r = await post(datos._url, datos); const j = await r.json().catch(() => ({}))
    if (r.ok) { resultado.value = j; if (j.aviso) resultado.value.aviso = j.aviso; ok.value = null; limpiar(); if (cli.value) cli.value.saldo += tipo.value === 'FX' ? totales.value.total : 0; return }
    if (r.status === 422 && j.errors?.credito) { forzar.value = j.errors.credito[0]; enviando.value = false; return }
    if (r.status === 422 && j.errors?.descuento) { ok.value = null; enviando.value = false; pedirOkPara(null); return }
    error.value = j.message ?? Object.values(j.errors ?? {}).flat()[0] ?? 'No se pudo.'; enviando.value = false
  } catch (e) { cola.value.push(datos); guardar(); resultado.value = { offline: true }; limpiar() }
}
const onOnline = () => { online.value = true; sincronizar(); descargar() }, onOffline = () => (online.value = false)
onMounted(() => { window.addEventListener('online', onOnline); window.addEventListener('offline', onOffline); sincronizar(); if (navigator.onLine) { descargar(); cargarHoy() } })
onBeforeUnmount(() => { window.removeEventListener('online', onOnline); window.removeEventListener('offline', onOffline) })
</script>
