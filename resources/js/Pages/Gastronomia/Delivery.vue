<template>
  <AppLayout titulo="Big Restó · Delivery">
    <!-- Fase 91: el reparto propio. Preparando → listos para salir → en camino → entregados hoy. Las zonas con su costo de envío. -->
    <PageHeader titulo="Delivery" subtitulo="Lo que se está preparando, lo que ya puede salir, lo que está en la calle y lo que se entregó hoy. El envío se cobra por zona y se agrega solo a la comanda."
      :principal="puede('gastronomia','crear') ? { label: 'Nuevo delivery', icono: 'plus', click: () => nueva = { tipo: 'delivery', cliente: '', direccion: '', telefono: '', zona: '' } } : null"
      :secundaria="puede('gastronomia','editar') ? { label: 'Zonas y repartidores', click: abrirZonas, e2e: 'zonas' } : null" />

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En curso</p><p class="text-xl font-extrabold tabular-nums">{{ tablero.kpis.en_curso }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En camino</p><p class="text-xl font-extrabold tabular-nums" :class="tablero.en_camino.length ? 'text-violeta' : ''">{{ tablero.en_camino.length }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Entregados hoy</p><p class="text-xl font-extrabold tabular-nums">{{ tablero.kpis.entregados_hoy }}</p><p class="text-[11px] text-marca-muted">{{ moneda(tablero.kpis.vendido_hoy, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Envíos cobrados</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(tablero.kpis.envios_hoy, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Demora promedio</p><p class="text-xl font-extrabold tabular-nums">{{ tablero.kpis.demora !== null ? tablero.kpis.demora + "'" : '—' }}</p><p class="text-[11px] text-marca-muted">de que sale a que se entrega</p></div>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4" data-e2e="tablero">
      <section v-for="col in columnas" :key="col.key" class="min-w-0">
        <p class="label flex items-center justify-between">{{ col.label }} <span class="font-normal normal-case text-marca-muted">{{ tablero[col.key].length }}</span></p>
        <div class="space-y-3">
          <div v-for="c in tablero[col.key]" :key="c.id" class="card !p-3" :class="col.borde" :data-delivery="c.id">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0"><p class="font-bold truncate">{{ c.cliente || 'Sin nombre' }} <span class="text-xs font-normal text-marca-muted">{{ c.numero }}</span></p><p class="text-xs text-marca-muted truncate">{{ c.direccion || 'Sin dirección' }}</p></div>
              <span class="font-black tabular-nums shrink-0">{{ moneda(c.total, 0) }}</span>
            </div>
            <p class="text-xs mt-1 flex flex-wrap gap-x-2 gap-y-0.5">
              <span v-if="c.zona" class="badge bg-lavanda-light text-violeta !px-1.5 !py-0">{{ c.zona }} · {{ moneda(c.costo_envio, 0) }}</span>
              <span v-else-if="zonas.length" class="text-amber-700">sin zona</span>
              <span v-if="col.key === 'preparando' || col.key === 'listos'" class="text-marca-muted">hace {{ tiempo(c.minutos) }}</span>
              <span v-if="c.en_camino_min !== null" class="text-violeta font-semibold">en camino {{ c.en_camino_min }}'<span v-if="c.repartidor"> · {{ c.repartidor }}</span></span>
              <span v-if="c.entregado" class="text-emerald-700">entregado {{ c.entregado }}<span v-if="c.repartidor"> · {{ c.repartidor }}</span></span>
              <span v-if="c.pagado" class="text-emerald-700">pagado</span>
            </p>
            <p class="text-xs text-marca-muted mt-1 line-clamp-2">{{ c.items.map(i => cantidad(i.cantidad) + '× ' + i.descripcion).join(', ') }}</p>
            <div class="flex flex-wrap gap-1.5 mt-2">
              <Link :href="`/gastronomia/comandas/${c.id}`" class="btn-ghost !px-2 !py-1 text-xs">Ver</Link>
              <template v-if="col.key === 'listos' && puede('gastronomia','crear')">
                <select v-if="repartidores.length" v-model="rep[c.id]" class="input !py-1 !text-xs !w-auto" :data-repartidor="c.id"><option value="">¿Quién lo lleva?</option><option v-for="r in repartidores" :key="r" :value="r">{{ r }}</option></select>
                <button class="btn-violeta !py-1 !px-3 text-xs" @click="salio(c)" :data-salio="c.id">Salió 🛵</button>
              </template>
              <button v-if="col.key === 'preparando' && puede('gastronomia','crear')" class="btn-secondary !py-1 !px-3 text-xs" @click="salio(c)" :data-salio="c.id" title="Salir igual, aunque la cocina no lo marcó listo">Salió igual</button>
              <template v-if="col.key === 'en_camino' && puede('gastronomia','crear')">
                <button v-for="(l, m) in medios" :key="m" class="!py-1 !px-2.5 text-xs" :class="c.pagado ? 'hidden' : 'btn-secondary'" @click="entregado(c, m)" :data-entregado="`${c.id}-${m}`">{{ l }}</button>
                <button v-if="c.pagado" class="btn-primary !py-1 !px-3 text-xs" @click="entregado(c, 'efectivo')" :data-entregado="`${c.id}-ok`">Entregado ✓</button>
                <span v-else class="text-[11px] text-marca-muted w-full">Tocá cómo pagó: se cobra y se cierra.</span>
              </template>
            </div>
          </div>
          <p v-if="!tablero[col.key].length" class="text-xs text-marca-muted border border-dashed border-marca-borde rounded-xl p-3 text-center">{{ col.vacio }}</p>
        </div>
      </section>
    </div>

    <Modal :abierto="zonasAbierto" titulo="Zonas de entrega y repartidores" @cerrar="zonasAbierto = false">
      <p class="text-xs text-marca-muted mb-2">Cada zona tiene su costo de envío y los barrios o calles que la identifican (separados por coma): si la dirección los menciona, el envío se agrega solo. También por WhatsApp.</p>
      <div class="space-y-2" data-e2e="zonas-lista">
        <div v-for="(z, i) in fz.zonas" :key="i" class="grid grid-cols-12 gap-1.5 items-center">
          <input v-model="z.nombre" class="input !py-1.5 col-span-3" placeholder="Centro" />
          <input v-model.number="z.costo" type="number" min="0" class="input !py-1.5 col-span-2" placeholder="$" />
          <input v-model="z.palabras" class="input !py-1.5 col-span-4" placeholder="barrios, calles" />
          <input v-model.number="z.demora" type="number" min="0" class="input !py-1.5 col-span-2" placeholder="min" />
          <button class="btn-ghost !px-1 text-carmin col-span-1" @click="fz.zonas.splice(i, 1)">✕</button>
        </div>
        <button class="btn-secondary !py-1 text-xs" @click="fz.zonas.push({ nombre: '', costo: 0, palabras: '', demora: 30 })" data-e2e="zona-mas">+ Zona</button>
      </div>
      <label class="label mt-4">Repartidores</label>
      <input v-model="repTexto" class="input" placeholder="Nombres separados por coma" data-e2e="repartidores" />
      <p v-if="fz.errors && Object.keys(fz.errors).length" class="text-xs text-carmin mt-2">Revisá las zonas: nombre y costo son obligatorios.</p>
      <template #pie><button class="btn-secondary" @click="zonasAbierto = false">Cancelar</button><button class="btn-primary" :disabled="fz.processing" data-e2e="guardar-zonas" @click="guardarZonas">Guardar</button></template>
    </Modal>

    <Modal :abierto="!!nueva" titulo="Nuevo delivery" @cerrar="nueva = null">
      <template v-if="nueva">
        <label class="label">Nombre del cliente</label><input v-model="nueva.cliente" class="input" />
        <label class="label mt-3">Dirección</label><input v-model="nueva.direccion" class="input" placeholder="Calle, número, barrio" />
        <div class="grid grid-cols-2 gap-3 mt-3"><div><label class="label">Teléfono</label><input v-model="nueva.telefono" class="input" /></div>
          <div v-if="zonas.length"><label class="label">Zona de envío</label><select v-model="nueva.zona" class="input" data-e2e="nueva-zona"><option value="">Según la dirección</option><option v-for="z in zonas" :key="z.nombre" :value="z.nombre">{{ z.nombre }} · {{ moneda(z.costo, 0) }}</option></select></div></div>
      </template>
      <template #pie><button class="btn-secondary" @click="nueva = null">Cancelar</button><button class="btn-primary" data-e2e="abrir-delivery" @click="router.post('/gastronomia/comandas', nueva)">Abrir y cargar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad, tiempo } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ tablero: Object, zonas: Array, repartidores: Array, medios: Object })
const { puede } = usePermisos()
const columnas = [
  { key: 'preparando', label: 'Preparando', vacio: 'Nada en cocina.', borde: '' },
  { key: 'listos', label: 'Listos para salir', vacio: 'Nada esperando repartidor.', borde: '!border-emerald-300' },
  { key: 'en_camino', label: 'En camino', vacio: 'Nadie en la calle.', borde: '!border-violeta/40' },
  { key: 'entregados', label: 'Entregados hoy', vacio: 'Todavía ninguno.', borde: 'opacity-80' },
]
const rep = reactive({}), nueva = ref(null)
function salio(c) { router.post(`/gastronomia/comandas/${c.id}/salio`, { repartidor: rep[c.id] || null }, { preserveScroll: true }) }
function entregado(c, medio) { router.post(`/gastronomia/comandas/${c.id}/entregado`, { medio }, { preserveScroll: true }) }
const zonasAbierto = ref(false)
const fz = useForm({ zonas: [], repartidores: [] })
const repTexto = ref('')
function abrirZonas() { fz.zonas = JSON.parse(JSON.stringify(props.zonas.length ? props.zonas : [{ nombre: 'Centro', costo: 0, palabras: '', demora: 30 }])); repTexto.value = props.repartidores.join(', '); zonasAbierto.value = true }
function guardarZonas() { fz.repartidores = repTexto.value.split(',').map(s => s.trim()).filter(Boolean); fz.post('/gastronomia/delivery/zonas', { preserveScroll: true, onSuccess: () => zonasAbierto.value = false }) }
let timer
onMounted(() => { timer = setInterval(() => { if (!zonasAbierto.value && !nueva.value) router.reload({ only: ['tablero'] }) }, 15000) })
onBeforeUnmount(() => clearInterval(timer))
</script>
