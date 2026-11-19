<template>
  <AppLayout titulo="Cobranzas">
    <Modal :abierto="!!tr.datos" titulo="Recibo desde el comprobante de transferencia" @cerrar="tr.datos = null">
      <div v-if="tr.datos" class="space-y-3 text-sm">
        <p class="text-xs text-marca-muted">Leído con IA: {{ tr.datos.ordenante_nombre || 'sin nombre' }}{{ tr.datos.ordenante_cuit ? ' · CUIT ' + tr.datos.ordenante_cuit : '' }}{{ tr.datos.banco ? ' · ' + tr.datos.banco : '' }}. Revisá antes de confirmar.</p>
        <p v-if="tr.repetido" class="rounded-lg bg-amber-50 text-amber-800 text-xs px-3 py-1.5">Ya hay un cobro con ese número de operación y ese importe. Fijate que no sea el mismo.</p>
        <div><label class="label">Cliente <span v-if="tr.por" class="text-emerald-700 normal-case font-normal">· reconocido por {{ tr.por }}</span><span v-else class="text-amber-700 normal-case font-normal">· no lo reconocí, elegilo</span></label>
          <select v-model="trf.contact_id" class="input" data-e2e="tr-cliente"><option :value="null">Elegí el cliente…</option><option v-for="c in clientesLista" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div>
        <div class="grid grid-cols-2 gap-3"><div><label class="label">Importe</label><input v-model.number="trf.monto" type="number" step="0.01" class="input" /></div><div><label class="label">Fecha</label><input v-model="trf.fecha" type="date" class="input" /></div></div>
        <div class="grid grid-cols-2 gap-3"><div><label class="label">Entró a</label><select v-model="trf.cuenta_fondos_id" class="input"><option v-for="b in bancos" :key="b.id" :value="b.id">{{ b.nombre }}</option></select></div><div><label class="label">N° de operación</label><input v-model="trf.referencia" class="input" /></div></div>
        <p v-if="tr.imputaciones.length" class="text-xs text-marca-muted">Cancela: {{ tr.imputaciones.map(i => i.numero + ' ' + moneda(i.monto)).join(' · ') }}. Lo que sobre queda a cuenta.</p>
      </div>
      <template #pie><button class="btn-secondary" @click="tr.datos = null">Cancelar</button><button class="btn-primary" :disabled="trf.processing || !trf.contact_id || !trf.cuenta_fondos_id" @click="trf.post('/clientes/cobranzas/desde-transferencia', { preserveScroll: true, onSuccess: () => (tr.datos = null) })" data-e2e="tr-confirmar">Hacer el recibo</button></template>
    </Modal>
    <PageHeader titulo="Cobranzas" subtitulo="Quién debe, hace cuánto, y los avisos que salen solos por mail o WhatsApp."
      :principal="puede('clientes','crear') ? { label: 'Registrar cobro', icono: 'cash', click: () => elegirAbierto = true } : null"
      :mas="[
        { label: 'Enviar avisos de hoy', click: () => router.post('/clientes/cobranzas/correr', {}, { preserveScroll: true }), cuando: puede('clientes','crear'), title: config.activo ? '' : 'Activá los avisos automáticos primero' },
        { label: `Avisos automáticos ${config.activo ? 'ON' : 'OFF'}`, icono: 'settings', click: () => cfgAbierta = true, cuando: puede('clientes','editar') },
        { label: `Bigcito cobrador ${cobrador.activo ? 'ON' : 'OFF'}`, icono: 'settings', click: () => cobAbierto = true, cuando: puede('clientes','editar') },
      ]">
      <template #extra><label v-if="puede('clientes','crear')" class="btn-secondary cursor-pointer" data-e2e="subir-transferencia" title="Subí el comprobante de transferencia y la IA arma el recibo">{{ tr.leyendo ? 'Leyendo…' : 'Recibo desde comprobante' }}<input type="file" accept="image/*,application/pdf" class="hidden" @change="leerTransferencia" /></label></template>
    </PageHeader>
    <Modal :abierto="elegirAbierto" titulo="¿A quién le cobrás?" @cerrar="elegirAbierto = false">
      <div class="space-y-3 text-sm">
        <p class="text-marca-muted">El cobro se registra en la ficha del cliente, donde ves qué le falta pagar e imputás lo que corresponde.</p>
        <select v-model="elegido" class="input" data-e2e="cobro-cliente"><option :value="null">Elegí el cliente…</option><option v-for="c in clientesLista" :key="c.id" :value="c.id">{{ c.nombre }}<template v-if="c.saldo > 0"> · debe {{ moneda(c.saldo, 0) }}</template></option></select>
      </div>
      <template #pie><button class="btn-secondary" @click="elegirAbierto = false">Cancelar</button><Link :href="elegido ? `/clientes/${elegido}?cobro=1` : '#'" class="btn-primary" :class="{ 'opacity-50 pointer-events-none': !elegido }">Ir a registrar el cobro</Link></template>
    </Modal>

    <!-- Fase 120.5: Bigcito cobrador -->
    <Modal :abierto="cobAbierto" titulo="Bigcito cobrador" @cerrar="cobAbierto = false">
      <div class="space-y-3 text-sm" data-e2e="cobrador-config">
        <p class="text-marca-muted">Cuando un cliente con deuda vencida escribe por WhatsApp que no puede pagar todo junto (o responde <b>PLAN</b> a un recordatorio), Bigcito le ofrece pagar en cuotas <b>sólo dentro de estos límites</b>. Lo que pida de más te lo pasa a vos.</p>
        <label class="flex items-center gap-2"><input v-model="cob.activo" type="checkbox" /> <b>Activar el cobrador</b></label>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Cuotas como máximo</label><input v-model.number="cob.max_cuotas" type="number" min="1" max="24" class="input" /></div>
          <div><label class="label">Interés sobre la deuda (%)</label><input v-model.number="cob.interes_pct" type="number" min="0" step="0.5" class="input" /></div>
          <div><label class="label">Cuota mínima ($)</label><input v-model.number="cob.cuota_minima" type="number" min="0" step="1000" class="input" /><p class="text-[11px] text-marca-muted mt-0.5">0 = sin mínimo. Evita planes de cuotas muy chicas.</p></div>
          <div><label class="label">Primera cuota a los (días)</label><input v-model.number="cob.dias_primer_vto" type="number" min="0" max="60" class="input" /></div>
        </div>
        <label class="flex items-start gap-2"><input v-model="cob.aprobar" type="checkbox" class="mt-1" /><span><b>Quiero aprobar cada acuerdo antes de que quede firme</b> (recomendado). Si lo destildás, el plan se arma solo cuando el cliente dice que sí.</span></label>
        <p v-if="!whatsapp.configurado" class="px-3 py-2 rounded-lg bg-amber-50 text-amber-800 text-xs">Para que funcione, la empresa tiene que tener el WhatsApp conectado (Configuración → Tienda y canales).</p>
        <p class="text-xs text-marca-muted">Ejemplo con una deuda de {{ moneda(100000, 0) }}: hasta {{ cob.max_cuotas }} cuota{{ cob.max_cuotas > 1 ? 's' : '' }} de {{ moneda(100000 * (1 + (Number(cob.interes_pct) || 0) / 100) / Math.max(1, cob.max_cuotas), 0) }}{{ Number(cob.interes_pct) > 0 ? ` (con ${cob.interes_pct} % de interés)` : ' sin interés' }}, la primera a los {{ cob.dias_primer_vto }} días.</p>
      </div>
      <template #pie><button class="btn-secondary" @click="cobAbierto = false">Cancelar</button><button class="btn-primary" :disabled="cob.processing" @click="cob.post('/clientes/cobranzas/cobrador', { preserveScroll: true, onSuccess: () => (cobAbierto = false) })" data-e2e="cobrador-guardar">Guardar</button></template>
    </Modal>
    <div v-if="acuerdos.length" class="card mb-4 text-sm border-violeta/40" data-e2e="acuerdos">
      <h2 class="font-bold">Acuerdos de pago que esperan tu aprobación</h2>
      <p class="text-xs text-marca-muted mb-1">Los armó Bigcito por WhatsApp con el cliente, dentro de tus límites. Al aprobar se crea el plan de pago y se le avisa.</p>
      <div v-for="a in acuerdos" :key="a.id" class="py-2 border-t border-marca-borde" data-e2e="acuerdo">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span><Link :href="`/clientes/${a.contact_id}`" class="font-semibold">{{ a.cliente }}</Link> · {{ a.cuotas === 1 ? 'un pago de' : `${a.cuotas} cuotas de` }} <b>{{ moneda(a.cuota, 0) }}</b><span v-if="a.interes_pct"> (con {{ a.interes_pct }} % de interés)</span> · debe {{ moneda(a.deuda, 0) }} vencidos · primera el {{ a.primer_vencimiento }} <span class="text-marca-muted">· {{ a.cuando }}</span></span>
          <span v-if="puede('clientes','crear')" class="flex gap-1 shrink-0">
            <button class="btn-ghost !py-1 text-xs" @click="charlaDe = charlaDe === a.id ? null : a.id">{{ charlaDe === a.id ? 'Ocultar la charla' : 'Ver la charla' }}</button>
            <button class="btn-primary !py-1 text-xs" @click="resolver(a, 'aprobar')" data-e2e="acuerdo-aprobar">Aprobar</button>
            <button class="btn-ghost !py-1 text-xs text-carmin" @click="resolver(a, 'rechazar')">Rechazar</button>
          </span>
        </div>
        <div v-if="charlaDe === a.id" class="mt-2 space-y-1 rounded-xl bg-marca-fondo p-2 text-xs">
          <p v-for="(l, i) in a.charla" :key="i" class="whitespace-pre-line" :class="l[0] === 'c' ? '' : 'text-marca-muted'"><b>{{ l[0] === 'c' ? a.cliente : 'Bigcito' }}:</b> {{ l[1] }}</p>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Por cobrar</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.por_cobrar, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vencido</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(kpis.vencido, 0) }}</p><p class="text-xs text-marca-muted">{{ kpis.deudores }} clientes</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Mora acumulada</p><p class="text-xl font-extrabold tabular-nums text-amber-700">{{ moneda(kpis.mora, 0) }}</p><p class="text-xs text-marca-muted">según % de cada cliente</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Avisos</p><p class="text-base font-extrabold leading-snug">{{ config.activo ? diasHumanos(config.dias) : 'Apagados' }}</p><p class="text-xs text-marca-muted">días respecto del vencimiento</p></div>
    </div>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-4">
      <div class="card p-0 overflow-x-auto min-w-0">
        <table class="table">
          <thead><tr><th>Cliente</th><th>Riesgo</th><th class="text-right">Saldo</th><th class="text-right">Vencido</th><th class="text-right">Atraso</th><th class="text-right">Mora</th><th class="whitespace-nowrap">Último aviso</th><th></th></tr></thead>
          <tbody>
            <tr v-for="d in deudores" :key="d.id">
              <td class="max-w-[16rem]"><Link :href="`/clientes/${d.id}`" class="font-semibold hover:text-carmin">{{ d.nombre }}</Link><p class="text-xs text-marca-muted truncate" :title="[d.vendedor, d.email, d.telefono].filter(Boolean).join(' · ')">{{ [d.vendedor, d.email, d.telefono].filter(Boolean).join(' · ') }}<span v-if="d.plan" class="badge bg-violeta-light text-violeta ml-1">plan de pago</span></p></td>
              <td><span v-if="d.riesgo" class="badge cursor-help" :class="{ bajo: 'bg-emerald-50 text-emerald-700', medio: 'bg-amber-50 text-amber-700', alto: 'bg-carmin-light text-carmin' }[d.riesgo.nivel]" :title="d.riesgo.motivos.join(' · ') || 'Sin motivos de riesgo'" :data-riesgo="d.id">{{ d.riesgo.nivel }} {{ d.riesgo.puntaje }}</span></td>
              <td class="text-right tabular-nums">{{ moneda(d.saldo, 0) }}</td>
              <td class="text-right tabular-nums" :class="d.vencido > 0 ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ moneda(d.vencido, 0) }}</td>
              <td class="text-right tabular-nums" :class="d.dias > 30 ? 'text-carmin' : d.dias > 0 ? 'text-amber-700' : 'text-marca-muted'">{{ d.dias ? d.dias + ' d' : '—' }}</td>
              <td class="text-right tabular-nums text-amber-700">{{ d.mora ? moneda(d.mora, 0) : '' }}</td>
              <td class="text-xs text-marca-muted">{{ d.ultimo_aviso ?? '—' }}</td>
              <td class="text-right whitespace-nowrap">
                <button v-if="d.email && puede('clientes','crear')" @click="recordar(d, 'mail')" class="btn-ghost !px-2 text-xs" title="Recordatorio por mail">Mail</button>
                <button v-if="d.telefono && puede('clientes','crear')" @click="recordar(d, 'whatsapp')" class="btn-ghost !px-2 text-xs" title="Recordatorio por WhatsApp">WhatsApp</button><button v-if="crmActivo && puede('clientes','crear')" @click="recordar(d, 'crm')" class="btn-ghost !px-2 text-xs text-violeta" title="Crea la tarea de cobranza en el CRM">CRM</button>
                <button v-if="d.vencido > 0 && !d.plan && puede('clientes','crear')" @click="abrirRefi(d)" class="btn-ghost !px-2 text-xs text-violeta">Refinanciar</button>
              </td>
            </tr>
            <tr v-if="!deudores.length"><td colspan="8" class="!py-0"><EstadoVacio texto="Nadie debe nada. 🎉" /></td></tr>
          </tbody>
        </table>
      </div>
      <div class="space-y-4">
        <div v-if="llamarAntes.length" class="card text-sm border-amber-300" data-e2e="llamar-antes">
          <p class="text-[11px] font-bold uppercase tracking-widest text-amber-700 mb-1">Para llamar antes de que venza</p>
          <p class="text-xs text-marca-muted mb-2">Vencen en 7 días y son clientes que suelen atrasarse. Una llamada ahora ahorra el reclamo después.</p>
          <div v-for="l in llamarAntes" :key="l.id" class="py-1.5 border-t border-marca-borde/60"><div class="flex justify-between gap-2"><Link :href="`/clientes/${l.id}`" class="font-semibold">{{ l.nombre }}</Link><span class="tabular-nums">{{ moneda(l.importe, 0) }} · {{ l.vence }}</span></div><p class="text-xs text-marca-muted">{{ l.telefono || 'sin teléfono' }} · riesgo {{ l.nivel }} · {{ l.motivos[0] }}</p></div>
        </div>
        <div class="card text-sm">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Planes de pago</p>
          <div v-for="p in planes" :key="p.id" class="py-2 border-t border-marca-borde first:border-0">
            <div class="flex justify-between"><span class="font-semibold">{{ p.numero }} · {{ p.cliente }}</span><span class="badge" :class="p.estado === 'pagado' ? 'bg-emerald-50 text-emerald-700' : p.estado === 'vigente' ? 'bg-violeta-light text-violeta' : 'bg-gris-light text-marca-muted'">{{ p.estado }}</span></div>
            <p class="text-xs text-marca-muted">{{ p.fecha }} · {{ moneda(p.total, 0) }} en {{ p.cuotas.length }} cuotas<span v-if="p.interes"> · interés {{ moneda(p.interes, 0) }}</span></p>
            <div class="flex flex-wrap gap-1 mt-1"><span v-for="q in p.cuotas" :key="q.numero" class="text-[11px] px-1.5 py-0.5 rounded" :class="q.estado === 'pagada' ? 'bg-emerald-50 text-emerald-700' : q.estado === 'vencida' ? 'bg-carmin-light text-carmin' : 'bg-gris-light text-marca-muted'" :title="`Cuota ${q.numero} vence ${q.vencimiento}: ${moneda(q.monto)} (pagado ${moneda(q.pagado)})`">{{ q.numero }} · {{ q.vencimiento.slice(0, 5) }}</span></div>
          </div>
          <p v-if="!planes.length" class="text-marca-muted">Sin planes de pago.</p>
        </div>
        <div class="card text-sm">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Últimos avisos</p>
          <div v-for="e in envios" :key="e.id" class="flex items-center justify-between gap-2 py-1.5 border-t border-marca-borde first:border-0">
            <span class="min-w-0"><span class="block truncate">{{ e.cliente }} · {{ e.canal }}</span><span class="text-xs text-marca-muted">{{ e.fecha }} · {{ e.destino }}</span></span>
            <span class="text-xs font-semibold" :class="e.estado === 'enviado' ? 'text-emerald-700' : e.estado === 'error' ? 'text-carmin' : 'text-amber-700'" :title="e.error ?? ''">{{ e.estado }}<a v-if="e.link && e.estado === 'pendiente'" :href="e.link" target="_blank" class="text-violeta ml-1">abrir</a></span>
          </div>
          <p v-if="!envios.length" class="text-marca-muted">Todavía no salió ningún aviso.</p>
        </div>
      </div>
    </div>

    <!-- Configuración -->
    <Modal :abierto="cfgAbierta" titulo="Avisos automáticos de cobranza" ancho="max-w-2xl" @cerrar="cfgAbierta = false">
      <label class="flex items-center gap-2 text-sm mb-3"><input v-model="cf.activo" type="checkbox" class="accent-carmin" /> <b>Mandar recordatorios solos</b> (todos los días a las 9:30)</label>
      <label v-if="ia" class="flex items-center gap-2 text-sm mb-3"><input v-model="cf.ia" type="checkbox" class="accent-carmin" /> <b>✦ Escribir cada recordatorio a medida</b>: suave con el que siempre paga, firme con el que se atrasa, con todas sus facturas y un plan si hace falta. Sin esto sale la plantilla de abajo.</label>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Días respecto del vencimiento</label><input v-model="diasTexto" class="input" placeholder="-3, 0, 7, 30" /><p class="text-[11px] text-marca-muted mt-1">Negativo = antes de vencer. Ej: -3 avisa 3 días antes, 7 avisa a la semana de vencida.</p></div>
        <div><label class="label">Canales</label><div class="flex gap-3 mt-2"><label class="flex items-center gap-1.5 text-sm"><input v-model="cf.canales" value="mail" type="checkbox" class="accent-carmin" /> Mail</label><label class="flex items-center gap-1.5 text-sm"><input v-model="cf.canales" value="whatsapp" type="checkbox" class="accent-carmin" /> WhatsApp</label><label v-if="crmActivo" class="flex items-center gap-1.5 text-sm" title="Crea una tarea de cobranza en el CRM para que el vendedor la mande desde la conversación del cliente"><input v-model="cf.canales" value="crm" type="checkbox" class="accent-carmin" /> Por el CRM</label></div><p class="text-[11px] text-marca-muted mt-1">{{ mailConfigurado ? 'Mail configurado.' : 'Mail en modo prueba (se registra, no sale).' }} {{ whatsapp.configurado ? 'WhatsApp por API.' : 'WhatsApp sin API: quedan como pendientes para mandar a mano.' }}</p></div>
        <div class="sm:col-span-2"><label class="label">Texto del aviso</label><textarea v-model="cf.texto" rows="3" class="input text-sm"></textarea><p class="text-[11px] text-marca-muted mt-1">Variables: {cliente} {comprobante} {importe} {estado} {link} {empresa}</p></div>
        <div class="sm:col-span-2 border-t border-marca-borde pt-3"><p class="label">WhatsApp Business API (opcional)</p><div class="grid sm:grid-cols-2 gap-2"><input v-model="cf.whatsapp_token" class="input !py-1 text-xs" placeholder="Token permanente de Meta" /><input v-model="cf.whatsapp_phone_id" class="input !py-1 text-xs" placeholder="Phone number ID" /></div><p class="text-[11px] text-marca-muted mt-1">Con esto los mensajes salen solos desde tu número. Sin esto, se abre WhatsApp Web con el texto listo.</p></div>
      </div>
      <template #pie><button class="btn-secondary" @click="cfgAbierta = false">Cancelar</button><button class="btn-primary" :disabled="cf.processing" @click="cf.transform(d => ({ ...d, dias: diasTexto.split(/[,\s]+/).filter(x => x !== '').map(Number) })).post('/clientes/cobranzas/configurar', { preserveScroll: true, onSuccess: () => (cfgAbierta = false) })">Guardar</button></template>
    </Modal>

    <!-- Refinanciar -->
    <Modal :abierto="!!refi" :titulo="`Refinanciar a ${refi?.nombre}`" ancho="max-w-2xl" @cerrar="refi = null">
      <p class="text-sm text-marca-muted mb-3">Se genera una nota de débito por el interés y un plan en cuotas. Las facturas elegidas dejan de figurar vencidas hasta la última cuota.</p>
      <div v-if="cargandoPend" class="text-sm text-marca-muted">Cargando facturas…</div>
      <div v-else class="max-h-48 overflow-y-auto border border-marca-borde rounded-xl divide-y divide-marca-borde/60 mb-3">
        <label v-for="p in pendRefi" :key="p.id" class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer"><input v-model="rf.comprobantes" :value="p.id" type="checkbox" class="accent-carmin" /><span class="flex-1">{{ p.nombre }} {{ p.numero }} <span class="text-xs text-marca-muted">vence {{ p.fecha_vto }}</span></span><b class="tabular-nums">{{ moneda(p.saldo) }}</b></label>
      </div>
      <div v-if="sug" class="rounded-xl bg-lavanda-light/50 p-3 text-sm mb-3" data-e2e="plan-sugerido"><p class="text-[11px] font-bold uppercase text-violeta">✦ Plan sugerido</p><p>{{ moneda(sug.deuda, 0) }} en <b>{{ sug.cuotas }}</b> cuota{{ sug.cuotas > 1 ? 's' : '' }} de <b>{{ moneda(sug.cuota, 0) }}</b>, primera el {{ sug.primer_vencimiento.slice(8) }}/{{ sug.primer_vencimiento.slice(5, 7) }}. <span class="text-xs text-marca-muted">{{ sug.porque }}</span></p>
        <p class="text-xs italic mt-1 bg-white rounded-lg px-2 py-1">“{{ sug.texto }}”</p>
        <div class="flex flex-wrap gap-1.5 mt-2"><button class="btn-secondary !py-1 text-xs" @click="usarSugerido">Usar estos valores</button><button v-if="refi?.telefono" class="btn-secondary !py-1 text-xs" @click="proponer('whatsapp')">Mandar propuesta por WhatsApp</button><button v-if="refi?.email" class="btn-secondary !py-1 text-xs" @click="proponer('mail')">por mail</button></div></div>
      <button v-else class="btn-ghost !px-2 text-xs mb-2" :disabled="sugCargando" @click="pedirSugerido" data-e2e="sugerir-plan">{{ sugCargando ? 'Pensando…' : '✦ Sugerir plan según cómo paga' }}</button>
      <div class="grid grid-cols-3 gap-3">
        <div><label class="label">Cuotas</label><input v-model.number="rf.cuotas" type="number" min="1" max="36" class="input" /></div>
        <div><label class="label">Interés total %</label><input v-model.number="rf.interes_pct" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">Primer vencimiento</label><input v-model="rf.primer_vencimiento" type="date" class="input" /></div>
      </div>
      <div class="mt-3 rounded-xl bg-marca-fondo p-3 text-sm space-y-1">
        <div class="flex justify-between"><span>Deuda a refinanciar</span><span class="tabular-nums">{{ moneda(deudaRefi) }}</span></div>
        <div class="flex justify-between"><span>Interés</span><span class="tabular-nums">{{ moneda(deudaRefi * (rf.interes_pct || 0) / 100) }}</span></div>
        <div class="flex justify-between font-bold"><span>{{ rf.cuotas }} cuotas de</span><span class="tabular-nums">{{ moneda(deudaRefi * (1 + (rf.interes_pct || 0) / 100) / (rf.cuotas || 1)) }}</span></div>
      </div>
      <p v-if="rf.errors.comprobantes" class="text-carmin text-xs mt-2">{{ rf.errors.comprobantes }}</p>
      <template #pie><button class="btn-secondary" @click="refi = null">Cancelar</button><button class="btn-primary" :disabled="rf.processing || !rf.comprobantes.length" @click="rf.post(`/clientes/cobranzas/${refi.id}/refinanciar`, { preserveScroll: true, onSuccess: () => (refi = null) })">Crear plan</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ cobrador: { type: Object, default: () => ({ activo: false, max_cuotas: 3, interes_pct: 0, cuota_minima: 0, dias_primer_vto: 10, aprobar: true }) }, acuerdos: { type: Array, default: () => [] }, deudores: Array, kpis: Object, config: Object, whatsapp: Object, mailConfigurado: Boolean, envios: Array, planes: Array, crmActivo: Boolean, llamarAntes: { type: Array, default: () => [] }, ia: Boolean, bancos: { type: Array, default: () => [] }, clientesLista: { type: Array, default: () => [] } })
const { puede } = usePermisos()
const elegirAbierto = ref(false), elegido = ref(null)
const page = usePage()
const cfgAbierta = ref(false)
// Fase 120.5: Bigcito cobrador
const cobAbierto = ref(false), charlaDe = ref(null)
const cob = useForm({ ...props.cobrador })
function resolver(a, decision) {
  let motivo = null
  if (decision === 'rechazar') { motivo = window.prompt(`¿Por qué no se toma el plan de ${a.cliente}? (se lo decimos al cliente; podés dejarlo vacío)`, ''); if (motivo === null) return }
  else if (!confirm(`Se crea el plan de pago de ${a.cliente} y se le avisa por WhatsApp. ¿Seguimos?`)) return
  router.post(`/clientes/cobranzas/acuerdos/${a.id}`, { decision, motivo }, { preserveScroll: true })
}
// Fase 47: la foto o el PDF de la transferencia → la IA lo lee → recibo para confirmar.
const tr = reactive({ leyendo: false, datos: null, por: null, imputaciones: [], repetido: false })
const trf = useForm({ contact_id: null, monto: 0, fecha: '', cuenta_fondos_id: props.bancos?.[0]?.id ?? null, referencia: '' })
async function leerTransferencia(ev) {
  const file = ev.target.files?.[0]; ev.target.value = ''; if (!file) return
  tr.leyendo = true
  try {
    const fd = new FormData(); fd.append('archivo', file)
    const { data } = await window.axios.post('/clientes/cobranzas/leer-transferencia', fd)
    if (!data.ok) { alertar(data.aviso); return }
    Object.assign(tr, { datos: data.datos, por: data.por, imputaciones: data.imputaciones, repetido: data.repetido })
    Object.assign(trf, { contact_id: data.contact_id, monto: data.datos.monto, fecha: data.datos.fecha || new Date().toISOString().slice(0, 10), referencia: data.datos.numero_operacion || '' })
  } catch (e) { alertar('No pude leer el archivo.') } finally { tr.leyendo = false }
}
function alertar(t) { page.props.flash = { ...(page.props.flash ?? {}), error: t } }
const cf = useForm({ activo: props.config.activo, ia: props.config.ia ?? true, canales: [...props.config.canales], texto: props.config.texto, whatsapp_token: props.whatsapp.token, whatsapp_phone_id: props.whatsapp.phone_id })
const diasTexto = ref(props.config.dias.join(', '))
// Fase 110: «-3 / 0 / 7 / 30» → «3 días antes · el día · a los 7 · a los 30»
function diasHumanos(ds) { return [...ds].map(Number).sort((a, b) => a - b).map(d => d < 0 ? `${-d} días antes` : (d === 0 ? 'el día' : `a los ${d}`)).join(' · ') }
function recordar(d, canal) { router.post(`/clientes/cobranzas/${d.id}/recordar`, { canal }, { preserveScroll: true, onSuccess: () => { const abrir = page.props.flash?.abrir; if (abrir) { window.open(abrir, '_blank'); const id = page.props.flash?.envio_id; if (id) window.axios.post(`/envios/${id}/marcar`).catch(() => {}) } } }) }

const refi = ref(null), pendRefi = ref([]), cargandoPend = ref(false)
// Fase 57: el plan que le podés ofrecer, y mandárselo
const sug = ref(null), sugCargando = ref(false)
async function pedirSugerido() { sugCargando.value = true; try { const { data } = await window.axios.get(`/clientes/cobranzas/${refi.value.id}/plan-sugerido`); if (data.ok) sug.value = data; else alertar(data.aviso) } catch (e) {} finally { sugCargando.value = false } }
function usarSugerido() { rf.comprobantes = [...sug.value.comprobantes]; rf.cuotas = sug.value.cuotas; rf.interes_pct = sug.value.interes_pct; rf.primer_vencimiento = sug.value.primer_vencimiento }
function proponer(canal) { router.post(`/clientes/cobranzas/${refi.value.id}/proponer-plan`, { canal, texto: sug.value.texto }, { preserveScroll: true }) }
const rf = useForm({ comprobantes: [], cuotas: 3, interes_pct: 0, primer_vencimiento: new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10), notas: '' })
async function abrirRefi(d) {
  refi.value = d; sug.value = null; rf.clearErrors(); rf.comprobantes = []; rf.interes_pct = d.interes_mora * 2 || 0; cargandoPend.value = true
  try { const { data } = await window.axios.get(`/clientes/${d.id}/pendientes`); pendRefi.value = data; rf.comprobantes = data.filter(p => p.vencido).map(p => p.id) } finally { cargandoPend.value = false }
}
const deudaRefi = computed(() => pendRefi.value.filter(p => rf.comprobantes.includes(p.id)).reduce((a, p) => a + p.saldo, 0))
</script>
