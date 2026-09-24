<template>
  <AppLayout :titulo="`${c.nombre} ${c.numero ?? ''}`">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div class="flex items-center gap-3">
        <span class="w-12 h-12 rounded-2xl grid place-items-center text-xl font-extrabold" :class="c.estado === 'anulado' ? 'bg-gris-light text-marca-muted' : 'bg-marca-grad text-white'">{{ c.letra }}</span>
        <div>
          <h1 class="page-title">{{ c.nombre }} <span class="tabular-nums">{{ c.numero ?? '(borrador)' }}</span></h1>
          <p class="page-subtitle">{{ c.fecha }} · {{ c.sucursal }} · {{ c.usuario }}</p>
          <div class="flex flex-wrap gap-1.5 mt-1.5">
            <span class="badge" :class="estadoComprobante[c.estado].clase">{{ estadoComprobante[c.estado].label }}</span>
            <span v-if="c.estado === 'emitido' && c.estado_cobro !== 'na'" class="badge" :class="estadoCobro[c.estado_cobro].clase">{{ estadoCobro[c.estado_cobro].label }}</span>
            <span v-if="c.vencido" class="badge bg-carmin-light text-carmin">Vencido</span>
            <span v-if="c.es_acopio" class="badge bg-violeta-light text-violeta">Acopio</span>
            <span v-if="c.interno" class="badge bg-amber-50 text-amber-700">Interno · no informado a ARCA</span>
            <span v-if="c.manual" class="badge bg-violeta/10 text-violeta" data-badge-manual>Manual de talonario<template v-if="c.cai"> · CAI {{ c.cai }}<template v-if="c.cai_vto"> vto. {{ c.cai_vto }}</template></template></span>
            <span v-else-if="c.afip_estado === 'aprobado'" class="badge bg-emerald-50 text-emerald-700">CAE {{ c.cae }}</span>
            <span v-else-if="c.afip_estado === 'simulado'" class="badge bg-amber-50 text-amber-700">Sin CAE · simulado</span>
            <span v-else-if="c.afip_estado === 'pendiente'" class="badge bg-carmin-light text-carmin">Pendiente de CAE</span>
            <span v-if="c.entrega_pendiente && c.pendiente_entrega > 0" class="badge bg-amber-50 text-amber-700">Entrega pendiente</span>
            <span v-else-if="c.entrega_pendiente" class="badge bg-emerald-50 text-emerald-700">Entregado</span>
            <span v-if="c.aprobado_en" class="badge bg-emerald-50 text-emerald-700">Aprobado por el cliente {{ c.aprobado_en }}</span>
            <span v-if="c.rechazado_en" class="badge bg-carmin-light text-carmin">Rechazado por el cliente {{ c.rechazado_en }}</span>
          </div>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <template v-if="c.estado === 'borrador'">
          <Link :href="`/comprobantes/${c.id}/editar`" class="btn-secondary"><Icono nombre="edit" clase="w-4 h-4" /> Editar</Link>
          <Link :href="`/comprobantes/${c.id}/emitir`" method="post" as="button" class="btn-primary">Emitir</Link>
        </template>
        <template v-else>
          <a :href="`/comprobantes/${c.id}/imprimir`" target="_blank" class="btn-secondary">Imprimir / PDF</a>
          <button v-if="c.estado === 'emitido'" @click="envioAbierto = true" class="btn-secondary">Enviar</button>
          <button v-if="c.estado === 'emitido' && c.estado_cobro !== 'na' && c.saldo > 0 && !c.link_pago && puede('comprobantes','crear')" @click="router.post(`/comprobantes/${c.id}/link-pago`, {}, { preserveScroll: true })" class="btn-secondary">Link de pago</button>
          <Link v-if="c.estado_cobro === 'pendiente' || c.estado_cobro === 'parcial'" :href="`/clientes/${c.contact_id}?cobrar=${c.id}`" class="btn-primary">Registrar cobro</Link>
          <button v-for="cv in conversiones" :key="cv.tipo" @click="cv.parcial ? abrirParcial(cv) : convertir(cv)" class="btn-secondary">{{ cv.label }}</button>
        </template>
        <button v-if="puedeAnular && puede('comprobantes', 'anular')" @click="anularAbierto = true" class="btn-danger">Anular</button>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-0 overflow-x-auto">
          <table class="table">
            <thead><tr><th>Artículo</th><th class="text-right">Cant.</th><th class="text-right">P. unit.</th><th class="text-right">Dto</th><th class="text-right">IVA</th><th class="text-right">Total</th></tr></thead>
            <tbody>
              <tr v-for="i in c.items" :key="i.id">
                <td><p class="font-medium">{{ i.descripcion }}</p><p v-if="i.sku" class="text-xs text-marca-muted">{{ i.sku }}</p></td>
                <td class="text-right tabular-nums">{{ cantidad(i.cantidad) }} {{ i.unidad }}</td>
                <td class="text-right tabular-nums">{{ moneda(i.precio_unit) }}</td>
                <td class="text-right tabular-nums text-marca-muted">{{ i.descuento ? i.descuento + '%' : '' }}</td>
                <td class="text-right tabular-nums text-marca-muted">{{ i.alicuota_iva }}%</td>
                <td class="text-right tabular-nums font-semibold">{{ moneda(i.total) }}</td>
              </tr>
            </tbody>
          </table>
          <div class="flex justify-end px-4 py-3 border-t border-marca-borde">
            <div class="w-64 text-sm space-y-1">
              <div class="flex justify-between"><span class="text-marca-muted">Neto</span><span class="tabular-nums">{{ moneda(c.neto) }}</span></div>
              <div class="flex justify-between"><span class="text-marca-muted">IVA</span><span class="tabular-nums">{{ moneda(c.iva) }}</span></div>
              <div v-for="t in c.impuestos ?? []" :key="t.tipo" class="flex justify-between"><span class="text-marca-muted">{{ t.nombre }} {{ t.alicuota }}%</span><span class="tabular-nums">{{ moneda(t.monto) }}</span></div>
              <div v-if="c.percepciones && !(c.impuestos ?? []).length" class="flex justify-between"><span class="text-marca-muted">Percepciones</span><span class="tabular-nums">{{ moneda(c.percepciones) }}</span></div>
              <div class="flex justify-between text-lg font-extrabold pt-1 border-t border-marca-borde"><span>Total</span><span class="tabular-nums">{{ moneda(c.total) }}</span></div>
              <div v-if="c.moneda && c.moneda !== 'ARS'" class="flex justify-between text-xs text-violeta font-semibold"><span>En {{ c.moneda }} · cotización {{ Number(c.cotizacion).toLocaleString('es-AR') }}</span><span class="tabular-nums">{{ c.moneda }} {{ Number(c.total_me).toLocaleString('es-AR', { minimumFractionDigits: 2 }) }}</span></div>
              <div v-if="c.proyecto" class="flex justify-between text-xs"><span class="text-marca-muted">Obra</span><Link :href="`/obras/${c.proyecto.id}`" class="text-violeta font-semibold hover:underline">{{ c.proyecto.codigo }} · {{ c.proyecto.nombre }}</Link></div>
              <div v-if="c.estado_cobro !== 'na' && c.estado === 'emitido'" class="flex justify-between" :class="c.saldo > 0 ? 'text-carmin font-semibold' : 'text-emerald-700'"><span>Saldo</span><span class="tabular-nums">{{ moneda(c.saldo) }}</span></div>
            </div>
          </div>
        </div>

        <div v-if="c.respuesta_cliente" class="card text-sm"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-1">Comentario del cliente</p>"{{ c.respuesta_cliente }}"</div>
        <div v-if="c.entrega_pendiente || c.tipo === 'REM'" class="card text-sm">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">{{ c.tipo === 'REM' ? 'Facturación de este remito' : 'Entregas de esta factura' }}</p>
          <div v-for="i in c.items" :key="i.id" class="flex justify-between py-1 border-t border-marca-borde first:border-0"><span>{{ i.descripcion }}</span><span class="tabular-nums" :class="(c.tipo === 'REM' ? i.facturada : i.entregada) < i.cantidad ? 'text-amber-700' : 'text-emerald-700'">{{ cantidad(c.tipo === 'REM' ? i.facturada : i.entregada) }} / {{ cantidad(i.cantidad) }} {{ i.unidad }}</span></div>
        </div>
        <div v-if="c.acopio" class="card">
          <div class="flex items-center justify-between mb-3"><h2 class="font-bold">Acopio · {{ c.acopio.estado }}</h2><span class="text-xs text-marca-muted">Límite {{ c.acopio.fecha_limite }}</span></div>
          <div class="space-y-2">
            <div v-for="i in c.acopio.items" :key="i.id">
              <div class="flex justify-between text-sm"><span>{{ i.descripcion }}</span><span class="tabular-nums text-marca-muted">{{ cantidad(i.retirada) }} / {{ cantidad(i.facturada) }} · quedan <b class="text-marca-texto">{{ cantidad(i.pendiente) }}</b></span></div>
              <div class="h-2 rounded-full bg-gris-light overflow-hidden mt-1"><div class="h-full bg-violeta-grad" :style="{ width: `${Math.min(100, i.retirada / i.facturada * 100)}%` }"></div></div>
            </div>
          </div>
          <Link :href="`/clientes/${c.contact_id}?retirar=${c.acopio.id}`" class="btn-violeta mt-4">Registrar retiro</Link>
        </div>

        <div v-if="c.exportacion" class="card text-sm">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Exportación · Factura E</p>
          <div class="grid sm:grid-cols-2 gap-x-4 gap-y-1 text-marca-muted">
            <p><b class="text-marca-texto">Destino:</b> {{ c.exportacion.pais_nombre || '-' }}</p>
            <p><b class="text-marca-texto">Tipo:</b> {{ { 1: 'bienes', 2: 'servicios', 4: 'otros' }[c.exportacion.tipo_expo ?? 1] }}<span v-if="c.exportacion.incoterm"> · {{ c.exportacion.incoterm }}</span></p>
            <p v-if="c.exportacion.permiso_embarque"><b class="text-marca-texto">Permiso de embarque:</b> {{ c.exportacion.permiso_embarque }}</p>
            <p v-if="c.exportacion.forma_pago"><b class="text-marca-texto">Pago:</b> {{ c.exportacion.forma_pago }}</p>
          </div>
        </div>
        <div v-if="c.transporte" class="card text-sm">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-2"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Transporte · remito electrónico</p><span v-if="c.transporte.cot" class="badge bg-emerald-50 text-emerald-700">COT {{ c.transporte.cot }}</span><span v-else-if="c.estado === 'emitido'" class="badge bg-amber-50 text-amber-700">Sin COT</span></div>
          <div class="grid sm:grid-cols-2 gap-x-4 gap-y-1 text-marca-muted">
            <p><b class="text-marca-texto">Entrega:</b> {{ c.transporte.domicilio_entrega || [c.contacto?.address, c.contacto?.city].filter(Boolean).join(', ') || '-' }}</p>
            <p><b class="text-marca-texto">Transportista:</b> {{ c.transporte.transportista || '-' }}<span v-if="c.transporte.transportista_cuit"> · {{ c.transporte.transportista_cuit }}</span></p>
            <p><b class="text-marca-texto">Patente:</b> {{ c.transporte.patente || '-' }} · <b class="text-marca-texto">Bultos:</b> {{ c.transporte.bultos ?? '-' }} · <b class="text-marca-texto">Peso:</b> {{ c.transporte.peso_kg != null ? c.transporte.peso_kg + ' kg' : '-' }}</p>
          </div>
          <div v-if="c.estado === 'emitido'" class="flex flex-wrap items-center gap-2 mt-3">
            <Link v-if="c.arba_ws && !c.transporte.cot && puede('comprobantes', 'editar')" :href="`/comprobantes/${c.id}/cot/pedir`" method="post" as="button" preserve-scroll class="btn-primary !py-1 text-xs">Pedir COT a ARBA</Link>
            <a :href="`/comprobantes/${c.id}/cot`" class="btn-secondary !py-1 text-xs">Descargar archivo para COT (ARBA)</a>
            <form v-if="puede('comprobantes', 'editar')" class="flex gap-1" @submit.prevent="cotForm.post(`/comprobantes/${c.id}/cot`, { preserveScroll: true })"><input v-model="cotForm.cot" class="input !py-1 text-xs w-44" placeholder="Pegá el COT que devolvió ARBA" /><button class="btn-primary !py-1 text-xs" :disabled="cotForm.processing">Guardar COT</button></form>
          </div>
          <p class="text-[11px] text-marca-muted mt-2">El archivo se sube en la web de ARBA (Remito electrónico) o por web service; el código que devuelve se guarda acá y sale impreso en el remito. Obligatorio para traslados en Provincia de Buenos Aires que superen los montos vigentes.</p>
        </div>

        <div v-if="c.notas" class="card text-sm text-marca-muted whitespace-pre-line">{{ c.notas }}</div>
      </div>

      <div class="space-y-4">
        <div v-if="c.url_publica" class="card text-sm">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted mb-2">Link para el cliente</p>
          <div class="flex gap-3 items-start">
            <canvas ref="qr" class="w-24 h-24 rounded-lg border border-marca-borde shrink-0"></canvas>
            <div class="min-w-0 flex-1">
              <a :href="c.url_publica" target="_blank" class="text-violeta font-semibold break-all text-xs">{{ c.url_publica }}</a>
              <p class="text-[11px] text-marca-muted mt-1">{{ c.tipo === 'PRE' ? 'El cliente ve el presupuesto y lo aprueba o rechaza desde ahí.' : 'El cliente ve el comprobante y descarga el PDF.' }}</p>
              <template v-if="c.link_pago"><a :href="c.link_pago" target="_blank" class="btn-primary !py-1 text-xs mt-2 inline-flex">Pagar {{ moneda(c.saldo) }}</a><p v-if="c.link_pago_simulado" class="text-[10px] text-amber-700 mt-1">Link simulado: sin credenciales de MercadoPago en Configuración.</p></template>
              <button @click="copiar" class="btn-ghost !px-2 text-xs mt-1">{{ copiado ? 'Copiado ✓' : 'Copiar link' }}</button>
            </div>
          </div>
          <div v-if="c.envios?.length" class="mt-2 pt-2 border-t border-marca-borde text-xs text-marca-muted"><p v-for="(e, i) in c.envios" :key="i">{{ e.fecha }} · {{ e.canal }} · {{ e.destino }} · <span :class="e.estado === 'enviado' ? 'text-emerald-700' : e.estado === 'error' ? 'text-carmin' : 'text-amber-700'">{{ e.estado }}</span></p></div>
        </div>

        <div class="card">
          <h2 class="font-bold mb-2">Cliente</h2>
          <template v-if="c.contacto">
            <Link :href="`/clientes/${c.contacto.id}`" class="font-semibold text-carmin hover:underline">{{ c.contacto.name }}</Link>
            <p class="text-sm text-marca-muted">{{ c.contacto.condicion_iva }} · {{ c.contacto.cuit ?? 'sin CUIT' }}</p>
            <p class="text-sm text-marca-muted">{{ [c.contacto.address, c.contacto.city].filter(Boolean).join(', ') }}</p>
            <p class="text-sm mt-2">Saldo en cuenta: <b class="tabular-nums" :class="c.contacto.balance > 0 ? 'text-carmin' : ''">{{ moneda(c.contacto.balance) }}</b></p>
          </template>
          <p v-else class="text-sm text-marca-muted">Consumidor final</p>
          <p class="text-sm mt-2">Condición: <b>{{ c.condicion === 'contado' ? 'Contado' : 'Cuenta corriente' }}</b><span v-if="c.fecha_vto && (c.grupo === 'factura' || c.grupo === 'nd')"> · vence {{ c.fecha_vto }}</span></p>
        </div>

        <div v-if="c.cobros.length" class="card">
          <h2 class="font-bold mb-2">Cobros aplicados</h2>
          <div v-for="k in c.cobros" :key="k.cobro_id" class="flex justify-between text-sm py-1 border-t border-marca-borde/60 first:border-0">
            <a :href="`/clientes/cobros/${k.cobro_id}/imprimir`" target="_blank" class="hover:text-carmin">{{ k.numero }} <span class="text-marca-muted">· {{ k.fecha }}</span></a>
            <span class="tabular-nums" :class="k.estado === 'anulado' ? 'line-through text-marca-muted' : ''">{{ moneda(k.monto) }}</span>
          </div>
        </div>

        <div v-if="c.origen || c.derivados.length" class="card">
          <h2 class="font-bold mb-2">Relacionados</h2>
          <Link v-if="c.origen" :href="`/comprobantes/${c.origen.id}`" class="block text-sm py-1 hover:text-carmin">↑ {{ c.origen.nombre }} {{ c.origen.numero }}</Link>
          <Link v-for="d in c.derivados" :key="d.id" :href="`/comprobantes/${d.id}`" class="block text-sm py-1 hover:text-carmin">↓ {{ d.nombre }} {{ d.numero ?? '(borrador)' }} <span v-if="d.estado === 'anulado'" class="badge bg-carmin-light text-carmin ml-1">Anulado</span></Link>
        </div>

        <div v-if="c.afip_estado === 'aprobado'" class="card text-sm">
          <h2 class="font-bold mb-2">ARCA</h2>
          <p>CAE <b class="tabular-nums">{{ c.cae }}</b></p><p class="text-marca-muted">Vence {{ c.cae_vto }}</p>
          <button class="btn-secondary !py-1 text-xs mt-2" :disabled="verificando" @click="verificarArca">{{ verificando ? 'Consultando…' : 'Verificar en ARCA' }}</button>
          <p v-if="verificacion" class="text-xs mt-2 rounded-lg p-2" :class="verificacion.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-carmin-light text-carmin'">{{ verificacion.detalle }}</p>
        </div>
        <div v-else-if="c.afip_estado === 'pendiente'" class="card text-sm border-carmin/40">
          <h2 class="font-bold mb-1">Pendiente de CAE</h2>
          <p class="text-marca-muted mb-2">ARCA no respondió cuando se emitió. El comprobante ya impactó en cuenta corriente y stock, pero no es válido como factura hasta tener CAE. El sistema reintenta solo cada 5 minutos.</p>
          <p v-if="c.afip_error" class="text-xs bg-amber-50 text-amber-900 rounded-lg p-2 mb-2">{{ c.afip_error }}</p>
          <Link :href="`/comprobantes/${c.id}/reintentar-cae`" method="post" as="button" class="btn-primary !py-1 text-xs">Reintentar ahora</Link>
        </div>
      </div>
    </div>

    <Modal :abierto="anularAbierto" titulo="Anular comprobante" @cerrar="anularAbierto = false">
      <p class="text-sm text-marca-muted mb-3">Se revierte la cuenta corriente y el stock. Si tiene cobros, anulalos primero. Indicá el motivo (queda en auditoría).</p>
      <input v-model="anular.motivo" class="input" placeholder="Motivo" />
      <p v-if="anular.errors.motivo" class="text-carmin text-xs mt-1">{{ anular.errors.motivo }}</p>
      <template #pie>
        <button class="btn-secondary" @click="anularAbierto = false">Cancelar</button>
        <button class="btn-danger" :disabled="anular.processing" @click="anular.post(`/comprobantes/${c.id}/anular`, { onSuccess: () => (anularAbierto = false) })">Anular</button>
      </template>
    </Modal>

    <Modal :abierto="!!conv" :titulo="conv?.label" @cerrar="conv = null">
      <p class="text-sm text-marca-muted mb-3">Se crea un borrador con los mismos ítems, listo para revisar y emitir.</p>
      <template v-if="conv?.tipo === 'FX'">
        <label class="label">Condición</label>
        <select v-model="convForm.condicion" class="input"><option value="cta_cte">Cuenta corriente</option><option value="contado">Contado</option></select>
        <label class="flex items-center gap-2 text-sm mt-3"><input v-model="convForm.es_acopio" type="checkbox" class="accent-violeta" /> Facturar como acopio</label>
      </template>
      <template #pie>
        <button class="btn-secondary" @click="conv = null">Cancelar</button>
        <button class="btn-primary" :disabled="convForm.processing" @click="convForm.transform(d => ({ ...d, tipo: conv.tipo })).post(`/comprobantes/${c.id}/convertir`)">Continuar</button>
      </template>
    </Modal>
    <Modal :abierto="!!parcial" :titulo="parcial?.label" ancho="max-w-2xl" @cerrar="parcial = null">
      <p class="text-sm text-marca-muted mb-3">Indicá cuánto {{ parcial?.tipo === 'REM' ? 'entregás' : 'facturás' }} ahora de cada línea. El resto queda pendiente.</p>
      <table class="table text-sm"><thead><tr><th>Artículo</th><th class="text-right">Pendiente</th><th class="text-right w-32">Ahora</th></tr></thead>
        <tbody><tr v-for="i in c.items.filter(x => pendItem(x) > 0)" :key="i.id"><td>{{ i.descripcion }}</td><td class="text-right tabular-nums">{{ cantidad(pendItem(i)) }} {{ i.unidad }}</td><td><input v-model.number="pf.items[i.id]" type="number" step="any" min="0" :max="pendItem(i)" class="input !py-1 text-right" /></td></tr></tbody></table>
      <div v-if="parcial?.tipo === 'FX'" class="mt-3"><label class="label">Condición</label><select v-model="pf.condicion" class="input"><option value="cta_cte">Cuenta corriente</option><option value="contado">Contado</option></select></div>
      <p v-if="pf.errors.items" class="text-carmin text-xs mt-2">{{ pf.errors.items }}</p>
      <template #pie><button class="btn-secondary" @click="parcial = null">Cancelar</button><button class="btn-primary" :disabled="pf.processing" @click="pf.transform(d => ({ ...d, tipo: parcial.tipo })).post(`/comprobantes/${c.id}/parcial`)">Emitir</button></template>
    </Modal>
    <EnviarModal :abierto="envioAbierto" modelo="Comprobante" :id="c.id" :titulo="`Enviar ${c.nombre} ${c.numero ?? ''}`" @cerrar="envioAbierto = false" />
  </AppLayout>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import QRCode from 'qrcode'
import EnviarModal from '@/Components/EnviarModal.vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad, estadoCobro, estadoComprobante } from '@/util/formato'
import { usePermisos } from '@/util/permisos'

const props = defineProps({ c: Object, conversiones: Array, puedeAnular: Boolean })
const { puede } = usePermisos()
const anularAbierto = ref(false)
const verificando = ref(false), verificacion = ref(null)
async function verificarArca() { verificando.value = true; try { const r = await fetch(`/comprobantes/${props.c.id}/verificar-arca`, { headers: { Accept: 'application/json' } }); verificacion.value = await r.json() } catch (e) { verificacion.value = { ok: false, detalle: 'No se pudo consultar.' } } finally { verificando.value = false } }
const anular = useForm({ motivo: '' })
const cotForm = useForm({ cot: props.c.transporte?.cot ?? '' })
const conv = ref(null)
const convForm = useForm({ condicion: props.c.condicion, es_acopio: false })
function convertir(cv) { conv.value = cv }
const envioAbierto = ref(false), copiado = ref(false), qr = ref(null)
const parcial = ref(null)
const pf = useForm({ items: {}, condicion: 'cta_cte' })
const pendItem = i => props.c.tipo === 'REM' ? i.cantidad - i.facturada : i.cantidad - i.entregada
function abrirParcial(cv) { pf.clearErrors(); pf.items = Object.fromEntries(props.c.items.filter(x => pendItem(x) > 0).map(x => [x.id, pendItem(x)])); parcial.value = cv }
async function copiar() { try { await navigator.clipboard.writeText(props.c.link_pago || props.c.url_publica); copiado.value = true; setTimeout(() => (copiado.value = false), 1500) } catch {} }
function dibujarQR() { if (qr.value && (props.c.link_pago || props.c.url_publica)) QRCode.toCanvas(qr.value, props.c.link_pago || props.c.url_publica, { width: 96, margin: 1, color: { dark: '#4f3089' } }).catch(() => {}) }
onMounted(dibujarQR); watch(() => props.c.link_pago, dibujarQR)
</script>
