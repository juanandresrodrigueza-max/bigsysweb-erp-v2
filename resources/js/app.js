import './bootstrap'
import { createApp, h } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import { createPinia } from 'pinia'

createInertiaApp({
  title: (title) => (title ? `${title} · BigSysWeb` : 'BigSysWeb ERP'),
  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
    return pages[`./Pages/${name}.vue`]
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .use(createPinia())
      .mount(el)
  },
  progress: { color: '#e4003f' },
})

// Fase 117.2: si la pestaña quedó abierta mucho tiempo y el token de seguridad caducó (419), en vez de mostrar
// «La página estuvo abierta mucho tiempo» y perder lo escrito, renovamos el token y repetimos el envío una vez.
let ultimoEnvio = null
router.on('before', (e) => {
  const v = e.detail.visit
  if (v.method !== 'get') ultimoEnvio = v
})
router.on('httpException', (e) => { // en Inertia 3 el 4xx/5xx sin X-Inertia llega como httpException
  const r = e.detail.response
  const v = ultimoEnvio
  if (!r || r.status !== 419 || !v || v.headers?.['X-BigSys-Reintento']) return
  e.preventDefault()
  ultimoEnvio = null
  fetch('/csrf-renovar', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .catch(() => null)
    .then(() => {
      router.visit(v.url, {
        method: v.method, data: v.data, replace: v.replace, preserveScroll: v.preserveScroll, preserveState: v.preserveState,
        only: v.only, except: v.except, errorBag: v.errorBag, forceFormData: v.forceFormData,
        headers: { ...(v.headers || {}), 'X-BigSys-Reintento': '1' },
        onStart: v.onStart, onProgress: v.onProgress, onSuccess: v.onSuccess, onError: v.onError, onFinish: v.onFinish,
      })
    })
})
