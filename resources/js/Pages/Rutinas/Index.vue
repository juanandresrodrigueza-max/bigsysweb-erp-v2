<template>
  <AppLayout titulo="Rutinas de Bigcito">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><p class="kicker mb-1">Bigcito trabaja solo</p><h1 class="page-title">Lo que le dejaste encargado</h1>
        <p class="page-subtitle">Le decís qué querés y cada cuánto, y Bigcito lo hace solo y te lo manda. Si hay que hacer algo (cobrar, mandar un recordatorio, pedir mercadería), te lo deja propuesto y vos confirmás. También se lo podés pedir hablando: «todos los lunes a las 8 pasame el resumen de la semana».</p></div>
      <div class="flex gap-2"><Link href="/propuestas" class="btn-secondary">Propuestas</Link><button class="btn-primary" @click="nueva()" data-e2e="rutina-nueva">Nueva rutina</button></div>
    </div>

    <p v-if="!conIa" class="text-sm p-3 rounded-xl bg-amber-50 text-amber-800 mb-4">Sin la clave de IA, las rutinas sólo entienden pedidos simples (ventas, deudores, saldos, stock). Con IA entienden cualquier cosa.</p>

    <div v-if="prueba" class="card mb-4 border-violeta/40" data-e2e="rutina-prueba">
      <div class="flex items-start justify-between gap-3"><p class="font-bold">Prueba de «{{ prueba.nombre }}»</p><span class="badge bg-violeta-light text-violeta">no se mandó, es sólo la prueba</span></div>
      <pre class="whitespace-pre-wrap font-sans text-sm mt-2">{{ prueba.texto }}</pre>
      <Propuestas v-if="prueba.propuestas?.length" :items="prueba.propuestas" class="mt-3" />
    </div>

    <div v-if="!rutinas.length" class="card mb-5">
      <EstadoVacio texto="Todavía no le encargaste nada. Probá con una de estas, o pedísela a Bigcito hablando." />
    </div>
    <div v-else class="space-y-3 mb-6">
      <div v-for="r in rutinas" :key="r.id" class="card" :class="r.activa ? '' : 'opacity-60'" :data-e2e="`rutina-${r.id}`">
        <div class="flex flex-wrap items-start gap-3">
          <div class="flex-1 min-w-0">
            <p class="font-semibold">{{ r.nombre }} <span v-if="!r.activa" class="badge bg-gris-light ml-1">en pausa</span><span v-if="r.solo_si_hay" class="badge bg-amber-50 text-amber-700 ml-1">sólo si hay novedad</span></p>
            <p class="text-sm text-marca-muted mt-0.5">{{ r.cuando }}<span v-if="r.proxima"> · próxima: {{ r.proxima }}</span></p>
            <p class="text-sm mt-1">{{ r.instruccion }}</p>
            <details v-if="r.ultimo_resultado" class="mt-2 text-sm"><summary class="cursor-pointer text-violeta font-semibold text-xs">Última vez ({{ r.ultima }}) · {{ r.ejecuciones }} {{ r.ejecuciones === 1 ? 'vez' : 'veces' }}</summary>
              <pre class="whitespace-pre-wrap font-sans bg-marca-fondo rounded-xl p-3 text-[13px] mt-1">{{ r.ultimo_resultado }}</pre></details>
            <Propuestas v-if="r.propuestas?.length" :items="r.propuestas" class="mt-2" />
          </div>
          <div class="flex flex-wrap gap-1.5 shrink-0">
            <button class="btn-secondary !py-1 text-xs" :disabled="probando === r.id" @click="probar(r)" data-e2e="rutina-probar">{{ probando === r.id ? 'Haciéndola…' : 'Probar ahora' }}</button>
            <button class="btn-secondary !py-1 text-xs" @click="editar(r)">Editar</button>
            <button class="btn-secondary !py-1 text-xs" @click="router.post(`/rutinas/${r.id}/alternar`, {}, { preserveScroll: true })">{{ r.activa ? 'Pausar' : 'Reanudar' }}</button>
            <button class="btn-ghost !py-1 text-xs text-carmin" @click="borrar(r)">Borrar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <p class="font-bold mb-2">Ideas para empezar</p>
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
        <button v-for="e in ejemplos" :key="e.nombre" type="button" class="text-left p-3 rounded-xl border border-marca-borde hover:border-violeta transition" @click="nueva(e)">
          <p class="font-semibold text-sm">{{ e.nombre }}</p><p class="text-xs text-marca-muted mt-0.5">{{ e.instruccion }}</p>
        </button>
      </div>
    </div>

    <Modal :abierto="abierto" :titulo="f.id ? 'Editar rutina' : 'Nueva rutina'" @cerrar="abierto = false">
      <form class="space-y-3" @submit.prevent="guardar">
        <div><label class="label">¿Qué tiene que hacer?</label><textarea v-model="f.instruccion" rows="3" class="input" placeholder="Pasame cuánto se vendió y cuánto se cobró, y quiénes son los que más deben." data-e2e="rutina-instruccion"></textarea><p v-if="f.errors.instruccion" class="text-xs text-carmin mt-1">{{ f.errors.instruccion }}</p></div>
        <div><label class="label">Nombre (opcional)</label><input v-model="f.nombre" class="input" placeholder="Resumen del lunes" /></div>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label">¿Cada cuánto?</label><select v-model="f.frecuencia" class="input"><option v-for="(n, k) in frecuencias" :key="k" :value="k">{{ n }}</option></select></div>
          <div><label class="label">{{ f.frecuencia === 'cada_hora' ? 'A qué minuto (se usa el minuto)' : 'A qué hora' }}</label><input v-model="f.hora" type="time" class="input" /><p v-if="f.errors.hora" class="text-xs text-carmin mt-1">{{ f.errors.hora }}</p></div>
        </div>
        <div v-if="f.frecuencia === 'semanal'"><label class="label">¿Qué días?</label>
          <div class="flex flex-wrap gap-1.5"><button v-for="(n, k) in dias" :key="k" type="button" class="px-3 py-1 rounded-full border text-sm capitalize" :class="f.dias.includes(Number(k)) ? 'bg-violeta text-white border-violeta' : 'border-marca-borde'" @click="alternarDia(Number(k))">{{ n }}</button></div>
          <p v-if="f.errors.dias" class="text-xs text-carmin mt-1">{{ f.errors.dias }}</p></div>
        <div v-if="f.frecuencia === 'mensual'"><label class="label">¿Qué día del mes?</label><select v-model="f.dia_mes" class="input"><option v-for="d in 28" :key="d" :value="d">{{ d }}</option><option value="ultimo">El último día</option></select></div>
        <div><label class="label">¿Por dónde te llega?</label><select v-model="f.canal" class="input"><option v-for="(n, k) in canales" :key="k" :value="k" :disabled="k === 'whatsapp' && !puedeWhatsapp">{{ n }}{{ k === 'whatsapp' && !puedeWhatsapp ? ' (falta conectar WhatsApp o cargar tu celular)' : '' }}</option></select><p v-if="f.errors.canal" class="text-xs text-carmin mt-1">{{ f.errors.canal }}</p></div>
        <label class="flex items-start gap-2 text-sm"><input v-model="f.solo_si_hay" type="checkbox" class="accent-carmin mt-1" /> <span>Es de vigilancia: avisame <b>sólo si hay algo</b> (si no pasa nada, no manda nada).</span></label>
        <p v-if="f.errors.rutina" class="text-xs text-carmin">{{ f.errors.rutina }}</p>
        <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="abierto = false">Cancelar</button><button class="btn-primary" :disabled="f.processing" data-e2e="rutina-guardar">{{ f.id ? 'Guardar' : 'Encargársela' }}</button></div>
      </form>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, h } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
import Modal from '@/Components/Modal.vue'

const props = defineProps({ rutinas: Array, frecuencias: Object, canales: Object, dias: Object, puedeWhatsapp: Boolean, canalDefecto: String, conIa: Boolean, prueba: Object, ejemplos: Array })
const abierto = ref(false), probando = ref(null)
const vacio = () => ({ id: null, nombre: '', instruccion: '', frecuencia: 'diaria', hora: '08:00', dias: [1], dia_mes: 1, canal: props.canalDefecto, solo_si_hay: false })
const f = useForm(vacio())
function nueva(e = null) { f.clearErrors(); Object.assign(f, vacio(), e ? { ...e, dias: e.dias ?? [1], dia_mes: e.dia_mes ?? 1, solo_si_hay: !!e.solo_si_hay } : {}); abierto.value = true }
function editar(r) { f.clearErrors(); Object.assign(f, { id: r.id, nombre: r.nombre, instruccion: r.instruccion, frecuencia: r.frecuencia, hora: r.hora, dias: r.dias?.length ? [...r.dias] : [1], dia_mes: r.dia_mes === 31 ? 'ultimo' : (r.dia_mes ?? 1), canal: r.canal, solo_si_hay: r.solo_si_hay }); abierto.value = true }
function alternarDia(k) { const i = f.dias.indexOf(k); if (i >= 0) f.dias.splice(i, 1); else f.dias.push(k) }
function guardar() { f.post(f.id ? `/rutinas/${f.id}` : '/rutinas', { preserveScroll: true, onSuccess: () => (abierto.value = false) }) }
function probar(r) { probando.value = r.id; router.post(`/rutinas/${r.id}/probar`, {}, { preserveScroll: true, onFinish: () => (probando.value = null) }) }
function borrar(r) { if (confirm(`¿Borrar la rutina «${r.nombre}»?`)) router.delete(`/rutinas/${r.id}`, { preserveScroll: true }) }

// Las acciones que dejó propuestas una rutina: se confirman de a una, igual que en el panel de Bigcito.
const estado = ref({})
async function resolver(p, cancelar = false) {
  estado.value[p.id] = 'haciendo'
  try { const { data } = await window.axios.post('/agente/ejecutar', { propuesta_id: p.id, ...(cancelar ? { cancelar: true } : {}) }); estado.value[p.id] = cancelar ? 'Cancelada.' : (data.texto ?? 'Listo.') }
  catch (e) { estado.value[p.id] = e.response?.data?.message ?? 'No se pudo.' }
}
const Propuestas = (pp) => h('div', { class: 'space-y-1.5' }, pp.items.map(p => h('div', { class: 'rounded-xl border border-violeta/30 bg-violeta-light/40 p-2 text-sm', 'data-e2e': 'rutina-propuesta' }, [
  h('p', { class: 'font-semibold' }, p.titulo), p.detalle ? h('p', { class: 'text-xs text-marca-muted' }, p.detalle) : null,
  estado.value[p.id] && estado.value[p.id] !== 'haciendo' ? h('p', { class: 'text-xs mt-1' }, estado.value[p.id])
    : h('div', { class: 'flex gap-2 mt-1.5' }, [h('button', { type: 'button', class: 'btn-primary !py-1 !px-3 text-xs', disabled: estado.value[p.id] === 'haciendo', onClick: () => resolver(p) }, estado.value[p.id] === 'haciendo' ? 'Haciendo…' : 'Confirmar'), h('button', { type: 'button', class: 'btn-secondary !py-1 !px-3 text-xs', onClick: () => resolver(p, true) }, 'No')]),
])))
Propuestas.props = ['items']
</script>
