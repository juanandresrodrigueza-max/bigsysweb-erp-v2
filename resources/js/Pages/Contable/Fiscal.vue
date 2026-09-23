<template>
  <AppLayout titulo="Fiscal">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Fiscal</h1><p class="page-subtitle">Retenciones, percepciones, Libro IVA Digital y cruce con ARCA. Todo listo para el contador.</p></div>
      <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" />
    </div>
    <ContableTabs />

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
      <div class="card">
        <h2 class="font-bold mb-1">Libro IVA Digital</h2>
        <p class="text-sm text-marca-muted mb-3">Archivos de comprobantes y alícuotas con el formato de ARCA (RG 4597) para importar directo en el servicio Libro de IVA Digital.</p>
        <div v-if="sucursalesCuit.length" class="mb-2"><label class="label">CUIT que presenta</label><select v-model="libroSucursal" class="input !py-1 text-xs"><option :value="null">Casa central · todo lo que no es de una sucursal con CUIT propio no se separa: elegí una sucursal</option><option v-for="s in sucursalesCuit" :key="s.id" :value="s.id">{{ s.nombre }} · CUIT {{ s.cuit }}</option></select><p class="text-[10px] text-marca-muted mt-1">Cada CUIT presenta su propio Libro IVA: elegí la sucursal para bajar solo sus comprobantes.</p></div>
        <div class="flex gap-2">
          <a :href="`/contable/fiscal/libro-digital?libro=ventas&desde=${periodo.desde}&hasta=${periodo.hasta}${libroSucursal ? '&sucursal=' + libroSucursal : ''}`" class="btn-primary !py-1.5 text-xs">Ventas (.zip)</a>
          <a :href="`/contable/fiscal/libro-digital?libro=compras&desde=${periodo.desde}&hasta=${periodo.hasta}${libroSucursal ? '&sucursal=' + libroSucursal : ''}`" class="btn-secondary !py-1.5 text-xs">Compras (.zip)</a>
        </div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-1">Aplicativos de retenciones</h2>
        <p class="text-sm text-marca-muted mb-3">Exportá las retenciones practicadas para SICORE (Ganancias e IVA) y SIRCAR (IIBB convenio), y las percepciones de IIBB para ARBA/AGIP.</p>
        <div class="flex flex-wrap gap-2">
          <a :href="exp('sicore')" class="btn-secondary !py-1.5 text-xs">SICORE</a>
          <a :href="exp('sircar')" class="btn-secondary !py-1.5 text-xs">SIRCAR</a>
          <a :href="exp('percepciones')" class="btn-secondary !py-1.5 text-xs">Percepciones IIBB</a>
        </div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-1">Cruce con Mis Comprobantes (ARCA)</h2>
        <p class="text-sm text-marca-muted mb-3">Subí el CSV de "Mis Comprobantes Emitidos" y el sistema te dice qué facturas faltan (emitidas por fuera) y las registra en cuenta corriente.</p>
        <form @submit.prevent="arca.post('/contable/fiscal/arca/analizar', { forceFormData: true, preserveScroll: true })" class="flex gap-2 items-end">
          <input type="file" accept=".csv,.txt" @change="arca.archivo = $event.target.files[0]" class="input !py-1.5 text-xs" />
          <button class="btn-primary !py-1.5 text-xs whitespace-nowrap" :disabled="arca.processing || !arca.archivo">Analizar</button>
        </form>
        <p v-if="arca.errors.archivo" class="text-carmin text-xs mt-1">{{ arca.errors.archivo }}</p>
      </div>
    </div>

    <div v-if="ultimoAnalisis" class="card mb-4 border-violeta">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
        <h2 class="font-bold">Resultado del cruce con ARCA</h2>
        <p class="text-sm text-marca-muted">{{ ultimoAnalisis.leidas }} leídos · {{ ultimoAnalisis.coinciden }} coinciden · <b class="text-carmin">{{ ultimoAnalisis.faltan.length }} faltan en el sistema</b> · {{ ultimoAnalisis.sobran.length }} están en el sistema y no en ARCA</p>
      </div>
      <div v-if="ultimoAnalisis.faltan.length" class="overflow-x-auto">
        <table class="table text-xs">
          <thead><tr><th><input type="checkbox" :checked="sel.length === ultimoAnalisis.faltan.length" @change="sel = $event.target.checked ? ultimoAnalisis.faltan.map((_, i) => i) : []" /></th><th>Fecha</th><th>Tipo</th><th>PV-Número</th><th>CUIT</th><th>Receptor</th><th class="text-right">Neto</th><th class="text-right">IVA</th><th class="text-right">Total</th><th>CAE</th></tr></thead>
          <tbody><tr v-for="(f, i) in ultimoAnalisis.faltan" :key="i"><td><input type="checkbox" :value="i" v-model="sel" /></td><td class="tabular-nums">{{ f.fecha }}</td><td>{{ f.tipo }}</td><td class="tabular-nums">{{ String(f.pv).padStart(5, '0') }}-{{ String(f.numero).padStart(8, '0') }}</td><td class="tabular-nums">{{ f.cuit }}</td><td>{{ f.nombre }}</td><td class="text-right tabular-nums">{{ moneda(f.neto) }}</td><td class="text-right tabular-nums">{{ moneda(f.iva) }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(f.total) }}</td><td class="tabular-nums text-marca-muted">{{ f.cae }}</td></tr></tbody>
        </table>
        <div class="flex justify-end mt-3"><button class="btn-primary" :disabled="!sel.length || reg.processing" @click="registrar">Registrar {{ sel.length }} en el sistema</button></div>
      </div>
      <p v-else class="text-sm text-emerald-700">Todo lo que ARCA tiene emitido está en el sistema.</p>
      <div v-if="ultimoAnalisis.sobran.length" class="mt-3 text-xs text-marca-muted">En el sistema y no en ARCA: <span v-for="s in ultimoAnalisis.sobran" :key="s.id" class="inline-block mr-2"><Link :href="`/comprobantes/${s.id}`" class="underline">{{ s.tipo }} {{ s.numero }}</Link></span>. Revisá si están emitidas con CAE.</div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
      <div class="card p-0 overflow-hidden">
        <div class="px-4 py-3 border-b border-marca-borde flex items-center justify-between"><h2 class="font-bold">Retenciones practicadas</h2><div class="flex gap-3 text-xs text-marca-muted"><span v-for="r in resumenRetenciones" :key="r.tipo">{{ r.tipo }}: <b class="tabular-nums">{{ moneda(r.monto, 0) }}</b> ({{ r.n }})</span></div></div>
        <div class="overflow-x-auto"><table class="table text-xs">
          <thead><tr><th>Fecha</th><th>Tipo</th><th>Proveedor</th><th class="text-right">Base</th><th class="text-right">%</th><th class="text-right">Monto</th><th>Certificado</th></tr></thead>
          <tbody>
            <tr v-for="r in retenciones" :key="r.id"><td class="tabular-nums">{{ r.fecha }}</td><td>{{ r.tipo }}</td><td>{{ r.proveedor }}<span class="text-marca-muted"> · OP {{ r.pago }}</span></td><td class="text-right tabular-nums">{{ moneda(r.base) }}</td><td class="text-right tabular-nums">{{ r.alicuota }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(r.monto) }}</td><td><a :href="`/contable/fiscal/retenciones/${r.id}/certificado`" target="_blank" class="underline text-violeta">{{ r.certificado || 'Certificado' }}</a></td></tr>
            <tr v-if="!retenciones.length"><td colspan="7" class="text-center text-marca-muted py-6">Sin retenciones en el período.</td></tr>
          </tbody></table></div>
      </div>
      <div class="card p-0 overflow-hidden">
        <div class="px-4 py-3 border-b border-marca-borde flex items-center justify-between"><h2 class="font-bold">Percepciones de IIBB cobradas</h2><span class="text-xs text-marca-muted">Total <b class="tabular-nums">{{ moneda(totalPercepciones, 0) }}</b></span></div>
        <div class="overflow-x-auto"><table class="table text-xs">
          <thead><tr><th>Fecha</th><th>Comprobante</th><th>Cliente</th><th>Jurisd.</th><th class="text-right">Base</th><th class="text-right">%</th><th class="text-right">Monto</th></tr></thead>
          <tbody>
            <tr v-for="p in percepciones" :key="p.id" class="cursor-pointer" @click="$inertia.visit(`/comprobantes/${p.comprobante_id}`)"><td class="tabular-nums">{{ p.fecha }}</td><td class="whitespace-nowrap">{{ p.comprobante }}</td><td>{{ p.cliente }}</td><td>{{ p.jurisdiccion }}</td><td class="text-right tabular-nums">{{ moneda(p.base) }}</td><td class="text-right tabular-nums">{{ p.alicuota }}</td><td class="text-right tabular-nums font-semibold" :class="p.monto < 0 ? 'text-carmin' : ''">{{ moneda(p.monto) }}</td></tr>
            <tr v-if="!percepciones.length"><td colspan="7" class="text-center text-marca-muted py-6">Sin percepciones en el período. Activalas en Configuración → Impuestos.</td></tr>
          </tbody></table></div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import { moneda } from '@/util/formato'
const libroSucursal = ref(null)
const props = defineProps({ periodo: Object, sucursalesCuit: { type: Array, default: () => [] }, retenciones: Array, resumenRetenciones: Array, percepciones: Array, totalPercepciones: Number, ultimoAnalisis: Object })
const exp = t => `/contable/fiscal/exportar?tipo=${t}&desde=${props.periodo.desde}&hasta=${props.periodo.hasta}`
const arca = useForm({ archivo: null })
const sel = ref([])
const reg = useForm({ filas: [] })
function registrar() { reg.filas = sel.value.map(i => props.ultimoAnalisis.faltan[i]); reg.post('/contable/fiscal/arca/registrar', { preserveScroll: true, onSuccess: () => (sel.value = []) }) }
</script>
