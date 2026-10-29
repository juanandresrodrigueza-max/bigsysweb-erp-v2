<template>
  <AppLayout titulo="Que vuelvan">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/servicios" class="text-xs text-marca-muted hover:text-carmin">← Servicio técnico</Link><h1 class="page-title">Que vuelvan</h1><p class="page-subtitle">A quiénes les toca el próximo service: por fecha, por {{ (config.medidor || 'uso').toLowerCase() }} estimado con lo que usan, o por pieza. Avisales por WhatsApp.</p></div>
      <select :value="dias" class="input !w-auto" @change="router.get('/servicios/que-vuelvan', { dias: $event.target.value }, { preserveState: true })"><option :value="15">Próximos 15 días</option><option :value="30">Próximos 30 días</option><option :value="60">Próximos 60 días</option></select>
    </div>
    <div class="grid lg:grid-cols-[1fr_320px] gap-4">
      <div class="card p-0 overflow-x-auto" data-e2e="lista">
        <table class="table text-sm"><thead><tr><th>Cliente</th><th>{{ config.equipo }}</th><th>Le toca</th><th></th></tr></thead>
          <tbody><tr v-for="x in lista" :key="x.ot_id"><td>{{ x.cliente }}</td><td>{{ x.equipo }} <span class="text-xs text-marca-muted">{{ x.serie }}</span></td><td class="text-xs"><p v-for="m in x.motivos" :key="m">{{ m }}</p></td>
            <td class="whitespace-nowrap text-right"><a v-if="x.whatsapp" :href="x.whatsapp" target="_blank" class="btn-secondary !py-1 text-xs" @click="router.post(`/servicios/${x.ot_id}/recordado`, {}, { preserveScroll: true })">WhatsApp</a><span v-if="x.recordado" class="block text-[10px] text-emerald-700">avisado {{ x.recordado }}</span></td></tr>
            <tr v-if="!lista.length"><td colspan="4" class="text-center text-marca-muted py-8">A nadie le toca en estos días.</td></tr></tbody></table>
      </div>
      <div class="card self-start" data-e2e="piezas"><h2 class="font-bold mb-1">Alertas por pieza</h2><p class="text-xs text-marca-muted mb-2">Cada cuánto {{ (config.medidor || 'uso').toLowerCase() }} se cambia (la correa cada 60.000). Se mira la última vez que se cambió en cada {{ config.equipo.toLowerCase() }}.</p>
        <div v-for="(p, i) in pz" :key="i" class="flex gap-2 mb-1"><input v-model="p.nombre" class="input !py-1" placeholder="Pieza" /><input v-model.number="p.cada" type="number" class="input !py-1 !w-28" placeholder="Cada" /><button class="text-carmin text-xs" @click="pz.splice(i, 1)">✕</button></div>
        <button class="text-xs text-violeta" @click="pz.push({ nombre: '', cada: null })">+ pieza</button>
        <button class="btn-primary w-full mt-2 !py-1.5" @click="router.post('/servicios/piezas', { piezas: pz.filter(p => p.nombre && p.cada) }, { preserveScroll: true })">Guardar</button></div>
    </div>
  </AppLayout>
</template>
<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
const props = defineProps({ lista: Array, dias: Number, piezas: Array, config: Object })
const pz = reactive(props.piezas.map(p => ({ ...p })))
</script>
