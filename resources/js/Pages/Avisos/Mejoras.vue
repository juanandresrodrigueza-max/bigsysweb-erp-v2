<template>
  <AppLayout titulo="Mejoras">
    <h1 class="page-title">Lo que podés sumar</h1>
    <p class="page-subtitle mb-5">Módulos que tu plan todavía no tiene. Tocá “Me interesa” y te escribimos para contarte cómo sumarlo, sin compromiso.</p>
    <p v-if="!mejoras.length" class="card text-sm text-marca-muted">¡Ya tenés todo! No hay módulos para sumar.</p>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <div v-for="m in mejoras" :key="m.modulo" class="card flex flex-col" data-e2e="mejora">
        <div class="flex items-center gap-2"><Icono :nombre="m.icono" clase="w-5 h-5 text-violeta" /><p class="font-bold">{{ m.nombre }}</p><span v-if="m.grupo" class="badge bg-marca-fondo text-marca-muted ml-auto">{{ m.grupo }}</span></div>
        <p class="text-sm text-marca-muted mt-1 flex-1">{{ m.que_hace }}</p>
        <p v-if="m.interesado" class="text-xs text-emerald-700 mt-3">✓ Pediste información el {{ m.cuando }}</p>
        <template v-else-if="puede">
          <input v-if="abierto === m.modulo" v-model="comentario" class="input mt-3 text-sm" placeholder="¿Algo que quieras contarnos? (opcional)" />
          <button class="btn-primary mt-2 !py-1.5 text-sm" @click="abierto === m.modulo ? enviar(m) : (abierto = m.modulo)" data-e2e="me-interesa">{{ abierto === m.modulo ? 'Mandar' : 'Me interesa' }}</button>
        </template>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
// Fase 40.5: la página de mejoras con lo que no está contratado y el “Me interesa”.
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
defineProps({ mejoras: Array, puede: Boolean })
const abierto = ref(null), comentario = ref('')
function enviar(m) { router.post('/mejoras', { modulo: m.modulo, comentario: comentario.value }, { preserveScroll: true, onSuccess: () => { abierto.value = null; comentario.value = '' } }) }
</script>
