<template>
  <div class="flex h-screen bg-gray-100">
    <aside :class="sidebarOpen ? 'w-64' : 'w-16'" class="bg-white shadow-lg transition-all duration-300 flex flex-col">
      <div class="flex items-center justify-between p-4 border-b">
        <span v-if="sidebarOpen" class="text-lg font-bold text-blue-600">BigSysWeb ERP</span>
        <button @click="sidebarOpen = !sidebarOpen" class="p-1 rounded hover:bg-gray-100">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
          </svg>
        </button>
      </div>
      <nav class="flex-1 overflow-y-auto py-4">
        <template v-for="group in navGroups" :key="group.label">
          <div v-if="sidebarOpen" class="px-4 py-1 text-xs font-semibold text-gray-400 uppercase">{{ group.label }}</div>
          <Link v-for="item in group.items" :key="item.href" :href="item.href" class="flex items-center px-4 py-2 text-sm text-gray-600 hover:bg-blue-50 hover:text-blue-600">
            <span class="text-lg mr-3">{{ item.icon }}</span>
            <span v-if="sidebarOpen">{{ item.label }}</span>
          </Link>
        </template>
      </nav>
    </aside>
    <div class="flex-1 flex flex-col overflow-hidden">
      <header class="bg-white shadow-sm px-6 py-3 flex items-center justify-between">
        <h1 class="text-lg font-semibold text-gray-700">{{ $page.props.title }}</h1>
        <div class="flex items-center gap-4">
          <span class="text-sm text-gray-500">{{ $page.props.auth?.user?.name }}</span>
          <Link href="/logout" method="post" as="button" class="text-sm text-red-500 hover:text-red-700">Salir</Link>
        </div>
      </header>
      <div v-if="$page.props.flash?.success" class="bg-green-100 border border-green-400 text-green-700 px-4 py-2 text-sm">{{ $page.props.flash.success }}</div>
      <main class="flex-1 overflow-y-auto p-6"><slot /></main>
    </div>
  </div>
</template>
<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
const sidebarOpen = ref(true)
const navGroups = [
  { label: 'Comercial', items: [{ href: '/ventas', icon: '🛒', label: 'Ventas' },{ href: '/clientes', icon: '👥', label: 'Clientes' },{ href: '/remitos', icon: '📦', label: 'Remitos' }]},
  { label: 'Compras', items: [{ href: '/compras', icon: '🛍', label: 'Compras' },{ href: '/proveedores', icon: '🏭', label: 'Proveedores' }]},
  { label: 'Inventario', items: [{ href: '/productos', icon: '📋', label: 'Productos' },{ href: '/stock', icon: '📊', label: 'Stock' }]},
  { label: 'Gastronomia', items: [{ href: '/mozo', icon: '🍽', label: 'Mozo' },{ href: '/cocina', icon: '🍳', label: 'Cocina' },{ href: '/caja', icon: '💰', label: 'Caja' }]},
  { label: 'CRM', items: [{ href: '/crm/leads', icon: '🎯', label: 'Leads' },{ href: '/crm/actividades', icon: '📅', label: 'Actividades' }]},
  { label: 'Config', items: [{ href: '/configuracion', icon: '⚙', label: 'Config' }]},
]
</script>
