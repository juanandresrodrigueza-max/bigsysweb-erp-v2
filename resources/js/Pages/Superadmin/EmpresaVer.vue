<template>
  <AdminLayout :titulo="empresa.nombre">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div class="flex items-center gap-3">
        <span class="w-12 h-12 rounded-2xl grid place-items-center text-xl font-extrabold text-white" :class="empresa.dada_de_baja ? 'bg-gris' : 'bg-violeta-grad'">{{ empresa.nombre[0] }}</span>
        <div>
          <Link href="/admin/empresas" class="text-xs text-marca-muted hover:text-carmin">← Empresas</Link>
          <h1 class="page-title">{{ empresa.nombre }}</h1>
          <p class="page-subtitle">{{ empresa.vertical }} · {{ empresa.cuit ?? 'sin CUIT' }} · alta {{ empresa.alta }}</p>
          <div class="flex flex-wrap gap-1.5 mt-1.5">
            <span class="badge" :class="estadoClase[empresa.estado]">{{ empresa.estado_label }}</span>
            <span v-if="suscripcion" class="badge bg-lavanda-light text-violeta">{{ suscripcion.plan }} · {{ cicloLabel[suscripcion.ciclo] }}</span>
            <span v-if="empresa.suspension_motivo" class="badge bg-carmin-light text-carmin">{{ empresa.suspension_motivo }}</span>
          </div>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <template v-if="empresa.dada_de_baja">
          <Link :href="`/admin/empresas/${empresa.id}/restaurar`" method="post" as="button" class="btn-primary">Restaurar empresa</Link>
        </template>
        <template v-else>
          <Link :href="`/admin/empresas/${empresa.id}/entrar`" method="post" as="button" class="btn-violeta"><Icono nombre="logout" clase="w-4 h-4" /> Entrar como dueño</Link>
          <button @click="planAbierto = true" class="btn-primary">Cambiar plan / renovar</button>
          <button @click="pagoAbierto = true" class="btn-secondary">Registrar cobro</button>
          <Link v-if="empresa.suspended_at" :href="`/admin/empresas/${empresa.id}/reactivar`" method="post" as="button" class="btn-secondary text-emerald-700">Reactivar</Link>
          <button v-else @click="suspAbierto = true" class="btn-secondary">Suspender</button>
          <button @click="bajaAbierto = true" class="btn-danger">Dar de baja</button>
        </template>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vence</p><p class="text-xl font-extrabold tabular-nums" :class="suscripcion?.dias !== null && suscripcion?.dias <= 3 ? 'text-carmin' : ''">{{ empresa.vence ?? '—' }}</p><p v-if="suscripcion?.dias !== null && suscripcion" class="text-xs text-marca-muted">{{ suscripcion.dias < 0 ? `hace ${-suscripcion.dias} días` : suscripcion.dias === 0 ? 'hoy' : `en ${suscripcion.dias} días` }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Paga por mes</p><p class="text-xl font-extrabold tabular-nums">{{ suscripcion ? moneda(suscripcion.ciclo === 'yearly' ? suscripcion.monto / 12 : suscripcion.monto, 0) : '—' }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Usuarios</p><p class="text-xl font-extrabold tabular-nums">{{ uso.usuarios }}</p><p class="text-xs text-marca-muted">{{ empresa.sucursales.length }} sucursal{{ empresa.sucursales.length === 1 ? '' : 'es' }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Comprobantes este mes</p><p class="text-xl font-extrabold tabular-nums">{{ uso.comprobantes_mes }}</p><p class="text-xs text-marca-muted">{{ uso.comprobantes_total }} en total · último acceso {{ uso.ultimo_login ? new Date(uso.ultimo_login).toLocaleDateString('es-AR') : 'nunca' }}</p></div>
    </div>

    <div class="flex gap-1 bg-white border border-marca-borde rounded-full p-1 w-fit mb-4 overflow-x-auto max-w-full">
      <button v-for="t in tabs" :key="t.key" @click="tab = t.key" class="px-4 py-1.5 rounded-full text-sm font-semibold whitespace-nowrap" :class="tab === t.key ? 'bg-carmin text-white' : 'text-marca-muted hover:text-marca-texto'">{{ t.label }}<span v-if="t.n" class="ml-1 opacity-70">({{ t.n }})</span></button>
    </div>

    <!-- Suscripción -->
    <div v-if="tab === 'suscripcion'" class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2">
        <h2 class="font-bold mb-3">Suscripción</h2>
        <template v-if="suscripcion">
          <dl class="grid sm:grid-cols-2 gap-x-8 gap-y-2 text-sm">
            <div class="flex justify-between border-b border-marca-borde/60 py-1.5"><dt class="text-marca-muted">Plan</dt><dd class="font-semibold">{{ suscripcion.plan }} ({{ cicloLabel[suscripcion.ciclo] }})</dd></div>
            <div class="flex justify-between border-b border-marca-borde/60 py-1.5"><dt class="text-marca-muted">Estado</dt><dd><span class="badge" :class="estadoClase[suscripcion.estado]">{{ suscripcion.estado_label }}</span></dd></div>
            <div class="flex justify-between border-b border-marca-borde/60 py-1.5"><dt class="text-marca-muted">Inicio</dt><dd>{{ suscripcion.inicio }}</dd></div>
            <div class="flex justify-between border-b border-marca-borde/60 py-1.5"><dt class="text-marca-muted">Vence</dt><dd class="font-semibold">{{ suscripcion.vence ?? '—' }}</dd></div>
            <div v-if="suscripcion.prueba_hasta" class="flex justify-between border-b border-marca-borde/60 py-1.5"><dt class="text-marca-muted">Prueba hasta</dt><dd>{{ suscripcion.prueba_hasta }}</dd></div>
            <div v-if="suscripcion.gracia_hasta" class="flex justify-between border-b border-marca-borde/60 py-1.5"><dt class="text-marca-muted">Gracia hasta</dt><dd class="text-amber-700 font-semibold">{{ suscripcion.gracia_hasta }}</dd></div>
            <div class="flex justify-between border-b border-marca-borde/60 py-1.5"><dt class="text-marca-muted">Importe</dt><dd class="tabular-nums">{{ moneda(suscripcion.monto) }} / {{ suscripcion.ciclo === 'yearly' ? 'año' : 'mes' }}</dd></div>
          </dl>
          <p v-if="suscripcion.notas" class="text-sm text-marca-muted mt-3 whitespace-pre-line">{{ suscripcion.notas }}</p>
          <div v-if="['trial','grace','suspended'].includes(suscripcion.estado) && !empresa.dada_de_baja" class="mt-4 flex flex-wrap items-center gap-2 p-3 rounded-xl bg-marca-fondo">
            <span class="text-sm">Darle más tiempo:</span>
            <input v-model.number="ext.dias" type="number" min="1" class="input w-20 !py-1" /><span class="text-sm">días</span>
            <button class="btn-secondary !py-1 text-xs" :disabled="ext.processing" @click="ext.post(`/admin/empresas/${empresa.id}/extender-prueba`, { preserveScroll: true })">Extender prueba</button>
          </div>
        </template>
        <p v-else class="text-sm text-marca-muted">Sin suscripción. Asignale un plan con "Cambiar plan".</p>
      </div>
      <div class="card">
        <h2 class="font-bold mb-3">Historial de cobros</h2>
        <div v-for="p in pagos" :key="p.id" class="py-2 border-t border-marca-borde/60 first:border-0 text-sm">
          <div class="flex items-center justify-between gap-2"><span class="font-medium">{{ p.fecha }} · {{ p.plan }} {{ cicloLabel[p.ciclo] }}</span><span class="tabular-nums font-semibold">{{ moneda(p.monto, 0) }}</span></div>
          <div class="flex items-center justify-between gap-2 mt-0.5"><span class="text-xs text-marca-muted">{{ p.medio_label }}<span v-if="p.referencia"> · {{ p.referencia }}</span><span v-if="p.periodo"> · {{ p.periodo }}</span></span><span class="badge" :class="pagoClase[p.estado]">{{ pagoLabel[p.estado] }}</span></div>
          <div v-if="p.estado === 'pendiente'" class="flex gap-2 mt-1.5">
            <Link :href="`/admin/empresas/${empresa.id}/pagos/${p.id}/aprobar`" method="post" as="button" preserve-scroll class="btn-primary !py-1 text-xs">Confirmar cobro</Link>
            <Link :href="`/admin/empresas/${empresa.id}/pagos/${p.id}/rechazar`" method="post" as="button" preserve-scroll class="btn-ghost !py-1 !px-2 text-xs text-carmin">Rechazar</Link>
          </div>
        </div>
        <p v-if="!pagos.length" class="text-sm text-marca-muted">Todavía no pagó nada.</p>
      </div>
    </div>

    <!-- Datos -->
    <form v-if="tab === 'datos'" @submit.prevent="datos.post(`/admin/empresas/${empresa.id}`, { preserveScroll: true })" class="card space-y-4">
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div><label class="label">Nombre comercial</label><input v-model="datos.name" class="input" /></div>
        <div><label class="label">Razón social</label><input v-model="datos.razon_social" class="input" /></div>
        <div><label class="label">CUIT</label><input v-model="datos.cuit" class="input" /></div>
        <div><label class="label">Email</label><input v-model="datos.email" type="email" class="input" /><p v-if="datos.errors.email" class="text-carmin text-xs mt-1">{{ datos.errors.email }}</p></div>
        <div><label class="label">Teléfono</label><input v-model="datos.phone" class="input" /></div>
        <div><label class="label">Rubro</label><select v-model="datos.vertical" class="input"><option v-for="(l, k) in verticales" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="label">Condición IVA</label><select v-model="datos.condicion_iva" class="input"><option>Responsable Inscripto</option><option>Monotributista</option><option>Exento</option></select></div>
        <div class="sm:col-span-2"><label class="label">Notas internas (solo BigSys)</label><input v-model="datos.notas_internas" class="input" /></div>
      </div>
      <div class="flex flex-wrap gap-2 text-sm"><span class="text-marca-muted">Sucursales:</span><span v-for="s in empresa.sucursales" :key="s.id" class="badge bg-marca-fondo">{{ s.nombre }}<span v-if="s.ciudad" class="text-marca-muted"> · {{ s.ciudad }}</span></span></div>
      <div class="flex justify-end"><button class="btn-primary" :disabled="datos.processing">Guardar</button></div>
    </form>

    <!-- Usuarios -->
    <div v-if="tab === 'usuarios'" class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th></th></tr></thead>
        <tbody>
          <tr v-for="u in usuarios" :key="u.id">
            <td><p class="font-semibold">{{ u.name }} <span v-if="u.es_dueno" class="badge bg-carmin-light text-carmin ml-1">Dueño</span></p><p class="text-xs text-marca-muted">{{ u.email }}</p></td>
            <td>{{ u.rol ?? '—' }}</td>
            <td><span class="badge" :class="u.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ u.status === 'active' ? 'Activo' : 'Inactivo' }}</span></td>
            <td class="text-xs text-marca-muted">{{ u.ultimo ?? 'Nunca' }}</td>
            <td class="text-right whitespace-nowrap">
              <button @click="pass = { id: u.id, nombre: u.name, password: '' }" class="btn-ghost !px-2 text-xs">Nueva contraseña</button>
              <Link :href="`/admin/empresas/${empresa.id}/usuarios/${u.id}`" method="post" :data="{ accion: u.status === 'active' ? 'desactivar' : 'activar' }" as="button" preserve-scroll class="btn-ghost !px-2 text-xs" :class="u.status === 'active' ? 'text-carmin' : 'text-emerald-700'">{{ u.status === 'active' ? 'Desactivar' : 'Activar' }}</Link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Auditoría -->
    <div v-if="tab === 'auditoria'" class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th></tr></thead>
        <tbody>
          <tr v-for="l in auditoria" :key="l.id"><td class="text-marca-muted whitespace-nowrap">{{ l.fecha }}</td><td>{{ l.usuario }}</td><td><span class="badge bg-marca-fondo">{{ l.accion }}</span></td><td>{{ l.descripcion }}</td></tr>
          <tr v-if="!auditoria.length"><td colspan="4" class="text-center text-marca-muted py-8">Sin registros.</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Modales -->
    <Modal :abierto="planAbierto" titulo="Cambiar plan o renovar" @cerrar="planAbierto = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2"><label class="label">Plan</label><select v-model="plan.plan_id" class="input"><option v-for="p in planes" :key="p.id" :value="p.id">{{ p.name }} · {{ moneda(p.price_monthly, 0) }}/mes · {{ moneda(p.price_yearly, 0) }}/año</option></select></div>
        <div><label class="label">Ciclo</label><select v-model="plan.ciclo" class="input"><option value="monthly">Mensual</option><option value="yearly">Anual</option></select></div>
        <div><label class="label">Vigente hasta</label><input v-model="plan.ends_at" type="date" class="input" /><p v-if="plan.errors.ends_at" class="text-carmin text-xs mt-1">{{ plan.errors.ends_at }}</p></div>
        <div><label class="label">Motivo / medio</label><select v-model="plan.medio" class="input"><option value="cortesia">Bonificación / acuerdo</option><option value="transferencia">Pagó por transferencia</option><option value="efectivo">Pagó en efectivo</option><option value="mercadopago">Pagó por MercadoPago</option></select></div>
        <div><label class="label">Notas</label><input v-model="plan.notas" class="input" /></div>
      </div>
      <p class="text-xs text-marca-muted mt-3">Esto no registra un cobro. Si recibiste plata usá "Registrar cobro", que además renueva.</p>
      <template #pie><button class="btn-secondary" @click="planAbierto = false">Cancelar</button><button class="btn-primary" :disabled="plan.processing" @click="plan.post(`/admin/empresas/${empresa.id}/plan`, { preserveScroll: true, onSuccess: () => (planAbierto = false) })">Aplicar</button></template>
    </Modal>

    <Modal :abierto="pagoAbierto" titulo="Registrar cobro recibido" @cerrar="pagoAbierto = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2"><label class="label">Plan</label><select v-model="pago.plan_id" class="input" @change="pagoMonto"><option v-for="p in planes" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
        <div><label class="label">Ciclo</label><select v-model="pago.ciclo" class="input" @change="pagoMonto"><option value="monthly">Mensual</option><option value="yearly">Anual</option></select></div>
        <div><label class="label">Importe</label><input v-model.number="pago.monto" type="number" step="any" class="input" /></div>
        <div><label class="label">Medio</label><select v-model="pago.medio" class="input"><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="mercadopago">MercadoPago (manual)</option><option value="cortesia">Cortesía</option></select></div>
        <div><label class="label">Referencia</label><input v-model="pago.referencia" class="input" placeholder="N° operación, banco…" /></div>
        <div class="sm:col-span-2"><label class="label">Notas</label><input v-model="pago.notas" class="input" /></div>
      </div>
      <p class="text-xs text-marca-muted mt-3">Se aprueba al instante y extiende la suscripción un período desde el vencimiento actual (o desde hoy si ya venció).</p>
      <template #pie><button class="btn-secondary" @click="pagoAbierto = false">Cancelar</button><button class="btn-primary" :disabled="pago.processing" @click="pago.post(`/admin/empresas/${empresa.id}/pagos`, { preserveScroll: true, onSuccess: () => (pagoAbierto = false) })">Registrar y renovar</button></template>
    </Modal>

    <Modal :abierto="suspAbierto" titulo="Suspender empresa" @cerrar="suspAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Sus usuarios no van a poder entrar hasta que la reactives. Van a ver el motivo.</p>
      <input v-model="susp.motivo" class="input" placeholder="Motivo (lo ve el cliente)" /><p v-if="susp.errors.motivo" class="text-carmin text-xs mt-1">{{ susp.errors.motivo }}</p>
      <template #pie><button class="btn-secondary" @click="suspAbierto = false">Cancelar</button><button class="btn-danger" :disabled="susp.processing" @click="susp.post(`/admin/empresas/${empresa.id}/suspender`, { preserveScroll: true, onSuccess: () => (suspAbierto = false) })">Suspender</button></template>
    </Modal>

    <Modal :abierto="bajaAbierto" titulo="Dar de baja la empresa" @cerrar="bajaAbierto = false">
      <p class="text-sm text-marca-muted mb-3">La empresa deja de operar, se cancela la suscripción y se desactivan sus usuarios. Los datos no se borran: se puede restaurar.</p>
      <input v-model="baja.motivo" class="input" placeholder="Motivo" /><p v-if="baja.errors.motivo" class="text-carmin text-xs mt-1">{{ baja.errors.motivo }}</p>
      <template #pie><button class="btn-secondary" @click="bajaAbierto = false">Cancelar</button><button class="btn-danger" :disabled="baja.processing" @click="baja.post(`/admin/empresas/${empresa.id}/baja`, { preserveScroll: true, onSuccess: () => (bajaAbierto = false) })">Dar de baja</button></template>
    </Modal>

    <Modal :abierto="!!pass" :titulo="`Nueva contraseña · ${pass?.nombre}`" @cerrar="pass = null">
      <input v-model="pass.password" class="input" placeholder="Mínimo 6 caracteres" v-if="pass" />
      <template #pie><button class="btn-secondary" @click="pass = null">Cancelar</button><button class="btn-primary" @click="router.post(`/admin/empresas/${empresa.id}/usuarios/${pass.id}`, { accion: 'password', password: pass.password }, { preserveScroll: true, onSuccess: () => (pass = null) })">Guardar</button></template>
    </Modal>
  </AdminLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
import { moneda, hoyISO } from '@/util/formato'
import { estadoClase, pagoClase, pagoLabel, cicloLabel } from '@/util/suscripcion'

const props = defineProps({ empresa: Object, suscripcion: Object, uso: Object, usuarios: Array, pagos: Array, auditoria: Array, planes: Array, medios: Object, verticales: Object, estados: Object })
const tab = ref('suscripcion')
const tabs = computed(() => [{ key: 'suscripcion', label: 'Suscripción', n: props.pagos.filter(p => p.estado === 'pendiente').length }, { key: 'datos', label: 'Datos' }, { key: 'usuarios', label: 'Usuarios', n: props.usuarios.length }, { key: 'auditoria', label: 'Actividad' }])

const planAbierto = ref(false), pagoAbierto = ref(false), suspAbierto = ref(false), bajaAbierto = ref(false), pass = ref(null)
const enUnMes = () => { const d = new Date(); d.setMonth(d.getMonth() + 1); return d.toISOString().slice(0, 10) }
const plan = useForm({ plan_id: props.suscripcion?.plan_id ?? props.planes[0]?.id, ciclo: props.suscripcion?.ciclo ?? 'monthly', ends_at: props.suscripcion?.vence_iso && props.suscripcion.dias > 0 ? props.suscripcion.vence_iso : enUnMes(), medio: 'cortesia', notas: '' })
const pago = useForm({ plan_id: props.suscripcion?.plan_id ?? props.planes[0]?.id, ciclo: props.suscripcion?.ciclo ?? 'monthly', monto: 0, medio: 'transferencia', referencia: '', notas: '' })
function pagoMonto() { const p = props.planes.find(x => x.id === pago.plan_id); pago.monto = p ? (pago.ciclo === 'yearly' ? p.price_yearly : p.price_monthly) : 0 }
pagoMonto()
const ext = useForm({ dias: 7 })
const susp = useForm({ motivo: '' })
const baja = useForm({ motivo: '' })
const datos = useForm({ name: props.empresa.nombre, razon_social: props.empresa.razon_social, cuit: props.empresa.cuit, email: props.empresa.email, phone: props.empresa.phone, vertical: props.empresa.vertical_key ?? 'otro', condicion_iva: props.empresa.condicion_iva ?? 'Responsable Inscripto', notas_internas: props.empresa.notas_internas })
</script>
