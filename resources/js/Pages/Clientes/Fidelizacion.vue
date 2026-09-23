<template>
  <AppLayout titulo="Puntos">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Programa de puntos</h1><p class="page-subtitle">{{ config.activo ? `1 punto cada ${moneda(config.pesos_por_punto, 0)} · cada punto vale ${moneda(config.valor_punto)} · canje mínimo ${config.minimo_canje}` : 'El programa está apagado. Activalo en Configuración → Tienda y canales → Programa de puntos.' }}</p></div>
      <div class="flex gap-2"><Link href="/configuracion/tienda#puntos" class="btn-secondary">Configurar</Link><button class="btn-primary" @click="ajAbierto = true">Sumar o canjear</button></div>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Clientes con puntos</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.clientes }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Puntos en circulación</p><p class="text-xl font-extrabold tabular-nums">{{ cantidad(kpis.en_circulacion) }}</p><p class="text-[11px] text-marca-muted">= {{ moneda(kpis.en_circulacion * config.valor_punto, 0) }} en descuentos</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Otorgados este mes</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ cantidad(kpis.otorgados_mes) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Canjeados</p><p class="text-xl font-extrabold tabular-nums text-violeta">{{ cantidad(kpis.canjeados) }}</p></div>
    </div>
    <div class="grid lg:grid-cols-2 gap-4">
      <div class="card p-0 overflow-hidden"><div class="px-4 py-3 border-b border-marca-borde font-bold">Ranking</div>
        <table class="table text-sm"><thead><tr><th>#</th><th>Cliente</th><th class="text-right">Puntos</th><th class="text-right">Valen</th></tr></thead>
          <tbody><tr v-for="(r, i) in ranking" :key="r.id" class="cursor-pointer" @click="$inertia.visit(`/clientes/${r.id}`)"><td class="text-marca-muted">{{ i + 1 }}</td><td class="font-medium">{{ r.nombre }}</td><td class="text-right tabular-nums font-bold text-violeta">★ {{ cantidad(r.puntos) }}</td><td class="text-right tabular-nums">{{ moneda(r.pesos, 0) }}</td></tr>
          <tr v-if="!ranking.length"><td colspan="4" class="text-center text-marca-muted py-6">Todavía nadie sumó puntos.</td></tr></tbody></table></div>
      <div class="card p-0 overflow-hidden"><div class="px-4 py-3 border-b border-marca-borde font-bold">Movimientos</div>
        <div class="max-h-[60vh] overflow-y-auto"><table class="table text-xs"><thead><tr><th>Fecha</th><th>Cliente</th><th>Motivo</th><th class="text-right">Puntos</th></tr></thead>
          <tbody><tr v-for="m in movimientos" :key="m.id"><td class="tabular-nums whitespace-nowrap">{{ m.fecha }}</td><td>{{ m.cliente }}</td><td class="text-marca-muted">{{ m.motivo }}</td><td class="text-right tabular-nums font-semibold" :class="m.puntos < 0 ? 'text-carmin' : 'text-emerald-700'">{{ m.puntos > 0 ? '+' : '' }}{{ cantidad(m.puntos) }}</td></tr>
          <tr v-if="!movimientos.length"><td colspan="4" class="text-center text-marca-muted py-6">Sin movimientos.</td></tr></tbody></table></div></div>
    </div>
    <Modal :abierto="ajAbierto" titulo="Sumar o canjear puntos" @cerrar="ajAbierto = false">
      <div class="space-y-3">
        <div><label class="label">Cliente</label><select v-model="aj.contact_id" class="input"><option :value="null">Elegir…</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }} (★ {{ cantidad(c.puntos) }})</option></select></div>
        <div><label class="label">Puntos (negativo = canje)</label><input v-model.number="aj.puntos" type="number" step="any" class="input" /><p class="text-[11px] text-marca-muted mt-1">Un canje de {{ Math.abs(aj.puntos || 0) }} puntos vale {{ moneda(Math.abs(aj.puntos || 0) * config.valor_punto) }}.</p></div>
        <div><label class="label">Motivo</label><input v-model="aj.motivo" class="input" placeholder="Ej. Canje en mostrador / Promo cumpleaños" /></div>
        <p v-if="aj.errors.puntos" class="text-carmin text-xs">{{ aj.errors.puntos }}</p>
      </div>
      <template #pie><button class="btn-secondary" @click="ajAbierto = false">Cancelar</button><button class="btn-primary" :disabled="aj.processing || !aj.contact_id || !aj.puntos || !aj.motivo" @click="aj.post('/clientes/fidelizacion/ajustar', { preserveScroll: true, onSuccess: () => { ajAbierto = false; aj.reset() } })">Registrar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'
defineProps({ config: Object, ranking: Array, movimientos: Array, kpis: Object, clientes: Array })
const ajAbierto = ref(false)
const aj = useForm({ contact_id: null, puntos: null, motivo: '' })
</script>
