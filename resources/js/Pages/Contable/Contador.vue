<template>
  <AppLayout titulo="Panel del contador">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Panel del contador</h1><p class="page-subtitle">Todo lo que el estudio pide cada mes, en un lugar, en el formato de su sistema.</p></div>
      <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" />
    </div>
    <ContableTabs />

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Asientos</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.asientos }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Comprobantes de venta</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.ventas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Compras</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.compras }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Retenciones</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.retenciones }}</p></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-1">Exportar para el sistema del contador</h2>
        <p class="text-sm text-marca-muted mb-3">Los asientos del período en el formato que importa cada programa. Si usa otro, el CSV genérico entra en cualquiera.</p>
        <div class="grid sm:grid-cols-2 gap-2">
          <a v-for="(lbl, k) in formatos" :key="k" :href="`/contable/contador/exportar?formato=${k}&desde=${periodo.desde}&hasta=${periodo.hasta}`" class="flex items-center justify-between p-3 rounded-xl border border-marca-borde hover:border-carmin hover:bg-red-50/30 text-sm"><span class="font-medium">{{ lbl }}</span><span class="text-marca-muted text-xs">Descargar</span></a>
        </div>
        <h2 class="font-bold mt-5 mb-1">Checklist mensual</h2>
        <div class="grid sm:grid-cols-2 gap-1">
          <Link v-for="c in checklist" :key="c.label" :href="c.url" class="flex items-center gap-2 p-2 rounded-lg hover:bg-marca-fondo text-sm"><span class="w-5 h-5 rounded-full border-2 border-lavanda shrink-0"></span>{{ c.label }}</Link>
        </div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-1">Acceso para el estudio</h2>
        <p class="text-sm text-marca-muted mb-3">Un usuario con rol Contador: ve contabilidad, libros, fondos y comprobantes (sin poder facturar ni tocar stock).</p>
        <div v-for="c in contadores" :key="c.id" class="text-sm py-1.5 border-t border-marca-borde/60 first:border-0 flex items-center gap-2"><div class="flex-1 min-w-0"><p class="font-medium">{{ c.name }} <span v-if="c.externo" class="badge bg-lavanda-light text-violeta ml-1">estudio · {{ c.empresas }} empresas</span></p><p class="text-xs text-marca-muted">{{ c.email }} · {{ c.ultimo }}</p></div><button v-if="c.externo" class="btn-ghost !px-2 text-xs text-carmin" @click="router.delete(`/contable/contador/acceso/${c.id}`, { preserveScroll: true })">Quitar</button></div>
        <p class="text-[11px] text-marca-muted mt-2">Si el contador ya usa BigSysWeb con otro cliente, cargá el mismo email: se le da acceso sin crear otro usuario y cambia de empresa desde el encabezado.</p>
        <form @submit.prevent="inv.post('/contable/contador/invitar', { preserveScroll: true, onSuccess: () => inv.reset() })" class="space-y-2 mt-3">
          <input v-model="inv.name" class="input" placeholder="Nombre del contador" />
          <input v-model="inv.email" type="email" class="input" placeholder="Email" /><p v-if="inv.errors.email" class="text-carmin text-xs">{{ inv.errors.email }}</p>
          <input v-model="inv.password" type="text" class="input" placeholder="Contraseña inicial (mín. 8; vacío si ya tiene usuario)" /><p v-if="inv.errors.password" class="text-carmin text-xs">{{ inv.errors.password }}</p>
          <button class="btn-primary w-full" :disabled="inv.processing || !inv.email">Dar acceso</button>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
defineProps({ periodo: Object, formatos: Object, contadores: Array, resumen: Object, checklist: Array })
const inv = useForm({ name: '', email: '', password: '' })
</script>
