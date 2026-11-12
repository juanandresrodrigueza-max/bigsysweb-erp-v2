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
          <div><label class="label">Avisar por mail a</label><input v-model="form.monitoreo_email" type="text" class="input" placeholder="gerencia@bigsys.com.ar, otro@mail.com" /><p class="text-[10px] text-marca-muted mt-1">Recibe un mail cuando un control pasa a crítico (una vez cada 6 h por control).</p></div>
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

    <!-- Fase 77: velocidad de las páginas, medida cada noche -->
    <div class="card p-0 overflow-x-auto mb-4" data-e2e="paginas-lentas">
      <div class="px-4 py-3 border-b border-marca-borde flex flex-wrap items-center justify-between gap-2"><h2 class="font-bold">Velocidad de las páginas</h2>
        <span class="text-xs text-marca-muted">{{ paginas.ronda ? `Ronda ${paginas.ronda} · umbral ${paginas.umbral} ms · ${paginas.lentas} lenta(s)` : 'Todavía no corrió la medición nocturna (php artisan erp:medir --nocturna)' }}</span></div>
      <table v-if="paginas.paginas.length" class="table text-sm">
        <thead><tr><th>Página</th><th class="text-right">Peor</th><th class="text-right">Promedio</th><th class="text-right">Noche anterior</th><th class="text-right">Consultas</th><th>Consulta más lenta</th></tr></thead>
        <tbody><tr v-for="p in paginas.paginas" :key="p.url" :class="{ 'bg-rose-50 dark:bg-rose-950/30': p.peor_ms > paginas.umbral }">
          <td class="font-mono text-xs">{{ p.url }}<span v-if="p.errores" class="badge !py-0 bg-carmin text-white ml-1">error</span></td>
          <td class="text-right tabular-nums font-semibold">{{ p.peor_ms }} ms</td><td class="text-right tabular-nums">{{ p.promedio_ms }} ms</td>
          <td class="text-right tabular-nums text-marca-muted">{{ p.anterior_ms === null ? '—' : p.anterior_ms + ' ms' }}</td><td class="text-right tabular-nums">{{ p.consultas }}</td>
          <td class="text-xs text-marca-muted max-w-md truncate" :title="p.lentas[0]?.sql">{{ p.lentas[0] ? p.lentas[0].ms + ' ms · ' + p.lentas[0].sql : '—' }}</td></tr></tbody>
      </table>
      <p class="px-4 py-2 text-[11px] text-marca-muted">Cada noche a las 4:40 se miden las páginas clave de las empresas más grandes como su dueño. Una página lenta dos noches seguidas pone la salud en crítico y avisa por mail.</p>
    </div>

    <!-- Fase 78: copias por empresa -->
    <div class="card p-0 overflow-x-auto mb-4" data-e2e="copias-empresas">
      <div class="px-4 py-3 border-b border-marca-borde flex flex-wrap items-center justify-between gap-2"><h2 class="font-bold">Copias de seguridad por empresa</h2>
        <span class="text-xs" :class="copias.remoto ? 'text-emerald-700' : 'text-amber-700'">{{ copias.remoto ? `Se suben a ${copias.disco}` : 'Sin disco externo: las copias viven en este servidor (BACKUP_DISK)' }}</span></div>
      <table class="table text-sm">
        <thead><tr><th>Empresa</th><th>Última copia</th><th class="text-right">Tamaño</th><th>Verificada</th><th>Ensayo de restauración</th><th>Fuera del servidor</th></tr></thead>
        <tbody><tr v-for="c in copias.empresas" :key="c.business_id" :class="{ 'opacity-60': !c.auto }">
          <td class="font-semibold">{{ c.empresa }}<span v-if="!c.auto" class="badge !py-0 ml-1">auto apagada</span></td>
          <td class="tabular-nums" :class="{ 'text-carmin': c.hace_horas !== null && c.hace_horas > 30 }">{{ c.ultima ? `hace ${c.hace_horas} h` : 'nunca' }}</td>
          <td class="text-right tabular-nums">{{ c.mb === null ? '—' : c.mb + ' MB' }}</td>
          <td><span v-if="c.verificada === null" class="text-marca-muted">—</span><span v-else-if="c.verificada" class="text-emerald-700">✓ se abre y cuadra</span><span v-else class="text-carmin" :title="c.problemas.join(' ')">✗ {{ c.problemas[0] }}</span></td>
          <td><span v-if="!c.ensayo" class="text-marca-muted">sin ensayo</span><span v-else :class="c.ensayo.ok ? 'text-emerald-700' : 'text-carmin'">{{ c.ensayo.ok ? '✓' : '✗' }} hace {{ c.ensayo.hace_dias }} d</span></td>
          <td>{{ c.remoto ? '✓' : (copias.remoto ? '✗ no subida' : '—') }}</td></tr>
          <tr v-if="!copias.empresas.length"><td colspan="6" class="!py-0"><EstadoVacio texto="Sin empresas activas." compacto /></td></tr></tbody>
      </table>
      <p class="px-4 py-2 text-[11px] text-marca-muted">A las 3:00 se hace la copia de cada empresa y se verifica en el acto; a las 3:40 se vuelven a comprobar todas; los domingos a las 5:10 se ensaya restaurar cada una en una transacción que se deshace. Una copia que no abre o no cuadra pone la salud en crítico.</p>
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
          <tr v-if="!errores.length"><td colspan="5" class="!py-0"><EstadoVacio texto="Sin errores registrados. 🙂" /></td></tr>
        </tbody>
      </table>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import EstadoVacio from '@/Components/EstadoVacio.vue'
const props = defineProps({ salud: Object, errores: Array, monitoreo: Object, copias: { type: Object, default: () => ({ empresas: [], remoto: false, disco: null }) }, paginas: { type: Object, default: () => ({ ronda: null, paginas: [], lentas: 0, umbral: 300 }) } })
const form = useForm({ sentry_dsn: '', monitoreo_email: props.monitoreo.email ?? '', salud_token: props.monitoreo.salud_token ?? '' })
const abierto = ref(null)
const clase = e => ({ ok: 'bg-emerald-50 text-emerald-700', aviso: 'bg-amber-50 text-amber-700', critico: 'bg-carmin-light text-carmin' }[e])
</script>
