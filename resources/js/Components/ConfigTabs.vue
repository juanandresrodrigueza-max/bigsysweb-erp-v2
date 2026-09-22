<template>
  <div class="flex gap-1 overflow-x-auto border-b border-marca-borde mb-6 -mx-1 px-1">
    <Link v-for="t in tabs" :key="t.href" :href="t.href"
          class="px-4 py-2.5 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px transition"
          :class="activa(t) ? 'border-carmin text-carmin' : 'border-transparent text-marca-muted hover:text-marca-texto'">
      {{ t.label }}
    </Link>
  </div>
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3'
const page = usePage()
const tabs = [
  { href: '/configuracion', label: 'Empresa' },
  { href: '/configuracion/sucursales', label: 'Sucursales' },
  { href: '/configuracion/usuarios', label: 'Usuarios' },
  { href: '/configuracion/roles', label: 'Roles y permisos' },
  { href: '/configuracion/puntos-venta', label: 'Puntos de venta y AFIP' },
  { href: '/configuracion/auditoria', label: 'Auditoría' },
]
const activa = t => page.url.split('?')[0] === t.href
</script>
