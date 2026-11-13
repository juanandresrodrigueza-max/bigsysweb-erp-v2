<template>
  <AppLayout titulo="Mozo de bolsillo">
    <!-- Fase 90: la pantalla del mozo en el celular. Todo grande, todo con el pulgar: mis mesas, lo que está listo para llevar, las mesas libres. -->
    <div class="max-w-lg mx-auto pb-24">
      <PageHeader titulo="Mis mesas" :subtitulo="`${yo} · ${mias.length} abierta${mias.length === 1 ? '' : 's'}`" compacto volver="/gastronomia" />

      <div v-if="paraLlevar.length" class="card !border-emerald-300 !bg-emerald-50/60 mb-4" data-e2e="para-llevar">
        <p class="font-bold text-emerald-800 flex items-center gap-2"><span class="text-lg">🔔</span> Listo para llevar</p>
        <div v-for="p in paraLlevar" :key="p.id" class="flex items-center justify-between gap-2 py-2 border-t border-emerald-200/70 text-sm">
          <div class="min-w-0"><p class="font-semibold truncate">{{ cantidad(p.cantidad) }}× {{ p.descripcion }}<span v-if="p.asiento" class="badge bg-lavanda-light text-violeta !px-1.5 !py-0 ml-1">A{{ p.asiento }}</span></p><p class="text-xs text-marca-muted">{{ p.titulo }}</p></div>
          <Link :href="`/gastronomia/items/${p.id}/estado`" method="post" :data="{ estado: 'entregado' }" as="button" preserve-scroll class="btn-primary !py-2 !px-3 text-sm shrink-0" :data-entregado="p.id">Entregado ✓</Link>
        </div>
      </div>

      <div v-if="llamados.length" class="card !border-carmin/40 mb-4" data-e2e="llamados">
        <p class="font-bold text-carmin">🙋 Te llaman</p>
        <Link v-for="c in llamados" :key="c.id" :href="`/gastronomia/comandas/${c.id}`" class="flex justify-between py-2 border-t border-marca-borde/60 text-sm"><span class="font-semibold">{{ c.titulo }}</span><span class="text-carmin font-bold animate-pulse">{{ c.llamado === 'cuenta' ? 'Pide la cuenta' : 'Llama al mozo' }} · {{ c.llamado_min }}'</span></Link>
      </div>

      <div class="space-y-3 mb-6">
        <div v-for="c in mias" :key="c.id" class="card !p-0 overflow-hidden" :data-mia="c.id">
          <Link :href="`/gastronomia/comandas/${c.id}`" class="flex items-center gap-3 p-4 active:bg-gris-light/60">
            <div class="w-14 h-14 rounded-2xl border-2 flex items-center justify-center text-xl font-black shrink-0" :class="c.estado === 'cuenta' ? 'bg-violeta text-white border-violeta' : c.listos ? 'bg-emerald-50 border-emerald-400 text-emerald-800' : c.sin_enviar ? 'bg-amber-50 border-amber-400 text-amber-800' : 'bg-lavanda-light border-lavanda text-violeta'">{{ c.mesa ?? (c.tipo === 'delivery' ? '🛵' : '🧾') }}</div>
            <div class="flex-1 min-w-0">
              <p class="font-bold truncate">{{ c.titulo }} <span class="text-xs font-normal text-marca-muted">· {{ tiempo(c.minutos) }}<span v-if="c.cubiertos"> · {{ c.cubiertos }} cub.</span></span></p>
              <p class="text-xs mt-0.5 flex flex-wrap gap-x-2">
                <span v-if="c.estado === 'cuenta'" class="font-bold text-violeta">Pidió la cuenta</span>
                <span v-if="c.sin_enviar" class="font-bold text-amber-700">{{ c.sin_enviar }} sin enviar</span>
                <span v-if="c.en_cocina" class="text-marca-muted">{{ c.en_cocina }} en cocina</span>
                <span v-if="c.listos" class="font-bold text-emerald-700">{{ c.listos }} listo{{ c.listos > 1 ? 's' : '' }}</span>
                <span v-if="!c.sin_enviar && !c.en_cocina && !c.listos && c.estado !== 'cuenta'" class="text-marca-muted">{{ c.total ? 'Comiendo' : 'Sin pedir todavía' }}</span>
              </p>
            </div>
            <span class="font-black tabular-nums">{{ moneda(c.total, 0) }}</span>
          </Link>
          <div class="grid grid-cols-3 border-t border-marca-borde/60 text-sm font-semibold divide-x divide-marca-borde/60">
            <Link :href="`/gastronomia/comandas/${c.id}`" class="py-3 text-center text-carmin active:bg-gris-light/60" :data-cargar="c.id">+ Cargar</Link>
            <Link v-if="c.sin_enviar" :href="`/gastronomia/comandas/${c.id}/enviar`" method="post" as="button" preserve-scroll class="py-3 text-center text-violeta active:bg-gris-light/60" :data-enviar="c.id">Enviar ({{ c.sin_enviar }})</Link>
            <span v-else class="py-3 text-center text-marca-muted/50">Enviar</span>
            <Link v-if="c.estado === 'abierta'" :href="`/gastronomia/comandas/${c.id}/cuenta`" method="post" as="button" preserve-scroll class="py-3 text-center active:bg-gris-light/60" :data-cuenta="c.id">Cuenta</Link>
            <Link v-else :href="`/gastronomia/comandas/${c.id}`" class="py-3 text-center text-emerald-700">Cobrar</Link>
          </div>
        </div>
        <EstadoVacio v-if="!mias.length" compacto texto="No tenés mesas abiertas. Tocá una libre de abajo para empezar." icono="inbox" />
      </div>

      <p class="label flex items-center justify-between">Mesas libres <span class="text-marca-muted font-normal normal-case">{{ libres.length }}</span></p>
      <div class="grid grid-cols-4 gap-2 mb-6" data-e2e="libres">
        <button v-for="m in libres" :key="m.id" type="button" class="rounded-2xl border-2 border-marca-borde bg-white py-3 flex flex-col items-center active:border-emerald-400 active:bg-emerald-50 disabled:opacity-50" :disabled="abriendo === m.id || !puede('gastronomia','crear')" @click="abrir(m)" :data-libre="m.id">
          <span class="text-xl font-black leading-none">{{ m.nombre }}</span><span class="text-[11px] text-marca-muted mt-1">{{ m.sector }} · {{ m.capacidad }}p</span>
        </button>
        <p v-if="!libres.length" class="col-span-4 text-sm text-marca-muted">Salón completo.</p>
      </div>

      <details v-if="otras.length" class="card !p-3">
        <summary class="cursor-pointer text-sm font-semibold text-marca-muted">De otros mozos ({{ otras.length }})</summary>
        <Link v-for="c in otras" :key="c.id" :href="`/gastronomia/comandas/${c.id}`" class="flex justify-between py-2 border-t border-marca-borde/60 text-sm"><span>{{ c.titulo }} <span class="text-xs text-marca-muted">· {{ c.mozo }}</span></span><span class="tabular-nums">{{ moneda(c.total, 0) }}</span></Link>
      </details>
    </div>

    <Modal :abierto="!!abriendo" :titulo="`Abrir ${mesaAbrir?.nombre ? 'mesa ' + mesaAbrir.nombre : 'mesa'}`" @cerrar="abriendo = null">
      <p class="text-sm text-marca-muted mb-3">¿Cuántos son?</p>
      <div class="grid grid-cols-4 gap-2">
        <button v-for="n in 8" :key="n" type="button" class="rounded-xl border-2 py-3 text-xl font-black" :class="cubiertos === n ? 'bg-carmin text-white border-carmin' : 'border-marca-borde'" @click="cubiertos = n" :data-cubiertos="n">{{ n }}</button>
      </div>
      <template #pie><button class="btn-secondary" @click="abriendo = null">Cancelar</button><button class="btn-primary" data-e2e="confirmar-abrir" @click="confirmarAbrir">Abrir y cargar</button></template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import Modal from '@/Components/Modal.vue'
import { moneda, cantidad, tiempo } from '@/util/formato'
import { usePermisos } from '@/util/permisos'
const props = defineProps({ mias: Array, otras: Array, libres: Array, yo: String })
const { puede } = usePermisos()
const paraLlevar = computed(() => props.mias.flatMap(c => c.para_llevar.map(p => ({ ...p, titulo: c.titulo }))))
const llamados = computed(() => props.mias.filter(c => c.llamado))
const abriendo = ref(null), cubiertos = ref(2)
const mesaAbrir = computed(() => props.libres.find(m => m.id === abriendo.value))
function abrir(m) { abriendo.value = m.id; cubiertos.value = Math.min(m.capacidad || 2, 2) }
function confirmarAbrir() { router.post('/gastronomia/comandas', { mesa_id: abriendo.value, cubiertos: cubiertos.value }); abriendo.value = null }
let timer
onMounted(() => { timer = setInterval(() => { if (!abriendo.value) router.reload({ only: ['mias', 'otras', 'libres'] }) }, 15000) })
onBeforeUnmount(() => clearInterval(timer))
</script>
