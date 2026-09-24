<template>
  <AppLayout :titulo="ot.numero">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <p class="text-xs font-bold uppercase tracking-widest text-marca-muted"><Link href="/servicios" class="hover:underline">Servicio técnico</Link> · {{ ot.numero }}</p>
        <h1 class="page-title">{{ ot.equipo }} <span v-if="ot.marca_modelo" class="text-marca-muted font-normal">· {{ ot.marca_modelo }}</span></h1>
        <p class="page-subtitle">{{ ot.cliente }}<span v-if="ot.telefono"> · {{ ot.telefono }}</span> · ingresó {{ ot.ingreso }}<span v-if="ot.prometida"> · prometido {{ ot.prometida }}</span> · <span class="badge" :class="claseEstado">{{ estados[ot.estado] }}</span><span v-if="ot.prioridad === 'alta'" class="badge bg-red-50 text-carmin ml-1">Urgente</span></p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary" @click="copiar">Link del cliente</button>
        <button class="btn-secondary" @click="editar = true">Editar</button>
        <template v-if="!ot.comprobante">
          <button v-if="ot.estado === 'recibido'" class="btn-secondary" @click="estado('diagnostico')">Diagnosticar</button>
          <button v-if="['recibido', 'diagnostico'].includes(ot.estado)" class="btn-violeta" :disabled="!ot.presupuesto" title="Manda el presupuesto al cliente para que lo apruebe" @click="estado('presupuestado')">Enviar presupuesto</button>
          <button v-if="ot.estado === 'presupuestado'" class="btn-secondary" @click="estado('aprobado')">Aprobado (por teléfono)</button>
          <button v-if="['aprobado', 'presupuestado'].includes(ot.estado)" class="btn-secondary" @click="estado('en_curso')">Empezar reparación</button>
          <button v-if="ot.estado === 'en_curso'" class="btn-secondary" @click="estado('listo')">Listo · avisar</button>
          <button v-if="['listo', 'en_curso', 'aprobado'].includes(ot.estado)" class="btn-primary" @click="facturarAbierto = true">Entregar y facturar</button>
          <button v-if="!['cancelado', 'entregado'].includes(ot.estado)" class="btn-ghost text-carmin" @click="estado('cancelado')">Cancelar</button>
        </template>
        <Link v-else :href="`/comprobantes/${ot.comprobante.id}`" class="btn-primary">{{ ot.comprobante.label }}<span v-if="ot.comprobante.saldo > 0"> · cobrar</span></Link>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card grid sm:grid-cols-2 gap-3 text-sm">
          <div><p class="label">Falla reportada</p><p>{{ ot.falla }}</p></div>
          <div><p class="label">Diagnóstico</p><p v-if="ot.diagnostico">{{ ot.diagnostico }}</p><button v-else class="text-xs text-violeta font-semibold" @click="editar = true">Cargar diagnóstico y presupuesto</button></div>
          <div><p class="label">Técnico</p><p>{{ ot.tecnico || 'Sin asignar' }}</p></div>
          <div><p class="label">Presupuesto</p><p class="font-extrabold text-lg tabular-nums">{{ moneda(ot.presupuesto) }}<span v-if="ot.aprobado_en" class="text-xs text-emerald-700 font-semibold ml-2">aprobado {{ ot.aprobado_en }}</span></p></div>
          <div v-if="ot.serie"><p class="label">N° de serie</p><p>{{ ot.serie }}</p></div>
          <div v-if="ot.notas" class="sm:col-span-2"><p class="label">Notas</p><p class="whitespace-pre-line">{{ ot.notas }}</p></div>
        </div>

        <div class="card p-0 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Hoja de trabajo</h2><span class="text-sm">Total <b class="tabular-nums">{{ moneda(ot.total_items) }}</b></span></div>
          <table class="table text-sm">
            <thead><tr><th>Ítem</th><th>Tipo</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Total</th><th></th></tr></thead>
            <tbody>
              <tr v-for="i in ot.items" :key="i.id"><td><p class="font-medium">{{ i.descripcion }}</p><p v-if="i.sku" class="text-xs text-marca-muted">{{ i.sku }}</p></td><td class="text-xs">{{ i.tipo === 'mano_obra' ? 'Mano de obra' : 'Repuesto' }}</td><td class="text-right tabular-nums">{{ cantidad(i.cantidad) }}</td><td class="text-right tabular-nums">{{ moneda(i.precio_unit) }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(i.total) }}</td><td class="text-right"><button v-if="!ot.comprobante" class="btn-ghost !px-2 text-xs text-carmin" @click="router.post(`/servicios/${ot.id}/items/${i.id}/borrar`, {}, { preserveScroll: true })">Quitar</button></td></tr>
              <tr v-if="!ot.comprobante"><td colspan="6" class="!p-2">
                <div class="grid grid-cols-[1fr_90px_110px_auto] gap-2 items-end">
                  <div><select v-model="it.product_id" class="input !py-1 text-xs" @change="alElegirProducto"><option :value="null">Repuesto o servicio del catálogo…</option><option v-for="p in productos" :key="p.id" :value="p.id">{{ p.name }} · {{ moneda(p.precio, 0) }}</option></select><input v-model="it.descripcion" class="input !py-1 text-xs mt-1" placeholder="o describilo a mano (ej. Mano de obra 2 h)" /></div>
                  <input v-model.number="it.cantidad" type="number" step="any" min="0" class="input !py-1 text-xs text-right" placeholder="Cant." /><input v-model.number="it.precio_unit" type="number" step="any" min="0" class="input !py-1 text-xs text-right" placeholder="Precio" />
                  <button class="btn-primary !py-1 text-xs" :disabled="it.processing || !it.cantidad || (!it.product_id && !it.descripcion)" @click="it.post(`/servicios/${ot.id}/items`, { preserveScroll: true, onSuccess: () => it.reset() })">Agregar</button>
                </div>
                <label class="flex items-center gap-1.5 text-[11px] text-marca-muted mt-1"><input v-model="it.actualizar_presupuesto" type="checkbox" class="accent-carmin" /> Actualizar el presupuesto con el total de la hoja</label>
              </td></tr>
            </tbody>
          </table>
        </div>

        <div class="card">
          <h2 class="font-bold mb-2">Firma del cliente</h2>
          <div v-if="ot.firma" class="flex items-center gap-4"><img :src="ot.firma" class="h-20 border border-marca-borde rounded-lg bg-white" /><p class="text-sm">{{ ot.firma_nombre }}<br><span class="text-xs text-marca-muted">Conformidad de entrega</span></p></div>
          <template v-else>
            <p class="text-xs text-marca-muted mb-2">Al entregar, el cliente firma acá con el dedo o el mouse.</p>
            <canvas ref="lienzo" width="600" height="180" class="w-full max-w-xl border border-marca-borde rounded-xl bg-white touch-none" @pointerdown="pd" @pointermove="pm" @pointerup="dibujando = false" @pointerleave="dibujando = false"></canvas>
            <div class="flex flex-wrap gap-2 mt-2 items-center"><input v-model="firmaNombre" class="input !w-56 !py-1 text-xs" placeholder="Nombre de quien firma" /><button class="btn-ghost !py-1 text-xs" @click="limpiar">Limpiar</button><button class="btn-secondary !py-1 text-xs" :disabled="!trazos" @click="guardarFirma">Guardar firma</button></div>
          </template>
        </div>
      </div>
      <div class="space-y-4">
        <div class="card">
          <h2 class="font-bold mb-2">Tareas</h2>
          <div class="space-y-1.5">
            <label v-for="t in ot.tareas" :key="t.id" class="flex items-start gap-2 text-sm cursor-pointer"><input type="checkbox" class="accent-emerald-600 mt-0.5" :checked="t.hecha" @change="router.post(`/servicios/${ot.id}/tareas/${t.id}/hecha`, {}, { preserveScroll: true })" /><span :class="t.hecha ? 'line-through text-marca-muted' : ''">{{ t.descripcion }}<span v-if="t.hecha" class="text-[10px] text-marca-muted"> · {{ t.usuario }} {{ t.hecha_en }}</span></span></label>
            <p v-if="!ot.tareas.length" class="text-xs text-marca-muted">Sin tareas. Anotá los pasos de la reparación.</p>
          </div>
          <div class="flex gap-2 mt-3"><input v-model="tarea.descripcion" class="input !py-1 text-xs" placeholder="Nueva tarea…" @keydown.enter.prevent="agregarTarea" /><button class="btn-secondary !py-1 text-xs" :disabled="!tarea.descripcion" @click="agregarTarea">+</button></div>
        </div>
        <div class="card text-sm">
          <h2 class="font-bold mb-2">Seguimiento del cliente</h2>
          <p class="text-xs text-marca-muted">El cliente ve el estado y aprueba el presupuesto desde este link:</p>
          <a :href="ot.url" target="_blank" class="text-xs text-violeta break-all underline">{{ ot.url }}</a>
          <a v-if="ot.telefono" :href="`https://wa.me/${ot.telefono.replace(/\D/g, '')}?text=${encodeURIComponent('Hola ' + ot.cliente + '! Podés seguir la reparación de tu ' + ot.equipo + ' acá: ' + ot.url)}`" target="_blank" class="btn-secondary w-full mt-2 !py-1.5 text-xs">Mandar por WhatsApp</a>
        </div>
      </div>
    </div>

    <Modal :abierto="editar" titulo="Editar orden" ancho="max-w-2xl" @cerrar="editar = false">
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2"><label class="label">Cliente</label><select v-model="ef.contact_id" class="input"><option :value="null">Sin ficha</option><option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div><label class="label">Nombre</label><input v-model="ef.nombre" class="input" :disabled="!!ef.contact_id" /></div><div><label class="label">Teléfono</label><input v-model="ef.telefono" class="input" /></div>
        <div><label class="label">Equipo</label><input v-model="ef.equipo" class="input" /></div><div><label class="label">Marca y modelo</label><input v-model="ef.marca_modelo" class="input" /></div>
        <div><label class="label">Serie</label><input v-model="ef.serie" class="input" /></div><div><label class="label">Prioridad</label><select v-model="ef.prioridad" class="input"><option v-for="(l, k) in prioridades" :key="k" :value="k">{{ l }}</option></select></div>
        <div class="sm:col-span-2"><label class="label">Falla</label><textarea v-model="ef.falla" rows="2" class="input"></textarea></div>
        <div class="sm:col-span-2"><label class="label">Diagnóstico</label><textarea v-model="ef.diagnostico" rows="2" class="input" placeholder="Qué tiene y qué hay que hacer"></textarea></div>
        <div><label class="label">Presupuesto (final, con IVA)</label><input v-model.number="ef.presupuesto" type="number" min="0" class="input" /></div><div><label class="label">Técnico</label><select v-model="ef.tecnico_id" class="input"><option :value="null">—</option><option v-for="t in tecnicos" :key="t.id" :value="t.id">{{ t.name }}</option></select></div>
        <div><label class="label">Ingreso</label><input v-model="ef.fecha_ingreso" type="date" class="input" /></div><div><label class="label">Prometido</label><input v-model="ef.fecha_prometida" type="date" class="input" /></div>
        <div class="sm:col-span-2"><label class="label">Notas internas</label><input v-model="ef.notas" class="input" /></div>
      </div>
      <template #pie><button class="btn-secondary" @click="editar = false">Cancelar</button><button class="btn-primary" :disabled="ef.processing" @click="ef.post(`/servicios/${ot.id}`, { preserveScroll: true, onSuccess: () => (editar = false) })">Guardar</button></template>
    </Modal>

    <Modal :abierto="facturarAbierto" titulo="Entregar y facturar" @cerrar="facturarAbierto = false">
      <p class="text-sm mb-3">Se factura {{ ot.items.length ? 'la hoja de trabajo (' + moneda(ot.total_items) + ')' : 'el presupuesto (' + moneda(ot.presupuesto) + ')' }}. Los repuestos salen del stock al emitir.</p>
      <label class="label">Condición</label><select v-model="fc.condicion" class="input"><option value="contado">Contado (cobrar ahora)</option><option value="cta_cte">Cuenta corriente</option></select>
      <template #pie><button class="btn-secondary" @click="facturarAbierto = false">Cancelar</button><button class="btn-primary" :disabled="fc.processing" @click="fc.post(`/servicios/${ot.id}/facturar`)">Emitir factura</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'
const props = defineProps({ ot: Object, estados: Object, prioridades: Object, clientes: Array, tecnicos: Array, productos: Array })
const page = usePage()
const claseEstado = computed(() => ({ recibido: 'bg-gris-light text-marca-muted', diagnostico: 'bg-amber-50 text-amber-700', presupuestado: 'bg-amber-50 text-amber-700', aprobado: 'bg-violeta/10 text-violeta', en_curso: 'bg-violeta/10 text-violeta', listo: 'bg-emerald-50 text-emerald-700', entregado: 'bg-emerald-50 text-emerald-700', cancelado: 'bg-red-50 text-carmin' }[props.ot.estado]))
const editar = ref(false), facturarAbierto = ref(false)
function estado(e) { router.post(`/servicios/${props.ot.id}/estado`, { estado: e }, { preserveScroll: true }) }
const it = useForm({ product_id: null, descripcion: '', cantidad: 1, precio_unit: null, actualizar_presupuesto: true })
function alElegirProducto() { const p = props.productos.find(x => x.id === it.product_id); if (p) { it.descripcion = p.name; it.precio_unit = p.precio } }
const tarea = useForm({ descripcion: '' })
function agregarTarea() { if (tarea.descripcion) tarea.post(`/servicios/${props.ot.id}/tareas`, { preserveScroll: true, onSuccess: () => tarea.reset() }) }
const o = props.ot
const ef = useForm({ contact_id: o.contact_id, nombre: o.nombre ?? '', telefono: o.telefono ?? '', equipo: o.equipo, marca_modelo: o.marca_modelo ?? '', serie: o.serie ?? '', falla: o.falla, diagnostico: o.diagnostico ?? '', prioridad: o.prioridad, tecnico_id: o.tecnico_id, fecha_ingreso: o.fecha_ingreso, fecha_prometida: o.fecha_prometida ?? '', presupuesto: o.presupuesto, notas: o.notas ?? '' })
const fc = useForm({ condicion: 'contado' })
// Firma en canvas
const lienzo = ref(null), dibujando = ref(false), trazos = ref(0), firmaNombre = ref(o.cliente)
const pos = e => { const r = lienzo.value.getBoundingClientRect(); return [(e.clientX - r.left) * lienzo.value.width / r.width, (e.clientY - r.top) * lienzo.value.height / r.height] }
function pd(e) { dibujando.value = true; const c = lienzo.value.getContext('2d'); c.lineWidth = 2.5; c.lineCap = 'round'; c.strokeStyle = '#1c1a18'; c.beginPath(); c.moveTo(...pos(e)); trazos.value++ }
function pm(e) { if (!dibujando.value) return; const c = lienzo.value.getContext('2d'); c.lineTo(...pos(e)); c.stroke() }
function limpiar() { lienzo.value.getContext('2d').clearRect(0, 0, lienzo.value.width, lienzo.value.height); trazos.value = 0 }
function guardarFirma() { router.post(`/servicios/${props.ot.id}/firmar`, { firma: lienzo.value.toDataURL('image/png'), nombre: firmaNombre.value }, { preserveScroll: true }) }
async function copiar() { try { await navigator.clipboard.writeText(props.ot.url) } catch (e) { window.prompt('Copiá el link', props.ot.url) } }
watch(() => page.props.flash?.abrir, l => { if (l) window.open(l, '_blank') }, { immediate: true })
</script>
