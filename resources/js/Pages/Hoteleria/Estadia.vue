<template>
  <AppLayout :titulo="`Habitación ${estadia.habitacion}`">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <p class="text-xs font-bold uppercase tracking-widest text-marca-muted"><Link href="/hoteleria" class="hover:underline">Hotelería</Link> · {{ estadia.habitacion }} · {{ estadia.tipo }}</p>
        <h1 class="page-title">{{ estadia.nombre }} <span class="text-marca-muted font-normal text-base">· {{ estadia.personas }} persona{{ estadia.personas > 1 ? 's' : '' }}</span></h1>
        <p class="page-subtitle">{{ fmt(estadia.desde) }} al {{ fmt(estadia.hasta) }} · {{ estadia.noches }} noche{{ estadia.noches > 1 ? 's' : '' }} · <span class="badge" :class="{ 'bg-amber-50 text-amber-700': estadia.estado === 'reservada', 'bg-violeta/10 text-violeta': estadia.estado === 'checkin', 'bg-emerald-50 text-emerald-700': estadia.estado === 'checkout', 'bg-gris-light text-marca-muted': ['cancelada', 'no_show'].includes(estadia.estado) }">{{ estados[estadia.estado] }}</span><span v-if="estadia.checkin_en"> · llegó {{ estadia.checkin_en }}</span><span v-if="estadia.telefono"> · {{ estadia.telefono }}</span></p>
      </div>
      <div class="flex flex-wrap gap-2">
        <template v-if="estadia.estado === 'reservada'">
          <button class="btn-secondary" @click="editar = true">Editar reserva</button>
          <button class="btn-secondary" @click="seniaAbierta = true">Cobrar seña</button>
          <button class="btn-primary" @click="router.post(`/hoteleria/estadias/${estadia.id}/checkin`, {}, { preserveScroll: true })">Check-in</button>
          <button class="btn-ghost text-carmin" @click="router.post(`/hoteleria/estadias/${estadia.id}/cancelar`, { estado: 'cancelada' })">Cancelar</button>
          <button class="btn-ghost text-carmin" @click="router.post(`/hoteleria/estadias/${estadia.id}/cancelar`, { estado: 'no_show' })">No vino</button>
        </template>
        <template v-else-if="estadia.estado === 'checkin'">
          <button class="btn-secondary" @click="seniaAbierta = true">Cobrar a cuenta</button>
          <button class="btn-primary" @click="checkoutAbierto = true">Check-out y facturar</button>
        </template>
        <Link v-else-if="estadia.comprobante" :href="`/comprobantes/${estadia.comprobante.id}`" class="btn-primary">{{ estadia.comprobante.label }}<span v-if="estadia.comprobante.saldo > 0"> · cobrar {{ moneda(estadia.comprobante.saldo, 0) }}</span></Link>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-0 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Consumos a la habitación</h2><span class="text-sm tabular-nums">{{ moneda(estadia.consumos_total) }}</span></div>
          <table class="table text-sm">
            <thead><tr><th>Fecha</th><th>Detalle</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Total</th><th></th></tr></thead>
            <tbody>
              <tr v-for="c in estadia.consumos" :key="c.id"><td class="tabular-nums text-xs">{{ c.fecha }}</td><td class="font-medium">{{ c.descripcion }}</td><td class="text-right tabular-nums">{{ cantidad(c.cantidad) }}</td><td class="text-right tabular-nums">{{ moneda(c.precio_unit) }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(c.total) }}</td><td class="text-right"><button v-if="estadia.estado === 'checkin'" class="btn-ghost !px-2 text-xs text-carmin" @click="router.post(`/hoteleria/estadias/${estadia.id}/consumos/${c.id}/borrar`, {}, { preserveScroll: true })">Quitar</button></td></tr>
              <tr v-if="!estadia.consumos.length"><td colspan="6" class="text-center text-marca-muted py-5 text-xs">Sin consumos. Desayunos, minibar, lavandería, excursiones: todo va a la cuenta de la habitación.</td></tr>
              <tr v-if="estadia.estado === 'checkin'"><td colspan="6" class="!p-2">
                <div class="grid grid-cols-[1fr_80px_110px_auto] gap-2 items-end">
                  <div><select v-model="cf.product_id" class="input !py-1 text-xs" @change="alElegirProducto"><option :value="null">Del catálogo…</option><option v-for="p in productos" :key="p.id" :value="p.id">{{ p.name }} · {{ moneda(p.precio, 0) }}</option></select><input v-model="cf.descripcion" class="input !py-1 text-xs mt-1" placeholder="o a mano: Desayuno x2, Lavandería…" /></div>
                  <input v-model.number="cf.cantidad" type="number" step="any" min="0" class="input !py-1 text-xs text-right" /><input v-model.number="cf.precio_unit" type="number" step="any" min="0" class="input !py-1 text-xs text-right" placeholder="Precio" />
                  <button class="btn-primary !py-1 text-xs" :disabled="cf.processing || !cf.cantidad || (!cf.product_id && !cf.descripcion)" @click="cf.post(`/hoteleria/estadias/${estadia.id}/consumos`, { preserveScroll: true, onSuccess: () => cf.reset() })">Cargar</button>
                </div>
              </td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="estadia.notas" class="card text-sm"><p class="label">Notas</p><p class="whitespace-pre-line">{{ estadia.notas }}</p></div>
      </div>
      <div class="space-y-4">
        <div class="card">
          <h2 class="font-bold mb-2">Cuenta de la estadía</h2>
          <div class="space-y-1.5 text-sm">
            <div class="flex justify-between"><span class="text-marca-muted">Alojamiento · {{ estadia.noches }} × {{ moneda(estadia.tarifa_noche, 0) }}</span><span class="tabular-nums">{{ moneda(estadia.alojamiento) }}</span></div>
            <div class="flex justify-between"><span class="text-marca-muted">Consumos</span><span class="tabular-nums">{{ moneda(estadia.consumos_total) }}</span></div>
            <div class="flex justify-between font-bold pt-1 border-t border-marca-borde"><span>Total</span><span class="tabular-nums">{{ moneda(estadia.total) }}</span></div>
            <div v-if="estadia.senia" class="flex justify-between text-emerald-700"><span>Seña / a cuenta</span><span class="tabular-nums">− {{ moneda(estadia.senia) }}</span></div>
            <div class="flex justify-between text-lg font-extrabold pt-1 border-t border-marca-borde"><span>Saldo</span><span class="tabular-nums">{{ moneda(estadia.saldo) }}</span></div>
          </div>
        </div>
        <div class="card text-sm">
          <h2 class="font-bold mb-2">Huésped</h2>
          <p>{{ estadia.nombre }}</p><p class="text-xs text-marca-muted">{{ [estadia.documento, estadia.telefono, estadia.email].filter(Boolean).join(' · ') || 'Sin datos de contacto' }}</p>
          <p class="text-xs text-marca-muted mt-1">Origen: {{ estadia.origen }}</p>
          <a v-if="estadia.telefono" :href="`https://wa.me/${estadia.telefono.replace(/\D/g, '')}`" target="_blank" class="btn-secondary w-full mt-2 !py-1.5 text-xs">WhatsApp</a>
        </div>
      </div>
    </div>

    <Modal :abierto="seniaAbierta" :titulo="estadia.estado === 'checkin' ? 'Cobrar a cuenta' : 'Cobrar seña'" @cerrar="seniaAbierta = false">
      <div class="grid grid-cols-2 gap-3"><div><label class="label">Importe</label><input v-model.number="sf.monto" type="number" min="0" class="input" /></div><div><label class="label">Entra en</label><select v-model="sf.cuenta_id" class="input"><option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div></div>
      <p class="text-xs text-marca-muted mt-2">Queda como anticipo y se descuenta en la factura del check-out.</p>
      <template #pie><button class="btn-secondary" @click="seniaAbierta = false">Cancelar</button><button class="btn-primary" :disabled="sf.processing || !sf.monto || !sf.cuenta_id" @click="sf.post(`/hoteleria/estadias/${estadia.id}/senia`, { preserveScroll: true, onSuccess: () => (seniaAbierta = false) })">Registrar</button></template>
    </Modal>

    <Modal :abierto="checkoutAbierto" titulo="Check-out" @cerrar="checkoutAbierto = false">
      <p class="text-sm mb-3">Se emite la factura por <b class="tabular-nums">{{ moneda(estadia.total) }}</b><span v-if="estadia.senia"> menos la seña de {{ moneda(estadia.senia) }}</span> y la habitación pasa a limpieza.</p>
      <label class="label">Condición</label><select v-model="co.condicion" class="input"><option value="contado">Contado (cobrar ahora)</option><option value="cta_cte">Cuenta corriente (empresa / agencia)</option></select>
      <template #pie><button class="btn-secondary" @click="checkoutAbierto = false">Cancelar</button><button class="btn-primary" :disabled="co.processing" @click="co.post(`/hoteleria/estadias/${estadia.id}/checkout`)">Emitir factura</button></template>
    </Modal>

    <Modal :abierto="editar" titulo="Editar reserva" ancho="max-w-2xl" @cerrar="editar = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Desde</label><input v-model="ef.desde" type="date" class="input" /></div><div><label class="label">Hasta</label><input v-model="ef.hasta" type="date" class="input" /></div>
        <div><label class="label">Habitación</label><select v-model="ef.habitacion_id" class="input"><option v-for="h in habitaciones" :key="h.id" :value="h.id">{{ h.nombre }} · {{ moneda(h.tarifa, 0) }}</option></select><p v-if="ef.errors.habitacion_id" class="text-carmin text-xs mt-1">{{ ef.errors.habitacion_id }}</p></div><div><label class="label">Personas</label><input v-model.number="ef.personas" type="number" min="1" class="input" /></div>
        <div><label class="label">Nombre</label><input v-model="ef.nombre" class="input" /></div><div><label class="label">Teléfono</label><input v-model="ef.telefono" class="input" /></div>
        <div><label class="label">Tarifa por noche</label><input v-model.number="ef.tarifa_noche" type="number" min="0" class="input" /></div><div><label class="label">Documento</label><input v-model="ef.documento" class="input" /></div>
        <div class="sm:col-span-2"><label class="label">Notas</label><input v-model="ef.notas" class="input" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="editar = false">Cancelar</button><button class="btn-primary" :disabled="ef.processing" @click="ef.post(`/hoteleria/reservas/${estadia.id}`, { preserveScroll: true, onSuccess: () => (editar = false) })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'
const props = defineProps({ estadia: Object, estados: Object, productos: Array, habitaciones: Array, clientes: Array, cuentas: Array })
const fmt = iso => iso.split('-').reverse().join('/')
const seniaAbierta = ref(false), checkoutAbierto = ref(false), editar = ref(false)
const cf = useForm({ product_id: null, descripcion: '', cantidad: 1, precio_unit: null })
function alElegirProducto() { const p = props.productos.find(x => x.id === cf.product_id); if (p) { cf.descripcion = p.name; cf.precio_unit = p.precio } }
const sf = useForm({ monto: null, cuenta_id: props.cuentas[0]?.id ?? null })
const co = useForm({ condicion: 'contado' })
const e = props.estadia
const ef = useForm({ habitacion_id: e.habitacion_id, contact_id: e.contact_id, nombre: e.nombre, telefono: e.telefono ?? '', email: e.email ?? '', documento: e.documento ?? '', personas: e.personas, desde: e.desde, hasta: e.hasta, tarifa_noche: e.tarifa_noche, origen: e.origen, notas: e.notas ?? '' })
</script>
