<template>
  <AppLayout titulo="Plan de cuentas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Plan de cuentas</h1><p class="page-subtitle">Viene armado para una PyME argentina. Las cuentas con "rol" las usa el sistema para los asientos automáticos.</p></div>
      <button v-if="puede('contable','editar')" @click="abrir()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Cuenta</button>
    </div>
    <ContableTabs />
    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Código</th><th>Cuenta</th><th>Tipo</th><th>Rol en el sistema</th><th class="text-right">Saldo</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in cuentas" :key="c.id" :class="[!c.imputable ? 'bg-marca-fondo/60' : '', !c.activa ? 'opacity-50' : '']">
            <td class="tabular-nums text-marca-muted" :style="{ paddingLeft: (12 + c.nivel * 16) + 'px' }">{{ c.codigo }}</td>
            <td :class="!c.imputable ? 'font-bold uppercase text-xs tracking-wide' : 'font-medium'"><component :is="c.imputable ? Link : 'span'" :href="`/contable/mayor?cuenta=${c.id}`" class="hover:text-carmin">{{ c.nombre }}</component></td>
            <td><span class="badge" :class="{ activo: 'bg-emerald-50 text-emerald-700', pasivo: 'bg-carmin-light text-carmin', patrimonio: 'bg-lavanda-light text-violeta', ingreso: 'bg-emerald-50 text-emerald-700', egreso: 'bg-amber-50 text-amber-700' }[c.tipo]">{{ tipos[c.tipo] }}</span></td>
            <td class="text-xs text-marca-muted">{{ c.clave ? roles[c.clave] ?? c.clave : '' }}</td>
            <td class="text-right tabular-nums" :class="c.saldo < 0 ? 'text-carmin' : ''">{{ c.imputable && c.movimientos ? moneda(c.saldo, 0) : '' }}</td>
            <td class="text-right"><button v-if="puede('contable','editar')" @click="abrir(c)" class="btn-ghost !px-2"><Icono nombre="edit" clase="w-4 h-4" /></button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? `Editar ${form.codigo}` : 'Nueva cuenta'" @cerrar="modal = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div><label class="label">Código</label><input v-model="form.codigo" class="input tabular-nums" placeholder="Ej: 5.2.06" /><p v-if="form.errors.codigo" class="text-carmin text-xs mt-1">{{ form.errors.codigo }}</p></div>
        <div><label class="label">Tipo</label><select v-model="form.tipo" class="input"><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="sm:col-span-2"><label class="label">Nombre</label><input v-model="form.nombre" class="input" /><p v-if="form.errors.nombre" class="text-carmin text-xs mt-1">{{ form.errors.nombre }}</p></div>
        <div class="sm:col-span-2"><label class="label">Dentro de</label><select v-model="form.parent_id" class="input"><option :value="null">— (nivel superior)</option><option v-for="c in cuentas.filter(x => !x.imputable && x.id !== form.id)" :key="c.id" :value="c.id">{{ c.codigo }} {{ c.nombre }}</option></select></div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.imputable" type="checkbox" class="accent-carmin" /> Recibe asientos (no es título)</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.activa" type="checkbox" class="accent-carmin" /> Activa</label>
      </div>
      <template #pie><button class="btn-secondary" @click="modal = false">Cancelar</button><button class="btn-primary" :disabled="form.processing" @click="form.post(`/contable/plan${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal = false) })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>
<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
defineProps({ cuentas: Array, tipos: Object })
const { puede } = usePermisos()
const roles = { caja: 'Cobros y pagos en efectivo', banco: 'Transferencias y cheques', billetera: 'MercadoPago y billeteras', cheques_cartera: 'Cheques recibidos', transito: 'Transferencias entre cuentas', deudores: 'Facturas de venta', tarjetas_cobrar: 'Cobros con tarjeta', iva_cf: 'IVA de compras', ret_sufridas: 'Retenciones que nos hacen', mercaderias: 'Compras y costo de ventas', proveedores: 'Facturas de compra', cheques_propios: 'Cheques que entregamos', iva_df: 'IVA de ventas', percepciones_cobradas: 'Percepciones en facturas', ret_practicadas: 'Retenciones que hacemos', ventas: 'Facturas de venta', otros_ingresos: 'Ingresos manuales de fondos', sobrante_caja: 'Cierres de caja', cmv: 'Costo de lo vendido', gastos: 'Gastos sin categoría', faltante_caja: 'Cierres de caja', compras_gastos: 'Compras sin artículo', resultados: 'Ajustes de redondeo' }
const modal = ref(false)
const form = useForm({ id: null, codigo: '', nombre: '', tipo: 'egreso', parent_id: null, imputable: true, activa: true })
function abrir(c = null) { form.clearErrors(); Object.assign(form, c ? { id: c.id, codigo: c.codigo, nombre: c.nombre, tipo: c.tipo, parent_id: c.parent_id, imputable: c.imputable, activa: c.activa } : { id: null, codigo: '', nombre: '', tipo: 'egreso', parent_id: null, imputable: true, activa: true }); modal.value = true }
</script>
