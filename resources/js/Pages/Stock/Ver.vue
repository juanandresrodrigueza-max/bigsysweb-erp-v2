<template>
  <AppLayout :titulo="p.name">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div>
        <Link href="/stock" class="text-xs text-marca-muted hover:text-carmin">← Stock</Link>
        <h1 class="page-title">{{ p.name }}</h1>
        <p class="page-subtitle tabular-nums">{{ p.sku }}<span v-if="p.barcode"> · {{ p.barcode }}</span><span v-if="p.marca"> · {{ p.marca }}</span> · {{ p.tipo_label.split(' (')[0] }}<span v-if="p.rubro"> · {{ p.rubro }}</span><span v-if="p.proveedor"> · proveedor {{ p.proveedor }}</span></p>
        <div class="flex flex-wrap gap-1.5 mt-1.5">
          <span v-if="!p.active" class="badge bg-gris-light text-marca-muted">Inactivo</span>
          <span v-if="p.controla_stock && p.stock <= 0" class="badge bg-carmin-light text-carmin">Sin stock</span>
          <span v-else-if="p.bajo" class="badge bg-amber-50 text-amber-700">Bajo mínimo</span>
          <span v-if="formula" class="badge bg-violeta-light text-violeta">Se produce · {{ formula.name }}</span>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-if="puede('stock','editar') && p.controla_stock" @click="ajAbierto = true" class="btn-secondary">Ajustar stock</button>
        <Link v-if="formula && puede('produccion','crear')" href="/produccion" class="btn-secondary">Producir</Link>
        <button v-if="puede('stock','editar')" @click="editAbierto = true" class="btn-primary"><Icono nombre="edit" clase="w-4 h-4" /> Editar</button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Stock total</p><p class="text-2xl font-extrabold tabular-nums" :class="p.stock <= 0 && p.controla_stock ? 'text-carmin' : p.bajo ? 'text-amber-600' : ''">{{ p.controla_stock ? cantidad(p.stock) + ' ' + p.unit : '—' }}</p><p class="text-xs text-marca-muted">mínimo {{ cantidad(p.stock_min) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Valorizado</p><p class="text-2xl font-extrabold tabular-nums">{{ moneda(p.valor, 0) }}</p><p class="text-xs text-marca-muted">a costo {{ moneda(p.cost) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Precio lista 1</p><p class="text-2xl font-extrabold tabular-nums">{{ moneda(p.price, 0) }}</p><p class="text-xs" :class="p.margen !== null && p.margen < 0 ? 'text-carmin' : 'text-marca-muted'">margen {{ p.margen === null ? '—' : p.margen + '%' }} · IVA {{ p.iva }}%<span v-if="p.precio_actualizado"> · act. {{ p.precio_actualizado }}</span></p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vendido 30 días</p><p class="text-2xl font-extrabold tabular-nums">{{ cantidad(p.vendido_30) }}</p><p class="text-xs text-marca-muted">comprado {{ cantidad(p.comprado_30) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Días de stock</p><p class="text-2xl font-extrabold tabular-nums" :class="p.dias_stock !== null && p.dias_stock < 7 ? 'text-amber-600' : ''">{{ p.dias_stock === null ? '—' : p.dias_stock }}</p><p class="text-xs text-marca-muted">al ritmo de venta actual</p></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="space-y-4">
        <div class="card">
          <h2 class="font-bold mb-2">Por depósito</h2>
          <div v-for="s in p.stocks" :key="s.deposito_id" class="flex items-center justify-between py-1.5 border-t border-marca-borde/60 first:border-0 text-sm">
            <div><p class="font-medium">{{ s.deposito }}</p><p class="text-xs text-marca-muted">{{ s.sucursal }}<span v-if="s.ubicacion"> · {{ s.ubicacion }}</span></p></div>
            <b class="tabular-nums" :class="s.cantidad < 0 ? 'text-carmin' : ''">{{ cantidad(s.cantidad) }} {{ p.unit }}</b>
          </div>
          <p v-if="!p.stocks.length" class="text-sm text-marca-muted">Sin existencias en ningún depósito.</p>
        </div>
        <div v-if="p.perecedero || p.seriado" class="card">
          <h2 class="font-bold mb-2">Partidas {{ p.perecedero ? '(vence primero, sale primero)' : '(por número de serie)' }}</h2>
          <div v-for="l in p.lotes" :key="l.id" class="flex items-center justify-between py-1.5 border-t border-marca-borde/60 first:border-0 text-sm">
            <div><p class="font-medium" :class="l.vencido ? 'text-carmin' : l.por_vencer ? 'text-amber-600' : ''">{{ l.etiqueta }}</p><p class="text-xs text-marca-muted">{{ l.deposito }}<span v-if="l.vencido"> · vencido</span><span v-else-if="l.por_vencer"> · vence en menos de 30 días</span></p></div>
            <b class="tabular-nums">{{ cantidad(l.cantidad) }} {{ p.unit }}</b>
          </div>
          <p v-if="!p.lotes.length" class="text-sm text-marca-muted">Sin partidas cargadas. Se crean al registrar una compra con lote, vencimiento o serie.</p>
        </div>
        <div class="card">
          <h2 class="font-bold mb-2">Listas de precios</h2>
          <div v-for="n in [1,2,3,4,5,6]" :key="n" class="flex justify-between py-1 text-sm border-t border-marca-borde/60 first:border-0"><span class="text-marca-muted">Lista {{ n }}</span><b class="tabular-nums">{{ moneda(n === 1 ? p.price : (p.prices[n] ?? p.price)) }}</b></div>
        </div>
        <div v-if="p.description" class="card text-sm text-marca-muted">{{ p.description }}</div>
      </div>

      <div class="lg:col-span-2 card p-0 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-marca-borde">
          <h2 class="font-bold">Kardex</h2>
          <select :value="filtros.deposito ?? ''" @change="$inertia.get(`/stock/${p.id}`, { deposito: $event.target.value || undefined }, { preserveState: true, replace: true })" class="input w-auto !py-1 text-xs"><option value="">Todos los depósitos</option><option v-for="d in depositos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead><tr><th>Fecha</th><th>Movimiento</th><th>Depósito</th><th class="text-right">Cant.</th><th class="text-right">Quedó</th><th>Usuario</th></tr></thead>
            <tbody>
              <tr v-for="m in movimientos.data" :key="m.id" :class="m.url ? 'cursor-pointer' : ''" @click="m.url && $inertia.visit(m.url)">
                <td class="tabular-nums text-marca-muted whitespace-nowrap">{{ m.fecha }}</td>
                <td><p class="font-medium">{{ m.motivo }}</p><span class="badge" :class="{ in: 'bg-emerald-50 text-emerald-700', out: 'bg-carmin-light text-carmin', ajuste: 'bg-amber-50 text-amber-700', transferencia: 'bg-lavanda-light text-violeta', inventario: 'bg-amber-50 text-amber-700', produccion: 'bg-violeta-light text-violeta' }[m.tipo]">{{ m.tipo_label }}</span></td>
                <td class="text-xs text-marca-muted">{{ m.deposito ?? '—' }}</td>
                <td class="text-right tabular-nums font-semibold" :class="m.entrada ? 'text-emerald-700' : 'text-carmin'">{{ m.entrada ? '+' : '−' }}{{ cantidad(m.cantidad) }}</td>
                <td class="text-right tabular-nums text-marca-muted">{{ cantidad(m.despues) }}</td>
                <td class="text-xs text-marca-muted">{{ m.usuario }}</td>
              </tr>
              <tr v-if="!movimientos.data.length"><td colspan="6" class="text-center text-marca-muted py-8">Sin movimientos.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="px-4"><Paginacion :links="movimientos.links" :desde="movimientos.from" :hasta="movimientos.to" :total="movimientos.total" /></div>
      </div>
    </div>

    <DocumentosAdjuntos tipo="articulo" :id="p.id" class="mt-4" ayuda="Fichas técnicas, certificados, manuales, fotos del producto." />

    <ArticuloModal :abierto="editAbierto" :articulo="articuloEditable" :rubros="rubros" :depositos="depositos" :proveedores="proveedores" :tipos="tipos" :unidades="unidades" @cerrar="editAbierto = false" />

    <Modal :abierto="ajAbierto" :titulo="`Ajustar stock · ${p.name}`" @cerrar="ajAbierto = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div><label class="label">Depósito</label><select v-model="aj.deposito_id" class="input"><option v-for="d in depositos.filter(x => x.activo)" :key="d.id" :value="d.id">{{ d.nombre }} · {{ cantidad(p.stocks.find(s => s.deposito_id === d.id)?.cantidad ?? 0) }} {{ p.unit }}</option></select></div>
        <div><label class="label">Stock real ({{ p.unit }})</label><input v-model.number="aj.nuevo" type="number" step="any" min="0" class="input" /></div>
        <div class="sm:col-span-2"><label class="label">Motivo</label><input v-model="aj.motivo" class="input" placeholder="Rotura, merma, error de carga, conteo…" /><p v-if="aj.errors.motivo" class="text-carmin text-xs mt-1">{{ aj.errors.motivo }}</p></div>
      </div>
      <p class="text-xs text-marca-muted mt-3">Queda registrado en el kardex y en auditoría. Para contar todo un depósito usá Inventario.</p>
      <template #pie><button class="btn-secondary" @click="ajAbierto = false">Cancelar</button><button class="btn-primary" :disabled="aj.processing" @click="aj.post('/stock/ajustar', { preserveScroll: true, onSuccess: () => (ajAbierto = false) })">Ajustar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import Paginacion from '@/Components/Paginacion.vue'
import ArticuloModal from '@/Components/ArticuloModal.vue'
import DocumentosAdjuntos from '@/Components/DocumentosAdjuntos.vue'
import { moneda, cantidad } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ p: Object, movimientos: Object, filtros: Object, depositos: Array, rubros: Array, tipos: Object, unidades: Object, proveedores: Array, formula: Object })
const { puede } = usePermisos()
const editAbierto = ref(false), ajAbierto = ref(false)
const articuloEditable = computed(() => ({ id: props.p.id, name: props.p.name, sku: props.p.sku, tipo: props.p.tipo, rubro_id: props.p.rubro_id, unit: props.p.unit, barcode: props.p.barcode ?? '', marca: props.p.marca ?? '', proveedor_id: props.p.proveedor_id, cost: props.p.cost, price: props.p.price, iva: Number(props.p.iva), prices: props.p.prices, stock_min: props.p.stock_min, controla_stock: props.p.controla_stock, description: props.p.description ?? '', active: props.p.active, perecedero: !!props.p.perecedero, seriado: !!props.p.seriado, control_turno: !!props.p.control_turno }))
const aj = useForm({ product_id: props.p.id, deposito_id: props.p.stocks[0]?.deposito_id ?? props.depositos[0]?.id, nuevo: props.p.stocks[0]?.cantidad ?? 0, motivo: '' })
</script>
