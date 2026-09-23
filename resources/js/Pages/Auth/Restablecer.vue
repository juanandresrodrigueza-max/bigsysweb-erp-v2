<template>
  <Head title="Nueva contraseña" />
  <div class="min-h-screen flex items-center justify-center p-6 bg-marca-fondo">
    <div class="w-full max-w-sm">
      <div class="mb-8"><Logo clase="h-9" /></div>
      <h1 class="text-2xl font-black tracking-tight">Elegí una contraseña nueva</h1>
      <p class="text-sm text-marca-muted mt-1 mb-6">Mínimo 8 caracteres, con letras y números.</p>
      <form @submit.prevent="form.post('/restablecer')" class="space-y-4">
        <div>
          <label class="label">Email</label>
          <input v-model="form.email" type="email" class="input" autocomplete="username" />
          <p v-if="form.errors.email" class="text-carmin text-xs mt-1.5">{{ form.errors.email }}</p>
        </div>
        <div>
          <label class="label">Contraseña nueva</label>
          <input v-model="form.password" type="password" class="input" autocomplete="new-password" autofocus />
          <p v-if="form.errors.password" class="text-carmin text-xs mt-1.5">{{ form.errors.password }}</p>
        </div>
        <div>
          <label class="label">Repetila</label>
          <input v-model="form.password_confirmation" type="password" class="input" autocomplete="new-password" />
        </div>
        <button type="submit" class="btn-primary w-full" :disabled="form.processing">{{ form.processing ? 'Guardando…' : 'Guardar y entrar' }}</button>
      </form>
      <p class="text-sm mt-6"><Link href="/login" class="text-violeta font-semibold">← Volver a ingresar</Link></p>
    </div>
  </div>
</template>

<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
const props = defineProps({ token: String, email: String })
const form = useForm({ token: props.token, email: props.email ?? '', password: '', password_confirmation: '' })
</script>
