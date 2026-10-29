<template>
  <AppLayout titulo="Estadísticas · Stock y compras">
    <!-- Fase 33.4: lo que dejás de vender por falta de stock, qué pedirle a cada proveedor, antigüedad, ajustes para abajo, cuánto aumentó cada proveedor, quién vende más barato. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
      <div><h1 class="page-title">Stock y compras</h1><p class="page-subtitle">Lo que falta, lo que sobra, lo que se quedó y a quién comprarle. Son cuentas con tus datos: cada tarjeta dice cómo (ⓘ).</p></div>
      <Link href="/stock/informes" class="btn-secondary !py-1 text-xs">Informes de stock</Link>
    </div>
    <EstadisticasTabs activa="stock" />

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
      <div class="card" data-e2e="dejas">
        <h2 class="font-bold">Lo que dejás de vender por falta de stock <span class="text-marca-muted cursor-help font-normal text-sm" :title="dejas.como">ⓘ</span></h2>
        <p class="text-3xl font-black tabular-nums mt-1" :class="dejas.por_mes > 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(dejas.por_mes, 0) }} <span class="text-sm font-normal text-marca-muted">por mes · {{ moneda(dejas.por_dia, 0) }} por día · {{ dejas.items.length }} artículos sin stock que se vendían</span></p>
        <div class="max-h-64 overflow-y-auto mt-2 text-sm"><div v-for="d in dejas.items" :key="d.id" class="flex justify-between gap-2 py-1 border-t border-marca-borde"><span class="truncate"><Link :href="`/stock/${d.id}`" class="hover:text-carmin font-medium">{{ d.nombre }}</Link> <span class="text-xs text-marca-muted">· {{ d.por_dia }}/día{{ d.proveedor ? ' · ' + d.proveedor : '' }}{{ d.dias_sin !== null ? ' · sin stock hace ' + d.dias_sin + ' d' : '' }}</span></span><b class="tabular-nums whitespace-nowrap">{{ moneda(d.pierde_dia, 0) }}/día</b></div><p v-if="!dejas.items.length" class="text-emerald-700 py-4 text-center">Nada que se venda está sin stock.</p></div>
      </div>
      <div class="card" data-e2e="pedir">
        <h2 class="font-bold">Qué pedirle a cada proveedor <span class="text-marca-muted cursor-help font-normal text-sm" :title="pedir.como">ⓘ</span></h2>
        <p class="text-3xl font-black tabular-nums mt-1">{{ moneda(pedir.total, 0) }} <span class="text-sm font-normal text-marca-muted">{{ pedir.renglones }} renglones{{ pedir.sin_costo ? ' · ' + pedir.sin_costo + ' sin costo (no suman)' : '' }}</span></p>
        <div class="max-h-64 overflow-y-auto mt-2 text-sm">
          <details v-for="p in pedir.proveedores" :key="p.proveedor_id ?? 0" class="border-t border-marca-borde py-1"><summary class="flex justify-between gap-2 cursor-pointer"><span><b>{{ p.proveedor }}</b> <span class="text-xs text-marca-muted">· {{ p.renglones }} renglones{{ p.cada_dias ? ' · le comprás cada ' + p.cada_dias + ' días' : '' }}{{ p.ultima_compra ? ' · última ' + p.ultima_compra : '' }}</span></span><span class="tabular-nums font-semibold whitespace-nowrap">{{ moneda(p.costo, 0) }}</span></summary>
            <div class="pl-3 text-xs"><div v-for="i in p.items" :key="i.id" class="flex justify-between gap-2 py-0.5"><span class="truncate">{{ i.nombre }} <span class="text-marca-muted">· stock {{ cantidad(i.stock) }} · vende {{ i.venta_diaria }}/día</span></span><span class="tabular-nums whitespace-nowrap">pedir {{ cantidad(i.pedir) }} · {{ i.costo > 0 ? moneda(i.costo, 0) : 'sin costo' }}</span></div></div>
            <Link v-if="p.proveedor_id" :href="`/proveedores/ordenes/nueva?sugerir=1&contact_id=${p.proveedor_id}`" class="text-xs text-violeta font-semibold">Armar la orden →</Link>
          </details>
          <p v-if="!pedir.proveedores.length" class="text-emerald-700 py-4 text-center">No hace falta reponer nada.</p>
        </div>
      </div>
    </div>

    <div class="card mb-4" data-e2e="antiguedad">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
        <h2 class="font-bold">Antigüedad: cuándo se vendió y se compró por última vez <span class="text-marca-muted cursor-help font-normal text-sm" :title="antiguedad.como">ⓘ</span></h2>
        <div class="flex items-center gap-1.5 text-xs"><span class="text-marca-muted">{{ antiguedad.total }} artículos · {{ moneda(antiguedad.plata, 0) }} parados</span><select :value="antiguedad.orden" class="input !py-1 !text-xs w-auto" @change="ir({ orden: $event.target.value })"><option value="sin_vender">Más días sin vender</option><option value="sin_comprar">Más días sin comprar</option><option value="plata">Más plata parada</option><option value="stock">Más stock</option></select><input :value="filtros.q" class="input !py-1 !text-xs w-36" placeholder="Nombre o código…" @keydown.enter="ir({ q: $event.target.value })" /></div>
      </div>
      <div class="max-h-80 overflow-y-auto"><table class="table text-xs"><thead><tr><th>Artículo</th><th class="text-right">Stock</th><th class="text-right">Plata al costo</th><th class="text-right">Última venta</th><th class="text-right">Última compra</th></tr></thead>
        <tbody><tr v-for="a in antiguedad.items" :key="a.id"><td><Link :href="`/stock/${a.id}`" class="hover:text-carmin">{{ a.nombre }}</Link> <span class="text-marca-muted">{{ a.sku }}</span></td><td class="text-right tabular-nums">{{ cantidad(a.stock) }} {{ a.unit }}</td><td class="text-right tabular-nums">{{ moneda(a.plata, 0) }}</td><td class="text-right tabular-nums" :class="a.dias_sin_vender === null || a.dias_sin_vender > 90 ? 'text-carmin' : ''">{{ a.ultima_venta ?? 'nunca' }}{{ a.dias_sin_vender !== null ? ' · ' + a.dias_sin_vender + ' d' : '' }}</td><td class="text-right tabular-nums text-marca-muted">{{ a.ultima_compra ?? 'nunca' }}{{ a.dias_sin_comprar !== null ? ' · ' + a.dias_sin_comprar + ' d' : '' }}</td></tr></tbody></table></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <div class="card" data-e2e="ajustes">
        <h2 class="font-bold">Ajustes para abajo <span class="text-marca-muted cursor-help font-normal text-sm" :title="ajustes.como">ⓘ</span></h2>
        <p class="text-2xl font-black tabular-nums mt-1 text-carmin">{{ moneda(ajustes.total, 0) }} <span class="text-sm font-normal text-marca-muted">{{ ajustes.cantidad }} ajustes en {{ ajustes.dias }} días</span></p>
        <div v-for="r in ajustes.por_rubro" :key="r.rubro" class="flex justify-between text-sm py-0.5"><span>{{ r.rubro }} <span class="text-xs text-marca-muted">{{ r.ajustes }}</span></span><span class="tabular-nums">{{ moneda(r.valor, 0) }}</span></div>
        <details v-if="ajustes.items.length" class="text-xs mt-2"><summary class="cursor-pointer text-violeta font-semibold">Artículo por artículo</summary><div v-for="a in ajustes.items" :key="a.fecha + a.id + a.cantidad" class="flex justify-between gap-2 py-0.5 border-t border-marca-borde"><span class="truncate">{{ a.fecha }} · {{ a.nombre }} <span class="text-marca-muted">· {{ a.motivo }}</span></span><span class="tabular-nums whitespace-nowrap">−{{ cantidad(a.cantidad) }} · {{ moneda(a.valor, 0) }}</span></div></details>
        <p v-if="!ajustes.items.length" class="text-emerald-700 text-sm py-3 text-center">Sin ajustes para abajo.</p>
      </div>
      <div class="card" data-e2e="aumentos">
        <h2 class="font-bold">Cuánto aumentó cada proveedor <span class="text-marca-muted cursor-help font-normal text-sm" :title="aumentos.como">ⓘ</span></h2>
        <p class="text-xs text-marca-muted mb-2">Últimos 90 días contra los 90 anteriores, con los mismos artículos.</p>
        <div v-for="a in aumentos.items" :key="a.contact_id" class="flex justify-between gap-2 text-sm py-1 border-t border-marca-borde"><span><Link :href="`/proveedores/${a.contact_id}`" class="hover:text-carmin font-medium">{{ a.proveedor }}</Link> <span class="text-xs text-marca-muted">· {{ a.articulos }} art. · {{ moneda(a.comprado, 0) }}</span></span><b class="tabular-nums" :class="a.aumento > 0 ? 'text-carmin' : 'text-emerald-700'">{{ a.aumento > 0 ? '+' : '' }}{{ a.aumento }}%</b></div>
        <p v-if="!aumentos.items.length" class="text-sm text-marca-muted py-3 text-center">Todavía no hay compras repetidas para comparar.</p>
      </div>
      <div class="card" data-e2e="barato">
        <h2 class="font-bold">Quién te vende más barato <span class="text-marca-muted cursor-help font-normal text-sm" :title="barato.como">ⓘ</span></h2>
        <p class="text-2xl font-black tabular-nums mt-1" :class="barato.ahorro_mes > 0 ? 'text-emerald-700' : ''">{{ moneda(barato.ahorro_mes, 0) }} <span class="text-sm font-normal text-marca-muted">de ahorro posible por mes</span></p>
        <p v-if="barato.gana.length" class="text-xs text-marca-muted mb-2">Gana más seguido: <span v-for="(g, i) in barato.gana.slice(0, 3)" :key="g.proveedor"><b>{{ g.proveedor }}</b> ({{ g.articulos }}){{ i < Math.min(2, barato.gana.length - 1) ? ', ' : '' }}</span></p>
        <div class="max-h-56 overflow-y-auto text-xs"><div v-for="b in barato.items" :key="b.id" class="py-1 border-t border-marca-borde"><div class="flex justify-between gap-2"><Link :href="`/stock/${b.id}`" class="font-medium truncate hover:text-carmin">{{ b.nombre }}</Link><b class="tabular-nums whitespace-nowrap text-emerald-700">{{ moneda(b.ahorro_mes, 0) }}/mes</b></div><p class="text-marca-muted">hoy {{ b.hoy.proveedor }} {{ moneda(b.hoy.costo) }} ({{ b.hoy.fecha }}) · más barato <b>{{ b.mejor.proveedor }}</b> {{ moneda(b.mejor.costo) }} ({{ b.mejor.fecha }}){{ b.mejor.codigo ? ' · cód. ' + b.mejor.codigo : '' }} · −{{ b.pct }}%</p></div></div>
        <details v-if="barato.revisar.length" class="text-xs mt-2"><summary class="cursor-pointer text-amber-700 font-semibold">A revisar: {{ barato.revisar.length }} con más del 70 % de diferencia</summary><div v-for="b in barato.revisar" :key="b.id" class="py-0.5">{{ b.nombre }}: {{ b.hoy.proveedor }} {{ moneda(b.hoy.costo) }} vs {{ b.mejor.proveedor }} {{ moneda(b.mejor.costo) }}</div></details>
        <p v-if="!barato.items.length && !barato.revisar.length" class="text-sm text-marca-muted py-3 text-center">Hace falta más de un proveedor con precio para cada artículo.</p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadisticasTabs from '@/Components/EstadisticasTabs.vue'
import { moneda, cantidad } from '@/util/formato'
defineProps({ dejas: Object, pedir: Object, antiguedad: Object, ajustes: Object, aumentos: Object, barato: Object })
const page = usePage()
const filtros = computed(() => Object.fromEntries(new URLSearchParams(page.url.split('?')[1] ?? '')))
function ir(extra) { router.get('/estadisticas/stock', Object.fromEntries(Object.entries({ ...filtros.value, ...extra }).filter(([, v]) => v !== undefined && v !== '')), { preserveState: true, preserveScroll: true, replace: true, only: ['antiguedad'] }) }
</script>
