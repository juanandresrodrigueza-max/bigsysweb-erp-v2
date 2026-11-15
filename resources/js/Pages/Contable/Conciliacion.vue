<template>
  <AppLayout titulo="Conciliación bancaria">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Conciliación bancaria</h1><p class="page-subtitle">Subís el extracto del home banking y se cruza solo con los movimientos del sistema. Lo que no coincide, lo resolvés acá.</p></div>
      <div class="flex gap-2">
        <select :value="cuentaId" @change="$inertia.get('/contable/conciliacion', { cuenta: $event.target.value })" class="input w-auto"><option v-for="b in bancos" :key="b.id" :value="b.id">{{ b.nombre }}</option></select>
        <button v-if="puede('contable','crear') && mercadopago.es_billetera && mercadopago.conectado" @click="router.post('/contable/conciliacion/mercadopago', { cuenta: cuentaId }, { preserveScroll: true })" class="btn-primary" data-e2e="traer-mp">Traer de Mercado Pago</button>
        <button v-if="puede('contable','crear')" @click="impAbierto = true" :class="mercadopago.es_billetera && mercadopago.conectado ? 'btn-secondary' : 'btn-primary'">Importar extracto</button>
        <button v-if="puede('contable','crear')" @click="reglasAbierto = true" class="btn-ghost" data-e2e="reglas">Reglas <span v-if="reglas.length" class="badge bg-violeta/10 text-violeta ml-1">{{ reglas.length }}</span></button>
      </div>
    </div>
    <!-- Fase 99: la cuenta de Mercado Pago se trae sola por API -->
    <div v-if="mercadopago.es_billetera" class="card mb-4 flex flex-wrap items-center gap-3 text-sm" data-e2e="mp-config">
      <span class="font-semibold">Mercado Pago</span>
      <template v-if="!mercadopago.conectado"><span class="text-marca-muted">No está conectado.</span><Link href="/configuracion#mercadopago" class="underline text-violeta">Conectar en Configuración</Link></template>
      <template v-else>
        <label class="flex items-center gap-2"><input type="checkbox" class="accent-carmin" :checked="mercadopago.activo" :disabled="!puede('contable','editar')" @change="router.post('/contable/conciliacion/mercadopago/config', { cuenta: cuentaId, activo: $event.target.checked, automatico: true }, { preserveScroll: true })" /> Esta es la cuenta de Mercado Pago: cobros, comisiones y devoluciones entran solos cada madrugada</label>
        <span v-if="mercadopago.ultima" class="text-xs text-marca-muted">Última vez: {{ mercadopago.ultima }}</span>
        <span v-if="mercadopago.otra_cuenta" class="text-xs text-amber-700">Hoy la cuenta marcada es «{{ mercadopago.otra_cuenta }}».</span>
      </template>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Saldo según sistema</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.saldo_sistema, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Saldo según banco</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.saldo_extracto !== null ? moneda(resumen.saldo_extracto, 0) : '—' }}</p><p v-if="resumen.ultimo_extracto" class="text-[11px] text-marca-muted">extracto {{ resumen.ultimo_extracto.desde }}–{{ resumen.ultimo_extracto.hasta }} · {{ resumen.ultimo_extracto.items }} renglones</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En el banco, no en el sistema</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.pendientes_extracto ? 'text-amber-600' : 'text-emerald-700'">{{ resumen.pendientes_extracto }}</p><p class="text-[11px] text-marca-muted">{{ moneda(resumen.monto_pend_extracto, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En el sistema, no en el banco</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.pendientes_sistema ? 'text-amber-600' : 'text-emerald-700'">{{ resumen.pendientes_sistema }}</p><p class="text-[11px] text-marca-muted">{{ moneda(resumen.monto_pend_sistema, 0) }}</p></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 card p-0 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-marca-borde">
          <h2 class="font-bold">Extracto del banco</h2>
          <div class="flex gap-1 bg-marca-fondo rounded-full p-1">
            <button v-for="e in [['pendiente','Pendientes'],['conciliado','Conciliados'],['ignorado','Ignorados'],['todos','Todos']]" :key="e[0]" @click="$inertia.get('/contable/conciliacion', { cuenta: cuentaId, estado: e[0] }, { preserveState: true, replace: true })" class="px-3 py-1 rounded-full text-xs font-semibold" :class="estado === e[0] ? 'bg-white shadow text-carmin' : 'text-marca-muted'">{{ e[1] }}</button>
          </div>
          <Link v-if="puede('contable','crear')" :href="`/contable/conciliacion/automatica?cuenta=${cuentaId}`" method="post" as="button" preserve-scroll class="btn-secondary !py-1 text-xs">Conciliar automáticamente</Link>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead><tr><th>Fecha</th><th>Descripción</th><th class="text-right">Importe</th><th>Estado</th><th></th></tr></thead>
            <tbody>
              <tr v-for="it in items" :key="it.id" :class="it.estado === 'ignorado' ? 'opacity-50' : ''">
                <td class="tabular-nums text-marca-muted whitespace-nowrap">{{ it.fecha }}</td>
                <td><p class="font-medium text-sm">{{ it.descripcion }}</p><p v-if="it.referencia" class="text-xs text-marca-muted">{{ it.referencia }}</p><p v-if="it.movimiento" class="text-xs text-emerald-700">↔ {{ it.movimiento.fecha }} · {{ it.movimiento.concepto }}</p></td>
                <td class="text-right tabular-nums font-semibold" :class="it.monto < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(it.monto) }}</td>
                <td><span class="badge" :class="{ pendiente: 'bg-amber-50 text-amber-700', conciliado: 'bg-emerald-50 text-emerald-700', ignorado: 'bg-gris-light text-marca-muted' }[it.estado]">{{ it.estado }}<span v-if="it.match === 'auto'"> · auto</span></span></td>
                <td class="text-right whitespace-nowrap">
                  <template v-if="puede('contable','crear')">
                    <template v-if="it.estado === 'pendiente'">
                      <button @click="vincular = it" class="btn-ghost !px-2 text-xs">Vincular</button>
                      <button @click="registrar = { ...it, expense_category_id: it.categoria?.id ?? null, concepto: it.descripcion }" class="btn-ghost !px-2 text-xs text-violeta">Registrar</button>
                      <button @click="ignorar = it" class="btn-ghost !px-2 text-xs">Ignorar</button>
                    </template>
                    <Link v-else-if="it.estado === 'conciliado'" :href="`/contable/conciliacion/${it.id}/desvincular`" method="post" as="button" preserve-scroll class="btn-ghost !px-2 text-xs text-carmin">Deshacer</Link>
                    <Link v-else :href="`/contable/conciliacion/${it.id}/ignorar`" method="post" as="button" preserve-scroll class="btn-ghost !px-2 text-xs">Reactivar</Link>
                  </template>
                </td>
              </tr>
              <tr v-if="!items.length"><td colspan="5" class="text-center text-marca-muted py-10">{{ estado === 'pendiente' ? 'Nada pendiente. Todo lo del banco está en el sistema.' : 'Sin renglones.' }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card p-0 overflow-hidden">
        <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Sistema sin conciliar</h2><p class="text-xs text-marca-muted">Movimientos que todavía no aparecen en el extracto.</p></div>
        <div class="max-h-[60vh] overflow-y-auto">
          <div v-for="m in sinConciliar" :key="m.id" class="px-4 py-2 border-b border-marca-borde/60 text-sm flex justify-between gap-2"><div class="min-w-0"><p class="font-medium truncate">{{ m.concepto }}</p><p class="text-xs text-marca-muted">{{ m.fecha }}<span v-if="m.referencia"> · {{ m.referencia }}</span> · {{ m.origen }}</p></div><span class="tabular-nums font-semibold shrink-0" :class="m.monto < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(m.monto, 0) }}</span></div>
          <p v-if="!sinConciliar.length" class="px-4 py-8 text-center text-sm text-marca-muted">Todo conciliado.</p>
        </div>
      </div>
    </div>

    <!-- Fase 35.3: los tres cajones del contador y el puente de saldos -->
    <div v-if="cajones" class="grid lg:grid-cols-3 gap-4 mt-4" data-e2e="cajones">
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-1">Está en el banco y no en el sistema</h2>
        <p class="text-xs text-marca-muted mb-2">La lista para cargar, por concepto. Las acreditaciones de tarjetas van aparte: en el sistema son cupones, se concilian en Fondos → Conciliar.</p>
        <button v-if="puede('contable','crear') && $page.props.auth?.ia" class="btn-secondary !py-1 text-xs mb-2" @click="router.post('/contable/conciliacion/clasificar-ia', { cuenta: cuentaId }, { preserveScroll: true })" data-e2e="clasificar-ia">✦ Clasificar con IA lo que no tiene categoría</button>
        <p v-if="cajones.aviso_centralizadora" class="rounded-lg bg-amber-50 text-amber-800 text-xs px-3 py-1.5 mb-2">{{ cajones.aviso_centralizadora }}</p>
        <details v-for="g in cajones.solo_banco.grupos" :key="g.clase" class="border-t border-marca-borde py-1.5 text-sm" :data-grupo="g.clase">
          <summary class="flex items-center justify-between gap-2 cursor-pointer"><span><b>{{ g.nombre }}</b> <span class="text-xs text-marca-muted">· {{ g.cantidad }}</span></span>
            <span class="flex items-center gap-2"><b class="tabular-nums" :class="g.monto < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(g.monto) }}</b>
              <button v-if="g.es_gasto && puede('contable','crear')" class="btn-primary !py-0.5 !px-2 text-xs" @click.prevent="router.post('/contable/conciliacion/registrar-grupo', { cuenta: cuentaId, clase: g.clase }, { preserveScroll: true })" :data-registrar="g.clase">Registrar todos</button>
              <Link v-else-if="g.clase === 'tarjeta'" href="/fondos/conciliar" class="text-xs text-violeta font-semibold">Conciliar tarjetas →</Link></span></summary>
          <div v-for="it in g.items" :key="it.id" class="flex justify-between gap-2 text-xs py-0.5 pl-3">
            <span>{{ it.fecha }} · {{ it.descripcion }}<span v-if="it.aprendido" class="text-violeta"> · aprendido</span><span v-if="it.categoria" class="badge bg-marca-fondo text-marca-muted ml-1" :title="'Categoría sugerida (' + it.categoria.origen + ')'">{{ it.categoria.nombre }}</span></span>
            <span class="flex items-center gap-2"><span class="tabular-nums">{{ moneda(it.monto) }}</span>
              <template v-if="it.monto > 0 && puede('clientes','crear')">
                <button v-if="deClientes[it.id]" class="btn-primary !py-0.5 !px-2 text-[11px]" @click="hacerRecibo(it.id, deClientes[it.id].contact_id)" :data-recibo="it.id" :title="'Reconocido por ' + deClientes[it.id].por">Recibo a {{ deClientes[it.id].cliente }}</button>
                <select v-else class="input !py-0 !text-[11px] !w-36" @change="$event.target.value && hacerRecibo(it.id, $event.target.value)" :data-recibo-elegir="it.id"><option value="">Recibo a…</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
              </template>
              <select v-if="puede('contable','crear')" class="input !py-0 !text-[11px] !w-auto" :value="g.clase" @change="router.post(`/contable/conciliacion/${it.id}/clasificar`, { clase: $event.target.value }, { preserveScroll: true })"><option v-for="(l, k) in clases" :key="k" :value="k">{{ l }}</option></select></span>
          </div>
        </details>
        <p v-if="!cajones.solo_banco.grupos.length" class="text-sm text-emerald-700">Nada: todo lo del banco está en el sistema.</p>
      </div>
      <div class="space-y-4">
        <div class="card text-sm" data-e2e="puente">
          <h2 class="font-bold mb-1">El puente</h2>
          <div class="flex justify-between"><span>En los dos (conciliado)</span><b class="tabular-nums">{{ cajones.en_los_dos.cantidad }}</b></div>
          <div class="flex justify-between"><span>Saldo del libro</span><b class="tabular-nums">{{ moneda(cajones.puente.libro) }}</b></div>
          <div class="flex justify-between"><span>+ falta registrar</span><b class="tabular-nums">{{ moneda(cajones.puente.falta_registrar) }}</b></div>
          <div class="flex justify-between"><span>− en la calle</span><b class="tabular-nums">{{ moneda(cajones.puente.en_la_calle) }}</b></div>
          <div class="flex justify-between border-t border-marca-borde mt-1 pt-1"><span>Extracto</span><b class="tabular-nums">{{ cajones.puente.extracto !== null ? moneda(cajones.puente.extracto) : '—' }}</b></div>
          <p class="text-xs mt-1" :class="cajones.puente.diferencia !== null && Math.abs(cajones.puente.diferencia) >= 0.01 ? 'text-carmin font-semibold' : 'text-emerald-700'">{{ cajones.puente.texto }}</p>
          <p v-if="cajones.cadena?.controlable" class="text-xs mt-1" :class="cajones.cadena.cierra ? 'text-emerald-700' : 'text-carmin'">{{ cajones.cadena.cierra ? 'La cadena de saldos del extracto cierra renglón por renglón.' : `La cadena de saldos no cierra en ${cajones.cadena.cantidad_rotos} renglones.` }}</p>
        </div>
        <div class="card text-sm">
          <h2 class="font-bold mb-1">En el sistema, todavía no en el banco</h2>
          <p class="text-xs text-marca-muted mb-1">Es normal: el cheque que emitiste y nadie cobró.</p>
          <div v-for="m in cajones.solo_sistema.items.slice(0, 12)" :key="m.id" class="flex justify-between text-xs py-0.5"><span>{{ m.fecha }} · {{ m.concepto }} <span class="text-marca-muted">({{ m.dias }} días)</span></span><span class="tabular-nums">{{ moneda(m.monto) }}</span></div>
        </div>
      </div>
    </div>

    <Modal :abierto="impAbierto" titulo="Importar extracto bancario" @cerrar="impAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Subí el extracto tal como lo baja el home banking: <b>PDF</b>, <b>Excel</b>, <b>CSV</b> o texto, de cualquier banco. El sistema detecta solo el formato, lee fecha, concepto, importe y saldo, y concilia automáticamente lo que coincide.</p>
      <select v-model="imp.cuenta_fondos_id" class="input mb-3"><option v-for="b in bancos" :key="b.id" :value="b.id">{{ b.nombre }}</option></select>
      <input type="file" accept=".pdf,.xlsx,.xls,.csv,.txt" class="input !py-1.5 text-xs" @change="imp.archivo = $event.target.files[0]" />
      <p v-if="imp.errors.archivo" class="text-carmin text-xs mt-2">{{ imp.errors.archivo }}</p>
      <template #pie><button class="btn-secondary" @click="impAbierto = false">Cancelar</button><button class="btn-primary" :disabled="imp.processing || !imp.archivo" @click="imp.post('/contable/conciliacion/importar', { forceFormData: true, onSuccess: () => (impAbierto = false) })">{{ imp.processing ? 'Importando…' : 'Importar y conciliar' }}</button></template>
    </Modal>

    <Modal :abierto="!!vincular" :titulo="vincular ? `Vincular: ${vincular.descripcion} (${moneda(vincular.monto)})` : ''" @cerrar="vincular = null">
      <p class="text-sm text-marca-muted mb-2">Elegí el movimiento del sistema que corresponde. Tiene que ser del mismo importe.</p>
      <div class="max-h-72 overflow-y-auto divide-y divide-marca-borde/60 border border-marca-borde rounded-xl">
        <button v-for="m in candidatos" :key="m.id" @click="router.post(`/contable/conciliacion/${vincular.id}/vincular`, { movimiento_id: m.id }, { preserveScroll: true, onSuccess: () => (vincular = null) })" class="w-full text-left px-3 py-2 text-sm hover:bg-marca-fondo flex justify-between gap-2"><span>{{ m.fecha }} · {{ m.concepto }}</span><b class="tabular-nums">{{ moneda(m.monto) }}</b></button>
        <p v-if="!candidatos.length" class="px-3 py-6 text-center text-sm text-marca-muted">No hay movimientos del sistema por ese importe. Si es un cargo del banco (comisión, impuesto), usá "Registrar".</p>
      </div>
    </Modal>

    <Modal :abierto="!!registrar" titulo="Registrar en Fondos" @cerrar="registrar = null">
      <template v-if="registrar">
        <p class="text-sm text-marca-muted mb-3">El banco {{ registrar.monto < 0 ? 'debitó' : 'acreditó' }} <b>{{ moneda(registrar.monto) }}</b> el {{ registrar.fecha }} y el sistema no lo tenía. Se crea el movimiento y queda conciliado.</p>
        <label class="label">Concepto</label><input v-model="registrar.concepto" class="input" />
        <template v-if="registrar.monto < 0"><label class="label mt-3">Categoría de gasto</label><select v-model="registrar.expense_category_id" class="input"><option :value="null">Sin categoría</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.name }}</option></select></template>
        <label class="flex items-center gap-2 text-sm mt-3" data-e2e="recordar"><input v-model="registrar.recordar" type="checkbox" class="accent-carmin" /> Recordar: la próxima vez que el extracto diga algo parecido, registrarlo solo así</label>
      </template>
      <template #pie><button class="btn-secondary" @click="registrar = null">Cancelar</button><button class="btn-primary" @click="router.post(`/contable/conciliacion/${registrar.id}/registrar`, { expense_category_id: registrar.expense_category_id, concepto: registrar.concepto, recordar: !!registrar.recordar }, { preserveScroll: true, onSuccess: () => (registrar = null) })">Registrar</button></template>
    </Modal>
    <!-- Fase 99: ignorar con «recordar» -->
    <Modal :abierto="!!ignorar" titulo="Ignorar este renglón" @cerrar="ignorar = null">
      <template v-if="ignorar">
        <p class="text-sm text-marca-muted mb-3">«{{ ignorar.descripcion }}» ({{ moneda(ignorar.monto) }}) no es algo del sistema (una transferencia entre cuentas propias, un movimiento que ya está de otra forma). Queda marcado como ignorado.</p>
        <label class="flex items-center gap-2 text-sm"><input v-model="ignorar.recordar" type="checkbox" class="accent-carmin" /> Recordar: los próximos renglones parecidos se ignoran solos</label>
      </template>
      <template #pie><button class="btn-secondary" @click="ignorar = null">Cancelar</button><button class="btn-primary" @click="router.post(`/contable/conciliacion/${ignorar.id}/ignorar`, { recordar: !!ignorar.recordar }, { preserveScroll: true, onSuccess: () => (ignorar = null) })">Ignorar</button></template>
    </Modal>

    <!-- Fase 99: reglas que actúan solas -->
    <Modal :abierto="reglasAbierto" titulo="Reglas de conciliación" ancho="max-w-3xl" @cerrar="reglasAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Cuando un renglón del banco o de Mercado Pago dice lo que pide la regla, se resuelve solo al importar: se registra como gasto en su categoría, se ignora, o se vuelve recibo del cliente. Se crean con «Recordar» cuando lo hacés a mano, o acá.</p>
      <div class="grid sm:grid-cols-[1fr_130px_150px_1fr_auto] gap-2 items-end mb-4 p-3 rounded-xl bg-marca-fondo" data-e2e="regla-nueva">
        <div><label class="label">Si dice</label><input v-model="nr.patron" class="input !py-1 text-sm" placeholder="edemsa, comision mantenimiento…" /></div>
        <div><label class="label">Sentido</label><select v-model="nr.sentido" class="input !py-1 text-sm"><option value="ambos">Ambos</option><option value="egreso">Débito</option><option value="ingreso">Crédito</option></select></div>
        <div><label class="label">Hacer</label><select v-model="nr.accion" class="input !py-1 text-sm"><option v-for="(l, k) in acciones" :key="k" :value="k">{{ l }}</option></select></div>
        <div v-if="nr.accion === 'gasto'"><label class="label">Categoría</label><select v-model="nr.expense_category_id" class="input !py-1 text-sm"><option :value="null">La que sugiera el sistema</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div v-else-if="nr.accion === 'recibo'"><label class="label">Cliente</label><select v-model="nr.contact_id" class="input !py-1 text-sm"><option :value="null">Elegí</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div>
        <div v-else></div>
        <button class="btn-primary !py-1.5 text-sm" :disabled="nr.processing || nr.patron.length < 3 || (nr.accion === 'recibo' && !nr.contact_id)" @click="nr.post('/contable/conciliacion/reglas', { preserveScroll: true, onSuccess: () => nr.reset('patron', 'contact_id') })">Agregar</button>
      </div>
      <table class="table text-sm">
        <thead><tr><th>Si dice</th><th>Sentido</th><th>Hace</th><th class="text-right">Veces</th><th></th></tr></thead>
        <tbody>
          <tr v-for="r in reglas" :key="r.id">
            <td><span class="font-mono text-xs">{{ r.patron }}</span><p v-if="r.cuenta" class="text-[11px] text-marca-muted">sólo {{ r.cuenta }}</p></td>
            <td class="text-xs">{{ { ambos: 'Ambos', egreso: 'Débito', ingreso: 'Crédito' }[r.sentido] }}</td>
            <td>{{ r.accion_label }}<span v-if="r.categoria" class="text-marca-muted"> · {{ r.categoria }}</span><span v-if="r.cliente" class="text-marca-muted"> · {{ r.cliente }}</span><span v-if="r.concepto" class="text-marca-muted"> · «{{ r.concepto }}»</span></td>
            <td class="text-right tabular-nums">{{ r.veces }}<p v-if="r.ultima_vez" class="text-[11px] text-marca-muted">{{ r.ultima_vez }}</p></td>
            <td class="text-right"><button class="text-marca-muted hover:text-carmin" title="Borrar" aria-label="Borrar" @click="router.post(`/contable/conciliacion/reglas/${r.id}/borrar`, {}, { preserveScroll: true })">×</button></td>
          </tr>
          <tr v-if="!reglas.length"><td colspan="5" class="text-center text-sm text-marca-muted py-6">Todavía no hay reglas. Al registrar o ignorar un renglón, marcá «Recordar».</td></tr>
        </tbody>
      </table>
      <template #pie><button class="btn-secondary" @click="reglasAbierto = false">Cerrar</button><button v-if="reglas.length" class="btn-primary" @click="router.post('/contable/conciliacion/reglas/aplicar', { cuenta: cuentaId }, { preserveScroll: true })">Aplicar a los pendientes</button></template>
    </Modal>
  </AppLayout>
</template>
<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ bancos: Array, cuentaId: Number, estado: String, items: Array, sinConciliar: Array, resumen: Object, categorias: Array, cajones: Object, clases: Object, deClientes: { type: Object, default: () => ({}) }, clientes: { type: Array, default: () => [] }, reglas: { type: Array, default: () => [] }, acciones: { type: Object, default: () => ({}) }, mercadopago: { type: Object, default: () => ({}) } })
function hacerRecibo(id, contactId) { router.post(`/contable/conciliacion/${id}/recibo`, { contact_id: contactId }, { preserveScroll: true }) }
const { puede } = usePermisos()
const impAbierto = ref(false), vincular = ref(null), registrar = ref(null), ignorar = ref(null), reglasAbierto = ref(false)
const nr = useForm({ patron: '', sentido: 'ambos', accion: 'gasto', expense_category_id: null, contact_id: null, concepto: '', cuenta_fondos_id: null, aplicar: props.cuentaId })
const imp = useForm({ cuenta_fondos_id: props.cuentaId, archivo: null })
const candidatos = computed(() => vincular.value ? props.sinConciliar.filter(m => Math.abs(m.monto - vincular.value.monto) < 0.01) : [])
</script>
