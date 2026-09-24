<template>
  <AppLayout :titulo="obra.codigo">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <p class="text-xs font-bold uppercase tracking-widest text-marca-muted"><Link href="/obras" class="hover:underline">Obras</Link> · {{ obra.codigo }}</p>
        <h1 class="page-title">{{ obra.nombre }}</h1>
        <p class="page-subtitle">{{ obra.cliente || 'Obra propia' }}<span v-if="obra.direccion"> · {{ obra.direccion }}</span><span v-if="obra.responsable"> · {{ obra.responsable }}</span> · <span class="badge" :class="{ 'bg-gris-light text-marca-muted': obra.estado === 'presupuestado', 'bg-violeta/10 text-violeta': obra.estado === 'en_curso', 'bg-amber-50 text-amber-700': obra.estado === 'pausado', 'bg-emerald-50 text-emerald-700': obra.estado === 'terminado', 'bg-red-50 text-carmin': obra.estado === 'cancelado' }">{{ estados[obra.estado] }}</span></p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary" @click="editar = true">Editar</button>
        <Link :href="`/comprobantes/nuevo?proyecto_id=${obra.id}${obra.contact_id ? '&contact_id=' + obra.contact_id : ''}`" class="btn-secondary">Facturar a mano</Link>
        <button class="btn-primary" :disabled="!obra.contact_id || !obra.presupuesto_venta" @click="certificarAbierto = true">Certificar avance</button>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Avance físico</p><p class="text-xl font-extrabold tabular-nums">{{ obra.avance }}%</p><p class="text-xs text-marca-muted">certificado {{ obra.avance_certificado }}%</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Costo real</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.costo_real, 0) }}</p><p class="text-xs text-marca-muted">previsto {{ moneda(obra.presupuesto_costo, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Desvío al avance</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.desvio_costo > 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(resumen.desvio_costo, 0) }}</p><p class="text-xs text-marca-muted">debería ir en {{ moneda(resumen.costo_esperado_avance, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Facturado / cobrado</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(resumen.facturado, 0) }}</p><p class="text-xs text-marca-muted">cobrado {{ moneda(resumen.cobrado, 0) }} · por certificar {{ moneda(resumen.por_certificar, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Margen proyectado</p><p class="text-xl font-extrabold tabular-nums" :class="resumen.margen_proyectado < 0 ? 'text-carmin' : 'text-emerald-700'">{{ moneda(resumen.margen_proyectado, 0) }}</p><p class="text-xs text-marca-muted">previsto {{ moneda(resumen.margen_previsto, 0) }} · real hoy {{ moneda(resumen.margen_real, 0) }}</p></div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-0 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Partes de obra</h2><button class="btn-primary !py-1 text-xs" @click="parteAbierto = true">Cargar parte</button></div>
          <table class="table text-sm">
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Detalle</th><th class="text-right">Cant.</th><th class="text-right">Costo</th><th class="text-right">Total</th><th></th></tr></thead>
            <tbody>
              <tr v-for="p in partes" :key="p.id">
                <td class="tabular-nums text-xs">{{ p.fecha }}</td><td class="text-xs">{{ tiposParte[p.tipo] }}</td><td><p class="font-medium">{{ p.descripcion }}</p><p class="text-xs text-marca-muted">{{ p.empleado || p.usuario }}<span v-if="p.stock"> · salió del stock</span></p></td>
                <td class="text-right tabular-nums">{{ cantidad(p.cantidad) }} {{ p.unidad }}</td><td class="text-right tabular-nums">{{ moneda(p.costo_unit) }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(p.total) }}</td>
                <td class="text-right"><button class="btn-ghost !px-2 text-xs text-carmin" @click="router.post(`/obras/${obra.id}/partes/${p.id}/borrar`, {}, { preserveScroll: true })">Quitar</button></td>
              </tr>
              <tr v-if="!partes.length"><td colspan="7" class="text-center text-marca-muted py-6">Todavía no hay partes. Cargá materiales, horas y gastos a medida que avanza la obra.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="grid md:grid-cols-2 gap-4">
          <div class="card p-0 overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Compras imputadas</h2><button class="btn-ghost !py-1 text-xs" @click="vincularAbierto = true">+ Imputar</button></div>
            <div class="divide-y divide-marca-borde/60 text-sm"><Link v-for="c in resumen.compras" :key="c.id" :href="`/proveedores/compras/${c.id}`" class="flex justify-between px-4 py-2 hover:bg-gris-light/40"><span><b>{{ c.numero }}</b> <span class="text-xs text-marca-muted">{{ c.proveedor }} · {{ c.fecha }}</span></span><span class="tabular-nums">{{ moneda(c.neto, 0) }}</span></Link><p v-if="!resumen.compras.length" class="px-4 py-4 text-xs text-marca-muted">Sin facturas de compra imputadas.</p></div>
          </div>
          <div class="card p-0 overflow-hidden">
            <div class="px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Facturas de la obra</h2></div>
            <div class="divide-y divide-marca-borde/60 text-sm"><Link v-for="c in resumen.ventas" :key="c.id" :href="`/comprobantes/${c.id}`" class="flex justify-between px-4 py-2 hover:bg-gris-light/40"><span><b>{{ c.numero }}</b> <span class="text-xs text-marca-muted">{{ c.fecha }}</span></span><span class="tabular-nums">{{ moneda(c.total, 0) }}<span v-if="c.saldo > 0" class="text-xs text-carmin"> · debe {{ moneda(c.saldo, 0) }}</span></span></Link><p v-if="!resumen.ventas.length" class="px-4 py-4 text-xs text-marca-muted">Todavía no se facturó nada.</p></div>
          </div>
        </div>
      </div>
      <div class="space-y-4">
        <div class="card">
          <h2 class="font-bold mb-2">Costeo por rubro</h2>
          <div class="space-y-2 text-sm">
            <div v-for="(t, k) in resumen.por_tipo" :key="k"><div class="flex justify-between"><span class="text-marca-muted">{{ t.label }}</span><b class="tabular-nums">{{ moneda(t.real, 0) }}</b></div><div class="h-1.5 rounded-full bg-gris-light overflow-hidden"><div class="h-full bg-violeta" :style="{ width: (resumen.costo_real ? t.real / resumen.costo_real * 100 : 0) + '%' }"></div></div></div>
            <div class="flex justify-between font-extrabold pt-2 border-t border-marca-borde"><span>Total real</span><span class="tabular-nums">{{ moneda(resumen.costo_real, 0) }}</span></div>
            <div class="flex justify-between text-xs text-marca-muted"><span>Presupuesto de costo</span><span class="tabular-nums">{{ moneda(obra.presupuesto_costo, 0) }}</span></div>
          </div>
        </div>
        <div v-if="obra.descripcion || obra.notas" class="card text-sm"><h2 class="font-bold mb-1">Alcance</h2><p class="whitespace-pre-line text-marca-muted text-xs">{{ obra.descripcion }}</p><p v-if="obra.notas" class="whitespace-pre-line text-xs mt-2">{{ obra.notas }}</p></div>
      </div>
    </div>

    <Modal :abierto="parteAbierto" titulo="Cargar parte de obra" ancho="max-w-2xl" @cerrar="parteAbierto = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Tipo</label><select v-model="pf.tipo" class="input"><option v-for="(l, k) in tiposParte" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="label">Fecha</label><input v-model="pf.fecha" type="date" class="input" /></div>
        <div v-if="pf.tipo === 'material'" class="sm:col-span-2"><label class="label">Artículo del stock</label><select v-model="pf.product_id" class="input" @change="alElegirProducto"><option :value="null">Sin artículo (cargar a mano)</option><option v-for="p in productos.filter(x => x.tipo !== 'servicio')" :key="p.id" :value="p.id">{{ p.name }} · stock {{ p.stock }} · costo {{ moneda(p.cost, 0) }}</option></select></div>
        <div v-if="pf.tipo === 'mano_obra'" class="sm:col-span-2"><label class="label">Empleado</label><select v-model="pf.empleado_id" class="input" @change="alElegirEmpleado"><option :value="null">Cuadrilla / sin detalle</option><option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.nombre }}</option></select></div>
        <div class="sm:col-span-2"><label class="label">Descripción</label><input v-model="pf.descripcion" class="input" :placeholder="pf.tipo === 'mano_obra' ? 'Colocación de cerámicos' : 'Detalle'" /></div>
        <div><label class="label">Cantidad</label><input v-model.number="pf.cantidad" type="number" step="any" min="0" class="input" /></div>
        <div><label class="label">Unidad</label><input v-model="pf.unidad" class="input" :placeholder="pf.tipo === 'mano_obra' ? 'h' : 'un'" /></div>
        <div><label class="label">Costo unitario</label><input v-model.number="pf.costo_unit" type="number" step="any" min="0" class="input" /><p class="text-[10px] text-marca-muted mt-0.5">{{ pf.tipo === 'material' && pf.product_id ? 'Vacío = costo del artículo' : pf.tipo === 'mano_obra' ? 'Costo hora (básico / 200 × 1,5 por cargas)' : '' }}</p></div>
        <p class="sm:col-span-2 text-sm">Total: <b class="tabular-nums">{{ moneda((pf.cantidad || 0) * (pf.costo_unit || 0)) }}</b></p>
      </div>
      <template #pie><button class="btn-secondary" @click="parteAbierto = false">Cancelar</button><button class="btn-primary" :disabled="pf.processing || !pf.cantidad" @click="pf.post(`/obras/${obra.id}/partes`, { preserveScroll: true, onSuccess: () => { parteAbierto = false; pf.reset('descripcion', 'cantidad', 'costo_unit', 'product_id', 'empleado_id') } })">Cargar</button></template>
    </Modal>

    <Modal :abierto="certificarAbierto" titulo="Certificar avance" @cerrar="certificarAbierto = false">
      <p class="text-sm mb-3">Avance certificado hasta ahora: <b>{{ obra.avance_certificado }}%</b>. Se factura al cliente la parte del presupuesto ({{ moneda(obra.presupuesto_venta, 0) }}) entre ese porcentaje y el nuevo.</p>
      <div class="grid grid-cols-2 gap-3"><div><label class="label">Certificar hasta %</label><input v-model.number="cf.avance" type="number" min="0" max="100" class="input" /></div><div><label class="label">Condición</label><select v-model="cf.condicion" class="input"><option value="cta_cte">Cuenta corriente</option><option value="contado">Contado</option></select></div></div>
      <p class="text-sm mt-3">Importe del certificado: <b class="tabular-nums">{{ moneda(Math.max(0, (cf.avance || 0) - obra.avance_certificado) * obra.presupuesto_venta / 100) }}</b></p>
      <template #pie><button class="btn-secondary" @click="certificarAbierto = false">Cancelar</button><button class="btn-primary" :disabled="cf.processing || !(cf.avance > obra.avance_certificado)" @click="cf.post(`/obras/${obra.id}/certificar`)">Emitir factura</button></template>
    </Modal>

    <Modal :abierto="vincularAbierto" titulo="Imputar factura de compra a la obra" @cerrar="vincularAbierto = false">
      <select v-model="vinc.comprobante_id" class="input"><option :value="null">Elegí la factura…</option><option v-for="c in comprasSinObra" :key="c.id" :value="c.id">{{ c.label }}</option></select>
      <template #pie><button class="btn-secondary" @click="vincularAbierto = false">Cancelar</button><button class="btn-primary" :disabled="!vinc.comprobante_id" @click="vinc.post(`/obras/${obra.id}/vincular`, { preserveScroll: true, onSuccess: () => (vincularAbierto = false) })">Imputar</button></template>
    </Modal>

    <ObraModal :abierto="editar" :obra="obra" :clientes="clientes" :usuarios="usuarios" :estados="estados" @cerrar="editar = false" />
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import ObraModal from '@/Pages/Obras/ObraModal.vue'
import { moneda, cantidad, hoyISO } from '@/util/formato'
const props = defineProps({ obra: Object, resumen: Object, estados: Object, tiposParte: Object, partes: Array, productos: Array, empleados: Array, clientes: Array, usuarios: Array, comprasSinObra: Array })
const parteAbierto = ref(false), certificarAbierto = ref(false), vincularAbierto = ref(false), editar = ref(false)
const pf = useForm({ tipo: 'material', fecha: hoyISO(), product_id: null, empleado_id: null, descripcion: '', cantidad: 1, unidad: '', costo_unit: null })
function alElegirProducto() { const p = props.productos.find(x => x.id === pf.product_id); if (p) { pf.descripcion = p.name; pf.unidad = p.unit; pf.costo_unit = p.cost } }
function alElegirEmpleado() { const e = props.empleados.find(x => x.id === pf.empleado_id); if (e) { pf.unidad = 'h'; pf.costo_unit = Math.round(e.sueldo_basico / 200 * 1.5) } }
const cf = useForm({ avance: Math.min(100, props.obra.avance || props.obra.avance_certificado + 10), condicion: 'cta_cte' })
const vinc = useForm({ comprobante_id: null })
</script>
