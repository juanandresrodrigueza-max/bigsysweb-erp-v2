<template>
  <div class="fixed bottom-5 right-5 z-50">
    <transition name="fade">
      <div v-if="abierto" class="absolute bottom-16 right-0 w-[360px] max-w-[calc(100vw-2.5rem)] h-[520px] max-h-[calc(100vh-7rem)] bg-white rounded-2xl shadow-2xl border border-marca-borde flex flex-col overflow-hidden">
        <div class="px-4 py-3 text-white flex items-center justify-between bg-violeta-grad">
          <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center"><Icono nombre="sparkles" clase="w-4 h-4" /></span>
            <div>
              <p class="text-sm font-bold leading-tight">Asistente BigSys</p>
              <p class="text-[11px] text-white/80 leading-tight">{{ modo === 'ia' ? 'Con IA · conoce tu negocio' : 'Modo básico' }}</p>
            </div>
          </div>
          <button @click="abierto = false" class="p-1 rounded hover:bg-white/15"><Icono nombre="x" clase="w-4 h-4" /></button>
        </div>

        <div ref="scroll" class="flex-1 overflow-y-auto px-3 py-3 space-y-2 bg-marca-fondo">
          <div v-if="!mensajes.length" class="text-xs text-marca-muted space-y-2 px-1 pt-1">
            <p>Hola {{ nombre }}. Preguntame cómo hacer algo en el sistema o consultame datos de {{ empresa }}.</p>
            <div class="flex flex-wrap gap-1.5">
              <button v-for="s in sugerencias" :key="s" @click="enviar(s)" class="px-2.5 py-1 rounded-full bg-white border border-marca-borde text-marca-texto hover:border-violeta text-[11px]">{{ s }}</button>
            </div>
          </div>
          <div v-for="(m, i) in mensajes" :key="i" :class="m.rol === 'user' ? 'justify-end' : 'justify-start'" class="flex">
            <div :class="m.rol === 'user' ? 'bg-carmin text-white rounded-br-sm' : 'bg-white border border-marca-borde text-marca-texto rounded-bl-sm'" class="max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap leading-snug">{{ m.texto }}</div>
          </div>
          <div v-if="cargando" class="flex"><div class="bg-white border border-marca-borde rounded-2xl rounded-bl-sm px-3 py-2 text-sm text-marca-muted">Pensando…</div></div>
        </div>

        <form @submit.prevent="enviar()" class="p-2 border-t border-marca-borde flex gap-2 bg-white">
          <input v-model="texto" class="input rounded-full" placeholder="Escribí tu consulta…" :disabled="cargando" />
          <button type="submit" class="btn-primary !px-3" :disabled="cargando || !texto.trim()"><Icono nombre="send" clase="w-4 h-4" /></button>
        </form>
      </div>
    </transition>

    <button @click="abierto = !abierto" class="w-14 h-14 rounded-full text-white shadow-pop flex items-center justify-center hover:scale-105 transition bg-violeta-grad" aria-label="Asistente">
      <Icono :nombre="abierto ? 'x' : 'sparkles'" clase="w-6 h-6" />
    </button>
  </div>
</template>

<script setup>
import { ref, nextTick, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import Icono from '@/Components/Icono.vue'

const page = usePage()
const abierto = ref(false)
const texto = ref('')
const cargando = ref(false)
const modo = ref('ia')
const mensajes = ref([])
const scroll = ref(null)

const nombre = computed(() => page.props.auth?.user?.name?.split(' ')[0] ?? '')
const empresa = computed(() => page.props.empresa?.nombre ?? 'tu empresa')
const sugerencias = ['¿Cuánto vendí hoy?', '¿Cuánto me deben?', '¿Qué alertas tengo?', '¿Cómo cambio de sucursal?', '¿Cómo creo un usuario?']

async function enviar(pre) {
  const msg = (pre ?? texto.value).trim()
  if (!msg || cargando.value) return
  texto.value = ''
  const historial = mensajes.value.slice(-10).map(m => ({ rol: m.rol, texto: m.texto }))
  mensajes.value.push({ rol: 'user', texto: msg })
  cargando.value = true
  await bajar()
  try {
    const { data } = await window.axios.post('/agente/chat', { mensaje: msg, historial, pantalla: page.component })
    modo.value = data.modo ?? 'ia'
    mensajes.value.push({ rol: 'assistant', texto: data.respuesta ?? 'No pude responder.' })
  } catch (e) {
    const st = e.response?.status
    mensajes.value.push({ rol: 'assistant', texto: st === 419 ? 'Tu sesión expiró. Recargá la página y volvé a intentar.' : `No pude conectarme${st ? ` (HTTP ${st})` : ''}. Probá de nuevo en unos segundos.` })
  } finally {
    cargando.value = false
    await bajar()
  }
}

async function bajar() {
  await nextTick()
  if (scroll.value) scroll.value.scrollTop = scroll.value.scrollHeight
}
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .15s, transform .15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(8px); }
</style>
