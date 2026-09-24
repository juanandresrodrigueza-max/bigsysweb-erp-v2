<template>
  <Head title="Ingresar · BigSys web ERP" />
  <div class="min-h-screen grid lg:grid-cols-[1.15fr_1fr] bg-marca-fondo">
    <!-- Presentación: qué es BigSys web -->
    <section class="relative overflow-hidden text-white px-6 py-8 sm:px-12 sm:py-10 lg:p-14 flex flex-col gap-6 sm:gap-10 lg:justify-between" style="background: linear-gradient(150deg,#e4003f 0%,#a42785 58%,#4f3089 100%)">
      <div class="absolute -right-24 -top-24 w-96 h-96 rounded-full bg-white/10 blur-2xl pointer-events-none"></div>
      <div class="relative">
        <Logo negativo clase="h-16 sm:h-20 lg:h-24" />
        <p class="mt-3 inline-flex items-center gap-2 rounded-full bg-white/15 border border-white/25 px-3 py-1 text-xs sm:text-sm font-semibold tracking-wide">ERP de gestión para PyMEs argentinas</p>
      </div>

      <div class="relative max-w-xl">
        <h1 class="font-marca text-3xl sm:text-4xl lg:text-5xl font-extrabold leading-[1.08] text-balance">Todo tu negocio<br class="hidden sm:block"> en un solo sistema.</h1>
        <p class="sm:hidden mt-3 text-[15px] text-white/90">Facturación con ARCA, stock, cobranzas, caja y contabilidad en un solo lugar.</p>
        <p class="hidden sm:block mt-4 text-base sm:text-lg text-white/90 leading-relaxed">Un <b>ERP</b> es el sistema que ordena toda la empresa en un solo lugar: lo que vendés, lo que comprás, lo que tenés en stock, lo que te deben y lo que debés. Cargás una vez y cada área ve el dato al día.</p>
        <ul class="hidden sm:grid mt-7 sm:grid-cols-2 gap-x-6 gap-y-3 text-[15px]">
          <li v-for="f in funciones" :key="f.t" class="flex gap-3"><span class="mt-0.5 grid place-items-center w-7 h-7 shrink-0 rounded-lg bg-white/15"><Icono :nombre="f.i" clase="w-4 h-4" /></span><span><b class="block leading-tight">{{ f.t }}</b><span class="text-white/75 text-sm">{{ f.d }}</span></span></li>
        </ul>
      </div>

      <p class="hidden lg:block relative text-xs sm:text-sm text-white/75">© {{ new Date().getFullYear() }} BigSys · Sistemas de gestión desde 1999</p>
    </section>

    <!-- Ingreso -->
    <section class="flex items-center justify-center px-6 py-12">
      <div class="w-full max-w-sm">
        <h2 class="text-3xl font-extrabold tracking-tight">Ingresar</h2>
        <p class="text-[15px] text-marca-muted mt-1.5 mb-7">Entrá con el email y la contraseña que te dio tu empresa.</p>
        <div v-if="flash?.success" class="mb-4 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ flash.success }}</div>

        <form @submit.prevent="form.post('/login')" class="space-y-4">
          <div>
            <label class="label">Email</label>
            <input v-model="form.email" type="email" class="input !py-2.5 !text-[15px]" autocomplete="username" autofocus />
          </div>
          <div>
            <label class="label">Contraseña</label>
            <input v-model="form.password" type="password" class="input !py-2.5 !text-[15px]" autocomplete="current-password" />
            <p v-if="form.errors.email" class="text-carmin text-sm mt-1.5">{{ form.errors.email }}</p>
          </div>
          <div class="flex items-center justify-between gap-2"><label class="flex items-center gap-2 text-sm text-marca-muted"><input v-model="form.remember" type="checkbox" class="accent-carmin" /> Recordarme</label><Link href="/recuperar" class="text-sm text-violeta font-semibold">¿Olvidaste la contraseña?</Link></div>
          <button type="submit" class="btn-primary w-full !py-3 !text-[15px]" :disabled="form.processing">{{ form.processing ? 'Ingresando…' : 'Ingresar' }}</button>
        </form>


        <div v-if="demo" class="mt-6 p-4 rounded-xl bg-white border border-marca-borde text-xs text-marca-muted">
          <p class="font-semibold text-marca-texto mb-1">Demo</p>
          <p>demo@bigsys.com.ar / password (dueño). También: admin@, vendedor@, cajero@, contador@, deposito@ con la misma clave.</p>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
import Icono from '@/Components/Icono.vue'
const form = useForm({ email: '', password: '', remember: false })
const page = usePage()
const flash = computed(() => page.props.flash)
const demo = computed(() => page.props.demo)
const funciones = [
  { i: 'receipt', t: 'Factura electrónica', d: 'A, B, C y E con CAE de ARCA' },
  { i: 'users', t: 'Clientes y cobranzas', d: 'Cuenta corriente, recibos y avisos' },
  { i: 'boxes', t: 'Stock y compras', d: 'Depósitos, listas de precios y proveedores' },
  { i: 'wallet', t: 'Caja y bancos', d: 'Cheques, tarjetas y conciliación' },
  { i: 'chart', t: 'Contabilidad e impuestos', d: 'Asientos solos, libro IVA, retenciones' },
  { i: 'store', t: 'Punto de venta', d: 'Mostrador, tienda online y reparto' },
]
</script>
