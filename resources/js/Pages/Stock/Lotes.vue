<template>
  <AppLayout titulo="Lotes y vencimientos">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <Link href="/stock" class="text-xs text-marca-muted hover:text-carmin">← Stock</Link>
        <h1 class="page-title">Lotes y vencimientos</h1>
        <p class="page-subtitle">Qué vence, qué ya venció y qué está bloqueado. La venta sale primero de lo que vence antes; los vencidos y bloqueados no se venden. Desde cada lote ves de qué proveedor vino y a qué clientes fue.</p>
      </div>
      <button class="btn-secondary" @click="cfgAbierto = true">Configuración</button>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
      <button class="card py-3 text-left" :class="filtros.filtro === 'vencidos' ? 'ring-2 ring-carmin' : ''" @click="ir({ filtro: 'vencidos' })"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vencidos con stock</p><p class="text-xl font-extrabold tabular-nums text-carmin">{{ kpis.vencidos }}</p><p class="text-xs text-marca-muted">{{ moneda(kpis.vencidos_valor, 0) }} al costo</p></button>
      <button class="card py-3 text-left" :class="filtros.filtro === 'por_vencer' ? 'ring-2 ring-amber-400' : ''" @click="ir({ filtro: 'por_vencer' })"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vencen en {{ filtros.dias }} días</p><p class="text-xl font-extrabold tabular-nums text-amber-700">{{ kpis.por_vencer }}</p><p class="text-xs text-marca-muted">{{ moneda(kpis.por_vencer_valor, 0) }} al costo</p></button>
      <button class="card py-3 text-left col-span-2 md:col-span-1" :class="filtros.filtro === 'bloqueados' ? 'ring-2 ring-violeta' : ''" @click="ir({ filtro: 'bloqueados' })"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Bloqueados o retirados</p><p class="text-xl font-extrabold tabular-nums text-violeta">{{ kpis.bloqueados }}</p><p class="text-xs text-marca-muted">no se venden</p></button>
    </div>

    <div class="card flex flex-wrap gap-2 items-end mb-4">
      <div class="flex-1 min-w-[12rem]"><label class="label">Buscar</label><input v-model="f.buscar" class="input" placeholder="Artículo, código o número de lote" data-e2e="buscar-lote" @keydown.enter="ir(f)" /></div>
      <div><label class="label">Ver</label><select v-model="f.filtro" class="input" @change="ir(f)"><option value="atencion">Requieren atención</option><option value="por_vencer">Por vencer</option><option value="vencidos">Vencidos</option><option value="bloqueados">Bloqueados</option><option value="todos">Todos los lotes</option></select></div>
      <div><label class="label">Días</label><select v-model.number="f.dias" class="input" @change="ir(f)"><option v-for="d in [7, 15, 30, 60, 90, 180]" :key="d" :value="d">{{ d }}</option></select></div>
      <div><label class="label">Depósito</label><select v-model="f.deposito" class="input" @change="ir(f)"><option :value="null">Todos</option><option v-for="d in depositos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <table class="table text-sm min-w-[860px]">
        <thead><tr><th>Artículo</th><th>Lote / serie</th><th>Vence</th><th class="text-right">Stock</th><th class="text-right">Valor</th><th>Depósito</th><th>Proveedor</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <tr v-for="l in lotes" :key="l.id" class="hover:bg-gris-light/40 cursor-pointer" @click="abrir(l)">
            <td><span class="font-medium">{{ l.producto }}</span><span class="block text-xs text-marca-muted">{{ l.sku }}</span></td>
            <td class="font-mono text-xs">{{ l.serie ? 'S/N ' + l.serie : (l.lote || 'sin número') }}</td>
            <td class="whitespace-nowrap"><template v-if="l.vencimiento">{{ fmt(l.vencimiento) }} <span class="badge ml-1" :class="l.dias < 0 ? 'bg-carmin-light text-carmin' : l.dias <= 7 ? 'bg-amber-50 text-amber-700' : 'bg-gris-light text-marca-muted'">{{ l.dias < 0 ? `venció hace ${-l.dias} d` : l.dias === 0 ? 'vence hoy' : `${l.dias} d` }}</span></template><span v-else class="text-marca-muted">—</span></td>
            <td class="text-right tabular-nums">{{ cantidad(l.cantidad) }} {{ l.unidad }}</td>
            <td class="text-right tabular-nums text-marca-muted">{{ moneda(l.valor, 0) }}</td>
            <td class="text-xs">{{ l.deposito }}</td><td class="text-xs">{{ l.proveedor || '—' }}</td>
            <td><span class="badge" :class="{ disponible: 'bg-emerald-50 text-emerald-700', bloqueado: 'bg-violeta/10 text-violeta', retirado: 'bg-carmin-light text-carmin' }[l.estado]" :title="l.motivo">{{ estados[l.estado] }}</span></td>
            <td class="text-right text-xs text-violeta font-semibold">Ver</td>
          </tr>
          <tr v-if="!lotes.length"><td colspan="9" class="text-center text-marca-muted py-8">Nada para mostrar con este filtro.</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Lote: trazabilidad y acciones -->
    <Modal :abierto="!!sel" :titulo="sel ? `${sel.producto} · ${sel.serie ? 'S/N ' + sel.serie : (sel.lote || 'sin número')}` : ''" ancho="max-w-3xl" @cerrar="sel = null">
      <div v-if="sel" data-e2e="traza">
        <div class="flex flex-wrap gap-2 mb-3">
          <button v-if="sel.estado === 'disponible'" class="btn-secondary !py-1 text-xs" @click="accion = 'bloqueado'">Bloquear</button>
          <button v-if="sel.estado === 'disponible' && sel.lote" class="btn-secondary !py-1 text-xs text-carmin" data-e2e="retirar" @click="accion = 'retirado'">Retiro del mercado</button>
          <button v-if="sel.estado !== 'disponible'" class="btn-secondary !py-1 text-xs" @click="cambiar('disponible')">Habilitar para la venta</button>
          <button class="btn-secondary !py-1 text-xs" data-e2e="baja" @click="baja.abierto = true">Dar de baja</button>
          <a :href="`/stock/lotes/${sel.id}/retiro.csv`" class="btn-ghost !py-1 text-xs">Planilla de clientes</a>
          <Link :href="`/stock/etiquetas?lotes=${sel.id}`" class="btn-ghost !py-1 text-xs">Etiquetas del lote</Link>
        </div>
        <div v-if="accion" class="rounded-xl border border-amber-300 bg-amber-50 p-3 mb-3 text-sm flex flex-wrap items-end gap-2">
          <div class="flex-1 min-w-[14rem]"><label class="label">Motivo</label><input v-model="motivo" class="input !py-1.5" :placeholder="accion === 'retirado' ? 'Disposición ANMAT, alerta del proveedor…' : 'Control de calidad, envase dañado…'" /></div>
          <button class="btn-primary !py-1.5 text-xs" data-e2e="confirmar-estado" @click="cambiar(accion)">{{ accion === 'retirado' ? 'Retirar (todos los depósitos)' : 'Bloquear' }}</button>
          <button class="btn-ghost !py-1.5 text-xs" @click="accion = null">Cancelar</button>
        </div>
        <div v-if="baja.abierto" class="rounded-xl border border-marca-borde bg-marca-fondo p-3 mb-3 text-sm flex flex-wrap items-end gap-2">
          <div><label class="label">Cantidad</label><input v-model.number="baja.cantidad" type="number" step="any" min="0" class="input !py-1.5 !w-28" :placeholder="String(sel.cantidad)" /></div>
          <div class="flex-1 min-w-[12rem]"><label class="label">Motivo</label><input v-model="baja.motivo" class="input !py-1.5" placeholder="Vencido, roto, devolución al proveedor…" /></div>
          <button class="btn-primary !py-1.5 text-xs" data-e2e="confirmar-baja" @click="darBaja">Dar de baja</button>
        </div>
        <p v-if="traza?.lote?.motivo" class="text-xs text-marca-muted mb-2">Motivo: {{ traza.lote.motivo }}</p>
        <div class="grid grid-cols-3 gap-2 mb-3 text-center text-sm">
          <div class="rounded-lg bg-gris-light/60 py-2">Entró<b class="block tabular-nums">{{ cantidad(traza?.entrado ?? 0) }}</b></div>
          <div class="rounded-lg bg-gris-light/60 py-2">Salió<b class="block tabular-nums">{{ cantidad(traza?.salido ?? 0) }}</b></div>
          <div class="rounded-lg bg-gris-light/60 py-2">Queda<b class="block tabular-nums">{{ cantidad(sel.cantidad) }}</b></div>
        </div>
        <template v-if="traza?.clientes?.length">
          <p class="font-bold text-sm mb-1">Clientes que lo recibieron</p>
          <div class="overflow-x-auto mb-3"><table class="table text-xs"><thead><tr><th>Cliente</th><th class="text-right">Cantidad</th><th>Contacto</th></tr></thead><tbody>
            <tr v-for="c in traza.clientes" :key="c.id"><td><Link :href="`/clientes/${c.id}`" class="font-medium hover:text-carmin">{{ c.nombre }}</Link></td><td class="text-right tabular-nums">{{ cantidad(c.cantidad) }}</td><td>{{ [c.telefono, c.email].filter(Boolean).join(' · ') || '—' }}</td></tr>
          </tbody></table></div>
        </template>
        <p class="font-bold text-sm mb-1">Movimientos</p>
        <div class="overflow-x-auto"><table class="table text-xs"><thead><tr><th>Fecha</th><th>Detalle</th><th>Cliente / proveedor</th><th class="text-right">Cantidad</th></tr></thead><tbody>
          <tr v-for="m in traza?.movimientos ?? []" :key="m.id"><td class="whitespace-nowrap">{{ m.fecha }}</td><td><Link v-if="m.comprobante_id" :href="m.direccion === 'compra' ? `/proveedores/compras/${m.comprobante_id}` : `/comprobantes/${m.comprobante_id}`" class="hover:text-carmin">{{ m.comprobante }}</Link><span v-else>{{ m.motivo }}</span></td><td>{{ m.contacto || '—' }}</td><td class="text-right tabular-nums" :class="m.cantidad < 0 ? 'text-carmin' : 'text-emerald-700'">{{ m.cantidad > 0 ? '+' : '' }}{{ cantidad(m.cantidad) }}</td></tr>
          <tr v-if="traza && !traza.movimientos.length"><td colspan="4" class="text-center text-marca-muted py-4">Sin movimientos registrados.</td></tr>
        </tbody></table></div>
      </div>
    </Modal>

    <Modal :abierto="cfgAbierto" titulo="Configuración de lotes" @cerrar="cfgAbierto = false">
      <label class="flex items-center gap-2 text-sm"><input v-model="cfg.bloquear_vencidos" type="checkbox" class="accent-carmin" /> No dejar vender lotes vencidos ni bloqueados</label>
      <p class="text-xs text-marca-muted mt-1 mb-3">Si no hay stock vendible, la factura avisa qué lotes están trabados.</p>
      <label class="flex items-center gap-2 text-sm mb-3"><input v-model="cfg.exigir_serie" type="checkbox" class="accent-carmin" /> Exigir el número de serie en cada venta de artículos con serie</label>
      <label class="label">Avisar cuando falten (días)</label><input v-model.number="cfg.dias_aviso" type="number" min="1" max="365" class="input !w-32" />
      <template #pie><button class="btn-secondary" @click="cfgAbierto = false">Cancelar</button><button class="btn-primary" @click="cfg.post('/stock/lotes/config', { preserveScroll: true, onSuccess: () => (cfgAbierto = false) })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'

const props = defineProps({ lotes: Array, kpis: Object, filtros: Object, depositos: Array, config: Object, estados: Object })
const f = reactive({ ...props.filtros })
const fmt = iso => iso ? iso.split('-').reverse().join('/') : ''
function ir(q) { router.get('/stock/lotes', { ...props.filtros, ...q }, { preserveState: false, preserveScroll: true }) }
const sel = ref(null), traza = ref(null), accion = ref(null), motivo = ref(''), cfgAbierto = ref(false)
const baja = reactive({ abierto: false, cantidad: null, motivo: '' })
const cfg = useForm({ ...props.config })
async function abrir(l) { sel.value = l; accion.value = null; motivo.value = ''; Object.assign(baja, { abierto: false, cantidad: null, motivo: '' }); traza.value = null; traza.value = (await window.axios.get(`/stock/lotes/${l.id}`)).data }
function recargar() { const id = sel.value?.id; router.reload({ preserveScroll: true, onSuccess: () => { const l = props.lotes.find(x => x.id === id); if (l) abrir(l); else sel.value = null } }) }
function cambiar(estado) { router.post(`/stock/lotes/${sel.value.id}/estado`, { estado, motivo: motivo.value }, { preserveScroll: true, onSuccess: recargar }) }
function darBaja() { router.post(`/stock/lotes/${sel.value.id}/baja`, { cantidad: baja.cantidad || null, motivo: baja.motivo }, { preserveScroll: true, onSuccess: recargar }) }
</script>
