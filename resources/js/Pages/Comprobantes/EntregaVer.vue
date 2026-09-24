<template>
  <AppLayout :titulo="orden.numero">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/comprobantes/entregas" class="text-xs text-marca-muted hover:text-carmin">← Hojas de reparto</Link>
        <h1 class="page-title flex items-center gap-3">{{ orden.numero }} <span class="badge text-sm" :class="{ pendiente: 'bg-gris-light text-marca-muted', en_curso: 'bg-violeta-light text-violeta', entregada: 'bg-emerald-50 text-emerald-700', cancelada: 'bg-carmin-light text-carmin' }[orden.estado]">{{ estados[orden.estado] }}</span><span v-if="rendicion" class="badge text-sm bg-emerald-50 text-emerald-700" data-rendida>Rendida</span></h1>
        <p class="page-subtitle">{{ orden.fecha }} · {{ orden.repartidor ?? 'sin repartidor' }}<span v-if="orden.vehiculo"> · {{ orden.vehiculo }}</span> · cargó {{ orden.usuario }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a :href="`/comprobantes/entregas/${orden.id}/imprimir`" target="_blank" class="btn-secondary">Imprimir hoja</a>
        <button v-if="orden.estado === 'pendiente' && puede('comprobantes','editar')" @click="estado('en_curso')" class="btn-violeta">Salió a repartir</button>
        <button v-if="!rendicion && orden.estado !== 'cancelada' && puede('fondos','crear')" @click="abrirRendir" class="btn-primary" data-rendir>Rendir viaje</button>
        <button v-if="['pendiente','en_curso'].includes(orden.estado) && puede('comprobantes','editar')" @click="estado('cancelada')" class="btn-ghost text-carmin">Cancelar</button>
      </div>
    </div>

    <div class="grid sm:grid-cols-4 gap-3 mb-4">
      <div v-for="(l, k) in medios" :key="k" class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ l }}</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(rendicion ? rendicion.esperado[k] : esperado[k]) }}</p><p class="text-[11px] text-marca-muted">{{ rendicion ? 'rendido' : 'a rendir' }}</p></div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table min-w-[860px]">
        <thead><tr><th>#</th><th>Cliente y dirección</th><th>Comprobante</th><th>Mercadería</th><th class="text-right">A cobrar</th><th>Cobrado</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="(i, n) in orden.items" :key="i.id" class="align-top" data-item>
            <td class="text-marca-muted">{{ n + 1 }}</td>
            <td><p class="font-semibold">{{ i.cliente }}</p><p class="text-xs text-marca-muted">{{ i.direccion ?? 'sin dirección' }}<span v-if="i.telefono"> · {{ i.telefono }}</span></p></td>
            <td><Link :href="`/comprobantes/${i.comprobante_id}`" class="hover:text-carmin">{{ i.comprobante }}</Link></td>
            <td class="text-xs max-w-xs">{{ i.detalle }}</td>
            <td class="text-right tabular-nums">{{ i.saldo > 0 ? moneda(i.saldo) : '—' }}<p v-if="i.saldo_cliente > i.saldo + 0.01" class="text-[10px] text-marca-muted">debe {{ moneda(i.saldo_cliente, 0) }} en total</p></td>
            <td class="text-xs">
              <p v-for="c in i.cobros" :key="c.id" class="flex items-center gap-1 whitespace-nowrap">{{ medios[c.medio] }} <b class="tabular-nums">{{ moneda(c.monto) }}</b><span v-if="c.datos?.numero" class="text-marca-muted">· ch. {{ c.datos.numero }}</span><span v-if="c.recibo" class="text-emerald-700">· {{ c.recibo }}</span><button v-else-if="!rendicion && puede('comprobantes','editar')" type="button" class="text-marca-muted hover:text-carmin" title="Quitar" @click="router.delete(`/comprobantes/entregas/cobros/${c.id}`, { preserveScroll: true })">✕</button></p>
              <button v-if="!rendicion && orden.estado !== 'cancelada' && puede('comprobantes','editar')" type="button" class="text-violeta font-semibold" @click="abrirCobro(i)" data-anotar-cobro>+ Cobro</button>
            </td>
            <td>
              <div v-if="i.estado === 'pendiente' && puede('comprobantes','editar')" class="flex gap-1">
                <button @click="marcar(i, 'entregado')" class="btn-primary !py-1 text-xs" data-entregado>Entregado</button>
                <button @click="noEntregado = i; obs = ''" class="btn-ghost !py-1 text-xs text-carmin">No se pudo</button>
              </div>
              <span v-else class="badge" :class="i.estado === 'entregado' ? 'bg-emerald-50 text-emerald-700' : 'bg-carmin-light text-carmin'">{{ i.estado === 'entregado' ? 'Entregado' : 'No entregado' }}<span v-if="i.observacion" class="block text-[10px] font-normal">{{ i.observacion }}</span></span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="orden.notas" class="text-sm text-marca-muted mt-3">{{ orden.notas }}</p>

    <!-- Rendición hecha -->
    <div v-if="rendicion" class="card mt-4 grid sm:grid-cols-2 gap-4 text-sm" data-resumen-rendicion>
      <div>
        <h2 class="font-bold mb-2">Rendición del {{ rendicion.fecha }}</h2>
        <div class="space-y-1">
          <div class="flex justify-between"><span>Efectivo cobrado</span><span class="tabular-nums">{{ moneda(rendicion.esperado.efectivo) }}</span></div>
          <div class="flex justify-between"><span>− Viáticos</span><span class="tabular-nums">{{ moneda(rendicion.total_viaticos) }}</span></div>
          <div class="flex justify-between font-semibold border-t border-marca-borde pt-1"><span>Tenía que rendir</span><span class="tabular-nums">{{ moneda(rendicion.debe_efectivo) }}</span></div>
          <div class="flex justify-between"><span>Rindió en {{ rendicion.caja }}</span><span class="tabular-nums">{{ moneda(rendicion.contado) }}</span></div>
          <div class="flex justify-between font-bold" :class="rendicion.diferencia < -0.005 ? 'text-carmin' : rendicion.diferencia > 0.005 ? 'text-emerald-700' : ''"><span>{{ rendicion.diferencia < -0.005 ? 'Faltante' : rendicion.diferencia > 0.005 ? 'Sobrante' : 'Diferencia' }}</span><span class="tabular-nums">{{ moneda(Math.abs(rendicion.diferencia)) }}</span></div>
        </div>
      </div>
      <div>
        <p class="label">Recibos generados</p><p>{{ rendicion.recibos.join(', ') || 'ninguno' }}</p>
        <p v-if="rendicion.viaticos.length" class="label mt-3">Viáticos</p><p v-for="(v, k) in rendicion.viaticos" :key="k">{{ v.concepto }} · {{ moneda(v.monto) }}</p>
        <p v-if="rendicion.notas" class="text-marca-muted mt-3">{{ rendicion.notas }}</p>
      </div>
    </div>

    <!-- Anotar cobro -->
    <Modal :abierto="!!cobroItem" :titulo="`Cobro de ${cobroItem?.cliente ?? ''}`" @cerrar="cobroItem = null">
      <form id="form-cobro" class="space-y-3 text-sm" @submit.prevent="guardarCobro">
        <div class="flex flex-wrap gap-2"><label v-for="(l, k) in medios" :key="k" class="flex items-center gap-2 rounded-xl border px-3 py-2 cursor-pointer" :class="cf.medio === k ? 'border-carmin bg-carmin-light/40' : 'border-marca-borde'"><input v-model="cf.medio" type="radio" :value="k" class="accent-carmin" /> {{ l }}</label></div>
        <div><label class="label">Importe</label><input v-model.number="cf.monto" type="number" min="0" step="0.01" class="input text-right" data-monto-cobro /><p v-if="cf.errors.monto" class="text-xs text-carmin">{{ cf.errors.monto }}</p></div>
        <div v-if="cf.medio === 'cheque'" class="grid grid-cols-3 gap-2">
          <div><label class="label">Banco</label><input v-model="cf.banco" class="input" /></div><div><label class="label">Número</label><input v-model="cf.numero" class="input" /></div><div><label class="label">Fecha de pago</label><input v-model="cf.fecha_pago" type="date" class="input" /></div>
          <p v-if="cf.errors.banco || cf.errors.numero" class="text-xs text-carmin col-span-3">Cargá el banco y el número del cheque.</p>
        </div>
        <div v-else><label class="label">Referencia (opcional)</label><input v-model="cf.referencia" class="input" placeholder="N° de operación" /></div>
      </form>
      <template #pie><button type="button" class="btn-secondary" @click="cobroItem = null">Cancelar</button><button type="submit" form="form-cobro" class="btn-primary" :disabled="cf.processing" data-guardar-cobro>Anotar</button></template>
    </Modal>

    <!-- No entregado -->
    <Modal :abierto="!!noEntregado" titulo="No se pudo entregar" @cerrar="noEntregado = null">
      <label class="label">¿Qué pasó? (queda en la hoja)</label><input v-model="obs" class="input" placeholder="No había nadie · dirección equivocada · rechazó la mercadería" />
      <template #pie><button type="button" class="btn-secondary" @click="noEntregado = null">Volver</button><button type="button" class="btn-primary" @click="marcar(noEntregado, 'no_entregado', obs); noEntregado = null">Guardar</button></template>
    </Modal>

    <!-- Rendir viaje -->
    <Modal :abierto="rindiendo" :titulo="`Rendir ${orden.numero}`" ancho="max-w-xl" @cerrar="rindiendo = false">
      <form id="form-rendir" class="space-y-3 text-sm" @submit.prevent="rendir">
        <p v-if="pendientes" class="rounded-xl bg-amber-50 text-amber-800 p-2 text-xs">Hay {{ pendientes }} entregas sin marcar. Marcalas antes de rendir.</p>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Caja donde rinde el efectivo</label><select v-model="rf.cuenta_fondos_id" class="input" data-caja><option v-for="c in cajas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div>
          <div><label class="label">Cuenta para transferencias y MP</label><select v-model="rf.cuenta_banco_id" class="input"><option :value="null">La de siempre</option><option v-for="c in bancos" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div>
        </div>
        <div>
          <p class="label">Viáticos del chofer (salen del efectivo)</p>
          <div v-for="(v, k) in rf.viaticos" :key="k" class="grid grid-cols-[1fr_120px_auto] gap-2 mb-1.5"><input v-model="v.concepto" class="input" placeholder="Combustible, peaje, comida…" :data-viatico="k" /><input v-model.number="v.monto" type="number" min="0" step="0.01" class="input text-right" placeholder="0" :data-viatico-monto="k" /><button type="button" class="text-marca-muted hover:text-carmin" @click="rf.viaticos.splice(k, 1)">✕</button></div>
          <button type="button" class="text-xs text-violeta font-semibold" @click="rf.viaticos.push({ concepto: '', monto: null })">+ Viático</button>
        </div>
        <div class="rounded-xl bg-marca-fondo p-3 space-y-1">
          <div class="flex justify-between"><span>Efectivo cobrado</span><span class="tabular-nums">{{ moneda(esperado.efectivo) }}</span></div>
          <div class="flex justify-between"><span>− Viáticos</span><span class="tabular-nums">{{ moneda(totalViaticos) }}</span></div>
          <div class="flex justify-between font-bold border-t border-marca-borde pt-1"><span>Tiene que rendir</span><span class="tabular-nums">{{ moneda(debe) }}</span></div>
          <p class="text-xs text-marca-muted">Cheques: {{ moneda(esperado.cheque) }} van a cartera. Transferencias y MP: {{ moneda(esperado.transferencia + esperado.mercadopago) }} al banco.</p>
        </div>
        <div><label class="label">Efectivo que entrega (contado)</label><input v-model.number="rf.efectivo_contado" type="number" min="0" step="0.01" class="input text-right text-lg font-bold" data-contado /></div>
        <p v-if="rf.efectivo_contado !== null && rf.efectivo_contado !== ''" class="font-bold" :class="dif < -0.005 ? 'text-carmin' : dif > 0.005 ? 'text-emerald-700' : 'text-marca-muted'" data-diferencia>{{ dif < -0.005 ? 'Faltante' : dif > 0.005 ? 'Sobrante' : 'Sin diferencia' }}<span v-if="Math.abs(dif) > 0.005"> de {{ moneda(Math.abs(dif)) }}</span></p>
        <div><label class="label">Notas</label><input v-model="rf.notas" class="input" /></div>
        <p v-if="rf.errors.rendir || rf.errors.cuenta_fondos_id" class="text-xs text-carmin">{{ rf.errors.rendir || rf.errors.cuenta_fondos_id }}</p>
      </form>
      <template #pie><button type="button" class="btn-secondary" @click="rindiendo = false">Cancelar</button><button type="submit" form="form-rendir" class="btn-primary" :disabled="rf.processing || !!pendientes || rf.efectivo_contado === null || rf.efectivo_contado === ''" data-confirmar-rendir>Rendir</button></template>
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

const props = defineProps({ orden: Object, estados: Object, rendicion: Object, esperado: { type: Object, default: () => ({ efectivo: 0, cheque: 0, transferencia: 0, mercadopago: 0 }) }, medios: Object, cajas: { type: Array, default: () => [] }, bancos: { type: Array, default: () => [] }, categorias: { type: Array, default: () => [] } })
const { puede } = usePermisos()
function estado(e) { router.post(`/comprobantes/entregas/${props.orden.id}/estado`, { estado: e }, { preserveScroll: true }) }
function marcar(i, e, observacion = null) { router.post(`/comprobantes/entregas/items/${i.id}`, { estado: e, observacion }, { preserveScroll: true }) }
const noEntregado = ref(null), obs = ref('')

const cobroItem = ref(null)
const cf = useForm({ medio: 'efectivo', monto: null, referencia: '', banco: '', numero: '', fecha_pago: null })
function abrirCobro(i) { cf.reset(); cf.clearErrors(); cf.monto = i.saldo > 0 ? Math.round((i.saldo - i.cobros.reduce((a, c) => a + c.monto, 0)) * 100) / 100 || null : null; cobroItem.value = i }
function guardarCobro() { cf.post(`/comprobantes/entregas/items/${cobroItem.value.id}/cobros`, { preserveScroll: true, onSuccess: () => { cobroItem.value = null } }) }

const rindiendo = ref(false)
const rf = useForm({ cuenta_fondos_id: props.cajas[0]?.id ?? null, cuenta_banco_id: null, efectivo_contado: null, viaticos: [], notas: '' })
const pendientes = computed(() => props.orden.items.filter(i => i.estado === 'pendiente').length)
const totalViaticos = computed(() => rf.viaticos.reduce((a, v) => a + (Number(v.monto) || 0), 0))
const debe = computed(() => Math.round((props.esperado.efectivo - totalViaticos.value) * 100) / 100)
const dif = computed(() => Math.round(((Number(rf.efectivo_contado) || 0) - debe.value) * 100) / 100)
function abrirRendir() { rf.clearErrors(); rindiendo.value = true }
function rendir() { rf.post(`/comprobantes/entregas/${props.orden.id}/rendir`, { preserveScroll: true, onSuccess: () => { rindiendo.value = false } }) }
</script>
