<template>
  <AppLayout :titulo="`${c.nombre} ${c.numero ?? ''}`">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div class="flex items-center gap-3">
        <span class="w-12 h-12 rounded-2xl grid place-items-center text-xl font-extrabold" :class="c.estado === 'anulado' ? 'bg-gris-light text-marca-muted' : 'bg-violeta-grad text-white'">{{ c.letra }}</span>
        <div>
          <Link href="/proveedores/compras" class="text-xs text-marca-muted hover:text-carmin">← Compras</Link>
          <h1 class="page-title">{{ c.nombre }} <span class="tabular-nums">{{ c.numero ?? '(sin número)' }}</span></h1>
          <p class="page-subtitle">{{ c.fecha }} · {{ c.sucursal }} · {{ c.usuario }} <span v-if="c.origen_carga && c.origen_carga !== 'manual'">· cargada por {{ c.origen_carga === 'ocr' ? 'IA' : 'AFIP' }}</span></p>
          <div class="flex flex-wrap gap-1.5 mt-1.5">
            <span class="badge" :class="estadoComprobante[c.estado].clase">{{ c.estado === 'emitido' ? 'Registrada' : estadoComprobante[c.estado].label }}</span>
            <span v-if="c.estado === 'emitido' && c.estado_pago !== 'na'" class="badge" :class="estadoCobro[c.estado_pago].clase">{{ { pendiente: 'A pagar', parcial: 'Pago parcial', cobrado: 'Pagada' }[c.estado_pago] }}</span>
            <span v-if="c.vencido" class="badge bg-carmin-light text-carmin">Vencida</span>
          </div>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <template v-if="c.estado === 'borrador'">
          <Link :href="`/proveedores/compras/${c.id}/editar`" class="btn-secondary"><Icono nombre="edit" clase="w-4 h-4" /> Editar</Link>
          <Link :href="`/proveedores/compras/${c.id}/registrar`" method="post" as="button" class="btn-primary">Registrar</Link>
        </template>
        <template v-else-if="c.estado === 'emitido'">
          <Link v-if="c.estado_pago === 'pendiente' || c.estado_pago === 'parcial'" :href="`/proveedores/${c.contact_id}?pagar=${c.id}`" class="btn-primary">Registrar pago</Link>
          <Link v-if="puedeNC" :href="`/proveedores/compras/${c.id}/nota-credito`" method="post" as="button" class="btn-secondary">Nota de crédito</Link>
        </template>
        <button v-if="c.estado !== 'anulado' && puede('proveedores','anular')" @click="anularAbierto = true" class="btn-danger">Anular</button>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-0 overflow-x-auto">
          <table class="table">
            <thead><tr><th>Ítem</th><th class="text-right">Cant.</th><th class="text-right">Costo unit.</th><th class="text-right">Dto</th><th class="text-right">IVA</th><th class="text-right">Total</th></tr></thead>
            <tbody>
              <tr v-for="i in c.items" :key="i.id">
                <td><p class="font-medium">{{ i.descripcion }}</p><p class="text-xs text-marca-muted">{{ i.sku ?? (i.product_id ? '' : 'sin artículo · no impacta stock') }}</p></td>
                <td class="text-right tabular-nums">{{ cantidad(i.cantidad) }} {{ i.unidad }}</td><td class="text-right tabular-nums">{{ moneda(i.precio_unit) }}</td>
                <td class="text-right tabular-nums text-marca-muted">{{ i.descuento ? i.descuento + '%' : '' }}</td><td class="text-right tabular-nums text-marca-muted">{{ i.alicuota_iva }}%</td><td class="text-right tabular-nums font-semibold">{{ moneda(i.total) }}</td>
              </tr>
            </tbody>
          </table>
          <div class="flex justify-end px-4 py-3 border-t border-marca-borde">
            <div class="w-64 text-sm space-y-1">
              <div class="flex justify-between"><span class="text-marca-muted">Neto</span><span class="tabular-nums">{{ moneda(c.neto) }}</span></div>
              <div class="flex justify-between"><span class="text-marca-muted">IVA</span><span class="tabular-nums">{{ moneda(c.iva) }}</span></div>
              <div v-for="(imp, i) in c.impuestos" :key="i" class="flex justify-between"><span class="text-marca-muted capitalize">{{ imp.tipo }}</span><span class="tabular-nums">{{ moneda(imp.monto) }}</span></div>
              <div class="flex justify-between text-lg font-extrabold pt-1 border-t border-marca-borde"><span>Total</span><span class="tabular-nums">{{ moneda(c.total) }}</span></div>
              <div v-if="c.moneda && c.moneda !== 'ARS'" class="flex justify-between text-xs text-violeta"><span>En {{ c.moneda }} a {{ moneda(c.cotizacion, 2) }}</span><span class="tabular-nums font-semibold">{{ c.moneda }} {{ Number(c.total_me).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span></div>
              <div v-if="c.estado_pago !== 'na' && c.estado === 'emitido'" class="flex justify-between" :class="c.saldo > 0 ? 'text-carmin font-semibold' : 'text-emerald-700'"><span>Saldo a pagar</span><span class="tabular-nums">{{ moneda(c.saldo) }}</span></div>
            </div>
          </div>
        </div>
        <div v-if="c.notas" class="card text-sm text-marca-muted whitespace-pre-line">{{ c.notas }}</div>
      </div>
      <div class="space-y-4">
        <div class="card">
          <h2 class="font-bold mb-2">Proveedor</h2>
          <Link :href="`/proveedores/${c.proveedor.id}`" class="font-semibold text-carmin hover:underline">{{ c.proveedor.name }}</Link>
          <p class="text-sm text-marca-muted">{{ c.proveedor.condicion_iva }} · {{ c.proveedor.cuit ?? 'sin CUIT' }}</p>
          <p class="text-sm mt-2">Le debemos: <b class="tabular-nums" :class="c.proveedor.balance > 0 ? 'text-carmin' : ''">{{ moneda(c.proveedor.balance) }}</b></p>
          <p class="text-sm mt-2">Condición: <b>{{ c.condicion === 'contado' ? 'Contado' : 'Cuenta corriente' }}</b><span v-if="c.fecha_vto"> · vence {{ c.fecha_vto }}</span></p>
          <p v-if="c.cae" class="text-xs text-marca-muted mt-1">CAE {{ c.cae }}</p>
        </div>
        <div v-if="c.pagos.length" class="card">
          <h2 class="font-bold mb-2">Pagos aplicados</h2>
          <div v-for="k in c.pagos" :key="k.pago_id" class="flex justify-between text-sm py-1 border-t border-marca-borde/60 first:border-0"><a :href="`/proveedores/pagos/${k.pago_id}/imprimir`" target="_blank" class="hover:text-carmin">{{ k.numero }} <span class="text-marca-muted">· {{ k.fecha }}</span></a><span class="tabular-nums" :class="k.estado === 'anulado' ? 'line-through text-marca-muted' : ''">{{ moneda(k.monto) }}</span></div>
        </div>
        <div v-if="c.origen" class="card"><h2 class="font-bold mb-2">Relacionados</h2><Link :href="`/proveedores/compras/${c.origen.id}`" class="block text-sm py-1 hover:text-carmin">↑ {{ c.origen.nombre }} {{ c.origen.numero }}</Link></div>
      </div>
    </div>

    <Modal :abierto="anularAbierto" titulo="Anular compra" @cerrar="anularAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Se revierte la cuenta corriente y el stock. Si tiene pagos, anulalos primero.</p>
      <input v-model="anular.motivo" class="input" placeholder="Motivo" /><p v-if="anular.errors.motivo" class="text-carmin text-xs mt-1">{{ anular.errors.motivo }}</p>
      <template #pie><button class="btn-secondary" @click="anularAbierto = false">Cancelar</button><button class="btn-danger" :disabled="anular.processing" @click="anular.post(`/proveedores/compras/${c.id}/anular`, { onSuccess: () => (anularAbierto = false) })">Anular</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad, estadoCobro, estadoComprobante } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
defineProps({ c: Object, puedeNC: Boolean })
const { puede } = usePermisos()
const anularAbierto = ref(false)
const anular = useForm({ motivo: '' })
</script>
