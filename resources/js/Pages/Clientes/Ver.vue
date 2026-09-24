<template>
  <AppLayout :titulo="cliente.name">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div>
        <Link href="/clientes" class="text-xs text-marca-muted hover:text-carmin">← Clientes</Link>
        <h1 class="page-title">{{ cliente.name }}</h1>
        <p class="page-subtitle">{{ cliente.condicion_iva }} · {{ cliente.cuit ?? 'sin CUIT' }} <span v-if="cliente.tipo">· {{ cliente.tipo }}</span> <span v-if="cliente.city">· {{ cliente.city }}</span></p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button @click="editarAbierto = true" class="btn-secondary"><Icono nombre="edit" clase="w-4 h-4" /> Editar</button>
        <Link :href="`/comprobantes/nuevo?tipo=PRE&contact_id=${cliente.id}`" class="btn-secondary">Presupuesto</Link>
        <Link :href="`/comprobantes/nuevo?contact_id=${cliente.id}`" class="btn-secondary">Factura</Link>
        <button @click="enviarDoc('Contact', cliente.id)" class="btn-secondary">Enviar resumen</button>
        <button @click="portalAbierto = true" class="btn-secondary" title="Link para que el cliente vea su cuenta, pague y pida">Portal del cliente</button>
        <button @click="cobroAbierto = true" class="btn-primary">Registrar cobro</button>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-3 mb-5" :class="cliente.fidelizacion_activa ? 'lg:grid-cols-6' : 'lg:grid-cols-5'">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Saldo</p><p class="text-xl font-extrabold tabular-nums" :class="cliente.balance > 0 ? 'text-carmin' : cliente.balance < 0 ? 'text-emerald-700' : ''">{{ moneda(cliente.balance, 0) }}</p><p v-if="cliente.balance < 0" class="text-[11px] text-marca-muted">a favor del cliente</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vencido</p><p class="text-xl font-extrabold tabular-nums" :class="cliente.deuda_vencida > 0 ? 'text-carmin' : ''">{{ moneda(cliente.deuda_vencida, 0) }}</p></div>
      <div v-if="cliente.fidelizacion_activa" class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Puntos</p><p class="text-xl font-extrabold tabular-nums text-violeta">★ {{ cliente.puntos }}</p><p class="text-[11px] text-marca-muted">valen {{ moneda(cliente.puntos_pesos, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Límite</p><p class="text-xl font-extrabold tabular-nums">{{ cliente.credit_limit > 0 ? moneda(cliente.credit_limit, 0) : '∞' }}</p><div v-if="cliente.credit_limit > 0" class="h-1.5 rounded-full bg-gris-light mt-1 overflow-hidden"><div class="h-full" :class="cliente.balance / cliente.credit_limit > 0.9 ? 'bg-carmin' : 'bg-violeta'" :style="{ width: `${Math.min(100, Math.max(0, cliente.balance / cliente.credit_limit * 100))}%` }"></div></div></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Condiciones</p><p class="text-sm font-semibold">Lista {{ cliente.lista_precios }} · {{ cliente.dias_pago }} días</p><p class="text-[11px] text-marca-muted">{{ cliente.descuento }}% dto.<span v-if="cliente.recordar_precio"> · recuerda precio</span><span v-if="cliente.percepcion_iibb"> · perc. IIBB</span></p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Antigüedad de deuda</p>
        <div class="flex gap-0.5 h-3 rounded-full overflow-hidden bg-gris-light mt-2" :title="`Al día ${moneda(antiguedad.al_dia,0)} · 1-30 ${moneda(antiguedad.v30,0)} · 31-60 ${moneda(antiguedad.v60,0)} · 61-90 ${moneda(antiguedad.v90,0)} · +90 ${moneda(antiguedad.mas90,0)}`">
          <div v-for="(k, i) in ['al_dia','v30','v60','v90','mas90']" :key="k" :style="{ width: `${pct(antiguedad[k])}%`, background: ['#1f9d5b','#c5bcdd','#a42785','#e4003f','#7a0020'][i] }"></div>
        </div>
        <p class="text-[10px] text-marca-muted mt-1">verde al día · rojo +60 días</p>
      </div>
    </div>

    <div class="flex gap-1 overflow-x-auto mb-4 bg-white border border-marca-borde rounded-full p-1 w-fit max-w-full">
      <button v-for="t in tabs" :key="t.key" @click="tab = t.key" class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap" :class="tab === t.key ? 'bg-carmin text-white' : 'text-marca-muted hover:text-marca-texto'">{{ t.label }}<span v-if="t.n" class="ml-1 opacity-70">({{ t.n }})</span></button>
    </div>

    <!-- Cuenta corriente -->
    <div v-if="tab === 'cc'" class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Fecha</th><th>Concepto</th><th>Vence</th><th class="text-right">Debe</th><th class="text-right">Haber</th><th class="text-right">Saldo</th></tr></thead>
        <tbody>
          <tr v-for="m in cc" :key="m.id" class="cursor-pointer" @click="abrirMov(m)">
            <td class="tabular-nums text-marca-muted">{{ m.fecha }}</td>
            <td class="font-medium">{{ m.concepto }}</td>
            <td class="tabular-nums text-marca-muted">{{ m.fecha_vto }}</td>
            <td class="text-right tabular-nums">{{ m.debe ? moneda(m.debe) : '' }}</td>
            <td class="text-right tabular-nums text-emerald-700">{{ m.haber ? moneda(m.haber) : '' }}</td>
            <td class="text-right tabular-nums font-semibold" :class="m.saldo > 0 ? 'text-carmin' : ''">{{ moneda(m.saldo) }}</td>
          </tr>
          <tr v-if="!cc.length"><td colspan="6" class="text-center text-marca-muted py-10">Sin movimientos todavía.</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Pendientes -->
    <div v-if="tab === 'pendientes'" class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Comprobante</th><th>Fecha</th><th>Vence</th><th class="text-right">Total</th><th class="text-right">Saldo</th></tr></thead>
        <tbody>
          <tr v-for="p in pendientes" :key="p.id" class="cursor-pointer" @click="$inertia.visit(`/comprobantes/${p.id}`)">
            <td class="font-semibold">{{ p.nombre }} <span class="tabular-nums">{{ p.numero }}</span></td><td class="text-marca-muted">{{ p.fecha }}</td>
            <td class="tabular-nums" :class="p.vencido ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ p.fecha_vto }} <span v-if="p.vencido" class="badge bg-carmin-light text-carmin ml-1">Vencido</span></td>
            <td class="text-right tabular-nums">{{ moneda(p.total) }}</td><td class="text-right tabular-nums font-semibold text-carmin">{{ moneda(p.saldo) }}</td>
          </tr>
          <tr v-if="!pendientes.length"><td colspan="5" class="text-center text-marca-muted py-10">No debe nada. 🙂</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Comprobantes -->
    <div v-if="tab === 'comprobantes'" class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Comprobante</th><th>Fecha</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="x in comprobantes" :key="x.id" class="cursor-pointer" @click="$inertia.visit(`/comprobantes/${x.id}`)">
            <td class="font-semibold">{{ x.nombre }} <span class="tabular-nums">{{ x.numero ?? '(borrador)' }}</span> <span v-if="x.es_acopio" class="badge bg-violeta-light text-violeta ml-1">Acopio</span></td>
            <td class="text-marca-muted">{{ x.fecha }}</td><td class="text-right tabular-nums">{{ moneda(x.total) }}</td><td class="text-right tabular-nums" :class="x.saldo > 0 ? 'text-carmin' : 'text-marca-muted'">{{ x.estado_cobro !== 'na' ? moneda(x.saldo) : '' }}</td>
            <td><span class="badge" :class="estadoComprobante[x.estado].clase">{{ estadoComprobante[x.estado].label }}</span> <span v-if="x.estado === 'emitido' && x.estado_cobro !== 'na'" class="badge" :class="estadoCobro[x.estado_cobro].clase">{{ estadoCobro[x.estado_cobro].label }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Cobros -->
    <div v-if="tab === 'cobros'" class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Recibo</th><th>Fecha</th><th>Medios</th><th class="text-right">Total</th><th class="text-right">A cuenta</th><th></th></tr></thead>
        <tbody>
          <tr v-for="k in cobros" :key="k.id" :class="{ 'opacity-50 line-through': k.estado === 'anulado' }">
            <td class="font-semibold tabular-nums">{{ k.numero }}</td><td class="text-marca-muted">{{ k.fecha }}</td><td class="text-xs text-marca-muted">{{ k.medios }}</td>
            <td class="text-right tabular-nums font-semibold">{{ moneda(k.total) }}</td><td class="text-right tabular-nums text-marca-muted">{{ k.a_cuenta ? moneda(k.a_cuenta) : '' }}</td>
            <td class="text-right whitespace-nowrap"><a :href="`/clientes/cobros/${k.id}/imprimir`" target="_blank" class="btn-ghost !px-2 text-xs">Recibo</a><button v-if="k.estado !== 'anulado' && k.a_cuenta > 0 && pendientes.length" @click="abrirAplicar(k)" class="btn-ghost !px-2 text-xs text-violeta font-semibold">Aplicar a facturas</button><button v-if="k.estado !== 'anulado'" @click="enviarDoc('Cobro', k.id)" class="btn-ghost !px-2 text-xs">Enviar</button><button v-if="k.estado !== 'anulado'" @click="anularCobro(k)" class="btn-ghost !px-2 text-xs text-carmin">Anular</button></td>
          </tr>
          <tr v-if="!cobros.length"><td colspan="6" class="text-center text-marca-muted py-10">Sin cobros registrados.</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="!!aplicarDe" :titulo="`Aplicar ${aplicarDe?.numero ?? ''} a facturas`" @cerrar="aplicarDe = null">
      <p class="text-sm text-marca-muted mb-3">El recibo tiene <b class="tabular-nums">{{ moneda(aplicarDe?.a_cuenta ?? 0) }}</b> a cuenta. Repartilo entre las facturas pendientes.</p>
      <div class="max-h-64 overflow-y-auto border border-marca-borde rounded-xl divide-y divide-marca-borde/60">
        <div v-for="p in pendientes" :key="p.id" class="flex items-center gap-2 px-3 py-2 text-sm">
          <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ p.nombre }} {{ p.numero }} <span v-if="p.saldo_usd" class="badge bg-violeta-light text-violeta !py-0">USD</span></p><p class="text-xs text-marca-muted">vence {{ p.fecha_vto }} · <template v-if="p.saldo_usd">saldo USD {{ p.saldo_usd }} · hoy {{ moneda(p.saldo_usd * (Number(aplicar.cotizacion) || cotizacionUsd || 0)) }}</template><template v-else>saldo {{ moneda(p.saldo) }}</template></p></div>
          <input v-model.number="imputAplicar[p.id]" type="number" step="any" min="0" class="input w-28 text-right !py-1" placeholder="0" />
        </div>
      </div>
      <div v-if="pendientes.some(p => p.saldo_usd)" class="mt-2 flex items-center gap-2 text-xs"><span class="text-marca-muted">Cotización (USD)</span><input v-model.number="aplicar.cotizacion" type="number" step="any" min="0" class="input !py-1 !w-28 text-right tabular-nums" /></div>
      <div class="mt-3 text-sm flex justify-between bg-marca-fondo rounded-xl p-3"><span>Imputado</span><b class="tabular-nums" :class="totalAplicar > (aplicarDe?.a_cuenta ?? 0) + 0.005 ? 'text-carmin' : ''">{{ moneda(totalAplicar) }}</b></div>
      <p v-if="aplicar.errors.imputaciones" class="text-carmin text-xs mt-2">{{ aplicar.errors.imputaciones }}</p>
      <template #pie><button class="btn-secondary" @click="aplicarDe = null">Cancelar</button><button class="btn-primary" :disabled="aplicar.processing || totalAplicar <= 0 || totalAplicar > (aplicarDe?.a_cuenta ?? 0) + 0.005" @click="enviarAplicar">Aplicar</button></template>
    </Modal>

    <!-- Acopios -->
    <div v-if="tab === 'acopios'" class="grid md:grid-cols-2 gap-4">
      <div v-for="a in acopios" :key="a.id" class="card">
        <div class="flex items-center justify-between mb-2">
          <div><p class="font-bold">Acopio de <Link :href="`/comprobantes/${a.comprobante_id}`" class="text-carmin">{{ a.factura }}</Link></p><p class="text-xs text-marca-muted">{{ a.fecha }} · límite {{ a.fecha_limite }}</p></div>
          <span class="badge" :class="{ abierto: 'bg-violeta-light text-violeta', parcial: 'bg-amber-50 text-amber-700', vencido: 'bg-carmin-light text-carmin', cerrado: 'bg-emerald-50 text-emerald-700' }[a.estado]">{{ a.estado }}</span>
        </div>
        <div class="space-y-2">
          <div v-for="i in a.items" :key="i.id">
            <div class="flex justify-between text-sm"><span>{{ i.descripcion }}</span><span class="tabular-nums">quedan <b>{{ cantidad(i.pendiente) }}</b> de {{ cantidad(i.facturada) }}</span></div>
            <div class="h-2 rounded-full bg-gris-light overflow-hidden mt-1"><div class="h-full bg-violeta-grad" :style="{ width: `${Math.min(100, i.retirada / i.facturada * 100)}%` }"></div></div>
          </div>
        </div>
        <button @click="abrirRetiro(a)" class="btn-violeta mt-4 w-full" :disabled="a.estado === 'cerrado'">Registrar retiro</button>
      </div>
      <p v-if="!acopios.length" class="card text-center text-marca-muted py-10 md:col-span-2">Sin acopios abiertos. Se crean al facturar con la opción "Es acopio".</p>
    </div>

    <!-- Precios pactados (Fase 25.1) -->
    <CondicionesCliente v-if="tab === 'precios'" :cliente-id="cliente.id" :lista="cliente.lista_precios" :descuento="Number(cliente.descuento)" :rubros="rubros" />

    <!-- Datos -->
    <div v-if="tab === 'datos'" class="card grid sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
      <div><p class="label">Email</p>{{ cliente.email ?? '—' }}</div><div><p class="label">Teléfono</p>{{ cliente.phone ?? cliente.mobile ?? '—' }}</div>
      <div class="sm:col-span-2"><p class="label">Dirección</p>{{ [cliente.address, cliente.city, cliente.province, cliente.postal_code].filter(Boolean).join(', ') || '—' }}</div>
      <div class="sm:col-span-2"><p class="label">Notas</p><span class="whitespace-pre-line">{{ cliente.notes ?? '—' }}</span></div>
    </div>

    <EnviarModal :abierto="!!envio" :modelo="envio?.modelo" :id="envio?.id" titulo="Enviar al cliente" @cerrar="envio = null" />
    <ClienteModal :abierto="editarAbierto" :cliente="cliente" :tipos="tipos" :condicionesIva="condicionesIva" :vendedores="vendedores" :paises="paises" :cuitPais="cuitPais" @cerrar="editarAbierto = false" />

    <!-- Cobro -->
    <Modal :abierto="cobroAbierto" titulo="Registrar cobro" ancho="max-w-3xl" @cerrar="cobroAbierto = false">
      <div class="grid md:grid-cols-2 gap-5">
        <div>
          <p class="label">Medios de pago</p>
          <div v-for="(m, i) in cobro.medios" :key="i" class="rounded-xl border border-marca-borde p-2 mb-2 space-y-1.5">
            <div class="grid grid-cols-[1fr_110px_28px] gap-2">
              <select v-model="m.medio" class="input" @change="cambiarMedio(m)"><option v-for="(lbl, k) in medios" :key="k" :value="k">{{ lbl }}</option></select>
              <input v-model.number="m.monto" type="number" step="any" min="0" class="input text-right" placeholder="0" />
              <button @click="cobro.medios.splice(i,1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
            </div>
            <select v-if="!['cheque','retencion'].includes(m.medio)" v-model="m.cuenta_fondos_id" class="input !py-1 text-xs">
              <option :value="null">Entra en: la cuenta predeterminada</option><option v-for="c in cuentasPara(m.medio)" :key="c.id" :value="c.id">{{ c.nombre }} · {{ c.moneda === 'USD' ? 'USD ' + Number(c.saldo).toLocaleString('es-AR') : moneda(c.saldo, 0) }}</option>
            </select>
            <div v-if="['efectivo','transferencia'].includes(m.medio) && cuentas.some(c => c.moneda === 'USD')" class="flex items-center gap-2 text-xs">
              <label class="flex items-center gap-1"><input type="checkbox" class="accent-carmin" :checked="m.moneda === 'USD'" @change="m.moneda = $event.target.checked ? 'USD' : 'ARS'; if (m.moneda === 'USD') { m.cotizacion = m.cotizacion || cotizacionUsd; m.cuenta_fondos_id = cuentas.find(c => c.moneda === 'USD')?.id ?? m.cuenta_fondos_id }" /> En dólares</label>
              <template v-if="m.moneda === 'USD'"><span class="text-marca-muted">cotización</span><input v-model.number="m.cotizacion" type="number" step="any" class="input !py-0.5 !w-24 text-xs" /><span class="text-marca-muted">= {{ moneda((m.monto || 0) * (m.cotizacion || 0), 0) }}</span></template>
            </div>
            <div v-if="m.medio === 'tarjeta'" class="grid grid-cols-3 gap-2">
              <select v-model="m.datos.tarjeta" class="input !py-1 text-xs"><option v-for="t in ['Visa','Mastercard','American Express','Cabal','Naranja','Maestro','Visa Débito','Mastercard Débito','Otra']" :key="t">{{ t }}</option></select>
              <input v-model="m.datos.numero" class="input !py-1 text-xs" placeholder="N° cupón" /><input v-model.number="m.datos.cuotas" type="number" min="1" class="input !py-1 text-xs" placeholder="Cuotas" />
            </div>
            <div v-if="m.medio === 'cheque'" class="grid grid-cols-2 gap-2">
              <input v-model="m.datos.numero" class="input !py-1 text-xs" placeholder="N° cheque" /><input v-model="m.datos.banco" class="input !py-1 text-xs" placeholder="Banco" />
              <div><label class="text-[10px] text-marca-muted">Fecha de pago</label><input v-model="m.datos.fecha_pago" type="date" class="input !py-1 text-xs" /></div>
              <div><label class="text-[10px] text-marca-muted">Emisor (si no es el cliente)</label><input v-model="m.datos.emisor" class="input !py-1 text-xs" :placeholder="cliente.name" /></div>
              <label class="flex items-center gap-1.5 text-xs col-span-2"><input v-model="m.datos.echeq" type="checkbox" class="accent-carmin" /> Es eCheq</label>
            </div>
            <input v-if="!['efectivo','cheque'].includes(m.medio)" v-model="m.referencia" class="input !py-1 text-xs" placeholder="Referencia / N° operación" />
          </div>
          <button @click="cobro.medios.push({ medio: 'transferencia', monto: 0, referencia: '', cuenta_fondos_id: null, datos: {}, moneda: 'ARS', cotizacion: null })" class="btn-ghost !px-2 text-xs">+ Otro medio</button>
          <div class="mt-3 grid grid-cols-2 gap-2">
            <div><label class="label">Descuento otorgado</label><input v-model.number="cobro.descuento" type="number" step="any" min="0" class="input" placeholder="0" /><p class="text-[10px] text-marca-muted mt-0.5">Cancela deuda sin cobrarse.</p></div>
            <div><label class="label">Interés cobrado</label><input v-model.number="cobro.interes" type="number" step="any" min="0" class="input" placeholder="0" /><p v-if="cliente.interes_calculado > 0" class="text-[10px] text-amber-700 mt-0.5 cursor-pointer" @click="cobro.interes = cliente.interes_calculado">Mora sugerida: {{ moneda(cliente.interes_calculado) }} ({{ cliente.interes_mora }}% mensual)</p><p v-else class="text-[10px] text-marca-muted mt-0.5">Se cobra además de la deuda.</p></div>
          </div>
          <div class="mt-2 grid grid-cols-2 gap-2"><div><label class="label">Fecha</label><input v-model="cobro.fecha" type="date" class="input" /></div><div><label class="label">Notas</label><input v-model="cobro.notas" class="input" /></div></div>
          <div class="mt-2"><label class="label">Vendedor (cobranza)</label><select v-model="cobro.vendedor_id" class="input !py-1 text-xs"><option :value="null">{{ cliente.vendedor ? 'El del cliente: ' + cliente.vendedor : 'Sin vendedor' }}</option><option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.nombre }}</option></select></div>
          <p v-if="cobro.errors.interes" class="text-carmin text-xs mt-2">{{ cobro.errors.interes }}</p>
          <p v-if="cobro.errors.medios" class="text-carmin text-xs mt-2">{{ cobro.errors.medios }}</p>
        </div>
        <div>
          <div class="flex items-center justify-between"><p class="label">Aplicar a</p><button @click="autoImputar" class="text-xs text-violeta font-semibold">Aplicar automáticamente (más viejo primero)</button></div>
          <div class="max-h-64 overflow-y-auto border border-marca-borde rounded-xl divide-y divide-marca-borde/60">
            <div v-for="p in pendientes" :key="p.id" class="flex items-center gap-2 px-3 py-2 text-sm">
              <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ p.nombre }} {{ p.numero }} <span v-if="p.saldo_usd" class="badge bg-violeta-light text-violeta !py-0">USD</span></p><p class="text-xs text-marca-muted">vence {{ p.fecha_vto }} · <template v-if="p.saldo_usd">saldo USD {{ p.saldo_usd }} · hoy {{ moneda(p.saldo_usd * (Number(cobro.cotizacion) || cotizacionUsd || 0)) }}</template><template v-else>saldo {{ moneda(p.saldo) }}</template></p></div>
              <input v-model.number="imput[p.id]" type="number" step="any" min="0" :max="p.saldo_usd ? Math.round(p.saldo_usd * (Number(cobro.cotizacion) || cotizacionUsd || 0) * 100) / 100 : p.saldo" class="input w-28 text-right !py-1" placeholder="0" />
            </div>
            <p v-if="!pendientes.length" class="px-3 py-4 text-sm text-marca-muted">No hay comprobantes pendientes: el cobro queda a cuenta.</p>
          </div>
          <p class="text-[10px] text-marca-muted mt-1">Podés dejar todo sin aplicar: el recibo queda a cuenta y se imputa a las facturas después desde el listado de cobros.</p>
          <div v-if="pendientes.some(p => p.saldo_usd)" class="mt-2 flex items-center gap-2 text-xs"><span class="text-marca-muted">Cotización del recibo (USD)</span><input v-model.number="cobro.cotizacion" type="number" step="any" min="0" class="input !py-1 !w-28 text-right tabular-nums" /><span class="text-marca-muted">la diferencia con la de la factura se registra como diferencia de cambio</span></div>
          <div class="mt-3 text-sm space-y-1 bg-marca-fondo rounded-xl p-3">
            <div class="flex justify-between"><span>Total cobrado</span><b class="tabular-nums">{{ moneda(totalCobro) }}</b></div>
            <div v-if="cobro.descuento > 0" class="flex justify-between text-emerald-700"><span>+ descuento</span><span class="tabular-nums">{{ moneda(cobro.descuento) }}</span></div>
            <div v-if="cobro.interes > 0" class="flex justify-between text-amber-700"><span>− interés</span><span class="tabular-nums">{{ moneda(cobro.interes) }}</span></div>
            <div class="flex justify-between font-semibold"><span>Cancela deuda</span><span class="tabular-nums">{{ moneda(cancela) }}</span></div>
            <div class="flex justify-between"><span>Imputado</span><span class="tabular-nums">{{ moneda(totalImputado) }}</span></div>
            <div class="flex justify-between" :class="cancela - totalImputado < -0.005 ? 'text-carmin' : ''"><span>A cuenta</span><span class="tabular-nums">{{ moneda(cancela - totalImputado) }}</span></div>
          </div>
          <p v-if="cobro.errors.imputaciones" class="text-carmin text-xs mt-2">{{ cobro.errors.imputaciones }}</p>
        </div>
      </div>
      <template #pie>
        <button class="btn-secondary" @click="cobroAbierto = false">Cancelar</button>
        <button class="btn-primary" :disabled="cobro.processing || totalCobro <= 0 || totalImputado > cancela + 0.005" @click="registrarCobro">Registrar {{ moneda(totalCobro) }}</button>
      </template>
    </Modal>

    <!-- Retiro de acopio -->
    <Modal :abierto="!!retiroDe" titulo="Registrar retiro de acopio" @cerrar="retiroDe = null">
      <p class="text-sm text-marca-muted mb-3">Indicá cuánto se lleva de cada ítem. Se genera un remito y se descuenta del stock; el precio ya está pago.</p>
      <div class="space-y-2">
        <div v-for="it in retiro.items" :key="it.acopio_item_id" class="flex items-center gap-3 text-sm">
          <span class="flex-1">{{ it.descripcion }} <span class="text-marca-muted">(quedan {{ cantidad(it.pendiente) }})</span></span>
          <input v-model.number="it.cantidad" type="number" step="any" min="0" :max="it.pendiente" class="input w-28 text-right" />
        </div>
      </div>
      <div class="grid grid-cols-2 gap-2 mt-4"><div><label class="label">Fecha</label><input v-model="retiro.fecha" type="date" class="input" /></div><div><label class="label">Retira</label><input v-model="retiro.retirado_por" class="input" placeholder="Nombre / patente" /></div></div>
      <p v-if="retiro.errors.items" class="text-carmin text-xs mt-2">{{ retiro.errors.items }}</p>
      <template #pie>
        <button class="btn-secondary" @click="retiroDe = null">Cancelar</button>
        <button class="btn-violeta" :disabled="retiro.processing" @click="retiro.post(`/clientes/acopios/${retiroDe.id}/retiros`, { preserveScroll: true, onSuccess: () => (retiroDe = null) })">Registrar retiro</button>
      </template>
    </Modal>
    <Modal :abierto="portalAbierto" titulo="Portal del cliente" @cerrar="portalAbierto = false">
      <p class="text-sm mb-2">Con este link el cliente ve su cuenta corriente, descarga sus facturas, paga online y hace pedidos con sus precios. No necesita usuario ni contraseña: el link es su acceso, no lo publiques.</p>
      <div class="flex gap-2"><input :value="cliente.portal_url" readonly class="input text-xs select-all" @focus="$event.target.select()" /><button class="btn-secondary whitespace-nowrap" @click="navigator.clipboard?.writeText(cliente.portal_url)">Copiar</button></div>
      <div class="flex gap-2 mt-3">
        <a :href="`https://wa.me/${(cliente.mobile || cliente.phone || '').replace(/\D/g, '')}?text=${encodeURIComponent('Hola ' + cliente.name + ', este es tu acceso a tu cuenta: ' + cliente.portal_url)}`" target="_blank" class="btn-primary !py-1.5 text-xs">Enviar por WhatsApp</a>
        <a :href="`mailto:${cliente.email ?? ''}?subject=${encodeURIComponent('Tu acceso a tu cuenta')}&body=${encodeURIComponent('Hola ' + cliente.name + ', este es tu acceso: ' + cliente.portal_url)}`" class="btn-secondary !py-1.5 text-xs">Enviar por mail</a>
        <a :href="cliente.portal_url" target="_blank" class="btn-ghost !py-1.5 text-xs">Ver como el cliente</a>
      </div>
      <template #pie><button class="btn-secondary" @click="portalAbierto = false">Cerrar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import ClienteModal from '@/Components/ClienteModal.vue'
import EnviarModal from '@/Components/EnviarModal.vue'
import CondicionesCliente from '@/Components/CondicionesCliente.vue'
import { moneda, cantidad, hoyISO, estadoCobro, estadoComprobante } from '@/util/formato'

const props = defineProps({ paises: { type: Object, default: () => ({}) }, cuitPais: { type: Object, default: () => ({}) },  cliente: Object, cc: Array, pendientes: Array, antiguedad: Object, comprobantes: Array, cobros: Array, acopios: Array, medios: Object, tipos: Array, condicionesIva: Array, cuentas: { type: Array, default: () => [] }, vendedores: { type: Array, default: () => [] }, rubros: { type: Array, default: () => [] }, cotizacionUsd: { type: Number, default: 0 } })
const cuentasPara = medio => props.cuentas.filter(c => ({ efectivo: ['caja'], transferencia: ['banco'], billetera: ['billetera', 'banco'], tarjeta: ['tarjeta', 'banco'] }[medio] ?? ['banco', 'caja']).includes(c.tipo))
function cambiarMedio(m) { m.cuenta_fondos_id = null; m.datos = m.medio === 'cheque' ? { numero: '', banco: '', fecha_pago: hoyISO(), emisor: '', echeq: false } : (m.medio === 'tarjeta' ? { tarjeta: 'Visa', numero: '', cuotas: 1 } : {}) }
const envio = ref(null)
function enviarDoc(modelo, id) { envio.value = { modelo, id } }
const tab = ref('cc')
const tabs = computed(() => [
  { key: 'cc', label: 'Cuenta corriente' }, { key: 'pendientes', label: 'Pendientes', n: props.pendientes.length }, { key: 'comprobantes', label: 'Comprobantes' },
  { key: 'cobros', label: 'Cobros' }, { key: 'acopios', label: 'Acopios', n: props.acopios.length }, { key: 'precios', label: 'Precios pactados' }, { key: 'datos', label: 'Datos' },
])
const totalAnt = computed(() => Object.values(props.antiguedad).reduce((a, b) => a + b, 0))
const pct = v => totalAnt.value ? v / totalAnt.value * 100 : 0
const editarAbierto = ref(false)
const portalAbierto = ref(false)
function abrirMov(m) { if (m.comprobante_id) router.visit(`/comprobantes/${m.comprobante_id}`); else if (m.cobro_id) window.open(`/clientes/cobros/${m.cobro_id}/imprimir`, '_blank') }

// Cobro
const cobroAbierto = ref(false)
const cobro = useForm({ fecha: hoyISO(), notas: '', descuento: 0, interes: 0, vendedor_id: null, cotizacion: props.cotizacionUsd || null, medios: [{ medio: 'efectivo', monto: 0, referencia: '', cuenta_fondos_id: null, datos: {}, moneda: 'ARS', cotizacion: null }], imputaciones: [] })
const cancela = computed(() => totalCobro.value + (Number(cobro.descuento) || 0) - (Number(cobro.interes) || 0))
const imput = reactive({})
const totalCobro = computed(() => cobro.medios.reduce((a, m) => a + (Number(m.monto) || 0) * (m.moneda === 'USD' ? (Number(m.cotizacion) || 0) : 1), 0))
const totalImputado = computed(() => Object.values(imput).reduce((a, v) => a + (Number(v) || 0), 0))
function autoImputar() {
  let resto = totalCobro.value
  Object.keys(imput).forEach(k => delete imput[k])
  const cot = Number(cobro.cotizacion) || props.cotizacionUsd || 0
  for (const p of props.pendientes) { if (resto <= 0) break; const saldoHoy = p.saldo_usd ? p.saldo_usd * cot : p.saldo; const m = Math.min(resto, saldoHoy); imput[p.id] = Math.round(m * 100) / 100; resto -= m }
}
function registrarCobro() {
  cobro.imputaciones = Object.entries(imput).filter(([, v]) => Number(v) > 0).map(([id, v]) => ({ comprobante_id: Number(id), monto: Number(v) }))
  cobro.post(`/clientes/${props.cliente.id}/cobros`, { preserveScroll: true, onSuccess: () => { cobroAbierto.value = false; cobro.reset(); Object.keys(imput).forEach(k => delete imput[k]) } })
}
// Aplicar a facturas un recibo que quedó a cuenta
const aplicarDe = ref(null)
const imputAplicar = reactive({})
const aplicar = useForm({ cotizacion: props.cotizacionUsd || null, imputaciones: [] })
const totalAplicar = computed(() => Object.values(imputAplicar).reduce((a, v) => a + (Number(v) || 0), 0))
function abrirAplicar(k) {
  Object.keys(imputAplicar).forEach(x => delete imputAplicar[x]); aplicar.clearErrors()
  let resto = k.a_cuenta; const cot = Number(aplicar.cotizacion) || props.cotizacionUsd || 0
  for (const p of props.pendientes) { if (resto <= 0) break; const saldoHoy = p.saldo_usd ? p.saldo_usd * cot : p.saldo; const m = Math.min(resto, saldoHoy); imputAplicar[p.id] = Math.round(m * 100) / 100; resto -= m }
  aplicarDe.value = k
}
function enviarAplicar() {
  aplicar.imputaciones = Object.entries(imputAplicar).filter(([, v]) => Number(v) > 0).map(([id, v]) => ({ comprobante_id: Number(id), monto: Number(v) }))
  aplicar.post(`/clientes/cobros/${aplicarDe.value.id}/aplicar`, { preserveScroll: true, onSuccess: () => (aplicarDe.value = null) })
}
function anularCobro(k) { const motivo = window.prompt(`Motivo para anular ${k.numero}:`); if (motivo) router.post(`/clientes/cobros/${k.id}/anular`, { motivo }, { preserveScroll: true }) }

// Acopio
const retiroDe = ref(null)
const retiro = useForm({ fecha: hoyISO(), retirado_por: '', observaciones: '', items: [] })
function abrirRetiro(a) { retiro.items = a.items.map(i => ({ acopio_item_id: i.id, descripcion: i.descripcion, pendiente: i.pendiente, cantidad: 0 })); retiroDe.value = a }

onMounted(() => {
  const q = new URLSearchParams(location.search)
  if (q.get('cobrar')) { const id = Number(q.get('cobrar')); const p = props.pendientes.find(x => x.id === id); if (p) { imput[p.id] = p.saldo; cobro.medios[0].monto = p.saldo } cobroAbierto.value = true; tab.value = 'pendientes' }
  if (q.get('retirar')) { const a = props.acopios.find(x => x.id === Number(q.get('retirar'))); if (a) { tab.value = 'acopios'; abrirRetiro(a) } }
})
</script>
