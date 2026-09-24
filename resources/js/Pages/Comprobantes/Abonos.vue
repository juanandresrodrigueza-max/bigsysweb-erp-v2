<template>
  <AppLayout titulo="Abonos">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/comprobantes" class="text-xs text-marca-muted hover:text-carmin">← Comprobantes</Link>
        <h1 class="page-title">Abonos y facturación recurrente</h1>
        <p class="page-subtitle">Cuotas, alquileres, servicios mensuales: se facturan solos el día que indiques. Ingreso mensual recurrente: <b>{{ moneda(mrr, 0) }}</b>.</p>
      </div>
      <div class="flex gap-2">
        <button v-if="abonos.some(a => a.vence) && puede('comprobantes','crear')" @click="router.post('/comprobantes/abonos/emitir-vencidos')" class="btn-violeta">Emitir los {{ abonos.filter(a => a.vence).length }} vencidos</button>
        <button v-if="puede('comprobantes','crear')" @click="abrir()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Abono</button>
      </div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Abono</th><th>Cliente</th><th>Frecuencia</th><th class="text-right">Importe</th><th>Próxima</th><th class="text-right">Emitidas</th><th></th></tr></thead>
        <tbody>
          <tr v-for="a in abonos" :key="a.id" :class="!a.activo ? 'opacity-50' : ''">
            <td><p class="font-semibold">{{ a.descripcion }}</p><p class="text-xs text-marca-muted">{{ a.emitir_auto ? 'Emite sola' : 'Genera borrador' }} · {{ a.condicion === 'contado' ? 'contado' : 'cta. cte.' }}</p></td>
            <td>{{ a.cliente }}</td>
            <td class="capitalize">{{ a.frecuencia }} · día {{ a.dia_emision }}<span v-if="a.meses_excluidos.length" class="block text-[10px] text-marca-muted">sin {{ a.meses_excluidos.map(m => meses[m - 1]).join(', ') }}</span></td>
            <td class="text-right tabular-nums font-semibold">{{ moneda(a.importe) }}</td>
            <td class="tabular-nums" :class="a.vence ? 'text-carmin font-semibold' : ''">{{ a.proximo ?? '—' }}<span v-if="a.vence" class="block text-[10px]">vencida</span></td>
            <td class="text-right tabular-nums">{{ a.emitidos }}<span class="text-xs text-marca-muted"> / cuota {{ a.cuota_actual }}</span></td>
            <td class="text-right whitespace-nowrap"><button v-if="a.activo && puede('comprobantes','crear')" @click="router.post(`/comprobantes/abonos/${a.id}/emitir`)" class="btn-ghost !px-2 text-xs">Emitir ahora</button><button v-if="puede('comprobantes','crear')" @click="abrir(a)" class="btn-ghost !px-2"><Icono nombre="edit" clase="w-4 h-4" /></button></td>
          </tr>
          <tr v-if="!abonos.length"><td colspan="7" class="text-center text-marca-muted py-10">Todavía no hay abonos. Creá el primero.</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? 'Editar abono' : 'Nuevo abono'" ancho="max-w-3xl" @cerrar="modal = false">
      <div class="grid sm:grid-cols-3 gap-3">
        <div class="sm:col-span-2"><label class="label">Cliente</label><select v-model="form.contact_id" class="input"><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }}</option></select><p v-if="form.errors.contact_id" class="text-carmin text-xs mt-1">{{ form.errors.contact_id }}</p></div>
        <div><label class="label">Nombre del abono</label><input v-model="form.descripcion" class="input" placeholder="Ej: Abono mantenimiento" /></div>
        <div><label class="label">Frecuencia</label><select v-model="form.frecuencia" class="input"><option v-for="f in frecuencias" :key="f" :value="f" class="capitalize">{{ f }}</option></select></div>
        <div><label class="label">Día de emisión</label><input v-model.number="form.dia_emision" type="number" min="1" max="28" class="input" /></div>
        <div><label class="label">Condición</label><select v-model="form.condicion" class="input"><option value="cta_cte">Cuenta corriente</option><option value="contado">Contado</option></select></div>
        <div><label class="label">Desde</label><input v-model="form.desde" type="date" class="input" /></div>
        <div><label class="label">Hasta (vacío = sin fin)</label><input v-model="form.hasta" type="date" class="input" /></div>
        <div class="sm:col-span-3"><label class="label">Meses que no se factura</label><div class="flex flex-wrap gap-1"><button type="button" v-for="(m, i) in meses" :key="m" @click="toggleMes(i + 1)" class="px-2 py-1 rounded-lg text-xs border" :class="form.meses_excluidos.includes(i + 1) ? 'bg-carmin text-white border-carmin' : 'border-marca-borde'">{{ m }}</button></div></div>
      </div>
      <div class="mt-4">
        <div class="flex items-center justify-between mb-1"><p class="label !mb-0">Ítems de cada factura</p><button type="button" @click="form.items.push({ product_id: null, descripcion: '', cantidad: 1, precio_unit: 0, alicuota_iva: 21 })" class="text-xs text-violeta font-semibold">+ Ítem</button></div>
        <div v-for="(it, i) in form.items" :key="i" class="grid grid-cols-[1fr_80px_120px_70px_28px] gap-2 mb-1.5">
          <div><select v-model="it.product_id" class="input !py-1 text-xs" @change="elegirProducto(it)"><option :value="null">Sin artículo (solo texto)</option><option v-for="p in productos" :key="p.id" :value="p.id">{{ p.name }}</option></select><input v-model="it.descripcion" class="input !py-1 text-xs mt-1" placeholder="Descripción · podés usar {cuota} {mes} {anio} {periodo}" /></div>
          <input v-model.number="it.cantidad" type="number" step="any" min="0" class="input !py-1 text-xs text-right" />
          <input v-model.number="it.precio_unit" type="number" step="any" min="0" class="input !py-1 text-xs text-right" placeholder="precio neto" />
          <select v-model.number="it.alicuota_iva" class="input !py-1 !px-1 text-xs"><option v-for="a in [0,10.5,21,27]" :key="a" :value="a">{{ a }}%</option></select>
          <button type="button" @click="form.items.splice(i,1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
        </div>
        <p class="text-[11px] text-marca-muted">Ejemplo de descripción: "Cuota {cuota} · {periodo}" sale como "Cuota 3 · Octubre 2026". Total con IVA: <b class="tabular-nums">{{ moneda(totalItems) }}</b></p>
        <p v-if="form.errors.items" class="text-carmin text-xs mt-1">{{ form.errors.items }}</p>
      </div>
      <div class="grid sm:grid-cols-2 gap-3 mt-3">
        <label class="flex items-center gap-2 text-sm"><input v-model="form.emitir_auto" type="checkbox" class="accent-carmin" /> Emitir la factura sola (si no, queda como borrador para revisar)</label>
        <label v-if="form.id" class="flex items-center gap-2 text-sm"><input v-model="form.activo" type="checkbox" class="accent-carmin" /> Activo</label>
        <div class="sm:col-span-2"><label class="label">Notas</label><input v-model="form.notas" class="input" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="modal = false">Cancelar</button><button class="btn-primary" :disabled="form.processing" @click="form.post(`/comprobantes/abonos${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal = false) })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, hoyISO } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ abonos: Array, clientes: Array, productos: Array, frecuencias: Array, mrr: Number })
const { puede } = usePermisos()
const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']
const modal = ref(false)
const vacio = () => ({ id: null, contact_id: props.clientes[0]?.id ?? null, descripcion: '', items: [{ product_id: null, descripcion: 'Cuota {cuota} · {periodo}', cantidad: 1, precio_unit: 0, alicuota_iva: 21 }], condicion: 'cta_cte', frecuencia: 'mensual', dia_emision: 1, desde: hoyISO(), hasta: '', meses_excluidos: [], emitir_auto: true, activo: true, notas: '' })
const form = useForm(vacio())
function abrir(a) { form.clearErrors(); Object.assign(form, vacio(), a ? { ...a, items: a.items.map(i => ({ ...i })), meses_excluidos: [...a.meses_excluidos], hasta: a.hasta ?? '' } : {}); modal.value = true }
function toggleMes(m) { const i = form.meses_excluidos.indexOf(m); i >= 0 ? form.meses_excluidos.splice(i, 1) : form.meses_excluidos.push(m) }
function elegirProducto(it) { const p = props.productos.find(x => x.id === it.product_id); if (p) { it.descripcion = p.name; it.precio_unit = Number(p.price); it.alicuota_iva = Number(p.iva) } }
const totalItems = computed(() => form.items.reduce((a, i) => a + (Number(i.cantidad) || 0) * (Number(i.precio_unit) || 0) * (1 + (Number(i.alicuota_iva) || 0) / 100), 0))
</script>
