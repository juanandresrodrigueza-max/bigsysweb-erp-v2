<template>
  <AppLayout titulo="Clientes">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <h1 class="page-title">Clientes</h1>
        <p class="page-subtitle">{{ entero(totales.clientes) }} clientes · por cobrar {{ moneda(totales.por_cobrar, 0) }}</p>
      </div>
      <div class="flex gap-2">
        <button @click="tiposAbierto = true" class="btn-secondary">Tipos de cliente</button>
        <button @click="editar(null)" class="btn-primary"><Icono nombre="plus" clase="w-4 h-4" /> Nuevo cliente</button>
      </div>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-4">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input sm:col-span-2" placeholder="Buscar por nombre, CUIT, email o teléfono…" />
      <select v-model="f.tipo_cliente_id" @change="filtrar" class="input"><option value="">Todos los tipos</option><option v-for="t in tipos" :key="t.id" :value="t.id">{{ t.nombre }}</option></select>
      <select v-model="f.estado" @change="filtrar" class="input"><option value="activos">Activos</option><option value="deudores">Con saldo</option><option value="inactivos">Inactivos</option></select>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table">
        <thead><tr><th>Cliente</th><th>Tipo</th><th>Condición IVA</th><th>Contacto</th><th class="text-right">Saldo</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in lista.data" :key="c.id" class="cursor-pointer" @click="$inertia.visit(`/clientes/${c.id}`)">
            <td><p class="font-semibold">{{ c.name }}</p><p class="text-xs text-marca-muted">{{ c.cuit ?? 'sin CUIT' }}<span v-if="c.city"> · {{ c.city }}</span></p></td>
            <td><span v-if="c.tipo" class="badge text-white" :style="{ background: c.tipo.color || '#6f6a62' }">{{ c.tipo.nombre }}</span></td>
            <td class="text-marca-muted">{{ c.condicion_iva }}</td>
            <td class="text-marca-muted text-xs">{{ c.phone }}<br>{{ c.email }}</td>
            <td class="text-right tabular-nums font-semibold" :class="c.balance > 0 ? 'text-carmin' : c.balance < 0 ? 'text-emerald-700' : 'text-marca-muted'">{{ moneda(c.balance) }}<p v-if="c.credit_limit > 0" class="text-[10px] font-normal text-marca-muted">límite {{ moneda(c.credit_limit, 0) }}</p></td>
            <td class="text-right"><button @click.stop="editar(c)" class="btn-ghost !px-2 text-xs"><Icono nombre="edit" clase="w-4 h-4" /></button></td>
          </tr>
          <tr v-if="!lista.data.length"><td colspan="6" class="text-center text-marca-muted py-10">No hay clientes con estos filtros.</td></tr>
        </tbody>
      </table>
    </div>
    <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />

    <ClienteModal :abierto="modal" :cliente="seleccionado" :tipos="tipos" :condicionesIva="condicionesIva" @cerrar="modal = false" />

    <Modal :abierto="tiposAbierto" titulo="Tipos de cliente" ancho="max-w-2xl" @cerrar="tiposAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Cada tipo define lista de precios, plazo de pago, descuento y límite por defecto para los clientes nuevos, y sirve para agrupar en reportes.</p>
      <table class="table mb-4">
        <thead><tr><th>Nombre</th><th class="text-right">Lista</th><th class="text-right">Días</th><th class="text-right">Dto</th><th class="text-right">Límite</th><th class="text-right">Clientes</th><th></th></tr></thead>
        <tbody>
          <tr v-for="t in tipos" :key="t.id">
            <td><span class="inline-block w-2.5 h-2.5 rounded-full mr-2" :style="{ background: t.color || '#6f6a62' }"></span>{{ t.nombre }}</td>
            <td class="text-right">{{ t.lista_precios }}</td><td class="text-right">{{ t.dias_pago }}</td><td class="text-right">{{ t.descuento }}%</td><td class="text-right tabular-nums">{{ moneda(t.limite_credito, 0) }}</td><td class="text-right">{{ t.contacts_count }}</td>
            <td class="text-right whitespace-nowrap"><button @click="tipoForm.id = t.id; Object.assign(tipoForm, t)" class="btn-ghost !px-2 text-xs"><Icono nombre="edit" clase="w-4 h-4" /></button><button @click="borrarTipo(t)" class="btn-ghost !px-2 text-xs text-carmin"><Icono nombre="trash" clase="w-4 h-4" /></button></td>
          </tr>
        </tbody>
      </table>
      <div class="grid sm:grid-cols-6 gap-2 items-end">
        <div class="sm:col-span-2"><label class="label">{{ tipoForm.id ? 'Editar' : 'Nuevo' }}</label><input v-model="tipoForm.nombre" class="input" placeholder="Nombre" /></div>
        <div><label class="label">Lista</label><select v-model.number="tipoForm.lista_precios" class="input"><option v-for="n in 5" :key="n" :value="n">{{ n }}</option></select></div>
        <div><label class="label">Días</label><input v-model.number="tipoForm.dias_pago" type="number" class="input" /></div>
        <div><label class="label">Dto %</label><input v-model.number="tipoForm.descuento" type="number" step="any" class="input" /></div>
        <div><label class="label">Límite</label><input v-model.number="tipoForm.limite_credito" type="number" class="input" /></div>
        <div class="sm:col-span-6 flex items-center gap-2"><input v-model="tipoForm.color" type="color" class="w-9 h-9 rounded-lg border border-marca-borde" /><button class="btn-primary" :disabled="tipoForm.processing || !tipoForm.nombre" @click="tipoForm.post(`/clientes/tipos${tipoForm.id ? '/' + tipoForm.id : ''}`, { preserveScroll: true, onSuccess: () => tipoForm.reset() })">Guardar tipo</button><button v-if="tipoForm.id" class="btn-ghost" @click="tipoForm.reset()">Cancelar</button></div>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import Paginacion from '@/Components/Paginacion.vue'
import ClienteModal from '@/Components/ClienteModal.vue'
import { moneda, entero } from '@/util/formato'

const props = defineProps({ lista: Object, totales: Object, filtros: Object, tipos: Array, condicionesIva: Array })
const f = reactive({ buscar: props.filtros.buscar ?? '', tipo_cliente_id: props.filtros.tipo_cliente_id ?? '', estado: props.filtros.estado ?? 'activos' })
function filtrar() { router.get('/clientes', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
const modal = ref(false), seleccionado = ref(null), tiposAbierto = ref(false)
function editar(c) { seleccionado.value = c ? { ...c, tipo_cliente_id: props.tipos.find(t => t.nombre === c.tipo?.nombre)?.id ?? null } : null; modal.value = true }
const tipoForm = useForm({ id: null, nombre: '', lista_precios: 1, dias_pago: 0, descuento: 0, limite_credito: 0, color: '#4f3089' })
function borrarTipo(t) { if (confirm(`¿Eliminar el tipo "${t.nombre}"?`)) router.delete(`/clientes/tipos/${t.id}`, { preserveScroll: true }) }
onMounted(() => { if (new URLSearchParams(location.search).get('nuevo')) editar(null) })
</script>
