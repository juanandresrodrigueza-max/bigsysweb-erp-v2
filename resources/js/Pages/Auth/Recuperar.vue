<template>
  <Head title="Recuperar contraseña" />
  <div class="min-h-screen flex items-center justify-center p-6 bg-marca-fondo">
    <div class="w-full max-w-sm">
      <div class="mb-8"><Logo clase="h-9" /></div>
      <h1 class="text-2xl font-black tracking-tight">¿Olvidaste la contraseña?</h1>
      <p class="text-sm text-marca-muted mt-1 mb-6">Poné el email con el que entrás y te mandamos un link para elegir una nueva.</p>
      <div v-if="flash?.success" class="mb-4 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ flash.success }}</div>
      <form v-else @submit.prevent="form.post('/recuperar')" class="space-y-4">
        <div>
          <label class="label">Email</label>
          <input v-model="form.email" type="email" class="input" autocomplete="username" autofocus />
          <p v-if="form.errors.email" class="text-carmin text-xs mt-1.5">{{ form.errors.email }}</p>
        </div>
        <button type="submit" class="btn-primary w-full" :disabled="form.processing">{{ form.processing ? 'Enviando…' : 'Mandarme el link' }}</button>
      </form>
      <p class="text-sm mt-6"><Link href="/login" class="text-violeta font-semibold">← Volver a ingresar</Link></p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
const form = useForm({ email: '' })
const flash = computed(() => usePage().props.flash)
</script>
