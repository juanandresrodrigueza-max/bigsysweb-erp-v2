<template>
  <AppLayout :titulo="'Vender · ' + modos[modo]">
    <!-- La franja dice siempre en qué modo estás y a nombre de quién vendés: cambiar de modo es un toque. -->
    <div class="rounded-2xl px-4 py-2.5 mb-4 flex flex-wrap items-center justify-between gap-2 text-white" :class="franja[modo]" data-e2e="franja">
      <div class="flex items-center gap-3 min-w-0">
        <h1 class="font-extrabold text-lg">Vender</h1>
        <button class="rounded-full bg-white/20 hover:bg-white/30 px-3 py-1 text-sm font-semibold" @click="selector = true" data-e2e="cambiar-modo">{{ modos[modo] }} <span class="opacity-70">· cambiar</span></button>
        <span class="text-xs opacity-90 truncate">{{ vendedor ? `a nombre de ${vendedor.nombre}` : 'tu usuario no es vendedor: las ventas van sin vendedor' }}</span>
      </div>
      <Link href="/comprobantes" class="text-xs opacity-80 hover:opacity-100">← Comprobantes</Link>
    </div>

    <VenderCalle v-if="modo === 'calle'" v-bind="$props" />
    <VenderMostrador v-else v-bind="$props" />

    <Modal :abierto="selector" titulo="¿Dónde vendés hoy?" @cerrar="selector = false">
      <p class="text-sm text-marca-muted mb-3">Es la misma app: cambia la pantalla, no la forma de facturar. Los precios y los permisos son los mismos.</p>
      <div class="grid gap-2">
        <button v-for="(t, k) in modos" :key="k" class="text-left rounded-xl border p-3" :class="k === modo ? 'border-violeta bg-violeta/5' : 'border-marca-borde hover:border-violeta/50'" :data-e2e="`modo-${k}`" @click="cambiar(k)">
          <p class="font-bold">{{ t }}</p><p class="text-xs text-marca-muted">{{ ayuda[k] }}</p>
        </button>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import VenderMostrador from './VenderMostrador.vue'
import VenderCalle from './VenderCalle.vue'

const props = defineProps({ modo: String, modos: Object, vendedor: Object, encargado: Boolean, config: Object, puede: Object, motivos: Object, motivosDevolucion: Object, bancos: Array, tiposRelevamiento: Object, avisos: Array, vertical: String, catalogoParcial: Boolean, productos: Array, rubros: Array, clientes: Array, consumidorFinalId: Number, cuentas: Array, caja: Object, hoy: Object, empresaLetra: String, preciosConIva: Boolean, posConfig: Object, planesCuotas: Array, mp: Object })
const selector = ref(false)
const franja = { calle: 'bg-gradient-to-r from-sky-700 to-sky-600', mostrador: 'bg-gradient-to-r from-emerald-700 to-emerald-600', caja: 'bg-gradient-to-r from-slate-800 to-slate-700' }
const ayuda = { calle: 'Arranca en tus clientes: la ruta del día, su cuenta y su lista. Anda sin señal y registra dónde estuviste.', mostrador: 'Arranca buscando productos. Consumidor final, cobrás en el momento y sale el ticket.', caja: 'El mismo mostrador con la pantalla ancha de una caja: ticket a la izquierda y el total grande.' }
function cambiar(k) { selector.value = false; if (k !== props.modo) router.get('/vender', { modo: k }) }
</script>
