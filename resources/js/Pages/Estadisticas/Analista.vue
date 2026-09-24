<template>
  <AppLayout titulo="Analista IA">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">✦ Analista del negocio</h1><p class="page-subtitle">Mira los números por vos y te cuenta qué pasa, en palabras. Cada lunes a las 8 llega este informe a los dueños.</p></div>
      <div class="flex gap-2"><Link href="/estadisticas" class="btn-secondary">Estadísticas</Link><Link href="/stock/informes?tipo=faltantes&estacional=1" class="btn-secondary">Compra sugerida completa</Link></div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Críticos</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.criticas ? 'text-carmin' : ''">{{ resumen.criticas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Avisos</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.avisos ? 'text-amber-600' : ''">{{ resumen.avisos }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Para saber</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.info }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Plata en juego</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.impacto, 0) }}</p></div>
    </div>
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card border-l-4" :class="informe.modo === 'ia' ? 'border-l-violeta' : 'border-l-marca-borde'">
          <div class="flex items-center justify-between mb-2"><h2 class="font-bold">Informe de la semana</h2><span class="badge" :class="informe.modo === 'ia' ? 'bg-violeta/10 text-violeta' : 'bg-gris-light text-marca-muted'">{{ informe.modo === 'ia' ? 'Redactado con IA' : 'Redactado por reglas' }}</span></div>
          <div class="text-sm leading-relaxed whitespace-pre-line">{{ informe.texto }}</div>
          <p v-if="!ia" class="text-xs text-marca-muted mt-3">Sin clave de IA configurada: el informe se arma con reglas. Cargala en Configuración → Sistema para que lo redacte en lenguaje natural.</p>
        </div>
        <div class="card p-0 overflow-hidden">
          <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Hallazgos</h2><p class="text-xs text-marca-muted">Ordenados por gravedad. Cada uno lleva a la pantalla donde se resuelve.</p></div>
          <div v-if="!hallazgos.length" class="p-8 text-center text-marca-muted text-sm">Nada llama la atención esta semana. El negocio anda parejo.</div>
          <Link v-for="(h, i) in ordenados" :key="i" :href="h.url || '/estadisticas'" class="flex gap-3 px-4 py-3 border-b border-marca-borde last:border-0 hover:bg-gris-light/50 transition">
            <span class="mt-0.5 h-2.5 w-2.5 rounded-full shrink-0" :class="{ 'bg-carmin': h.sev === 'critica', 'bg-amber-400': h.sev === 'aviso', 'bg-violeta': h.sev === 'info', 'bg-emerald-500': h.sev === 'ok' }"></span>
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2"><p class="font-semibold text-sm">{{ h.titulo }}</p><span class="badge bg-gris-light text-marca-muted text-[10px] uppercase">{{ h.cat }}</span></div>
              <p class="text-xs text-marca-muted mt-0.5">{{ h.detalle }}</p>
            </div>
            <span v-if="h.impacto" class="text-sm font-bold tabular-nums shrink-0">{{ moneda(h.impacto, 0) }}</span>
          </Link>
        </div>
      </div>
      <div class="card p-0 overflow-hidden h-fit">
        <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Compra sugerida</h2><p class="text-xs text-marca-muted">Con estacionalidad: mira cómo vendiste este mes el año pasado. {{ compra.n }} artículos · {{ moneda(compra.total, 0) }}</p></div>
        <table class="table text-xs">
          <thead><tr><th>Artículo</th><th class="text-right">Días</th><th class="text-right">Pedir</th><th class="text-right">Costo</th></tr></thead>
          <tbody>
            <tr v-for="f in compra.filas" :key="f.id"><td><Link :href="`/stock/${f.id}`" class="font-medium hover:underline">{{ f.nombre }}</Link><p class="text-[10px] text-marca-muted">{{ f.proveedor || 'Sin proveedor' }}</p></td><td class="text-right tabular-nums" :class="f.dias_stock !== null && f.dias_stock < 7 ? 'text-carmin font-bold' : ''">{{ f.dias_stock ?? '∞' }}</td><td class="text-right tabular-nums">{{ f.pedir }}</td><td class="text-right tabular-nums">{{ moneda(f.costo, 0) }}</td></tr>
            <tr v-if="!compra.filas.length"><td colspan="4" class="text-center text-marca-muted py-6">Stock cubierto para los próximos 30 días.</td></tr>
          </tbody>
        </table>
        <div v-if="compra.n > compra.filas.length" class="px-4 py-2 text-xs text-marca-muted border-t border-marca-borde">Mostrando {{ compra.filas.length }} de {{ compra.n }}. <Link href="/stock/informes?tipo=faltantes&estacional=1" class="underline">Ver todo y armar el pedido</Link></div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ hallazgos: Array, informe: Object, ia: Boolean, resumen: Object, compra: Object })
const peso = { critica: 0, aviso: 1, info: 2, ok: 3 }
const ordenados = computed(() => [...props.hallazgos].sort((a, b) => (peso[a.sev] ?? 9) - (peso[b.sev] ?? 9) || (b.impacto || 0) - (a.impacto || 0)))
</script>
