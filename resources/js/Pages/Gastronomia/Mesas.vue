<template>
  <AppLayout titulo="Salón">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div><h1 class="page-title">Salón</h1><p class="page-subtitle">Tocá una mesa para abrirla o ver su comanda. Se actualiza solo.</p></div>
      <div class="flex flex-wrap gap-2">
        <Link href="/gastronomia/cocina" class="btn-secondary"><Icono nombre="utensils" clase="w-4 h-4" /> Cocina <span v-if="kpis.en_cocina" class="ml-1 badge bg-carmin text-white">{{ kpis.en_cocina }}</span></Link>
        <button v-if="puede('gastronomia','crear')" @click="nueva = { tipo: 'mostrador', cliente: '', direccion: '', telefono: '' }" class="btn-secondary">Mostrador</button>
        <button v-if="puede('gastronomia','crear')" @click="nueva = { tipo: 'delivery', cliente: '', direccion: '', telefono: '' }" class="btn-secondary">Delivery</button>
        <button v-if="puede('gastronomia','editar')" @click="configAbierto = true" class="btn-ghost"><Icono nombre="settings" clase="w-4 h-4" /></button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-6 gap-3 mb-5">
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Mesas ocupadas</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.ocupadas }} <span class="text-sm text-marca-muted font-semibold">/ {{ kpis.mesas }}</span></p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Comandas abiertas</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.abiertas }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">En cocina</p><p class="text-xl font-extrabold tabular-nums" :class="kpis.en_cocina ? 'text-amber-600' : ''">{{ kpis.en_cocina }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Ventas hoy</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.ventas_hoy, 0) }}</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Cubiertos hoy</p><p class="text-xl font-extrabold tabular-nums">{{ kpis.cubiertos_hoy }}</p><p class="text-[11px] text-marca-muted">{{ kpis.comandas_hoy }} comandas</p></div>
      <div class="card py-3"><p class="text-[11px] font-bold uppercase tracking-widest text-marca-muted">Propinas hoy</p><p class="text-xl font-extrabold tabular-nums">{{ moneda(kpis.propinas_hoy, 0) }}</p></div>
    </div>

    <div v-for="(grupo, sector) in porSector" :key="sector" class="mb-5">
      <p class="label">{{ sector }}</p>
      <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 xl:grid-cols-8 gap-3">
        <button v-for="m in grupo" :key="m.id" @click="abrirMesa(m)" class="rounded-2xl p-3 text-left border-2 transition aspect-square flex flex-col justify-between" :class="claseMesa(m)">
          <div class="flex items-start justify-between"><span class="text-2xl font-black">{{ m.nombre }}</span><span class="text-[10px] font-semibold opacity-70">{{ m.capacidad }}p</span></div>
          <div v-if="m.comanda" class="text-xs leading-tight">
            <p class="font-bold tabular-nums text-sm">{{ moneda(m.comanda.total, 0) }}</p>
            <p class="opacity-80">{{ m.comanda.minutos }} min · {{ m.comanda.cubiertos }} cub.</p>
            <p v-if="m.comanda.estado === 'cuenta'" class="font-bold">Pidió la cuenta</p>
            <p v-else-if="m.comanda.listos" class="font-bold">{{ m.comanda.listos }} listo{{ m.comanda.listos > 1 ? 's' : '' }} 🔔</p>
            <p v-else-if="m.comanda.sin_enviar" class="font-bold">{{ m.comanda.sin_enviar }} sin enviar</p>
          </div>
          <p v-else class="text-xs opacity-60">Libre</p>
        </button>
      </div>
    </div>
    <div v-if="!mesas.length" class="card text-center text-marca-muted py-12">Todavía no hay mesas en esta sucursal. Creá las primeras con el engranaje de arriba.</div>

    <div v-if="otras.length" class="mb-5">
      <p class="label">Mostrador y delivery</p>
      <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-3">
        <Link v-for="c in otras" :key="c.id" :href="`/gastronomia/comandas/${c.id}`" class="card hover:border-carmin/40">
          <div class="flex items-center justify-between"><span class="font-bold">{{ c.titulo }}</span><span class="badge" :class="c.tipo === 'delivery' ? 'bg-violeta-light text-violeta' : 'bg-lavanda-light text-violeta'">{{ c.tipo }}</span></div>
          <p class="text-xs text-marca-muted">{{ c.minutos }} min · {{ c.items.length }} ítems<span v-if="c.direccion"> · {{ c.direccion }}</span></p>
          <p class="font-extrabold tabular-nums mt-1">{{ moneda(c.total, 0) }}</p>
        </Link>
      </div>
    </div>

    <Modal :abierto="!!nueva" :titulo="nueva?.tipo === 'delivery' ? 'Nuevo delivery' : 'Pedido de mostrador'" @cerrar="nueva = null">
      <template v-if="nueva">
        <label class="label">Nombre del cliente</label><input v-model="nueva.cliente" class="input" placeholder="Para llamarlo cuando esté listo" />
        <template v-if="nueva.tipo === 'delivery'"><label class="label mt-3">Dirección</label><input v-model="nueva.direccion" class="input" /><label class="label mt-3">Teléfono</label><input v-model="nueva.telefono" class="input" /></template>
      </template>
      <template #pie><button class="btn-secondary" @click="nueva = null">Cancelar</button><button class="btn-primary" @click="router.post('/gastronomia/comandas', nueva)">Abrir</button></template>
    </Modal>

    <Modal :abierto="configAbierto" titulo="Mesas de esta sucursal" @cerrar="configAbierto = false">
      <div v-for="m in mesas" :key="m.id" class="flex items-center gap-2 py-1.5 border-b border-marca-borde/60 text-sm"><span class="font-bold w-10">{{ m.nombre }}</span><span class="text-marca-muted flex-1">{{ m.sector }} · {{ m.capacidad }} personas</span><button @click="Object.assign(mf, { id: m.id, nombre: m.nombre, sector: m.sector, capacidad: m.capacidad, activa: m.activa })" class="text-xs text-violeta">editar</button><Link v-if="!m.comanda" :href="`/gastronomia/mesas/${m.id}`" method="delete" as="button" preserve-scroll class="text-xs text-carmin">quitar</Link></div>
      <div class="mt-3 p-3 rounded-xl bg-marca-fondo grid grid-cols-3 gap-2 items-end">
        <div><label class="label">{{ mf.id ? 'Editar' : 'Nueva' }} mesa</label><input v-model="mf.nombre" class="input !py-1.5" placeholder="Nº" /></div>
        <div><label class="label">Sector</label><input v-model="mf.sector" class="input !py-1.5" placeholder="Salón" list="sectores" /><datalist id="sectores"><option v-for="s in Object.keys(porSector)" :key="s" :value="s" /></datalist></div>
        <div><label class="label">Personas</label><input v-model.number="mf.capacidad" type="number" min="1" class="input !py-1.5" /></div>
        <div class="col-span-3 flex gap-2"><button class="btn-primary !py-1 text-xs" :disabled="!mf.nombre" @click="mf.post(`/gastronomia/mesas${mf.id ? '/' + mf.id : ''}`, { preserveScroll: true, onSuccess: () => mf.reset() })">Guardar</button><button v-if="mf.id" class="btn-ghost !py-1 text-xs" @click="mf.reset()">Cancelar</button></div>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Icono from '@/Components/Icono.vue'
import Modal from '@/Components/Modal.vue'
import { moneda } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ mesas: Array, otras: Array, kpis: Object, listaSucursales: Array })
const { puede } = usePermisos()
const porSector = computed(() => { const g = {}; for (const m of props.mesas) { (g[m.sector] ??= []).push(m) } return g })
const claseMesa = m => !m.activa ? 'opacity-40 border-marca-borde bg-white' : !m.comanda ? 'bg-white border-marca-borde hover:border-emerald-400 text-marca-texto' : m.comanda.estado === 'cuenta' ? 'bg-violeta text-white border-violeta' : m.comanda.listos ? 'bg-amber-100 border-amber-400 text-amber-900' : 'bg-carmin-light border-carmin/60 text-carmin-dark'
function abrirMesa(m) { if (m.comanda) router.visit(`/gastronomia/comandas/${m.comanda.id}`); else if (puede('gastronomia', 'crear')) router.post('/gastronomia/comandas', { mesa_id: m.id, cubiertos: 2 }) }
const nueva = ref(null), configAbierto = ref(false)
const mf = useForm({ id: null, nombre: '', sector: 'Salón', capacidad: 4, activa: true })
let timer
onMounted(() => { timer = setInterval(() => { if (!nueva.value && !configAbierto.value) router.reload({ only: ['mesas', 'otras', 'kpis'] }) }, 15000) })
onBeforeUnmount(() => clearInterval(timer))
</script>
