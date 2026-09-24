<template>
  <AppLayout titulo="Previsiones">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Previsiones</h1><p class="page-subtitle">Lo que se paga o se cobra todos los meses o cada X meses: alquiler, seguros, impuestos, cuotas, abonos. Avisa antes, entra en el cash flow y se registra en un clic (o solo).</p></div>
      <div class="flex flex-wrap gap-2"><Link href="/fondos" class="btn-secondary">Fondos</Link><Link href="/contable/cashflow" class="btn-secondary">Cash flow</Link><Link href="/comprobantes/abonos" class="btn-secondary" title="Lo recurrente que facturás a clientes">Abonos a facturar</Link><button v-if="puede('fondos','crear')" @click="abrir()" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nueva previsión</button></div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Egresos por mes</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(kpis.egresos_mes, 0) }}</p><p class="text-xs text-marca-muted">promedio mensual de lo recurrente</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Ingresos por mes</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ moneda(kpis.ingresos_mes, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vencen en 7 días</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.vencen_7 ? 'text-amber-600' : ''">{{ kpis.vencen_7 }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Activas</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.activas }}</p></div>
    </div>

    <div class="card mb-4">
      <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Próximos 12 meses</p>
      <div class="flex items-end gap-1.5 h-28">
        <div v-for="m in calendario" :key="m.label" class="flex-1 flex flex-col justify-end gap-px min-w-[10px]" :title="`${m.label}: egresos ${moneda(m.egresos, 0)} · ingresos ${moneda(m.ingresos, 0)}`">
          <div class="rounded-t bg-emerald-400" :style="{ height: (maxMes ? m.ingresos / maxMes * 100 : 0) + '%' }"></div>
          <div class="rounded-b bg-carmin/80" :style="{ height: (maxMes ? m.egresos / maxMes * 100 : 0) + '%' }"></div>
        </div>
      </div>
      <div class="flex justify-between text-[10px] text-marca-muted mt-1"><span v-for="m in calendario" :key="m.label" class="flex-1 text-center truncate">{{ m.label }}</span></div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Concepto</th><th>Frecuencia</th><th class="text-right">Importe</th><th>Próximo vencimiento</th><th>Cuenta</th><th></th></tr></thead>
        <tbody>
          <tr v-for="p in items" :key="p.id" :class="{ 'opacity-50': !p.activo }">
            <td><p class="font-semibold">{{ p.descripcion }} <span class="badge !py-0" :class="p.tipo === 'ingreso' ? 'bg-emerald-50 text-emerald-700' : 'bg-carmin-light text-carmin'">{{ p.tipo === 'ingreso' ? 'ingreso' : 'gasto' }}</span><span v-if="p.registrar_auto" class="badge !py-0 bg-violeta-light text-violeta ml-1">se registra solo</span></p><p class="text-xs text-marca-muted">{{ [p.categoria, p.contacto].filter(Boolean).join(' · ') }}<span v-if="p.registradas"> · {{ p.registradas }} registrada(s), última {{ p.ultimo }}</span></p></td>
            <td class="text-sm">{{ p.frecuencia }}<p class="text-xs text-marca-muted">el día {{ p.dia }}</p></td>
            <td class="text-right tabular-nums font-semibold">{{ moneda(p.monto) }}</td>
            <td class="tabular-nums"><span v-if="p.proximo" :class="p.dias < 0 ? 'text-carmin font-semibold' : (p.dias <= p.avisar_dias ? 'text-amber-600 font-semibold' : '')">{{ p.proximo }}</span><span v-else class="text-marca-muted">terminó</span><p v-if="p.proximo" class="text-xs text-marca-muted">{{ p.dias < 0 ? `vencida hace ${-p.dias} día(s)` : (p.dias === 0 ? 'vence hoy' : `en ${p.dias} día(s)`) }}</p></td>
            <td class="text-sm text-marca-muted">{{ p.cuenta ?? 'caja por defecto' }}</td>
            <td class="text-right whitespace-nowrap">
              <button v-if="p.activo && p.proximo && puede('fondos','crear')" @click="regDe = p; reg.cuenta_fondos_id = p.cuenta_fondos_id; reg.monto = p.monto; reg.fecha = hoyISO()" class="btn-ghost !px-2 text-xs text-violeta font-semibold">Registrar</button>
              <button @click="abrir(p)" class="btn-ghost !px-2 text-xs">Editar</button>
              <button v-if="puede('fondos','editar')" @click="eliminar(p)" class="btn-ghost !px-2 text-xs text-carmin">Eliminar</button>
            </td>
          </tr>
          <tr v-if="!items.length"><td colspan="6" class="text-center text-marca-muted py-10">Todavía no hay previsiones. Cargá el alquiler, los seguros, el monotributo… y el sistema te avisa antes de cada vencimiento.</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="modal" :titulo="form.id ? 'Editar previsión' : 'Nueva previsión'" @cerrar="modal = false">
      <form @submit.prevent="guardar" class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2 flex gap-2"><button type="button" class="flex-1 rounded-xl border px-3 py-2 text-sm font-semibold" :class="form.tipo === 'egreso' ? 'border-carmin bg-carmin-light text-carmin' : 'border-marca-borde'" @click="form.tipo = 'egreso'">Gasto que pago</button><button type="button" class="flex-1 rounded-xl border px-3 py-2 text-sm font-semibold" :class="form.tipo === 'ingreso' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-marca-borde'" @click="form.tipo = 'ingreso'">Ingreso que cobro</button></div>
        <div class="sm:col-span-2"><label class="label">Concepto</label><input v-model="form.descripcion" class="input" placeholder="Alquiler del local, Seguro del auto, Monotributo…" /><p v-if="form.errors.descripcion" class="text-carmin text-xs mt-1">{{ form.errors.descripcion }}</p></div>
        <div><label class="label">Importe</label><input v-model.number="form.monto" type="number" step="any" min="0" class="input text-right tabular-nums" /><p v-if="form.errors.monto" class="text-carmin text-xs mt-1">{{ form.errors.monto }}</p></div>
        <div><label class="label">Cada cuánto</label><select v-model.number="form.cada_meses" class="input"><option v-for="(l, n) in frecuencias" :key="n" :value="Number(n)">{{ l }}</option></select></div>
        <div><label class="label">Día del mes</label><input v-model.number="form.dia" type="number" min="1" max="31" class="input" /></div>
        <div><label class="label">Avisar días antes</label><input v-model.number="form.avisar_dias" type="number" min="0" max="60" class="input" /></div>
        <div><label class="label">Desde</label><input v-model="form.desde" type="date" class="input" /></div>
        <div><label class="label">Hasta (vacío = sin fin)</label><input v-model="form.hasta" type="date" class="input" /><p v-if="form.errors.hasta" class="text-carmin text-xs mt-1">{{ form.errors.hasta }}</p></div>
        <div v-if="form.tipo === 'egreso'"><label class="label">Categoría de gasto</label><select v-model="form.expense_category_id" class="input"><option :value="null">Sin categoría</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div><label class="label">{{ form.tipo === 'egreso' ? 'Proveedor (opcional)' : 'Cliente (opcional)' }}</label><select v-model="form.contact_id" class="input"><option :value="null">—</option><option v-for="c in contactos.filter(x => form.tipo === 'egreso' ? x.type !== 'customer' : x.type !== 'supplier')" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div><label class="label">Cuenta donde impacta</label><select v-model="form.cuenta_fondos_id" class="input"><option :value="null">Caja por defecto</option><option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div>
        <label class="sm:col-span-2 flex items-center gap-2 text-sm"><input v-model="form.registrar_auto" type="checkbox" class="accent-carmin" /> Registrar solo el día del vencimiento (si no, te avisa y lo registrás con un clic)</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.activo" type="checkbox" class="accent-carmin" /> Activa</label>
        <div class="sm:col-span-2"><label class="label">Notas</label><input v-model="form.notas" class="input" /></div>
      </form>
      <template #pie><button class="btn-secondary" @click="modal = false">Cancelar</button><button class="btn-primary" :disabled="form.processing" @click="guardar">Guardar</button></template>
    </Modal>

    <Modal :abierto="!!regDe" :titulo="`Registrar ${regDe?.descripcion ?? ''}`" @cerrar="regDe = null">
      <p class="text-sm text-marca-muted mb-3">Se registra como {{ regDe?.tipo === 'ingreso' ? 'ingreso' : 'gasto' }} en la cuenta elegida y la previsión pasa al vencimiento siguiente.</p>
      <div class="grid sm:grid-cols-3 gap-3">
        <div><label class="label">Fecha</label><input v-model="reg.fecha" type="date" class="input" /></div>
        <div><label class="label">Importe</label><input v-model.number="reg.monto" type="number" step="any" class="input text-right tabular-nums" /></div>
        <div><label class="label">Cuenta</label><select v-model="reg.cuenta_fondos_id" class="input"><option :value="null">Caja por defecto</option><option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></div>
      </div>
      <template #pie><button class="btn-secondary" @click="regDe = null">Cancelar</button><button class="btn-primary" :disabled="reg.processing" @click="reg.post(`/fondos/previsiones/${regDe.id}/registrar`, { preserveScroll: true, onSuccess: () => (regDe = null) })">Registrar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
import { moneda, hoyISO } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ items: Array, kpis: Object, calendario: Array, frecuencias: Object, categorias: Array, cuentas: Array, contactos: Array })
const { puede } = usePermisos()
const maxMes = computed(() => Math.max(0, ...props.calendario.map(m => Math.max(m.egresos, m.ingresos))))
const modal = ref(false)
const vacio = { id: null, tipo: 'egreso', descripcion: '', monto: null, cada_meses: 1, dia: 10, avisar_dias: 3, desde: hoyISO().slice(0, 8) + '01', hasta: '', expense_category_id: null, contact_id: null, cuenta_fondos_id: null, registrar_auto: false, activo: true, notas: '' }
const form = useForm({ ...vacio })
function abrir(p) { form.clearErrors(); Object.assign(form, p ? { ...vacio, ...p, hasta: p.hasta ?? '', notas: p.notas ?? '' } : { ...vacio }); modal.value = true }
function guardar() { form.post(`/fondos/previsiones${form.id ? '/' + form.id : ''}`, { preserveScroll: true, onSuccess: () => (modal.value = false) }) }
function eliminar(p) { if (window.confirm(`¿Eliminar la previsión "${p.descripcion}"? Los movimientos ya registrados quedan.`)) router.delete(`/fondos/previsiones/${p.id}`, { preserveScroll: true }) }
const regDe = ref(null)
const reg = useForm({ fecha: hoyISO(), monto: null, cuenta_fondos_id: null })
</script>
