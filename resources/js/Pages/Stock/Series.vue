<template>
  <AppLayout titulo="Números de serie">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <Link href="/stock" class="text-xs text-marca-muted hover:text-carmin">← Stock</Link>
        <h1 class="page-title">Números de serie</h1>
        <p class="page-subtitle">Cada equipo con su historia: de qué proveedor vino, a quién se vendió, hasta cuándo tiene garantía y qué services tuvo. Escaneá o escribí la serie.</p>
      </div>
    </div>
    <div class="grid grid-cols-3 gap-3 mb-4">
      <button class="card py-3 text-left" @click="ir({ estado: 'en_stock' })"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En stock</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.en_stock }}</p></button>
      <button class="card py-3 text-left" @click="ir({ estado: 'vendidos' })"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Vendidos</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.vendidos }}</p></button>
      <button class="card py-3 text-left" @click="ir({ estado: 'garantia' })"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En garantía</p><p class="text-xl font-extrabold tabular-nums text-emerald-700">{{ kpis.garantia }}</p></button>
    </div>
    <div class="card flex flex-wrap gap-2 items-end mb-4">
      <div class="flex-1 min-w-[14rem]"><label class="label">Serie, artículo o cliente</label><input ref="bus" v-model="f.buscar" class="input font-mono" placeholder="Escaneá la serie y Enter" data-e2e="buscar-serie" @keydown.enter="ir(f)" /></div>
      <div><label class="label">Ver</label><select v-model="f.estado" class="input" @change="ir(f)"><option :value="undefined">Todas</option><option value="en_stock">En stock</option><option value="vendidos">Vendidas</option><option value="garantia">En garantía</option></select></div>
    </div>
    <div class="card p-0 overflow-x-auto">
      <table class="table text-sm min-w-[820px]">
        <thead><tr><th>Serie</th><th>Artículo</th><th>Estado</th><th>Cliente</th><th>Vendido</th><th>Garantía</th><th></th></tr></thead>
        <tbody>
          <tr v-for="s in series" :key="s.id" class="hover:bg-gris-light/40 cursor-pointer" @click="abrir(s)">
            <td class="font-mono font-bold">{{ s.serie }}</td><td>{{ s.producto }}<span class="block text-xs text-marca-muted">{{ s.sku }}</span></td>
            <td><span class="badge" :class="clase[s.estado]">{{ nombres[s.estado] }}</span></td>
            <td>{{ s.cliente || '—' }}</td><td class="text-xs">{{ s.vendido_en || '—' }}</td>
            <td class="text-xs"><span v-if="s.garantia_hasta" :class="s.en_garantia ? 'text-emerald-700 font-semibold' : 'text-marca-muted'">{{ s.en_garantia ? 'hasta ' : 'venció ' }}{{ s.garantia_hasta }}</span><span v-else class="text-marca-muted">—</span></td>
            <td class="text-right text-xs text-violeta font-semibold">Ver</td>
          </tr>
          <tr v-if="!series.length"><td colspan="7" class="text-center text-marca-muted py-8">Sin números de serie para mostrar. Se cargan al recibir la compra de un artículo marcado "Con número de serie".</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :abierto="!!sel" :titulo="sel ? `S/N ${sel.serie}` : ''" ancho="max-w-3xl" @cerrar="sel = null">
      <div v-if="det" data-e2e="ficha-serie">
        <div class="grid sm:grid-cols-2 gap-3 text-sm mb-4">
          <div class="rounded-xl bg-marca-fondo p-3"><p class="text-[11px] text-marca-muted uppercase tracking-widest">Equipo</p><p class="font-bold">{{ det.producto }}</p><p class="text-xs text-marca-muted">Entró {{ det.ingreso || '—' }}{{ det.proveedor ? ' de ' + det.proveedor : '' }}</p></div>
          <div class="rounded-xl p-3" :class="det.en_garantia ? 'bg-emerald-50' : 'bg-marca-fondo'"><p class="text-[11px] text-marca-muted uppercase tracking-widest">Venta y garantía</p>
            <p v-if="det.cliente" class="font-bold"><Link :href="`/clientes/${det.cliente_id}`" class="hover:text-carmin">{{ det.cliente }}</Link></p><p v-else class="font-bold">{{ nombres[det.estado] }}</p>
            <p class="text-xs"><Link v-if="det.comprobante_id" :href="`/comprobantes/${det.comprobante_id}`" class="hover:text-carmin">{{ det.comprobante }}</Link> {{ det.vendido_en ? '· ' + det.vendido_en : '' }}</p>
            <p v-if="det.garantia_hasta" class="text-xs font-semibold" :class="det.en_garantia ? 'text-emerald-700' : 'text-carmin'" data-e2e="garantia-estado">{{ det.en_garantia ? 'En garantía hasta ' : 'Garantía vencida el ' }}{{ det.garantia_hasta }}</p>
          </div>
        </div>
        <div class="rounded-xl border border-marca-borde p-3 mb-4">
          <div class="flex justify-between items-center mb-1"><p class="font-bold text-sm">Ingresar a servicio técnico</p><Link :href="`/stock/etiquetas?lotes=${det.id}`" class="text-xs text-violeta font-semibold">Etiqueta de la serie</Link></div>
          <div class="flex gap-2"><input v-model="falla" class="input !py-1.5 flex-1" placeholder="¿Qué falla tiene?" data-e2e="falla" /><button class="btn-primary !py-1.5 text-xs" :disabled="!falla" data-e2e="crear-servicio" @click="router.post(`/stock/series/${det.id}/servicio`, { falla })">Crear orden</button></div>
          <div v-if="det.servicios.length" class="mt-2 text-xs space-y-1"><p class="text-marca-muted">Services anteriores:</p><Link v-for="o in det.servicios" :key="o.id" :href="`/servicios/${o.id}`" class="block hover:text-carmin">{{ o.numero }} · {{ o.fecha }} · {{ o.estado }} · {{ o.falla }}</Link></div>
        </div>
        <p class="font-bold text-sm mb-1">Historia</p>
        <table class="table text-xs"><tbody>
          <tr v-for="m in det.movimientos" :key="m.id"><td class="whitespace-nowrap">{{ m.fecha }}</td><td><Link v-if="m.comprobante_id" :href="m.direccion === 'compra' ? `/proveedores/compras/${m.comprobante_id}` : `/comprobantes/${m.comprobante_id}`" class="hover:text-carmin">{{ m.comprobante }}</Link><span v-else>{{ m.motivo }}</span></td><td>{{ m.contacto || '—' }}</td><td class="text-right" :class="m.cantidad < 0 ? 'text-carmin' : 'text-emerald-700'">{{ m.cantidad < 0 ? 'salió' : 'entró' }}</td></tr>
        </tbody></table>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'

const props = defineProps({ series: Array, filtros: Object, kpis: Object })
const f = reactive({ buscar: props.filtros?.buscar ?? '', estado: props.filtros?.estado })
const nombres = { en_stock: 'En stock', vendido: 'Vendido', bloqueado: 'Bloqueado', retirado: 'Retirado', sin_stock: 'Sin stock' }
const clase = { en_stock: 'bg-emerald-50 text-emerald-700', vendido: 'bg-violeta/10 text-violeta', bloqueado: 'bg-amber-50 text-amber-700', retirado: 'bg-carmin-light text-carmin', sin_stock: 'bg-gris-light text-marca-muted' }
const bus = ref(null)
onMounted(() => { bus.value?.focus(); if (props.series.length === 1 && f.buscar) abrir(props.series[0]) })
function ir(q) { router.get('/stock/series', { ...props.filtros, ...q }, { preserveScroll: true }) }
const sel = ref(null), det = ref(null), falla = ref('')
async function abrir(s) { sel.value = s; det.value = null; falla.value = ''; det.value = (await window.axios.get(`/stock/series/${s.id}`)).data }
</script>
