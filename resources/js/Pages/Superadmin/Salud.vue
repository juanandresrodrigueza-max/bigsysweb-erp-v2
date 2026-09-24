<template>
  <AdminLayout titulo="Salud">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
      <div><h1 class="page-title">Salud del servidor</h1><p class="page-subtitle">Cómo está el sistema ahora: base, cola, cron, disco, copias, ARCA, errores. Se revisa sola cada hora y avisa por mail si algo está crítico.</p></div>
      <div class="flex items-center gap-2"><span class="badge text-sm px-3 py-1" :class="clase(salud.estado)">{{ { ok: 'Todo bien', aviso: 'Con avisos', critico: 'Crítico' }[salud.estado] }}</span><button class="btn-secondary !py-1.5 text-xs" @click="router.reload()">Volver a revisar</button></div>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3 mb-5">
      <div v-for="c in salud.controles" :key="c.key" class="card py-3 flex items-start gap-3">
        <span class="w-3 h-3 rounded-full mt-1.5 shrink-0" :class="{ ok: 'bg-emerald-500', aviso: 'bg-amber-500', critico: 'bg-carmin' }[c.estado]"></span>
        <div class="min-w-0"><p class="font-semibold text-sm">{{ c.nombre }}</p><p class="text-xs text-marca-muted break-words">{{ c.detalle }}</p></div>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mb-5">
      <form @submit.prevent="form.post('/admin/salud', { preserveScroll: true, onSuccess: () => (form.sentry_dsn = '') })" class="card space-y-3 lg:col-span-2">
        <div class="flex items-center justify-between"><h2 class="font-bold">Monitoreo de errores (Sentry o compatible)</h2><span class="badge" :class="monitoreo.dsn_set ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ monitoreo.dsn_set ? (monitoreo.dsn_panel ? 'DSN del panel' : 'DSN del .env') : 'solo panel local' }}</span></div>
        <p class="text-sm text-marca-muted">Cada error real (no los 404 ni las validaciones) queda agrupado acá abajo. Si cargás el DSN de un proyecto de Sentry, GlitchTip o Bugsink, además se manda allá con la traza, la empresa y la ruta, sin datos personales. Se usa el protocolo estándar: no hace falta instalar nada más en el servidor.</p>
        <div class="grid sm:grid-cols-2 gap-3">
          <div class="sm:col-span-2"><label class="label">DSN</label><input v-model="form.sentry_dsn" class="input font-mono text-xs" :placeholder="monitoreo.dsn_set ? '•••••••• (cargado; escribí uno nuevo para reemplazar)' : 'https://clave@o123.ingest.sentry.io/456'" /><p v-if="form.errors.sentry_dsn" class="text-carmin text-xs mt-1">{{ form.errors.sentry_dsn }}</p></div>
          <div><label class="label">Avisar por mail a</label><input v-model="form.monitoreo_email" type="email" class="input" placeholder="admin@bigsys.com.ar" /><p class="text-[10px] text-marca-muted mt-1">Recibe un mail cuando un control pasa a crítico (una vez cada 6 h por control).</p></div>
          <div><label class="label">Token del endpoint /salud</label><input v-model="form.salud_token" class="input font-mono text-xs" placeholder="opcional, para ver el detalle" /><p class="text-[10px] text-marca-muted mt-1">Para UptimeRobot o similar: <code class="font-mono">{{ monitoreo.url_publica }}</code> devuelve 200 si está bien y 503 si está crítico; con <code class="font-mono">?token=</code> devuelve el detalle.</p></div>
        </div>
        <div class="flex flex-wrap gap-2 justify-end">
          <button v-if="monitoreo.dsn_panel" type="button" class="btn-ghost text-xs text-carmin" @click="router.post('/admin/salud/borrar-dsn', {}, { preserveScroll: true })">Borrar DSN</button>
          <button v-if="monitoreo.dsn_set" type="button" class="btn-secondary" @click="router.post('/admin/salud/probar', {}, { preserveScroll: true })">Enviar evento de prueba</button>
          <button class="btn-primary" :disabled="form.processing">Guardar</button>
        </div>
      </form>
      <div class="card text-sm space-y-2">
        <h2 class="font-bold">Qué hacer si algo está en rojo</h2>
        <p><b>Cron</b>: agregá en el servidor <code class="font-mono text-xs">* * * * * php artisan schedule:run</code>.</p>
        <p><b>Cola</b>: tiene que correr <code class="font-mono text-xs">php artisan queue:work</code> (Supervisor lo reinicia solo).</p>
        <p><b>Copias</b>: revisá el disco y que el cron esté vivo; corré <code class="font-mono text-xs">php artisan backups:diario</code>.</p>
        <p><b>ARCA pendientes</b>: ARCA no respondió; el reintento es automático cada 5 minutos. Si sigue, mirá Configuración → Puntos de venta → Probar conexión en esa empresa.</p>
        <p><b>Errores</b>: abrí el detalle abajo; la traza dice archivo y línea. Marcalos resueltos cuando los arregles.</p>
        <p class="text-xs text-marca-muted">PHP {{ salud.version.php }} · Laravel {{ salud.version.laravel }} · revisado {{ new Date(salud.hora).toLocaleTimeString('es-AR') }}</p>
      </div>
    </div>

    <div class="card p-0 overflow-x-auto">
      <div class="px-4 py-3 border-b border-marca-borde flex items-center justify-between"><h2 class="font-bold">Errores recientes</h2><span class="text-xs text-marca-muted">{{ errores.filter(e => !e.resuelto).length }} sin resolver · agrupados por lugar</span></div>
      <table class="table text-sm">
        <thead><tr><th>Error</th><th>Dónde</th><th class="text-right">Veces</th><th>Última vez</th><th></th></tr></thead>
        <tbody>
          <template v-for="e in errores" :key="e.id">
            <tr :class="{ 'opacity-50': e.resuelto }">
              <td class="max-w-md"><p class="font-semibold">{{ e.clase }} <span v-if="e.sentry" class="badge !py-0 bg-violeta-light text-violeta">enviado</span></p><p class="text-xs text-marca-muted break-words">{{ e.mensaje }}</p></td>
              <td class="text-xs"><p class="font-mono">{{ e.archivo }}:{{ e.linea }}</p><p class="text-marca-muted">{{ e.ruta }}<span v-if="e.empresa"> · empresa {{ e.empresa }}</span></p></td>
              <td class="text-right tabular-nums font-semibold">{{ e.veces }}</td>
              <td class="text-xs tabular-nums">{{ e.ultima }}<p class="text-marca-muted">desde {{ e.primera }}</p></td>
              <td class="text-right whitespace-nowrap"><button class="btn-ghost !px-2 text-xs" @click="abierto = abierto === e.id ? null : e.id">Traza</button><button v-if="!e.resuelto" class="btn-ghost !px-2 text-xs text-emerald-700" @click="router.post(`/admin/salud/errores/${e.id}/resolver`, {}, { preserveScroll: true })">Resuelto</button></td>
            </tr>
            <tr v-if="abierto === e.id"><td colspan="5" class="bg-marca-fondo"><pre class="text-[11px] whitespace-pre-wrap max-h-64 overflow-auto">{{ e.traza }}</pre></td></tr>
          </template>
          <tr v-if="!errores.length"><td colspan="5" class="text-center text-marca-muted py-8">Sin errores registrados. 🙂</td></tr>
        </tbody>
      </table>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
const props = defineProps({ salud: Object, errores: Array, monitoreo: Object })
const form = useForm({ sentry_dsn: '', monitoreo_email: props.monitoreo.email ?? '', salud_token: props.monitoreo.salud_token ?? '' })
const abierto = ref(null)
const clase = e => ({ ok: 'bg-emerald-50 text-emerald-700', aviso: 'bg-amber-50 text-amber-700', critico: 'bg-carmin-light text-carmin' }[e])
</script>
