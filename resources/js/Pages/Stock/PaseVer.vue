<template>
  <AppLayout :titulo="pase.numero">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/stock/pases" class="text-xs text-marca-muted hover:text-carmin">← Pases</Link><h1 class="page-title">{{ pase.numero }} · {{ pase.origen }} → {{ pase.destino }}</h1><p class="page-subtitle">{{ pase.estado_label }} · enviado {{ pase.enviada ?? pase.fecha }} por {{ pase.usuario }}<span v-if="pase.recibida"> · recibido {{ pase.recibida }}</span></p></div>
      <div class="flex gap-2"><a :href="pdf" target="_blank" class="btn-secondary">PDF de control</a><a :href="`https://wa.me/?text=${encodeURIComponent('Pase ' + pase.numero + ' de ' + pase.origen + ' a ' + pase.destino + ': ' + pdf)}`" target="_blank" class="btn-secondary">Mandar por WhatsApp</a></div>
    </div>
    <div class="card p-0 overflow-x-auto" data-e2e="items">
      <table class="table text-sm"><thead><tr><th>Artículo</th><th class="text-right">Enviado</th><th class="text-right">{{ pase.estado === 'en_viaje' ? 'Contaste' : 'Recibido' }}</th><th class="text-right">Diferencia</th></tr></thead>
        <tbody><tr v-for="i in pase.items" :key="i.id" :class="diferencia(i) ? 'bg-red-50' : ''"><td>{{ i.articulo }} <span class="text-xs text-marca-muted">{{ i.codigo }}</span></td><td class="text-right tabular-nums">{{ i.enviado }} {{ i.unidad }}</td>
          <td class="text-right"><input v-if="pase.estado === 'en_viaje'" v-model.number="conteos[i.id]" type="number" step="any" min="0" class="input !py-0.5 !w-28 text-right inline-block" :data-contar="i.id" /><span v-else class="tabular-nums">{{ i.recibido }}</span></td>
          <td class="text-right tabular-nums" :class="diferencia(i) ? 'text-carmin font-bold' : 'text-marca-muted'">{{ diferencia(i) === null ? '' : (diferencia(i) > 0 ? '+' : '') + diferencia(i) }}</td></tr></tbody></table>
    </div>
    <div v-if="pase.estado === 'en_viaje'" class="flex gap-2 mt-3"><input v-model="notas" class="input" placeholder="Notas al recibir (llegó una caja rota…)" /><button class="btn-primary whitespace-nowrap" @click="router.post(`/stock/pases/${pase.id}/recibir`, { conteos, notas }, { preserveScroll: true })" data-e2e="recibir">Recibir lo contado</button></div>
    <div v-if="pase.estado === 'con_diferencias'" class="card mt-3 border-carmin/40 text-sm">Llegó distinto de lo que salió. Si lo que faltó no va a aparecer (quedó en el camión, se perdió), devolvelo al stock del origen. <button class="btn-secondary !py-1 text-xs ml-2" @click="router.post(`/stock/pases/${pase.id}/devolver`, {}, { preserveScroll: true })">Lo que faltó vuelve a {{ pase.origen }}</button></div>
    <p v-if="pase.notas" class="text-xs text-marca-muted mt-3 whitespace-pre-line">{{ pase.notas }}</p>
  </AppLayout>
</template>
<script setup>
import { reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
const props = defineProps({ pase: Object, pdf: String })
const conteos = reactive(Object.fromEntries(props.pase.items.map(i => [i.id, null]))), notas = ref('')
const diferencia = i => { const r = props.pase.estado === 'en_viaje' ? conteos[i.id] : i.recibido; return r === null || r === '' || r === undefined ? null : Math.round((Number(r) - i.enviado) * 1000) / 1000 || 0 }
</script>
