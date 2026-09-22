<template>
  <AppLayout titulo="Alertas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <h1 class="page-title">Alertas</h1>
        <p class="page-subtitle">Lo que necesita tu atención, por módulo y severidad.</p>
      </div>
      <Link href="/alertas/leer-todas" method="post" as="button" class="btn-secondary">Marcar todas como leídas</Link>
    </div>

    <div class="card mb-4 flex flex-wrap gap-2 items-center">
      <select v-model="f.estado" @change="filtrar" class="input w-auto"><option value="activas">Activas</option><option value="resueltas">Resueltas</option><option value="todas">Todas</option></select>
      <select v-model="f.modulo" @change="filtrar" class="input w-auto"><option value="">Todos los módulos</option><option v-for="m in modulos" :key="m.key" :value="m.key">{{ m.label }}</option></select>
      <select v-model="f.severidad" @change="filtrar" class="input w-auto"><option value="">Toda severidad</option><option value="critica">Crítica</option><option value="aviso">Aviso</option><option value="info">Info</option></select>
    </div>

    <div class="card p-0 divide-y divide-marca-borde/70">
      <div v-for="a in listado.data" :key="a.id" class="flex gap-4 px-5 py-4" :class="{ 'opacity-60': a.leida && !a.resuelta }">
        <span class="mt-1.5 w-2.5 h-2.5 rounded-full shrink-0" :class="{ critica: 'bg-carmin', aviso: 'bg-amber-500', info: 'bg-violeta' }[a.severidad]"></span>
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <p class="font-semibold">{{ a.titulo }}</p>
            <span class="badge bg-marca-fondo text-marca-muted">{{ etiqueta(a.modulo) }}</span>
            <span v-if="a.resuelta" class="badge bg-emerald-50 text-emerald-700">Resuelta</span>
          </div>
          <p v-if="a.detalle" class="text-sm text-marca-muted mt-0.5">{{ a.detalle }}</p>
          <p class="text-xs text-marca-muted/80 mt-1">{{ a.hace }}</p>
        </div>
        <div class="flex items-start gap-1 shrink-0">
          <Link v-if="a.url" :href="a.url" class="btn-ghost !px-2 text-xs">Ir</Link>
          <Link v-if="!a.leida" :href="`/alertas/${a.id}/leer`" method="post" as="button" preserve-scroll class="btn-ghost !px-2 text-xs">Leída</Link>
          <Link v-if="!a.resuelta" :href="`/alertas/${a.id}/resolver`" method="post" as="button" preserve-scroll class="btn-ghost !px-2 text-xs text-emerald-700">Resolver</Link>
        </div>
      </div>
      <p v-if="!listado.data.length" class="px-5 py-12 text-center text-marca-muted">No hay alertas con estos filtros.</p>
    </div>

    <div v-if="listado.links?.length > 3" class="flex justify-center gap-1 mt-4">
      <Link v-for="l in listado.links" :key="l.label" :href="l.url || '#'" v-html="l.label" class="px-3 py-1 rounded-lg text-sm" :class="l.active ? 'bg-carmin text-white' : 'bg-white border border-marca-borde text-marca-muted'" />
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({ listado: Object, modulos: Array, filtros: Object })
const f = reactive({ estado: props.filtros.estado ?? 'activas', modulo: props.filtros.modulo ?? '', severidad: props.filtros.severidad ?? '' })
const etiqueta = k => props.modulos.find(m => m.key === k)?.label ?? k
function filtrar() { router.get('/alertas', f, { preserveState: true, replace: true }) }
</script>
