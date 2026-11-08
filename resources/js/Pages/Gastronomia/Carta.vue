<template>
  <AppLayout titulo="Carta">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <Link href="/gastronomia" class="text-xs text-marca-muted hover:text-carmin">← Salón</Link>
        <h1 class="page-title">Carta</h1>
        <p class="page-subtitle">Qué va a cocina y las opciones de cada plato: el punto de la carne, la guarnición, los extras con precio. El mozo las elige al pedir y salen en la comanda.</p>
      </div>
      <input v-model="q" class="input w-60" placeholder="Buscar plato…" />
    </div>
    <div class="card mb-4" data-e2e="config-estaciones">
      <div class="flex flex-wrap items-center gap-2"><p class="font-bold text-sm mr-2">Estaciones</p>
        <span v-for="(e, i) in est" :key="i" class="flex items-center gap-1 rounded-full border border-marca-borde pl-2 pr-1 py-0.5 text-xs"><input v-model="e.nombre" class="w-20 bg-transparent font-semibold outline-none" /> avisa a los <input v-model.number="e.alerta" type="number" min="1" class="w-10 bg-transparent text-right outline-none" />' <button v-if="est.length > 1" class="text-marca-muted hover:text-carmin px-1" @click="est.splice(i, 1)">×</button></span>
        <button class="btn-ghost !px-2 text-xs" @click="est.push({ nombre: '', alerta: 15 })">+ Estación</button>
        <button v-if="puede('gastronomia','editar')" class="btn-secondary !py-1 text-xs ml-auto" @click="router.post('/gastronomia/estaciones', { estaciones: est.filter(e => e.nombre.trim()), qr_confirma: qrc }, { preserveScroll: true })">Guardar estaciones</button></div>
      <label class="flex items-center gap-2 text-xs mt-2"><input v-model="qrc" type="checkbox" class="accent-carmin" /> Los pedidos que hacen desde el QR de la mesa los confirma el mozo antes de ir a cocina</label>
      <p class="text-xs text-marca-muted mt-1">Cada estación tiene su pantalla (Cocina → elegís la estación) y su comanda impresa. Si un plato pasa los minutos que pusiste, se marca en rojo.</p>
    </div>
    <div class="card mb-4" data-e2e="happy-hours">
      <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-bold text-sm">Happy hour y precios por horario</p>
        <div class="flex gap-2"><button class="btn-ghost !px-2 text-xs" @click="hhs.push({ nombre: 'Happy hour', activo: true, pct: 20, desde: '18:00', hasta: '20:00', dias: [1, 2, 3, 4, 5], rubros: [] })">+ Franja</button>
          <button v-if="puede('gastronomia','editar')" class="btn-secondary !py-1 text-xs" @click="router.post('/gastronomia/happy-hours', { happy_hours: hhs }, { preserveScroll: true })" data-e2e="hh-guardar">Guardar</button></div></div>
      <div v-for="(h, i) in hhs" :key="i" class="flex flex-wrap items-center gap-2 mt-2 text-xs border-t border-marca-borde/60 pt-2">
        <input v-model="h.activo" type="checkbox" class="accent-carmin" /><input v-model="h.nombre" class="input !py-0.5 !text-xs !w-32" />
        <input v-model.number="h.pct" type="number" min="1" max="90" class="input !py-0.5 !text-xs !w-14 text-right" />% off de
        <input v-model="h.desde" type="time" class="input !py-0.5 !text-xs !w-auto" /> a <input v-model="h.hasta" type="time" class="input !py-0.5 !text-xs !w-auto" />
        <span class="flex gap-0.5"><button v-for="(d, k) in dias" :key="k" type="button" class="w-6 h-6 rounded-full border text-[10px] font-bold" :class="h.dias.includes(k + 1) ? 'bg-carmin text-white border-carmin' : 'border-marca-borde'" @click="h.dias = h.dias.includes(k + 1) ? h.dias.filter(x => x !== k + 1) : [...h.dias, k + 1]">{{ d }}</button></span>
        <select multiple v-model="h.rubros" class="input !py-0 !text-xs !w-40 !h-14" title="Vacío = toda la carta"><option v-for="r in rubros" :key="r.id" :value="r.id">{{ r.nombre }}</option></select>
        <button class="text-marca-muted hover:text-carmin" @click="hhs.splice(i, 1)">Quitar</button>
      </div>
      <p class="text-xs text-marca-muted mt-1">En esa franja, los platos de esos rubros (sin rubros elegidos, toda la carta) salen con el descuento, en el salón y en el QR. Una franja puede cruzar la medianoche.</p>
    </div>
    <div class="card p-0">
      <div v-for="p in visibles" :key="p.id" class="flex flex-wrap items-center gap-3 px-4 py-2.5 border-b border-marca-borde/60 text-sm">
        <div class="flex-1 min-w-[180px]"><p class="font-semibold">{{ p.name }}</p><p class="text-xs text-marca-muted">{{ p.rubro ?? 'Sin rubro' }} · {{ moneda(p.price, 0) }}</p></div>
        <div class="flex flex-wrap gap-1 max-w-md"><span v-if="p.horario" class="badge bg-amber-50 text-amber-700">{{ p.horario.desde }}–{{ p.horario.hasta }}</span><span v-for="g in p.modificadores" :key="g.nombre" class="badge bg-lavanda-light text-violeta">{{ g.nombre }}{{ g.obligatorio ? ' *' : '' }} · {{ g.opciones.length }}</span></div>
        <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" class="accent-carmin" :checked="p.va_cocina" :disabled="!puede('gastronomia','editar')" @change="router.post(`/gastronomia/carta/${p.id}`, { va_cocina: $event.target.checked, modificadores: p.modificadores }, { preserveScroll: true })" /> Se prepara</label>
        <select v-if="p.va_cocina" class="input !py-0.5 !text-xs !w-28" :value="p.estacion ?? estaciones[0].nombre" :disabled="!puede('gastronomia','editar')" @change="router.post(`/gastronomia/carta/${p.id}`, { estacion: $event.target.value, modificadores: p.modificadores }, { preserveScroll: true })" :data-estacion="p.id"><option v-for="e in estaciones" :key="e.nombre" :value="e.nombre">{{ e.nombre }}</option></select>
        <button v-if="puede('gastronomia','editar')" class="btn-secondary !py-1 text-xs" @click="editar(p)" :data-opciones="p.id">Opciones</button>
      </div>
      <p v-if="!visibles.length" class="text-center text-marca-muted text-sm py-10">No hay platos con ese nombre.</p>
    </div>

    <Modal :abierto="!!ed" :titulo="ed ? `Opciones de ${ed.name}` : ''" ancho="max-w-2xl" @cerrar="ed = null">
      <div v-if="ed" class="space-y-3">
        <div v-for="(g, gi) in ed.grupos" :key="gi" class="rounded-xl border border-marca-borde p-3">
          <div class="flex flex-wrap items-center gap-2">
            <input v-model="g.nombre" class="input !py-1 text-sm flex-1 min-w-[140px]" placeholder="Grupo: Punto, Guarnición, Extras…" />
            <label class="flex items-center gap-1 text-xs"><input v-model="g.obligatorio" type="checkbox" class="accent-carmin" /> Obligatorio</label>
            <label class="flex items-center gap-1 text-xs"><input v-model="g.multiple" type="checkbox" class="accent-carmin" /> Puede elegir varios</label>
            <button class="text-marca-muted hover:text-carmin text-xs" @click="ed.grupos.splice(gi, 1)">Quitar grupo</button>
          </div>
          <div v-for="(o, oi) in g.opciones" :key="oi" class="grid grid-cols-[1fr_110px_24px] gap-2 mt-2">
            <input v-model="o.nombre" class="input !py-1 text-sm" placeholder="Jugoso, Papas fritas, + Queso…" />
            <input v-model.number="o.precio" type="number" min="0" step="any" class="input !py-1 text-sm text-right" placeholder="$ extra" />
            <button class="text-marca-muted hover:text-carmin" @click="g.opciones.splice(oi, 1)">×</button>
          </div>
          <button class="btn-ghost !px-2 text-xs mt-1" @click="g.opciones.push({ nombre: '', precio: 0 })">+ Opción</button>
        </div>
        <div class="flex flex-wrap gap-2"><button class="btn-secondary text-xs" @click="ed.grupos.push({ nombre: '', obligatorio: false, multiple: false, opciones: [{ nombre: '', precio: 0 }] })">+ Grupo de opciones</button>
          <button v-for="m in modelos" :key="m.nombre" class="btn-ghost text-xs" @click="ed.grupos.push(JSON.parse(JSON.stringify(m)))">+ {{ m.nombre }}</button></div>
        <p class="text-xs text-marca-muted">Obligatorio: el mozo no puede cargar el plato sin elegir (por ejemplo, el punto). El precio extra se suma al plato. Para un <b>menú o combo</b> (entrada + principal + postre), armá un grupo obligatorio por paso.</p>
        <div class="rounded-xl border border-marca-borde p-3 text-sm">
          <label class="flex items-center gap-2 font-semibold"><input v-model="ed.conHorario" type="checkbox" class="accent-carmin" /> Sólo se sirve en un horario (menú del día, ejecutivo al mediodía)</label>
          <div v-if="ed.conHorario" class="flex flex-wrap items-center gap-2 mt-2 text-xs"><input v-model="ed.horario.desde" type="time" class="input !py-0.5 !text-xs !w-auto" /> a <input v-model="ed.horario.hasta" type="time" class="input !py-0.5 !text-xs !w-auto" />
            <span class="flex gap-0.5"><button v-for="(d, k) in dias" :key="k" type="button" class="w-6 h-6 rounded-full border text-[10px] font-bold" :class="ed.horario.dias.includes(k + 1) ? 'bg-carmin text-white border-carmin' : 'border-marca-borde'" @click="ed.horario.dias = ed.horario.dias.includes(k + 1) ? ed.horario.dias.filter(x => x !== k + 1) : [...ed.horario.dias, k + 1]">{{ d }}</button></span></div>
          <p class="text-xs text-marca-muted mt-1">Fuera de ese horario no aparece ni para el mozo ni en el QR.</p>
        </div>
      </div>
      <template #pie><button class="btn-secondary" @click="ed = null">Cancelar</button><button class="btn-primary" @click="guardar" data-e2e="carta-guardar">Guardar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ productos: Array, estaciones: { type: Array, default: () => [{ nombre: 'Cocina', alerta: 20 }] }, qrConfirma: Boolean, happyHours: { type: Array, default: () => [] }, rubros: { type: Array, default: () => [] } })
const est = ref(JSON.parse(JSON.stringify(props.estaciones)))
const qrc = ref(props.qrConfirma)
const { puede } = usePermisos()
const q = ref('')
const norm = s => (s ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
const visibles = computed(() => props.productos.filter(p => !q.value || norm(p.name + ' ' + (p.rubro ?? '')).includes(norm(q.value))))
const modelos = [
  { nombre: 'Punto', obligatorio: true, multiple: false, opciones: [{ nombre: 'Jugoso', precio: 0 }, { nombre: 'A punto', precio: 0 }, { nombre: 'Cocido', precio: 0 }] },
  { nombre: 'Guarnición', obligatorio: true, multiple: false, opciones: [{ nombre: 'Papas fritas', precio: 0 }, { nombre: 'Puré', precio: 0 }, { nombre: 'Ensalada', precio: 0 }] },
  { nombre: 'Extras', obligatorio: false, multiple: true, opciones: [{ nombre: '+ Queso', precio: 0 }, { nombre: '+ Huevo', precio: 0 }] },
  { nombre: 'Sin', obligatorio: false, multiple: true, opciones: [{ nombre: 'Sin sal', precio: 0 }, { nombre: 'Sin cebolla', precio: 0 }, { nombre: 'Sin TACC', precio: 0 }] },
]
const ed = ref(null)
function editar(p) { ed.value = { id: p.id, name: p.name, va_cocina: p.va_cocina, grupos: JSON.parse(JSON.stringify(p.modificadores ?? [])), conHorario: !!p.horario, horario: p.horario ? { ...p.horario, dias: [...(p.horario.dias ?? [])] } : { desde: '12:00', hasta: '16:00', dias: [1, 2, 3, 4, 5] } } }
const dias = ['L', 'M', 'M', 'J', 'V', 'S', 'D']
const hhs = ref(JSON.parse(JSON.stringify(props.happyHours ?? [])))
function guardar() {
  const grupos = ed.value.grupos.map(g => ({ ...g, opciones: g.opciones.filter(o => o.nombre.trim()) })).filter(g => g.nombre.trim() && g.opciones.length)
  router.post(`/gastronomia/carta/${ed.value.id}`, { va_cocina: ed.value.va_cocina, modificadores: grupos, horario: ed.value.conHorario ? ed.value.horario : {} }, { preserveScroll: true, onSuccess: () => (ed.value = null) })
}
</script>
