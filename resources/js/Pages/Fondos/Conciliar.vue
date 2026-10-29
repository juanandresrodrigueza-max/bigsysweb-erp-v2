<template>
  <AppLayout titulo="Fondos · Conciliar">
    <!-- Fase 35: subís la liquidación de la procesadora o billetera tal como viene y el sistema la cruza contra tus cupones. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/fondos" class="text-xs text-marca-muted hover:text-carmin">← Fondos</Link><h1 class="page-title">Conciliar tarjetas y billeteras</h1><p class="page-subtitle">Subí el archivo que te manda la procesadora: lo que cierra queda listo para tildar, lo que no, te lo explica.</p></div>
      <Link href="/fondos/tarjetas" class="btn-secondary">Cupones en cartera</Link>
    </div>

    <div class="grid lg:grid-cols-[1fr_380px] gap-4">
      <div class="space-y-4">
        <div class="card" data-e2e="subir">
          <h2 class="font-bold mb-2">Subir una liquidación</h2>
          <div class="grid sm:grid-cols-3 gap-2 text-sm">
            <label>Fondo<select v-model="sf.cuenta_fondos_id" class="input" data-e2e="fondo"><option v-for="f in fondosConcilia" :key="f.id" :value="f.id">{{ f.nombre }}</option></select></label>
            <label>De quién es<select v-model="sf.origen" class="input"><option value="">Reconocer solo</option><option v-for="(l, k) in origenes" :key="k" :value="k" :disabled="sin_lector.includes(k)">{{ l }}{{ sin_lector.includes(k) ? ' (falta archivo real)' : '' }}</option></select></label>
            <label>Archivo (Excel o CSV)<input type="file" accept=".csv,.txt,.xlsx,.xls" class="input !py-1.5 text-xs" @change="sf.archivo = $event.target.files[0]" data-e2e="archivo" /></label>
          </div>
          <p v-if="sf.errors.archivo" class="text-carmin text-xs mt-2" data-e2e="error">{{ sf.errors.archivo }}</p>
          <button class="btn-primary mt-3" :disabled="sf.processing || !sf.archivo" @click="sf.post('/fondos/conciliar/subir', { forceFormData: true })" data-e2e="cruzar">{{ sf.processing ? 'Cruzando…' : 'Cruzar contra mis cupones' }}</button>
          <p class="text-[11px] text-marca-muted mt-2">Lee Fiserv, Naranja X, GOcuotas, Mercado Pago (settlement) y NAVE. Payway, Getnet y MODO esperan un archivo real para armar el lector.</p>
        </div>

        <div class="card p-0 overflow-x-auto">
          <h2 class="font-bold px-4 py-3 border-b border-marca-borde">Lotes</h2>
          <table class="table text-sm"><thead><tr><th>#</th><th>Origen</th><th>Fondo</th><th>Período</th><th class="text-right">Cerró</th><th class="text-right">A revisar</th><th>Estado</th></tr></thead>
            <tbody><tr v-for="l in lotes" :key="l.id" class="cursor-pointer hover:bg-gris-light" @click="router.visit(`/fondos/conciliar/${l.id}`)">
              <td>{{ l.id }}</td><td>{{ l.origen }}</td><td>{{ l.fondo }}</td><td class="text-xs">{{ l.periodo }}</td>
              <td class="text-right tabular-nums">{{ moneda(l.resumen?.cerro ?? 0, 0) }}</td><td class="text-right tabular-nums" :class="l.resumen?.revisar_n ? 'text-amber-700 font-bold' : ''">{{ l.resumen?.revisar_n ?? 0 }}</td>
              <td><span class="badge" :class="{ propuesta: 'bg-violeta/10 text-violeta', asentada: 'bg-emerald-50 text-emerald-700', deshecha: 'bg-gris-light text-marca-muted' }[l.estado]">{{ l.estado }}</span></td></tr>
              <tr v-if="!lotes.length"><td colspan="7" class="text-center text-marca-muted py-6">Todavía no subiste ninguna liquidación.</td></tr></tbody></table>
        </div>
      </div>

      <div class="card self-start" data-e2e="fondos">
        <h2 class="font-bold mb-1">Qué es cada fondo</h2>
        <p class="text-xs text-marca-muted mb-2">Decile al sistema qué procesadora liquida en cada fondo, dónde deposita y lo pactado. Lo sugerido viene por el nombre.</p>
        <div v-for="f in ff" :key="f.id" class="border-t border-marca-borde py-2 text-sm">
          <div class="flex items-center justify-between gap-2"><b>{{ f.nombre }}</b><label class="text-xs flex items-center gap-1"><input type="checkbox" v-model="f.concilia" class="accent-carmin" /> se concilia</label></div>
          <p class="text-[11px] text-marca-muted">{{ f.tipo }}<span v-if="f.cupones_cartera !== null"> · {{ f.cupones_cartera }} cupones en cartera</span></p>
          <template v-if="f.concilia && ['tarjeta', 'billetera'].includes(f.tipo)">
            <div class="flex flex-wrap gap-1 mt-1"><label v-for="(l, k) in origenes" :key="k" class="text-[11px] border rounded px-1.5 py-0.5 cursor-pointer" :class="f.procesadoras.includes(k) ? 'bg-violeta text-white border-violeta' : 'border-marca-borde'"><input type="checkbox" class="hidden" :value="k" v-model="f.procesadoras" />{{ l }}</label></div>
            <div class="grid grid-cols-3 gap-1 mt-1 text-xs"><label>Comisión %<input v-model="f.comision_pactada" type="number" step="0.01" class="input !py-0.5" /></label><label>Plazo días<input v-model="f.plazo_pactado" type="number" class="input !py-0.5" /></label><label>Deposita en<select v-model="f.banco_id" class="input !py-0.5"><option :value="null">—</option><option v-for="b in bancos" :key="b.id" :value="b.id">{{ b.nombre }}</option></select></label></div>
          </template>
        </div>
        <button class="btn-primary mt-2 w-full" @click="router.post('/fondos/conciliar/fondos', { fondos: ff }, { preserveScroll: true })" data-e2e="guardar-fondos">Guardar</button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ fondos: Array, origenes: Object, sin_lector: Array, bancos: Array, lotes: Array })
const ff = reactive(props.fondos.map(f => ({ ...f, procesadoras: [...f.procesadoras] })))
const fondosConcilia = computed(() => props.fondos.filter(f => f.concilia && ['tarjeta', 'billetera'].includes(f.tipo)))
const sf = useForm({ cuenta_fondos_id: fondosConcilia.value[0]?.id ?? null, origen: '', archivo: null })
</script>
