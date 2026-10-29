<template>
  <div>
    <!-- Estado de la conexión y del paquete: siempre a la vista -->
    <div class="flex flex-wrap items-center gap-2 text-xs mb-3">
      <span class="badge" :class="online ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-carmin'"><Icono nombre="wifi" clase="w-3.5 h-3.5 inline" /> {{ online ? 'En línea' : 'Sin señal: se vende igual' }}</span>
      <button v-if="cola.length" class="badge bg-amber-50 text-amber-700" :disabled="!online || sincronizando" @click="sincronizar" data-e2e="cola">{{ sincronizando ? 'Mandando…' : `${cola.length} por mandar` }}</button>
      <span v-if="rechazadas.length" class="badge bg-carmin-light text-carmin">{{ rechazadas.length }} que no entraron</span>
      <span v-if="paquete" class="text-marca-muted">Datos del {{ fecha(paquete.generado) }}</span>
      <span v-if="datosViejos" class="badge bg-amber-50 text-amber-700">Descargaste hace {{ datosViejos }} días: los precios pueden haber cambiado</span>
      <button class="ml-auto btn-ghost !px-2 text-xs" @click="pantalla = pantalla === 'datos' ? 'ruta' : 'datos'">{{ pantalla === 'datos' ? '← Volver' : 'Datos y conexión' }}</button>
    </div>

    <!-- DATOS -->
    <div v-if="pantalla === 'datos'" class="grid lg:grid-cols-2 gap-4">
      <div class="card">
        <h2 class="font-bold mb-1">Datos en el teléfono</h2>
        <p class="text-sm text-marca-muted mb-3">Antes de salir, descargá con wifi. Después se actualiza solo cada vez que abrís con señal.</p>
        <p class="text-sm" v-if="paquete">{{ paquete.productos.length }} productos · {{ paquete.ruta.total }} clientes · descargado el {{ fecha(paquete.generado) }}</p>
        <p class="text-sm text-carmin" v-else>Todavía no descargaste nada.</p>
        <button class="btn-primary mt-3" :disabled="descargando || !online" @click="descargar" data-e2e="descargar">{{ descargando ? 'Descargando…' : 'Descargar ahora' }}</button>
      </div>
      <div class="card">
        <h2 class="font-bold mb-1">Por mandar</h2>
        <ul class="text-sm divide-y divide-marca-borde"><li v-for="c in cola" :key="c.offline_id" class="py-1.5 flex justify-between"><span>{{ c._que }}</span><span class="text-marca-muted">{{ fecha(c.fecha_offline) }}</span></li><li v-if="!cola.length" class="py-3 text-marca-muted">Nada pendiente.</li></ul>
        <h2 class="font-bold mt-4 mb-1 text-carmin">Las que NO entraron</h2>
        <p class="text-xs text-marca-muted mb-2">El sistema las rechazó. No se van solas: reintentá si se arregló, o descartalas.</p>
        <ul class="text-sm divide-y divide-marca-borde"><li v-for="(r, i) in rechazadas" :key="i" class="py-1.5"><p>{{ r._que }} <span class="text-carmin">· {{ r._error }}</span></p><div class="flex gap-2 mt-1"><button class="btn-secondary !py-0.5 text-xs" @click="cola.push(r); rechazadas.splice(i, 1); guardar(); sincronizar()">Reintentar</button><button class="btn-ghost !py-0.5 text-xs" @click="rechazadas.splice(i, 1); guardar()">Descartar</button></div></li><li v-if="!rechazadas.length" class="py-3 text-marca-muted">Ninguna.</li></ul>
      </div>
    </div>

    <!-- RUTA -->
    <div v-else-if="pantalla === 'ruta'" class="space-y-3">
      <div class="flex gap-2"><input v-model="q" class="input flex-1" placeholder="Buscar cliente por nombre, CUIT o localidad" data-e2e="buscar-cliente" /></div>
      <p v-if="ruta?.hasta && ruta.hasta < hoyISO()" class="text-xs text-marca-muted">Las compras que conoce el sistema llegan hasta el {{ ruta.hasta }}: el atraso se mide contra esa fecha.</p>
      <div v-for="g in gruposVisibles" :key="g.k" class="card p-0 overflow-hidden">
        <button class="w-full flex items-center justify-between px-4 py-2.5 text-left" @click="abiertos[g.k] = !abiertos[g.k]" :data-e2e="`grupo-${g.k}`">
          <span class="font-bold">{{ g.titulo }} <span class="text-marca-muted font-normal text-sm">· {{ g.items.length }}</span></span><span class="text-xs text-marca-muted">{{ abiertos[g.k] ? 'ocultar' : 'ver' }}</span>
        </button>
        <div v-if="abiertos[g.k]" class="divide-y divide-marca-borde border-t border-marca-borde">
          <div v-for="c in g.items" :key="c.id" class="px-4 py-2.5 flex flex-wrap items-center gap-2" :data-e2e="`cliente-${c.id}`">
            <div class="flex-1 min-w-[12rem]">
              <p class="font-semibold">{{ c.nombre }} <span v-if="c.agenda" class="badge bg-sky-50 text-sky-700 ml-1">{{ c.agenda }}</span></p>
              <p class="text-xs text-marca-muted">{{ c.direccion || 'sin dirección' }}<span v-if="c.ritmo"> · compra cada {{ c.ritmo }} días · última hace {{ c.dias }}</span><span v-else-if="c.dias !== null && c.dias !== undefined"> · última hace {{ c.dias }} días</span></p>
              <p class="text-xs"><span :class="c.vencido > 0 ? 'text-carmin font-semibold' : 'text-marca-muted'">Saldo {{ moneda(c.saldo, 0) }}<span v-if="c.vencido > 0"> · vencido {{ moneda(c.vencido, 0) }} ({{ c.mora_dias }} días)</span></span></p>
            </div>
            <div class="flex gap-1.5">
              <button class="btn-primary !py-1 text-xs" @click="elegir(c)" :data-e2e="`venderle-${c.id}`">Venderle</button>
              <button class="btn-secondary !py-1 text-xs" @click="visita(c, 'llegue')">Llegué</button>
              <button class="btn-secondary !py-1 text-xs" @click="noVendi = c">No vendí</button>
              <button class="btn-ghost !py-1 !px-2 text-xs" @click="hoyNoVa.push(c.id)">Hoy no va</button>
            </div>
          </div>
        </div>
      </div>
      <p v-if="!gruposVisibles.length" class="card text-center text-marca-muted">{{ paquete ? 'Ningún cliente coincide.' : 'Descargá los datos en "Datos y conexión" para ver tu ruta.' }}</p>
    </div>

    <!-- CLIENTE Y PEDIDO -->
    <div v-else class="grid lg:grid-cols-[1fr_22rem] gap-4">
      <div class="space-y-3 min-w-0">
        <div class="card !py-3" data-e2e="ficha-cliente">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
              <button class="text-xs text-marca-muted hover:text-carmin" @click="volverRuta">← Cambiar cliente</button>
              <p class="font-extrabold text-lg">{{ cli.nombre }}</p>
              <p class="text-xs text-marca-muted">Lista {{ cli.lista }}<span v-if="cli.descuento"> · {{ cli.descuento }}% general</span> · {{ cli.condicion_iva }}<span v-if="cli.limite"> · límite {{ moneda(cli.limite, 0) }}</span></p>
              <p class="text-sm mt-1"><b :class="cli.vencido > 0 ? 'text-carmin' : ''">Saldo {{ moneda(cli.saldo) }}</b><span v-if="cli.vencido > 0" class="text-carmin"> · {{ moneda(cli.vencido, 0) }} vencido hace {{ cli.mora_dias }} días</span></p>
            </div>
            <div class="flex gap-1.5 text-xs">
              <a v-if="cli.telefono" :href="`tel:${cli.telefono}`" class="btn-secondary !py-1">Llamar</a>
              <a v-if="cli.lat || cli.direccion" :href="cli.lat ? `https://www.google.com/maps?q=${cli.lat},${cli.lng}` : `https://www.google.com/maps/search/${encodeURIComponent(cli.direccion)}`" target="_blank" class="btn-secondary !py-1">Cómo llegar</a>
              <button class="btn-secondary !py-1" @click="noVendi = cli">No vendí</button>
            </div>
          </div>
          <p v-if="cli.notas" class="mt-2 text-xs rounded-lg bg-amber-50 text-amber-900 px-2 py-1">{{ cli.notas }}</p>
          <p v-if="ficha?.visitas?.length" class="mt-1 text-[11px] text-marca-muted">Última visita: {{ ficha.visitas[0].cuando }} · {{ ficha.visitas[0].tipo === 'venta' ? 'vendiste' : ficha.visitas[0].tipo === 'llegue' ? 'llegaste' : ficha.visitas[0].motivo }}</p>
        </div>

        <div class="flex gap-1.5 overflow-x-auto pb-1">
          <button v-for="a in atajos" :key="a.k" @click="atajo = a.k" class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border" :class="atajo === a.k ? 'bg-marca-texto text-white border-marca-texto' : 'bg-white border-marca-borde text-marca-muted'" :data-e2e="`atajo-${a.k}`">{{ a.t }}</button>
          <button class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border bg-white border-marca-borde text-violeta" @click="repetirUltimo" :disabled="!ficha?.ultimo" data-e2e="repetir">Repetir el último<span v-if="ficha?.ultimo" class="opacity-70"> ({{ ficha.ultimo.fecha }})</span></button>
          <button class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap border bg-white border-marca-borde text-violeta" @click="cargarSugerido" :disabled="!ficha?.sugerido?.length" data-e2e="sugerido">Pedido sugerido</button>
        </div>
        <div class="relative"><Icono nombre="search" clase="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-marca-muted" /><input ref="buscador" v-model="qp" class="input !pl-10 !py-3" placeholder="Buscar producto, código o escanear" data-e2e="buscar-producto" @keydown.enter.prevent="enterProducto" /></div>

        <div class="card p-0 divide-y divide-marca-borde max-h-[60vh] overflow-y-auto overflow-x-hidden">
          <div v-for="p in listado" :key="p.id" class="flex items-center gap-2 px-3 py-2" :data-e2e="`prod-${p.id}`">
            <input type="checkbox" class="accent-carmin w-5 h-5" :checked="!!sel[p.id]" @change="toggle(p)" />
            <div class="flex-1 min-w-0" @click="toggle(p)">
              <p class="font-semibold text-sm leading-tight">{{ p.name }}</p>
              <p class="text-[11px] text-marca-muted">{{ p.controla ? `${cantidad(p.stock)} ${p.unit}` : p.sku }}<span v-if="p.promo"> · llevando {{ cantidad(p.promo.desde) }} o más: {{ p.promo.pct }}% menos</span><span v-if="p._motivo" class="text-violeta"> · {{ p._motivo }}</span></p>
            </div>
            <div class="text-right"><p class="font-extrabold tabular-nums">{{ moneda(precioFinal(p)) }}</p><p v-if="origen(p) !== 'lista'" class="text-[10px] text-violeta">{{ origen(p) === 'pactado' ? 'pactado' : origen(p) === 'rubro' ? 'pactado (rubro)' : 'último' }}</p></div>
            <input v-if="sel[p.id]" v-model.number="sel[p.id].cantidad" type="number" step="any" min="0" class="input !py-1 w-16 text-center tabular-nums" @click.stop />
          </div>
          <p v-if="!listado.length" class="text-center text-sm text-marca-muted py-8">{{ qp ? 'Nada coincide.' : 'Sin historial: buscá o pasá a Todo el catálogo.' }}</p>
        </div>
        <button v-if="seleccionados.length" class="btn-primary w-full" @click="agregarSeleccion" data-e2e="agregar">Agregar {{ seleccionados.length }} al pedido</button>
      </div>

      <!-- El pedido -->
      <div class="card p-0 flex flex-col lg:sticky lg:top-4 max-h-[calc(100vh-150px)]" data-e2e="pedido">
        <div class="px-4 py-2.5 border-b border-marca-borde">
          <p class="label">¿Con qué lo cerrás?</p>
          <div class="flex gap-1.5">
            <button v-for="t in tiposDisponibles" :key="t.k" class="flex-1 py-1.5 rounded-lg border text-xs font-semibold" :class="tipo === t.k ? 'bg-sky-700 text-white border-sky-700' : 'bg-white border-marca-borde'" @click="tipo = t.k" :data-e2e="`tipo-${t.k}`">{{ t.t }}</button>
          </div>
          <p class="text-[11px] text-marca-muted mt-1">{{ tipo === 'PRE' ? 'Queda para mandar al cliente; no mueve stock ni cuenta.' : config.calle_emite ? 'Se emite ahora.' : 'Queda pendiente de emitir en el local.' }}</p>
        </div>
        <div class="flex-1 overflow-y-auto">
          <div v-for="(it, i) in pedido" :key="i" class="px-4 py-2 border-b border-marca-borde/60 text-sm flex items-center gap-2">
            <div class="flex-1 min-w-0"><p class="font-medium truncate">{{ it.descripcion }}</p><p class="text-xs text-marca-muted tabular-nums">{{ moneda(it.precio_unit) }} c/u<span v-if="it.descuento"> · {{ it.origen === 'promo' ? `${it.descuento}% de promo` : it.origen === 'pactado' || it.origen === 'rubro' ? `${it.descuento}% pactado` : `−${it.descuento}%` }}</span></p></div>
            <input v-model.number="it.cantidad" type="number" step="any" min="0" class="input !py-1 w-16 text-center tabular-nums" />
            <span class="w-20 text-right font-semibold tabular-nums">{{ moneda(it.cantidad * it.precio_unit * (1 - it.descuento / 100) * (ri ? 1 + it.alicuota_iva / 100 : 1)) }}</span>
            <button @click="pedido.splice(i, 1)" class="text-marca-muted hover:text-carmin"><Icono nombre="x" clase="w-4 h-4" /></button>
          </div>
          <p v-if="!pedido.length" class="text-center text-marca-muted text-sm py-10">Pedido vacío. Tildá lo que lleva.</p>
        </div>
        <div class="px-4 py-3 border-t border-marca-borde space-y-2">
          <div v-if="ri && totales.iva" class="flex justify-between text-xs text-marca-muted"><span>Neto {{ moneda(totales.neto) }}</span><span>IVA {{ moneda(totales.iva) }}</span></div>
          <div class="flex items-baseline justify-between"><span class="font-bold">TOTAL</span><span class="text-3xl font-black tabular-nums" data-e2e="total">{{ moneda(totales.total) }}</span></div>
          <input v-model="notas" class="input !py-1.5 text-xs" placeholder="Notas del comprobante (entregar después de las 14…)" maxlength="300" />
          <p v-if="avisoCredito" class="text-xs rounded-lg bg-carmin-light text-carmin px-2 py-1" data-e2e="aviso-credito">{{ avisoCredito }}</p>
          <p v-if="error" class="text-carmin text-xs">{{ error }}</p>
          <button class="btn-primary w-full !py-3" :disabled="!pedido.length || enviando" @click="confirmar()" data-e2e="confirmar">{{ enviando ? 'Mandando…' : (tipo === 'PRE' ? 'Guardar presupuesto' : 'Confirmar pedido') }}</button>
          <button v-if="pedido.length" class="w-full text-xs text-carmin" @click="pedido = []">Vaciar</button>
        </div>
      </div>
    </div>

    <!-- Resultado -->
    <div v-if="resultado" class="fixed inset-x-3 bottom-3 z-40 rounded-2xl shadow-lg px-4 py-3 text-sm flex flex-wrap items-center gap-3" :class="resultado.offline ? 'bg-amber-50 border border-amber-300 text-amber-900' : 'bg-emerald-50 border border-emerald-200 text-emerald-800'" data-e2e="resultado">
      <span v-if="resultado.offline">Guardado en el teléfono: <b>entra solo cuando vuelva la señal</b>. No lo vuelvas a cargar.</span>
      <span v-else><b>{{ resultado.tipo }} {{ resultado.numero }}</b> por <b class="tabular-nums">{{ moneda(resultado.total) }}</b> · {{ resultado.estado === 'emitido' ? 'emitido' : resultado.presupuesto ? 'guardado' : 'pendiente de emitir' }}</span>
      <Link v-if="resultado.comprobante_id" :href="`/comprobantes/${resultado.comprobante_id}`" class="font-semibold text-xs">Ver</Link>
      <button @click="resultado = null" class="ml-auto text-xs">✕</button>
    </div>

    <!-- No vendí -->
    <Modal :abierto="!!noVendi" :titulo="noVendi ? `No vendí a ${noVendi.nombre}` : ''" @cerrar="noVendi = null">
      <p class="text-sm text-marca-muted mb-2">El motivo es de una lista para poder contarlos después: si "estaba cerrado" encabeza la lista, el problema es el horario de la ruta.</p>
      <div class="grid gap-1.5"><button v-for="(t, k) in motivos" :key="k" class="text-left rounded-lg border px-3 py-2 text-sm" :class="motivo === k ? 'border-violeta bg-violeta/5' : 'border-marca-borde'" @click="motivo = k" :data-e2e="`motivo-${k}`">{{ t }}</button></div>
      <input v-model="notaVisita" class="input mt-2" placeholder="Nota (opcional)" />
      <template #pie><button class="btn-secondary" @click="noVendi = null">Cancelar</button><button class="btn-primary" :disabled="!motivo" @click="visita(noVendi, 'no_vendi'); noVendi = null" data-e2e="guardar-no-vendi">Guardar</button></template>
    </Modal>

    <!-- Vender igual -->
    <Modal :abierto="!!forzar" titulo="¿Vender igual?" @cerrar="forzar = null">
      <p class="text-sm">{{ forzar }}</p><p class="text-xs text-marca-muted mt-2">Podés vender, pero queda registrado que fue a pesar del aviso, con tu nombre.</p>
      <template #pie><button class="btn-secondary" @click="forzar = null">No</button><button class="btn-primary" @click="confirmar(true)" data-e2e="vender-igual">Sí, vender igual</button></template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad, hoyISO } from '@/util/formato'

const props = defineProps({ config: Object, puede: Object, motivos: Object, preciosConIva: Boolean, vendedor: Object })
const ri = computed(() => props.preciosConIva)
const xsrf = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')
const post = (url, body) => fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(body) })
const fecha = iso => iso ? new Date(iso).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : ''
const leer = (k, d) => { try { return JSON.parse(localStorage.getItem(k)) ?? d } catch (e) { return d } }
const escribir = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)) } catch (e) {} }

// --- El paquete: todo lo necesario para vender sin señal ---
const paquete = ref(leer('vender_paquete', null)), descargando = ref(false), online = ref(navigator.onLine)
const datosViejos = computed(() => { if (!paquete.value) return 0; const d = Math.floor((Date.now() - new Date(paquete.value.generado)) / 86400000); return d >= (props.config.avisar_datos_dias || 7) && props.config.avisar_datos_dias > 0 ? d : 0 })
async function descargar() {
  if (!navigator.onLine) return
  descargando.value = true
  try { const r = await fetch('/vender/paquete', { headers: { Accept: 'application/json' } }); if (r.ok) { paquete.value = await r.json(); escribir('vender_paquete', paquete.value) } } catch (e) {} finally { descargando.value = false }
}
const ruta = computed(() => paquete.value?.ruta)
const productos = computed(() => paquete.value?.productos ?? [])
const porId = computed(() => Object.fromEntries(productos.value.map(p => [p.id, p])))

// --- Ruta ---
const pantalla = ref('ruta'), q = ref(''), abiertos = reactive({ agenda: true, toca: true, dejo: false, no_toca: false, sin_historial: false, diarios: false }), hoyNoVa = ref(leer('vender_hoy_no_va_' + hoyISO(), []))
watch(hoyNoVa, v => escribir('vender_hoy_no_va_' + hoyISO(), v), { deep: true })
const titulos = { agenda: 'Hoy por agenda', toca: 'Le toca hoy', dejo: 'Dejó de comprar', no_toca: 'Todavía no le toca', sin_historial: 'Sin historial suficiente', diarios: 'Compran casi todos los días' }
const norm = s => (s ?? '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
const gruposVisibles = computed(() => {
  const g = ruta.value?.grupos ?? {}; const t = norm(q.value)
  return Object.keys(titulos).filter(k => g[k]?.length).map(k => ({ k, titulo: titulos[k], items: g[k].filter(c => !hoyNoVa.value.includes(c.id) && (!t || norm(c.nombre).includes(t) || (c.cuit || '').includes(q.value) || norm(c.direccion).includes(t))) })).filter(x => x.items.length)
})

// --- Cliente elegido ---
const cli = ref(null), ficha = ref(null), atajo = ref('suele'), qp = ref(''), sel = reactive({}), buscador = ref(null)
const atajos = [{ k: 'suele', t: 'Lo que suele llevar' }, { k: 'hace_rato', t: 'Hace rato que no lleva' }, { k: 'todo', t: 'Todo el catálogo' }]
async function elegir(c) {
  cli.value = c; ficha.value = null; pantalla.value = 'cliente'; Object.keys(sel).forEach(k => delete sel[k]); atajo.value = 'suele'
  const pend = leer('vender_pendiente_' + c.id, null); if (pend?.length && confirm('Tenés un pedido a medias con este cliente. ¿Lo retomás?')) pedido.value = pend; else pedido.value = []
  try { const r = await fetch(`/vender/cliente/${c.id}`, { headers: { Accept: 'application/json' } }); if (r.ok) { ficha.value = await r.json(); cli.value = { ...c, ...ficha.value.cliente }; escribir('vender_ficha_' + c.id, ficha.value) } else throw 0 } catch (e) { ficha.value = leer('vender_ficha_' + c.id, { condiciones: { lista: c.lista, descuento: c.descuento, rubros: {}, articulos: {} }, suele_llevar: [], sugerido: [], hace_rato: [] }) }
  if (!ficha.value.suele_llevar?.length) atajo.value = 'todo'
}
function volverRuta() { if (pedido.value.length) escribir('vender_pendiente_' + cli.value.id, pedido.value); pantalla.value = 'ruta'; cli.value = null }
const cond = computed(() => ficha.value?.condiciones ?? { lista: cli.value?.lista ?? 1, descuento: cli.value?.descuento ?? 0, rubros: {}, articulos: {} })
// El precio y el descuento de cada producto para este cliente: pactado por artículo, por rubro, su lista y descuento general. La promo por cantidad se aplica al cargar.
function base(p) { const a = cond.value.articulos?.[p.id]; const precio = a?.precio ?? p.prices?.[cond.value.lista] ?? p.price; let desc = a?.descuento ?? (a?.precio != null ? 0 : null); let origen = a ? (a.origen === 'ultimo' ? 'ultimo' : 'pactado') : null; if (desc == null && p.rubro_id && cond.value.rubros?.[p.rubro_id] != null) { desc = cond.value.rubros[p.rubro_id]; origen = origen ?? 'rubro' } return { precio, descuento: desc ?? cond.value.descuento ?? 0, origen: origen ?? 'lista' } }
const origen = p => base(p).origen
const precioFinal = p => { const b = base(p); return Math.round(b.precio * (1 - b.descuento / 100) * (ri.value ? 1 + p.iva / 100 : 1) * 100) / 100 }
const listado = computed(() => {
  const t = norm(qp.value)
  if (t) return productos.value.filter(p => norm(p.name).includes(t) || norm(p.sku).includes(t) || (p.barcode && p.barcode.includes(qp.value.trim()))).slice(0, 60)
  if (atajo.value === 'todo') return productos.value.slice(0, 200)
  const src = atajo.value === 'suele' ? (ficha.value?.suele_llevar ?? []) : (ficha.value?.hace_rato ?? [])
  return src.map(h => porId.value[h.product_id] ? { ...porId.value[h.product_id], _motivo: atajo.value === 'suele' ? `lleva ${cantidad(h.cantidad)} al año` : `lo llevó ${h.veces} veces` } : null).filter(Boolean)
})
function toggle(p) { if (sel[p.id]) delete sel[p.id]; else sel[p.id] = { p, cantidad: 1 } }
const seleccionados = computed(() => Object.values(sel))
function enterProducto() { const t = qp.value.trim(); if (!t) return; const p = productos.value.find(x => x.barcode === t || norm(x.sku) === norm(t)) ?? listado.value[0]; if (p) { sumar(p, 1); qp.value = '' } }
function agregarSeleccion() { seleccionados.value.forEach(s => sumar(s.p, s.cantidad)); Object.keys(sel).forEach(k => delete sel[k]) }

// --- El pedido ---
const pedido = ref([]), tipo = ref(props.config.calle_comprobante || 'PRE'), notas = ref(''), enviando = ref(false), error = ref(null), resultado = ref(null), forzar = ref(null)
const tiposDisponibles = computed(() => [{ k: 'PRE', t: 'Presupuesto' }, ...(props.puede.facturar ? [{ k: 'FX', t: 'Factura' }, { k: 'REM', t: 'Remito' }] : [])])
function sumar(p, cant) {
  const b = base(p); const ex = pedido.value.find(i => i.product_id === p.id)
  const cantidadTotal = (ex?.cantidad ?? 0) + cant
  // Promo por cantidad: gana la mayor entre la promo y el descuento del cliente, nunca se suman.
  let desc = b.descuento, orig = b.origen; if (p.promo && cantidadTotal >= p.promo.desde && p.promo.pct > desc) { desc = p.promo.pct; orig = 'promo' }
  if (ex) { ex.cantidad = cantidadTotal; ex.descuento = desc; ex.origen = orig } else pedido.value.push({ product_id: p.id, descripcion: p.name, cantidad: cant, precio_unit: b.precio, descuento: desc, origen: orig, alicuota_iva: p.iva, _promo: p.promo })
}
watch(pedido, v => v.forEach(i => { if (i._promo) { const b = base(porId.value[i.product_id] ?? {}); if (i.cantidad >= i._promo.desde && i._promo.pct > b.descuento) { i.descuento = i._promo.pct; i.origen = 'promo' } else if (i.origen === 'promo') { i.descuento = b.descuento; i.origen = b.origen } } }), { deep: true })
const totales = computed(() => { let neto = 0, iva = 0; pedido.value.forEach(i => { const n = Math.round(i.cantidad * i.precio_unit * (1 - i.descuento / 100) * 100) / 100; neto += n; iva += Math.round(n * i.alicuota_iva / 100 * 100) / 100 }); return { neto: Math.round(neto * 100) / 100, iva: Math.round(iva * 100) / 100, total: Math.round((neto + iva) * 100) / 100 } })
const avisoCredito = computed(() => { if (!cli.value || tipo.value === 'PRE') return null; const a = []; if (cli.value.limite > 0 && cli.value.saldo + totales.value.total > cli.value.limite) a.push('con este pedido se pasa del límite de crédito'); if (cli.value.vencido > 0 && cli.value.mora_dias > (props.config.dias_mora || 0)) a.push(`tiene ${moneda(cli.value.vencido, 0)} vencidos`); return a.length ? a.join(' y ') + '. Al confirmar te va a preguntar.' : null })
function repetirUltimo() { if (!ficha.value?.ultimo) return; let faltan = 0; ficha.value.ultimo.items.forEach(i => { const p = porId.value[i.product_id]; if (p) sumar(p, i.cantidad); else faltan++ }); if (faltan) error.value = `${faltan} artículo(s) del último pedido ya no están en el catálogo.` }
function cargarSugerido() { (ficha.value?.sugerido ?? []).forEach(s => { const p = porId.value[s.product_id]; if (p) sumar(p, s.cantidad) }) }
function ubicacion() { return new Promise(res => { if (!navigator.geolocation) return res({}); navigator.geolocation.getCurrentPosition(p => res({ lat: p.coords.latitude, lng: p.coords.longitude }), () => res({}), { timeout: 5000, maximumAge: 60000 }) }) }

// --- Cola: lo que se hizo sin señal (ventas y visitas) espera y se manda solo. Cada cosa lleva un número único: nunca entra dos veces ---
const cola = ref(leer('vender_cola', [])), rechazadas = ref(leer('vender_rechazadas', [])), sincronizando = ref(false)
const guardar = () => { escribir('vender_cola', cola.value); escribir('vender_rechazadas', rechazadas.value) }
const offlineId = () => 'off-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8)
async function sincronizar() {
  if (sincronizando.value || !cola.value.length || !navigator.onLine) return
  sincronizando.value = true
  for (const c of [...cola.value]) {
    try {
      const r = await post(c._url, c); const j = await r.json().catch(() => ({}))
      if (r.ok) { cola.value = cola.value.filter(x => x.offline_id !== c.offline_id); if (j.comprobante_id) resultado.value = { ...j, sync: true } }
      else if (r.status === 422 || r.status === 403) { cola.value = cola.value.filter(x => x.offline_id !== c.offline_id); rechazadas.value.push({ ...c, _error: j.message ?? Object.values(j.errors ?? {}).flat()[0] ?? 'rechazada' }) }
      else break
      guardar()
    } catch (e) { break }
  }
  sincronizando.value = false
}
async function visita(c, tipoV) {
  const pos = await ubicacion()
  const datos = { tipo: tipoV, motivo: tipoV === 'no_vendi' ? motivo.value : null, nota: notaVisita.value || null, ...pos, cuando: new Date().toISOString(), offline_id: offlineId(), _url: `/vender/cliente/${c.id}/visita`, _que: `${tipoV === 'llegue' ? 'Llegué a' : 'No vendí a'} ${c.nombre}`, fecha_offline: new Date().toISOString() }
  motivo.value = null; notaVisita.value = ''
  cola.value.push(datos); guardar(); if (navigator.onLine) sincronizar(); else resultado.value = { offline: true }
  if (tipoV === 'no_vendi') hoyNoVa.value.push(c.id)
}
const noVendi = ref(null), motivo = ref(null), notaVisita = ref('')
async function confirmar(vendoIgual = false) {
  if (enviando.value || !pedido.value.length) return
  enviando.value = true; error.value = null; forzar.value = null
  const pos = await ubicacion()
  const datos = { modo: 'calle', tipo: tipo.value, contact_id: cli.value.id, notas: notas.value || null, forzar: vendoIgual, items: pedido.value.map(i => ({ product_id: i.product_id, descripcion: i.descripcion, cantidad: i.cantidad, precio_unit: i.precio_unit, descuento: i.descuento })), ...pos, offline_id: offlineId(), fecha_offline: new Date().toISOString(), _url: '/vender/vender', _que: `${tipo.value === 'PRE' ? 'Presupuesto' : tipo.value === 'REM' ? 'Remito' : 'Factura'} a ${cli.value.nombre} por ${moneda(totales.value.total)}` }
  const limpiar = () => { pedido.value = []; notas.value = ''; localStorage.removeItem('vender_pendiente_' + cli.value.id); enviando.value = false }
  if (!navigator.onLine) { cola.value.push(datos); guardar(); resultado.value = { offline: true }; limpiar(); return }
  try {
    const r = await post(datos._url, datos); const j = await r.json().catch(() => ({}))
    if (r.ok) { resultado.value = j; if (j.aviso) resultado.value.aviso = j.aviso; limpiar(); if (cli.value) cli.value.saldo += tipo.value === 'FX' ? totales.value.total : 0; return }
    if (r.status === 422 && j.errors?.credito) { forzar.value = j.errors.credito[0]; enviando.value = false; return }
    error.value = j.message ?? Object.values(j.errors ?? {}).flat()[0] ?? 'No se pudo.'; enviando.value = false
  } catch (e) { cola.value.push(datos); guardar(); resultado.value = { offline: true }; limpiar() }
}
const onOnline = () => { online.value = true; sincronizar(); descargar() }, onOffline = () => (online.value = false)
onMounted(() => { window.addEventListener('online', onOnline); window.addEventListener('offline', onOffline); sincronizar(); if (navigator.onLine) descargar() })
onBeforeUnmount(() => { window.removeEventListener('online', onOnline); window.removeEventListener('offline', onOffline) })
</script>
