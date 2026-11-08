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
    <div class="card p-0">
      <div v-for="p in visibles" :key="p.id" class="flex flex-wrap items-center gap-3 px-4 py-2.5 border-b border-marca-borde/60 text-sm">
        <div class="flex-1 min-w-[180px]"><p class="font-semibold">{{ p.name }}</p><p class="text-xs text-marca-muted">{{ p.rubro ?? 'Sin rubro' }} · {{ moneda(p.price, 0) }}</p></div>
        <div class="flex flex-wrap gap-1 max-w-md"><span v-for="g in p.modificadores" :key="g.nombre" class="badge bg-lavanda-light text-violeta">{{ g.nombre }}{{ g.obligatorio ? ' *' : '' }} · {{ g.opciones.length }}</span></div>
        <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" class="accent-carmin" :checked="p.va_cocina" :disabled="!puede('gastronomia','editar')" @change="router.post(`/gastronomia/carta/${p.id}`, { va_cocina: $event.target.checked, modificadores: p.modificadores }, { preserveScroll: true })" /> Va a cocina</label>
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
        <p class="text-xs text-marca-muted">Obligatorio: el mozo no puede cargar el plato sin elegir (por ejemplo, el punto). El precio extra se suma al plato.</p>
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
const props = defineProps({ productos: Array })
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
function editar(p) { ed.value = { id: p.id, name: p.name, va_cocina: p.va_cocina, grupos: JSON.parse(JSON.stringify(p.modificadores ?? [])) } }
function guardar() {
  const grupos = ed.value.grupos.map(g => ({ ...g, opciones: g.opciones.filter(o => o.nombre.trim()) })).filter(g => g.nombre.trim() && g.opciones.length)
  router.post(`/gastronomia/carta/${ed.value.id}`, { va_cocina: ed.value.va_cocina, modificadores: grupos }, { preserveScroll: true, onSuccess: () => (ed.value = null) })
}
</script>
