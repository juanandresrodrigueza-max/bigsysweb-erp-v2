<template>
  <AppLayout :titulo="`${c.nombre} ${c.numero ?? ''}`">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div class="flex items-center gap-3">
        <span class="w-12 h-12 rounded-2xl grid place-items-center text-xl font-extrabold" :class="c.estado === 'anulado' ? 'bg-gris-light text-marca-muted' : 'bg-marca-grad text-white'">{{ c.letra }}</span>
        <div>
          <h1 class="page-title">{{ c.nombre }} <span class="tabular-nums">{{ c.numero ?? '(borrador)' }}</span></h1>
          <p class="page-subtitle">{{ c.fecha }} · {{ c.sucursal }} · {{ c.usuario }}</p>
          <div class="flex flex-wrap gap-1.5 mt-1.5">
            <span class="badge" :class="estadoComprobante[c.estado].clase">{{ estadoComprobante[c.estado].label }}</span>
            <span v-if="c.estado === 'emitido' && c.estado_cobro !== 'na'" class="badge" :class="estadoCobro[c.estado_cobro].clase">{{ estadoCobro[c.estado_cobro].label }}</span>
            <span v-if="c.vencido" class="badge bg-carmin-light text-carmin">Vencido</span>
            <span v-if="c.es_acopio" class="badge bg-violeta-light text-violeta">Acopio</span>
            <span v-if="c.afip_estado === 'aprobado'" class="badge bg-emerald-50 text-emerald-700">CAE {{ c.cae }}</span>
            <span v-else-if="c.afip_estado === 'simulado'" class="badge bg-amber-50 text-amber-700">Sin CAE · simulado</span>
          </div>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <template v-if="c.estado === 'borrador'">
          <Link :href="`/comprobantes/${c.id}/editar`" class="btn-secondary"><Icono nombre="edit" clase="w-4 h-4" /> Editar</Link>
          <Link :href="`/comprobantes/${c.id}/emitir`" method="post" as="button" class="btn-primary">Emitir</Link>
        </template>
        <template v-else>
          <a :href="`/comprobantes/${c.id}/imprimir`" target="_blank" class="btn-secondary">Imprimir / PDF</a>
          <Link v-if="c.estado_cobro === 'pendiente' || c.estado_cobro === 'parcial'" :href="`/clientes/${c.contact_id}?cobrar=${c.id}`" class="btn-primary">Registrar cobro</Link>
          <button v-for="cv in conversiones" :key="cv.tipo" @click="convertir(cv)" class="btn-secondary">{{ cv.label }}</button>
        </template>
        <button v-if="puedeAnular && puede('comprobantes', 'anular')" @click="anularAbierto = true" class="btn-danger">Anular</button>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-0 overflow-x-auto">
          <table class="table">
            <thead><tr><th>Artículo</th><th class="text-right">Cant.</th><th class="text-right">P. unit.</th><th class="text-right">Dto</th><th class="text-right">IVA</th><th class="text-right">Total</th></tr></thead>
            <tbody>
              <tr v-for="i in c.items" :key="i.id">
                <td><p class="font-medium">{{ i.descripcion }}</p><p v-if="i.sku" class="text-xs text-marca-muted">{{ i.sku }}</p></td>
                <td class="text-right tabular-nums">{{ cantidad(i.cantidad) }} {{ i.unidad }}</td>
                <td class="text-right tabular-nums">{{ moneda(i.precio_unit) }}</td>
                <td class="text-right tabular-nums text-marca-muted">{{ i.descuento ? i.descuento + '%' : '' }}</td>
                <td class="text-right tabular-nums text-marca-muted">{{ i.alicuota_iva }}%</td>
                <td class="text-right tabular-nums font-semibold">{{ moneda(i.total) }}</td>
              </tr>
            </tbody>
          </table>
          <div class="flex justify-end px-4 py-3 border-t border-marca-borde">
            <div class="w-64 text-sm space-y-1">
              <div class="flex justify-between"><span class="text-marca-muted">Neto</span><span class="tabular-nums">{{ moneda(c.neto) }}</span></div>
              <div class="flex justify-between"><span class="text-marca-muted">IVA</span><span class="tabular-nums">{{ moneda(c.iva) }}</span></div>
              <div v-if="c.percepciones" class="flex justify-between"><span class="text-marca-muted">Percepciones</span><span class="tabular-nums">{{ moneda(c.percepciones) }}</span></div>
              <div class="flex justify-between text-lg font-extrabold pt-1 border-t border-marca-borde"><span>Total</span><span class="tabular-nums">{{ moneda(c.total) }}</span></div>
              <div v-if="c.estado_cobro !== 'na' && c.estado === 'emitido'" class="flex justify-between" :class="c.saldo > 0 ? 'text-carmin font-semibold' : 'text-emerald-700'"><span>Saldo</span><span class="tabular-nums">{{ moneda(c.saldo) }}</span></div>
            </div>
          </div>
        </div>

        <div v-if="c.acopio" class="card">
          <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Acopio · {{ c.acopio.estado }}</h2><span class="text-xs text-marca-muted">Límite {{ c.acopio.fecha_limite }}</span></div>
          <div class="space-y-2">
            <div v-for="i in c.acopio.items" :key="i.id">
              <div class="flex justify-between text-sm"><span>{{ i.descripcion }}</span><span class="tabular-nums text-marca-muted">{{ cantidad(i.retirada) }} / {{ cantidad(i.facturada) }} · quedan <b class="text-marca-texto">{{ cantidad(i.pendiente) }}</b></span></div>
              <div class="h-2 rounded-full bg-gris-light overflow-hidden mt-1"><div class="h-full bg-violeta-grad" :style="{ width: `${Math.min(100, i.retirada / i.facturada * 100)}%` }"></div></div>
            </div>
          </div>
          <Link :href="`/clientes/${c.contact_id}?retirar=${c.acopio.id}`" class="btn-violeta mt-4">Registrar retiro</Link>
        </div>

        <div v-if="c.notas" class="card text-sm text-marca-muted whitespace-pre-line">{{ c.notas }}</div>
      </div>

      <div class="space-y-4">
        <div class="card">
          <h2 class="font-bold mb-2">Cliente</h2>
          <template v-if="c.contacto">
            <Link :href="`/clientes/${c.contacto.id}`" class="font-semibold text-carmin hover:underline">{{ c.contacto.name }}</Link>
            <p class="text-sm text-marca-muted">{{ c.contacto.condicion_iva }} · {{ c.contacto.cuit ?? 'sin CUIT' }}</p>
            <p class="text-sm text-marca-muted">{{ [c.contacto.address, c.contacto.city].filter(Boolean).join(', ') }}</p>
            <p class="text-sm mt-2">Saldo en cuenta: <b class="tabular-nums" :class="c.contacto.balance > 0 ? 'text-carmin' : ''">{{ moneda(c.contacto.balance) }}</b></p>
          </template>
          <p v-else class="text-sm text-marca-muted">Consumidor final</p>
          <p class="text-sm mt-2">Condición: <b>{{ c.condicion === 'contado' ? 'Contado' : 'Cuenta corriente' }}</b><span v-if="c.fecha_vto && (c.grupo === 'factura' || c.grupo === 'nd')"> · vence {{ c.fecha_vto }}</span></p>
        </div>

        <div v-if="c.cobros.length" class="card">
          <h2 class="font-bold mb-2">Cobros aplicados</h2>
          <div v-for="k in c.cobros" :key="k.cobro_id" class="flex justify-between text-sm py-1 border-t border-marca-borde/60 first:border-0">
            <a :href="`/clientes/cobros/${k.cobro_id}/imprimir`" target="_blank" class="hover:text-carmin">{{ k.numero }} <span class="text-marca-muted">· {{ k.fecha }}</span></a>
            <span class="tabular-nums" :class="k.estado === 'anulado' ? 'line-through text-marca-muted' : ''">{{ moneda(k.monto) }}</span>
          </div>
        </div>

        <div v-if="c.origen || c.derivados.length" class="card">
          <h2 class="font-bold mb-2">Relacionados</h2>
          <Link v-if="c.origen" :href="`/comprobantes/${c.origen.id}`" class="block text-sm py-1 hover:text-carmin">↑ {{ c.origen.nombre }} {{ c.origen.numero }}</Link>
          <Link v-for="d in c.derivados" :key="d.id" :href="`/comprobantes/${d.id}`" class="block text-sm py-1 hover:text-carmin">↓ {{ d.nombre }} {{ d.numero ?? '(borrador)' }} <span v-if="d.estado === 'anulado'" class="badge bg-carmin-light text-carmin ml-1">Anulado</span></Link>
        </div>

        <div v-if="c.afip_estado === 'aprobado'" class="card text-sm">
          <h2 class="font-bold mb-2">AFIP</h2>
          <p>CAE <b class="tabular-nums">{{ c.cae }}</b></p><p class="text-marca-muted">Vence {{ c.cae_vto }}</p>
        </div>
      </div>
    </div>

    <Modal :abierto="anularAbierto" titulo="Anular comprobante" @cerrar="anularAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Se revierte la cuenta corriente y el stock. Si tiene cobros, anulalos primero. Indicá el motivo (queda en auditoría).</p>
      <input v-model="anular.motivo" class="input" placeholder="Motivo" />
      <p v-if="anular.errors.motivo" class="text-carmin text-xs mt-1">{{ anular.errors.motivo }}</p>
      <template #pie>
        <button class="btn-secondary" @click="anularAbierto = false">Cancelar</button>
        <button class="btn-danger" :disabled="anular.processing" @click="anular.post(`/comprobantes/${c.id}/anular`, { onSuccess: () => (anularAbierto = false) })">Anular</button>
      </template>
    </Modal>

    <Modal :abierto="!!conv" :titulo="conv?.label" @cerrar="conv = null">
      <p class="text-sm text-marca-muted mb-3">Se crea un borrador con los mismos ítems, listo para revisar y emitir.</p>
      <template v-if="conv?.tipo === 'FX'">
        <label class="label">Condición</label>
        <select v-model="convForm.condicion" class="input"><option value="cta_cte">Cuenta corriente</option><option value="contado">Contado</option></select>
        <label class="flex items-center gap-2 text-sm mt-3"><input v-model="convForm.es_acopio" type="checkbox" class="accent-violeta" /> Facturar como acopio</label>
      </template>
      <template #pie>
        <button class="btn-secondary" @click="conv = null">Cancelar</button>
        <button class="btn-primary" :disabled="convForm.processing" @click="convForm.transform(d => ({ ...d, tipo: conv.tipo })).post(`/comprobantes/${c.id}/convertir`)">Continuar</button>
      </template>
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

const props = defineProps({ c: Object, conversiones: Array, puedeAnular: Boolean })
const { puede } = usePermisos()
const anularAbierto = ref(false)
const anular = useForm({ motivo: '' })
const conv = ref(null)
const convForm = useForm({ condicion: props.c.condicion, es_acopio: false })
function convertir(cv) { conv.value = cv }
</script>
