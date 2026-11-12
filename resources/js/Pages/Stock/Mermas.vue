<template>
  <AppLayout titulo="Mermas y cortesías">
    <PageHeader titulo="Mermas y cortesías" subtitulo="Lo que se pierde con nombre y apellido: vencido, roto, se tiró, error de cocina, invitaciones y consumo del personal. Baja el stock y queda el costo."
      :principal="puede('stock','editar') ? { label: 'Registrar merma', icono: 'trash', click: () => abierto = true } : null" />

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Perdido en el período</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ moneda(resumen.total, 0) }}</p><p class="text-[11px] text-marca-muted">{{ resumen.veces }} registro{{ resumen.veces === 1 ? '' : 's' }} · a costo</p></div>
      <div v-for="r in resumen.por_motivo.slice(0, 3)" :key="r.motivo" class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">{{ r.label }}</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(r.importe, 0) }}</p><p class="text-[11px] text-marca-muted">{{ r.cantidad }} vez{{ r.cantidad === 1 ? '' : 'es' }}</p></div>
    </div>

    <div class="card mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
      <input v-model="f.buscar" @keyup.enter="filtrar" class="input" placeholder="Artículo…" />
      <select v-model="f.motivo" @change="filtrar" class="input"><option value="">Todos los motivos</option><option v-for="(l, k) in motivos" :key="k" :value="k">{{ l }}</option></select>
      <input v-model="f.desde" type="date" @change="filtrar" class="input" /><input v-model="f.hasta" type="date" @change="filtrar" class="input" />
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card p-0 overflow-x-auto lg:col-span-2">
        <table class="table">
          <thead><tr><th>Fecha</th><th>Artículo</th><th class="text-right">Cantidad</th><th>Motivo</th><th class="text-right">Costo</th><th>Quién</th></tr></thead>
          <tbody>
            <tr v-for="m in lista.data" :key="m.id">
              <td class="whitespace-nowrap">{{ m.fecha }}</td>
              <td><p class="font-semibold">{{ m.articulo }}</p><p class="text-xs text-marca-muted">{{ m.sku }}<span v-if="m.deposito"> · {{ m.deposito }}</span><span v-if="m.notas"> · {{ m.notas }}</span></p></td>
              <td class="text-right tabular-nums">{{ cantidad(m.cantidad) }} {{ m.unidad }}</td>
              <td><span class="badge" :class="m.motivo_key === 'cortesia' || m.motivo_key === 'consumo_personal' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ m.motivo }}</span><span v-if="m.comanda" class="text-[10px] text-marca-muted ml-1">desde la comanda</span></td>
              <td class="text-right tabular-nums font-semibold">{{ moneda(m.importe) }}</td>
              <td class="text-xs text-marca-muted">{{ m.usuario }}</td>
            </tr>
            <tr v-if="!lista.data.length"><td colspan="6" class="!py-0"><EstadoVacio texto="Ninguna merma registrada en el período. Ojalá siga así: cuando algo se tira, se rompe o se invita, registralo acá para saber cuánto cuesta." :filtros="{ buscar: f.buscar, motivo: f.motivo }" :accion="puede('stock','editar') ? { label: 'Registrar merma', click: () => abierto = true } : null" /></td></tr>
          </tbody>
        </table>
        <Paginacion :links="lista.links" :desde="lista.from" :hasta="lista.to" :total="lista.total" />
      </div>
      <div class="space-y-4">
        <div class="card">
          <h2 class="font-bold mb-2">Por motivo</h2>
          <div v-if="!resumen.por_motivo.length" class="text-sm text-marca-muted">Nada en el período.</div>
          <div v-for="r in resumen.por_motivo" :key="r.motivo" class="flex justify-between py-1.5 border-t border-marca-borde/60 text-sm"><span>{{ r.label }} <span class="text-xs text-marca-muted">× {{ r.cantidad }}</span></span><b class="tabular-nums">{{ moneda(r.importe, 0) }}</b></div>
        </div>
        <div class="card">
          <h2 class="font-bold mb-2">Lo que más se pierde</h2>
          <div v-if="!resumen.por_articulo.length" class="text-sm text-marca-muted">Nada en el período.</div>
          <div v-for="a in resumen.por_articulo" :key="a.nombre" class="flex justify-between py-1.5 border-t border-marca-borde/60 text-sm"><span class="truncate pr-2">{{ a.nombre }} <span class="text-xs text-marca-muted">{{ cantidad(a.cantidad) }} {{ a.unidad }}</span></span><b class="tabular-nums shrink-0">{{ moneda(a.importe, 0) }}</b></div>
        </div>
      </div>
    </div>

    <Modal :abierto="abierto" titulo="Registrar merma" @cerrar="abierto = false">
      <div class="space-y-3 text-sm">
        <div><label class="label">Artículo</label><input v-model="buscarArt" class="input mb-1" placeholder="Escribí para buscar…" data-e2e="merma-buscar" /><select v-model="form.product_id" class="input" size="5" data-e2e="merma-articulo"><option v-for="a in articulosFiltrados" :key="a.id" :value="a.id">{{ a.name }} · {{ a.sku }} · stock {{ cantidad(a.stock) }} {{ a.unit }}</option></select></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Cantidad</label><input v-model.number="form.cantidad" type="number" step="any" min="0.001" class="input" data-e2e="merma-cantidad" /></div>
          <div><label class="label">Depósito</label><select v-model="form.deposito_id" class="input"><option :value="null">El principal</option><option v-for="d in depositos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></div>
        </div>
        <div><label class="label">Motivo</label><select v-model="form.motivo" class="input" data-e2e="merma-motivo"><option v-for="(l, k) in motivos" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="label">Nota</label><input v-model="form.notas" class="input" placeholder="Qué pasó" /></div>
        <p v-if="form.errors.product_id || form.errors.cantidad" class="text-carmin text-xs">{{ form.errors.product_id || form.errors.cantidad }}</p>
      </div>
      <template #pie><button class="btn-secondary" @click="abierto = false">Cancelar</button><button class="btn-primary" :disabled="form.processing || !form.product_id || !form.cantidad" data-e2e="merma-guardar" @click="form.post('/stock/mermas', { preserveScroll: true, onSuccess: () => { abierto = false; form.reset(); buscarArt = '' } })">Registrar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import Modal from '@/Components/Modal.vue'
import Paginacion from '@/Components/Paginacion.vue'
import { moneda, cantidad } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ lista: Object, resumen: Object, filtros: Object, motivos: Object, depositos: Array, articulos: Array })
const { puede } = usePermisos()
const f = reactive({ buscar: props.filtros.buscar ?? '', motivo: props.filtros.motivo ?? '', desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '' })
function filtrar() { router.get('/stock/mermas', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }) }
const abierto = ref(false), buscarArt = ref('')
const norm = s => (s ?? '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
const articulosFiltrados = computed(() => { const t = norm(buscarArt.value.trim()); return (t ? props.articulos.filter(a => norm(a.name + ' ' + a.sku).includes(t)) : props.articulos).slice(0, 60) })
const form = useForm({ product_id: null, deposito_id: null, cantidad: null, motivo: 'tirado', notas: '' })
</script>
