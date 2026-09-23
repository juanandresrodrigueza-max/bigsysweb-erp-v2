<template>
  <AppLayout :titulo="titulo">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div>
        <h1 class="page-title">{{ titulo }}</h1>
        <p class="page-subtitle" v-if="origen">Desde {{ origen.nombre }} {{ origen.numero }}.</p>
        <p class="page-subtitle" v-else>Cargá cliente e ítems; el tipo de factura sale solo según la condición de IVA.</p>
      </div>
      <div class="flex gap-2">
        <button type="button" @click="abrirIA = true" class="btn-violeta"><Icono nombre="sparkles" clase="w-4 h-4" /> Cargar con IA</button>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="card grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Tipo</label>
            <select v-model="form.tipo" class="input" :disabled="!!form.origen_id && esConversion">
              <option v-for="t in tipos" :key="t.key" :value="t.key">{{ t.label }}</option>
            </select>
            <p v-if="tipoResuelto" class="text-xs text-marca-muted mt-1">Se emitirá como <b>{{ tipoResuelto }}</b>.</p>
          </div>
          <div>
            <label class="label">Cliente</label>
            <BuscadorSelect v-model="form.contact_id" :opciones="opcionesClientes" :url="'/buscar/contactos/cliente'" @cargados="f => sumar(clientesCat, f)" placeholder="Buscar cliente por nombre o CUIT… (vacío: recientes)" @elegido="alElegirCliente">
              <template #pie><Link href="/clientes" class="block px-3 py-2 text-xs text-carmin font-semibold border-t border-marca-borde">+ Crear cliente nuevo</Link></template>
            </BuscadorSelect>
            <p v-if="form.errors.contact_id" class="text-carmin text-xs mt-1">{{ form.errors.contact_id }}</p>
            <button v-if="form.contact_id && !form.items.some(i => i.product_id)" type="button" class="text-xs text-violeta font-semibold mt-1" :disabled="repitiendo" @click="repetirUltima">{{ repitiendo ? 'Buscando…' : '↻ Repetir la última factura de este cliente' }}</button>
            <p v-if="repetida" class="text-xs text-emerald-700 mt-1">{{ repetida }}</p>
            <p v-if="cliente" class="text-xs text-marca-muted mt-1">{{ cliente.condicion_iva }} · Lista {{ cliente.lista_precios }} <span v-if="cliente.descuento">· {{ cliente.descuento }}% dto.</span> · Saldo {{ moneda(cliente.balance, 0) }}<span v-if="cliente.credit_limit > 0"> / límite {{ moneda(cliente.credit_limit, 0) }}</span></p>
          </div>
          <div><label class="label">Fecha</label><input v-model="form.fecha" type="date" class="input" /><p v-if="form.errors.fecha" class="text-carmin text-xs mt-1">{{ form.errors.fecha }}</p></div>
          <div>
            <label class="label">Condición</label>
            <div class="flex gap-1 bg-marca-fondo rounded-xl p-1">
              <button type="button" v-for="c in [['contado','Contado'],['cta_cte','Cuenta corriente']]" :key="c[0]" @click="form.condicion = c[0]" class="flex-1 py-1.5 rounded-lg text-sm font-semibold transition" :class="form.condicion === c[0] ? 'bg-white shadow-card' : 'text-marca-muted'">{{ c[1] }}</button>
            </div>
          </div>
          <div v-if="form.condicion === 'cta_cte' && esFactura"><label class="label">Días para el vencimiento</label><input v-model.number="form.dias_vto" type="number" min="0" class="input" /></div>
          <div v-if="vendedores.length"><label class="label">Vendedor</label><select v-model="form.vendedor_id" class="input"><option :value="null">{{ cliente?.vendedor ? 'El del cliente' : 'Sin vendedor' }}</option><option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.nombre }}</option></select></div>
          <div v-if="proyectos.length"><label class="label">Obra / proyecto</label><select v-model="form.proyecto_id" class="input"><option :value="null">Sin obra</option><option v-for="p in proyectos" :key="p.id" :value="p.id">{{ p.codigo }} · {{ p.nombre }}</option></select></div>
          <div><label class="label">Moneda</label>
            <div class="flex gap-2 items-center">
              <div class="flex gap-1 bg-marca-fondo rounded-xl p-1 flex-1"><button type="button" v-for="m in ['ARS','USD']" :key="m" @click="form.moneda = m; if (m === 'USD' && !form.cotizacion) form.cotizacion = cotizacionUsd" class="flex-1 py-1.5 rounded-lg text-sm font-semibold transition" :class="form.moneda === m ? 'bg-white shadow text-marca-texto' : 'text-marca-muted'">{{ m === 'ARS' ? '$ Pesos' : 'US$ Dólares' }}</button></div>
              <input v-if="form.moneda === 'USD'" v-model.number="form.cotizacion" type="number" step="any" class="input !w-28" placeholder="Cotización" title="Cotización del dólar" />
            </div>
            <p v-if="form.moneda === 'USD'" class="text-xs text-violeta mt-1">Los precios se cargan en dólares y la factura sale en pesos a {{ moneda(form.cotizacion || 0) }} por dólar.</p>
          </div>
          <div v-if="puntosVenta.length > 1"><label class="label">Punto de venta</label><select v-model="form.punto_venta_id" class="input"><option v-for="p in puntosVenta" :key="p.id" :value="p.id">{{ String(p.numero).padStart(4,'0') }} · {{ p.sucursal ?? 'General' }}</option></select></div>
          <label v-if="esFactura" class="flex items-center gap-2 text-sm sm:col-span-2 p-3 rounded-xl border" :class="form.es_acopio ? 'border-violeta bg-violeta-light' : 'border-marca-borde'">
            <input v-model="form.es_acopio" type="checkbox" class="accent-violeta" />
            <span><b>Es acopio</b> · el cliente paga ahora y retira la mercadería en partes. El stock no se descuenta hasta cada retiro y el precio queda congelado.</span>
          </label>
          <label v-if="esFactura && !form.es_acopio && !form.origen_id" class="flex items-center gap-2 text-sm sm:col-span-2 p-3 rounded-xl border" :class="form.entrega_pendiente ? 'border-violeta bg-violeta-light' : 'border-marca-borde'">
            <input v-model="form.entrega_pendiente" type="checkbox" class="accent-violeta" />
            <span><b>Entrega pendiente</b> · se factura ahora y la mercadería sale después con remito (en una o varias entregas). El stock se descuenta con cada remito.</span>
          </label>
          <label v-if="esFiscal && !form.origen_id" class="flex items-center gap-2 text-sm sm:col-span-2 p-3 rounded-xl border" :class="form.sin_arca ? 'border-amber-400 bg-amber-50/40' : 'border-marca-borde'">
            <input :checked="!form.sin_arca" type="checkbox" class="accent-carmin" @change="form.sin_arca = !$event.target.checked" />
            <span><b>Informar a ARCA</b> · con el tilde sale la factura electrónica con CAE. Sin el tilde queda como <b>comprobante interno</b>: numeración propia, sin CAE, no válido como factura y fuera de los libros de IVA.</span>
          </label>
          <label v-if="esFactura && !form.sin_arca && cliente && cliente.condicion_iva === 'Responsable Inscripto'" class="flex items-center gap-2 text-sm sm:col-span-2 p-3 rounded-xl border" :class="form.fce ? 'border-carmin bg-red-50/40' : 'border-marca-borde'">
            <input v-model="form.fce" type="checkbox" class="accent-carmin" />
            <span class="flex-1"><b>Factura de Crédito Electrónica MiPyME</b> · obligatoria si el cliente es empresa grande y el total supera el mínimo vigente. Vence a 30 días y se puede negociar.<span v-if="!cbuFce" class="text-carmin"> Falta el CBU en Configuración → Impuestos.</span></span>
            <span v-if="form.fce" class="flex items-center gap-1 text-xs whitespace-nowrap">Vto. pago <input v-model="form.fce_vto_pago" type="date" class="input !py-1 text-xs" @click.stop /></span>
          </label>
        </div>

        <div class="card p-0 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 border-b border-marca-borde">
            <h2 class="font-bold">Ítems</h2>
            <button type="button" @click="agregar()" class="btn-secondary !py-1 text-xs"><Icono nombre="plus" clase="w-3.5 h-3.5" /> Agregar</button><button v-if="esFactura && puntos && puntos.activo && puntos.puntos >= puntos.minimo && !form.canje_puntos" type="button" class="btn-ghost !py-1 text-xs text-violeta" @click="usarPuntos" :title="`${puntos.puntos} puntos = ${moneda(puntos.pesos)}`">★ Usar {{ puntos.puntos }} puntos ({{ moneda(puntos.pesos, 0) }})</button>
          </div>
          <div class="overflow-x-auto">
            <table class="table min-w-[720px]">
              <thead><tr><th class="w-[36%]">Artículo</th><th class="w-24 text-right">Cant.</th><th class="w-32 text-right">P. unit. (neto)</th><th class="w-20 text-right">Dto %</th><th class="w-20 text-right">IVA</th><th class="text-right">Total</th><th class="w-8"></th></tr></thead>
              <tbody>
                <tr v-for="(it, i) in form.items" :key="i" class="align-top">
                  <td>
                    <BuscadorSelect v-model="it.product_id" :opciones="opcionesProductos" :url="`/buscar/articulos/venta${form.contact_id ? '?contact_id=' + form.contact_id : ''}`" @cargados="f => sumar(productosCat, f)" placeholder="Buscar artículo… (Enter elige, luego cantidad)" :data-fila="i" @elegido="o => { alElegirProducto(it, o); enfocar(i, 'cant') }" />
                    <input v-if="!it.product_id" v-model="it.descripcion" class="input mt-1 !py-1 text-xs" placeholder="Descripción libre" />
                    <p v-else class="text-[11px] text-marca-muted mt-1">{{ it.descripcion }} <span v-if="stockDe(it) !== null" :class="stockDe(it) < it.cantidad ? 'text-carmin font-semibold' : ''">· stock {{ cantidad(stockDe(it)) }}</span></p>
                  </td>
                  <td><input v-model.number="it.cantidad" type="number" min="0" step="any" class="input text-right" :data-cant="i" @keydown.enter.prevent="enfocar(i, 'precio')" @focus="$event.target.select()" /></td>
                  <td><input v-model.number="it.precio_unit" type="number" min="0" step="any" class="input text-right" :data-precio="i" @keydown.enter.prevent="siguienteFila(i)" @focus="$event.target.select()" /></td>
                  <td><input v-model.number="it.descuento" type="number" min="0" max="100" step="any" class="input text-right" /></td>
                  <td><select v-model.number="it.alicuota_iva" class="input !px-1"><option v-for="a in [0,2.5,5,10.5,21,27]" :key="a" :value="a">{{ a }}%</option></select></td>
                  <td class="text-right font-semibold tabular-nums pt-3">{{ moneda(totalItem(it)) }}</td>
                  <td class="pt-2"><button type="button" @click="form.items.splice(i,1)" class="text-marca-muted hover:text-carmin"><Icono nombre="trash" clase="w-4 h-4" /></button></td>
                </tr>
                <tr v-if="!form.items.length"><td colspan="7" class="text-center text-marca-muted py-8">Agregá ítems o usá "Cargar con IA".</td></tr>
              </tbody>
            </table>
          </div>
          <p v-if="form.errors.items" class="text-carmin text-xs px-4 pb-3">{{ form.errors.items }}</p>
        </div>

        <div v-if="esExportacion" class="card">
          <div class="flex items-center justify-between mb-1"><h2 class="font-bold">Exportación (Factura E)</h2><span class="text-[11px] text-marca-muted">Va a ARCA por WSFEX · sin IVA</span></div>
          <p v-if="!cliente?.pais_codigo" class="text-xs text-carmin mb-2">El cliente no tiene país cargado: editalo en Clientes (condición "Exterior", país y CUIT país).</p>
          <div class="grid sm:grid-cols-3 gap-3">
            <div><label class="label">Tipo</label><select v-model.number="form.exportacion.tipo_expo" class="input"><option v-for="(l, k) in arcaPaises.tipos_expo" :key="k" :value="Number(k)">{{ l }}</option></select></div>
            <div v-if="form.exportacion.tipo_expo === 1"><label class="label">Incoterm</label><select v-model="form.exportacion.incoterm" class="input"><option v-for="(l, k) in arcaPaises.incoterms" :key="k" :value="k">{{ l }}</option></select></div>
            <div v-if="form.exportacion.tipo_expo === 1"><label class="label">Permiso de embarque</label><input v-model="form.exportacion.permiso_embarque" class="input" placeholder="Opcional" /></div>
            <div><label class="label">Moneda para ARCA</label><select v-model="form.exportacion.moneda_arca" class="input" :disabled="form.moneda !== 'USD'"><option v-for="(l, k) in arcaPaises.monedas" :key="k" :value="k">{{ l }}</option></select><p class="text-[11px] text-marca-muted mt-1">{{ form.moneda === 'USD' ? 'Los importes van en esta moneda con la cotización cargada.' : 'En pesos se informa PES.' }}</p></div>
            <div><label class="label">Forma de pago</label><input v-model="form.exportacion.forma_pago" class="input" placeholder="Transferencia anticipada, carta de crédito…" /></div>
            <div class="sm:col-span-3"><label class="label">Observaciones comerciales</label><input v-model="form.exportacion.obs_comerciales" class="input" /></div>
          </div>
        </div>
        <div v-if="form.tipo === 'REM'" class="card">
          <div class="flex items-center justify-between mb-1"><h2 class="font-bold">Transporte (remito electrónico)</h2><span class="text-[11px] text-marca-muted">Sale impreso y arma el archivo del COT de ARBA</span></div>
          <div class="grid sm:grid-cols-3 gap-3">
            <div class="sm:col-span-3"><label class="label">Domicilio de entrega</label><input v-model="form.domicilio_entrega" class="input" :placeholder="cliente ? [cliente.address, cliente.city].filter(Boolean).join(', ') || 'Calle, número, localidad' : 'Calle, número, localidad'" /></div>
            <div><label class="label">Transportista</label><input v-model="form.transportista" class="input" placeholder="Nombre o empresa" /></div>
            <div><label class="label">CUIT transportista</label><input v-model="form.transportista_cuit" class="input" placeholder="30-12345678-9" /></div>
            <div><label class="label">Patente</label><input v-model="form.patente" class="input" placeholder="AB123CD" /></div>
            <div><label class="label">Bultos</label><input v-model.number="form.bultos" type="number" min="0" class="input" /></div>
            <div><label class="label">Peso (kg)</label><input v-model.number="form.peso_kg" type="number" min="0" step="any" class="input" /></div>
          </div>
        </div>
        <div class="card"><label class="label">Notas (salen impresas)</label><textarea v-model="form.notas" rows="2" class="input"></textarea></div>
      </div>

      <div class="space-y-4">
        <div class="card sticky top-20">
          <h2 class="font-bold mb-3">Resumen</h2>
          <div class="space-y-1.5 text-sm">
            <div class="flex justify-between"><span class="text-marca-muted">Neto</span><span class="tabular-nums">{{ moneda(totales.neto) }}</span></div>
            <div class="flex justify-between"><span class="text-marca-muted">IVA</span><span class="tabular-nums">{{ moneda(totales.iva) }}</span></div>
            <div v-if="cliente?.percepcion_iibb && esFactura" class="flex justify-between"><span class="text-marca-muted">Percepción IIBB 3%</span><span class="tabular-nums">{{ moneda(totales.neto * 0.03) }}</span></div>
            <p v-if="(cliente?.percepcion_iva || cliente?.percepcion_ganancias) && esFactura" class="text-[11px] text-marca-muted">Este cliente lleva percepción de {{ [cliente.percepcion_iva ? 'IVA' : null, cliente.percepcion_ganancias ? 'Ganancias' : null].filter(Boolean).join(' y ') }}: se calcula al emitir según Configuración → Impuestos.</p>
            <div class="flex justify-between text-lg font-extrabold pt-2 border-t border-marca-borde"><span>Total</span><span class="tabular-nums">{{ form.moneda === 'USD' ? 'US$ ' + totales.total.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : moneda(totales.total) }}</span></div>
            <div v-if="form.moneda === 'USD'" class="flex justify-between text-xs text-violeta font-semibold"><span>En pesos a {{ moneda(form.cotizacion || 0) }}</span><span class="tabular-nums">{{ moneda(totales.total * (form.cotizacion || 0)) }}</span></div>
          </div>
          <p v-if="form.errors.afip" class="text-carmin text-xs mt-3">{{ form.errors.afip }}</p>
          <p v-if="Object.keys(form.errors).length && !form.errors.afip" class="text-carmin text-xs mt-3">Revisá los campos marcados.</p>
          <div class="grid gap-2 mt-5">
            <button type="button" @click="guardar(true)" class="btn-primary w-full" :disabled="form.processing || !form.items.length">{{ form.processing ? 'Procesando…' : 'Emitir' }}</button>
            <button type="button" @click="guardar(false)" class="btn-secondary w-full" :disabled="form.processing">Guardar borrador</button>
            <p class="text-[11px] text-marca-muted text-center"><kbd class="px-1 rounded border border-marca-borde">Ctrl</kbd>+<kbd class="px-1 rounded border border-marca-borde">Enter</kbd> emite · <kbd class="px-1 rounded border border-marca-borde">Ctrl</kbd>+<kbd class="px-1 rounded border border-marca-borde">S</kbd> guarda · Enter en cantidad y precio pasa al siguiente</p>
            <Link :href="comprobante ? `/comprobantes/${comprobante.id}` : '/comprobantes'" class="btn-ghost w-full">Cancelar</Link>
          </div>
          <p v-if="form.sin_arca && esFiscal" class="text-[11px] text-amber-700 mt-3">Se emite como comprobante interno: no se informa a ARCA.</p>
          <p v-else-if="!afipConfigurado && esFiscal" class="text-[11px] text-amber-700 mt-3">Sin certificado AFIP se emite simulado (sin CAE).</p>
        </div>
      </div>
    </div>

    <!-- Cargar con IA -->
    <Modal :abierto="abrirIA" titulo="Cargar ítems con IA" ancho="max-w-2xl" @cerrar="abrirIA = false">
      <p class="text-sm text-marca-muted mb-3">Pegá el mensaje del cliente (WhatsApp, mail) o subí una foto del pedido. La IA lo cruza con tus artículos y arma los ítems; después revisás y emitís.</p>
      <textarea v-model="ia.texto" rows="5" class="input" placeholder="Ej: Hola, necesito 20 bolsas de cemento, 6 hierros del 8 y 1 metro de arena para el lunes"></textarea>
      <div class="mt-3 flex flex-wrap items-center gap-3">
        <label class="btn-secondary cursor-pointer"><input type="file" accept="image/*" class="hidden" @change="ia.imagen = $event.target.files[0]" /> Subir foto</label>
        <span v-if="ia.imagen" class="text-xs text-marca-muted">{{ ia.imagen.name }}</span>
        <button v-if="dictado.soportado" type="button" class="btn-secondary" :class="dictado.escuchando.value ? '!bg-carmin !text-white !border-carmin animate-pulse' : ''" @click="dictado.alternar()"><Icono nombre="mic" clase="w-4 h-4" /> {{ dictado.escuchando.value ? 'Escuchando… (tocá para parar)' : 'Dictar el pedido' }}</button>
        <span v-else class="text-xs text-marca-muted">El dictado por voz funciona en Chrome, Edge o Safari.</span>
      </div>
      <div v-if="ia.resultado" class="mt-4">
        <p class="text-xs font-bold uppercase tracking-widest text-marca-muted mb-2">Detectado ({{ ia.resultado.modo === 'ia' ? 'con IA' : 'reconocimiento básico' }})</p>
        <table class="table">
          <thead><tr><th>Pedido</th><th>Artículo</th><th class="text-right">Cant.</th><th class="text-right">Confianza</th></tr></thead>
          <tbody>
            <tr v-for="(r, i) in ia.resultado.items" :key="i">
              <td class="text-marca-muted">{{ r.pedido }}</td>
              <td><BuscadorSelect v-model="r.product_id" :opciones="opcionesProductos" :url="props.catalogoParcial.productos ? '/buscar/articulos/venta' : null" @cargados="f => sumar(productosCat, f)" placeholder="Elegir artículo…" @elegido="o => { if (o) { r.descripcion = o.label; r.precio_unit = o.precios[lista] } }" /></td>
              <td><input v-model.number="r.cantidad" type="number" step="any" class="input text-right w-20" /></td>
              <td class="text-right"><span class="badge" :class="r.confianza >= 0.7 ? 'bg-emerald-50 text-emerald-700' : r.confianza > 0 ? 'bg-amber-50 text-amber-700' : 'bg-carmin-light text-carmin'">{{ Math.round(r.confianza * 100) }}%</span></td>
            </tr>
          </tbody>
        </table>
        <p v-if="ia.resultado.observaciones" class="text-xs text-marca-muted mt-2">{{ ia.resultado.observaciones }}</p>
        <p v-if="ia.resultado.aviso" class="text-xs text-amber-700 mt-1">{{ ia.resultado.aviso }}</p>
      </div>
      <p v-if="ia.error" class="text-carmin text-sm mt-2">{{ ia.error }}</p>
      <template #pie>
        <button class="btn-secondary" @click="abrirIA = false">Cerrar</button>
        <button v-if="!ia.resultado" class="btn-violeta" @click="interpretar" :disabled="ia.cargando || (!ia.texto && !ia.imagen)">{{ ia.cargando ? 'Interpretando…' : 'Interpretar' }}</button>
        <button v-else class="btn-primary" @click="aplicarIA">Agregar {{ ia.resultado.items.filter(r => r.product_id).length }} ítems</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, nextTick, onMounted, onBeforeUnmount, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import BuscadorSelect from '@/Components/BuscadorSelect.vue'
import { moneda, cantidad, hoyISO } from '@/util/formato'
import { useDictado } from '@/util/dictado'

const props = defineProps({ catalogoParcial: { type: Object, default: () => ({}) },  proyectos: { type: Array, default: () => [] }, cotizacionUsd: { type: Number, default: 0 }, comprobante: Object, tipoInicial: String, origen: Object, tipos: Array, clientes: Array, productos: Array, puntosVenta: Array, puntoVentaDefault: Number, empresa: Object, afipConfigurado: Boolean, vendedores: { type: Array, default: () => [] }, vendedorDefault: Number, cbuFce: String, arcaPaises: { type: Object, default: () => ({ incoterms: {}, tipos_expo: {}, monedas: {}, paises: {} }) } })
const productosCat = ref([...props.productos])
const clientesCat = ref([...props.clientes])
function sumar(lista, filas) { const arr = Array.isArray(lista) ? lista : lista.value; const por = new Map(arr.map(x => [x.id, x])); filas.forEach(f => { const e = por.get(f.id); if (e) Object.assign(e, f); else arr.push(f) }) } // en el template los refs llegan desenvueltos; lo que ya está se actualiza (sugerido, precios)

const base = props.comprobante ?? (props.origen ? { contact_id: props.origen.contact_id, origen_id: props.origen.id, items: props.origen.items } : null)
const form = useForm({
  tipo: props.tipoInicial, contact_id: base?.contact_id ?? null, punto_venta_id: base?.punto_venta_id ?? props.puntoVentaDefault, vendedor_id: base?.vendedor_id ?? props.vendedorDefault ?? null, origen_id: base?.origen_id ?? null,
  fecha: base?.fecha ?? hoyISO(), condicion: base?.condicion ?? 'cta_cte', dias_vto: null, es_acopio: base?.es_acopio ?? false, entrega_pendiente: base?.entrega_pendiente ?? false, fce: base?.fce ?? false, sin_arca: base?.sin_arca ?? false, exportacion: { tipo_expo: 1, incoterm: 'FOB', permiso_embarque: '', moneda_arca: 'DOL', forma_pago: '', obs_comerciales: '', ...(base?.exportacion ?? {}) }, fce_vto_pago: base?.fce_vto_pago ?? null, canje_puntos: 0, notas: base?.notas ?? '',
  transportista: base?.transportista ?? '', transportista_cuit: base?.transportista_cuit ?? '', patente: base?.patente ?? '', bultos: base?.bultos ?? null, peso_kg: base?.peso_kg ?? null, domicilio_entrega: base?.domicilio_entrega ?? '',
  items: (base?.items ?? []).map(i => ({ ...i })), emitir: false, moneda: base?.moneda ?? 'ARS', cotizacion: base?.cotizacion && base.cotizacion !== 1 ? base.cotizacion : null, proyecto_id: base?.proyecto_id ?? (new URLSearchParams(location.search).get('proyecto_id') ? Number(new URLSearchParams(location.search).get('proyecto_id')) : null),
})
const esConversion = !!props.origen

const cliente = computed(() => clientesCat.value.find(c => c.id === form.contact_id))
const lista = computed(() => cliente.value?.lista_precios ?? 1)
const esFactura = computed(() => ['FX', 'FA', 'FB', 'FC'].includes(form.tipo))
const esFiscal = computed(() => !['PRE', 'REM'].includes(form.tipo))
const esExportacion = computed(() => esFiscal.value && cliente.value?.condicion_iva === 'Exterior')
const letra = computed(() => cliente.value?.condicion_iva === 'Exterior' ? 'E' : props.empresa.condicion_iva === 'Responsable Inscripto' ? (cliente.value?.condicion_iva === 'Responsable Inscripto' ? 'A' : 'B') : 'C')
const tipoResuelto = computed(() => ({ FX: `Factura ${letra.value}`, NCX: `Nota de crédito ${letra.value}`, NDX: `Nota de débito ${letra.value}` }[form.tipo] ?? null))
const titulo = computed(() => props.comprobante ? 'Editar borrador' : ({ PRE: 'Nuevo presupuesto', REM: 'Nuevo remito', NCX: 'Nueva nota de crédito', NDX: 'Nueva nota de débito' }[form.tipo] ?? 'Nueva factura'))

const opcionesClientes = computed(() => clientesCat.value.map(c => ({ id: c.id, label: c.name, sub: c.cuit ?? c.condicion_iva, extra: c.tipo, sugerido: c.sugerido })))
const opcionesProductos = computed(() => productosCat.value.map(p => ({ id: p.id, label: p.name, sub: p.sku, extra: moneda(p.precios[lista.value]), precios: p.precios, unit: p.unit, iva: p.iva, stock: p.stock, sugerido: p.sugerido })))
const stockDe = it => productosCat.value.find(p => p.id === it.product_id)?.stock ?? null

function alElegirCliente(o) {
  const c = clientesCat.value.find(x => x.id === o?.id)
  if (!c) return
  form.dias_vto = c.dias_pago
  if (c.dias_pago === 0 && !esConversion) form.condicion = 'contado'
  form.items.forEach(it => { const p = productosCat.value.find(x => x.id === it.product_id); if (p) { it.precio_unit = p.precios[c.lista_precios]; it.descuento = c.descuento } })
}
function alElegirProducto(it, o) {
  if (!o) return
  it.descripcion = o.label; it.unidad = o.unit; it.alicuota_iva = esExportacion.value ? 0 : o.iva; it.precio_unit = o.precios[lista.value]; it.descuento = cliente.value?.descuento ?? 0
  if (!it.cantidad) it.cantidad = 1
}
const puntos = ref(null)
watch(() => form.contact_id, async id => { puntos.value = null; form.canje_puntos = 0; if (!id) return; try { const r = await fetch(`/clientes/${id}/puntos`, { headers: { Accept: 'application/json' } }); if (r.ok) puntos.value = await r.json() } catch (e) {} }, { immediate: true })
function usarPuntos() {
  if (!puntos.value) return
  const neto = form.items.reduce((a, it) => a + netoItem(it) * (1 + (Number(it.alicuota_iva) || 0) / 100), 0)
  const pesos = Math.min(puntos.value.pesos, neto)
  const usar = Math.floor(pesos / puntos.value.valor_punto)
  if (usar < puntos.value.minimo) return
  form.canje_puntos = usar
  form.items.push({ product_id: null, descripcion: `Canje de ${usar} puntos`, cantidad: 1, unidad: 'un', precio_unit: -Math.round(usar * puntos.value.valor_punto * 100) / 100, descuento: 0, alicuota_iva: 0 })
}
function agregar(pre = {}) { form.items.push({ product_id: null, descripcion: '', cantidad: 1, unidad: null, precio_unit: 0, descuento: cliente.value?.descuento ?? 0, alicuota_iva: 21, ...pre }) }
// Facturar sin mouse: elegir artículo → cantidad → precio → Enter agrega la fila siguiente y vuelve al buscador.
function enfocar(i, campo) { nextTick(() => { const el = document.querySelector(campo === 'cant' ? `[data-cant="${i}"]` : campo === 'precio' ? `[data-precio="${i}"]` : `[data-fila="${i}"] input`); el?.focus(); el?.select?.() }) }
function siguienteFila(i) { if (i === form.items.length - 1) agregar(); enfocar(i + 1, 'fila') }
function teclasForm(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); if (form.items.length && !form.processing) guardar(true) }
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); if (!form.processing) guardar(false) }
}
onMounted(() => window.addEventListener('keydown', teclasForm)); onBeforeUnmount(() => window.removeEventListener('keydown', teclasForm))
const repitiendo = ref(false), repetida = ref('')
async function repetirUltima() {
  repitiendo.value = true; repetida.value = ''
  try {
    const r = await fetch(`/comprobantes/ultima-de/${form.contact_id}`, { headers: { Accept: 'application/json' } }); const d = await r.json()
    if (!d.items?.length) { repetida.value = 'Este cliente no tiene facturas anteriores.'; return }
    form.items = form.items.filter(i => i.product_id || i.descripcion)
    d.items.forEach(it => { const p = productosCat.value.find(x => x.id === it.product_id); agregar({ ...it, precio_unit: p ? p.precios[lista.value] : it.precio_unit }) })
    repetida.value = `Se cargaron ${d.items.length} ítems de la ${d.comprobante.numero} (${d.comprobante.fecha}) con los precios de hoy. Revisá cantidades.`
  } catch (e) { repetida.value = 'No se pudo cargar.' } finally { repitiendo.value = false }
}
const netoItem = it => (Number(it.cantidad) || 0) * (Number(it.precio_unit) || 0) * (1 - (Number(it.descuento) || 0) / 100)
const totalItem = it => netoItem(it) * (1 + (Number(it.alicuota_iva) || 0) / 100)
const totales = computed(() => {
  const neto = form.items.reduce((a, it) => a + netoItem(it), 0)
  const iva = form.items.reduce((a, it) => a + netoItem(it) * (Number(it.alicuota_iva) || 0) / 100, 0)
  const percep = cliente.value?.percepcion_iibb && esFactura.value ? neto * 0.03 : 0
  return { neto, iva, total: neto + iva + percep }
})

function guardar(emitir) {
  form.emitir = emitir
  form.post(props.comprobante ? `/comprobantes/${props.comprobante.id}` : '/comprobantes', { preserveScroll: true })
}

onMounted(() => {
  const q = new URLSearchParams(location.search)
  if (q.get('contact_id') && !form.contact_id) { form.contact_id = Number(q.get('contact_id')); alElegirCliente({ id: form.contact_id }) }
})

// IA
const abrirIA = ref(false)
const ia = reactive({ texto: '', imagen: null, cargando: false, resultado: null, error: null })
let iaBase = ''
const dictado = useDictado((t, final) => { if (!iaBase && !dictado.escuchando.value) iaBase = ia.texto; ia.texto = (iaBase ? iaBase.trim() + ' ' : '') + t; if (final) iaBase = ia.texto }, { continuo: true })
async function interpretar() {
  ia.cargando = true; ia.error = null
  try {
    const fd = new FormData()
    if (ia.texto) fd.append('texto', ia.texto)
    if (ia.imagen) fd.append('imagen', ia.imagen)
    fd.append('lista', lista.value)
    const { data } = await window.axios.post('/comprobantes/ia/interpretar', fd)
    ia.resultado = data
  } catch (e) { ia.error = e.response?.data?.message ?? 'No se pudo interpretar el pedido.' }
  finally { ia.cargando = false }
}
function aplicarIA() {
  ia.resultado.items.filter(r => r.product_id).forEach(r => {
    const p = productosCat.value.find(x => x.id === r.product_id)
    agregar({ product_id: r.product_id, descripcion: p?.name ?? r.descripcion, cantidad: r.cantidad, unidad: p?.unit, precio_unit: p ? p.precios[lista.value] : r.precio_unit, alicuota_iva: p?.iva ?? 21 })
  })
  abrirIA.value = false; ia.resultado = null; ia.texto = ''; ia.imagen = null
}
</script>
