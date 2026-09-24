<template>
  <AppLayout :titulo="orden.numero">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/proveedores/ordenes" class="text-xs text-marca-muted hover:text-carmin">← Órdenes de compra</Link>
        <h1 class="page-title flex items-center gap-3">{{ orden.numero }} <span class="badge text-sm" :class="claseEstado[orden.estado]">{{ estados[orden.estado] }}</span></h1>
        <p class="page-subtitle">{{ orden.proveedor }} · {{ orden.fecha }}<span v-if="orden.entrega"> · entrega {{ orden.entrega }}</span><span v-if="orden.atrasada" class="text-carmin font-semibold"> · atrasada</span></p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a :href="`/proveedores/ordenes/${orden.id}/imprimir`" target="_blank" class="btn-secondary"><Icono nombre="print" clase="w-4 h-4" /> Imprimir / PDF</a>
        <button @click="envioAbierto = true" class="btn-secondary">Enviar al proveedor</button>
        <Link v-if="['borrador','enviada'].includes(orden.estado) && puede('proveedores','editar')" :href="`/proveedores/ordenes/${orden.id}/editar`" class="btn-secondary">Editar</Link>
        <button v-if="orden.estado === 'borrador' && puede('proveedores','crear')" @click="router.post(`/proveedores/ordenes/${orden.id}/enviar`)" class="btn-violeta">Marcar enviada</button>
        <Link v-if="['borrador','enviada','parcial'].includes(orden.estado) && puede('proveedores','crear')" :href="`/proveedores/ordenes/${orden.id}/recibir`" class="btn-primary"><Icono nombre="truck" clase="w-4 h-4" /> Recibir mercadería</Link>
        <button v-if="['borrador','enviada','parcial'].includes(orden.estado) && puede('proveedores','anular')" @click="cancelar" class="btn-ghost text-carmin">Cancelar</button>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 card p-0 overflow-x-auto">
        <table class="table">
          <thead><tr><th>Artículo</th><th class="text-right">Pedido</th><th class="text-right">Recibido</th><th class="text-right">Pendiente</th><th class="text-right">Precio</th><th class="text-right">Subtotal</th></tr></thead>
          <tbody>
            <tr v-for="i in orden.items" :key="i.id">
              <td><p class="font-medium">{{ i.descripcion }}</p><p class="text-xs text-marca-muted">{{ i.sku }}<span v-if="i.notas"> · {{ i.notas }}</span></p></td>
              <td class="text-right tabular-nums">{{ cantidad(i.cantidad) }} {{ i.unit }}</td>
              <td class="text-right tabular-nums text-emerald-700">{{ cantidad(i.recibido) }}</td>
              <td class="text-right tabular-nums" :class="i.pendiente > 0 ? 'text-amber-700 font-semibold' : 'text-marca-muted'">{{ cantidad(i.pendiente) }}</td>
              <td class="text-right tabular-nums">{{ moneda(i.precio_unit) }}</td>
              <td class="text-right tabular-nums font-semibold">{{ moneda(i.cantidad * i.precio_unit) }}</td>
            </tr>
          </tbody>
          <tfoot><tr class="font-extrabold"><td colspan="5" class="text-right">Total neto</td><td class="text-right tabular-nums">{{ moneda(orden.total) }}</td></tr></tfoot>
        </table>
      </div>
      <div class="space-y-4">
        <div class="card text-sm space-y-1">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-1">Datos</p>
          <div class="flex justify-between"><span class="text-marca-muted">Cargó</span><span>{{ orden.usuario }}</span></div>
          <div class="flex justify-between"><span class="text-marca-muted">Origen</span><span class="capitalize">{{ orden.origen }}</span></div>
          <div v-if="orden.enviada_en" class="flex justify-between"><span class="text-marca-muted">Enviada</span><span>{{ orden.enviada_en }}</span></div>
          <p v-if="orden.notas" class="pt-2 border-t border-marca-borde whitespace-pre-line">{{ orden.notas }}</p>
        </div>
        <div class="card text-sm">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Facturas recibidas</p>
          <Link v-for="c in orden.compras" :key="c.id" :href="`/proveedores/compras/${c.id}`" class="flex justify-between py-1 hover:text-carmin"><span>{{ c.nombre }} {{ c.numero ?? '' }} · {{ c.fecha }}</span><b class="tabular-nums">{{ moneda(c.total, 0) }}</b></Link>
          <p v-if="!orden.compras.length" class="text-marca-muted">Todavía no se recibió nada.</p>
        </div>
      </div>
    </div>
    <EnviarModal :abierto="envioAbierto" modelo="OrdenCompra" :id="orden.id" titulo="Enviar orden al proveedor" @cerrar="envioAbierto = false" />
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import EnviarModal from '@/Components/EnviarModal.vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import { moneda, cantidad } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ orden: Object, estados: Object })
const { puede } = usePermisos()
const envioAbierto = ref(false)
const claseEstado = { borrador: 'bg-gris-light text-marca-muted', enviada: 'bg-violeta-light text-violeta', parcial: 'bg-amber-50 text-amber-700', recibida: 'bg-emerald-50 text-emerald-700', cancelada: 'bg-carmin-light text-carmin' }
const texto = computed(() => `Orden de compra ${props.orden.numero} del ${props.orden.fecha}:\n` + props.orden.items.map(i => `• ${cantidad(i.cantidad)} ${i.unit ?? ''} ${i.descripcion}`).join('\n') + (props.orden.entrega ? `\nEntrega: ${props.orden.entrega}` : '') + (props.orden.notas ? `\n${props.orden.notas}` : ''))
const mailto = computed(() => `mailto:${props.orden.proveedor_email}?subject=${encodeURIComponent('Orden de compra ' + props.orden.numero)}&body=${encodeURIComponent(texto.value)}`)
const whatsapp = computed(() => `https://wa.me/${String(props.orden.proveedor_telefono).replace(/\D/g, '')}?text=${encodeURIComponent(texto.value)}`)
function cancelar() { const motivo = window.prompt('Motivo para cancelar la orden:'); if (motivo) router.post(`/proveedores/ordenes/${props.orden.id}/cancelar`, { motivo }) }
</script>
