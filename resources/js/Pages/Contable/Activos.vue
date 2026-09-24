<template>
  <AppLayout titulo="Bienes de uso">
    <ContableTabs />
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Bienes de uso y amortizaciones</h1><p class="page-subtitle">Rodados, máquinas, muebles, equipos. Cada mes se amortizan en forma lineal y el asiento sale solo.</p></div>
      <div class="flex flex-wrap gap-2">
        <a href="/contable/activos?export=1" class="btn-secondary">Cuadro CSV</a>
        <button class="btn-violeta" :disabled="resumen.mes_amortizado" @click="am.post('/contable/activos/amortizar', { preserveScroll: true })">{{ resumen.mes_amortizado ? 'Mes amortizado ✓' : `Amortizar ${periodoLabel}` }}</button>
        <button class="btn-primary" @click="abrir()">Nuevo bien</button>
      </div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Bienes en uso</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.bienes }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Valor de origen</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.valor_origen, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Valor contable</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.residual, 0) }}</p><p class="text-xs text-marca-muted">amortizado {{ moneda(resumen.amortizado, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Amortización mensual</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.cuota_mensual, 0) }}</p></div>
    </div>
    <div class="flex gap-2 mb-3">
      <Link v-for="(l, k) in { activo: 'En uso', baja: 'Bajas', vendido: 'Vendidos' }" :key="k" :href="`/contable/activos?estado=${k}`" class="px-3 py-1.5 rounded-full text-xs font-semibold border" :class="(filtros.estado || 'activo') === k ? 'bg-violeta text-white border-violeta' : 'border-marca-borde text-marca-muted'">{{ l }}</Link>
    </div>
    <div class="card p-0 overflow-x-auto">
      <table class="table text-sm">
        <thead><tr><th>Bien</th><th>Categoría</th><th>Alta</th><th class="text-right">Valor origen</th><th class="text-right">Vida útil</th><th class="text-right">Cuota</th><th class="text-right">Amortizado</th><th class="text-right">Valor contable</th><th></th></tr></thead>
        <tbody>
          <tr v-for="a in activos" :key="a.id">
            <td><p class="font-medium">{{ a.nombre }}</p><p class="text-xs text-marca-muted">{{ a.identificacion }}<span v-if="a.sucursal"> · {{ a.sucursal }}</span><span v-if="a.estado !== 'activo'"> · {{ estados[a.estado] }} {{ a.fecha_baja }}<span v-if="a.valor_baja"> por {{ moneda(a.valor_baja, 0) }}</span></span></p></td>
            <td class="text-xs">{{ a.categoria_label }}</td><td class="tabular-nums text-xs">{{ a.fecha_alta }}</td>
            <td class="text-right tabular-nums">{{ moneda(a.valor_origen, 0) }}</td><td class="text-right tabular-nums text-xs">{{ a.meses }} / {{ a.vida_util_meses }} m</td><td class="text-right tabular-nums">{{ moneda(a.cuota, 0) }}</td>
            <td class="text-right"><div class="flex items-center justify-end gap-2"><div class="w-16 h-1.5 rounded-full bg-gris-light overflow-hidden"><div class="h-full bg-violeta" :style="{ width: a.pct + '%' }"></div></div><span class="tabular-nums text-xs">{{ a.pct }}%</span></div></td>
            <td class="text-right tabular-nums font-semibold">{{ moneda(a.residual_contable, 0) }}</td>
            <td class="text-right whitespace-nowrap"><button class="btn-ghost !px-2 text-xs" @click="abrir(a)">Editar</button><button v-if="a.estado === 'activo'" class="btn-ghost !px-2 text-xs text-carmin" @click="baja = { id: a.id, nombre: a.nombre, residual: a.residual_contable, fecha: hoyISO(), valor_venta: 0, motivo: '' }">Baja / venta</button></td>
          </tr>
          <tr v-if="!activos.length"><td colspan="9" class="text-center text-marca-muted py-8">Sin bienes en este estado.</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="modal" :titulo="f.id ? 'Editar bien' : 'Nuevo bien de uso'" ancho="max-w-2xl" @cerrar="modal = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2"><label class="label">Nombre</label><input v-model="f.nombre" class="input" placeholder="Camioneta Toyota Hilux 2022" /></div>
        <div><label class="label">Categoría</label><select v-model="f.categoria" class="input" @change="f.vida_util_meses = categorias[f.categoria].meses"><option v-for="(c, k) in categorias" :key="k" :value="k">{{ c.label }} ({{ c.meses / 12 }} años)</option></select></div>
        <div><label class="label">Patente / serie</label><input v-model="f.identificacion" class="input" /></div>
        <div><label class="label">Fecha de alta</label><input v-model="f.fecha_alta" type="date" class="input" :disabled="!!f.id" /></div>
        <div><label class="label">Valor de origen</label><input v-model.number="f.valor_origen" type="number" min="0" class="input" :disabled="!!f.id" /></div>
        <div><label class="label">Vida útil (meses)</label><input v-model.number="f.vida_util_meses" type="number" min="1" class="input" /></div>
        <div><label class="label">Valor residual (al final)</label><input v-model.number="f.valor_residual" type="number" min="0" class="input" /></div>
        <div v-if="!f.id" class="sm:col-span-2"><label class="label">Cómo entra a la contabilidad</label>
          <select v-model="f.origen" class="input"><option value="ninguno">Ya está contabilizado (o viene del saldo inicial)</option><option value="compra">Compra sin factura cargada: contra Proveedores</option><option value="reclasificar">Ya lo cargué como factura de compra: reclasificar de gasto a bienes de uso</option><option value="aporte">Aporte del dueño: contra Capital</option></select>
          <select v-if="f.origen === 'compra'" v-model="f.contact_id" class="input mt-2"><option :value="null">Proveedor…</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.name }}</option></select>
        </div>
        <div class="sm:col-span-2"><label class="label">Notas</label><input v-model="f.notas" class="input" /></div>
        <p v-if="f.valor_origen && f.vida_util_meses" class="sm:col-span-2 text-xs text-marca-muted">Cuota mensual: <b class="tabular-nums">{{ moneda((f.valor_origen - (f.valor_residual || 0)) / f.vida_util_meses) }}</b></p>
      </div>
      <template #pie><button class="btn-secondary" @click="modal = false">Cancelar</button><button class="btn-primary" :disabled="f.processing || !f.nombre || !f.valor_origen" @click="f.post(`/contable/activos/${f.id || ''}`, { preserveScroll: true, onSuccess: () => (modal = false) })">Guardar</button></template>
    </Modal>

    <Modal :abierto="!!baja" :titulo="`Baja o venta · ${baja?.nombre}`" @cerrar="baja = null">
      <template v-if="baja">
        <p class="text-sm mb-3">Valor contable hoy: <b class="tabular-nums">{{ moneda(baja.residual) }}</b>. Si lo vendés, la diferencia contra el precio es ganancia o pérdida; facturá la venta aparte.</p>
        <div class="grid grid-cols-2 gap-3"><div><label class="label">Fecha</label><input v-model="baja.fecha" type="date" class="input" /></div><div><label class="label">Precio de venta (0 = baja)</label><input v-model.number="baja.valor_venta" type="number" min="0" class="input" /></div><div class="col-span-2"><label class="label">Motivo</label><input v-model="baja.motivo" class="input" placeholder="Robo, rotura, venta…" /></div></div>
        <p class="text-xs mt-2" :class="baja.valor_venta - baja.residual >= 0 ? 'text-emerald-700' : 'text-carmin'">Resultado: {{ moneda(baja.valor_venta - baja.residual) }}</p>
      </template>
      <template #pie><button class="btn-secondary" @click="baja = null">Cancelar</button><button class="btn-danger" @click="router.post(`/contable/activos/${baja.id}/baja`, baja, { preserveScroll: true, onSuccess: () => (baja = null) })">Confirmar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, hoyISO } from '@/util/formato'
const props = defineProps({ activos: Array, categorias: Object, estados: Object, filtros: Object, resumen: Object, periodo: String, proveedores: Array })
const periodoLabel = computed(() => { const [y, m] = props.periodo.split('-'); return new Date(y, m - 1, 1).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' }) })
const am = useForm({ periodo: props.periodo })
const modal = ref(false), baja = ref(null)
const f = useForm({ id: null, nombre: '', categoria: 'rodados', identificacion: '', fecha_alta: hoyISO(), valor_origen: null, valor_residual: 0, vida_util_meses: 60, origen: 'ninguno', contact_id: null, notas: '' })
function abrir(a = null) { f.clearErrors(); Object.assign(f, { id: a?.id ?? null, nombre: a?.nombre ?? '', categoria: a?.categoria ?? 'rodados', identificacion: a?.identificacion ?? '', fecha_alta: a ? a.fecha_alta.split('/').reverse().join('-') : hoyISO(), valor_origen: a?.valor_origen ?? null, valor_residual: a?.valor_residual ?? 0, vida_util_meses: a?.vida_util_meses ?? 60, origen: 'ninguno', contact_id: null, notas: a?.notas ?? '' }); modal.value = true }
</script>
