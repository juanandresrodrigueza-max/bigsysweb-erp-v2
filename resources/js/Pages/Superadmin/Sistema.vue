<template>
  <AdminLayout titulo="Sistema">
    <div class="mb-5"><h1 class="page-title">Parámetros del sistema</h1><p class="page-subtitle">Reglas de prueba y vencimiento, cómo cobra BigSys y datos de soporte.</p></div>

    <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="grid lg:grid-cols-3 gap-4">
      <div class="card space-y-4">
        <h2 class="font-bold">Ciclo de suscripción</h2>
        <div><label class="label">Días de prueba gratis</label><input v-model.number="form.dias_prueba" type="number" min="1" class="input" /></div>
        <div><label class="label">Días de gracia después del vencimiento</label><input v-model.number="form.dias_gracia" type="number" min="0" class="input" /><p class="text-xs text-marca-muted mt-1">Vencida la gracia, la empresa queda suspendida hasta que pague.</p></div>
        <div><label class="label">Avisar cuando falten (días, separados por coma)</label><input v-model="form.aviso_dias" class="input" placeholder="7,3,1" /></div>
        <div class="p-3 rounded-xl bg-marca-fondo text-sm">
          <p class="font-semibold mb-1">Revisión automática</p>
          <p class="text-marca-muted text-xs">Corre todos los días a las 06:00. Podés forzarla ahora.</p>
          <Link href="/admin/sistema/revisar" method="post" as="button" preserve-scroll class="btn-secondary !py-1 text-xs mt-2">Revisar vencimientos ahora</Link>
        </div>
      </div>

      <div class="card space-y-4">
        <h2 class="font-bold">Cobros de BigSys</h2>
        <div class="p-3 rounded-xl text-sm" :class="config.mp_access_token_set ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'">
          <b>MercadoPago:</b> {{ config.mp_access_token_set ? 'credenciales cargadas, los pagos van en serio.' : 'sin credenciales. Los pagos corren en modo simulado (se aprueban solos) para probar el circuito.' }}
        </div>
        <div><label class="label">Public key</label><input v-model="form.mp_public_key" class="input" placeholder="APP_USR-…" /></div>
        <div><label class="label">Access token {{ config.mp_access_token_set ? '(dejar vacío para no cambiarlo)' : '' }}</label><input v-model="form.mp_access_token" type="password" class="input" autocomplete="off" placeholder="APP_USR-…" /></div>
        <p class="label !mb-0 pt-2">Transferencia bancaria</p>
        <div><label class="label">Titular</label><input v-model="form.transferencia_titular" class="input" /></div>
        <div class="grid grid-cols-2 gap-3"><div><label class="label">CBU</label><input v-model="form.transferencia_cbu" class="input tabular-nums" /></div><div><label class="label">Alias</label><input v-model="form.transferencia_alias" class="input" /></div></div>
      </div>

      <div class="card space-y-4">
        <h2 class="font-bold">Soporte y avisos</h2>
        <div><label class="label">WhatsApp de soporte</label><input v-model="form.soporte_whatsapp" class="input" /></div>
        <div><label class="label">Email de soporte</label><input v-model="form.soporte_email" type="email" class="input" /></div>
        <div><label class="label">Mensaje global (lo ven todas las empresas arriba de la pantalla)</label><textarea v-model="form.mensaje_global" rows="3" class="input" placeholder="Ej: El sábado de 2 a 4 hay mantenimiento."></textarea></div>
        <div class="text-xs text-marca-muted space-y-1 pt-2 border-t border-marca-borde">
          <p>Laravel {{ version.laravel }} · PHP {{ version.php }} · base {{ version.db }}</p>
        </div>
        <div class="flex justify-end"><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
      </div>
    </form>

    <div class="grid lg:grid-cols-2 gap-4 mt-4">
      <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="card space-y-3">
        <div class="flex items-center justify-between"><h2 class="font-bold">Inteligencia artificial (Claude)</h2><span class="badge" :class="ia ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ ia ? (config.ia_api_key_set ? 'clave del panel' : 'clave del .env') : 'modo básico' }}</span></div>
        <p class="text-sm text-marca-muted">Con clave, el asistente, el analista, la lectura de facturas y la interpretación de pedidos usan IA. Sin clave, todo sigue funcionando en modo básico. La clave se guarda cifrada y no se vuelve a mostrar.</p>
        <div><label class="label">API key {{ config.ia_api_key_set ? '(dejar vacío para no cambiarla)' : '' }}</label><input v-model="form.ia_api_key" type="password" class="input" autocomplete="off" placeholder="sk-ant-…" /></div>
        <div><label class="label">Modelo</label><input v-model="form.ia_modelo" class="input" placeholder="claude-sonnet-5" /><p class="text-[11px] text-marca-muted mt-1">Haiku es más barato y rápido; Sonnet entiende mejor pedidos complejos y facturas borrosas.</p></div>
        <div class="flex flex-wrap gap-2 justify-end"><button v-if="config.ia_api_key_set" type="button" class="btn-ghost text-xs text-carmin" @click="borrar('ia_api_key')">Borrar clave del panel</button><Link href="/admin/sistema/probar-ia" method="post" as="button" preserve-scroll class="btn-secondary">Probar la clave</Link><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
      </form>
      <form @submit.prevent="form.post('/admin/sistema', { preserveScroll: true })" class="card space-y-3">
        <div class="flex items-center justify-between"><h2 class="font-bold">Correo saliente (SMTP)</h2><span class="badge" :class="config.mail_activo === 'smtp' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ config.mail_activo === 'smtp' ? (config.mail_host ? 'SMTP del panel' : 'SMTP del .env') : `sin envío real (${config.mail_activo})` }}</span></div>
        <p class="text-sm text-marca-muted">Para recuperar contraseñas, mandar comprobantes, recordatorios y avisos. Cualquier casilla con SMTP sirve (Gmail con clave de aplicación, el hosting, Resend, Brevo…).</p>
        <div class="grid grid-cols-3 gap-2"><div class="col-span-2"><label class="label">Servidor</label><input v-model="form.mail_host" class="input" placeholder="smtp.gmail.com" /></div><div><label class="label">Puerto</label><input v-model.number="form.mail_port" type="number" class="input" /></div></div>
        <div class="grid grid-cols-2 gap-2"><div><label class="label">Usuario</label><input v-model="form.mail_username" class="input" autocomplete="off" /></div><div><label class="label">Contraseña {{ config.mail_password_set ? '(vacío = no cambiar)' : '' }}</label><input v-model="form.mail_password" type="password" class="input" autocomplete="off" /></div></div>
        <div class="grid grid-cols-3 gap-2"><div><label class="label">Cifrado</label><select v-model="form.mail_encryption" class="input"><option value="tls">TLS (587)</option><option value="ssl">SSL (465)</option><option value="none">Ninguno</option></select></div><div><label class="label">Remitente</label><input v-model="form.mail_from_address" type="email" class="input" placeholder="no-responder@bigsys.com.ar" /></div><div><label class="label">Nombre</label><input v-model="form.mail_from_name" class="input" /></div></div>
        <div class="flex flex-wrap items-center gap-2 justify-end"><input v-model="pruebaA" type="email" class="input !w-56 !py-1.5 text-sm" placeholder="Mandar prueba a…" /><button type="button" class="btn-secondary" :disabled="!pruebaA" @click="router.post('/admin/sistema/probar-correo', { a: pruebaA }, { preserveScroll: true })">Enviar prueba</button><button class="btn-primary" :disabled="form.processing">Guardar</button></div>
      </form>
    </div>
    <div class="card mt-4" :class="mt.activo ? 'border-carmin' : ''">
      <h2 class="font-bold mb-1">Modo mantenimiento</h2>
      <p class="text-sm text-marca-muted mb-3">Mientras está activo, todos los usuarios ven una pantalla de aviso (los superadmin siguen entrando). Usalo para migraciones o cambios grandes.</p>
      <form @submit.prevent="mt.post('/admin/sistema/mantenimiento', { preserveScroll: true })" class="grid sm:grid-cols-3 gap-3 items-end">
        <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="mt.activo" type="checkbox" class="accent-carmin" /> Activar mantenimiento</label>
        <div><label class="label">Mensaje</label><input v-model="mt.mensaje" class="input" placeholder="Estamos haciendo mejoras…" /></div>
        <div><label class="label">Volvemos aprox.</label><input v-model="mt.hasta" class="input" placeholder="Ej. 22:30" /></div>
        <div class="sm:col-span-3 flex justify-end"><button class="btn-primary" :disabled="mt.processing">{{ mt.activo ? 'Guardar y activar' : 'Guardar' }}</button></div>
      </form>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
const props = defineProps({ config: Object, ia: Boolean, version: Object, mantenimiento: Object })
const mt = useForm({ activo: !!props.mantenimiento?.activo, mensaje: props.mantenimiento?.mensaje ?? '', hasta: props.mantenimiento?.hasta ?? '' })
const form = useForm({ ...props.config, mp_access_token: '', ia_api_key: '', mail_password: '', mail_encryption: props.config.mail_encryption || 'tls' })
const pruebaA = ref('')
function borrar(clave) { router.post('/admin/sistema/clave/borrar', { clave }, { preserveScroll: true }) }
</script>
