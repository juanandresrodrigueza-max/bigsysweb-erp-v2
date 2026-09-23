<template>
  <AppLayout titulo="Mis empresas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Mis empresas</h1><p class="page-subtitle">Los clientes que atendés con BigSysWeb, de un vistazo. Entrás a cualquiera con un clic; el rol es el que te dio cada empresa.</p></div>
      <div class="flex items-center gap-2"><label class="label !mb-0">Mes</label><input type="month" :value="mes" class="input w-auto !py-1.5" @change="router.get('/contador/empresas', { mes: $event.target.value })" /></div>
    </div>
    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="e in empresas" :key="e.id" class="card" :class="e.actual ? 'border-violeta/40' : ''">
        <div class="flex items-start justify-between gap-2 mb-3">
          <div class="min-w-0"><p class="font-extrabold truncate">{{ e.nombre }}</p><p class="text-xs text-marca-muted">{{ e.cuit ?? 'sin CUIT' }} · {{ e.condicion_iva }} · cierra en {{ meses[e.cierre_mes - 1] }}</p></div>
          <span v-if="e.actual" class="badge bg-lavanda-light text-violeta shrink-0">estás acá</span>
          <Link v-else :href="`/empresa/${e.id}`" method="post" as="button" class="btn-primary !py-1 text-xs shrink-0">Entrar</Link>
        </div>
        <div class="grid grid-cols-2 gap-2 text-sm">
          <div class="p-2.5 rounded-xl bg-marca-fondo"><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">Ventas</p><p class="font-extrabold tabular-nums">{{ moneda(e.ventas, 0) }}</p><p class="text-[11px] text-marca-muted">{{ e.ventas_n }} comprobantes</p></div>
          <div class="p-2.5 rounded-xl bg-marca-fondo"><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">Compras</p><p class="font-extrabold tabular-nums">{{ moneda(e.compras, 0) }}</p><p class="text-[11px] text-marca-muted">{{ e.compras_n }} comprobantes</p></div>
          <div class="p-2.5 rounded-xl col-span-2" :class="e.iva_saldo > 0 ? 'bg-carmin-light' : 'bg-emerald-50'"><p class="text-[10px] font-bold uppercase tracking-widest text-marca-muted">IVA del mes</p><p class="font-extrabold tabular-nums" :class="e.iva_saldo > 0 ? 'text-carmin' : 'text-emerald-700'">{{ e.iva_saldo > 0 ? 'A pagar ' : 'A favor ' }}{{ moneda(Math.abs(e.iva_saldo), 0) }}</p><p class="text-[11px] text-marca-muted">débito {{ moneda(e.iva_debito, 0) }} · crédito {{ moneda(e.iva_credito, 0) }}</p></div>
        </div>
        <div class="flex flex-wrap gap-1.5 mt-3 text-xs">
          <span class="badge" :class="e.sin_asiento ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'">{{ e.sin_asiento }} sin asiento</span>
          <span class="badge" :class="e.pendientes_cae ? 'bg-carmin-light text-carmin' : 'bg-emerald-50 text-emerald-700'">{{ e.pendientes_cae }} pendientes de CAE</span>
          <span class="badge bg-marca-fondo text-marca-muted">copia {{ e.ultimo_backup ? new Date(e.ultimo_backup).toLocaleDateString('es-AR') : 'nunca' }}</span>
        </div>
        <div v-if="e.actual" class="flex flex-wrap gap-1.5 mt-3">
          <Link :href="`/contable/iva?desde=${periodo.desde}&hasta=${periodo.hasta}`" class="btn-secondary !py-1 text-xs">Libro IVA</Link>
          <Link :href="`/contable/contador?desde=${periodo.desde}&hasta=${periodo.hasta}`" class="btn-secondary !py-1 text-xs">Exportar asientos</Link>
          <Link :href="`/contable/fiscal?desde=${periodo.desde}&hasta=${periodo.hasta}`" class="btn-secondary !py-1 text-xs">Fiscal</Link>
        </div>
      </div>
    </div>
    <p v-if="empresas.length < 2" class="text-sm text-marca-muted mt-4">Tenés acceso a una sola empresa. Cuando otro cliente te dé acceso desde su panel del contador con este mismo email, aparece acá.</p>
  </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda } from '@/util/formato'
defineProps({ empresas: Array, mes: String, periodo: Object })
const meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
</script>
