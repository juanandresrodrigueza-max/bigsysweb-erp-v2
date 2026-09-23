<template>
  <AppLayout titulo="Conciliación bancaria">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Conciliación bancaria</h1><p class="page-subtitle">Subís el extracto del home banking y se cruza solo con los movimientos del sistema. Lo que no coincide, lo resolvés acá.</p></div>
      <div class="flex gap-2">
        <select :value="cuentaId" @change="$inertia.get('/contable/conciliacion', { cuenta: $event.target.value })" class="input w-auto"><option v-for="b in bancos" :key="b.id" :value="b.id">{{ b.nombre }}</option></select>
        <button v-if="puede('contable','crear')" @click="impAbierto = true" class="btn-primary">Importar extracto</button>
      </div>
    </div>
    <ContableTabs />

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Saldo según sistema</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.saldo_sistema, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Saldo según banco</p><p class="text-xl font-extrabold tabular-nums">{{ resumen.saldo_extracto !== null ? moneda(resumen.saldo_extracto, 0) : '—' }}</p><p v-if="resumen.ultimo_extracto" class="text-[11px] text-marca-muted">extracto {{ resumen.ultimo_extracto.desde }}–{{ resumen.ultimo_extracto.hasta }} · {{ resumen.ultimo_extracto.items }} renglones</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En el banco, no en el sistema</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.pendientes_extracto ? 'text-amber-600' : 'text-emerald-700'">{{ resumen.pendientes_extracto }}</p><p class="text-[11px] text-marca-muted">{{ moneda(resumen.monto_pend_extracto, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En el sistema, no en el banco</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.pendientes_sistema ? 'text-amber-600' : 'text-emerald-700'">{{ resumen.pendientes_sistema }}</p><p class="text-[11px] text-marca-muted">{{ moneda(resumen.monto_pend_sistema, 0) }}</p></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 card p-0 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-marca-borde">
          <h2 class="font-bold">Extracto del banco</h2>
          <div class="flex gap-1 bg-marca-fondo rounded-full p-1">
            <button v-for="e in [['pendiente','Pendientes'],['conciliado','Conciliados'],['ignorado','Ignorados'],['todos','Todos']]" :key="e[0]" @click="$inertia.get('/contable/conciliacion', { cuenta: cuentaId, estado: e[0] }, { preserveState: true, replace: true })" class="px-3 py-1 rounded-full text-xs font-semibold" :class="estado === e[0] ? 'bg-white shadow text-carmin' : 'text-marca-muted'">{{ e[1] }}</button>
          </div>
          <Link v-if="puede('contable','crear')" :href="`/contable/conciliacion/automatica?cuenta=${cuentaId}`" method="post" as="button" preserve-scroll class="btn-secondary !py-1 text-xs">Conciliar automáticamente</Link>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead><tr><th>Fecha</th><th>Descripción</th><th class="text-right">Importe</th><th>Estado</th><th></th></tr></thead>
            <tbody>
              <tr v-for="it in items" :key="it.id" :class="it.estado === 'ignorado' ? 'opacity-50' : ''">
                <td class="tabular-nums text-marca-muted whitespace-nowrap">{{ it.fecha }}</td>
                <td><p class="font-medium text-sm">{{ it.descripcion }}</p><p v-if="it.referencia" class="text-xs text-marca-muted">{{ it.referencia }}</p><p v-if="it.movimiento" class="text-xs text-emerald-700">↔ {{ it.movimiento.fecha }} · {{ it.movimiento.concepto }}</p></td>
                <td class="text-right tabular-nums font-semibold" :class="it.monto < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(it.monto) }}</td>
                <td><span class="badge" :class="{ pendiente: 'bg-amber-50 text-amber-700', conciliado: 'bg-emerald-50 text-emerald-700', ignorado: 'bg-gris-light text-marca-muted' }[it.estado]">{{ it.estado }}<span v-if="it.match === 'auto'"> · auto</span></span></td>
                <td class="text-right whitespace-nowrap">
                  <template v-if="puede('contable','crear')">
                    <template v-if="it.estado === 'pendiente'">
                      <button @click="vincular = it" class="btn-ghost !px-2 text-xs">Vincular</button>
                      <button @click="registrar = { ...it, expense_category_id: null, concepto: it.descripcion }" class="btn-ghost !px-2 text-xs text-violeta">Registrar</button>
                      <Link :href="`/contable/conciliacion/${it.id}/ignorar`" method="post" as="button" preserve-scroll class="btn-ghost !px-2 text-xs">Ignorar</Link>
                    </template>
                    <Link v-else-if="it.estado === 'conciliado'" :href="`/contable/conciliacion/${it.id}/desvincular`" method="post" as="button" preserve-scroll class="btn-ghost !px-2 text-xs text-carmin">Deshacer</Link>
                    <Link v-else :href="`/contable/conciliacion/${it.id}/ignorar`" method="post" as="button" preserve-scroll class="btn-ghost !px-2 text-xs">Reactivar</Link>
                  </template>
                </td>
              </tr>
              <tr v-if="!items.length"><td colspan="5" class="text-center text-marca-muted py-10">{{ estado === 'pendiente' ? 'Nada pendiente. Todo lo del banco está en el sistema.' : 'Sin renglones.' }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card p-0 overflow-hidden">
        <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Sistema sin conciliar</h2><p class="text-xs text-marca-muted">Movimientos que todavía no aparecen en el extracto.</p></div>
        <div class="max-h-[60vh] overflow-y-auto">
          <div v-for="m in sinConciliar" :key="m.id" class="px-4 py-2 border-b border-marca-borde/60 text-sm flex justify-between gap-2"><div class="min-w-0"><p class="font-medium truncate">{{ m.concepto }}</p><p class="text-xs text-marca-muted">{{ m.fecha }}<span v-if="m.referencia"> · {{ m.referencia }}</span> · {{ m.origen }}</p></div><span class="tabular-nums font-semibold shrink-0" :class="m.monto < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(m.monto, 0) }}</span></div>
          <p v-if="!sinConciliar.length" class="px-4 py-8 text-center text-sm text-marca-muted">Todo conciliado.</p>
        </div>
      </div>
    </div>

    <Modal :abierto="impAbierto" titulo="Importar extracto bancario" @cerrar="impAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Exportá los movimientos del home banking en CSV (o Excel guardado como CSV). Reconoce columnas <b>Fecha</b>, <b>Concepto</b>, <b>Importe</b> (o Débito / Crédito) y <b>Saldo</b>. No duplica lo ya importado.</p>
      <select v-model="imp.cuenta_fondos_id" class="input mb-3"><option v-for="b in bancos" :key="b.id" :value="b.id">{{ b.nombre }}</option></select>
      <input type="file" accept=".csv,.txt" class="input !py-1.5 text-xs" @change="imp.archivo = $event.target.files[0]" />
      <p v-if="imp.errors.archivo" class="text-carmin text-xs mt-2">{{ imp.errors.archivo }}</p>
      <template #pie><button class="btn-secondary" @click="impAbierto = false">Cancelar</button><button class="btn-primary" :disabled="imp.processing || !imp.archivo" @click="imp.post('/contable/conciliacion/importar', { forceFormData: true, onSuccess: () => (impAbierto = false) })">{{ imp.processing ? 'Importando…' : 'Importar y conciliar' }}</button></template>
    </Modal>

    <Modal :abierto="!!vincular" :titulo="vincular ? `Vincular: ${vincular.descripcion} (${moneda(vincular.monto)})` : ''" @cerrar="vincular = null">
      <p class="text-sm text-marca-muted mb-2">Elegí el movimiento del sistema que corresponde. Tiene que ser del mismo importe.</p>
      <div class="max-h-72 overflow-y-auto divide-y divide-marca-borde/60 border border-marca-borde rounded-xl">
        <button v-for="m in candidatos" :key="m.id" @click="router.post(`/contable/conciliacion/${vincular.id}/vincular`, { movimiento_id: m.id }, { preserveScroll: true, onSuccess: () => (vincular = null) })" class="w-full text-left px-3 py-2 text-sm hover:bg-marca-fondo flex justify-between gap-2"><span>{{ m.fecha }} · {{ m.concepto }}</span><b class="tabular-nums">{{ moneda(m.monto) }}</b></button>
        <p v-if="!candidatos.length" class="px-3 py-6 text-center text-sm text-marca-muted">No hay movimientos del sistema por ese importe. Si es un cargo del banco (comisión, impuesto), usá "Registrar".</p>
      </div>
    </Modal>

    <Modal :abierto="!!registrar" titulo="Registrar en Fondos" @cerrar="registrar = null">
      <template v-if="registrar">
        <p class="text-sm text-marca-muted mb-3">El banco {{ registrar.monto < 0 ? 'debitó' : 'acreditó' }} <b>{{ moneda(registrar.monto) }}</b> el {{ registrar.fecha }} y el sistema no lo tenía. Se crea el movimiento y queda conciliado.</p>
        <label class="label">Concepto</label><input v-model="registrar.concepto" class="input" />
        <template v-if="registrar.monto < 0"><label class="label mt-3">Categoría de gasto</label><select v-model="registrar.expense_category_id" class="input"><option :value="null">Sin categoría</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.name }}</option></select></template>
      </template>
      <template #pie><button class="btn-secondary" @click="registrar = null">Cancelar</button><button class="btn-primary" @click="router.post(`/contable/conciliacion/${registrar.id}/registrar`, { expense_category_id: registrar.expense_category_id, concepto: registrar.concepto }, { preserveScroll: true, onSuccess: () => (registrar = null) })">Registrar</button></template>
    </Modal>
  </AppLayout>
</template>
<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ContableTabs from '@/Components/ContableTabs.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ bancos: Array, cuentaId: Number, estado: String, items: Array, sinConciliar: Array, resumen: Object, categorias: Array })
const { puede } = usePermisos()
const impAbierto = ref(false), vincular = ref(null), registrar = ref(null)
const imp = useForm({ cuenta_fondos_id: props.cuentaId, archivo: null })
const candidatos = computed(() => vincular.value ? props.sinConciliar.filter(m => Math.abs(m.monto - vincular.value.monto) < 0.01) : [])
</script>
