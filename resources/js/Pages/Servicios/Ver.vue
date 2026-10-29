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

    <!-- Fase 36.1: el próximo paso, siempre a la vista -->
    <div v-if="que_sigue.paso" class="rounded-xl px-4 py-2.5 mb-4 flex flex-wrap items-center justify-between gap-2 text-sm" :class="ot.estado === 'esperando' ? 'bg-amber-50 text-amber-800' : 'bg-violeta/10 text-violeta'" data-e2e="que-sigue">
      <span><b>Qué sigue:</b> {{ que_sigue.paso }}</span>
      <span class="flex gap-2"><button v-if="ot.estado === 'esperando'" class="btn-secondary !py-1 text-xs" @click="estado('en_curso')">Retomar</button><button v-else-if="!['entregado', 'cancelado', 'listo'].includes(ot.estado)" class="btn-ghost !py-1 text-xs" @click="esperar" data-e2e="esperar">Dejar esperando…</button></span>
    </div>
    <div v-if="ot.aprobacion" class="rounded-xl bg-emerald-50 text-emerald-800 px-4 py-2 mb-4 text-sm">El cliente aprobó {{ ot.aprobacion.items.length }} {{ ot.aprobacion.items.length === 1 ? 'ítem' : 'ítems' }} por {{ moneda(ot.aprobacion.total) }} el {{ ot.aprobacion.cuando }} ({{ ot.aprobacion.nombre }}). Si cambian los importes, la aprobación se anula.</div>
    <div v-if="pendientes.length && !ot.comprobante" class="card mb-4 border-amber-300" data-e2e="pendientes"><h2 class="font-bold text-sm">Quedó pendiente de visitas anteriores</h2>
      <div v-for="p in pendientes" :key="p.id" class="flex justify-between items-center text-sm py-1 border-t border-marca-borde"><span>{{ p.descripcion }} <span class="text-xs text-marca-muted">· {{ p.ot }} del {{ p.fecha }}</span></span><span class="flex items-center gap-2"><b class="tabular-nums">{{ moneda(p.total) }}</b><button class="btn-secondary !py-0.5 text-xs" @click="router.post(`/servicios/${ot.id}/pendientes/${p.id}`, {}, { preserveScroll: true })">Ofrecer ahora</button></span></div></div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card grid sm:grid-cols-2 gap-3 text-sm">
          <div><p class="label">Falla reportada</p><p>{{ ot.falla }}</p></div>
          <div><p class="label">Diagnóstico</p><p v-if="ot.diagnostico">{{ ot.diagnostico }}</p><button v-else class="text-xs text-violeta font-semibold" @click="editar = true">Cargar diagnóstico y presupuesto</button></div>
          <div><p class="label">{{ config.tecnico }}</p><p>{{ ot.tecnico || 'Sin asignar' }}</p></div>
          <div><p class="label">Presupuesto</p><p class="font-extrabold text-lg tabular-nums">{{ moneda(ot.presupuesto) }}<span v-if="ot.aprobado_en" class="text-xs text-emerald-700 font-semibold ml-2">aprobado {{ ot.aprobado_en }}</span></p></div>
          <div v-if="ot.serie"><p class="label">{{ config.serie }}</p><p>{{ ot.serie }}</p></div>
          <div v-if="ot.medidor !== null"><p class="label">{{ config.medidor || 'Medidor' }}</p><p class="tabular-nums">{{ cantidad(ot.medidor) }}</p></div>
          <div v-for="(v, k) in ot.datos_equipo" :key="k"><p class="label">{{ k }}</p><p>{{ v }}</p></div>
          <div v-if="ot.notas" class="sm:col-span-2"><p class="label">Notas</p><p class="whitespace-pre-line">{{ ot.notas }}</p></div>
        </div>

        <div class="card p-0 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde"><h2 class="font-bold">Hoja de trabajo</h2><span class="text-sm">Total <b class="tabular-nums">{{ moneda(ot.total_items) }}</b></span></div>
          <table class="table text-sm">
            <thead><tr><th>Ítem</th><th>Tipo</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Total</th><th></th></tr></thead>
            <tbody>
              <tr v-for="i in ot.items" :key="i.id" :class="i.aprobado === false ? 'opacity-60' : ''"><td><p class="font-medium">{{ i.descripcion }}<span v-if="i.aprobado === true" class="badge bg-emerald-50 text-emerald-700 !text-[10px] ml-1">aprobado</span><span v-if="i.pendiente_proxima" class="badge bg-amber-50 text-amber-700 !text-[10px] ml-1">para la próxima</span><span v-if="i.de_antes" class="badge bg-gris-light !text-[10px] ml-1">de la visita anterior</span></p><p v-if="i.sku" class="text-xs text-marca-muted">{{ i.sku }}</p>
                <p v-if="i.tipo === 'mano_obra'" class="text-[11px] text-marca-muted">{{ i.tecnicos.length ? i.tecnicos.map(t => (tecnicos.find(x => x.id === t.user_id)?.name ?? '?') + ' ' + t.pct + '%').join(' · ') : (ot.tecnico ? ot.tecnico + ' 100%' : 'sin ' + config.tecnico.toLowerCase()) }} <button v-if="!ot.comprobante" class="text-violeta font-semibold" @click="abrirReparto(i)">repartir</button></p></td><td class="text-xs">{{ i.tipo === 'mano_obra' ? 'Mano de obra' : 'Repuesto' }}</td><td class="text-right tabular-nums">{{ cantidad(i.cantidad) }}</td><td class="text-right tabular-nums">{{ moneda(i.precio_unit) }}</td><td class="text-right tabular-nums font-semibold">{{ moneda(i.total) }}</td><td class="text-right"><button v-if="!ot.comprobante" class="btn-ghost !px-2 text-xs text-carmin" @click="router.post(`/servicios/${ot.id}/items/${i.id}/borrar`, {}, { preserveScroll: true })">Quitar</button></td></tr>
              <tr v-if="!ot.comprobante"><td colspan="6" class="!p-2">
                <div class="grid grid-cols-[1fr_90px_110px_auto] gap-2 items-end">
                  <div><select v-model="it.product_id" class="input !py-1 text-xs" @change="alElegirProducto"><option :value="null">Repuesto o servicio del catálogo…</option><option v-for="p in productos" :key="p.id" :value="p.id">{{ p.name }} · {{ moneda(p.precio, 0) }}</option></select><input v-model="it.descripcion" class="input !py-1 text-xs mt-1" placeholder="o describilo a mano (ej. Mano de obra 2 h)" /></div>
                  <input v-model.number="it.cantidad" type="number" step="any" min="0" class="input !py-1 text-xs text-right" placeholder="Cant." /><input v-model.number="it.precio_unit" type="number" step="any" min="0" class="input !py-1 text-xs text-right" placeholder="Precio" />
                  <button class="btn-primary !py-1 text-xs" :disabled="it.processing || !it.cantidad || (!it.product_id && !it.descripcion)" @click="it.post(`/servicios/${ot.id}/items`, { preserveScroll: true, onSuccess: () => it.reset() })">Agregar</button>
                </div>
                <label class="flex items-center gap-1.5 text-[11px] text-marca-muted mt-1"><input v-model="it.actualizar_presupuesto" type="checkbox" class="accent-carmin" /> Actualizar el presupuesto con el total de la hoja</label>
                <div class="flex flex-wrap items-center gap-2 mt-2 text-xs"><span class="text-marca-muted">Plantillas:</span><button v-for="p in plantillas" :key="p.id" class="btn-ghost !py-0.5 !px-2 text-xs border border-marca-borde" @click="router.post(`/servicios/${ot.id}/plantilla/${p.id}`, {}, { preserveScroll: true })">+ {{ p.nombre }}</button><button v-if="ot.items.length" class="text-violeta font-semibold" @click="guardarPlantilla">Guardar esta hoja como plantilla</button></div>
              </td></tr>
            </tbody>
          </table>
        </div>

        <div class="card" data-e2e="fotos">
          <h2 class="font-bold mb-2">Fotos</h2>
          <div class="grid sm:grid-cols-4 gap-3">
            <div v-for="(l, k) in momentos" :key="k"><p class="label">{{ l }}</p>
              <div class="flex flex-wrap gap-1"><a v-for="f in fotos[k] || []" :key="f.id" :href="f.url" target="_blank"><img :src="f.url" class="w-14 h-14 object-cover rounded-lg border border-marca-borde" /></a></div>
              <label class="text-xs text-violeta font-semibold cursor-pointer">+ subir<input type="file" accept="image/*,video/*" capture="environment" multiple class="hidden" @change="subirFotos(k, $event.target.files)" :data-momento="k" /></label></div>
          </div>
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
    <Modal :abierto="!!rep" :titulo="`Repartir: ${rep?.descripcion ?? ''}`" @cerrar="rep = null">
      <p class="text-xs text-marca-muted mb-2">Qué parte de esta mano de obra hizo cada {{ config.tecnico.toLowerCase() }}. Tiene que sumar 100 %.</p>
      <div v-for="(r, k) in reparto" :key="k" class="flex gap-2 mb-1"><select v-model="r.user_id" class="input !py-1"><option :value="null">—</option><option v-for="t in tecnicos" :key="t.id" :value="t.id">{{ t.name }}</option></select><input v-model.number="r.pct" type="number" min="0" max="100" class="input !py-1 !w-24" /><span class="self-center">%</span></div>
      <button class="text-xs text-violeta" @click="reparto.push({ user_id: null, pct: 0 })">+ otro</button>
      <p class="text-xs mt-2" :class="Math.abs(reparto.reduce((a, r) => a + (Number(r.pct) || 0), 0) - 100) < 0.01 ? 'text-emerald-700' : 'text-carmin'">Suma {{ reparto.reduce((a, r) => a + (Number(r.pct) || 0), 0) }} %</p>
      <template #pie><button class="btn-secondary" @click="rep = null">Cancelar</button><button class="btn-primary" @click="router.post(`/servicios/${ot.id}/items/${rep.id}/reparto`, { reparto }, { preserveScroll: true, onSuccess: () => (rep = null) })">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad } from '@/util/formato'
const props = defineProps({ ot: Object, estados: Object, prioridades: Object, clientes: Array, tecnicos: Array, productos: Array, config: Object, que_sigue: Object, pendientes: Array, plantillas: Array, fotos: [Object, Array], momentos: Object })
const page = usePage()
const claseEstado = computed(() => ({ recibido: 'bg-gris-light text-marca-muted', diagnostico: 'bg-amber-50 text-amber-700', presupuestado: 'bg-amber-50 text-amber-700', aprobado: 'bg-violeta/10 text-violeta', en_curso: 'bg-violeta/10 text-violeta', listo: 'bg-emerald-50 text-emerald-700', entregado: 'bg-emerald-50 text-emerald-700', cancelado: 'bg-red-50 text-carmin' }[props.ot.estado]))
const editar = ref(false), facturarAbierto = ref(false)
function estado(e) { router.post(`/servicios/${props.ot.id}/estado`, { estado: e }, { preserveScroll: true }) }
function esperar() { const m = window.prompt('¿Qué está esperando? (repuesto, respuesta del cliente, otro taller…)'); if (m) router.post(`/servicios/${props.ot.id}/esperar`, { motivo: m }, { preserveScroll: true }) }
function guardarPlantilla() { const n = window.prompt('Nombre de la plantilla (ej. Service 10.000 km)'); if (n) router.post(`/servicios/${props.ot.id}/plantilla`, { nombre: n }, { preserveScroll: true }) }
function subirFotos(momento, files) { if (!files?.length) return; router.post(`/servicios/${props.ot.id}/fotos`, { momento, fotos: [...files] }, { forceFormData: true, preserveScroll: true }) }
const rep = ref(null), reparto = ref([])
function abrirReparto(i) { rep.value = i; reparto.value = i.tecnicos.length ? i.tecnicos.map(t => ({ ...t })) : [{ user_id: props.ot.tecnico_id, pct: 100 }] }
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
