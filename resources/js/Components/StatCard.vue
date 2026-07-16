<template>
  <div class="bg-white rounded-xl shadow p-6">
    <div class="flex items-center justify-between mb-2">
      <span class="text-2xl">{{ icono }}</span>
      <span class="text-xs font-medium px-2 py-1 rounded-full" :class="colorClass">{{ titulo }}</span>
    </div>
    <div class="text-3xl font-bold text-gray-800">{{ valor }}</div>
  </div>
</template>
<script setup>
import { computed } from 'vue'
const props = defineProps({ titulo: String, valor: [String, Number], icono: String, color: { type: String, default: 'blue' } })
const colorClass = computed(() => ({ blue: 'bg-blue-100 text-blue-700', green: 'bg-green-100 text-green-700', purple: 'bg-purple-100 text-purple-700', red: 'bg-red-100 text-red-700' }[props.color] || 'bg-gray-100 text-gray-700'))
</script>
