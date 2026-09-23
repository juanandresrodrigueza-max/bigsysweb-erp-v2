<template>
  <Head :title="titulo" />
  <div class="min-h-screen flex bg-marca-fondo">

    <!-- Sidebar -->
    <aside :class="abierto ? 'w-64' : 'w-[72px]'" class="hidden md:flex flex-col shrink-0 transition-all duration-200 text-white" style="background:linear-gradient(180deg,#4f3089 0%,#a42785 100%)">
      <div class="h-16 flex items-center px-4 gap-3 border-b border-white/20">
        <Logo v-if="abierto" negativo clase="h-7" />
        <Logo v-else variante="iso" negativo clase="h-8" />
        <button @click="abierto = !abierto" class="ml-auto p-1.5 rounded-lg hover:bg-white/10 text-white/70"><Icono nombre="menu" clase="w-4 h-4" /></button>
      </div>
      <p v-if="abierto" class="px-4 pt-3 text-[11px] font-semibold text-lavanda truncate">{{ empresa?.nombre }}</p>

      <nav class="flex-1 overflow-y-auto py-3" data-tour="menu">
        <template v-for="grupo in nav" :key="grupo.label">
          <p v-if="abierto && grupo.label" class="px-4 pt-4 pb-1 text-[10px] font-bold uppercase tracking-widest text-lavanda">{{ grupo.label }}</p>
          <Link v-for="item in grupo.items" :key="item.key" :href="item.ruta"
                class="relative flex items-center gap-3 mx-2 my-0.5 px-3 py-2 rounded-xl text-sm transition"
                :class="activo(item) ? 'bg-white text-violeta font-semibold shadow-sm' : 'text-white/80 hover:bg-white/15 hover:text-white'"
                :title="item.label">
            <span v-if="activo(item)" class="absolute left-0 top-2 bottom-2 w-1 rounded-r bg-carmin"></span>
            <Icono :nombre="item.icono" clase="w-5 h-5 shrink-0" />
            <span v-if="abierto" class="truncate">{{ item.label }}</span>
            <span v-if="abierto && !item.disponible" class="ml-auto text-[9px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-white/10 text-lavanda">pronto</span>
          </Link>
        </template>
      </nav>

      <div class="p-3 border-t border-white/20">
        <div class="flex items-center gap-3 px-2">
          <span class="w-9 h-9 rounded-full bg-violeta-grad flex items-center justify-center text-sm font-bold shrink-0">{{ iniciales }}</span>
          <div v-if="abierto" class="min-w-0 flex-1">
            <p class="text-sm font-semibold truncate">{{ user?.name }}</p>
            <p class="text-[11px] text-lavanda truncate">{{ user?.rol }}</p>
          </div>
          <Link v-if="abierto" href="/logout" method="post" as="button" class="p-1.5 rounded-lg hover:bg-white/10 text-white/70" title="Salir"><Icono nombre="logout" clase="w-4 h-4" /></Link>
        </div>
      </div>
    </aside>

    <!-- Contenido -->
    <div class="flex-1 flex flex-col min-w-0 pb-16 md:pb-0">
      <header class="h-16 bg-white border-b border-marca-borde flex items-center px-4 md:px-6 gap-3 sticky top-0 z-30">
        <button @click="movil = true" class="md:hidden p-2 rounded-lg hover:bg-marca-fondo"><Icono nombre="menu" /></button>

        <!-- Selector de sucursal -->
        <div class="relative" ref="selRef" data-tour="sucursal">
          <button @click="selAbierto = !selAbierto" class="flex items-center gap-2 px-3 py-1.5 rounded-full border border-marca-borde hover:border-carmin/50 text-sm">
            <Icono nombre="pin" clase="w-4 h-4 text-carmin" />
            <span class="font-semibold max-w-[160px] truncate">{{ sucursales?.actual?.nombre ?? 'Sin sucursal' }}</span><span v-if="sucursales?.consolidado" class="badge bg-violeta-light text-violeta !py-0 text-[10px]">consolidado</span>
            <Icono v-if="(sucursales?.lista?.length ?? 0) > 1" nombre="chevron" clase="w-4 h-4 text-marca-muted" />
          </button>
          <div v-if="selAbierto && sucursales?.lista?.length > 1" class="absolute left-0 mt-2 w-64 bg-white rounded-2xl shadow-2xl border border-marca-borde overflow-hidden z-40">
            <p class="px-4 py-2 text-[10px] font-bold uppercase tracking-widest text-marca-muted border-b border-marca-borde">Cambiar sucursal</p>
            <Link v-for="s in sucursales.lista" :key="s.id" :href="`/sucursal/${s.id}`" method="post" as="button" preserve-scroll
                  class="w-full text-left flex items-center gap-3 px-4 py-2.5 hover:bg-marca-fondo text-sm" @click="selAbierto = false">
              <span class="w-2 h-2 rounded-full" :class="s.id === sucursales.actual?.id ? 'bg-carmin' : 'bg-marca-borde'"></span>
              <span class="flex-1"><span class="font-semibold">{{ s.nombre }}</span><span v-if="s.ciudad" class="text-marca-muted"> · {{ s.ciudad }}</span></span>
              <Icono v-if="s.id === sucursales.actual?.id" nombre="check" clase="w-4 h-4 text-carmin" />
            </Link>
            <Link v-if="sucursales.puede_consolidar" href="/sucursal/consolidado" method="post" as="button" preserve-scroll class="w-full text-left flex items-center gap-3 px-4 py-2.5 border-t border-marca-borde hover:bg-marca-fondo text-sm" @click="selAbierto = false" title="El tablero y las estadísticas suman todas las sucursales (casa central)">
              <span class="w-2 h-2 rounded-full" :class="sucursales.consolidado ? 'bg-violeta' : 'bg-marca-borde'"></span>
              <span class="flex-1 font-semibold">Ver consolidado de todas</span>
              <Icono v-if="sucursales.consolidado" nombre="check" clase="w-4 h-4 text-violeta" />
            </Link>
          </div>
        </div>

        <!-- Contador con varias empresas -->
        <div v-if="empresas" class="relative" ref="empRef">
          <button @click="empAbierto = !empAbierto" class="flex items-center gap-2 px-3 py-1.5 rounded-full border border-violeta/40 bg-lavanda-light text-violeta hover:border-violeta text-sm" title="Cambiar de empresa">
            <Icono nombre="building" clase="w-4 h-4" /><span class="font-semibold max-w-[160px] truncate hidden sm:inline">{{ empresa?.nombre }}</span><Icono nombre="chevron" clase="w-4 h-4" />
          </button>
          <div v-if="empAbierto" class="absolute left-0 mt-2 w-72 bg-white rounded-2xl shadow-2xl border border-marca-borde overflow-hidden z-40">
            <p class="px-4 py-2 text-[10px] font-bold uppercase tracking-widest text-marca-muted border-b border-marca-borde">Cambiar empresa</p>
            <Link v-for="e in empresas" :key="e.id" :href="`/empresa/${e.id}`" method="post" as="button" class="w-full text-left flex items-center gap-3 px-4 py-2.5 hover:bg-marca-fondo text-sm" @click="empAbierto = false">
              <span class="w-2 h-2 rounded-full" :class="e.id === empresa?.id ? 'bg-carmin' : 'bg-marca-borde'"></span>
              <span class="flex-1 min-w-0"><span class="font-semibold block truncate">{{ e.nombre }}</span><span v-if="e.cuit" class="text-marca-muted text-xs">{{ e.cuit }}</span></span>
              <Icono v-if="e.id === empresa?.id" nombre="check" clase="w-4 h-4 text-carmin" />
            </Link>
            <Link href="/contador/empresas" class="block px-4 py-2 text-xs font-semibold text-violeta border-t border-marca-borde hover:bg-marca-fondo" @click="empAbierto = false">Ver todas mis empresas →</Link>
          </div>
        </div>

        <div class="flex-1"></div>

        <button type="button" @click="paleta?.abrir()" data-tour="buscar" class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full border border-marca-borde text-sm text-marca-muted hover:border-carmin/50" title="Buscar cliente, artículo, comprobante o pantalla (Ctrl+K)">
          <Icono nombre="search" clase="w-4 h-4" /><span>Buscar…</span><kbd class="text-[10px] px-1.5 py-0.5 rounded border border-marca-borde">Ctrl K</kbd>
        </button>
        <button type="button" @click="paleta?.abrir()" class="sm:hidden p-2 rounded-lg hover:bg-marca-fondo" title="Buscar"><Icono nombre="search" clase="w-5 h-5" /></button>

        <Link v-if="empresa?.plan" href="/suscripcion" class="hidden sm:inline badge bg-lavanda-light text-violeta hover:bg-lavanda">Plan {{ empresa.plan }}</Link>
        <button type="button" @click="ayuda?.abrir()" data-tour="ayuda" class="w-9 h-9 rounded-full border border-marca-borde grid place-items-center text-marca-muted hover:border-carmin/50 hover:text-carmin font-black" title="Ayuda de esta pantalla" aria-label="Ayuda">?</button>
        <span data-tour="alertas"><CampanaAlertas :alertas="alertas" /></span>
      </header>
      <Paleta ref="paleta" :nav="nav" />
      <AyudaPanel ref="ayuda" @tour="p => tourRef?.iniciar(p)" />
      <Tour ref="tourRef" :pasos-iniciales="tourInicial" />
      <!-- Barra inferior en el celular: lo que más se usa, a un toque -->
      <nav class="md:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-marca-borde grid grid-cols-5 text-[10px] font-semibold text-marca-muted" style="padding-bottom: env(safe-area-inset-bottom, 0px)">
        <Link href="/dueno" class="flex flex-col items-center py-2 gap-0.5" :class="page.url.startsWith('/dueno') ? 'text-carmin' : ''"><Icono nombre="home" clase="w-5 h-5" />Mi negocio</Link>
        <Link href="/comprobantes" class="flex flex-col items-center py-2 gap-0.5" :class="page.url.startsWith('/comprobantes') ? 'text-carmin' : ''"><Icono nombre="receipt" clase="w-5 h-5" />Ventas</Link>
        <button type="button" @click="paleta?.abrir()" class="flex flex-col items-center py-2 gap-0.5"><span class="-mt-5 w-11 h-11 rounded-full bg-marca-grad text-white grid place-items-center shadow-pop"><Icono nombre="search" clase="w-5 h-5" /></span>Buscar</button>
        <Link href="/clientes/cobranzas" class="flex flex-col items-center py-2 gap-0.5" :class="page.url.startsWith('/clientes') ? 'text-carmin' : ''"><Icono nombre="users" clase="w-5 h-5" />Cobrar</Link>
        <button type="button" @click="movil = true" class="flex flex-col items-center py-2 gap-0.5"><Icono nombre="menu" clase="w-5 h-5" />Menú</button>
      </nav>

      <!-- Superadmin dando soporte dentro de una empresa -->
      <div v-if="impersonando" class="px-4 md:px-6 py-2 bg-violeta text-white text-sm flex items-center gap-3">
        <Icono nombre="shield" clase="w-4 h-4" /><span>Estás dentro de <b>{{ impersonando.empresa }}</b> como superadmin. Todo lo que hagas queda auditado.</span>
        <Link href="/admin/volver" method="post" as="button" class="ml-auto px-3 py-1 rounded-full bg-white/15 hover:bg-white/25 text-xs font-semibold">Volver al panel</Link>
      </div>
      <div v-if="mensajeGlobal" class="px-4 md:px-6 py-2 bg-lavanda-light text-violeta text-sm flex items-center gap-2"><Icono nombre="info" clase="w-4 h-4 shrink-0" /><span>{{ mensajeGlobal }}</span></div>
      <div v-if="onboarding && !$page.url.startsWith('/primeros-pasos')" class="px-4 md:px-6 py-2 bg-white border-b border-marca-borde text-sm flex items-center gap-3">
        <span class="text-marca-muted">Primeros pasos: <b class="text-marca-texto">{{ onboarding.hechos }} de {{ onboarding.total }}</b> listos</span>
        <div class="flex-1 max-w-48 h-1.5 rounded-full bg-marca-fondo"><div class="h-1.5 rounded-full bg-marca-grad" :style="{ width: (onboarding.hechos / onboarding.total * 100) + '%' }"></div></div>
        <Link href="/primeros-pasos" class="text-xs font-bold text-carmin underline whitespace-nowrap">Seguir configurando</Link>
      </div>
      <!-- Aviso de suscripción: solo a quien puede renovarla -->
      <div v-if="suscripcion?.aviso && suscripcion.puede" class="px-4 md:px-6 py-2 text-sm flex items-center gap-2" :class="{ info: 'bg-lavanda-light text-violeta', warn: 'bg-amber-50 text-amber-800', error: 'bg-carmin text-white' }[suscripcion.aviso.nivel]">
        <Icono :nombre="suscripcion.aviso.nivel === 'info' ? 'info' : 'alert'" clase="w-4 h-4 shrink-0" /><span>{{ suscripcion.aviso.texto }}</span>
        <Link href="/suscripcion" class="ml-auto text-xs font-bold underline whitespace-nowrap">{{ suscripcion.estado === 'trial' ? 'Contratar plan' : 'Renovar' }}</Link>
      </div>

      <div v-if="flash?.success" class="mx-4 md:mx-6 mt-4 px-4 py-2.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ flash.success }}</div>
      <div v-if="flash?.error" class="mx-4 md:mx-6 mt-4 px-4 py-2.5 rounded-xl bg-carmin-light border border-carmin/30 text-carmin-dark text-sm">{{ flash.error }}</div>

      <main class="flex-1 p-4 md:p-6 pb-24"><slot /></main>
    </div>

    <!-- Sidebar móvil -->
    <div v-if="movil" class="fixed inset-0 z-40 md:hidden">
      <div class="absolute inset-0 bg-black/50" @click="movil = false"></div>
      <div class="absolute left-0 top-0 bottom-0 w-72 text-white p-4 overflow-y-auto" style="background:linear-gradient(180deg,#4f3089 0%,#a42785 100%)">
        <div class="flex items-center justify-between mb-4">
          <Logo negativo clase="h-7" />
          <button @click="movil = false"><Icono nombre="x" /></button>
        </div>
        <template v-for="grupo in nav" :key="grupo.label">
          <p v-if="grupo.label" class="pt-3 pb-1 text-[10px] font-bold uppercase tracking-widest text-lavanda">{{ grupo.label }}</p>
          <Link v-for="item in grupo.items" :key="item.key" :href="item.ruta" @click="movil = false" class="flex items-center gap-3 px-2 py-2 rounded-lg text-sm" :class="activo(item) ? 'bg-white/10 font-semibold' : 'text-white/80'">
            <Icono :nombre="item.icono" clase="w-5 h-5" /> {{ item.label }}
          </Link>
        </template>
        <Link href="/ayuda" @click="movil = false" class="mt-6 flex items-center gap-2 text-sm text-white/70 hover:text-white"><Icono nombre="info" clase="w-4 h-4" /> Ayuda</Link>
        <Link href="/soporte" @click="movil = false" class="mt-2 flex items-center gap-2 text-sm text-white/70 hover:text-white"><Icono nombre="info" clase="w-4 h-4" /> Soporte</Link>
        <Link href="/logout" method="post" as="button" class="mt-2 flex items-center gap-2 text-sm text-white/70"><Icono nombre="logout" clase="w-4 h-4" /> Salir</Link>
      </div>
    </div>

    <span data-tour="asistente" class="contents"><AgenteIA /></span>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'
import Logo from '@/Components/Logo.vue'
import CampanaAlertas from '@/Components/CampanaAlertas.vue'
import AgenteIA from '@/Components/AgenteIA.vue'
import Paleta from '@/Components/Paleta.vue'
import AyudaPanel from '@/Components/AyudaPanel.vue'
import Tour from '@/Components/Tour.vue'

defineProps({ titulo: { type: String, default: '' } })

const page = usePage()
const user = computed(() => page.props.auth?.user)
const empresa = computed(() => page.props.empresa)
const sucursales = computed(() => page.props.sucursales)
const nav = computed(() => page.props.nav ?? [])
const alertas = computed(() => page.props.alertas)
const flash = computed(() => page.props.flash)
const suscripcion = computed(() => page.props.suscripcion)
const impersonando = computed(() => page.props.impersonando)
const mensajeGlobal = computed(() => page.props.mensajeGlobal)
const onboarding = computed(() => page.props.onboarding)
const empresas = computed(() => page.props.empresas)
// El tour se muestra una vez, en Inicio, la primera vez que entra cada usuario.
const tourInicial = page.props.tour && ['/dashboard', '/dueno'].includes(page.url.split('?')[0]) ? page.props.tour : null

const abierto = ref(true)
const movil = ref(false)
const selAbierto = ref(false)
const selRef = ref(null)
const paleta = ref(null), ayuda = ref(null), tourRef = ref(null), empAbierto = ref(false), empRef = ref(null)

const iniciales = computed(() => (user.value?.name ?? '?').split(' ').slice(0, 2).map(p => p[0]).join('').toUpperCase())

function activo(item) {
  const url = page.url.split('?')[0]
  return url === item.ruta || (item.ruta !== '/dashboard' && url.startsWith(item.ruta + '/')) || (item.ruta === '/configuracion' && url.startsWith('/configuracion'))
}
function clickAfuera(e) { if (selRef.value && !selRef.value.contains(e.target)) selAbierto.value = false; if (empRef.value && !empRef.value.contains(e.target)) empAbierto.value = false }
onMounted(() => {
  document.addEventListener('click', clickAfuera)
  try { abierto.value = localStorage.getItem('bs.sidebar') !== '0' } catch {}
})
onBeforeUnmount(() => document.removeEventListener('click', clickAfuera))
watch(abierto, v => { try { localStorage.setItem('bs.sidebar', v ? '1' : '0') } catch {} })
</script>
