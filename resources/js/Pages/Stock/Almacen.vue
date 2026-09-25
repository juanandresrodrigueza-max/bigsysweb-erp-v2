<template>
  <AppLayout titulo="Almacén">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <Link href="/stock" class="text-xs text-marca-muted hover:text-carmin">← Stock</Link>
        <h1 class="page-title">Almacén</h1>
        <p class="page-subtitle">Cada cosa en su lugar: ubicaciones con código de barras (pasillo, estante, nivel), la pistola para guardar, mover, buscar y contar, y la hoja para preparar pedidos en orden de recorrido.</p>
      </div>
      <div class="flex gap-2 items-end">
        <div><label class="label">Depósito</label><select :value="deposito?.id" class="input" @change="router.get('/stock/almacen', { deposito: $event.target.value })"><option v-for="d in depositos" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></div>
        <Link :href="`/stock/almacen/etiquetas?deposito=${deposito?.id}`" class="btn-secondary">Etiquetas de ubicaciones</Link>
      </div>
    </div>

    <div class="flex gap-1 mb-4 overflow-x-auto">
      <button v-for="t in tabs" :key="t.k" class="px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap" :class="tab === t.k ? 'bg-carmin text-white' : 'text-marca-muted hover:bg-gris-light'" :data-e2e="`tab-${t.k}`" @click="tab = t.k">{{ t.l }}<span v-if="t.n" class="ml-1 opacity-70">{{ t.n }}</span></button>
    </div>

    <!-- Pistola -->
    <div v-if="tab === 'pistola'" class="grid lg:grid-cols-[1fr_1fr] gap-4">
      <div class="card">
        <div class="flex flex-wrap gap-2 mb-3">
          <button v-for="m in modos" :key="m.k" class="btn-secondary !py-1 text-xs" :class="modo === m.k ? '!bg-violeta !text-white !border-violeta' : ''" :data-e2e="`modo-${m.k}`" @click="cambiarModo(m.k)">{{ m.l }}</button>
        </div>
        <p class="text-sm font-semibold mb-1" data-e2e="paso">{{ paso }}</p>
        <input ref="scan" v-model="codigo" class="input !text-lg font-mono" placeholder="Escaneá o escribí el código y Enter" autocomplete="off" data-e2e="scan" @keydown.enter.prevent="leer" />
        <div v-if="modo !== 'consultar'" class="mt-3 grid grid-cols-2 gap-2 text-sm">
          <div class="rounded-lg bg-marca-fondo px-3 py-2"><p class="text-[11px] text-marca-muted">{{ modo === 'mover' ? 'Desde' : 'Ubicación' }}</p><p class="font-mono font-bold">{{ op.ubic?.codigo ?? '—' }}</p></div>
          <div v-if="modo === 'mover'" class="rounded-lg bg-marca-fondo px-3 py-2"><p class="text-[11px] text-marca-muted">Hacia</p><p class="font-mono font-bold">{{ op.hacia?.codigo ?? '—' }}</p></div>
          <div v-if="modo !== 'contar'" class="rounded-lg bg-marca-fondo px-3 py-2 col-span-2 sm:col-span-1"><p class="text-[11px] text-marca-muted">Artículo</p><p class="font-semibold">{{ op.art?.nombre ?? '—' }}</p></div>
          <div v-if="modo !== 'contar'"><label class="label !text-[10px]">Cantidad (cada lectura suma 1)</label><input v-model.number="op.cantidad" type="number" step="any" min="0" class="input" data-e2e="op-cantidad" @keydown.enter.prevent="confirmar" /></div>
        </div>
        <div v-if="modo === 'contar' && op.ubic" class="mt-3">
          <p class="text-xs text-marca-muted mb-1">Escaneá cada unidad (o cargá la cantidad). Lo que no escanees se toma como 0.</p>
          <table class="table text-sm"><tbody>
            <tr v-for="(c, pid) in op.conteo" :key="pid"><td>{{ c.nombre }}</td><td class="text-xs text-marca-muted">antes {{ cantidad(c.antes) }}</td><td><input v-model.number="c.cantidad" type="number" step="any" min="0" class="input !py-1 !w-24 text-right" /></td></tr>
          </tbody></table>
        </div>
        <div class="flex gap-2 mt-3" v-if="modo !== 'consultar'"><button class="btn-primary" :disabled="!listo || enviando" data-e2e="confirmar" @click="confirmar">{{ enviando ? '…' : ({ guardar: 'Guardar', mover: 'Mover', contar: 'Guardar conteo' })[modo] }}</button><button class="btn-ghost" @click="cambiarModo(modo)">Empezar de nuevo</button></div>
        <p v-if="msg" class="mt-3 text-sm rounded-lg px-3 py-2" :class="ok ? 'bg-emerald-50 text-emerald-800' : 'bg-carmin-light text-carmin'" data-e2e="msg">{{ msg }}</p>
      </div>
      <div class="card" data-e2e="resultado">
        <template v-if="res?.tipo === 'ubicacion'">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Ubicación · {{ res.ubicacion.deposito }}</p>
          <p class="text-2xl font-black font-mono">{{ res.ubicacion.codigo }}</p><p class="text-xs text-marca-muted mb-3">{{ tipos[res.ubicacion.tipo] }}</p>
          <table class="table text-sm"><tbody><tr v-for="c in res.ubicacion.contenido" :key="c.product_id + '-' + c.lote_id"><td>{{ c.nombre }}<span v-if="c.lote" class="block text-xs text-marca-muted">{{ c.lote }}</span></td><td class="text-right tabular-nums font-semibold">{{ cantidad(c.cantidad) }} {{ c.unidad }}</td></tr>
            <tr v-if="!res.ubicacion.contenido.length"><td class="text-marca-muted">Vacía.</td></tr></tbody></table>
        </template>
        <template v-else-if="res?.tipo === 'articulo'">
          <p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Artículo · stock {{ cantidad(res.articulo.stock) }} {{ res.articulo.unidad }}</p>
          <p class="text-xl font-black">{{ res.articulo.nombre }}</p><p class="text-xs text-marca-muted mb-3">{{ res.articulo.sku }}</p>
          <table class="table text-sm"><tbody>
            <tr v-for="u in res.articulo.ubicaciones" :key="u.ubicacion_id + '-' + u.lote_id"><td class="font-mono font-bold">{{ u.codigo }}</td><td class="text-xs">{{ u.deposito }}<span v-if="u.lote" class="block text-marca-muted">{{ u.lote }}</span></td><td class="text-right tabular-nums">{{ cantidad(u.cantidad) }}</td></tr>
            <tr v-for="s in res.articulo.sin_ubicar" :key="'s' + s.deposito_id"><td class="text-marca-muted">Sin ubicar</td><td class="text-xs">{{ s.deposito }}</td><td class="text-right tabular-nums text-amber-700">{{ cantidad(s.cantidad) }}</td></tr>
          </tbody></table>
        </template>
        <p v-else class="text-sm text-marca-muted">Escaneá una ubicación para ver qué hay, o un artículo para ver dónde está.</p>
      </div>
    </div>

    <!-- Ubicaciones -->
    <div v-if="tab === 'ubicaciones'" class="grid lg:grid-cols-[1fr_20rem] gap-4">
      <div class="card p-0 overflow-x-auto">
        <table class="table text-sm min-w-[560px]">
          <thead><tr><th>Orden</th><th>Código</th><th>Tipo</th><th class="text-right">Artículos</th><th class="text-right">Unidades</th><th></th></tr></thead>
          <tbody>
            <tr v-for="u in ubicaciones" :key="u.id" :class="u.activa ? '' : 'opacity-50'">
              <td class="tabular-nums text-marca-muted">{{ u.orden }}</td><td class="font-mono font-bold"><button class="hover:text-carmin" @click="consultar(u.codigo)">{{ u.codigo }}</button></td><td class="text-xs">{{ tipos[u.tipo] }}</td>
              <td class="text-right tabular-nums">{{ u.articulos }}</td><td class="text-right tabular-nums">{{ cantidad(u.unidades) }}</td>
              <td class="text-right whitespace-nowrap"><button class="text-xs text-violeta" @click="editar(u)">editar</button> <button class="text-xs text-carmin ml-1" @click="router.delete(`/stock/almacen/ubicaciones/${u.id}`, { preserveScroll: true })">borrar</button></td>
            </tr>
            <tr v-if="!ubicaciones.length"><td colspan="6" class="text-center text-marca-muted py-8">Todavía no hay ubicaciones en este depósito. Generalas de una vez con el formulario de la derecha.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="space-y-4">
        <div class="card text-sm" data-e2e="generar">
          <p class="font-bold mb-2">Generar ubicaciones</p>
          <div class="grid grid-cols-2 gap-2">
            <div class="col-span-2"><label class="label">Pasillos</label><input v-model="gen.pasillos" class="input !py-1.5" placeholder="A-D  o  1-5  o  A,B,FRIO" /></div>
            <div><label class="label">Estantes por pasillo</label><input v-model.number="gen.estantes" type="number" min="1" class="input !py-1.5" /></div>
            <div><label class="label">Niveles (0 = sin nivel)</label><input v-model.number="gen.niveles" type="number" min="0" class="input !py-1.5" /></div>
            <div><label class="label">Tipo</label><select v-model="gen.tipo" class="input !py-1.5"><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l }}</option></select></div>
            <div><label class="label">Prefijo</label><input v-model="gen.prefijo" class="input !py-1.5" placeholder="opcional" /></div>
          </div>
          <p class="text-[11px] text-marca-muted mt-2">Ejemplo: A-B × 3 estantes × 2 niveles = A-01-1, A-01-2… B-03-2 ({{ totalGen }} ubicaciones). El recorrido va en serpentina: un pasillo de ida, el siguiente de vuelta.</p>
          <button class="btn-primary !py-1 text-xs mt-2" :disabled="gen.processing || !gen.pasillos" @click="gen.transform(d => ({ ...d, deposito_id: deposito.id })).post('/stock/almacen/generar', { preserveScroll: true })">Generar</button>
        </div>
        <div class="card text-sm">
          <p class="font-bold mb-2">{{ uf.id ? 'Editar ubicación' : 'Una ubicación' }}</p>
          <div class="grid grid-cols-2 gap-2">
            <div class="col-span-2"><label class="label">Código</label><input v-model="uf.codigo" class="input !py-1.5 font-mono" placeholder="RECEPCION, A-01-1…" /></div>
            <div><label class="label">Tipo</label><select v-model="uf.tipo" class="input !py-1.5"><option v-for="(l, k) in tipos" :key="k" :value="k">{{ l }}</option></select></div>
            <div><label class="label">Orden</label><input v-model.number="uf.orden" type="number" min="0" class="input !py-1.5" /></div>
            <label class="col-span-2 flex items-center gap-2 text-xs"><input v-model="uf.activa" type="checkbox" class="accent-carmin" /> Activa</label>
          </div>
          <div class="flex gap-2 mt-2"><button class="btn-primary !py-1 text-xs" :disabled="!uf.codigo" @click="uf.transform(d => ({ ...d, deposito_id: deposito.id })).post(`/stock/almacen/ubicaciones${uf.id ? '/' + uf.id : ''}`, { preserveScroll: true, onSuccess: () => uf.reset() })">Guardar</button><button v-if="uf.id" class="btn-ghost !py-1 text-xs" @click="uf.reset()">Cancelar</button></div>
        </div>
      </div>
    </div>

    <!-- Sin ubicar -->
    <div v-if="tab === 'sin'" class="card p-0 overflow-x-auto">
      <p class="px-4 py-3 text-xs text-marca-muted border-b border-marca-borde">Mercadería del depósito que todavía no está en ninguna ubicación. Guardala con la pistola (modo Guardar).</p>
      <table class="table text-sm"><thead><tr><th>Artículo</th><th class="text-right">Sin ubicar</th><th></th></tr></thead><tbody>
        <tr v-for="s in sinUbicar" :key="s.product_id"><td>{{ s.nombre }} <span class="text-xs text-marca-muted">{{ s.sku }}</span></td><td class="text-right tabular-nums">{{ cantidad(s.cantidad) }} {{ s.unidad }}</td><td class="text-right"><button class="text-xs text-violeta" @click="empezarGuardar(s)">Guardar…</button></td></tr>
        <tr v-if="!sinUbicar.length"><td colspan="3" class="text-center text-marca-muted py-8">Todo está ubicado.</td></tr>
      </tbody></table>
    </div>

    <!-- Preparar pedido -->
    <div v-if="tab === 'preparar'" class="grid lg:grid-cols-[18rem_1fr] gap-4">
      <div class="card p-0 overflow-hidden">
        <p class="px-4 py-3 font-bold border-b border-marca-borde">Comprobantes recientes</p>
        <button v-for="c in comprobantes" :key="c.id" class="w-full text-left px-4 py-2 text-sm border-b border-marca-borde/60 hover:bg-gris-light/40" :class="prep?.comprobante?.id === c.id ? 'bg-violeta/5' : ''" @click="preparar(c.id)"><span class="font-medium">{{ c.nombre }}</span><span class="block text-xs text-marca-muted">{{ c.fecha }} · {{ c.cliente || 'Consumidor final' }}</span></button>
      </div>
      <div class="card" data-e2e="preparacion">
        <template v-if="prep">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-3"><div><p class="font-bold">{{ prep.comprobante.nombre }}</p><p class="text-xs text-marca-muted">{{ prep.comprobante.cliente || 'Consumidor final' }} · {{ prep.deposito }}</p></div><a :href="`/stock/almacen/preparar/${prep.comprobante.id}`" target="_blank" class="btn-secondary !py-1 text-xs">Imprimir hoja</a></div>
          <table class="table text-sm"><thead><tr><th>#</th><th>Ubicación</th><th>Artículo</th><th class="text-right">Cantidad</th></tr></thead><tbody>
            <tr v-for="(p, i) in prep.pasos" :key="i"><td class="text-marca-muted">{{ i + 1 }}</td><td class="font-mono font-bold">{{ p.codigo }}</td><td>{{ p.nombre }}<span v-if="p.lote" class="block text-xs text-marca-muted">{{ p.lote }}</span></td><td class="text-right tabular-nums font-semibold">{{ cantidad(p.cantidad) }} {{ p.unidad }}</td></tr>
            <tr v-if="!prep.pasos.length"><td colspan="4" class="text-marca-muted">Nada ubicado para este comprobante.</td></tr>
          </tbody></table>
          <p v-for="f in prep.faltan" :key="f.product_id" class="text-xs text-carmin mt-2">{{ f.nombre }}: faltan {{ cantidad(f.cantidad) }} en ubicaciones ({{ cantidad(f.sin_ubicar) }} sin ubicar).</p>
        </template>
        <p v-else class="text-sm text-marca-muted">Elegí un comprobante: el sistema arma el recorrido por el depósito con lo que hay que sacar de cada ubicación, primero lo que vence antes.</p>
      </div>
    </div>

    <!-- Movimientos -->
    <div v-if="tab === 'movs'" class="card p-0 overflow-x-auto">
      <table class="table text-sm"><thead><tr><th>Fecha</th><th>Artículo</th><th>Desde</th><th>Hacia</th><th class="text-right">Cantidad</th><th>Motivo</th><th>Usuario</th></tr></thead><tbody>
        <tr v-for="m in movimientos" :key="m.id"><td class="whitespace-nowrap">{{ m.fecha }}</td><td>{{ m.articulo }}</td><td class="font-mono text-xs">{{ m.desde }}</td><td class="font-mono text-xs">{{ m.hacia }}</td><td class="text-right tabular-nums">{{ cantidad(m.cantidad) }}</td><td class="text-xs">{{ m.motivo }}</td><td class="text-xs">{{ m.usuario }}</td></tr>
        <tr v-if="!movimientos.length"><td colspan="7" class="text-center text-marca-muted py-8">Sin movimientos todavía.</td></tr>
      </tbody></table>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, nextTick, onMounted } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { cantidad } from '@/util/formato'

const props = defineProps({ deposito: Object, depositos: Array, ubicaciones: Array, sinUbicar: Array, tipos: Object, movimientos: Array, comprobantes: Array })
const tabs = computed(() => [{ k: 'pistola', l: 'Pistola' }, { k: 'ubicaciones', l: 'Ubicaciones', n: props.ubicaciones.length }, { k: 'sin', l: 'Sin ubicar', n: props.sinUbicar.length }, { k: 'preparar', l: 'Preparar pedido' }, { k: 'movs', l: 'Movimientos' }])
const tab = ref(props.ubicaciones.length ? 'pistola' : 'ubicaciones')
const modos = [{ k: 'consultar', l: 'Consultar' }, { k: 'guardar', l: 'Guardar' }, { k: 'mover', l: 'Mover' }, { k: 'contar', l: 'Contar' }]
const modo = ref('consultar'), codigo = ref(''), res = ref(null), msg = ref(''), ok = ref(true), enviando = ref(false), scan = ref(null)
const op = reactive({ ubic: null, hacia: null, art: null, cantidad: 0, conteo: {} })
const foco = () => nextTick(() => scan.value?.focus())
onMounted(foco)
function cambiarModo(m) { modo.value = m; Object.assign(op, { ubic: null, hacia: null, art: null, cantidad: 0, conteo: {} }); msg.value = ''; codigo.value = ''; tab.value = 'pistola'; foco() }
const paso = computed(() => {
  if (modo.value === 'consultar') return 'Escaneá una ubicación o un artículo.'
  if (modo.value === 'guardar') return !op.ubic ? '1. Escaneá la ubicación donde vas a guardar.' : !op.art ? '2. Escaneá el artículo.' : '3. Confirmá la cantidad (o seguí escaneando para sumar).'
  if (modo.value === 'mover') return !op.ubic ? '1. Escaneá la ubicación de origen.' : !op.art ? '2. Escaneá el artículo.' : !op.hacia ? '3. Escaneá la ubicación de destino.' : '4. Confirmá la cantidad.'
  return !op.ubic ? '1. Escaneá la ubicación que vas a contar.' : '2. Escaneá cada unidad; al terminar, Guardar conteo.'
})
const listo = computed(() => modo.value === 'contar' ? !!op.ubic : modo.value === 'mover' ? op.ubic && op.hacia && op.art && op.cantidad > 0 : op.ubic && op.art && op.cantidad > 0)
async function buscar(c) { const r = await window.axios.get('/stock/almacen/escanear', { params: { codigo: c } }); return r.data }
async function consultar(c) { tab.value = 'pistola'; modo.value = 'consultar'; try { res.value = await buscar(c); msg.value = '' } catch (e) { res.value = null; ok.value = false; msg.value = e.response?.data?.error || 'Código desconocido.' } }
async function leer() {
  const c = codigo.value.trim(); codigo.value = ''; if (!c) return
  let r
  try { r = await buscar(c) } catch (e) { ok.value = false; msg.value = e.response?.data?.error || 'Código desconocido.'; return foco() }
  msg.value = ''; res.value = r
  if (modo.value === 'consultar') return foco()
  if (r.tipo === 'ubicacion') {
    if (modo.value === 'mover' && op.ubic && op.art) op.hacia = r.ubicacion
    else { op.ubic = r.ubicacion; if (modo.value === 'contar') op.conteo = Object.fromEntries(r.ubicacion.contenido.map(x => [x.product_id, { nombre: x.nombre, antes: x.cantidad, cantidad: 0 }])) }
  } else {
    const a = r.articulo
    if (!op.ubic) { ok.value = false; msg.value = 'Primero escaneá la ubicación.' }
    else if (modo.value === 'contar') { const k = op.conteo[a.id] ?? (op.conteo[a.id] = { nombre: a.nombre, antes: 0, cantidad: 0 }); k.cantidad = (Number(k.cantidad) || 0) + 1 }
    else if (op.art?.id === a.id) op.cantidad = (Number(op.cantidad) || 0) + 1
    else { op.art = a; op.cantidad = 1 }
  }
  foco()
}
async function confirmar() {
  if (!listo.value) return
  enviando.value = true
  try {
    const url = { guardar: '/stock/almacen/guardar', mover: '/stock/almacen/mover', contar: '/stock/almacen/contar' }[modo.value]
    const body = modo.value === 'contar' ? { ubicacion: op.ubic.codigo, conteos: Object.fromEntries(Object.entries(op.conteo).map(([k, v]) => [k, Number(v.cantidad) || 0])) }
      : modo.value === 'mover' ? { desde: op.ubic.codigo, hacia: op.hacia.codigo, product_id: op.art.id, cantidad: op.cantidad } : { ubicacion: op.ubic.codigo, product_id: op.art.id, cantidad: op.cantidad }
    const r = (await window.axios.post(url, body)).data
    ok.value = true; msg.value = r.mensaje; res.value = { tipo: 'ubicacion', ubicacion: r.ubicacion }
    const m = modo.value; Object.assign(op, { ubic: m === 'guardar' ? op.ubic : null, hacia: null, art: null, cantidad: 0, conteo: {} })
    router.reload({ only: ['ubicaciones', 'sinUbicar', 'movimientos'], preserveScroll: true })
  } catch (e) { ok.value = false; msg.value = Object.values(e.response?.data?.errors ?? {}).flat().join(' ') || e.response?.data?.message || 'No se pudo.' }
  finally { enviando.value = false; foco() }
}
function empezarGuardar(s) { cambiarModo('guardar'); msg.value = `Escaneá la ubicación para guardar ${s.nombre}.`; ok.value = true; op.art = { id: s.product_id, nombre: s.nombre }; op.cantidad = s.cantidad }
const gen = useForm({ pasillos: 'A-B', estantes: 3, niveles: 2, tipo: 'estanteria', prefijo: '' })
const totalGen = computed(() => { const p = (gen.pasillos || '').trim(); const m = p.match(/^([A-Za-z]|\d+)\s*-\s*([A-Za-z]|\d+)$/); const n = m ? (isNaN(m[1]) ? m[2].toUpperCase().charCodeAt(0) - m[1].toUpperCase().charCodeAt(0) + 1 : Number(m[2]) - Number(m[1]) + 1) : p.split(',').filter(x => x.trim()).length; return Math.max(0, n) * (gen.estantes || 0) * Math.max(1, gen.niveles || 0) })
const uf = useForm({ id: null, codigo: '', tipo: 'estanteria', orden: null, activa: true })
function editar(u) { Object.assign(uf, { id: u.id, codigo: u.codigo, tipo: u.tipo, orden: u.orden, activa: u.activa }) }
const prep = ref(null)
async function preparar(id) { prep.value = (await window.axios.get(`/stock/almacen/preparar/${id}`, { headers: { Accept: 'application/json' } })).data }
</script>
