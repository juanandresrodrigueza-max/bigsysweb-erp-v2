<template>
  <!-- Fase 71: lo que el CRM habló con este cliente, y el botón para seguir la charla allá -->
  <div class="card" data-e2e="crm-cliente">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <div><h2 class="font-bold">Conversaciones del CRM</h2><p class="text-xs text-marca-muted">WhatsApp, mail y redes que el equipo de ventas habló con este cliente, en lectura. Para escribirle, se sigue en el CRM.</p></div>
      <div class="flex gap-2">
        <a v-if="crm.url_escribir" :href="crm.url_escribir" target="_blank" class="btn-primary !py-1 text-xs" data-e2e="crm-escribir">Escribirle por el CRM ↗</a>
        <a v-if="crm.url_ficha" :href="crm.url_ficha" target="_blank" class="btn-secondary !py-1 text-xs">Ver en el CRM ↗</a>
      </div>
    </div>
    <p v-if="cargando" class="text-sm text-marca-muted py-4 text-center">Buscando en el CRM…</p>
    <p v-else-if="error" class="text-sm text-carmin">{{ error }}</p>
    <p v-else-if="!crm.external_id" class="text-sm text-marca-muted">Este cliente todavía no está en el CRM: se manda solo en la próxima sincronización, o guardalo de nuevo para mandarlo ahora.</p>
    <p v-else-if="!convs.length" class="text-sm text-marca-muted py-4 text-center">El CRM no tiene conversaciones con este cliente.</p>
    <div v-else class="divide-y divide-marca-borde/60">
      <div v-for="c in convs" :key="c.id" class="py-3 text-sm">
        <div class="flex flex-wrap items-center gap-2 mb-1">
          <span class="badge bg-lavanda-light text-violeta">{{ c.canal }}</span>
          <span class="badge" :class="c.estado === 'open' ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ c.estado === 'open' ? 'abierta' : c.estado }}</span>
          <span v-if="c.no_leidos" class="badge bg-carmin-light text-carmin">{{ c.no_leidos }} sin leer</span>
          <span class="text-xs text-marca-muted">último mensaje {{ c.ultimo }}</span>
          <a :href="c.url" target="_blank" class="ml-auto text-xs text-violeta">Abrir en el CRM ↗</a>
        </div>
        <div v-for="(m, i) in c.mensajes" :key="i" class="max-w-[85%] rounded-2xl px-3 py-1.5 text-xs mb-1" :class="m.direccion === 'outbound' ? 'bg-lavanda-light ml-auto' : 'bg-marca-fondo'">
          <p class="whitespace-pre-wrap">{{ m.texto }}</p><p class="text-[11px] text-marca-muted">{{ m.direccion === 'outbound' ? 'Nosotros' : 'Cliente' }} · {{ m.cuando }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
const props = defineProps({ clienteId: { type: Number, required: true }, crm: { type: Object, required: true } })
const convs = ref([]), cargando = ref(true), error = ref('')
onMounted(async () => { try { convs.value = (await window.axios.get(`/clientes/${props.clienteId}/crm`)).data } catch (e) { error.value = 'No se pudo consultar al CRM ahora. Probá en un momento.' } finally { cargando.value = false } })
</script>
