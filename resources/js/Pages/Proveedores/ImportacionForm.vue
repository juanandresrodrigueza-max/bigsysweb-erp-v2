<template>
  <AppLayout :titulo="despacho ? despacho.numero : 'Nuevo despacho'">
    <!-- Fase 97: un despacho de importación. FOB × cotización + gastos prorrateados por valor = costo nacionalizado por unidad. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <Link href="/proveedores/importaciones" class="text-xs text-marca-muted hover:text-carmin">← Importaciones</Link>
        <h1 class="page-title flex items-center gap-3">{{ despacho ? despacho.numero : 'Nuevo despacho de importación' }}<span v-if="despacho" class="badge text-sm" :class="{ borrador: 'bg-amber-50 text-amber-700', nacionalizado: 'bg-emerald-50 text-emerald-700', anulado: 'bg-gris-light text-marca-muted' }[despacho.estado]">{{ despacho.estado_label }}</span></h1>
        <p class="page-subtitle">{{ cerrado ? `Nacionalizado el ${despacho.nacionalizado}: la mercadería ya está en el stock con su costo.` : 'Cargá los artículos con su precio FOB en la moneda del proveedor y los gastos del despacho. El costo por unidad se calcula solo.' }}</p>
      </div>
      <div v-if="!cerrado && puede('proveedores','crear')" class="flex flex-wrap gap-2">
        <button v-if="despacho && puede('proveedores','anular')" class="btn-ghost text-carmin" @click="router.post(`/proveedores/importaciones/${despacho.id}/anular`)">Anular</button>
        <button class="btn-secondary" :disabled="form.processing" @click="guardar(false)">Guardar</button>
        <button class="btn-primary" :disabled="form.processing || !items.length" @click="guardar(true)" data-e2e="nacionalizar">Nacionalizar: entra al stock</button>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <div class="sm:col-span-2"><label class="label">Proveedor del exterior</label><select v-model="form.contact_id" class="input" :disabled="cerrado"><option :value="null">Sin proveedor</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}{{ p.exterior ? '' : ' (local)' }}</option></select></div>
          <div><label class="label">N° de despacho</label><input v-model="form.despacho" class="input" placeholder="25001IC04000123X" :disabled="cerrado" /></div>
          <div><label class="label">Fecha</label><input v-model="form.fecha" type="date" class="input" :disabled="cerrado" /></div>
          <div><label class="label">Moneda</label><select v-model="form.moneda" class="input" :disabled="cerrado"><option>USD</option><option>EUR</option><option>BRL</option><option>CNY</option></select></div>
          <div><label class="label">Cotización ($ por {{ form.moneda }})</label><input v-model.number="form.cotizacion" type="number" step="any" min="0" class="input text-right tabular-nums" :disabled="cerrado" data-e2e="cotizacion" /></div>
          <div class="sm:col-span-2"><label class="label">Depósito donde entra</label><select v-model="form.deposito_id" class="input" :disabled="cerrado"><option :value="null">El de la sucursal</option><option v-for="d in depositos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></div>
          <div class="sm:col-span-2 lg:col-span-4"><label class="label">Notas</label><input v-model="form.notas" class="input" :disabled="cerrado" placeholder="Contenedor, forwarder, lo que quieras recordar" /></div>
        </div>

        <div class="card p-0 overflow-x-auto">
          <div class="px-4 py-3 border-b border-marca-borde/60 flex items-center justify-between"><h2 class="font-bold">Mercadería</h2><button v-if="!cerrado" class="btn-secondary !py-1 text-xs" @click="items.push({ product_id: null, descripcion: '', cantidad: 1, fob_unit: 0 })" data-e2e="agregar-item">+ Artículo</button></div>
          <table class="table">
            <thead><tr><th>Artículo</th><th class="text-right">Cantidad</th><th class="text-right">FOB unit. {{ form.moneda }}</th><th class="text-right">FOB $</th><th class="text-right">Gastos $</th><th class="text-right">Costo unit. $</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(it, i) in items" :key="i">
                <td class="min-w-[220px]">
                  <BuscadorSelect v-if="!cerrado" v-model="it.product_id" :opciones="opcionesProductos" :url="catalogoParcial ? '/buscar/articulos/compra' : null" @cargados="f => sumar(productosCat, f)" placeholder="Buscar artículo del catálogo" @elegido="o => { if (o && !it.descripcion) it.descripcion = o.label }" />
                  <input v-if="!cerrado" v-model="it.descripcion" class="input !py-1 text-xs mt-1" placeholder="Descripción (si no está en el catálogo)" />
                  <p v-else class="font-semibold">{{ it.descripcion }}</p>
                </td>
                <td class="text-right"><input v-model.number="it.cantidad" type="number" step="any" min="0" class="input !w-24 text-right tabular-nums" :disabled="cerrado" /></td>
                <td class="text-right"><input v-model.number="it.fob_unit" type="number" step="any" min="0" class="input !w-28 text-right tabular-nums" :disabled="cerrado" /></td>
                <td class="text-right tabular-nums">{{ moneda(calc.filas[i]?.fob ?? 0, 0) }}</td>
                <td class="text-right tabular-nums text-marca-muted">{{ moneda(calc.filas[i]?.gastos ?? 0, 0) }}</td>
                <td class="text-right tabular-nums font-bold" :data-costo="i">{{ moneda(calc.filas[i]?.costo_unit ?? 0) }}</td>
                <td><button v-if="!cerrado" class="text-marca-muted hover:text-carmin" @click="items.splice(i, 1)" title="Quitar" aria-label="Quitar"><Icono nombre="x" clase="w-4 h-4" /></button></td>
              </tr>
              <tr v-if="!items.length"><td colspan="7" class="text-center text-marca-muted py-8 text-sm">Agregá los artículos del despacho.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="space-y-4">
        <div class="card">
          <div class="flex items-center justify-between mb-2"><h2 class="font-bold">Gastos del despacho</h2><button v-if="!cerrado" class="btn-secondary !py-1 text-xs" @click="gastos.push({ tipo: 'flete', concepto: '', monto: 0 })" data-e2e="agregar-gasto">+ Gasto</button></div>
          <p class="text-xs text-marca-muted mb-3">En pesos. Flete, seguro, derechos, tasa y despachante van al costo; el IVA y las percepciones no (son crédito fiscal).</p>
          <div v-for="(g, i) in gastos" :key="i" class="grid grid-cols-[1fr_110px_28px] gap-2 mb-2">
            <div><select v-model="g.tipo" class="input !py-1 text-xs" :disabled="cerrado"><option v-for="t in tiposGasto" :key="t.key" :value="t.key">{{ t.label }}{{ t.al_costo ? '' : ' (no va al costo)' }}</option></select><input v-model="g.concepto" class="input !py-1 text-xs mt-1" placeholder="Detalle (opcional)" :disabled="cerrado" /></div>
            <input v-model.number="g.monto" type="number" step="any" min="0" class="input !py-1 text-right tabular-nums" :disabled="cerrado" />
            <button v-if="!cerrado" class="text-marca-muted hover:text-carmin" @click="gastos.splice(i, 1)" title="Quitar" aria-label="Quitar"><Icono nombre="x" clase="w-4 h-4" /></button>
          </div>
          <p v-if="!gastos.length" class="text-sm text-marca-muted">Sin gastos todavía.</p>
        </div>
        <div class="card text-sm space-y-1" data-e2e="totales">
          <div class="flex justify-between"><span class="text-marca-muted">FOB en pesos</span><b class="tabular-nums">{{ moneda(calc.fob, 0) }}</b></div>
          <div class="flex justify-between"><span class="text-marca-muted">Gastos al costo</span><b class="tabular-nums">{{ moneda(calc.gastos, 0) }}</b></div>
          <div class="flex justify-between border-t border-marca-borde pt-1"><span>Costo total nacionalizado</span><b class="tabular-nums text-lg">{{ moneda(calc.costo, 0) }}</b></div>
          <div class="flex justify-between"><span class="text-marca-muted">Coeficiente sobre FOB</span><b class="tabular-nums">{{ calc.fob ? (calc.costo / calc.fob).toFixed(3) : '—' }}</b></div>
          <div class="flex justify-between"><span class="text-marca-muted">Crédito fiscal (IVA y percepciones)</span><span class="tabular-nums">{{ moneda(calc.credito, 0) }}</span></div>
          <p class="text-[11px] text-marca-muted pt-1">Al nacionalizar, cada artículo entra al stock con su costo unitario y el costo del artículo se actualiza.</p>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import Icono from '@/Components/Icono.vue'
import { moneda, hoyISO } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const { puede } = usePermisos()
const props = defineProps({ despacho: Object, proveedores: Array, productos: Array, catalogoParcial: Boolean, depositos: Array, tiposGasto: Array, cotizacionUsd: Number })
const cerrado = computed(() => props.despacho && props.despacho.estado !== 'borrador')
const d = props.despacho
const form = useForm({ contact_id: d?.contact_id ?? null, despacho: d?.despacho ?? '', fecha: d?.fecha_iso ?? hoyISO(), moneda: d?.moneda ?? 'USD', cotizacion: d?.cotizacion ?? (props.cotizacionUsd || null), deposito_id: d?.deposito_id ?? null, notas: d?.notas ?? '', items: [], gastos: [], nacionalizar: false })
const items = ref((d?.items ?? []).map(i => ({ product_id: i.product_id, descripcion: i.descripcion, cantidad: i.cantidad, fob_unit: i.fob_unit })))
const gastos = ref((d?.gastos ?? []).map(g => ({ tipo: g.tipo, concepto: g.concepto ?? '', monto: g.monto })))
const productosCat = ref([...(props.productos ?? [])])
function sumar(lista, filas) { const arr = lista.value; const por = new Map(arr.map(x => [x.id, x])); filas.forEach(f => { const e = por.get(f.id); if (e) Object.assign(e, f); else arr.push(f) }) }
const opcionesProductos = computed(() => productosCat.value.map(p => ({ id: p.id, label: p.name, sub: p.sku, extra: 'costo ' + moneda(p.cost, 0) })))
const alCosto = computed(() => Object.fromEntries((props.tiposGasto ?? []).map(t => [t.key, t.al_costo])))
// El mismo prorrateo que hace el servidor, para verlo mientras se carga.
const calc = computed(() => {
  const cot = Number(form.cotizacion) || 0
  const filas = items.value.map(it => ({ fob: Math.round(Number(it.cantidad || 0) * Number(it.fob_unit || 0) * cot * 100) / 100 }))
  const fob = filas.reduce((a, f) => a + f.fob, 0)
  const gastosC = gastos.value.filter(g => alCosto.value[g.tipo] !== false).reduce((a, g) => a + Number(g.monto || 0), 0)
  const credito = gastos.value.filter(g => alCosto.value[g.tipo] === false).reduce((a, g) => a + Number(g.monto || 0), 0)
  filas.forEach((f, i) => { f.gastos = fob > 0 ? Math.round(gastosC * f.fob / fob * 100) / 100 : 0; const q = Number(items.value[i].cantidad || 0); f.costo_unit = q > 0 ? (f.fob + f.gastos) / q : 0 })
  return { filas, fob, gastos: gastosC, credito, costo: fob + gastosC }
})
function guardar(nacionalizar) {
  form.items = items.value; form.gastos = gastos.value; form.nacionalizar = nacionalizar
  form.post(d ? `/proveedores/importaciones/${d.id}` : '/proveedores/importaciones')
}
</script>
