<template>
  <div class="min-h-screen bg-gray-100 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-lg p-8 w-full max-w-md">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-blue-600">BigSysWeb ERP</h1>
        <p class="text-gray-500 mt-1">Ingresa a tu cuenta</p>
      </div>
      <form @submit.prevent="submit">
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input v-model="form.email" type="email" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="tu@email.com" />
          <p v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</p>
        </div>
        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-1">Contrasena</label>
          <input v-model="form.password" type="password" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
          <p v-if="form.errors.password" class="text-red-500 text-xs mt-1">{{ form.errors.password }}</p>
        </div>
        <button type="submit" :disabled="form.processing" class="w-full bg-blue-600 text-white py-2 rounded-lg font-medium hover:bg-blue-700 disabled:opacity-50 transition">
          {{ form.processing ? 'Ingresando...' : 'Ingresar' }}
        </button>
      </form>
    </div>
  </div>
</template>
<script setup>
import { useForm } from '@inertiajs/vue3'
const form = useForm({ email: '', password: '' })
function submit() { form.post('/login') }
</script>
