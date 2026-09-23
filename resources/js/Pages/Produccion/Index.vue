<template>
  <AppLayout titulo="Producción">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Producción</h1><p class="page-subtitle">Órdenes que consumen insumos y cargan el producto terminado al depósito.</p></div>
      <div class="flex gap-2">
        <Link href="/produccion/formulas" class="btn-secondary">Fórmulas</Link>
        <button v-if="puede('produccion','crear')" @click="nuevaAbierta = true" class="btn-primary" :disabled="!formulas.length"><Icono nombre="plus" clase="w-4 h-4" /> Orden de producción</button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En curso</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.en_curso }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Pendientes</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.pendientes }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Atrasadas</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.atrasadas ? 'text-carmin' : ''">{{ kpis.atrasadas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Terminadas este mes</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.terminadas_mes }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Costo producido mes</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.costo_mes, 0) }}</p></div>
    </div>

    <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1 w-fit mb-4">
      <button v-for="t in [['', 'Abiertas'], ['completed', 'Terminadas'], ['cancelled', 'Canceladas']]" :key="t[0]" @click="$inertia.get('/produccion', t[0] ? { estado: t[0] } : {}, { preserveState: true, replace: true })" class="px-4 py-1.5 rounded-full text-sm font-semibold" :class="(filtros.estado ?? '') === t[0] ? 'bg-carmin text-white' : 'text-marca-muted'">{{ t[1] }}</button>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="o in ordenes.data" :key="o.id" class="card flex flex-col" :class="o.atrasada ? 'border-carmin/40' : ''">
        <div class="flex items-start justify-between gap-2">
          <div><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted tabular-nums">{{ o.numero }}<span v-if="o.programada"> · {{ o.programada }}</span></p><h2 class="font-extrabold text-lg leading-tight">{{ cantidad(o.cantidad) }} {{ o.unit }} · {{ o.producto }}</h2><p class="text-xs text-marca-muted">{{ o.formula }} · {{ o.deposito }}</p></div>
          <span class="badge shrink-0" :class="{ pending: 'bg-lavanda-light text-violeta', in_progress: 'bg-amber-50 text-amber-700', completed: 'bg-emerald-50 text-emerald-700', cancelled: 'bg-gris-light text-marca-muted' }[o.estado]">{{ o.atrasada ? 'Atrasada' : o.estado_label }}</span>
        </div>
        <div v-if="o.insumos.length" class="mt-3 text-xs space-y-0.5">
          <div v-for="i in o.insumos" :key="i.product_id" class="flex justify-between"><span :class="i.falta > 0 ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ i.nombre }}</span><span class="tabular-nums" :class="i.falta > 0 ? 'text-carmin font-semibold' : ''">{{ cantidad(i.necesario) }} {{ i.unit }}<span v-if="i.falta > 0"> · faltan {{ cantidad(i.falta) }}</span></span></div>
        </div>
        <p v-if="o.notas" class="text-xs text-marca-muted mt-2 whitespace-pre-line">{{ o.notas }}</p>
        <div class="flex items-center justify-between mt-3 pt-3 border-t border-marca-borde text-sm">
          <span class="text-marca-muted">Costo <b class="text-marca-texto tabular-nums">{{ moneda(o.costo, 0) }}</b><span v-if="o.producida !== null"> · salieron {{ cantidad(o.producida) }} {{ o.unit }}</span></span>
          <div v-if="['pending','in_progress'].includes(o.estado) && puede('produccion','editar')" class="flex gap-1">
            <Link v-if="o.estado === 'pending'" :href="`/produccion/ordenes/${o.id}/iniciar`" method="post" as="button" preserve-scroll class="btn-secondary !py-1 text-xs">Iniciar</Link>
            <button @click="terminar = { id: o.id, numero: o.numero, producida: o.cantidad, unit: o.unit, faltan: o.faltan }" class="btn-primary !py-1 text-xs">Terminar</button>
            <button v-if="puede('produccion','anular')" @click="cancelar(o)" class="btn-ghost !py-1 !px-2 text-xs text-carmin">✕</button>
          </div>
        </div>
      </div>
      <div v-if="!ordenes.data.length" class="card md:col-span-2 xl:col-span-3 text-center text-marca-muted py-12">{{ formulas.length ? 'No hay órdenes en esta vista. Creá una para empezar.' : 'Primero cargá una fórmula (qué insumos lleva cada producto) en "Fórmulas".' }}</div>
    </div>
    <Paginacion :links="ordenes.links" :desde="ordenes.from" :hasta="ordenes.to" :total="ordenes.total" />

    <Modal :abierto="nuevaAbierta" titulo="Nueva orden de producción" @cerrar="nuevaAbierta = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2"><label class="label">Fórmula</label><select v-model="nueva.recipe_id" class="input"><option v-for="f in formulas" :key="f.id" :value="f.id">{{ f.name }} → {{ f.producto }} (tanda {{ cantidad(f.yield) }} {{ f.unit }})</option></select></div>
        <div><label class="label">Cantidad a producir ({{ formulaElegida?.unit }})</label><input v-model.number="nueva.quantity" type="number" step="any" min="0" class="input" /><p v-if="nueva.errors.quantity" class="text-carmin text-xs mt-1">{{ nueva.errors.quantity }}</p></div>
        <div><label class="label">Costo estimado</label><p class="input bg-marca-fondo tabular-nums">{{ moneda((formulaElegida?.costo_unit ?? 0) * (nueva.quantity || 0)) }}</p></div>
        <div><label class="label">Depósito (insumos y producto)</label><select v-model="nueva.deposito_id" class="input"><option :value="null">El de mi sucursal</option><option v-for="d in depositos" :key="d.id" :value="d.id">{{ d.nombre }} · {{ d.sucursal }}</option></select></div>
        <div><label class="label">Programada para</label><input v-model="nueva.scheduled_at" type="date" class="input" /></div>
        <div class="sm:col-span-2"><label class="label">Notas</label><input v-model="nueva.notes" class="input" placeholder="Para qué pedido, quién la hace…" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="nuevaAbierta = false">Cancelar</button><button class="btn-primary" :disabled="nueva.processing || !nueva.quantity" @click="nueva.post('/produccion/ordenes', { preserveScroll: true, onSuccess: () => { nuevaAbierta = false; nueva.reset('quantity', 'notes') } })">Crear orden</button></template>
    </Modal>

    <Modal :abierto="!!terminar" :titulo="`Terminar ${terminar?.numero}`" @cerrar="terminar = null">
      <template v-if="terminar">
        <p v-if="terminar.faltan" class="mb-3 px-3 py-2 rounded-xl bg-carmin-light text-carmin text-sm">Faltan insumos en el depósito. Cargá una compra o ajustá el stock antes de terminar.</p>
        <label class="label">Cantidad que salió realmente ({{ terminar.unit }})</label><input v-model.number="terminar.producida" type="number" step="any" min="0" class="input" />
        <p class="text-xs text-marca-muted mt-2">Se descuentan los insumos según la fórmula, entra el producto terminado y su costo se recalcula con los costos de hoy.</p>
        <p v-if="errores.insumos" class="text-carmin text-xs mt-2">{{ errores.insumos }}</p>
      </template>
      <template #pie><button class="btn-secondary" @click="terminar = null">Cancelar</button><button class="btn-primary" :disabled="terminar?.faltan" @click="router.post(`/produccion/ordenes/${terminar.id}/terminar`, { producida: terminar.producida }, { preserveScroll: true, onSuccess: () => (terminar = null) })">Terminar y cargar stock</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda, cantidad } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ ordenes: Object, filtros: Object, estados: Object, formulas: Array, depositos: Array, kpis: Object, abierta: Object })
const { puede } = usePermisos()
const page = usePage()
const errores = computed(() => page.props.errors ?? {})
const nuevaAbierta = ref(false), terminar = ref(null)
const nueva = useForm({ recipe_id: props.formulas[0]?.id, quantity: null, deposito_id: null, scheduled_at: '', notes: '' })
const formulaElegida = computed(() => props.formulas.find(f => f.id === nueva.recipe_id))
function cancelar(o) { const motivo = window.prompt(`Motivo para cancelar ${o.numero}:`); if (motivo) router.post(`/produccion/ordenes/${o.id}/cancelar`, { motivo }, { preserveScroll: true }) }
</script>
