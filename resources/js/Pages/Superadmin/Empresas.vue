<template>
  <AdminLayout titulo="Empresas">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Empresas</h1><p class="page-subtitle">Cada cliente de BigSysWeb con su plan, estado y vencimiento.</p></div>
      <button @click="nueva = true" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nueva empresa</button>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-4">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input sm:col-span-2" placeholder="Nombre, CUIT o email…" />
      <select v-model="f.estado" @change="filtrar" class="input"><option value="">Todos los estados</option><option v-for="(l, k) in estados" :key="k" :value="k">{{ l }}</option><option value="suspendida">Suspendidas a mano</option><option value="baja">Dadas de baja</option></select>
      <select v-model="f.plan" @change="filtrar" class="input"><option value="">Todos los planes</option><option v-for="p in planes" :key="p.id" :value="p.id">{{ p.name }}</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Empresa</th><th>Rubro</th><th>Plan</th><th>Estado</th><th>Vence</th><th class="text-right">Usuarios</th><th>Dueño</th><th>Último acceso</th></tr></thead>
        <tbody>
          <tr v-for="e in lista.data" :key="e.id" class="cursor-pointer" @click="$inertia.visit(`/admin/empresas/${e.id}`)" :class="e.estado === 'baja' ? 'opacity-60' : ''">
            <td><p class="font-semibold">{{ e.nombre }}</p><p class="text-xs text-marca-muted">{{ e.cuit ?? 'sin CUIT' }} · alta {{ e.alta }}</p></td>
            <td class="text-marca-muted">{{ e.vertical }}</td>
            <td><span class="badge bg-lavanda-light text-violeta">{{ e.plan ?? '—' }}</span></td>
            <td><span class="badge" :class="estadoClase[e.estado]">{{ e.estado_label }}</span></td>
            <td class="tabular-nums" :class="e.dias !== null && e.dias <= 3 && ['trial','active','grace'].includes(e.estado) ? 'text-carmin font-semibold' : 'text-marca-muted'">{{ e.vence ?? '—' }}<span v-if="e.dias !== null && ['trial','active','grace'].includes(e.estado)" class="text-xs"> ({{ e.dias <= 0 ? 'hoy' : `${e.dias} d` }})</span></td>
            <td class="text-right tabular-nums">{{ e.usuarios }}</td>
            <td class="text-sm">{{ e.dueno }}</td>
            <td class="text-xs text-marca-muted">{{ e.ultimo_acceso ?? 'Nunca' }}</td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="8" class="text-center text-marca-muted py-10">No hay empresas con este filtro.</td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />

    <Modal :abierto="nueva" titulo="Alta de empresa" ancho="max-w-3xl" @cerrar="nueva = false">
      <div class="grid sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2"><p class="label !mb-2">Empresa</p></div>
        <div><label class="label">Nombre comercial</label><input v-model="alta.name" class="input" /><p v-if="alta.errors.name" class="text-carmin text-xs mt-1">{{ alta.errors.name }}</p></div>
        <div><label class="label">Rubro</label><select v-model="alta.vertical" class="input"><option v-for="(l, k) in verticales" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="label">CUIT</label><input v-model="alta.cuit" class="input" placeholder="30-12345678-9" /></div>
        <div><label class="label">Condición IVA</label><select v-model="alta.condicion_iva" class="input"><option>Responsable Inscripto</option><option>Monotributista</option><option>Exento</option></select></div>
        <div><label class="label">Ciudad</label><input v-model="alta.city" class="input" /></div>
        <div><label class="label">Provincia</label><input v-model="alta.province" class="input" /></div>
        <div class="sm:col-span-2 pt-2"><p class="label !mb-2">Dueño (primer usuario)</p></div>
        <div><label class="label">Nombre</label><input v-model="alta.dueno_nombre" class="input" /><p v-if="alta.errors.dueno_nombre" class="text-carmin text-xs mt-1">{{ alta.errors.dueno_nombre }}</p></div>
        <div><label class="label">Email (para entrar)</label><input v-model="alta.dueno_email" type="email" class="input" /><p v-if="alta.errors.dueno_email" class="text-carmin text-xs mt-1">{{ alta.errors.dueno_email }}</p></div>
        <div><label class="label">Contraseña inicial</label><input v-model="alta.dueno_password" class="input" /><p v-if="alta.errors.dueno_password" class="text-carmin text-xs mt-1">{{ alta.errors.dueno_password }}</p></div>
        <div><label class="label">Celular</label><input v-model="alta.dueno_mobile" class="input" /></div>
        <div class="sm:col-span-2 pt-2"><p class="label !mb-2">Plan</p></div>
        <div><label class="label">Plan</label><select v-model="alta.plan_id" class="input"><option v-for="p in planes.filter(x => x.is_active)" :key="p.id" :value="p.id">{{ p.name }} · {{ moneda(p.price_monthly, 0) }}/mes</option></select></div>
        <div><label class="label">Arranca</label><select v-model="alta.modo" class="input"><option value="trial">En prueba gratis</option><option value="activa">Activa (ya pagó o bonificada)</option></select></div>
        <div v-if="alta.modo === 'trial'"><label class="label">Días de prueba</label><input v-model.number="alta.dias_prueba" type="number" min="1" class="input" /></div>
        <template v-else>
          <div><label class="label">Ciclo</label><select v-model="alta.ciclo" class="input"><option value="monthly">Mensual</option><option value="yearly">Anual</option></select></div>
          <div><label class="label">Cómo pagó</label><select v-model="alta.medio" class="input"><option value="cortesia">Bonificado / cortesía</option><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="mercadopago">MercadoPago</option></select></div>
        </template>
        <div class="sm:col-span-2"><label class="label">Notas internas</label><input v-model="alta.notas_internas" class="input" placeholder="Cómo llegó, acuerdos, contacto…" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="nueva = false">Cancelar</button><button class="btn-primary" :disabled="alta.processing" @click="alta.post('/admin/empresas', { onSuccess: () => (nueva = false) })">{{ alta.processing ? 'Creando…' : 'Dar de alta' }}</button></template>
    </Modal>
  </AdminLayout>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Modal from '@/Components/Modal.vue'
import Icono from '@/Components/Icono.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda } from '@/util/formato'
import { estadoClase } from '@/util/suscripcion'
const props = defineProps({ lista: Object, filtros: Object, planes: Array, estados: Object, verticales: Object, diasPrueba: Number })
const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? '', plan: props.filtros.plan ?? '' })
function filtrar() { router.get('/admin/empresas', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
const nueva = ref(false)
const alta = useForm({ name: '', vertical: 'otro', cuit: '', condicion_iva: 'Responsable Inscripto', city: '', province: '', dueno_nombre: '', dueno_email: '', dueno_password: '', dueno_mobile: '', plan_id: props.planes.find(p => !p.is_free && p.is_active)?.id ?? props.planes[0]?.id, modo: 'trial', dias_prueba: props.diasPrueba, ciclo: 'monthly', medio: 'cortesia', notas_internas: '' })
onMounted(() => { if (new URLSearchParams(window.location.search).get('nueva')) nueva.value = true })
</script>
