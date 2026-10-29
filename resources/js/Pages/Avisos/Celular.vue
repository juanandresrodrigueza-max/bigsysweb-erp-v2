<template>
  <AppLayout titulo="Avisos al celular">
    <h1 class="page-title">Avisos al celular</h1>
    <p class="page-subtitle mb-5">Te avisamos en el celular o la compu aunque no tengas el sistema abierto: alertas críticas, el parte de la mañana y las novedades.</p>

    <div class="grid lg:grid-cols-2 gap-4">
      <div class="card" data-e2e="push">
        <p class="font-bold">Este dispositivo</p>
        <p v-if="!soportado" class="text-sm text-marca-muted mt-1">Este navegador no recibe avisos. En iPhone, primero agregá BigSysWeb a la pantalla de inicio (Compartir → Agregar a inicio) y abrilo desde ahí.</p>
        <template v-else>
          <p class="text-sm mt-1" :class="activo ? 'text-emerald-700' : 'text-marca-muted'">{{ activo ? '✓ Recibe avisos.' : permiso === 'denied' ? 'Bloqueaste los avisos para este sitio: habilitalos desde el candadito de la barra de direcciones.' : 'Todavía no recibe avisos.' }}</p>
          <div class="flex flex-wrap gap-2 mt-3">
            <button v-if="!activo" class="btn-primary" :disabled="trabajando || permiso === 'denied'" @click="activar" data-e2e="push-activar">{{ trabajando ? 'Activando…' : 'Activar en este dispositivo' }}</button>
            <button v-else class="btn-secondary" :disabled="trabajando" @click="desactivar">Desactivar acá</button>
            <button class="btn-ghost" :disabled="!dispositivos.length" @click="router.post('/avisos-celular/probar', {}, { preserveScroll: true })">Mandar uno de prueba</button>
          </div>
        </template>
        <p v-if="error" class="text-sm text-carmin mt-2">{{ error }}</p>
        <div v-if="dispositivos.length" class="mt-4">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-1">Tus dispositivos</p>
          <div v-for="d in dispositivos" :key="d.id" class="flex items-center justify-between gap-2 py-1.5 border-t border-marca-borde/60 first:border-0 text-sm">
            <span class="min-w-0 truncate">{{ d.dispositivo }} <span class="text-xs text-marca-muted">· desde {{ d.desde }}{{ d.ultimo ? ' · último aviso ' + d.ultimo : '' }}</span></span>
            <button class="text-xs text-carmin shrink-0" @click="router.post('/avisos-celular/desuscribir', { id: d.id }, { preserveScroll: true })">Quitar</button>
          </div>
        </div>
      </div>

      <div v-if="manana" class="card" data-e2e="manana">
        <p class="font-bold">Parte de la mañana</p>
        <p class="text-sm text-marca-muted mt-1">Cómo cerró ayer y qué vence hoy, todos los días a la hora que elijas.</p>
        <form class="mt-3 space-y-2" @submit.prevent="form.post('/avisos-celular/manana', { preserveScroll: true })">
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.activo" /> Mandarme el parte todos los días</label>
          <div class="flex flex-wrap items-center gap-3 text-sm">
            <label class="flex items-center gap-2">a las <input type="time" v-model="form.hora" class="input !w-28" data-e2e="manana-hora" /></label>
            <label class="flex items-center gap-2"><input type="checkbox" v-model="form.push" /> al celular</label>
            <label class="flex items-center gap-2" :class="!whatsapp && 'opacity-50'" :title="whatsapp ? '' : 'Cargá tu WhatsApp en Configuración → Empresa → Avisos'"><input type="checkbox" v-model="form.whatsapp" :disabled="!whatsapp" /> por WhatsApp</label>
          </div>
          <button class="btn-primary" :disabled="form.processing" data-e2e="manana-guardar">Guardar</button>
        </form>
        <details class="mt-3"><summary class="text-xs text-violeta cursor-pointer">Ver cómo queda el de hoy</summary><pre class="text-xs whitespace-pre-wrap bg-marca-fondo rounded-xl p-3 mt-2" data-e2e="parte">{{ parte }}</pre></details>
      </div>
    </div>
    <p class="text-xs text-marca-muted mt-4">También podés pedírselo a Bigcito: “mandame el parte a las 7 y media”, “¿qué hay de nuevo en BigSys?”, “¿qué módulos no tengo?”.</p>
  </AppLayout>
</template>

<script setup>
// Fase 40.5: activar el web push en este dispositivo y el parte de la mañana.
import { ref, onMounted } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
const props = defineProps({ clave: String, dispositivos: Array, manana: Object, parte: String, whatsapp: String })
const soportado = typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
const activo = ref(false), trabajando = ref(false), error = ref(''), permiso = ref(soportado ? Notification.permission : 'default')
const form = useForm({ activo: props.manana?.activo ?? false, hora: props.manana?.hora ?? '08:00', push: props.manana?.push ?? true, whatsapp: props.manana?.whatsapp ?? false })
const b64 = s => { const p = '='.repeat((4 - s.length % 4) % 4); const r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from([...r].map(c => c.charCodeAt(0))) }
const registro = () => navigator.serviceWorker.getRegistration().then(r => r || navigator.serviceWorker.register('/sw.js'))
onMounted(async () => { if (!soportado) return; try { const r = await registro(); activo.value = !!(await r.pushManager.getSubscription()) } catch {} })
function nombreDispositivo() { const u = navigator.userAgent; const so = /Android/.test(u) ? 'Android' : /iPhone|iPad/.test(u) ? 'iPhone' : /Windows/.test(u) ? 'Windows' : /Mac/.test(u) ? 'Mac' : 'Linux'; const nav = /Edg\//.test(u) ? 'Edge' : /Chrome\//.test(u) ? 'Chrome' : /Firefox\//.test(u) ? 'Firefox' : /Safari\//.test(u) ? 'Safari' : 'Navegador'; return `${nav} en ${so}` }
async function activar() {
  trabajando.value = true; error.value = ''
  try {
    permiso.value = await Notification.requestPermission()
    if (permiso.value !== 'granted') { error.value = 'Sin permiso no podemos mandarte avisos.'; return }
    const r = await registro(); await navigator.serviceWorker.ready
    const s = await r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(props.clave) })
    await window.axios.post('/avisos-celular/suscribir', { ...s.toJSON(), dispositivo: nombreDispositivo() })
    activo.value = true; router.reload({ only: ['dispositivos'] })
  } catch (e) { error.value = 'No se pudo activar: ' + (e.message || e) } finally { trabajando.value = false }
}
async function desactivar() {
  trabajando.value = true
  try { const s = await (await registro()).pushManager.getSubscription(); if (s) { await window.axios.post('/avisos-celular/desuscribir', { endpoint: s.endpoint }); await s.unsubscribe() } activo.value = false; router.reload({ only: ['dispositivos'] }) } finally { trabajando.value = false }
}
</script>
