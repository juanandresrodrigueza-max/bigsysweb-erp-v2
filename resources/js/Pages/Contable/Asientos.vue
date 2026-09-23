<template>
  <AppLayout titulo="Asientos">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Asientos</h1><p class="page-subtitle">Libro diario. Los automáticos llevan al comprobante que los generó.</p></div>
      <PeriodoSelector :desde="periodo.desde" :hasta="periodo.hasta" :extra="filtros" />
    </div>
    <ContableTabs />

    <div class="card mb-4 flex flex-wrap gap-2 items-center">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input flex-1 min-w-[200px]" placeholder="Concepto…" />
      <select v-model="f.origen" @change="filtrar" class="input w-auto"><option value="">Todos los orígenes</option><option v-for="(l, k) in origenes" :key="k" :value="k">{{ l }}</option></select>
      <select v-model="f.estado" @change="filtrar" class="input w-auto"><option value="">Confirmados</option><option value="anulado">Anulados</option></select>
      <button v-if="puede('contable','crear')" @click="manualAbierto = true" class="btn-primary ml-auto"><Icono nombre="plus" clase="w-4 h-4" /> Asiento manual</button>
    </div>

    <div class="space-y-2">
      <div v-for="a in lista.data" :key="a.id" class="card p-0 overflow-hidden" :class="a.estado === 'anulado' ? 'opacity-60' : ''">
        <button @click="abierto = abierto === a.id ? null : a.id" class="w-full text-left flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-marca-fondo">
          <span class="font-bold tabular-nums text-sm w-24">{{ a.numero }}</span><span class="text-marca-muted tabular-nums text-sm w-24">{{ a.fecha }}</span>
          <span class="flex-1 text-sm font-medium truncate">{{ a.concepto }}</span>
          <span class="badge" :class="{ venta: 'bg-emerald-50 text-emerald-700', compra: 'bg-lavanda-light text-violeta', cobro: 'bg-emerald-50 text-emerald-700', pago: 'bg-carmin-light text-carmin', fondos: 'bg-amber-50 text-amber-700', manual: 'bg-gris-light text-marca-muted' }[a.origen]">{{ a.origen_label }}</span>
          <span class="font-semibold tabular-nums text-sm w-32 text-right">{{ moneda(a.total) }}</span>
          <Icono nombre="chevron" clase="w-4 h-4 text-marca-muted transition" :class="abierto === a.id ? 'rotate-180' : ''" />
        </button>
        <div v-if="abierto === a.id" class="border-t border-marca-borde bg-marca-fondo/40 px-4 py-3">
          <table class="table text-sm"><thead><tr><th>Cuenta</th><th>Detalle</th><th class="text-right">Debe</th><th class="text-right">Haber</th></tr></thead>
            <tbody><tr v-for="(l, i) in a.lineas" :key="i"><td :class="l.haber ? 'pl-8' : 'font-medium'"><Link :href="`/contable/mayor?cuenta=${l.cuenta_id}`" class="hover:text-carmin">{{ l.cuenta }}</Link></td><td class="text-marca-muted">{{ l.detalle }}</td><td class="text-right tabular-nums">{{ l.debe ? moneda(l.debe) : '' }}</td><td class="text-right tabular-nums">{{ l.haber ? moneda(l.haber) : '' }}</td></tr></tbody></table>
          <div class="flex items-center justify-between mt-2 text-xs text-marca-muted"><span>{{ a.usuario ?? 'Sistema' }}</span><div class="flex gap-3"><Link v-if="a.url" :href="a.url" class="font-semibold text-carmin">Ver origen</Link><Link v-if="a.origen === 'manual' && a.estado === 'confirmado' && puede('contable','anular')" :href="`/contable/asientos/${a.id}/anular`" method="post" as="button" preserve-scroll class="text-carmin">Anular</Link></div></div>
        </div>
      </div>
      <div v-if="!lista.data.length" class="card text-center text-marca-muted py-10">Sin asientos en este período.</div>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />

    <Modal :abierto="manualAbierto" titulo="Asiento manual" ancho="max-w-3xl" @cerrar="manualAbierto = false">
      <div class="grid sm:grid-cols-3 gap-3 mb-3">
        <div><label class="label">Fecha</label><input v-model="form.fecha" type="date" class="input" /></div>
        <div class="sm:col-span-2"><label class="label">Concepto</label><input v-model="form.concepto" class="input" placeholder="Ej: Aporte de capital, amortización, ajuste…" /><p v-if="form.errors.concepto" class="text-carmin text-xs mt-1">{{ form.errors.concepto }}</p></div>
      </div>
      <div v-for="(l, i) in form.lineas" :key="i" class="grid grid-cols-[1fr_120px_120px_28px] gap-2 mb-2">
        <BuscadorSelect v-model="l.cuenta_id" :opciones="opcionesCuentas" placeholder="Cuenta…" />
        <input v-model.number="l.debe" type="number" step="any" min="0" class="input text-right" placeholder="Debe" />
        <input v-model.number="l.haber" type="number" step="any" min="0" class="input text-right" placeholder="Haber" />
        <button @click="form.lineas.splice(i, 1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
      </div>
      <div class="flex items-center justify-between"><button @click="form.lineas.push({ cuenta_id: null, debe: null, haber: null })" class="btn-ghost !px-2 text-xs">+ Línea</button><p class="text-sm tabular-nums" :class="Math.abs(tDebe - tHaber) < 0.005 ? 'text-emerald-700' : 'text-carmin'">Debe {{ moneda(tDebe) }} · Haber {{ moneda(tHaber) }}{{ Math.abs(tDebe - tHaber) < 0.005 ? ' ✓' : ' · no balancea' }}</p></div>
      <p v-if="form.errors.lineas" class="text-carmin text-xs mt-1">{{ form.errors.lineas }}</p>
      <template #pie><button class="btn-secondary" @click="manualAbierto = false">Cancelar</button><button class="btn-primary" :disabled="form.processing || Math.abs(tDebe - tHaber) > 0.005 || !tDebe" @click="form.post('/contable/asientos', { preserveScroll: true, onSuccess: () => { manualAbierto = false; form.reset() } })">Registrar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import PeriodoSelector from '@/Components/PeriodoSelector.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
import Paginacion from '@/Components/Paginacion.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import { moneda, hoyISO } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ lista: Object, periodo: Object, filtros: Object, origenes: Object, cuentas: Array })
const { puede } = usePermisos()
const f = reactive({ buscar: props.filtros.buscar ?? '', origen: props.filtros.origen ?? '', estado: props.filtros.estado ?? '' })
function filtrar() { router.get('/contable/asientos', { ...props.periodo, ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)) }, { preserveState: true, replace: true }) }
const abierto = ref(null), manualAbierto = ref(false)
const form = useForm({ fecha: hoyISO(), concepto: '', lineas: [{ cuenta_id: null, debe: null, haber: null }, { cuenta_id: null, debe: null, haber: null }] })
const opcionesCuentas = computed(() => props.cuentas.map(c => ({ id: c.id, label: c.nombre, sub: c.codigo })))
const tDebe = computed(() => form.lineas.reduce((a, l) => a + (Number(l.debe) || 0), 0))
const tHaber = computed(() => form.lineas.reduce((a, l) => a + (Number(l.haber) || 0), 0))
</script>
