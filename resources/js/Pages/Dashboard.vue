<template>
  <AppLayout>
    <div class="mb-6">
      <h2 class="text-2xl font-bold text-gray-800">Dashboard</h2>
      <p class="text-gray-500">Resumen del dia</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <StatCard titulo="Ventas Hoy" :valor="stats.ventas_hoy" icono="💰" color="blue" />
      <StatCard titulo="Compras Mes" :valor="stats.compras_mes" icono="🛍" color="green" />
      <StatCard titulo="Clientes" :valor="stats.clientes" icono="👥" color="purple" />
      <StatCard titulo="Alertas Stock" :valor="stats.alertas_stock" icono="⚠" color="red" />
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <Link href="/ventas/nueva" class="bg-white rounded-xl shadow p-6 hover:shadow-md transition flex items-center gap-4">
        <span class="text-4xl">🛒</span>
        <div><h3 class="font-semibold text-gray-800">Nueva Venta</h3><p class="text-sm text-gray-500">Registrar una venta</p></div>
      </Link>
      <Link href="/compras/nueva" class="bg-white rounded-xl shadow p-6 hover:shadow-md transition flex items-center gap-4">
        <span class="text-4xl">🛍</span>
        <div><h3 class="font-semibold text-gray-800">Nueva Compra</h3><p class="text-sm text-gray-500">Registrar una compra</p></div>
      </Link>
      <Link href="/productos/nuevo" class="bg-white rounded-xl shadow p-6 hover:shadow-md transition flex items-center gap-4">
        <span class="text-4xl">📦</span>
        <div><h3 class="font-semibold text-gray-800">Nuevo Producto</h3><p class="text-sm text-gray-500">Agregar al catalogo</p></div>
      </Link>
    </div>
  </AppLayout>
</template>
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import StatCard from '@/Components/StatCard.vue'
import { Link } from '@inertiajs/vue3'
defineProps({ stats: { type: Object, default: () => ({ ventas_hoy: 0, compras_mes: 0, clientes: 0, alertas_stock: 0 }) } })
</script>
