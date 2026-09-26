<template>
  <AppLayout titulo="Carnicería y pesables">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <Link href="/stock" class="text-xs text-marca-muted hover:text-carmin">← Stock</Link>
        <h1 class="page-title">Carnicería y pesables</h1>
        <p class="page-subtitle">Despiece de media res, cerdo o pollo con el rinde real de cada corte y su costo por kilo, y los artículos que se venden por peso con su código PLU para la balanza.</p>
      </div>
      <div class="flex gap-2 items-end">
        <div><label class="label">Lista para la balanza</label><select v-model="listaPlu" class="input"><option v-for="n in 6" :key="n" :value="n">Lista {{ n }}</option></select></div>
        <a :href="`/stock/despiece/plu.csv?lista=${listaPlu}`" class="btn-secondary" data-e2e="exportar-plu">Exportar PLU a la balanza</a>
      </div>
    </div>

    <div class="flex gap-1 mb-4">
      <button v-for="t in [{ k: 'despiece', l: 'Despiece' }, { k: 'plantillas', l: 'Plantillas' }, { k: 'pesables', l: 'Pesables y PLU' }]" :key="t.k" class="px-3 py-1.5 rounded-full text-xs font-semibold" :class="tab === t.k ? 'bg-carmin text-white' : 'text-marca-muted hover:bg-gris-light'" :data-e2e="`tab-${t.k}`" @click="tab = t.k">{{ t.l }}</button>
    </div>

    <!-- Despiece -->
    <div v-if="tab === 'despiece'" class="grid lg:grid-cols-[1fr_1fr] gap-4">
      <div class="card" data-e2e="form-despiece">
        <p v-if="!plantillas.length" class="text-sm text-marca-muted">Primero armá una plantilla (media res, cuarto trasero…) con sus cortes en la pestaña Plantillas.</p>
        <template v-else>
          <div class="grid grid-cols-2 gap-2 mb-3">
            <div class="col-span-2"><label class="label">Plantilla</label><select v-model="dz.plantilla" class="input" @change="prepararCortes"><option v-for="p in plantillas.filter(x => x.activo)" :key="p.id" :value="p.id">{{ p.nombre }} · {{ p.materia }}</option></select></div>
            <div><label class="label">Kilos que entran</label><input v-model.number="dz.kg_entrada" type="number" step="0.001" min="0" class="input" data-e2e="kg-entrada" /></div>
            <div><label class="label">Costo por kg</label><input v-model.number="dz.costo_kg" type="number" step="0.01" min="0" class="input" :placeholder="plantilla ? String(plantilla.costo_kg) : ''" /></div>
          </div>
          <table class="table text-sm">
            <thead><tr><th>Corte</th><th class="text-right">Esperado</th><th class="text-right">Kilos</th><th class="text-right">Rinde</th></tr></thead>
            <tbody>
              <tr v-for="c in plantilla?.cortes ?? []" :key="c.product_id">
                <td>{{ c.nombre }}</td>
                <td class="text-right tabular-nums text-marca-muted text-xs">{{ c.rinde ? c.rinde + ' %' : '—' }}<span v-if="c.rinde && dz.kg_entrada" class="block">{{ cantidad(dz.kg_entrada * c.rinde / 100) }} kg</span></td>
                <td><input v-model.number="dz.cortes[c.product_id]" type="number" step="0.001" min="0" class="input !py-1 !w-24 text-right ml-auto" :data-e2e="`corte-${c.product_id}`" /></td>
                <td class="text-right tabular-nums text-xs" :class="rindeClase(c)">{{ dz.kg_entrada && dz.cortes[c.product_id] ? (dz.cortes[c.product_id] / dz.kg_entrada * 100).toFixed(1) + ' %' : '' }}</td>
              </tr>
            </tbody>
            <tfoot><tr class="font-semibold"><td>Merma</td><td></td><td class="text-right tabular-nums" :class="merma < 0 ? 'text-carmin' : ''">{{ cantidad(merma) }} kg</td><td class="text-right tabular-nums text-xs">{{ dz.kg_entrada ? (merma / dz.kg_entrada * 100).toFixed(1) + ' %' : '' }}</td></tr></tfoot>
          </table>
          <label class="flex items-center gap-2 text-xs mt-2"><input v-model="dz.actualizar_costos" type="checkbox" class="accent-carmin" /> Actualizar el costo de cada corte con el de este despiece</label>
          <p v-if="Object.keys(dz.errors).length" class="text-carmin text-xs mt-2">{{ Object.values(dz.errors).join(' ') }}</p>
          <button class="btn-primary mt-3" :disabled="dz.processing || !dz.kg_entrada || merma < 0" data-e2e="registrar-despiece" @click="registrar">Registrar despiece</button>
        </template>
      </div>
      <div class="space-y-3">
        <div v-for="o in operaciones" :key="o.id" class="card" data-e2e="operacion">
          <div class="flex flex-wrap justify-between gap-2 mb-2"><div><p class="font-bold">{{ o.plantilla }} · {{ cantidad(o.kg_entrada) }} kg</p><p class="text-xs text-marca-muted">{{ o.fecha }} · merma {{ cantidad(o.merma_kg) }} kg ({{ o.merma_pct }} %)</p></div>
            <div class="text-right text-xs"><p>Costo {{ moneda(o.costo_total, 0) }}</p><p>Venta {{ moneda(o.venta_total, 0) }}</p><p v-if="o.margen_pct !== null" class="font-bold" :class="o.margen_pct >= 0 ? 'text-emerald-700' : 'text-carmin'">Margen {{ o.margen_pct }} %</p></div></div>
          <table class="table text-xs"><thead><tr><th>Corte</th><th class="text-right">Kg</th><th class="text-right">Rinde</th><th class="text-right">Costo/kg</th><th class="text-right">Precio</th><th class="text-right">Margen</th></tr></thead><tbody>
            <tr v-for="i in o.items" :key="i.producto"><td>{{ i.producto }}</td><td class="text-right tabular-nums">{{ cantidad(i.kg) }}</td><td class="text-right tabular-nums" :class="i.rinde_esperado && i.rinde_real < i.rinde_esperado - 1 ? 'text-carmin' : ''">{{ i.rinde_real }} %<span v-if="i.rinde_esperado" class="text-marca-muted"> / {{ i.rinde_esperado }}</span></td><td class="text-right tabular-nums">{{ moneda(i.costo_kg) }}</td><td class="text-right tabular-nums">{{ moneda(i.precio_venta) }}</td><td class="text-right tabular-nums">{{ i.margen_pct !== null ? i.margen_pct + ' %' : '—' }}</td></tr>
          </tbody></table>
        </div>
        <p v-if="!operaciones.length" class="card text-sm text-marca-muted">Todavía no hay despieces registrados.</p>
      </div>
    </div>

    <!-- Plantillas -->
    <div v-if="tab === 'plantillas'" class="grid lg:grid-cols-[1fr_22rem] gap-4">
      <div class="space-y-3">
        <div v-for="p in plantillas" :key="p.id" class="card text-sm">
          <div class="flex justify-between"><p class="font-bold">{{ p.nombre }} <span class="text-xs text-marca-muted font-normal">· {{ p.materia }}</span></p><button class="text-xs text-violeta" @click="editarPlantilla(p)">editar</button></div>
          <p class="text-xs text-marca-muted mt-1">{{ p.cortes.map(c => `${c.nombre}${c.rinde ? ' ' + c.rinde + '%' : ''}`).join(' · ') }}</p>
        </div>
        <p v-if="!plantillas.length" class="card text-sm text-marca-muted">Sin plantillas. Cargá la media res con sus cortes (asado, vacío, nalga, cuadril, bola de lomo, carne picada, hueso…) y el rinde que esperás de cada uno.</p>
      </div>
      <div class="card text-sm" data-e2e="form-plantilla">
        <p class="font-bold mb-2">{{ pl.id ? 'Editar plantilla' : 'Nueva plantilla' }}</p>
        <label class="label">Nombre</label><input v-model="pl.nombre" class="input !py-1.5 mb-2" placeholder="Media res novillo" />
        <label class="label">Materia prima (lo que se despieza)</label><select v-model="pl.product_id" class="input !py-1.5 mb-3"><option :value="null">Elegí…</option><option v-for="p in productos" :key="p.id" :value="p.id">{{ p.name }}</option></select>
        <p class="label">Cortes y rinde esperado (%)</p>
        <div v-for="(c, i) in pl.cortes" :key="i" class="flex gap-1 mb-1"><select v-model="c.product_id" class="input !py-1 flex-1"><option :value="null">Corte…</option><option v-for="p in productos" :key="p.id" :value="p.id">{{ p.name }}</option></select><input v-model.number="c.rinde" type="number" step="0.1" min="0" max="100" class="input !py-1 !w-20 text-right" /><button class="text-carmin px-1" @click="pl.cortes.splice(i, 1)">×</button></div>
        <button class="text-xs text-violeta font-semibold mb-2" @click="pl.cortes.push({ product_id: null, rinde: null })">+ Agregar corte</button>
        <p class="text-xs text-marca-muted mb-2">Suman {{ pl.cortes.reduce((a, c) => a + (Number(c.rinde) || 0), 0).toFixed(1) }} %; el resto es merma (hueso sin valor, grasa, oreo).</p>
        <p v-if="Object.keys(pl.errors).length" class="text-carmin text-xs mb-2">{{ Object.values(pl.errors).join(' ') }}</p>
        <div class="flex gap-2"><button class="btn-primary !py-1 text-xs" :disabled="!pl.nombre || !pl.product_id || !pl.cortes.some(c => c.product_id)" @click="guardarPlantilla">Guardar</button><button v-if="pl.id" class="btn-ghost !py-1 text-xs" @click="nuevaPlantilla">Cancelar</button></div>
      </div>
    </div>

    <!-- Pesables -->
    <div v-if="tab === 'pesables'" class="card p-0 overflow-x-auto">
      <p class="px-4 py-3 text-xs text-marca-muted border-b border-marca-borde">Artículos marcados "Pesable" en su ficha. El PLU es el número que se carga en la balanza: la etiqueta que imprime (código que empieza con el prefijo de Configuración → Empresa → Punto de venta) trae ese PLU y el peso o el importe, y la caja y la factura lo leen solos.</p>
      <table class="table text-sm"><thead><tr><th>PLU</th><th>Artículo</th><th class="text-right">Precio lista 1</th><th>Unidad</th><th class="text-right">Vence</th></tr></thead><tbody>
        <tr v-for="p in pesables" :key="p.id"><td class="font-mono font-bold">{{ p.plu || '—' }}</td><td><Link :href="`/stock/${p.id}`" class="hover:text-carmin">{{ p.name }}</Link></td><td class="text-right tabular-nums">{{ moneda(p.price) }}</td><td>{{ p.unit }}</td><td class="text-right text-xs">{{ p.dias_vencimiento ? p.dias_vencimiento + ' días' : '—' }}</td></tr>
        <tr v-if="!pesables.length"><td colspan="5" class="text-center text-marca-muted py-8">Ningún artículo marcado como pesable.</td></tr>
      </tbody></table>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { moneda, cantidad } from '@/util/formato'

const props = defineProps({ plantillas: Array, operaciones: Array, productos: Array, pesables: Array, depositos: Array })
const tab = ref(props.plantillas.length ? 'despiece' : 'plantillas'), listaPlu = ref(1)
const dz = useForm({ plantilla: props.plantillas.find(x => x.activo)?.id ?? null, kg_entrada: null, costo_kg: null, cortes: {}, actualizar_costos: true })
const plantilla = computed(() => props.plantillas.find(p => p.id === dz.plantilla))
function prepararCortes() { dz.cortes = {} }
const merma = computed(() => Math.round(((Number(dz.kg_entrada) || 0) - Object.values(dz.cortes).reduce((a, k) => a + (Number(k) || 0), 0)) * 1000) / 1000)
const rindeClase = c => { const k = dz.cortes[c.product_id]; if (!k || !dz.kg_entrada || !c.rinde) return ''; const r = k / dz.kg_entrada * 100; return r < c.rinde - 1 ? 'text-carmin font-semibold' : 'text-emerald-700' }
function registrar() { dz.transform(d => ({ kg_entrada: d.kg_entrada, costo_kg: d.costo_kg || null, cortes: d.cortes, actualizar_costos: d.actualizar_costos })).post(`/stock/despiece/${dz.plantilla}/ejecutar`, { preserveScroll: true, onSuccess: () => { dz.kg_entrada = null; dz.cortes = {} } }) }
const pl = useForm({ id: null, nombre: '', product_id: null, activo: true, cortes: [{ product_id: null, rinde: null }] })
function nuevaPlantilla() { Object.assign(pl, { id: null, nombre: '', product_id: null, activo: true, cortes: [{ product_id: null, rinde: null }] }) }
function editarPlantilla(p) { Object.assign(pl, { id: p.id, nombre: p.nombre, product_id: p.product_id, activo: p.activo, cortes: p.cortes.map(c => ({ product_id: c.product_id, rinde: c.rinde })) }) }
function guardarPlantilla() { pl.transform(d => ({ ...d, cortes: d.cortes.filter(c => c.product_id) })).post(`/stock/despiece/plantillas${pl.id ? '/' + pl.id : ''}`, { preserveScroll: true, onSuccess: () => { nuevaPlantilla(); tab.value = 'despiece' } }) }
</script>
