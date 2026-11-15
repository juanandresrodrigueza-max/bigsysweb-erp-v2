<template>
  <Head title="Ingresar · BigSys web ERP" />
  <!-- La portada del sitio: ciruela profunda con brillos magenta, títulos livianos, la tarjeta blanca flotando. -->
  <div class="min-h-screen bg-ciruela-grad text-white relative overflow-hidden">
    <div class="pointer-events-none absolute -top-40 -left-40 w-[560px] h-[560px] rounded-full bg-rosa/30 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-48 right-[-10%] w-[640px] h-[640px] rounded-full bg-violeta/40 blur-3xl"></div>

    <div class="relative mx-auto max-w-6xl px-6 py-8 lg:py-12 min-h-screen flex flex-col">
      <header class="flex items-center justify-between gap-4">
        <Logo negativo clase="h-9 sm:h-10" />
        <Link href="/conocer" class="hidden sm:inline-flex items-center gap-2 rounded-full border border-white/25 bg-white/10 px-4 py-2 text-xs font-semibold tracking-wide hover:bg-white/15 transition">Conocé BigSys Web ↗</Link>
      </header>

      <div class="flex-1 grid lg:grid-cols-[1.1fr_minmax(360px,440px)] gap-10 lg:gap-16 items-center py-10 lg:py-6">
        <!-- Presentación -->
        <section class="max-w-xl">
          <p class="kicker !text-rosa-light/90">{{ new Date().getFullYear() }} · ERP 100 % web</p>
          <h1 class="font-display mt-3 text-4xl sm:text-5xl lg:text-[3.6rem] leading-[1.02] font-light tracking-tight text-balance">Dirigí tu negocio <span class="font-normal">como en un videojuego.</span></h1>
          <p class="mt-5 text-lg text-white/85 leading-relaxed hidden sm:block">Vendé, facturá con ARCA, cobrá, controlá el stock y cerrá la caja desde un solo lugar. Y Bigcito, tu asistente, te acompaña en cada pantalla.</p>
          <p class="mt-4 text-[15px] text-white/85 sm:hidden">Facturación con ARCA, stock, cobranzas, caja y contabilidad en un solo lugar.</p>
          <ul class="hidden sm:grid mt-8 sm:grid-cols-2 gap-x-6 gap-y-4 text-[15px]">
            <li v-for="f in funciones" :key="f.t" class="flex gap-3">
              <span class="mt-0.5 grid place-items-center w-9 h-9 shrink-0 rounded-xl bg-white/10 border border-white/15"><Icono :nombre="f.i" clase="w-4 h-4" /></span>
              <span><b class="block leading-tight font-semibold">{{ f.t }}</b><span class="text-white/65 text-sm">{{ f.d }}</span></span>
            </li>
          </ul>
          <div class="hidden sm:flex items-center gap-4 mt-9">
            <img src="/img/bigcito-cara.webp" alt="Bigcito" class="w-14 h-14 rounded-full ring-2 ring-white/20 shadow-pop object-cover" loading="lazy" />
            <p class="text-sm text-white/75 max-w-xs"><b class="text-white">Bigcito</b> entiende voz, fotos y audios: «cobrale 10.000 a Pérez», «¿cuánto vendí hoy?».</p>
          </div>
        </section>

        <!-- Ingreso -->
        <section class="w-full">
          <div class="rounded-3xl bg-white text-marca-texto shadow-pop p-7 sm:p-8">
            <h2 class="font-display text-3xl font-medium tracking-tight text-ciruela-900">Ingresar</h2>
            <p class="text-[15px] text-marca-muted mt-1.5 mb-6">Con el email y la contraseña que te dio tu empresa.</p>
            <div v-if="flash?.success" class="mb-4 px-4 py-3 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ flash.success }}</div>

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
            <!-- Fase 96: entrar con Google (si BigSys lo configuró) -->
            <div v-if="google" class="mt-4">
              <div class="flex items-center gap-3 text-xs text-marca-muted mb-3"><span class="h-px flex-1 bg-marca-borde"></span>o<span class="h-px flex-1 bg-marca-borde"></span></div>
              <a href="/login/google" class="btn-secondary w-full !py-3 !text-[15px] flex items-center justify-center gap-2" data-e2e="google"><svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.3l7.8 6C12.3 13.2 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4 7.1-10 7.1-17.5z"/><path fill="#FBBC05" d="M10.4 28.7A14.5 14.5 0 0 1 9.5 24c0-1.6.3-3.2.8-4.7l-7.8-6A24 24 0 0 0 0 24c0 3.9.9 7.5 2.6 10.7l7.8-6z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.9 2.3-8.4 2.3-6.3 0-11.7-3.7-13.6-9l-7.8 6C6.5 42.6 14.6 48 24 48z"/></svg> Entrar con Google</a>
            </div>
            <p class="mt-6 text-center text-sm text-marca-muted">¿Todavía no tenés cuenta? <Link href="/registro" class="text-carmin font-bold" data-e2e="ir-registro">Registrate gratis</Link></p>
          </div>
          <div v-if="demo" class="mt-4 glass p-4 text-xs text-white/80">
            <p class="font-semibold text-white mb-1">Demo</p>
            <p>demo@bigsys.com.ar / password (dueño). También: admin@, vendedor@, cajero@, contador@, deposito@ con la misma clave.</p>
          </div>
          <p class="mt-5 text-center text-xs text-white/55">15 días con todo activado · Sin tarjeta · Después podés seguir gratis</p>
        </section>
      </div>

      <footer class="text-xs text-white/50 flex flex-wrap items-center justify-between gap-2">
        <span>© {{ new Date().getFullYear() }} BigSys · Sistemas de gestión desde 1999</span>
        <span class="flex gap-3"><a href="/privacidad" class="hover:text-white">Privacidad</a><a href="/terminos" class="hover:text-white">Términos</a><span>Mendoza, Argentina</span></span>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
import Icono from '@/Components/Icono.vue'
const form = useForm({ email: '', password: '', remember: false })
const props = defineProps({ google: Boolean, demo: Boolean })
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
