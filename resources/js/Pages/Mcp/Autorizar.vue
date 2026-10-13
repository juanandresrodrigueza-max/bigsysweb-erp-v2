<template>
  <Head title="Conectar IA · BigSys web ERP" />
  <div class="min-h-screen grid place-items-center bg-marca-fondo px-4 py-10">
    <div class="card w-full max-w-lg">
      <Logo clase="h-10 mb-4" />
      <div v-if="error" class="rounded-xl bg-carmin-light text-carmin p-4 text-sm" data-e2e="oauth-error">{{ error }}</div>
      <form v-else @submit.prevent="enviar('permitir')" data-e2e="consentimiento">
        <h1 class="text-xl font-extrabold text-balance"><span class="text-violeta">{{ cliente }}</span> quiere conectarse a tu ERP</h1>
        <p class="text-sm text-marca-muted mt-1">Empresa <b>{{ empresa }}</b> · como <b>{{ usuario }}</b>. Va a poder usar solo lo que tu usuario tiene permitido. Regresa a <code>{{ host }}</code>.</p>
        <p class="label mt-5">¿Qué puede hacer?</p>
        <div class="space-y-2">
          <label v-for="(txt, k) in modos" :key="k" class="flex gap-3 items-start rounded-xl border p-3 cursor-pointer" :class="modo === k ? 'border-violeta bg-violeta/5' : 'border-marca-borde'">
            <input v-model="modo" type="radio" :value="k" class="mt-1" :data-e2e="`modo-${k}`" />
            <span class="text-sm"><b class="block">{{ txt }}</b><span class="text-marca-muted text-xs">{{ ayuda[k] }}</span></span>
          </label>
        </div>
        <p class="text-xs text-marca-muted mt-3">Ninguna acción se hace sola: la IA te muestra lo que va a hacer y espera tu "sí". Podés desconectarla cuando quieras en Configuración → Conexiones IA.</p>
        <div class="flex gap-2 justify-end mt-5">
          <button type="button" class="btn-ghost" :disabled="enviando" @click="enviar('rechazar')">Cancelar</button>
          <button class="btn-primary" :disabled="enviando" data-e2e="permitir">Permitir</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import Logo from '@/Components/Logo.vue'

const props = defineProps({ error: String, cliente: String, host: String, empresa: String, usuario: String, modos: Object, params: Object })
const modo = ref('lectura'), enviando = ref(false)
const ayuda = { lectura: 'Ver ventas, clientes, stock, cajas e informes. No cambia nada.', acciones: 'Además puede proponer presupuestos, cobros, órdenes de compra, gastos… y hacerlos cuando confirmás.', delicadas: 'También emitir facturas en ARCA, anular, pagar a proveedores y transferir plata (siempre con tu confirmación).' }
function enviar(decision) { enviando.value = true; router.post('/oauth/authorize', { ...props.params, decision, modo: modo.value }, { onFinish: () => (enviando.value = false) }) }
</script>
