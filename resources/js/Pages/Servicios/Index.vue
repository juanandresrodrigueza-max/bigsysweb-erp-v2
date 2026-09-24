<template>
  <AppLayout titulo="Servicio técnico">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Servicio técnico</h1><p class="page-subtitle">Órdenes de trabajo de recibido a entregado: diagnóstico, presupuesto aprobado por link, tareas, hoja de trabajo, firma y factura.</p></div>
      <button class="btn-primary" @click="modal = true">Nueva orden</button>
    </div>
    <div class="grid grid-cols-3 md:grid-cols-6 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Abiertas</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.abiertas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Urgentes</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.urgentes ? 'text-carmin' : ''">{{ kpis.urgentes }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Atrasadas</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.atrasadas ? 'text-carmin' : ''">{{ kpis.atrasadas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Por aprobar</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.por_aprobar ? 'text-amber-600' : ''">{{ kpis.por_aprobar }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Listas</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ kpis.listas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Para facturar</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.facturable, 0) }}</p></div>
    </div>
    <div class="overflow-x-auto pb-2">
      <div class="grid gap-3 min-w-[1100px]" style="grid-template-columns: repeat(6, minmax(0, 1fr))">
        <div v-for="k in columnas" :key="k" class="rounded-2xl bg-gris-light/60 p-2 min-h-[300px]">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted px-1 mb-2">{{ estados[k] }} <span class="text-marca-texto">{{ (tablero[k] || []).length }}</span></p>
          <div class="space-y-2">
            <Link v-for="o in tablero[k] || []" :key="o.id" :href="`/servicios/${o.id}`" class="block bg-white rounded-xl border p-2.5 text-xs hover:shadow transition" :class="o.prioridad === 'alta' ? 'border-carmin/50' : o.atrasada ? 'border-amber-300' : 'border-marca-borde'">
              <div class="flex justify-between items-start"><b class="tabular-nums">{{ o.numero }}</b><span v-if="o.prioridad === 'alta'" class="badge bg-red-50 text-carmin !text-[10px]">Urgente</span><span v-else-if="o.atrasada" class="badge bg-amber-50 text-amber-700 !text-[10px]">Atrasada</span></div>
              <p class="font-semibold mt-1 truncate">{{ o.equipo }}<span v-if="o.marca_modelo" class="font-normal text-marca-muted"> · {{ o.marca_modelo }}</span></p>
              <p class="truncate">{{ o.cliente }}</p>
              <p class="text-marca-muted truncate">{{ o.falla }}</p>
              <div class="flex justify-between mt-1.5 text-[11px] text-marca-muted"><span>{{ o.tecnico || 'sin técnico' }} · {{ o.dias }} d</span><span v-if="o.presupuesto" class="font-semibold text-marca-texto tabular-nums">{{ moneda(o.presupuesto, 0) }}</span></div>
            </Link>
          </div>
        </div>
      </div>
    </div>
    <div v-if="entregadas.length" class="card p-0 overflow-hidden mt-4">
      <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Últimas entregadas o canceladas</h2></div>
      <table class="table text-sm"><tbody><tr v-for="o in entregadas" :key="o.id" class="cursor-pointer hover:bg-gris-light/40" @click="router.visit(`/servicios/${o.id}`)"><td class="tabular-nums font-semibold">{{ o.numero }}</td><td>{{ o.equipo }}</td><td class="text-marca-muted">{{ o.cliente }}</td><td><span class="badge" :class="o.estado === 'entregado' ? 'bg-emerald-50 text-emerald-700' : 'bg-gris-light text-marca-muted'">{{ estados[o.estado] }}</span></td><td class="text-right tabular-nums">{{ moneda(o.presupuesto, 0) }}</td></tr></tbody></table>
    </div>

    <Modal :abierto="modal" titulo="Nueva orden de trabajo" ancho="max-w-2xl" @cerrar="modal = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2"><label class="label">Cliente</label><select v-model="f.contact_id" class="input" @change="alElegirCliente"><option :value="null">Sin ficha (cargar nombre)</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div><label class="label">Nombre</label><input v-model="f.nombre" class="input" :disabled="!!f.contact_id" /></div><div><label class="label">Teléfono</label><input v-model="f.telefono" class="input" placeholder="Para avisarle por WhatsApp" /></div>
        <div><label class="label">Equipo</label><input v-model="f.equipo" class="input" placeholder="Notebook, heladera, moto, bomba…" /><p v-if="f.errors.equipo" class="text-carmin text-xs mt-1">{{ f.errors.equipo }}</p></div><div><label class="label">Marca y modelo</label><input v-model="f.marca_modelo" class="input" /></div>
        <div><label class="label">N° de serie</label><input v-model="f.serie" class="input" /></div><div><label class="label">Prioridad</label><select v-model="f.prioridad" class="input"><option v-for="(l, k) in prioridades" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="sm:col-span-2"><label class="label">Falla que reporta el cliente</label><textarea v-model="f.falla" rows="2" class="input"></textarea><p v-if="f.errors.falla" class="text-carmin text-xs mt-1">{{ f.errors.falla }}</p></div>
        <div><label class="label">Técnico</label><select v-model="f.tecnico_id" class="input"><option :value="null">Asignar después</option><option v-for="t in tecnicos" :key="t.id" :value="t.id">{{ t.name }}</option></select></div><div><label class="label">Fecha prometida</label><input v-model="f.fecha_prometida" type="date" class="input" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="modal = false">Cancelar</button><button class="btn-primary" :disabled="f.processing || !f.equipo || !f.falla || (!f.contact_id && !f.nombre)" @click="f.post('/servicios')">Crear orden</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ tablero: Object, estados: Object, prioridades: Object, entregadas: Array, clientes: Array, tecnicos: Array, kpis: Object })
const columnas = ['recibido', 'diagnostico', 'presupuestado', 'aprobado', 'en_curso', 'listo']
const modal = ref(false)
const f = useForm({ contact_id: null, nombre: '', telefono: '', equipo: '', marca_modelo: '', serie: '', falla: '', prioridad: 'normal', tecnico_id: null, fecha_prometida: '' })
function alElegirCliente() { const c = props.clientes.find(x => x.id === f.contact_id); if (c) { f.nombre = ''; if (!f.telefono) f.telefono = c.phone || '' } }
</script>
