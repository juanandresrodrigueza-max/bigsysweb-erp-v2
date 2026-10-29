<template>
  <AppLayout titulo="Pases entre sucursales">
    <!-- Fase 37.4: se arma escaneando, sale el stock al enviar, y la otra sucursal lo recibe contando. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/stock/movimientos" class="text-xs text-marca-muted hover:text-carmin">← Movimientos</Link><h1 class="page-title">Pases entre sucursales</h1><p class="page-subtitle">Lo que sale se cuenta al salir y al llegar. Las diferencias las ven las dos sucursales.</p></div>
    </div>
    <div class="grid lg:grid-cols-[1fr_1fr] gap-4">
      <div class="card" data-e2e="armar">
        <h2 class="font-bold mb-2">Armar un pase</h2>
        <div class="grid grid-cols-2 gap-2 text-sm mb-2"><label>Sale de<select v-model="f.origen_id" class="input"><option v-for="d in depositos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></label><label>Va a<select v-model="f.destino_id" class="input" data-e2e="destino"><option v-for="d in depositos.filter(x => x.id !== f.origen_id)" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></label></div>
        <input ref="lector" v-model="codigo" class="input text-lg" placeholder="Escaneá (3*código para tres; etiquetas de balanza)" @keydown.enter.prevent="leer" data-e2e="lector" />
        <p v-if="msg" class="text-xs mt-1" :class="msgOk ? 'text-emerald-700' : 'text-carmin'">{{ msg }}</p>
        <table class="table text-sm mt-2"><tbody><tr v-for="(it, k) in items" :key="k"><td>{{ it.nombre }}</td><td class="w-28"><input v-model.number="it.cantidad" type="number" step="any" class="input !py-0.5 text-right" /></td><td class="text-xs">{{ it.unidad }}</td><td><button class="text-carmin text-xs" @click="items.splice(k, 1)">✕</button></td></tr>
          <tr v-if="!items.length"><td class="text-center text-marca-muted py-6">Escaneá lo que va.</td></tr></tbody></table>
        <input v-model="f.notas" class="input mt-2" placeholder="Notas (quién lo lleva, en qué vehículo)" />
        <p v-if="f.errors.items" class="text-carmin text-xs mt-1">{{ f.errors.items }}</p>
        <button class="btn-primary w-full mt-2" :disabled="f.processing || !items.length || !f.destino_id" @click="enviar" data-e2e="enviar">Enviar: sale el stock de {{ depositos.find(d => d.id === f.origen_id)?.nombre }}</button>
      </div>
      <div class="card p-0 overflow-x-auto" data-e2e="lista">
        <h2 class="font-bold px-4 py-3 border-b border-marca-borde">Pases</h2>
        <table class="table text-sm"><thead><tr><th>Pase</th><th>De → a</th><th>Estado</th></tr></thead>
          <tbody><tr v-for="p in pases" :key="p.id" class="cursor-pointer hover:bg-gris-light" @click="router.visit(`/stock/pases/${p.id}`)"><td class="font-semibold">{{ p.numero }}<span class="block text-xs text-marca-muted">{{ p.fecha }}</span></td><td>{{ p.origen }} → {{ p.destino }}</td>
            <td><span class="badge" :class="{ en_viaje: 'bg-violeta/10 text-violeta', recibida: 'bg-emerald-50 text-emerald-700', con_diferencias: 'bg-carmin-light text-carmin', confirmada: 'bg-gris-light', anulada: 'bg-gris-light text-marca-muted' }[p.estado]">{{ p.estado_label }}</span></td></tr>
            <tr v-if="!pases.length"><td colspan="3" class="text-center text-marca-muted py-6">Todavía no hay pases.</td></tr></tbody></table>
      </div>
    </div>
  </AppLayout>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
const props = defineProps({ pases: Array, depositos: Array })
const f = useForm({ origen_id: props.depositos[0]?.id ?? null, destino_id: props.depositos[1]?.id ?? null, notas: '', items: [] })
const items = ref([]), codigo = ref(''), msg = ref(''), msgOk = ref(true), lector = ref(null)
async function leer() {
  if (!codigo.value.trim()) return
  const { data } = await window.axios.post('/stock/pases/leer', { codigo: codigo.value })
  if (data.ok) { const ya = items.value.find(i => i.product_id === data.product_id && !data.pesable); if (ya) ya.cantidad = Math.round((ya.cantidad + data.cantidad) * 1000) / 1000; else items.value.unshift({ product_id: data.product_id, nombre: data.nombre, unidad: data.unidad, cantidad: data.cantidad }); msg.value = `+${data.cantidad} ${data.nombre}`; msgOk.value = true }
  else { msg.value = data.mensaje; msgOk.value = false }
  codigo.value = ''; lector.value?.focus()
}
function enviar() { f.transform(d => ({ ...d, items: items.value.map(i => ({ product_id: i.product_id, cantidad: i.cantidad })) })).post('/stock/pases') }
onMounted(() => lector.value?.focus())
</script>
