<template>
  <AppLayout titulo="Sueldos">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Sueldos</h1><p class="page-subtitle">Legajos, liquidación mensual con los conceptos de tu convenio (o la que manda el contador), recibos, asiento y pago desde fondos.</p></div>
      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary" @click="configAbierto = true">Configuración</button>
        <button class="btn-secondary" @click="conceptosAbierto = true">Conceptos</button>
        <button class="btn-secondary" @click="importarAbierto = true">Importar del contador</button>
        <button class="btn-secondary" @click="abrirEmpleado()">Nuevo empleado</button>
        <button class="btn-primary" @click="abrirLiquidar">Liquidar</button>
      </div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Empleados activos</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.activos }}</p><p class="text-xs text-marca-muted">básicos {{ moneda(kpis.masa, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Última liquidación</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.ultimo_neto, 0) }}</p><p class="text-xs text-marca-muted">{{ kpis.ultimo_periodo || 'todavía ninguna' }} · neto</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Costo laboral</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.ultimo_costo, 0) }}</p><p class="text-xs text-marca-muted">bruto + no rem. + contribuciones</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Confirmadas sin pagar</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.pendientes ? 'text-carmin' : ''">{{ kpis.pendientes }}</p></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div v-if="enCurso" class="card p-0 overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-marca-borde">
            <div><h2 class="font-bold">{{ enCurso.label }}</h2><p class="text-xs text-marca-muted">{{ enCurso.recibos }} recibos · <span class="badge" :class="{ 'bg-amber-50 text-amber-700': enCurso.estado === 'borrador', 'bg-violeta/10 text-violeta': enCurso.estado === 'confirmada', 'bg-emerald-50 text-emerald-700': enCurso.estado === 'pagada' }">{{ estados[enCurso.estado] }}</span><span v-if="enCurso.importada"> · importada</span></p></div>
            <div class="flex flex-wrap gap-2">
              <a :href="`/sueldos/${enCurso.id}/recibo`" target="_blank" class="btn-secondary !py-1 text-xs">Recibos</a>
              <a :href="`/sueldos/${enCurso.id}/libro`" class="btn-secondary !py-1 text-xs">Libro CSV</a>
              <a :href="`/sueldos/${enCurso.id}/f931`" class="btn-secondary !py-1 text-xs" title="Resumen por empleado para cargar el F.931 / Libro de Sueldos Digital">F.931</a>
              <button v-if="enCurso.estado === 'borrador'" class="btn-primary !py-1 text-xs" @click="router.post(`/sueldos/${enCurso.id}/confirmar`, {}, { preserveScroll: true })">Confirmar y contabilizar</button>
              <button v-if="enCurso.estado === 'confirmada'" class="btn-ghost !py-1 text-xs" @click="router.post(`/sueldos/${enCurso.id}/reabrir`, {}, { preserveScroll: true })">Reabrir</button>
              <button v-if="enCurso.estado === 'confirmada'" class="btn-primary !py-1 text-xs" @click="pagarAbierto = true">Pagar</button>
              <Link v-if="enCurso.asiento_id" :href="`/contable/asientos?id=${enCurso.asiento_id}`" class="btn-ghost !py-1 text-xs">Asiento</Link>
            </div>
          </div>
          <table class="table text-sm">
            <thead><tr><th>Empleado</th><th class="text-right">Días</th><th class="text-right">Bruto</th><th class="text-right">No rem.</th><th class="text-right">Deducciones</th><th class="text-right">Neto</th><th class="text-right">Contrib.</th><th></th></tr></thead>
            <tbody>
              <tr v-for="i in enCurso.items" :key="i.id" class="cursor-pointer hover:bg-gris-light/40" @click="detalle = i">
                <td><span class="font-medium">{{ i.empleado }}</span><span class="text-xs text-marca-muted"> · leg. {{ i.legajo }}</span></td><td class="text-right tabular-nums">{{ i.dias }}</td><td class="text-right tabular-nums">{{ moneda(i.bruto, 0) }}</td><td class="text-right tabular-nums">{{ moneda(i.no_rem, 0) }}</td><td class="text-right tabular-nums text-carmin">{{ moneda(i.deducciones + i.anticipos, 0) }}</td><td class="text-right tabular-nums font-bold">{{ moneda(i.neto, 0) }}</td><td class="text-right tabular-nums text-marca-muted">{{ moneda(i.contribuciones, 0) }}</td>
                <td class="text-right"><a :href="`/sueldos/${enCurso.id}/recibo/${i.id}`" target="_blank" class="text-xs text-violeta font-semibold" @click.stop>Recibo</a></td>
              </tr>
            </tbody>
            <tfoot><tr class="font-bold bg-gris-light/50"><td>Totales</td><td></td><td class="text-right tabular-nums">{{ moneda(enCurso.bruto, 0) }}</td><td class="text-right tabular-nums">{{ moneda(enCurso.no_rem, 0) }}</td><td class="text-right tabular-nums">{{ moneda(enCurso.deducciones, 0) }}</td><td class="text-right tabular-nums">{{ moneda(enCurso.neto, 0) }}</td><td class="text-right tabular-nums">{{ moneda(enCurso.contribuciones, 0) }}</td><td></td></tr></tfoot>
          </table>
          <div class="flex flex-wrap items-end gap-2 px-4 py-3 border-t border-marca-borde text-xs" data-e2e="deposito">
            <span class="font-semibold text-marca-muted w-full sm:w-auto sm:mr-2">Último depósito de aportes (sale en el recibo)</span>
            <div><label class="label !text-[10px]">Fecha</label><input v-model="dep.deposito_fecha" type="date" class="input !py-1" /></div>
            <div><label class="label !text-[10px]">Banco</label><input v-model="dep.deposito_banco" class="input !py-1 !w-36" placeholder="BCO.REGIONAL" /></div>
            <div><label class="label !text-[10px]">Mes</label><input v-model="dep.deposito_periodo" type="month" class="input !py-1" /></div>
            <button class="btn-secondary !py-1 text-xs" :disabled="dep.processing" @click="dep.post(`/sueldos/${enCurso.id}/deposito`, { preserveScroll: true })">Guardar</button>
          </div>
          <p v-if="enCurso.notas" class="px-4 py-2 text-xs text-marca-muted border-t border-marca-borde">{{ enCurso.notas }}</p>
        </div>
        <div v-else class="card text-center text-marca-muted py-8 text-sm">No hay liquidación para {{ periodo }}. Usá <b>Liquidar</b> para calcularla con tus conceptos o <b>Importar</b> la que mandó el contador.</div>

        <div class="card p-0 overflow-hidden">
          <div class="px-4 py-3 border-b border-marca-borde flex items-center justify-between"><h2 class="font-bold">Empleados</h2><span class="text-xs text-marca-muted">{{ empleados.length }} legajos</span></div>
          <table class="table text-sm">
            <thead><tr><th>Leg.</th><th>Nombre</th><th>Categoría</th><th>Ingreso</th><th class="text-right">Básico</th><th class="text-right">Anticipos</th><th></th></tr></thead>
            <tbody>
              <tr v-for="e in empleados" :key="e.id" :class="e.activo ? '' : 'opacity-50'">
                <td class="tabular-nums">{{ e.legajo }}</td><td class="font-medium">{{ e.nombre }}<p class="text-xs text-marca-muted">{{ e.cuil }}<span v-if="e.puesto"> · {{ e.puesto }}</span></p></td><td class="text-xs">{{ e.categoria }}<p class="text-marca-muted">{{ e.convenio }}</p></td><td class="tabular-nums text-xs">{{ fmt(e.fecha_ingreso) }} <span class="text-marca-muted">({{ e.antiguedad }} a.)</span></td><td class="text-right tabular-nums">{{ moneda(e.sueldo_basico, 0) }}</td><td class="text-right tabular-nums" :class="e.anticipos ? 'text-amber-700 font-semibold' : 'text-marca-muted'">{{ e.anticipos ? moneda(e.anticipos, 0) : '—' }}</td>
                <td class="text-right whitespace-nowrap"><button class="btn-ghost !px-2 text-xs" @click="anticipo = { id: e.id, nombre: e.nombre, monto: null, cuenta_id: cuentas[0]?.id ?? null, fecha: hoyISO() }">Anticipo</button><button class="btn-ghost !px-2 text-xs" @click="abrirEmpleado(e)">Editar</button></td>
              </tr>
              <tr v-if="!empleados.length"><td colspan="7" class="text-center text-marca-muted py-8">Todavía no cargaste empleados.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="space-y-4">
        <div class="card p-0 overflow-hidden">
          <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Liquidaciones</h2></div>
          <div class="divide-y divide-marca-borde/60">
            <Link v-for="l in liquidaciones" :key="l.id" :href="`/sueldos?periodo=${l.periodo}&tipo=${l.tipo}`" class="flex items-center justify-between px-4 py-2.5 hover:bg-gris-light/40 text-sm" :class="enCurso?.id === l.id ? 'bg-violeta/5' : ''">
              <div><p class="font-medium">{{ l.label }}</p><p class="text-xs text-marca-muted">{{ l.recibos }} recibos · <span :class="{ 'text-amber-700': l.estado === 'borrador', 'text-violeta': l.estado === 'confirmada', 'text-emerald-700': l.estado === 'pagada' }">{{ estados[l.estado] }}</span></p></div>
              <span class="tabular-nums font-bold">{{ moneda(l.neto, 0) }}</span>
            </Link>
            <p v-if="!liquidaciones.length" class="px-4 py-6 text-center text-marca-muted text-sm">Sin liquidaciones.</p>
          </div>
        </div>
        <div class="card text-sm">
          <h2 class="font-bold mb-2">Cómo funciona</h2>
          <ol class="list-decimal pl-4 space-y-1 text-xs text-marca-muted">
            <li>Cargás la plantilla de tu convenio (Conceptos → Cargar Comercio) y los empleados con básico, jornada y fecha de ingreso.</li><li>Cada mes liquidás con las novedades (días, horas extra, premios, anticipos) o importás el CSV del contador.</li><li>Confirmás: se genera el asiento (sueldos y cargas al gasto, neto y F931 al pasivo).</li><li>Pagás desde caja o banco; las cargas se pagan aparte cuando vence el F931.</li>
          </ol>
        </div>
      </div>
    </div>

    <!-- Liquidar -->
    <Modal :abierto="liquidarAbierto" titulo="Liquidar sueldos" ancho="max-w-4xl" @cerrar="liquidarAbierto = false">
      <div class="grid sm:grid-cols-3 gap-3 mb-3">
        <div><label class="label">Período</label><input v-model="lq.periodo" type="month" class="input" /></div>
        <div><label class="label">Tipo</label><select v-model="lq.tipo" class="input"><option v-for="(l, k) in tiposLiq" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="label">Fecha de pago</label><input v-model="lq.fecha" type="date" class="input" /></div>
      </div>
      <p class="text-xs text-marca-muted mb-2">Novedades del mes por empleado. Lo que no toques queda en 30 días y sin extras. Al cargar feriados o vacaciones, los días normales se ajustan solos (30 − feriados − vacaciones).</p>
      <div class="overflow-x-auto"><table class="table text-xs">
        <thead><tr><th></th><th>Empleado</th><th class="text-right">Días</th><th class="text-right">Feriados</th><th class="text-right">Vacac.</th><th class="text-right">HE 50%</th><th class="text-right">HE 100%</th><th class="text-right">Premios</th><th class="text-right">No rem.</th><th class="text-right">Anticipos</th></tr></thead>
        <tbody>
          <tr v-for="e in empleados.filter(x => x.activo)" :key="e.id">
            <td><input type="checkbox" class="accent-carmin" :checked="!lq.novedades[e.id].excluir" @change="lq.novedades[e.id].excluir = !$event.target.checked" title="Incluir" /></td>
            <td class="font-medium whitespace-nowrap">{{ e.nombre }}</td>
            <td><input v-model.number="lq.novedades[e.id].dias" type="number" min="0" max="31" class="input !py-1 !w-16 text-right" /></td>
            <td><input v-model.number="lq.novedades[e.id].feriados" type="number" min="0" max="31" class="input !py-1 !w-14 text-right" @input="ajustarDias(e.id)" /></td>
            <td><input v-model.number="lq.novedades[e.id].vacaciones" type="number" min="0" max="31" class="input !py-1 !w-14 text-right" @input="ajustarDias(e.id)" /></td>
            <td><input v-model.number="lq.novedades[e.id].horas_extra_50" type="number" step="0.5" min="0" class="input !py-1 !w-16 text-right" /></td>
            <td><input v-model.number="lq.novedades[e.id].horas_extra_100" type="number" step="0.5" min="0" class="input !py-1 !w-16 text-right" /></td>
            <td><input v-model.number="lq.novedades[e.id].adicionales" type="number" min="0" class="input !py-1 !w-24 text-right" /></td>
            <td><input v-model.number="lq.novedades[e.id].no_rem_extra" type="number" min="0" class="input !py-1 !w-24 text-right" /></td>
            <td><input v-model.number="lq.novedades[e.id].anticipos" type="number" min="0" class="input !py-1 !w-24 text-right" /></td>
          </tr>
        </tbody>
      </table></div>
      <template #pie><button class="btn-secondary" @click="liquidarAbierto = false">Cancelar</button><button class="btn-primary" :disabled="lq.processing || !lq.periodo" @click="lq.post('/sueldos/liquidar', { onSuccess: () => (liquidarAbierto = false) })">Calcular liquidación</button></template>
    </Modal>

    <!-- Importar -->
    <Modal :abierto="importarAbierto" titulo="Importar liquidación del contador" ancho="max-w-2xl" @cerrar="importarAbierto = false">
      <div class="grid grid-cols-2 gap-3 mb-3"><div><label class="label">Período</label><input v-model="im.periodo" type="month" class="input" /></div><div><label class="label">Tipo</label><select v-model="im.tipo" class="input"><option v-for="(l, k) in tiposLiq" :key="k" :value="k">{{ l }}</option></select></div></div>
      <p class="text-xs text-marca-muted mb-2">Una fila por empleado: <code>legajo o CUIL; bruto; no remunerativo; deducciones; neto; contribuciones</code>. Sirve el export de cualquier liquidador (Bejerman, Holistor, Tango, Excel).</p>
      <textarea v-model="im.csv" rows="7" class="input font-mono text-xs" placeholder="1;850000;50000;144500;755500;240550&#10;20-12345678-9;920000;0;156400;763600;260360"></textarea>
      <label class="btn-secondary cursor-pointer mt-2 inline-flex"><input type="file" accept=".csv,.txt" class="hidden" @change="leerArchivo" /> Subir archivo</label>
      <p v-if="im.errors.csv" class="text-carmin text-xs mt-2">{{ im.errors.csv }}</p>
      <template #pie><button class="btn-secondary" @click="importarAbierto = false">Cancelar</button><button class="btn-primary" :disabled="im.processing || !im.csv" @click="im.post('/sueldos/importar', { onSuccess: () => (importarAbierto = false) })">Importar</button></template>
    </Modal>

    <!-- Pagar -->
    <Modal :abierto="pagarAbierto" titulo="Pagar sueldos" @cerrar="pagarAbierto = false">
      <p class="text-sm mb-3">Neto a pagar: <b class="tabular-nums">{{ moneda(enCurso?.neto ?? 0) }}</b>. Cargas sociales (F931): <b class="tabular-nums">{{ moneda((enCurso?.deducciones ?? 0) + (enCurso?.contribuciones ?? 0)) }}</b>.</p>
      <div class="grid grid-cols-2 gap-3"><div><label class="label">Sale de</label><select v-model="pg.cuenta_id" class="input"><option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.nombre }} · {{ moneda(c.saldo, 0) }}</option></select></div><div><label class="label">Fecha</label><input v-model="pg.fecha" type="date" class="input" /></div></div>
      <label class="flex items-center gap-2 text-sm mt-3"><input v-model="pg.cargas" type="checkbox" class="accent-carmin" /> Pagar también las cargas sociales ahora</label>
      <template #pie><button class="btn-secondary" @click="pagarAbierto = false">Cancelar</button><button class="btn-primary" :disabled="pg.processing || !pg.cuenta_id" @click="pg.post(`/sueldos/${enCurso.id}/pagar`, { preserveScroll: true, onSuccess: () => (pagarAbierto = false) })">Pagar</button></template>
    </Modal>

    <!-- Anticipo -->
    <Modal :abierto="!!anticipo" :titulo="`Anticipo a ${anticipo?.nombre}`" @cerrar="anticipo = null">
      <template v-if="anticipo">
        <div class="grid grid-cols-2 gap-3"><div><label class="label">Importe</label><input v-model.number="anticipo.monto" type="number" min="0" class="input" /></div><div><label class="label">Fecha</label><input v-model="anticipo.fecha" type="date" class="input" /></div>
          <div class="col-span-2"><label class="label">Sale de</label><select v-model="anticipo.cuenta_id" class="input"><option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.nombre }} · {{ moneda(c.saldo, 0) }}</option></select></div></div>
        <p class="text-xs text-marca-muted mt-2">Se descuenta solo en la próxima liquidación.</p>
      </template>
      <template #pie><button class="btn-secondary" @click="anticipo = null">Cancelar</button><button class="btn-primary" :disabled="!anticipo?.monto || !anticipo?.cuenta_id" @click="router.post(`/sueldos/empleados/${anticipo.id}/anticipo`, anticipo, { preserveScroll: true, onSuccess: () => (anticipo = null) })">Registrar</button></template>
    </Modal>

    <!-- Empleado -->
    <Modal :abierto="empleadoAbierto" :titulo="ef.id ? 'Editar empleado' : 'Nuevo empleado'" ancho="max-w-2xl" @cerrar="empleadoAbierto = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2"><label class="label">Nombre y apellido</label><input v-model="ef.nombre" class="input" /><p v-if="ef.errors.nombre" class="text-carmin text-xs mt-1">{{ ef.errors.nombre }}</p></div>
        <div><label class="label">CUIL</label><input v-model="ef.cuil" class="input" placeholder="20-12345678-9" /></div><div><label class="label">Documento</label><input v-model="ef.documento" class="input" placeholder="25781816" /></div><div><label class="label">Fecha de ingreso</label><input v-model="ef.fecha_ingreso" type="date" class="input" /></div>
        <div><label class="label">Jornada</label><select v-model="ef.jornada" class="input"><option v-for="(l, k) in jornadas" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="label">Centro de costo</label><input v-model="ef.centro_costo" class="input" placeholder="ADMINISTRACION" /></div><div><label class="label">Lugar de trabajo</label><input v-model="ef.lugar_trabajo" class="input" placeholder="MENDOZA" /></div>
        <div><label class="label">Categoría</label><input v-model="ef.categoria" class="input" placeholder="Vendedor B, Oficial…" /></div><div><label class="label">Convenio</label><input v-model="ef.convenio" class="input" placeholder="Comercio 130/75, UOCRA…" /></div>
        <div><label class="label">Puesto</label><input v-model="ef.puesto" class="input" /></div><div><label class="label">Obra social</label><input v-model="ef.obra_social" class="input" /></div>
        <div><label class="label">Sueldo básico</label><input v-model.number="ef.sueldo_basico" type="number" min="0" class="input" /></div><div><label class="label">Modalidad</label><select v-model="ef.modalidad" class="input"><option value="mensual">Mensual</option><option value="jornal">Jornal (por día)</option></select></div>
        <div><label class="label">CBU para acreditar</label><input v-model="ef.cbu" class="input" /></div><div><label class="label">Teléfono</label><input v-model="ef.telefono" class="input" /></div>
        <div><label class="label">Email</label><input v-model="ef.email" type="email" class="input" /></div><div v-if="ef.id"><label class="label">Fecha de egreso</label><input v-model="ef.fecha_egreso" type="date" class="input" /></div>
        <label v-if="ef.id" class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="ef.activo" type="checkbox" class="accent-carmin" /> Activo (entra en las liquidaciones)</label>
        <div class="sm:col-span-2 border-t border-marca-borde pt-3" data-e2e="asignados">
          <p class="label">Conceptos propios del empleado</p>
          <p class="text-xs text-marca-muted mb-2">Los marcados "solo asignados" (como el seguro CEC) se liquidan únicamente a quien los tenga. Un valor propio reemplaza al del concepto; vacío usa el general.</p>
          <div class="grid sm:grid-cols-2 gap-2">
            <label v-for="c in conceptos" :key="c.id" class="flex items-center gap-2 text-xs"><input type="checkbox" class="accent-carmin" :checked="!!asig(c.id)" @change="toggleAsig(c.id, $event.target.checked)" /><span class="font-mono">{{ c.codigo }}</span> <span class="truncate">{{ c.nombre }}</span><span v-if="c.solo_asignados" class="badge bg-violeta/10 text-violeta">solo asignados</span>
              <input v-if="asig(c.id)" v-model.number="asig(c.id).valor" type="number" step="0.01" class="input !py-0.5 !w-20 text-right ml-auto" :placeholder="String(c.valor)" /></label>
          </div>
        </div>
      </div>
      <template #pie><button class="btn-secondary" @click="empleadoAbierto = false">Cancelar</button><button class="btn-primary" :disabled="ef.processing || !ef.nombre || !ef.fecha_ingreso" @click="ef.post(`/sueldos/empleados/${ef.id || ''}`, { preserveScroll: true, onSuccess: () => (empleadoAbierto = false) })">Guardar</button></template>
    </Modal>

    <!-- Conceptos -->
    <Modal :abierto="conceptosAbierto" titulo="Conceptos de liquidación" ancho="max-w-5xl" @cerrar="conceptosAbierto = false">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3 rounded-xl bg-violeta/5 px-3 py-2">
        <p class="text-xs text-marca-muted">Cada concepto: <b>%</b> de una base, <b>importe fijo</b> o <b>base ÷ valor × cantidad</b> (ej. básico ÷ 30 × días). La base admite <code>BASICO</code>, <code>REM</code>, <code>NOREM</code> o códigos sumados: <code>REM+1238</code>.</p>
        <div class="flex gap-2"><button v-for="(l, k) in plantillas" :key="k" class="btn-primary !py-1 text-xs" data-e2e="plantilla" @click="plantillaConfirm = k">Cargar {{ l }}</button></div>
      </div>
      <div v-if="plantillaConfirm" class="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs flex flex-wrap items-center gap-2">
        <span>Se desactivan los conceptos actuales y se cargan los de {{ plantillas[plantillaConfirm] }}. Las sumas del acuerdo (1200 y 1238) hay que actualizarlas con cada paritaria.</span>
        <button class="btn-primary !py-1 text-xs" data-e2e="plantilla-ok" @click="router.post(`/sueldos/plantilla/${plantillaConfirm}`, {}, { preserveScroll: true, onSuccess: () => { plantillaConfirm = null; conceptosAbierto = false } })">Sí, cargar</button>
        <button class="btn-ghost !py-1 text-xs" @click="plantillaConfirm = null">Cancelar</button>
      </div>
      <div class="overflow-x-auto"><table class="table text-xs">
        <thead><tr><th>Código</th><th>Concepto</th><th>Tipo</th><th>Cálculo</th><th class="text-right">Valor</th><th>Base</th><th>Cantidad</th><th></th></tr></thead>
        <tbody>
          <template v-for="c in conceptosEdit" :key="c.id">
            <tr>
              <td class="font-mono">{{ c.codigo }}</td><td><input v-model="c.nombre" class="input !py-1 min-w-[12rem]" /></td><td class="text-marca-muted whitespace-nowrap">{{ tiposConcepto[c.tipo] }}</td>
              <td><select v-model="c.modo" class="input !py-1"><option v-for="(l, k) in modos" :key="k" :value="k">{{ l }}</option></select></td>
              <td><input v-model.number="c.valor" type="number" step="0.0001" class="input !py-1 !w-24 text-right" /></td>
              <td><input v-model="c.base" class="input !py-1 !w-28 font-mono" /></td>
              <td><select v-model="c.cantidad" class="input !py-1"><option v-for="(l, k) in cantidades" :key="k" :value="k || null">{{ l }}</option></select></td>
              <td class="text-right whitespace-nowrap"><button class="btn-ghost !px-2 text-xs" @click="c._mas = !c._mas">{{ c._mas ? 'Menos' : 'Más' }}</button><button class="btn-ghost !px-2 text-xs" @click="router.post(`/sueldos/conceptos/${c.id}`, c, { preserveScroll: true })">Guardar</button><button class="btn-ghost !px-2 text-xs text-carmin" @click="router.post(`/sueldos/conceptos/${c.id}/borrar`, {}, { preserveScroll: true })">Quitar</button></td>
            </tr>
            <tr v-if="c._mas" class="bg-gris-light/40"><td></td><td colspan="7">
              <div class="flex flex-wrap items-center gap-x-4 gap-y-2 py-1">
                <label class="flex items-center gap-1"><input v-model="c.por_anio" type="checkbox" class="accent-carmin" /> × años de antigüedad</label>
                <label class="flex items-center gap-1">+ <input v-model.number="c.mas_antiguedad" type="number" step="0.01" class="input !py-0.5 !w-16 text-right" /> % por año</label>
                <label class="flex items-center gap-1"><input v-model="c.proporcional_jornada" type="checkbox" class="accent-carmin" /> Fijo proporcional a la jornada</label>
                <label class="flex items-center gap-1"><input v-model="c.jornada_completa" type="checkbox" class="accent-carmin" /> Base llevada a jornada completa</label>
                <label class="flex items-center gap-1"><input v-model="c.con_detraccion" type="checkbox" class="accent-carmin" /> Resta la detracción</label>
                <label class="flex items-center gap-1"><input v-model="c.solo_asignados" type="checkbox" class="accent-carmin" /> Solo empleados asignados</label>
                <label class="flex items-center gap-1">Grupo <select v-model="c.grupo" class="input !py-0.5"><option :value="null">—</option><option v-for="(l, k) in grupos" :key="k" :value="k">{{ l }}</option></select></label>
                <label class="flex items-center gap-1">Orden <input v-model.number="c.orden" type="number" class="input !py-0.5 !w-16" /></label>
              </div>
            </td></tr>
          </template>
          <tr>
            <td><input v-model="nc.codigo" class="input !py-1 !w-20" placeholder="COD" /></td><td><input v-model="nc.nombre" class="input !py-1" placeholder="Nuevo concepto" /></td>
            <td><select v-model="nc.tipo" class="input !py-1"><option v-for="(l, k) in tiposConcepto" :key="k" :value="k">{{ l }}</option></select></td>
            <td><select v-model="nc.modo" class="input !py-1"><option v-for="(l, k) in modos" :key="k" :value="k">{{ l }}</option></select></td>
            <td><input v-model.number="nc.valor" type="number" step="0.0001" class="input !py-1 !w-24 text-right" /></td>
            <td><input v-model="nc.base" class="input !py-1 !w-28 font-mono" /></td>
            <td><select v-model="nc.cantidad" class="input !py-1"><option v-for="(l, k) in cantidades" :key="k" :value="k || null">{{ l }}</option></select></td>
            <td class="text-right"><button class="btn-primary !py-1 text-xs" :disabled="!nc.codigo || !nc.nombre" @click="nc.post('/sueldos/conceptos', { preserveScroll: true, onSuccess: () => nc.reset() })">Agregar</button></td>
          </tr>
        </tbody>
      </table></div>
      <p v-if="Object.keys(nc.errors).length" class="text-carmin text-xs mt-2">{{ Object.values(nc.errors).join(' ') }}</p>
    </Modal>

    <!-- Configuración -->
    <Modal :abierto="configAbierto" titulo="Configuración de sueldos" ancho="max-w-2xl" @cerrar="configAbierto = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Día de pago (mes siguiente)</label><input v-model.number="cf.dia_pago" type="number" min="1" max="28" class="input" /></div>
        <div><label class="label">Cuenta de pago habitual</label><select v-model="cf.cuenta_id" class="input"><option :value="null">—</option><option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div>
        <div class="sm:col-span-2"><label class="label">Actividad</label><input v-model="cf.actividad" class="input" placeholder="Servicios de Informática N.C.P." /></div>
        <div><label class="label">Convenio</label><input v-model="cf.convenio" class="input" placeholder="COMERCIO CCT N° 130/75" /></div><div><label class="label">Obra social</label><input v-model="cf.obra_social" class="input" placeholder="OSECAC - COMERCIO" /></div>
        <div><label class="label">Banco de depósito de aportes</label><input v-model="cf.deposito_banco" class="input" placeholder="BCO.REGIONAL" /></div><div><label class="label">Lugar de pago</label><input v-model="cf.lugar_pago" class="input" /></div>
        <div><label class="label">Detracción (jornada completa)</label><input v-model.number="cf.detraccion" type="number" step="0.01" class="input" /><p class="text-[11px] text-marca-muted mt-1">Se resta de la base de contribuciones, proporcional a la jornada.</p></div>
        <div><label class="label">Redondeo del neto</label><select v-model="cf.redondeo" class="input"><option value="no">Sin redondeo</option><option value="peso">Al peso siguiente</option></select><input v-if="cf.redondeo === 'peso'" v-model="cf.codigo_redondeo" class="input mt-1" placeholder="Código (3950)" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="configAbierto = false">Cancelar</button><button class="btn-primary" :disabled="cf.processing" @click="cf.post('/sueldos/config', { preserveScroll: true, onSuccess: () => (configAbierto = false) })">Guardar</button></template>
    </Modal>

    <!-- Detalle recibo -->
    <Modal :abierto="!!detalle" :titulo="detalle?.empleado" @cerrar="detalle = null">
      <table v-if="detalle" class="table text-sm">
        <tbody>
          <tr v-for="(d, i) in detalle.detalle" :key="i"><td><span class="font-mono text-xs text-marca-muted mr-1">{{ d.codigo }}</span>{{ d.nombre }}<span v-if="d.cantidad != null" class="text-xs text-marca-muted"> · {{ d.cantidad }}</span></td><td class="text-right tabular-nums" :class="{ 'text-carmin': d.tipo === 'deduccion', 'text-marca-muted': d.tipo === 'contribucion' }">{{ d.tipo === 'deduccion' ? '−' : '' }}{{ moneda(d.monto) }}<span v-if="d.tipo === 'contribucion'" class="text-[10px] ml-1">(patronal)</span></td></tr>
          <tr class="font-extrabold"><td>Neto</td><td class="text-right tabular-nums">{{ moneda(detalle.neto) }}</td></tr>
        </tbody>
      </table>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, hoyISO } from '@/util/formato'
const props = defineProps({ empleados: Array, conceptos: Array, tiposConcepto: Object, tiposLiq: Object, estados: Object, liquidaciones: Array, periodo: String, tipo: String, enCurso: Object, cuentas: Array, kpis: Object, config: Object, modos: Object, cantidades: Object, grupos: Object, plantillas: Object, jornadas: Object })
const fmt = iso => iso ? iso.split('-').reverse().join('/') : ''
const liquidarAbierto = ref(false), importarAbierto = ref(false), pagarAbierto = ref(false), empleadoAbierto = ref(false), conceptosAbierto = ref(false), configAbierto = ref(false), plantillaConfirm = ref(null), detalle = ref(null), anticipo = ref(null)
const nov = {}; props.empleados.forEach(e => { nov[e.id] = { dias: 30, feriados: 0, vacaciones: 0, horas_extra_50: 0, horas_extra_100: 0, adicionales: 0, no_rem_extra: 0, anticipos: e.anticipos || 0, excluir: false } })
const lq = useForm({ periodo: props.periodo, tipo: props.tipo, fecha: '', novedades: nov })
const im = useForm({ periodo: props.periodo, tipo: 'mensual', csv: '' })
const pg = useForm({ cuenta_id: props.config?.cuenta_id ?? props.cuentas[0]?.id ?? null, fecha: hoyISO(), cargas: false })
// Las novedades se arman al abrir: así incluyen a los empleados dados de alta después de cargar la pantalla.
function abrirLiquidar() { props.empleados.forEach(e => { lq.novedades[e.id] ??= { dias: 30, feriados: 0, vacaciones: 0, horas_extra_50: 0, horas_extra_100: 0, adicionales: 0, no_rem_extra: 0, anticipos: e.anticipos || 0, excluir: false } }); liquidarAbierto.value = true }
function ajustarDias(id) { const n = lq.novedades[id]; n.dias = Math.max(0, 30 - (n.feriados || 0) - (n.vacaciones || 0)) }
const dep = useForm({ deposito_fecha: props.enCurso?.deposito_fecha ?? '', deposito_banco: props.enCurso?.deposito_banco ?? props.config?.deposito_banco ?? '', deposito_periodo: props.enCurso?.deposito_periodo ?? '' })
const cf = useForm({ ...props.config })
const ef = useForm({ id: null, nombre: '', cuil: '', documento: '', centro_costo: '', lugar_trabajo: '', jornada: '1', asignados: [], fecha_ingreso: hoyISO(), fecha_egreso: '', categoria: '', convenio: '', puesto: '', obra_social: '', sueldo_basico: 0, modalidad: 'mensual', cbu: '', telefono: '', email: '', activo: true })
function abrirEmpleado(e = null) { ef.clearErrors(); Object.assign(ef, { id: e?.id ?? null, nombre: e?.nombre ?? '', cuil: e?.cuil ?? '', fecha_ingreso: e?.fecha_ingreso ?? hoyISO(), fecha_egreso: '', categoria: e?.categoria ?? '', convenio: e?.convenio ?? '', puesto: e?.puesto ?? '', obra_social: e?.obra_social ?? '', sueldo_basico: e?.sueldo_basico ?? 0, modalidad: e?.modalidad ?? 'mensual', cbu: e?.cbu ?? '', telefono: e?.telefono ?? '', email: e?.email ?? '', activo: e?.activo ?? true, documento: e?.documento ?? '', centro_costo: e?.centro_costo ?? '', lugar_trabajo: e?.lugar_trabajo ?? '', jornada: e?.jornada ?? '1', asignados: (e?.asignados ?? []).map(a => ({ ...a })) }); empleadoAbierto.value = true }
const asig = id => ef.asignados.find(a => a.id === id)
function toggleAsig(id, on) { ef.asignados = on ? [...ef.asignados, { id, valor: null }] : ef.asignados.filter(a => a.id !== id) }
const conceptosEdit = reactive(props.conceptos.map(c => ({ ...c })))
const nc = useForm({ codigo: '', nombre: '', tipo: 'haber', modo: 'porcentaje', valor: 0, base: 'BASICO', cantidad: null })
function leerArchivo(e) { const f = e.target.files[0]; if (!f) return; const r = new FileReader(); r.onload = () => (im.csv = r.result); r.readAsText(f, 'utf-8') }
</script>
