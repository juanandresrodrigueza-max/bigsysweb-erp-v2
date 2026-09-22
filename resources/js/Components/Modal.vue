<template>
  <Teleport to="body">
    <div v-if="abierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
      <div class="absolute inset-0 bg-black/50" @click="$emit('cerrar')"></div>
      <div class="relative bg-white w-full sm:rounded-2xl rounded-t-2xl shadow-2xl max-h-[92vh] flex flex-col" :class="ancho">
        <div class="flex items-center justify-between px-5 py-4 border-b border-marca-borde">
          <h3 class="font-bold text-lg">{{ titulo }}</h3>
          <button @click="$emit('cerrar')" class="p-1.5 rounded-lg hover:bg-marca-fondo"><Icono nombre="x" clase="w-5 h-5" /></button>
        </div>
        <div class="px-5 py-4 overflow-y-auto"><slot /></div>
        <div v-if="$slots.pie" class="px-5 py-3 border-t border-marca-borde flex justify-end gap-2"><slot name="pie" /></div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import Icono from '@/Components/Icono.vue'
defineProps({ abierto: Boolean, titulo: String, ancho: { type: String, default: 'max-w-lg' } })
defineEmits(['cerrar'])
</script>
