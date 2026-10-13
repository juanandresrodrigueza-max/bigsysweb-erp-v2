<template>
  <AppLayout titulo="Conexiones IA">
    <h1 class="page-title mb-4">Configuración</h1>
    <ConfigTabs v-if="conTabs" />

    <div class="grid lg:grid-cols-[1.2fr_1fr] gap-4 mb-4">
      <div class="card">
        <h2 class="font-bold mb-1">Conectá Claude, ChatGPT u otra IA a tu ERP</h2>
        <p class="text-sm text-marca-muted mb-3">El ERP funciona como servidor <b>MCP</b>: la IA puede consultar todo lo que tu usuario ve y proponer acciones (presupuestos, cobros, órdenes de compra…) que solo se hacen cuando vos confirmás.</p>
        <label class="label">Dirección del servidor MCP</label>
        <div class="flex gap-2"><input :value="url" readonly class="input font-mono text-sm flex-1" data-e2e="mcp-url" /><button class="btn-secondary text-xs" @click="copiar(url)">Copiar</button></div>
        <div class="mt-4 space-y-3 text-sm">
          <details class="rounded-xl bg-marca-fondo p-3" open><summary class="font-semibold cursor-pointer">Claude (web, escritorio o celular)</summary>
            <ol class="list-decimal ml-5 mt-2 space-y-1 text-marca-muted"><li>Configuración → Conectores → <b>Agregar conector personalizado</b>.</li><li>Pegá la dirección de arriba y tocá Conectar.</li><li>Se abre esta página para que inicies sesión y elijas qué puede hacer.</li></ol></details>
          <details class="rounded-xl bg-marca-fondo p-3"><summary class="font-semibold cursor-pointer">ChatGPT</summary>
            <ol class="list-decimal ml-5 mt-2 space-y-1 text-marca-muted"><li>Configuración → Conectores (modo desarrollador) → <b>Crear</b>.</li><li>URL del servidor MCP: la de arriba. Autenticación: OAuth.</li><li>Autorizá en la ventana que se abre.</li></ol></details>
          <details class="rounded-xl bg-marca-fondo p-3"><summary class="font-semibold cursor-pointer">Claude Code, Cursor u otro (con token)</summary>
            <p class="text-marca-muted mt-2">Creá un token personal acá abajo y usalo como encabezado <code>Authorization: Bearer …</code>. Ejemplo:</p>
            <pre class="text-[11px] bg-white rounded-lg p-2 mt-1 overflow-x-auto">claude mcp add --transport http bigsys {{ url }} \
  --header "Authorization: Bearer TU_TOKEN"</pre></details>
        </div>
      </div>
      <div class="card">
        <h2 class="font-bold mb-1">Token personal</h2>
        <p class="text-sm text-marca-muted mb-3">Para programas que no usan OAuth. No vence: desconectalo cuando no lo uses.</p>
        <div v-if="tokenNuevo" class="rounded-xl bg-amber-50 text-amber-900 p-3 text-sm mb-3" data-e2e="token-nuevo">
          <p class="font-bold mb-1">Copiá el token ahora: no se vuelve a mostrar.</p>
          <div class="flex gap-2"><code class="select-all break-all text-xs bg-white rounded px-2 py-1 flex-1">{{ tokenNuevo }}</code><button class="btn-secondary !py-1 text-xs" @click="copiar(tokenNuevo)">Copiar</button></div>
        </div>
        <form class="space-y-2" @submit.prevent="nuevo.post('/conexiones-ia/token', { preserveScroll: true, onSuccess: () => nuevo.reset('nombre') })">
          <div><label class="label">Nombre</label><input v-model="nuevo.nombre" class="input" placeholder="Claude Code de la oficina" data-e2e="token-nombre" /></div>
          <div><label class="label">Puede</label><select v-model="nuevo.modo" class="input" data-e2e="token-modo"><option v-for="(t, k) in modos" :key="k" :value="k">{{ t }}</option></select></div>
          <button class="btn-primary" :disabled="!nuevo.nombre || nuevo.processing" data-e2e="crear-token">Crear token</button>
        </form>
      </div>
    </div>

    <div class="card p-0 overflow-x-auto mb-4">
      <table class="table text-sm min-w-[720px]">
        <thead><tr><th>Conexión</th><th v-if="esDueno">Usuario</th><th>Puede</th><th>Último uso</th><th class="text-right">Llamadas</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in conexiones" :key="c.id" :data-e2e="`conexion-${c.id}`">
            <td><b>{{ c.nombre }}</b><span class="block text-xs text-marca-muted">{{ c.tipo }} · desde {{ c.creada }}</span></td>
            <td v-if="esDueno">{{ c.usuario }}</td>
            <td><select :value="c.modo" class="input !py-1 text-xs" @change="router.post(`/conexiones-ia/${c.id}/modo`, { modo: $event.target.value }, { preserveScroll: true })"><option v-for="(t, k) in modos" :key="k" :value="k">{{ t }}</option></select></td>
            <td class="text-xs">{{ c.ultimo_uso || 'Nunca' }}</td><td class="text-right tabular-nums">{{ c.usos }}</td>
            <td class="text-right"><button class="btn-ghost !px-2 text-xs text-carmin" @click="router.post(`/conexiones-ia/${c.id}/revocar`, {}, { preserveScroll: true })">Desconectar</button></td>
          </tr>
          <tr v-if="!conexiones.length"><td :colspan="esDueno ? 6 : 5" class="text-center text-marca-muted py-8">Todavía no conectaste ninguna IA.</td></tr>
        </tbody>
      </table>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
      <div class="card">
        <h2 class="font-bold mb-2">Acciones propuestas</h2>
        <ul class="text-sm divide-y divide-marca-borde">
          <li v-for="p in propuestas" :key="p.id" class="py-2 flex justify-between gap-3"><span>{{ p.titulo }}<span class="block text-xs text-marca-muted">{{ p.cuando }} · {{ p.usuario }} · {{ p.origen === 'mcp' ? 'IA conectada' : 'Asistente' }}</span></span><span class="badge h-fit" :class="claseEstado[p.estado]">{{ p.estado }}</span></li>
          <li v-if="!propuestas.length" class="py-4 text-marca-muted text-center">Sin acciones todavía.</li>
        </ul>
      </div>
      <div class="card">
        <h2 class="font-bold mb-2">Últimas llamadas</h2>
        <ul class="text-xs divide-y divide-marca-borde">
          <li v-for="l in llamadas" :key="l.id" class="py-1.5 flex justify-between gap-2"><span><code>{{ l.herramienta }}</code> <span class="text-marca-muted">· {{ l.usuario }} · {{ l.cuando }}</span><span v-if="l.error" class="block text-carmin">{{ l.error }}</span></span><span :class="l.ok ? 'text-emerald-700' : 'text-carmin'">{{ l.ok ? 'ok' : 'error' }} · {{ l.ms }} ms</span></li>
          <li v-if="!llamadas.length" class="py-4 text-marca-muted text-center">Sin llamadas todavía.</li>
        </ul>
        <details class="mt-3 text-xs"><summary class="cursor-pointer font-semibold">Herramientas disponibles para tu usuario ({{ herramientas.length }})</summary>
          <div class="flex flex-wrap gap-1 mt-2"><span v-for="h in herramientas" :key="h.nombre" class="badge" :class="h.tipo === 'consulta' ? 'bg-gris-light' : h.delicada ? 'bg-carmin-light text-carmin' : 'bg-violeta/10 text-violeta'">{{ h.titulo }}</span></div>
        </details>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ConfigTabs from '@/Components/ConfigTabs.vue'

defineProps({ url: String, esDueno: Boolean, conTabs: Boolean, modos: Object, tokenNuevo: String, conexiones: Array, llamadas: Array, propuestas: Array, herramientas: Array })
const nuevo = useForm({ nombre: '', modo: 'lectura' })
const claseEstado = { pendiente: 'bg-amber-50 text-amber-700', ejecutada: 'bg-emerald-50 text-emerald-700', cancelada: 'bg-gris-light', vencida: 'bg-gris-light', error: 'bg-carmin-light text-carmin' }
function copiar(t) { navigator.clipboard?.writeText(t).catch(() => {}) }
</script>
