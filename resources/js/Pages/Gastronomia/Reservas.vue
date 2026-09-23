<template>
  <AppLayout titulo="Reservas y QR">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Reservas y menú QR</h1><p class="page-subtitle">Las reservas que entran por la web y las que cargás a mano. Y los códigos QR de cada mesa para que pidan desde el celular.</p></div>
      <div class="flex gap-2"><Link href="/gastronomia" class="btn-secondary">Salón</Link><button class="btn-primary" @click="nuevaAbierta = true">Nueva reserva</button></div>
    </div>
    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Reservas hoy</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.hoy }} <span class="text-sm text-marca-muted">· {{ kpis.personas_hoy }} personas</span></p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Pendientes de confirmar</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.pendientes ? 'text-carmin' : ''">{{ kpis.pendientes }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Reservas online</p><p class="text-sm"><span class="badge" :class="reservasActivas ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ reservasActivas ? 'activas' : 'apagadas' }}</span> <a :href="urlReservas" target="_blank" class="underline text-xs ml-1">{{ urlReservas }}</a></p></div>
    </div>
    <div class="flex items-center gap-2 mb-3"><PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" /></div>
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card p-0 overflow-hidden lg:col-span-2">
        <table class="table text-sm">
          <thead><tr><th>Cuándo</th><th>Nombre</th><th class="text-right">Personas</th><th>Teléfono</th><th>Origen</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in reservas" :key="r.id" :class="r.estado === 'cancelled' ? 'opacity-50' : ''">
              <td class="tabular-nums whitespace-nowrap"><b>{{ r.fecha }}</b> {{ r.hora }} <span class="text-marca-muted text-xs capitalize">{{ r.dia }}</span></td><td class="font-medium">{{ r.nombre }}<p v-if="r.notas" class="text-xs text-marca-muted">{{ r.notas }}</p></td><td class="text-right tabular-nums">{{ r.personas }}</td><td class="tabular-nums">{{ r.telefono }}</td><td class="text-marca-muted text-xs">{{ r.origen }}</td>
              <td><span class="badge" :class="{ 'bg-amber-50 text-amber-700': r.estado === 'pending', 'bg-emerald-50 text-emerald-700': r.estado === 'confirmed', 'bg-gris-light text-marca-muted': ['cancelled', 'completed'].includes(r.estado), 'bg-red-50 text-carmin': r.estado === 'no_show' }">{{ { pending: 'Pendiente', confirmed: 'Confirmada', cancelled: 'Cancelada', completed: 'Vino', no_show: 'No vino' }[r.estado] }}</span></td>
              <td class="text-right whitespace-nowrap"><button v-if="r.estado === 'pending'" class="btn-ghost !px-2 text-xs" @click="estado(r, 'confirmed')">Confirmar</button><button v-if="['pending', 'confirmed'].includes(r.estado)" class="btn-ghost !px-2 text-xs" @click="estado(r, 'completed')">Vino</button><button v-if="['pending', 'confirmed'].includes(r.estado)" class="btn-ghost !px-2 text-xs text-carmin" @click="estado(r, 'cancelled')">Cancelar</button></td>
            </tr>
            <tr v-if="!reservas.length"><td colspan="7" class="text-center text-marca-muted py-8">Sin reservas en el período.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="card">
        <div class="flex items-center justify-between mb-1"><h2 class="font-bold">QR por mesa</h2><button class="btn-secondary !py-1 text-xs" @click="imprimir">Imprimir</button></div>
        <p class="text-xs text-marca-muted mb-3">El cliente escanea, ve la carta y pide: el pedido cae en la comanda de esa mesa y en cocina. <span :class="menuActivo ? 'text-emerald-700' : 'text-carmin'">{{ menuActivo ? 'Menú activo.' : 'Activá el menú en Configuración → Tienda y canales.' }}</span></p>
        <div id="qrs" class="grid grid-cols-2 gap-3">
          <div v-for="m in mesas" :key="m.id" class="qr border border-marca-borde rounded-xl p-2 text-center"><canvas :ref="el => dibujar(el, m.url)"></canvas><p class="font-bold text-sm mt-1">{{ m.nombre }}</p><p class="text-[10px] text-marca-muted">{{ m.sector }} · escaneá para pedir</p></div>
        </div>
      </div>
    </div>
    <Modal :abierto="nuevaAbierta" titulo="Nueva reserva" @cerrar="nuevaAbierta = false">
      <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2"><label class="label">Nombre</label><input v-model="nr.nombre" class="input" /></div>
        <div><label class="label">Teléfono</label><input v-model="nr.telefono" class="input" /></div><div><label class="label">Personas</label><input v-model.number="nr.personas" type="number" min="1" class="input" /></div>
        <div><label class="label">Fecha</label><input v-model="nr.fecha" type="date" class="input" /></div><div><label class="label">Hora</label><input v-model="nr.hora" type="time" class="input" /></div>
        <div class="col-span-2"><label class="label">Notas</label><input v-model="nr.notas" class="input" placeholder="Cumpleaños, ventana, silla alta…" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="nuevaAbierta = false">Cancelar</button><button class="btn-primary" :disabled="nr.processing || !nr.nombre || !nr.fecha || !nr.hora" @click="nr.post('/gastronomia/reservas', { preserveScroll: true, onSuccess: () => { nuevaAbierta = false; nr.reset() } })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import QRCode from 'qrcode'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { hoyISO } from '@/util/formato'
defineProps({ periodo: Object, reservas: Array, kpis: Object, urlReservas: String, reservasActivas: Boolean, urlMenu: String, menuActivo: Boolean, mesas: Array })
const dibujar = (el, url) => { if (el && !el.dataset.ok) { el.dataset.ok = 1; QRCode.toCanvas(el, url, { width: 110, margin: 1 }).catch(() => {}) } }
function estado(r, e) { router.post(`/gastronomia/reservas/${r.id}/estado`, { estado: e }, { preserveScroll: true }) }
const nuevaAbierta = ref(false)
const nr = useForm({ nombre: '', telefono: '', personas: 2, fecha: hoyISO(), hora: '21:00', notas: '' })
function imprimir() { window.print() }
</script>

<style>
@media print { body * { visibility: hidden } #qrs, #qrs * { visibility: visible } #qrs { position: absolute; left: 0; top: 0; width: 100%; display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px } .qr { page-break-inside: avoid } }
</style>
