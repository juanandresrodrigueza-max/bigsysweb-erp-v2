<template>
  <Head title="Ingresar" />
  <div class="min-h-screen grid lg:grid-cols-2">
    <div class="hidden lg:flex flex-col justify-between p-12 text-white" style="background: linear-gradient(150deg,#e4003f 0%,#a42785 100%)">
      <Logo negativo clase="h-10" />
      <div>
        <h2 class="text-5xl font-light leading-[1.05] max-w-md">«Hacemos <span class="font-extrabold italic">crecer</span><br>tu negocio»</h2>
        <p class="mt-5 text-white/85 max-w-md">Facturación AFIP, clientes, proveedores, stock, fondos y contabilidad. Multi-sucursal, con permisos por usuario y un asistente que conoce tus números.</p>
      </div>
      <p class="text-xs text-white/70">© {{ new Date().getFullYear() }} BigSys · Sistemas de gestión desde 1999</p>
    </div>

    <div class="flex items-center justify-center p-6 bg-marca-fondo">
      <div class="w-full max-w-sm">
        <div class="lg:hidden mb-8"><Logo clase="h-9" /></div>
        <h1 class="text-2xl font-black tracking-tight">Ingresar</h1>
        <p class="text-sm text-marca-muted mt-1 mb-6">Usá el email y la contraseña de tu empresa.</p>
        <div v-if="flash?.success" class="mb-4 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ flash.success }}</div>

        <form @submit.prevent="form.post('/login')" class="space-y-4">
          <div>
            <label class="label">Email</label>
            <input v-model="form.email" type="email" class="input" autocomplete="username" autofocus />
          </div>
          <div>
            <label class="label">Contraseña</label>
            <input v-model="form.password" type="password" class="input" autocomplete="current-password" />
            <p v-if="form.errors.email" class="text-carmin text-xs mt-1.5">{{ form.errors.email }}</p>
          </div>
          <div class="flex items-center justify-between gap-2"><label class="flex items-center gap-2 text-sm text-marca-muted"><input v-model="form.remember" type="checkbox" class="accent-carmin" /> Recordarme</label><Link href="/recuperar" class="text-sm text-violeta font-semibold">¿Olvidaste la contraseña?</Link></div>
          <button type="submit" class="btn-primary w-full" :disabled="form.processing">{{ form.processing ? 'Ingresando…' : 'Ingresar' }}</button>
        </form>

        <div class="mt-8 p-4 rounded-xl bg-white border border-marca-borde text-xs text-marca-muted">
          <p class="font-semibold text-marca-texto mb-1">Demo</p>
          <p>demo@bigsys.com.ar / password (dueño). También: admin@, vendedor@, cajero@, contador@, deposito@ con la misma clave.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
const form = useForm({ email: '', password: '', remember: false })
const flash = computed(() => usePage().props.flash)
</script>
