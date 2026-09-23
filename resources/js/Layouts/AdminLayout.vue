<template>
  <Head :title="titulo ? `${titulo} · Superadmin` : 'Superadmin'" />
  <div class="min-h-screen flex bg-marca-fondo">
    <aside class="hidden md:flex w-64 flex-col shrink-0 text-white" style="background:linear-gradient(180deg,#4f3089 0%,#a42785 100%)">
      <div class="h-16 flex items-center px-5 gap-3 border-b border-white/10">
        <Logo negativo clase="h-7" />
        <span class="ml-auto text-[9px] font-bold uppercase tracking-widest px-2 py-1 rounded-md bg-white/15">Admin</span>
      </div>
      <nav class="flex-1 py-4">
        <Link v-for="i in items" :key="i.href" :href="i.href" class="relative flex items-center gap-3 mx-2 my-0.5 px-3 py-2 rounded-xl text-sm transition" :class="activo(i) ? 'bg-white text-violeta font-semibold shadow-sm' : 'text-white/80 hover:bg-white/15 hover:text-white'">
          <span v-if="activo(i)" class="absolute left-0 top-2 bottom-2 w-1 rounded-r bg-carmin"></span>
          <Icono :nombre="i.icono" clase="w-5 h-5 shrink-0" /><span>{{ i.label }}</span>
        </Link>
      </nav>
      <div class="p-3 border-t border-white/10">
        <div class="flex items-center gap-3 px-2">
          <span class="w-9 h-9 rounded-full bg-marca-grad flex items-center justify-center text-sm font-bold shrink-0">{{ iniciales }}</span>
          <div class="min-w-0 flex-1"><p class="text-sm font-semibold truncate">{{ user?.name }}</p><p class="text-[11px] text-lavanda truncate">Superadmin BigSys</p></div>
          <Link href="/logout" method="post" as="button" class="p-1.5 rounded-lg hover:bg-white/10 text-white/70" title="Salir"><Icono nombre="logout" clase="w-4 h-4" /></Link>
        </div>
      </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0">
      <header class="h-16 bg-white border-b border-marca-borde flex items-center px-4 md:px-6 gap-3 sticky top-0 z-30">
        <button @click="movil = true" class="md:hidden p-2 rounded-lg hover:bg-marca-fondo"><Icono nombre="menu" /></button>
        <p class="text-sm text-marca-muted hidden sm:block">Panel de control de <b class="text-marca-texto">BigSysWeb</b> · todas las empresas</p>
        <div class="flex-1"></div>
        <Link href="/admin/empresas?nueva=1" class="btn-primary !py-1.5 text-xs"><Icono nombre="plus" clase="w-4 h-4" /> Nueva empresa</Link>
      </header>
      <div v-if="flash?.success" class="mx-4 md:mx-6 mt-4 px-4 py-2.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ flash.success }}</div>
      <div v-if="flash?.error" class="mx-4 md:mx-6 mt-4 px-4 py-2.5 rounded-xl bg-carmin-light border border-carmin/30 text-carmin-dark text-sm">{{ flash.error }}</div>
      <main class="flex-1 p-4 md:p-6"><slot /></main>
    </div>

    <div v-if="movil" class="fixed inset-0 z-40 md:hidden">
      <div class="absolute inset-0 bg-black/50" @click="movil = false"></div>
      <div class="absolute left-0 top-0 bottom-0 w-72 text-white p-4" style="background:#2a1a52">
        <div class="flex items-center justify-between mb-4"><Logo negativo clase="h-7" /><button @click="movil = false"><Icono nombre="x" /></button></div>
        <Link v-for="i in items" :key="i.href" :href="i.href" @click="movil = false" class="flex items-center gap-3 px-2 py-2 rounded-lg text-sm" :class="activo(i) ? 'bg-white/10 font-semibold' : 'text-white/80'"><Icono :nombre="i.icono" clase="w-5 h-5" /> {{ i.label }}</Link>
        <Link href="/logout" method="post" as="button" class="mt-6 flex items-center gap-2 text-sm text-white/70"><Icono nombre="logout" clase="w-4 h-4" /> Salir</Link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'
import Logo from '@/Components/Logo.vue'
defineProps({ titulo: { type: String, default: '' } })
const page = usePage()
const user = computed(() => page.props.auth?.user)
const flash = computed(() => page.props.flash)
const movil = ref(false)
const iniciales = computed(() => (user.value?.name ?? '?').split(' ').slice(0, 2).map(p => p[0]).join('').toUpperCase())
const items = [
  { href: '/admin', label: 'Inicio', icono: 'home' },
  { href: '/admin/empresas', label: 'Empresas', icono: 'building' },
  { href: '/admin/planes', label: 'Planes', icono: 'receipt' },
  { href: '/admin/cobros', label: 'Cobros', icono: 'wallet' },
  { href: '/admin/usuarios', label: 'Usuarios', icono: 'users' },
  { href: '/admin/soporte', label: 'Soporte', icono: 'info' },
  { href: '/admin/sistema', label: 'Sistema', icono: 'settings' },
  { href: '/admin/auditoria', label: 'Auditoría', icono: 'history' },
]
function activo(i) { const u = page.url.split('?')[0]; return u === i.href || (i.href !== '/admin' && u.startsWith(i.href + '/')) }
</script>
