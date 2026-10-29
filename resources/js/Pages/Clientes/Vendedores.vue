<template>
  <AppLayout titulo="Vendedores">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/clientes" class="text-xs text-marca-muted hover:text-carmin">← Clientes</Link>
        <h1 class="page-title">Vendedores y comisiones</h1>
        <p class="page-subtitle">Cada factura y cada cobro llevan un vendedor. Acá se liquidan las comisiones por período.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" />
        <button v-if="puede('clientes','editar')" @click="abrir()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Vendedor</button>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 card p-0 overflow-x-auto">
        <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Liquidación {{ periodo.desde }} → {{ periodo.hasta }}</h2></div>
        <table class="table">
          <thead><tr><th>Vendedor</th><th class="text-right">Facturado (neto)</th><th class="text-right">% venta</th><th class="text-right">Cobrado</th><th class="text-right">% cobro</th><th class="text-right">Comisión</th><th></th></tr></thead>
          <tbody>
            <template v-for="l in liquidacion" :key="l.id">
              <tr>
                <td class="font-semibold">{{ l.nombre }}<p class="text-xs text-marca-muted font-normal">{{ l.comprobantes }} comp. · {{ l.cobros }} cobros</p></td>
                <td class="text-right tabular-nums">{{ moneda(l.facturado) }}</td><td class="text-right tabular-nums text-marca-muted">{{ l.comision_venta }}% = {{ moneda(l.com_venta, 0) }}</td>
                <td class="text-right tabular-nums">{{ moneda(l.cobrado) }}</td><td class="text-right tabular-nums text-marca-muted">{{ l.comision_cobro }}% = {{ moneda(l.com_cobro, 0) }}</td>
                <td class="text-right tabular-nums font-extrabold text-violeta">{{ moneda(l.total) }}</td>
                <td><button @click="abierto = abierto === l.id ? null : l.id" class="text-xs text-violeta font-semibold">{{ abierto === l.id ? 'Ocultar' : 'Detalle' }}</button></td>
              </tr>
              <tr v-if="abierto === l.id"><td colspan="7" class="bg-marca-fondo p-0">
                <table class="table text-xs"><thead><tr><th>Fecha</th><th>Comprobante</th><th>Cliente</th><th class="text-right">Neto</th><th class="text-right">Comisión</th></tr></thead>
                <tbody><tr v-for="(d, i) in l.detalle" :key="i"><td>{{ d.fecha }}</td><td>{{ d.tipo }} {{ d.numero }}</td><td>{{ d.cliente }}</td><td class="text-right tabular-nums">{{ moneda(d.neto) }}</td><td class="text-right tabular-nums">{{ moneda(d.comision) }}</td></tr><tr v-if="!l.detalle.length"><td colspan="5" class="text-center text-marca-muted py-3">Sin comprobantes en el período.</td></tr></tbody></table>
                <table v-if="l.detalle_cobros?.length" class="table text-xs border-t border-marca-borde" data-detalle-cobros><thead><tr><th>Fecha</th><th>Recibo</th><th>Cliente</th><th>Como</th><th class="text-right">Cobrado</th><th class="text-right">Comisión</th></tr></thead>
                <tbody><tr v-for="(d, i) in l.detalle_cobros" :key="i"><td>{{ d.fecha }}</td><td>{{ d.numero }}</td><td>{{ d.cliente }}</td><td>{{ d.como === 'cobrador' ? 'Cobrador' : 'Vendedor' }}</td><td class="text-right tabular-nums">{{ moneda(d.total) }}</td><td class="text-right tabular-nums">{{ moneda(d.comision) }}</td></tr></tbody></table>
              </td></tr>
            </template>
            <tr v-if="!liquidacion.length"><td colspan="7" class="text-center text-marca-muted py-10">Todavía no hay vendedores. Creá el primero.</td></tr>
          </tbody>
          <tfoot v-if="liquidacion.length"><tr class="font-extrabold"><td colspan="5" class="text-right">Total a liquidar</td><td class="text-right tabular-nums">{{ moneda(liquidacion.reduce((a, l) => a + l.total, 0)) }}</td><td></td></tr></tfoot>
        </table>
      </div>
      <div class="card">
        <h2 class="font-bold mb-3">Vendedores</h2>
        <div v-for="v in vendedores" :key="v.id" class="flex items-center justify-between gap-2 py-2 border-t border-marca-borde first:border-0" :class="!v.activo ? 'opacity-50' : ''">
          <div class="min-w-0"><p class="font-semibold truncate">{{ v.nombre }}</p><p class="text-xs text-marca-muted">{{ v.comision_venta }}% venta · {{ v.comision_cobro }}% cobro<span v-if="v.usuario"> · usuario {{ v.usuario }}</span><span v-if="v.clientes"> · {{ v.clientes }} clientes</span></p></div>
          <button v-if="puede('clientes','editar')" @click="abrir(v)" class="btn-ghost !px-2"><Icono nombre="edit" clase="w-4 h-4" /></button>
        </div>
        <p v-if="!vendedores.length" class="text-sm text-marca-muted">Ninguno todavía.</p>
        <div class="border-t border-marca-borde mt-4 pt-3" data-e2e="config-calle">
          <h2 class="font-bold mb-1">Cómo se vende en la calle</h2>
          <p class="text-xs text-marca-muted mb-2">Vale para todos los vendedores en <Link href="/vender?modo=calle" class="text-violeta font-semibold">Vender → En la calle</Link>.</p>
          <div class="space-y-2 text-sm">
            <div><label class="label">Con qué comprobante arranca</label><select v-model="calleForm.calle_comprobante" class="input !py-1.5"><option value="PRE">Presupuesto (el cliente lo aprueba después)</option><option value="FX">Factura</option><option value="REM">Remito</option></select></div>
            <label class="flex items-center gap-2"><input v-model="calleForm.calle_emite" type="checkbox" class="accent-carmin" /> La factura o el remito de la calle se emiten en el momento (si no, quedan pendientes de emitir en el local)</label>
            <div class="grid grid-cols-3 gap-2">
              <div><label class="label">Tope de descuento %</label><input v-model.number="calleForm.tope_descuento" type="number" step="any" min="0" class="input !py-1.5" /></div>
              <div><label class="label">Mora tolerada (días)</label><input v-model.number="calleForm.dias_mora" type="number" min="0" class="input !py-1.5" /></div>
              <div><label class="label">Avisar datos viejos (días)</label><input v-model.number="calleForm.avisar_datos_dias" type="number" min="0" class="input !py-1.5" /></div>
            </div>
            <button v-if="puede('configuracion','editar')" class="btn-secondary !py-1.5 text-xs" :disabled="calleForm.processing" @click="calleForm.post('/clientes/vendedores/calle', { preserveScroll: true })" data-e2e="guardar-calle">Guardar</button>
          </div>
        </div>
        <div class="border-t border-marca-borde mt-4 pt-3" data-e2e="autorizaciones">
          <h2 class="font-bold mb-1">Descuentos por encima del tope <span class="text-marca-muted font-normal text-sm">· últimos 30 días</span></h2>
          <p class="text-xs text-marca-muted mb-2">Quién lo pidió, de cuánto, a qué cliente y quién lo autorizó. Autoriza quien puede anular comprobantes, nunca uno mismo; el OK vale 15 minutos y una sola venta.</p>
          <p class="text-sm mb-2"><b>{{ autorizaciones.pedidas }}</b> pedidas · <b>{{ autorizaciones.usadas }}</b> usadas<span v-if="autorizaciones.pedidas - autorizaciones.usadas" class="text-amber-700"> · {{ autorizaciones.pedidas - autorizaciones.usadas }} sin usar</span></p>
          <div v-if="autorizaciones.items.length" class="overflow-x-auto"><table class="table text-xs"><thead><tr><th>Cuándo</th><th>Vendedor</th><th>Cliente</th><th class="text-right">%</th><th>Autorizó</th><th>Venta</th></tr></thead>
            <tbody><tr v-for="a in autorizaciones.items" :key="a.id"><td class="tabular-nums">{{ a.cuando }}</td><td>{{ a.vendedor }}</td><td>{{ a.cliente }}</td><td class="text-right tabular-nums">{{ a.pct }}%</td><td>{{ a.encargado }}</td><td><Link v-if="a.comprobante" :href="`/comprobantes/${a.comprobante.id}`" class="text-violeta font-semibold">{{ a.comprobante.numero }} · {{ moneda(a.comprobante.total) }}</Link><span v-else class="text-marca-muted">sin usar</span></td></tr></tbody></table></div>
          <p v-else class="text-xs text-marca-muted">Ninguna todavía.</p>
        </div>
      </div>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? 'Editar vendedor' : 'Nuevo vendedor'" @cerrar="modal = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2"><label class="label">Nombre</label><input v-model="form.nombre" class="input" /><p v-if="form.errors.nombre" class="text-carmin text-xs mt-1">{{ form.errors.nombre }}</p></div>
        <div><label class="label">Comisión por venta %</label><input v-model.number="form.comision_venta" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">Comisión por cobranza %</label><input v-model.number="form.comision_cobro" type="number" step="any" min="0" class="input" /></div>
        <div class="sm:col-span-2"><label class="label">Usuario del sistema (sus ventas se le atribuyen solas)</label><select v-model="form.user_id" class="input"><option :value="null">Ninguno</option><option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
        <div><label class="label">Email</label><input v-model="form.email" type="email" class="input" /></div>
        <div><label class="label">Teléfono</label><input v-model="form.telefono" class="input" /></div>
        <div><label class="label">Abre Vender en</label><select v-model="form.modo_default" class="input"><option v-for="(t, k) in modos" :key="k" :value="k">{{ t }}</option></select></div>
        <div><label class="label">Objetivo mensual (opcional)</label><input v-model.number="form.objetivo_mensual" type="number" step="any" min="0" class="input" placeholder="$ facturado por mes" /></div>
        <label v-if="form.id" class="flex items-center gap-2 text-sm"><input v-model="form.activo" type="checkbox" class="accent-carmin" /> Activo</label>
      </div>
      <template #pie><button class="btn-secondary" @click="modal = false">Cancelar</button><button class="btn-primary" :disabled="form.processing" @click="form.post(`/clientes/vendedores${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal = false) })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ vendedores: Array, liquidacion: Array, periodo: Object, usuarios: Array, calle: Object, modos: Object, autorizaciones: Object })
const { puede } = usePermisos()
const abierto = ref(null), modal = ref(false)
const vacio = () => ({ id: null, nombre: '', email: '', telefono: '', user_id: null, comision_venta: 0, comision_cobro: 0, activo: true, modo_default: 'mostrador', objetivo_mensual: null })
const calleForm = useForm({ calle_comprobante: props.calle.calle_comprobante, calle_emite: !!props.calle.calle_emite, tope_descuento: props.calle.tope_descuento, dias_mora: props.calle.dias_mora, avisar_datos_dias: props.calle.avisar_datos_dias })
const form = useForm(vacio())
function abrir(v) { form.clearErrors(); Object.assign(form, vacio(), v ?? {}); modal.value = true }
</script>
