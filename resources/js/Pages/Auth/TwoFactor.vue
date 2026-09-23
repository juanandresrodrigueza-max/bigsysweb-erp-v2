<template>
  <Head title="Verificación en dos pasos" />
  <div class="min-h-screen flex items-center justify-center p-6 bg-marca-fondo">
    <div class="w-full max-w-sm">
      <Logo clase="h-9 mb-8" />
      <h1 class="text-2xl font-black tracking-tight">Un paso más</h1>
      <p class="text-sm text-marca-muted mt-1 mb-6">Abrí la app de autenticación en tu teléfono y escribí el código de 6 dígitos. Si no la tenés a mano, usá uno de los códigos de recuperación.</p>
      <form @submit.prevent="form.post('/login/verificar')" class="space-y-4">
        <div>
          <label class="label">Código</label>
          <input v-model="form.codigo" class="input text-center text-2xl tracking-[.4em] font-bold tabular-nums" inputmode="numeric" autocomplete="one-time-code" autofocus maxlength="12" />
          <p v-if="form.errors.codigo" class="text-carmin text-xs mt-1.5">{{ form.errors.codigo }}</p>
        </div>
        <button type="submit" class="btn-primary w-full" :disabled="form.processing || form.codigo.length < 6">Verificar y entrar</button>
        <Link href="/login" class="block text-center text-xs text-marca-muted hover:underline">Volver a ingresar</Link>
      </form>
    </div>
  </div>
</template>

<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
const form = useForm({ codigo: '' })
</script>
