<template>
  <div class="card" data-e2e="personas">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <div><h2 class="font-bold">Contactos</h2><p class="text-xs text-marca-muted">Las personas con las que hablás y qué documentos le llegan a cada una. Al enviar, el sistema sugiere a quien corresponde.</p></div>
      <button class="btn-secondary !py-1 text-xs" @click="editar()">Agregar contacto</button>
    </div>
    <div v-if="cargando" class="text-sm text-marca-muted py-4 text-center">Cargando…</div>
    <div v-else class="grid sm:grid-cols-2 gap-3">
      <div v-for="p in personas" :key="p.id" class="rounded-xl border border-marca-borde p-3 text-sm">
        <div class="flex items-start justify-between gap-2">
          <div><p class="font-semibold">{{ p.nombre }}</p><p v-if="p.cargo" class="text-xs text-marca-muted">{{ p.cargo }}</p></div>
          <div class="whitespace-nowrap"><button class="text-xs text-violeta" @click="editar(p)">editar</button> <button class="text-xs text-carmin ml-1" @click="borrar(p)">quitar</button></div>
        </div>
        <p v-if="p.telefono" class="text-xs mt-1">📞 {{ p.telefono }}</p><p v-if="p.email" class="text-xs">✉ {{ p.email }}</p>
        <div class="flex flex-wrap gap-1 mt-2"><span v-for="(l, k) in RECIBE" v-show="p[k]" :key="k" class="badge bg-violeta/10 text-violeta">{{ l }}</span></div>
        <p v-if="p.notas" class="text-xs text-marca-muted mt-1">{{ p.notas }}</p>
      </div>
      <p v-if="!personas.length" class="text-sm text-marca-muted sm:col-span-2 text-center py-4">Sin contactos cargados. Agregá al titular, a quien compra o a quien paga.</p>
    </div>
    <div v-if="form" class="mt-4 rounded-xl bg-marca-fondo p-3 grid sm:grid-cols-2 gap-2 text-sm">
      <div><label class="label">Nombre</label><input v-model="form.nombre" class="input !py-1.5" /></div>
      <div><label class="label">Cargo</label><input v-model="form.cargo" class="input !py-1.5" placeholder="Titular, compras, pagos…" /></div>
      <div><label class="label">Teléfono</label><input v-model="form.telefono" class="input !py-1.5" /></div>
      <div><label class="label">Email</label><input v-model="form.email" type="email" class="input !py-1.5" /></div>
      <div class="sm:col-span-2"><label class="label">Notas</label><input v-model="form.notas" class="input !py-1.5" /></div>
      <div class="sm:col-span-2 flex flex-wrap gap-x-4 gap-y-1"><label v-for="(l, k) in RECIBE" :key="k" class="flex items-center gap-1.5 text-xs"><input v-model="form[k]" type="checkbox" class="accent-carmin" /> Recibe {{ l.toLowerCase() }}</label></div>
      <p v-if="error" class="sm:col-span-2 text-carmin text-xs">{{ error }}</p>
      <div class="sm:col-span-2 flex gap-2"><button class="btn-primary !py-1 text-xs" :disabled="!form.nombre || guardando" @click="guardar">Guardar</button><button class="btn-ghost !py-1 text-xs" @click="form = null">Cancelar</button></div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
const props = defineProps({ contactoId: { type: Number, required: true } })
const RECIBE = { recibe_comprobantes: 'Comprobantes', recibe_cobranzas: 'Recibos y resumen', recibe_pagos: 'Órdenes de pago y compra' }
const personas = ref([]), cargando = ref(true), form = ref(null), guardando = ref(false), error = ref('')
async function cargar() { cargando.value = true; try { personas.value = (await window.axios.get(`/contactos/${props.contactoId}/personas`)).data } finally { cargando.value = false } }
function editar(p = null) { error.value = ''; form.value = p ? { ...p } : { id: null, nombre: '', cargo: '', telefono: '', email: '', notas: '', recibe_comprobantes: false, recibe_cobranzas: false, recibe_pagos: false } }
async function guardar() {
  guardando.value = true; error.value = ''
  try { await window.axios.post(`/contactos/${props.contactoId}/personas${form.value.id ? '/' + form.value.id : ''}`, form.value); form.value = null; await cargar() }
  catch (e) { error.value = Object.values(e.response?.data?.errors ?? {}).flat().join(' ') || 'No se pudo guardar.' }
  finally { guardando.value = false }
}
async function borrar(p) { await window.axios.delete(`/contactos/${props.contactoId}/personas/${p.id}`); await cargar() }
onMounted(cargar)
</script>
