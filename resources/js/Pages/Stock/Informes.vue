<template>
  <AppLayout titulo="Informes de stock">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Informes de stock</h1><p class="page-subtitle">Cuánta plata hay en el depósito, qué se vende y qué no, qué comprar y qué vence.</p></div>
      <div class="flex gap-2"><Link href="/stock" class="btn-secondary">Volver a stock</Link><a :href="urlCon({ export: 1 })" class="btn-secondary">Exportar CSV</a></div>
    </div>
    <div class="flex gap-1 overflow-x-auto border-b border-marca-borde mb-4 -mx-1 px-1">
      <Link v-for="t in tipos" :key="t.k" :href="`/stock/informes?tipo=${t.k}`" class="px-4 py-2.5 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px transition" :class="tipo === t.k ? 'border-carmin text-carmin' : 'border-transparent text-marca-muted hover:text-marca-texto'">{{ t.label }}</Link>
    </div>

    <!-- Valorizado -->
    <template v-if="tipo === 'valorizado'">
      <div class="flex flex-wrap gap-2 items-end mb-4">
        <div><label class="label">Valuar a</label><select :value="filtros.base ?? 'cost'" class="input" @change="ir({ base: $event.target.value })"><option value="cost">Costo</option><option value="precio_compra">Precio de compra</option><option value="lista1">Precio lista 1</option><option value="lista2">Precio lista 2</option></select></div>
        <div><label class="label">Rubro</label><select :value="filtros.rubro ?? ''" class="input" @change="ir({ rubro: $event.target.value })"><option value="">Todos</option><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.nombre }}</option></select></div>
        <div class="card py-2 ml-auto"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Total valorizado</p><p class="text-2xl font-extrabold tabular-nums">{{ moneda(datos.total, 0) }}</p></div>
      </div>
      <div class="grid lg:grid-cols-3 gap-4">
        <div class="card p-0 overflow-hidden"><div class="px-4 py-3 border-b border-marca-borde font-bold">Por rubro</div>
          <table class="table text-sm"><tbody><tr v-for="r in datos.por_rubro" :key="r.rubro"><td>{{ r.rubro }}<span class="text-marca-muted text-xs"> · {{ r.articulos }}</span></td><td class="text-right tabular-nums font-semibold">{{ moneda(r.valor, 0) }}</td><td class="w-24"><div class="h-2 rounded-full bg-marca-fondo"><div class="h-2 rounded-full bg-violeta" :style="{ width: (datos.total ? r.valor / datos.total * 100 : 0) + '%' }"></div></div></td></tr></tbody></table></div>
        <div class="card p-0 overflow-hidden lg:col-span-2"><div class="overflow-x-auto"><table class="table text-xs">
          <thead><tr><th>Artículo</th><th>Rubro</th><th class="text-right">Stock</th><th class="text-right">Unitario</th><th class="text-right">Valor</th></tr></thead>
          <tbody><tr v-for="f in datos.filas" :key="f.id" class="cursor-pointer" @click="$inertia.visit(`/stock/${f.id}`)"><td>{{ f.nombre }}<span class="text-marca-muted"> {{ f.sku }}</span></td><td class="text-marca-muted">{{ f.rubro }}</td><td class="text-right tabular-nums">{{ cantidad(f.stock) }} {{ f.unit }}</td><td class="text-right tabular-nums">{{ moneda(f.unitario) }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(f.valor) }}</td></tr></tbody></table></div></div>
      </div>
    </template>

    <!-- ABC -->
    <template v-else-if="tipo === 'abc'">
      <div class="flex flex-wrap gap-3 items-end mb-4">
        <div><label class="label">Ventas de los últimos</label><select :value="datos.dias" class="input" @change="ir({ dias: $event.target.value })"><option :value="30">30 días</option><option :value="90">90 días</option><option :value="180">180 días</option><option :value="365">365 días</option></select></div>
        <div v-for="r in datos.resumen" :key="r.clase" class="card py-2 px-4"><p class="text-[11px] font-bold uppercase tracking-widest" :class="{ 'text-emerald-700': r.clase === 'A', 'text-amber-600': r.clase === 'B', 'text-marca-muted': r.clase === 'C' }">Clase {{ r.clase }} · {{ r.articulos }} art.</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(r.neto, 0) }}</p><p class="text-[11px] text-marca-muted">stock {{ moneda(r.valor_stock, 0) }}</p></div>
        <p class="text-xs text-marca-muted max-w-xs">A: el 20% de artículos que hace el 80% de la venta. C: casi no se venden; revisá si vale tenerlos en stock.</p>
      </div>
      <div class="card p-0 overflow-x-auto"><table class="table text-xs">
        <thead><tr><th>Clase</th><th>Artículo</th><th class="text-right">Vendido</th><th class="text-right">Neto</th><th class="text-right">% acum.</th><th class="text-right">Stock</th><th class="text-right">Valor stock</th></tr></thead>
        <tbody><tr v-for="f in datos.filas" :key="f.id" class="cursor-pointer" @click="$inertia.visit(`/stock/${f.id}`)"><td><span class="badge font-bold" :class="{ 'bg-emerald-50 text-emerald-700': f.clase === 'A', 'bg-amber-50 text-amber-700': f.clase === 'B', 'bg-gris-light text-marca-muted': f.clase === 'C' }">{{ f.clase }}</span></td><td>{{ f.nombre }}<span class="text-marca-muted"> {{ f.sku }}</span></td><td class="text-right tabular-nums">{{ cantidad(f.vendido) }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(f.neto, 0) }}</td><td class="text-right tabular-nums">{{ f.acumulado }}%</td><td class="text-right tabular-nums">{{ cantidad(f.stock) }}</td><td class="text-right tabular-nums">{{ moneda(f.valor_stock, 0) }}</td></tr></tbody></table></div>
    </template>

    <!-- Faltantes -->
    <template v-else-if="tipo === 'faltantes'">
      <div class="flex flex-wrap gap-3 items-end mb-4">
        <div><label class="label">Cubrir</label><select :value="datos.cobertura" class="input" @change="ir({ cobertura: $event.target.value })"><option :value="15">15 días</option><option :value="30">30 días</option><option :value="60">60 días</option></select></div>
        <div class="card py-2 px-4"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Compra sugerida</p><p class="text-lg font-extrabold tabular-nums">{{ moneda(datos.total, 0) }}</p><p class="text-[11px] text-marca-muted">{{ datos.filas.length }} artículos</p></div>
        <Link v-if="datos.filas.length" :href="`/proveedores/ordenes/nueva`" class="btn-primary ml-auto">Armar órdenes de compra</Link>
      </div>
      <div class="card p-0 overflow-x-auto"><table class="table text-xs">
        <thead><tr><th>Artículo</th><th>Proveedor</th><th class="text-right">Stock</th><th class="text-right">Mínimo</th><th class="text-right">Venta/día</th><th class="text-right">Días de stock</th><th class="text-right">Pedir</th><th class="text-right">Costo</th></tr></thead>
        <tbody><tr v-for="f in datos.filas" :key="f.id"><td><Link :href="`/stock/${f.id}`" class="hover:underline">{{ f.nombre }}</Link><span class="text-marca-muted"> {{ f.sku }}</span></td><td class="text-marca-muted">{{ f.proveedor ?? '—' }}</td><td class="text-right tabular-nums" :class="f.stock <= f.minimo ? 'text-carmin font-semibold' : ''">{{ cantidad(f.stock) }}</td><td class="text-right tabular-nums">{{ cantidad(f.minimo) }}</td><td class="text-right tabular-nums">{{ f.venta_diaria }}</td><td class="text-right tabular-nums" :class="f.dias_stock !== null && f.dias_stock < 7 ? 'text-amber-600 font-semibold' : ''">{{ f.dias_stock ?? '—' }}</td><td class="text-right tabular-nums font-bold">{{ cantidad(f.pedir) }}</td><td class="text-right tabular-nums">{{ moneda(f.costo, 0) }}</td></tr>
        <tr v-if="!datos.filas.length"><td colspan="8" class="text-center text-marca-muted py-8">Nada que reponer con el ritmo de venta actual.</td></tr></tbody></table></div>
    </template>

    <!-- Muertos -->
    <template v-else-if="tipo === 'muertos'">
      <div class="flex flex-wrap gap-3 items-end mb-4">
        <div><label class="label">Sin ventas hace</label><select :value="datos.dias" class="input" @change="ir({ dias: $event.target.value })"><option :value="60">60 días</option><option :value="90">90 días</option><option :value="180">180 días</option><option :value="365">1 año</option></select></div>
        <div class="card py-2 px-4"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Plata parada</p><p class="text-lg font-extrabold tabular-nums text-carmin">{{ moneda(datos.total, 0) }}</p><p class="text-[11px] text-marca-muted">{{ datos.filas.length }} artículos con stock y sin ventas</p></div>
        <p class="text-xs text-marca-muted max-w-sm">Ideas: promoción, combo con un artículo clase A, devolución al proveedor o bajar el mínimo para no volver a comprarlo.</p>
      </div>
      <div class="card p-0 overflow-x-auto"><table class="table text-xs">
        <thead><tr><th>Artículo</th><th class="text-right">Stock</th><th class="text-right">Valor</th><th>Última venta</th><th></th></tr></thead>
        <tbody><tr v-for="f in datos.filas" :key="f.id"><td>{{ f.nombre }}<span class="text-marca-muted"> {{ f.sku }}</span></td><td class="text-right tabular-nums">{{ cantidad(f.stock) }} {{ f.unit }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(f.valor, 0) }}</td><td class="tabular-nums text-marca-muted">{{ f.ultima_venta ? f.ultima_venta.split('-').reverse().join('/') : 'nunca' }}</td><td class="text-right"><Link :href="`/stock/etiquetas?ids=${f.id}`" class="btn-ghost !px-2 text-xs">Etiqueta oferta</Link></td></tr>
        <tr v-if="!datos.filas.length"><td colspan="5" class="text-center text-marca-muted py-8">Todo lo que hay en stock se movió en el período.</td></tr></tbody></table></div>
    </template>

    <!-- Mínimos -->
    <template v-else-if="tipo === 'minimos'">
      <div class="flex flex-wrap gap-3 items-end mb-4">
        <div><label class="label">Días para reponer (lead time)</label><select :value="datos.lead" class="input" @change="ir({ lead: $event.target.value })"><option :value="7">7</option><option :value="15">15</option><option :value="30">30</option></select></div>
        <p class="text-xs text-marca-muted max-w-md">Mínimo sugerido = venta diaria × días de reposición × 1,2 de margen. Marcá los que quieras aplicar.</p>
        <button class="btn-primary ml-auto" :disabled="!Object.keys(sel).length || mn.processing" @click="aplicar">Aplicar {{ Object.keys(sel).length }} mínimos</button>
      </div>
      <div class="card p-0 overflow-x-auto"><table class="table text-xs">
        <thead><tr><th><input type="checkbox" @change="todos($event.target.checked)" /></th><th>Artículo</th><th class="text-right">Venta/día</th><th class="text-right">Mínimo actual</th><th class="text-right">Sugerido</th></tr></thead>
        <tbody><tr v-for="f in datos.filas" :key="f.id"><td><input type="checkbox" :checked="sel[f.id] !== undefined" @change="$event.target.checked ? (sel[f.id] = f.sugerido) : delete sel[f.id]" /></td><td>{{ f.nombre }}<span class="text-marca-muted"> {{ f.sku }}</span></td><td class="text-right tabular-nums">{{ f.venta_diaria }}</td><td class="text-right tabular-nums">{{ cantidad(f.actual) }}</td><td class="text-right tabular-nums font-bold" :class="f.sugerido > f.actual ? 'text-carmin' : 'text-emerald-700'">{{ cantidad(f.sugerido) }}</td></tr>
        <tr v-if="!datos.filas.length"><td colspan="5" class="text-center text-marca-muted py-8">Los mínimos ya están alineados con la venta.</td></tr></tbody></table></div>
    </template>

    <!-- Vencimientos -->
    <template v-else>
      <div class="flex flex-wrap gap-3 items-end mb-4">
        <div><label class="label">Vencen en</label><select :value="datos.dias" class="input" @change="ir({ dias: $event.target.value })"><option :value="7">7 días</option><option :value="30">30 días</option><option :value="90">90 días</option></select></div>
        <p class="text-xs text-marca-muted max-w-md">Solo artículos marcados como perecederos. Las partidas salen FEFO: vence primero, sale primero.</p>
      </div>
      <div class="card p-0 overflow-x-auto"><table class="table text-xs">
        <thead><tr><th>Artículo</th><th>Partida</th><th>Depósito</th><th>Vence</th><th class="text-right">Cantidad</th><th class="text-right">Valor</th></tr></thead>
        <tbody><tr v-for="f in datos.filas" :key="f.id" :class="f.vencido ? 'bg-red-50' : ''"><td><Link :href="`/stock/${f.product_id}`" class="hover:underline">{{ f.nombre }}</Link><span class="text-marca-muted"> {{ f.sku }}</span></td><td>{{ f.lote || f.serie || '—' }}</td><td class="text-marca-muted">{{ f.deposito }}</td><td class="tabular-nums" :class="f.vencido ? 'text-carmin font-bold' : f.dias <= 7 ? 'text-amber-600 font-semibold' : ''">{{ f.vencimiento }} <span class="text-[10px]">{{ f.vencido ? '(vencido)' : `(${f.dias} días)` }}</span></td><td class="text-right tabular-nums">{{ cantidad(f.cantidad) }} {{ f.unit }}</td><td class="text-right tabular-nums">{{ moneda(f.valor, 0) }}</td></tr>
        <tr v-if="!datos.filas.length"><td colspan="6" class="text-center text-marca-muted py-8">Nada vence en el período.</td></tr></tbody></table></div>
    </template>
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda, cantidad } from '@/util/formato'
const props = defineProps({ tipo: String, datos: Object, filtros: Object, rubros: Array })
const tipos = [{ k: 'valorizado', label: 'Valorizado' }, { k: 'abc', label: 'ABC' }, { k: 'faltantes', label: 'Faltantes y compra' }, { k: 'muertos', label: 'Sin movimiento' }, { k: 'minimos', label: 'Mínimos sugeridos' }, { k: 'vencimientos', label: 'Vencimientos' }]
const urlCon = extra => '/stock/informes?' + new URLSearchParams({ tipo: props.tipo, ...Object.fromEntries(Object.entries(props.filtros).filter(([, v]) => v !== null && v !== '')), ...extra }).toString()
const ir = extra => router.visit(urlCon(extra), { preserveScroll: true })
const sel = reactive({})
const mn = useForm({ minimos: {} })
function todos(on) { Object.keys(sel).forEach(k => delete sel[k]); if (on) props.datos.filas.forEach(f => (sel[f.id] = f.sugerido)) }
function aplicar() { mn.minimos = { ...sel }; mn.post('/stock/informes/minimos', { preserveScroll: true, onSuccess: () => todos(false) }) }
</script>
