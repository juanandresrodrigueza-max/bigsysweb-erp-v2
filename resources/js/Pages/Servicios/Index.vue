<template>
  <AppLayout titulo="Servicio técnico">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Servicio técnico</h1><p class="page-subtitle">Órdenes de trabajo de recibido a entregado: diagnóstico, presupuesto aprobado por link, tareas, hoja de trabajo, firma y factura.</p></div>
      <div class="flex gap-2"><Link href="/servicios/mis-ordenes" class="btn-secondary">Mis órdenes</Link><Link href="/servicios/que-vuelvan" class="btn-secondary">Que vuelvan</Link><Link href="/servicios/tecnicos" class="btn-secondary">Qué pagarle a cada {{ config.tecnico.toLowerCase() }}</Link><button class="btn-secondary" @click="cfgAbierto = true" data-e2e="que-reparas">¿Qué reparás?</button><button class="btn-primary" @click="modal = true" data-e2e="nueva">Nueva orden</button></div>
    </div>
    <!-- Fase 36.1: con un toque, las órdenes hablan como el taller -->
    <div v-if="!config.configurado" class="card mb-4 border-violeta/40" data-e2e="arrancar">
      <h2 class="font-bold">¿Qué reparás?</h2><p class="text-sm text-marca-muted mb-2">Elegí y las órdenes usan tus palabras (Vehículo, Patente, Km, Mecánico) y piden los datos de cada equipo.</p>
      <div class="flex flex-wrap gap-2"><button v-for="(n, k) in rubros" :key="k" class="btn-secondary !py-1.5 text-sm" @click="router.post('/servicios/config', { rubro: k }, { preserveScroll: true })" :data-rubro="k">{{ n }}</button></div>
    </div>
    <div class="grid grid-cols-3 md:grid-cols-7 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Abiertas</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.abiertas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Urgentes</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.urgentes ? 'text-carmin' : ''">{{ kpis.urgentes }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Atrasadas</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.atrasadas ? 'text-carmin' : ''">{{ kpis.atrasadas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Por aprobar</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.por_aprobar ? 'text-amber-600' : ''">{{ kpis.por_aprobar }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Esperando</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.esperando ? 'text-amber-600' : ''">{{ kpis.esperando }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Listas</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ kpis.listas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Para facturar</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.facturable, 0) }}</p></div>
    </div>
    <div v-if="para_revisar.length" class="card mb-4 border-amber-300" data-e2e="para-revisar"><h2 class="font-bold text-sm mb-1">Para revisar <span class="badge bg-amber-50 text-amber-700">{{ para_revisar.length }}</span></h2>
      <Link v-for="r in para_revisar.slice(0, 8)" :key="r.id" :href="`/servicios/${r.id}`" class="flex justify-between text-sm py-0.5 border-t border-marca-borde"><span><b>{{ r.numero }}</b> · {{ r.equipo }} · {{ r.cliente }}</span><span class="text-amber-700 text-xs">{{ r.motivo }}</span></Link></div>
    <div class="overflow-x-auto pb-2">
      <div class="grid gap-3 min-w-[1100px]" style="grid-template-columns: repeat(7, minmax(0, 1fr))">
        <div v-for="k in columnas" :key="k" class="rounded-2xl bg-gris-light/60 p-2 min-h-[300px]">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted px-1 mb-2">{{ estados[k] }} <span class="text-marca-texto">{{ (tablero[k] || []).length }}</span></p>
          <div class="space-y-2">
            <Link v-for="o in tablero[k] || []" :key="o.id" :href="`/servicios/${o.id}`" class="block bg-white rounded-xl border p-2.5 text-xs hover:shadow transition" :class="o.prioridad === 'alta' ? 'border-carmin/50' : o.atrasada ? 'border-amber-300' : 'border-marca-borde'">
              <div class="flex justify-between items-start"><b class="tabular-nums">{{ o.numero }}</b><span v-if="o.prioridad === 'alta'" class="badge bg-red-50 text-carmin !text-[10px]">Urgente</span><span v-else-if="o.atrasada" class="badge bg-amber-50 text-amber-700 !text-[10px]">Atrasada</span></div>
              <p class="font-semibold mt-1 truncate">{{ o.equipo }}<span v-if="o.marca_modelo" class="font-normal text-marca-muted"> · {{ o.marca_modelo }}</span></p>
              <p class="truncate">{{ o.cliente }}</p>
              <p class="text-marca-muted truncate">{{ o.falla }}</p>
              <p v-if="o.que_sigue" class="mt-1 text-[11px] font-semibold" :class="o.estado === 'esperando' ? 'text-amber-700' : 'text-violeta'">→ {{ o.que_sigue }}</p>
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
        <div><label class="label">{{ config.equipo }}</label><input v-model="f.equipo" class="input" placeholder="Qué es (ej. Gol Trend, iPhone 12, heladera)" data-e2e="equipo" /><p v-if="f.errors.equipo" class="text-carmin text-xs mt-1">{{ f.errors.equipo }}</p></div><div><label class="label">Marca y modelo</label><input v-model="f.marca_modelo" class="input" /></div>
        <div><label class="label">{{ config.serie }}</label><input v-model="f.serie" class="input" data-e2e="serie" /></div>
        <div v-if="config.medidor"><label class="label">{{ config.medidor }}</label><input v-model.number="f.medidor" type="number" min="0" class="input" /></div>
        <div v-for="c in config.campos.filter(c => !['marca', 'modelo'].includes(c))" :key="c"><label class="label">{{ c }}</label><input v-model="f.datos_equipo[c]" class="input" /></div><div><label class="label">Prioridad</label><select v-model="f.prioridad" class="input"><option v-for="(l, k) in prioridades" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="sm:col-span-2"><label class="label">Falla que reporta el cliente</label><textarea v-model="f.falla" rows="2" class="input"></textarea><p v-if="f.errors.falla" class="text-carmin text-xs mt-1">{{ f.errors.falla }}</p></div>
        <div><label class="label">{{ config.tecnico }}</label><select v-model="f.tecnico_id" class="input"><option :value="null">{{ config.tecnico }}: asignar después</option><option v-for="t in tecnicos" :key="t.id" :value="t.id">{{ t.name }}</option></select></div><div><label class="label">Fecha prometida</label><input v-model="f.fecha_prometida" type="date" class="input" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="modal = false">Cancelar</button><button class="btn-primary" :disabled="f.processing || !f.equipo || !f.falla || (!f.contact_id && !f.nombre)" @click="f.post('/servicios')">Crear orden</button></template>
    </Modal>
    <Modal :abierto="cfgAbierto" titulo="¿Qué reparás?" @cerrar="cfgAbierto = false">
      <div class="grid sm:grid-cols-2 gap-3 text-sm">
        <div class="sm:col-span-2"><label class="label">Rubro</label><select v-model="cf.rubro" class="input"><option v-for="(n, k) in rubros" :key="k" :value="k">{{ n }}</option></select></div>
        <div><label class="label">Cómo le decís al equipo</label><input v-model="cf.equipo" class="input" /></div><div><label class="label">Al número que lo identifica</label><input v-model="cf.serie" class="input" /></div>
        <div><label class="label">Medidor (vacío si no hay)</label><input v-model="cf.medidor" class="input" placeholder="Km, Horas…" /></div><div><label class="label">A quien repara</label><input v-model="cf.tecnico" class="input" /></div>
        <div class="sm:col-span-2"><label class="label">Datos de cada equipo (separados por coma)</label><input v-model="cf.campos_txt" class="input" /></div>
        <div><label class="label">% de la mano de obra que se le paga</label><input v-model.number="cf.pago_tecnico_pct" type="number" min="0" max="100" class="input" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="cfgAbierto = false">Cancelar</button><button class="btn-primary" @click="router.post('/servicios/config', { ...cf, campos: cf.campos_txt.split(',').map(x => x.trim()).filter(Boolean) }, { preserveScroll: true, onSuccess: () => (cfgAbierto = false) })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
const props = defineProps({ tablero: Object, estados: Object, prioridades: Object, entregadas: Array, clientes: Array, tecnicos: Array, kpis: Object, config: Object, rubros: Object, para_revisar: { type: Array, default: () => [] } })
const columnas = ['recibido', 'diagnostico', 'presupuestado', 'aprobado', 'en_curso', 'esperando', 'listo']
const cfgAbierto = ref(false)
const cf = reactive({ rubro: props.config.rubro ?? 'otro', equipo: props.config.equipo, serie: props.config.serie, medidor: props.config.medidor ?? '', tecnico: props.config.tecnico, campos_txt: props.config.campos.join(', '), pago_tecnico_pct: props.config.pago_tecnico_pct })
const modal = ref(false)
const f = useForm({ contact_id: null, nombre: '', telefono: '', equipo: '', marca_modelo: '', serie: '', falla: '', prioridad: 'normal', tecnico_id: null, fecha_prometida: '', medidor: null, datos_equipo: {} })
function alElegirCliente() { const c = props.clientes.find(x => x.id === f.contact_id); if (c) { f.nombre = ''; if (!f.telefono) f.telefono = c.phone || '' } }
</script>
