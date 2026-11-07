<template>
  <AppLayout titulo="Armar el viaje">
    <!-- Fase 37.1: lo pendiente por cliente, con cantidades parciales, retiros de acopio y paradas a mano. -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><Link href="/comprobantes/entregas" class="text-xs text-marca-muted hover:text-carmin">← Hojas de reparto</Link><h1 class="page-title">Armar el viaje</h1><p class="page-subtitle">Tildá qué lleva el camión. Si va una parte, cambiá la cantidad. Abajo, la planilla de carga sumada por artículo.</p></div>
    </div>
    <div class="grid lg:grid-cols-[1fr_340px] gap-4">
      <div class="space-y-3" data-e2e="pendientes">
        <div v-for="c in pendientes" :key="c.contact_id ?? 0" class="card">
          <div class="flex justify-between"><b>{{ c.cliente }}</b><span class="text-xs text-marca-muted">{{ c.direccion || 'sin dirección' }}<span v-if="!c.con_ubicacion" class="text-amber-700"> · sin ubicación</span></span></div>
          <div v-for="d in c.documentos" :key="d.tipo + d.id" class="border-t border-marca-borde mt-2 pt-2">
            <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" class="accent-carmin" :checked="!!sel[clave(d)]" @change="toggle(d, $event.target.checked)" :data-doc="clave(d)" /> {{ d.label }} <span class="text-xs text-marca-muted font-normal">{{ d.fecha }}</span></label>
            <div v-if="sel[clave(d)]" class="pl-6 text-xs">
              <div v-for="i in d.items" :key="i.id" class="flex items-center gap-2 py-0.5"><span class="flex-1">{{ i.descripcion }} <span class="text-marca-muted">(quedan {{ i.pendiente }} {{ i.unidad }})</span></span><input v-model.number="sel[clave(d)].cantidades[i.id]" type="number" step="any" min="0" :max="i.pendiente" class="input !py-0.5 !w-24 text-right" /></div>
            </div>
          </div>
        </div>
        <p v-if="!pendientes.length" class="card text-center text-marca-muted py-10">No hay nada pendiente de entregar.</p>
      </div>
      <div class="space-y-3">
        <div class="card text-sm">
          <h2 class="font-bold mb-2">El viaje</h2>
          <div class="grid grid-cols-2 gap-2"><label class="text-xs">Fecha<input v-model="f.fecha" type="date" class="input !py-1" /></label><label class="text-xs">Chofer<input v-model="f.repartidor" list="lista-choferes" class="input !py-1" data-e2e="chofer" /></label><label class="text-xs col-span-2">Camión<input v-model="f.vehiculo" list="lista-camiones" class="input !py-1" data-e2e="camion" /></label></div>
          <datalist id="lista-choferes"><option v-for="c in reparto.choferes" :key="c.nombre" :value="c.nombre" /></datalist>
          <datalist id="lista-camiones"><option v-for="c in reparto.camiones" :key="c.nombre" :value="c.nombre">{{ c.patente }}</option></datalist>
          <details class="text-xs mt-1" data-e2e="camiones-choferes"><summary class="text-violeta cursor-pointer">Camiones y choferes</summary>
            <div class="mt-2 space-y-1">
              <p class="font-semibold">Camiones</p>
              <div v-for="(c, k) in cfg.camiones" :key="'c' + k" class="flex gap-1"><input v-model="c.nombre" class="input !py-0.5" placeholder="Nombre (Iveco blanco)" /><input v-model="c.patente" class="input !py-0.5 !w-24" placeholder="Patente" /><button class="text-carmin" @click="cfg.camiones.splice(k, 1)">✕</button></div>
              <button class="text-violeta" @click="cfg.camiones.push({ nombre: '', patente: '' })">+ camión</button>
              <p class="font-semibold mt-2">Choferes</p>
              <div v-for="(c, k) in cfg.choferes" :key="'h' + k" class="flex gap-1"><input v-model="c.nombre" class="input !py-0.5" placeholder="Nombre" /><select v-model="c.user_id" class="input !py-0.5 !w-36" title="Si tiene usuario, ve sus repartos al entrar"><option :value="null">Sin usuario (link)</option><option v-for="u in usuariosChofer" :key="u.id" :value="u.id">{{ u.name }}</option></select><button class="text-carmin" @click="cfg.choferes.splice(k, 1)">✕</button></div>
              <button class="text-violeta" @click="cfg.choferes.push({ nombre: '', user_id: null })">+ chofer</button>
              <label class="flex items-center gap-1 mt-2"><input type="checkbox" v-model="cfg.foto_obligatoria" /> Foto del remito firmado obligatoria al entregar</label>
              <p class="text-marca-muted">Un chofer con usuario (perfil Chofer en Configuración → Usuarios) entra y ve sólo sus repartos; no ocupa un usuario del plan.</p>
              <button class="btn-secondary !py-0.5 text-xs" @click="router.post('/comprobantes/entregas/reparto-config', cfg, { preserveScroll: true })">Guardar</button>
            </div>
          </details>
          <p class="label mt-2">Paradas a mano</p>
          <div v-for="(p, k) in manuales" :key="k" class="mb-1"><div class="flex gap-1"><input v-model="p.nombre" class="input !py-1" placeholder="Qué (retirar cheques…)" /><input v-model="p.direccion" class="input !py-1" placeholder="Dónde (o pegá un link de Google Maps)" @input="buscarDir(p)" data-e2e="dir" /><button class="text-carmin text-xs" @click="manuales.splice(k, 1)">✕</button></div>
            <div v-if="p.sug?.length" class="border border-marca-borde rounded-lg mt-0.5 text-xs bg-white"><button v-for="(o, j) in p.sug" :key="j" class="block w-full text-left px-2 py-1 hover:bg-gris-light" @click="Object.assign(p, { direccion: o.direccion, lat: o.lat, lng: o.lng, contact_id: o.contact_id ?? null, nombre: p.nombre || o.nombre, sug: [] })"><b v-if="o.tipo === 'cliente'">{{ o.nombre }}</b> {{ o.tipo === 'cliente' ? '· ' + o.direccion : o.nombre }} <span v-if="o.km !== null" class="text-marca-muted">· {{ o.km }} km</span></button></div></div>
          <button class="text-xs text-violeta" @click="manuales.push({ nombre: '', direccion: '' })">+ parada</button>
          <p class="text-xs mt-2">Sale de <b>{{ origen.nombre }}</b> <span v-if="origen.lat !== null" class="text-emerald-700">· con ubicación</span><button v-else class="text-violeta" @click="ubicarDeposito">· guardar la ubicación del depósito (estás acá)</button></p>
          <label class="flex items-center gap-2 text-xs mt-1"><input v-model="f.ordenar" type="checkbox" class="accent-carmin" /> Ordenar por el camino más corto</label>
          <p v-if="f.errors.paradas" class="text-carmin text-xs mt-1">{{ f.errors.paradas }}</p>
          <button class="btn-primary w-full mt-2" :disabled="f.processing || !paradas.length" @click="armar" data-e2e="armar-viaje">Armar viaje con {{ paradas.length }} paradas</button>
        </div>
        <div class="card text-sm" data-e2e="carga"><h2 class="font-bold mb-1">Planilla de carga</h2>
          <div v-for="c in carga" :key="c.descripcion" class="flex justify-between border-t border-marca-borde py-0.5"><span>{{ c.descripcion }}</span><b class="tabular-nums">{{ c.cantidad }} {{ c.unidad }}</b></div>
          <p v-if="!carga.length" class="text-xs text-marca-muted">Tildá lo que va.</p></div>
      </div>
    </div>
  </AppLayout>
</template>
<script setup>
import { reactive, ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { hoyISO } from '@/util/formato'
const props = defineProps({ pendientes: Array, origen: Object, reparto: { type: Object, default: () => ({ camiones: [], choferes: [], foto_obligatoria: true }) }, usuariosChofer: { type: Array, default: () => [] } })
// Fase 43.1 y 43.3: la lista de camiones y choferes, y el buscador de direcciones (clientes ubicados primero, cerca, con los km).
const cfg = reactive(JSON.parse(JSON.stringify(props.reparto)))
let tDir = null
function buscarDir(p) { clearTimeout(tDir); if ((p.direccion ?? '').length < 3) { p.sug = []; return } tDir = setTimeout(async () => { try { p.sug = (await window.axios.get('/comprobantes/entregas/direccion', { params: { q: p.direccion } })).data } catch { p.sug = [] } }, 350) }
const sel = reactive({}); const manuales = ref([])
const clave = d => d.tipo + ':' + d.id
function toggle(d, on) { if (on) sel[clave(d)] = { doc: d, cantidades: Object.fromEntries(d.items.map(i => [i.id, i.pendiente])) }; else delete sel[clave(d)] }
const f = useForm({ fecha: hoyISO(), repartidor: '', vehiculo: '', ordenar: true, paradas: [] })
const paradas = computed(() => [...Object.values(sel).map(s => ({ tipo: s.doc.tipo, id: s.doc.id, cantidades: s.doc.items.every(i => Number(s.cantidades[i.id]) === i.pendiente) ? null : s.cantidades })), ...manuales.value.filter(m => m.nombre).map(m => ({ tipo: 'parada', nombre: m.nombre, direccion: m.direccion, lat: m.lat ?? null, lng: m.lng ?? null, contact_id: m.contact_id ?? null }))])
const carga = computed(() => { const t = {}; Object.values(sel).forEach(s => s.doc.items.forEach(i => { const q = Number(s.cantidades[i.id]) || 0; if (!q) return; const k = i.product_id ?? i.descripcion; t[k] ??= { descripcion: i.descripcion, unidad: i.unidad, cantidad: 0 }; t[k].cantidad = Math.round((t[k].cantidad + q) * 1000) / 1000 })); return Object.values(t).sort((a, b) => a.descripcion.localeCompare(b.descripcion)) })
function armar() { f.transform(d => ({ ...d, paradas: paradas.value })).post('/comprobantes/entregas/armar') }
function ubicarDeposito() { navigator.geolocation?.getCurrentPosition(p => router.post('/comprobantes/entregas/origen', { lat: p.coords.latitude, lng: p.coords.longitude }, { preserveScroll: true }), () => alert('No pude leer tu ubicación.')) }
</script>
