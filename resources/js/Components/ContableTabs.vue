<template>
  <div class="flex gap-1 overflow-x-auto border-b border-marca-borde mb-5 -mx-1 px-1">
    <Link v-for="t in tabs" :key="t.href" :href="t.href" class="px-4 py-2.5 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px transition" :class="activa(t) ? 'border-carmin text-carmin' : 'border-transparent text-marca-muted hover:text-marca-texto'">{{ t.label }}</Link>
  </div>
</template>
<script setup>
import { Link, usePage } from '@inertiajs/vue3'
const page = usePage()
const tabs = [
  { href: '/contable', label: 'Resumen' }, { href: '/contable/asientos', label: 'Asientos' }, { href: '/contable/mayor', label: 'Mayor' }, { href: '/contable/iva', label: 'Libros IVA' },
  { href: '/contable/balance', label: 'Balance' }, { href: '/contable/flujo', label: 'Flujo de fondos' }, { href: '/contable/conciliacion', label: 'Conciliación' }, { href: '/contable/plan', label: 'Plan de cuentas' },
]
const activa = t => page.url.split('?')[0] === t.href
</script>
