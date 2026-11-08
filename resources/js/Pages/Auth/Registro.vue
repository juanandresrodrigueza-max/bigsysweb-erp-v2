<template>
  <Head title="Registrate gratis · BigSys web ERP" />
  <div class="min-h-screen grid lg:grid-cols-[1fr_1fr] bg-marca-fondo">
    <section class="relative overflow-hidden text-white px-6 py-8 sm:px-12 lg:p-14 flex flex-col gap-6 lg:justify-center" style="background: linear-gradient(150deg,#e4003f 0%,#a42785 58%,#4f3089 100%)">
      <div class="flex items-center justify-between gap-4"><Logo negativo clase="h-14 sm:h-16" /><img src="/img/bigcito.webp" alt="Bigcito, tu asistente" class="hidden sm:block w-28 lg:w-44 h-auto drop-shadow-2xl -my-4" /></div>
      <div class="max-w-lg">
        <h1 class="font-marca text-3xl sm:text-4xl font-extrabold leading-tight text-balance">Probalo {{ dias }} días con todo activado.</h1>
        <p class="mt-3 text-white/90">Sin tarjeta. Al terminar la prueba seguís gratis (1 usuario, 10 facturas por mes) o elegís un plan y pagás con Mercado Pago.</p>
        <ul class="mt-6 space-y-2 text-[15px]">
          <li>✓ Bigcito te pregunta cómo trabajás y te deja el sistema a medida</li><li>✓ Factura electrónica con ARCA</li><li>✓ Stock, compras, clientes y caja</li><li>✓ Contabilidad e IVA automáticos</li><li>✓ Bigcito: manejalo hablando</li>
        </ul>
      </div>
    </section>
    <section class="flex items-center justify-center px-6 py-10">
      <form @submit.prevent="enviar" class="w-full max-w-md space-y-3" data-e2e="registro">
        <h2 class="text-3xl font-extrabold tracking-tight">Registrate gratis</h2>
        <p class="text-sm text-marca-muted">En un minuto tenés tu empresa lista.</p>
        <div><label class="label">Nombre de tu empresa</label><input v-model="f.empresa" class="input" autofocus /><p v-if="f.errors.empresa" class="text-xs text-carmin">{{ f.errors.empresa }}</p></div>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label">Tu nombre</label><input v-model="f.nombre" class="input" autocomplete="name" /><p v-if="f.errors.nombre" class="text-xs text-carmin">{{ f.errors.nombre }}</p></div>
          <div><label class="label">Teléfono (opcional)</label><input v-model="f.telefono" class="input" autocomplete="tel" /></div>
        </div>
        <div><label class="label">Email</label><input v-model="f.email" type="email" class="input" autocomplete="username" /><p v-if="f.errors.email" class="text-xs text-carmin">{{ f.errors.email }}</p></div>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label">Contraseña</label><input v-model="f.password" type="password" class="input" autocomplete="new-password" /><p v-if="f.errors.password" class="text-xs text-carmin">{{ f.errors.password }}</p></div>
          <div><label class="label">Repetila</label><input v-model="f.password_confirmation" type="password" class="input" autocomplete="new-password" /></div>
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label">Rubro</label><select v-model="f.rubro" class="input"><option v-for="(n, k) in rubros" :key="k" :value="k">{{ n }}</option></select></div>
          <div><label class="label">CUIT (opcional)</label><input v-model="f.cuit" class="input" placeholder="30-12345678-9" /><p v-if="f.errors.cuit" class="text-xs text-carmin">{{ f.errors.cuit }}</p></div>
        </div>
        <input v-model="f.sitio_web" type="text" name="sitio_web" tabindex="-1" autocomplete="off" class="absolute -left-[9999px]" aria-hidden="true" />
        <label class="flex items-start gap-2 text-sm"><input v-model="f.acepto" type="checkbox" class="accent-carmin mt-1" /> <span>Acepto los términos de uso y la política de privacidad.</span></label>
        <p v-if="f.errors.acepto" class="text-xs text-carmin">{{ f.errors.acepto }}</p>
 <div v-if="captcha" ref="caja" class="min-h-[65px]"></div><p v-if="f.errors.captcha" class="text-xs text-carmin">{{ f.errors.captcha }}</p>
        <p v-if="error" class="text-sm text-carmin">{{ error }}</p>
        <button class="btn-primary w-full !py-3" :disabled="f.processing">{{ f.processing ? 'Creando tu empresa…' : 'Crear mi cuenta gratis' }}</button>
        <p class="text-center text-sm text-marca-muted">¿Ya tenés cuenta? <Link href="/login" class="text-violeta font-semibold">Ingresá</Link></p>
      </form>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'
const props = defineProps({ rubros: Object, marca: String, dias: Number, captcha: { type: String, default: null } })
const f = useForm({ empresa: '', nombre: '', telefono: '', email: '', password: '', password_confirmation: '', rubro: 'otro', cuit: '', acepto: false, sitio_web: '', marca: props.marca, 'cf-turnstile-response': '' })
const error = ref(''), caja = ref(null)
// Captcha opcional: solo si el servidor tiene la clave de Turnstile.
onMounted(() => {
  if (!props.captcha) return
  const s = document.createElement('script'); s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js'; s.async = true
  s.onload = () => window.turnstile?.render(caja.value, { sitekey: props.captcha, callback: t => (f['cf-turnstile-response'] = t) })
  document.head.appendChild(s)
})
function enviar() { error.value = ''; f.post('/registro', { onError: e => { if (!Object.keys(e).length) error.value = 'No pudimos crear la cuenta. Probá de nuevo.' } }) }
</script>
